<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Stable JSON for immutable revision bytes and hashes. Lists retain their order; maps sort by key. */
class Estandar_Quotes_Canonical_Json {
	public static function encode( $value ): string { return wp_json_encode( self::normalize( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
	public static function hash( $value ): string { return hash( 'sha256', self::encode( $value ) ); }
	public static function normalize( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		foreach ( $value as $key => $item ) { $value[ $key ] = self::normalize( $item ); }
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value, SORT_STRING ); }
		return $value;
	}
}
