<?php
defined( 'ABSPATH' ) || exit;

/**
 * Central bootstrap. Loads includes and wires up admin/REST/cron on
 * `plugins_loaded`, so Pro (loaded as a separate plugin, hooking its own
 * `plugins_loaded` callback) can rely on Free's classes already existing.
 */
class BYZO_Plugin {

	const MENU_SLUG = 'bookyzo';

	/** @var BYZO_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		$this->maybe_upgrade_db();
		$this->load_includes();
		$this->register_hooks();

		do_action( 'byzo_loaded' );
	}

	/**
	 * Re-runs dbDelta if BYZO_DB_VERSION has moved on since the stored
	 * option — covers plugin updates, not just fresh activation (dbDelta
	 * is idempotent/additive so this is safe to call opportunistically).
	 */
	private function maybe_upgrade_db() {
		if ( get_option( 'byzo_db_version' ) !== BYZO_DB_VERSION ) {
			BYZO_Activator::create_tables();
			update_option( 'byzo_db_version', BYZO_DB_VERSION );
		}

		// Idempotent — cheap enough to check every plugins_loaded, and
		// ensures an install merely updated (not reactivated) still gets
		// the byzo_customer role from Phase 1.7 step 1.
		BYZO_Activator::maybe_register_customer_role();
	}

	private function load_includes() {
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-capabilities.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-install-redirect.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-bookable-item.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-customer.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-availability.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-slot-generator.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-slot.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-booking.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-bookable-items-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-customers-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-slots-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-bookings-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-settings-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-setup.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-setup-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-admin-assets.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-booking-export.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-customer-import.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-booking-token.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-booking-cancel.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-public-guard.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-payment-gateways.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-payment-settings.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-public-widget.php';
		require_once BYZO_PLUGIN_DIR . 'includes/functions.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-email-templates.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-notifications.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-email-templates-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-stripe.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-payment-gateways-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-payment-settings-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-stripe-webhook-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-reports.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-reports-controller.php';
	}

	private function register_hooks() {
		BYZO_Install_Redirect::init();
		( new BYZO_REST() )->init();
		BYZO_Admin_Assets::init();
		BYZO_Booking_Export::init();
		BYZO_Customer_Import::init();
		BYZO_Booking_Cancel::init();
		BYZO_Public_Widget::init();
		BYZO_Notifications::init();

		add_filter( 'byzo_rest_controllers', array( $this, 'register_core_rest_controllers' ) );

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'byzo_generate_slots', array( 'BYZO_Slot_Generator', 'generate_all' ) );

		/**
		 * Newly created/reactivated bookable items need their slots
		 * generated immediately rather than waiting for the next daily
		 * cron tick — otherwise a freshly onboarded business has an empty
		 * booking widget for up to 24h.
		 */
		add_action( 'byzo_bookable_item_created', array( $this, 'generate_slots_for_bookable_item' ) );
		add_action( 'byzo_bookable_item_activated', array( $this, 'generate_slots_for_bookable_item' ) );
	}

	public function generate_slots_for_bookable_item( $bookable_item_id ) {
		BYZO_Slot_Generator::generate_for_bookable_item( $bookable_item_id );
	}

	public function register_core_rest_controllers( $controllers ) {
		$controllers[] = new BYZO_REST_Bookable_Items_Controller();
		$controllers[] = new BYZO_REST_Customers_Controller();
		$controllers[] = new BYZO_REST_Slots_Controller();
		$controllers[] = new BYZO_REST_Bookings_Controller();
		$controllers[] = new BYZO_REST_Settings_Controller();
		$controllers[] = new BYZO_REST_Setup_Controller();
		$controllers[] = new BYZO_REST_Email_Templates_Controller();
		$controllers[] = new BYZO_REST_Payment_Gateways_Controller();
		$controllers[] = new BYZO_REST_Payment_Settings_Controller();
		$controllers[] = new BYZO_REST_Stripe_Webhook_Controller();
		$controllers[] = new BYZO_REST_Reports_Controller();

		return $controllers;
	}

	/**
	 * Top-level admin menu — the Vue admin shell (source in the plugin
	 * root's src/, built by Vite into assets/admin/) mounts into
	 * #byzo-admin-app. See BYZO_Admin_Assets for the
	 * enqueue/manifest-reading side.
	 *
	 * Each entry gets its OWN page slug (`bookyzo-bookings`, …) rather
	 * than all sharing `page=bookyzo` with a `view` query parameter. The
	 * slug is the route: BYZO_Admin_Assets maps it back to a Vue route
	 * via routes() below and hands the app its boot route in
	 * `window.byzoAdmin.initialRoute`, so PHP decides the starting screen
	 * and the Vue Router takes over from there. This mirrors the
	 * `backreload` plugin's admin routing, which is the precedent asked
	 * for here.
	 *
	 * Why it beats the `bookyzo&view=/…` scheme it replaces:
	 * - WordPress highlights the current submenu row by matching the
	 *   `page` parameter, so real slugs make the left nav track the app
	 *   for free — with one shared slug, every row pointed at the same
	 *   page and WP could not tell them apart.
	 * - Each screen gets a clean, bookmarkable URL with no route encoded
	 *   in a second parameter.
	 * - `$hook` becomes distinct per screen, so anything hooking a single
	 *   Bookyzo screen later can target it precisely.
	 *
	 * The dashboard row is registered explicitly, first, on the bare
	 * `bookyzo` slug. That also suppresses the row core would otherwise
	 * auto-insert at index 0: add_submenu_page() only injects that parent
	 * link while `$menu_slug !== $parent_slug` holds for the first
	 * submenu added, so a first entry whose slug IS the parent slug takes
	 * its place — keeping the top-level menu free to read "Bookyzo"
	 * without the first submenu row repeating it.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'Bookyzo – Booking & Appointments', 'bookyzo' ),
			__( 'Bookyzo', 'bookyzo' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_admin_root' ),
			'dashicons-calendar-alt',
			30
		);

		foreach ( self::routes() as $slug => $route ) {
			add_submenu_page(
				self::MENU_SLUG,
				$route['title'],
				$route['label'],
				'manage_options',
				$slug,
				array( $this, 'render_admin_root' )
			);
		}
	}

	/**
	 * Admin page slug → Vue route. The single source of truth for
	 * Bookyzo's admin routing: register_admin_menu() builds the WP menu
	 * from it, and BYZO_Admin_Assets::initial_route() reads it to tell
	 * the booting app which screen to open.
	 *
	 * Matches src/components/base/ByzoSidebar.vue's item list exactly —
	 * keep the two in sync if a route is added/renamed/removed there.
	 *
	 * Routes the sidebar does not link to (`/setup`, `/bookable-items/new`,
	 * `/bookable-items/:id/edit`) deliberately have no slug here: they are
	 * reached from inside the app, not from the WP menu, and the Vue
	 * Router still owns them.
	 *
	 * @return array<string,array{route:string,label:string,title:string}>
	 */
	public static function routes() {
		return array(
			self::MENU_SLUG => array(
				'route' => '/',
				'label' => __( 'Dashboard', 'bookyzo' ),
				'title' => __( 'Bookyzo Dashboard', 'bookyzo' ),
			),
			'bookyzo-calendar' => array(
				'route' => '/calendar',
				'label' => __( 'Calendar', 'bookyzo' ),
				'title' => __( 'Bookyzo Calendar', 'bookyzo' ),
			),
			'bookyzo-bookings' => array(
				'route' => '/bookings',
				'label' => __( 'Bookings', 'bookyzo' ),
				'title' => __( 'Bookyzo Bookings', 'bookyzo' ),
			),
			'bookyzo-bookable-items' => array(
				'route' => '/bookable-items',
				'label' => __( 'Bookable Items', 'bookyzo' ),
				'title' => __( 'Bookyzo Bookable Items', 'bookyzo' ),
			),
			'bookyzo-customers' => array(
				'route' => '/customers',
				'label' => __( 'Customers', 'bookyzo' ),
				'title' => __( 'Bookyzo Customers', 'bookyzo' ),
			),
			'bookyzo-emails' => array(
				'route' => '/settings/email-templates',
				'label' => __( 'Emails', 'bookyzo' ),
				'title' => __( 'Bookyzo Email Templates', 'bookyzo' ),
			),
			'bookyzo-payments' => array(
				'route' => '/settings/payments',
				'label' => __( 'Payments', 'bookyzo' ),
				'title' => __( 'Bookyzo Payment Settings', 'bookyzo' ),
			),
			'bookyzo-settings' => array(
				'route' => '/settings/general',
				'label' => __( 'Settings', 'bookyzo' ),
				'title' => __( 'Bookyzo Settings', 'bookyzo' ),
			),
		);
	}

	public function render_admin_root() {
		require BYZO_PLUGIN_DIR . 'templates/admin/admin-root.php';
	}
}
