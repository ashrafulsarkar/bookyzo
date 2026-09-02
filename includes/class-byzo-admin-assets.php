<?php
defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the built Vue admin app on the Bookyzo admin page only.
 * Reads admin/dist/.vite/manifest.json rather than hardcoding hashed
 * filenames, so neither side of the Vite/PHP boundary needs updating
 * when a rebuild changes the hash (DETAILED-SPEC.md §4 / bookyzo-design
 * skill's "Vue 3 SPA mounted inside wp-admin" architecture).
 */
class BYZO_Admin_Assets {

	const ENTRY = 'src/main.js';

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook ) {
		if ( 'toplevel_page_bookyzo' !== $hook ) {
			return;
		}

		$manifest = self::read_manifest();
		if ( ! $manifest || empty( $manifest[ self::ENTRY ] ) ) {
			// Dev build not run yet / manifest missing — fail quietly with
			// an admin notice rather than a fatal, so activating the
			// plugin before `npm run build` doesn't break wp-admin.
			add_action( 'admin_notices', array( __CLASS__, 'render_missing_build_notice' ) );
			return;
		}

		$entry    = $manifest[ self::ENTRY ];
		$dist_url = BYZO_PLUGIN_URL . 'admin/dist/';

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
			esc_html__( 'Bookyzo: admin app build not found. Run "npm install && npm run build" inside the plugin\'s admin/ folder.', 'bookyzo' )
		);
	}

	private static function read_manifest() {
		$path = BYZO_PLUGIN_DIR . 'admin/dist/.vite/manifest.json';

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded  = json_decode( $contents, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
