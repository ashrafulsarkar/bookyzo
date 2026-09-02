<?php
defined( 'ABSPATH' ) || exit;

/**
 * The public-facing booking widget (DETAILED-SPEC.md §A5): Gutenberg
 * block `bookyzo/booking-form` + shortcode `[bookyzo_booking]`, both
 * rendering the same standalone Vue app into a mount div, config via
 * `data-*` attributes.
 *
 * This is a SEPARATE small Vue bundle from the wp-admin app
 * (BYZO_Admin_Assets) — different source tree (src/widget/), different
 * build output (public/, not admin/), and always built-mode (no dev-server
 * auto-detect like the admin app has; iterating on this screen with
 * `npm run build:widget` after each change is an acceptable tradeoff for
 * how much less frequently this screen changes than the admin app, unlike
 * the admin app which gets touched constantly across every Phase 1 step).
 * It must be entirely independent because it runs on arbitrary frontend
 * pages/themes, not inside wp-admin — no wp.apiFetch (no nonce needed for
 * a guest with no session to protect, see BYZO_Public_Guard), no shared
 * CSS with the admin bundle (see src/widget/widget.css's own scoped tokens).
 */
class BYZO_Public_Widget {

	const ENTRY = 'widget/main.js';

	public static function init() {
		add_shortcode( 'bookyzo_booking', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'resource_id' => '' ), $atts, 'bookyzo_booking' );

		return self::render( array( 'resource_id' => $atts['resource_id'] ) );
	}

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'byzo-block-editor',
			BYZO_PLUGIN_URL . 'blocks/booking-form/index.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			BYZO_VERSION,
			true
		);

		register_block_type(
			'bookyzo/booking-form',
			array(
				'editor_script'   => 'byzo-block-editor',
				'render_callback' => array( __CLASS__, 'render_block' ),
				'attributes'      => array(
					'resourceId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
			)
		);
	}

	public static function render_block( $attributes ) {
		return self::render( array( 'resource_id' => ! empty( $attributes['resourceId'] ) ? $attributes['resourceId'] : '' ) );
	}

	/**
	 * @param array $config { @type string|int $resource_id Optional — pins the widget to one resource, skipping the picker step. }
	 */
	private static function render( $config ) {
		self::enqueue_assets();

		$attrs = '';
		if ( ! empty( $config['resource_id'] ) ) {
			$attrs .= ' data-resource-id="' . esc_attr( absint( $config['resource_id'] ) ) . '"';
		}

		return '<div class="bookyzo-widget-mount"' . $attrs . '></div>';
	}

	private static function enqueue_assets() {
		$manifest = self::read_manifest();
		if ( ! $manifest || empty( $manifest[ self::ENTRY ] ) ) {
			// Silently no-op rather than fatal — a shortcode/block on a
			// page shouldn't be able to break the whole page render just
			// because `npm run build:widget` hasn't been run yet. The
			// mount div still renders (from render()), just inert.
			return;
		}

		$entry = $manifest[ self::ENTRY ];
		$base  = BYZO_PLUGIN_URL . 'public/';

		wp_enqueue_script( 'byzo-widget', $base . $entry['file'], array(), BYZO_VERSION, true );
		wp_script_add_data( 'byzo-widget', 'type', 'module' );

		if ( ! empty( $entry['css'] ) ) {
			foreach ( $entry['css'] as $index => $css_file ) {
				wp_enqueue_style( 'byzo-widget-' . $index, $base . $css_file, array(), BYZO_VERSION );
			}
		}

		wp_add_inline_script(
			'byzo-widget',
			sprintf( 'window.byzoWidget = { restUrl: %s };', wp_json_encode( esc_url_raw( rest_url( 'bookyzo/v1' ) ) ) ),
			'before'
		);
	}

	private static function read_manifest() {
		$path = BYZO_PLUGIN_DIR . 'public/.vite/manifest.json';

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded  = json_decode( $contents, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
