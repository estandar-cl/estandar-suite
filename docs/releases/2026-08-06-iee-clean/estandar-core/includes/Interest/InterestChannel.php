<?php

namespace Estandar\Core\Interest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * InterestChannel — pesos de canal configurables.
 *
 * INTEREST_EVOLUTION_ENGINE_V1 (IEE-1, ADR-0044).
 *
 * - El canal modifica el peso del interés, pero las ponderaciones NO están
 *   hardcodeadas: cada instalación las ajusta con el filtro
 *   `estandar_interest_channel_weight`.
 * - Los valores por defecto son sugerencias neutrales (nunca ley).
 * - Canal desconocido / vacío => 1.0 (neutral, no penaliza ni favorece).
 *
 * Contractos de canal (normalizados por el adapter a estos slugs):
 *   direct · organic · google_ads · meta_ads · referral · form_web · chatbot
 */
final class InterestChannel
{
	/** Peso por defecto por canal (sugerencia, no ley). */
	public static function defaults(): array
	{
		return array(
			'direct'    => 1.0,
			'organic'   => 0.8,
			'google_ads' => 1.0,
			'meta_ads'  => 0.9,
			'referral'  => 1.1,
			'form_web'  => 1.0,
			'chatbot'   => 1.0,
		);
	}

	/** Mapa completo canal => peso, reemplazable por filtro por instalación. */
	public static function weights(): array
	{
		$defaults = self::defaults();
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'estandar_interest_channel_weight', $defaults );
			if ( is_array( $filtered ) ) {
				return $filtered;
			}
		}
		return $defaults;
	}

	/** Peso de un canal. Desconocido/vacío => 1.0 (neutral). */
	public static function weight( string $channel ): float
	{
		$channel = sanitize_key( $channel );
		$weights = self::weights();
		if ( $channel !== '' && isset( $weights[ $channel ] ) ) {
			return (float) $weights[ $channel ];
		}
		return 1.0;
	}

	/** Etiqueta legible por canal. Desconocido => canal o 'Sitio'. */
	public static function label( string $channel ): string
	{
		$labels = array(
			'direct'    => 'Directo',
			'organic'   => 'Orgánico',
			'google_ads' => 'Google Ads',
			'meta_ads'  => 'Meta Ads',
			'referral'  => 'Referido',
			'form_web'  => 'Formulario Web',
			'chatbot'   => 'Chatbot',
		);
		$channel = sanitize_key( $channel );
		if ( $channel !== '' && isset( $labels[ $channel ] ) ) {
			return $labels[ $channel ];
		}
		return $channel !== '' ? $channel : 'Sitio';
	}
}
