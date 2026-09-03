<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read-only data-access layer for wp_byzo_slots (DETAILED-SPEC.md §1.3).
 * No create/update here — rows are only ever written by
 * BYZO_Slot_Generator (materialization) and BYZO_Booking (capacity
 * decrement on booking, added when the /bookings endpoint lands).
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

		$where_sql = implode( ' AND ', $where );
		$sql       = 'SELECT * FROM ' . self::table() . " WHERE {$where_sql} ORDER BY start_datetime ASC";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

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

	private static function sanitize_date( $value ) {
		$value = trim( (string) $value );
		$dt    = DateTime::createFromFormat( 'Y-m-d', $value );
		return ( $dt && $dt->format( 'Y-m-d' ) === $value ) ? $value : false;
	}

	private static function cast( $row ) {
		$row['id']                  = (int) $row['id'];
		$row['bookable_item_id']    = (int) $row['bookable_item_id'];
		$row['capacity_remaining']  = (int) $row['capacity_remaining'];
		return $row;
	}
}
