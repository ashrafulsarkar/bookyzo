<?php
/**
 * Markup for the Bookyzo admin page's Vue mount point, rendered by
 * BYZO_Plugin::render_admin_root(). Pulled into its own template file
 * (matching the wp-vue.php starter's wp-src/admin/admin.php pattern)
 * rather than an inline echo, since this is genuinely a view — not
 * logic — and will grow (loading state, no-JS fallback) as the admin
 * app matures.
 *
 * The spinner below (styled by BYZO_Admin_Assets::enqueue_boot_loader_style(),
 * same pattern as BackReload's own pre-mount root placeholder) is only ever
 * visible for the moment between this page loading and Vue's app.mount()
 * replacing the div's contents — previously bare "Loading…" text with no
 * styling of its own until the built bundle's CSS caught up.
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="byzo-admin-app">
	<div class="byzo-boot-loader">
		<span class="byzo-boot-spinner" aria-hidden="true"></span>
		<?php esc_html_e( 'Loading…', 'bookyzo' ); ?>
	</div>
</div>
