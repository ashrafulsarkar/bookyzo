<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stripe gateway configuration (DETAILED-SPEC.md §A7) — kept as its own
 * option (`byzo_payment_settings`), separate from `byzo_settings`
 * (business profile), since API secrets are a different sensitivity
 * class from general UI preferences, not because the underlying REST
 * shape needs to differ.
 */
class BYZO_Payment_Settings {

	const OPTION = 'byzo_payment_settings';

	public static function get() {
		$defaults = array(
			'stripe_enabled'         => false,
			'stripe_publishable_key' => '',
			'stripe_secret_key'      => '',
			// Stripe's webhook SIGNING secret (whsec_...) — a different
			// value from the API secret key above, used only to verify
			// that an incoming /stripe-webhook POST really came from
			// Stripe (DETAILED-SPEC.md §4: "signature-verified, not
			// nonce-based"). Found via Stripe's dashboard when adding
			// the webhook endpoint, not the same secret used for API calls.
			'stripe_webhook_secret'  => '',
		);

		return wp_parse_args( get_option( self::OPTION, array() ), $defaults );
	}

	/**
	 * @return array|WP_Error
	 */
	public static function update( $data ) {
		$current = self::get();

		if ( isset( $data['stripe_enabled'] ) ) {
			$current['stripe_enabled'] = (bool) $data['stripe_enabled'];
		}

		if ( isset( $data['stripe_publishable_key'] ) ) {
			$key = sanitize_text_field( $data['stripe_publishable_key'] );
			// Loose prefix check — catches the single most common mistake
			// (pasting the secret key into the publishable field, or
			// vice versa) without trying to fully validate Stripe's key
			// format, which isn't this plugin's job.
			if ( '' !== $key && 0 !== strpos( $key, 'pk_' ) ) {
				return new WP_Error( 'byzo_invalid_publishable_key', __( 'That doesn\'t look like a Stripe publishable key (should start with "pk_").', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$current['stripe_publishable_key'] = $key;
		}

		if ( isset( $data['stripe_secret_key'] ) ) {
			$key = sanitize_text_field( $data['stripe_secret_key'] );
			if ( '' !== $key && 0 !== strpos( $key, 'sk_' ) ) {
				return new WP_Error( 'byzo_invalid_secret_key', __( 'That doesn\'t look like a Stripe secret key (should start with "sk_").', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$current['stripe_secret_key'] = $key;
		}

		if ( isset( $data['stripe_webhook_secret'] ) ) {
			$key = sanitize_text_field( $data['stripe_webhook_secret'] );
			if ( '' !== $key && 0 !== strpos( $key, 'whsec_' ) ) {
				return new WP_Error( 'byzo_invalid_webhook_secret', __( 'That doesn\'t look like a Stripe webhook signing secret (should start with "whsec_").', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$current['stripe_webhook_secret'] = $key;
		}

		update_option( self::OPTION, $current );

		return $current;
	}

	public static function is_stripe_ready() {
		$settings = self::get();

		return $settings['stripe_enabled'] && '' !== $settings['stripe_publishable_key'] && '' !== $settings['stripe_secret_key'];
	}
}
