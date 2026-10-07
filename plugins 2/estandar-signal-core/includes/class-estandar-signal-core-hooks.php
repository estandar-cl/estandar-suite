<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @deprecated Phase 1 (Leads↔Signal decoupling). This listener reads es_leads
 * status and applies the commercial whitelist (event_for_status) — domain logic
 * that now belongs to estandar-leads (Estandar_Leads_Advertising_Event). It is
 * kept ONLY as a fallback for installs whose estandar-leads predates the
 * AdvertisingEvent emitter. When that emitter class is present, every handler
 * here stands down (see legacy_superseded()), so a fact is never persisted twice.
 * Removal criterion: drop once all installs report the emitter capability
 * (filter 'estandar_advertising_event_emitter_available' === true).
 */
class Estandar_Signal_Core_Hooks {

	public static function init(): void {
		add_action( 'estandar_lead_ingested', array( __CLASS__, 'on_lead_ingested' ), 20, 4 );
		add_action( 'estandar_lead_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
	}

	/**
	 * True when the new AdvertisingEvent emitter has EXPLICITLY declared itself
	 * available (emission fully wired) — the legacy path must stand down to avoid
	 * double persistence (idempotency is the backstop). Uses a stable capability
	 * filter, never class_exists: a loaded-but-uninitialized class keeps the
	 * fallback active. No hard dependency on the Leads plugin.
	 */
	private static function legacy_superseded(): bool {
		return apply_filters( 'estandar_advertising_event_emitter_available', false ) === true;
	}

	/** Per-request guard: the routing state is invariant within a request. */
	private static $observed = false;

	/** Test seam only — reset the once-per-request observability guard. */
	public static function reset_observability(): void {
		self::$observed = false;
	}

	/**
	 * Emit deterministic, minimal observability about the fallback routing state,
	 * reusing the project's canonical telemetry channel (estandar_telemetry_event).
	 * Pure observation — it changes no functional behavior and never throws.
	 * Records: capability available/absent, new listener active, legacy active,
	 * and the reason the fallback remains enabled.
	 */
	private static function observe_routing(): void {
		if ( self::$observed ) {
			return;
		}
		self::$observed = true;

		$capability   = self::legacy_superseded();
		$new_listener = class_exists( 'Estandar_Signal_Core_Advertising_Listener' );

		try {
			do_action( 'estandar_telemetry_event', array(
				'source_plugin' => 'estandar-signal-core',
				'type'          => 'business_event',
				'severity'      => 'info',
				'code'          => $capability ? 'SIGNAL_LEGACY_FALLBACK_SUPERSEDED' : 'SIGNAL_LEGACY_FALLBACK_ACTIVE',
				'message'       => $capability
					? 'AdvertisingEvent emitter available — legacy listener stands down.'
					: 'AdvertisingEvent emitter unavailable — legacy fallback active.',
				'context'       => array(
					'capability_available'    => $capability,
					'new_listener_registered' => $new_listener,
					'legacy_active'           => ! $capability,
					'reason'                  => $capability ? 'emitter_capability_true' : 'emitter_capability_absent',
				),
			) );
		} catch ( \Throwable $e ) {
			// Observability must never break business flow.
		}
	}

	public static function on_lead_ingested( $lead_id, $submission_id, $is_new, $payload ): void {
		self::observe_routing();
		if ( self::legacy_superseded() ) {
			return; // deprecated fallback — new AdvertisingEvent path owns this fact
		}
		if ( ! $is_new ) {
			return;
		}
		if ( class_exists( 'Estandar_Leads_QA_Policy' ) && ! empty( Estandar_Leads_QA_Policy::from_payload( is_array( $payload ) ? $payload : array() )['is_test_lead'] ) ) {
			return;
		}
		Estandar_Signal_Core_Repository::record_from_lead(
			absint( $lead_id ),
			'lead_created',
			array(
				'source' => 'lead_ingested',
				'submission_id' => absint( $submission_id ),
			)
		);
	}

	public static function on_status_changed( $lead_id, $from_status, $to_status, $changed_at ): void {
		self::observe_routing();
		if ( self::legacy_superseded() ) {
			return; // deprecated fallback — new AdvertisingEvent path owns this fact
		}
		if ( class_exists( 'Estandar_Leads_QA_Policy' ) && Estandar_Leads_QA_Policy::is_test_lead( absint( $lead_id ) ) ) {
			return;
		}
		$event = self::event_for_status( (string) $to_status );
		if ( $event === '' ) {
			return;
		}
		Estandar_Signal_Core_Repository::record_from_lead(
			absint( $lead_id ),
			$event,
			array(
				'source' => 'status_changed',
				'from_status' => (string) $from_status,
				'to_status' => (string) $to_status,
				'status' => (string) $to_status,
				'event_time' => (string) $changed_at,
			)
		);
	}

	/** @deprecated Phase 1 — commercial whitelist relocated to Estandar_Leads_Advertising_Event::event_for_status(). Kept for the legacy fallback only. */
	public static function event_for_status( string $status ): string {
		$status = strtoupper( sanitize_key( $status ) );
		if ( $status === 'CONTACTED' ) {
			return 'lead_contacted';
		}
		if ( $status === 'QUALIFIED' ) {
			return 'lead_qualified';
		}
		if ( in_array( $status, array( 'VISIT_SCHEDULED', 'VISITED', 'MEETING', 'MEETING_BOOKED' ), true ) ) {
			return 'meeting_booked';
		}
		if ( in_array( $status, array( 'RESERVED', 'DEED_SIGNED', 'DELIVERED', 'CONVERTED', 'SALE' ), true ) ) {
			return 'sale_closed';
		}
		if ( in_array( $status, array( 'LOST', 'UNREACHABLE', 'INVALID' ), true ) ) {
			return 'lead_rejected';
		}
		return '';
	}
}
