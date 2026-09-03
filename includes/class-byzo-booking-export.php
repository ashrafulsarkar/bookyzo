<?php
defined( 'ABSPATH' ) || exit;

/**
 * CSV export for the booking list (DETAILED-SPEC.md §A8) — the
 * admin-post.php + nonce pattern used by the sister plugins
 * (codeholt-bundles-for-woocommerce's import/export, license-envato's
 * log export), not a REST endpoint: this streams a file download, which
 * doesn't fit REST's JSON-response shape.
 */
class BYZO_Booking_Export {

	const ACTION = 'byzo_export_bookings';
	const NONCE  = 'byzo_export_bookings';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * The admin-post.php callback: auth/nonce/headers/exit, then delegates
	 * the actual CSV building to stream_csv() so that part is unit-testable
	 * without a real authenticated HTTP request (nonces are tied to the
	 * current user + a time window, making check_admin_referer() itself
	 * awkward to exercise from a CLI smoke test).
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'bookyzo' ), 403 );
		}

		check_admin_referer( self::NONCE, '_wpnonce' );

		$args = self::args_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified above via check_admin_referer().

		$filename = 'bookyzo-bookings-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		self::stream_csv( $out, $args );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		exit;
	}

	/**
	 * @param array $raw Typically $_GET — from/to/resource_id/status, same shape BYZO_Booking::query() accepts.
	 */
	public static function args_from_request( $raw ) {
		return array(
			'from'        => isset( $raw['from'] ) ? sanitize_text_field( wp_unslash( $raw['from'] ) ) : '',
			'to'          => isset( $raw['to'] ) ? sanitize_text_field( wp_unslash( $raw['to'] ) ) : '',
			'resource_id' => isset( $raw['resource_id'] ) ? absint( $raw['resource_id'] ) : 0,
			'status'      => isset( $raw['status'] ) ? sanitize_text_field( wp_unslash( $raw['status'] ) ) : '',
			'per_page'    => 100,
			'page'        => 1,
		);
	}

	/**
	 * Writes the CSV (header row + every matching booking) to an open
	 * file handle/stream. Separated from handle() so it can be pointed at
	 * php://memory or a temp file in a test, not just php://output.
	 *
	 * @param resource $out  An open writable stream (fopen() resource).
	 * @param array    $args Same shape as BYZO_Booking::query()'s $args.
	 * @return int Number of booking rows written.
	 */
	public static function stream_csv( $out, $args ) {
		fputcsv( $out, array( 'ID', 'Customer Name', 'Customer Email', 'Customer Phone', 'Resource', 'Party Size', 'Date', 'Time', 'Status', 'Payment Status', 'Payment Amount' ) );

		$resources_by_id = self::resources_by_id();
		$written         = 0;

		// Paginates through BYZO_Booking::query() rather than one giant
		// unbounded SELECT * — matches the same 100-per-page cap the
		// REST endpoint already enforces, exports are just as likely to
		// span thousands of bookings on a mature site as the admin list is.
		$page = 1;
		do {
			$args['page'] = $page;
			$result       = BYZO_Booking::query( $args );

			foreach ( $result['items'] as $booking ) {
				$resource_name = $resources_by_id[ $booking['resource_id'] ]['name'] ?? '';
				$date          = '';
				$time          = '';
				if ( $booking['slot_start'] ) {
					$start = new DateTime( $booking['slot_start'], wp_timezone() );
					$date  = $start->format( 'Y-m-d' );
					$time  = $start->format( 'H:i' );
				}

				fputcsv(
					$out,
					array(
						$booking['id'],
						$booking['customer_name'],
						$booking['customer_email'],
						$booking['customer_phone'],
						$resource_name,
						$booking['party_size'],
						$date,
						$time,
						$booking['status'],
						$booking['payment_status'],
						null === $booking['payment_amount'] ? '' : $booking['payment_amount'],
					)
				);
				$written++;
			}

			$page++;
		} while ( count( $result['items'] ) === $args['per_page'] );

		return $written;
	}

	private static function resources_by_id() {
		$all = BYZO_Resource::query( array( 'per_page' => 100 ) );
		$map = array();
		foreach ( $all['items'] as $resource ) {
			$map[ $resource['id'] ] = $resource;
		}
		return $map;
	}
}
