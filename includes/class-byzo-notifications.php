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

	/** How long before a booking the built-in reminder email goes out. */
	const REMINDER_HOURS_BEFORE = 24;

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
	 * Hourly cron: every booking whose 24-hours-before mark has passed and
	 * that hasn't been reminded yet (BYZO_Booking::get_due_for_reminder()).
	 * A late cron run catches up rather than skipping those bookings, which
	 * an exact one-hour window used to do on any site whose WP-Cron only
	 * fires when someone visits. reminder_sent_at is the duplicate guard.
	 */
	public static function send_reminders() {
		/**
		 * Filters whether this built-in 24-hours-before email reminder is
		 * sent at all. An add-on that sends its own email reminders turns
		 * it off, so the customer isn't emailed the same reminder twice.
		 *
		 * @param bool $send Default true.
		 */
		if ( ! apply_filters( 'byzo_send_default_email_reminder', true ) ) {
			return;
		}

		$due = BYZO_Booking::get_due_for_reminder( new DateTime( 'now', wp_timezone() ), self::REMINDER_HOURS_BEFORE );

		foreach ( $due as $booking ) {
			self::send( 'booking_reminder', $booking['id'] );
			BYZO_Booking::mark_reminder_sent( $booking['id'] );
		}
	}

	/**
	 * The `{placeholder}` substitution values for one booking — extracted
	 * out of send() (Phase 2 step 7) and made public so
	 * `bookyzo-pro`'s reminder cron can build SMS/WhatsApp messages using
	 * the exact same values email uses, without going through send()
	 * itself (which would also unconditionally fire an email — wrong for
	 * a reminder rule whose channels don't include 'email').
	 *
	 * @return array<string,string>
	 */
	public static function build_placeholders( $booking ) {
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
			$cancel_link = BYZO_Booking_Cancel::build_link( $booking['id'], $booking['customer_email'] );
		}

		return array(
			'{customer_name}'        => $booking['customer_name'],
			'{bookable_item_name}'   => $item ? $item['name'] : '',
			// Added 2026-09-22 (QA report §4.4) — the facts customers and
			// staff kept having to look up elsewhere: which booking, how
			// many, how much, and (for the admin) where to open it.
			'{booking_id}'           => (string) $booking['id'],
			'{party_size}'           => (string) $booking['party_size'],
			'{customer_email}'       => $booking['customer_email'],
			'{customer_phone}'       => (string) $booking['customer_phone'],
			'{total}'                => self::format_total( $booking ),
			'{admin_booking_link}'   => admin_url( 'admin.php?page=bookyzo-bookings' ),
			'{start_time}'           => self::format_start_time( $booking['slot_start'] ),
			'{business_name}'        => $settings['business_name'],
			'{cancel_link}'          => $cancel_link,
			'{add_to_calendar_link}' => self::google_calendar_link( $booking, $item ),
			// Added 2026-09-05 (Phase 1.6, FEATURES.md A2/A6) — blank when
			// the bookable item hasn't set one, same as every other
			// optional placeholder here.
			'{location}'             => $item && ! empty( $item['location'] ) ? $item['location'] : '',
			'{meeting_link}'         => $item && ! empty( $item['meeting_link'] ) ? $item['meeting_link'] : '',
			// Added 2026-09-22 (QA report B10) — blank unless the capability
			// is on and the booking has one (an e-ticket for a confirmed
			// event booking, an invoice for anything with a price).
			'{ticket_link}'          => self::document_link( $booking, $item, 'ticket-qr' ),
			'{invoice_link}'         => self::document_link( $booking, $item, 'invoice' ),
		);
	}

	/**
	 * The booking's own total, in the store currency — blank for a booking
	 * with nothing to charge, so a template line reading "Total: {total}"
	 * doesn't print "Total: $0.00" on a free booking.
	 */
	private static function format_total( $booking ) {
		if ( null === $booking['payment_amount'] ) {
			return '';
		}

		$currency = BYZO_Settings::get()['currency'];
		$amount   = number_format( (float) $booking['payment_amount'], 2 );

		return $currency . ' ' . $amount;
	}

	private static function document_link( $booking, $item, $document ) {
		if ( 'ticket-qr' === $document ) {
			$available = BYZO_Capabilities::qr_ticket_enabled() && $item && 'event' === $item['type'] && 'confirmed' === $booking['status'];
		} else {
			$available = BYZO_Capabilities::pdf_invoice_enabled() && null !== $booking['payment_amount'] && in_array( $booking['status'], array( 'pending', 'confirmed', 'completed' ), true );
		}

		return $available ? BYZO_Booking_Token::document_url( $booking, $document ) : '';
	}

	/**
	 * The confirmation email carries the e-ticket and invoice links even
	 * when the site's template predates those placeholders — otherwise a
	 * guest (no customer account) had no way to get either. A template that
	 * places {ticket_link}/{invoice_link} itself is left exactly as written.
	 */
	private static function append_document_links( $event, $template_body, $body, $placeholders ) {
		if ( 'booking_confirmed' !== $event ) {
			return $body;
		}

		$lines = array(
			/* translators: %s: link to the booking's QR e-ticket */
			'{ticket_link}'  => __( 'Your e-ticket: %s', 'bookyzo' ),
			/* translators: %s: link to the booking's PDF invoice */
			'{invoice_link}' => __( 'Your invoice: %s', 'bookyzo' ),
		);

		foreach ( $lines as $placeholder => $line ) {
			if ( '' !== $placeholders[ $placeholder ] && false === strpos( $template_body, $placeholder ) ) {
				$body .= "\n\n" . sprintf( $line, $placeholders[ $placeholder ] );
			}
		}

		return $body;
	}

	/**
	 * Public (Phase 2 step 7) so `bookyzo-pro`'s rule-based reminder cron
	 * can send the 'email' channel of a custom-offset reminder rule
	 * through this exact same template/placeholder/wp_mail() path — never
	 * duplicated in Pro. Every OTHER caller is still internal to this
	 * class; nothing about making this callable externally changes what
	 * it does.
	 *
	 * @param string      $event
	 * @param int         $booking_id
	 * @param string|null $to Defaults to the booking's own customer_email —
	 *     pass an explicit address (e.g. get_option('admin_email')) for the
	 *     admin-facing notification, which isn't addressed to the customer.
	 */
	public static function send( $event, $booking_id, $to = null ) {
		$booking = BYZO_Booking::get( $booking_id );
		if ( ! $booking ) {
			return;
		}

		$template = BYZO_Email_Templates::get_one( $event );
		if ( ! $template ) {
			return;
		}

		$placeholders = self::build_placeholders( $booking );

		$subject = strtr( $template['subject'], $placeholders );
		$body    = self::append_document_links( $event, $template['body'], strtr( $template['body'], $placeholders ), $placeholders );

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

		/**
		 * Phase 2 step 7 (DETAILED-SPEC.md §A6) — lets Pro send this same
		 * event via SMS/WhatsApp using the exact placeholders already
		 * built for the email above, without Free knowing SMS/WhatsApp
		 * exist. Free never hooks this itself. Deliberately fired for
		 * every event this method handles (not just the 3 Pro actually
		 * uses) — Pro's own listener decides which events it cares about,
		 * same "the filter fires unconditionally, the consumer opts in"
		 * shape as every other Free→Pro extension point in this plugin.
		 *
		 * @param string $event
		 * @param array  $booking
		 * @param array  $placeholders
		 */
		do_action( 'byzo_notification_channels', $event, $booking, $placeholders );
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
