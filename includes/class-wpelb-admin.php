<?php
/** Authorized connection and health controls in the WordPress post editor. */
defined( 'ABSPATH' ) || exit;

final class WPELB_Admin {
    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_status_page' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
        add_action( 'admin_post_wpelb_action', array( __CLASS__, 'handle' ) );
    }

    public static function add_status_page() {
        add_management_page( 'Etherpad to Liveblog', 'Etherpad to Liveblog', 'manage_options', 'wp-etherpad-to-liveblog', array( __CLASS__, 'status_page' ) );
    }

    public static function status_page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed' ); }
        echo '<div class="wrap"><h1>Etherpad to Liveblog</h1><p>Version ' . esc_html( WPELB_VERSION ) . '</p>';
        echo '<p>Liveblog API: ' . esc_html( class_exists( 'WPCOM_Liveblog_Entry' ) ? 'Detected' : 'Missing' ) . '</p>';
        echo '<p>Etherpad credentials: ' . esc_html( self::has_credentials() ? 'Configured' : 'Missing' ) . '</p>';
        echo '<p>WP-CLI worker required: <code>wp etherpad-liveblog run --interval=2</code> (configure persistent systemd service).</p>';
        echo '<p>No background worker is launched by activating this plugin. Publishing is disabled until a pad is linked and the worker is running.</p></div>';
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
            echo '<p>Pad: <code>' . esc_html( $state['pad_id'] ) . '</code><br>Status: <strong>' . esc_html( $state_status ) . '</strong><br>Revision: ' . esc_html( (string) $state['revision'] ) . '</p>';
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
                echo '<button class="button" name="wpelb_operation" value="pause">Pause sync</button> ';
            } else {
                echo '<button class="button" name="wpelb_operation" value="resume">Resume sync</button> ';
            }
            echo '<button class="button" name="wpelb_operation" value="disconnect" onclick="return confirm(\'Disconnect this pad? Published entries are preserved.\')">Disconnect</button>';
            echo '</form>';
        } else {
            echo '<p>Connect an existing pad. Previous POSTOK lines are ignored on first link.</p>';
            self::form_open( $post->ID );
            echo '<label for="wpelb-pad-url">Etherpad URL</label><input id="wpelb-pad-url" class="widefat" type="url" name="pad_url" placeholder="https://pad.omniatv.com/p/example" required />';
            echo '<p><label><input type="checkbox" name="pad_acl_ack" value="1" required /> I confirm that only trusted reporters can edit this pad.</label></p>';
            echo '<button class="button button-primary" name="wpelb_operation" value="connect">Connect pad</button></form>';
        }
    }

    private static function form_open( $post_id ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="wpelb_action" />';
        echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '" />';
        wp_nonce_field( 'wpelb_action_' . $post_id );
    }

    public static function handle() {
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'publish_posts' ) ) {
            wp_die( 'Permission denied', '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'wpelb_action_' . $post_id );
        $operation = sanitize_key( wp_unslash( $_POST['wpelb_operation'] ?? '' ) );
        if ( ! WPELB_Mapping_Store::acquire( $post_id ) ) {
            wp_die( 'Synchronization is busy. Try again shortly; a stale lock needs operator review.', 'Etherpad to Liveblog', array( 'response' => 409 ) );
        }
        wp_cache_delete( $post_id, 'post_meta' );
        $state = WPELB_Mapping_Store::get( $post_id );
        $result = true;
        if ( 'connect' === $operation ) {
            if ( $state || empty( $_POST['pad_acl_ack'] ) || ! WPELB_Liveblog_Adapter::available( $post_id ) || ! self::has_credentials() ) {
                $result = new WP_Error( 'wpelb_connect', 'Existing connection, missing pad access acknowledgement, inactive Liveblog or missing credentials.' );
            } else {
                $pad = WPELB_Etherpad_Client::pad_id_from_url( esc_url_raw( wp_unslash( $_POST['pad_url'] ?? '' ) ) );
                if ( is_wp_error( $pad ) ) {
                    $result = $pad;
                } else {
                    try {
                        $client = new WPELB_Etherpad_Client();
                        $rev = $client->head( $pad );
                        $text = is_wp_error( $rev ) ? $rev : $client->text( $pad, $rev );
                        if ( is_wp_error( $text ) ) {
                            $result = $text;
                        } elseif ( strlen( $text ) > 800000 ) {
                            $result = new WP_Error( 'wpelb_large', 'Pad is too large.' );
                        } else {
                            $parsed = WPELB_Block_Parser::parse( $text );
                            $anchors = WPELB_Block_Matcher::anchors( $text, $parsed['markers'] );
                            if ( $parsed['error'] || is_wp_error( $anchors ) ) {
                                $result = new WP_Error( 'wpelb_invalid', 'Pad has incomplete markers or invalid encoding.' );
                            } else {
                                $reserved = WPELB_Mapping_Store::reserve( $post_id, $pad );
                                if ( is_wp_error( $reserved ) ) {
                                    $result = $reserved;
                                } else {
                                    $blocks = array();
                                    foreach ( $parsed['blocks'] as $content ) { $blocks[] = array( 'status' => 'skipped' ); }
                                    WPELB_Mapping_Store::save( $post_id, array(
                                        'pad_id' => $pad, 'status' => 'active', 'revision' => $rev,
                                        'anchors' => $anchors, 'blocks' => $blocks,
                                        'author_id' => get_current_user_id(), 'last_error' => '',
                                    ) );
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
                WPELB_Mapping_Store::unreserve( $post_id, $state['pad_id'] );
                delete_post_meta( $post_id, WPELB_Mapping_Store::META );
            }
        } else {
            $result = new WP_Error( 'wpelb_action', 'Unsupported action.' );
        }
        WPELB_Mapping_Store::release( $post_id );
        $message = is_wp_error( $result ) ? $result->get_error_message() : 'Connection updated.';
        wp_die( esc_html( $message ) . ' <a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">Back to Liveblog</a>', 'Etherpad to Liveblog', array( 'response' => is_wp_error( $result ) ? 400 : 200 ) );
    }
}
