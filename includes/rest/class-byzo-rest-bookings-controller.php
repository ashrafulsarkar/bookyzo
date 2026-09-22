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
					// Widened Phase 1.7 step 5 (DETAILED-SPEC.md §A11) — a
					// logged-in byzo_customer may also call this, auto-scoped
					// to their own bookings by get_items() below (never able
					// to pass bookable_item_id/an arbitrary filter to see
					// anyone else's rows — see get_items()'s own comment).
					'permission_callback' => array( $this, 'admin_or_own_customer_permissions_check' ),
					'args'                => array(
						'from'             => array( 'type' => 'string' ),
						'to'               => array( 'type' => 'string' ),
						'bookable_item_id' => array( 'type' => 'integer' ),
						// Phase 2 step 13 (DETAILED-SPEC.md §3 B3 timeline
						// view) — an alternative to bookable_item_id: every
						// active bookable item of this type at once.
						'type'             => array( 'type' => 'string' ),
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

		// Reschedule (DETAILED-SPEC.md §1.4/§A4, ROADMAP.md Phase 1.6 step
		// 3) — changes slot_id rather than status. Separate narrow route
		// from the general status PUT above, same "only one field writable
		// here" rule as /payment below. Widened Phase 1.7 step 5 to also
		// accept a logged-in byzo_customer — reschedule_item() itself
		// enforces the ownership check the permission callback alone can't
		// (it needs the specific booking's customer_id, not just "is this
		// caller a customer at all").
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)/reschedule',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'reschedule_item' ),
					'permission_callback' => array( $this, 'admin_or_own_customer_permissions_check' ),
				),
			)
		);

		// Phase 1.7 step 5 (DETAILED-SPEC.md §A11) — the dashboard's own
		// cancel action, a second authorization path onto the exact same
		// cancellation policy the guest token link already enforces
		// (BYZO_Booking::cancel_within_policy(), shared by both). Guest
		// cancellation itself is untouched — this route is reachable only
		// by a real logged-in session, never by admin_permissions_check's
		// manage_options (the admin Bookings screen already has its own
		// unrestricted status-PUT for that).
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)/cancel-as-customer',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'cancel_as_customer' ),
					'permission_callback' => array( $this, 'customer_permissions_check' ),
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

		// Same guest-allowed, email-guarded shape as the route above.
		// Called by the widget once Stripe confirms the card client-side,
		// so a booking is marked paid even where the webhook can't be
		// delivered (local/staging sites, firewalled hosts, or a webhook
		// endpoint not set up yet). Nothing here trusts the client's
		// claim — see confirm_payment().
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)/confirm-payment',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'confirm_payment' ),
					'permission_callback' => array( $this, 'public_permissions_check' ),
				),
			)
		);

		// Guest-allowed price preview (QA report 2026-09-22 B3) — the
		// widget's "Total" comes from here, computed by the same
		// BYZO_Booking::price_breakdown() create() charges with, so the two
		// can't disagree. Read-only; reveals nothing a guest couldn't learn
		// by booking. A literal segment, so it can't collide with the
		// numeric (?P<id>\d+) routes.
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/quote',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'quote' ),
					'permission_callback' => array( $this, 'public_permissions_check' ),
				),
			)
		);

		// Same guest-allowed, email-guarded shape again. Called by the
		// widget when the payment step opens (`stripe`) and when the
		// customer picks "Pay in person instead" (`in_person`), so an
		// abandoned card form and a deliberate pay-later booking are told
		// apart — BYZO_Booking_Cleanup only ever releases the former.
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<id>\d+)/payment-method',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'choose_payment_method' ),
					'permission_callback' => array( $this, 'public_permissions_check' ),
				),
			)
		);
	}

	public function get_items( WP_REST_Request $request ) {
		$args = array(
			'from'             => $request->get_param( 'from' ),
			'to'               => $request->get_param( 'to' ),
			'bookable_item_id' => $request->get_param( 'bookable_item_id' ),
			'type'             => $request->get_param( 'type' ),
			'status'           => $request->get_param( 'status' ),
			'per_page'         => $request->get_param( 'per_page' ),
			'page'             => $request->get_param( 'page' ),
		);

		// Phase 1.7 step 5 (DETAILED-SPEC.md §A11) — a byzo_customer caller
		// is force-scoped to their own customer_id, resolved server-side
		// from the real session, never from a request param (there is no
		// customer_id request arg registered at all — see register_routes()
		// — so a customer can't pass bookable_item_id/anything else to
		// widen this beyond their own rows either). An admin's request is
		// completely unaffected — customer_id stays 0, query()'s existing
		// unscoped behavior.
		if ( ! current_user_can( 'manage_options' ) ) {
			$customer = BYZO_Customer::get_by_wp_user_id( get_current_user_id() );
			$args     = array(
				'from'      => $args['from'],
				'to'        => $args['to'],
				'status'    => $args['status'],
				'per_page'  => $args['per_page'],
				'page'      => $args['page'],
				'customer_id' => $customer ? $customer['id'] : -1, // no linked row -> guaranteed zero results, never falls through to "unscoped".
			);
		}

		$result = BYZO_Booking::query( $args );

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

		$body = $request->get_json_params();
		$id   = BYZO_Booking::create( $body );

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

		// Phase 1.7 step 6 (DETAILED-SPEC.md §A11) — booking-time account
		// opt-in. Deliberately never fails the booking itself: the booking
		// is the primary action the guest came here for, account creation
		// is a bonus; a booking that already succeeded shouldn't be undone
		// or errored out because e.g. the chosen email already has an
		// account. `account_created`/`account_error` on the response let
		// the widget tell the guest what happened without a second
		// round-trip.
		if ( ! empty( $body['create_account'] ) && ! empty( $body['password'] ) ) {
			$account_result = self::maybe_create_account_for_booking( $booking, $body['password'] );
			if ( is_wp_error( $account_result ) ) {
				$booking['account_created'] = false;
				$booking['account_error']   = $account_result->get_error_message();
			} else {
				$booking['account_created'] = true;
			}
		}

		$response = rest_ensure_response( $booking );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Same registration logic BYZO_Customer_Auth::handle_register() uses
	 * (email as username, byzo_customer role, links the wp_user_id onto
	 * the customer row BYZO_Booking::create() already upserted above —
	 * never creates a second customer row) — not called directly from
	 * there since this is a JSON REST request, not an admin-post form
	 * submit, but the underlying steps are identical. Silently no-ops
	 * (returns a WP_Error, not a fatal) when customer_accounts_enabled is
	 * off, matching every other capability gate in this doc.
	 */
	private static function maybe_create_account_for_booking( $booking, $password ) {
		if ( ! BYZO_Customer_Auth::enabled() ) {
			return new WP_Error( 'byzo_accounts_disabled', __( 'Customer accounts are not enabled on this site.', 'bookyzo' ) );
		}

		if ( strlen( (string) $password ) < 8 ) {
			return new WP_Error( 'byzo_weak_password', __( 'Password must be at least 8 characters.', 'bookyzo' ) );
		}

		$email = $booking['customer_email'];

		if ( email_exists( $email ) ) {
			return new WP_Error( 'byzo_email_taken', __( 'An account with this email already exists.', 'bookyzo' ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $booking['customer_name'],
				'role'         => 'byzo_customer',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// The booking's own create() call already upserted this customer
		// row (by email) before this method ever runs — link onto it
		// rather than upserting again, so we never risk creating a second
		// row for the same email.
		if ( ! empty( $booking['customer_id'] ) ) {
			BYZO_Customer::update( $booking['customer_id'], array( 'wp_user_id' => $user_id ) );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		return true;
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
	 * Body: `{ "slot_id": 123 }` — moves the booking to a different open
	 * slot (BYZO_Booking::reschedule() does the actual work/validation).
	 * Phase 1.7 step 5: a non-admin (byzo_customer) caller is additionally
	 * required to own the booking — admin_or_own_customer_permissions_check()
	 * only confirms "is this caller an admin or A customer," it has no way
	 * to know which specific booking is being asked for, so the ownership
	 * check has to happen here once $id is known.
	 */
	public function reschedule_item( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$body = $request->get_json_params();

		if ( empty( $body['slot_id'] ) ) {
			return new WP_Error( 'byzo_missing_slot_id', __( 'slot_id is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$ownership_check = $this->require_owns_booking_if_customer( $id );
		if ( is_wp_error( $ownership_check ) ) {
			return $ownership_check;
		}

		$result = BYZO_Booking::reschedule( $id, absint( $body['slot_id'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( BYZO_Booking::get( $id ) );
	}

	/**
	 * Phase 1.7 step 5 (DETAILED-SPEC.md §A11) — the dashboard's own
	 * cancel action. No request body: the booking id in the URL plus the
	 * caller's own session (customer_permissions_check already confirmed
	 * it's a byzo_customer) is everything BYZO_Booking::cancel_as_customer()
	 * needs — it re-derives the customer row and checks ownership itself,
	 * same shape as cancel_via_token() re-deriving from a token rather than
	 * trusting anything the request claims.
	 */
	public function cancel_as_customer( WP_REST_Request $request ) {
		$id       = (int) $request->get_param( 'id' );
		$customer = BYZO_Customer::get_by_wp_user_id( get_current_user_id() );

		$result = BYZO_Booking::cancel_as_customer( $id, $customer ? $customer['id'] : 0 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( BYZO_Booking::get( $id ) );
	}

	/**
	 * Shared ownership guard for any admin-or-own-customer route that
	 * needs to know about one SPECIFIC booking (reschedule, potentially
	 * others later) — an admin always passes; a byzo_customer must own
	 * the booking in question or gets the same 403 shape
	 * customer_permissions_check() itself would return.
	 */
	private function require_owns_booking_if_customer( $booking_id ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$booking  = BYZO_Booking::get( $booking_id );
		$customer = BYZO_Customer::get_by_wp_user_id( get_current_user_id() );

		if ( ! $booking || ! $customer || (int) $booking['customer_id'] !== (int) $customer['id'] ) {
			return new WP_Error( 'byzo_not_owner', __( 'This booking does not belong to your account.', 'bookyzo' ), array( 'status' => 403 ) );
		}

		return true;
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

		BYZO_Booking::set_payment_method( $booking['id'], 'stripe' );

		return rest_ensure_response( $result );
	}

	/**
	 * Body: { slot_id, party_size?, ticket_tier_id?, service_id?, addon_ids? },
	 * or { service_id, start_datetime, ... } for "any available staff".
	 * Returns the same money fields a booking made with that body would
	 * store. 404 for a slot whose item isn't active (same answer /slots
	 * gives), so it can't be used to price hidden items.
	 */
	public function quote( WP_REST_Request $request ) {
		$body       = (array) $request->get_json_params();
		$service_id = absint( $body['service_id'] ?? 0 );
		$slot       = BYZO_Slot::get( absint( $body['slot_id'] ?? 0 ) );

		// "Any available staff": price the slot create_for_service() would
		// try first at that time (open_at()'s own order).
		if ( ! $slot && $service_id && ! empty( $body['start_datetime'] ) ) {
			$candidates = array_map( 'absint', (array) apply_filters( 'byzo_bookable_items_offering_service', array(), $service_id ) );
			$open       = $candidates ? BYZO_Slot::open_at( $candidates, sanitize_text_field( $body['start_datetime'] ) ) : array();
			$slot       = $open ? $open[0] : null;
		}

		$item = $slot ? BYZO_Bookable_Item::get( $slot['bookable_item_id'] ) : null;

		if ( ! $item || 'active' !== $item['status'] ) {
			return new WP_Error( 'byzo_not_found', __( 'Time slot not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$price = BYZO_Booking::price_breakdown(
			$slot,
			max( 1, absint( $body['party_size'] ?? 1 ) ),
			absint( $body['ticket_tier_id'] ?? 0 ) ?: null,
			$service_id ?: null,
			array_filter( array_map( 'absint', (array) ( $body['addon_ids'] ?? array() ) ) )
		);

		return rest_ensure_response(
			array(
				'unit_price'         => $price['unit_price'],
				'subtotal'           => $price['subtotal'],
				'addons_total'       => $price['addons_total'],
				'booking_fee_amount' => $price['booking_fee_amount'],
				'tax_amount'         => $price['tax_amount'],
				'payment_amount'     => $price['payment_amount'],
				'deposit_amount'     => $price['deposit_amount'],
			)
		);
	}

	/**
	 * Records how the customer is paying: `stripe` (the card form was
	 * opened) or `in_person`. Only these two are accepted from a guest —
	 * PayPal/WooCommerce are stamped server-side when their own checkout
	 * starts. `in_person` is refused when the merchant turned that option
	 * off (the widget hides the button then, so only a hand-made request
	 * reaches this). A no-op for a booking that is already paid or no
	 * longer active.
	 */
	public function choose_payment_method( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$booking = BYZO_Booking::get( $id );

		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$body = (array) $request->get_json_params();

		if ( ! current_user_can( 'manage_options' ) ) {
			if ( empty( $body['customer_email'] ) || strtolower( trim( $body['customer_email'] ) ) !== strtolower( $booking['customer_email'] ) ) {
				return new WP_Error( 'byzo_email_mismatch', __( 'customer_email does not match this booking.', 'bookyzo' ), array( 'status' => 403 ) );
			}
		}

		$method = sanitize_key( (string) ( $body['payment_method'] ?? '' ) );
		if ( ! in_array( $method, array( 'stripe', 'in_person' ), true ) ) {
			return new WP_Error( 'byzo_invalid_payment_method', __( 'payment_method must be "stripe" or "in_person".', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( 'in_person' === $method && empty( BYZO_Settings::get()['allow_pay_in_person'] ) ) {
			return new WP_Error( 'byzo_pay_in_person_disabled', __( 'Paying in person is not available for this booking.', 'bookyzo' ), array( 'status' => 403 ) );
		}

		if ( 'unpaid' === $booking['payment_status'] && in_array( $booking['status'], array( 'pending', 'confirmed' ), true ) ) {
			BYZO_Booking::set_payment_method( $booking['id'], $method );
		}

		return rest_ensure_response( array( 'payment_method' => BYZO_Booking::get( $booking['id'] )['payment_method'] ) );
	}

	/**
	 * Marks a booking paid after the widget has confirmed the card with
	 * Stripe client-side.
	 *
	 * The webhook is still the authoritative path and this is deliberately
	 * written to be redundant with it: whichever arrives first wins, and
	 * the second is a harmless no-op (set_payment_status writes the same
	 * value; the `pending` guard below means the status transition only
	 * fires once). That redundancy is the point — a card charged on a site
	 * Stripe cannot reach by webhook would otherwise leave the booking
	 * `unpaid` indefinitely.
	 *
	 * The client's claim is never trusted: it supplies only an intent id,
	 * and the server asks Stripe directly whether that intent succeeded
	 * AND whether it belongs to this booking (Stripe echoes back the
	 * booking_id this plugin set in the intent's metadata at creation
	 * time). A forged or borrowed intent id fails both checks.
	 */
	public function confirm_payment( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$booking = BYZO_Booking::get( $id );

		if ( ! $booking ) {
			return new WP_Error( 'byzo_not_found', __( 'Booking not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$body = (array) $request->get_json_params();

		if ( ! current_user_can( 'manage_options' ) ) {
			if ( empty( $body['customer_email'] ) || strtolower( trim( $body['customer_email'] ) ) !== strtolower( $booking['customer_email'] ) ) {
				return new WP_Error( 'byzo_email_mismatch', __( 'customer_email does not match this booking.', 'bookyzo' ), array( 'status' => 403 ) );
			}
		}

		if ( empty( $body['payment_intent_id'] ) ) {
			return new WP_Error( 'byzo_missing_payment_intent', __( 'payment_intent_id is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$intent = BYZO_Stripe::get_payment_intent( sanitize_text_field( $body['payment_intent_id'] ) );

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		// The intent must have been created for THIS booking. Without this
		// check, knowing any succeeded intent id plus a booking's email
		// would be enough to mark that booking paid.
		if ( (int) ( $intent['metadata']['booking_id'] ?? 0 ) !== $booking['id'] ) {
			return new WP_Error( 'byzo_intent_mismatch', __( 'That payment does not belong to this booking.', 'bookyzo' ), array( 'status' => 403 ) );
		}

		if ( 'succeeded' !== ( $intent['status'] ?? '' ) ) {
			return new WP_Error( 'byzo_payment_not_succeeded', __( 'That payment has not completed.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		BYZO_Booking::set_payment_status( $booking['id'], 'paid_online' );

		// Same transition guard the webhook uses — a booking already moved
		// on (confirmed by an admin, or cancelled) is left alone.
		if ( 'pending' === $booking['status'] ) {
			BYZO_Booking::update_status( $booking['id'], 'confirmed' );
		}

		return rest_ensure_response( BYZO_Booking::get( $booking['id'] ) );
	}
}
