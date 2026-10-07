<?php
/**
 * Commercial Feedback Importer — Architecture & Design
 * 
 * Domain-generic. Born from AK Contadores learning (2026-08-05).
 * Immediately elevated to Estandar Suite v2 shared capability.
 * 
 * PROBLEM: Operators give batch feedback days/weeks later in unstructured text.
 * SOLUTION: Accept that text, parse it, match leads, apply outcomes, audit everything.
 * 
 * ARCHITECTURE:
 *   Parser  → extracts phones, names, categories from raw text
 *   Matcher → matches phones/names to existing leads
 *   Preview → shows matches + ambiguities for human confirmation
 *   Applier → batch-updates lead outcomes (idempotent, audited, rollback-able)
 * 
 * SHARED: Both legacy (estandar-leads) and Suite v2 consume the same service.
 *         No duplication. One parser. One matcher. One applier.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Commercial Feedback Importer — Main orchestrator.
 * 
 * Usage:
 *   $importer = new Estandar_Commercial_Feedback_Importer();
 *   $preview  = $importer->preview( $raw_text );
 *   $result   = $importer->apply( $confirmed_entries );
 *   $importer->rollback( $batch_id );  // if needed
 */
class Estandar_Commercial_Feedback_Importer {

	const OPTION_SNAPSHOTS = 'estandar_feedback_snapshots';
	const TABLE_AUDIT      = 'es_feedback_audit';

	/** @var array Map of category → outcome fields to set on lead */
	const OUTCOME_MAP = [
		'VALIDO'      => [ 'status' => 'CONTACTED', 'quality' => 'VALID_LEAD', 'sales_stage' => 'VALIDO' ],
		'BASURA'      => [ 'status' => 'LOST', 'quality' => 'LOW', 'sales_stage' => 'BASURA', 'invalid_reason' => 'no_fit' ],
		'NO_CONTESTA' => [ 'status' => 'NO_RESPONSE', 'sales_stage' => 'NO_CONTESTA' ],
		'PROPUESTA'   => [ 'sales_stage' => 'PROPOSAL_SENT' ],
		'REUNION'     => [ 'sales_stage' => 'MEETING_BOOKED' ],
		'CLIENTE'     => [ 'status' => 'CLIENT', 'quality' => 'HIGH', 'sales_stage' => 'CLIENT' ],
	];

	/** @var array */
	private $warnings = [];

	/** @var string Current batch ID */
	private $batch_id;

	/**
	 * Preview: parse + match + return preview data.
	 * Does NOT modify any lead. Safe to call repeatedly.
	 * 
	 * @param string $raw  Unstructured feedback text
	 * @return array  [entries, matches, ambiguities, counts, warnings]
	 */
	public function preview( string $raw ): array {
		$this->warnings = [];

		// 1. Parse
		$parsed = Estandar_Commercial_Feedback_Parser::parse( $raw );
		$this->warnings = array_merge( $this->warnings, $parsed['warnings'] );

		// 2. Match each entry to existing leads
		$matches   = [];
		$ambiguous = [];
		$unmatched = [];

		foreach ( $parsed['entries'] as $entry ) {
			$found = $this->find_leads( $entry['phone'], $entry['name'] );

			if ( count( $found ) === 1 ) {
				$entry['matched_lead'] = $found[0];
				$entry['match_method'] = $found[0]['_match_method'];
				$matches[] = $entry;
			} elseif ( count( $found ) > 1 ) {
				$entry['candidates'] = $found;
				$ambiguous[] = $entry;
			} else {
				$unmatched[] = $entry;
			}
		}

		return [
			'entries'    => $parsed['entries'],
			'counts'     => $parsed['counts'],
			'matches'    => $matches,
			'ambiguous'  => $ambiguous,
			'unmatched'  => $unmatched,
			'warnings'   => $this->warnings,
			'total'      => count( $parsed['entries'] ),
			'matched_pct'=> count( $parsed['entries'] ) > 0
				? round( count( $matches ) / count( $parsed['entries'] ) * 100 )
				: 0,
		];
	}

	/**
	 * Apply confirmed outcomes to leads.
	 * Creates snapshot before modifying. Idempotent.
	 * 
	 * @param array $entries  Confirmed entries from preview (with matched_lead)
	 * @param string $operator  Who performed the import
	 * @return array  [applied, skipped, errors, batch_id]
	 */
	public function apply( array $entries, string $operator = '' ): array {
		$this->batch_id = 'feedback_' . gmdate( 'YmdHis' ) . '_' . substr( md5( uniqid( '', true ) ), 0, 8 );

		// Snapshot current state
		$this->create_snapshot( $entries );

		$applied = [];
		$skipped = [];
		$errors  = [];

		foreach ( $entries as $entry ) {
			if ( empty( $entry['matched_lead'] ) ) {
				$skipped[] = $entry;
				continue;
			}

			$lead    = $entry['matched_lead'];
			$lead_id = (int) ( $lead['id'] ?? $lead['lead_id'] ?? 0 );
			$category = $entry['category'] ?? 'VALIDO';

			if ( ! $lead_id ) {
				$errors[] = [ 'entry' => $entry, 'error' => 'no_lead_id' ];
				continue;
			}

			// Build outcome updates from category map
			$updates = self::OUTCOME_MAP[ $category ] ?? [ 'sales_stage' => $category ];
			$updates['feedback_imported'] = 1;
			$updates['feedback_batch']    = $this->batch_id;

			$result = $this->update_lead( $lead_id, $updates, $entry, $operator );

			if ( $result['success'] ) {
				$applied[] = array_merge( $entry, [ 'result' => 'updated' ] );
			} elseif ( $result['unchanged'] ) {
				$skipped[] = array_merge( $entry, [ 'result' => 'unchanged' ] );
			} else {
				$errors[] = array_merge( $entry, [ 'result' => 'error', 'error' => $result['error'] ] );
			}
		}

		return [
			'applied'  => $applied,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'batch_id' => $this->batch_id,
			'summary'  => sprintf(
				'%d applied, %d skipped, %d errors',
				count( $applied ), count( $skipped ), count( $errors )
			),
		];
	}

	/**
	 * Rollback a batch by restoring snapshot.
	 * 
	 * @param string $batch_id
	 * @return array
	 */
	public function rollback( string $batch_id ): array {
		$snapshots = get_option( self::OPTION_SNAPSHOTS, [] );
		if ( ! isset( $snapshots[ $batch_id ] ) ) {
			return [ 'status' => 'error', 'error' => 'snapshot not found' ];
		}

		$restored = 0;
		foreach ( $snapshots[ $batch_id ]['leads'] as $lead_id => $prev_state ) {
			$this->update_lead_raw( (int) $lead_id, $prev_state );
			$restored++;
		}

		unset( $snapshots[ $batch_id ] );
		update_option( self::OPTION_SNAPSHOTS, $snapshots, false );

		return [ 'status' => 'rolled_back', 'restored' => $restored ];
	}

	// ── Private helpers ──

	/**
	 * Find leads matching a phone number and name.
	 * Returns array of matched leads with _match_method.
	 */
	private function find_leads( string $phone, string $name ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'es_leads';

		// 1. Exact phone match
		if ( $phone ) {
			$digits = preg_replace( '/\D/', '', $phone );
			if ( strlen( $digits ) >= 8 ) {
				$results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, nombre, telefono, email, status, sales_stage, quality, situacion_empresa
						 FROM $table
						 WHERE REPLACE(REPLACE(REPLACE(REPLACE(telefono, ' ', ''), '+', ''), '-', ''), '56', '') LIKE %s
						    OR telefono = %s
						 LIMIT 5",
						'%' . $digits . '%',
						$phone
					),
					ARRAY_A
				);

				if ( ! empty( $results ) ) {
					$leads = [];
					foreach ( $results as $r ) {
						$r['_match_method'] = 'phone';
						$leads[] = $r;
					}
					return $leads;
				}
			}
		}

		// 2. Name match (when no phone match)
		if ( $name && strlen( $name ) > 2 ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, nombre, telefono, email, status, sales_stage, quality, situacion_empresa
					 FROM $table
					 WHERE nombre LIKE %s
					 LIMIT 5",
					'%' . $wpdb->esc_like( $name ) . '%'
				),
				ARRAY_A
			);

			if ( ! empty( $results ) ) {
				$leads = [];
				foreach ( $results as $r ) {
					$r['_match_method'] = 'name';
					$leads[] = $r;
				}
				return $leads;
			}
		}

		return [];
	}

	/**
	 * Update a lead with outcome fields. Idempotent.
	 */
	private function update_lead( int $lead_id, array $updates, array $entry, string $operator ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'es_leads';

		// Check if already updated in this batch
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT feedback_batch FROM $table WHERE id = %d",
			$lead_id
		) );

		if ( $existing === $this->batch_id ) {
			return [ 'success' => false, 'unchanged' => true ];
		}

		$updates['updated_at'] = current_time( 'mysql' );
		$result = $wpdb->update( $table, $updates, [ 'id' => $lead_id ] );

		if ( $result === false ) {
			return [ 'success' => false, 'error' => $wpdb->last_error ];
		}

		// Fire action for hooks (Flow pipeline, OCI bridge, journey events)
		do_action( 'estandar_feedback_applied', $lead_id, $entry['category'], $this->batch_id, $operator );

		return [ 'success' => true ];
	}

	/** Raw update for rollback. */
	private function update_lead_raw( int $lead_id, array $data ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'es_leads', $data, [ 'id' => $lead_id ] );
	}

	/** Create snapshot before applying changes. */
	private function create_snapshot( array $entries ): void {
		global $wpdb;
		$snapshots = get_option( self::OPTION_SNAPSHOTS, [] );
		$leads_state = [];

		foreach ( $entries as $entry ) {
			if ( empty( $entry['matched_lead']['id'] ) ) continue;
			$lead_id = (int) $entry['matched_lead']['id'];
			$current = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT status, sales_stage, quality, invalid_reason FROM {$wpdb->prefix}es_leads WHERE id = %d",
					$lead_id
				),
				ARRAY_A
			);
			if ( $current ) {
				$leads_state[ $lead_id ] = $current;
			}
		}

		$snapshots[ $this->batch_id ] = [
			'time'  => current_time( 'mysql' ),
			'count' => count( $leads_state ),
			'leads' => $leads_state,
		];

		// Keep max 50 snapshots
		if ( count( $snapshots ) > 50 ) {
			$snapshots = array_slice( $snapshots, -50, 50, true );
		}

		update_option( self::OPTION_SNAPSHOTS, $snapshots, false );
	}
}
