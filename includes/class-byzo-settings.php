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
			// Added 2026-09-03 (onboarding wizard deepening, DETAILED-SPEC.md
			// §A1/§A4/§A6) — surfaced in the wizard's Customer Information and
			// Confirmation & Notifications steps, but real settings fields in
			// their own right, editable later outside the wizard too.
			'require_phone'           => false,
			'auto_confirm'            => false,
			'send_confirmation_email' => true,
			'send_admin_notification' => true,
			// Added 2026-09-03 (Phase 1.5 step 7, DETAILED-SPEC.md §A4) — the
			// single Free cancellation-policy rule. 0 = no guest
			// self-service cancellation at all; otherwise the guest cancel
			// link (BYZO_Booking_Cancel) only works while the slot start is
			// more than this many hours away. Admin cancellation from the
			// Bookings screen is never subject to this.
			'cancellation_hours'      => 24,
			// Added 2026-09-05 (Phase 1.5 step 9, DETAILED-SPEC.md §A7) — a
			// flat site-wide percentage, 0 = no tax added. Per-bookable item
			// overrides (`bookable_items.tax_rate_override`) are Pro-only
			// and not part of this schema touch; Free's UI only ever writes
			// this one site-wide value.
			'tax_rate'                => 0,
			// Added 2026-09-06 (Phase 1.7 step 1, DETAILED-SPEC.md §A11) —
			// off by default; flips on the [bookyzo_account] shortcode's
			// login/register UI and the widget's booking-time "Create an
			// account" opt-in. Inert (no wp_user_id ever created) while off.
			'customer_accounts_enabled' => false,
			// Added 2026-09-11 (Phase 2 step 4, DETAILED-SPEC.md §A2) — the
			// shared weekly schedule a bookable item can link to instead of
			// keeping its own (`bookable_items.uses_business_hours`, Pro-only
			// per BYZO_Capabilities::business_hours_link_enabled()). Same row
			// shape as a bookable item's own schedule (weekday/start_time/
			// end_time/shift_group) so it reuses ByzoWeeklyScheduleGrid.vue
			// unchanged. Empty array = not configured yet.
			'business_hours'            => array(),
			// Added 2026-09-13 (Phase 5 step 1, DETAILED-SPEC.md §3 B9) — how
			// long an unpaid `pending` booking holds its slot's capacity
			// before `byzo_release_stale_pending_bookings` auto-cancels it.
			// 0 disables the cleanup entirely (a pending booking holds its
			// slot forever, today's exact behavior on every existing install
			// until this setting is touched).
			'pending_booking_hold_hours' => 24,
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

		foreach ( array( 'require_phone', 'auto_confirm', 'send_confirmation_email', 'send_admin_notification', 'customer_accounts_enabled' ) as $bool_field ) {
			if ( isset( $data[ $bool_field ] ) ) {
				$patch[ $bool_field ] = (bool) $data[ $bool_field ];
			}
		}

		if ( isset( $data['cancellation_hours'] ) ) {
			$patch['cancellation_hours'] = max( 0, absint( $data['cancellation_hours'] ) );
		}

		if ( isset( $data['tax_rate'] ) ) {
			$tax_rate = (float) $data['tax_rate'];
			if ( $tax_rate < 0 || $tax_rate > 100 ) {
				return new WP_Error( 'byzo_invalid_tax_rate', __( 'tax_rate must be between 0 and 100.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$patch['tax_rate'] = $tax_rate;
		}

		if ( isset( $data['pending_booking_hold_hours'] ) ) {
			$patch['pending_booking_hold_hours'] = max( 0, absint( $data['pending_booking_hold_hours'] ) );
		}

		if ( isset( $data['business_hours'] ) ) {
			$rows = self::validate_business_hours( $data['business_hours'] );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			$patch['business_hours'] = $rows;
		}

		$updated = array_merge( $current, $patch );
		update_option( 'byzo_settings', $updated );

		return $updated;
	}

	/**
	 * Validates the `business_hours` row list — same weekday/start_time/
	 * end_time/shift_group shape and start<end rule as
	 * BYZO_Availability::set_schedules(), deliberately WITHOUT that
	 * method's max_shifts_per_day cap: this is the business's own default,
	 * not a per-item schedule subject to the Free/Pro shift-count limit.
	 *
	 * @return array|WP_Error Sanitized rows, or WP_Error.
	 */
	private static function validate_business_hours( $rows ) {
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'byzo_invalid_business_hours', __( 'business_hours must be an array of schedule rows.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$validated = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['weekday'], $row['start_time'], $row['end_time'] ) ) {
				return new WP_Error( 'byzo_invalid_business_hours', __( 'Each business_hours row needs weekday, start_time, and end_time.', 'bookyzo' ), array( 'status' => 400 ) );
			}

			$weekday = (int) $row['weekday'];
			if ( $weekday < 0 || $weekday > 6 ) {
				return new WP_Error( 'byzo_invalid_weekday', __( 'weekday must be 0-6.', 'bookyzo' ), array( 'status' => 400 ) );
			}

			$start = self::sanitize_time( $row['start_time'] );
			$end   = self::sanitize_time( $row['end_time'] );
			if ( ! $start || ! $end ) {
				return new WP_Error( 'byzo_invalid_time', __( 'business_hours start_time/end_time must be valid times (H:i).', 'bookyzo' ), array( 'status' => 400 ) );
			}
			if ( $start >= $end ) {
				return new WP_Error( 'byzo_invalid_time_range', __( 'business_hours start_time must be before end_time.', 'bookyzo' ), array( 'status' => 400 ) );
			}

			$validated[] = array(
				'weekday'     => $weekday,
				'start_time'  => $start,
				'end_time'    => $end,
				'shift_group' => isset( $row['shift_group'] ) ? max( 0, (int) $row['shift_group'] ) : 0,
			);
		}

		return $validated;
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
