<?php
/**
 * WP admin AJAX connection regression test, without a running WordPress site.
 * php tests/admin-ajax-smoke.php
 */
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'WPELB_VERSION', '0.3.0' );
define( 'WPELB_PLUGIN_FILE', '/plugins/wp-etherpad-to-liveblog/wp-etherpad-to-liveblog.php' );

class AjaxTestExit extends RuntimeException {
    public $payload;
    public $http_status;
    public function __construct( $payload, $http_status ) {
        $this->payload = $payload;
        $this->http_status = $http_status;
    }
}
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function current_user_can( $cap, $id = null ) { return true; }
function wp_create_nonce( $action ) { return 'nonce-for-' . $action; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_attr( $v ); }
function esc_url_raw( $v ) { return $v; }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function absint( $v ) { return abs( (int) $v ); }
function wp_unslash( $v ) { return $v; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $v ) ); }
function wp_doing_ajax() { return true; }
function check_ajax_referer( $action, $field, $stop ) {
    return ( $_POST[$field] ?? '' ) === wp_create_nonce( $action );
}
function wp_send_json_error( $data, $status ) {
    throw new AjaxTestExit( array( 'success' => false, 'data' => $data ), $status );
}
function wp_send_json_success( $data ) {
    throw new AjaxTestExit( array( 'success' => true, 'data' => $data ), 200 );
}
function wp_cache_delete( $id, $group ) { return true; }
function get_post( $id ) { return (object) array( 'ID' => $id ); }
function get_current_user_id() { return 7; }

class WPCOM_Liveblog_Entry {}
class WPCOM_Liveblog {
    public static function get_liveblog_state( $id ) { return 'enable'; }
}
class WPELB_Mapping_Store {
    public static $state = array();
    public static $reserved = '';
    public static function get( $post_id ) { return self::$state; }
    public static function acquire( $post_id ) { return true; }
    public static function release( $post_id ) {}
    public static function reserve( $post_id, $pad ) { self::$reserved = $pad; return true; }
    public static function save( $post_id, $state ) { self::$state = $state; return true; }
    public static function unreserve( $post_id, $pad ) { self::$reserved = ''; }
}
class WPELB_Liveblog_Adapter {
    public static function available( $post ) { return true; }
}
class WPELB_Public_Export_Client {
    public function __construct( $origin ) {}
    public static function connection_from_url( $url ) {
        return array( 'origin' => 'https://pad.omniatv.com', 'pad_id' => 'example-pad' );
    }
    public function head( $pad ) { return 3; }
    public function text( $pad, $rev ) { return "Prior report\nPOSTOK\n"; }
    public function last_text_hash() { return hash( 'sha256', "Prior report\nPOSTOK\n" ); }
}
class WPELB_Block_Parser {
    public static function parse( $text ) {
        return array( 'error' => null, 'markers' => array( 13 ), 'blocks' => array( 'Prior report' ) );
    }
}
class WPELB_Block_Matcher {
    public static function anchors( $text, $markers ) { return array( 13 ); }
}

require_once dirname( __DIR__ ) . '/includes/class-wpelb-admin.php';

ob_start();
WPELB_Admin::render_box( get_post( 42 ) );
$html = ob_get_clean();
check( ! preg_match( '/<\\/?form\\b/i', $html ), 'Nested HTML forms are forbidden in post editor meta boxes.' );
check( strpos( $html, 'data-wpelb-operation="connect"' ) !== false, 'AJAX connect button must exist.' );
check( strpos( $html, 'type="button"' ) !== false, 'Connect must not submit the post editor.' );
check( strpos( $html, 'data-nonce="nonce-for-wpelb_action_42"' ) !== false, 'Post-bound nonce missing.' );
check( strpos( $html, 'name="pad_url"' ) !== false, 'Pad URL field missing.' );

$_POST = array(
    'post_id' => '42',
    'nonce' => 'nonce-for-wpelb_action_42',
    'wpelb_operation' => 'connect',
    'pad_url' => 'https://pad.omniatv.com/p/example-pad',
    'pad_acl_ack' => '1',
);
try {
    WPELB_Admin::handle();
    throw new RuntimeException( 'Expected JSON success response.' );
} catch ( AjaxTestExit $e ) {
    check( $e->http_status === 200, 'Connection AJAX HTTP status.' );
    check( $e->payload['success'] === true, 'Connection response not successful.' );
    check( strpos( $e->payload['data']['html'], 'Status: <strong>active</strong>' ) !== false, 'AJAX response did not show saved active state.' );
}
check( WPELB_Mapping_Store::$reserved === 'https://pad.omniatv.com/p/example-pad', 'Pad reservation was not stored with origin.' );
check( WPELB_Mapping_Store::$state['origin'] === 'https://pad.omniatv.com', 'Origin not persisted.' );
check( WPELB_Mapping_Store::$state['status'] === 'active', 'Pad mapping was not stored.' );
check( WPELB_Mapping_Store::$state['revision'] === 3, 'Initial revision baseline missing.' );
check( WPELB_Mapping_Store::$state['blocks'][0]['status'] === 'skipped', 'Existing POSTOK should not be republished.' );

$_POST['nonce'] = 'invalid-token';
try {
    WPELB_Admin::handle();
    throw new RuntimeException( 'Expected nonce rejection.' );
} catch ( AjaxTestExit $e ) {
    check( $e->http_status === 403 && $e->payload['success'] === false, 'Invalid nonce was not rejected.' );
}

$_POST['nonce'] = 'nonce-for-wpelb_action_42';
$_POST['wpelb_operation'] = 'pause';
try {
    WPELB_Admin::handle();
    throw new RuntimeException( 'Expected pause JSON response.' );
} catch ( AjaxTestExit $e ) {
    check( $e->payload['success'] === true, 'Pause not successful.' );
}
check( WPELB_Mapping_Store::$state['status'] === 'paused', 'Pause did not persist.' );

echo "Admin AJAX connection tests passed.\n";
