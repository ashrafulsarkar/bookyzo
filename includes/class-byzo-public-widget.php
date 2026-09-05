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

		// Elementor widget (added 2026-09-05, Phase 1.5 step 9, competitor
		// gap-fill) — this hook only fires when Elementor itself is active
		// and loaded, so BYZO_Elementor_Widget (which extends
		// \Elementor\Widget_Base) is only ever require_once'd once that
		// base class is guaranteed to exist; a site without Elementor
		// never runs this callback at all.
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_elementor_widget' ) );
	}

	public static function register_elementor_widget( $widgets_manager ) {
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-elementor-widget.php';
		$widgets_manager->register( new BYZO_Elementor_Widget() );
	}

	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'bookable_item_id' => '', 'type' => '' ), $atts, 'bookyzo_booking' );

		return self::render( array( 'bookable_item_id' => $atts['bookable_item_id'], 'type' => $atts['type'] ) );
	}

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'byzo-block-editor',
			BYZO_PLUGIN_URL . 'blocks/booking-form/index.js',
			// wp-api-fetch added 2026-09-03 (Phase 1.5 step 4) — the editor's
			// new bookable-item/type selector fetches GET /bookable-items/public
			// (the same guest-allowed endpoint the frontend widget itself uses,
			// not the admin-gated /bookable-items) so this works for any user
			// who can access the block editor, not just manage_options users.
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch' ),
			BYZO_VERSION,
			true
		);

		register_block_type(
			'bookyzo/booking-form',
			array(
				'editor_script'   => 'byzo-block-editor',
				'render_callback' => array( __CLASS__, 'render_block' ),
				'attributes'      => array(
					'bookableItemId' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'type'           => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	public static function render_block( $attributes ) {
		return self::render(
			array(
				'bookable_item_id' => ! empty( $attributes['bookableItemId'] ) ? $attributes['bookableItemId'] : '',
				'type'             => ! empty( $attributes['type'] ) ? $attributes['type'] : '',
			)
		);
	}

	/**
	 * Public entry point for rendering surfaces that are neither a
	 * shortcode nor a Gutenberg block — currently BYZO_Elementor_Widget
	 * (added 2026-09-05, Phase 1.5 step 9) — but need the exact same
	 * mount-div + config shape render_shortcode()/render_block() produce.
	 *
	 * @param array $config Same shape as render()'s own $config.
	 */
	public static function render_widget( $config ) {
		return self::render( $config );
	}

	/**
	 * @param array $config {
	 *     @type string|int $bookable_item_id Optional — pins the widget to one bookable item, skipping the picker step.
	 *     @type string     $type             Optional — filters the picker to one booking type (DETAILED-SPEC.md §A5).
	 *                                        Ignored when bookable_item_id is also set — a pinned item already skips
	 *                                        the picker step entirely, so there's nothing left for a type to filter.
	 * }
	 */
	private static function render( $config ) {
		self::enqueue_assets();

		$attrs = '';
		if ( ! empty( $config['bookable_item_id'] ) ) {
			$attrs .= ' data-bookable-item-id="' . esc_attr( absint( $config['bookable_item_id'] ) ) . '"';
		} elseif ( ! empty( $config['type'] ) ) {
			$attrs .= ' data-type="' . esc_attr( $config['type'] ) . '"';
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
		$base  = BYZO_PLUGIN_URL . 'assets/widget/';

		wp_enqueue_script( 'byzo-widget', $base . $entry['file'], array(), BYZO_VERSION, true );
		add_filter( 'wp_script_attributes', array( __CLASS__, 'add_module_type' ) );

		if ( ! empty( $entry['css'] ) ) {
			foreach ( $entry['css'] as $index => $css_file ) {
				wp_enqueue_style( 'byzo-widget-' . $index, $base . $css_file, array(), BYZO_VERSION );
			}
		}

		// stripePublishableKey is safe to expose here — Stripe's
		// publishable keys are explicitly designed for client-side use
		// (that's their whole purpose, unlike the secret key, which
		// never leaves the server — see BYZO_Stripe). stripeEnabled lets
		// the widget skip rendering a payment step entirely when the
		// merchant hasn't turned Stripe on, rather than showing a "Pay
		// now" button that would just error.
		$payment_settings = class_exists( 'BYZO_Payment_Settings' ) ? BYZO_Payment_Settings::get() : array();
		$currency         = class_exists( 'BYZO_Settings' ) ? BYZO_Settings::get()['currency'] : 'USD';

		wp_add_inline_script(
			'byzo-widget',
			sprintf(
				'window.byzoWidget = { restUrl: %s, stripeEnabled: %s, stripePublishableKey: %s, currency: %s };',
				wp_json_encode( esc_url_raw( rest_url( 'bookyzo/v1' ) ) ),
				wp_json_encode( ! empty( $payment_settings['stripe_enabled'] ) && ! empty( $payment_settings['stripe_publishable_key'] ) ),
				wp_json_encode( $payment_settings['stripe_publishable_key'] ?? '' ),
				wp_json_encode( $currency )
			),
			'before'
		);
	}

	/**
	 * Marks the widget bundle as an ES module — same mechanism, and same
	 * reasoning, as BYZO_Admin_Assets::add_module_type(); see that method
	 * for why wp_script_add_data( 'type', 'module' ) and
	 * script_loader_tag both fail to do this on WP 7.1.
	 *
	 * Without it the Vite bundle's top-level `import` throws in the
	 * browser and the shortcode/block renders its mount div with nothing
	 * inside — the "inert mount div" that render() deliberately tolerates
	 * when the build is MISSING, except here the build is present and the
	 * tag is simply wrong.
	 */
	public static function add_module_type( $attributes ) {
		if ( isset( $attributes['id'] ) && 'byzo-widget-js' === $attributes['id'] ) {
			$attributes['type'] = 'module';
		}

		return $attributes;
	}

	private static function read_manifest() {
		$path = BYZO_PLUGIN_DIR . 'assets/widget/.vite/manifest.json';

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded  = json_decode( $contents, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
