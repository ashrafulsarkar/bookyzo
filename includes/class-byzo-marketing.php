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
 * Same shape as BYZO_Booking_Cancel: a stateless signed link opens a page
 * rendered through `template_redirect` (outside wp-admin and outside the
 * REST API), and one button on it POSTs to admin-post.php to act. The
 * signed token says whose preference it is; the nonce makes sure the click
 * came from that page. No login and no form to fill in.
 */
class BYZO_Marketing {

	const QUERY_VAR = 'byzo_unsubscribe';
	const ACTION    = 'byzo_marketing_preference';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_page' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_choice' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_choice' ) );
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
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page render; the signed token (verified in render()) says whose page it is.
			return;
		}

		$customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token       = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$done        = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the message shown.

		echo self::render( $customer_id, $token, $done ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes everything it prints.

		exit;
	}

	/**
	 * Whether $token is this customer's own signed link.
	 *
	 * @param array|null $customer Customer row.
	 * @param string     $token    Token from the link.
	 * @return bool
	 */
	private static function token_valid( $customer, $token ) {
		return $customer && hash_equals( self::token( $customer['id'], $customer['email'] ), (string) $token );
	}

	/**
	 * Shows the customer's current preference with one button to change it.
	 * Changes nothing itself: a mail client or link scanner prefetching the
	 * link can't unsubscribe (or resubscribe) anyone.
	 *
	 * Public so a CLI smoke test can exercise it without forking a request,
	 * same reasoning as BYZO_Booking_Cancel::render().
	 *
	 * @param int    $customer_id Customer id from the link.
	 * @param string $token       Token from the link.
	 * @param string $done        'unsubscribed' | 'resubscribed' right after a change, else ''.
	 * @return string
	 */
	public static function render( $customer_id, $token, $done = '' ) {
		$customer = BYZO_Customer::get( $customer_id );
		$valid    = self::token_valid( $customer, $token );

		byzo_enqueue_standalone_page_style();

		return byzo_get_template(
			'unsubscribe.php',
			array(
				'valid'        => $valid,
				'customer'     => $customer,
				'unsubscribed' => $valid && ! empty( $customer['marketing_unsubscribed'] ),
				'done'         => in_array( $done, array( 'unsubscribed', 'resubscribed' ), true ) ? $done : '',
				'customer_id'  => (int) $customer_id,
				'token'        => (string) $token,
				'action'       => self::ACTION,
			)
		);
	}

	/**
	 * The page's button: checks the nonce and the signed token, applies
	 * the choice, and goes back to the page to show the result. A stale
	 * nonce changes nothing; the page shows again with a fresh one.
	 */
	public static function handle_choice() {
		$nonce       = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$nonce_ok    = (bool) wp_verify_nonce( $nonce, self::ACTION );
		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified just above; a failed check changes nothing.
		$token       = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same.
		$resubscribe = isset( $_POST['choice'] ) && 'resubscribe' === sanitize_key( wp_unslash( $_POST['choice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same.

		$customer = BYZO_Customer::get( $customer_id );
		$done     = '';

		if ( $nonce_ok && self::token_valid( $customer, $token ) ) {
			BYZO_Customer::set_marketing( $customer['id'], array( 'unsubscribed' => ! $resubscribe ) );
			$done = $resubscribe ? 'resubscribed' : 'unsubscribed';
		}

		$url = add_query_arg(
			array(
				self::QUERY_VAR => 1,
				'customer_id'   => $customer_id,
				'token'         => $token,
			),
			home_url( '/' )
		);

		wp_safe_redirect( $done ? add_query_arg( 'done', $done, $url ) : $url );
		exit;
	}
}
