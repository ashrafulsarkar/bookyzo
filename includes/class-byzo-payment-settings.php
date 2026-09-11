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

	/**
	 * Phase 2 step 6 (PayPal, DETAILED-SPEC.md §A7) widened this from a
	 * hardcoded single-gateway array to filter-driven — `stripe` stays
	 * Free's own hardcoded default (never touched by anything else), and
	 * `byzo_payment_gateway_defaults` is the extension point a Pro
	 * gateway's own default settings shape gets added through, same
	 * pattern as `byzo_payment_gateways` already registers the gateway
	 * itself. Free never hooks this filter, so this is a no-op change in
	 * Free alone.
	 */
	private static function defaults() {
		return array(
			'gateways' => apply_filters(
				'byzo_payment_gateway_defaults',
				array(
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
				)
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

		// Any OTHER registered gateway (Pro's PayPal, and any future
		// candidate) validates+merges through this filter instead of a
		// hardcoded method here — Free has no idea what fields a Pro
		// gateway even has. `BYZO_Payment_Gateways::exists()` guards
		// against writing settings for an id nothing actually registered
		// (e.g. Pro was just deactivated) — silently dropped, not an
		// error, same "the data just doesn't apply anymore" reasoning as
		// every other Pro-consumed-but-Free-schema field in this plugin.
		if ( isset( $data['gateways'] ) && is_array( $data['gateways'] ) ) {
			foreach ( $data['gateways'] as $gateway_id => $gateway_data ) {
				if ( 'stripe' === $gateway_id || ! is_array( $gateway_data ) || ! BYZO_Payment_Gateways::exists( $gateway_id ) ) {
					continue;
				}

				/**
				 * Filters (validates + merges) a non-Stripe gateway's
				 * settings patch. Must return the merged settings array for
				 * this gateway, or a WP_Error to reject the whole save.
				 *
				 * @param array|WP_Error $result     Passed in as the gateway's current stored settings; a hook should merge $gateway_data into it (or return WP_Error).
				 * @param string         $gateway_id
				 * @param array          $gateway_data Incoming patch.
				 */
				$updated = apply_filters(
					'byzo_validate_payment_gateway_settings',
					isset( $current['gateways'][ $gateway_id ] ) ? $current['gateways'][ $gateway_id ] : array(),
					$gateway_id,
					$gateway_data
				);

				if ( is_wp_error( $updated ) ) {
					return $updated;
				}

				$current['gateways'][ $gateway_id ] = $updated;
			}
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
