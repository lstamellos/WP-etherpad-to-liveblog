<?php
/**
 * Dependency-free smoke tests for the native GitHub updater.
 * Run with: php tests/updater-smoke.php
 */

define( 'ABSPATH', '/tmp/wordpress/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WPELB_VERSION', '0.1.1' );
define( 'WPELB_PLUGIN_FILE', '/wp-content/plugins/wp-etherpad-to-liveblog/wp-etherpad-to-liveblog.php' );

$GLOBALS['wpelb_test_transients'] = array();
$GLOBALS['wpelb_test_hooks'] = array();
$GLOBALS['wpelb_test_http_calls'] = 0;
$GLOBALS['wpelb_test_http_code'] = 200;
$GLOBALS['wpelb_test_http_body'] = '';

function add_filter( $name, $callback, $priority, $accepted ) {
    $GLOBALS['wpelb_test_hooks'][ $name ] = array( $callback, $accepted );
}

function plugin_basename( $filename ) {
    return 'wp-etherpad-to-liveblog/wp-etherpad-to-liveblog.php';
}

function get_site_transient( $key ) {
    return $GLOBALS['wpelb_test_transients'][ $key ] ?? false;
}

function set_site_transient( $key, $value, $expires ) {
    $GLOBALS['wpelb_test_transients'][ $key ] = $value;
    return true;
}

function wp_safe_remote_get( $url, $args ) {
    ++$GLOBALS['wpelb_test_http_calls'];
    check( $url === WPELB_GitHub_Updater::API_URL, 'Must fetch only fixed GitHub API URL' );
    check( isset( $args['limit_response_size'] ), 'Response size limit' );
    check( $args['redirection'] === 0, 'No redirects to arbitrary hosts' );
    return array(
        'response' => array( 'code' => $GLOBALS['wpelb_test_http_code'] ),
        'body' => $GLOBALS['wpelb_test_http_body'],
    );
}

function is_wp_error( $response ) {
    return false;
}

function wp_remote_retrieve_response_code( $response ) {
    return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
    return $response['body'];
}

function esc_html( $value ) {
    return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function wpautop( $value ) {
    return '<p>' . $value . '</p>';
}

function check( $condition, $description ) {
    if ( ! $condition ) {
        throw new RuntimeException( 'FAIL: ' . $description );
    }
}

function fresh( $release, $code = 200 ) {
    $GLOBALS['wpelb_test_transients'] = array();
    $GLOBALS['wpelb_test_http_body'] = json_encode( $release );
    $GLOBALS['wpelb_test_http_code'] = $code;
}

require_once dirname( __DIR__ ) . '/includes/class-wpelb-github-updater.php';

WPELB_GitHub_Updater::register();
check( isset( $GLOBALS['wpelb_test_hooks']['update_plugins_github.com'] ), 'Native update hook registered' );
check( isset( $GLOBALS['wpelb_test_hooks']['plugins_api'] ), 'Plugin details hook registered' );

$uri = WPELB_GitHub_Updater::UPDATE_URI;
$slug = 'wp-etherpad-to-liveblog';
$file = $slug . '/wp-etherpad-to-liveblog.php';
$asset = array(
    'name' => $slug . '.zip',
    'state' => 'uploaded',
    'size' => 4000,
    'browser_download_url' => $uri . '/releases/download/v0.1.1/' . $slug . '.zip',
);
$release = array(
    'tag_name' => 'v0.1.1',
    'draft' => false,
    'prerelease' => false,
    'assets' => array( $asset ),
    'published_at' => '2026-10-23T08:00:00Z',
    'body' => 'Fixes <script>alert(1)</script>',
);
$plugin = array( 'UpdateURI' => $uri, 'Version' => '0.1.0' );

fresh( $release );
$update = WPELB_GitHub_Updater::check_update( false, $plugin, $file, array() );
check( is_array( $update ) && $update['version'] === '0.1.1', 'New stable release discovered' );
check( $update['package'] === $asset['browser_download_url'], 'Expected ZIP asset selected' );

$calls = $GLOBALS['wpelb_test_http_calls'];
$details = WPELB_GitHub_Updater::plugin_information( false, 'plugin_information', (object) array( 'slug' => $slug ) );
check( is_object( $details ) && $details->version === '0.1.1', 'Plugin information supplied' );
check( strpos( $details->sections['changelog'], '<script>' ) === false, 'Release notes escaped' );
check( $GLOBALS['wpelb_test_http_calls'] === $calls, 'Release metadata cached' );

check( WPELB_GitHub_Updater::check_update( false, $plugin, 'other-plugin/plugin.php', array() ) === false, 'Other GitHub plugin untouched' );
check( WPELB_GitHub_Updater::check_update( false, array( 'UpdateURI' => 'https://github.com/another/project', 'Version' => '0.1.0' ), $file, array() ) === false, 'Wrong Update URI ignored' );
check( WPELB_GitHub_Updater::check_update( false, array( 'UpdateURI' => $uri, 'Version' => '0.1.1' ), $file, array() ) === false, 'Equal version not offered' );
check( WPELB_GitHub_Updater::plugin_information( 'existing', 'another_action', (object) array( 'slug' => $slug ) ) === 'existing', 'Other plugin API action untouched' );

$draft = $release;
$draft['draft'] = true;
fresh( $draft );
check( WPELB_GitHub_Updater::check_update( false, $plugin, $file, array() ) === false, 'Draft rejected' );
$prerelease = $release;
$prerelease['prerelease'] = true;
fresh( $prerelease );
check( WPELB_GitHub_Updater::check_update( false, $plugin, $file, array() ) === false, 'Prerelease rejected' );

$missing = $release;
$missing['assets'] = array();
fresh( $missing );
check( WPELB_GitHub_Updater::check_update( false, $plugin, $file, array() ) === false, 'No ZIP asset: no update' );

$wrong = $release;
$wrong['assets'][0]['browser_download_url'] = 'https://evil.example/plugin.zip';
fresh( $wrong );
check( WPELB_GitHub_Updater::check_update( false, $plugin, $file, array() ) === false, 'Foreign package URL rejected' );

$wrong_tag = $release;
$wrong_tag['tag_name'] = 'v0.1.1-beta';
fresh( $wrong_tag );
check( WPELB_GitHub_Updater::check_update( false, $plugin, $file, array() ) === false, 'Unstable version string rejected' );

fresh( $release, 403 );
check( WPELB_GitHub_Updater::check_update( false, $plugin, $file, array() ) === false, 'GitHub failure fails closed' );

echo "GitHub updater smoke tests passed.\n";
