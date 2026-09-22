<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for wp_byzo_slots (DETAILED-SPEC.md §1.3). Mostly
 * read-only — rows are normally only ever written by BYZO_Slot_Generator
 * (recurring-schedule materialization) and BYZO_Booking (capacity
 * decrement on booking) — plus one deliberate exception: event-type
 * bookable items have no weekly schedule (§1.3/§5 item 7), so their
 * occurrences are admin-entered directly as slot rows via
 * create_occurrence()/delete_occurrence() below, bypassing the generator
 * entirely (Phase 1.5 step 5).
 */
class BYZO_Slot {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'byzo_slots';
	}

	/**
	 * @param array $args {
	 *     @type int    $bookable_item_id Required.
	 *     @type string $from             Y-m-d, defaults to today.
	 *     @type string $to               Y-m-d, defaults to byzo_settings max_advance_days out.
	 *     @type bool   $open_only        Default true — only 'open' slots with capacity_remaining > 0 (what the public widget needs). false = admin view, all statuses.
	 *     @type bool   $upcoming_only    Default false — also drop slots starting before earliest_bookable_start() (what a customer may still book; the public /slots route sets this for everyone but admins).
	 * }
	 * @return array|WP_Error
	 */
	public static function query( $args ) {
		global $wpdb;

		if ( empty( $args['bookable_item_id'] ) ) {
			return new WP_Error( 'byzo_missing_bookable_item_id', __( 'bookable_item_id is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$bookable_item_id = absint( $args['bookable_item_id'] );

		$from = ! empty( $args['from'] ) ? self::sanitize_date( $args['from'] ) : ( new DateTime( 'today', wp_timezone() ) )->format( 'Y-m-d' );
		if ( ! $from ) {
			return new WP_Error( 'byzo_invalid_from', __( 'from must be a valid date (Y-m-d).', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( ! empty( $args['to'] ) ) {
			$to = self::sanitize_date( $args['to'] );
			if ( ! $to ) {
				return new WP_Error( 'byzo_invalid_to', __( 'to must be a valid date (Y-m-d).', 'bookyzo' ), array( 'status' => 400 ) );
			}
		} else {
			$settings = wp_parse_args( get_option( 'byzo_settings', array() ), array( 'max_advance_days' => 30 ) );
			$to       = ( new DateTime( $from, wp_timezone() ) )->modify( '+' . max( 0, (int) $settings['max_advance_days'] ) . ' days' )->format( 'Y-m-d' );
		}

		if ( $to < $from ) {
			return new WP_Error( 'byzo_invalid_range', __( 'to must not be before from.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$open_only = ! isset( $args['open_only'] ) || (bool) $args['open_only'];

		$where  = array( 'bookable_item_id = %d', 'start_datetime >= %s', 'start_datetime < %s' );
		$params = array( $bookable_item_id, "{$from} 00:00:00", ( new DateTime( $to, wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' ) . ' 00:00:00' );

		if ( $open_only ) {
			$where[]  = "status = 'open'";
			$where[]  = 'capacity_remaining > 0';
		}

		if ( ! empty( $args['upcoming_only'] ) ) {
			$item     = BYZO_Bookable_Item::get( $bookable_item_id );
			$where[]  = 'start_datetime >= %s';
			$params[] = self::earliest_bookable_start( $item ? $item['type'] : '' );
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = 'SELECT * FROM ' . self::table() . " WHERE {$where_sql} ORDER BY start_datetime ASC";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'cast' ), $rows ?: array() );
	}

	/**
	 * The earliest slot start a customer may still book, site-local
	 * 'Y-m-d H:i:s' like start_datetime itself.
	 *
	 * Slots are materialized ahead of time by the generator, which only
	 * skips times inside the minimum-advance window as of the moment it
	 * ran — a 10:00 slot generated overnight is still an `open` row at
	 * 09:59, and at 14:00, and three weeks later. So the same rule has to
	 * hold at read and booking time too. Event occurrences never go
	 * through the generator (their times are the admin's own), so for them
	 * the only rule is "hasn't started yet".
	 *
	 * @param string $type The bookable item's type.
	 */
	public static function earliest_bookable_start( $type ) {
		$hours = 'event' === $type ? 0 : max( 0, (int) BYZO_Settings::get()['min_advance_hours'] );

		return wp_date( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS );
	}

	/**
	 * Whether earliest_bookable_start() binds the current request. Admins
	 * are exempt — they record walk-ins and phone bookings for times a
	 * customer could no longer book online.
	 */
	public static function lead_time_applies() {
		/**
		 * Filters whether the booking lead time (no past slots, and the
		 * minimum advance notice) applies to the current request.
		 *
		 * @param bool $applies Default: true for everyone but users who can manage_options.
		 */
		return (bool) apply_filters( 'byzo_enforce_booking_lead_time', ! current_user_can( 'manage_options' ) );
	}

	/**
	 * Every slot row for one bookable item, any date, any status — the
	 * admin Dates tab's own listing (DETAILED-SPEC.md §A2/A3), deliberately
	 * NOT date-windowed like query() above: an event occurrence can be
	 * scheduled further out than byzo_settings.max_advance_days (that
	 * setting only bounds the *generator's* rolling window, meaningless
	 * for event items which skip the generator entirely), and the admin
	 * needs to see past occurrences too, not just upcoming ones.
	 */
	public static function list_for_bookable_item( $bookable_item_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE bookable_item_id = %d ORDER BY start_datetime ASC', $bookable_item_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'cast' ), $rows ?: array() );
	}

	/**
	 * Open slots across multiple bookable items in a date range, merged
	 * into one chronological list — the "any available staff" browsing
	 * query (DETAILED-SPEC.md §3 B7, Phase 2 step 5): a service's widget
	 * picker shows one combined list of bookable times, not a per-staff
	 * breakdown. Thin wrapper around query() (reuses its from/to
	 * validation rather than duplicating it) — fine for the handful of
	 * staff a single service realistically has; not meant for a
	 * hundred-bookable-item aggregate.
	 *
	 * @param int[] $bookable_item_ids
	 * @param array $args Same shape as query() minus bookable_item_id.
	 * @return array|WP_Error
	 */
	public static function query_for_bookable_items( $bookable_item_ids, $args = array() ) {
		$bookable_item_ids = array_values( array_filter( array_map( 'absint', (array) $bookable_item_ids ) ) );

		if ( empty( $bookable_item_ids ) ) {
			return array();
		}

		$merged = array();

		foreach ( $bookable_item_ids as $bookable_item_id ) {
			$slots = self::query( array_merge( $args, array( 'bookable_item_id' => $bookable_item_id ) ) );
			if ( is_wp_error( $slots ) ) {
				return $slots;
			}
			$merged = array_merge( $merged, $slots );
		}

		usort( $merged, static function ( $a, $b ) {
			return strcmp( $a['start_datetime'], $b['start_datetime'] );
		} );

		return $merged;
	}

	/**
	 * Every OPEN slot starting at exactly $start_datetime across the given
	 * bookable items — the "any available staff" resolution query
	 * (DETAILED-SPEC.md §3 B7, Phase 2 step 5).
	 * BYZO_Booking::create_for_service() tries each returned candidate in
	 * turn until one's atomic capacity claim succeeds, so a stable
	 * (bookable_item_id ASC) order matters more than any particular
	 * "fairness" ordering — it just needs to be deterministic.
	 *
	 * @param int[]  $bookable_item_ids
	 * @param string $start_datetime Y-m-d H:i:s.
	 * @return array Cast slot rows.
	 */
	public static function open_at( $bookable_item_ids, $start_datetime ) {
		global $wpdb;

		$bookable_item_ids = array_filter( array_map( 'absint', (array) $bookable_item_ids ) );
		if ( empty( $bookable_item_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $bookable_item_ids ), '%d' ) );
		$sql          = 'SELECT * FROM ' . self::table() . " WHERE bookable_item_id IN ({$placeholders}) AND start_datetime = %s AND status = 'open' AND capacity_remaining > 0 ORDER BY bookable_item_id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$params       = array_merge( array_values( $bookable_item_ids ), array( $start_datetime ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'cast' ), $rows ?: array() );
	}

	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return $row ? self::cast( $row ) : null;
	}

	/**
	 * The 'blocked' mirror of open_at() — used by BYZO_Booking::uncombine_tables()
	 * (DETAILED-SPEC.md §3 B7 Table, Phase 2 step 12 continued) to re-derive
	 * which slots combine_tables() blocked, rather than requiring the caller
	 * to re-pass slot IDs it may no longer have handy.
	 *
	 * @param int[]  $bookable_item_ids
	 * @param string $start_datetime Y-m-d H:i:s.
	 * @return array Cast slot rows.
	 */
	public static function blocked_at( $bookable_item_ids, $start_datetime ) {
		global $wpdb;

		$bookable_item_ids = array_filter( array_map( 'absint', (array) $bookable_item_ids ) );
		if ( empty( $bookable_item_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $bookable_item_ids ), '%d' ) );
		$sql          = 'SELECT * FROM ' . self::table() . " WHERE bookable_item_id IN ({$placeholders}) AND start_datetime = %s AND status = 'blocked' ORDER BY bookable_item_id ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$params       = array_merge( array_values( $bookable_item_ids ), array( $start_datetime ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( array( __CLASS__, 'cast' ), $rows ?: array() );
	}

	/**
	 * Occupancy rate per bookable item over [from, to) — Pro's Analytics
	 * dashboard (DETAILED-SPEC.md §3 B8, Phase 2 step 10). Defined as the
	 * fraction of a bookable item's OFFERED time slots that received at
	 * least one booking, not a capacity-weighted fill rate:
	 * capacity_remaining is mutated in place with no stored "original
	 * capacity" column, so there's no reliable way to compute a true
	 * capacity-weighted number for a slot generated under a
	 * since-changed capacity setting. 'blocked' slots (blackout dates,
	 * Pro's calendar-sync busy blocks) are excluded from the denominator
	 * entirely — they were never actually offered, so they don't belong
	 * in an occupancy calculation either way.
	 *
	 * @return array<int,array{bookable_item_id:int,total_slots:int,booked_slots:int,rate:float}>
	 */
	public static function get_occupancy_by_bookable_item( DateTime $from, DateTime $to ) {
		global $wpdb;

		$slots_table    = self::table();
		$bookings_table = $wpdb->prefix . 'byzo_bookings';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.bookable_item_id,
				        COUNT(DISTINCT s.id) AS total_slots,
				        COUNT(DISTINCT CASE WHEN b.id IS NOT NULL THEN s.id END) AS booked_slots
				 FROM {$slots_table} s
				 LEFT JOIN {$bookings_table} b ON b.slot_id = s.id AND b.status NOT IN ('cancelled','rejected')
				 WHERE s.status IN ('open','full') AND s.start_datetime >= %s AND s.start_datetime < %s
				 GROUP BY s.bookable_item_id",
				$from->format( 'Y-m-d H:i:s' ),
				$to->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array_map(
			static function ( $row ) {
				$total  = (int) $row['total_slots'];
				$booked = (int) $row['booked_slots'];
				return array(
					'bookable_item_id' => (int) $row['bookable_item_id'],
					'total_slots'      => $total,
					'booked_slots'     => $booked,
					'rate'             => $total > 0 ? $booked / $total : 0.0,
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * Flips one slot from 'open' to 'blocked' — the same status value
	 * BYZO_Slot_Generator::block_slots_on_dates() already uses for blackout
	 * dates (Phase 0), reused here as the generic "unbookable, not because
	 * it's full" marker. Every open-slot query and the atomic booking-claim
	 * UPDATE in BYZO_Booking already exclude non-'open' rows, so this is the
	 * entire mechanism — no other Free-side code needs to know why a slot
	 * got blocked (a Pro add-on's external-calendar sync, DETAILED-SPEC.md
	 * §3 B7, added 2026-09-12).
	 *
	 * @return bool True if a row was actually flipped.
	 */
	public static function block( $slot_id ) {
		global $wpdb;

		return (bool) $wpdb->query(
			$wpdb->prepare( "UPDATE " . self::table() . " SET status = 'blocked' WHERE id = %d AND status = 'open'", $slot_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Reverses block() — only ever flips a row this same mechanism blocked
	 * (guarded by status = 'blocked'), so it can never reopen a slot that a
	 * blackout exception or a booking's atomic claim independently touched.
	 *
	 * @return bool True if a row was actually flipped.
	 */
	public static function unblock( $slot_id ) {
		global $wpdb;

		return (bool) $wpdb->query(
			$wpdb->prepare( "UPDATE " . self::table() . " SET status = 'open' WHERE id = %d AND status = 'blocked'", $slot_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Creates one event occurrence directly as a slot row (DETAILED-SPEC.md
	 * §1.3/§5 item 7) — the admin-entered equivalent of what
	 * BYZO_Slot_Generator materializes from a weekly schedule for the other
	 * three types. capacity_remaining seeds from the bookable item's own
	 * `capacity` column, same as the generator does.
	 *
	 * @param array $data { @type string $date, $start_time, $end_time }
	 * @return int|WP_Error New slot ID, or WP_Error on validation failure.
	 */
	public static function create_occurrence( $bookable_item_id, $data ) {
		global $wpdb;

		if ( empty( $data['date'] ) || empty( $data['start_time'] ) || empty( $data['end_time'] ) ) {
			return new WP_Error( 'byzo_missing_fields', __( 'date, start_time, and end_time are required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$date = self::sanitize_date( $data['date'] );
		if ( ! $date ) {
			return new WP_Error( 'byzo_invalid_date', __( 'date must be a valid date (Y-m-d).', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$start_time = self::sanitize_time( $data['start_time'] );
		$end_time   = self::sanitize_time( $data['end_time'] );
		if ( ! $start_time || ! $end_time ) {
			return new WP_Error( 'byzo_invalid_time', __( 'start_time/end_time must be valid times (H:i).', 'bookyzo' ), array( 'status' => 400 ) );
		}
		if ( $start_time >= $end_time ) {
			return new WP_Error( 'byzo_invalid_time_range', __( 'start_time must be before end_time.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$item = BYZO_Bookable_Item::get( $bookable_item_id );
		if ( ! $item ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'bookable_item_id'   => $bookable_item_id,
				'start_datetime'     => "{$date} {$start_time}",
				'end_datetime'       => "{$date} {$end_time}",
				'capacity_remaining' => max( 1, (int) $item['capacity'] ),
				'status'             => 'open',
			)
		);

		if ( false === $inserted ) {
			return new WP_Error( 'byzo_db_error', __( 'Could not create this occurrence.', 'bookyzo' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Removes one occurrence — refuses if any booking references it
	 * (DETAILED-SPEC.md §1.3: "refuse if bookings exist"), same
	 * never-silently-orphan-a-booking principle as everywhere else in this
	 * plugin (e.g. BYZO_Booking's slot_id FK is nullable specifically so a
	 * booking record survives its slot being removed, but the removal
	 * itself must still be a deliberate, blocked-until-resolved action).
	 *
	 * @return true|WP_Error
	 */
	public static function delete_occurrence( $id ) {
		global $wpdb;

		if ( ! self::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Occurrence not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$bookings_table = $wpdb->prefix . 'byzo_bookings';
		$booking_count  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$bookings_table} WHERE slot_id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $booking_count > 0 ) {
			return new WP_Error(
				'byzo_occurrence_has_bookings',
				__( 'This occurrence has bookings against it and cannot be deleted.', 'bookyzo' ),
				array( 'status' => 409 )
			);
		}

		$wpdb->delete( self::table(), array( 'id' => $id ) );

		return true;
	}

	private static function sanitize_date( $value ) {
		$value = trim( (string) $value );
		$dt    = DateTime::createFromFormat( 'Y-m-d', $value );
		return ( $dt && $dt->format( 'Y-m-d' ) === $value ) ? $value : false;
	}

	private static function sanitize_time( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value ) ) {
			return $value . ':00';
		}
		if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d):([0-5]\d)$/', $value ) ) {
			return $value;
		}
		return false;
	}

	private static function cast( $row ) {
		$row['id']                  = (int) $row['id'];
		$row['bookable_item_id']    = (int) $row['bookable_item_id'];
		$row['capacity_remaining']  = (int) $row['capacity_remaining'];
		return $row;
	}
}
