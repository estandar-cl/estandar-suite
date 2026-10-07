<?php
/**
 * LEAD_ASSESSMENT_V1 — Learning loop: prediction vs operator vs outcome.
 *
 * Compares, per lead:
 *   1. automatic prediction  (persisted fit / fit_reason / confidence)
 *   2. operator evaluation   (is_valid_lead / invalid_reason / quality / status)
 *   3. real commercial outcome (CLIENT, DEED_SIGNED, DELIVERED, revenue)
 *
 * The operator is NOT assumed correct: the commercial outcome is the ground
 * truth when it exists (a lead that became CLIENT was, by definition, a fit —
 * even if the operator confirmed a wrong-fit prediction).
 *
 * Metrics are aggregated per level WITHOUT PII:
 *   - global reusable   → canonical defaults + filterable semantics (no store)
 *   - installation      → wp_option `estandar_leads_assessment_accuracy_install`
 *   - project           → wp_option `estandar_leads_assessment_accuracy_project`
 *
 * No new tables: audit comparisons in es_lead_events (`fit_accuracy_reviewed`);
 * aggregates in wp_options. Recording is event-driven (status change to a
 * terminal commercial state) + a bounded daily cron sweep as recovery.
 *
 * classify() is PURE (unit-testable). record()/sweep() are DB-bound.
 *
 * @package EstandarLeads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Assessment_Accuracy {

	const OPTION_INSTALL  = 'estandar_leads_assessment_accuracy_install';
	const OPTION_PROJECT  = 'estandar_leads_assessment_accuracy_project';
	const CRON            = 'estandar_leads_assessment_accuracy_daily';

	/** Terminal/commercial statuses that resolve a fit prediction. */
	const RESOLVED_STATUSES = array(
		'CLIENT', 'CLIENT_SIGNED', 'DEED_SIGNED', 'DELIVERED', 'CONVERTED', 'SALE', 'INVALID', 'LOST',
	);

	/** Statuses that prove the lead WAS a fit (outcome beats everything). */
	const POSITIVE_OUTCOME_STATUSES = array(
		'CLIENT', 'CLIENT_SIGNED', 'DEED_SIGNED', 'DELIVERED', 'CONVERTED', 'SALE',
	);

	/** Fit classes tracked for precision/recall (decided axis). */
	const TRACKED_CLASSES = array( 'MATCH', 'FUERA_DE_PERFIL' );

	public static function init(): void {
		add_action( 'estandar_lead_status_changed', array( __CLASS__, 'on_status_changed' ), 30, 3 );
		add_action( self::CRON, array( __CLASS__, 'sweep' ) );
		// Schedule the daily recovery sweep once (guarded, idempotent). Without
		// this the sweep hook was registered but never scheduled (dead path).
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/** Public read API for the persisted install-level accuracy aggregates. */
	public static function metrics_install(): array {
		$agg = function_exists( 'get_option' ) ? get_option( self::OPTION_INSTALL, array() ) : array();
		return self::metrics( is_array( $agg ) ? $agg : array() );
	}

	/** Public read API for the persisted per-project accuracy aggregates. */
	public static function metrics_project( string $slug ): array {
		$slug = sanitize_key( $slug );
		if ( $slug === '' ) {
			return self::metrics( array() );
		}
		$all = function_exists( 'get_option' ) ? get_option( self::OPTION_PROJECT, array() ) : array();
		$all = is_array( $all ) ? $all : array();
		return self::metrics( isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : array() );
	}

	/**
	 * Record a comparison when a lead reaches a resolved commercial state.
	 */
	public static function on_status_changed( $lead_id, $old_status = '', $new_status = '', $changed_at = '' ): void {
		$lead_id = (int) $lead_id;
		if ( $lead_id <= 0 ) {
			return;
		}
		$new_status = strtoupper( trim( (string) $new_status ) );
		if ( ! in_array( $new_status, self::RESOLVED_STATUSES, true ) ) {
			return;
		}
		try {
			self::record( $lead_id );
		} catch ( Throwable $e ) {
			error_log( '[estandar-leads][assessment-accuracy] ' . $e->getMessage() );
		}
	}

	/**
	 * Record the comparison for one lead and update per-level aggregates.
	 * Idempotent per (lead,status): a comparison already reviewed for the
	 * same status is skipped.
	 */
	public static function record( int $lead_id ): void {
		global $wpdb;
		$lead_id = (int) $lead_id;
		if ( $lead_id <= 0 || ! isset( $wpdb ) || ! class_exists( 'Estandar_Leads_Fit' ) ) {
			return;
		}

		$lead = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status, commercial_fit, fit_reason, project_slug
			 FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
			$lead_id
		), ARRAY_A );
		if ( ! is_array( $lead ) || (string) ( $lead['commercial_fit'] ?? '' ) === '' ) {
			return; // no prediction → nothing to learn from.
		}

		// Idempotency: already reviewed for this exact status?
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}es_lead_events
			 WHERE lead_id = %d AND event_type = %s
			   AND JSON_EXTRACT(metadata, '$.status') = %s
			 LIMIT 1",
			$lead_id,
			Estandar_Leads_Fit::EVENT_ACCURACY,
			sanitize_key( (string) $lead['status'] )
		) );
		if ( $existing ) {
			return;
		}

		// Operator verdict (latest classification) + optional revenue signal.
		$classification = $wpdb->get_row( $wpdb->prepare(
			"SELECT is_valid_lead, invalid_reason, quality, status, sales_stage
			 FROM {$wpdb->prefix}es_lead_classifications
			 WHERE lead_id = %d ORDER BY classified_at DESC, id DESC LIMIT 1",
			$lead_id
		), ARRAY_A );
		$has_revenue = self::lead_has_revenue( $lead_id );

		$result = self::classify( array(
			'fit'            => (string) $lead['commercial_fit'],
			'fit_reason'     => (string) $lead['fit_reason'],
			'status'         => (string) $lead['status'],
			'is_valid_lead'  => $classification ? $classification['is_valid_lead'] : null,
			'invalid_reason' => $classification ? (string) $classification['invalid_reason'] : '',
			'quality'        => $classification ? (string) $classification['quality'] : '',
			'has_revenue'    => $has_revenue,
		) );

		if ( ( $result['verdict'] ?? 'pending' ) !== 'resolved' ) {
			return; // no ground truth yet → nothing to aggregate.
		}

		self::update_aggregates( 'install', $result, $lead );
		$project = (string) ( $lead['project_slug'] ?? '' );
		if ( $project !== '' ) {
			self::update_aggregates( 'project_' . $project, $result, $lead );
		}

		if ( class_exists( 'Estandar_Leads_Repository' ) ) {
			Estandar_Leads_Repository::insert_event( $lead_id, Estandar_Leads_Fit::EVENT_ACCURACY, array(
				'submission_id' => 0,
				'reason'        => $result['prediction_correct'] ? 'prediction_correct' : 'prediction_wrong',
				'metadata'      => array(
					'status'            => (string) $lead['status'],
					'prediction'        => (string) $lead['commercial_fit'],
					'prediction_reason' => (string) $lead['fit_reason'],
					'ground_truth'      => $result['ground_truth'],
					'prediction_correct' => (bool) $result['prediction_correct'],
					'basis'             => $result['basis'],
					'operator_verdict'  => $result['operator_verdict'],
				),
			) );
		}
	}

	/**
	 * PURE classification. Resolves the ground truth with precedence
	 * outcome > operator > prediction. Returns a verdict row.
	 *
	 * @param array $row { fit, fit_reason, status, is_valid_lead, invalid_reason, quality, has_revenue }
	 */
	public static function classify( array $row ): array {
		$fit        = strtoupper( trim( (string) ( $row['fit'] ?? '' ) ) );
		$status     = strtoupper( trim( (string) ( $row['status'] ?? '' ) ) );
		$is_valid   = isset( $row['is_valid_lead'] ) ? (int) $row['is_valid_lead'] : null;
		$invalid    = strtoupper( trim( (string) ( $row['invalid_reason'] ?? '' ) ) );
		$has_revenue = ! empty( $row['has_revenue'] );

		$is_client = in_array( $status, self::POSITIVE_OUTCOME_STATUSES, true ) || $has_revenue;
		$operator_negative = ( $is_valid === 0 ) || $status === 'INVALID';

		// Ground truth precedence: outcome > operator > pending.
		$ground_truth = '';
		$basis        = 'pending';
		if ( $is_client ) {
			$ground_truth = Estandar_Leads_Fit::FIT_MATCH;
			$basis        = 'outcome';
		} elseif ( $operator_negative && in_array( $invalid, array( 'NOT_TARGET', 'OTHER_SERVICE', 'OUTSIDE_AREA', 'NO_BUDGET', 'OTHER' ), true ) ) {
			$ground_truth = Estandar_Leads_Fit::FIT_FUERA_DE_PERFIL;
			$basis        = 'operator';
		} elseif ( $operator_negative ) {
			$ground_truth = Estandar_Leads_Fit::FIT_FUERA_DE_PERFIL;
			$basis        = 'operator';
		} elseif ( $status === 'LOST' ) {
			$ground_truth = ''; // no evidence: a lost lead is not necessarily wrong-fit.
			$basis        = 'pending';
		}

		if ( $ground_truth === '' ) {
			return array(
				'verdict' => 'pending',
				'ground_truth' => '',
				'basis' => $basis,
				'prediction_correct' => null,
				'operator_verdict' => $operator_negative ? 'negative' : ( $is_valid === 1 ? 'positive' : 'none' ),
			);
		}

		$prediction_correct = $fit === $ground_truth;
		return array(
			'verdict'            => 'resolved',
			'ground_truth'       => $ground_truth,
			'basis'              => $basis,
			'prediction_correct' => $prediction_correct,
			'prediction'         => $fit,
			'operator_verdict'   => $operator_negative ? 'negative' : ( $is_valid === 1 ? 'positive' : 'none' ),
		);
	}

	/**
	 * PURE counter update for the decided binary axis {MATCH, FUERA_DE_PERFIL}.
	 * Cautious predictions (MATCH_PARCIAL/DUDOSO) count as undecided and are
	 * excluded from precision/recall but tallied.
	 */
	public static function apply_counts( array $aggregates, array $result ): array {
		$agg = is_array( $aggregates ) ? $aggregates : array();
		$agg['total'] = (int) ( $agg['total'] ?? 0 ) + 1;
		$agg['correct'] = (int) ( $agg['correct'] ?? 0 ) + ( $result['prediction_correct'] ? 1 : 0 );

		$pred = strtoupper( trim( (string) ( $result['prediction'] ?? '' ) ) );
		$gt   = strtoupper( trim( (string) ( $result['ground_truth'] ?? '' ) ) );

		$agg['by_class'] = isset( $agg['by_class'] ) && is_array( $agg['by_class'] ) ? $agg['by_class'] : array();
		if ( in_array( $pred, self::TRACKED_CLASSES, true ) && in_array( $gt, self::TRACKED_CLASSES, true ) ) {
			$agg['by_class'][ $pred ] = isset( $agg['by_class'][ $pred ] ) ? $agg['by_class'][ $pred ] : array( 'tp' => 0, 'fp' => 0, 'fn' => 0 );
			if ( $pred === $gt ) {
				$agg['by_class'][ $pred ]['tp']++;
			} else {
				$agg['by_class'][ $pred ]['fp']++;
				$agg['by_class'][ $gt ] = isset( $agg['by_class'][ $gt ] ) ? $agg['by_class'][ $gt ] : array( 'tp' => 0, 'fp' => 0, 'fn' => 0 );
				$agg['by_class'][ $gt ]['fn']++;
			}
		} else {
			$agg['undecided'] = (int) ( $agg['undecided'] ?? 0 ) + 1;
		}
		return $agg;
	}

	/** Pure metrics from aggregates. */
	public static function metrics( array $aggregates ): array {
		$agg = is_array( $aggregates ) ? $aggregates : array();
		$out = array(
			'total'     => (int) ( $agg['total'] ?? 0 ),
			'correct'   => (int) ( $agg['correct'] ?? 0 ),
			'undecided' => (int) ( $agg['undecided'] ?? 0 ),
			'accuracy'  => null,
			'by_class'  => array(),
		);
		if ( $out['total'] > 0 ) {
			$out['accuracy'] = round( ( $out['correct'] / $out['total'] ) * 100, 1 );
		}
		foreach ( (array) ( $agg['by_class'] ?? array() ) as $class => $c ) {
			$tp = (int) ( $c['tp'] ?? 0 );
			$fp = (int) ( $c['fp'] ?? 0 );
			$fn = (int) ( $c['fn'] ?? 0 );
			$precision = ( $tp + $fp ) > 0 ? round( ( $tp / ( $tp + $fp ) ) * 100, 1 ) : null;
			$recall    = ( $tp + $fn ) > 0 ? round( ( $tp / ( $tp + $fn ) ) * 100, 1 ) : null;
			$f1        = ( $precision !== null && $recall !== null && ( $precision + $recall ) > 0 )
				? round( ( 2 * $precision * $recall ) / ( $precision + $recall ), 1 ) : null;
			$out['by_class'][ $class ] = array(
				'tp' => $tp, 'fp' => $fp, 'fn' => $fn,
				'precision' => $precision, 'recall' => $recall, 'f1' => $f1,
			);
		}
		return $out;
	}

	// ── Storage (wp_options, no new tables) ────────────────────────────────

	private static function update_aggregates( string $level, array $result, array $lead ): void {
		$option = self::OPTION_INSTALL;
		if ( strpos( $level, 'project_' ) === 0 ) {
			$option = self::OPTION_PROJECT;
			$key    = substr( $level, 8 );
			$all    = function_exists( 'get_option' ) ? get_option( self::OPTION_PROJECT, array() ) : array();
			$all    = is_array( $all ) ? $all : array();
			$all[ $key ] = self::apply_counts( isset( $all[ $key ] ) ? $all[ $key ] : array(), $result );
			if ( function_exists( 'update_option' ) ) {
				update_option( self::OPTION_PROJECT, $all, false );
			}
			return;
		}
		$cur = function_exists( 'get_option' ) ? get_option( self::OPTION_INSTALL, array() ) : array();
		$cur = self::apply_counts( is_array( $cur ) ? $cur : array(), $result );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_INSTALL, $cur, false );
		}
	}

	private static function lead_has_revenue( int $lead_id ): bool {
		if ( ! class_exists( 'Estandar_Commercial_Outcomes' ) || ! isset( $GLOBALS['wpdb'] ) ) {
			return false;
		}
		global $wpdb;
		$amount = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(COALESCE(amount, 0)) FROM {$wpdb->prefix}es_commercial_outcomes_log WHERE lead_id = %d",
			$lead_id
		) );
		return is_numeric( $amount ) && (float) $amount > 0;
	}

	/** Bounded daily recovery sweep for resolved leads not yet reviewed. */
	public static function sweep(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$ids = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}es_leads
			 WHERE commercial_fit <> ''
			   AND status IN ('CLIENT','CLIENT_SIGNED','DEED_SIGNED','DELIVERED','CONVERTED','SALE','INVALID','LOST')
			   AND id NOT IN (
			     SELECT DISTINCT lead_id FROM {$wpdb->prefix}es_lead_events
			     WHERE event_type = 'fit_accuracy_reviewed'
			   )
			 ORDER BY id ASC LIMIT 300"
		);
		foreach ( (array) $ids as $id ) {
			try {
				self::record( (int) $id );
			} catch ( Throwable $e ) {
				error_log( '[estandar-leads][assessment-accuracy] ' . $e->getMessage() );
			}
		}
	}
}
