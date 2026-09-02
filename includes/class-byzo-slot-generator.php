<?php
defined( 'ABSPATH' ) || exit;

/**
 * Turns a resource's weekly schedule + exceptions into materialized rows
 * in wp_byzo_slots, on a rolling window (DETAILED-SPEC.md §1.3). Slots are
 * materialized (not computed on the fly) so a booking can atomically
 * decrement capacity_remaining on a real row — see the "why" note in
 * DETAILED-SPEC.md §1.3.
 *
 * All datetimes here are site-local (DETAILED-SPEC.md §5 item 1) — no
 * timezone conversion happens in this class, `current_time()`/
 * `wp_date()` semantics only.
 */
class BYZO_Slot_Generator {

	/**
	 * Regenerates the rolling window of slots for one resource.
	 *
	 * @param int      $resource_id
	 * @param int|null $days_ahead Defaults to byzo_settings['max_advance_days'].
	 * @return int Number of slot rows upserted.
	 */
	public static function generate_for_resource( $resource_id, $days_ahead = null ) {
		$resource = BYZO_Resource::get( $resource_id );
		if ( ! $resource || 'active' !== $resource['status'] ) {
			return 0;
		}

		$settings   = self::get_settings();
		$days_ahead = null === $days_ahead ? (int) $settings['max_advance_days'] : (int) $days_ahead;
		$days_ahead = max( 0, $days_ahead );

		$availability = BYZO_Availability::get_for_resource( $resource_id );
		$schedules_by_weekday = array();
		foreach ( $availability['schedules'] as $row ) {
			$schedules_by_weekday[ (int) $row['weekday'] ][] = $row;
		}

		$exceptions_by_date = array();
		foreach ( $availability['exceptions'] as $row ) {
			$exceptions_by_date[ $row['date'] ][] = $row;
		}

		$duration_minutes = max( 1, (int) $resource['duration_minutes'] );
		$buffer_minutes   = max( 0, (int) $settings['buffer_minutes'] );
		$min_advance      = new DateTime( '+' . max( 0, (int) $settings['min_advance_hours'] ) . ' hours', wp_timezone() );

		$today         = new DateTime( 'today', wp_timezone() );
		$slots         = array();
		$blocked_dates = array();

		for ( $offset = 0; $offset <= $days_ahead; $offset++ ) {
			$date  = ( clone $today )->modify( "+{$offset} days" );
			$date_str = $date->format( 'Y-m-d' );
			$weekday  = (int) $date->format( 'w' );

			$day_exceptions = $exceptions_by_date[ $date_str ] ?? array();
			$is_closed      = false;
			$override_hours = null;

			foreach ( $day_exceptions as $exception ) {
				if ( $exception['is_closed'] ) {
					$is_closed = true;
					break;
				}
				$override_hours = array( $exception['start_time'], $exception['end_time'] );
			}

			if ( $is_closed ) {
				// A blackout can be added AFTER slots for that date were
				// already materialized by an earlier cron run — block any
				// still-open ones rather than leaving them bookable.
				$blocked_dates[] = $date_str;
				continue;
			}

			$windows = $override_hours ? array( $override_hours ) : array_map(
				static function ( $row ) {
					return array( $row['start_time'], $row['end_time'] );
				},
				$schedules_by_weekday[ $weekday ] ?? array()
			);

			foreach ( $windows as list( $start_time, $end_time ) ) {
				$slots = array_merge(
					$slots,
					self::build_day_slots( $resource, $date_str, $start_time, $end_time, $duration_minutes, $buffer_minutes, $min_advance )
				);
			}
		}

		if ( $blocked_dates ) {
			self::block_slots_on_dates( $resource_id, $blocked_dates );
		}

		return self::upsert_slots( $resource_id, $slots );
	}

	/**
	 * Marks any still-`open` slots on the given dates as `blocked` —
	 * only touches slots with no capacity taken yet (capacity_remaining
	 * unchanged means nobody booked it), so an existing booking on a date
	 * later marked as an exception is never silently orphaned; that's a
	 * cancellation decision for a human, not something the generator
	 * should do implicitly.
	 */
	private static function block_slots_on_dates( $resource_id, $dates ) {
		global $wpdb;

		$table        = $wpdb->prefix . 'byzo_slots';
		$placeholders = implode( ',', array_fill( 0, count( $dates ), '%s' ) );

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET status = 'blocked' WHERE resource_id = %d AND status = 'open' AND DATE(start_datetime) IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( array( $resource_id ), $dates )
		);

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Builds slot rows for one open window on one date, honoring the
	 * minimum-advance-notice cutoff.
	 */
	private static function build_day_slots( $resource, $date_str, $start_time, $end_time, $duration_minutes, $buffer_minutes, DateTime $min_advance ) {
		$tz = wp_timezone();

		$cursor = new DateTime( "{$date_str} {$start_time}", $tz );
		$end    = new DateTime( "{$date_str} {$end_time}", $tz );
		$step   = new DateInterval( 'PT' . ( $duration_minutes + $buffer_minutes ) . 'M' );

		$slots = array();

		while ( true ) {
			$slot_end = ( clone $cursor )->modify( "+{$duration_minutes} minutes" );
			if ( $slot_end > $end ) {
				break;
			}

			if ( $cursor >= $min_advance ) {
				$slots[] = array(
					'resource_id'         => $resource['id'],
					'start_datetime'      => $cursor->format( 'Y-m-d H:i:s' ),
					'end_datetime'        => $slot_end->format( 'Y-m-d H:i:s' ),
					'capacity_remaining'  => max( 1, (int) $resource['capacity'] ),
					'status'              => 'open',
				);
			}

			$cursor->add( $step );
		}

		return $slots;
	}

	/**
	 * Idempotent upsert keyed on (resource_id, start_datetime) — the
	 * unique key from DETAILED-SPEC.md §1.3 / the activator's schema.
	 * Re-running for a date range that already has slots must not
	 * duplicate or clobber a slot that already has bookings against it
	 * (ON DUPLICATE KEY UPDATE only touches end_datetime; it deliberately
	 * never rewrites capacity_remaining/status on an existing row, since
	 * those reflect live booking state the generator must not stomp on).
	 */
	private static function upsert_slots( $resource_id, $slots ) {
		global $wpdb;

		if ( empty( $slots ) ) {
			return 0;
		}

		$table = $wpdb->prefix . 'byzo_slots';
		$count = 0;

		foreach ( $slots as $slot ) {
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (resource_id, start_datetime, end_datetime, capacity_remaining, status)
				 VALUES (%d, %s, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE end_datetime = VALUES(end_datetime)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slot['resource_id'],
				$slot['start_datetime'],
				$slot['end_datetime'],
				$slot['capacity_remaining'],
				$slot['status']
			);
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$count++;
		}

		return $count;
	}

	/**
	 * Regenerates slots for every active resource. Hooked to the
	 * `byzo_generate_slots` cron event (registered in BYZO_Plugin).
	 */
	public static function generate_all() {
		$resources = BYZO_Resource::query(
			array(
				'status'   => 'active',
				'per_page' => 100,
			)
		);

		foreach ( $resources['items'] as $resource ) {
			self::generate_for_resource( $resource['id'] );
		}
	}

	private static function get_settings() {
		$defaults = array(
			'min_advance_hours' => 2,
			'max_advance_days'  => 30,
			'buffer_minutes'    => 0,
		);

		return wp_parse_args( get_option( 'byzo_settings', array() ), $defaults );
	}
}
