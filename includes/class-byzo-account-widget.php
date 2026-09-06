<?php
defined( 'ABSPATH' ) || exit;

/**
 * The `[bookyzo_account]` shortcode (Phase 1.7 step 4, DETAILED-SPEC.md
 * §A11) — login/register when logged out, dashboard when logged in,
 * renders nothing when `customer_accounts_enabled` is off. Same
 * architecture as BYZO_Public_Widget: a SEPARATE small Vue bundle
 * (src/account/ -> assets/account/, own manifest, always built-mode
 * only — this screen is expected to change about as rarely as the
 * booking widget did) mounted into a plain div via a shortcode, config
 * passed through a `window.byzoAccount` inline script.
 *
 * Replaces `templates/account.php`'s old `template_redirect`/query-var
 * full-page-bypass render — an account/dashboard page is exactly the
 * kind of persistent page a site owner deliberately places in their own
 * site structure (like WooCommerce's My Account page), unlike the
 * one-off transactional link BYZO_Booking_Cancel's theme-bypass pattern
 * suits. BYZO_Customer_Auth's admin-post handlers and its state-resolving
 * logic (enabled()/render's old state derivation) are unchanged — this
 * class only changes how that state reaches the page (a shortcode mount
 * div + window.byzoAccount, not a printed HTML document).
 */
class BYZO_Account_Widget {

	const ENTRY      = 'account/main.js';
	const SHORTCODE  = 'bookyzo_account';

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * The page containing `[bookyzo_account]` — resolved as "wherever this
	 * shortcode currently renders," i.e. the current request's own URL,
	 * since there's no dedicated settings field pinning one page as *the*
	 * account page (a site could embed the shortcode on more than one
	 * page). Used for the logout/lost-password links below — the login
	 * and register forms instead rely on BYZO_Customer_Auth's own
	 * wp_get_referer()-based redirect, since a <form> submit always
	 * carries a real Referer header pointing at this same page.
	 */
	public static function current_page_url() {
		global $wp;

		if ( $wp instanceof WP && isset( $wp->request ) ) {
			return home_url( add_query_arg( array(), $wp->request ) );
		}

		return home_url( '/' );
	}

	public static function render_shortcode() {
		if ( ! (bool) BYZO_Settings::get()['customer_accounts_enabled'] ) {
			return '';
		}

		self::enqueue_assets();

		return '<div class="bookyzo-account-mount"></div>';
	}

	private static function enqueue_assets() {
		$manifest = self::read_manifest();
		if ( ! $manifest || empty( $manifest[ self::ENTRY ] ) ) {
			// Silently no-op, same reasoning as BYZO_Public_Widget — a
			// shortcode on a page shouldn't fatal the whole page just
			// because `npm run build:account` hasn't run yet.
			return;
		}

		$entry = $manifest[ self::ENTRY ];
		$base  = BYZO_PLUGIN_URL . 'assets/account/';

		wp_enqueue_script( 'byzo-account', $base . $entry['file'], array(), BYZO_VERSION, true );
		add_filter( 'wp_script_attributes', array( __CLASS__, 'add_module_type' ) );

		if ( ! empty( $entry['css'] ) ) {
			foreach ( $entry['css'] as $index => $css_file ) {
				wp_enqueue_style( 'byzo-account-' . $index, $base . $css_file, array(), BYZO_VERSION );
			}
		}

		wp_add_inline_script( 'byzo-account', 'window.byzoAccount = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Resolves 'login'/'register'/'dashboard'/'disabled' plus everything
	 * window.byzoAccount needs, handed to Vue as data rather than driving
	 * a PHP-side v-if. Public (not called directly by WP, only from
	 * enqueue_assets() above) so a CLI smoke test can exercise the
	 * page-state logic directly, same testability reasoning
	 * BYZO_Booking_Cancel::render() already documents.
	 */
	public static function config() {
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page render, nothing here authorizes a write.
		$mode  = ( isset( $_GET['mode'] ) && 'register' === $_GET['mode'] ) ? 'register' : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( is_user_logged_in() ) {
			$state = 'dashboard';
		} elseif ( ! (bool) BYZO_Settings::get()['customer_accounts_enabled'] ) {
			$state = 'disabled';
		} else {
			$state = $mode;
		}

		$page_url = self::current_page_url();

		return array(
			'state'           => $state,
			'mode'            => $mode,
			'errorMessage'    => BYZO_Customer_Auth::error_message( $error ),
			'displayName'     => is_user_logged_in() ? wp_get_current_user()->display_name : '',
			'restUrl'         => esc_url_raw( rest_url( 'bookyzo/v1' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'registerUrl'     => add_query_arg( 'action', BYZO_Customer_Auth::ACTION_REGISTER, admin_url( 'admin-post.php' ) ),
			'loginUrl'        => add_query_arg( 'action', BYZO_Customer_Auth::ACTION_LOGIN, admin_url( 'admin-post.php' ) ),
			'logoutUrl'       => add_query_arg(
				array(
					'action'      => BYZO_Customer_Auth::ACTION_LOGOUT,
					'redirect_to' => rawurlencode( $page_url ),
				),
				admin_url( 'admin-post.php' )
			),
			'lostPasswordUrl' => wp_lostpassword_url( $page_url ),
		);
	}

	/**
	 * Same mechanism/reasoning as BYZO_Public_Widget::add_module_type() —
	 * marks the account bundle's script tag as an ES module so the Vite
	 * bundle's top-level `import` doesn't throw in the browser.
	 */
	public static function add_module_type( $attributes ) {
		if ( isset( $attributes['id'] ) && 'byzo-account-js' === $attributes['id'] ) {
			$attributes['type'] = 'module';
		}

		return $attributes;
	}

	private static function read_manifest() {
		$path = BYZO_PLUGIN_DIR . 'assets/account/.vite/manifest.json';

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded  = json_decode( $contents, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
