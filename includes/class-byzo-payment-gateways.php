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
					'description' => __( 'Card payments via Stripe', 'bookyzo' ),
					// Stripe's own brand purple — the Payments list's icon
					// badge is colored per gateway so the list reads at a
					// glance, same as any billing-settings UI. A future
					// gateway that doesn't set this falls back to a neutral
					// gray below rather than breaking the badge.
					'color'       => '#635bff',
				),
			)
		);

		// Defensive against a filter returning a malformed entry — the
		// Vue list indexes straight into `id`/`label`, so a gateway
		// missing either would render a blank/broken row.
		return array_filter(
			array_map(
				function ( $gateway ) {
					if ( ! is_array( $gateway ) ) {
						return $gateway;
					}
					return wp_parse_args( $gateway, array( 'color' => '#6b7280' ) );
				},
				$gateways
			),
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
