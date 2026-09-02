<?php
defined( 'ABSPATH' ) || exit;

/**
 * /reports/summary — the Dashboard's today/this-week/this-month booking
 * counts (DETAILED-SPEC.md §A8). Admin-only.
 */
class BYZO_REST_Reports_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'reports';
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/summary',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_summary' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/trend',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_trend' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'days' => array(
							'type'    => 'integer',
							'default' => 14,
						),
					),
				),
			)
		);
	}

	public function get_summary( WP_REST_Request $request ) {
		return rest_ensure_response( BYZO_Reports::get_dashboard_summary() );
	}

	public function get_trend( WP_REST_Request $request ) {
		return rest_ensure_response( BYZO_Reports::get_dashboard_trend( $request->get_param( 'days' ) ) );
	}
}
