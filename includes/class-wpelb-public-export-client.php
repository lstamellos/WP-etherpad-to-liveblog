<?php
/**
 * Credential-free public Etherpad exports, including revision changesets.
 *
 * A public .etherpad export contains the head, current attributed text and
 * revision records. A cheap .txt export is polled first; the history is only
 * downloaded if text changed. No cookies, token or REST API permission.
 *
 * @package WPELB
 */
defined( 'ABSPATH' ) || exit;

final class WPELB_Public_Export_Client {
    const MAX_TEXT_BYTES = 800000;
    const MAX_EXPORT_BYTES = 8388608;

    private $origin;
    private $text = null;
    private $hash = '';
    private $head = null;
    private $changes = array();

    public function __construct( $origin ) {
        $validated = self::validate_origin( $origin );
        if ( is_wp_error( $validated ) ) {
            throw new InvalidArgumentException( $validated->get_error_message() );
        }
        $this->origin = $validated;
    }

    /**
     * A user supplies the complete public pad URL. No global origin, no
     * credentials and no arbitrary URL paths, ports, query or fragments.
     */
    public static function connection_from_url( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 700 ) {
            return new WP_Error( 'wpelb_url', 'Invalid Etherpad URL.' );
        }
        $parts = wp_parse_url( $url );
        if (
            ! is_array( $parts ) ||
            'https' !== strtolower( $parts['scheme'] ?? '' ) ||
            empty( $parts['host'] ) ||
            isset( $parts['user'] ) || isset( $parts['pass'] ) ||
            isset( $parts['port'] ) || isset( $parts['query'] ) ||
            isset( $parts['fragment'] ) ||
            ! preg_match( '~^/p/([a-zA-Z0-9_.\\-$]{1,200})/?$~D', $parts['path'] ?? '', $matches )
        ) {
            return new WP_Error( 'wpelb_url', 'Enter a full HTTPS Etherpad /p/<padID> URL without query parameters.' );
        }
        $origin = 'https://' . strtolower( $parts['host'] );
        $validated = self::validate_origin( $origin );
        if ( is_wp_error( $validated ) ) {
            return $validated;
        }
        return array( 'origin' => $validated, 'pad_id' => $matches[1] );
    }

    public static function validate_origin( $origin ) {
        if ( ! is_string( $origin ) || strlen( $origin ) > 255 ) {
            return new WP_Error( 'wpelb_origin', 'Invalid Etherpad origin.' );
        }
        $parts = wp_parse_url( $origin );
        if (
            ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) ||
            empty( $parts['host'] ) ||
            isset( $parts['user'] ) || isset( $parts['pass'] ) ||
            isset( $parts['port'] ) || isset( $parts['path'] ) ||
            isset( $parts['query'] ) || isset( $parts['fragment'] )
        ) {
            return new WP_Error( 'wpelb_origin', 'The Etherpad origin must be HTTPS without ports, credentials or paths.' );
        }
        $host = strtolower( $parts['host'] );
        if (
            strlen( $host ) > 253 ||
            false === strpos( $host, '.' ) ||
            ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host ) ||
            filter_var( $host, FILTER_VALIDATE_IP ) ||
            preg_match( '/\\.(?:local|localhost|internal|test|invalid)$/D', $host )
        ) {
            return new WP_Error( 'wpelb_origin', 'A public DNS hostname is required for an external Etherpad instance.' );
        }
        // WordPress resolves and rejects private/reserved hosts, mitigating
        // SSRF and DNS rebinding attempts where supported by the HTTP API.
        $safe = wp_http_validate_url( 'https://' . $host . '/p/check/export/txt' );
        if ( ! $safe ) {
            return new WP_Error( 'wpelb_origin', 'The Etherpad server failed WordPress public-host validation.' );
        }
        return 'https://' . $host;
    }

    /**
     * Read a versioned public snapshot. For the common unchanged case, only
     * .txt is downloaded. If it changed, .etherpad provides authoritative
     * revision IDs/changesets. Never infer identity from marker order alone.
     */
    public function head( $pad, $previous_hash = '', $previous_revision = null ) {
        if ( ! self::valid_pad_id( $pad ) ) {
            return new WP_Error( 'wpelb_pad', 'Invalid pad ID.' );
        }

        $txt = $this->fetch( $pad, 'txt', self::MAX_TEXT_BYTES );
        if ( is_wp_error( $txt ) ) {
            return $txt;
        }
        if ( ! preg_match( '//u', $txt ) ) {
            return new WP_Error( 'wpelb_utf8', 'Public Etherpad text is not valid UTF-8.' );
        }

        $this->hash = hash( 'sha256', $txt );
        if (
            '' !== $previous_hash &&
            hash_equals( $previous_hash, $this->hash ) &&
            null !== $previous_revision
        ) {
            return (int) $previous_revision;
        }

        $archive = $this->fetch( $pad, 'etherpad', self::MAX_EXPORT_BYTES );
        if ( is_wp_error( $archive ) ) {
            return $archive;
        }
        $records = json_decode( $archive, true );
        $key = 'pad:' . $pad;
        $pad_record = is_array( $records ) ? ( $records[$key] ?? null ) : null;
        if (
            ! is_array( $pad_record ) ||
            ! isset( $pad_record['head'], $pad_record['atext']['text'] ) ||
            ! is_int( $pad_record['head'] ) ||
            $pad_record['head'] < 0 ||
            ! is_string( $pad_record['atext']['text'] )
        ) {
            return new WP_Error( 'wpelb_public_export', 'Public Etherpad archive has no usable revision history.' );
        }
        $head = $pad_record['head'];
        $text = $pad_record['atext']['text'];

        if (
            strlen( $text ) > self::MAX_TEXT_BYTES ||
            ! preg_match( '//u', $text ) ||
            rtrim( str_replace( array( "\r\n", "\r" ), "\n", $txt ), "\n" ) !== rtrim( $text, "\n" )
        ) {
            // Native TXT may format bullet/numbered lists differently from
            // the internal atext; don't publish mismatched snapshots.
            return new WP_Error( 'wpelb_public_export', 'TXT and revision exports disagree; retry after editing settles or use plain-text pad content.' );
        }
        $this->head = $head;
        $this->text = $text;
        $this->changes = array();

        // These records are necessary only for changed revisions. Retain only
        // the most recent 500 (the worker's configured safety limit).
        $start = max( 0, $head - 500 );
        for ( $rev = $start; $rev <= $head; ++$rev ) {
            $row = $records[$key . ':revs:' . $rev] ?? null;
            if ( is_array( $row ) && isset( $row['changeset'] ) && is_string( $row['changeset'] ) ) {
                $this->changes[$rev] = $row['changeset'];
            }
        }
        return $head;
    }

    public function text( $pad, $rev ) {
        if ( null === $this->head || (int) $rev !== $this->head ) {
            return new WP_Error( 'wpelb_public_export', 'No matching public export snapshot is loaded.' );
        }
        return $this->text;
    }

    public function changeset( $pad, $rev ) {
        return $this->changes[(int) $rev] ?? new WP_Error( 'wpelb_public_export', 'A required public revision is absent from the archive.' );
    }

    public function last_text_hash() {
        return $this->hash;
    }

    private static function valid_pad_id( $pad ) {
        return is_string( $pad ) && (bool) preg_match( '/^[a-zA-Z0-9_.\\-$]{1,200}$/D', $pad );
    }

    private function fetch( $pad, $type, $limit ) {
        $url = $this->origin . '/p/' . rawurlencode( $pad ) . '/export/' . $type;
        $res = wp_safe_remote_get( $url, array(
            'timeout' => 15,
            'redirection' => 0,
            'limit_response_size' => $limit + 1,
            'headers' => array(
                'Accept' => 'etherpad' === $type ? 'application/json' : 'text/plain',
                'Cache-Control' => 'no-cache',
            ),
        ) );
        if ( is_wp_error( $res ) ) {
            return new WP_Error( 'wpelb_public_export', 'Unable to reach the public Etherpad export endpoint.' );
        }
        if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
            return new WP_Error( 'wpelb_public_export', 'Public Etherpad export failed (HTTP ' . (int) wp_remote_retrieve_response_code( $res ) . '). Check Cloudflare, export access and pad permissions.' );
        }
        $body = wp_remote_retrieve_body( $res );
        if ( ! is_string( $body ) || strlen( $body ) > $limit ) {
            return new WP_Error( 'wpelb_public_export', 'Public Etherpad export exceeds the configured size limit.' );
        }
        return $body;
    }
}
