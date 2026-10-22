<?php
/**
 * Planned persistent mapping and sync state store.
 *
 * Requirements: unique pad/liveblog bindings, original entry IDs, hashes,
 * per-block debounce timestamps, conflict state, retry and audit history.
 *
 * @package WPELB
 */

defined( 'ABSPATH' ) || exit;

final class WPELB_Mapping_Store {
    // Schema and migrations will be introduced with tests.
}
