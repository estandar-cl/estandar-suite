<?php
/**
 * OPERATIONAL_HOURS_V1 — site-wide business hours gate.
 *
 * Determines whether the current moment falls within operational hours.
 * Consumers use is_open() as a gate for notifications/compliance.
 * Capture, ingestion, attribution, autoresponse remain 24/7.
 *
 * Storage: wp_option 'estandar_operational_hours_v1'.
 * Disabled by default = current behavior intact.
 *
 * @package Estandar_Core_MU
 * @since   OPERATIONAL_HOURS_V1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Operational_Hours' ) ) :

class Estandar_Operational_Hours {

	const OPTION = 'estandar_operational_hours_v1';

	const WEEKDAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	const DEFAULT_CONFIG = array(
		'enabled'  => false,
		'timezone' => 'America/Santiago',
		'schedule' => array(
			'mon' => array( 'start' => '09:00', 'end' => '18:00' ),
			'tue' => array( 'start' => '09:00', 'end' => '18:00' ),
			'wed' => array( 'start' => '09:00', 'end' => '18:00' ),
			'thu' => array( 'start' => '09:00', 'end' => '18:00' ),
			'fri' => array( 'start' => '09:00', 'end' => '18:00' ),
			'sat' => null,
			'sun' => null,
		),
	);

	/**
	 * Get the current configuration (validated).
	 */
	public static function get_config(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return self::normalize_config( $raw );
	}

	/**
	 * Save configuration.
	 */
	public static function save_config( array $config ): bool {
		$normalized = self::normalize_config( $config );
		return update_option( self::OPTION, $normalized, false );
	}

	/**
	 * Is the system currently within operational hours?
	 *
	 * @param DateTimeInterface|null $at Moment to check (null = now).
	 * @param array|null $config Optional override config. If null, reads global option.
	 * @return bool True if open or if feature is disabled (always-open fallback).
	 */
	public static function is_open( $at = null, ?array $config = null ): bool {
		$config = $config ?? self::get_config();

		if ( empty( $config['enabled'] ) ) {
			return true;
		}

		$now = self::resolve_moment( $at, $config['timezone'] );
		if ( $now === null ) {
			return true;
		}

		$dow = strtolower( substr( $now->format( 'D' ), 0, 3 ) );
		$schedule = $config['schedule'];

		if ( ! isset( $schedule[ $dow ] ) || $schedule[ $dow ] === null ) {
			return false;
		}

		$day_config = $schedule[ $dow ];
		$start = self::time_to_minutes( $day_config['start'] ?? '09:00' );
		$end   = self::time_to_minutes( $day_config['end'] ?? '18:00' );
		$current = (int) $now->format( 'H' ) * 60 + (int) $now->format( 'i' );

		return $current >= $start && $current < $end;
	}

	/**
	 * Next moment when operational hours begin (in project timezone).
	 *
	 * @param DateTimeInterface|null $from Starting point (null = now).
	 * @return string|null ISO datetime string in config timezone, or null if disabled/no schedule.
	 */
	public static function next_open_window( $from = null, ?array $config = null ): ?string {
		$config = $config ?? self::get_config();

		if ( empty( $config['enabled'] ) ) {
			return null;
		}

		$now = self::resolve_moment( $from, $config['timezone'] );
		if ( $now === null ) {
			return null;
		}

		if ( self::is_open( $from, $config ) ) {
			return $now->format( 'Y-m-d H:i' );
		}

		$cursor = clone $now;
		$schedule = $config['schedule'];

		for ( $i = 0; $i < 8; $i++ ) {
			if ( $i > 0 ) {
				$cursor->setTime( 0, 0, 0 );
			}

			$dow = strtolower( substr( $cursor->format( 'D' ), 0, 3 ) );

			if ( isset( $schedule[ $dow ] ) && $schedule[ $dow ] !== null ) {
				$start_min = self::time_to_minutes( $schedule[ $dow ]['start'] ?? '09:00' );
				$current_min = (int) $cursor->format( 'H' ) * 60 + (int) $cursor->format( 'i' );

				if ( $current_min < $start_min ) {
					$h = intdiv( $start_min, 60 );
					$m = $start_min % 60;
					$cursor->setTime( $h, $m, 0 );
					return $cursor->format( 'Y-m-d H:i' );
				}
			}

			$cursor->modify( '+1 day' );
			$cursor->setTime( 0, 0, 0 );
		}

		return null;
	}

	/**
	 * Next open window as a Unix timestamp (UTC). Used for scheduling deferred events.
	 *
	 * @param DateTimeInterface|null $from Starting point (null = now).
	 * @return int|null Unix timestamp or null if disabled.
	 */
	public static function next_open_timestamp( $from = null, ?array $config = null ): ?int {
		$config = $config ?? self::get_config();

		if ( empty( $config['enabled'] ) ) {
			return null;
		}

		$window = self::next_open_window( $from, $config );
		if ( $window === null ) {
			return null;
		}

		try {
			$dt = new DateTime( $window, new DateTimeZone( $config['timezone'] ) );
			return $dt->getTimestamp();
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Human-readable message about current status and next window.
	 * For chatbot consumption.
	 *
	 * @param DateTimeInterface|null $at Moment to evaluate (null = now).
	 * @return array{is_open:bool,message:string,next_window:?string}
	 */
	public static function human_message( $at = null ): array {
		$config = self::get_config();

		if ( empty( $config['enabled'] ) ) {
			return array(
				'is_open'     => true,
				'message'     => '',
				'next_window' => null,
			);
		}

		$open = self::is_open( $at );

		if ( $open ) {
			return array(
				'is_open'     => true,
				'message'     => 'Estamos en horario de atención.',
				'next_window' => null,
			);
		}

		$next = self::next_open_window( $at );
		$msg  = 'Estamos fuera de horario de atención.';

		if ( $next !== null ) {
			try {
				$dt = new DateTime( $next, new DateTimeZone( $config['timezone'] ) );
				$day_names = array(
					'Mon' => 'lunes', 'Tue' => 'martes', 'Wed' => 'miércoles',
					'Thu' => 'jueves', 'Fri' => 'viernes', 'Sat' => 'sábado', 'Sun' => 'domingo',
				);
				$day_name = $day_names[ $dt->format( 'D' ) ] ?? $dt->format( 'l' );
				$time_str = $dt->format( 'H:i' );
				$msg .= " La próxima ventana de atención es el {$day_name} a las {$time_str}.";
			} catch ( Exception $e ) {
				// fallback: no detail
			}
		}

		return array(
			'is_open'     => false,
			'message'     => $msg,
			'next_window' => $next,
		);
	}

	/**
	 * Calculate deferred minutes between a moment and the next open window.
	 *
	 * @param DateTimeInterface|null $at Moment of lead capture (null = now).
	 * @return int Minutes deferred (0 if currently open or disabled).
	 */
	public static function deferred_minutes( $at = null, ?array $config = null ): int {
		if ( self::is_open( $at, $config ) ) {
			return 0;
		}

		$config = $config ?? self::get_config();
		$now = self::resolve_moment( $at, $config['timezone'] );
		$next_ts = self::next_open_timestamp( $at, $config );

		if ( $now === null || $next_ts === null ) {
			return 0;
		}

		$diff = $next_ts - $now->getTimestamp();
		return max( 0, (int) round( $diff / 60 ) );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	public static function normalize_config( array $raw ): array {
		$def = self::DEFAULT_CONFIG;

		$enabled = ! empty( $raw['enabled'] );

		$tz = (string) ( $raw['timezone'] ?? $def['timezone'] );
		if ( ! in_array( $tz, timezone_identifiers_list(), true ) ) {
			$tz = $def['timezone'];
		}

		$schedule = array();
		$raw_schedule = is_array( $raw['schedule'] ?? null ) ? $raw['schedule'] : $def['schedule'];

		foreach ( self::WEEKDAYS as $day ) {
			if ( ! isset( $raw_schedule[ $day ] ) || $raw_schedule[ $day ] === null || $raw_schedule[ $day ] === false ) {
				$schedule[ $day ] = null;
				continue;
			}

			$day_raw = is_array( $raw_schedule[ $day ] ) ? $raw_schedule[ $day ] : array();
			$start = self::normalize_time_value( $day_raw['start'] ?? '', '09:00' );
			$end   = self::normalize_time_value( $day_raw['end'] ?? '', '18:00' );

			if ( self::time_to_minutes( $end ) <= self::time_to_minutes( $start ) ) {
				$start = '09:00';
				$end   = '18:00';
			}

			$schedule[ $day ] = array( 'start' => $start, 'end' => $end );
		}

		return array(
			'enabled'  => $enabled,
			'timezone' => $tz,
			'schedule' => $schedule,
		);
	}

	private static function resolve_moment( $at, string $timezone ): ?DateTime {
		try {
			$tz = new DateTimeZone( $timezone );

			if ( $at instanceof DateTimeInterface ) {
				$dt = new DateTime( $at->format( 'Y-m-d H:i:s' ), new DateTimeZone( $at->format( 'e' ) ?: 'UTC' ) );
				$dt->setTimezone( $tz );
				return $dt;
			}

			if ( is_string( $at ) && $at !== '' ) {
				$dt = new DateTime( $at, new DateTimeZone( 'UTC' ) );
				$dt->setTimezone( $tz );
				return $dt;
			}

			return new DateTime( 'now', $tz );
		} catch ( Exception $e ) {
			return null;
		}
	}

	private static function normalize_time_value( $value, string $fallback ): string {
		$value = trim( (string) $value );
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $m ) ) {
			$h = (int) $m[1];
			$i = (int) $m[2];
			if ( $h >= 0 && $h <= 23 && $i >= 0 && $i <= 59 ) {
				return sprintf( '%02d:%02d', $h, $i );
			}
		}
		return $fallback;
	}

	private static function time_to_minutes( string $hhmm ): int {
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $hhmm, $m ) ) {
			return (int) $m[1] * 60 + (int) $m[2];
		}
		return 0;
	}
}

endif;
