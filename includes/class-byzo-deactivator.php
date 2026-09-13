<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fired on plugin deactivation. Deliberately does NOT drop tables or delete
 * options/bookings — that's uninstall.php's job, and only if the user
 * explicitly opts in (see uninstall.php). Deactivation must be non-destructive.
 */
class BYZO_Deactivator {

	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'byzo_generate_slots' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'byzo_generate_slots' );
		}

		$reminder_timestamp = wp_next_scheduled( 'byzo_send_reminders' );
		if ( $reminder_timestamp ) {
			wp_unschedule_event( $reminder_timestamp, 'byzo_send_reminders' );
		}

		$stale_pending_timestamp = wp_next_scheduled( 'byzo_release_stale_pending_bookings' );
		if ( $stale_pending_timestamp ) {
			wp_unschedule_event( $stale_pending_timestamp, 'byzo_release_stale_pending_bookings' );
		}

		flush_rewrite_rules();
	}
}
