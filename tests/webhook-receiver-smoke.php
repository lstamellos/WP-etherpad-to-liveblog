<?php
/** Authenticated Etherpad webhook regression test, no WordPress runtime. */
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'WPELB_WEBHOOK_SECRET', '0123456789abcdef0123456789abcdef0123456789abcdef' );
$GLOBALS['actions'] = array();
$GLOBALS['routes'] = array();
$GLOBALS['events'] = array();
$GLOBALS['synced'] = array();
$GLOBALS['states'] = array(
    42 => array( 'status' => 'active', 'origin' => 'https://pad.omniatv.com', 'pad_id' => 'newsroom' ),
    43 => array( 'status' => 'paused', 'origin' => 'https://pad.omniatv.com', 'pad_id' => 'paused' ),
    44 => array( 'status' => 'active', 'origin' => 'https://another.example.org', 'pad_id' => 'newsroom' ),
);
class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct( $code, $message, $data = array() ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    private $body; private $status;
    public function __construct( $body, $status ) { $this->body = $body; $this->status = $status; }
    public function get_data() { return $this->body; }
    public function get_status() { return $this->status; }
}
class FakeRequest {
    private $body; private $headers;
    public function __construct( $body, $headers ) { $this->body = $body; $this->headers = $headers; }
    public function get_body() { return $this->body; }
    public function get_header( $name ) { return $this->headers[$name] ?? ''; }
}
function check( $v, $message ) { if ( ! $v ) { throw new RuntimeException( $message ); } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_action( $hook, $fn, $prio = 10, $args = 1 ) { $GLOBALS['actions'][$hook] = $fn; }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['routes'][$namespace . $route] = $args; }
function __return_true() { return true; }
function wp_next_scheduled( $hook, $args = array() ) {
    return $GLOBALS['events'][$hook . ':' . json_encode( $args )] ?? false;
}
function wp_schedule_single_event( $when, $hook, $args = array() ) {
    $GLOBALS['events'][$hook . ':' . json_encode( $args )] = $when; return true;
}
class WPELB_Mapping_Store {
    public static function linked_posts() { return array( 42, 43, 44 ); }
    public static function get( $id ) { return $GLOBALS['states'][$id] ?? array(); }
}
class WPELB_Sync_Worker {
    public static function sync( $id ) { $GLOBALS['synced'][] = $id; return true; }
}
require_once dirname( __DIR__ ) . '/includes/class-wpelb-webhook.php';
WPELB_Webhook::register();
check( isset( $GLOBALS['actions']['rest_api_init'] ), 'REST registration missing.' );
WPELB_Webhook::register_route();
check( isset( $GLOBALS['routes']['wpelb/v1/notify'] ), 'Webhook route missing.' );
check( $GLOBALS['routes']['wpelb/v1/notify']['methods'] === 'POST', 'Webhook must be POST-only.' );

function signed_request( $data, $timestamp = null, $event = 'ae067a90-4c84-4354-b490-f8b7a9a14363', $secret = WPELB_WEBHOOK_SECRET ) {
    $raw = json_encode( $data );
    $timestamp = null === $timestamp ? time() : $timestamp;
    return new FakeRequest( $raw, array(
        'x-wpelb-timestamp' => (string) $timestamp,
        'x-wpelb-event-id' => $event,
        'x-wpelb-signature' => 'sha256=' . hash_hmac(
            'sha256', (string) $timestamp . "\n" . $event . "\n" . $raw, $secret
        ),
    ) );
}
$event = array( 'origin' => 'https://pad.omniatv.com', 'pad_id' => 'newsroom', 'revision' => 12 );
$ok = WPELB_Webhook::receive( signed_request( $event ) );
check( $ok instanceof WP_REST_Response && $ok->get_status() === 202, 'Valid event must be accepted.' );
check( $ok->get_data()['accepted'], 'Linked active pad not recognized.' );
check( count( $GLOBALS['events'] ) === 1, 'A deferred one-shot task was not scheduled.' );
check( wp_next_scheduled( 'wpelb_webhook_deferred_sync', array( 42 ) ) !== false, 'Scheduled wrong post.' );

$bad = WPELB_Webhook::receive( signed_request( $event, null, 'ae067a90-4c84-4354-b490-f8b7a9a14363', 'wrong-secret-that-is-wrong-secret' ) );
check( is_wp_error( $bad ) && $bad->get_error_data()['status'] === 403, 'Wrong secret should be rejected.' );
$old = WPELB_Webhook::receive( signed_request( $event, time() - 900 ) );
check( is_wp_error( $old ) && $old->get_error_data()['status'] === 403, 'Expired event should be rejected.' );
$invalid = WPELB_Webhook::receive( signed_request( array( 'origin' => 'http://localhost', 'pad_id' => 'x', 'revision' => 1 ) ) );
check( is_wp_error( $invalid ) && $invalid->get_error_data()['status'] === 400, 'Invalid origin should be rejected.' );
$unlinked = WPELB_Webhook::receive( signed_request( array( 'origin' => 'https://another.example.org', 'pad_id' => 'not-linked', 'revision' => 1 ) ) );
check( $unlinked instanceof WP_REST_Response && ! $unlinked->get_data()['accepted'], 'Unlinked pads must be ignored.' );
check( count( $GLOBALS['events'] ) === 1, 'Rejected requests must not schedule work.' );
$pause = WPELB_Webhook::receive( signed_request( array( 'origin' => 'https://pad.omniatv.com', 'pad_id' => 'paused', 'revision' => 1 ) ) );
check( ! $pause->get_data()['accepted'], 'Paused pads must not sync.' );
$ok2 = WPELB_Webhook::receive( signed_request( $event ) );
check( count( $GLOBALS['events'] ) === 1, 'Concurrent events must not duplicate deferred schedule.' );

WPELB_Webhook::sync_post( 42 );
check( $GLOBALS['synced'] === array( 42 ), 'Must delegate to single safe synchronizer.' );
WPELB_Webhook::sync_post( 43 );
check( $GLOBALS['synced'] === array( 42 ), 'Paused pad should be skipped on sync.' );
echo "Signed Etherpad webhook tests passed.\n";
