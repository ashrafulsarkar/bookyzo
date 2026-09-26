<?php
defined( 'ABSPATH' ) || exit;

/**
 * /ticket-tiers/public — the widget's own read of which ticket tiers
 * apply to an Event bookable item (Phase 2 step 12 continued,
 * DETAILED-SPEC.md §3 B7 Event). Same "Free owns the public read, Pro
 * owns the admin CRUD on its own table" split `/addons/public` (step 17)
 * and `/custom-fields/public` (step 16) already established.
 *
 * Free has no idea `wp_byzo_ticket_tiers` exists — this route just calls
 * a filter and returns whatever comes back, defaulting to `array()` so
 * a site without ticket tiers shows no tier picker, not a broken section.
 */
class BYZO_REST_Ticket_Tiers_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'ticket-tiers';
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/public',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_public_tiers' ),
				'permission_callback' => array( $this, 'public_permissions_check' ),
				'args'                => array(
					'bookable_item_id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
	}

	public function get_public_tiers( WP_REST_Request $request ) {
		$bookable_item_id = (int) $request->get_param( 'bookable_item_id' );

		/**
		 * Filters which ticket tiers apply to a bookable item, for the
		 * public booking widget to render. Free always returns the
		 * untouched default (none) — this is a Pro concept
		 * (`wp_byzo_ticket_tiers`); the filter point lives in Free so the
		 * widget doesn't need to know Pro's table shape.
		 *
		 * @param array $tiers            Each: { id, name, price, capacity_remaining }.
		 * @param int   $bookable_item_id
		 */
		$tiers = apply_filters( 'byzo_ticket_tiers_for_bookable_item', array(), $bookable_item_id );

		return rest_ensure_response( array_values( (array) $tiers ) );
	}
}
