<?php
/** Revision-driven synchronizer with per-published-block 5s debounce. */
defined( 'ABSPATH' ) || exit;

final class WPELB_Sync_Worker {
    const MAX_REVISION_GAP = 500;

    public static function register() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'etherpad-liveblog', __CLASS__ );
        }
    }

    /** wp etherpad-liveblog run [--once] [--interval=2] */
    public function run( $args, $assoc ) {
        $once = isset( $assoc['once'] );
        $interval = isset( $assoc['interval'] ) ? max( 1, min( 30, (int) $assoc['interval'] ) ) : 2;
        do {
            $posts = WPELB_Mapping_Store::linked_posts();
            foreach ( $posts as $post_id ) {
                wp_cache_delete( $post_id, 'post_meta' ); // Reflect editor-side pause/disconnect in long-lived CLI processes.
                $state = WPELB_Mapping_Store::get( $post_id );
                if ( ( $state['status'] ?? '' ) !== 'active' ) {
                    continue;
                }
                $result = self::sync( $post_id );
                if ( is_wp_error( $result ) ) {
                    WP_CLI::warning( 'Liveblog ' . $post_id . ': ' . $result->get_error_message() );
                }
            }
            if ( ! $once ) {
                sleep( $interval );
            }
        } while ( ! $once );
        WP_CLI::success( 'Synchronization pass finished.' );
    }

    public static function sync( $post_id ) {
        wp_cache_delete( $post_id, 'post_meta' );
        if ( ! WPELB_Mapping_Store::acquire( $post_id ) ) {
            return new WP_Error( 'wpelb_locked', 'A synchronization lock exists; investigate if stale.' );
        }
        try {
            return self::process( $post_id );
        } finally {
            WPELB_Mapping_Store::release( $post_id );
        }
    }

    private static function fail( $post_id, &$state, $error, $pause = false ) {
        $state['last_error'] = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
        if ( $pause ) {
            $state['status'] = 'paused';
        }
        WPELB_Mapping_Store::save( $post_id, $state );
        return is_wp_error( $error ) ? $error : new WP_Error( 'wpelb_sync', (string) $error );
    }

    private static function process( $post_id ) {
        $state = WPELB_Mapping_Store::get( $post_id );
        if ( ( $state['status'] ?? '' ) !== 'active' ) {
            return true;
        }
        if ( ! WPELB_Liveblog_Adapter::available( $post_id ) ) {
            return self::fail( $post_id, $state, 'The Liveblog is not enabled.', true );
        }
        try {
            $client = new WPELB_Etherpad_Client();
        } catch ( InvalidArgumentException $e ) {
            return self::fail( $post_id, $state, $e->getMessage(), true );
        }
        $head = $client->head( $state['pad_id'] );
        if ( is_wp_error( $head ) ) {
            return self::fail( $post_id, $state, $head );
        }
        $previous = (int) $state['revision'];
        if ( $head < $previous || $head - $previous > self::MAX_REVISION_GAP ) {
            return self::fail( $post_id, $state, 'Etherpad revision history reset or large gap; manual review required.', true );
        }
        foreach ( $state['blocks'] as $block ) {
            if ( 'pending' === ( $block['status'] ?? '' ) ) {
                return self::fail( $post_id, $state, 'Pending Liveblog operation needs manual reconciliation.', true );
            }
        }
        $blocks = $state['blocks'];
        if ( $head > $previous ) {
            $anchors = $state['anchors'];
            for ( $rev = $previous + 1; $rev <= $head; $rev++ ) {
                $changeset = $client->changeset( $state['pad_id'], $rev );
                if ( is_wp_error( $changeset ) ) {
                    return self::fail( $post_id, $state, $changeset );
                }
                $anchors = WPELB_Block_Matcher::apply( $anchors, $changeset );
                if ( is_wp_error( $anchors ) ) {
                    return self::fail( $post_id, $state, $anchors, true );
                }
            }
            $text = $client->text( $state['pad_id'], $head );
            if ( is_wp_error( $text ) ) { return self::fail( $post_id, $state, $text ); }
            if ( strlen( $text ) > 800000 ) { return self::fail( $post_id, $state, 'Pad too large for configured safety limit.', true ); }
            $parsed = WPELB_Block_Parser::parse( $text );
            if ( $parsed['error'] ) {
                // An unfinished NOPUB can arise while typing: temporary error, not a destructive rewrite.
                return self::fail( $post_id, $state, $parsed['error'] );
            }
            $actual = WPELB_Block_Matcher::anchors( str_replace( array( "\r\n", "\r" ), "\n", $text ), $parsed['markers'] );
            if ( is_wp_error( $actual ) ) { return self::fail( $post_id, $state, $actual, true ); }
            if ( ! WPELB_Block_Matcher::verify( $anchors, $actual ) ) {
                return self::fail( $post_id, $state, 'POSTOK marker structure changed; publication paused for safety.', true );
            }
            if ( count( $actual ) < count( $blocks ) ) {
                return self::fail( $post_id, $state, 'Missing published block boundary.', true );
            }
            $state['anchors'] = $actual;
            $state['revision'] = $head;
            foreach ( $parsed['blocks'] as $i => $content ) {
                if ( ! isset( $blocks[$i] ) ) {
                    $blocks[$i] = array( 'status' => '' === $content ? 'skipped' : 'ready' );
                }
                $hash = hash( 'sha256', WPELB_Block_Parser::html( $content ) );
                if ( 'published' === $blocks[$i]['status'] || 'dirty' === $blocks[$i]['status'] ) {
                    if ( ( $blocks[$i]['observed_hash'] ?? $blocks[$i]['published_hash'] ) !== $hash ) {
                        $blocks[$i]['observed_hash'] = $hash;
                        $blocks[$i]['changed_at'] = time();
                    }
                    if ( $hash === $blocks[$i]['published_hash'] ) {
                        $blocks[$i]['status'] = 'published';
                    } else {
                        $blocks[$i]['status'] = 'dirty';
                    }
                }
                $blocks[$i]['content'] = $content;
            }
            $state['blocks'] = $blocks;
            WPELB_Mapping_Store::save( $post_id, $state );
        }
        // Must also run on unchanged revisions: debounce belongs to each block,
        // not to overall Etherpad typing or the next revision arriving.
        foreach ( $state['blocks'] as $i => $block ) {
            if ( 'ready' !== $block['status'] && 'dirty' !== $block['status'] ) { continue; }
            if ( 'dirty' === $block['status'] && time() - (int) ( $block['changed_at'] ?? time() ) < 5 ) { continue; }
            if ( '' === trim( $block['content'] ) ) {
                $state['blocks'][$i]['status'] = 'conflict';
                $state['last_error'] = 'Empty block requires manual review; never delete an entry implicitly.';
                WPELB_Mapping_Store::save( $post_id, $state );
                continue;
            }
            $html = WPELB_Block_Parser::html( $block['content'] );
            if ( 'dirty' === $block['status'] ) {
                $current = WPELB_Liveblog_Adapter::stored_content( $block['entry_id'] );
                if ( null === $current || $current !== ( $block['wp_baseline'] ?? null ) ) {
                    $state['blocks'][$i]['status'] = 'conflict';
                    $state['last_error'] = 'Manual WordPress edit or missing original entry; conflict in block ' . ( $i + 1 );
                    WPELB_Mapping_Store::save( $post_id, $state );
                    continue;
                }
            }
            // Write-ahead safety: if PHP dies in the insert/update window, a
            // pending operation is left for manual reconciliation, never replayed.
            $action = $block['status'];
            $state['blocks'][$i]['status'] = 'pending';
            WPELB_Mapping_Store::save( $post_id, $state );
            $result = 'ready' === $action
                ? WPELB_Liveblog_Adapter::insert( $post_id, $html, (int) $state['author_id'] )
                : WPELB_Liveblog_Adapter::update( $post_id, (int) $block['entry_id'], $html );
            if ( is_wp_error( $result ) ) {
                $state['last_error'] = $result->get_error_message();
                WPELB_Mapping_Store::save( $post_id, $state );
                // Pending is intentionally sticky: a WP_Error cannot always
                // prove that no entry was committed before a late failure.
                return $result;
            }
            if ( 'ready' === $action ) {
                $state['blocks'][$i]['entry_id'] = $result;
            } else {
                $state['blocks'][$i]['last_event_id'] = $result;
            }
            $state['blocks'][$i]['status'] = 'published';
            $state['blocks'][$i]['published_hash'] = hash( 'sha256', $html );
            $state['blocks'][$i]['observed_hash'] = $state['blocks'][$i]['published_hash'];
            $state['blocks'][$i]['wp_baseline'] = WPELB_Liveblog_Adapter::stored_content( $state['blocks'][$i]['entry_id'] );
            unset( $state['blocks'][$i]['changed_at'] );
            $state['last_success'] = time();
            $state['last_error'] = '';
            WPELB_Mapping_Store::save( $post_id, $state );
        }
        return true;
    }
}
