<?php
defined( 'ABSPATH' ) || exit;

/**
 * Redirects to the setup wizard once, right after activation — guarded
 * against bulk-activate and network-admin activation spamming redirects,
 * the standard WP plugin pattern (mirrors CBFW's activation redirect).
 */
class BYZO_Install_Redirect {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
	}

	public static function maybe_redirect() {
		if ( ! get_transient( 'byzo_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'byzo_activation_redirect' );

		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$target = self::needs_setup() ? 'admin.php?page=bookyzo#/setup' : 'admin.php?page=bookyzo';

		wp_safe_redirect( admin_url( $target ) );
		exit;
	}

	/**
	 * Whether the wizard should still be shown — false once the owner
	 * either finished it (`byzo_setup_step` reaches 'done') or explicitly
	 * skipped it. Also used by the Vue app's own bootstrap (via a
	 * REST-exposed flag, not this PHP method directly) to decide whether
	 * to auto-open the wizard on a normal page load, not just right after
	 * activation.
	 */
	public static function needs_setup() {
		if ( get_option( 'byzo_setup_skipped' ) ) {
			return false;
		}

		return 'done' !== get_option( 'byzo_setup_step', 'booking_type' );
	}
}
