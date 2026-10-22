<?php
/**
 * Read-only admin status page for the development scaffold.
 *
 * @package WPELB
 */

defined( 'ABSPATH' ) || exit;

final class WPELB_Admin {
    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_status_page' ) );
    }

    public static function add_status_page() {
        add_management_page(
            __( 'Etherpad to Liveblog', 'wp-etherpad-to-liveblog' ),
            __( 'Etherpad to Liveblog', 'wp-etherpad-to-liveblog' ),
            'manage_options',
            'wp-etherpad-to-liveblog',
            array( __CLASS__, 'render_status_page' )
        );
    }

    public static function render_status_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'wp-etherpad-to-liveblog' ) );
        }

        $liveblog_available = class_exists( 'WPCOM_Liveblog_Entry' );
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Etherpad to Liveblog', 'wp-etherpad-to-liveblog' ) . '</h1>';
        echo '<p><strong>' . esc_html__( 'Development scaffold only. Synchronization and publishing are disabled.', 'wp-etherpad-to-liveblog' ) . '</strong></p>';
        echo '<p>' . esc_html__( 'Version:', 'wp-etherpad-to-liveblog' ) . ' ' . esc_html( WPELB_VERSION ) . '</p>';
        echo '<p>' . esc_html__( 'Automattic Liveblog entry API:', 'wp-etherpad-to-liveblog' ) . ' <strong>' .
            esc_html( $liveblog_available ? __( 'Detected', 'wp-etherpad-to-liveblog' ) : __( 'Not detected', 'wp-etherpad-to-liveblog' ) ) .
            '</strong></p>';
        echo '<p>' . esc_html__( 'The pad connection form, background worker and publishing logic will be added in later milestones.', 'wp-etherpad-to-liveblog' ) . '</p>';
        echo '</div>';
    }
}
