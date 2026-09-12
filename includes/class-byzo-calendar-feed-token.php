<?php
defined( 'ABSPATH' ) || exit;

/**
 * Signed token for the subscribable per-bookable-item `.ics` calendar
 * feed (Phase 2 step 15, DETAILED-SPEC.md §3 B9) — a sibling to
 * BYZO_Booking_Token, not a reuse of it: that token's scope is one
 * specific booking + the customer's own email; this one's scope is an
 * entire bookable item's ongoing schedule, a meaningfully different
 * (and longer-lived, business-facing) grant. Same stateless-HMAC
 * shape either way — no DB row, no expiry-cleanup job.
 */
class BYZO_Calendar_Feed_Token {

	public static function generate( $bookable_item_id ) {
		return substr( hash_hmac( 'sha256', 'calendar-feed|' . $bookable_item_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function verify( $bookable_item_id, $token ) {
		if ( empty( $token ) ) {
			return false;
		}

		return hash_equals( self::generate( $bookable_item_id ), (string) $token );
	}
}
