<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Domain value object. It has no WordPress or persistence dependency. */
final class Estandar_SEO_URL {
    public static function normalize( $url ): string {
        $url = trim( (string) $url );
        if ( $url === '' ) { return ''; }
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return ''; }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
        $host = strtolower( (string) $parts['host'] );
        $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        $path = '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );
        $path = preg_replace( '#/{2,}#', '/', $path );
        if ( $path !== '/' ) { $path = rtrim( $path, '/' ) . '/'; }
        return $scheme . '://' . $host . $port . $path;
    }

    public static function hash( $normalized_url ): string {
        return hash( 'sha256', (string) $normalized_url );
    }
}
