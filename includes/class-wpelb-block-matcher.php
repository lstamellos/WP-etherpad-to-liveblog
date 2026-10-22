<?php
/**
 * Planned revision-aware stable identity tracker.
 *
 * Map a newly observed block to its existing immutable internal block ID.
 * Detect ambiguous marker insertion/deletion/reordering and fail closed.
 *
 * @package WPELB
 */

defined( 'ABSPATH' ) || exit;

final class WPELB_Block_Matcher {
    // Never use positional index alone to identify published blocks.
}
