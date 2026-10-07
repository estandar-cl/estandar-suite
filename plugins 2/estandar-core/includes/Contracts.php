<?php
/**
 * Core contracts — shared interfaces between the 5 products.
 * Each product implements the contracts it produces; consumers depend on the
 * interface, never on another product's internals (no hidden dependencies).
 */

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface TelemetryBus
{
	/** Emit a non-PII telemetry event. */
	public static function emit( string $severity, string $code, string $message, array $context = array() ): void;
}

interface CaptureSink
{
	/** Persist a normalized capture. Returns the new capture id or 0. */
	public static function persist( array $capture ): int;
}

interface SignalLedger
{
	/** Record a canonical commercial signal. Returns signal id or 0. */
	public static function record( array $signal ): int;
}

interface CommercialStageMap
{
	/** Map a canonical stage to a status label (or '' when unknown). */
	public static function status_label( string $stage ): string;
}

interface AdoptionProbeInterface
{
	/** True when legacy original plugins/tables are present. */
	public static function probe_legacy(): bool;
}
