<?php
/**
 * No-credential, cross-instance export reader smoke tests.
 * Run: php tests/public-export-smoke.php
 */
define( 'ABSPATH', '/tmp/wordpress/' );
$GLOBALS['responses'] = array();
$GLOBALS['calls'] = array();
$GLOBALS['valid_host'] = true;

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_http_validate_url( $url ) {
    return $GLOBALS['valid_host'] ? $url : false;
}
function wp_safe_remote_get( $url, $args ) {
    $GLOBALS['calls'][] = array( 'url' => $url, 'args' => $args );
    if ( ! isset( $GLOBALS['responses'][$url] ) ) {
        return array( 'code' => 404, 'body' => '' );
    }
    return $GLOBALS['responses'][$url];
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function check( $v, $message ) { if ( ! $v ) { throw new RuntimeException( $message ); } }

require_once dirname( __DIR__ ) . '/includes/class-wpelb-public-export-client.php';

$target = WPELB_Public_Export_Client::connection_from_url( 'https://pad.other-example.org/p/newsroom' );
check( ! is_wp_error( $target ), 'External public Etherpad should be accepted.' );
check( $target['origin'] === 'https://pad.other-example.org', 'Wrong external origin.' );
check( $target['pad_id'] === 'newsroom', 'Wrong pad ID.' );
foreach ( array(
    'http://pad.other-example.org/p/newsroom',
    'https://127.0.0.1/p/newsroom',
    'https://localhost/p/newsroom',
    'https://pad.other-example.org:9443/p/newsroom',
    'https://pad.other-example.org/p/newsroom?debug=1',
    'https://pad.other-example.org@127.0.0.1/p/newsroom',
    'https://pad.other-example.org/internal/newsroom',
) as $url ) {
    check( is_wp_error( WPELB_Public_Export_Client::connection_from_url( $url ) ), 'Unsafe URL accepted: ' . $url );
}
$GLOBALS['valid_host'] = false;
check( is_wp_error( WPELB_Public_Export_Client::connection_from_url( 'https://pad.other-example.org/p/newsroom' ) ), 'WordPress unsafe-host policy must be enforced.' );
$GLOBALS['valid_host'] = true;

$origin = 'https://pad.other-example.org';
$path = $origin . '/p/newsroom/export/';
$txt = "first news\nPOSTOK\n";
$archive = array(
    'pad:newsroom' => array( 'head' => 5, 'atext' => array( 'text' => $txt ) ),
    'pad:newsroom:revs:5' => array( 'changeset' => 'Z:g>1+1$z' ),
);
$GLOBALS['responses'][$path . 'txt'] = array( 'code' => 200, 'body' => $txt );
$GLOBALS['responses'][$path . 'etherpad'] = array( 'code' => 200, 'body' => json_encode( $archive ) );
$reader = new WPELB_Public_Export_Client( $origin );
check( 5 === $reader->head( 'newsroom' ), 'Public archive head missing.' );
check( $reader->text( 'newsroom', 5 ) === $txt, 'Head text mismatch.' );
check( $reader->changeset( 'newsroom', 5 ) === 'Z:g>1+1$z', 'Revision changeset missing.' );
$hash = $reader->last_text_hash();
check( hash( 'sha256', $txt ) === $hash, 'Public text digest mismatch.' );
check( count( $GLOBALS['calls'] ) === 2, 'First read should fetch TXT and archive.' );
foreach ( $GLOBALS['calls'] as $call ) {
    check( $call['args']['redirection'] === 0, 'No cross-site redirects allowed.' );
    check( $call['args']['limit_response_size'] > 0, 'Remote content must be bounded.' );
}
$next = new WPELB_Public_Export_Client( $origin );
check( 5 === $next->head( 'newsroom', $hash, 5 ), 'Unchanged pad should return saved head.' );
check( count( $GLOBALS['calls'] ) === 3, 'Unchanged poll must not download full revision history.' );

$changed = "first news updated\nPOSTOK\n";
$archive['pad:newsroom']['head'] = 6;
$archive['pad:newsroom']['atext']['text'] = $changed;
$archive['pad:newsroom:revs:6'] = array( 'changeset' => 'Z:o>8+8$ updated' );
$GLOBALS['responses'][$path . 'txt']['body'] = $changed;
$GLOBALS['responses'][$path . 'etherpad']['body'] = json_encode( $archive );
$next = new WPELB_Public_Export_Client( $origin );
check( 6 === $next->head( 'newsroom', $hash, 5 ), 'Changed pad must reload revision metadata.' );
check( $next->text( 'newsroom', 6 ) === $changed, 'Changed text missing.' );
check( $next->changeset( 'newsroom', 6 ) === 'Z:o>8+8$ updated', 'Changed revision missing.' );
check( count( $GLOBALS['calls'] ) === 5, 'Changed poll must fetch TXT and archive.' );

$GLOBALS['responses'][$path . 'etherpad'] = array( 'code' => 403, 'body' => '<html>Just a moment...</html>' );
$blocked = new WPELB_Public_Export_Client( $origin );
check( is_wp_error( $blocked->head( 'newsroom' ) ), 'Blocked public archive must fail closed.' );
$GLOBALS['responses'][$path . 'etherpad'] = array( 'code' => 200, 'body' => '{"invalid":true}' );
$invalid = new WPELB_Public_Export_Client( $origin );
check( is_wp_error( $invalid->head( 'newsroom' ) ), 'Malformed archive must fail closed.' );

echo "Public Etherpad export tests passed.\n";
