<?php
/**
 * LEAD_ASSESSMENT_V1 — Canonical taxonomy for the Lead Assessment Engine.
 *
 * SSOT (single source of truth) for the commercial-fit dimension and its
 * sub-axes. Pure vocabulary: no business knowledge, no WP/DB except optional
 * apply_filters for per-installation extension.
 *
 * Three ORTHOGONAL axes (never mixed, per ADR-004 / design A0.5):
 *   1. commercial_fit  — pertinencia comercial (MATCH, MATCH_PARCIAL,
 *                        DUDOSO, FUERA_DE_PERFIL). '' = not assessed (absent).
 *   2. fit_reason      — razón granular (OTHER_SERVICE, JOB_SEEKER, SUPPLIER,
 *                        WRONG_INTENT, LOW_BUDGET, OUTSIDE_REGION,
 *                        PERSONAL_USE, STUDENT, UNKNOWN).
 *   3. integrity       — TEST (QA) / SPAM / FRAUDE. SEPARATE axis: SPAM/FRAUDE
 *                        are NOT lack of fit. Evaluated before fit (short-circuit).
 *
 * Untouchable axes that live elsewhere and must NEVER be written here:
 * temperature (BEHAVIORAL_SCORE_V1), quality (HOT/WARM/COLD/JUNK), status,
 * sales_stage.
 *
 * Compatibility with existing vocabulary:
 *   - reason_to_lost_reason() maps canonical reasons to
 *     Estandar_Leads_Normalizer::lost_reason_labels() where a counterpart exists.
 *   - state_to_lost_reason() maps fit states to a best-effort lost reason.
 *   - Integrity SPAM/FRAUDE/TEST map to existing QA/suspect/lost_reason concepts.
 *
 * @package EstandarLeads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Fit {

	const ENGINE_VERSION = 'LEAD_ASSESSMENT_V1';

	// ── commercial_fit states (axis 1) ─────────────────────────────────────
	const FIT_MATCH           = 'MATCH';
	const FIT_MATCH_PARCIAL   = 'MATCH_PARCIAL';
	const FIT_DUDOSO          = 'DUDOSO';
	const FIT_FUERA_DE_PERFIL = 'FUERA_DE_PERFIL';
	const FIT_NOT_ASSESSED    = ''; // absent = default (byte-identical behavior)

	// ── fit_reason (axis 2) ────────────────────────────────────────────────
	const REASON_OTHER_SERVICE = 'OTHER_SERVICE';
	const REASON_JOB_SEEKER    = 'JOB_SEEKER';
	const REASON_SUPPLIER      = 'SUPPLIER';
	const REASON_WRONG_INTENT  = 'WRONG_INTENT';
	const REASON_LOW_BUDGET    = 'LOW_BUDGET';
	const REASON_OUTSIDE_REGION = 'OUTSIDE_REGION';
	const REASON_PERSONAL_USE  = 'PERSONAL_USE';
	const REASON_STUDENT       = 'STUDENT';
	const REASON_UNKNOWN       = 'UNKNOWN';

	// ── integrity axis (axis 3, separate) ──────────────────────────────────
	const INTEGRITY_OK    = 'OK';
	const INTEGRITY_TEST  = 'TEST';
	const INTEGRITY_SPAM  = 'SPAM';
	const INTEGRITY_FRAUD = 'FRAUDE';

	// ── decision source (explainability) ───────────────────────────────────
	const SOURCE_DETERMINISTIC = 'deterministic';
	const SOURCE_AI            = 'ai';
	const SOURCE_MANUAL        = 'manual';
	const SOURCE_HYBRID        = 'hybrid';
	const SOURCE_NONE          = 'none';

	// ── Event vocabulary (audit, es_lead_events reuse) ─────────────────────
	const EVENT_ASSESSED       = 'fit_assessed';
	const EVENT_MANUAL_OVERRIDE = 'fit_manual_override';
	const EVENT_ACCURACY       = 'fit_accuracy_reviewed';
	const EVENT_AI_ASSESSED    = 'fit_ai_assessed';

	/** Default confidence when a decision has no explicit confidence. */
	const DEFAULT_CONFIDENCE = 50;

	/**
	 * Canonical fit states. Filterable per installation
	 * (`estandar_leads_fit_states`) — labels map must extend in parallel.
	 *
	 * @return array<int,string>
	 */
	public static function states(): array {
		$states = array(
			self::FIT_MATCH,
			self::FIT_MATCH_PARCIAL,
			self::FIT_DUDOSO,
			self::FIT_FUERA_DE_PERFIL,
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'estandar_leads_fit_states', $states );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$states = array_values( array_unique( array_map( 'strval', $filtered ) ) );
			}
		}
		return $states;
	}

	/**
	 * Canonical fit reasons. Filterable per installation
	 * (`estandar_leads_fit_reasons`).
	 *
	 * @return array<int,string>
	 */
	public static function reasons(): array {
		$reasons = array(
			self::REASON_OTHER_SERVICE,
			self::REASON_JOB_SEEKER,
			self::REASON_SUPPLIER,
			self::REASON_WRONG_INTENT,
			self::REASON_LOW_BUDGET,
			self::REASON_OUTSIDE_REGION,
			self::REASON_PERSONAL_USE,
			self::REASON_STUDENT,
			self::REASON_UNKNOWN,
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'estandar_leads_fit_reasons', $reasons );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) {
				$reasons = array_values( array_unique( array_map( 'strval', $filtered ) ) );
			}
		}
		return $reasons;
	}

	/** Integrity axis — TEST/SPAM/FRAUDE are NOT fit states. */
	public static function integrity_states(): array {
		return array( self::INTEGRITY_OK, self::INTEGRITY_TEST, self::INTEGRITY_SPAM, self::INTEGRITY_FRAUD );
	}

	/** Explainability decision sources. */
	public static function decision_sources(): array {
		return array(
			self::SOURCE_DETERMINISTIC,
			self::SOURCE_AI,
			self::SOURCE_MANUAL,
			self::SOURCE_HYBRID,
			self::SOURCE_NONE,
		);
	}

	/** Spanish labels for fit states (per-installation override via filter). */
	public static function labels(): array {
		$labels = array(
			self::FIT_MATCH           => 'Match — cliente objetivo',
			self::FIT_MATCH_PARCIAL   => 'Match parcial — requiere revisión',
			self::FIT_DUDOSO          => 'Dudoso — evidencia insuficiente',
			self::FIT_FUERA_DE_PERFIL => 'Fuera de perfil — no es cliente objetivo',
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'estandar_leads_fit_labels', $labels );
			if ( is_array( $filtered ) ) {
				$labels = $filtered;
			}
		}
		return $labels;
	}

	/** Spanish labels for fit reasons. */
	public static function reason_labels(): array {
		$labels = array(
			self::REASON_OTHER_SERVICE => 'Busca otro servicio',
			self::REASON_JOB_SEEKER    => 'Busca trabajo, no contratar',
			self::REASON_SUPPLIER      => 'Es proveedor, no cliente',
			self::REASON_WRONG_INTENT  => 'Intención distinta al servicio',
			self::REASON_LOW_BUDGET    => 'Presupuesto fuera de rango',
			self::REASON_OUTSIDE_REGION => 'Fuera del área de servicio',
			self::REASON_PERSONAL_USE  => 'Uso personal, no empresa objetivo',
			self::REASON_STUDENT       => 'Estudiante / investigación',
			self::REASON_UNKNOWN       => 'No se puede determinar la razón',
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'estandar_leads_fit_reason_labels', $labels );
			if ( is_array( $filtered ) ) {
				$labels = $filtered;
			}
		}
		return $labels;
	}

	/** Spanish labels for integrity axis. */
	public static function integrity_labels(): array {
		return array(
			self::INTEGRITY_OK    => 'Integridad OK',
			self::INTEGRITY_TEST  => 'Lead de prueba (QA)',
			self::INTEGRITY_SPAM  => 'Sospecha de spam',
			self::INTEGRITY_FRAUD => 'Sospecha de fraude',
		);
	}

	public static function state_label( string $state ): string {
		$labels = self::labels();
		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}

	public static function reason_label( string $reason ): string {
		$labels = self::reason_labels();
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
	}

	public static function is_valid_state( string $state ): bool {
		return $state !== '' && in_array( $state, self::states(), true );
	}

	public static function is_valid_reason( string $reason ): bool {
		return in_array( $reason, self::reasons(), true );
	}

	public static function is_valid_source( string $source ): bool {
		return in_array( $source, self::decision_sources(), true );
	}

	/**
	 * States that imply review by the operator.
	 */
	public static function review_states(): array {
		return array( self::FIT_MATCH_PARCIAL, self::FIT_DUDOSO );
	}

	/**
	 * Default reason for a state when none was decided: UNKNOWN for
	 * non-MATCH states, '' for MATCH (no reason needed).
	 */
	public static function default_reason_for_state( string $state ): string {
		if ( $state === self::FIT_MATCH || $state === '' ) {
			return '';
		}
		return self::REASON_UNKNOWN;
	}

	/**
	 * Map a canonical reason to an existing lost-reason key
	 * (Estandar_Leads_Normalizer::lost_reason_labels) where a counterpart
	 * exists, else NOT_TARGET (best-effort) or '' for MATCH.
	 */
	public static function reason_to_lost_reason( string $reason ): string {
		$map = array(
			self::REASON_OTHER_SERVICE  => 'OTHER_SERVICE',
			self::REASON_OUTSIDE_REGION => 'OUTSIDE_AREA',
			self::REASON_LOW_BUDGET     => 'NO_BUDGET',
			self::REASON_WRONG_INTENT   => 'NOT_TARGET',
			self::REASON_JOB_SEEKER     => 'NOT_TARGET',
			self::REASON_SUPPLIER       => 'NOT_TARGET',
			self::REASON_PERSONAL_USE   => 'NOT_TARGET',
			self::REASON_STUDENT        => 'NOT_TARGET',
			self::REASON_UNKNOWN        => 'OTHER',
		);
		return isset( $map[ $reason ] ) ? $map[ $reason ] : 'OTHER';
	}

	/**
	 * Map a fit state to a best-effort lost reason for operator workflows.
	 * MATCH / MATCH_PARCIAL → '' (not lost). Only wrong-fit-like states map.
	 */
	public static function state_to_lost_reason( string $state ): string {
		switch ( $state ) {
			case self::FIT_FUERA_DE_PERFIL:
				return 'NOT_TARGET';
			case self::FIT_DUDOSO:
				return 'OTHER';
			default:
				return '';
		}
	}

	/**
	 * Validate/clean a persisted fit state ('' → not assessed). Unknown
	 * values are rejected (never stored) to keep the enum closed.
	 */
	public static function sanitize_state( $value ): string {
		$value = strtoupper( trim( (string) $value ) );
		return in_array( $value, self::states(), true ) ? $value : self::FIT_NOT_ASSESSED;
	}

	/** Validate/clean a persisted fit reason. */
	public static function sanitize_reason( $value ): string {
		$value = strtoupper( trim( (string) $value ) );
		return in_array( $value, self::reasons(), true ) ? $value : '';
	}

	/** Validate/clean a decision source. */
	public static function sanitize_source( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, self::decision_sources(), true ) ? $value : '';
	}

	/** Validate/clean a confidence value (0-100, null allowed). */
	public static function sanitize_confidence( $value ) {
		if ( $value === null || $value === '' ) {
			return null;
		}
		$value = max( 0, min( 100, (int) $value ) );
		return $value;
	}

	/** Validate/clean an integrity value (unknown → OK). */
	public static function sanitize_integrity( $value ): string {
		$value = strtoupper( trim( (string) $value ) );
		return in_array( $value, self::integrity_states(), true ) ? $value : self::INTEGRITY_OK;
	}

	/**
	 * Fit states that BLOCK ads-feedback export (conversion/negative signal).
	 * SSOT — the enforcement gate (QA_Policy::ads_feedback_blocked) and the
	 * observability resolver mirror this single definition:
	 *   - MATCH_PARCIAL: undetermined intent is not exportable yet (review).
	 *   - DUDOSO:        insufficient/contradictory evidence → no signal.
	 *   - FUERA_DE_PERFIL: real human, wrong fit → never a conversion.
	 * MATCH never blocks. '' (not assessed) never blocks (byte-compatible).
	 */
	public static function blocks_ads_feedback( string $fit ): bool {
		return in_array( $fit, array( self::FIT_MATCH_PARCIAL, self::FIT_DUDOSO, self::FIT_FUERA_DE_PERFIL ), true );
	}

	/**
	 * Commercial statuses that prove a lead is a real (positive) opportunity.
	 * Used to lift a capture-time fit block when the operator positively
	 * classifies the lead (operator confirmation is authoritative).
	 */
	public static function positive_commercial_statuses(): array {
		return array(
			'QUALIFIED', 'MEETING_BOOKED', 'PROPOSAL_SENT', 'NEGOTIATION',
			'CONVERTED', 'CLIENT', 'CLIENT_SIGNED',
			'VISIT_SCHEDULED', 'VISITED', 'RESERVED', 'DEED_SIGNED', 'DELIVERED', 'SALE',
		);
	}

	public static function is_positive_commercial_status( string $status ): bool {
		return in_array( strtoupper( trim( $status ) ), self::positive_commercial_statuses(), true );
	}

	/**
	 * SSOT for the ads-export gate — used by BOTH the enforcement point
	 * (QA_Policy::ads_feedback_blocked) and the observability resolver, so the
	 * panel and the enforced state can never diverge.
	 *
	 * Precedence (blocked = true wins):
	 *   1. qualification policy === 'skip'   → block (explicit disqualification)
	 *   2. fit blocks                        → block UNLESS the operator has
	 *      positively confirmed the lead (manual qualification override, or
	 *      is_valid_lead=1, or a positive commercial status). The DEFAULT
	 *      qualification decision (decision_source='default', policy='allow')
	 *      is NOT confirmation — a capture-time wrong-fit must block until a
	 *      human confirms otherwise.
	 *   3. otherwise                          → allow.
	 *
	 * Pure function (no WP/DB) — unit-testable standalone.
	 *
	 * @param string $fit            persisted commercial_fit state ('' = not assessed).
	 * @param string $status         current es_leads.status.
	 * @param mixed  $is_valid_lead  es_leads.is_valid_lead (0|1|null).
	 * @param string $decision_source qualification decision_source (default|system|manual).
	 * @param string $policy         qualification ads_feedback_policy (allow|skip|'').
	 */
	public static function should_block_ads_export( string $fit, string $status = '', $is_valid_lead = null, string $decision_source = '', string $policy = '' ): bool {
		$policy = strtolower( trim( $policy ) );
		if ( $policy === 'skip' ) {
			return true;
		}

		$fit = strtoupper( trim( $fit ) );
		if ( $fit !== '' && self::blocks_ads_feedback( $fit ) ) {
			// Lift the block ONLY on an explicit positive operator confirmation.
			if ( $decision_source === self::SOURCE_MANUAL ) {
				return false;
			}
			if ( $is_valid_lead === 1 || $is_valid_lead === '1' ) {
				return false;
			}
			if ( self::is_positive_commercial_status( $status ) ) {
				return false;
			}
			return true;
		}
		return false;
	}
}
