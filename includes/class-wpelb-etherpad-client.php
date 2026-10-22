<?php
/**
 * Planned Etherpad HTTP API adapter.
 *
 * Responsibilities for the next milestone:
 * - negotiate /api version and the deployed authentication method;
 * - query getRevisionsCount and getText/getHTML at the SAME revision;
 * - read-only, allowlisted HTTPS requests; bound payload sizes and timeouts;
 * - return structured WP_Error values without exposing secrets.
 *
 * Not loaded or used by the scaffold.
 *
 * @package WPELB
 */

defined( 'ABSPATH' ) || exit;

final class WPELB_Etherpad_Client {
    // Deliberately left unimplemented until the connection milestone.
}
