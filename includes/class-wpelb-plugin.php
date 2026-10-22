<?php
/**
 * Plugin lifecycle: intentionally no polling, API requests or publication yet.
 *
 * @package WPELB
 */

defined( 'ABSPATH' ) || exit;

final class WPELB_Plugin {
    public static function boot() {
        if ( is_admin() ) {
            require_once __DIR__ . '/class-wpelb-admin.php';
            WPELB_Admin::register();
        }
    }
}
