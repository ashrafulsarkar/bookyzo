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
			$result = $this->handle_payment_succeeded( $event );

			// Only "try again later" goes back to Stripe as a failure (it
			// retries non-2xx), e.g. the same booking's payment being
			// recorded by the widget at this very moment.
			if ( is_wp_error( $result ) && 'byzo_payment_busy' === $result->get_error_code() ) {
				return $result;
			}
		}

		// Any other event type is acknowledged (200) but ignored — Stripe
		// retries on non-2xx responses, and there's nothing this plugin
		// needs from event types it isn't listening for.
		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * @return true|WP_Error
	 */
	private function handle_payment_succeeded( $event ) {
		$object   = $event['data']['object'] ?? array();
		$metadata = $object['metadata'] ?? array();

		/**
		 * Any succeeded PaymentIntent, for payments that aren't bookings —
		 * e.g. an add-on's package purchase (QA report §6.1 A2), which it
		 * recognises by its own metadata.
		 *
		 * @param array $intent The PaymentIntent object.
		 */
		do_action( 'byzo_stripe_payment_succeeded', $object );

		// Phase 2 step 14 (DETAILED-SPEC.md §3 B6, cart checkout) — a
		// PaymentIntent Pro created for a cart carries `cart_id` instead
		// of a single `booking_id`; every booking sharing that cart_id is
		// handled the same way the one-booking path below handles its one
		// (1.0.3: including a cart whose hold ran out, booking by booking).
		// Free itself never creates a cart_id PaymentIntent (no cart UI
		// exists there), so this branch is simply unreachable without Pro.
		if ( ! empty( $metadata['cart_id'] ) ) {
			$busy = null;
			foreach ( BYZO_Booking::get_by_cart_id( $metadata['cart_id'] ) as $booking ) {
				// One intent paid for the whole cart; each booking keeps the
				// intent id and its own share, so it can be refunded alone.
				$result = BYZO_Booking::apply_online_payment( $booking['id'], $object['id'] ?? '', BYZO_Booking::online_charge_amount( $booking ) );
				if ( is_wp_error( $result ) && 'byzo_payment_busy' === $result->get_error_code() ) {
					$busy = $result;
				}
			}
			return $busy ? $busy : true;
		}

		$booking_id = $metadata['booking_id'] ?? null;
		if ( ! $booking_id ) {
			return true;
		}

		$booking = BYZO_Booking::get( (int) $booking_id );
		if ( ! $booking ) {
			return true;
		}

		// Pending, cancelled by an expired hold, or already cancelled:
		// apply_online_payment() decides (confirm, take the place back, or
		// mark it for refund). It no longer just marks a cancelled booking
		// paid with nobody told.
		$result = BYZO_Booking::apply_online_payment(
			$booking['id'],
			$object['id'] ?? '',
			isset( $object['amount_received'] ) ? BYZO_Stripe::from_minor_units( (int) $object['amount_received'] ) : BYZO_Booking::online_charge_amount( $booking )
		);

		return is_wp_error( $result ) ? $result : true;
	}
}
