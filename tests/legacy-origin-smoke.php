<?php
/** Legacy Etherpad origin must never silently point to a publisher's server. */
define( 'ABSPATH', '/tmp/wordpress/' );
class WP_Error {
    public $code;
    public function __construct( $code, $message ) { $this->code = $code; }
}
function wp_parse_url( $url ) { return parse_url( $url ); }
require_once dirname( __DIR__ ) . '/includes/class-wpelb-etherpad-client.php';

if ( defined( 'WPELB_ETHERPAD_BASE_URL' ) ) {
    throw new RuntimeException( 'This test must run without legacy origin config.' );
}
try {
    new WPELB_Etherpad_Client();
    throw new RuntimeException( 'Legacy origin fallback was not rejected.' );
} catch ( InvalidArgumentException $e ) {
    if ( false === strpos( $e->getMessage(), 'WPELB_ETHERPAD_BASE_URL' ) ) {
        throw new RuntimeException( 'Legacy origin error did not explain configuration.' );
    }
}
$result = WPELB_Etherpad_Client::pad_id_from_url( 'https://pad.example.org/p/test' );
if ( ! ( $result instanceof WP_Error ) || 'wpelb_origin' !== $result->code ) {
    throw new RuntimeException( 'Unconfigured legacy URL must be rejected.' );
}
echo "Legacy Etherpad API origin requires explicit site configuration.\n";
