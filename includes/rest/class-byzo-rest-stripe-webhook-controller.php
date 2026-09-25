<?php
defined( 'ABSPATH' ) || exit;

/**
 * /stripe-webhook (DETAILED-SPEC.md §4: "Stripe only, signature-verified,
 * not nonce-based"). The permission_callback always returns true —
 * Stripe's signature (verified inside the handler, against the RAW
 * request body) is the actual auth mechanism, not a WP capability check
 * or nonce; Stripe itself is never a logged-in WP user.
 */
class BYZO_REST_Stripe_Webhook_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'stripe-webhook';
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base(),
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	public function handle( WP_REST_Request $request ) {
		$stripe = BYZO_Payment_Settings::get()['gateways']['stripe'];

		$verified = BYZO_Stripe::verify_webhook_signature(
			$request->get_body(),
			$request->get_header( 'stripe-signature' ),
			$stripe['webhook_secret']
		);

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$event = json_decode( $request->get_body(), true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_Error( 'byzo_invalid_webhook_payload', __( 'Malformed webhook payload.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( 'payment_intent.succeeded' === $event['type'] ) {
			$this->handle_payment_succeeded( $event );
		}

		// Any other event type is acknowledged (200) but ignored — Stripe
		// retries on non-2xx responses, and there's nothing this plugin
		// needs from event types it isn't listening for.
		return rest_ensure_response( array( 'received' => true ) );
	}

	private function handle_payment_succeeded( $event ) {
		$metadata = $event['data']['object']['metadata'] ?? array();

		// Phase 2 step 14 (DETAILED-SPEC.md §3 B6, cart checkout) — a
		// PaymentIntent Pro created for a cart carries `cart_id` instead
		// of a single `booking_id`; every booking sharing that cart_id
		// gets marked paid together, same as the one-booking path below
		// just looped. Free itself never creates a cart_id PaymentIntent
		// (no cart UI exists there), so this branch is simply unreachable
		// without Pro.
		if ( ! empty( $metadata['cart_id'] ) ) {
			foreach ( BYZO_Booking::get_by_cart_id( $metadata['cart_id'] ) as $booking ) {
				// One intent paid for the whole cart; each booking keeps the
				// intent id and its own share, so it can be refunded alone.
				BYZO_Booking::record_payment( $booking['id'], $event['data']['object']['id'] ?? '', BYZO_Booking::online_charge_amount( $booking ) );
				$this->mark_booking_paid( $booking );
			}
			return;
		}

		$booking_id = $metadata['booking_id'] ?? null;
		if ( ! $booking_id ) {
			return;
		}

		$booking = BYZO_Booking::get( (int) $booking_id );
		if ( ! $booking ) {
			return;
		}

		$object = $event['data']['object'];
		BYZO_Booking::record_payment( $booking['id'], $object['id'] ?? '', isset( $object['amount_received'] ) ? BYZO_Stripe::from_minor_units( (int) $object['amount_received'] ) : BYZO_Booking::online_charge_amount( $booking ) );
		$this->mark_booking_paid( $booking );
	}

	private function mark_booking_paid( $booking ) {
		BYZO_Booking::set_payment_status( $booking['id'], 'paid_online' );

		// Reuses update_status()'s own transition guard rather than a
		// direct status write — if the booking is already confirmed (or
		// otherwise not in a state where ->confirmed is legal, e.g. it
		// was cancelled after payment was initiated), this is a no-op
		// WP_Error the webhook doesn't need to surface as a failure; the
		// payment itself still succeeded and was recorded above.
		if ( 'pending' === $booking['status'] ) {
			BYZO_Booking::update_status( $booking['id'], 'confirmed' );
		}
	}
}
