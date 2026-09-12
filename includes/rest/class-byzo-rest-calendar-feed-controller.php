<?php
defined( 'ABSPATH' ) || exit;

/**
 * /calendar-feed/{bookable_item_id}/{token} — a subscribable per-bookable-
 * item .ics feed (Phase 2 step 15, DETAILED-SPEC.md §3 B9). Public route:
 * the signed token IS the real gate, same "permission_callback always
 * true, the real auth is inside handle()" shape as the Stripe webhook —
 * a calendar app subscribing to this URL is never a logged-in WP user.
 * Computed live on every request, nothing materialized — a feed reader
 * polls this occasionally, not on every page load, so there's no
 * meaningful caching win worth the staleness risk.
 */
class BYZO_REST_Calendar_Feed_Controller extends BYZO_REST_Controller {

	protected function get_rest_base() {
		return 'calendar-feed';
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/' . $this->get_rest_base() . '/(?P<bookable_item_id>\d+)/(?P<token>[a-f0-9]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_feed' ),
				'permission_callback' => '__return_true',
			)
		);

		// A real fix, not cosmetic: WP_REST_Server::serve_request() ALWAYS
		// wp_json_encode()'s the response body before echoing it, no matter
		// what Content-Type header a controller sets — setting
		// 'text/calendar' only changes the declared MIME type, the body
		// still comes out as a JSON-quoted string (literal `\r\n` escapes,
		// wrapping quotes), which no real calendar app can parse as .ics.
		// The one documented way to serve raw non-JSON output from the
		// REST API is the `rest_pre_serve_request` filter — return true
		// (and echo the body yourself) to skip WP's own JSON encoding
		// entirely. Missing this was a real bug in the original
		// implementation, caught only by testing over a REAL HTTP request
		// (curl) rather than PHP-internal `rest_do_request()`, which never
		// exercises this serialization layer at all.
		add_filter( 'rest_pre_serve_request', array( $this, 'maybe_serve_raw_ics' ), 10, 3 );
	}

	public function maybe_serve_raw_ics( $served, $result, $request ) {
		if ( ! $result instanceof WP_REST_Response ) {
			return $served;
		}

		$content_type = $result->get_headers()['Content-Type'] ?? '';
		if ( false === strpos( $content_type, 'text/calendar' ) ) {
			return $served;
		}

		// WP_REST_Server::serve_request() already sent $result's headers
		// and status code (via send_headers()/set_status()) before this
		// filter ever runs — only the body needs to bypass wp_json_encode().
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw ICS text, not HTML.

		return true;
	}

	public function get_feed( WP_REST_Request $request ) {
		// Checked on every request, not just once at token-generation
		// time — a lapsed license must 403 even a previously-valid feed
		// URL immediately, not keep serving it until something re-checks.
		if ( ! BYZO_Capabilities::calendar_feed_enabled() ) {
			return new WP_Error( 'byzo_calendar_feed_not_available', __( 'This calendar feed is not available.', 'bookyzo' ), array( 'status' => 403 ) );
		}

		$bookable_item_id = (int) $request->get_param( 'bookable_item_id' );
		$token            = (string) $request->get_param( 'token' );

		if ( ! BYZO_Calendar_Feed_Token::verify( $bookable_item_id, $token ) ) {
			return new WP_Error( 'byzo_invalid_token', __( 'Invalid calendar feed link.', 'bookyzo' ), array( 'status' => 403 ) );
		}

		$item = BYZO_Bookable_Item::get( $bookable_item_id );
		if ( ! $item ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$ics = self::build_ics( $item );

		$response = new WP_REST_Response( $ics );
		$response->header( 'Content-Type', 'text/calendar; charset=utf-8' );
		$response->header( 'Content-Disposition', 'inline; filename="' . sanitize_file_name( $item['name'] ) . '.ics"' );

		return $response;
	}

	/**
	 * One VEVENT per confirmed (or completed) booking on this bookable
	 * item — cancelled/rejected/no_show bookings never held a real slot
	 * anyone needs to see on an external calendar.
	 */
	private static function build_ics( $item ) {
		$result   = BYZO_Booking::query(
			array(
				'bookable_item_id' => $item['id'],
				'status'           => 'confirmed,completed',
				'per_page'         => 100,
			)
		);
		$bookings = is_wp_error( $result ) ? array() : $result['items'];

		$lines   = array();
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//Bookyzo//Calendar Feed//EN';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'X-WR-CALNAME:' . self::escape_ics_text( $item['name'] );

		foreach ( $bookings as $booking ) {
			if ( ! $booking['slot_start'] ) {
				continue;
			}

			$start = new DateTime( $booking['slot_start'], wp_timezone() );
			$end   = $booking['slot_end'] ? new DateTime( $booking['slot_end'], wp_timezone() ) : ( clone $start )->modify( '+30 minutes' );

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:byzo-booking-' . $booking['id'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
			$lines[] = 'DTSTART:' . self::to_utc_ics( $start );
			$lines[] = 'DTEND:' . self::to_utc_ics( $end );
			$lines[] = 'SUMMARY:' . self::escape_ics_text( $booking['customer_name'] . ' — ' . $item['name'] );
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		// iCalendar requires CRLF line endings.
		return implode( "\r\n", $lines ) . "\r\n";
	}

	private static function to_utc_ics( DateTime $date ) {
		$utc = clone $date;
		$utc->setTimezone( new DateTimeZone( 'UTC' ) );
		return $utc->format( 'Ymd\THis\Z' );
	}

	private static function escape_ics_text( $text ) {
		return str_replace( array( '\\', ',', ';', "\n" ), array( '\\\\', '\\,', '\\;', '\\n' ), $text );
	}
}
