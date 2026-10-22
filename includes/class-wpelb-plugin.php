<?php
/** WordPress bootstrap; updates and the WP-CLI worker are separate. */
defined( 'ABSPATH' ) || exit;

final class WPELB_Plugin {
    public static function boot() {
        require_once __DIR__ . '/class-wpelb-github-updater.php';
        WPELB_GitHub_Updater::register();
        require_once __DIR__ . '/class-wpelb-etherpad-client.php';
        require_once __DIR__ . '/class-wpelb-block-parser.php';
        require_once __DIR__ . '/class-wpelb-block-matcher.php';
        require_once __DIR__ . '/class-wpelb-mapping-store.php';
        require_once __DIR__ . '/class-wpelb-liveblog-adapter.php';
        require_once __DIR__ . '/class-wpelb-sync-worker.php';
        WPELB_Sync_Worker::register();
        if ( is_admin() ) {
            require_once __DIR__ . '/class-wpelb-admin.php';
            WPELB_Admin::register();
        }
    }
}
