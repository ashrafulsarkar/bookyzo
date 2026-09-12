<?php
defined( 'ABSPATH' ) || exit;

/**
 * /custom-fields/public — the widget's own read of which custom form
 * fields apply to a bookable item (Phase 2 step 16, DETAILED-SPEC.md §3
 * B11). Own `/public` suffix rather than splitting HTTP methods across two
 * controllers on one path, same convention `/bookable-items/public` and
 * `/services/public` already established — Pro's own REST controller owns
 * the admin CRUD methods on the plain `/custom-fields` path against its
 * own `wp_byzo_custom_fields` table.
 *
 * Free has no idea that table exists — this route just calls a filter and
 * returns whatever comes back, defaulting to `array()` so an unlicensed
 * site's widget renders nothing extra, not a broken section.
 */
class BYZO_REST_Custom_Fields_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'custom-fields';
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/public',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_public_fields' ),
				'permission_callback' => array( $this, 'public_permissions_check' ),
				'args'                => array(
					'bookable_item_id' => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);
	}

	public function get_public_fields( WP_REST_Request $request ) {
		$bookable_item_id = (int) $request->get_param( 'bookable_item_id' );

		/**
		 * Filters which custom form fields apply to a bookable item, for
		 * the public booking widget to render. Free always returns the
		 * untouched default (none) — this is a Pro concept
		 * (`wp_byzo_custom_fields`); the filter point lives in Free so the
		 * widget doesn't need to know Pro's table shape.
		 *
		 * @param array $fields           Each: { id, label, field_type, options, required }.
		 * @param int   $bookable_item_id
		 */
		$fields = apply_filters( 'byzo_custom_fields_for_bookable_item', array(), $bookable_item_id );

		return rest_ensure_response( array_values( (array) $fields ) );
	}
}
