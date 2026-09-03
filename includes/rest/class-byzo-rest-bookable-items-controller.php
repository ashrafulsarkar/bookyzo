<?php
defined( 'ABSPATH' ) || exit;

/**
 * /bookable-items and /bookable-items/{id} — admin CRUD for the bookable
 * entity (staff/table/room/event/rental item). See DETAILED-SPEC.md §4.
 * Renamed from "resource"/"resources" 2026-09-03 — read as an unclear,
 * overly technical term to end users.
 *
 * The per-type cap (Free = 1, DETAILED-SPEC.md §A9) is enforced here via
 * BYZO_Capabilities::max_bookable_items_per_type(), not in the model —
 * this is the "one real capability check" the Guideline-5 rule requires,
 * Pro removes the limit purely by filtering the capability function.
 */
class BYZO_REST_Bookable_Items_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'bookable-items';
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

		// Public, read-only, whitelisted-fields listing for the booking
		// widget's bookable-item/service picker step (DETAILED-SPEC.md §A5)
		// — a real gap found while building that step: the widget has
		// no wp-admin session, so the main GET /bookable-items route
		// (admin_permissions_check) isn't reachable, and even if it
		// were, it exposes internal fields (location_id, timestamps,
		// etc.) a guest has no reason to see.
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/public',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_public_items' ),
					'permission_callback' => array( $this, 'public_permissions_check' ),
					// `type` added 2026-09-03 (ROADMAP.md Phase 1.5 step 4)
					// for the `[bookyzo_booking type="..."]` shortcode/block
					// variant — filters the picker to one booking type
					// instead of pinning to one specific bookable item.
					'args'                => array(
						'type' => array( 'type' => 'string' ),
					),
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
		$result = BYZO_Bookable_Item::query(
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

	/**
	 * GET /bookable-items/public — see the registration comment above.
	 * Whitelists exactly the fields the booking widget needs; never
	 * passes a raw bookable-item row through, so a new internal column
	 * added later doesn't accidentally leak to guests by default.
	 */
	public function get_public_items( WP_REST_Request $request ) {
		$result = BYZO_Bookable_Item::query(
			array(
				'status'   => 'active',
				'type'     => $request->get_param( 'type' ),
				'per_page' => 100,
			)
		);

		$items = array_map(
			static function ( $item ) {
				return array(
					'id'                => $item['id'],
					'type'              => $item['type'],
					'name'              => $item['name'],
					'description'       => $item['description'],
					'photo_id'          => $item['photo_id'],
					'duration_minutes'  => $item['duration_minutes'],
					'price'             => $item['price'],
					'capacity'          => $item['capacity'],
				);
			},
			$result['items']
		);

		return rest_ensure_response( $items );
	}

	public function get_item( WP_REST_Request $request ) {
		$item = BYZO_Bookable_Item::get( (int) $request->get_param( 'id' ) );

		if ( ! $item ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $item );
	}

	public function create_item( WP_REST_Request $request ) {
		$type = $request->get_param( 'type' ) ?: 'appointment';

		$cap_check = $this->check_type_cap( $type );
		if ( is_wp_error( $cap_check ) ) {
			return $cap_check;
		}

		$id = BYZO_Bookable_Item::create( $request->get_json_params() );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		/**
		 * Fires after a new bookable item is created, so slots can be
		 * generated immediately rather than waiting for the next daily
		 * cron tick (BYZO_Plugin hooks this to BYZO_Slot_Generator).
		 */
		do_action( 'byzo_bookable_item_created', $id );

		$response = rest_ensure_response( BYZO_Bookable_Item::get( $id ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_item( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$data = $request->get_json_params();

		// Type is immutable after creation (DETAILED-SPEC.md §5 item 6:
		// "changing type would orphan the item's schedule/slots" — its
		// existing wp_byzo_schedules rows or materialized wp_byzo_slots were
		// built for the OLD type's booking model, e.g. an event's directly-
		// entered occurrences vs. a table's weekly-recurring generator
		// output). BookableItemDetailsTab.vue shows type read-only; this is
		// the real, honest enforcement behind that UI choice, not just a UI
		// suggestion a direct API call could quietly bypass.
		if ( isset( $data['type'] ) ) {
			$existing = BYZO_Bookable_Item::get( $id );
			if ( $existing && $existing['type'] !== $data['type'] ) {
				return new WP_Error(
					'byzo_type_immutable',
					__( "A bookable item's booking type cannot be changed after creation.", 'bookyzo' ),
					array( 'status' => 400 )
				);
			}
		}

		$result = BYZO_Bookable_Item::update( $id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( BYZO_Bookable_Item::get( $id ) );
	}

	public function delete_item( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = BYZO_Bookable_Item::delete( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
	}

	public function get_availability( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! BYZO_Bookable_Item::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( BYZO_Availability::get_for_bookable_item( $id ) );
	}

	/**
	 * Replaces the bookable item's full weekly schedule. Body: `{ "schedules": [ { weekday, start_time, end_time, shift_group? } ] }`.
	 */
	public function update_availability( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! BYZO_Bookable_Item::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$body      = $request->get_json_params();
		$schedules = isset( $body['schedules'] ) && is_array( $body['schedules'] ) ? $body['schedules'] : array();

		$result = BYZO_Availability::set_schedules( $id, $schedules );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Schedule changed — regenerate now so stale slots (from the old
		// hours) don't linger until the next nightly cron tick.
		BYZO_Slot_Generator::generate_for_bookable_item( $id );

		return rest_ensure_response( BYZO_Availability::get_for_bookable_item( $id ) );
	}

	/**
	 * Adds one blackout/holiday exception. Body: `{ date, is_closed?, start_time?, end_time? }`.
	 */
	public function add_availability_exception( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! BYZO_Bookable_Item::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$result = BYZO_Availability::add_exception( $id, $request->get_json_params() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Blocks any already-materialized open slots on the new exception date.
		BYZO_Slot_Generator::generate_for_bookable_item( $id );

		$response = rest_ensure_response( BYZO_Availability::get_for_bookable_item( $id ) );
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

		return rest_ensure_response( BYZO_Availability::get_for_bookable_item( $id ) );
	}

	/**
	 * Enforces BYZO_Capabilities::max_bookable_items_per_type() by counting
	 * active bookable items of that type. Free = 1, so the 2nd active item
	 * of the same type is the real "hit the wall" moment described in
	 * ROADMAP.md's Free -> Pro Conversion Strategy table — not a cosmetic
	 * block, an honest count against a real (filterable) limit.
	 */
	private function check_type_cap( $type ) {
		$max = BYZO_Capabilities::max_bookable_items_per_type();

		if ( $max <= 0 || $max >= PHP_INT_MAX ) {
			return true;
		}

		$existing = BYZO_Bookable_Item::query(
			array(
				'type'     => $type,
				'status'   => 'active',
				'per_page' => 1,
			)
		);

		if ( $existing['total'] >= $max ) {
			return new WP_Error(
				'byzo_bookable_item_limit_reached',
				sprintf(
					/* translators: %d: maximum number of active bookable items allowed */
					__( 'You already have %d active bookable item of this type. Upgrade to add more.', 'bookyzo' ),
					$max
				),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
