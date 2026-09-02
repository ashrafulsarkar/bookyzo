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
