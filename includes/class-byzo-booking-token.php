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

	/**
	 * verify() when only the booking id is known — the shape a REST route
	 * with a `?token=` query arg has.
	 */
	public static function verify_for_booking( $booking_id, $token ) {
		$booking = BYZO_Booking::get( (int) $booking_id );

		return $booking && self::verify( $booking['id'], $booking['customer_email'], $token );
	}

	/**
	 * A guest-openable link to one of a booking's documents — the PDF
	 * invoice or the QR e-ticket (QA report 2026-09-22 B10: both used to be
	 * reachable only from a logged-in customer account, which is off by
	 * default, so most customers never got them). The token is the same
	 * one the "manage booking" link carries; the add-on that serves those
	 * routes checks it. Callers gate on BYZO_Capabilities first — this only
	 * builds the URL.
	 *
	 * @param array  $booking  A BYZO_Booking::get() row.
	 * @param string $document 'invoice' or 'ticket-qr'.
	 */
	public static function document_url( $booking, $document ) {
		return add_query_arg(
			'token',
			self::generate( $booking['id'], $booking['customer_email'] ),
			rest_url( 'bookyzo/v1/bookings/' . (int) $booking['id'] . '/' . $document )
		);
	}
}
