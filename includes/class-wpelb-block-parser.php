<?php
/** Parse full-line publication decisions, including mixed Greek/Latin keyboards. */
defined( 'ABSPATH' ) || exit;

final class WPELB_Block_Parser {
    /**
     * Convert typed control words to ASCII, regardless of keyboard layout,
     * mixed alphabets or lettercase. Never normalize ordinary reporting text.
     *
     * All recognized control markers have exactly six UTF-16 code units,
     * which is required by the existing changeset anchor tracking.
     */
    public static function command( $token ) {
        $token = trim( (string) $token );
        // Greek letter lookalikes, with optional tonos for rushed typists.
        // strtr replaces whole UTF-8 characters without requiring mbstring.
        $token = strtr( $token, array(
            'Π' => 'P', 'π' => 'P',
            'Ο' => 'O', 'ο' => 'O', 'Ό' => 'O', 'ό' => 'O',
            'Σ' => 'S', 'σ' => 'S', 'ς' => 'S',
            'Τ' => 'T', 'τ' => 'T',
            'Κ' => 'K', 'κ' => 'K',
            'Ν' => 'N', 'ν' => 'N',
        ) );
        $command = strtoupper( $token );
        return in_array( $command, array( 'POSTOK', 'NOPOST' ), true ) ? $command : null;
    }

    /**
     * @return array{blocks:array,markers:array,commands:array,error:?string,warning:string}
     * POSTOK publishes the preceding block; NOPOST closes it without
     * publication. Legacy NOPUB/ENDNOPUB spans stay supported.
     */
    public static function parse( $source ) {
        $text = str_replace( array( "\r\n", "\r" ), "\n", (string) $source );
        $lines = explode( "\n", $text );
        $blocks = array();
        $markers = array();
        $commands = array();
        $public = array();
        $private = false;
        $offset = 0;
        foreach ( $lines as $line ) {
            $token = trim( $line );
            $command = self::command( $token );
            if ( 'NOPUB' === $token ) {
                if ( $private ) {
                    return array( 'blocks' => array(), 'markers' => array(), 'commands' => array(), 'error' => 'Nested NOPUB', 'warning' => '' );
                }
                $private = true;
            } elseif ( 'ENDNOPUB' === $token ) {
                if ( ! $private ) {
                    return array( 'blocks' => array(), 'markers' => array(), 'commands' => array(), 'error' => 'Unmatched ENDNOPUB', 'warning' => '' );
                }
                $private = false;
            } elseif ( null !== $command && ! $private ) {
                // strspn() returns indentation bytes; the matcher converts
                // complete UTF-8 prefixes to UTF-16 positions.
                $indentation = strlen( $line ) - strlen( ltrim( $line ) );
                $markers[] = array( 'offset' => $offset + $indentation, 'length' => 6 );
                $commands[] = $command;
                // Never retain a NOPOST section's text in the mapping.
                $blocks[] = 'POSTOK' === $command ? trim( implode( "\n", $public ) ) : '';
                $public = array();
            } elseif ( ! $private ) {
                $public[] = $line;
            }
            $offset += strlen( $line ) + 1;
        }
        return array(
            'blocks' => $blocks,
            'markers' => $markers,
            'commands' => $commands,
            'error' => null,
            'warning' => $private ? 'Unclosed NOPUB tail withheld' : '',
        );
    }

    /** Publish only escaped plain-text paragraphs in the MVP. */
    public static function html( $text ) {
        $escaped = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        return '<p>' . str_replace( "\n", "<br />\n", $escaped ) . '</p>';
    }
}
