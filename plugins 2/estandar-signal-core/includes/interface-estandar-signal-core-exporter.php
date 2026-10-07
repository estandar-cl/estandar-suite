<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for Signal Core exporters.
 *
 * An exporter is responsible for sending a recorded signal to an external
 * ad platform (e.g. Meta CAPI, Google Ads OCI). Each exporter declares
 * which events it handles and receives the full signal row on export.
 *
 * Usage:
 *   add_filter( 'estandar_signal_core_exporters', function( $exporters ) {
 *       $exporters[] = new My_Custom_Exporter();
 *       return $exporters;
 *   } );
 */
interface Estandar_Signal_Core_Exporter_Interface {

	/**
	 * Unique identifier for this exporter.
	 * Used for logging and per-exporter tracking.
	 * Example: 'meta_capi', 'google_ads_oci'
	 */
	public function get_id(): string;

	/**
	 * Returns true if this exporter handles the given event name.
	 * Called before export() to decide whether to dispatch.
	 *
	 * @param string $event_name One of: lead_created, lead_contacted,
	 *                           lead_qualified, meeting_booked,
	 *                           sale_closed, lead_rejected.
	 */
	public function supports_event( string $event_name ): bool;

	/**
	 * Send the signal to the external platform.
	 *
	 * @param array $signal Full row from es_signal_events (ARRAY_A).
	 *                      Includes: signal_uuid, event_name, event_time,
	 *                      gclid, gbraid, wbraid, fbclid, meta_fbc, meta_fbp,
	 *                      email_hash, phone_hash, identity_json, etc.
	 * @return bool          True = success (mark exported).
	 *                       False = transient failure (will retry).
	 * @throws \Exception    Thrown for permanent failures (will mark failed,
	 *                       no further retries).
	 */
	public function export( array $signal ): bool;
}
