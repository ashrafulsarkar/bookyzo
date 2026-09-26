<?php
defined( 'ABSPATH' ) || exit;

/**
 * The plugin's filterable limits and extension switches, in one place.
 *
 * The two limits are real defaults of this plugin (one active item per
 * booking type, one opening-hours block per day); an add-on may raise them.
 * Every switch below turns on a place in the booking form or account page
 * where an add-on shows something of its own (a document link, a waitlist,
 * a code field). The feature itself lives in that add-on, so each default
 * is simply "nothing to show". DETAILED-SPEC.md §A9.
 */
class BYZO_Capabilities {

	/**
	 * How many bookable items of a single type may be active at once.
	 * Default 1 (FEATURES.md §A9); filterable via
	 * `byzo_max_bookable_items_per_type`.
	 */
	public static function max_bookable_items_per_type() {
		return (int) apply_filters( 'byzo_max_bookable_items_per_type', 1 );
	}

	/**
	 * How many shifts per day a bookable item's weekly schedule may have.
	 * Default 1 (FEATURES.md §A3); filterable via `byzo_max_shifts_per_day`.
	 * The admin only offers "+ Add shift" while a day is under it.
	 */
	public static function max_shifts_per_day() {
		return (int) apply_filters( 'byzo_max_shifts_per_day', 1 );
	}

	/**
	 * Whether a booking may be paid partly now, as a deposit. Default
	 * false: bookings are paid in full. An add-on that offers deposits
	 * turns this on and supplies the amount via `byzo_deposit_amount`.
	 */
	public static function deposits_enabled() {
		return (bool) apply_filters( 'byzo_deposits_enabled', false );
	}

	/**
	 * Whether the customer dashboard and emails link to a PDF invoice.
	 * Default false: this plugin makes no PDFs. An add-on that serves them
	 * turns this on; until then the link is simply absent.
	 */
	public static function pdf_invoice_enabled() {
		return (bool) apply_filters( 'byzo_pdf_invoice_enabled', false );
	}

	/**
	 * Whether the customer dashboard and emails link to a QR e-ticket.
	 * Default false: this plugin makes no tickets. An add-on that serves
	 * them turns this on; until then the link is simply absent.
	 */
	public static function qr_ticket_enabled() {
		return (bool) apply_filters( 'byzo_qr_ticket_enabled', false );
	}

	/**
	 * Whether anything on this site sends marketing email — the widget only
	 * asks for consent when there's something to consent to (QA report
	 * §4.6). This plugin sends none; an add-on with campaigns hooks this.
	 *
	 * @return bool
	 */
	public static function marketing_enabled() {
		return (bool) apply_filters( 'byzo_marketing_enabled', false );
	}

	/**
	 * Whether a full slot can be waitlisted — the widget then shows full
	 * times as "Full — join the waitlist" instead of hiding them (QA report
	 * §6.2.6, FEATURES.md B2). This plugin has no waitlist; an add-on hooks this.
	 *
	 * @return bool
	 */
	public static function waitlist_enabled() {
		return (bool) apply_filters( 'byzo_waitlist_enabled', false );
	}

	/**
	 * Whether the booking form asks for a discount or package code (QA
	 * report §6.1 A2). This plugin has no codes; an add-on hooks this.
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
	 * This plugin has no way to link anyone; an add-on hooks this.
	 *
	 * @return bool
	 */
	public static function staff_logins_enabled() {
		return (bool) apply_filters( 'byzo_staff_logins_enabled', false );
	}
}
