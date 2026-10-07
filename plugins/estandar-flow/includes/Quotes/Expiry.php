<?php

namespace Estandar\Flow\Quotes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expiry — cron diario que expira las cotizaciones vencidas (M7).
 *
 * Sólo programa NUESTRO hook (`estandar_suite_flow_quote_expiry`); todos los
 * accesos a la API de cron están guardados con `function_exists` (sin WP no
 * fataliza).
 */
class Expiry
{
	const HOOK = 'estandar_suite_flow_quote_expiry';

	/** Programa el evento diario si aún no está programado. */
	public static function schedule(): void
	{
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + 3600, 'daily', self::HOOK );
	}

	/** Limpia el evento programado. */
	public static function unschedule(): void
	{
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Callback del cron: expira las cotizaciones vencidas. */
	public static function run(): void
	{
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Lifecycle' ) ) {
			\Estandar\Flow\Quotes\Lifecycle::expire_due();
		}
	}
}
