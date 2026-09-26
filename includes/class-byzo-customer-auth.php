<?php
defined( 'ABSPATH' ) || exit;

/**
 * Customer self-service registration/login/logout (Phase 1.7 steps 2-3,
 * DETAILED-SPEC.md §A11) — front-end `admin-post.php` handlers, the same
 * guest-write pattern BYZO_Booking_Cancel already established (no
 * REST+nonce plumbing for a not-yet-authenticated visitor; the credentials
 * themselves, checked by wp_signon()/wp_insert_user(), are the real
 * authorization, same reasoning as that class's token check).
 *
 * Redirect targets are always "the page the form was submitted from"
 * (build_redirect_target(), backed by wp_get_referer()) rather than a
 * fixed URL — there's no dedicated "this is THE account page" setting
 * (a site could embed `[bookyzo_account]`, BYZO_Account_Widget's
 * shortcode, on more than one page), so a customer always lands back on
 * whichever page they actually started from. Forgot-password reuses WP
 * core's own wp-login.php screens entirely unmodified (step 3); the
 * login_redirect filter below is what keeps a byzo_customer-role account
 * out of wp-admin after ANY core auth action, not just this class's own
 * login handler.
 *
 * Entirely inert while `customer_accounts_enabled` is off (DETAILED-SPEC.md
 * §A11: "absent, not visibly disabled" — same rule as every Free/Pro
 * capability gate in this doc, applied here even though this toggle has
 * nothing to do with Pro): both admin-post actions redirect straight to
 * an error state rather than half-functioning.
 */
class BYZO_Customer_Auth {

	const ACTION_REGISTER = 'byzo_customer_register';
	const ACTION_LOGIN    = 'byzo_customer_login';
	const ACTION_LOGOUT   = 'byzo_customer_logout';

	/**
	 * Every form here carries a nonce for its own action, put on the form's
	 * URL (BYZO_Account_Widget passes these to the account app), so the
	 * app needs no hidden field of its own.
	 */
	public static function form_url( $action, $args = array() ) {
		return add_query_arg(
			array_merge( array( 'action' => $action, '_wpnonce' => wp_create_nonce( $action ) ), $args ),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * A stale or missing nonce sends the visitor back with a "try again"
	 * message rather than a bare wp_die(): the account page can sit open
	 * (or cached) long enough for its nonce to expire.
	 */
	private static function verify_nonce( $action, $mode ) {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			self::redirect_with_error( 'expired', $mode );
		}
	}

	public static function init() {
		add_action( 'admin_post_nopriv_' . self::ACTION_REGISTER, array( __CLASS__, 'handle_register' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_LOGIN, array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_' . self::ACTION_LOGOUT, array( __CLASS__, 'handle_logout' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'redirect_customer_away_from_wp_admin' ), 10, 3 );
	}

	/**
	 * Phase 1.7 step 3 (DETAILED-SPEC.md §A11) — WP core's own
	 * `wp-login.php` (lost-password/reset screens, reused entirely
	 * unmodified) funnels its post-auth redirect through this one filter,
	 * so it's the single place that keeps a byzo_customer-role account
	 * out of wp-admin after ANY core auth action succeeds for them — not
	 * just this class's own login handler.
	 */
	public static function redirect_customer_away_from_wp_admin( $redirect_to, $requested_redirect_to, $user ) {
		if ( $user instanceof WP_User && in_array( 'byzo_customer', (array) $user->roles, true ) ) {
			return self::build_redirect_target();
		}

		return $redirect_to;
	}

	public static function enabled() {
		return (bool) BYZO_Settings::get()['customer_accounts_enabled'];
	}

	/**
	 * Where an admin-post handler sends the browser back to — the page
	 * the form was submitted from (wp_get_referer(), the same page a
	 * `[bookyzo_account]` shortcode renders on), falling back to the site
	 * home when no referer is available (e.g. a direct POST with no
	 * Referer header). $args are appended as query args (?error=/?mode=)
	 * for BYZO_Account_Widget's config() to pick back up.
	 */
	public static function build_redirect_target( $args = array() ) {
		// The referer can still carry the previous attempt's ?error=/?mode=
		// (a failed register, then a successful one) — drop them so a
		// success doesn't land on the dashboard with a stale error.
		$base = remove_query_arg( array( 'error', 'mode', 'welcome' ), wp_get_referer() ?: home_url( '/' ) );

		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/**
	 * A real switch (not an array lookup) so `wp i18n make-pot` can
	 * statically extract every literal __() call, same requirement every
	 * other translatable string in this plugin already follows. Public —
	 * BYZO_Account_Widget::config() calls this to hand Vue the same
	 * mapped message a failed admin-post redirect's ?error= arg names.
	 */
	public static function error_message( $error_code ) {
		switch ( $error_code ) {
			case 'disabled':
				return __( 'Customer accounts are not enabled on this site.', 'bookyzo' );
			case 'missing_fields':
				return __( 'Please fill in all required fields.', 'bookyzo' );
			case 'invalid_email':
				return __( 'Please enter a valid email address.', 'bookyzo' );
			case 'weak_password':
				return __( 'Password must be at least 8 characters.', 'bookyzo' );
			case 'email_taken':
				return __( 'An account with this email already exists. Try logging in instead.', 'bookyzo' );
			case 'invalid_login':
				return __( 'Incorrect email or password.', 'bookyzo' );
			case 'registration_failed':
				return __( 'Could not create your account. Please try again.', 'bookyzo' );
			case 'expired':
				return __( 'This form has expired. Please try again.', 'bookyzo' );
			default:
				return '';
		}
	}

	public static function handle_register() {
		if ( ! self::enabled() ) {
			self::redirect_with_error( 'disabled', 'register' );
		}
		self::verify_nonce( self::ACTION_REGISTER, 'register' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above by verify_nonce().
		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is used exactly as typed, never sanitized (same as wp-login.php).
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $name || '' === $email || '' === $password ) {
			self::redirect_with_error( 'missing_fields', 'register' );
		}
		if ( ! is_email( $email ) ) {
			self::redirect_with_error( 'invalid_email', 'register' );
		}
		if ( strlen( $password ) < 8 ) {
			self::redirect_with_error( 'weak_password', 'register' );
		}
		if ( email_exists( $email ) ) {
			self::redirect_with_error( 'email_taken', 'register' );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'role'         => 'byzo_customer',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			self::redirect_with_error( 'registration_failed', 'register' );
		}

		// Links to an existing guest-created row by email rather than
		// duplicating (DETAILED-SPEC.md §A11) — upsert_by_email() itself is
		// reused unchanged; wp_user_id is set in a second, explicit step
		// since that column is new to this class, not part of the
		// booking-upsert flow upsert_by_email() otherwise serves.
		$customer_id = BYZO_Customer::upsert_by_email( $name, $email );
		if ( $customer_id ) {
			BYZO_Customer::update( $customer_id, array( 'wp_user_id' => $user_id ) );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		// ?welcome=1 lets the dashboard greet a brand-new account as new
		// rather than "Welcome back" (QA report 2026-09-22 U7).
		wp_safe_redirect( self::build_redirect_target( array( 'welcome' => '1' ) ) );
		exit;
	}

	public static function handle_login() {
		if ( ! self::enabled() ) {
			self::redirect_with_error( 'disabled', 'login' );
		}
		self::verify_nonce( self::ACTION_LOGIN, 'login' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above by verify_nonce().
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a password is used exactly as typed, never sanitized (same as wp-login.php).
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$user = wp_signon(
			array(
				'user_login'    => $email,
				'user_password' => $password,
				'remember'      => true,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			self::redirect_with_error( 'invalid_login', 'login' );
		}

		wp_safe_redirect( self::build_redirect_target() );
		exit;
	}

	public static function handle_logout() {
		check_admin_referer( self::ACTION_LOGOUT );
		wp_logout();

		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above by check_admin_referer().
		wp_safe_redirect( $redirect_to ?: self::build_redirect_target() );
		exit;
	}

	private static function redirect_with_error( $error_code, $mode ) {
		wp_safe_redirect( self::build_redirect_target( array( 'error' => $error_code, 'mode' => $mode ) ) );
		exit;
	}
}
