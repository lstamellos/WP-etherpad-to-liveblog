<?php
/** Native Liveblog polling trigger regression tests (no WordPress runtime). */
define( 'ABSPATH', '/tmp/wordpress/' );

$GLOBALS['actions'] = array();
$GLOBALS['filters'] = array();
$GLOBALS['options'] = array();
$GLOBALS['schedule'] = array();
$GLOBALS['scheduled_single'] = array();
$GLOBALS['synced'] = array();
$GLOBALS['states'] = array(
    42 => array( 'status' => 'active' ),
    43 => array( 'status' => 'paused' ),
);

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class FakeWpdb {
    public $options = 'wp_options';
    public function prepare( $sql, ...$args ) { return $args; }
    public function query( $params ) {
        list( $now, $key, $old ) = $params;
        if ( ! isset( $GLOBALS['options'][$key] ) || $GLOBALS['options'][$key] !== $old ) {
            return 0;
        }
        $GLOBALS['options'][$key] = $now;
        return 1;
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();
function check( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function add_filter( $name, $fn, $priority = 10, $num = 1 ) {
    $GLOBALS['filters'][$name] = $fn;
}
function add_action( $name, $fn, $priority = 10, $num = 1 ) {
    $GLOBALS['actions'][$name] = $fn;
}
function get_option( $key, $default = false ) {
    return $GLOBALS['options'][$key] ?? $default;
}
function add_option( $key, $value, $deprecated = '', $autoload = true ) {
    if ( isset( $GLOBALS['options'][$key] ) ) { return false; }
    $GLOBALS['options'][$key] = $value;
    return true;
}
function wp_cache_delete( $key, $group ) { return true; }
function wp_next_scheduled( $name, $args = array() ) {
    return $GLOBALS['schedule'][$name . ':' . json_encode( $args )] ?? false;
}
function wp_schedule_event( $when, $schedule, $hook ) {
    $GLOBALS['schedule'][$hook . ':[]'] = $when;
    return true;
}
function wp_schedule_single_event( $when, $hook, $args = array() ) {
    $GLOBALS['schedule'][$hook . ':' . json_encode( $args )] = $when;
    $GLOBALS['scheduled_single'][] = array( 'hook' => $hook, 'args' => $args );
    return true;
}
class WPELB_Mapping_Store {
    public static function get( $id ) { return $GLOBALS['states'][$id] ?? array(); }
    public static function linked_posts() { return array( 42, 43 ); }
}
class WPELB_Sync_Worker {
    public static function sync( $id ) {
        $GLOBALS['synced'][] = $id;
        return true;
    }
}
class FakeLiveblogPollRequest {
    private $method;
    private $route;
    private $params;
    public function __construct( $route, $method = 'GET', $params = array( 'post_id' => 42 ) ) {
        $this->route = $route;
        $this->method = $method;
        $this->params = $params;
    }
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function get_url_params() { return $this->params; }
}

require_once dirname( __DIR__ ) . '/includes/class-wpelb-liveblog-poll-trigger.php';
WPELB_Liveblog_Poll_Trigger::register();
check( isset( $GLOBALS['filters']['rest_request_before_callbacks'] ), 'Native REST polling not hooked.' );
check( isset( $GLOBALS['actions']['wpelb_periodic_sync'] ), 'WP-Cron fallback missing.' );
check( wp_next_scheduled( 'wpelb_periodic_sync' ) > 0, 'Minute event was not scheduled.' );
check( WPELB_Liveblog_Poll_Trigger::cron_schedules( array() )['wpelb_one_minute']['interval'] === 60, 'Minute cron recurrence missing.' );

$poll = new FakeLiveblogPollRequest( '/liveblog/v1/42/entries/100/200/' );
$sentinel = (object) array( 'status' => 200 );
$callback = array( 'callback' => 'Liveblog' );
$out = WPELB_Liveblog_Poll_Trigger::maybe_trigger( $sentinel, $callback, $poll );
check( $out === $sentinel, 'REST response changed.' );
check( count( $GLOBALS['scheduled_single'] ) === 1, 'One deferred event should be created (non-FPM).' );
check( $GLOBALS['scheduled_single'][0]['args'] === array( 42 ), 'Wrong post selected.' );

WPELB_Liveblog_Poll_Trigger::maybe_trigger( $sentinel, $callback, $poll );
check( count( $GLOBALS['scheduled_single'] ) === 1, 'Concurrent/frequent polls should be throttled.' );

foreach ( array(
    new FakeLiveblogPollRequest( '/liveblog/v1/42/crud/' ),
    new FakeLiveblogPollRequest( '/liveblog/v1/42/entries/100/200/', 'POST' ),
    new FakeLiveblogPollRequest( '/liveblog/v1/42/get-entries/1/latest' ),
    new FakeLiveblogPollRequest( '/liveblog/v1/99/entries/100/200/' ),
    new FakeLiveblogPollRequest( '/liveblog/v1/42/entries/100/200/', 'GET', array( 'post_id' => 99 ) ),
    new FakeLiveblogPollRequest( '/liveblog/v1/43/entries/100/200/', 'GET', array( 'post_id' => 43 ) ),
) as $not_a_trigger ) {
    WPELB_Liveblog_Poll_Trigger::maybe_trigger( $sentinel, $callback, $not_a_trigger );
}
check( count( $GLOBALS['scheduled_single'] ) === 1, 'Unrelated, non-GET or paused requests must not trigger sync.' );

$GLOBALS['options']['wpelb_poll_last_42'] = (string) ( time() - 20 );
unset( $GLOBALS['schedule']['wpelb_deferred_poll_sync:[42]' ] );
WPELB_Liveblog_Poll_Trigger::maybe_trigger( $sentinel, $callback, $poll );
check( count( $GLOBALS['scheduled_single'] ) === 2, 'Expired per-post throttle should allow another trigger.' );

$GLOBALS['states'][42]['status'] = 'paused';
WPELB_Liveblog_Poll_Trigger::sync( 42 );
check( count( $GLOBALS['synced'] ) === 0, 'Paused Liveblog must not sync.' );

$GLOBALS['states'][42]['status'] = 'active';
WPELB_Liveblog_Poll_Trigger::sync( 42 );
check( $GLOBALS['synced'] === array( 42 ), 'Active Liveblog should use the existing safe worker.' );

WPELB_Liveblog_Poll_Trigger::cron_sync();
check( $GLOBALS['synced'] === array( 42, 42 ), 'Cron must only synchronize active linked posts.' );
echo "Liveblog polling / WP-Cron tests passed.\n";
