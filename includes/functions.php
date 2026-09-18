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
 * Whether Bookyzo Pro is installed, active, AND currently licensed —
 * false covers all three "not fully Pro" states uniformly (Pro not
 * installed, installed-but-inactive, active-but-unlicensed), which is
 * exactly the condition under which the Free plugin's own "Get Pro" nav
 * item/page should appear (see BYZO_Plugin::register_admin_menu()/
 * routes() and ByzoSidebar.vue). Deliberately checks byzop_is_licensed()
 * itself rather than a separate Free-side option, so this can never drift
 * out of sync with what BYZOP_Plugin::register_hooks() actually gates
 * Pro's REST controllers on.
 *
 * @return bool
 */
function byzo_is_pro_licensed() {
	return function_exists( 'byzop_is_licensed' ) && byzop_is_licensed();
}

/**
 * Where the Free plugin's "Get Pro" page sends a site owner to actually
 * buy Bookyzo Pro. NO real sales/purchase URL exists yet for this product
 * (per project notes: the "bookyzo" domain itself was only ever checked
 * for availability, never actually registered) — deliberately left empty
 * rather than guessing a plausible-looking domain that may not belong to
 * this plugin's author. Override via the `byzo_get_pro_url` filter (or a
 * future BYZO_GET_PRO_URL constant, same pattern as BYZOP_API_URL) once a
 * real one exists — GetPro.vue treats an empty value as "no purchase link
 * yet" and hides the buy button rather than linking out to nothing.
 *
 * @return string
 */
function byzo_get_pro_url() {
	return apply_filters( 'byzo_get_pro_url', '' );
}
