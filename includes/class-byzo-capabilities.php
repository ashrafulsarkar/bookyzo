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
	 * Whether a downloadable PDF invoice is available from the customer
	 * dashboard (Phase 2 step 18, `FEATURES.md` B4) — genuinely Pro-
	 * exclusive infrastructure (a PDF-rendering library only Pro ships),
	 * not a re-skin of a Free feature, so a real gate is correct here.
	 * Free = false; the dashboard link is simply absent, never shown
	 * disabled.
	 */
	public static function pdf_invoice_enabled() {
		return (bool) apply_filters( 'byzo_pdf_invoice_enabled', false );
	}

	/**
	 * Whether a downloadable QR e-ticket is available from the customer
	 * dashboard (Phase 2 step 12 continued, `FEATURES.md` B7 Event) —
	 * same shape as pdf_invoice_enabled() above: the dashboard link is
	 * simply absent while false, never a dead/disabled link. Free = false.
	 */
	public static function qr_ticket_enabled() {
		return (bool) apply_filters( 'byzo_qr_ticket_enabled', false );
	}

	/**
	 * Whether anything on this site sends marketing email — the widget only
	 * asks for consent when there's something to consent to (QA report
	 * §4.6). Free never sends any; an add-on with campaigns hooks this.
	 *
	 * @return bool
	 */
	public static function marketing_enabled() {
		return (bool) apply_filters( 'byzo_marketing_enabled', false );
	}

	/**
	 * Whether a full slot can be waitlisted — the widget then shows full
	 * times as "Full — join the waitlist" instead of hiding them (QA report
	 * §6.2.6, FEATURES.md B2). Free has no waitlist; an add-on hooks this.
	 *
	 * @return bool
	 */
	public static function waitlist_enabled() {
		return (bool) apply_filters( 'byzo_waitlist_enabled', false );
	}

	/**
	 * Whether the booking form asks for a discount or package code (QA
	 * report §6.1 A2). Free has no codes; an add-on hooks this.
	 *
	 * @return bool
	 */
	public static function codes_enabled() {
		return (bool) apply_filters( 'byzo_codes_enabled', false );
	}

	/**
	 * Whether a WordPress user linked to a bookable item
	 * (`bookable_items.wp_user_id`) gets the staff view of the admin app —
	 * only their own items' bookings (QA report §6.2.9, FEATURES.md B1).
	 * Free has no way to link anyone; an add-on hooks this.
	 *
	 * @return bool
	 */
	public static function staff_logins_enabled() {
		return (bool) apply_filters( 'byzo_staff_logins_enabled', false );
	}
}
