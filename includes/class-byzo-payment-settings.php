<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gateway configuration (DETAILED-SPEC.md §A7), keyed per-gateway under
 * `gateways.<id>` rather than one flat `stripe_*` set of keys — so a
 * second gateway (Pro's PayPal, etc.) slots in as its own sub-array
 * without another shape change. Kept as its own option
 * (`byzo_payment_settings`), separate from `byzo_settings` (business
 * profile), since API secrets are a different sensitivity class from
 * general UI preferences.
 *
 * FEATURES.md §B6 has Pro running multiple gateways *simultaneously*
 * (not a single active-gateway switch), so each gateway only carries its
 * own `enabled` flag — there's no plugin-wide "active gateway" setting to
 * keep in sync.
 */
class BYZO_Payment_Settings {

	const OPTION = 'byzo_payment_settings';

	private static function defaults() {
		return array(
			'gateways' => array(
				'stripe' => array(
					'enabled'         => false,
					'publishable_key' => '',
					'secret_key'      => '',
					// Stripe's webhook SIGNING secret (whsec_...) — a different
					// value from the API secret key above, used only to verify
					// that an incoming /stripe-webhook POST really came from
					// Stripe (DETAILED-SPEC.md §4: "signature-verified, not
					// nonce-based"). Found via Stripe's dashboard when adding
					// the webhook endpoint, not the same secret used for API calls.
					'webhook_secret'  => '',
				),
			),
		);
	}

	public static function get() {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		if ( ! isset( $stored['gateways'] ) || ! is_array( $stored['gateways'] ) ) {
			$stored['gateways'] = array();
		}

		// Merge defaults into whatever's stored one gateway at a time — a
		// plain wp_parse_args() on the outer array wouldn't reach inside
		// `gateways.stripe` to fill in keys a future gateway addition
		// (or a partial old save) left missing.
		foreach ( $defaults['gateways'] as $id => $gateway_defaults ) {
			$stored['gateways'][ $id ] = wp_parse_args(
				isset( $stored['gateways'][ $id ] ) && is_array( $stored['gateways'][ $id ] ) ? $stored['gateways'][ $id ] : array(),
				$gateway_defaults
			);
		}

		return $stored;
	}

	/**
	 * @return array|WP_Error
	 */
	public static function update( $data ) {
		$current = self::get();

		if ( isset( $data['gateways']['stripe'] ) && is_array( $data['gateways']['stripe'] ) ) {
			$stripe = self::update_stripe( $current['gateways']['stripe'], $data['gateways']['stripe'] );
			if ( is_wp_error( $stripe ) ) {
				return $stripe;
			}
			$current['gateways']['stripe'] = $stripe;
		}

		update_option( self::OPTION, $current );

		return $current;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function update_stripe( $stripe, $data ) {
		if ( isset( $data['enabled'] ) ) {
			$stripe['enabled'] = (bool) $data['enabled'];
		}

		if ( isset( $data['publishable_key'] ) ) {
			$key = sanitize_text_field( $data['publishable_key'] );
			// Loose prefix check — catches the single most common mistake
			// (pasting the secret key into the publishable field, or
			// vice versa) without trying to fully validate Stripe's key
			// format, which isn't this plugin's job.
			if ( '' !== $key && 0 !== strpos( $key, 'pk_' ) ) {
				return new WP_Error( 'byzo_invalid_publishable_key', __( 'That doesn\'t look like a Stripe publishable key (should start with "pk_").', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$stripe['publishable_key'] = $key;
		}

		if ( isset( $data['secret_key'] ) ) {
			$key = sanitize_text_field( $data['secret_key'] );
			if ( '' !== $key && 0 !== strpos( $key, 'sk_' ) ) {
				return new WP_Error( 'byzo_invalid_secret_key', __( 'That doesn\'t look like a Stripe secret key (should start with "sk_").', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$stripe['secret_key'] = $key;
		}

		if ( isset( $data['webhook_secret'] ) ) {
			$key = sanitize_text_field( $data['webhook_secret'] );
			if ( '' !== $key && 0 !== strpos( $key, 'whsec_' ) ) {
				return new WP_Error( 'byzo_invalid_webhook_secret', __( 'That doesn\'t look like a Stripe webhook signing secret (should start with "whsec_").', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$stripe['webhook_secret'] = $key;
		}

		return $stripe;
	}

	public static function is_stripe_ready() {
		$stripe = self::get()['gateways']['stripe'];

		return $stripe['enabled'] && '' !== $stripe['publishable_key'] && '' !== $stripe['secret_key'];
	}
}
