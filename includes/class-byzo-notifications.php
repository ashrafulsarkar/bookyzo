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
		$booking = BYZO_Booking::get( $booking_id );
		if ( ! $booking ) {
			return;
		}

		// byzo_settings.auto_confirm (DETAILED-SPEC.md §A4) can create a
		// booking already 'confirmed' rather than 'pending' — send the
		// matching template for whichever it actually is, rather than
		// always sending "request received" (which would be misleading
		// for a booking that was never pending in the first place).
		$event = 'confirmed' === $booking['status'] ? 'booking_confirmed' : 'booking_created';
		self::maybe_send_customer( $event, $booking_id );

		self::maybe_send_admin( $booking_id );
	}

	public static function on_status_changed( $booking_id, $new_status, $old_status ) {
		if ( 'confirmed' === $new_status ) {
			self::maybe_send_customer( 'booking_confirmed', $booking_id );
		} elseif ( 'cancelled' === $new_status ) {
			self::send( 'booking_cancelled', $booking_id );
		}
	}

	/**
	 * Gates the customer-facing "confirmation" emails (created + confirmed
	 * — the two that tell the customer their booking status) on
	 * byzo_settings.send_confirmation_email (default true, DETAILED-SPEC.md
	 * §A6). Cancellation and reminder stay unconditional — that toggle is
	 * specifically about the confirmation messages, not every email.
	 */
	private static function maybe_send_customer( $event, $booking_id ) {
		if ( empty( BYZO_Settings::get()['send_confirmation_email'] ) ) {
			return;
		}

		self::send( $event, $booking_id );
	}

	/**
	 * Sends the admin-facing "New booking received" notification (added
	 * for the deepened onboarding wizard, DETAILED-SPEC.md §A6) — gated by
	 * its own toggle, separate from the customer-facing one, and to
	 * get_option('admin_email') rather than the booking's customer_email.
	 */
	private static function maybe_send_admin( $booking_id ) {
		if ( empty( BYZO_Settings::get()['send_admin_notification'] ) ) {
			return;
		}

		self::send( 'admin_new_booking', $booking_id, get_option( 'admin_email' ) );
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

	/**
	 * @param string      $event
	 * @param int         $booking_id
	 * @param string|null $to Defaults to the booking's own customer_email —
	 *     pass an explicit address (e.g. get_option('admin_email')) for the
	 *     admin-facing notification, which isn't addressed to the customer.
	 */
	private static function send( $event, $booking_id, $to = null ) {
		$booking = BYZO_Booking::get( $booking_id );
		if ( ! $booking ) {
			return;
		}

		$template = BYZO_Email_Templates::get_one( $event );
		if ( ! $template ) {
			return;
		}

		$item     = BYZO_Bookable_Item::get( $booking['bookable_item_id'] );
		$settings = BYZO_Settings::get();

		// {cancel_link} (added Phase 1.5 step 7, DETAILED-SPEC.md §A4/§A5)
		// — only a real, working link while guest cancellation is actually
		// possible for this booking (cancellation_hours > 0, status still
		// pending/confirmed); otherwise left blank so a template that
		// includes it doesn't show a link that would just 403. The link's
		// own page re-checks everything again at click time regardless —
		// this is purely about not printing a link that's already known
		// to be dead at send time.
		$cancel_link = '';
		if ( (int) $settings['cancellation_hours'] > 0 && in_array( $booking['status'], array( 'pending', 'confirmed' ), true ) ) {
			$cancel_link = BYZO_Booking_Cancel::build_link( $booking_id, $booking['customer_email'] );
		}

		$placeholders = array(
			'{customer_name}'        => $booking['customer_name'],
			'{bookable_item_name}'   => $item ? $item['name'] : '',
			'{start_time}'           => self::format_start_time( $booking['slot_start'] ),
			'{business_name}'        => $settings['business_name'],
			'{cancel_link}'          => $cancel_link,
			'{add_to_calendar_link}' => self::google_calendar_link( $booking, $item ),
		);

		$subject = strtr( $template['subject'], $placeholders );
		$body    = strtr( $template['body'], $placeholders );

		$message = byzo_get_template( 'emails/notification.php', array( 'body' => $body ) );
		if ( '' === $message ) {
			$message = $body; // Template file missing/unreadable — fall back to the raw body rather than sending an empty email.
		}

		wp_mail( $to ?? $booking['customer_email'], $subject, $message );

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

	/**
	 * "Add to calendar" (DETAILED-SPEC.md §A6, Phase 1.5 step 9) — a
	 * Google Calendar render?action=TEMPLATE deep link, computed on the
	 * fly from the booking's own slot times, not stored. Empty when the
	 * booking has no slot (slot_id deleted, §1.4) — nothing to add. The
	 * confirmation-page equivalent (widget's StepConfirmation.vue) also
	 * offers a client-side .ics download; a downloadable file has no
	 * natural place in a plain-text email, so the email placeholder is
	 * this link only.
	 */
	private static function google_calendar_link( $booking, $item ) {
		if ( ! $booking['slot_start'] ) {
			return '';
		}

		$utc   = new DateTimeZone( 'UTC' );
		$start = ( new DateTime( $booking['slot_start'], wp_timezone() ) )->setTimezone( $utc );
		$end   = ( new DateTime( $booking['slot_end'] ?: $booking['slot_start'], wp_timezone() ) )->setTimezone( $utc );

		$params = array(
			'action' => 'TEMPLATE',
			'text'   => $item ? $item['name'] : __( 'Booking', 'bookyzo' ),
			'dates'  => $start->format( 'Ymd\THis\Z' ) . '/' . $end->format( 'Ymd\THis\Z' ),
		);

		return 'https://calendar.google.com/calendar/render?' . http_build_query( $params );
	}
}
