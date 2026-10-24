<?php
/** In-process WordPress / Etherpad / Liveblog contract smoke tests. */
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'WPELB_ETHERPAD_API_KEY', 'test-only-key' );
define( 'WPELB_VERSION', '0.2.0' );
$GLOBALS['meta'] = array( 42 => array( 'liveblog' => 'enable' ) );
$GLOBALS['options'] = array();
$GLOBALS['comments'] = array();
$GLOBALS['sequence'] = 100;
$GLOBALS['revision'] = 1;
$GLOBALS['pad'] = array( 1 => "first\nPOSTOK\n" );
$GLOBALS['changes'] = array();
class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_parse_url( $v ) { return parse_url( $v ); }
function wp_cache_delete( $id, $group ) { return true; }
function get_post_meta( $post, $key ) { return $GLOBALS['meta'][$post][$key] ?? ''; }
function update_post_meta( $post, $key, $v ) { $GLOBALS['meta'][$post][$key] = $v; return true; }
function add_option( $key, $value ) { if ( isset( $GLOBALS['options'][$key] ) ) { return false; } $GLOBALS['options'][$key] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); }
function get_option( $key ) { return $GLOBALS['options'][$key] ?? false; }
function get_userdata( $id ) { return (object) array( 'ID' => $id ); }
function user_can( $user, $cap, $post = 0 ) { return true; }
function get_comment( $id ) { return $GLOBALS['comments'][$id] ?? false; }
function wp_remote_retrieve_response_code( $res ) { return $res['status']; }
function wp_remote_retrieve_body( $res ) { return json_encode( $res['data'] ); }
function wp_safe_remote_get( $url, $opts ) {
    return array( 'status' => 200, 'data' => array( 'currentVersion' => '1.3.0' ) );
}
function wp_safe_remote_post( $url, $opts ) {
    $method = basename( $url );
    $data = $opts['body'];
    check( $data['apikey'] === 'test-only-key', 'API key sent in body, not URL' );
    $revision = (int) ( $data['rev'] ?? $GLOBALS['revision'] );
    if ( 'getRevisionsCount' === $method ) { $value = array( 'revisions' => $GLOBALS['revision'] ); }
    elseif ( 'getText' === $method ) { $value = array( 'text' => $GLOBALS['pad'][$revision] ); }
    elseif ( 'getRevisionChangeset' === $method ) { $value = $GLOBALS['changes'][$revision]; }
    else { throw new RuntimeException( 'Unexpected API call ' . $method ); }
    return array( 'status' => 200, 'data' => array( 'code' => 0, 'data' => $value ) );
}
class WPCOM_Liveblog { const KEY = 'liveblog'; }
class WPCOM_Liveblog_Entry {
    public $id;
    public function __construct( $id ) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public static function insert( $args ) {
        $GLOBALS['last_insert_args'] = $args;
        $id = ++$GLOBALS['sequence'];
        $GLOBALS['comments'][$id] = (object) array( 'comment_content' => $args['content'], 'comment_post_ID' => $args['post_id'], 'comment_type' => 'liveblog' );
        return new self( $id );
    }
    public static function update( $args ) {
        $id = ++$GLOBALS['sequence'];
        $GLOBALS['comments'][$args['entry_id']]->comment_content = $args['content'];
        return new self( $id );
    }
}
function check( $yes, $message ) { if ( ! $yes ) { throw new RuntimeException( $message ); } }
function b36( $n ) { return base_convert( (string) $n, 10, 36 ); }
function append_pad( $content ) {
    $old = $GLOBALS['pad'][$GLOBALS['revision']];
    $GLOBALS['revision']++;
    $n = $GLOBALS['revision'];
    $GLOBALS['pad'][$n] = $old . $content;
    $GLOBALS['changes'][$n] = 'Z:' . b36( strlen( $old ) ) . '>' . b36( strlen( $content ) ) . '=' . b36( strlen( $old ) ) . '+' . b36( strlen( $content ) ) . '$' . $content;
}
function prepend_pad( $content ) {
    $old = $GLOBALS['pad'][$GLOBALS['revision']];
    $GLOBALS['revision']++;
    $n = $GLOBALS['revision'];
    $GLOBALS['pad'][$n] = $content . $old;
    $GLOBALS['changes'][$n] = 'Z:' . b36( strlen( $old ) ) . '>' . b36( strlen( $content ) ) . '+' . b36( strlen( $content ) ) . '=' . b36( strlen( $old ) ) . '$' . $content;
}
foreach ( array( 'block-parser', 'block-matcher', 'mapping-store', 'etherpad-client', 'liveblog-adapter', 'sync-worker' ) as $name ) {
    require_once dirname( __DIR__ ) . '/includes/class-wpelb-' . $name . '.php';
}
$initial = WPELB_Block_Parser::parse( $GLOBALS['pad'][1] );
$anchors = WPELB_Block_Matcher::anchors( $GLOBALS['pad'][1], $initial['markers'] );
WPELB_Mapping_Store::save( 42, array( 'pad_id' => 'test', 'status' => 'active', 'revision' => 1, 'anchors' => $anchors, 'blocks' => array( array( 'status' => 'skipped' ) ), 'author_id' => 1 ) );
append_pad( "second\nPOSTOK\n" );
check( true === WPELB_Sync_Worker::sync( 42 ), 'Sync success after new post' );
$state = WPELB_Mapping_Store::get( 42 );
check( count( $state['blocks'] ) === 2, 'Two logical blocks' );
check( $state['blocks'][0]['status'] === 'skipped', 'Baseline not republished' );
check( $state['blocks'][1]['status'] === 'published', 'New POSTOK published' );
check( $state['blocks'][1]['entry_id'] === 101, 'Original liveblog ID saved' );
check( $GLOBALS['last_insert_args']['author_id'] === 1, 'Legacy mapping author must be preserved.' );
check( $GLOBALS['last_insert_args']['user']->ID === 1, 'Legacy publishing user fallback must work.' );
check( count( $GLOBALS['comments'] ) === 1, 'No duplicate entries' );
// Later edit the second published block, inserting at its beginning. Build a
// realistic changeset with an insertion after the first marker.
$old = $GLOBALS['pad'][2];
$at = strpos( $old, 'second' );
$insert = 'new ';
$GLOBALS['revision'] = 3;
$GLOBALS['pad'][3] = substr( $old, 0, $at ) . $insert . substr( $old, $at ) . 'third in progress';
$length = strlen( $old );
// A new draft also arrives: changeset adds text at two positions.
$GLOBALS['changes'][3] = 'Z:' . b36( $length ) . '>' . b36( strlen($insert) + strlen('third in progress') ) . '=' . b36( $at ) . '+' . b36(strlen($insert)) . '=' . b36($length-$at) . '+' . b36(strlen('third in progress')) . '$' . $insert . 'third in progress';
check( true === WPELB_Sync_Worker::sync( 42 ), 'Changed block detected' );
$state = WPELB_Mapping_Store::get( 42 );
check( $state['blocks'][1]['status'] === 'dirty', 'Published block marked dirty' );
$state['blocks'][1]['changed_at'] -= 6;
WPELB_Mapping_Store::save( 42, $state );
check( true === WPELB_Sync_Worker::sync( 42 ), 'Dirty block updated on unchanged pad revision' );
$state = WPELB_Mapping_Store::get( 42 );
check( $state['blocks'][1]['status'] === 'published', 'Dirty state cleared after update' );
check( $state['blocks'][1]['entry_id'] === 101, 'Original ID unchanged' );
check( $state['blocks'][1]['last_event_id'] === 102, 'Replacement event tracked' );
check( count( $GLOBALS['comments'] ) === 1, 'Entry not duplicated by update' );
check( strpos( $GLOBALS['comments'][101]->comment_content, 'new second' ) !== false, 'Updated content found' );
$private = WPELB_Block_Parser::parse( "safe\nNOPUB\nsecret\nPOSTOK\nENDNOPUB\npublic\nPOSTOK\n" );
check( $private['blocks'][0] === "safe\npublic", 'Private section removed, POSTOK ignored inside NOPUB' );
check( count( WPELB_Block_Parser::parse("NOPUB\nsecret\nPOSTOK\n")['blocks'] ) === 0, 'Unclosed NOPUB never publishes private tail' );
$bad = WPELB_Block_Matcher::apply( array( 4 ), 'Z:a<1=4-1=5$' );
check( is_wp_error( $bad ), 'Modifying a tracked POSTOK is rejected' );
// Changing the selected author affects only subsequent entries, and the
// connector remains the authorized publishing user.
$state = WPELB_Mapping_Store::get( 42 );
$state['author_id'] = 22;
$state['publisher_id'] = 1;
WPELB_Mapping_Store::save( 42, $state );
// A reporter can close the ongoing third draft while older blocks remain linked.
append_pad( "\nPOSTOK\n" ); // revision 4 follows the edited old block + draft.
check( true === WPELB_Sync_Worker::sync( 42 ), 'Third report published after POSTOK' );
$state = WPELB_Mapping_Store::get( 42 );
check( count( $state['blocks'] ) === 3, 'Three delimiters' );
check( $state['blocks'][2]['status'] === 'published', 'Latest report published' );
check( $state['blocks'][2]['entry_id'] === 103, 'Third report creates new original ID' );
check( $GLOBALS['last_insert_args']['author_id'] === 22, 'Changed author must apply to new entries.' );
check( $GLOBALS['last_insert_args']['user']->ID === 1, 'Changed author must not elevate publisher permissions.' );
check( count( $GLOBALS['comments'] ) === 2, 'Two public entries after baseline' );
// Manual changes in WordPress must not be silently replaced by the Etherpad worker.
$GLOBALS['comments'][101]->comment_content = 'manual WordPress change';
$old = $GLOBALS['pad'][$GLOBALS['revision']];
$pos = strpos( $old, 'new second' );
$insert = 'more ';
$GLOBALS['revision']++;
$rev = $GLOBALS['revision'];
$GLOBALS['pad'][$rev] = substr($old, 0, $pos) . $insert . substr($old, $pos);
$GLOBALS['changes'][$rev] = 'Z:' . b36(strlen($old)) . '>' . b36(strlen($insert)) . '=' . b36($pos) . '+' . b36(strlen($insert)) . '=' . b36(strlen($old)-$pos) . '$' . $insert;
check( true === WPELB_Sync_Worker::sync( 42 ), 'Pad revision after manual change processed' );
$state = WPELB_Mapping_Store::get( 42 );
$state['blocks'][1]['changed_at'] -= 6;
WPELB_Mapping_Store::save( 42, $state );
check( true === WPELB_Sync_Worker::sync( 42 ), 'Conflicting edit handled' );
$state = WPELB_Mapping_Store::get( 42 );
check( $state['blocks'][1]['status'] === 'conflict', 'Manual WordPress edit is not overwritten' );
check( $GLOBALS['comments'][101]->comment_content === 'manual WordPress change', 'Manual WordPress edit preserved' );
// NOPOST consumes a complete block but never creates a Liveblog entry.
append_pad( "Nonpublishable editorial note\nnOpOsT\n" );
check( true === WPELB_Sync_Worker::sync( 42 ), 'NOPOST private block processed' );
$state = WPELB_Mapping_Store::get( 42 );
check( count( $state['blocks'] ) === 4, 'NOPOST added a tracked boundary' );
check( $state['blocks'][3]['status'] === 'skipped', 'NOPOST not marked skipped' );
check( count( $GLOBALS['comments'] ) === 2, 'NOPOST published an entry unexpectedly' );
// A subsequent POSTOK starts a fresh section, not the previous private one.
append_pad( "Next public report\npostok\n" );
check( true === WPELB_Sync_Worker::sync( 42 ), 'Lowercase POSTOK processed after NOPOST' );
$state = WPELB_Mapping_Store::get( 42 );
check( $state['blocks'][4]['status'] === 'published', 'Publish marker after NOPOST not honored' );
check( count( $GLOBALS['comments'] ) === 3, 'Only next public report should be published' );
check( strpos( $GLOBALS['comments'][$state['blocks'][4]['entry_id']]->comment_content, 'Nonpublishable' ) === false, 'NOPOST private content leaked' );
echo "Parser, marker tracking, Liveblog author selection, NOPOST, per-block editing and NOPUB smoke tests passed.\\n";
