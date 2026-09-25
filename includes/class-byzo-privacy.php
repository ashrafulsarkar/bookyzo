<?php
defined( 'ABSPATH' ) || exit;

/**
 * WordPress's privacy tools for Bookyzo's customer data (QA report
 * 2026-09-22 §6.2.5, GDPR): Tools → Export Personal Data / Erase Personal
 * Data find a person's customer profile and bookings by email, and
 * Settings → Privacy gets suggested policy text.
 *
 * Erasing anonymises bookings rather than deleting them: the business
 * still needs the record that a slot was sold and paid for, just not who
 * it was. A booking that is still upcoming (pending/confirmed, not yet
 * started) is kept as it is and reported, since erasing the contact of
 * someone about to arrive would silently break that booking — cancel it
 * first, then erase again.
 */
class BYZO_Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_policy_text' ) );
	}

	public static function register_exporters( $exporters ) {
		$exporters['bookyzo-customer'] = array(
			'exporter_friendly_name' => __( 'Bookyzo customer profile', 'bookyzo' ),
			'callback'               => array( __CLASS__, 'export_customer' ),
		);
		$exporters['bookyzo-bookings'] = array(
			'exporter_friendly_name' => __( 'Bookyzo bookings', 'bookyzo' ),
			'callback'               => array( __CLASS__, 'export_bookings' ),
		);
		return $exporters;
	}

	public static function register_erasers( $erasers ) {
		$erasers['bookyzo'] = array(
			'eraser_friendly_name' => __( 'Bookyzo customer profile and bookings', 'bookyzo' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function export_customer( $email, $page = 1 ) {
		$customer = BYZO_Customer::get_by_email( $email );
		$data     = array();

		if ( $customer ) {
			$fields = array(
				__( 'Name', 'bookyzo' )                        => $customer['name'],
				__( 'Email', 'bookyzo' )                       => $customer['email'],
				__( 'Phone', 'bookyzo' )                       => $customer['phone'],
				__( 'Notes', 'bookyzo' )                       => $customer['notes'],
				__( 'Tags', 'bookyzo' )                        => $customer['tags'],
				__( 'Agreed to marketing email', 'bookyzo' )   => ! empty( $customer['marketing_opt_in'] ) ? __( 'Yes', 'bookyzo' ) : __( 'No', 'bookyzo' ),
				__( 'Unsubscribed from marketing', 'bookyzo' ) => ! empty( $customer['marketing_unsubscribed'] ) ? __( 'Yes', 'bookyzo' ) : __( 'No', 'bookyzo' ),
				__( 'Customer since', 'bookyzo' )              => $customer['created_at'],
			);
			$data[] = array(
				'group_id'    => 'bookyzo-customer',
				'group_label' => __( 'Booking customer profile', 'bookyzo' ),
				'item_id'     => 'bookyzo-customer-' . $customer['id'],
				'data'        => self::pairs( $fields ),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function export_bookings( $email, $page = 1 ) {
		$per_page = 50;
		$rows     = self::bookings_for( $email, $per_page, ( max( 1, (int) $page ) - 1 ) * $per_page );
		$data     = array();

		foreach ( $rows as $booking ) {
			$fields = array(
				__( 'Booking number', 'bookyzo' ) => $booking['id'],
				__( 'Booked item', 'bookyzo' )    => $booking['item_name'],
				__( 'Starts', 'bookyzo' )         => $booking['slot_start'],
				__( 'Status', 'bookyzo' )         => $booking['status'],
				__( 'Name', 'bookyzo' )           => $booking['customer_name'],
				__( 'Email', 'bookyzo' )          => $booking['customer_email'],
				__( 'Phone', 'bookyzo' )          => $booking['customer_phone'],
				__( 'Party size', 'bookyzo' )     => $booking['party_size'],
				__( 'Your notes', 'bookyzo' )     => $booking['customer_notes'],
				__( 'Amount', 'bookyzo' )         => null === $booking['payment_amount'] ? '' : $booking['payment_amount'],
				__( 'Payment status', 'bookyzo' ) => $booking['payment_status'],
				__( 'Booked on', 'bookyzo' )      => $booking['created_at'],
			);

			foreach ( (array) json_decode( (string) $booking['custom_fields'], true ) as $key => $value ) {
				/* translators: %s: form field id */
				$label = is_numeric( $key ) ? sprintf( __( 'Form answer (field %s)', 'bookyzo' ), $key ) : (string) $key;
				/**
				 * The label a booking-form answer is exported under. Answers are
				 * stored by field id; an add-on that defines the fields names them.
				 *
				 * @param string     $label Default label.
				 * @param string|int $key   Stored key (field id).
				 */
				$label            = (string) apply_filters( 'byzo_privacy_custom_field_label', $label, $key );
				$fields[ $label ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			}

			$attendees = (array) json_decode( (string) $booking['attendees'], true );
			if ( $attendees ) {
				$fields[ __( 'Attendees', 'bookyzo' ) ] = implode( '; ', array_map( array( __CLASS__, 'attendee_line' ), $attendees ) );
			}

			$data[] = array(
				'group_id'    => 'bookyzo-bookings',
				'group_label' => __( 'Bookings', 'bookyzo' ),
				'item_id'     => 'bookyzo-booking-' . $booking['id'],
				'data'        => self::pairs( $fields ),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < $per_page,
		);
	}

	public static function erase( $email, $page = 1 ) {
		global $wpdb;

		$bookings_table = $wpdb->prefix . 'byzo_bookings';
		$now            = current_time( 'mysql' );
		$removed        = false;
		$retained       = false;
		$messages       = array();

		// All of them at once: erased rows stop matching the email, so
		// paging by offset would skip some. One customer's bookings are a
		// short list.
		foreach ( self::bookings_for( $email, 0, 0 ) as $booking ) {
			$upcoming = in_array( $booking['status'], array( 'pending', 'confirmed' ), true ) && $booking['slot_start'] && $booking['slot_start'] >= $now;

			if ( $upcoming ) {
				$retained   = true;
				$messages[] = sprintf(
					/* translators: 1: booking number, 2: date and time */
					__( 'Bookyzo booking #%1$d (%2$s) was kept because it is still upcoming. Cancel it first, then erase again.', 'bookyzo' ),
					$booking['id'],
					$booking['slot_start']
				);
				continue;
			}

			$wpdb->update(
				$bookings_table,
				array(
					'customer_name'  => wp_privacy_anonymize_data( 'text' ),
					'customer_email' => wp_privacy_anonymize_data( 'email' ),
					'customer_phone' => '',
					'customer_notes' => null,
					'custom_fields'  => null,
					'attendees'      => null,
					'internal_notes' => null,
					'tags'           => null,
					'customer_id'    => null,
				),
				array( 'id' => $booking['id'] )
			);
			$removed = true;
		}

		$customer = BYZO_Customer::get_by_email( $email );
		if ( $customer ) {
			if ( $retained ) {
				$messages[] = __( 'The Bookyzo customer profile was kept for the upcoming booking(s) above.', 'bookyzo' );
			} else {
				$wpdb->update( $bookings_table, array( 'customer_id' => null ), array( 'customer_id' => $customer['id'] ) );
				$wpdb->delete( $wpdb->prefix . 'byzo_customers', array( 'id' => $customer['id'] ) );
				$removed = true;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Bookings made with this email (case-insensitive), with the booked
	 * item's name and the slot's start. $limit 0 = all.
	 */
	private static function bookings_for( $email, $limit, $offset ) {
		global $wpdb;

		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return array();
		}

		$sql = $wpdb->prepare(
			"SELECT b.*, s.start_datetime AS slot_start, i.name AS item_name
			FROM {$wpdb->prefix}byzo_bookings b
			LEFT JOIN {$wpdb->prefix}byzo_slots s ON s.id = b.slot_id
			LEFT JOIN {$wpdb->prefix}byzo_bookable_items i ON i.id = b.bookable_item_id
			WHERE LOWER(b.customer_email) = %s
			ORDER BY b.id ASC",
			$email
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	private static function pairs( $fields ) {
		$pairs = array();
		foreach ( $fields as $name => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$pairs[] = array(
				'name'  => $name,
				'value' => (string) $value,
			);
		}
		return $pairs;
	}

	private static function attendee_line( $attendee ) {
		if ( ! is_array( $attendee ) ) {
			return (string) $attendee;
		}
		return trim( implode( ' ', array_filter( array( $attendee['name'] ?? '', isset( $attendee['email'] ) ? '<' . $attendee['email'] . '>' : '' ) ) ) );
	}

	/**
	 * Suggested text for Settings → Privacy → Policy Guide.
	 */
	public static function add_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for the part of your privacy policy that covers online bookings. Adjust it to what your site actually collects and how long you keep it.', 'bookyzo' ) . '</p>';
		$content .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'bookyzo' ) . '</strong> ';
		$content .= esc_html__( 'When you book with us we collect your name, email address and, if you give it, your phone number, plus any notes or answers to our booking questions. We use them to confirm, remind you about, change or cancel your booking, and to keep a record of it. If you ticked the box to hear about news and offers, we also use your email for that, and every such email has a link to stop it. If you pay online, the payment is handled by our payment provider; we do not store your card details. We keep booking records for as long as we need them for our business and accounting. You can ask us for a copy of the data we hold about you, or ask us to erase it; bookings that have not happened yet are kept until they are cancelled or have taken place.', 'bookyzo' );
		$content .= '</p>';

		wp_add_privacy_policy_content( 'Bookyzo', wp_kses_post( $content ) );
	}
}
