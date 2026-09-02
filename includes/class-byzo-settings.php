<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read/update layer for the `byzo_settings` option (DETAILED-SPEC.md §1.2
 * "Business-level settings", seeded by BYZO_Activator on first activation).
 * A whitelist validator, not a passthrough — REST callers can only ever
 * touch the known fields, and each is sanity-checked before being saved
 * so a bad value (e.g. negative buffer_minutes) can't silently corrupt
 * slot generation math downstream.
 */
class BYZO_Settings {

	/**
	 * @return array The current settings, merged over defaults so a
	 * value added to the schema after activation always has a fallback.
	 */
	public static function get() {
		$defaults = array(
			'business_name'      => get_bloginfo( 'name' ),
			'timezone'           => wp_timezone_string(),
			'currency'           => 'USD',
			'min_advance_hours'  => 2,
			'max_advance_days'   => 30,
			'buffer_minutes'     => 0,
			'default_start_time' => '09:00',
			'default_end_time'   => '17:00',
		);

		return wp_parse_args( get_option( 'byzo_settings', array() ), $defaults );
	}

	/**
	 * @param array $data Partial or full settings patch.
	 * @return array|WP_Error The full settings after the update, or WP_Error on validation failure.
	 */
	public static function update( $data ) {
		$current = self::get();
		$patch   = array();

		if ( isset( $data['business_name'] ) ) {
			$name = sanitize_text_field( $data['business_name'] );
			if ( '' === $name ) {
				return new WP_Error( 'byzo_invalid_business_name', __( 'business_name cannot be empty.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$patch['business_name'] = $name;
		}

		if ( isset( $data['timezone'] ) ) {
			$timezone = sanitize_text_field( $data['timezone'] );
			if ( ! self::is_valid_timezone( $timezone ) ) {
				return new WP_Error( 'byzo_invalid_timezone', __( 'timezone must be a valid PHP timezone identifier (e.g. Asia/Dhaka) or UTC offset (e.g. +06:00).', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$patch['timezone'] = $timezone;
		}

		if ( isset( $data['currency'] ) ) {
			$currency = strtoupper( sanitize_text_field( $data['currency'] ) );
			if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				return new WP_Error( 'byzo_invalid_currency', __( 'currency must be a 3-letter ISO code (e.g. USD, BDT).', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$patch['currency'] = $currency;
		}

		if ( isset( $data['min_advance_hours'] ) ) {
			$patch['min_advance_hours'] = max( 0, absint( $data['min_advance_hours'] ) );
		}

		if ( isset( $data['max_advance_days'] ) ) {
			$max_days = absint( $data['max_advance_days'] );
			if ( $max_days < 1 ) {
				return new WP_Error( 'byzo_invalid_max_advance_days', __( 'max_advance_days must be at least 1.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$patch['max_advance_days'] = $max_days;
		}

		if ( isset( $data['buffer_minutes'] ) ) {
			$patch['buffer_minutes'] = max( 0, absint( $data['buffer_minutes'] ) );
		}

		if ( isset( $data['default_start_time'] ) ) {
			$time = self::sanitize_time( $data['default_start_time'] );
			if ( ! $time ) {
				return new WP_Error( 'byzo_invalid_time', __( 'default_start_time must be a valid time (H:i).', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$patch['default_start_time'] = $time;
		}

		if ( isset( $data['default_end_time'] ) ) {
			$time = self::sanitize_time( $data['default_end_time'] );
			if ( ! $time ) {
				return new WP_Error( 'byzo_invalid_time', __( 'default_end_time must be a valid time (H:i).', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$patch['default_end_time'] = $time;
		}

		$updated = array_merge( $current, $patch );
		update_option( 'byzo_settings', $updated );

		return $updated;
	}

	private static function is_valid_timezone( $timezone ) {
		if ( in_array( $timezone, timezone_identifiers_list(), true ) ) {
			return true;
		}

		// wp_timezone_string() can also return a raw UTC offset like "+06:00" for sites without an Olson ID set.
		return (bool) preg_match( '/^[+-]\d{2}:\d{2}$/', $timezone );
	}

	private static function sanitize_time( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value ) ? $value : false;
	}
}
