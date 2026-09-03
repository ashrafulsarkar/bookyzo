<?php
defined( 'ABSPATH' ) || exit;

/**
 * Base class every Bookyzo REST controller extends. Centralizes the
 * namespace and the two auth patterns every route needs: admin-only
 * (capability check) and public-but-guarded (rate-limit/nonce hook point
 * for guest booking creation, see DETAILED-SPEC.md §4's note on A5).
 *
 * Not a WP_REST_Controller subclass on purpose — that base class assumes a
 * CRUD-over-one-object-type shape (schema(), prepare_item_for_response(),
 * etc.) that doesn't fit every Bookyzo route (e.g. /stripe-webhook). Routes
 * that *are* plain CRUD can still follow WP_REST_Controller's method-naming
 * convention by hand without inheriting its assumptions.
 */
abstract class BYZO_REST_Controller {

	const NAMESPACE_V1 = 'bookyzo/v1';

	/**
	 * Route base, e.g. 'bookable-items' for `/bookyzo/v1/bookable-items`.
	 * Implemented by each concrete controller.
	 */
	abstract protected function get_rest_base();

	/**
	 * Registers this controller's routes. Implemented by each concrete
	 * controller, called from BYZO_REST::register_routes().
	 */
	abstract public function register_routes();

	/**
	 * Permission callback for admin-only routes (bookable-item/booking
	 * management, settings). One real capability check, reused everywhere
	 * — never duplicated inline per DETAILED-SPEC.md §A9's "one place"
	 * enforcement principle.
	 */
	public function admin_permissions_check( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'byzo_rest_forbidden',
				__( 'You do not have permission to do this.', 'bookyzo' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Permission callback for routes the public booking widget calls
	 * (guest-allowed). Always true today — reserved as a named hook point
	 * so a rate-limit/honeypot guard (DETAILED-SPEC.md §4's abuse-guard
	 * note for A5) has one place to attach later without touching every
	 * controller that needs it.
	 */
	public function public_permissions_check( WP_REST_Request $request ) {
		return apply_filters( 'byzo_rest_public_permission', true, $request );
	}
}
