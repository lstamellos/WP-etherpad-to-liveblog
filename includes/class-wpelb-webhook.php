<?php
/**
 * Signed Etherpad padUpdate notifications.
 *
 * This endpoint never publishes directly or accepts arbitrary posts from an
 * external server. It validates the HMAC, resolves an existing matching
 * mapping and calls the same single-flight synchronizer as the CLI.
 */
defined( 'ABSPATH' ) || exit;

final class WPELB_Webhook {
    const NAMESPACE_NAME = 'wpelb/v1';
    const ROUTE = '/notify';
    const MAX_BODY_BYTES = 2048;
    const CLOCK_SKEW_SECONDS = 300;

    public static function register() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
        add_action( 'wpelb_webhook_deferred_sync', array( __CLASS__, 'sync_post' ), 10, 1 );
    }

    public static function register_route() {
        register_rest_route( self::NAMESPACE_NAME, self::ROUTE, array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'receive' ),
            // Machine authentication uses a signed, short-lived request, not
            // WP accounts/nonces. verify_signature() runs before any effect.
            'permission_callback' => '__return_true',
        ) );
    }

    private static function configured_secret() {
        if ( ! defined( 'WPELB_WEBHOOK_SECRET' ) ||
            ! is_string( WPELB_WEBHOOK_SECRET ) ||
            strlen( WPELB_WEBHOOK_SECRET ) < 32 ) {
            return null;
        }
        return WPELB_WEBHOOK_SECRET;
    }

    private static function reject( $code, $message, $status ) {
        return new WP_Error( $code, $message, array( 'status' => $status ) );
    }

    public static function receive( $request ) {
        $secret = self::configured_secret();
        if ( null === $secret ) {
            return self::reject( 'wpelb_not_configured', 'Etherpad webhook is not configured.', 503 );
        }
        $raw = $request->get_body();
        if ( ! is_string( $raw ) || strlen( $raw ) < 2 ||
            strlen( $raw ) > self::MAX_BODY_BYTES ) {
            return self::reject( 'wpelb_invalid_body', 'Invalid webhook size.', 400 );
        }

        $stamp = (string) $request->get_header( 'x-wpelb-timestamp' );
        $event = (string) $request->get_header( 'x-wpelb-event-id' );
        $signature = (string) $request->get_header( 'x-wpelb-signature' );
        if ( ! preg_match( '/^[0-9]{10,11}$/D', $stamp ) ||
            abs( time() - (int) $stamp ) > self::CLOCK_SKEW_SECONDS ||
            ! preg_match( '/^[a-zA-Z0-9-]{16,80}$/D', $event ) ||
            ! preg_match( '/^sha256=[a-f0-9]{64}$/D', $signature ) ) {
            return self::reject( 'wpelb_unauthorized', 'Invalid webhook authentication.', 403 );
        }
        $expected = 'sha256=' . hash_hmac(
            'sha256', $stamp . "\n" . $event . "\n" . $raw, $secret
        );
        if ( ! hash_equals( $expected, $signature ) ) {
            return self::reject( 'wpelb_unauthorized', 'Invalid webhook authentication.', 403 );
        }

        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return self::reject( 'wpelb_invalid_payload', 'Malformed webhook JSON.', 400 );
        }
        $origin = $data['origin'] ?? '';
        $pad = $data['pad_id'] ?? '';
        $revision = $data['revision'] ?? null;
        if ( ! is_string( $origin ) ||
            ! preg_match( '~^https://(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$~D', $origin ) ||
            ! is_string( $pad ) ||
            ! preg_match( '/^[a-zA-Z0-9_.\\-$]{1,200}$/D', $pad ) ||
            ! is_int( $revision ) || $revision < 0 ) {
            return self::reject( 'wpelb_invalid_payload', 'Invalid pad identity or revision.', 400 );
        }

        $target_id = 0;
        foreach ( WPELB_Mapping_Store::linked_posts() as $post_id ) {
            $state = WPELB_Mapping_Store::get( (int) $post_id );
            if ( 'active' !== ( $state['status'] ?? '' ) ) {
                continue;
            }
            if ( $origin === ( $state['origin'] ?? null ) && $pad === ( $state['pad_id'] ?? null ) ) {
                $target_id = (int) $post_id;
                break;
            }
        }

        // A valid event for an unlinked pad is harmless; no post or foreign
        // pad identity in the payload can become an arbitrary write target.
        if ( ! $target_id ) {
            return new WP_REST_Response( array( 'accepted' => false ), 202 );
        }

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            add_action( 'shutdown', static function () use ( $target_id ) {
                fastcgi_finish_request();
                WPELB_Webhook::sync_post( $target_id );
            }, 0 );
        } elseif ( ! wp_next_scheduled( 'wpelb_webhook_deferred_sync', array( $target_id ) ) ) {
            wp_schedule_single_event( time(), 'wpelb_webhook_deferred_sync', array( $target_id ) );
        }

        // The same sync lock protects against repeated valid webhook events,
        // and the system cron provides independent recovery after a crash.
        return new WP_REST_Response( array( 'accepted' => true ), 202 );
    }

    public static function sync_post( $post_id ) {
        $post_id = (int) $post_id;
        $state = WPELB_Mapping_Store::get( $post_id );
        if ( 'active' !== ( $state['status'] ?? '' ) ) {
            return;
        }
        $result = WPELB_Sync_Worker::sync( $post_id );
        if ( is_wp_error( $result ) && 'wpelb_locked' !== $result->get_error_code() ) {
            error_log( '[WPELB] Webhook sync for post ' . $post_id . ': ' . $result->get_error_message() );
        }
    }
}
