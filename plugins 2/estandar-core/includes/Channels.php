<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared channel/attribution primitives — extracted so Capture (facts) and
 * Insights (reporting) reuse the SAME definitions without a cross-product
 * dependency. Pure functions.
 *
 * IEE-7: añadido AttributionCanonical — separa raw_referrer / raw_source /
 * raw_medium de canonical_channel, con attribution_confidence y
 * attribution_reason. Un dominio de redirect/referral no se convierte
 * automáticamente en canal comercial sin evidencia suficiente.
 */
class Channels
{
	const CLICK_KEYS = array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'meta_fbc', 'meta_fbp', 'ttclid', 'msclkid' );

	/** PURE: canonical channel resolution. */
	public static function resolve( string $source, string $medium ): string
	{
		$source = strtolower( trim( $source ) );
		$medium = strtolower( trim( $medium ) );
		if ( in_array( $medium, array( 'cpc', 'ppc', 'paid' ), true ) ) {
			if ( in_array( $source, array( 'google', 'g' ), true ) ) { return 'google_ads'; }
			if ( in_array( $source, array( 'fb', 'facebook', 'ig', 'instagram' ), true ) ) { return 'meta_ads'; }
			return 'paid_other';
		}
		if ( $medium === 'organic' ) { return $source === 'google' ? 'google_organic' : 'organic'; }
		if ( $medium === 'referral' ) { return 'referral'; }
		return 'direct';
	}

	/**
	 * IEE-7: resolución canónica de atribución con confianza.
	 *
	 * SEPARA las dimensiones crudas de la conclusión canónica.
	 * Un redirect/referral sin evidencia NO se convierte en canal comercial.
	 *
	 * @param array $attribution {
	 *   raw_referrer  ?string  URL de referencia completa.
	 *   raw_source    ?string  utm_source o fuente inferida.
	 *   raw_medium    ?string  utm_medium o medio inferido.
	 *   gclid         ?string  Google Click ID.
	 *   fbclid        ?string  Facebook Click ID.
	 *   meta_fbc      ?string  Meta Click ID (cookie).
	 *   ...otros click IDs...
	 * }
	 * @return array {
	 *   raw_referrer         string
	 *   raw_source           string
	 *   raw_medium           string
	 *   canonical_channel    string    canal canónico resuelto ('' si no se puede resolver).
	 *   attribution_confidence float  0.0–1.0.
	 *   attribution_reason   string   razón de la resolución.
	 *   click_evidence       array    click IDs encontrados.
	 * }
	 */
	public static function canonicalize( array $attribution ): array
	{
		$raw_referrer = trim( (string) ( $attribution['raw_referrer'] ?? '' ) );
		$raw_source   = trim( (string) ( $attribution['raw_source'] ?? '' ) );
		$raw_medium   = trim( (string) ( $attribution['raw_medium'] ?? '' ) );

		$click_evidence = self::extract_click_ids( $attribution );
		$channel = '';
		$confidence = 0.0;
		$reason = '';

		// Nivel 1: click IDs = máxima confianza (evidencia criptográfica del clic).
		if ( ! empty( $click_evidence ) ) {
			foreach ( $click_evidence as $key => $val ) {
				if ( in_array( $key, array( 'gclid', 'gbraid', 'wbraid' ), true ) ) {
					$channel = 'google_ads';
					$confidence = 0.95;
					$reason = 'google_click_id_present';
					break;
				}
				if ( in_array( $key, array( 'fbclid', 'meta_fbc' ), true ) ) {
					$channel = 'meta_ads';
					$confidence = 0.95;
					$reason = 'meta_click_id_present';
					break;
				}
			}
		}

		// Nivel 2: UTM source + medium = confianza media.
		if ( $confidence < 0.9 && $raw_source !== '' && $raw_medium !== '' ) {
			$resolved = self::resolve( $raw_source, $raw_medium );
			$channel = $resolved;
			$confidence = 0.7;
			$reason = 'utm_resolved';
		}

		// Nivel 3: solo UTM source sin medium = confianza baja.
		if ( $confidence < 0.5 && $raw_source !== '' && $raw_medium === '' ) {
			if ( in_array( $raw_source, array( 'google', 'g' ), true ) ) {
				$channel = 'google_ads';
				$confidence = 0.4;
				$reason = 'utm_source_only_google';
			} elseif ( in_array( $raw_source, array( 'fb', 'facebook', 'ig', 'instagram' ), true ) ) {
				$channel = 'meta_ads';
				$confidence = 0.4;
				$reason = 'utm_source_only_meta';
			}
		}

		// Nivel 4: solo referrer sin UTM = no se puede determinar el canal.
		// Un dominio de redirect/referral NO es un canal comercial.
		if ( $confidence < 0.3 && $raw_referrer !== '' && $raw_source === '' && $raw_medium === '' ) {
			$channel = '';
			$confidence = 0.0;
			$reason = 'referrer_insufficient';
		}

		// Nivel 5: sin datos → sin canal.
		if ( $channel === '' && $confidence < 0.1 ) {
			$reason = $reason !== '' ? $reason : 'no_evidence';
		}

		return array(
			'raw_referrer'          => $raw_referrer,
			'raw_source'            => $raw_source,
			'raw_medium'            => $raw_medium,
			'canonical_channel'     => $channel,
			'attribution_confidence' => $confidence,
			'attribution_reason'    => $reason,
			'click_evidence'        => $click_evidence,
		);
	}

	/** PURE: extract present click IDs from an attribution map. */
	public static function extract_click_ids( array $attribution ): array
	{
		$out = array();
		foreach ( self::CLICK_KEYS as $key ) {
			$value = trim( (string) ( $attribution[ $key ] ?? '' ) );
			if ( $value !== '' ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/** PURE: usable click evidence for a platform channel. */
	public static function has_click_evidence( array $attribution, string $channel ): bool
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
			return false; // _fbp alone is not click evidence (contract parity).
		}
		return false;
	}
}
