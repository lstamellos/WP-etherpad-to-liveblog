<?php
/**
 * Plugin lifecycle: intentionally no polling, API requests or publication yet.
 *
 * @package WPELB
 */

defined( 'ABSPATH' ) || exit;

final class WPELB_Plugin {
    public static function boot() {
        require_once __DIR__ . '/class-wpelb-github-updater.php';
        WPELB_GitHub_Updater::register();

        if ( is_admin() ) {
            require_once __DIR__ . '/class-wpelb-admin.php';
            WPELB_Admin::register();
        }
    }
}
