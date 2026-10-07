<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduler bootstrap for estandar-destinations.
 *
 * Reserves cron hook names that adapter workers will register against.
 * No workers exist yet — hooks are declared here so adapters can attach
 * without knowing whether the engine is already loaded.
 *
 * Hooks reserved (not yet scheduled — adapters schedule on their own init):
 *   estandar_destinations_gads_process_queue   Google Ads OCI worker
 *   estandar_destinations_meta_process_queue   Meta Conversions API worker
 *
 * During migration, estandar-leads still schedules its own hooks:
 *   estandar_leads_gads_process_queue
 *   estandar_leads_meta_process_queue
 * Both sets run in parallel until Phase 5 removes the Leads copies.
 */
final class Estandar_Destinations_Scheduler {

	const HOOK_GADS = 'estandar_destinations_gads_process_queue';
	const HOOK_META = 'estandar_destinations_meta_process_queue';

	/**
	 * Wire declared hooks so adapters can add_action() against them at any load order.
	 * Does NOT schedule — adapters schedule when they init.
	 */
	public static function init(): void {
		// Intentionally empty at Phase 0.
		// Adapters call wp_schedule_event() on self::HOOK_* in their own init().
	}

	/**
	 * Unschedule Destinations-owned hooks on plugin deactivation.
	 */
	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::HOOK_GADS );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK_GADS );
		}
		$ts = wp_next_scheduled( self::HOOK_META );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK_META );
		}
	}
}
