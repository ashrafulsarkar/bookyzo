<?php
defined( 'ABSPATH' ) || exit;

/**
 * /customers and /customers/{id} — admin CRUD over wp_byzo_customers
 * (DETAILED-SPEC.md §4/§A10, Phase 1.5 step 9). Admin-only; nothing here
 * is guest-reachable — a customer row is either upserted server-side by
 * BYZO_Booking::create() or managed directly from this admin screen.
 */
class BYZO_REST_Customers_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'customers';
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
					'args'                => array(
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'default' => 20 ),
						'page'     => array( 'type' => 'integer', 'default' => 1 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)',
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
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);
	}

	public function get_items( WP_REST_Request $request ) {
		$result = BYZO_Customer::query(
			array(
				'search'   => $request->get_param( 'search' ),
				'per_page' => $request->get_param( 'per_page' ),
				'page'     => $request->get_param( 'page' ),
			)
		);

		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );

		return $response;
	}

	public function get_item( WP_REST_Request $request ) {
		$customer = BYZO_Customer::get( (int) $request->get_param( 'id' ) );

		if ( ! $customer ) {
			return new WP_Error( 'byzo_not_found', __( 'Customer not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $customer );
	}

	public function create_item( WP_REST_Request $request ) {
		$id = BYZO_Customer::create( $request->get_json_params() );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$response = rest_ensure_response( BYZO_Customer::get( $id ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_item( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = BYZO_Customer::update( $id, $request->get_json_params() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( BYZO_Customer::get( $id ) );
	}

	public function delete_item( WP_REST_Request $request ) {
		$result = BYZO_Customer::delete( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}
}
