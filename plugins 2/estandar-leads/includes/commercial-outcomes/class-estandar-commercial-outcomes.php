<?php
/**
 * Commercial Outcomes — module bootstrap + provider locator.
 *
 * REUSE, DON'T DUPLICATE. This domain module is a configuration + provider layer
 * over the EXISTING production transports (Meta CAPI dispatcher/queue/retry/Graph
 * client/Payload builder, and OCI). It registers NO dispatcher and NO event
 * subscriber — the Meta Adapter and OCI already subscribe to
 * estandar_lead_status_changed; a second subscriber would double-dispatch.
 *
 * It is the SINGLE RESOLUTION POINT for the domain: one decision, many consumers.
 * Adapters obtain providers from the locator instead of scattering class_exists()
 * checks throughout the dispatch path.
 *
 *   stages()         → Estandar_Commercial_Stages           (configurable stages)
 *   value_provider() → Estandar_Commercial_Value_Provider   (Money resolution)
 *   log()            → Estandar_Commercial_Outcomes_Log      (table-backed history)
 *
 * @package EstandarLeads\CommercialOutcomes
 * @since   10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Commercial_Outcomes {

	/** @var self|null */
	private static $instance = null;

	/** @var Estandar_Commercial_Stages|null */
	private $stages = null;
	/** @var Estandar_Commercial_Value_Provider|null */
	private $value_provider = null;
	/** @var Estandar_Commercial_Outcomes_Log|null */
	private $log = null;

	/** Single resolution point. */
	public static function locator(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function stages(): Estandar_Commercial_Stages {
		if ( $this->stages === null ) {
			$this->stages = new Estandar_Commercial_Stages();
		}
		return $this->stages;
	}

	public function value_provider(): Estandar_Commercial_Value_Provider {
		if ( $this->value_provider === null ) {
			$this->value_provider = new Estandar_Commercial_Outcomes_Value_Provider();
		}
		return $this->value_provider;
	}

	public function log(): Estandar_Commercial_Outcomes_Log {
		if ( $this->log === null ) {
			$this->log = new Estandar_Commercial_Outcomes_Log();
		}
		return $this->log;
	}

	/**
	 * No hooks registered by design — providers are consumed on demand by the
	 * existing dispatchers. Kept as the module's single entry point.
	 */
	public static function init(): void {}

	// ── Convenience pass-throughs ────────────────────────────────────────────────

	public static function stage_codes(): array {
		return self::locator()->stages()->all();
	}

	public static function recent_log( int $limit = 50 ): array {
		return self::locator()->log()->recent( $limit );
	}
}
