<?php
defined( 'ABSPATH' ) || exit;

/**
 * /resources and /resources/{id} — admin CRUD for the bookable entity
 * (staff/table/room/event/rental item). See DETAILED-SPEC.md §4.
 *
 * The per-type resource cap (Free = 1, DETAILED-SPEC.md §A9) is enforced
 * here via BYZO_Capabilities::max_resources_per_type(), not in the model —
 * this is the "one real capability check" the Guideline-5 rule requires,
 * Pro removes the limit purely by filtering the capability function.
 */
class BYZO_REST_Resources_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'resources';
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
						'type'     => array( 'type' => 'string' ),
						'status'   => array( 'type' => 'string' ),
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

		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)/availability',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_availability' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_availability' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)/availability/exceptions',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_availability_exception' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)/availability/exceptions/(?P<exception_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_availability_exception' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);
	}

	public function get_items( WP_REST_Request $request ) {
		$result = BYZO_Resource::query(
			array(
				'type'     => $request->get_param( 'type' ),
				'status'   => $request->get_param( 'status' ),
				'per_page' => $request->get_param( 'per_page' ),
				'page'     => $request->get_param( 'page' ),
			)
		);

		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );

		return $response;
	}

	public function get_item( WP_REST_Request $request ) {
		$resource = BYZO_Resource::get( (int) $request->get_param( 'id' ) );

		if ( ! $resource ) {
			return new WP_Error( 'byzo_not_found', __( 'Resource not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $resource );
	}

	public function create_item( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' ) ?: 'appointment';

		$cap_check = $this->check_type_cap( $type );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$id = BYZO_Resource::create( $request->get_json_params() );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		/**
		 * Fires after a new resource is created, so slots can be
		 * generated immediately rather than waiting for the next daily
		 * cron tick (BYZO_Plugin hooks this to BYZO_Slot_Generator).
		 */
		do_action( 'byzo_resource_created', $id );

		$response = rest_ensure_response( BYZO_Resource::get( $id ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_item( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$data = $request->get_json_params();

		// Changing type on an existing resource still has to respect the cap on the *new* type.
		if ( ! empty( $data['type'] ) ) {
			$existing = BYZO_Resource::get( $id );
			if ( $existing && $existing['type'] !== $data['type'] ) {
				$cap_check = $this->check_type_cap( $data['type'] );
				if ( is_wp_error( $cap_check ) ) {
					return $cap_check;
				}
			}
		}

		$result = BYZO_Resource::update( $id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( BYZO_Resource::get( $id ) );
	}

	public function delete_item( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = BYZO_Resource::delete( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
	}

	public function get_availability( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! BYZO_Resource::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Resource not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( BYZO_Availability::get_for_resource( $id ) );
	}

	/**
	 * Replaces the resource's full weekly schedule. Body: `{ "schedules": [ { weekday, start_time, end_time, shift_group? } ] }`.
	 */
	public function update_availability( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! BYZO_Resource::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Resource not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$body      = $request->get_json_params();
		$schedules = isset( $body['schedules'] ) && is_array( $body['schedules'] ) ? $body['schedules'] : array();

		$result = BYZO_Availability::set_schedules( $id, $schedules );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Schedule changed — regenerate now so stale slots (from the old
		// hours) don't linger until the next nightly cron tick.
		BYZO_Slot_Generator::generate_for_resource( $id );

		return rest_ensure_response( BYZO_Availability::get_for_resource( $id ) );
	}

	/**
	 * Adds one blackout/holiday exception. Body: `{ date, is_closed?, start_time?, end_time? }`.
	 */
	public function add_availability_exception( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! BYZO_Resource::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Resource not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$result = BYZO_Availability::add_exception( $id, $request->get_json_params() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Blocks any already-materialized open slots on the new exception date.
		BYZO_Slot_Generator::generate_for_resource( $id );

		$response = rest_ensure_response( BYZO_Availability::get_for_resource( $id ) );
		$response->set_status( 201 );

		return $response;
	}

	public function delete_availability_exception( WP_REST_Request $request ) {
		$id           = (int) $request->get_param( 'id' );
		$exception_id = (int) $request->get_param( 'exception_id' );

		$result = BYZO_Availability::delete_exception( $id, $exception_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( BYZO_Availability::get_for_resource( $id ) );
	}

	/**
	 * Enforces BYZO_Capabilities::max_resources_per_type() by counting
	 * active resources of that type. Free = 1, so the 2nd active resource
	 * of the same type is the real "hit the wall" moment described in
	 * ROADMAP.md's Free -> Pro Conversion Strategy table — not a cosmetic
	 * block, an honest count against a real (filterable) limit.
	 */
	private function check_type_cap( $type ) {
		$max = BYZO_Capabilities::max_resources_per_type();

		if ( $max <= 0 || $max >= PHP_INT_MAX ) {
			return true;
		}

		$existing = BYZO_Resource::query(
			array(
				'type'     => $type,
				'status'   => 'active',
				'per_page' => 1,
			)
		);

		if ( $existing['total'] >= $max ) {
			return new WP_Error(
				'byzo_resource_limit_reached',
				sprintf(
					/* translators: %d: maximum number of active resources allowed */
					__( 'You already have %d active resource of this type. Upgrade to add more.', 'bookyzo' ),
					$max
				),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
