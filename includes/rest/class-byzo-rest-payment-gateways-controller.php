<?php
defined( 'ABSPATH' ) || exit;

/**
 * /payment-gateways — read-only list of registered gateways
 * (BYZO_Payment_Gateways), so the admin Payments screen can render its
 * tab list from whatever's actually available (just Stripe on Free)
 * instead of hardcoding it — the same registry Pro extends via
 * `byzo_payment_gateways` to add its own tabs later.
 */
class BYZO_REST_Payment_Gateways_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'payment-gateways';
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base(),
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);
	}

	public function get_items( WP_REST_Request $request ) {
		return rest_ensure_response( array_values( BYZO_Payment_Gateways::all() ) );
	}
}
