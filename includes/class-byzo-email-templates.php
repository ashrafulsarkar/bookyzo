<?php
defined( 'ABSPATH' ) || exit;

/**
 * Free's editable notification content (DETAILED-SPEC.md §A6): plain
 * textarea subject/body per event, `{customer_name}`/`{resource_name}`/
 * `{start_time}`/`{business_name}` placeholders, no visual builder (Pro
 * feature — this class's own shape doesn't change for Pro, Pro adds a
 * separate builder UI on top of the same stored subject/body values,
 * per the "don't gate the data model" principle).
 */
class BYZO_Email_Templates {

	const OPTION = 'byzo_email_templates';

	const EVENTS = array( 'booking_created', 'booking_confirmed', 'booking_cancelled', 'booking_reminder' );

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
				'body'    => __( "Hi {customer_name},\n\nWe've received your booking request with {business_name} for {resource_name} on {start_time}.\n\nYou'll receive another email once it's confirmed.\n\nThanks,\n{business_name}", 'bookyzo' ),
			),
			'booking_confirmed' => array(
				'subject' => __( 'Your booking is confirmed', 'bookyzo' ),
				'body'    => __( "Hi {customer_name},\n\nGood news — your booking with {business_name} for {resource_name} on {start_time} is confirmed.\n\nSee you then!\n{business_name}", 'bookyzo' ),
			),
			'booking_cancelled' => array(
				'subject' => __( 'Your booking has been cancelled', 'bookyzo' ),
				'body'    => __( "Hi {customer_name},\n\nYour booking with {business_name} for {resource_name} on {start_time} has been cancelled.\n\nIf this wasn't expected, please get in touch.\n\n{business_name}", 'bookyzo' ),
			),
			'booking_reminder'  => array(
				'subject' => __( 'Reminder: your booking is tomorrow', 'bookyzo' ),
				'body'    => __( "Hi {customer_name},\n\nJust a reminder about your booking with {business_name} for {resource_name} on {start_time}.\n\nSee you soon!\n{business_name}", 'bookyzo' ),
			),
		);
	}
}
