<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data-access layer for wp_byzo_bookable_items (DETAILED-SPEC.md §1.1;
 * table/class renamed from "resource" 2026-09-03 — "resource" read as an
 * unclear, overly technical term to end users). Plain static methods over
 * $wpdb rather than a full ORM/WP_Post shape — the table is custom SQL by
 * design (WooCommerce-independent core), and the REST controller is the
 * only caller for now so an ORM would be premature.
 */
class BYZO_Bookable_Item {

	const VALID_TYPES    = array( 'appointment', 'table', 'event', 'slot' );
	const VALID_STATUSES = array( 'active', 'inactive', 'archived' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'byzo_bookable_items';
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
	 * @param array $args {
	 *     @type string $type     Filter by booking type.
	 *     @type string $status   Filter by status.
	 *     @type int    $per_page Default 20, max 100.
	 *     @type int    $page     1-indexed.
	 * }
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'type'     => '',
			'status'   => '',
			'per_page' => 20,
			'page'     => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( $args['type'] && in_array( $args['type'], self::VALID_TYPES, true ) ) {
			$where[]  = 'type = %s';
			$params[] = $args['type'];
		}

		if ( $args['status'] && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT * FROM " . self::table() . " WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$count_sql = "SELECT COUNT(*) FROM " . self::table() . " WHERE {$where_sql}";
		$count_params = array_slice( $params, 0, count( $params ) - 2 );
		$total = $count_params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery

		return array(
			'items' => array_map( array( __CLASS__, 'cast' ), $rows ?: array() ),
			'total' => $total,
		);
	}

	/**
	 * @return int|WP_Error New bookable item ID, or WP_Error on validation failure.
	 */
	public static function create( $data ) {
		global $wpdb;

		$validated = self::validate( $data, true );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$now = current_time( 'mysql' );
		$validated['created_at'] = $now;
		$validated['updated_at'] = $now;

		$inserted = $wpdb->insert( self::table(), $validated );

		if ( false === $inserted ) {
			return new WP_Error( 'byzo_db_error', __( 'Could not create bookable item.', 'bookyzo' ) );
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
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$validated = self::validate( $data, false );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( empty( $validated ) ) {
			return true;
		}

		$validated['updated_at'] = current_time( 'mysql' );

		$updated = $wpdb->update( self::table(), $validated, array( 'id' => $id ) );

		if ( false === $updated ) {
			return new WP_Error( 'byzo_db_error', __( 'Could not update bookable item.', 'bookyzo' ) );
		}

		// Fires when a bookable item transitions INTO 'active' from
		// something else (not on every save of an already-active item) —
		// this is the "reactivated" half of the doc comment on
		// BYZO_Plugin::register_hooks()'s byzo_bookable_item_activated
		// listener; without this the listener was wired to an event that
		// never actually fired, and reactivating an inactive item silently
		// generated no slots until the next nightly cron.
		if ( isset( $validated['status'] ) && 'active' === $validated['status'] && 'active' !== $existing['status'] ) {
			do_action( 'byzo_bookable_item_activated', $id );
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		if ( ! self::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		$wpdb->delete( self::table(), array( 'id' => $id ) );

		return true;
	}

	/**
	 * Sets commission_type/commission_rate (DETAILED-SPEC.md §3 B7
	 * Appointment, Phase 2 step 12 continued) — a plain field update, not
	 * folded into validate()/update() since this changes no booking/slot
	 * behavior at all (unlike e.g. uses_business_hours) and so needs no
	 * capability gate; Free's own admin screens never write it, only
	 * Pro's REST route does.
	 *
	 * @param string|null $type 'percentage', 'fixed', or null to clear.
	 * @param float|null  $rate Null to clear.
	 * @return true|WP_Error
	 */
	public static function update_commission( $id, $type, $rate ) {
		global $wpdb;

		if ( ! self::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		if ( null !== $type && ! in_array( $type, array( 'percentage', 'fixed' ), true ) ) {
			return new WP_Error( 'byzo_invalid_commission_type', __( 'commission_type must be percentage or fixed.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( null !== $rate ) {
			$rate = (float) $rate;
			if ( $rate < 0 ) {
				return new WP_Error( 'byzo_invalid_commission_rate', __( 'commission_rate cannot be negative.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			if ( 'percentage' === $type && $rate > 100 ) {
				return new WP_Error( 'byzo_invalid_commission_rate', __( 'A percentage commission_rate cannot exceed 100.', 'bookyzo' ), array( 'status' => 400 ) );
			}
		}

		$wpdb->update(
			self::table(),
			array(
				'commission_type' => $type,
				'commission_rate' => $rate,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		return true;
	}

	/**
	 * Sets tax_rate_override (DETAILED-SPEC.md §1.1/§A7, Phase 2 step 14)
	 * — same shape as update_commission(): a plain field update with no
	 * capability gate, since Free's own admin screens never expose this
	 * field at all (only Pro's REST route ever writes it).
	 *
	 * @param float|null $rate 0-100, or null to clear (falls back to the site-wide rate).
	 * @return true|WP_Error
	 */
	public static function update_tax_rate_override( $id, $rate ) {
		global $wpdb;

		if ( ! self::get( $id ) ) {
			return new WP_Error( 'byzo_not_found', __( 'Bookable item not found.', 'bookyzo' ), array( 'status' => 404 ) );
		}

		if ( null !== $rate ) {
			$rate = (float) $rate;
			if ( $rate < 0 || $rate > 100 ) {
				return new WP_Error( 'byzo_invalid_tax_rate_override', __( 'tax_rate_override must be between 0 and 100.', 'bookyzo' ), array( 'status' => 400 ) );
			}
		}

		$wpdb->update(
			self::table(),
			array(
				'tax_rate_override' => $rate,
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		return true;
	}

	/**
	 * Validates and whitelists incoming data. `$require_all` = true for
	 * create (name/type required); false for update (partial patches OK).
	 *
	 * @return array|WP_Error Sanitized column => value pairs ready for $wpdb, or WP_Error.
	 */
	private static function validate( $data, $require_all ) {
		$out = array();

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'byzo_invalid_name', __( 'Bookable item name cannot be empty.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$out['name'] = $name;
		} elseif ( $require_all ) {
			return new WP_Error( 'byzo_missing_name', __( 'Bookable item name is required.', 'bookyzo' ), array( 'status' => 400 ) );
		}

		if ( isset( $data['type'] ) ) {
			if ( ! in_array( $data['type'], self::VALID_TYPES, true ) ) {
				return new WP_Error( 'byzo_invalid_type', __( 'Invalid booking type.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$out['type'] = $data['type'];
		} elseif ( $require_all ) {
			$out['type'] = 'appointment';
		}

		if ( isset( $data['description'] ) ) {
			$out['description'] = wp_kses_post( $data['description'] );
		}

		// array_key_exists, not isset — a JSON `photo_id: null` (the
		// "Remove photo" case) is a real key with a null value, and isset()
		// treats that as absent, silently dropping the clear-out.
		if ( array_key_exists( 'photo_id', $data ) ) {
			$out['photo_id'] = absint( $data['photo_id'] ) ?: null;
		}

		// location/meeting_link (added 2026-09-05, Phase 1.6, DETAILED-SPEC.md
		// §1.1) — free-text address/room/"Online" plus an optional meeting
		// URL, available on every type. array_key_exists (not isset), same
		// reasoning as photo_id above: a JSON `null` is a real "clear this
		// field" request, not an absent key.
		if ( array_key_exists( 'location', $data ) ) {
			$out['location'] = ( '' === $data['location'] || null === $data['location'] ) ? null : sanitize_text_field( $data['location'] );
		}

		if ( array_key_exists( 'meeting_link', $data ) ) {
			$out['meeting_link'] = ( '' === $data['meeting_link'] || null === $data['meeting_link'] ) ? null : esc_url_raw( $data['meeting_link'] );
		}

		if ( isset( $data['capacity'] ) ) {
			$out['capacity'] = max( 1, absint( $data['capacity'] ) );
		} elseif ( $require_all ) {
			$out['capacity'] = 1;
		}

		if ( isset( $data['price'] ) ) {
			$out['price'] = ( '' === $data['price'] || null === $data['price'] ) ? null : (float) $data['price'];
		}

		if ( isset( $data['duration_minutes'] ) ) {
			$duration = absint( $data['duration_minutes'] );
			if ( $duration < 5 ) {
				return new WP_Error( 'byzo_invalid_duration', __( 'duration_minutes must be at least 5.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$out['duration_minutes'] = $duration;
		} elseif ( $require_all ) {
			$out['duration_minutes'] = 30;
		}

		if ( isset( $data['status'] ) ) {
			if ( ! in_array( $data['status'], self::VALID_STATUSES, true ) ) {
				return new WP_Error( 'byzo_invalid_status', __( 'Invalid bookable item status.', 'bookyzo' ), array( 'status' => 400 ) );
			}
			$out['status'] = $data['status'];
		} elseif ( $require_all ) {
			$out['status'] = 'active';
		}

		// Phase 2 step 4 — linking to the shared business-hours default
		// instead of this item's own schedule (DETAILED-SPEC.md §A2).
		// A simple boolean capability check, not a counting query, so it
		// lives here with every other business-rule validation rather
		// than in the REST controller (which is where check_type_cap()'s
		// COUNT(*) query lives instead).
		if ( isset( $data['uses_business_hours'] ) ) {
			$uses_business_hours = (bool) $data['uses_business_hours'];
			if ( $uses_business_hours && ! BYZO_Capabilities::business_hours_link_enabled() ) {
				return new WP_Error(
					'byzo_business_hours_link_not_available',
					__( 'Linking to business hours is not available on your current plan.', 'bookyzo' ),
					array( 'status' => 403 )
				);
			}
			$out['uses_business_hours'] = $uses_business_hours;
		}

		return $out;
	}

	private static function cast( $row ) {
		$row['id']          = (int) $row['id'];
		$row['photo_id']    = $row['photo_id'] ? (int) $row['photo_id'] : null;
		$row['capacity']    = (int) $row['capacity'];
		$row['price']       = null === $row['price'] ? null : (float) $row['price'];
		$row['duration_minutes'] = (int) $row['duration_minutes'];
		$row['location_id'] = $row['location_id'] ? (int) $row['location_id'] : null;
		$row['group_id']    = $row['group_id'] ? (int) $row['group_id'] : null;
		$row['uses_business_hours'] = (bool) $row['uses_business_hours'];
		$row['commission_rate']    = isset( $row['commission_rate'] ) && null !== $row['commission_rate'] ? (float) $row['commission_rate'] : null;
		$row['tax_rate_override']  = isset( $row['tax_rate_override'] ) && null !== $row['tax_rate_override'] ? (float) $row['tax_rate_override'] : null;

		return $row;
	}
}
