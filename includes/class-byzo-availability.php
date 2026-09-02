<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for a resource's availability: the recurring weekly
 * pattern (wp_byzo_schedules) and blackout/holiday overrides
 * (wp_byzo_availability_exceptions). See DETAILED-SPEC.md §1.2.
 *
 * Free always writes shift_group = 0 (one shift/day, enforced here via
 * BYZO_Capabilities::max_shifts_per_day() — the column itself already
 * supports more, per the "don't gate the data model" principle).
 */
class BYZO_Availability {

	public static function schedules_table() {
		global $wpdb;
		return $wpdb->prefix . 'byzo_schedules';
	}

	public static function exceptions_table() {
		global $wpdb;
		return $wpdb->prefix . 'byzo_availability_exceptions';
	}

	public static function get_for_resource( $resource_id ) {
		global $wpdb;

		$schedules = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, weekday, start_time, end_time, shift_group FROM ' . self::schedules_table() . ' WHERE resource_id = %d ORDER BY weekday ASC, shift_group ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$resource_id
			),
			ARRAY_A
		);

		$exceptions = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, date, is_closed, start_time, end_time FROM ' . self::exceptions_table() . ' WHERE resource_id = %d OR resource_id IS NULL ORDER BY date ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$resource_id
			),
			ARRAY_A
		);

		return array(
			'schedules'  => array_map( array( __CLASS__, 'cast_schedule' ), $schedules ?: array() ),
			'exceptions' => array_map( array( __CLASS__, 'cast_exception' ), $exceptions ?: array() ),
		);
	}

	/**
	 * Replaces the full weekly schedule for a resource (PUT semantics —
	 * simplest correct model for a 7-day grid the Vue admin submits as a
	 * whole, rather than per-row PATCHes). Delete + re-insert inside a
	 * transaction-like pair of queries; wp_byzo_schedules is small per
	 * resource (max 7 rows on Free, a few dozen on Pro) so this is cheap.
	 *
	 * @param array $rows Each: weekday (0-6), start_time (H:i or H:i:s), end_time, shift_group (optional, default 0).
	 * @return true|WP_Error
	 */
	public static function set_schedules( $resource_id, $rows ) {
		global $wpdb;

		$max_shifts   = BYZO_Capabilities::max_shifts_per_day();
		$per_weekday  = array();
		$validated    = array();

		foreach ( $rows as $row ) {
			if ( ! isset( $row['weekday'], $row['start_time'], $row['end_time'] ) ) {
				return new WP_Error( 'byzo_invalid_schedule', __( 'Each schedule row needs weekday, start_time, and end_time.', 'bookyzo' ), array( 'status' => 400 ) );
			}

			$weekday = (int) $row['weekday'];
			if ( $weekday < 0 || $weekday > 6 ) {
				return new WP_Error( 'byzo_invalid_weekday', __( 'weekday must be 0-6.', 'bookyzo' ), array( 'status' => 400 ) );
			}

			$start = self::sanitize_time( $row['start_time'] );
			$end   = self::sanitize_time( $row['end_time'] );
			if ( ! $start || ! $end ) {
				return new WP_Error( 'byzo_invalid_time', __( 'start_time/end_time must be valid times (H:i).', 'bookyzo' ), array( 'status' => 400 ) );
			}
			if ( $start >= $end ) {
				return new WP_Error( 'byzo_invalid_time_range', __( 'start_time must be before end_time.', 'bookyzo' ), array( 'status' => 400 ) );
			}

			$shift_group = isset( $row['shift_group'] ) ? max( 0, (int) $row['shift_group'] ) : 0;

			$per_weekday[ $weekday ] = ( $per_weekday[ $weekday ] ?? 0 ) + 1;
			if ( $per_weekday[ $weekday ] > $max_shifts ) {
				return new WP_Error(
					'byzo_shift_limit_reached',
					sprintf(
						/* translators: %d: maximum shifts per day allowed */
						__( 'Only %d shift(s) per day allowed. Upgrade to add more.', 'bookyzo' ),
						$max_shifts
					),
					array( 'status' => 403 )
				);
			}

			$validated[] = array(
				'resource_id' => $resource_id,
				'weekday'     => $weekday,
				'start_time'  => $start,
				'end_time'    => $end,
				'shift_group' => $shift_group,
			);
		}

		$wpdb->delete( self::schedules_table(), array( 'resource_id' => $resource_id ) );

		foreach ( $validated as $row ) {
			$wpdb->insert( self::schedules_table(), $row );
		}

		return true;
	}

	/**
	 * Adds one exception (blackout day or custom hours override).
	 *
	 * @return int|WP_Error New exception ID, or WP_Error.
	 */
	public static function add_exception( $resource_id, $data ) {
		global $wpdb;

		if ( empty( $data['date'] ) ) {
			return new WP_Error( 'byzo_missing_date', __( 'date is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$date = self::sanitize_date( $data['date'] );
		if ( ! $date ) {
			return new WP_Error( 'byzo_invalid_date', __( 'date must be a valid date (Y-m-d).', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$is_closed = ! isset( $data['is_closed'] ) || (bool) $data['is_closed'];

		$row = array(
			'resource_id' => $resource_id,
			'date'        => $date,
			'is_closed'   => $is_closed ? 1 : 0,
		);

		if ( ! $is_closed ) {
			$start = ! empty( $data['start_time'] ) ? self::sanitize_time( $data['start_time'] ) : null;
			$end   = ! empty( $data['end_time'] ) ? self::sanitize_time( $data['end_time'] ) : null;
			if ( ! $start || ! $end ) {
				return new WP_Error( 'byzo_invalid_time', __( 'start_time/end_time are required when is_closed is false.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$row['start_time'] = $start;
			$row['end_time']   = $end;
		}

		$inserted = $wpdb->insert( self::exceptions_table(), $row );

		if ( false === $inserted ) {
			return new WP_Error( 'byzo_db_error', __( 'Could not add exception.', 'bookyzo' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete_exception( $resource_id, $exception_id ) {
		global $wpdb;

		$deleted = $wpdb->delete(
			self::exceptions_table(),
			array(
				'id'          => $exception_id,
				'resource_id' => $resource_id,
			)
		);

		if ( ! $deleted ) {
			return new WP_Error( 'byzo_not_found', __( 'Exception not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		return true;
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

	private static function sanitize_date( $value ) {
		$value = trim( (string) $value );
		$dt    = DateTime::createFromFormat( 'Y-m-d', $value );
		return ( $dt && $dt->format( 'Y-m-d' ) === $value ) ? $value : false;
	}

	private static function cast_schedule( $row ) {
		$row['id']          = (int) $row['id'];
		$row['weekday']     = (int) $row['weekday'];
		$row['shift_group'] = (int) $row['shift_group'];
		return $row;
	}

	private static function cast_exception( $row ) {
		$row['id']        = (int) $row['id'];
		$row['is_closed'] = (bool) (int) $row['is_closed'];
		return $row;
	}
}
