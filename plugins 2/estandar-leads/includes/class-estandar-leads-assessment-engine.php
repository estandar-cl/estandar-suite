<?php
/**
 * LEAD_ASSESSMENT_V1 — Deterministic commercial-fit assessment engine.
 *
 * Runs as a thin, pure orchestrator that REUSES the existing qualification
 * machinery — no duplicate engine, no duplicate rules loader:
 *
 *   - Input normalization  → Estandar_Commercial_Qualification_Engine::normalize_input()
 *   - Rules                → Estandar_Commercial_Qualification_Rules::load($project)
 *                             (same config option + filter, same data-tier
 *                             precedence, first-writer-wins)
 *   - Fit action keys      → commercial_fit · fit_reason · fit_confidence ·
 *                            fit_review_required · integrity (pass through
 *                            normalize_action untouched)
 *
 * Orthogonal axes (ADR-004 / design A0.5):
 *   - integrity short-circuits BEFORE fit: TEST (QA), SPAM (spam payload /
 *     rule), FRAUDE (rule/AI only). integrity != OK ⇒ fit is NOT assessed
 *     (absent). SPAM/FRAUDE are NOT fit states.
 *   - temperature / quality / status / sales_stage are NEVER written here.
 *
 * Absence = default: no matched rule and no integrity signal ⇒ absent=true,
 * fit='' → byte-identical behavior for existing installations.
 *
 * evaluate() is a PURE function (no WP/DB) so it is unit-testable standalone.
 * assess() only guards class_exists and never persists (persistence belongs to
 * MS-A03 / Assessment stage).
 *
 * Explainability contract (AssessmentOutput):
 *   fit, fit_reason, confidence, decision_source, activated_rules,
 *   evidence_used, evidence_missing, data_sources, review_required,
 *   ai_assessment (null here; MS-A06), policy_reason, absent.
 *
 * @package EstandarLeads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Assessment_Engine {

	const ENGINE_VERSION = 'LEAD_ASSESSMENT_V1';

	/** Fit sub-keys form a unit: only the rule that wins `commercial_fit`
	 *  contributes them. A losing rule's reason must never contaminate the
	 *  winning fit. */
	const FIT_SUB_KEYS = array( 'fit_reason', 'fit_confidence', 'fit_review_required' );

	// ── Public API ─────────────────────────────────────────────────────────

	/**
	 * Orchestrator: payload → normalized input → rules → AssessmentOutput.
	 * Never persists. Best-effort (throws are caught and degrade to absent).
	 *
	 * @param array $payload capture payload (same shape the qualification engine consumes).
	 */
	public static function assess( array $payload ): array {
		try {
			$integrity = self::resolve_integrity( $payload );
			if ( $integrity !== Estandar_Leads_Fit::INTEGRITY_OK ) {
				return self::absent( $integrity, array( 'policy_reason' => 'integrity:' . strtolower( $integrity ) ) );
			}
			$input   = self::normalize_input( $payload );
			$project = (string) ( $input['project'] ?? '' );
			$rules   = self::load_rules( $project );
			return self::evaluate( $input, $rules );
		} catch ( Throwable $e ) {
			return self::absent( Estandar_Leads_Fit::INTEGRITY_OK, array( 'policy_reason' => 'assessment_error:' . $e->getMessage() ) );
		}
	}

	/**
	 * Pure deterministic evaluation. Same input + same rules ⇒ same output.
	 *
	 * @param array $input normalized input map (see normalize_input()).
	 * @param array $rules normalized rules (see Estandar_Commercial_Qualification_Rules).
	 */
	public static function evaluate( array $input, array $rules ): array {
		$matched  = array();
		$action   = array();
		$used     = array();
		$missing  = array();

		foreach ( $rules as $rule ) {
			if ( ! Estandar_Commercial_Qualification_Rules::matches( $rule, $input ) ) {
				continue;
			}
			$matched[ (string) $rule['id'] ] = (string) $rule['source'];

			foreach ( (array) ( $rule['conditions'] ?? array() ) as $condition ) {
				$field = (string) ( $condition['field'] ?? '' );
				if ( $field === '' ) {
					continue;
				}
				$used[ $field ] = true;
				if ( ! array_key_exists( $field, $input ) || trim( (string) $input[ $field ] ) === '' ) {
					$missing[ $field ] = true;
				}
			}

			// First-writer-wins: a higher-precedence rule's value is final.
			// Fit sub-keys are a UNIT: they only merge from the rule that wins
			// `commercial_fit`, so a losing rule can never attach its reason to
			// a different rule's fit.
			$rule_action = (array) ( $rule['action'] ?? array() );
			$provides_fit = array_key_exists( 'commercial_fit', $rule_action );
			$fit_decided  = array_key_exists( 'commercial_fit', $action );
			foreach ( $rule_action as $key => $value ) {
				if ( $key === 'stop' ) {
					continue;
				}
				if ( in_array( $key, self::FIT_SUB_KEYS, true ) && ( ! $provides_fit || $fit_decided ) ) {
					continue;
				}
				if ( ! array_key_exists( $key, $action ) ) {
					$action[ $key ] = $value;
				}
			}
			if ( ! empty( $rule['action']['stop'] ) ) {
				break;
			}
		}

		return self::output_from_action( $action, $matched, $used, $missing, $input );
	}

	/** The output when nothing matched and integrity is OK — current behavior. */
	public static function default_output(): array {
		return self::absent( Estandar_Leads_Fit::INTEGRITY_OK, array() );
	}

	/**
	 * Merge an AI result into an AssessmentOutput. The AI is NEVER
	 * authoritative:
	 *   - rules decided → AI only adds ai_assessment evidence, may LOWER
	 *     confidence and add missing-evidence hints / review flag; never
	 *     changes fit/fit_reason.
	 *   - rules decided nothing (absent) AND $allow_propose is true AND the
	 *     result carries a valid fit → AI may PROPOSE a fit with
	 *     decision_source=ai, review_required=true. Without $allow_propose
	 *     the absence is preserved (AI alone never creates a fit).
	 *
	 * Pure function (no WP/DB).
	 *
	 * @param bool $allow_propose gate the absent→propose path (settings.propose_when_absent).
	 */
	public static function merge_ai( array $output, array $ai_result, bool $allow_propose = false ): array {
		if ( ! is_array( $ai_result ) || empty( $ai_result ) ) {
			return $output;
		}

		if ( empty( $output['absent'] ) ) {
			// Rules decided: corroborate only.
			$merged = $output;
			$merged['ai_assessment'] = $ai_result;
			if ( isset( $ai_result['confidence'] ) && is_numeric( $ai_result['confidence'] ) ) {
				$ai_conf = Estandar_Leads_Fit::sanitize_confidence( (int) $ai_result['confidence'] );
				if ( $ai_conf !== null && $ai_conf < (int) $merged['confidence'] ) {
					$merged['confidence'] = $ai_conf;
				}
			}
			if ( ! empty( $ai_result['missing_evidence'] ) && is_array( $ai_result['missing_evidence'] ) ) {
				$merged['evidence_missing'] = array_values( array_unique( array_merge(
					(array) $merged['evidence_missing'],
					array_map( 'strval', $ai_result['missing_evidence'] )
				) ) );
			}
			if ( ! empty( $ai_result['review'] ) ) {
				$merged['review_required'] = true;
			}
			$merged['decision_source'] = Estandar_Leads_Fit::SOURCE_HYBRID;
			return $merged;
		}

		// Absent (no rule decided): AI may propose — only when explicitly
		// allowed AND a valid fit is given.
		if ( ! $allow_propose ) {
			return $output;
		}
		$fit = Estandar_Leads_Fit::sanitize_state( $ai_result['fit'] ?? '' );
		if ( $fit === Estandar_Leads_Fit::FIT_NOT_ASSESSED ) {
			return $output; // nothing to apply.
		}
		$reason = Estandar_Leads_Fit::sanitize_reason( $ai_result['fit_reason'] ?? '' );
		if ( $reason === '' ) {
			$reason = Estandar_Leads_Fit::default_reason_for_state( $fit );
		}
		$merged = $output;
		$merged['absent'] = false;
		$merged['fit'] = $fit;
		$merged['fit_reason'] = $reason;
		$merged['confidence'] = Estandar_Leads_Fit::sanitize_confidence( $ai_result['confidence'] ?? null )
			?? Estandar_Leads_Fit::DEFAULT_CONFIDENCE;
		$merged['decision_source'] = Estandar_Leads_Fit::SOURCE_AI;
		$merged['review_required'] = true; // AI-proposed → always operator review.
		$merged['policy_reason'] = 'ai_proposed';
		$merged['ai_assessment'] = $ai_result;
		return $merged;
	}

	// ── Runtime wiring (persistence stage, MS-A03) ─────────────────────────

	/**
	 * Hook the assessment on lead ingestion. Runs AFTER the qualification
	 * engine (15) and BEFORE scoring (20) and the advertising emitter (25) so
	 * the fit gate is available when notification/ads feedback resolve.
	 */
	public static function init(): void {
		add_action( 'estandar_lead_ingested', array( __CLASS__, 'on_lead_ingested' ), 17, 4 );
	}

	/**
	 * Best-effort: assessment must never block capture. Absence = default:
	 * no event, no write, byte-identical behavior.
	 */
	public static function on_lead_ingested( $lead_id, $submission_id = 0, $is_new = true, $payload = array() ): void {
		if ( ! $is_new ) {
			return; // assess on first persistence only (capture fact).
		}
		$lead_id = self::lead_id( $lead_id );
		if ( $lead_id <= 0 ) {
			return;
		}
		try {
			$output = self::assess( is_array( $payload ) ? $payload : array() );
			if ( class_exists( 'Estandar_Leads_Assessment_AI' ) ) {
				// Schedule async AI even for absent leads ONLY when the
				// installation allows AI proposals (propose_when_absent).
				Estandar_Leads_Assessment_AI::maybe_schedule( $lead_id, (int) $submission_id, ! empty( $output['absent'] ) );
			}
			if ( ! empty( $output['absent'] ) ) {
				return; // absence = default → nothing persisted, nothing emitted.
			}
			self::persist( $lead_id, (int) $submission_id, $output );
		} catch ( Throwable $e ) {
			error_log( '[estandar-leads][assessment] ' . $e->getMessage() );
		}
	}

	/**
	 * Persist an assessment to es_leads + audit event. Additive columns only;
	 * NEVER touches status, quality, temperature or sales_stage.
	 *
	 * @param bool $emit_event when false, only the columns are written and the
	 *                         caller emits its own dedicated event
	 *                         (fit_manual_override / fit_ai_assessed) — avoids
	 *                         redundant fit_assessed records on those paths.
	 */
	public static function persist( int $lead_id, int $submission_id, array $output, bool $emit_event = true ): void {
		global $wpdb;
		if ( $lead_id <= 0 || ! isset( $GLOBALS['wpdb'] ) || ! empty( $output['absent'] ) ) {
			return;
		}
		$update = array(
			'commercial_fit'      => (string) ( $output['fit'] ?? '' ),
			'fit_reason'          => (string) ( $output['fit_reason'] ?? '' ),
			'fit_confidence'      => isset( $output['confidence'] ) && $output['confidence'] !== null ? (int) $output['confidence'] : null,
			'fit_decision_source' => (string) ( $output['decision_source'] ?? '' ),
			'fit_review_required' => ! empty( $output['review_required'] ) ? 1 : 0,
			'fit_updated_at'      => gmdate( 'Y-m-d H:i:s' ),
			'fit_evidence_json'   => wp_json_encode( self::evidence_payload( $output ) ),
		);
		$wpdb->update(
			$wpdb->prefix . 'es_leads',
			$update,
			array( 'id' => $lead_id ),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( $emit_event && class_exists( 'Estandar_Leads_Repository' ) ) {
			Estandar_Leads_Repository::insert_event( $lead_id, Estandar_Leads_Fit::EVENT_ASSESSED, array(
				'submission_id' => $submission_id,
				'reason'        => (string) ( $output['policy_reason'] ?? 'default_policy' ),
				'metadata'      => array(
					'assessment' => self::evidence_payload( $output ),
				),
			) );
		}
	}

	/**
	 * MANUAL_OVERRIDE — operator decisions are authoritative for the fit axis
	 * (not for temperature/quality/status). Mirrors the qualification engine's
	 * manual-override pattern. Persists an event ALWAYS (manual decisions are
	 * always auditable).
	 *
	 * @param int   $lead_id
	 * @param array $override subset of: fit, fit_reason, confidence,
	 *                        review_required, reason.
	 * @return array new effective AssessmentOutput.
	 */
	public static function apply_manual_override( int $lead_id, array $override ): array {
		$lead_id = self::lead_id( $lead_id );
		$fit     = Estandar_Leads_Fit::sanitize_state( $override['fit'] ?? '' );

		$output = array_merge( self::absent( Estandar_Leads_Fit::INTEGRITY_OK, array() ), array(
			'fit'             => $fit,
			'fit_reason'      => $fit !== '' ? Estandar_Leads_Fit::sanitize_reason( $override['fit_reason'] ?? '' ) : '',
			'confidence'      => $fit !== '' ? Estandar_Leads_Fit::sanitize_confidence( $override['confidence'] ?? null ) : null,
			'decision_source' => Estandar_Leads_Fit::SOURCE_MANUAL,
			'review_required' => array_key_exists( 'review_required', $override )
				? (bool) $override['review_required']
				: ( $fit !== '' && in_array( $fit, Estandar_Leads_Fit::review_states(), true ) ),
			'policy_reason'   => trim( (string) ( $override['reason'] ?? 'manual_override' ) ),
			'absent'          => $fit === '',
		) );
		if ( $output['fit_reason'] === '' && $fit !== '' ) {
			$output['fit_reason'] = Estandar_Leads_Fit::default_reason_for_state( $fit );
		}

		if ( $lead_id > 0 ) {
			self::persist( $lead_id, 0, $output, false ); // fit_manual_override is the event.
			if ( class_exists( 'Estandar_Leads_Repository' ) ) {
				Estandar_Leads_Repository::insert_event( $lead_id, Estandar_Leads_Fit::EVENT_MANUAL_OVERRIDE, array(
					'submission_id' => 0,
					'reason'        => (string) $output['policy_reason'],
					'metadata'      => array(
						'manual' => self::evidence_payload( $output ),
					),
				) );
			}
		}
		return $output;
	}

	/** Read the persisted fit state of a lead ('' when not assessed). */
	public static function read_lead_fit( int $lead_id ): array {
		global $wpdb;
		$lead_id = self::lead_id( $lead_id );
		if ( $lead_id <= 0 || ! isset( $wpdb ) ) {
			return array();
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT commercial_fit, fit_reason, fit_confidence, fit_decision_source,
			        fit_review_required, fit_updated_at, fit_evidence_json
			 FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
			$lead_id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array();
		}
		return array(
			'fit'               => (string) ( $row['commercial_fit'] ?? '' ),
			'fit_reason'        => (string) ( $row['fit_reason'] ?? '' ),
			'confidence'        => isset( $row['fit_confidence'] ) && $row['fit_confidence'] !== null ? (int) $row['fit_confidence'] : null,
			'decision_source'   => (string) ( $row['fit_decision_source'] ?? '' ),
			'review_required'   => ! empty( $row['fit_review_required'] ),
			'updated_at'        => (string) ( $row['fit_updated_at'] ?? '' ),
			'evidence'          => json_decode( (string) ( $row['fit_evidence_json'] ?? '' ), true ),
		);
	}

	/** Pure (no DB): the explainability payload to persist (no PII). */
	public static function evidence_payload( array $output ): array {
		return array(
			'fit'              => (string) ( $output['fit'] ?? '' ),
			'fit_reason'       => (string) ( $output['fit_reason'] ?? '' ),
			'confidence'       => isset( $output['confidence'] ) && $output['confidence'] !== null ? (int) $output['confidence'] : null,
			'decision_source'  => (string) ( $output['decision_source'] ?? '' ),
			'integrity'        => (string) ( $output['integrity'] ?? Estandar_Leads_Fit::INTEGRITY_OK ),
			'activated_rules'  => (array) ( $output['activated_rules'] ?? array() ),
			'rule_sources'     => (array) ( $output['rule_sources'] ?? array() ),
			'evidence_used'    => (array) ( $output['evidence_used'] ?? array() ),
			'evidence_missing' => (array) ( $output['evidence_missing'] ?? array() ),
			'data_sources'     => (array) ( $output['data_sources'] ?? array() ),
			'ai_assessment'    => $output['ai_assessment'] ?? null,
			'review_required'  => ! empty( $output['review_required'] ),
			'policy_reason'    => (string) ( $output['policy_reason'] ?? '' ),
		);
	}

	// ── Output derivation ───────────────────────────────────────────────────

	/**
	 * Derive the immutable AssessmentOutput from the merged rule actions.
	 *
	 * @param array $action merged fit action keys (first-writer-wins).
	 * @param array $matched rule_id => source.
	 * @param array $used    fields referenced by matched rules.
	 * @param array $missing fields referenced but absent/empty in the input.
	 * @param array $input   normalized input (for data provenance).
	 */
	private static function output_from_action( array $action, array $matched, array $used, array $missing, array $input ): array {
		// Integrity action from a rule overrides fit (separate axis).
		$integrity = Estandar_Leads_Fit::sanitize_integrity( $action['integrity'] ?? '' );
		if ( $integrity !== Estandar_Leads_Fit::INTEGRITY_OK ) {
			return self::absent( $integrity, array(
				'policy_reason'  => 'integrity:' . strtolower( $integrity ),
				'activated_rules' => array_keys( $matched ),
			) );
		}

		$fit = Estandar_Leads_Fit::sanitize_state( $action['commercial_fit'] ?? '' );
		if ( $fit === Estandar_Leads_Fit::FIT_NOT_ASSESSED ) {
			return self::absent( Estandar_Leads_Fit::INTEGRITY_OK, array() );
		}

		$reason  = Estandar_Leads_Fit::sanitize_reason( $action['fit_reason'] ?? '' );
		if ( $reason === '' ) {
			$reason = Estandar_Leads_Fit::default_reason_for_state( $fit );
		}

		$confidence_raw = $action['fit_confidence'] ?? ( $action['confidence'] ?? '' );
		$confidence     = $confidence_raw !== '' && $confidence_raw !== null
			? Estandar_Leads_Fit::sanitize_confidence( $confidence_raw )
			: Estandar_Leads_Fit::DEFAULT_CONFIDENCE;

		$review = array_key_exists( 'fit_review_required', $action )
			? (bool) $action['fit_review_required']
			: in_array( $fit, Estandar_Leads_Fit::review_states(), true );

		$reason_text = trim( (string) ( $action['reason'] ?? '' ) );
		if ( $reason_text === '' && ! empty( $matched ) ) {
			$reason_text = 'matched_rules:' . implode( ',', array_keys( $matched ) );
		}
		if ( $reason_text === '' ) {
			$reason_text = 'default_policy';
		}

		// Evidence provenance: namespace prefix → human source. Deterministic.
		$data_sources = array();
		foreach ( array_keys( $used ) as $field ) {
			$data_sources[ $field ] = self::field_source( $field );
		}
		foreach ( array_keys( $missing ) as $field ) {
			$data_sources[ $field ] = 'missing';
		}

		return array(
			'engine_version'    => self::ENGINE_VERSION,
			'fit'               => $fit,
			'fit_reason'        => $reason,
			'confidence'        => $confidence,
			'decision_source'   => empty( $matched ) ? Estandar_Leads_Fit::SOURCE_NONE : Estandar_Leads_Fit::SOURCE_DETERMINISTIC,
			'integrity'         => Estandar_Leads_Fit::INTEGRITY_OK,
			'activated_rules'   => array_values( array_keys( $matched ) ),
			'rule_sources'      => $matched,
			'evidence_used'     => array_values( array_keys( $used ) ),
			'evidence_missing'  => array_values( array_keys( $missing ) ),
			'data_sources'      => $data_sources,
			'ai_assessment'     => null,
			'review_required'   => $review,
			'policy_reason'     => $reason_text,
			'absent'            => false,
		);
	}

	/** The canonical "nothing to see" output (byte-identical behavior). */
	private static function absent( string $integrity, array $overrides ): array {
		return array_merge( array(
			'engine_version'    => self::ENGINE_VERSION,
			'fit'               => Estandar_Leads_Fit::FIT_NOT_ASSESSED,
			'fit_reason'        => '',
			'confidence'        => null,
			'decision_source'   => Estandar_Leads_Fit::SOURCE_NONE,
			'integrity'         => $integrity,
			'activated_rules'   => array(),
			'rule_sources'      => array(),
			'evidence_used'     => array(),
			'evidence_missing'  => array(),
			'data_sources'      => array(),
			'ai_assessment'     => null,
			'review_required'   => false,
			'policy_reason'     => 'absent_default',
			'absent'            => true,
		), $overrides );
	}

	// ── Reused machinery ────────────────────────────────────────────────────

	/** Reuse the qualification engine's normalizer (pure, class_exists guarded). */
	public static function normalize_input( array $payload ): array {
		if ( class_exists( 'Estandar_Commercial_Qualification_Engine' ) ) {
			return Estandar_Commercial_Qualification_Engine::normalize_input( $payload );
		}
		// Fallback minimal flatten (should never be reached in the plugin).
		$map = array();
		foreach ( array( 'contact', 'content', 'context', 'attribution', 'spam', 'scoring', 'request', 'chatbot' ) as $group ) {
			foreach ( (array) ( $payload[ $group ] ?? array() ) as $k => $v ) {
				if ( is_scalar( $v ) || $v === null ) {
					$map[ $group . '.' . $k ] = trim( (string) $v );
				}
			}
		}
		return $map;
	}

	/** Reuse the existing rules loader (same option + filter + precedence). */
	public static function load_rules( string $project = '' ): array {
		if ( class_exists( 'Estandar_Commercial_Qualification_Rules' ) ) {
			return Estandar_Commercial_Qualification_Rules::load( $project );
		}
		return array();
	}

	/**
	 * Integrity short-circuit: QA test lead or spam payload evidence.
	 * FRAUDE can only come from a rule action (no deterministic signal yet).
	 */
	public static function resolve_integrity( array $payload ): string {
		if ( class_exists( 'Estandar_Leads_QA_Policy' ) ) {
			$qa = Estandar_Leads_QA_Policy::from_payload( $payload );
			if ( ! empty( $qa['is_test_lead'] ) ) {
				return Estandar_Leads_Fit::INTEGRITY_TEST;
			}
		}
		$spam = is_array( $payload['spam'] ?? null ) ? $payload['spam'] : array();
		if ( ! empty( $spam['honeypot_filled'] ) || ! empty( $spam['is_spam'] ) ) {
			return Estandar_Leads_Fit::INTEGRITY_SPAM;
		}
		return Estandar_Leads_Fit::INTEGRITY_OK;
	}

	/** Deterministic provenance label for a normalized field. */
	public static function field_source( string $field ): string {
		$field = strtolower( trim( $field ) );
		foreach ( array(
			'fields.'      => 'form',
			'contact.'     => 'contact',
			'attribution.' => 'attribution',
			'context.'     => 'context',
			'chatbot.'     => 'chatbot',
			'scoring.'     => 'scoring',
			'spam.'        => 'spam',
			'request.'     => 'request',
			'content.'     => 'free_text',
		) as $prefix => $source ) {
			if ( strpos( $field, $prefix ) === 0 ) {
				return $source;
			}
		}
		if ( $field === 'message' ) {
			return 'free_text';
		}
		if ( in_array( $field, array( 'service', 'project', 'intent', 'has_email', 'has_phone', 'source_plugin', 'schema_version' ), true ) ) {
			return 'derived';
		}
		return 'configured';
	}

	/** absint with standalone fallback (keeps the class unit-testable). */
	private static function lead_id( $value ): int {
		return function_exists( 'absint' ) ? absint( $value ) : max( 0, (int) $value );
	}
}
