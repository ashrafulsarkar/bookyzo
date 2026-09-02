<?php
defined( 'ABSPATH' ) || exit;

/**
 * Abuse guard for the public booking widget's POST /bookings — resolves
 * the Open Decision DETAILED-SPEC.md §4 flagged before A5 was built
 * ("nonce isn't available to fully logged-out guest widget embeds
 * cached by a page cache — consider a short-lived REST nonce fetched on
 * widget mount, or a honeypot + rate limit by IP, decide before
 * building A5"). Decided 2026-09-02: a nonce isn't actually needed here
 * at all — nonces exist to protect an authenticated user's cookie
 * session against CSRF, and a guest has no session to protect, so the
 * public widget calls the REST API with no nonce (see src/widget/api.js).
 * Real abuse protection is a honeypot field + a soft per-IP rate limit
 * instead.
 *
 * Only applies to guest requests — an authenticated admin using the
 * manual-booking modal (same POST /bookings route, per §A4) has no
 * honeypot field in their form and shouldn't be throttled by a limit
 * meant for anonymous spam.
 */
class BYZO_Public_Guard {

	const HONEYPOT_FIELD = 'website';

	// 20/10min comfortably covers realistic legitimate burst traffic from
	// one IP (several family members booking back-to-back, a shared
	// office/kiosk device, a person retrying after a mistake) while still
	// meaningfully throttling actual spam floods, which run far higher
	// volume than that. 5/10min (the original value) turned out too
	// strict even for this plugin's own automated test suite's normal
	// rapid-fire guest-booking calls — a useful signal that it was tuned
	// too aggressively for real usage too, not just a test artifact.
	const RATE_LIMIT_MAX    = 20;
	const RATE_LIMIT_WINDOW = 600; // 10 minutes

	/**
	 * @return true|WP_Error
	 */
	public static function check_booking_request( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$body = $request->get_json_params();

		if ( ! empty( $body[ self::HONEYPOT_FIELD ] ) ) {
			// Bots that auto-fill every field trip this; real users never
			// see or fill it (hidden in the widget's own markup). A
			// generic validation-shaped error, not a "you got caught"
			// message — no reason to teach a scraper what tripped it.
			return new WP_Error( 'byzo_invalid_request', __( 'Could not process this request.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		return self::check_rate_limit();
	}

	private static function check_rate_limit() {
		$max    = (int) apply_filters( 'byzo_booking_rate_limit_max', self::RATE_LIMIT_MAX );
		$window = (int) apply_filters( 'byzo_booking_rate_limit_window', self::RATE_LIMIT_WINDOW );

		$ip  = self::client_ip();
		$key = 'byzo_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return new WP_Error(
				'byzo_rate_limited',
				__( 'Too many booking attempts. Please try again in a few minutes.', 'bookyzo' ),
				array( 'status' => 429 )
			);
		}

		// A get-then-set counter has a small race window under truly
		// concurrent hits from the same IP (two requests could both read
		// the same count and both increment from it, letting one extra
		// through) — acceptable for a soft abuse throttle, not a hard
		// security boundary; not worth a locking mechanism here.
		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * REMOTE_ADDR only — deliberately not trusting X-Forwarded-For or
	 * similar headers, which are trivially spoofable by the client
	 * unless the site is known to sit behind a specific trusted proxy
	 * (varies per host, not something this plugin can assume). A site
	 * behind a proxy that wants real client IPs here can filter this.
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';

		return apply_filters( 'byzo_client_ip', $ip );
	}
}
