<?php
/**
 * Plugin Name: WP Etherpad to Liveblog
 * Plugin URI: https://github.com/lstamellos/WP-etherpad-to-liveblog
 * Description: Development scaffold for publishing Etherpad reports to Automattic Liveblog. Synchronization is not implemented or enabled.
 * Version: 0.1.1
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: OmniaTV contributors
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/lstamellos/WP-etherpad-to-liveblog
 * Text Domain: wp-etherpad-to-liveblog
 */

defined( 'ABSPATH' ) || exit;

define( 'WPELB_VERSION', '0.1.1' );
define( 'WPELB_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-wpelb-plugin.php';

add_action( 'plugins_loaded', array( 'WPELB_Plugin', 'boot' ), 20 );
