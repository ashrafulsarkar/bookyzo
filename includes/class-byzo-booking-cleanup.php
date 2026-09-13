<?php
defined( 'ABSPATH' ) || exit;

/**
 * Auto-cancels stale unpaid `pending` bookings (DETAILED-SPEC.md §3 B9,
 * Phase 5 step 1). Before this, no booking ever expired — a direct
 * Stripe/PayPal booking that was never paid held its slot's capacity
 * forever. Surfaced by the WooCommerce bridge's longer, page-reload-based
 * checkout (a bigger abandonment window than the widget's own single-page
 * flow), but the gap itself is Free-side and real regardless of the bridge.
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

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $hold_hours * HOUR_IN_SECONDS ) );
		$table  = $wpdb->prefix . 'byzo_bookings';

		$stale_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = 'pending' AND payment_status = 'unpaid' AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
