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

	const VALID_STATUSES = array( 'pending', 'confirmed', 'completed', 'cancelled', 'no_show' );

	/**
	 * Allowed status transitions. pending is the only entry state (set by
	 * create()); every other status is reached only via update_status().
	 * completed/cancelled/no_show are terminal — nothing transitions out
	 * of them, which is the server-side guarantee DETAILED-SPEC.md §A4
	 * calls for ("prevents completed flipping back to pending").
	 */
	const TRANSITIONS = array(
		'pending'   => array( 'confirmed', 'cancelled', 'no_show' ),
		'confirmed' => array( 'completed', 'cancelled', 'no_show' ),
		'completed' => array(),
		'cancelled' => array(),
		'no_show'   => array(),
	);

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'byzo_bookings';
	}

	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? self::cast( $row ) : null;
	}

	/**
	 * @param array $args { @type string $from, $to (Y-m-d, filters by slot start_datetime's date); @type int $resource_id; @type string $status; @type int $per_page; @type int $page }
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'from'        => '',
			'to'          => '',
			'resource_id' => 0,
			'status'      => '',
			'per_page'    => 20,
			'page'        => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( $args['resource_id'] ) {
			$where[]  = 'resource_id = %d';
			$params[] = (int) $args['resource_id'];
		}

		if ( $args['status'] && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( $args['from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}

		if ( $args['to'] ) {
			$where[]  = 'created_at < %s';
			$params[] = ( new DateTime( $args['to'], wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' ) . ' 00:00:00';
		}

		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$sql      = 'SELECT * FROM ' . self::table() . " WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$count_sql    = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where_sql}";
		$count_params = array_slice( $params, 0, count( $params ) - 2 );
		$total        = $count_params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery

		return array(
			'items' => array_map( array( __CLASS__, 'cast' ), $rows ?: array() ),
			'total' => $total,
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

		$row = array(
			'slot_id'         => $slot['id'],
			'resource_id'     => $slot['resource_id'],
			'customer_name'   => $validated['customer_name'],
			'customer_email'  => $validated['customer_email'],
			'customer_phone'  => $validated['customer_phone'],
			'customer_notes'  => $validated['customer_notes'],
			'party_size'      => $party_size,
			'status'          => 'pending',
			'payment_status'  => 'unpaid',
			'payment_amount'  => $validated['payment_amount'],
			'custom_fields'   => wp_json_encode( $validated['custom_fields'] ),
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		$inserted = $wpdb->insert( self::table(), $row );

		if ( false === $inserted ) {
			// Booking insert failed after capacity was already taken — release it back rather than leaking a phantom hold.
			$wpdb->query( $wpdb->prepare( "UPDATE {$slots_table} SET capacity_remaining = capacity_remaining + %d, status = 'open' WHERE id = %d", $party_size, $slot['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			return new WP_Error( 'byzo_db_error', __( 'Could not create booking.', 'bookyzo' ) );
		}

		$booking_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a booking is created (still in 'pending' status).
		 * Notification/payment logic (Phase 1 steps 7-8) hooks this
		 * rather than living inside create().
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

		$releases_capacity = in_array( $new_status, array( 'cancelled', 'no_show' ), true )
			&& in_array( $booking['status'], array( 'pending', 'confirmed' ), true );

		$updates = array( 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) );
		if ( 'cancelled' === $new_status ) {
			$updates['cancelled_at'] = current_time( 'mysql' );
		}

		$wpdb->update( self::table(), $updates, array( 'id' => $id ) );

		if ( $releases_capacity && $booking['slot_id'] ) {
			$slots_table = $wpdb->prefix . 'byzo_slots';

			// Credit the seat(s) back. A slot that was 'full' also flips
			// back to 'open' (CASE keeps a 'blocked' slot — e.g. a
			// blackout added after this booking was made — from being
			// silently reopened by a later cancellation).
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$slots_table}
					 SET capacity_remaining = capacity_remaining + %d,
					     status = CASE WHEN status = 'full' THEN 'open' ELSE status END
					 WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$booking['party_size'],
					$booking['slot_id']
				)
			);
		}

		do_action( 'byzo_booking_status_changed', $id, $new_status, $booking['status'] );

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

		$party_size = isset( $data['party_size'] ) ? max( 1, absint( $data['party_size'] ) ) : 1;

		return array(
			'slot_id'        => absint( $data['slot_id'] ),
			'customer_name'  => sanitize_text_field( $data['customer_name'] ),
			'customer_email' => sanitize_email( $data['customer_email'] ),
			'customer_phone' => isset( $data['customer_phone'] ) ? sanitize_text_field( $data['customer_phone'] ) : null,
			'customer_notes' => isset( $data['customer_notes'] ) ? wp_kses_post( $data['customer_notes'] ) : null,
			'party_size'     => $party_size,
			'payment_amount' => isset( $data['payment_amount'] ) ? (float) $data['payment_amount'] : null,
			'custom_fields'  => isset( $data['custom_fields'] ) && is_array( $data['custom_fields'] ) ? $data['custom_fields'] : array(),
		);
	}

	private static function cast( $row ) {
		$row['id']             = (int) $row['id'];
		$row['slot_id']        = $row['slot_id'] ? (int) $row['slot_id'] : null;
		$row['resource_id']    = (int) $row['resource_id'];
		$row['party_size']     = (int) $row['party_size'];
		$row['payment_amount'] = null === $row['payment_amount'] ? null : (float) $row['payment_amount'];
		$row['custom_fields']  = $row['custom_fields'] ? json_decode( $row['custom_fields'], true ) : array();

		return $row;
	}
}
