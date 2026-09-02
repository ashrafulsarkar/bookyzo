<?php
/**
 * Markup for the Bookyzo admin page's Vue mount point, rendered by
 * BYZO_Plugin::render_admin_root(). Pulled into its own template file
 * (matching the wp-vue.php starter's wp-src/admin/admin.php pattern)
 * rather than an inline echo, since this is genuinely a view — not
 * logic — and will grow (loading state, no-JS fallback) as the admin
 * app matures.
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="byzo-admin-app"><?php esc_html_e( 'Loading…', 'bookyzo' ); ?></div>
