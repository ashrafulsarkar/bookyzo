<?php
/**
 * Fired only when the plugin is deleted from the Plugins screen (never on
 * plain deactivate). Destructive by nature, so it's opt-in: only runs the
 * actual cleanup if the site owner has explicitly enabled it in Settings —
 * silently keeping bookings/settings by default is the safer choice for a
 * booking plugin (losing customer booking history on an accidental
 * uninstall would be a real data-loss incident, not a minor inconvenience).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Role removal is unconditional, unlike the data purge below — it only
// removes the role definition (not any byzo_customer-role users or their
// wp_users/wp_usermeta rows), so it isn't the kind of data loss the
// delete_data_on_uninstall opt-in below exists to gate (Phase 1.7 step 1).
remove_role( 'byzo_customer' );

$settings = get_option( 'byzo_settings', array() );

if ( empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$tables = array(
	"{$wpdb->prefix}byzo_bookings",
	"{$wpdb->prefix}byzo_slots",
	"{$wpdb->prefix}byzo_availability_exceptions",
	"{$wpdb->prefix}byzo_schedules",
	"{$wpdb->prefix}byzo_bookable_items",
	"{$wpdb->prefix}byzo_customers",
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

$options = array(
	'byzo_settings',
	'byzo_db_version',
	'byzo_setup_step',
	'byzo_setup_skipped',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
