<?php
defined( 'ABSPATH' ) || exit;

/**
 * /setup — the wizard's own progress tracking (DETAILED-SPEC.md §A1),
 * separate from /settings: this is UI-flow state (which step, skipped or
 * not), not business configuration. Admin-only like every other Bookyzo
 * write surface.
 */
class BYZO_REST_Setup_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'setup';
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
		return rest_ensure_response( BYZO_Setup::get_state() );
	}

	/**
	 * Body: `{ "step": "business_profile" }` to advance, or `{ "skip": true }`.
	 */
	public function update_item( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( ! empty( $body['skip'] ) ) {
			return rest_ensure_response( BYZO_Setup::skip() );
		}

		if ( empty( $body['step'] ) ) {
			return new WP_Error( 'byzo_missing_step', __( 'step is required (or pass skip: true).', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$result = BYZO_Setup::set_step( $body['step'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
