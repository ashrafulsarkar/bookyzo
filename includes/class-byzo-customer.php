<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for wp_byzo_customers (DETAILED-SPEC.md §1.5, added
 * Phase 1.5 step 9 — competitor gap-fill). A customer must be able to
 * exist independently of any booking (CSV import, A10), so this is its
 * own table rather than a `SELECT DISTINCT customer_email FROM bookings`
 * view — mirrors BYZO_Bookable_Item's plain-static-methods-over-$wpdb shape.
 */
class BYZO_Customer {

	const VALID_SOURCES = array( 'booking', 'import' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'byzo_customers';
	}

	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Repeat-customer stats for Pro's Analytics dashboard (DETAILED-SPEC.md
	 * §3 B8, Phase 2 step 10). Deliberately NOT date-range-scoped like
	 * every other analytics metric — retention is a whole-history
	 * question ("has this customer been back at all"), not a "in the
	 * selected window" one; scoping it to a date range would make the
	 * number bounce around based on which range happens to be selected
	 * rather than reflect actual repeat behavior.
	 *
	 * @return array{total_customers:int,repeat_customers:int,repeat_rate:float}
	 */
	public static function get_repeat_customer_stats() {
		global $wpdb;

		$customers_table = self::table();
		$bookings_table  = $wpdb->prefix . 'byzo_bookings';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names, not user input.
		$total_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$customers_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		$repeat_customers = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT customer_id
				FROM {$bookings_table}
				WHERE customer_id IS NOT NULL AND status NOT IN ('cancelled','rejected')
				GROUP BY customer_id
				HAVING COUNT(*) >= 2
			) repeat_customers" // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'total_customers'  => $total_customers,
			'repeat_customers' => $repeat_customers,
			'repeat_rate'      => $total_customers > 0 ? $repeat_customers / $total_customers : 0.0,
		);
	}

	/**
	 * A segmented customer list for Pro's bulk/marketing campaigns
	 * (DETAILED-SPEC.md §3 B5, Phase 2 step 15) — deliberately a separate
	 * method from query() rather than more filter params bolted onto it:
	 * this one aggregates across bookings (last-booking date, no-show
	 * count), query()'s own name/email/phone search doesn't touch
	 * bookings at all.
	 *
	 * @param array $args {
	 *     @type string $tag                  Matches within the comma-separated tags column.
	 *     @type string $last_booking_before  Y-m-d — only customers whose MOST RECENT
	 *                                         non-cancelled/rejected booking's slot_start
	 *                                         is before this date (including customers
	 *                                         with no bookings at all).
	 *     @type int    $min_no_show_count    Only customers with at least this many
	 *                                         'no_show' bookings.
	 * }
	 * @return array<int,array> Customer rows (id/name/email/phone/tags).
	 */
	public static function query_for_campaign( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'tag'                 => '',
			'last_booking_before' => '',
			'min_no_show_count'   => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$customers_table = self::table();
		$bookings_table  = $wpdb->prefix . 'byzo_bookings';
		$slots_table     = $wpdb->prefix . 'byzo_slots';

		$where  = array( '1=1' );
		$params = array();

		if ( $args['tag'] ) {
			$where[]  = 'c.tags LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['tag'] ) . '%';
		}

		if ( $args['last_booking_before'] ) {
			$where[]  = "c.id NOT IN (
				SELECT b.customer_id FROM {$bookings_table} b
				INNER JOIN {$slots_table} s ON s.id = b.slot_id
				WHERE b.customer_id IS NOT NULL AND b.status NOT IN ('cancelled','rejected')
				AND s.start_datetime >= %s
			)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[] = $args['last_booking_before'] . ' 00:00:00';
		}

		if ( $args['min_no_show_count'] > 0 ) {
			$where[]  = "c.id IN (
				SELECT customer_id FROM {$bookings_table}
				WHERE customer_id IS NOT NULL AND status = 'no_show'
				GROUP BY customer_id
				HAVING COUNT(*) >= %d
			)"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params[] = (int) $args['min_no_show_count'];
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT c.* FROM {$customers_table} c WHERE {$where_sql} ORDER BY c.name ASC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- no $params means none of the three optional filters added a WHERE clause, so $where_sql is guaranteed to be the hardcoded '1=1' literal from line 102 with zero interpolated user data; nothing here needs $wpdb->prepare().

		return array_map( array( __CLASS__, 'cast' ), $rows ?: array() );
	}

	public static function get_by_email( $email ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', $email ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Added Phase 1.7 step 2 (DETAILED-SPEC.md §A11) — dashboard-scoping
	 * lookup for an authenticated byzo_customer-role request: resolves the
	 * customer row linked to the currently logged-in WP account.
	 */
	public static function get_by_wp_user_id( $wp_user_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE wp_user_id = %d', $wp_user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return $row ? self::cast( $row ) : null;
	}

	/**
	 * @param array $args {
	 *     @type string $search   Matches against name/email/phone (LIKE).
	 *     @type int    $per_page Default 20, max 100.
	 *     @type int    $page     1-indexed.
	 * }
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'search'   => '',
			'per_page' => 20,
			'page'     => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== trim( (string) $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where[]  = '( name LIKE %s OR email LIKE %s OR phone LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$sql      = 'SELECT * FROM ' . self::table() . " WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$count_sql    = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where_sql}";
		$count_params = array_slice( $params, 0, count( $params ) - 2 );
		$total        = $count_params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery

		return array(
			'items' => array_map( array( __CLASS__, 'cast' ), $rows ?: array() ),
			'total' => $total,
		);
	}

	/**
	 * @return int|WP_Error New customer ID, or WP_Error on validation failure.
	 */
	public static function create( $data ) {
		global $wpdb;

		$validated = self::validate( $data, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( self::get_by_email( $validated['email'] ) ) {
			return new WP_Error( 'byzo_duplicate_email', __( 'A customer with this email already exists.', 'bookyzo' ), array( 'status' => 409 ) );
		}

		$now                = current_time( 'mysql' );
		$validated['created_at'] = $now;
		$validated['updated_at'] = $now;

		$inserted = $wpdb->insert( self::table(), $validated );

		if ( false === $inserted ) {
			return new WP_Error( 'byzo_db_error', __( 'Could not create customer.', 'bookyzo' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$existing = self::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'byzo_not_found', __( 'Customer not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$validated = self::validate( $data, false );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( isset( $validated['email'] ) && $validated['email'] !== $existing['email'] ) {
			$other = self::get_by_email( $validated['email'] );
			if ( $other && (int) $other['id'] !== (int) $id ) {
				return new WP_Error( 'byzo_duplicate_email', __( 'A customer with this email already exists.', 'bookyzo' ), array( 'status' => 409 ) );
			}
		}

		if ( empty( $validated ) ) {
			return true;
		}

		$validated['updated_at'] = current_time( 'mysql' );

		$updated = $wpdb->update( self::table(), $validated, array( 'id' => $id ) );

		if ( false === $updated && '' !== $wpdb->last_error ) {
			return new WP_Error( 'byzo_db_error', __( 'Could not update customer.', 'bookyzo' ) );
		}

		return true;
	}

	public static function delete( $id ) {
		global $wpdb;

		if ( ! self::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Customer not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$wpdb->delete( self::table(), array( 'id' => $id ) );

		return true;
	}

	/**
	 * Finds-or-creates a customer by email, refreshing name/phone if
	 * they've changed — called from BYZO_Booking::create() (§1.5: "the
	 * customer record tracks their CURRENT details, the booking row keeps
	 * what was true THEN"). Never touches `tags`/`notes` — those are
	 * admin-curated fields a booking has no opinion about.
	 *
	 * @return int Customer ID.
	 */
	public static function upsert_by_email( $name, $email, $phone = '' ) {
		global $wpdb;

		$email = sanitize_email( $email );

		$existing = self::get_by_email( $email );

		if ( ! $existing ) {
			$id = self::create(
				array(
					'name'   => $name,
					'email'  => $email,
					'phone'  => $phone,
					'source' => 'booking',
				)
			);

			return is_wp_error( $id ) ? 0 : $id;
		}

		$patch = array();
		if ( $name && $name !== $existing['name'] ) {
			$patch['name'] = $name;
		}
		if ( $phone && $phone !== $existing['phone'] ) {
			$patch['phone'] = $phone;
		}

		if ( $patch ) {
			$wpdb->update( self::table(), array_merge( $patch, array( 'updated_at' => current_time( 'mysql' ) ) ), array( 'id' => $existing['id'] ) );
		}

		return (int) $existing['id'];
	}

	/**
	 * Validates and whitelists incoming data. `$require_all` = true for
	 * create (name/email required); false for update (partial patches OK).
	 *
	 * @return array|WP_Error Sanitized column => value pairs ready for $wpdb, or WP_Error.
	 */
	private static function validate( $data, $require_all ) {
		$out = array();

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'byzo_invalid_name', __( 'Customer name cannot be empty.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$out['name'] = $name;
		} elseif ( $require_all ) {
			return new WP_Error( 'byzo_missing_name', __( 'Customer name is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( isset( $data['email'] ) ) {
			$email = sanitize_email( $data['email'] );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'byzo_invalid_email', __( 'A valid email address is required.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$out['email'] = $email;
		} elseif ( $require_all ) {
			return new WP_Error( 'byzo_missing_email', __( 'Customer email is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( isset( $data['phone'] ) ) {
			$out['phone'] = sanitize_text_field( $data['phone'] );
		}

		if ( isset( $data['notes'] ) ) {
			$out['notes'] = sanitize_textarea_field( $data['notes'] );
		}

		if ( isset( $data['tags'] ) ) {
			$out['tags'] = sanitize_text_field( $data['tags'] );
		}

		if ( isset( $data['source'] ) ) {
			if ( ! in_array( $data['source'], self::VALID_SOURCES, true ) ) {
				return new WP_Error( 'byzo_invalid_source', __( 'Invalid customer source.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$out['source'] = $data['source'];
		} elseif ( $require_all ) {
			$out['source'] = 'booking';
		}

		// Added Phase 1.7 step 2 (DETAILED-SPEC.md §A11) — links this row to
		// a real WP account once registered. Not part of the public REST
		// controller's writable fields (no route passes it); only
		// BYZO_Customer_Auth sets it, via array_key_exists (not isset) so
		// a null explicitly clears the link if ever needed.
		if ( array_key_exists( 'wp_user_id', $data ) ) {
			$out['wp_user_id'] = null === $data['wp_user_id'] ? null : absint( $data['wp_user_id'] );
		}

		return $out;
	}

	private static function cast( $row ) {
		$row['id'] = (int) $row['id'];

		return $row;
	}
}
