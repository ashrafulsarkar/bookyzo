<?php
/**
 * Plugin Name:       Bookyzo – Booking & Appointments
 * Description:        Appointments, table reservations, event tickets and generic time-slot bookings on your WordPress site — a customer-facing booking widget, email reminders, and optional Stripe payments.
 * Version:            1.0.0
 * Requires at least:  6.0
 * Requires PHP:       7.4
 * Author:             Ashraful Sarkar Naiem
 * Author URI:         https://github.com/ashrafulsarkar
 * License:            GPL v2 or later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        bookyzo
 */

defined( 'ABSPATH' ) || exit;

define( 'BYZO_VERSION', '1.0.0' );
define( 'BYZO_DB_VERSION', '3.2' );
define( 'BYZO_PLUGIN_FILE', __FILE__ );
define( 'BYZO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BYZO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BYZO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-settings.php';
require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-activator.php';
require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-deactivator.php';
require_once BYZO_PLUGIN_DIR . 'includes/class-byzo-plugin.php';

register_activation_hook( __FILE__, array( 'BYZO_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BYZO_Deactivator', 'deactivate' ) );

/**
 * Boots the plugin once all plugins are loaded, so Pro (if active) can hook
 * into Free's filters/actions from its own `plugins_loaded` callback safely.
 */
function byzo_run() {
	BYZO_Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'byzo_run' );
