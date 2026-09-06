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
		$booking_id = $event['data']['object']['metadata']['booking_id'] ?? null;
		if ( ! $booking_id ) {
			return;
		}

		$booking = BYZO_Booking::get( (int) $booking_id );
		if ( ! $booking ) {
			return;
		}

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
