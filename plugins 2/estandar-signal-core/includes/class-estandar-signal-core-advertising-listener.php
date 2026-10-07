<?php
/**
 * AdvertisingEvent consumer (Phase 1 decoupling).
 *
 * Subscribes to the normalized 'estandar_advertising_event_ready' event emitted
 * by estandar-leads and persists it via the repository. This is the NEW canonical
 * path: Signal Core acts purely as infrastructure and does not consult es_leads,
 * apply commercial whitelists, nor call event_for_status().
 *
 * @package EstandarSignalCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Signal_Core_Advertising_Listener {

	const HOOK = 'estandar_advertising_event_ready';

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'on_event' ), 10, 1 );
	}

	public static function on_event( $event ): void {
		if ( ! is_array( $event ) ) {
			return;
		}
		Estandar_Signal_Core_Repository::record_from_event( $event );
	}
}
