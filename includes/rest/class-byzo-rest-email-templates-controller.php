<?php
defined( 'ABSPATH' ) || exit;

/**
 * /email-templates — Free's plain-text template editing (DETAILED-SPEC.md
 * §A6). Admin-only, mirrors /settings' shape (single GET/PUT resource,
 * not a collection).
 */
class BYZO_REST_Email_Templates_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'email-templates';
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
		return rest_ensure_response( BYZO_Email_Templates::get() );
	}

	public function update_item( WP_REST_Request $request ) {
		$result = BYZO_Email_Templates::update( (array) $request->get_json_params() );

		return rest_ensure_response( $result );
	}
}
