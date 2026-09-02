<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stripe integration (DETAILED-SPEC.md §A7/§4) — talks to Stripe's REST
 * API directly via wp_remote_post() rather than pulling in Stripe's PHP
 * SDK as a Composer dependency, matching how most WP plugins avoid
 * bundling a full SDK + its own dependency tree for a handful of API
 * calls. Two responsibilities: creating a PaymentIntent server-side
 * (never trusting a client-supplied amount — see BYZO_Booking::create()'s
 * own comment on the same principle) and verifying the webhook's
 * signature before trusting anything in its payload.
 *
 * NOT independently testable against the real Stripe API in this dev
 * environment (no live/test Stripe API keys available) — the HTTP call
 * shape below is written to match Stripe's documented REST API exactly,
 * but the actual round trip needs verification with real test-mode keys
 * before this ships. Signature verification IS fully testable without
 * live keys (it's pure HMAC math against a webhook secret we control),
 * see scratchpad/smoke-payments.php.
 */
class BYZO_Stripe {

	const API_BASE = 'https://api.stripe.com/v1';

	/**
	 * Creates a Stripe PaymentIntent for a booking's server-computed
	 * amount. Never accepts a client-supplied amount — booking['payment_amount']
	 * was itself already server-computed from the resource's price at
	 * booking-creation time (BYZO_Booking::create()).
	 *
	 * @return array|WP_Error { client_secret, payment_intent_id } on success.
	 */
	public static function create_payment_intent( $booking ) {
		$settings = BYZO_Payment_Settings::get();

		if ( ! BYZO_Payment_Settings::is_stripe_ready() ) {
			return new WP_Error( 'byzo_stripe_not_configured', __( 'Online card payment is not available right now.', 'bookyzo' ), array( 'status' => 503 ) );
		}

		if ( null === $booking['payment_amount'] || $booking['payment_amount'] <= 0 ) {
			return new WP_Error( 'byzo_nothing_to_charge', __( 'This booking has nothing to charge.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$currency     = strtolower( BYZO_Settings::get()['currency'] );
		$amount_minor = self::to_minor_units( $booking['payment_amount'] );

		$response = wp_remote_post(
			self::API_BASE . '/payment_intents',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $settings['stripe_secret_key'],
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'amount'                => $amount_minor,
					'currency'              => $currency,
					'metadata[booking_id]'  => $booking['id'],
					'receipt_email'         => $booking['customer_email'],
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'byzo_stripe_request_failed', __( 'Could not reach the payment provider. Please try again.', 'bookyzo' ), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['client_secret'] ) ) {
			$message = $body['error']['message'] ?? __( 'The payment provider rejected this request.', 'bookyzo' );
			return new WP_Error( 'byzo_stripe_error', $message, array( 'status' => 502 ) );
		}

		return array(
			'client_secret'     => $body['client_secret'],
			'payment_intent_id' => $body['id'],
		);
	}

	/**
	 * Stripe's signing scheme: the header is `t=<timestamp>,v1=<sig>[,v1=<sig2>...]`
	 * (multiple v1 values appear during secret rotation — any match is
	 * valid). The signed payload is the literal string "{timestamp}.{raw_body}",
	 * HMAC-SHA256'd with the webhook signing secret. Verifying against the
	 * RAW request body (not a re-encoded/re-serialized version of it) is
	 * essential — any re-serialization can change whitespace/key order
	 * and silently break every signature.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_webhook_signature( $raw_payload, $signature_header, $webhook_secret ) {
		if ( empty( $webhook_secret ) ) {
			return new WP_Error( 'byzo_webhook_not_configured', __( 'Stripe webhook secret is not configured.', 'bookyzo' ), array( 'status' => 500 ) );
		}

		if ( empty( $signature_header ) ) {
			return new WP_Error( 'byzo_missing_signature', __( 'Missing Stripe-Signature header.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$parts = array();
		foreach ( explode( ',', $signature_header ) as $pair ) {
			$kv = explode( '=', $pair, 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ $kv[0] ][] = $kv[1];
			}
		}

		if ( empty( $parts['t'][0] ) || empty( $parts['v1'] ) ) {
			return new WP_Error( 'byzo_malformed_signature', __( 'Malformed Stripe-Signature header.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		$timestamp = $parts['t'][0];
		$signed_payload = $timestamp . '.' . $raw_payload;
		$expected = hash_hmac( 'sha256', $signed_payload, $webhook_secret );

		foreach ( $parts['v1'] as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}

		return new WP_Error( 'byzo_signature_mismatch', __( 'Stripe signature verification failed.', 'bookyzo' ), array( 'status' => 400 ) );
	}

	/**
	 * Stripe amounts are integers in the currency's smallest unit (cents
	 * for USD, but zero-decimal for currencies like JPY) — this plugin
	 * only needs the common 2-decimal case for now (matches DETAILED-SPEC.md's
	 * DECIMAL(10,2) price columns); zero-decimal currencies aren't
	 * special-cased here (no $currency parameter — always ×100) and
	 * would need this revisited before supporting one.
	 */
	private static function to_minor_units( $amount ) {
		return (int) round( $amount * 100 );
	}
}
