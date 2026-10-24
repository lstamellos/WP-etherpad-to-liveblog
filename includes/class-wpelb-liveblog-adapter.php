<?php
/** Use the real Automattic Liveblog entry API and defend against overwrites. */
defined( 'ABSPATH' ) || exit;

final class WPELB_Liveblog_Adapter {
    public static function available( $post_id ) {
        return class_exists( 'WPCOM_Liveblog' ) && class_exists( 'WPCOM_Liveblog_Entry' ) &&
            in_array( get_post_meta( $post_id, WPCOM_Liveblog::KEY, true ), array( 'enable', 1 ), true );
    }

    public static function insert( $post_id, $html, $author_id, $publisher_id = 0 ) {
        // Editorial attribution and publishing permission are different roles.
        // The Liveblog engine accepts authors with edit_posts even when they
        // cannot publish/edit this particular WordPress post.
        $publisher = get_userdata( $publisher_id ?: $author_id );
        $author = get_userdata( $author_id );
        if ( ! $publisher || ! $author || ! user_can( $author, 'edit_posts' ) ||
            ! self::available( $post_id ) ||
            ! user_can( $publisher, 'edit_post', $post_id ) ||
            ! user_can( $publisher, 'publish_posts' ) ) {
            return new WP_Error( 'wpelb_permission', 'Selected author or authorized publishing user unavailable.' );
        }
        $entry = WPCOM_Liveblog_Entry::insert( array(
            'post_id' => $post_id, 'content' => $html,
            'user' => $publisher, 'author_id' => $author_id,
        ) );
        return is_wp_error( $entry ) ? $entry : (int) $entry->get_id();
    }

    public static function update( $post_id, $entry_id, $html ) {
        $comment = get_comment( $entry_id );
        if ( ! self::available( $post_id ) || ! $comment || 'liveblog' !== $comment->comment_type || (int) $comment->comment_post_ID !== (int) $post_id ) {
            return new WP_Error( 'wpelb_invalid_entry', 'Existing Liveblog entry not found in this post.' );
        }
        $result = WPCOM_Liveblog_Entry::update( array( 'post_id' => $post_id, 'entry_id' => $entry_id, 'content' => $html ) );
        return is_wp_error( $result ) ? $result : (int) $result->get_id();
    }

    public static function stored_content( $entry_id ) {
        $entry = get_comment( $entry_id );
        return $entry && 'liveblog' === $entry->comment_type ? $entry->comment_content : null;
    }
}
