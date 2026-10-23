<?php
/**
 * Opportunistic synchronization from Liveblog's native REST polling.
 *
 * No persistent worker: native Liveblog reads trigger post-scoped background
 * sync after PHP-FPM has finished the response; WP-Cron provides a one-minute
 * fallback while the site has WordPress traffic.
 */
defined( 'ABSPATH' ) || exit;

final class WPELB_Liveblog_Poll_Trigger {
    const MIN_POLL_SECONDS = 5;
    const CRON_HOOK = 'wpelb_periodic_sync';

    public static function register() {
        add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'maybe_trigger' ), 10, 3 );
        add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'cron_sync' ) );
        add_action( 'wpelb_deferred_poll_sync', array( __CLASS__, 'sync' ), 10, 1 );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'wpelb_one_minute', self::CRON_HOOK );
        }
    }

    public static function cron_schedules( $schedules ) {
        $schedules['wpelb_one_minute'] = array(
            'interval' => 60,
            'display'  => 'Once per minute (Etherpad Liveblog)',
        );
        return $schedules;
    }

    /**
     * WordPress calls this after REST route permission checks.
     * Match the exact Liveblog polling route and the URL-scoped post ID, never
     * untrusted query/body parameters or unrelated Liveblog REST methods.
     */
    public static function maybe_trigger( $response, $handler, $request ) {
        if ( is_wp_error( $response ) || 'GET' !== $request->get_method() ) {
            return $response;
        }
        $route = $request->get_route();
        if ( ! preg_match( '~^/liveblog/v1/([0-9]+)/entries/[0-9]+/[0-9]+/?$~D', $route, $matches ) ) {
            return $response;
        }
        $id = (int) $matches[1];
        $url = $request->get_url_params();
        if ( $id < 1 || ! isset( $url['post_id'] ) || $id !== (int) $url['post_id'] ) {
            return $response;
        }

        $state = WPELB_Mapping_Store::get( $id );
        if ( 'active' !== ( $state['status'] ?? '' ) ) {
            return $response;
        }
        if ( ! self::claim_slot( $id ) ) {
            return $response;
        }

        // A single, throttled callback runs only after the REST response has
        // been constructed. Its expensive work cannot block unrelated polls.
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            add_action( 'shutdown', static function () use ( $id ) {
                // Send Liveblog polling response before fetching pad exports.
                fastcgi_finish_request();
                self::sync( $id );
            }, 0 );
        } else {
            // On non-FPM setups, never hold a reader's 10-second poll open:
            // defer through standard WP-Cron rather than run inline.
            if ( ! wp_next_scheduled( 'wpelb_deferred_poll_sync', array( $id ) ) ) {
                wp_schedule_single_event( time(), 'wpelb_deferred_poll_sync', array( $id ) );
            }
        }
        return $response;
    }

    /**
     * DB-backed atomic per-post throttle across concurrent PHP requests.
     * WPELB_Mapping_Store::acquire() remains the publication single-flight lock.
     */
    private static function claim_slot( $id ) {
        global $wpdb;
        $key = 'wpelb_poll_last_' . (int) $id;
        $now = time();
        if ( add_option( $key, (string) $now, '', false ) ) {
            return true;
        }
        $previous = get_option( $key, '0' );
        if ( ! is_scalar( $previous ) || $now - (int) $previous < self::MIN_POLL_SECONDS ) {
            return false;
        }
        // CAS: only one of many simultaneous readers can claim the old slot.
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            (string) $now, $key, (string) $previous
        ) );
        if ( 1 !== (int) $updated ) {
            return false;
        }
        wp_cache_delete( $key, 'options' );
        return true;
    }

    public static function sync( $id ) {
        $state = WPELB_Mapping_Store::get( (int) $id );
        if ( 'active' !== ( $state['status'] ?? '' ) ) {
            return;
        }
        // Existing worker handles idempotency, unpublished revisions, conflicts,
        // locks and write-ahead pending entries. No second publishing path.
        $result = WPELB_Sync_Worker::sync( (int) $id );
        if ( is_wp_error( $result ) && 'wpelb_locked' !== $result->get_error_code() ) {
            error_log( '[WPELB] Liveblog ' . (int) $id . ': ' . $result->get_error_message() );
        }
    }

    public static function cron_sync() {
        foreach ( WPELB_Mapping_Store::linked_posts() as $id ) {
            self::sync( (int) $id );
        }
    }
}
