<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fired on plugin activation: creates the 4 core tables (DETAILED-SPEC.md §1)
 * and seeds default settings. Schema is intentionally the *full* Free+Pro
 * shape from day one (e.g. shift_group, location_id) — see ROADMAP.md's
 * "don't gate the data model, gate the UI/capability" principle.
 */
class BYZO_Activator {

	public static function activate() {
		self::create_tables();
		self::maybe_seed_default_settings();
		self::schedule_cron_events();

		update_option( 'byzo_db_version', BYZO_DB_VERSION );

		if ( false === get_option( 'byzo_setup_step' ) ) {
			add_option( 'byzo_setup_step', 'booking_type' );
			set_transient( 'byzo_activation_redirect', 1, 30 );
		}

		flush_rewrite_rules();
	}

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$sql = array();

		// wp_byzo_resources
		$sql[] = "CREATE TABLE {$prefix}byzo_resources (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(20) NOT NULL DEFAULT 'appointment',
			name VARCHAR(255) NOT NULL,
			description TEXT NULL,
			photo_id BIGINT UNSIGNED NULL,
			tags VARCHAR(255) NULL,
			capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			price DECIMAL(10,2) NULL,
			duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			location_id BIGINT UNSIGNED NULL,
			group_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY status (status)
		) {$charset_collate};";

		// wp_byzo_schedules (recurring weekly pattern)
		$sql[] = "CREATE TABLE {$prefix}byzo_schedules (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			resource_id BIGINT UNSIGNED NOT NULL,
			weekday TINYINT NOT NULL,
			start_time TIME NOT NULL,
			end_time TIME NOT NULL,
			shift_group TINYINT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY resource_id (resource_id),
			KEY weekday (weekday)
		) {$charset_collate};";

		// wp_byzo_availability_exceptions (blackout / holiday overrides)
		$sql[] = "CREATE TABLE {$prefix}byzo_availability_exceptions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			resource_id BIGINT UNSIGNED NULL,
			date DATE NOT NULL,
			is_closed TINYINT(1) NOT NULL DEFAULT 1,
			start_time TIME NULL,
			end_time TIME NULL,
			PRIMARY KEY  (id),
			KEY resource_id (resource_id),
			KEY date (date)
		) {$charset_collate};";

		// wp_byzo_slots (materialized bookable time slots)
		$sql[] = "CREATE TABLE {$prefix}byzo_slots (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			resource_id BIGINT UNSIGNED NOT NULL,
			start_datetime DATETIME NOT NULL,
			end_datetime DATETIME NOT NULL,
			capacity_remaining SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			PRIMARY KEY  (id),
			UNIQUE KEY resource_start (resource_id, start_datetime),
			KEY status (status)
		) {$charset_collate};";

		// wp_byzo_bookings (the actual booking record)
		$sql[] = "CREATE TABLE {$prefix}byzo_bookings (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slot_id BIGINT UNSIGNED NULL,
			resource_id BIGINT UNSIGNED NOT NULL,
			customer_name VARCHAR(255) NOT NULL,
			customer_email VARCHAR(255) NOT NULL,
			customer_phone VARCHAR(50) NULL,
			customer_notes TEXT NULL,
			party_size SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			payment_amount DECIMAL(10,2) NULL,
			custom_fields LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			cancelled_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY slot_id (slot_id),
			KEY resource_id (resource_id),
			KEY status (status),
			KEY customer_email (customer_email)
		) {$charset_collate};";

		foreach ( $sql as $table_sql ) {
			dbDelta( $table_sql );
		}
	}

	/**
	 * Registers the daily slot-generation cron and the reminder-email cron
	 * (the latter's handler ships in a later Phase 1 step — the schedule
	 * is reserved now so activation only ever has one place that touches
	 * wp_schedule_event).
	 */
	private static function schedule_cron_events() {
		if ( ! wp_next_scheduled( 'byzo_generate_slots' ) ) {
			wp_schedule_event( time(), 'daily', 'byzo_generate_slots' );
		}

		if ( ! wp_next_scheduled( 'byzo_send_reminders' ) ) {
			wp_schedule_event( time(), 'hourly', 'byzo_send_reminders' );
		}
	}

	/**
	 * Seeds `byzo_settings` with sane defaults on first activation only —
	 * never overwrites an existing option (safe to call on every activation).
	 */
	private static function maybe_seed_default_settings() {
		if ( false !== get_option( 'byzo_settings' ) ) {
			return;
		}

		$defaults = array(
			'business_name'       => get_bloginfo( 'name' ),
			'timezone'            => wp_timezone_string(),
			'currency'            => 'USD',
			'min_advance_hours'   => 2,
			'max_advance_days'    => 30,
			'buffer_minutes'      => 0,
			'default_start_time'  => '09:00',
			'default_end_time'    => '17:00',
		);

		/**
		 * Filters the default `byzo_settings` seeded on first activation.
		 * Pro does not need this filter today, but reserved so Pro's
		 * activation (running after Free's) could adjust defaults later
		 * without touching Free's activator.
		 */
		$defaults = apply_filters( 'byzo_default_settings', $defaults );

		add_option( 'byzo_settings', $defaults );
	}
}
