<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attribution — thin delegate to the shared Core\Channels primitives (single
 * definition; Capture owns the FACTS, Insights owns the reporting).
 */
class Attribution
{
	/** PURE: extract click IDs present in a payload (canonical key set). */
	public static function extract_click_ids( array $attribution ): array
	{
		return class_exists( 'Estandar\\Core\\Channels' )
			? \Estandar\Core\Channels::extract_click_ids( $attribution )
			: self::fallback_click_ids( $attribution );
	}

	/** PURE: canonical channel resolution. */
	public static function channel( string $source, string $medium ): string
	{
		return class_exists( 'Estandar\\Core\\Channels' )
			? \Estandar\Core\Channels::resolve( $source, $medium )
			: self::fallback_channel( $source, $medium );
	}

	/** PURE: usable click evidence for a platform channel. */
	public static function has_click_evidence( array $attribution, string $channel ): bool
	{
		return class_exists( 'Estandar\\Core\\Channels' )
			? \Estandar\Core\Channels::has_click_evidence( $attribution, $channel )
			: self::fallback_evidence( $attribution, $channel );
	}

	// ── Standalone fallbacks (no hidden dependency; Core absent) ───────────

	public static function fallback_click_ids( array $attribution ): array
	{
		$out = array();
		foreach ( Ingestion::ID_KEYS as $key ) {
			$value = trim( (string) ( $attribution[ $key ] ?? '' ) );
			if ( $value !== '' ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	public static function fallback_channel( string $source, string $medium ): string
	{
		$source = strtolower( trim( $source ) );
		$medium = strtolower( trim( $medium ) );
		if ( in_array( $medium, array( 'cpc', 'ppc', 'paid' ), true ) ) {
			if ( in_array( $source, array( 'google', 'g' ), true ) ) { return 'google_ads'; }
			if ( in_array( $source, array( 'fb', 'facebook', 'ig', 'instagram' ), true ) ) { return 'meta_ads'; }
			return 'paid_other';
		}
		if ( $medium === 'organic' ) { return $source === 'google' ? 'google_organic' : 'organic'; }
		return 'direct';
	}

	public static function fallback_evidence( array $attribution, string $channel ): bool
	{
		if ( $channel === 'google_ads' ) {
			foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $k ) {
				if ( trim( (string) ( $attribution[ $k ] ?? '' ) ) !== '' ) { return true; }
			}
			return false;
		}
		if ( $channel === 'meta_ads' ) {
			foreach ( array( 'fbclid', 'meta_fbc' ) as $k ) {
				if ( trim( (string) ( $attribution[ $k ] ?? '' ) ) !== '' ) { return true; }
			}
			return false;
		}
		return false;
	}
}
