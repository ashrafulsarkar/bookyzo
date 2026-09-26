<?php
defined( 'ABSPATH' ) || exit;

/**
 * /addons/public — the widget's own read of which add-ons apply to a
 * bookable item (Phase 2 step 17, DETAILED-SPEC.md §3 B6). Same
 * "Free owns the public read, Pro owns the admin CRUD on its own table"
 * split as `/custom-fields/public` (step 16) — own `/public` suffix
 * rather than splitting HTTP methods across two controllers on one path.
 *
 * Free has no idea `wp_byzo_addons` exists — this route just calls a
 * filter and returns whatever comes back, defaulting to `array()` so an
 * site without an add-on's add-ons shows none, not a broken section.
 */
class BYZO_REST_Addons_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'addons';
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/public',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_public_addons' ),
				'permission_callback' => array( $this, 'public_permissions_check' ),
				'args'                => array(
					'bookable_item_id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
	}

	public function get_public_addons( WP_REST_Request $request ) {
		$bookable_item_id = (int) $request->get_param( 'bookable_item_id' );

		/**
		 * Filters which add-ons apply to a bookable item, for the public
		 * booking widget to render. Free always returns the untouched
		 * default (none) — this is a Pro concept (`wp_byzo_addons`); the
		 * filter point lives in Free so the widget doesn't need to know
		 * Pro's table shape.
		 *
		 * @param array $addons           Each: { id, name, price }.
		 * @param int   $bookable_item_id
		 */
		$addons = apply_filters( 'byzo_addons_for_bookable_item', array(), $bookable_item_id );

		return rest_ensure_response( array_values( (array) $addons ) );
	}
}
