<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for wp_byzo_bookings (DETAILED-SPEC.md §1.4), plus the
 * status state machine (§A4). Booking creation atomically decrements the
 * matching slot's capacity_remaining — DETAILED-SPEC.md §1.3 explains why
 * slots are materialized rows rather than computed on the fly: this
 * decrement needs a real row to lock/update under concurrent requests.
 */
class BYZO_Booking {

	const VALID_STATUSES = array( 'pending', 'confirmed', 'completed', 'cancelled', 'no_show', 'rejected' );

	// not_required: the total came to nothing (a code covered it all), so
	// there's nothing to collect (QA report 2026-09-26 M6).
	const VALID_PAYMENT_STATUSES = array( 'unpaid', 'paid_manual', 'paid_online', 'refunded', 'not_required' );

	/**
	 * Allowed status transitions. pending is the only entry state (set by
	 * create()); every other status is reached only via update_status().
	 * completed/cancelled/no_show/rejected are terminal — nothing
	 * transitions out of them, which is the server-side guarantee
	 * DETAILED-SPEC.md §A4 calls for ("prevents completed flipping back to
	 * pending"). `rejected` (added 2026-09-05, Phase 1.6) is reachable
	 * ONLY from `pending` — a `confirmed` booking can't be "rejected,"
	 * only cancelled, since rejection specifically means declining a
	 * request before ever accepting it.
	 */
	const TRANSITIONS = array(
		'pending'   => array( 'confirmed', 'rejected', 'cancelled', 'no_show' ),
		'confirmed' => array( 'completed', 'cancelled', 'no_show' ),
		'completed' => array(),
		'cancelled' => array(),
		'no_show'   => array(),
		'rejected'  => array(),
	);

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'byzo_bookings';
	}

	public static function get( $id ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.*, s.start_datetime AS slot_start, s.end_datetime AS slot_end FROM {$bookings_table} b LEFT JOIN {$slots_table} s ON s.id = b.slot_id WHERE b.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);

		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Every booking sharing one cart_id (Phase 2 step 14, DETAILED-SPEC.md
	 * §3 B6) — the Stripe webhook's own cart-payment branch groups by
	 * this to mark every booking in a cart paid together.
	 *
	 * @return array<int,array>
	 */
	public static function get_by_cart_id( $cart_id ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, s.start_datetime AS slot_start, s.end_datetime AS slot_end FROM {$bookings_table} b LEFT JOIN {$slots_table} s ON s.id = b.slot_id WHERE b.cart_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cart_id
			),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'cast' ), $rows ?: array() );
	}

	/**
	 * @param array $args {
	 *     @type string $from, $to  Y-m-d, filters by the booking's SLOT time
	 *         (when the appointment happens), not when the booking record
	 *         was created — the calendar view (DETAILED-SPEC.md §A4) needs
	 *         "what's on the schedule for this date range," not "what was
	 *         booked recently." Bookings with no slot (slot_id NULL, e.g.
	 *         after the slot row was deleted — see §1.4) are excluded when
	 *         from/to is given, since they have no date to place on a
	 *         calendar; they still show up in an unfiltered list.
	 *     @type int    $bookable_item_id
	 *     @type string $type             Phase 2 step 13 (DETAILED-SPEC.md §3 B3 timeline view) — an
	 *                                     alternative to $bookable_item_id: every ACTIVE bookable item of
	 *                                     this type, for "scan every staff/table/etc. of one type at once."
	 *                                     Ignored if $bookable_item_id is also given.
	 *     @type string $status
	 *     @type int    $per_page
	 *     @type int    $page
	 * }
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'from'             => '',
			'to'               => '',
			'search'           => '',
			'bookable_item_id' => 0,
			'bookable_item_ids' => null,
			// Bookings for one location's items (QA report §6.1 A1) —
			// items.location_id is Free's column, set by a locations add-on.
			'location_id'      => 0,
			'type'             => '',
			'status'           => '',
			'customer_id'      => 0,
			'per_page'         => 20,
			'page'             => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';
		$items_table    = $wpdb->prefix . 'byzo_bookable_items';

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['location_id'] ) ) {
			$where[]  = "b.bookable_item_id IN (SELECT id FROM {$items_table} WHERE location_id = %d)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[] = (int) $args['location_id'];
		}

		// A staff login's own items only (QA report §6.2.9) — set by the
		// REST controller from the session, never from a request param.
		if ( is_array( $args['bookable_item_ids'] ) ) {
			$ids = array_map( 'intval', $args['bookable_item_ids'] );
			if ( ! $ids ) {
				$where[] = '1=0';
			} else {
				$where[] = 'b.bookable_item_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$params  = array_merge( $params, $ids );
			}
		}

		if ( $args['bookable_item_id'] ) {
			$where[]  = 'b.bookable_item_id = %d';
			$params[] = (int) $args['bookable_item_id'];
		} elseif ( $args['type'] && in_array( $args['type'], BYZO_Bookable_Item::VALID_TYPES, true ) ) {
			$where[]  = "b.bookable_item_id IN (SELECT id FROM {$items_table} WHERE type = %s AND status = 'active')"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[] = $args['type'];
		}

		// Phase 1.7 step 5 (DETAILED-SPEC.md §A11) — the dashboard-scoping
		// filter for an authenticated byzo_customer request. Always passed
		// by the REST controller for that caller, never client-suppliable
		// as an arbitrary filter (see get_items()'s own permission check) —
		// this is what actually prevents a customer from ever seeing
		// another customer's rows, not just a UI convenience filter.
		if ( $args['customer_id'] ) {
			$where[]  = 'b.customer_id = %d';
			$params[] = (int) $args['customer_id'];
		}

		// A comma-separated list is accepted alongside a single value
		// (added 2026-09-05, Phase 1.5 step 10, DETAILED-SPEC.md §A8) — the
		// Dashboard's upcoming-bookings widget needs "pending OR confirmed"
		// in one call; a single-status caller (status=confirmed) still
		// gets exactly the same query as before (an IN() with one value).
		if ( $args['status'] ) {
			$statuses = array_intersect( array_map( 'trim', explode( ',', $args['status'] ) ), self::VALID_STATUSES );
			if ( $statuses ) {
				$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
				$where[]      = "b.status IN ({$placeholders})";
				foreach ( $statuses as $status ) {
					$params[] = $status;
				}
			}
		}

		// A literal "now" (added alongside the multi-status change above)
		// filters from the current moment rather than midnight today — the
		// same upcoming-bookings widget needs "what's still ahead today,"
		// not "everything today including what already happened."
		if ( $args['from'] ) {
			$where[]  = 's.start_datetime >= %s';
			$params[] = 'now' === $args['from'] ? current_time( 'mysql' ) : $args['from'] . ' 00:00:00';
		}

		if ( $args['to'] ) {
			$where[]  = 's.start_datetime < %s';
			$params[] = ( new DateTime( $args['to'], wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' ) . ' 00:00:00';
		}

		// Free-text match across the customer's own details — the admin
		// list's search box (added 2026-09-22, QA report §4.10; finding one
		// booking meant paging through everything).
		if ( '' !== trim( (string) $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where[]  = '(b.customer_name LIKE %s OR b.customer_email LIKE %s OR b.customer_phone LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );
		$select    = 'b.*, s.start_datetime AS slot_start, s.end_datetime AS slot_end';
		$join      = "LEFT JOIN {$slots_table} s ON s.id = b.slot_id";

		// Soonest upcoming booking first, then the rest of the future, then
		// the past most-recent-first — the order staff actually work in.
		// Plain ascending put months-old bookings at the top of page 1
		// (QA report §4.10). $now is a bound parameter, not NOW(), so the
		// ordering matches the site's timezone like every other datetime here.
		$now     = current_time( 'mysql' );
		$order   = 'ORDER BY (COALESCE(s.start_datetime, b.created_at) >= %s) DESC,
			CASE WHEN COALESCE(s.start_datetime, b.created_at) >= %s THEN COALESCE(s.start_datetime, b.created_at) END ASC,
			COALESCE(s.start_datetime, b.created_at) DESC';
		$params[] = $now;
		$params[] = $now;

		$sql = "SELECT {$select} FROM {$bookings_table} b {$join} WHERE {$where_sql} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$count_sql = "SELECT COUNT(*) FROM {$bookings_table} b {$join} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Drop the two ORDER BY timestamps and the LIMIT/OFFSET pair.
		$count_params = array_slice( $params, 0, count( $params ) - 4 );
		$total        = $count_params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery

		return array(
			'items' => array_map( array( __CLASS__, 'cast' ), $rows ?: array() ),
			'total' => $total,
		);
	}

	/**
	 * Booking counts by status within [$from, $to), keyed by slot start
	 * time (when the appointment happens, matching query()'s own
	 * from/to semantics — see its docblock) — the aggregate query
	 * DETAILED-SPEC.md §A8 describes. INNER JOIN, not LEFT: a booking
	 * whose slot was deleted has no date to count it under, same
	 * exclusion query() already applies.
	 *
	 * @return array Every status key present (0 if none), plus 'total'.
	 */
	public static function get_status_counts( DateTime $from, DateTime $to ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.status, COUNT(*) AS count
				 FROM {$bookings_table} b
				 INNER JOIN {$slots_table} s ON s.id = b.slot_id
				 WHERE s.start_datetime >= %s AND s.start_datetime < %s
				 GROUP BY b.status",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$counts = array_fill_keys( self::VALID_STATUSES, 0 );
		foreach ( $rows ?: array() as $row ) {
			$counts[ $row['status'] ] = (int) $row['count'];
		}
		$counts['total'] = array_sum( $counts );

		return $counts;
	}

	/**
	 * Sum of payment_amount for paid bookings in [from, to) — the
	 * Dashboard's Revenue stat (DETAILED-SPEC.md §A8, Phase 1.5 step 10).
	 * One total per period, no breakdown by bookable item/type — that
	 * stays Pro (B8). Same INNER JOIN-onto-slots convention as
	 * get_status_counts() so a booking whose slot was deleted doesn't
	 * inflate the total.
	 */
	public static function get_revenue( DateTime $from, DateTime $to ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(b.payment_amount)
				 FROM {$bookings_table} b
				 INNER JOIN {$slots_table} s ON s.id = b.slot_id
				 WHERE s.start_datetime >= %s AND s.start_datetime < %s
				 AND b.payment_status IN ('paid_manual','paid_online')",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return null === $total ? 0.0 : (float) $total;
	}

	/**
	 * " AND <column> IN (the items at this location)", or '' for every
	 * location — the Analytics screen's location filter (QA report §6.1
	 * A1). Each aggregate below takes an optional $location_id and appends
	 * this to its WHERE.
	 *
	 * @param int    $location_id Location id; 0 = all.
	 * @param string $column      The bookable_item_id column to narrow.
	 * @return string
	 */
	public static function location_sql( $location_id, $column ) {
		global $wpdb;

		$location_id = absint( $location_id );
		if ( ! $location_id ) {
			return '';
		}

		return " AND {$column} IN (SELECT id FROM {$wpdb->prefix}byzo_bookable_items WHERE location_id = {$location_id})";
	}

	/**
	 * Daily booking counts over [from, to) — the Dashboard trend chart's
	 * data source. Same INNER JOIN onto wp_byzo_slots as get_status_counts()
	 * (a booking whose slot was deleted has no date to bucket it under).
	 * start_datetime is stored as a naive local timestamp (see
	 * BYZO_Slot::query()'s own "{$from} 00:00:00" string comparison — no
	 * UTC conversion anywhere in this codebase), so DATE() on it directly
	 * buckets by the same local date get_status_counts()'s today/this_week/
	 * this_month ranges already use — no CONVERT_TZ needed or correct here.
	 *
	 * @return array Zero-filled for every date in the range (no gaps for
	 *               days with no bookings), each entry { date, total }.
	 */
	public static function get_daily_counts( DateTime $from, DateTime $to, $location_id = 0 ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		$in_location = self::location_sql( $location_id, 'b.bookable_item_id' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(s.start_datetime) AS day, COUNT(*) AS count
				 FROM {$bookings_table} b
				 INNER JOIN {$slots_table} s ON s.id = b.slot_id
				 WHERE s.start_datetime >= %s AND s.start_datetime < %s
				 {$in_location}
				 GROUP BY day",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$by_date = array();
		foreach ( $rows ?: array() as $row ) {
			$by_date[ $row['day'] ] = (int) $row['count'];
		}

		$series = array();
		$cursor = new DateTime( $from->format( 'Y-m-d' ), $from->getTimezone() );
		$end    = new DateTime( $to->format( 'Y-m-d' ), $from->getTimezone() );
		while ( $cursor < $end ) {
			$key      = $cursor->format( 'Y-m-d' );
			$series[] = array(
				'date'  => $key,
				'total' => $by_date[ $key ] ?? 0,
			);
			$cursor->modify( '+1 day' );
		}

		return $series;
	}

	/**
	 * Revenue summed per bookable item over [from, to) — same
	 * SUM(payment_amount) WHERE payment_status IN (...) as get_revenue(),
	 * grouped. Pro's Analytics dashboard (DETAILED-SPEC.md §3 B8,
	 * Phase 2 step 10); Free itself only ever shows the one-number total.
	 *
	 * @return array<int,array{bookable_item_id:int,revenue:float,count:int}>
	 */
	public static function get_revenue_by_bookable_item( DateTime $from, DateTime $to, $location_id = 0 ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		$in_location = self::location_sql( $location_id, 'b.bookable_item_id' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.bookable_item_id, SUM(b.payment_amount) AS revenue, COUNT(*) AS count
				 FROM {$bookings_table} b
				 INNER JOIN {$slots_table} s ON s.id = b.slot_id
				 WHERE s.start_datetime >= %s AND s.start_datetime < %s
				 {$in_location}
				 AND b.payment_status IN ('paid_manual','paid_online')
				 GROUP BY b.bookable_item_id",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'bookable_item_id' => (int) $row['bookable_item_id'],
					'revenue'          => (float) $row['revenue'],
					'count'            => (int) $row['count'],
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * Commission owed per bookable item over [from, to) — Pro's Payouts
	 * screen (DETAILED-SPEC.md §3 B7 Appointment, Phase 2 step 12
	 * continued). Only items with a configured commission_type are
	 * included at all; commission_owed is computed in PHP after the
	 * aggregate since the arithmetic (percentage-of-revenue vs.
	 * fixed-per-booking) depends on each item's own type, not something
	 * SQL should carry.
	 *
	 * @return array<int,array{bookable_item_id:int,commission_type:string,commission_rate:float,revenue:float,count:int,commission_owed:float}>
	 */
	public static function get_commission_by_bookable_item( DateTime $from, DateTime $to ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';
		$items_table    = $wpdb->prefix . 'byzo_bookable_items';

		// A plain double-LEFT-JOIN (items -> bookings -> slots) doesn't work
		// here: putting the date-range/payment-status filter on the SLOTS
		// join only nulls out the slot columns for a disqualified booking,
		// not the booking row itself, so an unpaid or out-of-range booking
		// still contributed its payment_amount to the SUM. Pre-filtering
		// to exactly the qualifying bookings in a derived table, THEN
		// LEFT JOINing that onto items, is what actually excludes them
		// while still keeping a configured item with zero qualifying
		// bookings in the result (revenue/count = 0, not missing entirely).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id AS bookable_item_id, i.commission_type, i.commission_rate,
				        COALESCE(SUM(qualified.payment_amount), 0) AS revenue, COUNT(qualified.id) AS count
				 FROM {$items_table} i
				 LEFT JOIN (
				     SELECT b.id, b.bookable_item_id, b.payment_amount
				     FROM {$bookings_table} b
				     INNER JOIN {$slots_table} s ON s.id = b.slot_id
				     WHERE s.start_datetime >= %s AND s.start_datetime < %s
				     AND b.payment_status IN ('paid_manual','paid_online')
				 ) qualified ON qualified.bookable_item_id = i.id
				 WHERE i.commission_type IS NOT NULL
				 GROUP BY i.id",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				$revenue = (float) $row['revenue'];
				$count   = (int) $row['count'];
				$rate    = (float) $row['commission_rate'];
				$owed    = 'percentage' === $row['commission_type'] ? $revenue * ( $rate / 100 ) : $count * $rate;

				return array(
					'bookable_item_id' => (int) $row['bookable_item_id'],
					'commission_type'  => $row['commission_type'],
					'commission_rate'  => $rate,
					'revenue'          => $revenue,
					'count'            => $count,
					'commission_owed'  => round( $owed, 2 ),
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * Same as get_revenue_by_bookable_item(), grouped by booking type
	 * instead (appointment/table/event/slot).
	 *
	 * @return array<int,array{type:string,revenue:float,count:int}>
	 */
	public static function get_revenue_by_type( DateTime $from, DateTime $to, $location_id = 0 ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';
		$items_table    = $wpdb->prefix . 'byzo_bookable_items';

		$in_location = self::location_sql( $location_id, 'b.bookable_item_id' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.type, SUM(b.payment_amount) AS revenue, COUNT(*) AS count
				 FROM {$bookings_table} b
				 INNER JOIN {$slots_table} s ON s.id = b.slot_id
				 INNER JOIN {$items_table} i ON i.id = b.bookable_item_id
				 WHERE s.start_datetime >= %s AND s.start_datetime < %s
				 {$in_location}
				 AND b.payment_status IN ('paid_manual','paid_online')
				 GROUP BY i.type",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'type'    => $row['type'],
					'revenue' => (float) $row['revenue'],
					'count'   => (int) $row['count'],
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * No-show rate over [from, to) — no_show / (completed + no_show),
	 * overall and per bookable item. Deliberately excludes
	 * cancelled/rejected/pending from the denominator: a no-show rate is
	 * about people who booked and didn't show up, not every possible
	 * booking outcome.
	 *
	 * @return array{overall:array{no_show:int,completed:int,rate:float},by_bookable_item:array<int,array{bookable_item_id:int,no_show:int,completed:int,rate:float}>}
	 */
	public static function get_no_show_rate( DateTime $from, DateTime $to, $location_id = 0 ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		$in_location = self::location_sql( $location_id, 'b.bookable_item_id' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.bookable_item_id, b.status, COUNT(*) AS count
				 FROM {$bookings_table} b
				 INNER JOIN {$slots_table} s ON s.id = b.slot_id
				 WHERE s.start_datetime >= %s AND s.start_datetime < %s
				 {$in_location}
				 AND b.status IN ('completed','no_show')
				 GROUP BY b.bookable_item_id, b.status",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$by_item       = array();
		$overall_no_show  = 0;
		$overall_completed = 0;

		foreach ( $rows ?: array() as $row ) {
			$id = (int) $row['bookable_item_id'];
			if ( ! isset( $by_item[ $id ] ) ) {
				$by_item[ $id ] = array(
					'bookable_item_id' => $id,
					'no_show'          => 0,
					'completed'        => 0,
				);
			}
			$by_item[ $id ][ $row['status'] ] = (int) $row['count'];

			if ( 'no_show' === $row['status'] ) {
				$overall_no_show += (int) $row['count'];
			} else {
				$overall_completed += (int) $row['count'];
			}
		}

		foreach ( $by_item as &$item ) {
			$denominator  = $item['no_show'] + $item['completed'];
			$item['rate'] = $denominator > 0 ? $item['no_show'] / $denominator : 0.0;
		}
		unset( $item );

		$overall_denominator = $overall_no_show + $overall_completed;

		return array(
			'overall'          => array(
				'no_show'   => $overall_no_show,
				'completed' => $overall_completed,
				'rate'      => $overall_denominator > 0 ? $overall_no_show / $overall_denominator : 0.0,
			),
			'by_bookable_item' => array_values( $by_item ),
		);
	}

	/**
	 * Which bookable items got booked most over [from, to), busiest first.
	 * Every non-cancelled/rejected booking counts (pending/confirmed/
	 * completed/no_show all reflect real demand for that item, unlike the
	 * no-show/revenue metrics above which specifically care about outcome).
	 *
	 * @return array<int,array{bookable_item_id:int,count:int}>
	 */
	public static function get_popular_bookable_items( DateTime $from, DateTime $to, $limit = 10, $location_id = 0 ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';
		$limit          = max( 1, min( 50, (int) $limit ) );

		$in_location = self::location_sql( $location_id, 'b.bookable_item_id' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.bookable_item_id, COUNT(*) AS count
				 FROM {$bookings_table} b
				 INNER JOIN {$slots_table} s ON s.id = b.slot_id
				 WHERE s.start_datetime >= %s AND s.start_datetime < %s
				 {$in_location}
				 AND b.status NOT IN ('cancelled','rejected')
				 GROUP BY b.bookable_item_id
				 ORDER BY count DESC
				 LIMIT %d",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' ),
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'bookable_item_id' => (int) $row['bookable_item_id'],
					'count'            => (int) $row['count'],
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * Which hour of the day (0-23, site-local — start_datetime is already
	 * stored site-local, DETAILED-SPEC.md §5 item 1) gets booked most over
	 * [from, to). Same non-cancelled/rejected filter as
	 * get_popular_bookable_items().
	 *
	 * @return array<int,array{hour:int,count:int}>
	 */
	public static function get_popular_hours( DateTime $from, DateTime $to, $location_id = 0 ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		$in_location = self::location_sql( $location_id, 'b.bookable_item_id' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT HOUR(s.start_datetime) AS hour, COUNT(*) AS count
				 FROM {$bookings_table} b
				 INNER JOIN {$slots_table} s ON s.id = b.slot_id
				 WHERE s.start_datetime >= %s AND s.start_datetime < %s
				 {$in_location}
				 AND b.status NOT IN ('cancelled','rejected')
				 GROUP BY hour
				 ORDER BY hour ASC",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				return array(
					'hour'  => (int) $row['hour'],
					'count' => (int) $row['count'],
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * Creates a booking against a slot, atomically decrementing the
	 * slot's capacity_remaining. Uses a single conditional UPDATE
	 * (`WHERE capacity_remaining >= party_size`) as the concurrency guard
	 * — two simultaneous requests for the last seat will race on this
	 * UPDATE at the database level, and only one can affect a row (MySQL
	 * serializes concurrent UPDATEs to the same row), so exactly one
	 * loses and gets `byzo_slot_full` instead of double-booking.
	 *
	 * @return int|WP_Error New booking ID, or WP_Error.
	 */
	public static function create( $data ) {
		$validated = self::validate_for_create( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Phase 2 step 5 (DETAILED-SPEC.md §3 B7) — "any available staff":
		// booking by service_id + start_datetime instead of a specific
		// slot_id delegates to create_for_service(), which resolves WHICH
		// staff member's slot to actually claim. A direct slot_id booking
		// (every Free booking, and any Pro booking where the customer
		// picked a specific staff member) is completely unchanged below.
		if ( $validated['service_id'] && ! $validated['slot_id'] ) {
			return self::create_for_service( $validated );
		}

		$slot = BYZO_Slot::get( $validated['slot_id'] );
		if ( ! $slot || 'open' !== $slot['status'] ) {
			return new WP_Error( 'byzo_slot_unavailable', __( 'This time slot is no longer available.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		if ( $validated['service_id'] ) {
			$offering = array_map( 'absint', (array) apply_filters( 'byzo_bookable_items_offering_service', array(), $validated['service_id'] ) );
			if ( ! in_array( (int) $slot['bookable_item_id'], $offering, true ) ) {
				return new WP_Error( 'byzo_service_unavailable', __( 'This staff member does not offer that service.', 'bookyzo' ), array( 'status' => 400 ) );
			}
		}

		return self::attempt_create_on_slot( $slot, $validated, $validated['service_id'] );
	}

	/**
	 * "Any available staff" resolution (DETAILED-SPEC.md §3 B7, Phase 2
	 * step 5): finds every bookable item that offers $validated['service_id']
	 * (via the byzo_bookable_items_offering_service filter — Free has no
	 * services table of its own, Pro's BYZOP_Services hooks this), then
	 * every one of THEIR slots open at the requested start_datetime, and
	 * tries the same atomic capacity claim attempt_create_on_slot() uses
	 * against each candidate in turn until one succeeds. A slot-specific
	 * failure (raced away between the SELECT and the UPDATE) tries the
	 * next candidate rather than failing the whole request — that's the
	 * entire point of "any available staff." Any other kind of error
	 * (a real DB failure) propagates immediately instead of being masked
	 * by a misleading "nothing was available" response.
	 *
	 * @return int|WP_Error
	 */
	private static function create_for_service( $validated ) {
		/**
		 * Filters which bookable item IDs offer a given service. Free
		 * always returns the untouched default (none) — this is a Pro
		 * concept (wp_byzo_bookable_item_services); the filter point lives
		 * in Free so /bookings doesn't need to know Pro's table shape.
		 *
		 * @param int[] $bookable_item_ids
		 * @param int   $service_id
		 */
		$candidates = apply_filters( 'byzo_bookable_items_offering_service', array(), $validated['service_id'] );
		$candidates = array_values( array_filter( array_map( 'absint', (array) $candidates ) ) );

		if ( empty( $candidates ) ) {
			return new WP_Error( 'byzo_service_unavailable', __( 'No staff currently offer this service.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$slots = BYZO_Slot::open_at( $candidates, $validated['start_datetime'] );

		if ( empty( $slots ) ) {
			return new WP_Error( 'byzo_slot_unavailable', __( 'This time is no longer available.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		foreach ( $slots as $slot ) {
			$result = self::attempt_create_on_slot( $slot, $validated, $validated['service_id'] );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! in_array( $result->get_error_code(), array( 'byzo_slot_full', 'byzo_slot_unavailable' ), true ) ) {
				return $result;
			}
		}

		return new WP_Error( 'byzo_slot_full', __( 'This time was just booked by someone else. Please pick another time.', 'bookyzo' ), array( 'status' => 409 ) );
	}

	/**
	 * What a booking of $slot costs, computed exactly the way create()
	 * charges it. Shared by attempt_create_on_slot() and the public
	 * POST /bookings/quote route, so the total the widget shows before
	 * the customer confirms can never drift from the amount actually
	 * charged (QA report 2026-09-22 B3: the widget re-did the arithmetic
	 * in JS and missed Pro's pricing rules and booking fees).
	 *
	 * Pure — reads settings/filters, writes nothing. Price-resolving
	 * filters (byzo_ticket_tier_price, byzo_service_price, byzo_unit_price,
	 * byzo_resolve_addons, byzo_deposit_amount) must stay side-effect
	 * free for that reason; claiming capacity lives in
	 * byzo_claim_ticket_tier instead.
	 *
	 * @param array    $slot           A BYZO_Slot::cast() row.
	 * @param int      $party_size     Quantity (seats/tickets/places).
	 * @param int|null $ticket_tier_id Event ticket tier, or null.
	 * @param int|null $service_id     Service being booked, or null.
	 * @param int[]    $addon_ids      Requested add-on ids (unvalidated; resolved server-side).
	 * @return array Keys: unit_price, subtotal, addons, addons_total, booking_fee_amount, tax_rate, tax_amount, payment_amount, deposit_amount. Money fields are null when there's nothing to charge.
	 */
	public static function price_breakdown( $slot, $party_size, $ticket_tier_id = null, $service_id = null, $addon_ids = array(), $discount_code = '', $customer_email = '' ) {
		$party_size = max( 1, (int) $party_size );

		// payment_amount is ALWAYS server-computed, never taken from client
		// input (validate_for_create() doesn't even look at a
		// client-supplied payment_amount — see its docblock) — this is what
		// BYZO_Stripe's payment-intent creation charges, so trusting a
		// client-suppliable amount here would let a guest pay $0.01 for an
		// expensive service. NULL price (a free item, DETAILED-SPEC.md
		// §1.1) means nothing to charge.
		//
		// Default source is the bookable item's own price; a service
		// booking (Phase 2 step 5, DETAILED-SPEC.md §3 B7) overrides it via
		// byzo_service_price — Pro resolves wp_byzo_bookable_item_services'
		// price_override, falling back to the service's own default_price.
		// Free never hooks this filter, so a direct (non-service) booking
		// is byte-for-byte the same computation as before this step.
		$item      = BYZO_Bookable_Item::get( $slot['bookable_item_id'] );
		$unit_price = $item ? $item['price'] : null;

		// Ticket tier pricing (Phase 2 step 12 continued) — same
		// precedence shape as service pricing below (checked before the
		// bookable item's own flat price), but mutually exclusive with it
		// in practice (a ticket_tier_id only ever accompanies an Event
		// booking, a service_id only an Appointment one).
		if ( $ticket_tier_id ) {
			$tier_price = apply_filters( 'byzo_ticket_tier_price', null, $ticket_tier_id );
			if ( null !== $tier_price ) {
				$unit_price = (float) $tier_price;
			}
		}

		if ( $service_id ) {
			$service_price = apply_filters( 'byzo_service_price', null, $service_id, $slot['bookable_item_id'] );
			if ( null !== $service_price ) {
				$unit_price = (float) $service_price;
			}
		}

		// Seasonal/dynamic pricing (Phase 2 step 14, DETAILED-SPEC.md §3
		// B6) — applied BEFORE tax, same "adjust the base first, tax the
		// adjusted total" order §A7's own tax step already established.
		// Free never hooks this (no pricing-rules UI exists there); Pro
		// resolves its own wp_byzo_pricing_rules against the slot's own
		// start_datetime for season/weekday rules or against (start_datetime
		// - now) for lead-time rules. A null $unit_price (free item) is
		// passed through unadjusted — nothing to adjust a percentage of.
		if ( null !== $unit_price ) {
			$unit_price = (float) apply_filters( 'byzo_unit_price', $unit_price, $item['id'], $slot );
		}

		$subtotal = null !== $unit_price ? round( $unit_price * $party_size, 2 ) : null;

		// Add-ons (Phase 2 step 17, DETAILED-SPEC.md §3 B6) — the client
		// submits only `addon_ids`; the price NEVER comes from the client
		// (same rule payment_amount as a whole already follows). Pro
		// resolves its own wp_byzo_addons table via this filter, silently
		// dropping any id that isn't active or doesn't actually apply to
		// this bookable item rather than erroring — same "the real wall
		// answers honestly" shape as an unavailable slot. Free never hooks
		// this, so a request with no addon_ids computes exactly as before.
		$resolved_addons = ! empty( $addon_ids )
			? (array) apply_filters( 'byzo_resolve_addons', array(), $addon_ids, $item['id'] )
			: array();
		$addons_total    = array_sum( array_column( $resolved_addons, 'price' ) );

		// Flat booking fee (Phase 2 step 17) — once per booking, not
		// multiplied by party_size, unlike unit_price above.
		$booking_fee_amount = ( null !== $item && null !== $item['booking_fee'] ) ? (float) $item['booking_fee'] : null;

		// Discount / package codes (QA report 2026-09-22 §6.1 A2). The code
		// comes from the customer; what it's worth never does. An add-on
		// resolves it through byzo_booking_discount and answers null (no
		// code system here), a WP_Error (not valid for this booking), or
		// { code, amount, package_purchase_id? }. It comes off the price and
		// add-ons, before tax, and never off the booking fee.
		$discount_code        = strtoupper( trim( (string) $discount_code ) );
		$discount_amount      = null;
		$discount_error       = null;
		$package_purchase_id  = null;
		if ( '' !== $discount_code ) {
			$discountable = ( null !== $subtotal ? $subtotal : 0 ) + $addons_total;
			$discount     = apply_filters(
				'byzo_booking_discount',
				null,
				array(
					'code'           => $discount_code,
					'item'           => $item,
					'slot'           => $slot,
					'party_size'     => $party_size,
					'service_id'     => $service_id,
					'unit_price'     => $unit_price,
					'subtotal'       => $subtotal,
					'addons_total'   => $addons_total,
					'discountable'   => $discountable,
					'customer_email' => strtolower( trim( (string) $customer_email ) ),
				)
			);
			if ( is_wp_error( $discount ) ) {
				$discount_error = $discount->get_error_message();
			} elseif ( ! is_array( $discount ) ) {
				$discount_error = __( 'That code is not valid.', 'bookyzo' );
			} else {
				$discount_amount     = round( max( 0, min( (float) $discount['amount'], $discountable ) ), 2 );
				$package_purchase_id = ! empty( $discount['package_purchase_id'] ) ? (int) $discount['package_purchase_id'] : null;
			}
		}

		$has_charge    = null !== $subtotal || $addons_total > 0 || null !== $booking_fee_amount;
		$taxable_total = ( null !== $subtotal ? $subtotal : 0 ) + $addons_total - (float) $discount_amount + (float) $booking_fee_amount;

		// Flat site-wide tax rate (added 2026-09-05, Phase 1.5 step 9,
		// DETAILED-SPEC.md §A7) — tax_amount is broken out from
		// payment_amount for invoice display; both NULL when there's
		// nothing to charge in the first place. `tax_rate_override`
		// (added 2026-09-12, Phase 2 step 14, DETAILED-SPEC.md §1.1) is a
		// Pro-only-WRITTEN Free-schema column — when a bookable item has
		// one set, it replaces the site-wide rate outright for THIS
		// booking (not added to it); Free itself never writes the column,
		// so an install with no Pro (or Pro's override cleared) computes
		// byte-for-byte the same as before this step. Tax applies to the
		// WHOLE taxable total (base + add-ons + booking fee), matching how
		// a real invoice taxes every line item, not just the base price.
		$tax_rate       = null !== $item && null !== $item['tax_rate_override'] ? (float) $item['tax_rate_override'] : (float) BYZO_Settings::get()['tax_rate'];
		$tax_amount     = ( $has_charge && $tax_rate > 0 ) ? round( $taxable_total * $tax_rate / 100, 2 ) : null;
		$payment_amount = $has_charge ? round( $taxable_total + (float) $tax_amount, 2 ) : null;

		// Deposits/partial payments (Phase 2 step 6, DETAILED-SPEC.md §A7)
		// — how much of payment_amount is actually due online now, vs. the
		// remainder collected later. BYZO_Capabilities::deposits_enabled()
		// (default false) is the master on/off switch — the byzo_deposit_amount
		// filter is only even consulted once it's on, same two-filter
		// shape (a boolean gate + a value resolver) B7's service pricing
		// already established with byzo_service_price. Clamped to [0, payment_amount] so a
		// misconfigured Pro filter can never charge more than the booking
		// is actually worth or go negative.
		$deposit_amount = null;
		if ( null !== $payment_amount && BYZO_Capabilities::deposits_enabled() ) {
			$deposit_amount = apply_filters( 'byzo_deposit_amount', null, $payment_amount, $slot['bookable_item_id'] );
			if ( null !== $deposit_amount ) {
				$deposit_amount = round( max( 0, min( (float) $deposit_amount, $payment_amount ) ), 2 );
			}
		}

		return array(
			'unit_price'         => $unit_price,
			'subtotal'           => $subtotal,
			'addons'             => $resolved_addons,
			'addons_total'       => round( (float) $addons_total, 2 ),
			'booking_fee_amount' => $booking_fee_amount,
			'tax_rate'           => $tax_rate,
			'tax_amount'         => $tax_amount,
			'payment_amount'     => $payment_amount,
			'deposit_amount'     => $deposit_amount,
			'discount_code'      => null !== $discount_amount ? $discount_code : null,
			'discount_amount'    => $discount_amount,
			'discount_error'     => $discount_error,
			'package_purchase_id' => $package_purchase_id,
		);
	}

	/**
	 * Whether new bookings (and reschedules onto it) may land on this
	 * bookable item. /slots already hides an inactive or archived item's
	 * times, but a slot id POSTed directly was still accepted (QA report
	 * 2026-09-22 B6). Admins may still book inactive items — e.g. a
	 * private session on an item that's hidden from the public widget —
	 * but nobody can book an archived or deleted one.
	 *
	 * @param array|null $item
	 */
	private static function item_accepts_bookings( $item ) {
		if ( ! $item || 'archived' === $item['status'] ) {
			return false;
		}

		return 'active' === $item['status'] || current_user_can( 'manage_options' );
	}

	/**
	 * Whether one booking takes the whole slot instead of party_size
	 * places in it. True for tables (DETAILED-SPEC.md §1.3: "A table for
	 * six either exists at 7pm or it doesn't") — the slot's capacity is
	 * the table's seat count, which only decides whether a party FITS;
	 * a party of 2 at a 4-seat table still occupies the table. Before
	 * 2026-09-22 (QA report B4) tables were claimed per seat, leaving the
	 * other two seats of a booked table open to a second party.
	 *
	 * @param array|null $item The slot's bookable item.
	 */
	private static function claims_whole_slot( $item ) {
		/**
		 * Filters whether a booking on this bookable item takes the whole
		 * slot rather than party_size places in it.
		 *
		 * @param bool       $whole Default: true for table items.
		 * @param array|null $item  The bookable item.
		 */
		return (bool) apply_filters( 'byzo_booking_claims_whole_slot', $item && 'table' === $item['type'], $item );
	}

	/**
	 * Atomically takes capacity on an open slot — party_size places, or
	 * the whole slot for claims_whole_slot() items — and flips it to
	 * `full` once nothing is left. One conditional UPDATE is the
	 * concurrency guard (see create()'s docblock): of two requests racing
	 * for the last place, only one can match the WHERE.
	 *
	 * @return bool False when the slot is no longer open or the party doesn't fit.
	 */
	private static function claim_slot_capacity( $slot_id, $party_size, $item ) {
		global $wpdb;

		$slots_table = $wpdb->prefix . 'byzo_slots';
		$take        = self::claims_whole_slot( $item ) ? 'capacity_remaining' : '%d';
		$params      = self::claims_whole_slot( $item ) ? array( $slot_id, $party_size ) : array( $party_size, $slot_id, $party_size );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name and a fixed column expression, not user input; $take is either a column name or one more %d, and $params always matches it.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$slots_table} SET capacity_remaining = capacity_remaining - {$take} WHERE id = %d AND status = 'open' AND capacity_remaining >= %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( ! $claimed ) {
			return false;
		}

		// Slot is now at 0 remaining -> flip it to 'full' so /slots stops offering it.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$slots_table} SET status = 'full' WHERE id = %d AND capacity_remaining <= 0 AND status = 'open'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slot_id
			)
		);

		return true;
	}

	/**
	 * Gives back what claim_slot_capacity() took: party_size places, or —
	 * for claims_whole_slot() items — the whole table again, i.e. its seat
	 * count (never more, so a booking whose party_size was later raised by
	 * combine_tables() can't overfill the primary table's slot). A slot
	 * that was 'full' flips back to 'open'; the CASE keeps a 'blocked' slot
	 * (a blackout added after the booking was made) from being silently
	 * reopened.
	 */
	private static function release_slot_capacity( $slot_id, $party_size, $item ) {
		global $wpdb;

		$slots_table = $wpdb->prefix . 'byzo_slots';

		if ( self::claims_whole_slot( $item ) ) {
			$set    = 'capacity_remaining = GREATEST( capacity_remaining, %d )';
			$amount = max( 1, (int) $item['capacity'] );
		} else {
			$set    = 'capacity_remaining = capacity_remaining + %d';
			$amount = (int) $party_size;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name and a fixed SET clause, not user input; $set holds exactly one %d, matching $amount.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$slots_table}
				 SET {$set},
				     status = CASE WHEN status = 'full' THEN 'open' ELSE status END
				 WHERE id = %d",
				$amount,
				$slot_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * The actual atomic-claim-and-insert, shared by both the direct
	 * slot_id path and create_for_service()'s candidate loop above — this
	 * is exactly what create() used to do inline before Phase 2 step 5
	 * needed the same logic reusable against more than one candidate slot.
	 *
	 * @param array    $slot       A BYZO_Slot::cast() row (already known open at call time — re-checked atomically below regardless).
	 * @param array    $validated  validate_for_create()'s return value.
	 * @param int|null $service_id Null for a direct booking; the service being booked otherwise (drives price resolution and is stored on the row).
	 * @return int|WP_Error New booking ID, or WP_Error — byzo_slot_full/byzo_slot_unavailable specifically mean THIS slot didn't work (the caller may have another candidate to try), anything else is a real failure.
	 */
	private static function attempt_create_on_slot( $slot, $validated, $service_id ) {
		if ( ! $slot || 'open' !== $slot['status'] ) {
			return new WP_Error( 'byzo_slot_unavailable', __( 'This time slot is no longer available.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		// Same rule /slots applies when listing times (see
		// BYZO_Slot::earliest_bookable_start()) — without it a slot id
		// that has already started, or started weeks ago, was bookable by
		// anyone who POSTed it directly.
		$slot_item = BYZO_Bookable_Item::get( $slot['bookable_item_id'] );
		if ( ! self::item_accepts_bookings( $slot_item ) ) {
			return new WP_Error( 'byzo_item_unavailable', __( 'This is not available for booking right now.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		if ( BYZO_Slot::lead_time_applies( $slot['bookable_item_id'] ) ) {
			if ( $slot['start_datetime'] < BYZO_Slot::earliest_bookable_start( $slot_item ? $slot_item['type'] : '' ) ) {
				return new WP_Error( 'byzo_slot_unavailable', __( 'This time can no longer be booked online. Please pick a later time.', 'bookyzo' ), array( 'status' => 409 ) );
			}
		}

		// Bookings that use the same discount or package code run one at a
		// time: a code's uses and a package's credits are counted from the
		// bookings table, so two at once could both pass the check and take
		// one use too many (QA report 2026-09-26 M5).
		$code_lock = ! empty( $validated['discount_code'] ) ? self::lock_code( $validated['discount_code'] ) : '';
		if ( false === $code_lock ) {
			return new WP_Error( 'byzo_code_busy', __( 'That code is being used for another booking right now. Please try again.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		try {
			$booking_id = self::claim_and_insert( $slot, $slot_item, $validated, $service_id );
		} finally {
			self::unlock_code( $code_lock );
		}

		if ( is_wp_error( $booking_id ) ) {
			return $booking_id;
		}

		/**
		 * Fires after a booking is created — status is 'pending' unless
		 * byzo_settings.auto_confirm made it 'confirmed' immediately (see
		 * claim_and_insert()). Notification/payment logic (Phase 1 steps
		 * 7-8, Phase 1.5 step 6) hooks this rather than living inside
		 * create(); BYZO_Notifications checks the booking's actual status to
		 * decide which customer template to send.
		 */
		do_action( 'byzo_booking_created', $booking_id );

		return $booking_id;
	}

	/**
	 * A MySQL named lock per code, held for one booking's claim and insert.
	 *
	 * @param string $code The discount or package code.
	 * @return string|false The lock name, or false when it couldn't be had within a few seconds.
	 */
	private static function lock_code( $code ) {
		global $wpdb;

		$name = 'byzo_code_' . md5( $wpdb->prefix . strtoupper( trim( (string) $code ) ) );

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) ) ? $name : false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data.
	}

	private static function unlock_code( $name ) {
		global $wpdb;

		if ( $name ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, not data.
		}
	}

	/**
	 * attempt_create_on_slot()'s second half: check the code, take the
	 * place, work out the price and insert the row.
	 *
	 * @return int|WP_Error New booking ID.
	 */
	private static function claim_and_insert( $slot, $slot_item, $validated, $service_id ) {
		global $wpdb;

		$party_size = $validated['party_size'];

		// A code that can't be used stops the booking before any place is
		// taken, with the reason (QA report §6.1 A2).
		if ( ! empty( $validated['discount_code'] ) ) {
			$check = self::price_breakdown( $slot, $party_size, $validated['ticket_tier_id'] ?? null, $service_id, $validated['addon_ids'] ?? array(), $validated['discount_code'], $validated['customer_email'] );
			if ( $check['discount_error'] ) {
				return new WP_Error( 'byzo_invalid_code', $check['discount_error'], array( 'status' => 400 ) );
			}
		}

		if ( ! self::claim_slot_capacity( $slot['id'], $party_size, $slot_item ) ) {
			return new WP_Error( 'byzo_slot_full', __( 'This time slot was just booked by someone else. Please pick another time.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		// Ticket tiers (Phase 2 step 12 continued, DETAILED-SPEC.md §3 B7
		// Event) — when a specific tier was requested, ALSO atomically
		// claim capacity on that tier, mirroring the slot's own atomic
		// claim just above. A sold-out tier must roll back the slot claim
		// already taken (the exact same release-back-into-the-pool UPDATE
		// the insert-failure path below already uses) rather than leaving
		// a phantom hold on the slot for a booking that never completes.
		// Free never hooks byzo_claim_ticket_tier (returns null = "no
		// tier system installed, nothing to claim"), so a direct
		// (non-tiered) booking is completely unaffected.
		$ticket_tier_id = ! empty( $validated['ticket_tier_id'] ) ? (int) $validated['ticket_tier_id'] : null;
		if ( $ticket_tier_id ) {
			$tier_claimed = apply_filters( 'byzo_claim_ticket_tier', null, $ticket_tier_id, $party_size );
			if ( false === $tier_claimed ) {
				self::release_slot_capacity( $slot['id'], $party_size, $slot_item );

				return new WP_Error( 'byzo_tier_sold_out', __( 'This ticket type just sold out. Please pick another.', 'bookyzo' ), array( 'status' => 409 ) );
			}
		}

		$now = current_time( 'mysql' );

		$price = self::price_breakdown( $slot, $party_size, $ticket_tier_id, $service_id, $validated['addon_ids'] ?? array(), $validated['discount_code'] ?? '', $validated['customer_email'] );

		// The code passed the check above but not now (it changed in
		// between): give the place back rather than book at full price
		// without saying so (QA report 2026-09-26 M5).
		if ( ! empty( $validated['discount_code'] ) && $price['discount_error'] ) {
			self::release_slot_capacity( $slot['id'], $party_size, $slot_item );
			if ( $ticket_tier_id ) {
				do_action( 'byzo_release_ticket_tier', $ticket_tier_id, $party_size );
			}

			return new WP_Error( 'byzo_invalid_code', $price['discount_error'], array( 'status' => 409 ) );
		}

		$resolved_addons    = $price['addons'];
		$booking_fee_amount = $price['booking_fee_amount'];
		$tax_amount         = $price['tax_amount'];
		$payment_amount     = $price['payment_amount'];
		$deposit_amount     = $price['deposit_amount'];

		// byzo_settings.auto_confirm (DETAILED-SPEC.md §A4, added for the
		// deepened onboarding wizard's Confirmation step) — skips the
		// admin-review step entirely for businesses with no
		// capacity/legitimacy concerns. The TRANSITIONS state machine
		// itself is unchanged; this only picks the booking's *starting*
		// status, same capacity-decrement/payment logic either way.
		$status = ! empty( BYZO_Settings::get()['auto_confirm'] ) ? 'confirmed' : 'pending';

		// Upserts wp_byzo_customers by email (DETAILED-SPEC.md §1.5, added
		// 2026-09-05) — the customer record tracks their CURRENT
		// name/phone; this booking row keeps what was true at booking time
		// regardless of later changes to either.
		$customer_id = BYZO_Customer::upsert_by_email( $validated['customer_name'], $validated['customer_email'], $validated['customer_phone'] );

		$row = array(
			'slot_id'          => $slot['id'],
			'bookable_item_id' => $slot['bookable_item_id'],
			'service_id'       => $service_id ?: null,
			'ticket_tier_id'   => $ticket_tier_id,
			'customer_id'      => $customer_id ?: null,
			'customer_name'    => $validated['customer_name'],
			'customer_email'   => $validated['customer_email'],
			'customer_phone'   => $validated['customer_phone'],
			'customer_notes'   => $validated['customer_notes'],
			'party_size'       => $party_size,
			'status'           => $status,
			'payment_status'   => null !== $payment_amount && (float) $payment_amount <= 0 ? 'not_required' : 'unpaid',
			'payment_amount'   => $payment_amount,
			'deposit_amount'   => $deposit_amount,
			'tax_amount'       => $tax_amount,
			'booking_fee_amount' => $booking_fee_amount,
			'discount_code'    => $price['discount_code'],
			'discount_amount'  => $price['discount_amount'],
			'package_purchase_id' => $price['package_purchase_id'],
			'custom_fields'    => wp_json_encode( $validated['custom_fields'] ),
			'addons'           => wp_json_encode( $resolved_addons ),
			'attendees'        => wp_json_encode( $validated['attendees'] ),
			'cart_id'          => $validated['cart_id'],
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		$inserted = $wpdb->insert( self::table(), $row );

		if ( false === $inserted ) {
			// Booking insert failed after capacity was already taken — release it back rather than leaking a phantom hold.
			self::release_slot_capacity( $slot['id'], $party_size, $slot_item );

			return new WP_Error( 'byzo_db_error', __( 'Could not create booking.', 'bookyzo' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Transitions a booking's status, enforcing TRANSITIONS. Cancelling
	 * or no-show-ing a booking that held slot capacity releases it back
	 * (capacity_remaining++, slot status back to 'open' if it was 'full').
	 *
	 * @return true|WP_Error
	 */
	public static function update_status( $id, $new_status ) {
		global $wpdb;

		$booking = self::get( $id );
		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( $new_status, self::VALID_STATUSES, true ) ) {
			return new WP_Error( 'byzo_invalid_status', __( 'Invalid booking status.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$allowed = self::TRANSITIONS[ $booking['status'] ] ?? array();
		if ( ! in_array( $new_status, $allowed, true ) ) {
			return new WP_Error(
				'byzo_invalid_transition',
				sprintf(
					/* translators: 1: current status, 2: attempted new status */
					__( 'Cannot change status from "%1$s" to "%2$s".', 'bookyzo' ),
					$booking['status'],
					$new_status
				),
				array( 'status' => 400 )
			);
		}

		// `rejected` releases capacity same as cancel/no_show (added
		// 2026-09-05, Phase 1.6) — a pending booking already holds its
		// slot's capacity from create(), so declining it must give that
		// capacity back exactly like a cancellation would.
		$releases_capacity = in_array( $new_status, array( 'cancelled', 'no_show', 'rejected' ), true )
			&& in_array( $booking['status'], array( 'pending', 'confirmed' ), true );

		$updates = array( 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) );
		if ( 'cancelled' === $new_status ) {
			$updates['cancelled_at'] = current_time( 'mysql' );
		} elseif ( 'completed' === $new_status ) {
			// Added 2026-09-05 (Phase 1.6) — reserved for Pro's post-visit
			// follow-up email (FEATURES.md B5), which needs "N hours after
			// completion" to compute from; mirrors cancelled_at above.
			$updates['completed_at'] = current_time( 'mysql' );
		}

		$wpdb->update( self::table(), $updates, array( 'id' => $id ) );

		if ( $releases_capacity && $booking['slot_id'] ) {
			// Credit the seat(s) — or the whole table — back.
			self::release_slot_capacity( $booking['slot_id'], $booking['party_size'], BYZO_Bookable_Item::get( $booking['bookable_item_id'] ) );

			// Ticket tiers (Phase 2 step 12 continued) — the symmetric
			// release for byzo_claim_ticket_tier's atomic claim at create
			// time; without this a tier's capacity_remaining would never
			// recover after a cancellation, permanently under-selling it.
			// Free never hooks this (no tier system exists there), so a
			// direct booking triggers a no-op action.
			if ( $booking['ticket_tier_id'] ) {
				do_action( 'byzo_release_ticket_tier', $booking['ticket_tier_id'], $booking['party_size'] );
			}
		}

		do_action( 'byzo_booking_status_changed', $id, $new_status, $booking['status'] );

		return true;
	}

	/**
	 * Moves a booking to a different open slot (DETAILED-SPEC.md §1.4/§A4,
	 * ROADMAP.md Phase 1.6 step 3) — NOT a status transition, a `slot_id`
	 * change. Claims the new slot's capacity FIRST (same atomic
	 * conditional UPDATE create() uses), then releases the old slot's —
	 * in that order, so a failed claim leaves the old slot completely
	 * untouched rather than briefly holding neither. Allowed from
	 * `pending`/`confirmed` only; a `completed`/`cancelled`/`no_show`/
	 * `rejected` booking has nothing left to reschedule.
	 *
	 * @return true|WP_Error
	 */
	public static function reschedule( $id, $new_slot_id ) {
		global $wpdb;

		$booking = self::get( $id );
		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		if ( ! in_array( $booking['status'], array( 'pending', 'confirmed' ), true ) ) {
			return new WP_Error( 'byzo_invalid_transition', __( 'Only a pending or confirmed booking can be rescheduled.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( (int) $new_slot_id === (int) $booking['slot_id'] ) {
			return new WP_Error( 'byzo_same_slot', __( 'This booking is already on that time slot.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$new_slot = BYZO_Slot::get( $new_slot_id );
		if ( ! $new_slot || 'open' !== $new_slot['status'] ) {
			return new WP_Error( 'byzo_slot_unavailable', __( 'The selected time slot is not available.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		// A customer rescheduling from their account follows the same lead
		// time as a new booking; admins are exempt (see lead_time_applies()).
		$new_slot_item = BYZO_Bookable_Item::get( $new_slot['bookable_item_id'] );
		if ( ! self::item_accepts_bookings( $new_slot_item ) ) {
			return new WP_Error( 'byzo_item_unavailable', __( 'That is not available for booking right now.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		if ( BYZO_Slot::lead_time_applies( $new_slot['bookable_item_id'] ) ) {
			if ( $new_slot['start_datetime'] < BYZO_Slot::earliest_bookable_start( $new_slot_item ? $new_slot_item['type'] : '' ) ) {
				return new WP_Error( 'byzo_slot_unavailable', __( 'That time can no longer be booked online. Please pick a later time.', 'bookyzo' ), array( 'status' => 409 ) );
			}
		}

		$party_size = $booking['party_size'];

		if ( ! self::claim_slot_capacity( $new_slot_id, $party_size, $new_slot_item ) ) {
			return new WP_Error( 'byzo_slot_full', __( 'This time slot was just booked by someone else. Please pick another time.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		// Release the old slot back — same CASE-guarded credit as
		// update_status()'s cancel/no_show/rejected release, so a
		// 'blocked' old slot (e.g. a blackout added after this booking was
		// made) isn't silently reopened by this reschedule.
		if ( $booking['slot_id'] ) {
			self::release_slot_capacity( $booking['slot_id'], $party_size, BYZO_Bookable_Item::get( $booking['bookable_item_id'] ) );
		}

		$old_slot_id = $booking['slot_id'];

		$wpdb->update(
			self::table(),
			array(
				'slot_id'          => $new_slot_id,
				'bookable_item_id' => $new_slot['bookable_item_id'],
				'rescheduled_at'   => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		/**
		 * Fires after a booking is moved to a different slot. Not a status
		 * change (status is untouched), so this is deliberately its own
		 * action rather than reusing byzo_booking_status_changed.
		 */
		do_action( 'byzo_booking_rescheduled', $id, $new_slot_id, $old_slot_id );

		return true;
	}

	/**
	 * The guest self-service cancellation path (DETAILED-SPEC.md §A4/§A5)
	 * — a PUT-equivalent authorized by BYZO_Booking_Token instead of
	 * admin capability. Everything past the checks below (transition
	 * validity, capacity release, the cancellation email) is the exact
	 * same update_status() every other cancellation already goes through;
	 * this only adds the guest-specific guards admin cancellation is
	 * deliberately exempt from.
	 *
	 * @return true|WP_Error
	 */
	public static function cancel_via_token( $id, $token ) {
		$booking = self::get( $id );
		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		if ( ! BYZO_Booking_Token::verify( $id, $booking['customer_email'], $token ) ) {
			return new WP_Error( 'byzo_invalid_token', __( 'This cancellation link is invalid.', 'bookyzo' ), array( 'status' => 403 ) );
		}

		return self::cancel_within_policy( $booking );
	}

	/**
	 * Phase 1.7 step 5 (DETAILED-SPEC.md §A11) — the logged-in dashboard's
	 * own cancel action. Ownership-by-session is a second valid
	 * authorization path alongside cancel_via_token()'s token check, NOT a
	 * replacement for it — the guest one-off link keeps working unchanged
	 * for anyone who never creates an account. Same cancellation_hours
	 * policy either way (cancel_within_policy() is the one shared guard
	 * both entry points funnel through).
	 *
	 * @param int $id          Booking ID.
	 * @param int $customer_id The requesting customer's own row id
	 *                         (BYZO_Customer::get_by_wp_user_id() of the
	 *                         current session) — the caller must have
	 *                         already resolved this, this method only
	 *                         checks it matches the booking's owner.
	 */
	public static function cancel_as_customer( $id, $customer_id ) {
		$booking = self::get( $id );
		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		if ( ! $customer_id || (int) $booking['customer_id'] !== (int) $customer_id ) {
			return new WP_Error( 'byzo_not_owner', __( 'This booking does not belong to your account.', 'bookyzo' ), array( 'status' => 403 ) );
		}

		return self::cancel_within_policy( $booking );
	}

	/**
	 * The cancellation-eligibility guard both cancel_via_token() and
	 * cancel_as_customer() share once their own authorization check has
	 * already passed — status/window rules are identical either way, only
	 * *who's allowed to ask* differs between the two callers.
	 *
	 * @return true|WP_Error
	 */
	private static function cancel_within_policy( $booking ) {
		if ( ! in_array( $booking['status'], array( 'pending', 'confirmed' ), true ) ) {
			return new WP_Error( 'byzo_invalid_transition', __( 'This booking cannot be cancelled.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		// byzo_settings.cancellation_hours: 0 disables self-service
		// cancellation entirely; otherwise it's only allowed while the slot
		// is still more than this many hours away.
		$cancellation_hours = (int) BYZO_Settings::get()['cancellation_hours'];
		if ( $cancellation_hours <= 0 ) {
			return new WP_Error( 'byzo_cancellation_disabled', __( "Online cancellation isn't available for this booking — please contact us directly.", 'bookyzo' ), array( 'status' => 403 ) );
		}

		if ( empty( $booking['slot_start'] ) ) {
			return new WP_Error( 'byzo_cannot_cancel', __( 'This booking can no longer be cancelled online.', 'bookyzo' ), array( 'status' => 403 ) );
		}

		$hours_until = ( ( new DateTime( $booking['slot_start'], wp_timezone() ) )->getTimestamp() - ( new DateTime( 'now', wp_timezone() ) )->getTimestamp() ) / HOUR_IN_SECONDS;
		if ( $hours_until <= $cancellation_hours ) {
			return new WP_Error( 'byzo_cancellation_window_passed', __( 'This booking is too close to cancel online — please contact us directly.', 'bookyzo' ), array( 'status' => 403 ) );
		}

		$result = self::update_status( $booking['id'], 'cancelled' );

		if ( true === $result ) {
			/**
			 * The customer cancelled this themselves (manage link or their
			 * account), as opposed to the business or the unpaid-hold
			 * cleanup. A refund add-on asks the admin to approve a refund on
			 * this (QA report 2026-09-22 §6.2.7).
			 *
			 * @param int $booking_id
			 */
			do_action( 'byzo_booking_cancelled_by_customer', $booking['id'] );
		}

		return $result;
	}

	/**
	 * Sets payment_status directly — no state-machine restriction like
	 * update_status()'s booking-status TRANSITIONS table, since
	 * DETAILED-SPEC.md §A7 doesn't call for one on Free (no
	 * deposit/partial flow, no refund UI yet) and a simple set covers
	 * "mark as paid" and the Stripe webhook's "flip to paid_online" alike.
	 *
	 * @return true|WP_Error
	 */
	/**
	 * Records what a gateway actually took (QA report §6.2.7), so it can be
	 * refunded later: its own transaction id (a Stripe PaymentIntent, a
	 * PayPal capture) and the amount charged — the deposit when only the
	 * deposit was taken online. Free only ever writes it; refunds are an
	 * add-on's.
	 *
	 * @param int        $id
	 * @param string     $reference
	 * @param float|null $amount
	 */
	public static function record_payment( $id, $reference, $amount ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'payment_reference' => substr( (string) $reference, 0, 100 ),
				'amount_paid'       => null === $amount ? null : round( (float) $amount, 2 ),
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * The refund fields, written by a refund add-on: refund_status
	 * (requested / refunded / declined / failed), refund_amount, refunded_at.
	 *
	 * @param int   $id
	 * @param array $fields Any of those three keys.
	 */
	public static function set_refund( $id, $fields ) {
		global $wpdb;

		$data = array_intersect_key( (array) $fields, array_flip( array( 'refund_status', 'refund_amount', 'refunded_at' ) ) );
		if ( ! $data ) {
			return;
		}
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * What was charged online for this booking: the deposit when one was
	 * set, the full amount otherwise — the same rule the gateways charge by.
	 */
	public static function online_charge_amount( $booking ) {
		return null !== $booking['deposit_amount'] ? (float) $booking['deposit_amount'] : (float) $booking['payment_amount'];
	}

	public static function set_payment_status( $id, $payment_status ) {
		global $wpdb;

		if ( ! in_array( $payment_status, self::VALID_PAYMENT_STATUSES, true ) ) {
			return new WP_Error( 'byzo_invalid_payment_status', __( 'Invalid payment status.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$booking = self::get( $id );
		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$wpdb->update(
			self::table(),
			array( 'payment_status' => $payment_status, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);

		do_action( 'byzo_booking_payment_status_changed', $id, $payment_status, $booking['payment_status'] );

		return true;
	}

	/**
	 * Stamps the WooCommerce order this booking's payment went through
	 * (DETAILED-SPEC.md §3 B9) — written by the separate `bookyzo-woocommerce`
	 * bridge plugin's BYZOWC_Order_Sync on `woocommerce_order_status_changed`,
	 * same simple-set shape as set_payment_status() above (no state-machine
	 * restriction; a booking only ever gets ONE wc_order_id, set once).
	 *
	 * @return true|WP_Error
	 */
	public static function set_wc_order_id( $id, $wc_order_id ) {
		global $wpdb;

		if ( ! self::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		// A linked WC order is an online payment in progress — stamped here
		// so BYZO_Booking_Cleanup can release it if the order is abandoned.
		$wpdb->update(
			self::table(),
			array( 'wc_order_id' => absint( $wc_order_id ), 'payment_method' => 'woocommerce', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);

		return true;
	}

	/**
	 * Records how the customer set out to pay (see the `payment_method`
	 * column note in BYZO_Activator). Called when an online payment is
	 * started (`stripe` by the payment-intent route, `paypal` by Pro's
	 * order route) and when the customer picks "Pay in person instead"
	 * (`in_person`). The latest choice wins — a customer who opened the
	 * card form and then switched to paying in person ends up `in_person`.
	 *
	 * @return true|WP_Error
	 */
	public static function set_payment_method( $id, $payment_method ) {
		global $wpdb;

		$payment_method = sanitize_key( $payment_method );
		if ( '' === $payment_method || strlen( $payment_method ) > 20 ) {
			return new WP_Error( 'byzo_invalid_payment_method', __( 'Invalid payment method.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( ! self::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$wpdb->update(
			self::table(),
			array( 'payment_method' => $payment_method, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);

		return true;
	}

	/**
	 * Sets the staff-only internal_notes/tags fields (DETAILED-SPEC.md §3
	 * B3, Phase 2 step 11) — a plain field update, not a status
	 * transition, so it's independent of TRANSITIONS/update_status().
	 * Free never calls this (no UI writes these columns there); Pro's
	 * "Bulk & Notes" screen is the only caller today.
	 *
	 * @param string|null $notes Pass null to leave unchanged, '' to clear.
	 * @param string|null $tags  Comma-separated, same shape as wp_byzo_customers.tags.
	 * @return true|WP_Error
	 */
	public static function update_notes_tags( $id, $notes, $tags ) {
		global $wpdb;

		if ( ! self::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$data = array( 'updated_at' => current_time( 'mysql' ) );
		if ( null !== $notes ) {
			$data['internal_notes'] = sanitize_textarea_field( $notes );
		}
		if ( null !== $tags ) {
			$data['tags'] = sanitize_text_field( $tags );
		}

		$wpdb->update( self::table(), $data, array( 'id' => $id ) );

		return true;
	}

	/**
	 * Combines other tables into $id's existing reservation (DETAILED-SPEC.md
	 * §3 B7 Table, Phase 2 step 12 continued) — same no-capability-gate shape
	 * as update_notes_tags()/update_commission(): a plain data-layer method
	 * Free never calls (no UI writes it), only Pro's combine-tables REST
	 * route does, so it's Pro-only in practice without needing a filter
	 * check here. Flips each of $slot_ids to 'blocked' via BYZO_Slot::block()
	 * — the exact mechanism blackout dates/calendar-sync already use — so a
	 * combined-away table simply can't be double-booked with no new
	 * capacity logic. Rolls back any slots already blocked in this same call
	 * if a later one in the list fails (e.g. someone else claimed it a
	 * moment ago), since this project has no wrapping DB transaction to lean
	 * on elsewhere either.
	 *
	 * @param int      $id         Booking ID (its own bookable_item_id stays the "primary" table).
	 * @param int[]    $table_ids  Bookable item IDs of the OTHER tables being combined in.
	 * @param int[]    $slot_ids   Their open slot IDs, same count/order as $table_ids.
	 * @param int|null $party_size Optional — the new COMBINED total party size, replacing the
	 *                             booking's existing one (DETAILED-SPEC.md §3 B7: "the primary
	 *                             table holds the real booking, with the combined party_size").
	 *                             Deliberately not re-validated against any slot's
	 *                             capacity_remaining — the whole point of combining tables is
	 *                             fitting a party larger than one table's own capacity across
	 *                             several tables' physical space, not a capacity-claim event.
	 *                             Omit/null to leave party_size untouched.
	 * @return true|WP_Error
	 */
	public static function combine_tables( $id, $table_ids, $slot_ids, $party_size = null ) {
		global $wpdb;

		$booking = self::get( $id );
		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$table_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $table_ids ) ) ) );
		$slot_ids  = array_values( array_filter( array_map( 'absint', (array) $slot_ids ) ) );

		if ( empty( $table_ids ) || count( $table_ids ) !== count( $slot_ids ) ) {
			return new WP_Error( 'byzo_invalid_combine_tables', __( 'table_ids and slot_ids must be non-empty and the same length.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( in_array( (int) $booking['bookable_item_id'], $table_ids, true ) ) {
			return new WP_Error( 'byzo_invalid_combine_tables', __( 'A booking cannot be combined with its own table.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$blocked = array();
		foreach ( $slot_ids as $slot_id ) {
			if ( BYZO_Slot::block( $slot_id ) ) {
				$blocked[] = $slot_id;
				continue;
			}

			foreach ( $blocked as $already_blocked_id ) {
				BYZO_Slot::unblock( $already_blocked_id );
			}

			return new WP_Error( 'byzo_slot_not_blockable', __( 'One of the selected tables is no longer available.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		$data = array(
			'combined_table_ids' => implode( ',', $table_ids ),
			'updated_at'         => current_time( 'mysql' ),
		);
		if ( null !== $party_size && (int) $party_size > 0 ) {
			$data['party_size'] = (int) $party_size;
		}

		$wpdb->update( self::table(), $data, array( 'id' => $id ) );

		return true;
	}

	/**
	 * Reverses combine_tables() — re-derives which slots to unblock from
	 * combined_table_ids plus the booking's own slot_start (rather than
	 * requiring the caller to remember/re-pass slot IDs), same
	 * re-derive-don't-trust-the-caller shape as cancel_as_customer()
	 * re-deriving ownership from the session instead of a client-supplied
	 * customer_id.
	 *
	 * @return true|WP_Error
	 */
	public static function uncombine_tables( $id ) {
		global $wpdb;

		$booking = self::get( $id );
		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		if ( empty( $booking['combined_table_ids'] ) ) {
			return new WP_Error( 'byzo_not_combined', __( 'This booking has no combined tables.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $booking['slot_start'] ) ) {
			foreach ( BYZO_Slot::blocked_at( $booking['combined_table_ids'], $booking['slot_start'] ) as $slot ) {
				BYZO_Slot::unblock( $slot['id'] );
			}
		}

		$wpdb->update(
			self::table(),
			array(
				'combined_table_ids' => null,
				'updated_at'         => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		return true;
	}

	/**
	 * Stamps checked_in_at for the web check-in scanner (Phase 2 step 12
	 * continued, DETAILED-SPEC.md §3 B7 Event) — same no-capability-gate
	 * shape as combine_tables()/update_notes_tags(): a plain data-layer
	 * method Free never calls, only Pro's `POST /bookings/check-in` route
	 * does (after verifying the scanned ticket token itself), so
	 * it's Pro-only in practice without needing a filter check here.
	 * Refuses a SECOND check-in with a specific error carrying the
	 * original timestamp — a double-scan at a busy door must never look
	 * like a fresh success, same "the real wall answers honestly"
	 * principle as an unavailable slot.
	 *
	 * @return true|WP_Error
	 */
	public static function check_in( $id ) {
		global $wpdb;

		$booking = self::get( $id );
		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		if ( in_array( $booking['status'], array( 'cancelled', 'rejected' ), true ) ) {
			return new WP_Error( 'byzo_invalid_transition', __( 'This booking was cancelled and cannot be checked in.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $booking['checked_in_at'] ) ) {
			return new WP_Error(
				'byzo_already_checked_in',
				sprintf(
					/* translators: %s: the original check-in date/time. */
					__( 'Already checked in at %s.', 'bookyzo' ),
					$booking['checked_in_at']
				),
				array( 'status' => 409 )
			);
		}

		$wpdb->update(
			self::table(),
			array(
				'checked_in_at' => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		return true;
	}

	private static function validate_for_create( $data ) {
		// Phase 2 step 5 (DETAILED-SPEC.md §3 B7) — "any available staff":
		// service_id + start_datetime is an alternative to a direct
		// slot_id, not an addition to it. Free never sends the service
		// path (no service picker exists there); Pro's widget does when
		// the customer books by service rather than by staff member.
		$service_id     = ! empty( $data['service_id'] ) ? absint( $data['service_id'] ) : 0;
		$start_datetime = null;

		// service_id + slot_id (added 2026-09-22, QA report B9): the customer
		// picked a specific staff member for the service — book that exact
		// slot, priced as the service. Only service_id + start_datetime means
		// "any available staff".
		$slot_for_service = $service_id && ! empty( $data['slot_id'] );

		if ( $service_id && ! $slot_for_service ) {
			if ( empty( $data['start_datetime'] ) ) {
				return new WP_Error( 'byzo_missing_start_datetime', __( 'start_datetime is required when booking by service_id.', 'bookyzo' ), array( 'status' => 400 ) );
			}

			$start_datetime = self::sanitize_datetime( $data['start_datetime'] );
			if ( ! $start_datetime ) {
				return new WP_Error( 'byzo_invalid_start_datetime', __( 'start_datetime must be a valid datetime (Y-m-d H:i:s).', 'bookyzo' ), array( 'status' => 400 ) );
			}
		} elseif ( empty( $data['slot_id'] ) ) {
			return new WP_Error( 'byzo_missing_slot_id', __( 'slot_id is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( empty( $data['customer_name'] ) ) {
			return new WP_Error( 'byzo_missing_name', __( 'customer_name is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( empty( $data['customer_email'] ) || ! is_email( $data['customer_email'] ) ) {
			return new WP_Error( 'byzo_invalid_email', __( 'A valid customer_email is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		// byzo_settings.require_phone (DETAILED-SPEC.md §A1's Customer
		// Information wizard step) — name/email are always required
		// regardless of this setting, phone is the only one that toggles.
		if ( ! empty( BYZO_Settings::get()['require_phone'] ) && empty( $data['customer_phone'] ) ) {
			return new WP_Error( 'byzo_missing_phone', __( 'customer_phone is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		// A whole number of at least 1 — anything else is refused rather
		// than quietly reinterpreted (QA report 2026-09-22 B16: -3 used to be
		// saved as 3 and 0 as 1, so a buggy client booked a different
		// quantity than it asked for without being told).
		$party_size = 1;
		if ( isset( $data['party_size'] ) && '' !== $data['party_size'] ) {
			$raw = $data['party_size'];
			if ( ! is_numeric( $raw ) || (float) $raw !== (float) (int) $raw || (int) $raw < 1 || (int) $raw > 65535 ) {
				return new WP_Error( 'byzo_invalid_party_size', __( 'party_size must be a whole number of at least 1.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$party_size = (int) $raw;
		}

		return array(
			'slot_id'        => $service_id && ! $slot_for_service ? null : absint( $data['slot_id'] ),
			'service_id'     => $service_id ?: null,
			'start_datetime' => $start_datetime,
			'customer_name'  => sanitize_text_field( $data['customer_name'] ),
			'customer_email' => sanitize_email( $data['customer_email'] ),
			'customer_phone' => isset( $data['customer_phone'] ) ? sanitize_text_field( $data['customer_phone'] ) : null,
			'customer_notes' => isset( $data['customer_notes'] ) ? wp_kses_post( $data['customer_notes'] ) : null,
			'party_size'     => $party_size,
			// No 'payment_amount' here — see create()'s comment; it's
			// always server-computed, never accepted from client input.
			'custom_fields'  => isset( $data['custom_fields'] ) && is_array( $data['custom_fields'] ) ? $data['custom_fields'] : array(),
			// addon_ids (Phase 2 step 17, DETAILED-SPEC.md §3 B6) — just
			// ids, never prices; attempt_create_on_slot() resolves the
			// real price server-side via byzo_resolve_addons.
			'addon_ids'      => isset( $data['addon_ids'] ) && is_array( $data['addon_ids'] ) ? array_values( array_filter( array_map( 'absint', $data['addon_ids'] ) ) ) : array(),
			// A discount or package code (QA report §6.1 A2) — only the code;
			// price_breakdown() asks an add-on what it's worth.
			'discount_code'  => isset( $data['discount_code'] ) ? strtoupper( substr( sanitize_text_field( (string) $data['discount_code'] ), 0, 40 ) ) : '',
			// attendees (Phase 2 step 20, DETAILED-SPEC.md §3 B4) — per-
			// ticket name (required)/email (optional) pairs; an entry with
			// no name is dropped rather than stored blank. No capability
			// gate here — same "Free stores the shape, Pro's widget UI is
			// the only thing that ever populates it" split as custom_fields.
			'attendees'      => self::sanitize_attendees( $data['attendees'] ?? array() ),
			// cart_id (Phase 2 step 14, DETAILED-SPEC.md §3 B6) — an opaque
			// grouping token Pro's own cart-checkout flow generates
			// client-side (a random string, not sequential, so it never
			// leaks cart volume); Free itself never sends one, so a direct
			// booking is unaffected. Not validated for shape here — it's
			// just an opaque label the Stripe webhook later groups by.
			'cart_id'        => isset( $data['cart_id'] ) ? sanitize_text_field( $data['cart_id'] ) : null,
			// ticket_tier_id (Phase 2 step 12 continued, DETAILED-SPEC.md
			// §3 B7 Event) - just an id, never a price; attempt_create_on_slot()
			// resolves the real price server-side via byzo_ticket_tier_price
			// and atomically claims the tier's own capacity via
			// byzo_claim_ticket_tier, same "id in, server resolves the rest"
			// shape as addon_ids above.
			'ticket_tier_id' => ! empty( $data['ticket_tier_id'] ) ? absint( $data['ticket_tier_id'] ) : null,
		);
	}

	/**
	 * @param mixed $attendees Client-submitted array of { name, email? }.
	 * @return array Sanitized entries; any entry with no non-empty name is dropped.
	 */
	private static function sanitize_attendees( $attendees ) {
		if ( ! is_array( $attendees ) ) {
			return array();
		}

		$out = array();
		foreach ( $attendees as $attendee ) {
			if ( ! is_array( $attendee ) || empty( $attendee['name'] ) ) {
				continue;
			}

			$name = sanitize_text_field( $attendee['name'] );
			if ( '' === $name ) {
				continue;
			}

			$email = ! empty( $attendee['email'] ) ? sanitize_email( $attendee['email'] ) : '';

			$out[] = array(
				'name'  => $name,
				'email' => $email,
			);
		}

		return $out;
	}

	/**
	 * Y-m-d H:i:s only (not the looser H:i BYZO_Availability/BYZO_Slot
	 * accept elsewhere) — this value is compared for exact equality
	 * against wp_byzo_slots.start_datetime (BYZO_Slot::open_at()), not
	 * used to build a range, so it must already be in the slot table's
	 * own stored format.
	 */
	private static function sanitize_datetime( $value ) {
		$value = trim( (string) $value );
		$dt    = DateTime::createFromFormat( 'Y-m-d H:i:s', $value );
		return ( $dt && $dt->format( 'Y-m-d H:i:s' ) === $value ) ? $value : false;
	}

	/**
	 * Bookings whose reminder is due and still worth sending: the slot is
	 * still ahead, its "$hours_before hours before" mark has passed, the
	 * booking existed by then (a one-hour grace, so a booking made just
	 * after its own mark still gets one — but a booking made well inside
	 * the window never gets a reminder for a moment that had already gone
	 * by), and the booking is at least an hour old, so no reminder lands
	 * right behind its own confirmation email.
	 *
	 * Rewritten 2026-09-22 (QA report §4.1). It used to take an exact
	 * one-hour window, so a WP-Cron run that slipped past that hour —
	 * routine on a low-traffic site — dropped those bookings' reminders
	 * for good. "Due and not yet sent" catches up on the next run instead;
	 * `reminder_sent_at` (or Pro's own per-rule log) is still what stops a
	 * second send.
	 *
	 * @param DateTime $now
	 * @param int      $hours_before    How long before the slot the reminder is due.
	 * @param bool     $only_unreminded Skip bookings with reminder_sent_at set — true for Free's own
	 *                                  single reminder, false for Pro's rules, which track each
	 *                                  (booking, rule, channel) send separately.
	 * @return array Cast booking rows (same shape as get()/query()).
	 */
	public static function get_due_for_reminder( DateTime $now, $hours_before = 24, $only_unreminded = true ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';
		$hours_before   = max( 0, (int) $hours_before );
		$reminded_sql   = $only_unreminded ? 'AND b.reminder_sent_at IS NULL' : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names and a fixed clause, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, s.start_datetime AS slot_start, s.end_datetime AS slot_end
				 FROM {$bookings_table} b
				 INNER JOIN {$slots_table} s ON s.id = b.slot_id
				 WHERE b.status IN ('pending','confirmed')
				   {$reminded_sql}
				   AND s.start_datetime > %s
				   AND s.start_datetime <= DATE_ADD(%s, INTERVAL %d HOUR)
				   AND b.created_at <= DATE_ADD(DATE_SUB(s.start_datetime, INTERVAL %d HOUR), INTERVAL 1 HOUR)
				   AND b.created_at <= DATE_SUB(%s, INTERVAL 1 HOUR)",
				$now->format( 'Y-m-d H:i:s' ),
				$now->format( 'Y-m-d H:i:s' ),
				$hours_before,
				$hours_before,
				$now->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'cast' ), $rows ?: array() );
	}

	/**
	 * Bookings whose slot starts within [$window_start, $window_end) —
	 * the same shape as get_due_for_reminder() above but deliberately
	 * WITHOUT its `reminder_sent_at IS NULL` filter (Phase 2 step 7,
	 * DETAILED-SPEC.md §A6). That column tracks Free's own single fixed
	 * 24h reminder; reusing it here would wrongly exclude a booking from
	 * every one of Pro's independent, per-rule reminders the moment
	 * Free's own reminder had fired for it once. Pro tracks its own
	 * per-(booking, rule, channel) send state in its own table instead.
	 *
	 * @return array Cast booking rows (same shape as get()/query()).
	 */
	public static function get_bookings_in_window( DateTime $window_start, DateTime $window_end ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, s.start_datetime AS slot_start, s.end_datetime AS slot_end
				 FROM {$bookings_table} b
				 INNER JOIN {$slots_table} s ON s.id = b.slot_id
				 WHERE b.status IN ('pending','confirmed')
				   AND s.start_datetime >= %s
				   AND s.start_datetime < %s",
				$window_start->format( 'Y-m-d H:i:s' ),
				$window_end->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'cast' ), $rows ?: array() );
	}

	public static function mark_reminder_sent( $id ) {
		global $wpdb;

		$wpdb->update( self::table(), array( 'reminder_sent_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
	}

	/**
	 * Completed bookings whose completed_at falls within
	 * [$window_start, $window_end) and haven't had their post-visit
	 * follow-up sent yet (Phase 2 step 18, DETAILED-SPEC.md §3 B5) — one
	 * fixed follow-up per booking, same shape as get_due_for_reminder()
	 * but keyed off completion time instead of a future slot start, and
	 * gated on completed_at actually being set (a booking marked
	 * completed without ever going through a real slot has nothing to
	 * measure "hours after" from).
	 *
	 * @return array Cast booking rows (same shape as get()/query()).
	 */
	public static function get_due_for_followup( DateTime $window_start, DateTime $window_end ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, s.start_datetime AS slot_start, s.end_datetime AS slot_end
				 FROM {$bookings_table} b
				 LEFT JOIN {$slots_table} s ON s.id = b.slot_id
				 WHERE b.status = 'completed'
				   AND b.followup_sent_at IS NULL
				   AND b.completed_at IS NOT NULL
				   AND b.completed_at >= %s
				   AND b.completed_at < %s",
				$window_start->format( 'Y-m-d H:i:s' ),
				$window_end->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'cast' ), $rows ?: array() );
	}

	public static function mark_followup_sent( $id ) {
		global $wpdb;

		$wpdb->update( self::table(), array( 'followup_sent_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
	}

	private static function cast( $row ) {
		$row['id']             = (int) $row['id'];
		$row['amount_paid']    = isset( $row['amount_paid'] ) && null !== $row['amount_paid'] ? (float) $row['amount_paid'] : null;
		$row['discount_amount'] = isset( $row['discount_amount'] ) && null !== $row['discount_amount'] ? (float) $row['discount_amount'] : null;
		$row['package_purchase_id'] = ! empty( $row['package_purchase_id'] ) ? (int) $row['package_purchase_id'] : null;
		$row['refund_amount']  = isset( $row['refund_amount'] ) && null !== $row['refund_amount'] ? (float) $row['refund_amount'] : null;
		$row['slot_id']        = $row['slot_id'] ? (int) $row['slot_id'] : null;
		$row['bookable_item_id'] = (int) $row['bookable_item_id'];
		$row['service_id']     = isset( $row['service_id'] ) && $row['service_id'] ? (int) $row['service_id'] : null;
		$row['ticket_tier_id'] = isset( $row['ticket_tier_id'] ) && $row['ticket_tier_id'] ? (int) $row['ticket_tier_id'] : null;
		$row['checked_in_at']  = isset( $row['checked_in_at'] ) ? $row['checked_in_at'] : null;
		$row['customer_id']    = $row['customer_id'] ? (int) $row['customer_id'] : null;
		$row['party_size']     = (int) $row['party_size'];
		$row['payment_amount'] = null === $row['payment_amount'] ? null : (float) $row['payment_amount'];
		$row['deposit_amount'] = isset( $row['deposit_amount'] ) && null !== $row['deposit_amount'] ? (float) $row['deposit_amount'] : null;
		$row['tax_amount']     = null === $row['tax_amount'] ? null : (float) $row['tax_amount'];
		$row['booking_fee_amount'] = isset( $row['booking_fee_amount'] ) && null !== $row['booking_fee_amount'] ? (float) $row['booking_fee_amount'] : null;
		$row['custom_fields']  = $row['custom_fields'] ? json_decode( $row['custom_fields'], true ) : array();
		$row['addons']         = isset( $row['addons'] ) && $row['addons'] ? json_decode( $row['addons'], true ) : array();
		$row['attendees']      = isset( $row['attendees'] ) && $row['attendees'] ? json_decode( $row['attendees'], true ) : array();
		$row['combined_table_ids'] = isset( $row['combined_table_ids'] ) && $row['combined_table_ids']
			? array_map( 'intval', explode( ',', $row['combined_table_ids'] ) )
			: array();
		$row['wc_order_id']    = isset( $row['wc_order_id'] ) && $row['wc_order_id'] ? (int) $row['wc_order_id'] : null;
		$row['payment_method'] = isset( $row['payment_method'] ) && '' !== $row['payment_method'] ? $row['payment_method'] : null;

		// slot_start/slot_end come from the LEFT JOIN in get()/query() —
		// always present in the shape (null when the slot was deleted,
		// per DETAILED-SPEC.md §1.4's note on slot_id being nullable).
		$row['slot_start'] = $row['slot_start'] ?? null;
		$row['slot_end']   = $row['slot_end'] ?? null;

		// The same two instants in UTC (ISO 8601, `Z`), for anything that
		// needs an absolute time rather than the site's wall-clock one —
		// the widget's add-to-calendar links, which otherwise had to guess
		// a zone and used the visitor's (QA report 2026-09-22 B7).
		$row['slot_start_gmt'] = self::local_to_gmt_iso( $row['slot_start'] );
		$row['slot_end_gmt']   = self::local_to_gmt_iso( $row['slot_end'] );

		// The same times on the booked item's own clock — its location's
		// timezone — for showing to people (QA report §6.1 A1). Identical
		// to slot_start/slot_end when the item uses the site's timezone.
		$row['timezone']         = BYZO_Bookable_Item::timezone( $row['bookable_item_id'] )->getName();
		$row['local_slot_start'] = BYZO_Bookable_Item::to_local( $row['slot_start'], $row['bookable_item_id'] );
		$row['local_slot_end']   = BYZO_Bookable_Item::to_local( $row['slot_end'], $row['bookable_item_id'] );
		// The zone's short name ("+06", "IST") when it isn't the site's, so
		// admin screens can say whose clock a time is on (QA report
		// 2026-09-26 §4.1); null when the item keeps the site timezone.
		$tz                = BYZO_Bookable_Item::timezone( $row['bookable_item_id'] );
		$row['local_zone'] = $row['slot_start'] && $tz->getName() !== wp_timezone()->getName() ? ( new DateTime( $row['slot_start'], wp_timezone() ) )->setTimezone( $tz )->format( 'T' ) : null;

		return $row;
	}

	/**
	 * @param string|null $local A site-local 'Y-m-d H:i:s' datetime.
	 * @return string|null e.g. '2026-09-26T18:00:00Z'.
	 */
	private static function local_to_gmt_iso( $local ) {
		if ( ! $local ) {
			return null;
		}

		$datetime = date_create_immutable( $local, wp_timezone() );

		return $datetime ? $datetime->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ) : null;
	}
}
