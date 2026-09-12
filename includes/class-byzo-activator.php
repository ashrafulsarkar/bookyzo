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
		self::maybe_register_customer_role();

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
		// `uses_business_hours` added 2026-09-11 (Phase 2 step 4,
		// BYZO_DB_VERSION 1.6->1.7) — when true, this item's own
		// wp_byzo_schedules rows are ignored and BYZO_Slot_Generator reads
		// byzo_settings.business_hours instead
		// (BYZO_Capabilities::business_hours_link_enabled(), Free=false/
		// Pro=true). Column exists in Free's own schema regardless —
		// "don't gate the data model" — the capability check lives in
		// BYZO_Bookable_Item::validate(). `commission_type`/`commission_rate`
		// added 2026-09-12 (Phase 2 step 12 continued, BYZO_DB_VERSION
		// 2.0->2.1, DETAILED-SPEC.md §3 B7 Appointment) — purely
		// informational config feeding a Pro report, no booking/slot
		// behavior changes, so unlike uses_business_hours these have no
		// capability gate at all: Free's own admin screens never expose
		// them, only Pro's REST route
		// (BYZO_Bookable_Item::update_commission()) ever writes them.
		// `tax_rate_override` added 2026-09-12 (Phase 2 step 14,
		// BYZO_DB_VERSION 2.1->2.2, DETAILED-SPEC.md §1.1/§A7) — when set,
		// REPLACES (not adds to) `byzo_settings.tax_rate` for this item's
		// own bookings; NULL (the only value Free itself ever writes,
		// which is to say Free never writes it at all) means "use the
		// site-wide rate," today's exact behavior. `booking_fee` added
		// 2026-09-12 (Phase 2 step 17, BYZO_DB_VERSION 2.2->2.3,
		// DETAILED-SPEC.md §3 B6) — a flat, once-per-booking charge (not
		// multiplied by party_size) added to payment_amount alongside any
		// resolved add-ons, then taxed like every other line item. Same
		// "purely informational until Pro writes it" shape as
		// commission_type/tax_rate_override — no capability gate, Free's
		// own admin screens never expose it. `floor_x`/`floor_y`/
		// `floor_width`/`floor_height`/`floor_shape` added 2026-09-12
		// (Phase 2 step 12 continued, BYZO_DB_VERSION 2.4->2.5,
		// DETAILED-SPEC.md §3 B7 Table) — purely visual floor-plan
		// position, zero effect on booking/slot/capacity logic, same
		// informational-only shape as booking_fee/tax_rate_override above.
		$sql[] = "CREATE TABLE {$prefix}byzo_bookable_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(20) NOT NULL DEFAULT 'appointment',
			name VARCHAR(255) NOT NULL,
			description TEXT NULL,
			photo_id BIGINT UNSIGNED NULL,
			capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			price DECIMAL(10,2) NULL,
			duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			location_id BIGINT UNSIGNED NULL,
			location VARCHAR(255) NULL,
			meeting_link VARCHAR(500) NULL,
			group_id BIGINT UNSIGNED NULL,
			uses_business_hours TINYINT(1) NOT NULL DEFAULT 0,
			commission_type VARCHAR(20) NULL,
			commission_rate DECIMAL(10,2) NULL,
			tax_rate_override DECIMAL(5,2) NULL,
			booking_fee DECIMAL(10,2) NULL,
			floor_x SMALLINT NULL,
			floor_y SMALLINT NULL,
			floor_width SMALLINT NULL,
			floor_height SMALLINT NULL,
			floor_shape VARCHAR(20) NULL,
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
		// the data model" pattern as `custom_fields` below. `attendees`
		// added 2026-09-06 (Phase 1.7 step 1, DETAILED-SPEC.md §1.4) —
		// reserved the same way; only consumed once Phase 2 step 20 flips
		// the `byzo_collect_attendee_details` filter on. `service_id` added
		// 2026-09-11 (Phase 2 step 5, BYZO_DB_VERSION 1.7->1.8,
		// DETAILED-SPEC.md §3 B7) — which Pro-owned wp_byzo_services row this
		// booking was for (NULL for every booking made the way Free already
		// works, direct staff pick, no service concept); drives
		// payment_amount's price resolution when set. `deposit_amount`
		// added 2026-09-11 (Phase 2 step 6, BYZO_DB_VERSION 1.8->1.9,
		// DETAILED-SPEC.md §A7) — how much of `payment_amount` was
		// actually charged online at booking time (NULL = full amount
		// required, today's exact behavior); the remainder
		// (`payment_amount - deposit_amount`) is the balance still due,
		// collected later. Resolved via the new `byzo_deposit_amount`
		// filter (Free never hooks it — always NULL there).
		// `internal_notes`/`tags` added 2026-09-12 (Phase 2 step 11,
		// BYZO_DB_VERSION 1.9->2.0, DETAILED-SPEC.md §3 B3) — staff-only,
		// deliberately separate from the customer-submitted `customer_notes`
		// above (different author, different audience). Free never writes
		// either column; Pro's "Bulk & Notes" screen is the only UI that does.
		// `cart_id` added 2026-09-12 (Phase 2 step 14, BYZO_DB_VERSION
		// 2.1->2.2, DETAILED-SPEC.md §3 B6) — bookings sharing the same
		// `cart_id` were created together in one Pro cart checkout and
		// share one Stripe PaymentIntent; the webhook flips every booking
		// with that `cart_id` to paid together, not just one by
		// metadata.booking_id. NULL for every booking made the way Free
		// already works (one booking, one payment, today's exact behavior).
		// `booking_fee_amount` added 2026-09-12 (Phase 2 step 17,
		// BYZO_DB_VERSION 2.2->2.3, DETAILED-SPEC.md §3 B6) — a frozen
		// snapshot of `bookable_items.booking_fee` as actually charged at
		// booking time, same reasoning `tax_amount`/`deposit_amount`
		// already established: reading the live item column later would
		// show the WRONG number the moment an admin edits the fee.
		// `followup_sent_at` added 2026-09-12 (Phase 2 step 18,
		// BYZO_DB_VERSION 2.3->2.4, DETAILED-SPEC.md §3 B5) — same shape
		// as `reminder_sent_at`: one fixed follow-up per booking (not
		// multiple configurable rules, unlike step 7's reminder system),
		// so a single dedup column is enough. Dormant until Pro writes it.
		// `combined_table_ids` added 2026-09-12 (Phase 2 step 12
		// continued, BYZO_DB_VERSION 2.4->2.5, DETAILED-SPEC.md §3 B7
		// Table) — comma-separated bookable_item_ids of the OTHER tables
		// combined into this one reservation (this booking's own
		// `bookable_item_id` is always the primary table); each combined
		// table's overlapping slot is flipped to the same `'blocked'`
		// status blackout dates/calendar-sync already use, so no new
		// capacity logic is needed to keep them from double-booking.
		// `ticket_tier_id`/`checked_in_at` added 2026-09-12 (Phase 2 step
		// 12 continued, BYZO_DB_VERSION 2.5->2.6, DETAILED-SPEC.md §3 B7
		// Event) — same "don't gate the data model" pattern: Free's own
		// widget never renders a tier picker and Free's own admin never
		// writes a check-in timestamp, so both columns stay permanently
		// NULL on an unlicensed install. `ticket_tier_id` has no FK
		// constraint (this project never uses real FKs, see the rest of
		// this table) and is resolved via `BYZO_Booking::get()`'s own
		// pattern if a future screen needs the tier's name/price
		// alongside a booking row.
		$sql[] = "CREATE TABLE {$prefix}byzo_bookings (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slot_id BIGINT UNSIGNED NULL,
			bookable_item_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NULL,
			ticket_tier_id BIGINT UNSIGNED NULL,
			customer_id BIGINT UNSIGNED NULL,
			customer_name VARCHAR(255) NOT NULL,
			customer_email VARCHAR(255) NOT NULL,
			customer_phone VARCHAR(50) NULL,
			customer_notes TEXT NULL,
			internal_notes TEXT NULL,
			tags VARCHAR(255) NULL,
			cart_id VARCHAR(64) NULL,
			combined_table_ids VARCHAR(255) NULL,
			party_size SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			payment_amount DECIMAL(10,2) NULL,
			deposit_amount DECIMAL(10,2) NULL,
			tax_amount DECIMAL(10,2) NULL,
			booking_fee_amount DECIMAL(10,2) NULL,
			custom_fields LONGTEXT NULL,
			addons LONGTEXT NULL,
			attendees LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			cancelled_at DATETIME NULL,
			completed_at DATETIME NULL,
			rescheduled_at DATETIME NULL,
			reminder_sent_at DATETIME NULL,
			followup_sent_at DATETIME NULL,
			checked_in_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY slot_id (slot_id),
			KEY bookable_item_id (bookable_item_id),
			KEY status (status),
			KEY customer_email (customer_email),
			KEY customer_id (customer_id),
			KEY cart_id (cart_id)
		) {$charset_collate};";

		// wp_byzo_customers (added 2026-09-05, Phase 1.5 step 9,
		// DETAILED-SPEC.md §1.5) — Free-owned, not Pro-gated: a customer
		// must be able to exist independently of any booking for CSV
		// import (A10) to make sense. Upserted by email on every booking
		// creation (BYZO_Customer::upsert_by_email(), called from
		// BYZO_Booking::create()) and directly by A10's CSV import.
		// `wp_user_id` added 2026-09-06 (Phase 1.7 step 1, DETAILED-SPEC.md
		// §1.5) — links to a real byzo_customer-role WP account once they
		// register; NULL for guest-only customers. UNIQUE permits multiple
		// NULLs in MySQL, so guest rows are unaffected.
		$sql[] = "CREATE TABLE {$prefix}byzo_customers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			email VARCHAR(255) NOT NULL,
			phone VARCHAR(50) NULL,
			notes TEXT NULL,
			tags VARCHAR(255) NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'booking',
			wp_user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			UNIQUE KEY wp_user_id (wp_user_id)
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
	 * Registers the zero-capability `byzo_customer` WP role (Phase 1.7 step
	 * 1, DETAILED-SPEC.md §A11) — never granted wp-admin access, just an
	 * identity for the self-service dashboard's login. `add_role()` is
	 * itself idempotent (WP no-ops if the role already exists), so this is
	 * safe to call unconditionally on every activation. Also called from
	 * `BYZO_Plugin::maybe_upgrade_db()` so an existing install picks the
	 * role up on a version bump alone, without needing a deactivate/
	 * reactivate cycle (only `register_activation_hook` fires `activate()`).
	 */
	public static function maybe_register_customer_role() {
		if ( null === get_role( 'byzo_customer' ) ) {
			add_role( 'byzo_customer', __( 'Customer', 'bookyzo' ), array() );
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
