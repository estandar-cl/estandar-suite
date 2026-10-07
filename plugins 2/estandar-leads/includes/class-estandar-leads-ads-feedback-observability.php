<?php
/**
 * Ads Feedback Observability — single read-only resolver over the persisted
 * execution evidence of the ads feedback layer (Google OCI / Meta CAPI).
 *
 * SSOT rule: the AI Context export and every admin panel MUST consume this
 * resolver — never re-derive ads feedback state from raw tables elsewhere.
 *
 * Evidence sources (all pre-existing; this class writes NOTHING):
 *   es_meta_queue                  Meta adapter outbox (Estandar_Leads_Meta_Adapter)
 *   es_google_queue                Google DM outbox (Estandar_Leads_Google_Adapter)
 *   es_commercial_outcomes_log     per-dispatch execution log (adapters meta/google_dm)
 *   es_lead_submissions.context_json.qa_protection   QA verdict frozen at ingestion
 *   es_lead_events (qualification_completed / manual override)  policy decision
 *   es_signal_events / es_signal_queue                secondary Signal Core ledger
 *   wp_options (presence-only booleans)               adapter configuration gates
 *
 * State contract (canonical, closed set):
 *   NOT_CONFIGURED · NOT_ELIGIBLE · SKIPPED_BY_POLICY · QUEUED · SENT ·
 *   API_ACCEPTED · API_REJECTED · RETRY_PENDING · FAILED ·
 *   PLATFORM_CONFIRMATION_UNKNOWN
 *
 * Honesty invariant: absence of telemetry renders
 * PLATFORM_CONFIRMATION_UNKNOWN — never a success state. Every state carries
 * a `basis`: 'api_acknowledgement' | 'local_execution' | 'policy' | 'config' | 'none'.
 *
 * Secret safety: configuration is exposed as presence booleans only; every
 * free-text error/message passes through redact() before rendering.
 *
 * @package EstandarLeads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Ads_Feedback_Observability {

	const STATE_NOT_CONFIGURED = 'NOT_CONFIGURED';
	const STATE_NOT_ELIGIBLE   = 'NOT_ELIGIBLE';
	const STATE_SKIPPED_POLICY = 'SKIPPED_BY_POLICY';
	const STATE_QUEUED         = 'QUEUED';
	const STATE_SENT           = 'SENT';
	const STATE_ACCEPTED       = 'API_ACCEPTED';
	const STATE_REJECTED       = 'API_REJECTED';
	const STATE_RETRY_PENDING  = 'RETRY_PENDING';
	const STATE_FAILED         = 'FAILED';
	const STATE_UNKNOWN        = 'PLATFORM_CONFIRMATION_UNKNOWN';

	/** Meta statuses → Meta event names (mirror of the payload builder map). */
	const META_EVENT_MAP = array(
		'CAPTURED'  => 'Lead',
		'QUALIFIED' => 'QualifiedLead',
	);

	// ── Public API ───────────────────────────────────────────────────────────────

	/**
	 * Resolve the full ads feedback observability structure for one lead.
	 *
	 * @param  int $lead_id
	 * @return array { eligibility, google, meta, signal_core, trace, notes }
	 */
	public static function resolve( $lead_id ) {
		global $wpdb;

		$lead_id = (int) $lead_id;
		$lead    = $lead_id > 0 ? $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
			$lead_id
		) ) : null;

		if ( ! $lead ) {
			return array(
				'lead_id'     => $lead_id,
				'eligibility' => array(),
				'google'      => array( 'state' => self::STATE_UNKNOWN, 'basis' => 'none', 'reason' => 'lead_not_found' ),
				'meta'        => array( 'state' => self::STATE_UNKNOWN, 'basis' => 'none', 'reason' => 'lead_not_found' ),
				'signal_core' => array( 'present' => false ),
				'trace'       => array(),
				'notes'       => array( 'lead_not_found' ),
			);
		}

		$submission_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d ORDER BY id DESC LIMIT 1",
			$lead_id
		) );

		$eligibility = self::resolve_eligibility( $lead, $lead_id, $submission_id );
		$notes       = array();

		$google = self::resolve_platform( 'google', $lead, $eligibility, $notes );
		$meta   = self::resolve_platform( 'meta', $lead, $eligibility, $notes );
		$signal = self::resolve_signal_core( $lead_id );

		$trace = self::build_trace( $google, $meta, $signal );

		return array(
			'lead_id'     => $lead_id,
			'eligibility' => $eligibility,
			'google'      => $google,
			'meta'        => $meta,
			'signal_core' => $signal,
			'trace'       => $trace,
			'notes'       => array_values( array_unique( $notes ) ),
		);
	}

	/**
	 * Markdown renderer over resolve() — the ONE canonical section consumed by
	 * both the AI Context export and the admin panel (same resolver, same output).
	 */
	public static function render( $lead_id ) {
		$r  = self::resolve( (int) $lead_id );
		$e  = $r['eligibility'];
		$md = "### Ads Feedback Observability\n\n";
		$md .= "_Resolver: single shared read-only source (export + panel). Absence of telemetry renders PLATFORM_CONFIRMATION_UNKNOWN — never success._\n\n";

		// ── Eligibility ──────────────────────────────────────────────────────
		$md .= "#### Eligibility\n\n";
		if ( empty( $e ) ) {
			$md .= "- UNKNOWN (lead not found)\n";
		} else {
			$md .= '- **Is test lead (QA):** ' . ( $e['is_test_lead'] ? 'TRUE' : 'FALSE' ) . "\n";
			$md .= '- **Ads feedback policy:** ' . self::show( $e['ads_feedback_policy'], 'UNKNOWN' ) . "\n";
			$md .= '- **Eligible:** ' . ( $e['eligible'] ? 'TRUE' : 'FALSE' ) . "\n";
			$md .= '- **Fit blocks export:** ' . ( ! empty( $e['fit_blocking_export'] ) ? 'TRUE' : 'FALSE' ) . "\n";
			$md .= '- **Eligible event:** ' . self::show( $e['eligible_event'], 'NONE' ) . "\n";
			$md .= '- **Eligibility reason:** ' . self::show( $e['eligibility_reason'], 'default_policy' ) . "\n";
			$md .= '- **Qualification:** ' . self::show( $e['qualification'], 'UNKNOWN' ) . "\n";
			$md .= '- **Qualification source:** ' . self::show( $e['qualification_source'], 'UNKNOWN' ) . "\n";
			$md .= '- **Commercial fit:** ' . self::show( $e['commercial_fit'], 'NONE' ) . "\n";
			$md .= '- **Fit (Lead Assessment):** ' . self::show( $e['fit'], 'NOT_ASSESSED' ) . "\n";
			if ( ( $e['fit'] ?? '' ) !== '' ) {
				$md .= '- **Fit reason:** ' . self::show( $e['fit_reason'], 'NONE' ) . "\n";
				$md .= '- **Fit confidence:** ' . ( $e['fit_confidence'] !== null ? (int) $e['fit_confidence'] : 'NONE' ) . "\n";
				$md .= '- **Fit decision source:** ' . self::show( $e['fit_decision_source'], 'NONE' ) . "\n";
				$md .= '- **Fit review required:** ' . ( ! empty( $e['fit_review_required'] ) ? 'TRUE' : 'FALSE' ) . "\n";
			}
			$md .= '- **Quality:** ' . self::show( $e['quality'], 'NONE' ) . "\n";
			$md .= '- **Sales stage:** ' . self::show( $e['sales_stage'], 'NONE' ) . "\n";
		}

		$md .= "\n#### Google Ads OCI\n\n" . self::render_platform( $r['google'], 'google' );
		$md .= "\n#### Meta CAPI\n\n" . self::render_platform( $r['meta'], 'meta' );

		// ── Signal Core (secondary ledger) ───────────────────────────────────
		$sc = $r['signal_core'];
		$md .= "\n#### Signal Core Ledger (secondary channel)\n\n";
		if ( empty( $sc['present'] ) ) {
			$md .= "- Not installed or no signal tables — no evidence from this channel.\n";
		} else {
			$md .= '- **Signals for this lead:** ' . (int) $sc['count'] . "\n";
			foreach ( (array) $sc['events'] as $ev ) {
				$md .= '- ' . $ev['event_time'] . ' · `' . $ev['event_name'] . '` · export_status=`' . $ev['export_status'] . '`'
					. ( $ev['queue_status'] !== '' ? ' · queue=`' . $ev['queue_status'] . '`' : '' )
					. ( $ev['last_error'] !== '' ? ' · error: ' . self::redact( $ev['last_error'] ) : '' )
					. "\n";
			}
			if ( ! empty( $sc['conflation_note'] ) ) {
				$md .= "- _Note: `exported` in Signal Core is a single flag shared by all exporters and is also set when an exporter skips (QA / no click id). It is rendered as PLATFORM_CONFIRMATION_UNKNOWN, never as acceptance evidence._\n";
			}
		}

		// ── Chronological execution trace ────────────────────────────────────
		$md .= "\n#### Execution Trace (chronological)\n\n";
		if ( empty( $r['trace'] ) ) {
			$md .= "No persisted ads feedback execution telemetry for this lead.\n";
		} else {
			foreach ( $r['trace'] as $line ) {
				$md .= '- ' . $line . "\n";
			}
		}

		if ( ! empty( $r['notes'] ) ) {
			$md .= "\n#### Observability Notes\n\n";
			foreach ( $r['notes'] as $note ) {
				$md .= '- ' . $note . "\n";
			}
		}

		return $md;
	}

	// ── Eligibility ──────────────────────────────────────────────────────────────

	private static function resolve_eligibility( $lead, $lead_id, $submission_id ) {
		$is_test = class_exists( 'Estandar_Leads_QA_Policy' )
			? Estandar_Leads_QA_Policy::is_test_lead( (int) $lead_id )
			: false;

		$decision = class_exists( 'Estandar_Commercial_Qualification_Engine' )
			? Estandar_Commercial_Qualification_Engine::decision_for( (int) $lead_id, (int) $submission_id )
			: array();

		$policy = strtolower( trim( (string) ( $decision['ads_feedback_policy'] ?? '' ) ) );
		if ( ! in_array( $policy, array( 'allow', 'skip' ), true ) ) {
			$policy = '';
		}

		// Exportable milestones actually reached: capture is a fact once the
		// lead row exists; the current status counts when any adapter exports it.
		$status     = strtoupper( trim( (string) ( $lead->status ?? '' ) ) );
		$exportable = self::exportable_statuses();
		$events     = array( 'CAPTURED' );
		if ( $status !== '' && in_array( $status, $exportable, true ) ) {
			$events[] = $status;
		}

		$reason = trim( (string) ( $decision['policy_reason'] ?? '' ) );
		if ( $is_test ) {
			$reason = 'qa_test_lead';
		} elseif ( $policy === 'skip' ) {
			$reason = $reason !== '' ? $reason : 'ads_feedback_policy:skip';
		}

		// LEAD_ASSESSMENT_V1 — fit axis gate. Mirrors the enforced gate
		// (QA_Policy::ads_feedback_blocked) via the shared SSOT resolver so the
		// panel and the enforced state can never diverge.
		$fit_value = strtoupper( trim( (string) ( $lead->commercial_fit ?? '' ) ) );
		$fit_blocks = class_exists( 'Estandar_Leads_Fit' )
			&& Estandar_Leads_Fit::should_block_ads_export(
				$fit_value,
				(string) ( $lead->status ?? '' ),
				isset( $lead->is_valid_lead ) ? (int) $lead->is_valid_lead : null,
				(string) ( $decision['decision_source'] ?? 'default' ),
				$policy
			);
		if ( $fit_blocks ) {
			$reason = 'fit_blocks_export (' . $fit_value . ')';
		}

		return array(
			'is_test_lead'         => (bool) $is_test,
			'ads_feedback_policy'  => $policy,
			'eligible'             => ! $is_test && $policy !== 'skip' && ! $fit_blocks,
			'eligible_event'       => implode( ', ', $events ),
			'eligibility_reason'   => $reason,
			'qualification'        => (string) ( $decision['qualification'] ?? '' ),
			'qualification_source' => (string) ( $decision['decision_source'] ?? '' ),
			'commercial_fit'       => (string) ( $decision['commercial_fit'] ?? '' ),
			'quality'              => (string) ( $decision['quality'] ?? ( $lead->quality ?? '' ) ),
			'sales_stage'          => (string) ( $decision['sales_stage'] ?? ( $lead->sales_stage ?? '' ) ),
			// LEAD_ASSESSMENT_V1 — persisted fit axis (SSOT: es_leads columns).
			'fit'                  => $fit_value,
			'fit_reason'           => (string) ( $lead->fit_reason ?? '' ),
			'fit_confidence'       => isset( $lead->fit_confidence ) && $lead->fit_confidence !== null ? (int) $lead->fit_confidence : null,
			'fit_decision_source'  => (string) ( $lead->fit_decision_source ?? '' ),
			'fit_review_required'  => ! empty( $lead->fit_review_required ),
			'fit_blocking_export'  => (bool) $fit_blocks,
		);
	}

	/** Union of both adapters' exportable status sets (constants when loaded). */
	private static function exportable_statuses() {
		$meta = class_exists( 'Estandar_Leads_Meta_Adapter' )
			? Estandar_Leads_Meta_Adapter::EXPORT_STATUSES
			: array( 'QUALIFIED', 'MEETING_BOOKED', 'CONVERTED', 'VISIT_SCHEDULED', 'VISITED', 'RESERVED', 'CLIENT', 'CLIENT_SIGNED', 'DEED_SIGNED', 'DELIVERED' );
		$google = class_exists( 'Estandar_Leads_Google_Adapter' )
			? Estandar_Leads_Google_Adapter::EXPORT_STATUSES
			: array( 'QUALIFIED' );
		return array_values( array_unique( array_merge( (array) $meta, (array) $google ) ) );
	}

	// ── Platform resolution ──────────────────────────────────────────────────────

	/**
	 * @param string $platform 'google' | 'meta'
	 */
	private static function resolve_platform( $platform, $lead, array $eligibility, array &$notes ) {
		global $wpdb;

		$lead_id = (int) $lead->id;
		$platform_eligibility = Estandar_Leads_Attribution::platform_eligibility(
			$platform,
			$lead,
			! empty( $eligibility['eligible'] )
		);

		if ( $platform === 'google' ) {
			$configured = self::google_configured();
			$enabled    = get_option( 'estandar_leads_google_enabled', '' ) === '1';
			$dry_run    = get_option( 'estandar_leads_google_dry_run', '' ) !== '0';
			$wired      = class_exists( 'Estandar_Leads_Google_Adapter' );
			$queue      = self::queue_rows( $wpdb->prefix . 'es_google_queue', $lead_id );
			$logs       = self::log_rows( $lead_id, 'google_dm' );
			$identifier = self::google_click_identifier( $lead );
			$out        = array(
				'configured'        => $configured,
				'enabled'           => $enabled,
				'dry_run'           => $dry_run,
				'adapter_wired'     => $wired,
				'click_identifier'  => $identifier['type'],
				'click_id_present'  => $identifier['type'] !== '',
				'conversion_action' => (string) get_option( 'estandar_leads_google_conversion_action_id', '' ),
				'channel'           => (string) $platform_eligibility['channel'],
				'platform_eligible' => (bool) $platform_eligibility['eligible'],
			);
			if ( ! $wired ) {
				$notes[] = 'Google adapter code exists but is not wired into the plugin bootstrap (dormant) — no Google execution can occur through estandar-leads today.';
			}
		} else {
			$configured = get_option( 'estandar_leads_meta_pixel_id', '' ) !== ''
				&& get_option( 'estandar_leads_meta_access_token', '' ) !== '';
			$enabled = class_exists( 'Estandar_Leads_Meta_Adapter' )
				? Estandar_Leads_Meta_Adapter::server_events_enabled()
				: ( get_option( 'estandar_leads_meta_enabled', '' ) !== '0' && get_option( 'estandar_leads_meta_server_events', '' ) !== '0' );
			$queue = self::queue_rows( $wpdb->prefix . 'es_meta_queue', $lead_id );
			$logs  = self::log_rows( $lead_id, 'meta' );
			$uuid  = trim( (string) ( $lead->lead_uuid ?? '' ) );
			if ( $uuid === '' ) {
				$uuid = 'lead-' . $lead_id;
			}
			$out = array(
				'configured'   => $configured,
				'enabled'      => $enabled,
				'test_mode'    => get_option( 'estandar_leads_meta_test_mode', '' ) === '1',
				'dedup'        => 'deterministic event_id (lead_uuid:STATUS) — browser Pixel and server CAPI emit the same id',
				'fbclid'       => trim( (string) ( $lead->first_fbclid ?? '' ) ) !== '',
				'fbc_fbp'      => trim( (string) ( $lead->first_meta_fbc ?? '' ) ) !== '' || trim( (string) ( $lead->first_meta_fbp ?? '' ) ) !== '',
				'lead_uuid'    => $uuid,
				'channel'      => (string) $platform_eligibility['channel'],
				'platform_eligible' => (bool) $platform_eligibility['eligible'],
			);
		}

		$out['queue'] = $queue;
		$out['logs']  = $logs;
		// Persisted gate decisions (ads_dispatch_skipped events) — the skip is
		// evidence, not an inference. Rendered in state + trace.
		$out['skip_events'] = self::skip_events( $lead_id, $platform );

		// Eligibility is authoritative even when historical execution evidence
		// exists. Contradictory rows remain visible in queue/log/trace and notes,
		// but can never turn an ineligible acquisition into a success state.
		if ( ! empty( $eligibility['is_test_lead'] )
			|| ( $eligibility['ads_feedback_policy'] ?? '' ) === 'skip'
			|| empty( $platform_eligibility['eligible'] ) ) {
			if ( ! empty( $eligibility['is_test_lead'] ) ) {
				$out['state']  = self::STATE_NOT_ELIGIBLE;
				$out['reason'] = 'qa_test_lead (QA exclusion — persisted at ingestion)';
			} elseif ( ( $eligibility['ads_feedback_policy'] ?? '' ) === 'skip' ) {
				$out['state']  = self::STATE_SKIPPED_POLICY;
				$out['reason'] = 'commercial qualification: ads_feedback_policy=skip';
			} else {
				$out['state']  = self::STATE_NOT_ELIGIBLE;
				$out['reason'] = (string) $platform_eligibility['reason'];
			}
			$out['basis'] = 'policy';
			if ( ! empty( $queue ) || ! empty( $logs ) ) {
				if ( ! empty( $eligibility['is_test_lead'] ) ) {
					$notes[] = strtoupper( $platform ) . ': execution telemetry exists for a QA test lead — QA exclusion leak, investigate.';
				} elseif ( ( $eligibility['ads_feedback_policy'] ?? '' ) === 'skip' ) {
					$notes[] = strtoupper( $platform ) . ': ads_feedback_policy=skip but execution telemetry exists — policy enforcement leak, investigate.';
				} else {
					$notes[] = strtoupper( $platform ) . ': execution telemetry exists for a currently ineligible acquisition — historical eligibility leak, investigate.';
				}
			}
			return $out;
		}

		// ── No persisted execution → derive the honest non-execution state ───
		if ( empty( $queue ) && empty( $logs ) ) {
			// A persisted gate rejection IS evidence: skipped/not_eligible with
			// the recorded reason (basis = policy decision, persisted).
			if ( ! empty( $out['skip_events'] ) ) {
				$last_skip     = end( $out['skip_events'] );
				$out['state']  = ( $last_skip['decision'] === 'skipped_by_policy' )
					? self::STATE_SKIPPED_POLICY
					: self::STATE_NOT_ELIGIBLE;
				$out['basis']  = 'policy';
				$out['reason'] = $last_skip['reason'] !== ''
					? $last_skip['reason'] . ' (persisted gate decision)'
					: 'dispatch skipped — persisted gate decision';
				return $out;
			}
			if ( ! $out['configured'] || ( $platform === 'google' && ( ! $out['adapter_wired'] || ! $out['enabled'] ) ) ) {
				$out['state']  = self::STATE_NOT_CONFIGURED;
				$out['basis']  = 'config';
				$out['reason'] = $platform === 'google' && ! $out['adapter_wired']
					? 'adapter not wired'
					: 'credentials/toggles incomplete (presence check only)';
			} else {
				$out['state']  = self::STATE_UNKNOWN;
				$out['basis']  = 'none';
				$out['reason'] = 'no execution telemetry persisted — cannot claim any send occurred';
			}
			return $out;
		}

		// ── Persisted execution exists → derive from the latest evidence ─────
		$latest = self::latest_stream_state( $platform, $queue, $logs );
		$out    = array_merge( $out, $latest );

		// Cross-checks: execution evidence that contradicts the gates.
		if ( ! empty( $eligibility['is_test_lead'] ) ) {
			$notes[] = strtoupper( $platform ) . ': execution telemetry exists for a QA test lead — QA exclusion leak, investigate.';
		}
		if ( ( $eligibility['ads_feedback_policy'] ?? '' ) === 'skip' ) {
			$notes[] = strtoupper( $platform ) . ': ads_feedback_policy=skip but execution telemetry exists — adapters do not consult the qualification policy yet (known enforcement gap).';
		}

		return $out;
	}

	/**
	 * Map the newest queue row + its log evidence to the canonical state.
	 * Retries never duplicate states: the stream's LATEST condition wins.
	 */
	private static function latest_stream_state( $platform, array $queue, array $logs ) {
		// Newest queue activity decides the current state.
		$row = null;
		foreach ( $queue as $q ) {
			if ( $row === null || strcmp( (string) ( $q->last_attempted_at ?: $q->created_at ), (string) ( $row->last_attempted_at ?: $row->created_at ) ) >= 0 ) {
				$row = $q;
			}
		}

		$last_log = ! empty( $logs ) ? $logs[0] : null; // logs come newest-first
		$http     = $last_log ? (int) $last_log->http_code : 0;
		$trace_id = self::trace_identifier( $platform, $row, $last_log );

		$common = array(
			'attempts'        => $row ? (int) $row->attempts : count( $logs ),
			'last_attempt_at' => $row ? (string) ( $row->last_attempted_at ?: '' ) : ( $last_log ? (string) $last_log->created_at : '' ),
			'event_time'      => $row ? (string) $row->event_time : '',
			'stream'          => $row ? (string) $row->export_status : ( $last_log ? (string) $last_log->stage : '' ),
			'request_id'      => $trace_id,
			'error'           => $row ? self::redact( (string) ( $row->last_error ?? '' ) ) : '',
			'retry_next_at'   => $row && isset( $row->next_attempt_at ) ? (string) ( $row->next_attempt_at ?: '' ) : '',
		);

		if ( ! $row ) {
			// Log rows without a queue row — a send (or a persisted skip
			// decision) happened but no outbox row exists.
			if ( $last_log && (string) $last_log->status === 'skipped' ) {
				$state = array( 'state' => self::STATE_NOT_ELIGIBLE, 'basis' => 'policy', 'reason' => 'dispatch skipped — decision persisted in execution log' );
			} elseif ( $last_log && (string) $last_log->status === 'success' ) {
				$state = array( 'state' => self::STATE_ACCEPTED, 'basis' => 'api_acknowledgement', 'reason' => 'execution log success (HTTP ' . $http . '), queue row absent' );
			} else {
				$state = array( 'state' => self::STATE_SENT, 'basis' => 'local_execution', 'reason' => 'execution log present without queue row — outcome not acknowledged' );
			}
			return array_merge( $common, $state );
		}

		switch ( (string) $row->queue_status ) {
			case 'pending':
				return array_merge( $common, (int) $row->attempts === 0
					? array( 'state' => self::STATE_QUEUED, 'basis' => 'local_execution', 'reason' => 'outbox row pending, no attempt yet' )
					: array( 'state' => self::STATE_RETRY_PENDING, 'basis' => 'local_execution', 'reason' => 'attempted, still pending (transient failure window)' ) );

			case 'retry':
				return array_merge( $common, array( 'state' => self::STATE_RETRY_PENDING, 'basis' => 'local_execution', 'reason' => 'transient failure — retry scheduled' ) );

			case 'validated': // Google dry-run only.
				return array_merge( $common, array( 'state' => self::STATE_UNKNOWN, 'basis' => 'api_acknowledgement', 'reason' => 'dry-run validateOnly pass — payload accepted, NO conversion recorded by Google' ) );

			case 'completed':
				if ( $last_log && (string) $last_log->status === 'success' && $http >= 200 && $http < 300 ) {
					return array_merge( $common, array( 'state' => self::STATE_ACCEPTED, 'basis' => 'api_acknowledgement', 'reason' => 'API acknowledged (HTTP ' . $http . ')' . ( $platform === 'google' ? ' — request-level ack; per-event processing needs requestStatus reconcile' : '' ) ) );
				}
				return array_merge( $common, array( 'state' => self::STATE_SENT, 'basis' => 'local_execution', 'reason' => 'outbox completed; no API acknowledgement row found' ) );

			case 'dead':
				// Gate skip persisted at dispatch time (skipped/not_eligible
				// prefix) — a decision, never rendered as FAILED.
				if ( stripos( (string) $row->last_error, 'skipped/not_eligible' ) === 0 ) {
					return array_merge( $common, array(
						'state'  => self::STATE_NOT_ELIGIBLE,
						'basis'  => 'policy',
						'reason' => self::redact( (string) $row->last_error ),
					) );
				}
				$permanent_api = ( $last_log && $http >= 400 && $http < 500 )
					|| stripos( (string) $row->last_error, 'partial failure' ) !== false
					|| stripos( (string) $row->last_error, 'permanent' ) !== false;
				return array_merge( $common, $permanent_api
					? array( 'state' => self::STATE_REJECTED, 'basis' => 'api_acknowledgement', 'reason' => 'API rejected permanently' . ( $http ? ' (HTTP ' . $http . ')' : '' ) )
					: array( 'state' => self::STATE_FAILED, 'basis' => 'local_execution', 'reason' => 'dead-lettered (retries exhausted or unmatchable)' ) );
		}

		return array_merge( $common, array( 'state' => self::STATE_UNKNOWN, 'basis' => 'none', 'reason' => 'unrecognized queue status `' . (string) $row->queue_status . '`' ) );
	}

	/** Meta: fbtrace_id from the API response; Google: dm_request_id. */
	private static function trace_identifier( $platform, $row, $last_log ) {
		if ( $platform === 'google' ) {
			return $row && isset( $row->dm_request_id ) ? (string) $row->dm_request_id : '';
		}
		if ( $last_log ) {
			$response = json_decode( (string) $last_log->response_json, true );
			$body     = is_array( $response ) ? json_decode( (string) ( $response['body'] ?? '' ), true ) : null;
			if ( is_array( $body ) && ! empty( $body['fbtrace_id'] ) ) {
				return (string) $body['fbtrace_id'];
			}
		}
		return '';
	}

	// ── Evidence readers (guarded, read-only) ────────────────────────────────────

	private static function queue_rows( $table, $lead_id ) {
		global $wpdb;
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE lead_id = %d ORDER BY created_at ASC, id ASC",
			(int) $lead_id
		) );
	}

	private static function log_rows( $lead_id, $adapter ) {
		global $wpdb;
		$table = $wpdb->prefix . 'es_commercial_outcomes_log';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}
		// Never select payload_json (hashed PII) — evidence fields only.
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, stage, adapter, event, response_json, http_code, status, created_at
			 FROM {$table}
			 WHERE lead_id = %d AND adapter = %s
			 ORDER BY id DESC",
			(int) $lead_id,
			(string) $adapter
		) );
	}

	/**
	 * Persisted gate decisions: ads_dispatch_skipped rows for one platform.
	 *
	 * @return array[] { at, decision, reason, channel, evidence, export_status }
	 */
	private static function skip_events( $lead_id, $platform ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT created_at, reason, metadata FROM {$wpdb->prefix}es_lead_events
			 WHERE lead_id = %d AND event_type = %s
			 ORDER BY created_at ASC, id ASC",
			(int) $lead_id,
			'ads_dispatch_skipped'
		) );
		$out = array();
		foreach ( $rows as $row ) {
			$meta = json_decode( (string) ( $row->metadata ?? '' ), true );
			$meta = is_array( $meta ) ? $meta : array();
			if ( (string) ( $meta['platform'] ?? '' ) !== (string) $platform ) {
				continue;
			}
			$out[] = array(
				'at'            => (string) $row->created_at,
				'decision'      => (string) ( $meta['decision'] ?? 'not_eligible' ),
				'reason'        => self::redact( (string) ( $row->reason ?: ( $meta['reason'] ?? '' ) ) ),
				'channel'       => (string) ( $meta['channel'] ?? '' ),
				'evidence'      => (string) ( $meta['evidence'] ?? '' ),
				'export_status' => (string) ( $meta['export_status'] ?? '' ),
			);
		}
		return $out;
	}

	private static function resolve_signal_core( $lead_id ) {
		global $wpdb;
		$events_table = $wpdb->prefix . 'es_signal_events';
		$queue_table  = $wpdb->prefix . 'es_signal_queue';

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events_table ) ) ) {
			return array( 'present' => false, 'count' => 0, 'events' => array() );
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT e.id, e.event_name, e.event_time, e.export_status, e.audit_only,
			        q.queue_status, q.attempts, q.last_error
			 FROM {$events_table} e
			 LEFT JOIN {$queue_table} q ON q.signal_id = e.id
			 WHERE e.lead_id = %d
			 ORDER BY e.event_time ASC, e.id ASC",
			(int) $lead_id
		) );

		$events     = array();
		$conflation = false;
		foreach ( $rows as $row ) {
			$export_status = (string) $row->export_status;
			if ( $export_status === 'exported' ) {
				// Single shared flag; also set on exporter-side skips (QA, no
				// click id). Not acceptance evidence — see conflation note.
				$conflation = true;
			}
			$events[] = array(
				'event_name'    => (string) $row->event_name,
				'event_time'    => (string) $row->event_time,
				'export_status' => $export_status,
				'queue_status'  => (string) ( $row->queue_status ?? '' ),
				'attempts'      => (int) ( $row->attempts ?? 0 ),
				'last_error'    => (string) ( $row->last_error ?? '' ),
			);
		}

		return array(
			'present'         => true,
			'count'           => count( $events ),
			'events'          => $events,
			'conflation_note' => $conflation,
		);
	}

	// ── Trace assembly ───────────────────────────────────────────────────────────

	private static function build_trace( array $google, array $meta, array $signal ) {
		$entries = array();

		foreach ( array( 'google' => $google, 'meta' => $meta ) as $platform => $p ) {
			foreach ( (array) ( $p['queue'] ?? array() ) as $q ) {
				$entries[] = array(
					'at'   => (string) $q->created_at,
					'line' => (string) $q->created_at . ' · ' . $platform . ' · `' . (string) $q->export_status . '` · queued (outbox)',
				);
				if ( ! empty( $q->last_attempted_at ) ) {
					$entries[] = array(
						'at'   => (string) $q->last_attempted_at,
						'line' => (string) $q->last_attempted_at . ' · ' . $platform . ' · `' . (string) $q->export_status . '` · attempt #' . (int) $q->attempts
							. ' → ' . (string) $q->queue_status
							. ( trim( (string) ( $q->last_error ?? '' ) ) !== '' ? ' · ' . self::redact( (string) $q->last_error ) : '' ),
					);
				}
			}
			foreach ( array_reverse( (array) ( $p['logs'] ?? array() ) ) as $log ) {
				$entries[] = array(
					'at'   => (string) $log->created_at,
					'line' => (string) $log->created_at . ' · ' . $platform . ' · `' . (string) $log->stage . '` · dispatch `' . (string) $log->event . '` → '
						. (string) $log->status . ' (HTTP ' . (int) $log->http_code . ')',
				);
			}
			foreach ( (array) ( $p['skip_events'] ?? array() ) as $skip ) {
				$entries[] = array(
					'at'   => $skip['at'],
					'line' => $skip['at'] . ' · ' . $platform . ' · `' . ( $skip['export_status'] !== '' ? $skip['export_status'] : '—' ) . '` · gate decision → '
						. $skip['decision'] . ( $skip['reason'] !== '' ? ' · ' . $skip['reason'] : '' ),
				);
			}
		}

		foreach ( (array) ( $signal['events'] ?? array() ) as $ev ) {
			$entries[] = array(
				'at'   => $ev['event_time'],
				'line' => $ev['event_time'] . ' · signal-core · `' . $ev['event_name'] . '` · export_status=' . $ev['export_status'],
			);
		}

		usort( $entries, static function ( $a, $b ) {
			return strcmp( $a['at'], $b['at'] );
		} );

		return array_map( static function ( $e ) {
			return $e['line'];
		}, $entries );
	}

	// ── Platform renderer ────────────────────────────────────────────────────────

	private static function render_platform( array $p, $platform ) {
		$md  = '- **Configured:** ' . ( ! empty( $p['configured'] ) ? 'TRUE' : 'FALSE' ) . " (presence check — secrets never displayed)\n";
		$md .= '- **Canonical channel:** ' . self::show( $p['channel'] ?? '', 'UNKNOWN' ) . "\n";
		$md .= '- **Platform eligible:** ' . ( ! empty( $p['platform_eligible'] ) ? 'TRUE' : 'FALSE' ) . "\n";

		if ( $platform === 'google' ) {
			$md .= '- **Adapter wired:** ' . ( ! empty( $p['adapter_wired'] ) ? 'TRUE' : 'FALSE (dormant code — not loaded at runtime)' ) . "\n";
			$md .= '- **Enabled:** ' . ( ! empty( $p['enabled'] ) ? 'TRUE' : 'FALSE' ) . ' · **Dry-run (validateOnly):** ' . ( ! empty( $p['dry_run'] ) ? 'TRUE' : 'FALSE' ) . "\n";
			$md .= '- **Click identifier:** ' . self::show( $p['click_identifier'] ?? '', 'NONE' ) . "\n";
			$md .= '- **Conversion action:** ' . self::show( $p['conversion_action'] ?? '', 'NOT SET' ) . "\n";
		} else {
			$md .= '- **Server events enabled:** ' . ( ! empty( $p['enabled'] ) ? 'TRUE' : 'FALSE' ) . ( ! empty( $p['test_mode'] ) ? ' · **Test mode:** TRUE (queue held)' : '' ) . "\n";
			$stream = (string) ( $p['stream'] ?? '' );
			if ( $stream !== '' ) {
				$md .= '- **Event name:** ' . ( self::META_EVENT_MAP[ $stream ] ?? $stream ) . " (stage `{$stream}`)\n";
				$md .= '- **Event ID:** `' . $p['lead_uuid'] . ':' . $stream . "`\n";
			}
			$md .= '- **Deduplication:** ' . (string) ( $p['dedup'] ?? '' ) . "\n";
			$md .= '- **Identifiers:** fbclid ' . ( ! empty( $p['fbclid'] ) ? 'present' : 'absent' ) . ' · fbc/fbp ' . ( ! empty( $p['fbc_fbp'] ) ? 'present' : 'absent' ) . " (_fbp alone is not eligibility evidence)\n";
		}

		$md .= '- **State:** `' . (string) ( $p['state'] ?? self::STATE_UNKNOWN ) . "`\n";
		$md .= '- **Conclusion basis:** ' . self::basis_label( (string) ( $p['basis'] ?? 'none' ) ) . "\n";
		$md .= '- **Reason:** ' . self::show( $p['reason'] ?? '', 'UNKNOWN' ) . "\n";

		if ( isset( $p['attempts'] ) ) {
			$md .= '- **Attempts:** ' . (int) $p['attempts'] . "\n";
		}
		if ( ! empty( $p['last_attempt_at'] ) ) {
			$md .= '- **Last attempt:** ' . $p['last_attempt_at'] . "\n";
		}
		if ( ! empty( $p['event_time'] ) ) {
			$md .= '- **Event timestamp:** ' . $p['event_time'] . "\n";
		}
		if ( ! empty( $p['request_id'] ) ) {
			$md .= '- **' . ( $platform === 'google' ? 'Request ID (Data Manager)' : 'Trace ID (fbtrace_id)' ) . ':** `' . self::redact( (string) $p['request_id'] ) . "`\n";
		}
		if ( ! empty( $p['retry_next_at'] ) ) {
			$md .= '- **Next retry at:** ' . $p['retry_next_at'] . "\n";
		}
		if ( ! empty( $p['error'] ) ) {
			$md .= '- **Last error:** ' . self::redact( (string) $p['error'] ) . "\n";
		}

		return $md;
	}

	private static function basis_label( $basis ) {
		$map = array(
			'api_acknowledgement' => 'API acknowledgement (persisted platform response)',
			'local_execution'     => 'local execution telemetry only — platform confirmation not persisted',
			'policy'              => 'local policy decision (persisted)',
			'config'              => 'configuration state (live presence check)',
			'none'                => 'no persisted evidence — state is UNKNOWN by contract',
		);
		return $map[ $basis ] ?? $map['none'];
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	private static function google_configured() {
		foreach ( array( 'customer_id', 'conversion_action_id', 'oauth_client_id', 'oauth_client_secret', 'oauth_refresh_token' ) as $key ) {
			if ( (string) get_option( 'estandar_leads_google_' . $key, '' ) === '' ) {
				return false;
			}
		}
		return true;
	}

	private static function google_click_identifier( $lead ) {
		foreach ( array( 'gclid' => 'first_gclid', 'gbraid' => 'first_gbraid', 'wbraid' => 'first_wbraid' ) as $type => $column ) {
			if ( trim( (string) ( $lead->{$column} ?? '' ) ) !== '' ) {
				return array( 'type' => $type );
			}
		}
		return array( 'type' => '' );
	}

	private static function has_contact_identifiers( $lead ) {
		return trim( (string) ( $lead->email_normalized ?? ( $lead->email ?? '' ) ) ) !== ''
			|| trim( (string) ( $lead->phone_normalized ?? ( $lead->phone ?? '' ) ) ) !== '';
	}

	private static function show( $value, $empty ) {
		$value = trim( (string) $value );
		return $value !== '' ? $value : $empty;
	}

	/**
	 * Defense-in-depth secret redaction for any free-text surfaced by the
	 * resolver (queue errors, API messages). Sources already sanitize, but no
	 * token/credential shape may ever reach the export.
	 */
	public static function redact( $text ) {
		$text = (string) $text;
		$text = preg_replace(
			'/\b(access[_-]?token|refresh[_-]?token|client[_-]?secret|developer[_-]?token|authorization|bearer|api[_-]?key)\b\s*[=:]?\s*[\'"]?[A-Za-z0-9\/\+\._\-]{8,}[\'"]?/i',
			'$1=[REDACTED]',
			$text
		);
		// Long unbroken credential-looking blobs (EAAB… Meta tokens, ya29… Google tokens).
		$text = preg_replace( '/\b(EAA[A-Za-z0-9]{20,}|ya29\.[A-Za-z0-9\-_\.]{20,}|1\/\/[A-Za-z0-9\-_]{20,})\b/', '[REDACTED]', $text );
		return substr( $text, 0, 300 );
	}
}
