<?php
/**
 * LEAD_ASSESSMENT_V1 — Optional AI assessment (MS-A06).
 *
 * Extension point for installations that want LLM-assisted coherence analysis
 * of free text (ad→search→landing→form→message). Guarantees:
 *
 *   - OPT-IN:   disabled by default (`estandar_leads_assessment_ai_settings`).
 *   - ASYNC:    never runs on the capture request — scheduled as a one-shot
 *               cron event after persistence.
 *   - DRY-RUN:  default ON — the AI result is computed and AUDITED but NOT
 *               applied to the lead until the installation turns it off.
 *   - NEVER AUTHORITATIVE: the AI result can only (a) add ai_assessment
 *               evidence, (b) lower confidence, (c) flag review, (d) propose a
 *               fit ONLY when no deterministic rule decided (source=ai,
 *               review_required=true). It can NEVER change a fit/reason
 *               already decided by rules or by the operator.
 *
 * Provider contract (per installation, via
 * `estandar_leads_assessment_ai_provider` filter):
 *   callable(array $evidence): ?array
 *     → null when the provider cannot assess
 *     → array { fit?:string, fit_reason?:string, confidence?:int,
 *               missing_evidence?:string[], review?:bool, note?:string }
 * The provider owns the actual LLM call (credentials, HTTP, timeouts). The
 * plugin ships NO default LLM client — none is verified in this codebase.
 *
 * No new tables: the queue is a one-shot cron event per lead; results live in
 * es_leads.fit_evidence_json + es_lead_events (fit_ai_assessed).
 *
 * @package EstandarLeads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Assessment_AI {

	const OPTION = 'estandar_leads_assessment_ai_settings';
	const CRON   = 'estandar_leads_assessment_ai_cron';

	public static function defaults(): array {
		return array(
			'enabled'            => 0,
			'provider'           => '',
			'dry_run'            => 1,
			'propose_when_absent' => 0,
		);
	}

	public static function settings(): array {
		$saved = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge( self::defaults(), $saved );
	}

	public static function is_enabled(): bool {
		$s = self::settings();
		return ! empty( $s['enabled'] ) && $s['provider'] !== '';
	}

	/** Sanitize + persist settings (additive). */
	public static function save_settings( array $raw ): array {
		$s = self::defaults();
		$s['enabled'] = ! empty( $raw['enabled'] ) ? 1 : 0;
		$s['provider'] = sanitize_key( (string) ( $raw['provider'] ?? '' ) );
		$s['dry_run'] = array_key_exists( 'dry_run', $raw ) ? ( ! empty( $raw['dry_run'] ) ? 1 : 0 ) : 1;
		$s['propose_when_absent'] = ! empty( $raw['propose_when_absent'] ) ? 1 : 0;
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $s, false );
		}
		return $s;
	}

	/**
	 * Provider registry. The settings `provider` field SELECTS the provider:
	 * the registered provider is active only when its id matches (or the field
	 * is empty → whatever is registered). A mismatch means AI is OFF — the
	 * setting must be an honest selector, never a dead label.
	 *
	 * Filter: `estandar_leads_assessment_ai_provider`
	 *   → array { id:string, callable:callable } or null when none.
	 */
	public static function provider(): ?array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return null;
		}
		$provider = apply_filters( 'estandar_leads_assessment_ai_provider', null );
		if ( ! is_array( $provider ) || empty( $provider['callable'] ) || ! is_callable( $provider['callable'] ) ) {
			return null;
		}
		$selected = sanitize_key( (string) ( self::settings()['provider'] ?? '' ) );
		if ( ! self::is_provider_active( $selected, $provider ) ) {
			return null; // settings names a provider that is not registered → inactive.
		}
		return array(
			'id'       => sanitize_key( (string) ( $provider['id'] ?? 'provider' ) ),
			'callable' => $provider['callable'],
		);
	}

	/**
	 * PURE: is the registered provider the one selected in settings?
	 * Empty selection = whatever is registered. Mismatch = inactive.
	 */
	public static function is_provider_active( string $selected, array $provider ): bool {
		$selected = sanitize_key( (string) $selected );
		if ( $selected === '' ) {
			return true; // no explicit selection → the registered provider is used.
		}
		return sanitize_key( (string) ( $provider['id'] ?? '' ) ) === $selected;
	}

	/** Runtime wiring. */
	public static function init(): void {
		add_action( self::CRON, array( __CLASS__, 'run_cron' ), 10, 2 );
	}

	/**
	 * Schedule the async assessment for a freshly persisted lead. No-op unless
	 * AI is enabled and a provider is registered. Absent leads are only
	 * scheduled when proposals are explicitly allowed.
	 *
	 * @param bool $absent true when the deterministic assessment found nothing.
	 */
	public static function maybe_schedule( int $lead_id, int $submission_id = 0, bool $absent = false ): void {
		if ( ! self::is_enabled() || self::provider() === null || $lead_id <= 0 ) {
			return;
		}
		if ( $absent && empty( self::settings()['propose_when_absent'] ) ) {
			return; // AI alone never creates a fit for an absent lead by default.
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON, array( $lead_id, $submission_id ) ) ) {
			wp_schedule_single_event( time() + 60, self::CRON, array( $lead_id, $submission_id ) );
		}
	}

	/** Cron handler: run the provider and merge (dry-run by default). */
	public static function run_cron( int $lead_id, int $submission_id = 0 ): void {
		$lead_id = (int) $lead_id;
		if ( $lead_id <= 0 ) {
			return;
		}
		try {
			if ( ! self::is_enabled() || ! class_exists( 'Estandar_Leads_Assessment_Engine' ) ) {
				return;
			}
			$provider = self::provider();
			if ( $provider === null ) {
				return;
			}
			$current = Estandar_Leads_Assessment_Engine::read_lead_fit( $lead_id );
			$base    = self::current_output( $current );
			$evidence = array(
				'lead_id'      => $lead_id,
				'submission_id' => (int) $submission_id,
				'current_fit'  => (string) ( $current['fit'] ?? '' ),
				'current_reason' => (string) ( $current['fit_reason'] ?? '' ),
				'evidence_used' => (array) ( $base['evidence_used'] ?? array() ),
				'evidence_missing' => (array) ( $base['evidence_missing'] ?? array() ),
				'data_sources' => (array) ( $base['data_sources'] ?? array() ),
				'note'         => 'LEAD_ASSESSMENT_V1 async ai provider=' . $provider['id'],
			);

			$ai_result = call_user_func( $provider['callable'], $evidence );
			if ( ! is_array( $ai_result ) ) {
				return;
			}

			// Sanitize BEFORE merge so no raw provider free text / PII ever
			// reaches fit_evidence_json (only the canonical subset does).
			$ai_result = self::sanitize_result( $ai_result );

			$merged = Estandar_Leads_Assessment_Engine::merge_ai( $base, $ai_result, ! empty( $s['propose_when_absent'] ) );
			$applied = empty( $s['dry_run'] ) && ! empty( $merged['ai_assessment'] );

			if ( $applied ) {
				Estandar_Leads_Assessment_Engine::persist( $lead_id, (int) $submission_id, $merged, false ); // fit_ai_assessed is the event.
			}

			if ( class_exists( 'Estandar_Leads_Repository' ) ) {
				Estandar_Leads_Repository::insert_event( $lead_id, Estandar_Leads_Fit::EVENT_AI_ASSESSED, array(
					'submission_id' => (int) $submission_id,
					'reason'        => $applied ? 'ai_applied' : 'ai_dry_run',
					'metadata'      => array(
						'provider'  => $provider['id'],
						'dry_run'   => ! $applied,
						'ai_result' => self::sanitize_result( $ai_result ),
						'merged'    => Estandar_Leads_Assessment_Engine::evidence_payload( $merged ),
					),
				) );
			}
		} catch ( Throwable $e ) {
			error_log( '[estandar-leads][assessment-ai] ' . $e->getMessage() );
		}
	}

	/** Rebuild an AssessmentOutput-shaped array from the persisted read. */
	public static function current_output( array $current ): array {
		$evidence = is_array( $current['evidence'] ?? null ) ? $current['evidence'] : array();
		return array(
			'fit'               => (string) ( $current['fit'] ?? '' ),
			'fit_reason'        => (string) ( $current['fit_reason'] ?? '' ),
			'confidence'        => $current['confidence'] ?? null,
			'decision_source'   => (string) ( $current['decision_source'] ?? '' ),
			'integrity'         => (string) ( $evidence['integrity'] ?? Estandar_Leads_Fit::INTEGRITY_OK ),
			'activated_rules'   => (array) ( $evidence['activated_rules'] ?? array() ),
			'rule_sources'      => (array) ( $evidence['rule_sources'] ?? array() ),
			'evidence_used'     => (array) ( $evidence['evidence_used'] ?? array() ),
			'evidence_missing'  => (array) ( $evidence['evidence_missing'] ?? array() ),
			'data_sources'      => (array) ( $evidence['data_sources'] ?? array() ),
			'ai_assessment'     => $evidence['ai_assessment'] ?? null,
			'review_required'   => ! empty( $current['review_required'] ),
			'policy_reason'     => (string) ( $evidence['policy_reason'] ?? 'default_policy' ),
			'absent'            => (string) ( $current['fit'] ?? '' ) === '',
		);
	}

	/** Strip free text down to a safe audit payload (no raw message/PII). */
	public static function sanitize_result( array $result ): array {
		$out = array();
		if ( isset( $result['fit'] ) ) {
			$out['fit'] = Estandar_Leads_Fit::sanitize_state( $result['fit'] );
		}
		if ( isset( $result['fit_reason'] ) ) {
			$out['fit_reason'] = Estandar_Leads_Fit::sanitize_reason( $result['fit_reason'] );
		}
		if ( isset( $result['confidence'] ) ) {
			$out['confidence'] = Estandar_Leads_Fit::sanitize_confidence( $result['confidence'] );
		}
		if ( isset( $result['missing_evidence'] ) && is_array( $result['missing_evidence'] ) ) {
			$out['missing_evidence'] = array_values( array_unique( array_map( 'strval', $result['missing_evidence'] ) ) );
		}
		if ( isset( $result['review'] ) ) {
			$out['review'] = (bool) $result['review'];
		}
		if ( isset( $result['note'] ) ) {
			$out['note'] = mb_substr( (string) $result['note'], 0, 200 );
		}
		return $out;
	}
}
