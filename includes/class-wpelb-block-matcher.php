<?php
/** Track existing POSTOK markers by Etherpad changesets across revisions. */
defined( 'ABSPATH' ) || exit;

final class WPELB_Block_Matcher {
    /** Convert byte offsets to Etherpad's UTF-16 code-unit positions. */
    public static function anchors( $text, $markers ) {
        $anchors = array();
        foreach ( $markers as $marker ) {
            $prefix = substr( $text, 0, $marker['offset'] );
            $utf16 = iconv( 'UTF-8', 'UTF-16LE', $prefix );
            if ( false === $utf16 ) {
                return new WP_Error( 'wpelb_utf8', 'Invalid UTF-8 pad content' );
            }
            $anchors[] = (int) ( strlen( $utf16 ) / 2 );
        }
        return $anchors;
    }

    /** Move existing marker anchors through a single Etherpad changeset. */
    public static function apply( $anchors, $changeset ) {
        $dollar = strpos( $changeset, '$' );
        if ( false === $dollar || ! preg_match( '/^Z:[0-9a-z]+[><][0-9a-z]+(.*)$/s', substr( $changeset, 0, $dollar ), $match ) ) {
            return new WP_Error( 'wpelb_changeset', 'Malformed Etherpad changeset' );
        }
        $ops = $match[1];
        $cursor = 0;
        $old = 0;
        $shifts = array_fill( 0, count( $anchors ), 0 );
        $length = strlen( $ops );
        while ( $cursor < $length ) {
            if ( ! preg_match( '/\G(?:\*[0-9a-z]+)*(?:\|[0-9a-z]+)?([=+\-])([0-9a-z]+)/A', $ops, $parts, 0, $cursor ) ) {
                return new WP_Error( 'wpelb_changeset', 'Unsupported Etherpad changeset operation' );
            }
            $cursor += strlen( $parts[0] );
            $n = (int) base_convert( $parts[2], 36, 10 );
            if ( $n < 1 || $n > 2000000 ) {
                return new WP_Error( 'wpelb_changeset', 'Changeset length out of bounds' );
            }
            if ( '+' === $parts[1] ) {
                foreach ( $anchors as $i => $position ) {
                    if ( $old > $position && $old < $position + 6 ) {
                        return new WP_Error( 'wpelb_anchor', 'A published POSTOK marker was modified' );
                    }
                    if ( $old <= $position ) {
                        $shifts[$i] += $n;
                    }
                }
            } elseif ( '-' === $parts[1] ) {
                foreach ( $anchors as $i => $position ) {
                    if ( $old < $position + 6 && $old + $n > $position ) {
                        return new WP_Error( 'wpelb_anchor', 'A published POSTOK marker was removed or changed' );
                    }
                    if ( $old + $n <= $position ) {
                        $shifts[$i] -= $n;
                    }
                }
                $old += $n;
            } else {
                $old += $n;
            }
        }
        foreach ( $anchors as $i => $position ) {
            $anchors[$i] = $position + $shifts[$i];
        }
        return $anchors;
    }

    /** Existing markers must remain ordered in the same positions after changesets. */
    public static function verify( $expected, $actual ) {
        if ( count( $actual ) < count( $expected ) ) {
            return false;
        }
        foreach ( $expected as $i => $position ) {
            if ( $actual[$i] !== $position ) {
                return false;
            }
        }
        return true;
    }
}
