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
			// Still nothing for visitors, but an admin viewing the page gets
			// told why it's empty (QA report 2026-09-22 B18).
			if ( ! current_user_can( 'manage_options' ) ) {
				return '';
			}

			return '<p class="bookyzo-admin-notice" style="margin:0 0 12px;padding:10px 12px;border-left:4px solid #d97706;background:#fffbeb;color:#78350f;font-size:14px;">'
				. esc_html__( 'Admin notice: customer accounts are turned off, so this page shows visitors nothing.', 'bookyzo' )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=bookyzo-settings' ) ) . '">' . esc_html__( 'Turn them on in Bookyzo → Settings', 'bookyzo' ) . '</a>. <em>'
				. esc_html__( 'Only site admins see this message.', 'bookyzo' ) . '</em></p>';
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

		wp_enqueue_script( 'byzo-account', $base . $entry['file'], array( 'wp-i18n' ), BYZO_VERSION, true );
		wp_set_script_translations( 'byzo-account', 'bookyzo', BYZO_PLUGIN_DIR . 'languages' );
		add_filter( 'wp_script_attributes', array( __CLASS__, 'add_module_type' ) );

		if ( ! empty( $entry['css'] ) ) {
			foreach ( $entry['css'] as $index => $css_file ) {
				$handle = 'byzo-account-' . $index;
				wp_enqueue_style( $handle, $base . $css_file, array(), BYZO_VERSION );

				// The account pages carry the same `--byzo-*` token names
				// as the widget (src/account/account.css declares its own
				// copy), so the merchant's Appearance settings apply here
				// too. Without this a site that branded its booking widget
				// green still got Bookyzo's stock blue on the login and
				// dashboard pages sitting next to it — two different-looking
				// halves of one customer-facing flow.
				self::add_appearance_css( $handle );
			}
		}

		wp_add_inline_script( 'byzo-account', 'window.byzoAccount = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Appends the merchant's Appearance overrides to the account bundle's
	 * stylesheet, mirroring BYZO_Public_Widget::add_appearance_css() —
	 * same handle-scoped, no-`!important`, nothing-emitted-when-unchanged
	 * approach, and the same reason for attaching to the style handle
	 * rather than printing inline in the body.
	 *
	 * Only the custom properties travel, not build_css_declarations():
	 * that method's `max-width` is the widget's own 900px canvas, which
	 * would stretch a 480px login card to more than twice its width. The
	 * typography half of it is reproduced here without that.
	 */
	private static function add_appearance_css( $handle ) {
		if ( ! class_exists( 'BYZO_Appearance_Settings' ) ) {
			return;
		}

		$settings = BYZO_Appearance_Settings::get();
		$defaults = BYZO_Appearance_Settings::defaults();
		$rules    = BYZO_Appearance_Settings::build_css_vars();

		if ( (int) $settings['font_size'] !== (int) $defaults['font_size'] ) {
			$rules .= 'font-size:' . (int) $settings['font_size'] . 'px;';
		}

		if ( '' !== $settings['font_family'] ) {
			$rules .= 'font-family:' . $settings['font_family'] . ';';
		}

		if ( '' === $rules ) {
			return;
		}

		wp_add_inline_style( $handle, '.bookyzo-account{' . $rules . '}' );
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
			'justRegistered'  => is_user_logged_in() && isset( $_GET['welcome'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the greeting.
			'restUrl'         => esc_url_raw( rest_url( 'bookyzo/v1' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'registerUrl'     => BYZO_Customer_Auth::form_url( BYZO_Customer_Auth::ACTION_REGISTER ),
			'loginUrl'        => BYZO_Customer_Auth::form_url( BYZO_Customer_Auth::ACTION_LOGIN ),
			'logoutUrl'       => BYZO_Customer_Auth::form_url( BYZO_Customer_Auth::ACTION_LOGOUT, array( 'redirect_to' => rawurlencode( $page_url ) ) ),
			'lostPasswordUrl' => wp_lostpassword_url( $page_url ),
			// Phase 2 step 18 (DETAILED-SPEC.md §3 B4) — never render a
			// "Download invoice" link that 404s: the dashboard only shows
			// it once a real Pro-provided PDF renderer is actually active.
			'pdfInvoiceEnabled' => BYZO_Capabilities::pdf_invoice_enabled(),
			// Phase 2 step 12 continued (DETAILED-SPEC.md §3 B7 Event) —
			// same "never render a link that 404s" reasoning as the PDF
			// invoice link above.
			'qrTicketEnabled'   => BYZO_Capabilities::qr_ticket_enabled(),
			'sections'          => self::extra_sections(),
		);
	}

	/**
	 * Extra sections an add-on shows the signed-in customer under their
	 * bookings (QA report 2026-09-26 §4.7: a package add-on lists their
	 * codes and credits). Only plain text goes through, so an add-on hands
	 * data, never markup.
	 *
	 * @return array<int,array{title:string,empty:string,items:array<int,array{title:string,lines:string[]}>}>
	 */
	private static function extra_sections() {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		/**
		 * Filters the extra sections on the customer account page.
		 *
		 * @param array   $sections Each: title, empty (shown when there are no items), items (each: title, lines).
		 * @param WP_User $user     The signed-in customer.
		 */
		$sections = apply_filters( 'byzo_account_sections', array(), wp_get_current_user() );

		$clean = array();
		foreach ( (array) $sections as $section ) {
			$items = array();
			foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
				$items[] = array(
					'title' => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
					'lines' => array_values( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $item['lines'] ?? array() ) ) ) ),
				);
			}
			$clean[] = array(
				'title' => sanitize_text_field( (string) ( $section['title'] ?? '' ) ),
				'empty' => sanitize_text_field( (string) ( $section['empty'] ?? '' ) ),
				'items' => $items,
			);
		}
		return $clean;
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
