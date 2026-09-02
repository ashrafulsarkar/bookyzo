<?php
defined( 'ABSPATH' ) || exit;

/**
 * Free's basic reporting (DETAILED-SPEC.md §A8): total bookings count for
 * day/week/month, by status. CSV export is BYZO_Booking_Export (step 5) —
 * this class is just the dashboard's own aggregate-count summary.
 */
class BYZO_Reports {

	public static function get_dashboard_summary() {
		$tz  = wp_timezone();
		$now = new DateTime( 'now', $tz );

		$today_start = new DateTime( 'today', $tz );
		$today_end   = ( clone $today_start )->modify( '+1 day' );

		// Sunday-start week, matching src/lib/date.js's startOfWeek() —
		// the same convention the admin calendar already uses, so "this
		// week" means the same thing here as it does on the Calendar screen.
		$week_start = ( clone $today_start )->modify( '-' . $today_start->format( 'w' ) . ' days' );
		$week_end   = ( clone $week_start )->modify( '+7 days' );

		$month_start = new DateTime( $now->format( 'Y-m-01' ), $tz );
		$month_end   = ( clone $month_start )->modify( '+1 month' );

		return array(
			'today'      => BYZO_Booking::get_status_counts( $today_start, $today_end ),
			'this_week'  => BYZO_Booking::get_status_counts( $week_start, $week_end ),
			'this_month' => BYZO_Booking::get_status_counts( $month_start, $month_end ),
		);
	}

	/**
	 * Daily booking-count trend for the Dashboard chart, last $days days
	 * including today. Capped 7-90 — a chart with too few points isn't a
	 * trend, and too many makes the daily bars illegibly thin.
	 */
	public static function get_dashboard_trend( $days = 14 ) {
		$days = max( 7, min( 90, (int) $days ) );
		$tz   = wp_timezone();

		$today_start = new DateTime( 'today', $tz );
		$range_start = ( clone $today_start )->modify( '-' . ( $days - 1 ) . ' days' );
		$range_end   = ( clone $today_start )->modify( '+1 day' );

		return BYZO_Booking::get_daily_counts( $range_start, $range_end );
	}
}
