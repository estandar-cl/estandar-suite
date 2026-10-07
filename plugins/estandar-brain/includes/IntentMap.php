<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intent vocabulary — deterministic mapping of a captured intent id to points.
 *
 * El vocabulario NO está hardcodeado a un rubro: el sitio elige un **área de
 * negocio** (ver IntentPresets) que precarga sus intents, y puede editarlos,
 * agregar o quitar. Todo es configurable por instalación.
 *
 * Compatibilidad: si existe la opción antigua `estandar_suite_brain_intent_map`
 * (mapa plano intent=>puntos) se usa como fuente mientras no haya config nueva.
 */
class IntentMap
{
	/** Opción nueva: { area, intents }. */
	const OPTION = 'estandar_suite_brain_intents';

	/** Opción legacy: mapa plano intent=>puntos. */
	const LEGACY_OPTION = 'estandar_suite_brain_intent_map';

	/**
	 * Configuración normalizada { area, intents }.
	 *
	 * @return array{area:string,intents:array<string,int>}
	 */
	public static function config(): array
	{
		$area    = IntentPresets::DEFAULT_AREA;
		$intents = array();

		if ( function_exists( 'get_option' ) ) {
			$opt = get_option( self::OPTION, array() );
			if ( is_array( $opt ) && ( isset( $opt['intents'] ) || isset( $opt['area'] ) ) ) {
				$a = isset( $opt['area'] ) ? sanitize_key( (string) $opt['area'] ) : '';
				if ( $a !== '' && IntentPresets::valid( $a ) ) {
					$area = $a;
				}
				$raw = isset( $opt['intents'] ) && is_array( $opt['intents'] ) ? $opt['intents'] : array();
				foreach ( $raw as $k => $v ) {
					$k = sanitize_key( (string) $k );
					if ( $k === '' ) {
						continue;
					}
					$intents[ $k ] = max( 0, min( 100, (int) $v ) );
				}
				if ( $intents ) {
					return array( 'area' => $area, 'intents' => $intents );
				}
			}

			// Compatibilidad con la opción plana antigua.
			$legacy = get_option( self::LEGACY_OPTION, array() );
			if ( is_array( $legacy ) && $legacy ) {
				foreach ( $legacy as $k => $v ) {
					$k = sanitize_key( (string) $k );
					if ( $k !== '' ) {
						$intents[ $k ] = max( 0, min( 100, (int) $v ) );
					}
				}
				if ( $intents ) {
					return array( 'area' => 'otro', 'intents' => $intents );
				}
			}
		}

		return array( 'area' => IntentPresets::DEFAULT_AREA, 'intents' => IntentPresets::preset( IntentPresets::DEFAULT_AREA ) );
	}

	/**
	 * Área de negocio configurada.
	 *
	 * @return string
	 */
	public static function area(): string
	{
		return self::config()['area'];
	}

	/**
	 * Intents efectivos (intent => puntos).
	 *
	 * @return array<string,int>
	 */
	public static function intents(): array
	{
		return self::config()['intents'];
	}

	/**
	 * Mapa plano (compatibilidad con llamadas existentes).
	 *
	 * @return array<string,int>
	 */
	public static function mapping(): array
	{
		return self::intents();
	}

	/**
	 * Preset de fábrica del área por defecto (compatibilidad).
	 *
	 * @return array<string,int>
	 */
	public static function defaults(): array
	{
		return IntentPresets::preset( IntentPresets::DEFAULT_AREA );
	}

	/**
	 * Normaliza y persiste una configuración.
	 *
	 * @param array $in { area, intents } o un mapa plano.
	 * @return array{area:string,intents:array<string,int>}
	 */
	public static function save( array $in ): array
	{
		$area = isset( $in['area'] ) ? sanitize_key( (string) $in['area'] ) : IntentPresets::DEFAULT_AREA;
		if ( ! IntentPresets::valid( $area ) ) {
			$area = IntentPresets::DEFAULT_AREA;
		}

		$raw     = isset( $in['intents'] ) && is_array( $in['intents'] ) ? $in['intents'] : $in;
		$intents = array();
		foreach ( $raw as $k => $v ) {
			if ( $k === 'area' || ! is_scalar( $v ) ) {
				continue;
			}
			$k = sanitize_key( (string) $k );
			if ( $k === '' ) {
				continue;
			}
			$intents[ $k ] = max( 0, min( 100, (int) $v ) );
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, array( 'area' => $area, 'intents' => $intents ), false );
		}

		return array( 'area' => $area, 'intents' => $intents );
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
