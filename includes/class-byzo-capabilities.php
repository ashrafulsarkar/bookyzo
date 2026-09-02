<?php
defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for every Free/Pro limit. Pro overrides these via
 * filters — never by patching Free's code or scattering `if free` checks
 * elsewhere. See bookyzo-dev SKILL.md "the one rule that matters most" and
 * DETAILED-SPEC.md §A9.
 */
class BYZO_Capabilities {

	/**
	 * How many resources of a single type Free allows active at once.
	 * Free = 1 (per FEATURES.md §A9). Pro overrides via the
	 * `byzo_max_resources_per_type` filter to return an unlimited value.
	 */
	public static function max_resources_per_type() {
		return (int) apply_filters( 'byzo_max_resources_per_type', 1 );
	}

	/**
	 * How many resource *types* (appointment/table/event/slot) can be
	 * active simultaneously. Free = 1 (FEATURES.md §A2). Pro overrides to
	 * allow all types at once (FEATURES.md §B1).
	 */
	public static function max_active_resource_types() {
		return (int) apply_filters( 'byzo_max_active_resource_types', 1 );
	}

	/**
	 * How many shifts per day a resource's weekly schedule may have.
	 * Free = 1 (FEATURES.md §A3). Pro overrides via
	 * `byzo_max_shifts_per_day` — the schema (`shift_group` column)
	 * already supports this in Free, only the UI/generation logic is gated.
	 */
	public static function max_shifts_per_day() {
		return (int) apply_filters( 'byzo_max_shifts_per_day', 1 );
	}

	/**
	 * Whether deposits/partial payments are available. Free = false
	 * (FEATURES.md §A7 — full amount or nothing on Free).
	 */
	public static function deposits_enabled() {
		return (bool) apply_filters( 'byzo_deposits_enabled', false );
	}
}
