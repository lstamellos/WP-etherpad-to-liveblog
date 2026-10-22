<?php
/** Mapping persistence, pad uniqueness and single-flight worker locks. */
defined( 'ABSPATH' ) || exit;

final class WPELB_Mapping_Store {
    const META = '_wpelb_state';

    public static function get( $post_id ) {
        $state = get_post_meta( $post_id, self::META, true );
        return is_array( $state ) ? $state : array();
    }

    public static function save( $post_id, $state ) {
        return update_post_meta( $post_id, self::META, $state );
    }

    public static function reserve( $post_id, $pad_id ) {
        $key = 'wpelb_pad_' . md5( $pad_id );
        $owner = get_option( $key );
        if ( $owner && (int) $owner !== (int) $post_id ) {
            return new WP_Error( 'wpelb_taken', 'This Etherpad is connected to another Liveblog.' );
        }
        if ( ! $owner && ! add_option( $key, (string) $post_id, '', false ) ) {
            return new WP_Error( 'wpelb_taken', 'Could not reserve pad; it may already be connected.' );
        }
        return true;
    }

    public static function unreserve( $post_id, $pad_id ) {
        $key = 'wpelb_pad_' . md5( $pad_id );
        if ( (int) get_option( $key ) === (int) $post_id ) {
            delete_option( $key );
        }
    }

    public static function linked_posts() {
        return get_posts( array(
            'post_type' => 'any', 'post_status' => 'any',
            'meta_key' => self::META, 'posts_per_page' => 100,
            'fields' => 'ids', 'suppress_filters' => true, 'cache_results' => false,
            'update_post_meta_cache' => false, 'update_post_term_cache' => false, 'no_found_rows' => true,
        ) );
    }

    public static function acquire( $post_id ) {
        $key = 'wpelb_lock_' . (int) $post_id;
        if ( add_option( $key, (string) time(), '', false ) ) {
            return true;
        }
        // A stale lock is NOT stolen automatically: multiple concurrent writers
        // would risk double publication. Operator must resolve stale locks.
        return false;
    }

    public static function release( $post_id ) {
        delete_option( 'wpelb_lock_' . (int) $post_id );
    }
}
