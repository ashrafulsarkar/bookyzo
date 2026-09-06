<?php
defined( 'ABSPATH' ) || exit;

/**
 * Customer self-service registration/login/logout (Phase 1.7 step 2,
 * DETAILED-SPEC.md §A11) — front-end `admin-post.php` handlers, the same
 * guest-write pattern BYZO_Booking_Cancel already established (no
 * REST+nonce plumbing for a not-yet-authenticated visitor; the credentials
 * themselves, checked by wp_signon()/wp_insert_user(), are the real
 * authorization, same reasoning as that class's token check).
 *
 * Renders a minimal standalone login/register page via `template_redirect`
 * (own query var, bypasses the theme, mirrors manage-booking.php) as a
 * placeholder landing target — Phase 1.7 step 4 replaces this with the
 * real `[bookyzo_account]` Vue-mounted page; this class's admin-post
 * handlers and their behavior are unaffected by that later swap.
 *
 * Entirely inert while `customer_accounts_enabled` is off (DETAILED-SPEC.md
 * §A11: "absent, not visibly disabled" — same rule as every Free/Pro
 * capability gate in this doc, applied here even though this toggle has
 * nothing to do with Pro).
 */
class BYZO_Customer_Auth {

	const QUERY_VAR       = 'byzo_account';
	const ACTION_REGISTER = 'byzo_customer_register';
	const ACTION_LOGIN    = 'byzo_customer_login';
	const ACTION_LOGOUT   = 'byzo_customer_logout';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_page' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_REGISTER, array( __CLASS__, 'handle_register' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_LOGIN, array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_' . self::ACTION_LOGOUT, array( __CLASS__, 'handle_logout' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'redirect_customer_away_from_wp_admin' ), 10, 3 );
	}

	/**
	 * Phase 1.7 step 3 (DETAILED-SPEC.md §A11) — WP core's own
	 * `wp-login.php` (lost-password/reset screens, reused entirely
	 * unmodified) and `wp_signon()` both funnel their post-auth redirect
	 * through this one filter, so it's the single place that keeps a
	 * byzo_customer-role account out of wp-admin after ANY core auth
	 * action succeeds for them — not just this class's own login handler.
	 */
	public static function redirect_customer_away_from_wp_admin( $redirect_to, $requested_redirect_to, $user ) {
		if ( $user instanceof WP_User && in_array( 'byzo_customer', (array) $user->roles, true ) ) {
			return self::build_link();
		}

		return $redirect_to;
	}

	private static function enabled() {
		return (bool) BYZO_Settings::get()['customer_accounts_enabled'];
	}

	public static function build_link( $args = array() ) {
		return add_query_arg( array_merge( array( self::QUERY_VAR => 1 ), $args ), home_url( '/' ) );
	}

	public static function maybe_render_page() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page render, nothing here authorizes a write.
			return;
		}

		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mode  = ( isset( $_GET['mode'] ) && 'register' === $_GET['mode'] ) ? 'register' : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo self::render( $mode, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render()'s own template escapes everything it outputs, see templates/account.php.

		exit;
	}

	/**
	 * Public (not called directly by WP, only via maybe_render_page() above)
	 * so a CLI smoke test can exercise the page-state logic directly,
	 * matching BYZO_Booking_Cancel::render()'s own documented reasoning.
	 */
	public static function render( $mode, $error_code ) {
		if ( is_user_logged_in() ) {
			$state = 'dashboard';
		} elseif ( ! self::enabled() ) {
			$state = 'disabled';
		} else {
			$state = $mode;
		}

		return byzo_get_template(
			'account.php',
			array(
				'state'            => $state,
				'error_message'    => self::error_message( $error_code ),
				'register_action'  => self::ACTION_REGISTER,
				'login_action'     => self::ACTION_LOGIN,
				'logout_action'    => self::ACTION_LOGOUT,
			)
		);
	}

	/**
	 * A real switch (not an array lookup) so `wp i18n make-pot` can
	 * statically extract every literal __() call, same requirement every
	 * other translatable string in this plugin already follows.
	 */
	private static function error_message( $error_code ) {
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
			default:
				return '';
		}
	}

	public static function handle_register() {
		if ( ! self::enabled() ) {
			self::redirect_with_error( 'disabled', 'register' );
		}

		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guest write, no session to protect yet; see class docblock.
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

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
				'user_login' => $email,
				'user_email' => $email,
				'user_pass'  => $password,
				'display_name' => $name,
				'role'       => 'byzo_customer',
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

		wp_safe_redirect( self::build_link() );
		exit;
	}

	public static function handle_login() {
		if ( ! self::enabled() ) {
			self::redirect_with_error( 'disabled', 'login' );
		}

		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guest write, no session to protect yet; see class docblock.
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

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

		wp_safe_redirect( self::build_link() );
		exit;
	}

	public static function handle_logout() {
		wp_logout();
		wp_safe_redirect( self::build_link() );
		exit;
	}

	private static function redirect_with_error( $error_code, $mode ) {
		wp_safe_redirect( self::build_link( array( 'error' => $error_code, 'mode' => $mode ) ) );
		exit;
	}
}
