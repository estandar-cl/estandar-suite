<?php
/**
 * Estandar_Destinations_Engine — minimum foundation for the Destinations Engine.
 *
 * Responsibilities (only these):
 *   • Registry of destinations (self-registered via the 'estandar_destinations_register' hook).
 *   • Dispatch of business events to registered destinations, with per-destination
 *     failure isolation.
 *
 * It owns NO integration logic. Validation, payload, queue, worker, retry, telemetry
 * and idempotency belong to each destination. No destination ships in this foundation,
 * so dispatch() is inert until one registers. The existing Click OCI is not involved.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Destinations_Engine {

	/** @var array<string,Estandar_Destination> id => destination */
	private static $destinations = array();

	/** @var bool */
	private static $registered = false;

	/**
	 * The canonical hook that feeds the engine.
	 * These are the ONLY entry points for business events.
	 */
	const HOOK_LEAD_INGESTED = 'estandar_lead_ingested';

	/**
	 * Fire the registration hook once. Destinations self-register by adding a
	 * callback to 'estandar_destinations_register' and calling ::register().
	 */
	public static function init(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		do_action( 'estandar_destinations_register' );
	}

	/**
	 * Boot the engine: register the canonical hook listeners.
	 * Called once from the plugin bootstrap.
	 */
	public static function boot(): void {
		self::init();
		// Listen to the canonical point of publication: estandar_lead_ingested.
		// Priority 25 ensures lead is fully ingested before destinations dispatch.
		add_action( self::HOOK_LEAD_INGESTED, array( __CLASS__, 'on_lead_ingested' ), 25, 4 );
	}

	/**
	 * Canonical entry point: receives fully ingested leads.
	 * Converts the internal event to the public contract and dispatches.
	 */
	public static function on_lead_ingested( $lead_id, $submission_id, $is_new_lead, $payload ): void {
		if ( empty( $lead_id ) || empty( $submission_id ) ) {
			return;
		}
		$event_name = $is_new_lead ? 'lead.created' : 'lead.recontacted';
		self::dispatch( $event_name, array(
			'lead_id'       => (int) $lead_id,
			'submission_id' => (int) $submission_id,
			'is_new'        => (bool) $is_new_lead,
			'payload'       => is_array( $payload ) ? $payload : array(),
		) );
	}

	/** Register a destination adapter (idempotent by id). */
	public static function register( Estandar_Destination $destination ): void {
		$id = $destination->id();
		if ( $id === '' ) {
			return;
		}
		self::$destinations[ $id ] = $destination;
	}

	/** @return Estandar_Destination[] */
	public static function all(): array {
		return array_values( self::$destinations );
	}

	/** @return Estandar_Destination|null */
	public static function get( string $id ): ?Estandar_Destination {
		return self::$destinations[ $id ] ?? null;
	}

	/**
	 * Dispatch a business event to every configured, opted-in destination.
	 * Each destination is isolated: a failure in one never affects the caller
	 * or the other destinations.
	 */
	public static function dispatch( string $event, array $payload ): void {
		foreach ( self::$destinations as $destination ) {
			try {
				if ( $destination->is_configured() && $destination->should_handle( $event, $payload ) ) {
					$destination->enqueue( $event, $payload );
				}
			} catch ( Throwable $e ) {
				// Failure isolation: never propagate a destination error to the caller.
				continue;
			}
		}
	}
}
