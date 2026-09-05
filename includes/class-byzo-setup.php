<?php
defined( 'ABSPATH' ) || exit;

/**
 * Setup wizard progress (DETAILED-SPEC.md §A1) — stored in `byzo_setup_step`
 * so an abandoned wizard resumes where the owner left off, and
 * `byzo_setup_skipped` for the explicit "Skip setup" escape hatch.
 */
class BYZO_Setup {

	// Deepened 2026-09-03 (DETAILED-SPEC.md §A1) from the old 3-step
	// version (booking_type -> business_profile -> first_bookable_item).
	const STEPS = array( 'welcome', 'booking_type', 'business_profile', 'booking_details', 'availability', 'customer_fields', 'pricing', 'confirmation', 'review', 'done' );

	public static function get_state() {
		return array(
			'step'    => get_option( 'byzo_setup_step', 'welcome' ),
			'skipped' => (bool) get_option( 'byzo_setup_skipped' ),
			'needed'  => BYZO_Install_Redirect::needs_setup(),
		);
	}

	/**
	 * Advances to a given step. Only allows known steps — an arbitrary
	 * string here would break the Vue wizard's own step-index lookup.
	 *
	 * @return array|WP_Error
	 */
	public static function set_step( $step ) {
		if ( ! in_array( $step, self::STEPS, true ) ) {
			return new WP_Error( 'byzo_invalid_step', __( 'Unknown setup step.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		update_option( 'byzo_setup_step', $step );

		if ( 'done' === $step ) {
			delete_option( 'byzo_setup_skipped' );
		}

		return self::get_state();
	}

	public static function skip() {
		update_option( 'byzo_setup_skipped', 1 );

		return self::get_state();
	}
}
