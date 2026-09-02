<?php
defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the Vue admin app on the Bookyzo admin page only, in one of
 * two modes:
 *
 * - **Dev mode** (auto-detected — see is_dev_mode()): loads straight from
 *   the Vite dev server (`npm run dev`, default http://localhost:5173) as
 *   ES modules, with HMR — no build step, no `admin/` folder needed at
 *   all. This is what makes local development possible even though
 *   `admin/` (the built output) is gitignored.
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
		if ( 'toplevel_page_bookyzo' !== $hook ) {
			return;
		}

		if ( self::is_dev_mode() ) {
			self::enqueue_dev();
			return;
		}

		self::enqueue_built();
	}

	/**
	 * Auto-detects a running `npm run dev` via its marker file.
	 * `BYZO_DEV_MODE` (defined false in wp-config.php) remains available
	 * as an explicit override to force built mode even if a stale marker
	 * is somehow left behind — auto-detect is right by default, this is
	 * only an escape hatch, not the primary mechanism.
	 */
	private static function is_dev_mode() {
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

		printf(
			'<script type="module">
				window.wp.apiFetch.use( window.wp.apiFetch.createNonceMiddleware( %s ) );
				window.wp.apiFetch.use( window.wp.apiFetch.createRootURLMiddleware( %s ) );
			</script>
			<script type="module" src="%s/@vite/client"></script>
			<script type="module" src="%s/main.js"></script>',
			wp_json_encode( wp_create_nonce( 'wp_rest' ) ),
			wp_json_encode( esc_url_raw( rest_url() ) ),
			esc_url( $server ),
			esc_url( $server )
		);
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
		$dist_url = BYZO_PLUGIN_URL . 'admin/';

		wp_enqueue_script(
			'byzo-admin',
			$dist_url . $entry['file'],
			array( 'wp-api-fetch' ),
			BYZO_VERSION,
			true
		);
		wp_script_add_data( 'byzo-admin', 'type', 'module' );

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
			'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) );wp.apiFetch.use( wp.apiFetch.createRootURLMiddleware( %s ) );',
			wp_json_encode( wp_create_nonce( 'wp_rest' ) ),
			wp_json_encode( esc_url_raw( rest_url() ) )
		);
		wp_add_inline_script( 'byzo-admin', $nonce_script, 'before' );
	}

	public static function render_missing_build_notice() {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Bookyzo: admin app build not found. Run "npm install && npm run build" from the plugin\'s root folder, or run "npm run dev" for local development (auto-detected).', 'bookyzo' )
		);
	}

	private static function read_manifest() {
		$path = BYZO_PLUGIN_DIR . 'admin/.vite/manifest.json';

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded  = json_decode( $contents, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
