<?php
defined( 'ABSPATH' ) || exit;

/**
 * Auto-cancels stale `pending` bookings whose online payment was started
 * but never finished (DETAILED-SPEC.md §3 B9, Phase 5 step 1). Before
 * this, no booking ever expired — a direct Stripe/PayPal booking that was
 * never paid held its slot's capacity forever. Surfaced by the WooCommerce
 * bridge's longer, page-reload-based checkout (a bigger abandonment window
 * than the widget's own single-page flow), but the gap itself is Free-side
 * and real regardless of the bridge.
 *
 * Narrowed 2026-09-22: the sweep used to take every pending+unpaid booking,
 * which with the default manual-approval setting also cancelled free
 * bookings and "Pay in person" bookings the admin simply hadn't approved
 * within the hold window — and emailed the customer that it was cancelled.
 * Now only a booking whose `payment_method` records an online payment in
 * progress (anything but NULL / `in_person`, see BYZO_Booking::set_payment_method())
 * qualifies.
 */
class BYZO_Booking_Cleanup {

	public static function init() {
		add_action( 'byzo_release_stale_pending_bookings', array( __CLASS__, 'release_stale_pending_bookings' ) );
	}

	public static function release_stale_pending_bookings() {
		global $wpdb;

		$hold_hours = (int) BYZO_Settings::get()['pending_booking_hold_hours'];
		if ( $hold_hours <= 0 ) {
			return;
		}

		// created_at is stored in site-local time (current_time( 'mysql' )),
		// so the cutoff must be too — wp_date() formats in wp_timezone().
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - ( $hold_hours * HOUR_IN_SECONDS ) );
		$table  = $wpdb->prefix . 'byzo_bookings';

		$stale_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = 'pending' AND payment_status = 'unpaid' AND payment_method IS NOT NULL AND payment_method <> 'in_person' AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		foreach ( $stale_ids as $id ) {
			// Goes through the same state machine (and capacity release)
			// every other cancellation uses — no bespoke cleanup path.
			BYZO_Booking::update_status( (int) $id, 'cancelled' );
		}
	}
}
