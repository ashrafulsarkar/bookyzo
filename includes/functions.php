<?php
defined( 'ABSPATH' ) || exit;

/**
 * Template loading helpers — the same `byzo_get_template()` pattern as
 * CBFW's `cbfw_get_template()` (DETAILED-SPEC.md §A6): a theme can
 * override any plugin template by placing a same-named file under its
 * own `bookyzo/` subfolder, checked child-theme first, then parent,
 * falling back to the plugin's own `templates/` copy.
 */

function byzo_locate_template( $template_name ) {
	$theme_file = trailingslashit( get_stylesheet_directory() ) . 'bookyzo/' . $template_name;
	if ( file_exists( $theme_file ) ) {
		return $theme_file;
	}

	if ( is_child_theme() ) {
		$parent_file = trailingslashit( get_template_directory() ) . 'bookyzo/' . $template_name;
		if ( file_exists( $parent_file ) ) {
			return $parent_file;
		}
	}

	return BYZO_PLUGIN_DIR . 'templates/' . $template_name;
}

/**
 * @param string $template_name Relative to templates/, e.g. 'emails/notification.php'.
 * @param array  $args          Extracted as local variables in the template's scope.
 * @return string
 */
function byzo_get_template( $template_name, $args = array() ) {
	$located = byzo_locate_template( $template_name );

	if ( ! file_exists( $located ) ) {
		return '';
	}

	if ( $args && is_array( $args ) ) {
		extract( $args ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- deliberate: this is the whole point of byzo_get_template(), matching the CBFW convention it mirrors, exposing $args as named variables to the included template file.
	}

	ob_start();
	include $located;
	return ob_get_clean();
}

/**
 * Enqueues the stylesheet shared by the plugin's standalone pages (the
 * guest manage-booking page and the unsubscribe page). They bypass the
 * theme, so each template prints it itself with wp_print_styles().
 */
function byzo_enqueue_standalone_page_style() {
	wp_enqueue_style( 'byzo-standalone-page', BYZO_PLUGIN_URL . 'assets/css/standalone-page.css', array(), BYZO_VERSION );
}

/**
 * Whether the "Get Pro" page is offered at all: its sidebar row, its WP
 * submenu page, the Plugins-screen link and the setup wizard's Pro step.
 * On whenever Bookyzo Pro isn't active (owner's decision 2026-10-31, now
 * that byzo_get_pro_url() has a real purchase page); the
 * `byzo_show_get_pro` filter can still turn it off.
 *
 * Only ever asks whether Pro is active — never about Pro's license. Free
 * reads no license state at all (WordPress.org review: "Remove all
 * license checks"); an installed Pro shows "Activate License" itself.
 *
 * @return bool
 */
function byzo_show_get_pro() {
	return (bool) apply_filters( 'byzo_show_get_pro', true ) && ! defined( 'BYZOP_VERSION' );
}

/**
 * Where the Free plugin's "Get Pro" page sends a site owner to buy
 * Bookyzo Pro. Override via the `byzo_get_pro_url` filter.
 *
 * @return string
 */
function byzo_get_pro_url() {
	return apply_filters( 'byzo_get_pro_url', 'https://codeholt.com/products/bookyzo-pro/' );
}

/**
 * The one-click "Activate" link for a Bookyzo Pro that is installed but
 * switched off, so the Get Pro page and the setup wizard can offer that
 * instead of a purchase the owner already made. Empty when Pro isn't
 * installed, is already active, or the user can't activate plugins.
 *
 * @return string
 */
function byzo_pro_activate_url() {
	$plugin = 'bookyzo-pro/bookyzo-pro.php';

	if ( defined( 'BYZOP_VERSION' ) || ! current_user_can( 'activate_plugins' ) || ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
		return '';
	}

	// A raw URL (not wp_nonce_url(), which HTML-encodes the `&`): it goes
	// to the Vue app as JSON and is escaped wherever PHP prints it.
	return add_query_arg(
		array(
			'action'   => 'activate',
			'plugin'   => rawurlencode( $plugin ),
			'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $plugin ),
		),
		admin_url( 'plugins.php' )
	);
}
