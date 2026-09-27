<?php
defined( 'ABSPATH' ) || exit;

/**
 * /bookable-items and /bookable-items/{id} — admin CRUD for the bookable
 * entity (staff/table/room/event/rental item). See DETAILED-SPEC.md §4.
 * Renamed from "resource"/"resources" 2026-09-03 — read as an unclear,
 * overly technical term to end users.
 *
 * The per-type cap (default 1, DETAILED-SPEC.md §A9) is enforced here via
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
					'permission_callback' => array( $this, 'staff_permissions_check' ),
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

		// Event occurrence CRUD (DETAILED-SPEC.md §1.3/§5 item 7, Phase 1.5
		// step 5) — event-type items only, admin-only. GET added alongside
		// the POST/DELETE the spec planned, since the "Dates" tab needs some
		// way to list what it's managing; natural REST completion of the
		// same collection route, not a new concept.
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)/slots',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_occurrences' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_occurrence' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)/slots/(?P<slot_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_occurrence' ),
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
				// A staff login lists only their own items (QA report §6.2.9).
				'ids'      => BYZO_Staff::is_staff() ? BYZO_Staff::item_ids() : null,
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
					// location/meeting_link added 2026-09-05 (Phase 1.6) — the
					// widget's confirmation step shows these when set
					// (FEATURES.md A2), so the public whitelist needs them
					// same as every other guest-visible field here.
					'location'          => $item['location'],
					'meeting_link'      => $item['meeting_link'],
					// Which location it's at (QA report §6.1 A1) — null
					// unless a locations add-on placed it.
					'location_id'       => $item['location_id'],
					// Added 2026-09-13 (Phase 5 step 2, DETAILED-SPEC.md §3
					// B9) — a derived boolean, never the raw wc_product_id
					// itself (no reason for the public widget to know a WC
					// post ID). Lets the widget's payment step swap to
					// WooCommerce checkout for this item without a second
					// round-trip; false on every install without the bridge
					// plugin active, same as every other Pro-consumed field.
					'wc_checkout_enabled' => ! empty( $item['wc_product_id'] ),
					// An event with no date left to sell stays out of the
					// widget's list (QA report 2026-09-26 H3).
					'has_upcoming_dates'  => 'event' !== $item['type'] || BYZO_Slot::has_upcoming( $item ),
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
	 * GET /bookable-items/{id}/slots — lists every occurrence for an
	 * event-type item (DETAILED-SPEC.md §1.3/§5 item 7), the "Dates" tab's
	 * own listing. Rejects non-event items with the same 400
	 * `byzo_not_event_type` this whole occurrence-CRUD trio uses — see
	 * check_event_type()'s docblock for why.
	 */
	public function get_occurrences( WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$check = $this->check_event_type( $id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		return rest_ensure_response( BYZO_Slot::list_for_bookable_item( $id ) );
	}

	/**
	 * POST /bookable-items/{id}/slots — creates one event occurrence.
	 * Body: `{ date, start_time, end_time }`.
	 */
	public function add_occurrence( WP_REST_Request $request ) {
		$id    = (int) $request->get_param( 'id' );
		$check = $this->check_event_type( $id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$result = BYZO_Slot::create_occurrence( $id, $request->get_json_params() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = rest_ensure_response( BYZO_Slot::get( $result ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * DELETE /bookable-items/{id}/slots/{slot_id} — removes one occurrence;
	 * refuses (409, via BYZO_Slot::delete_occurrence()) if it has bookings.
	 */
	public function delete_occurrence( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$slot_id = (int) $request->get_param( 'slot_id' );

		$check = $this->check_event_type( $id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$slot = BYZO_Slot::get( $slot_id );
		if ( ! $slot || $slot['bookable_item_id'] !== $id ) {
			return new WP_Error( 'byzo_not_found', __( 'Occurrence not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$result = BYZO_Slot::delete_occurrence( $slot_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( BYZO_Slot::list_for_bookable_item( $id ) );
	}

	/**
	 * Shared guard for all 3 occurrence-CRUD routes above: the item must
	 * exist AND be type=event. Direct slot-row management only makes sense
	 * for event items (DETAILED-SPEC.md §1.3) — every other type gets its
	 * slots exclusively from BYZO_Slot_Generator, and letting an admin poke
	 * a stray manual row into e.g. an Appointment's slots would create a
	 * row the generator doesn't know about and never cleans up.
	 */
	private function check_event_type( $id ) {
		$item = BYZO_Bookable_Item::get( $id );

		if ( ! $item ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		if ( 'event' !== $item['type'] ) {
			return new WP_Error(
				'byzo_not_event_type',
				__( 'Occurrences can only be managed for event-type bookable items.', 'bookyzo' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Enforces BYZO_Capabilities::max_bookable_items_per_type() by counting
	 * active bookable items of that type (default 1) — an honest count
	 * against a real, filterable limit, answered with a plain message.
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
					/* translators: %d: maximum number of active bookable items allowed per type */
					_n(
						'You already have %d active bookable item of this type. Deactivate or archive it before adding another.',
						'You already have %d active bookable items of this type. Deactivate or archive one before adding another.',
						$max,
						'bookyzo'
					),
					$max
				),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
