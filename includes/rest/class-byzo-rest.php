<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API bootstrapper. Owns the `rest_api_init` hook and instantiates
 * every concrete controller. Individual endpoints (/resources, /slots,
 * /bookings, ...) are added controller-by-controller starting Phase 0
 * step 3 — this step only stands up the namespace + registration pattern.
 */
class BYZO_REST {

	/** @var BYZO_REST_Controller[] */
	private $controllers = array();

	public function init() {
		require_once BYZO_PLUGIN_DIR . 'includes/rest/class-byzo-rest-controller.php';

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		/**
		 * Filters the list of REST controller instances Bookyzo registers.
		 * Pro adds its own controllers here (e.g. locations, waitlist)
		 * rather than patching this class.
		 */
		$this->controllers = apply_filters( 'byzo_rest_controllers', array() );

		foreach ( $this->controllers as $controller ) {
			if ( $controller instanceof BYZO_REST_Controller ) {
				$controller->register_routes();
			}
		}
	}
}
