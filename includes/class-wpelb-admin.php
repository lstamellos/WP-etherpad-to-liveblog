<?php
/** Authorized connection and health controls in the WordPress post editor. */
defined( 'ABSPATH' ) || exit;

final class WPELB_Admin {
    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_status_page' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
        add_action( 'admin_post_wpelb_action', array( __CLASS__, 'handle' ) );
        add_action( 'wp_ajax_wpelb_action', array( __CLASS__, 'handle' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * The WP post editor already owns a <form>; a nested form in a meta box
     * is invalid HTML and prevented connections from being saved.
     * Load a separate AJAX controller only on post editing screens.
     */
    public static function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }
        wp_enqueue_script(
            'wpelb-admin',
            plugins_url( 'assets/admin.js', WPELB_PLUGIN_FILE ),
            array(),
            WPELB_VERSION,
            true
        );
        wp_add_inline_script(
            'wpelb-admin',
            'window.wpelbAdmin=' . wp_json_encode( array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) ) . ';',
            'before'
        );
    }

    public static function add_status_page() {
        add_management_page( 'Etherpad to Liveblog', 'Etherpad to Liveblog', 'manage_options', 'wp-etherpad-to-liveblog', array( __CLASS__, 'status_page' ) );
    }

    public static function status_page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed' ); }
        echo '<div class="wrap"><h1>Etherpad to Liveblog</h1><p>Version ' . esc_html( WPELB_VERSION ) . '</p>';
        echo '<p>Liveblog API: ' . esc_html( class_exists( 'WPCOM_Liveblog_Entry' ) ? 'Detected' : 'Missing' ) . '</p>';
        echo '<p>Public Etherpad exports: No API credentials required.</p>';
        echo '<p>Automatic sync: native Liveblog polling, optional signed Etherpad webhook and WP-Cron fallback. Recommended: external system cron every minute; no systemd service required.</p>';
        echo '<p>Signed webhook: ' . esc_html( defined( 'WPELB_WEBHOOK_SECRET' ) && is_string( WPELB_WEBHOOK_SECRET ) && strlen( WPELB_WEBHOOK_SECRET ) >= 32 ? 'Ready' : 'Not configured (optional)' ) . '</p>';
        echo '<p>WP-Cron requires visits reaching WordPress. For guaranteed recovery install a separate one-minute system cron job. Manual fallback: <code>wp etherpad-liveblog run --once</code>.</p></div>';
    }

    private static function has_credentials() {
        return ( defined( 'WPELB_ETHERPAD_BEARER_TOKEN' ) && WPELB_ETHERPAD_BEARER_TOKEN ) ||
            ( defined( 'WPELB_ETHERPAD_API_KEY' ) && WPELB_ETHERPAD_API_KEY ) ||
            ( defined( 'WPELB_ETHERPAD_CLIENT_ID' ) && WPELB_ETHERPAD_CLIENT_ID && defined( 'WPELB_ETHERPAD_CLIENT_SECRET' ) && WPELB_ETHERPAD_CLIENT_SECRET );
    }

    public static function add_box() {
        if ( class_exists( 'WPCOM_Liveblog_Entry' ) ) {
            add_meta_box( 'wpelb-connection', 'Etherpad → Liveblog', array( __CLASS__, 'render_box' ), 'post', 'side', 'default' );
        }
    }

    public static function render_box( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( 'publish_posts' ) ) {
            echo '<p>Insufficient permissions.</p>';
            return;
        }
        $state = WPELB_Mapping_Store::get( $post->ID );
        $state_status = $state['status'] ?? '';
        echo '<p>Liveblog: <strong>' . esc_html( class_exists( 'WPCOM_Liveblog' ) ? (string) WPCOM_Liveblog::get_liveblog_state( $post->ID ) : 'unavailable' ) . '</strong></p>';
        if ( $state_status ) {
            echo '<p>Pad: <code>' . esc_html( isset( $state['origin'] ) ? $state['origin'] . '/p/' . $state['pad_id'] : $state['pad_id'] ) . '</code><br>Status: <strong>' . esc_html( $state_status ) . '</strong><br>Revision: ' . esc_html( (string) $state['revision'] ) . '</p>';
            if ( ! empty( $state['last_error'] ) ) {
                echo '<p role="alert"><strong>Error:</strong> ' . esc_html( $state['last_error'] ) . '</p>';
            }
            $published = $pending = $conflicts = 0;
            foreach ( $state['blocks'] as $block ) {
                if ( 'published' === $block['status'] || 'dirty' === $block['status'] ) { ++$published; }
                if ( 'pending' === $block['status'] ) { ++$pending; }
                if ( 'conflict' === $block['status'] ) { ++$conflicts; }
            }
            echo '<p>Published blocks: ' . esc_html( (string) $published ) . '; pending reconciliation: ' . esc_html( (string) $pending ) . '; conflicts: ' . esc_html( (string) $conflicts ) . '</p>';
            if ( $pending || $conflicts ) {
                echo '<p>Manual review required. Do not reconnect without reconciling outstanding entries.</p>';
            }
            self::form_open( $post->ID );
            if ( 'active' === $state_status ) {
                echo '<button type="button" class="button" data-wpelb-operation="pause">Pause sync</button> ';
            } else {
                echo '<button type="button" class="button" data-wpelb-operation="resume">Resume sync</button> ';
            }
            echo '<button type="button" class="button" data-wpelb-operation="disconnect">Disconnect</button>';
            echo '</div>';
        } else {
            echo '<p>Connect a public HTTPS Etherpad pad (any instance). No Etherpad API credentials required. Previous POSTOK lines are ignored on first link.</p>';
            self::form_open( $post->ID );
            echo '<label for="wpelb-pad-url">Etherpad URL</label><input id="wpelb-pad-url" class="widefat" type="url" name="pad_url" placeholder="https://pad.omniatv.com/p/example" required />';
            echo '<p><label><input type="checkbox" name="pad_acl_ack" value="1" required /> I confirm that only trusted reporters can edit this pad.</label></p>';
            echo '<button type="button" class="button button-primary" data-wpelb-operation="connect">Connect pad</button></div>';
        }
    }

    private static function form_open( $post_id ) {
        // Not a HTML form: WordPress post.php already wraps all meta boxes in
        // its own form. The AJAX button carries its own post-bound nonce.
        echo '<div class="wpelb-action-group" data-post-id="' . esc_attr( (string) $post_id ) .
            '" data-nonce="' . esc_attr( wp_create_nonce( 'wpelb_action_' . $post_id ) ) . '">';
        echo '<p class="wpelb-feedback" role="status" aria-live="polite" hidden></p>';
    }

    public static function handle() {
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'publish_posts' ) ) {
            if ( wp_doing_ajax() ) {
                wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
            }
            wp_die( 'Permission denied', '', array( 'response' => 403 ) );
        }
        if ( wp_doing_ajax() ) {
            if ( ! check_ajax_referer( 'wpelb_action_' . $post_id, 'nonce', false ) ) {
                wp_send_json_error( array( 'message' => 'Security token expired; reload the editor.' ), 403 );
            }
        } else {
            check_admin_referer( 'wpelb_action_' . $post_id );
        }
        $operation = sanitize_key( wp_unslash( $_POST['wpelb_operation'] ?? '' ) );
        if ( ! WPELB_Mapping_Store::acquire( $post_id ) ) {
            if ( wp_doing_ajax() ) {
                wp_send_json_error( array( 'message' => 'Synchronization is busy. Investigate the existing lock.' ), 409 );
            }
            wp_die( 'Synchronization is busy. Try again shortly; a stale lock needs operator review.', 'Etherpad to Liveblog', array( 'response' => 409 ) );
        }
        wp_cache_delete( $post_id, 'post_meta' );
        $state = WPELB_Mapping_Store::get( $post_id );
        $result = true;
        if ( 'connect' === $operation ) {
            if ( $state || empty( $_POST['pad_acl_ack'] ) || ! WPELB_Liveblog_Adapter::available( $post_id ) ) {
                $result = new WP_Error( 'wpelb_connect', 'Existing connection, missing editor acknowledgement or inactive Liveblog.' );
            } else {
                $target = WPELB_Public_Export_Client::connection_from_url( esc_url_raw( wp_unslash( $_POST['pad_url'] ?? '' ) ) );
                if ( is_wp_error( $target ) ) {
                    $result = $target;
                } else {
                    try {
                        $client = new WPELB_Public_Export_Client( $target['origin'] );
                        $rev = $client->head( $target['pad_id'] );
                        $text = is_wp_error( $rev ) ? $rev : $client->text( $target['pad_id'], $rev );
                        if ( is_wp_error( $text ) ) {
                            $result = $text;
                        } elseif ( strlen( $text ) > 800000 ) {
                            $result = new WP_Error( 'wpelb_large', 'Pad is too large.' );
                        } else {
                            $parsed = WPELB_Block_Parser::parse( $text );
                            $anchors = WPELB_Block_Matcher::anchors( $text, $parsed['markers'] );
                            if ( $parsed['error'] || is_wp_error( $anchors ) ) {
                                $result = new WP_Error( 'wpelb_invalid', 'Pad has invalid markers or encoding.' );
                            } else {
                                $pad_ref = $target['origin'] . '/p/' . $target['pad_id'];
                                $reserved = WPELB_Mapping_Store::reserve( $post_id, $pad_ref );
                                if ( is_wp_error( $reserved ) ) {
                                    $result = $reserved;
                                } else {
                                    $blocks = array();
                                    foreach ( $parsed['blocks'] as $content ) {
                                        $blocks[] = array( 'status' => 'skipped' );
                                    }
                                    $state_data = array(
                                        'origin' => $target['origin'],
                                        'pad_id' => $target['pad_id'],
                                        'pad_ref' => $pad_ref,
                                        'public_hash' => $client->last_text_hash(),
                                        'status' => 'active',
                                        'revision' => $rev,
                                        'anchors' => $anchors,
                                        'blocks' => $blocks,
                                        'author_id' => get_current_user_id(),
                                        'last_error' => '',
                                    );
                                    if ( false === WPELB_Mapping_Store::save( $post_id, $state_data ) ) {
                                        WPELB_Mapping_Store::unreserve( $post_id, $pad_ref );
                                        $result = new WP_Error( 'wpelb_storage', 'Could not persist the Etherpad connection.' );
                                    }
                                }
                            }
                        }
                    } catch ( InvalidArgumentException $e ) {
                        $result = new WP_Error( 'wpelb_origin', $e->getMessage() );
                    }
                }
            }
        } elseif ( 'pause' === $operation || 'resume' === $operation ) {
            if ( ! $state ) {
                $result = new WP_Error( 'wpelb_state', 'No pad is connected.' );
            } else {
                $state['status'] = 'pause' === $operation ? 'paused' : 'active';
                WPELB_Mapping_Store::save( $post_id, $state );
            }
        } elseif ( 'disconnect' === $operation && $state ) {
            foreach ( $state['blocks'] as $block ) {
                if ( 'pending' === $block['status'] ) {
                    $result = new WP_Error( 'wpelb_pending', 'Cannot disconnect until the pending operation has been manually reconciled.' );
                    break;
                }
            }
            if ( ! is_wp_error( $result ) ) {
                WPELB_Mapping_Store::unreserve( $post_id, $state['pad_ref'] ?? $state['pad_id'] );
                delete_post_meta( $post_id, WPELB_Mapping_Store::META );
            }
        } else {
            $result = new WP_Error( 'wpelb_action', 'Unsupported action.' );
        }
        WPELB_Mapping_Store::release( $post_id );
        $message = is_wp_error( $result ) ? $result->get_error_message() : 'Connection updated.';
        if ( wp_doing_ajax() ) {
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $message ), 400 );
            }
            wp_cache_delete( $post_id, 'post_meta' );
            ob_start();
            self::render_box( get_post( $post_id ) );
            $html = ob_get_clean();
            wp_send_json_success( array( 'message' => $message, 'html' => $html ) );
        }
        wp_die( esc_html( $message ) . ' <a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">Back to Liveblog</a>', 'Etherpad to Liveblog', array( 'response' => is_wp_error( $result ) ? 400 : 200 ) );
    }
}
