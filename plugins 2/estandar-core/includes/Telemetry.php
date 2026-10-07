<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Telemetry bus — unified non-PII event channel (absorbs the legacy
 * `estandar_telemetry_event` concept under a product-safe channel).
 */
class Telemetry implements TelemetryBus
{
	const EVENT_ACTIONS = array( 'info', 'debug', 'warning', 'error' );
	const OPTION        = 'estandar_suite_core_telemetry_settings';
	const CRON          = 'estandar_suite_telemetry_daily';
	const LEVEL_RANK    = array( 'debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3 );

	public static function defaults(): array
	{
		return array(
			'enabled'        => 1,
			'retention_days' => 30,
			'min_level'      => 'info',
		);
	}

	public static function settings(): array
	{
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$saved = is_array( $saved ) ? $saved : array();
		$s     = array_merge( self::defaults(), $saved );
		$s['enabled'] = ! empty( $s['enabled'] ) ? 1 : 0;
		$s['retention_days'] = max( 1, min( 365, (int) $s['retention_days'] ) );
		if ( ! isset( self::LEVEL_RANK[ $s['min_level'] ] ) ) {
			$s['min_level'] = self::defaults()['min_level'];
		}
		return $s;
	}

	/** Sanitize + persist settings (returns the stored array). */
	public static function save_settings( array $raw ): array
	{
		$s = self::defaults();
		$s['enabled'] = ! empty( $raw['enabled'] ) ? 1 : 0;
		$s['retention_days'] = isset( $raw['retention_days'] ) ? max( 1, min( 365, (int) $raw['retention_days'] ) ) : 30;
		$min = sanitize_key( (string) ( $raw['min_level'] ?? 'info' ) );
		$s['min_level'] = isset( self::LEVEL_RANK[ $min ] ) ? $min : 'info';
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $s, false );
		}
		return $s;
	}

	/** Runtime wiring: daily cleanup sweep (guarded, idempotent). */
	public static function init(): void
	{
		add_action( self::CRON, array( __CLASS__, 'prune' ) );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/** PURE: does a severity pass the configured minimum level? */
	public static function level_allows( string $severity, string $min_level ): bool
	{
		$sev = self::LEVEL_RANK[ $severity ] ?? 1;
		$min = self::LEVEL_RANK[ $min_level ] ?? 1;
		return $sev >= $min;
	}

	/** Delete rows older than the retention window (DB-safe via PHP cutoff). */
	public static function prune(): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$days   = (int) self::settings()['retention_days'];
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}estandar_core_telemetry WHERE created_at < %s", $cutoff
		) );
	}

	public static function emit( string $severity, string $code, string $message, array $context = array() ): void
	{
		$severity = in_array( $severity, self::EVENT_ACTIONS, true ) ? $severity : 'info';
		$code     = substr( sanitize_key( $code ), 0, 80 );

		// Settings gate: disabled or below minimum level → not persisted.
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) || ! self::level_allows( $severity, (string) $settings['min_level'] ) ) {
			return;
		}

		// Pure/non-WP guard (tests + CLI contexts).
		if ( ! isset( $GLOBALS['wpdb'] ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'estandar_core_telemetry', array(
			'severity'     => $severity,
			'code'         => $code,
			'message'      => mb_substr( sanitize_textarea_field( $message ), 0, 500 ),
			'context_json' => wp_json_encode( self::sanitize_context( $context ) ),
			'source_plugin' => isset( $context['source_plugin'] ) ? sanitize_key( $context['source_plugin'] ) : '',
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );

		do_action( 'estandar_suite_telemetry', array(
			'severity' => $severity,
			'code'     => $code,
			'message'  => $message,
			'context'  => self::sanitize_context( $context ),
		) );
	}

	/** Drop PII-looking keys defensively before persisting. */
	public static function sanitize_context( array $context ): array
	{
		$blocked = array( 'email', 'phone', 'password', 'token', 'secret', 'key', 'access_token', 'refresh_token' );
		$out     = array();
		foreach ( $context as $k => $v ) {
			if ( in_array( strtolower( (string) $k ), $blocked, true ) ) {
				continue;
			}
			if ( is_scalar( $v ) || is_array( $v ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/** Recent telemetry (read API). */
	public static function recent( int $limit = 50 ): array
	{
		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			return array();
		}
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT severity, code, message, created_at FROM {$wpdb->prefix}estandar_core_telemetry ORDER BY id DESC LIMIT %d",
			$limit
		), ARRAY_A );
	}
}
