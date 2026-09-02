<?php
defined( 'ABSPATH' ) || exit;

/**
 * Central bootstrap. Loads includes and wires up admin/REST/cron on
 * `plugins_loaded`, so Pro (loaded as a separate plugin, hooking its own
 * `plugins_loaded` callback) can rely on Free's classes already existing.
 */
class BYZO_Plugin {

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
	}

	private function load_includes() {
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-capabilities.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-install-redirect.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-resource.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-availability.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-slot-generator.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-slot.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-booking.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-resources-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-slots-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-bookings-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-settings-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-setup.php';
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-setup-controller.php';
		require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-admin-assets.php';
	}

	private function register_hooks() {
		load_plugin_textdomain( 'bookyzo', false, dirname( BYZO_PLUGIN_BASENAME ) . '/languages' );

		BYZO_Install_Redirect::init();
		( new BYZO_REST() )->init();
		BYZO_Admin_Assets::init();

		add_filter( 'byzo_rest_controllers', array( $this, 'register_core_rest_controllers' ) );

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'byzo_generate_slots', array( 'BYZO_Slot_Generator', 'generate_all' ) );

		/**
		 * Newly created/reactivated resources need their slots generated
		 * immediately rather than waiting for the next daily cron tick —
		 * otherwise a freshly onboarded business has an empty booking
		 * widget for up to 24h.
		 */
		add_action( 'byzo_resource_created', array( $this, 'generate_slots_for_resource' ) );
		add_action( 'byzo_resource_activated', array( $this, 'generate_slots_for_resource' ) );
	}

	public function generate_slots_for_resource( $resource_id ) {
		BYZO_Slot_Generator::generate_for_resource( $resource_id );
	}

	public function register_core_rest_controllers( $controllers ) {
		$controllers[] = new BYZO_REST_Resources_Controller();
		$controllers[] = new BYZO_REST_Slots_Controller();
		$controllers[] = new BYZO_REST_Bookings_Controller();
		$controllers[] = new BYZO_REST_Settings_Controller();
		$controllers[] = new BYZO_REST_Setup_Controller();

		return $controllers;
	}

	/**
	 * Top-level admin menu — the Vue admin shell (admin/src, built by
	 * Vite into admin/dist/) mounts into #byzo-admin-app below. See
	 * BYZO_Admin_Assets for the enqueue/manifest-reading side.
	 */
	public function register_admin_menu() {
		add_menu_page(
			__( 'Bookyzo – Booking & Appointments', 'bookyzo' ),
			__( 'Bookyzo', 'bookyzo' ),
			'manage_options',
			'bookyzo',
			array( $this, 'render_admin_root' ),
			'dashicons-calendar-alt',
			30
		);
	}

	public function render_admin_root() {
		echo '<div id="byzo-admin-app">' . esc_html__( 'Loading…', 'bookyzo' ) . '</div>';
	}
}
