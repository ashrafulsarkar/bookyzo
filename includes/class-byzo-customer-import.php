<?php
defined( 'ABSPATH' ) || exit;

/**
 * CSV import for the customer list (DETAILED-SPEC.md §A10, Phase 1.5 step
 * 9) — the admin-post.php + nonce + file-upload pattern, mirroring
 * BYZO_Booking_Export's architecture in reverse: parse_csv_rows() and
 * upsert_customers() are separated from handle()'s auth/nonce/upload
 * wrapper so both are unit-testable without a real authenticated HTTP
 * file upload (same testability rationale as Phase 1 step 5's export
 * refactor).
 */
class BYZO_Customer_Import {

	const ACTION = 'byzo_import_customers';
	const NONCE  = 'byzo_import_customers';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * The admin-post.php callback: auth/nonce/file-upload, then delegates
	 * to parse_csv_rows() + upsert_customers() for the actual work.
	 * Redirects back to the Customers screen with a result count/error in
	 * the query string — there's no REST/JSON response shape for a
	 * multipart file upload triggered by a plain form POST.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'bookyzo' ), 403 );
		}

		check_admin_referer( self::NONCE, '_wpnonce' );

		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=bookyzo-customers' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above via check_admin_referer().
		if ( empty( $_FILES['customers_csv']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['customers_csv']['error'] ) {
			wp_safe_redirect( add_query_arg( 'byzo_import_error', 'no_file', $redirect ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- nonce already verified above; a raw upload handle, not a filesystem write.
		$handle = fopen( $_FILES['customers_csv']['tmp_name'], 'r' );
		if ( ! $handle ) {
			wp_safe_redirect( add_query_arg( 'byzo_import_error', 'unreadable', $redirect ) );
			exit;
		}

		$rows = self::parse_csv_rows( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$result = self::upsert_customers( $rows );

		wp_safe_redirect( add_query_arg( array( 'byzo_imported' => $result['imported'], 'byzo_skipped' => $result['skipped'] ), $redirect ) );
		exit;
	}

	/**
	 * Reads an open CSV stream (header row required: name, email, and
	 * optionally phone/notes/tags, any order) into an array of
	 * associative rows. Separated from handle() so a test can hand it
	 * php://memory instead of a real uploaded file.
	 *
	 * @param resource $handle An open readable stream.
	 * @return array<int, array{name:string,email:string,phone:string,notes:string,tags:string}>
	 */
	public static function parse_csv_rows( $handle ) {
		$header = fgetcsv( $handle );
		if ( ! $header ) {
			return array();
		}

		$header = array_map( static fn( $col ) => strtolower( trim( (string) $col ) ), $header );
		$rows   = array();

		while ( false !== ( $line = fgetcsv( $handle ) ) ) {
			$assoc = array();
			foreach ( $header as $index => $col ) {
				$assoc[ $col ] = isset( $line[ $index ] ) ? trim( (string) $line[ $index ] ) : '';
			}

			$rows[] = array(
				'name'  => $assoc['name'] ?? '',
				'email' => $assoc['email'] ?? '',
				'phone' => $assoc['phone'] ?? '',
				'notes' => $assoc['notes'] ?? '',
				'tags'  => $assoc['tags'] ?? '',
			);
		}

		return $rows;
	}

	/**
	 * Upserts each row by email (DETAILED-SPEC.md §1.5 — same key
	 * booking-creation's own upsert uses), so re-importing an updated
	 * list doesn't duplicate rows. Rows with no name or an invalid email
	 * are skipped rather than aborting the whole import.
	 *
	 * @param array $rows From parse_csv_rows().
	 * @return array{imported:int,skipped:int}
	 */
	public static function upsert_customers( $rows ) {
		$imported = 0;
		$skipped  = 0;

		foreach ( $rows as $row ) {
			if ( '' === $row['name'] || ! is_email( $row['email'] ) ) {
				$skipped++;
				continue;
			}

			$existing = BYZO_Customer::get_by_email( $row['email'] );

			if ( $existing ) {
				$result = BYZO_Customer::update(
					$existing['id'],
					array(
						'name'  => $row['name'],
						'phone' => $row['phone'],
						'notes' => $row['notes'],
						'tags'  => $row['tags'],
					)
				);
			} else {
				$result = BYZO_Customer::create(
					array(
						'name'   => $row['name'],
						'email'  => $row['email'],
						'phone'  => $row['phone'],
						'notes'  => $row['notes'],
						'tags'   => $row['tags'],
						'source' => 'import',
					)
				);
			}

			if ( is_wp_error( $result ) ) {
				$skipped++;
				continue;
			}

			$imported++;
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}
}
