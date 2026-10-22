<?php
/** Read-only Etherpad HTTP API access. */
defined( 'ABSPATH' ) || exit;

final class WPELB_Etherpad_Client {
    private $base;
    private $version;
    private static $cached_token = '';
    private static $cached_until = 0;

    public function __construct() {
        $base = defined( 'WPELB_ETHERPAD_BASE_URL' ) ? WPELB_ETHERPAD_BASE_URL : 'https://pad.omniatv.com';
        $parts = wp_parse_url( $base );
        if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['path'] ) || isset( $parts['port'] ) ) {
            throw new InvalidArgumentException( 'Etherpad base URL must be an HTTPS origin without paths or ports.' );
        }
        // Configuration is only set by trusted server administrators, never pad users.
        $this->base = rtrim( $base, '/' );
    }

    public static function pad_id_from_url( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 600 ) {
            return new WP_Error( 'wpelb_url', 'Invalid pad URL' );
        }
        $expected = defined( 'WPELB_ETHERPAD_BASE_URL' ) ? WPELB_ETHERPAD_BASE_URL : 'https://pad.omniatv.com';
        $u = wp_parse_url( $url );
        $origin = wp_parse_url( $expected );
        if ( ! is_array( $u ) || ! is_array( $origin ) || 'https' !== ( $u['scheme'] ?? '' ) || strtolower( $u['host'] ?? '' ) !== strtolower( $origin['host'] ?? '' ) || isset( $u['port'] ) || isset( $u['user'] ) || isset( $u['pass'] ) || isset( $u['query'] ) || ! preg_match( '~^/p/([a-zA-Z0-9_.\-$]{1,200})/?$~D', $u['path'] ?? '', $matches ) ) {
            return new WP_Error( 'wpelb_url', 'Pad URL must point to an allowed Etherpad /p/<padID> address' );
        }
        return $matches[1];
    }

    public function revision() {
        return $this->version();
    }

    private function version() {
        if ( $this->version ) {
            return $this->version;
        }
        $res = wp_safe_remote_get( $this->base . '/api', array( 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 4096 ) );
        if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
            return new WP_Error( 'wpelb_api', 'Unable to negotiate Etherpad API version' );
        }
        $json = json_decode( wp_remote_retrieve_body( $res ), true );
        $version = $json['currentVersion'] ?? $json['version'] ?? null;
        if ( ! is_string( $version ) || ! preg_match( '/^1\.[0-9]+(?:\.[0-9]+)?$/D', $version ) ) {
            return new WP_Error( 'wpelb_api', 'Unexpected Etherpad API version' );
        }
        $this->version = $version;
        return $version;
    }

    public function request( $method, $pad, $extras = array() ) {
        if ( ! preg_match( '/^[a-zA-Z0-9_.\-$]{1,200}$/D', $pad ) || ! in_array( $method, array( 'getText', 'getRevisionsCount', 'getRevisionChangeset' ), true ) ) {
            return new WP_Error( 'wpelb_request', 'Invalid Etherpad request' );
        }
        $version = $this->version();
        if ( is_wp_error( $version ) ) {
            return $version;
        }
        $params = array_merge( array( 'padID' => $pad ), $extras );
        $headers = array( 'Accept' => 'application/json' );
        if ( defined( 'WPELB_ETHERPAD_BEARER_TOKEN' ) && WPELB_ETHERPAD_BEARER_TOKEN ) {
            $headers['Authorization'] = 'Bearer ' . WPELB_ETHERPAD_BEARER_TOKEN;
        } elseif ( defined( 'WPELB_ETHERPAD_API_KEY' ) && WPELB_ETHERPAD_API_KEY ) {
            $params['apikey'] = WPELB_ETHERPAD_API_KEY;
        } elseif ( defined( 'WPELB_ETHERPAD_CLIENT_ID' ) && WPELB_ETHERPAD_CLIENT_ID && defined( 'WPELB_ETHERPAD_CLIENT_SECRET' ) && WPELB_ETHERPAD_CLIENT_SECRET ) {
            $bearer = $this->oauth_token();
            if ( is_wp_error( $bearer ) ) { return $bearer; }
            $headers['Authorization'] = 'Bearer ' . $bearer;
        } else {
            return new WP_Error( 'wpelb_auth', 'Missing Etherpad credentials in wp-config.php' );
        }
        $url = $this->base . '/api/' . rawurlencode( $version ) . '/' . $method;
        $response = wp_safe_remote_post( $url, array( 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 1048576, 'headers' => $headers, 'body' => $params ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return new WP_Error( 'wpelb_http', 'Etherpad API request failed' );
        }
        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $json ) || 0 !== ( $json['code'] ?? -1 ) || ! array_key_exists( 'data', $json ) ) {
            return new WP_Error( 'wpelb_response', 'Etherpad API returned an error' );
        }
        return $json['data'];
    }

    /** Obtain short-lived OAuth client-credentials token server-side. */
    private function oauth_token() {
        if ( self::$cached_token && time() + 30 < self::$cached_until ) {
            return self::$cached_token;
        }
        $res = wp_safe_remote_post( $this->base . '/oidc/token', array(
            'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 16384,
            'body' => array( 'grant_type' => 'client_credentials',
                'client_id' => WPELB_ETHERPAD_CLIENT_ID, 'client_secret' => WPELB_ETHERPAD_CLIENT_SECRET ),
        ) );
        if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
            return new WP_Error( 'wpelb_oauth', 'Etherpad OAuth credentials rejected or unavailable' );
        }
        $json = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $json ) || empty( $json['access_token'] ) || ! is_string( $json['access_token'] ) ) {
            return new WP_Error( 'wpelb_oauth', 'Invalid Etherpad OAuth response' );
        }
        self::$cached_token = $json['access_token'];
        self::$cached_until = time() + max( 60, min( 3600, (int) ( $json['expires_in'] ?? 300 ) ) );
        return self::$cached_token;
    }

    public function head( $pad ) {
        $data = $this->request( 'getRevisionsCount', $pad );
        if ( is_wp_error( $data ) ) { return $data; }
        if ( ! is_array( $data ) || ! isset( $data['revisions'] ) || ! is_numeric( $data['revisions'] ) ) { return new WP_Error( 'wpelb_head', 'Invalid revision count' ); }
        return (int) $data['revisions'];
    }

    public function text( $pad, $rev ) {
        $data = $this->request( 'getText', $pad, array( 'rev' => (int) $rev ) );
        if ( is_wp_error( $data ) ) { return $data; }
        return is_array( $data ) && isset( $data['text'] ) && is_string( $data['text'] ) ? $data['text'] : new WP_Error( 'wpelb_text', 'Invalid pad text' );
    }

    public function changeset( $pad, $rev ) {
        $data = $this->request( 'getRevisionChangeset', $pad, array( 'rev' => (int) $rev ) );
        return is_wp_error( $data ) || is_string( $data ) ? $data : new WP_Error( 'wpelb_changeset', 'Invalid changeset' );
    }
}
