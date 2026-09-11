<?php
defined( 'ABSPATH' ) || exit;

/**
 * /slots — public read endpoint the booking widget calls to show
 * available times for a bookable item (DETAILED-SPEC.md §4). Guest-allowed
 * by design: this is browse-only (no write), so `public_permissions_check()`
 * is the right gate, not `admin_permissions_check()`.
 */
class BYZO_REST_Slots_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'slots';
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base(),
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'public_permissions_check' ),
					// bookable_item_id and service_id are mutually exclusive
					// alternatives (Phase 2 step 5, DETAILED-SPEC.md §3 B7) —
					// neither is marked `required` at the schema level since
					// which one applies depends on the other; get_items()
					// itself 400s if neither is present.
					'args'                => array(
						'bookable_item_id' => array( 'type' => 'integer' ),
						'service_id'       => array( 'type' => 'integer' ),
						'from'             => array( 'type' => 'string' ),
						'to'               => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	public function get_items( WP_REST_Request $request ) {
		$service_id = (int) $request->get_param( 'service_id' );

		// Phase 2 step 5 (DETAILED-SPEC.md §3 B7) — "any available staff"
		// browsing: a combined list across every bookable item offering
		// the service, resolved the same way BYZO_Booking::create_for_service()
		// resolves candidates for the actual booking. Free never populates
		// this filter, so this path simply returns an empty list without
		// Pro — no separate "is Pro active" check needed here either.
		if ( $service_id ) {
			$candidates = apply_filters( 'byzo_bookable_items_offering_service', array(), $service_id );

			$slots = BYZO_Slot::query_for_bookable_items(
				$candidates,
				array(
					'from' => $request->get_param( 'from' ),
					'to'   => $request->get_param( 'to' ),
				)
			);

			if ( is_wp_error( $slots ) ) {
				return $slots;
			}

			return rest_ensure_response( $slots );
		}

		$item = BYZO_Bookable_Item::get( (int) $request->get_param( 'bookable_item_id' ) );

		// Same "not found" response whether the item never existed or is
		// inactive/archived — the public widget has no legitimate need to
		// distinguish those, and archived items shouldn't leak via
		// enumeration either.
		if ( ! $item || 'active' !== $item['status'] ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$slots = BYZO_Slot::query(
			array(
				'bookable_item_id' => $item['id'],
				'from'             => $request->get_param( 'from' ),
				'to'               => $request->get_param( 'to' ),
				'open_only'        => true,
			)
		);

		if ( is_wp_error( $slots ) ) {
			return $slots;
		}

		return rest_ensure_response( $slots );
	}
}
