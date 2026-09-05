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
			add_option( 'byzo_setup_step', 'welcome' );
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

		// wp_byzo_bookable_items — renamed from wp_byzo_resources 2026-09-03
		// (ROADMAP.md's naming pass: "resource" read as an unclear, overly
		// technical term to end users). See maybe_rename_legacy_schema()
		// below for the one-time migration of any install that already has
		// the old table/columns. `location`/`meeting_link` added 2026-09-05
		// (Phase 1.6, DETAILED-SPEC.md §1.1) — free-text, distinct from
		// `location_id` above (Pro's structured multi-branch FK).
		$sql[] = "CREATE TABLE {$prefix}byzo_bookable_items (
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
			location VARCHAR(255) NULL,
			meeting_link VARCHAR(500) NULL,
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
			bookable_item_id BIGINT UNSIGNED NOT NULL,
			weekday TINYINT NOT NULL,
			start_time TIME NOT NULL,
			end_time TIME NOT NULL,
			shift_group TINYINT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY bookable_item_id (bookable_item_id),
			KEY weekday (weekday)
		) {$charset_collate};";

		// wp_byzo_availability_exceptions (blackout / holiday overrides)
		$sql[] = "CREATE TABLE {$prefix}byzo_availability_exceptions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bookable_item_id BIGINT UNSIGNED NULL,
			date DATE NOT NULL,
			is_closed TINYINT(1) NOT NULL DEFAULT 1,
			start_time TIME NULL,
			end_time TIME NULL,
			PRIMARY KEY  (id),
			KEY bookable_item_id (bookable_item_id),
			KEY date (date)
		) {$charset_collate};";

		// wp_byzo_slots (materialized bookable time slots)
		$sql[] = "CREATE TABLE {$prefix}byzo_slots (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bookable_item_id BIGINT UNSIGNED NOT NULL,
			start_datetime DATETIME NOT NULL,
			end_datetime DATETIME NOT NULL,
			capacity_remaining SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			PRIMARY KEY  (id),
			UNIQUE KEY bookable_item_start (bookable_item_id, start_datetime),
			KEY status (status)
		) {$charset_collate};";

		// wp_byzo_bookings (the actual booking record). customer_id and
		// tax_amount added 2026-09-05 (Phase 1.5 step 9, competitor
		// gap-fill, DETAILED-SPEC.md §1.4) — dbDelta() ADD COLUMNs these
		// automatically on an existing install once BYZO_DB_VERSION moves
		// past 1.3, no manual ALTER needed (unlike maybe_rename_legacy_schema()'s
		// RENAME, which dbDelta genuinely can't do). `rescheduled_at`,
		// `completed_at`, and `addons` added 2026-09-05 (Phase 1.6 /
		// Universal-Booking-Plugin gap audit, DETAILED-SPEC.md §1.4) —
		// `addons` is reserved now but unused until Pro's add-on feature
		// (B6, `wp_byzo_addons`) actually writes to it, same "don't gate
		// the data model" pattern as `custom_fields` below.
		$sql[] = "CREATE TABLE {$prefix}byzo_bookings (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slot_id BIGINT UNSIGNED NULL,
			bookable_item_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED NULL,
			customer_name VARCHAR(255) NOT NULL,
			customer_email VARCHAR(255) NOT NULL,
			customer_phone VARCHAR(50) NULL,
			customer_notes TEXT NULL,
			party_size SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			payment_amount DECIMAL(10,2) NULL,
			tax_amount DECIMAL(10,2) NULL,
			custom_fields LONGTEXT NULL,
			addons LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			cancelled_at DATETIME NULL,
			completed_at DATETIME NULL,
			rescheduled_at DATETIME NULL,
			reminder_sent_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY slot_id (slot_id),
			KEY bookable_item_id (bookable_item_id),
			KEY status (status),
			KEY customer_email (customer_email),
			KEY customer_id (customer_id)
		) {$charset_collate};";

		// wp_byzo_customers (added 2026-09-05, Phase 1.5 step 9,
		// DETAILED-SPEC.md §1.5) — Free-owned, not Pro-gated: a customer
		// must be able to exist independently of any booking for CSV
		// import (A10) to make sense. Upserted by email on every booking
		// creation (BYZO_Customer::upsert_by_email(), called from
		// BYZO_Booking::create()) and directly by A10's CSV import.
		$sql[] = "CREATE TABLE {$prefix}byzo_customers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			email VARCHAR(255) NOT NULL,
			phone VARCHAR(50) NULL,
			notes TEXT NULL,
			tags VARCHAR(255) NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'booking',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) {$charset_collate};";

		self::maybe_rename_legacy_schema( $prefix );

		foreach ( $sql as $table_sql ) {
			dbDelta( $table_sql );
		}
	}

	/**
	 * One-time migration for any install created before the 2026-09-03
	 * "resource" -> "bookable item" rename (BYZO_DB_VERSION 1.3). dbDelta()
	 * only ever ADDS tables/columns — it cannot rename an existing one, so
	 * without this step a pre-rename install would end up with an orphaned
	 * `byzo_resources` table plus a brand new, empty `byzo_bookable_items`
	 * table sitting next to it. Guarded on the OLD table's existence so it
	 * runs at most once and is a no-op on both a fresh install and an
	 * already-migrated one.
	 */
	private static function maybe_rename_legacy_schema( $prefix ) {
		global $wpdb;

		$old_table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . 'byzo_resources' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( ! $old_table_exists ) {
			return;
		}

		$wpdb->query( "RENAME TABLE {$prefix}byzo_resources TO {$prefix}byzo_bookable_items" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( array( 'byzo_schedules', 'byzo_availability_exceptions', 'byzo_slots', 'byzo_bookings' ) as $table ) {
			$wpdb->query( "ALTER TABLE {$prefix}{$table} CHANGE COLUMN resource_id bookable_item_id BIGINT UNSIGNED " . ( 'byzo_availability_exceptions' === $table ? 'NULL' : 'NOT NULL' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table is one of 4 hardcoded literals in the loop above, never user input; identifiers can't be $wpdb->prepare() placeholders anyway.
		}

		// Index names don't follow CHANGE COLUMN automatically — rename them
		// too so a fresh install and a migrated one end up byte-identical.
		// `RENAME INDEX` (MySQL 5.7+ / MariaDB 10.5.2+) isn't available on
		// every supported target (this dev environment runs MariaDB 10.4),
		// so DROP + re-ADD is the portable equivalent.
		self::rename_index( "{$prefix}byzo_schedules", 'resource_id', 'bookable_item_id', '(bookable_item_id)' );
		self::rename_index( "{$prefix}byzo_availability_exceptions", 'resource_id', 'bookable_item_id', '(bookable_item_id)' );
		self::rename_index( "{$prefix}byzo_slots", 'resource_start', 'bookable_item_start', '(bookable_item_id, start_datetime)', true );
		self::rename_index( "{$prefix}byzo_bookings", 'resource_id', 'bookable_item_id', '(bookable_item_id)' );
	}

	/**
	 * Portable index rename (DROP + re-ADD) — see maybe_rename_legacy_schema()
	 * for why RENAME INDEX isn't used. Guarded on the OLD index name still
	 * existing, so repeated activation calls stay a no-op once migrated.
	 */
	private static function rename_index( $table, $old_name, $new_name, $columns_sql, $unique = false ) {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s', $old_name ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( ! $exists ) {
			return;
		}

		$wpdb->query( "ALTER TABLE {$table} DROP INDEX {$old_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- all 4 call sites in maybe_rename_legacy_schema() pass hardcoded table/index-name literals, never user input; identifiers can't be $wpdb->prepare() placeholders anyway.
		$wpdb->query( 'ALTER TABLE ' . $table . ' ADD ' . ( $unique ? 'UNIQUE ' : '' ) . "INDEX {$new_name} {$columns_sql}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- same as above.
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

		// BYZO_Settings::get() returns these same defaults merged over the
		// (currently empty) option — reused here rather than duplicated so
		// the two can't drift apart.
		$defaults = BYZO_Settings::get();

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
