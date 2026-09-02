<?php
defined( 'ABSPATH' ) || exit;

/**
 * /settings — business profile + Free/Pro settings sections
 * (DETAILED-SPEC.md §4). Admin-only; there is no public read of settings
 * today (the public booking widget needs currency/timezone display
 * formatting, but that's served inline on the booking-widget's own
 * bootstrap payload later, not by exposing the whole settings object).
 */
class BYZO_REST_Settings_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'settings';
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
		return rest_ensure_response( BYZO_Settings::get() );
	}

	public function update_item( WP_REST_Request $request ) {
		$result = BYZO_Settings::update( $request->get_json_params() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
