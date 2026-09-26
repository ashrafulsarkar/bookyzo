<?php
defined( 'ABSPATH' ) || exit;

/**
 * Guest self-service cancellation (DETAILED-SPEC.md §A4/§A5) — the
 * `{cancel_link}` placeholder's actual destination. Entirely outside
 * wp-admin and outside the REST API: a plain GET page (via
 * `template_redirect`, bypassing the active theme entirely — this is a
 * transactional utility page, not site content) showing a confirm
 * button that POSTs to `admin-post.php` (the same guest-triggered-write
 * pattern WordPress itself provides via `admin_post_nopriv_`), rather
 * than a REST+JS round trip. The signed token itself (unforgeable HMAC,
 * see BYZO_Booking_Token) is already the real authorization here — a
 * CSRF nonce on top would add nothing a session-less guest flow needs,
 * same reasoning BYZO_Public_Guard already applies to the guest booking
 * POST itself.
 */
class BYZO_Booking_Cancel {

	const QUERY_VAR = 'byzo_manage_booking';
	const ACTION    = 'byzo_cancel_booking';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_page' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_cancel' ) );
	}

	/**
	 * The `{cancel_link}` email placeholder's value — a stateless,
	 * self-contained URL (query args on the site's own home URL, no
	 * rewrite rules or options needed).
	 */
	public static function build_link( $booking_id, $customer_email ) {
		return add_query_arg(
			array(
				self::QUERY_VAR => 1,
				'booking_id'    => $booking_id,
				'token'         => BYZO_Booking_Token::generate( $booking_id, $customer_email ),
			),
			home_url( '/' )
		);
	}

	public static function maybe_render_page() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page render; the token param (verified in render()) is the real authorization, not a nonce.
			return;
		}

		$booking_id = isset( $_GET['booking_id'] ) ? absint( $_GET['booking_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token      = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cancelled  = ! empty( $_GET['cancelled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo self::render( $booking_id, $token, $cancelled ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render()'s own template escapes everything it outputs, see templates/manage-booking.php.

		exit;
	}

	/**
	 * Re-derives the page's state from scratch on every request (rather
	 * than trusting the `cancelled` redirect flag alone) so a reload, a
	 * bookmarked link, or the window closing between two requests all
	 * show the actually-correct state instead of a stale one. Public
	 * (not called directly by WP, only via maybe_render_page() above) so
	 * a CLI smoke test can exercise the page-state logic directly without
	 * forking a process — the same reasoning BYZO_Booking_Export's
	 * stream_csv()/handle() split already documents.
	 */
	public static function render( $booking_id, $token, $already_cancelled ) {
		$booking = BYZO_Booking::get( $booking_id );

		$state   = 'error';
		$message = __( 'This link is invalid or has expired.', 'bookyzo' );
		$item    = null;

		if ( $already_cancelled ) {
			$state = 'cancelled';
		} elseif ( $booking && BYZO_Booking_Token::verify( $booking_id, $booking['customer_email'], $token ) ) {
			$item = BYZO_Bookable_Item::get( $booking['bookable_item_id'] );

			if ( 'cancelled' === $booking['status'] ) {
				$message = __( 'This booking has already been cancelled.', 'bookyzo' );
			} elseif ( ! in_array( $booking['status'], array( 'pending', 'confirmed' ), true ) ) {
				$message = __( 'This booking can no longer be cancelled online.', 'bookyzo' );
			} else {
				$cancellation_hours = (int) BYZO_Settings::get()['cancellation_hours'];
				$hours_until        = $booking['slot_start']
					? ( ( new DateTime( $booking['slot_start'], wp_timezone() ) )->getTimestamp() - ( new DateTime( 'now', wp_timezone() ) )->getTimestamp() ) / HOUR_IN_SECONDS
					: -1;

				if ( $cancellation_hours <= 0 || $hours_until <= $cancellation_hours ) {
					$message = __( 'This booking is too close to cancel online — please contact us directly.', 'bookyzo' );
				} else {
					$state = 'confirm';
				}
			}
		}

		return byzo_get_template(
			'manage-booking.php',
			array(
				'state'      => $state,
				'message'    => $message,
				'booking'    => $booking,
				'item'       => $item,
				'booking_id' => $booking_id,
				'token'      => $token,
				'action'     => self::ACTION,
			)
		);
	}

	public static function handle_cancel() {
		// The signed token (verified inside cancel_via_token()) is what
		// authorizes the cancel; the nonce makes sure the click came from
		// the manage-booking page itself. A stale nonce just skips the
		// cancel: the redirect below re-shows the page with a fresh one.
		$nonce      = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$nonce_ok   = (bool) wp_verify_nonce( $nonce, self::ACTION );
		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just above; a failed check only skips the cancel.
		$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same.

		$result = $nonce_ok ? BYZO_Booking::cancel_via_token( $booking_id, $token ) : new WP_Error( 'byzo_expired', '' );

		// Redirect back to the same GET page either way (success or
		// failure) — render() re-derives the correct state fresh, so a
		// failure (e.g. the window closed between page-load and click)
		// naturally shows its own real error rather than needing a
		// separate failure branch here.
		$redirect_args = array(
			self::QUERY_VAR => 1,
			'booking_id'    => $booking_id,
			'token'         => $token,
		);
		if ( ! is_wp_error( $result ) ) {
			$redirect_args['cancelled'] = 1;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, home_url( '/' ) ) );
		exit;
	}
}
