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
	 * The package store's mount point, on the same widget bundle (QA report
	 * §6.1 A2). Free sells no packages and registers no shortcode for this;
	 * an add-on that does calls it.
	 */
	public static function render_packages_store() {
		self::enqueue_assets();
		return '<div class="bookyzo-packages-mount"></div>';
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

		// Marketing pixel injection (Phase 2 step 15, DETAILED-SPEC.md §3
		// B9) — Pro hooks this to append the standard FB Pixel/GA/GTM
		// base snippets right where the widget itself renders. Free never
		// hooks it, so the widget's own markup is byte-for-byte unchanged
		// without Pro.
		$after_mount = apply_filters( 'byzo_widget_after_mount', '', $config );

		return self::pinned_item_notice( $config ) . '<div class="bookyzo-widget-mount"' . $attrs . '></div>' . $after_mount;
	}

	/**
	 * A form pinned to an item that's gone (deleted, archived or inactive)
	 * falls back to listing every item, which is the right thing for a
	 * visitor — but an admin looking at the page had no way to tell the
	 * embed was broken (QA report 2026-09-22 B17). Admins, and only admins,
	 * get a line saying so above the form.
	 */
	private static function pinned_item_notice( $config ) {
		if ( empty( $config['bookable_item_id'] ) || ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$id   = absint( $config['bookable_item_id'] );
		$item = BYZO_Bookable_Item::get( $id );
		if ( $item && 'active' === $item['status'] ) {
			return '';
		}

		$message = $item
			/* translators: 1: bookable item name, 2: bookable item ID */
			? sprintf( __( 'Admin notice: this booking form is set to “%1$s” (#%2$d), which isn\'t active, so visitors see every bookable item instead. Activate it, or change the form\'s bookable item.', 'bookyzo' ), $item['name'], $id )
			/* translators: %d: bookable item ID */
			: sprintf( __( 'Admin notice: this booking form is set to bookable item #%d, which no longer exists, so visitors see every bookable item instead. Change the form\'s bookable item.', 'bookyzo' ), $id );

		return '<p class="bookyzo-admin-notice" style="margin:0 0 12px;padding:10px 12px;border-left:4px solid #d97706;background:#fffbeb;color:#78350f;font-size:14px;">' . esc_html( $message ) . ' <em>' . esc_html__( 'Only site admins see this message.', 'bookyzo' ) . '</em></p>';
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

			self::add_appearance_css( 'byzo-widget-0' );
		}

		// stripePublishableKey is safe to expose here — Stripe's
		// publishable keys are explicitly designed for client-side use
		// (that's their whole purpose, unlike the secret key, which
		// never leaves the server — see BYZO_Stripe). stripeEnabled lets
		// the widget skip rendering a payment step entirely when the
		// merchant hasn't turned Stripe on, rather than showing a "Pay
		// now" button that would just error.
		$stripe   = class_exists( 'BYZO_Payment_Settings' ) ? BYZO_Payment_Settings::get()['gateways']['stripe'] : array();
		$currency = class_exists( 'BYZO_Settings' ) ? BYZO_Settings::get()['currency'] : 'USD';

		// payInPersonEnabled gates StepPayment.vue's "Pay in person instead"
		// button (Settings screen's "Booking behavior" section,
		// BYZO_Settings::get()['allow_pay_in_person']) — on by default so
		// an existing site's widget keeps behaving exactly as it always
		// has. Defaults true (not false) if the class is somehow missing,
		// same fail-open reasoning as every other "don't accidentally lock
		// a merchant's own customers out" default in this file.
		$pay_in_person_enabled = ! class_exists( 'BYZO_Settings' ) || (bool) BYZO_Settings::get()['allow_pay_in_person'];

		// customerAccountsEnabled added Phase 1.7 step 6 (DETAILED-SPEC.md
		// §A11) — gates StepDetails.vue's "Create an account" checkbox the
		// same way every other capability toggle in this doc gates its own
		// UI: absent when off, not a disabled/greyed-out control.
		$accounts_enabled = class_exists( 'BYZO_Settings' ) ? (bool) BYZO_Settings::get()['customer_accounts_enabled'] : false;

		// A logged-in visitor's own contact details, so StepDetails.vue
		// can prefill them (DETAILED-SPEC.md §A11, built 2026-09-22 — QA
		// report B12: promised but never wired, so a logged-in customer
		// typed everything again and could book under a different email
		// than their account's). Their customer record wins over the WP
		// profile; null for guests. Only ever printed on a logged-in page
		// view, which page caches don't serve to anyone else.
		$current_customer = null;
		if ( is_user_logged_in() ) {
			$user     = wp_get_current_user();
			$customer = class_exists( 'BYZO_Customer' ) ? BYZO_Customer::get_by_wp_user_id( $user->ID ) : null;

			$current_customer = array(
				'name'  => $customer && '' !== (string) $customer['name'] ? $customer['name'] : $user->display_name,
				'email' => $customer && '' !== (string) $customer['email'] ? $customer['email'] : $user->user_email,
				'phone' => $customer && ! empty( $customer['phone'] ) ? $customer['phone'] : '',
			);
		}

		// taxRate lets StepDetails.vue show the same total the server will
		// actually charge. BYZO_Booking::create() computes
		// price × party_size + tax and is the only authority on the
		// amount (a client-supplied one is never trusted — see its
		// docblock); this is display-only, so the confirm step can stop
		// asking people to commit to an unstated price.
		$tax_rate = class_exists( 'BYZO_Settings' ) ? (float) BYZO_Settings::get()['tax_rate'] : 0.0;

		// Phase 2 step 20 (DETAILED-SPEC.md §3 B4) — whether an Event
		// booking with party_size > 1 collects each ticket's own
		// name/email instead of one contact name for the whole purchase.
		// Site-wide, so it's exposed here once rather than a per-request
		// fetch, same shape as customerAccountsEnabled above.
		$collect_attendee_details = (bool) BYZO_Capabilities::collect_attendee_details_enabled();

		// QA report 2026-09-22 B10 — whether the confirmation screen shows
		// the QR e-ticket (confirmed event bookings) and an invoice link
		// (bookings with a price); the same capability checks the customer
		// account dashboard already uses.
		$documents = array(
			'ticket'  => (bool) BYZO_Capabilities::qr_ticket_enabled(),
			'invoice' => (bool) BYZO_Capabilities::pdf_invoice_enabled(),
		);

		// Whether anything on this site sends marketing email — the widget
		// only asks for consent when there is something to opt into (QA
		// report §4.6).
		$marketing_enabled = (bool) BYZO_Capabilities::marketing_enabled();
		$waitlist_enabled  = (bool) BYZO_Capabilities::waitlist_enabled();
		$codes_enabled     = (bool) BYZO_Capabilities::codes_enabled();

		wp_add_inline_script(
			'byzo-widget',
			sprintf(
				'window.byzoWidget = { restUrl: %s, stripeEnabled: %s, stripePublishableKey: %s, currency: %s, customerAccountsEnabled: %s, taxRate: %s, collectAttendeeDetailsEnabled: %s, payInPersonEnabled: %s, documents: %s, currentCustomer: %s, marketingEnabled: %s, waitlistEnabled: %s, codesEnabled: %s };',
				wp_json_encode( esc_url_raw( rest_url( 'bookyzo/v1' ) ) ),
				wp_json_encode( ! empty( $stripe['enabled'] ) && ! empty( $stripe['publishable_key'] ) ),
				wp_json_encode( $stripe['publishable_key'] ?? '' ),
				wp_json_encode( $currency ),
				wp_json_encode( $accounts_enabled ),
				wp_json_encode( $tax_rate ),
				wp_json_encode( $collect_attendee_details ),
				wp_json_encode( $pay_in_person_enabled ),
				wp_json_encode( $documents ),
				wp_json_encode( $current_customer ),
				wp_json_encode( $marketing_enabled ),
				wp_json_encode( $waitlist_enabled ),
				wp_json_encode( $codes_enabled )
			),
			'before'
		);
	}

	/**
	 * Appends the admin's Appearance overrides (BYZO_Appearance_Settings)
	 * to the widget's own stylesheet handle, so they land after it in the
	 * cascade with the same specificity — no `!important`, and nothing
	 * emitted at all when every value is still the stylesheet default.
	 *
	 * Attached to the enqueued style rather than printed inline in the
	 * body so it stays in <head> with the rules it overrides, and so a
	 * page with two widget embeds still only prints it once.
	 */
	private static function add_appearance_css( $handle ) {
		if ( ! class_exists( 'BYZO_Appearance_Settings' ) ) {
			return;
		}

		$vars         = BYZO_Appearance_Settings::build_css_vars();
		$declarations = BYZO_Appearance_Settings::build_css_declarations();

		if ( '' === $vars && '' === $declarations ) {
			return;
		}

		wp_add_inline_style( $handle, '.bookyzo-widget{' . $vars . $declarations . '}' );
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
