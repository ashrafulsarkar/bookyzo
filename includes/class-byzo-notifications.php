<?php
defined( 'ABSPATH' ) || exit;

/**
 * Transactional booking emails (DETAILED-SPEC.md §A6) — hooks the events
 * BYZO_Booking already fires (byzo_booking_created on create(),
 * byzo_booking_status_changed on every update_status() transition) plus
 * the byzo_send_reminders cron BYZO_Activator already schedules hourly.
 * Plain text only on Free (no HTML wrapper/visual builder — that's a
 * Pro feature; see templates/emails/notification.php).
 */
class BYZO_Notifications {

	public static function init() {
		add_action( 'byzo_booking_created', array( __CLASS__, 'on_booking_created' ) );
		add_action( 'byzo_booking_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
		add_action( 'byzo_send_reminders', array( __CLASS__, 'send_reminders' ) );
	}

	public static function on_booking_created( $booking_id ) {
		self::send( 'booking_created', $booking_id );
	}

	public static function on_status_changed( $booking_id, $new_status, $old_status ) {
		if ( 'confirmed' === $new_status ) {
			self::send( 'booking_confirmed', $booking_id );
		} elseif ( 'cancelled' === $new_status ) {
			self::send( 'booking_cancelled', $booking_id );
		}
	}

	/**
	 * Hourly cron: finds bookings whose slot starts in exactly 24h,
	 * matched to the hour (so each booking is only ever picked up once,
	 * during the single hourly run whose window its 24h-before mark
	 * falls into) rather than a loose ">=24h and <25h from right now"
	 * check that would re-match on every subsequent run within that
	 * hour. reminder_sent_at is still the actual duplicate-send guard —
	 * the hour-matching is what keeps that column from ever needing to
	 * catch a real duplicate in normal operation.
	 */
	public static function send_reminders() {
		$now = new DateTime( 'now', wp_timezone() );
		$target = ( clone $now )->modify( '+24 hours' );
		$target->setTime( (int) $target->format( 'H' ), 0, 0 );

		$window_end = ( clone $target )->modify( '+1 hour' );

		$due = BYZO_Booking::get_due_for_reminder( $target, $window_end );

		foreach ( $due as $booking ) {
			self::send( 'booking_reminder', $booking['id'] );
			BYZO_Booking::mark_reminder_sent( $booking['id'] );
		}
	}

	private static function send( $event, $booking_id ) {
		$booking = BYZO_Booking::get( $booking_id );
		if ( ! $booking ) {
			return;
		}

		$template = BYZO_Email_Templates::get_one( $event );
		if ( ! $template ) {
			return;
		}

		$resource = BYZO_Resource::get( $booking['resource_id'] );
		$settings = BYZO_Settings::get();

		$placeholders = array(
			'{customer_name}' => $booking['customer_name'],
			'{resource_name}' => $resource ? $resource['name'] : '',
			'{start_time}'    => self::format_start_time( $booking['slot_start'] ),
			'{business_name}' => $settings['business_name'],
		);

		$subject = strtr( $template['subject'], $placeholders );
		$body    = strtr( $template['body'], $placeholders );

		$message = byzo_get_template( 'emails/notification.php', array( 'body' => $body ) );
		if ( '' === $message ) {
			$message = $body; // Template file missing/unreadable — fall back to the raw body rather than sending an empty email.
		}

		wp_mail( $booking['customer_email'], $subject, $message );

		/**
		 * Fires after a notification email is sent (or attempted —
		 * wp_mail()'s own return value isn't checked here, matching how
		 * WordPress core generally treats mail delivery as fire-and-forget
		 * rather than blocking the booking flow on an SMTP round trip).
		 */
		do_action( 'byzo_notification_sent', $event, $booking_id );
	}

	private static function format_start_time( $slot_start ) {
		if ( ! $slot_start ) {
			return '';
		}

		$date = new DateTime( $slot_start, wp_timezone() );

		return $date->format( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}
}
