<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Time — canonical de fechas de la Suite.
 *
 * Convención (TIMEZONE_V1): TODOS los timestamps de negocio que ve el cliente
 * (leads, submissions, events, logs, correos) se guardan en la ZONA HORARIA DE
 * WORDPRESS (`current_time('mysql')`), no en UTC. WordPress es la fuente de
 * verdad del huso (Settings → General → Timezone / gmt_offset).
 *
 * Lo que NO pasa por aquí (sigue UTC/epoch):
 *  - expiración de tokens/cookies/mutex, cooldowns y rate-limits (siempre epoch);
 *  - agendado de cron (API de WP usa epoch);
 *  - comparaciones internas de ventanas que usan time().
 *
 * Para comparar un valor guardado (zona WP) con time()/current_time('timestamp')
 * usar `self::epoch()`: convierte el string naive a epoch real aplicando el
 * gmt_offset de WordPress.
 */
class Time
{
	/** Ahora como string en zona WP (mysql). Fallback UTC si no hay WP. */
	public static function now(): string
	{
		return function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Ahora como epoch REAL (UTC). Usar en comparaciones contra valores
	 * guardados en zona WP con `Time::epoch()` o para generar cutoffs con
	 * `Time::from_epoch()`.
	 *
	 * NOTA: NO usar `current_time('timestamp')` aquí: ese devuelve el "reloj
	 * de pared como epoch" (time() + gmt_offset), que al pasarlo a
	 * `from_epoch()`/`wp_date()` suma el offset OTRA vez (doble salto).
	 * Ese bug hacía que el debounce del notifier (5 min) se volviera ~8h y
	 * consolidara notificaciones de leads recontactados.
	 */
	public static function now_epoch(): int
	{
		return time();
	}

	/**
	 * Convierte un string guardado en zona WP (naive) a epoch real.
	 * Interpreta el string EN la zona horaria de WordPress (wp_timezone) y
	 * devuelve su epoch. Fallback (sin WP): strtotime naive + gmt_offset.
	 */
	public static function epoch( string $local ): int
	{
		$local = trim( $local );
		if ( $local === '' ) {
			return 0;
		}
		if ( function_exists( 'wp_timezone' ) ) {
			try {
				$dt = new \DateTimeImmutable( $local, wp_timezone() );
				return $dt->getTimestamp();
			} catch ( \Exception $e ) {
				return 0;
			}
		}
		$ts = strtotime( $local );
		if ( $ts === false ) {
			return 0;
		}
		$offset = (int) get_option( 'gmt_offset', 0 );
		return $ts - $offset * 3600;
	}

	/**
	 * Convierte un epoch real a string en zona WP (para displays/export cuando
	 * el valor NO está guardado en zona WP).
	 */
	public static function from_epoch( int $ts ): string
	{
		if ( $ts <= 0 ) {
			return '';
		}
		return function_exists( 'wp_date' )
			? wp_date( 'Y-m-d H:i:s', $ts )
			: gmdate( 'Y-m-d H:i:s', $ts + ( (int) get_option( 'gmt_offset', 0 ) ) * 3600 );
	}

	/** Formatea un valor guardado (zona WP) a formato humano en la zona de WP. */
	public static function display( string $local, string $format = 'd/m/Y H:i' ): string
	{
		$ts = self::epoch( $local );
		if ( $ts <= 0 ) {
			return '';
		}
		return function_exists( 'wp_date' )
			? wp_date( $format, $ts )
			: gmdate( $format, $ts - ( (int) get_option( 'gmt_offset', 0 ) ) * 3600 );
	}
}
