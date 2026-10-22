<?php
/** Strict, side-effect-free Etherpad delimiter parser. */
defined( 'ABSPATH' ) || exit;

final class WPELB_Block_Parser {
    /**
     * @return array{blocks:array,markers:array,error:string|null}
     * Blocks are zero-indexed by POSTOK order. NOPUB spans are not retained.
     */
    public static function parse( $source ) {
        $text = str_replace( array( "\r\n", "\r" ), "\n", (string) $source );
        $lines = explode( "\n", $text );
        $blocks = array();
        $markers = array();
        $public = array();
        $private = false;
        $offset = 0;
        foreach ( $lines as $line ) {
            $token = trim( $line );
            if ( 'NOPUB' === $token ) {
                if ( $private ) {
                    return array( 'blocks' => array(), 'markers' => array(), 'error' => 'Nested NOPUB' );
                }
                $private = true;
            } elseif ( 'ENDNOPUB' === $token ) {
                if ( ! $private ) {
                    return array( 'blocks' => array(), 'markers' => array(), 'error' => 'Unmatched ENDNOPUB' );
                }
                $private = false;
            } elseif ( 'POSTOK' === $token && ! $private ) {
                $markers[] = array( 'offset' => $offset + (strlen( $line ) - strlen( ltrim( $line ) ) ), 'length' => 6 );
                $blocks[] = trim( implode( "\n", $public ) );
                $public = array();
            } elseif ( ! $private ) {
                $public[] = $line;
            }
            $offset += strlen( $line ) + 1;
        }
        // An unclosed NOPUB tail is simply withheld. Previously completed
        // published blocks are still safe to synchronize independently.
        return array( 'blocks' => $blocks, 'markers' => $markers, 'error' => null,
            'warning' => $private ? 'Unclosed NOPUB tail withheld' : '' );
    }

    /** Public output intentionally supports plain text/paragraphs only in MVP. */
    public static function html( $text ) {
        $escaped = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        return '<p>' . str_replace( "\n", "<br />\n", $escaped ) . '</p>';
    }
}
