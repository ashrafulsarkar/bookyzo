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
 *
 * 1.0.3: the hold is the booking's own `hold_expires_at` (default 15
 * minutes after the payment started, `online_payment_hold_minutes`), and
 * the work moved to BYZO_Booking::release_expired_holds(), which a new
 * booking also calls for its own slot before claiming it. This hourly run
 * is the sweep for everything else.
 */
class BYZO_Booking_Cleanup {

	public static function init() {
		add_action( 'byzo_release_stale_pending_bookings', array( __CLASS__, 'release_stale_pending_bookings' ) );
	}

	public static function release_stale_pending_bookings() {
		BYZO_Booking::release_expired_holds();
	}
}
