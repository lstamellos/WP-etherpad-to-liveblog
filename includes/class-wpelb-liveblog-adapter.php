<?php
/**
 * Planned adapter for WPCOM_Liveblog_Entry::insert()/update().
 *
 * insert() returns the original comment/entry ID. update() creates a
 * replacement event but preserves the original reader-facing entry ID.
 * Keep the original ID separate from replacement event IDs.
 *
 * @package WPELB
 */

defined( 'ABSPATH' ) || exit;

final class WPELB_Liveblog_Adapter {
    // No raw wp_insert_comment/writing to Liveblog tables.
}
