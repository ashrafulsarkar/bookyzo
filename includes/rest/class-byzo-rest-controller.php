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

	/**
	 * Phase 1.7 step 5 (DETAILED-SPEC.md §A11) — a route an admin OR a
	 * logged-in byzo_customer may both call, each auto-scoped to their own
	 * data by the callback itself (see BYZO_REST_Bookings_Controller's own
	 * get_items()/reschedule_item(), which never trust a client-supplied
	 * customer_id — only what this check resolves server-side from the
	 * real session). A guest (no session at all) is rejected the same way
	 * admin_permissions_check() already rejects a non-admin.
	 */
	public function admin_or_own_customer_permissions_check( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return $this->customer_permissions_check( $request );
	}

	/**
	 * Permission callback for a route only a logged-in byzo_customer
	 * session may call (not admin — an admin route to the same effect
	 * already exists elsewhere, e.g. the general status PUT for
	 * cancellation). Phase 1.7 step 5.
	 */
	public function customer_permissions_check( WP_REST_Request $request ) {
		if ( is_user_logged_in() && in_array( 'byzo_customer', (array) wp_get_current_user()->roles, true ) ) {
			return true;
		}

		return new WP_Error(
			'byzo_rest_forbidden',
			__( 'You do not have permission to do this.', 'bookyzo' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
