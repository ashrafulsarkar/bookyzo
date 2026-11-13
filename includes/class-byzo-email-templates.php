<?php
defined( 'ABSPATH' ) || exit;

/**
 * Free's editable notification content (DETAILED-SPEC.md §A6): plain
 * textarea subject/body per event, `{customer_name}`/`{bookable_item_name}`/
 * `{start_time}`/`{business_name}`/`{cancel_link}`/`{add_to_calendar_link}`
 * placeholders, no visual builder (Pro feature — this class's own shape
 * doesn't change for Pro, Pro adds a separate builder UI on top of the
 * same stored subject/body values, per the "don't gate the data model"
 * principle).
 */
class BYZO_Email_Templates {

	const OPTION = 'byzo_email_templates';

	// 'admin_new_booking' added 2026-09-03 (onboarding/dashboard deepening,
	// DETAILED-SPEC.md §A6) — the only one of the five sent to the site
	// admin (get_option('admin_email')) rather than the customer, gated by
	// byzo_settings.send_admin_notification instead of
	// send_confirmation_email (see BYZO_Notifications).
	// The two late_payment_* templates (1.0.3) go out when a payment arrives
	// after its booking's hold expired and the place was taken: one to the
	// admin (always — money has to go back), one to the customer.
	const EVENTS = array( 'booking_created', 'booking_confirmed', 'booking_cancelled', 'booking_reminder', 'admin_new_booking', 'late_payment_refund_due_admin', 'late_payment_refund_due_customer' );

	public static function get() {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );

		$merged = array();
		foreach ( self::EVENTS as $event ) {
			$merged[ $event ] = wp_parse_args( isset( $stored[ $event ] ) ? $stored[ $event ] : array(), $defaults[ $event ] );
		}

		return $merged;
	}

	/**
	 * @param array $data { [event] => { subject?, body? } } — only known
	 *     events and fields are accepted, everything else is ignored.
	 * @return array The full merged template set after the update.
	 */
	public static function update( $data ) {
		$current = self::get();

		foreach ( self::EVENTS as $event ) {
			if ( ! isset( $data[ $event ] ) || ! is_array( $data[ $event ] ) ) {
				continue;
			}

			if ( isset( $data[ $event ]['subject'] ) ) {
				$current[ $event ]['subject'] = sanitize_text_field( $data[ $event ]['subject'] );
			}

			if ( isset( $data[ $event ]['body'] ) ) {
				$current[ $event ]['body'] = sanitize_textarea_field( $data[ $event ]['body'] );
			}
		}

		update_option( self::OPTION, $current );

		return $current;
	}

	public static function get_one( $event ) {
		$all = self::get();

		return $all[ $event ] ?? null;
	}

	private static function defaults() {
		return array(
			'booking_created'   => array(
				'subject' => __( 'Your booking request has been received', 'bookyzo' ),
				'body'    => __( "Hi {customer_name},\n\nWe've received your booking request with {business_name} for {bookable_item_name} on {start_time}.\n\nBooking #{booking_id}\nHow many: {party_size}\nTotal: {total}\n{location}\n\nYou'll receive another email once it's confirmed.\n\nAdd to calendar: {add_to_calendar_link}\n\nNeed to cancel? {cancel_link}\n\nThanks,\n{business_name}", 'bookyzo' ),
			),
			'booking_confirmed' => array(
				'subject' => __( 'Your booking is confirmed', 'bookyzo' ),
				'body'    => __( "Hi {customer_name},\n\nGood news — your booking with {business_name} for {bookable_item_name} on {start_time} is confirmed.\n\nBooking #{booking_id}\nHow many: {party_size}\nTotal: {total}\n{location}\n\nAdd to calendar: {add_to_calendar_link}\n\nNeed to cancel? {cancel_link}\n\nSee you then!\n{business_name}", 'bookyzo' ),
			),
			'booking_cancelled' => array(
				'subject' => __( 'Your booking has been cancelled', 'bookyzo' ),
				'body'    => __( "Hi {customer_name},\n\nYour booking with {business_name} for {bookable_item_name} on {start_time} (booking #{booking_id}) has been cancelled.\n\nIf this wasn't expected, please get in touch.\n\n{business_name}", 'bookyzo' ),
			),
			'booking_reminder'  => array(
				'subject' => __( 'Reminder: your upcoming booking', 'bookyzo' ),
				'body'    => __( "Hi {customer_name},\n\nJust a reminder about your booking with {business_name} for {bookable_item_name} on {start_time}.\n\nBooking #{booking_id}\n{location}\n\nSee you soon!\n{business_name}", 'bookyzo' ),
			),
			'admin_new_booking' => array(
				'subject' => __( 'New booking received', 'bookyzo' ),
				'body'    => __( "Hi,\n\n{customer_name} just booked {bookable_item_name} for {start_time}.\n\nBooking #{booking_id}\nHow many: {party_size}\nTotal: {total}\nEmail: {customer_email}\nPhone: {customer_phone}\n\nOpen it in Bookyzo: {admin_booking_link}", 'bookyzo' ),
			),
			'late_payment_refund_due_admin'    => array(
				'subject' => __( 'Refund needed: payment arrived after booking #{booking_id} was released', 'bookyzo' ),
				'body'    => __( "Hi,\n\n{customer_name} paid {amount_paid} for {bookable_item_name} on {start_time} (booking #{booking_id}), but the payment arrived after the booking had already been cancelled, and the place is no longer free.\n\nThe booking is marked \"Refund due\". Please refund the payment with your payment provider (reference: {payment_reference}), then mark it refunded in Bookyzo.\n\nEmail: {customer_email}\nPhone: {customer_phone}\n\nOpen it in Bookyzo: {admin_booking_link}", 'bookyzo' ),
			),
			'late_payment_refund_due_customer' => array(
				'subject' => __( 'Your payment for {bookable_item_name} will be refunded', 'bookyzo' ),
				'body'    => __( "Hi {customer_name},\n\nThank you for your payment of {amount_paid} for {bookable_item_name} on {start_time} (booking #{booking_id}).\n\nUnfortunately it reached us after the time to complete the booking had run out, and that place has since been taken, so we can't keep this booking. Your payment will be refunded in full.\n\nWe're sorry for the trouble. If you'd like to book another time, just reply to this email.\n\n{business_name}", 'bookyzo' ),
			),
		);
	}
}
