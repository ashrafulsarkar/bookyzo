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
	 * How many bookable items of a single type Free allows active at once.
	 * Free = 1 (per FEATURES.md §A9). Pro overrides via the
	 * `byzo_max_bookable_items_per_type` filter to return an unlimited value.
	 */
	public static function max_bookable_items_per_type() {
		return (int) apply_filters( 'byzo_max_bookable_items_per_type', 1 );
	}

	/**
	 * How many shifts per day a bookable item's weekly schedule may have.
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

	/**
	 * Whether a bookable item may link its schedule to the shared
	 * business-hours default (`bookable_items.uses_business_hours`)
	 * instead of keeping its own independent weekly schedule. Free =
	 * false (FEATURES.md §B2 "per-staff individual schedules that differ
	 * from the business default" — Free's own per-item schedules already
	 * differ independently; this flag is about explicitly following the
	 * business default, a Pro-only convenience for staff who DON'T
	 * differ). Pro overrides via `byzo_business_hours_link_enabled`.
	 */
	public static function business_hours_link_enabled() {
		return (bool) apply_filters( 'byzo_business_hours_link_enabled', false );
	}

	/**
	 * Whether the subscribable per-bookable-item `.ics` calendar feed
	 * (Phase 2 step 15, `FEATURES.md` B9) is reachable at all — a genuine
	 * capability gate (unlike e.g. `internal_notes`/commission tracking,
	 * which have no Free/Pro behavioral difference to gate): this is a
	 * business-scale "subscribe your whole bookable item's schedule into
	 * an external tool" integration, not a single customer's own
	 * "add this one booking to my calendar" convenience (that one's
	 * already Free, see A6). Free = false. `BYZO_REST_Calendar_Feed_Controller`
	 * checks this on every request, not just at token-generation time, so
	 * a lapsed license immediately 403s even a previously-valid feed URL.
	 */
	public static function calendar_feed_enabled() {
		return (bool) apply_filters( 'byzo_calendar_feed_enabled', false );
	}

	/**
	 * Whether a downloadable PDF invoice is available from the customer
	 * dashboard (Phase 2 step 18, `FEATURES.md` B4) — genuinely Pro-
	 * exclusive infrastructure (a PDF-rendering library only Pro ships),
	 * not a re-skin of a Free feature, so a real gate is correct here,
	 * same class of decision as `calendar_feed_enabled()` above. Free =
	 * false; the dashboard link is simply absent, never shown disabled.
	 */
	public static function pdf_invoice_enabled() {
		return (bool) apply_filters( 'byzo_pdf_invoice_enabled', false );
	}
}
