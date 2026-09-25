<?php
defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the Vue admin app on the Bookyzo admin page only, in one of
 * two modes:
 *
 * - **Dev mode** (auto-detected — see is_dev_mode()): loads straight from
 *   the Vite dev server (`npm run dev`, default http://localhost:5173) as
 *   ES modules, with HMR — no build step, no `assets/admin/` folder needed at
 *   all. This is what makes local development possible even though
 *   `assets/admin/` (the built output) is gitignored.
 * - **Built mode** (default): reads admin/.vite/manifest.json to resolve
 *   hashed filenames without either side of the Vite/PHP boundary
 *   hardcoding them (DETAILED-SPEC.md §4 / bookyzo-design skill's "Vue 3
 *   SPA mounted inside wp-admin" architecture). This is what a
 *   `npm run build` produces and what the WordPress.org release zip
 *   ships (ROADMAP.md Phase 1 step 10) — root-level src/, node_modules,
 *   and build configs are dev-only and excluded from that zip.
 */
class BYZO_Admin_Assets {

	const ENTRY = 'main.js';

	/**
	 * Filterable so a dev's local Vite port can differ from the default
	 * without editing this file (`add_filter( 'byzo_dev_server_url', ... )`).
	 */
	const DEFAULT_DEV_SERVER = 'http://localhost:5173';

	/**
	 * Written/removed by vite.config.js's devServerMarkerPlugin whenever
	 * `npm run dev` starts/stops — so dev mode is detected automatically,
	 * with no wp-config.php constant to remember to toggle on/off.
	 */
	const DEV_SERVER_MARKER = '.dev-server-running';

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook ) {
		if ( ! self::is_bookyzo_screen( $hook ) ) {
			return;
		}

		self::enqueue_boot_loader_style();

		// The Bookable Item photo picker (BookableItemDetailsTab.vue) opens
		// a wp.media frame — that JS only exists once this is called, in
		// either dev or built mode, so it lives here rather than in either
		// branch below.
		wp_enqueue_media();

		if ( self::is_dev_mode() ) {
			self::enqueue_dev();
			return;
		}

		self::enqueue_built();
	}

	/**
	 * True on any of Bookyzo's own admin pages.
	 *
	 * Each screen now has its own registered slug (BYZO_Plugin::routes()),
	 * so this matches the `page` parameter against that map rather than
	 * against one hardcoded value. The $hook check still gates it to
	 * admin.php screens, so an unrelated page carrying the same parameter
	 * can't trigger an enqueue.
	 */
	private static function is_bookyzo_screen( $hook ) {
		// The top-level page's hook is unambiguously ours, so trust it
		// directly — no request parameter needed. Submenu hooks are
		// derived from their slugs and would have to be enumerated here,
		// so those go through the slug map below instead.
		if ( 'toplevel_page_' . BYZO_Plugin::MENU_SLUG === $hook ) {
			return true;
		}

		if ( 'admin.php' !== $GLOBALS['pagenow'] ) {
			return false;
		}

		return null !== self::current_slug();
	}

	/**
	 * The Bookyzo page slug being requested, or null if this isn't one.
	 */
	private static function current_slug() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check, not a state change.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return isset( BYZO_Plugin::routes()[ $page ] ) ? $page : null;
	}

	/**
	 * The Vue route the app should boot on, derived from which admin page
	 * WordPress is serving. Falls back to the dashboard for anything
	 * unmapped — defensive only, since is_bookyzo_screen() already gated
	 * the enqueue on the slug being in the map.
	 */
	private static function initial_route() {
		$slug   = self::current_slug();
		$routes = BYZO_Plugin::routes();

		return $slug && isset( $routes[ $slug ]['route'] ) ? $routes[ $slug ]['route'] : '/';
	}

	/**
	 * Auto-detects a running `npm run dev` via its marker file.
	 * `BYZO_DEV_MODE` (defined false in wp-config.php) remains available
	 * as an explicit override to force built mode even if a stale marker
	 * is somehow left behind — auto-detect is right by default, this is
	 * only an escape hatch, not the primary mechanism.
	 *
	 * Public (not just used internally): BYZOP_Admin_Assets checks this too,
	 * since Pro's own bundle can't just depend on the 'byzo-admin' script
	 * handle for load ordering — that handle is never registered while this
	 * is true (see print_dev_scripts(), which prints raw <script> tags
	 * instead of going through wp_enqueue_script()).
	 */
	public static function is_dev_mode() {
		if ( defined( 'BYZO_DEV_MODE' ) && false === BYZO_DEV_MODE ) {
			return false;
		}

		return file_exists( BYZO_PLUGIN_DIR . self::DEV_SERVER_MARKER );
	}

	/**
	 * Points wp-admin straight at the Vite dev server. No wp_enqueue_*
	 * here — Vite's dev client and the app entry must load as native
	 * `<script type="module">` tags in a specific order (@vite/client
	 * first, so HMR's WebSocket connects before any component code
	 * runs), which WP's enqueue system doesn't model, so this is output
	 * directly via wp_print_scripts on the Bookyzo page only.
	 */
	private static function enqueue_dev() {
		// wp.apiFetch itself still comes from WP core (the Vite dev
		// server only serves Bookyzo's own source, not WP's JS
		// packages) — enqueue the handle so window.wp.apiFetch exists
		// before print_dev_scripts()'s inline nonce-middleware code runs.
		wp_enqueue_script( 'wp-api-fetch' );

		add_action( 'admin_footer', array( __CLASS__, 'print_dev_scripts' ) );
	}

	public static function print_dev_scripts() {
		$server = apply_filters( 'byzo_dev_server_url', self::DEFAULT_DEV_SERVER );
		$server = untrailingslashit( $server );

		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript, WordPress.Security.EscapeOutput.OutputNotEscaped -- dev-mode-only Vite module scripts (never present on a production/WordPress.org install, see is_dev_mode()); admin_globals_js() and every other argument are already wp_json_encode()/esc_url()-safe.
		printf(
			'<script type="module">
				window.wp.apiFetch.use( window.wp.apiFetch.createNonceMiddleware( %1$s ) );
				window.wp.apiFetch.use( window.wp.apiFetch.createRootURLMiddleware( %2$s ) );
				%3$s
			</script>
			<script type="module" src="%4$s/@vite/client"></script>
			<script type="module" src="%4$s/main.js"></script>',
			wp_json_encode( wp_create_nonce( 'wp_rest' ) ),
			wp_json_encode( esc_url_raw( rest_url() ) ),
			self::admin_globals_js(),
			esc_url( $server )
		);
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript, WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * `window.byzoAdmin` — the small set of non-REST values the Vue app
	 * needs from PHP (the admin-post.php export/import URLs+nonces,
	 * DETAILED-SPEC.md §A8/§A10). Shared between dev and built mode so the
	 * two don't drift; admin-post.php actions aren't part of the REST API
	 * so they can't ride wp.apiFetch's own nonce middleware.
	 *
	 * `currency` rides along for the same reason the widget's bootstrap
	 * already carries it (BYZO_Public_Widget): src/lib/money.js formats
	 * every price in the app and needs the ISO code synchronously, at
	 * first paint, rather than after a /settings round-trip — otherwise
	 * list columns and the dashboard's revenue tile render bare numbers
	 * for a frame and then reflow.
	 */
	private static function admin_globals_js() {
		$currency = class_exists( 'BYZO_Settings' ) ? BYZO_Settings::get()['currency'] : 'USD';

		return sprintf(
			'window.byzoAdmin = { exportBookingsUrl: %s, exportNonce: %s, importCustomersUrl: %s, importCustomersNonce: %s, initialRoute: %s, currency: %s, proLicensed: %s, getProUrl: %s, documents: %s };',
			wp_json_encode( admin_url( 'admin-post.php' ) ),
			// Nonce action string must stay in sync with
			// BYZO_Booking_Export::NONCE — not referencing the constant
			// directly since this class loads before that one.
			wp_json_encode( wp_create_nonce( 'byzo_export_bookings' ) ),
			wp_json_encode( admin_url( 'admin-post.php' ) ),
			// Must stay in sync with BYZO_Customer_Import::NONCE, same
			// reasoning as the export nonce above.
			wp_json_encode( wp_create_nonce( 'byzo_import_customers' ) ),
			// Which screen the app opens on, decided by the WP admin page
			// slug — see BYZO_Plugin::routes(). The Vue Router owns every
			// navigation after this first one.
			wp_json_encode( self::initial_route() ),
			wp_json_encode( $currency ),
			// ByzoSidebar.vue only renders the "Get Pro" row while this is
			// false — kept in lockstep with BYZO_Plugin::routes() (which
			// only registers the matching WP submenu page under the same
			// condition) via the same byzo_is_pro_licensed() call, so the
			// sidebar link and the page it points to can never disagree
			// about whether Get Pro should exist right now.
			wp_json_encode( byzo_is_pro_licensed() ),
			wp_json_encode( byzo_get_pro_url() ),
			// Which booking documents exist (the email template editor lists
			// {ticket_link}/{invoice_link} only when they do).
			wp_json_encode(
				array(
					'ticket'  => (bool) BYZO_Capabilities::qr_ticket_enabled(),
					'invoice' => (bool) BYZO_Capabilities::pdf_invoice_enabled(),
				)
			)
		);
	}

	/**
	 * Styles templates/admin/admin-root.php's pre-mount spinner — hardcoded
	 * hex rather than the `--byzo-*` CSS variables (which only exist once
	 * the built/dev bundle's own stylesheet has loaded) so the placeholder
	 * renders correctly the instant this page paints, in dev mode, built
	 * mode, or even if the build is missing entirely. Same treatment as
	 * BackReload's BYZO_Admin_Assets-equivalent (Backreload\Admin::render_app()
	 * + loader_css_rules()) — brand accent color swapped in, everything
	 * else (track color, size, spin timing) identical so a "please wait"
	 * moment looks the same across both plugins.
	 */
	private static function enqueue_boot_loader_style() {
		wp_register_style( 'byzo-boot-loader', false, array(), BYZO_VERSION );
		wp_enqueue_style( 'byzo-boot-loader' );
		wp_add_inline_style( 'byzo-boot-loader', self::boot_loader_css_rules() );
	}

	private static function boot_loader_css_rules() {
		return '.byzo-boot-loader{display:flex;align-items:center;justify-content:center;'
			. 'gap:10px;min-height:60vh;color:#6b7280;font-size:14px;}'
			. '.byzo-boot-spinner{display:inline-block;width:18px;height:18px;border-radius:50%;'
			. 'border:2.5px solid #dbeafe;border-top-color:#2563eb;animation:byzo-boot-spin 800ms linear infinite;}'
			. '@keyframes byzo-boot-spin{to{transform:rotate(360deg);}}';
	}

	private static function enqueue_built() {
		$manifest = self::read_manifest();
		if ( ! $manifest || empty( $manifest[ self::ENTRY ] ) ) {
			// Dev build not run yet / manifest missing — fail quietly with
			// an admin notice rather than a fatal, so activating the
			// plugin before `npm run build` doesn't break wp-admin.
			add_action( 'admin_notices', array( __CLASS__, 'render_missing_build_notice' ) );
			return;
		}

		$entry    = $manifest[ self::ENTRY ];
		$dist_url = BYZO_PLUGIN_URL . 'assets/admin/';

		wp_enqueue_script(
			'byzo-admin',
			$dist_url . $entry['file'],
			array( 'wp-api-fetch', 'wp-i18n' ),
			BYZO_VERSION,
			true
		);
		// Language-pack JSON for this bundle (QA report §6.2.1); a copy in
		// the plugin's own languages/ folder wins over the downloaded one.
		wp_set_script_translations( 'byzo-admin', 'bookyzo', BYZO_PLUGIN_DIR . 'languages' );
		add_filter( 'wp_script_attributes', array( __CLASS__, 'add_module_type' ) );

		if ( ! empty( $entry['css'] ) ) {
			foreach ( $entry['css'] as $index => $css_file ) {
				wp_enqueue_style( 'byzo-admin-' . $index, $dist_url . $css_file, array(), BYZO_VERSION );
			}
		}

		// wp.apiFetch needs the REST nonce middleware configured before
		// the app's own code runs its first request — inject it as a
		// preamble rather than a second enqueued script, since it's a
		// few lines of glue, not a module of its own.
		$nonce_script = sprintf(
			'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) );wp.apiFetch.use( wp.apiFetch.createRootURLMiddleware( %s ) );%s',
			wp_json_encode( wp_create_nonce( 'wp_rest' ) ),
			wp_json_encode( esc_url_raw( rest_url() ) ),
			self::admin_globals_js()
		);
		wp_add_inline_script( 'byzo-admin', $nonce_script, 'before' );
	}

	/**
	 * Marks the built bundle as an ES module.
	 *
	 * Vite emits a real ES module (top-level `import`/`export`), which a
	 * plain `<script>` tag cannot execute — the browser throws on the
	 * import, the app never mounts, and the first thing the user sees is
	 * whatever error the page surfaces instead (here:
	 * "window.wp.apiFetch is not a function", because the bundle never
	 * ran to use it).
	 *
	 * Two approaches do NOT work on WP 7.1 and were both tried here:
	 *
	 * - `wp_script_add_data( $handle, 'type', 'module' )` — core's
	 *   registered 'type' data key has nothing to do with the tag's type
	 *   attribute; nothing in WP_Scripts reads it, so it silently no-ops.
	 * - `script_loader_tag` — WP_Scripts::do_item() builds the tag from an
	 *   attribute array via wp_get_script_tag() and only applies that
	 *   filter to the already-assembled string, which by then also has the
	 *   inline 'before' script concatenated in front of it. Editing that
	 *   string means string-surgery on two tags at once.
	 *
	 * wp_script_attributes is the filter that actually feeds tag
	 * generation, so set the attribute there. It fires for every script
	 * tag, hence the id check — and it never fires for inline scripts,
	 * so the 'before' preamble (which configures wp.apiFetch's middleware
	 * and must stay a classic, non-deferred script to run before the
	 * bundle) is untouched by construction.
	 */
	public static function add_module_type( $attributes ) {
		if ( isset( $attributes['id'] ) && 'byzo-admin-js' === $attributes['id'] ) {
			$attributes['type'] = 'module';
		}

		return $attributes;
	}

	public static function render_missing_build_notice() {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Bookyzo: admin app build not found. Run "npm install && npm run build" from the plugin\'s root folder, or run "npm run dev" for local development (auto-detected).', 'bookyzo' )
		);
	}

	private static function read_manifest() {
		$path = BYZO_PLUGIN_DIR . 'assets/admin/.vite/manifest.json';

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded  = json_decode( $contents, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
