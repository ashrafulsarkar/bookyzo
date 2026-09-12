<?php
defined( 'ABSPATH' ) || exit;

/**
 * Signed token for the QR e-ticket / check-in scanner (Phase 2 step 12
 * continued, DETAILED-SPEC.md §3 B7 Event) — a sibling to
 * BYZO_Booking_Token/BYZO_Calendar_Feed_Token, not a reuse of either:
 * scoped to just `booking_id`, deliberately WITHOUT the customer's email
 * BYZO_Booking_Token requires — a host scanning tickets at the door has
 * no reason to also need to know (or type in) the customer's email.
 * Same stateless-HMAC shape either way — no DB row, no expiry-cleanup job.
 */
class BYZO_Ticket_Token {

	public static function generate( $booking_id ) {
		return substr( hash_hmac( 'sha256', 'ticket-checkin|' . $booking_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function verify( $booking_id, $token ) {
		if ( empty( $token ) ) {
			return false;
		}

		return hash_equals( self::generate( $booking_id ), (string) $token );
	}
}
