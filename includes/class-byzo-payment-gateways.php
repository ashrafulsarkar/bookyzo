<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registry of available payment gateways (FEATURES.md §A7/§B6). Free
 * registers only Stripe; Pro adds more (PayPal, etc.) via the
 * `byzo_payment_gateways` filter — the same capability-filter pattern as
 * BYZO_Capabilities, so the Free plugin never has to know a Pro gateway
 * exists to make room for it in the Payments settings UI. This is also
 * why the admin Payments screen is safe to build as a tab list now: with
 * only Stripe registered, it renders one tab, and gains more automatically
 * once something else hooks the filter — no dead/disabled tabs to show
 * in the meantime (see bookyzo-dev SKILL.md's Guideline-5 rule).
 */
class BYZO_Payment_Gateways {

	/**
	 * @return array<string, array{id:string,label:string,description:string}>
	 */
	public static function all() {
		$gateways = apply_filters(
			'byzo_payment_gateways',
			array(
				'stripe' => array(
					'id'          => 'stripe',
					'label'       => __( 'Stripe', 'bookyzo' ),
					'description' => __( 'Accept card payments online with Stripe.', 'bookyzo' ),
				),
			)
		);

		// Defensive against a filter returning a malformed entry — the
		// Vue tab list indexes straight into `id`/`label`, so a gateway
		// missing either would render a blank/broken tab.
		return array_filter(
			$gateways,
			function ( $gateway ) {
				return is_array( $gateway ) && ! empty( $gateway['id'] ) && ! empty( $gateway['label'] );
			}
		);
	}

	public static function exists( $id ) {
		$all = self::all();
		return isset( $all[ $id ] );
	}
}
