<?php
defined( 'ABSPATH' ) || exit;

/**
 * Marketing consent and one-click unsubscribe (QA report 2026-09-22 §4.6).
 *
 * Campaign sending itself is an add-on feature, but consent is the
 * customer's, not the add-on's: the flags live on Free's own customers
 * table, the widget's opt-in writes them, and the unsubscribe link works
 * whether or not the add-on that sent the email is still installed.
 *
 * Same shape as BYZO_Booking_Cancel: a stateless signed link, a plain GET
 * page rendered through `template_redirect` (outside wp-admin and outside
 * the REST API), and one click to act — an unsubscribe that asks people to
 * log in or fill a form isn't one.
 */
class BYZO_Marketing {

	const QUERY_VAR = 'byzo_unsubscribe';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_page' ) );
	}

	/**
	 * Signed with the customer's own email, so the link stops working if
	 * the row is reassigned to someone else.
	 */
	public static function token( $customer_id, $email ) {
		return substr( hash_hmac( 'sha256', 'unsubscribe|' . (int) $customer_id . '|' . strtolower( trim( $email ) ), wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function unsubscribe_url( $customer ) {
		return add_query_arg(
			array(
				self::QUERY_VAR => 1,
				'customer_id'   => (int) $customer['id'],
				'token'         => self::token( $customer['id'], $customer['email'] ),
			),
			home_url( '/' )
		);
	}

	public static function maybe_render_page() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed token below is the authorization, not a nonce.
			return;
		}

		$customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token       = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$resubscribe = ! empty( $_GET['resubscribe'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo self::render( $customer_id, $token, $resubscribe ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes everything it prints.

		exit;
	}

	/**
	 * Unsubscribing happens on this GET, deliberately: an unsubscribe link
	 * that needs a second click to take effect is the thing people complain
	 * about, and a mail client prefetching it only ever stops mail it was
	 * going to deliver to that same person. Re-subscribing needs the same
	 * signed link plus an explicit `resubscribe` — nobody is opted back in
	 * by accident.
	 *
	 * Public so a CLI smoke test can exercise it without forking a request,
	 * same reasoning as BYZO_Booking_Cancel::render().
	 */
	public static function render( $customer_id, $token, $resubscribe = false ) {
		$customer = BYZO_Customer::get( $customer_id );
		$valid    = $customer && hash_equals( self::token( $customer['id'], $customer['email'] ), (string) $token );

		if ( $valid ) {
			BYZO_Customer::set_marketing( $customer['id'], array( 'unsubscribed' => ! $resubscribe ) );
			$customer = BYZO_Customer::get( $customer_id );
		}

		return byzo_get_template(
			'unsubscribe.php',
			array(
				'valid'       => $valid,
				'customer'    => $customer,
				'resubscribe' => $resubscribe,
				'resume_url'  => $valid ? add_query_arg( 'resubscribe', 1, self::unsubscribe_url( $customer ) ) : '',
			)
		);
	}
}
