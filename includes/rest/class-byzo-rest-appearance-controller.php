<?php
defined( 'ABSPATH' ) || exit;

/**
 * /appearance — booking widget visual configuration. Admin-only, same
 * GET/PUT single-resource shape as /settings and /payment-settings, and
 * its own route for the same reason BYZO_Appearance_Settings is its own
 * option: presentation is a separate concern from booking behavior.
 */
class BYZO_REST_Appearance_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'appearance';
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
		return rest_ensure_response( BYZO_Appearance_Settings::get() );
	}

	public function update_item( WP_REST_Request $request ) {
		$result = BYZO_Appearance_Settings::update( (array) $request->get_json_params() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
