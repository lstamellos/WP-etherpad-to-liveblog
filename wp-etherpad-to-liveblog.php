<?php
/**
 * Plugin Name: WP Etherpad to Liveblog
 * Plugin URI: https://github.com/lstamellos/WP-etherpad-to-liveblog
 * Description: Publish Etherpad POSTOK sections to Automattic Liveblog, with NOPUB exclusion and per-entry edit synchronization.
 * Version: 0.4.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: OmniaTV contributors
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/lstamellos/WP-etherpad-to-liveblog
 * Text Domain: wp-etherpad-to-liveblog
 */

defined( 'ABSPATH' ) || exit;

define( 'WPELB_VERSION', '0.4.0' );
define( 'WPELB_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-wpelb-plugin.php';
add_action( 'plugins_loaded', array( 'WPELB_Plugin', 'boot' ), 20 );
