<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intent vocabulary — deterministic mapping of a captured intent id to points.
 * Configurable per installation via the option; unknown intents contribute
 * nothing (never fabricated).
 */
class IntentMap
{
	const OPTION = 'estandar_suite_brain_intent_map';

	public static function defaults(): array
	{
		return array(
			'ventas'              => 20,
			'cotizar'             => 20,
			'precios'             => 18,
			'cambio_contador'     => 18,
			'declaracion_renta'   => 18,
			'contabilidad_mensual'=> 15,
			'inicio_actividades'  => 15,
			'opciones'            => 12,
			'asesor'              => 12,
			'tributaria'          => 12,
			'consulta'            => 8,
			'urgencia'            => 8,
			'humano'              => 5,
			'soporte'             => 5,
			'info'                => 5,
			'faq'                 => 5,
		);
	}

	public static function mapping(): array
	{
		if ( ! function_exists( 'get_option' ) ) {
			return self::defaults();
		}
		$opt = get_option( self::OPTION, array() );
		return is_array( $opt ) && ! empty( $opt ) ? $opt : self::defaults();
	}

	/** PURE: derive points from an intent id (null when unknown/empty). */
	public static function derive( string $intent ): ?array
	{
		$intent = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $intent ) : strtolower( $intent ) );
		if ( $intent === '' ) {
			return null;
		}
		$map = self::mapping();
		if ( ! isset( $map[ $intent ] ) ) {
			return null;
		}
		$points = max( 0, min( 100, (int) $map[ $intent ] ) );
		return array( 'intent' => $intent, 'points' => $points );
	}
}
