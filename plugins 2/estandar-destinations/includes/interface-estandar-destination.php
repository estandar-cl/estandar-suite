<?php
/**
 * Estandar_Destination — contract for a single external destination adapter.
 *
 * Each destination is fully self-owned: it implements its own validation,
 * payload building, queue, worker, retry, telemetry and idempotency internally.
 * The engine only registers destinations and dispatches business events to them.
 *
 * This is the minimum foundation contract. No concrete destination is provided.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Estandar_Destination {

	/** Unique, stable destination id (slug). */
	public function id(): string;

	/** Human-readable label. */
	public function label(): string;

	/** True when the destination has enough configuration to receive events. */
	public function is_configured(): bool;

	/**
	 * Destination-owned validation / opt-in. Return true only if this destination
	 * should handle the given business event + payload.
	 */
	public function should_handle( string $event, array $payload ): bool;

	/**
	 * Hand the business event to the destination. The destination owns everything
	 * downstream: validation, payload, queue, worker, retry, telemetry, idempotency.
	 */
	public function enqueue( string $event, array $payload ): void;
}
