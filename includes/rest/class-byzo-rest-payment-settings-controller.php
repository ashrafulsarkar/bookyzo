<?php
defined( 'ABSPATH' ) || exit;

/**
 * /payment-settings — Stripe gateway config (DETAILED-SPEC.md §A7).
 * Admin-only, same GET/PUT single-resource shape as /settings and
 * /email-templates. Kept as its own route (not folded into /settings)
 * since BYZO_Payment_Settings is its own option for the same reason —
 * API secrets are a different sensitivity class from general preferences.
 */
class BYZO_REST_Payment_Settings_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'payment-settings';
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base(),
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);
	}

	public function get_item( WP_REST_Request $request ) {
		return rest_ensure_response( BYZO_Payment_Settings::get() );
	}

	public function update_item( WP_REST_Request $request ) {
		$result = BYZO_Payment_Settings::update( (array) $request->get_json_params() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
