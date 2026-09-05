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

	const VALID_PAYMENT_STATUSES = array( 'unpaid', 'paid_manual', 'paid_online', 'refunded' );

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
			'bookable_item_id' => 0,
			'status'           => '',
			'per_page'         => 20,
			'page'             => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		$where  = array( '1=1' );
		$params = array();

		if ( $args['bookable_item_id'] ) {
			$where[]  = 'b.bookable_item_id = %d';
			$params[] = (int) $args['bookable_item_id'];
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

		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );
		$select    = 'b.*, s.start_datetime AS slot_start, s.end_datetime AS slot_end';
		$join      = "LEFT JOIN {$slots_table} s ON s.id = b.slot_id";

		$sql = "SELECT {$select} FROM {$bookings_table} b {$join} WHERE {$where_sql} ORDER BY COALESCE(s.start_datetime, b.created_at) ASC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$count_sql    = "SELECT COUNT(*) FROM {$bookings_table} b {$join} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_params = array_slice( $params, 0, count( $params ) - 2 );
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
	public static function get_daily_counts( DateTime $from, DateTime $to ) {
		global $wpdb;

		$bookings_table = self::table();
		$slots_table    = $wpdb->prefix . 'byzo_slots';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(s.start_datetime) AS day, COUNT(*) AS count
				 FROM {$bookings_table} b
				 INNER JOIN {$slots_table} s ON s.id = b.slot_id
				 WHERE s.start_datetime >= %s AND s.start_datetime < %s
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
		global $wpdb;

		$validated = self::validate_for_create( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$slot = BYZO_Slot::get( $validated['slot_id'] );
		if ( ! $slot || 'open' !== $slot['status'] ) {
			return new WP_Error( 'byzo_slot_unavailable', __( 'This time slot is no longer available.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		$party_size = $validated['party_size'];

		$slots_table = $wpdb->prefix . 'byzo_slots';
		$updated     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$slots_table} SET capacity_remaining = capacity_remaining - %d WHERE id = %d AND status = 'open' AND capacity_remaining >= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$party_size,
				$slot['id'],
				$party_size
			)
		);

		if ( ! $updated ) {
			return new WP_Error( 'byzo_slot_full', __( 'This time slot was just booked by someone else. Please pick another time.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		// Slot is now at 0 remaining -> flip it to 'full' so /slots stops offering it.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$slots_table} SET status = 'full' WHERE id = %d AND capacity_remaining <= 0 AND status = 'open'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slot['id']
			)
		);

		$now = current_time( 'mysql' );

		// payment_amount is ALWAYS server-computed from the bookable item's
		// own price, never taken from client input (validate_for_create()
		// doesn't even look at a client-supplied payment_amount — see its
		// docblock) — this is what BYZO_Stripe's payment-intent creation
		// charges, so trusting a client-suppliable amount here would let a
		// guest pay $0.01 for an expensive service. NULL price (a free
		// item, DETAILED-SPEC.md §1.1) means nothing to charge.
		$item            = BYZO_Bookable_Item::get( $slot['bookable_item_id'] );
		$subtotal        = ( $item && null !== $item['price'] ) ? round( $item['price'] * $party_size, 2 ) : null;

		// Flat site-wide tax rate (added 2026-09-05, Phase 1.5 step 9,
		// DETAILED-SPEC.md §A7) — tax_amount is broken out from
		// payment_amount for invoice display; both NULL when there's
		// nothing to charge in the first place. Per-bookable item
		// overrides (tax_rate_override) are a Pro-only column not part of
		// this schema touch, so Free always uses the one site-wide rate.
		$tax_rate       = (float) BYZO_Settings::get()['tax_rate'];
		$tax_amount     = ( null !== $subtotal && $tax_rate > 0 ) ? round( $subtotal * $tax_rate / 100, 2 ) : null;
		$payment_amount = null !== $subtotal ? round( $subtotal + (float) $tax_amount, 2 ) : null;

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
			'customer_id'      => $customer_id ?: null,
			'customer_name'    => $validated['customer_name'],
			'customer_email'   => $validated['customer_email'],
			'customer_phone'   => $validated['customer_phone'],
			'customer_notes'   => $validated['customer_notes'],
			'party_size'       => $party_size,
			'status'           => $status,
			'payment_status'   => 'unpaid',
			'payment_amount'   => $payment_amount,
			'tax_amount'       => $tax_amount,
			'custom_fields'    => wp_json_encode( $validated['custom_fields'] ),
			'created_at'       => $now,
			'updated_at'       => $now,
		);

		$inserted = $wpdb->insert( self::table(), $row );

		if ( false === $inserted ) {
			// Booking insert failed after capacity was already taken — release it back rather than leaking a phantom hold.
			$wpdb->query( $wpdb->prepare( "UPDATE {$slots_table} SET capacity_remaining = capacity_remaining + %d, status = 'open' WHERE id = %d", $party_size, $slot['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return new WP_Error( 'byzo_db_error', __( 'Could not create booking.', 'bookyzo' ) );
		}

		$booking_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a booking is created — status is 'pending' unless
		 * byzo_settings.auto_confirm made it 'confirmed' immediately (see
		 * $status above). Notification/payment logic (Phase 1 steps 7-8,
		 * Phase 1.5 step 6) hooks this rather than living inside create();
		 * BYZO_Notifications checks the booking's actual status to decide
		 * which customer template to send.
		 */
		do_action( 'byzo_booking_created', $booking_id );

		return $booking_id;
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
			$slots_table = $wpdb->prefix . 'byzo_slots';

			// Credit the seat(s) back. A slot that was 'full' also flips
			// back to 'open' (CASE keeps a 'blocked' slot — e.g. a
			// blackout added after this booking was made — from being
			// silently reopened by a later cancellation).
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name, not user input.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$slots_table}
					 SET capacity_remaining = capacity_remaining + %d,
					     status = CASE WHEN status = 'full' THEN 'open' ELSE status END
					 WHERE id = %d",
					$booking['party_size'],
					$booking['slot_id']
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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

		$party_size  = $booking['party_size'];
		$slots_table = $wpdb->prefix . 'byzo_slots';

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$slots_table} SET capacity_remaining = capacity_remaining - %d WHERE id = %d AND status = 'open' AND capacity_remaining >= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$party_size,
				$new_slot_id,
				$party_size
			)
		);

		if ( ! $claimed ) {
			return new WP_Error( 'byzo_slot_full', __( 'This time slot was just booked by someone else. Please pick another time.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$slots_table} SET status = 'full' WHERE id = %d AND capacity_remaining <= 0 AND status = 'open'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$new_slot_id
			)
		);

		// Release the old slot back — same CASE-guarded credit as
		// update_status()'s cancel/no_show/rejected release, so a
		// 'blocked' old slot (e.g. a blackout added after this booking was
		// made) isn't silently reopened by this reschedule.
		if ( $booking['slot_id'] ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name, not user input.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$slots_table}
					 SET capacity_remaining = capacity_remaining + %d,
					     status = CASE WHEN status = 'full' THEN 'open' ELSE status END
					 WHERE id = %d",
					$party_size,
					$booking['slot_id']
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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

		if ( ! in_array( $booking['status'], array( 'pending', 'confirmed' ), true ) ) {
			return new WP_Error( 'byzo_invalid_transition', __( 'This booking cannot be cancelled.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		// byzo_settings.cancellation_hours: 0 disables guest cancellation
		// entirely; otherwise it's only allowed while the slot is still
		// more than this many hours away.
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

		return self::update_status( $id, 'cancelled' );
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

	private static function validate_for_create( $data ) {
		if ( empty( $data['slot_id'] ) ) {
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

		$party_size = isset( $data['party_size'] ) ? max( 1, absint( $data['party_size'] ) ) : 1;

		return array(
			'slot_id'        => absint( $data['slot_id'] ),
			'customer_name'  => sanitize_text_field( $data['customer_name'] ),
			'customer_email' => sanitize_email( $data['customer_email'] ),
			'customer_phone' => isset( $data['customer_phone'] ) ? sanitize_text_field( $data['customer_phone'] ) : null,
			'customer_notes' => isset( $data['customer_notes'] ) ? wp_kses_post( $data['customer_notes'] ) : null,
			'party_size'     => $party_size,
			// No 'payment_amount' here — see create()'s comment; it's
			// always server-computed from the bookable item's price, never
			// accepted from client input.
			'custom_fields'  => isset( $data['custom_fields'] ) && is_array( $data['custom_fields'] ) ? $data['custom_fields'] : array(),
		);
	}

	/**
	 * Bookings whose slot starts within [$window_start, $window_end) that
	 * haven't been reminded yet — the query behind the 24h reminder cron
	 * (DETAILED-SPEC.md §A6). Only pending/confirmed bookings are
	 * reminder-eligible; a cancelled/completed/no_show booking has
	 * nothing upcoming to remind about.
	 *
	 * @return array Cast booking rows (same shape as get()/query()).
	 */
	public static function get_due_for_reminder( DateTime $window_start, DateTime $window_end ) {
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
				   AND b.reminder_sent_at IS NULL
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

	private static function cast( $row ) {
		$row['id']             = (int) $row['id'];
		$row['slot_id']        = $row['slot_id'] ? (int) $row['slot_id'] : null;
		$row['bookable_item_id'] = (int) $row['bookable_item_id'];
		$row['customer_id']    = $row['customer_id'] ? (int) $row['customer_id'] : null;
		$row['party_size']     = (int) $row['party_size'];
		$row['payment_amount'] = null === $row['payment_amount'] ? null : (float) $row['payment_amount'];
		$row['tax_amount']     = null === $row['tax_amount'] ? null : (float) $row['tax_amount'];
		$row['custom_fields']  = $row['custom_fields'] ? json_decode( $row['custom_fields'], true ) : array();

		// slot_start/slot_end come from the LEFT JOIN in get()/query() —
		// always present in the shape (null when the slot was deleted,
		// per DETAILED-SPEC.md §1.4's note on slot_id being nullable).
		$row['slot_start'] = $row['slot_start'] ?? null;
		$row['slot_end']   = $row['slot_end'] ?? null;

		return $row;
	}
}
