<?php
/** Unicode control-string and NOPOST boundary safety tests. */
define( 'ABSPATH', '/tmp/wordpress/' );
class WP_Error {
    public function __construct( $code, $message ) {}
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
require_once dirname( __DIR__ ) . '/includes/class-wpelb-block-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-wpelb-block-matcher.php';
function check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }

$publish = array(
    'POSTOK', 'postok', 'PostOk',
    'ΠΟΣΤΟΚ', 'ποστοκ', 'ΠοΣτΟκ',
    'POΣTOK', 'ΠOΣTOΚ', 'pοstοk',
);
$skip = array(
    'NOPOST', 'nopost', 'NoPost',
    'ΝΟΠΟΣΤ', 'νοποστ', 'ΝοΠοΣτ',
    'NΟPOΣT', 'ΝoPΟsΤ',
);
foreach ( $publish as $literal ) {
    check( WPELB_Block_Parser::command( $literal ) === 'POSTOK', "Unrecognized publish marker: $literal" );
}
foreach ( $skip as $literal ) {
    check( WPELB_Block_Parser::command( $literal ) === 'NOPOST', "Unrecognized exclude marker: $literal" );
}
foreach ( array( 'POSTO', 'POSTOK!', 'NO POST', 'NOPOST!', 'xPOSTOK', 'ΝΟ ΠΟΣΤ' ) as $str ) {
    check( null === WPELB_Block_Parser::command( $str ), "False-positive control: $str" );
}
foreach ( array_merge( $publish, $skip ) as $literal ) {
    $source = "intro\n " . $literal . " \ntail in draft\n";
    $parsed = WPELB_Block_Parser::parse( $source );
    check( ! $parsed['error'], "Parse error: $literal" );
    check( count( $parsed['markers'] ) === 1, "Missing marker: $literal" );
    $anchors = WPELB_Block_Matcher::anchors( $source, $parsed['markers'] );
    check( $anchors[0] === 7, "UTF-16 marker anchor wrong for $literal" );
    check( $parsed['markers'][0]['length'] === 6, "Changed anchor width for $literal" );
    check( ( 'POSTOK' === $parsed['commands'][0] ) === in_array( $literal, $publish, true ), "Wrong type for $literal" );
}
$p = WPELB_Block_Parser::parse( "Private reporter note\nνοποστ\nPublic report\nποστοκ\nUnfinished draft" );
check( count( $p['blocks'] ) === 2, 'Both marker kinds must own one boundary each.' );
check( $p['blocks'][0] === '' && $p['commands'][0] === 'NOPOST', 'NOPOST must discard preceding block.' );
check( $p['blocks'][1] === 'Public report' && $p['commands'][1] === 'POSTOK', 'POSTOK must publish next block only.' );
check( count( $p['markers'] ) === 2, 'Both boundaries need changeset anchors.' );
check( ! in_array( 'Private reporter note', $p['blocks'], true ), 'Excluded content leaked.' );
$legacy = WPELB_Block_Parser::parse( "safe\nNOPUB\nsecret\nPOSTOK\nENDNOPUB\nvisible\nPOSTOK\n" );
check( $legacy['blocks'][0] === "safe\nvisible", 'Legacy private spans broke.' );
check( count( $legacy['markers'] ) === 1, 'POSTOK inside legacy private span must be ignored.' );
check( count( WPELB_Block_Parser::parse( "NOPUB\nsecret\npostok\n" )['blocks'] ) === 0, 'Unclosed private span leaked.' );
echo "Greek/Latin keyboard markers, NOPOST boundaries and legacy exclusion tests passed.\n";
