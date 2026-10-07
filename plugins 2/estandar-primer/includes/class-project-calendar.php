<?php
/**
 * PROJECT_CALENDAR_V1 — shared project-level business-time calendar.
 *
 * Converts calendar-elapsed time into project BUSINESS time so that a lead
 * arriving Friday 17:55 and reviewed Monday 09:05 reads as ~10 business
 * minutes, not ~63 hours.
 *
 * Scope V1 (frozen): project-level calendars only.
 *   NO operator calendars · NO holidays · NO shifts · NO availability mgmt.
 *
 * Storage: wp_option dictionary keyed by project slug (smallest change;
 * the Project Registry is FROZEN per ADR-003, so we do NOT touch its schema).
 * Missing project → system default calendar.
 *
 * Inputs are treated as UTC (events persist via gmdate); the working window
 * (start_time/end_time on business_days) is evaluated in the project timezone.
 *
 * @package Estandar_Core_MU
 * @since   PROJECT_CALENDAR_V1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Project_Calendar' ) ) :

class Estandar_Project_Calendar {

	const OPTION = 'estandar_project_calendars_v1';

	const WEEKDAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/** System default — applies to any project without an explicit override. */
	const DEFAULT_CALENDAR = array(
		'timezone'      => 'America/Santiago',
		'business_days' => array( 'mon', 'tue', 'wed', 'thu', 'fri' ),
		'start_time'    => '09:00',
		'end_time'      => '18:00',
	);

	/**
	 * Resolve the calendar for a project slug.
	 * Override (wp_option) → system default. Always returns a normalized array.
	 */
	public static function for_project( string $project_slug ): array {
		$slug  = sanitize_key( $project_slug );
		$store = get_option( self::OPTION, array() );
		$store = is_array( $store ) ? $store : array();

		$raw = ( $slug !== '' && is_array( $store[ $slug ] ?? null ) )
			? $store[ $slug ]
			: self::DEFAULT_CALENDAR;

		return self::normalize_calendar( $raw );
	}

	/**
	 * Validate/normalize a calendar definition, falling back field-by-field to
	 * the system default. Guarantees a usable, deterministic structure.
	 */
	public static function normalize_calendar( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$def = self::DEFAULT_CALENDAR;

		// Timezone: must be a valid identifier, else default.
		$tz = (string) ( $raw['timezone'] ?? $def['timezone'] );
		if ( ! in_array( $tz, timezone_identifiers_list(), true ) ) {
			$tz = $def['timezone'];
		}

		// Business days: intersect with known weekday tokens; empty → default.
		$days = array();
		if ( is_array( $raw['business_days'] ?? null ) ) {
			foreach ( $raw['business_days'] as $d ) {
				$d = strtolower( substr( trim( (string) $d ), 0, 3 ) );
				if ( in_array( $d, self::WEEKDAYS, true ) && ! in_array( $d, $days, true ) ) {
					$days[] = $d;
				}
			}
		}
		if ( empty( $days ) ) {
			$days = $def['business_days'];
		}

		$start = self::normalize_time( $raw['start_time'] ?? '', $def['start_time'] );
		$end   = self::normalize_time( $raw['end_time'] ?? '', $def['end_time'] );
		// Degenerate/inverted window → default window.
		if ( self::time_to_minutes( $end ) <= self::time_to_minutes( $start ) ) {
			$start = $def['start_time'];
			$end   = $def['end_time'];
		}

		return array(
			'timezone'      => $tz,
			'business_days' => $days,
			'start_time'    => $start,
			'end_time'      => $end,
		);
	}

	/**
	 * Business time elapsed between two UTC datetimes, per a project calendar.
	 *
	 * @param string|DateTimeInterface $start_utc Start (UTC).
	 * @param string|DateTimeInterface $end_utc   End (UTC).
	 * @param array|null               $calendar  Normalized calendar; null → default.
	 * Canonical output keys = business_elapsed_minutes / business_elapsed_hours
	 * (legacy business_minutes / business_hours retained as aliases per the
	 * original EXECUTION SPEC). New features MUST consume business_elapsed_*.
	 *
	 * @return array{business_elapsed_minutes:int,business_elapsed_hours:float,business_minutes:int,business_hours:float}
	 */
	public static function business_elapsed( $start_utc, $end_utc, ?array $calendar = null ): array {
		$calendar = self::normalize_calendar( $calendar ?? self::DEFAULT_CALENDAR );

		$start = self::to_datetime( $start_utc, $calendar['timezone'] );
		$end   = self::to_datetime( $end_utc, $calendar['timezone'] );
		if ( $start === null || $end === null || $end <= $start ) {
			return self::result( 0 );
		}

		$days        = array_flip( $calendar['business_days'] );
		$open_min    = self::time_to_minutes( $calendar['start_time'] );
		$close_min   = self::time_to_minutes( $calendar['end_time'] );
		$total_min   = 0;
		$tz          = $start->getTimezone();

		// Walk each calendar day in the project timezone from the start date to
		// the end date (inclusive); accumulate the overlap with that day's
		// working window. Capped to avoid runaway loops on bad input.
		$cursor = new DateTime( $start->format( 'Y-m-d' ) . ' 00:00:00', $tz );
		$last   = new DateTime( $end->format( 'Y-m-d' ) . ' 00:00:00', $tz );
		$guard  = 0;

		while ( $cursor <= $last && $guard < 3660 ) {
			$guard++;
			$dow = strtolower( $cursor->format( 'D' ) ); // mon, tue, ...
			$dow = substr( $dow, 0, 3 );

			if ( isset( $days[ $dow ] ) ) {
				$win_open  = ( clone $cursor )->modify( "+{$open_min} minutes" );
				$win_close = ( clone $cursor )->modify( "+{$close_min} minutes" );

				$lo = ( $start > $win_open ) ? $start : $win_open;
				$hi = ( $end < $win_close ) ? $end : $win_close;

				if ( $hi > $lo ) {
					$total_min += (int) round( ( $hi->getTimestamp() - $lo->getTimestamp() ) / 60 );
				}
			}
			$cursor->modify( '+1 day' );
		}

		return self::result( $total_min );
	}

	/** Canonical result shape (+ legacy aliases). */
	private static function result( int $minutes ): array {
		$hours = round( $minutes / 60, 2 );
		return array(
			'business_elapsed_minutes' => $minutes,
			'business_elapsed_hours'   => $hours,
			// Legacy aliases (original EXECUTION SPEC output contract).
			'business_minutes'         => $minutes,
			'business_hours'           => $hours,
		);
	}

	/** Convenience: business time elapsed for a given project slug. */
	public static function business_elapsed_for_project( $start_utc, $end_utc, string $project_slug ): array {
		return self::business_elapsed( $start_utc, $end_utc, self::for_project( $project_slug ) );
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	private static function to_datetime( $value, string $timezone ): ?DateTime {
		try {
			if ( $value instanceof DateTimeInterface ) {
				$dt = new DateTime( $value->format( 'Y-m-d H:i:s' ), new DateTimeZone( $value->format( 'e' ) ?: 'UTC' ) );
			} else {
				$value = trim( (string) $value );
				if ( $value === '' ) {
					return null;
				}
				$dt = new DateTime( $value, new DateTimeZone( 'UTC' ) );
			}
			$dt->setTimezone( new DateTimeZone( $timezone ) );
			return $dt;
		} catch ( Exception $e ) {
			return null;
		}
	}

	private static function normalize_time( $value, string $fallback ): string {
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
