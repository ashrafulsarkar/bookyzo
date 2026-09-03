<?php
defined( 'ABSPATH' ) || exit;

/**
 * /bookings — POST is guest-allowed (the public widget's own creation
 * flow), GET/PUT are admin-only (DETAILED-SPEC.md §4 / §A4). Same POST
 * endpoint serves both the public widget and the admin "manual booking"
 * modal — the difference is just which permission callback let the
 * request through, per §A4's note that the admin modal reuses this route.
 */
class BYZO_REST_Bookings_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'bookings';
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
						'from'             => array( 'type' => 'string' ),
						'to'               => array( 'type' => 'string' ),
						'bookable_item_id' => array( 'type' => 'integer' ),
						'status'           => array( 'type' => 'string' ),
						'per_page'         => array( 'type' => 'integer', 'default' => 20 ),
						'page'             => array( 'type' => 'integer', 'default' => 1 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'public_permissions_check' ),
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
			)
		);

		// "Mark as paid" (DETAILED-SPEC.md §A7) — admin-only, deliberately
		// a separate route from the general status PUT above rather than
		// folding payment_status into that route's writable fields,
		// matching update_item()'s own "only status is writable through
		// THAT route" rule — payment state has its own narrow write surface.
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)/payment',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_payment' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		// Guest-allowed (public_permissions_check) — the widget calls this
		// right after creating its own booking, in the same guest session,
		// no admin auth available. Guarded instead by requiring the
		// request to know the booking's customer_email (see
		// create_payment_intent()) — not the booking's numeric id alone,
		// which is sequentially guessable.
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)/payment-intent',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_payment_intent' ),
					'permission_callback' => array( $this, 'public_permissions_check' ),
				),
			)
		);
	}

	public function get_items( WP_REST_Request $request ) {
		$result = BYZO_Booking::query(
			array(
				'from'             => $request->get_param( 'from' ),
				'to'               => $request->get_param( 'to' ),
				'bookable_item_id' => $request->get_param( 'bookable_item_id' ),
				'status'           => $request->get_param( 'status' ),
				'per_page'         => $request->get_param( 'per_page' ),
				'page'             => $request->get_param( 'page' ),
			)
		);

		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', (string) $result['total'] );

		return $response;
	}

	public function get_item( WP_REST_Request $request ) {
		$booking = BYZO_Booking::get( (int) $request->get_param( 'id' ) );

		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $booking );
	}

	public function create_item( WP_REST_Request $request ) {
		$guard_check = BYZO_Public_Guard::check_booking_request( $request );
		if ( is_wp_error( $guard_check ) ) {
			return $guard_check;
		}

		$id = BYZO_Booking::create( $request->get_json_params() );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$booking = BYZO_Booking::get( $id );

		// Only exposed on creation — a self-service link is only useful
		// right when the booking is made (for a confirmation-email link,
		// DETAILED-SPEC.md §A5); nothing needs it on a later GET, and
		// keeping it off other responses keeps the field's surface area
		// matched to its actual use today.
		$booking['manage_token'] = BYZO_Booking_Token::generate( $booking['id'], $booking['customer_email'] );

		$response = rest_ensure_response( $booking );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Body: `{ "status": "confirmed" }`. Only the status field is
	 * writable through this route — everything else about a booking is
	 * immutable once created (matches DETAILED-SPEC.md §A4's state
	 * machine requirement; free-form field edits aren't part of Free
	 * scope and would bypass the transition guard entirely if allowed here).
	 */
	public function update_item( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$body = $request->get_json_params();

		if ( empty( $body['status'] ) ) {
			return new WP_Error( 'byzo_missing_status', __( 'status is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$result = BYZO_Booking::update_status( $id, $body['status'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( BYZO_Booking::get( $id ) );
	}

	/**
	 * Body: `{ "payment_status": "paid_manual" }` — the "Mark as paid"
	 * button (DETAILED-SPEC.md §A7). Free only ever sends 'paid_manual'
	 * from the admin UI; 'paid_online'/'refunded' are set by the Stripe
	 * webhook / a future refund flow, not through this admin-facing route.
	 */
	public function update_payment( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$body = $request->get_json_params();

		if ( empty( $body['payment_status'] ) ) {
			return new WP_Error( 'byzo_missing_payment_status', __( 'payment_status is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$result = BYZO_Booking::set_payment_status( $id, $body['payment_status'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( BYZO_Booking::get( $id ) );
	}

	/**
	 * Body: `{ "customer_email": "..." }` — must match the booking's
	 * stored email, since this route has no admin session to rely on
	 * (called by the guest widget right after booking creation). This
	 * is a lightweight ownership check, not a strong auth boundary — the
	 * actual security-sensitive part (the charge amount) is server-computed
	 * from the booking's own payment_amount, never client-suppliable
	 * (see BYZO_Booking::create()), so the worst a wrong-email guess
	 * enables is creating a PaymentIntent for someone else's booking,
	 * which still requires a real card to complete and charges the
	 * correct (server-computed) amount to THAT booking either way.
	 */
	public function create_payment_intent( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$booking = BYZO_Booking::get( $id );

		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$body = $request->get_json_params();
			if ( empty( $body['customer_email'] ) || strtolower( trim( $body['customer_email'] ) ) !== strtolower( $booking['customer_email'] ) ) {
				return new WP_Error( 'byzo_email_mismatch', __( 'customer_email does not match this booking.', 'bookyzo' ), array( 'status' => 403 ) );
			}
		}

		$result = BYZO_Stripe::create_payment_intent( $booking );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
