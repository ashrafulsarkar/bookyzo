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

		wp_safe_redirect( admin_url( 'admin.php?page=bookyzo' ) );
		exit;
	}
}
