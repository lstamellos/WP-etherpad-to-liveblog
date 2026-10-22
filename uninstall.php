<?php
/**
 * Uninstall entrypoint.
 *
 * The bridge intentionally preserves all future mappings and audit records
 * unless a separately designed, explicitly confirmed cleanup policy exists.
 * Liveblog entries must never be removed on plugin uninstall.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
