<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Estandar_Quotes_Profiles {
	public static function all(): array { $profiles = array( 'base' => array( 'version' => '1', 'label' => 'Base', 'schema' => array( 'required' => array( 'title', 'currency', 'items' ) ) ) ); return apply_filters( 'estandar_quotes_profiles', $profiles ); }
	public static function get( string $key ): array { $profile = self::all()[ self::key( $key ) ] ?? array(); if ( ! is_array( $profile ) ) { throw new InvalidArgumentException( 'Unknown quote profile.' ); } return $profile; }
	public static function key( string $key ): string { return preg_replace( '/[^a-z0-9_@.-]/', '', strtolower( $key ) ); }
	public static function validate_snapshot( string $key, array $snapshot ): array { $profile = self::get( $key ); if ( isset( $profile['normalizer'] ) && is_callable( $profile['normalizer'] ) ) { $snapshot = call_user_func( $profile['normalizer'], $snapshot ); } foreach ( (array) ( $profile['schema']['required'] ?? array() ) as $field ) { if ( ! array_key_exists( $field, $snapshot ) ) { throw new InvalidArgumentException( 'Missing required quote field: ' . $field ); } } return $snapshot; }
}
