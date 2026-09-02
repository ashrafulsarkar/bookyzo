<?php
defined( 'ABSPATH' ) || exit;

/**
 * Signed self-service token for a booking (DETAILED-SPEC.md §A5): "a
 * booking is identified by customer_email + a signed token in the
 * confirmation email/URL for any future self-service links (Pro
 * dashboard needs this same token mechanism, so build it in Free even
 * though Free has no dashboard to use it yet)."
 *
 * Stateless HMAC, not a stored/DB value — recomputing and comparing is
 * cheap and needs no schema column, migration, or expiry-cleanup job.
 * Uses wp_salt('auth') as the HMAC secret: a built-in WP secret rather
 * than one this plugin would otherwise need to generate and store
 * itself; the one accepted tradeoff is that rotating AUTH_SALT (a rare,
 * administrative action) invalidates all outstanding tokens at once.
 */
class BYZO_Booking_Token {

	public static function generate( $booking_id, $customer_email ) {
		return substr( hash_hmac( 'sha256', $booking_id . '|' . strtolower( trim( $customer_email ) ), wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function verify( $booking_id, $customer_email, $token ) {
		if ( empty( $token ) ) {
			return false;
		}

		return hash_equals( self::generate( $booking_id, $customer_email ), (string) $token );
	}
}
