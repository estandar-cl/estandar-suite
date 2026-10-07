<?php
/**
 * Google Ads (Data Manager API) adapter for Estandar Leads — OCI_FOUNDATION_V1.
 *
 * Mirrors the architectural pattern of Estandar_Leads_Meta_Adapter:
 *   estandar_lead_status_changed (existing Business Event)
 *     → on_status_changed()   real-transition gate → emits the DOMAIN event
 *       estandar_lead_qualified( $lead_id, $old, $new, $changed_at )
 *     → on_lead_qualified()   exportability + config gate
 *     → enqueue()             writes to es_google_queue (durable outbox, idempotent)
 *     → run_worker()          batch → Payload Builder → DM Client (cron + shutdown)
 *
 * Layer boundaries (do not blur):
 *   Adapter         = subscriber + outbox + worker + gates (this class)
 *   Payload Builder = pure data shaping (Estandar_Leads_Google_Payload_Builder)
 *   DM Client       = transport only  (Estandar_Leads_Google_DM_Client)
 *
 * wp_options (prefix: estandar_leads_google_):
 *   customer_id           Google Ads CID (digits; dashes stripped on save)
 *   login_customer_id     Optional MCC CID when access goes through a manager
 *   conversion_action_id  Google Ads conversion action ("Lead Calificado",
 *                         source: Website — Import from clicks)
 *   oauth_client_id / oauth_client_secret / oauth_refresh_token
 *                         OAuth credentials, scope …/auth/datamanager
 *   enabled       '1' = on. DEFAULT OFF — unlike Meta there is no pre-existing
 *                 install to grandfather: a new integration must opt in.
 *   dry_run       '' or '1' = validateOnly (Google validates, records NOTHING).
 *                 DEFAULT ON — real conversions require flipping it off.
 *   test_mode     '1' = enqueue + log, never touch the network (queue held).
 *   debug_logging '1' = per-batch telemetry (request_id/http/duration — no PII)
 *
 * Queue-state model (es_google_queue.queue_status):
 *   pending → validated  (dry-run pass: Google accepted the payload shape)
 *   pending|validated → completed  (real send acknowledged, requestId stored)
 *   pending|validated → retry      (temporary failure, next_attempt_at = backoff)
 *   → dead  (permanent failure or retries exhausted)
 * validated rows re-dispatch automatically when dry_run is turned off — the
 * UNIQUE(lead_id, export_status) key still guarantees exactly one real send.
 *
 * @package EstandarLeads
 * @since   13.0.59
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Google_Adapter {

	const OPTION_PREFIX = 'estandar_leads_google_';
	const CRON_HOOK     = 'estandar_leads_google_process_queue';
	const CRON_INTERVAL = 'hourly';
	const MAX_ATTEMPTS  = 5;
	const BATCH_SIZE    = 50;  // rows per worker run
	const HTTP_BATCH    = 20;  // events per ingest request (spec allows ≤ 2000)

	// Immediate dispatch (in-process, cron-independent) + worker mutex.
	const IMMEDIATE_BATCH = 5;
	const LOCK_OPTION     = 'estandar_leads_google_worker_lock';
	const LOCK_TTL        = 120;

	/**
	 * Exponential backoff schedule (seconds) indexed by attempt number:
	 * 15m → 1h → 4h → 16h → capped at 24h.
	 */
	const BACKOFF_BASE = 900;
	const BACKOFF_CAP  = 86400;

	/**
	 * Lead statuses that export to the "Lead Calificado" conversion action.
	 * V1 scope: the qualification milestone only (OCI_EXPORT_WHITELIST canon —
	 * neutral/subjective statuses NEVER export). Deeper milestones need their
	 * own conversion actions first; extend via the filter below when they exist.
	 */
	const EXPORT_STATUSES = array( 'QUALIFIED' );

	// ── Bootstrap ──────────────────────────────────────────────────────────────

	public static function init() {
		// Reuse the existing Business Event transport — no emitter is modified.
		add_action( 'estandar_lead_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );

		// Domain event subscriber — the adapter consumes the same event it
		// derives, so future consumers (webhooks, analytics) attach here too.
		add_action( 'estandar_lead_qualified', array( __CLASS__, 'on_lead_qualified' ), 10, 4 );

		// Async worker — cron is the RECOVERY layer under immediate dispatch.
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	// ── Domain event derivation ──────────────────────────────────────────────────

	/**
	 * LeadQualified domain event: fired exactly once per REAL transition into
	 * an exportable status (old ≠ new). Emitted unconditionally of the Google
	 * config — the domain fact exists whether or not an exporter is listening.
	 *
	 * Payload contract (frozen):
	 *   do_action( 'estandar_lead_qualified', $lead_id, $old_status, $new_status, $changed_at )
	 */
	public static function on_status_changed( $lead_id, $old_status, $new_status, $changed_at ) {
		if ( class_exists( 'Estandar_Leads_QA_Policy' ) && Estandar_Leads_QA_Policy::is_test_lead( (int) $lead_id ) ) {
			return;
		}
		$new = strtoupper( trim( (string) $new_status ) );
		$old = strtoupper( trim( (string) $old_status ) );

		if ( $old === $new || ! self::is_exportable_status( $new ) ) {
			return;
		}

		do_action( 'estandar_lead_qualified', (int) $lead_id, $old, $new, (string) $changed_at );
	}

	/**
	 * Exporter gate on the domain event. The outbox UNIQUE key makes the whole
	 * path idempotent even if the domain event ever fires twice for one lead.
	 */
	public static function on_lead_qualified( $lead_id, $old_status, $new_status, $changed_at ) {
		if ( ! self::is_enabled() || ! self::is_configured() ) {
			return;
		}
		// GOOGLE_GATE_V1 (mirror of META_GATE_V1): QA + ads_feedback policy +
		// canonical Google Ads channel + click identifier — one decision point,
		// rejection persisted (skipped/not_eligible), never a silent drop.
		// Applied only here (before enqueue) — the domain event still fires for
		// non-ads consumers; only the ads export is suppressed.
		$gate = self::dispatch_gate( (int) $lead_id, (string) $new_status );
		if ( empty( $gate['allowed'] ) ) {
			self::record_skip( (int) $lead_id, (string) $new_status, $gate );
			return;
		}
		self::enqueue( (int) $lead_id, (string) $new_status, (string) $changed_at, $gate );
	}

	// ── GOOGLE_GATE_V1 — single canonical dispatch gate ──────────────────────────

	/**
	 * Composes the canonical sources verbatim (no rule re-implemented):
	 *   1. QA/test protection + ads_feedback_policy (Estandar_Leads_QA_Policy)
	 *   2. canonical Google Ads channel + click identifier
	 *      (Estandar_Leads_Attribution::platform_eligibility('google')).
	 * Evaluated independently from Meta — a Meta rejection never affects OCI.
	 *
	 * @return array {allowed:bool, decision:string, reason:string,
	 *                channel:string, evidence:string, persist:bool}
	 */
	public static function dispatch_gate( $lead_id, $export_status, $submission_id = 0, $payload = array() ) {
		$lead_id = (int) $lead_id;

		$is_test = class_exists( 'Estandar_Leads_QA_Policy' ) && Estandar_Leads_QA_Policy::is_test_lead( $lead_id );
		if ( ! $is_test && ! empty( $payload ) && class_exists( 'Estandar_Leads_QA_Policy' ) ) {
			$is_test = ! empty( Estandar_Leads_QA_Policy::from_payload( $payload )['is_test_lead'] );
		}
		if ( $is_test ) {
			return array( 'allowed' => false, 'decision' => 'qa_test_lead', 'reason' => 'qa_test_lead', 'channel' => '', 'evidence' => '', 'persist' => false );
		}

		if ( class_exists( 'Estandar_Leads_QA_Policy' )
			&& Estandar_Leads_QA_Policy::ads_feedback_blocked( $lead_id, (int) $submission_id, is_array( $payload ) ? $payload : array() ) ) {
			return array( 'allowed' => false, 'decision' => 'skipped_by_policy', 'reason' => 'ads_feedback_policy:skip', 'channel' => '', 'evidence' => '', 'persist' => true );
		}

		$lead = self::get_lead( $lead_id );
		if ( ! $lead ) {
			return array( 'allowed' => false, 'decision' => 'not_eligible', 'reason' => 'lead_not_found', 'channel' => '', 'evidence' => '', 'persist' => false );
		}
		$eligibility = Estandar_Leads_Attribution::platform_eligibility( 'google', $lead, true );
		if ( empty( $eligibility['eligible'] ) ) {
			return array(
				'allowed'  => false,
				'decision' => 'not_eligible',
				'reason'   => (string) $eligibility['reason'],
				'channel'  => (string) $eligibility['channel'],
				'evidence' => (string) $eligibility['evidence'],
				'persist'  => true,
			);
		}

		return array(
			'allowed'  => true,
			'decision' => 'eligible',
			'reason'   => (string) $eligibility['reason'],
			'channel'  => (string) $eligibility['channel'],
			'evidence' => (string) $eligibility['evidence'],
			'persist'  => false,
		);
	}

	/** Persist a gate rejection as an auditable ads_dispatch_skipped event. */
	private static function record_skip( $lead_id, $export_status, array $gate ) {
		if ( empty( $gate['persist'] ) ) {
			return;
		}
		if ( class_exists( 'Estandar_Leads_Repository' ) ) {
			Estandar_Leads_Repository::insert_event( (int) $lead_id, 'ads_dispatch_skipped', array(
				'to_status' => (string) $export_status,
				'reason'    => (string) $gate['reason'],
				'metadata'  => array(
					'platform'      => 'google',
					'export_status' => (string) $export_status,
					'decision'      => (string) $gate['decision'],
					'channel'       => (string) $gate['channel'],
					'evidence'      => (string) $gate['evidence'],
					'gate'          => 'GOOGLE_GATE_V1',
				),
			) );
		}
		self::log( 'info', 'GOOGLE_DM_DISPATCH_SKIPPED', 'Google dispatch skipped by canonical gate.', array(
			'lead_id'       => (int) $lead_id,
			'export_status' => (string) $export_status,
			'decision'      => (string) $gate['decision'],
			'reason'        => (string) $gate['reason'],
			'channel'       => (string) $gate['channel'],
		) );
	}

	// ── Outbox (persistence only) ────────────────────────────────────────────────

	/**
	 * Write a Google export job to the durable outbox.
	 *
	 * Idempotent: UNIQUE KEY (lead_id, export_status) silently absorbs a
	 * duplicate insert — one lead can produce at most one "Lead Calificado"
	 * conversion, ever. No payload mapping, no HTTP: persistence only.
	 */
	private static function enqueue( $lead_id, $export_status, $changed_at, $gate = null ) {
		global $wpdb;
		// GOOGLE_GATE_V1: single canonical gate before persistence. Callers pass
		// their gate result; any other caller is gated here with the rejection
		// persisted — never a silent drop.
		if ( ! is_array( $gate ) ) {
			$gate = self::dispatch_gate( (int) $lead_id, (string) $export_status );
		}
		if ( empty( $gate['allowed'] ) ) {
			self::record_skip( (int) $lead_id, (string) $export_status, $gate );
			return;
		}

		$event_time = $changed_at !== '' ? $changed_at : gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}es_google_queue
			 (lead_id, export_status, event_time, queue_status, attempts, created_at)
			 VALUES (%d, %s, %s, 'pending', 0, %s)",
			$lead_id,
			strtoupper( trim( (string) $export_status ) ),
			$event_time,
			gmdate( 'Y-m-d H:i:s' )
		) );

		self::log( 'info', 'GOOGLE_DM_ENQUEUED', 'Google Data Manager export job queued.', array(
			'lead_id'       => (int) $lead_id,
			'export_status' => (string) $export_status,
			'duplicate'     => ! $inserted,
		) );

		if ( $inserted ) {
			self::dispatch_async();
		}
	}

	// ── Async worker ─────────────────────────────────────────────────────────────

	/** WP-Cron callback — recovery layer for whatever immediate dispatch missed. */
	public static function process_queue() {
		self::run_worker( self::BATCH_SIZE );
	}

	/**
	 * Shared worker core (cron + immediate dispatch). Config gate → test-mode
	 * hold → lock → row selection with backoff — then batches of HTTP_BATCH
	 * events per ingest request.
	 */
	private static function run_worker( $batch_size ) {
		global $wpdb;

		if ( ! self::is_enabled() || ! self::is_configured() ) {
			return;
		}

		// TEST MODE: nothing leaves the installation (not even validateOnly).
		// Observable while holding — a silent hold looks like a broken worker.
		if ( self::option( 'test_mode' ) === '1' ) {
			$held = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}es_google_queue
				 WHERE queue_status IN ('pending', 'retry', 'validated')"
			);
			if ( $held > 0 ) {
				self::log( 'warning', 'GOOGLE_DM_TEST_MODE_HOLD', 'Test mode active — queue held, nothing sent.', array(
					'held_jobs' => $held,
				) );
			}
			return;
		}

		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			$dry_run = self::dry_run_enabled();
			$now     = gmdate( 'Y-m-d H:i:s' );

			// dry_run ON  → validate pending/retry rows (validated rows rest).
			// dry_run OFF → also pick up 'validated' rows for the real send.
			$statuses = $dry_run ? "('pending','retry')" : "('pending','retry','validated')";

			$items = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}es_google_queue
				 WHERE queue_status IN {$statuses}
				   AND attempts < %d
				   AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
				 ORDER BY created_at ASC
				 LIMIT %d",
				self::MAX_ATTEMPTS,
				$now,
				max( 1, (int) $batch_size )
			) );

			foreach ( array_chunk( (array) $items, self::HTTP_BATCH ) as $chunk ) {
				self::process_batch( $chunk, $dry_run );
			}
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Process one HTTP batch: stamp attempts, build events, single ingest call,
	 * then apply one state transition to every row that traveled in it.
	 *
	 * @param object[] $items    Rows from es_google_queue.
	 * @param bool     $dry_run  true → validateOnly (success lands on 'validated').
	 */
	private static function process_batch( array $items, $dry_run ) {
		global $wpdb;

		$now    = gmdate( 'Y-m-d H:i:s' );
		$events = array();
		$sendable = array();

		foreach ( $items as $item ) {
			// Stamp the attempt first — leave queue_status untouched until a
			// terminal outcome is known (worker death ⇒ row stays selectable).
			$wpdb->update(
				$wpdb->prefix . 'es_google_queue',
				array( 'attempts' => (int) $item->attempts + 1, 'last_attempted_at' => $now ),
				array( 'id' => (int) $item->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			$item->attempts = (int) $item->attempts + 1;

			$lead = self::get_lead( (int) $item->lead_id );
			if ( ! $lead ) {
				self::transition( $item, 'dead', 'Lead not found', '' );
				continue;
			}
			$eligibility = self::platform_eligibility( $lead );
			if ( empty( $eligibility['eligible'] ) ) {
				// GOOGLE_GATE_V1: dispatch-time ineligibility is a persisted
				// decision (skipped/not_eligible), not a failure.
				self::transition( $item, 'dead', 'skipped/not_eligible: ' . (string) $eligibility['reason'], '' );
				self::record_dispatch_skip( $item, $lead, $eligibility );
				continue;
			}

			// Canonical business timestamp = the qualification instant persisted
			// on the outbox row, never rebuilt from the live lead.
			$lead->event_time = (string) $item->event_time;

			// Commercial Outcomes value enrichment (additive; null = omitted).
			$outcomes = self::outcomes();
			if ( $outcomes ) {
				$money = $outcomes->value_provider()->value_for( $lead, (string) $item->export_status );
				if ( $money !== null ) {
					$lead->conversion_value = $money->amount();
					if ( $money->has_currency() ) {
						$lead->currency = $money->currency();
					}
				}
			}

			$payload = Estandar_Leads_Google_Payload_Builder::build( $lead, (string) $item->export_status );
			if ( empty( $payload ) ) {
				// No click IDs AND no valid user data → permanently unmatchable.
				self::transition( $item, 'dead', 'Unmatchable: no click IDs and no valid user identifiers', '' );
				continue;
			}

			$events[]   = $payload;
			$sendable[] = array( 'item' => $item, 'payload' => $payload, 'lead' => $lead, 'evidence' => (string) $eligibility['evidence'] );
		}

		if ( empty( $events ) ) {
			return;
		}

		$t0     = microtime( true );
		$result = Estandar_Leads_Google_DM_Client::ingest( $events, $dry_run );
		$result['duration_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		$request_id = (string) ( $result['request_id'] ?? '' );

		foreach ( $sendable as $entry ) {
			$item = $entry['item'];

			if ( ! empty( $result['success'] ) ) {
				self::transition( $item, $dry_run ? 'validated' : 'completed', (string) $result['message'], $request_id );
			} elseif ( ! empty( $result['temporary'] ) && (int) $item->attempts < self::MAX_ATTEMPTS ) {
				self::transition( $item, 'retry', (string) $result['message'], $request_id, self::backoff( (int) $item->attempts ) );
			} else {
				self::transition( $item, 'dead', (string) $result['message'], $request_id );
			}

			// Commercial Outcomes execution log — payload holds hashed PII only.
			// OCI_OBSERVABILITY_V1 telemetry contract: every attempt persists
			// decision (eligible), mode (validateOnly|send), identifier used,
			// conversion action, API result and normalized error — the export
			// never infers success without this row.
			$outcomes = self::outcomes();
			if ( $outcomes ) {
				$outcomes->log()->record( new Estandar_Commercial_Outcome( array(
					'lead_id'    => (int) $item->lead_id,
					'project_id' => (string) ( $entry['lead']->project_slug ?? '' ),
					'stage'      => (string) $item->export_status,
					'adapter'    => 'google_dm',
					'event'      => $dry_run ? 'lead_qualified_validate' : 'lead_qualified',
					'payload'    => $entry['payload'],
					'response'   => self::sanitized_response( $result ) + array(
						'decision'          => 'eligible',
						'mode'              => $dry_run ? 'validateOnly' : 'send',
						'identifier'        => (string) ( $entry['evidence'] ?? '' ),
						'conversion_action' => (string) get_option( self::OPTION_PREFIX . 'conversion_action_id', '' ),
						'attempted_at'      => $now,
					),
					'http_code'  => (int) ( $result['http_code'] ?? 0 ),
					'status'     => self::outcome_status( $result, $dry_run ),
				) ) );
			}
		}

		self::log(
			empty( $result['success'] ) ? 'warning' : 'info',
			empty( $result['success'] ) ? 'GOOGLE_DM_UPLOAD_FAILED' : ( $dry_run ? 'GOOGLE_DM_VALIDATED' : 'GOOGLE_DM_UPLOAD_SUCCESS' ),
			empty( $result['success'] ) ? 'Google Data Manager ingest failed.' : ( $dry_run ? 'Google Data Manager validateOnly pass.' : 'Google Data Manager ingest completed.' ),
			array(
				'events'      => count( $events ),
				'http_code'   => (int) ( $result['http_code'] ?? 0 ),
				'request_id'  => $request_id,
				'duration_ms' => (int) $result['duration_ms'],
				'dry_run'     => (bool) $dry_run,
				'message'     => substr( (string) ( $result['message'] ?? '' ), 0, 200 ),
			)
		);
	}

	/**
	 * OCI_OBSERVABILITY_V1: persist a dispatch-time skip decision to the
	 * execution log (status 'skipped') + ads_dispatch_skipped lead event.
	 */
	private static function record_dispatch_skip( $item, $lead, array $eligibility ) {
		$outcomes = self::outcomes();
		if ( $outcomes ) {
			$outcomes->log()->record( new Estandar_Commercial_Outcome( array(
				'lead_id'    => (int) $item->lead_id,
				'project_id' => (string) ( $lead->project_slug ?? '' ),
				'stage'      => (string) $item->export_status,
				'adapter'    => 'google_dm',
				'event'      => 'dispatch_skipped',
				'payload'    => array(),
				'response'   => array(
					'skipped'           => true,
					'decision'          => 'not_eligible',
					'reason'            => (string) $eligibility['reason'],
					'channel'           => (string) $eligibility['channel'],
					'evidence'          => (string) $eligibility['evidence'],
					'conversion_action' => (string) get_option( self::OPTION_PREFIX . 'conversion_action_id', '' ),
				),
				'http_code'  => 0,
				'status'     => 'skipped',
			) ) );
		}
		if ( class_exists( 'Estandar_Leads_Repository' ) ) {
			Estandar_Leads_Repository::insert_event( (int) $item->lead_id, 'ads_dispatch_skipped', array(
				'to_status' => (string) $item->export_status,
				'reason'    => (string) $eligibility['reason'],
				'metadata'  => array(
					'platform'      => 'google',
					'export_status' => (string) $item->export_status,
					'decision'      => 'not_eligible',
					'channel'       => (string) $eligibility['channel'],
					'evidence'      => (string) $eligibility['evidence'],
					'gate'          => 'GOOGLE_GATE_V1',
					'stage'         => 'dispatch',
				),
			) );
		}
		self::log( 'info', 'GOOGLE_DM_DISPATCH_SKIPPED', 'Google dispatch skipped at send time (not eligible).', array(
			'queue_id'      => (int) $item->id,
			'lead_id'       => (int) $item->lead_id,
			'export_status' => (string) $item->export_status,
			'reason'        => substr( (string) $eligibility['reason'], 0, 200 ),
		) );
	}

	// ── Queue state transitions ──────────────────────────────────────────────────

	/**
	 * Single writer for outbox state. Errors are pre-sanitized by the DM client
	 * (OAuth error codes only, Google error messages truncated — no secrets,
	 * no user data) and truncated again here as defense in depth.
	 */
	private static function transition( $item, $status, $message, $request_id, $next_attempt_at = null ) {
		global $wpdb;

		$data   = array(
			'queue_status' => (string) $status,
			'last_error'   => substr( (string) $message, 0, 500 ),
		);
		$format = array( '%s', '%s' );

		if ( $request_id !== '' ) {
			$data['dm_request_id'] = (string) $request_id;
			$format[]              = '%s';
		}
		if ( $next_attempt_at !== null ) {
			$data['next_attempt_at'] = (string) $next_attempt_at;
			$format[]                = '%s';
		}

		$wpdb->update(
			$wpdb->prefix . 'es_google_queue',
			$data,
			array( 'id' => (int) $item->id ),
			$format,
			array( '%d' )
		);

		// A gate skip is a decision, not a failure — its own info log already
		// fired (record_dispatch_skip); the dead-letter ERROR is for real failures.
		if ( $status === 'dead' && strpos( (string) $message, 'skipped/not_eligible' ) !== 0 ) {
			self::log( 'error', 'GOOGLE_DM_DEAD_LETTER', 'Google export dead-lettered.', array(
				'queue_id' => (int) $item->id,
				'lead_id'  => (int) $item->lead_id,
				'attempts' => (int) $item->attempts,
				'reason'   => substr( (string) $message, 0, 200 ),
			) );
		}
	}

	/**
	 * Exponential backoff: 15m → 1h → 4h → 16h → 24h cap.
	 *
	 * @param int $attempts  Attempts already consumed (≥ 1).
	 * @return string MySQL datetime UTC of the next eligible attempt.
	 */
	public static function backoff( $attempts ) {
		$delay = min( self::BACKOFF_BASE * pow( 4, max( 0, (int) $attempts - 1 ) ), self::BACKOFF_CAP );
		return gmdate( 'Y-m-d H:i:s', time() + (int) $delay );
	}

	// ── Immediate dispatch (mirrors Meta — shutdown hook, no loopback) ───────────

	private static $shutdown_registered = false;

	private static function dispatch_async() {
		if ( self::$shutdown_registered || self::option( 'test_mode' ) === '1' ) {
			return;
		}
		self::$shutdown_registered = true;
		add_action( 'shutdown', array( __CLASS__, 'dispatch_on_shutdown' ), PHP_INT_MAX );
	}

	public static function dispatch_on_shutdown() {
		self::$shutdown_registered = false;
		if ( PHP_SAPI !== 'cli' && function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		try {
			self::run_worker( self::IMMEDIATE_BATCH );
		} catch ( \Throwable $e ) {
			self::log( 'error', 'GOOGLE_DM_IMMEDIATE_DISPATCH_FAILED', 'Immediate dispatch failed — cron will recover.', array(
				'exception' => get_class( $e ),
				'message'   => substr( (string) $e->getMessage(), 0, 200 ),
			) );
		}
	}

	// ── Worker mutex (wp_options, same semantics as Meta/WP_Upgrader) ────────────

	private static function acquire_lock() {
		if ( add_option( self::LOCK_OPTION, (string) time(), '', 'no' ) ) {
			return true;
		}
		$ts = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $ts > 0 && ( time() - $ts ) > self::LOCK_TTL ) {
			update_option( self::LOCK_OPTION, (string) time(), false );
			return true;
		}
		return false;
	}

	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	// ── Reconciliation (read-only diagnostics) ───────────────────────────────────

	/**
	 * Ask Google how the most recent real ingests were processed. Read-only:
	 * consults requestStatus:retrieve per distinct dm_request_id and returns
	 * the raw diagnostics for the admin panel. Never mutates queue state.
	 *
	 * @param int $limit  Distinct recent request_ids to check.
	 * @return array[] { request_id, http_code, success, message, body }
	 */
	public static function reconcile_recent( $limit = 3 ) {
		global $wpdb;

		$request_ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT dm_request_id FROM {$wpdb->prefix}es_google_queue
			 WHERE queue_status = 'completed' AND dm_request_id <> ''
			 ORDER BY last_attempted_at DESC
			 LIMIT %d",
			max( 1, (int) $limit )
		) );

		$out = array();
		foreach ( $request_ids as $request_id ) {
			$status = Estandar_Leads_Google_DM_Client::request_status( (string) $request_id );
			$out[]  = array(
				'request_id' => (string) $request_id,
				'http_code'  => (int) $status['http_code'],
				'success'    => (bool) $status['success'],
				'message'    => (string) $status['message'],
				'body'       => (string) $status['body'],
			);
			self::log( $status['success'] ? 'info' : 'warning', 'GOOGLE_DM_RECONCILE', 'Request status retrieved.', array(
				'request_id' => (string) $request_id,
				'http_code'  => (int) $status['http_code'],
			) );
		}
		return $out;
	}

	// ── Internal helpers ─────────────────────────────────────────────────────────

	private static function is_exportable_status( $status ) {
		$statuses = apply_filters( 'estandar_leads_google_export_statuses', self::EXPORT_STATUSES );
		return in_array( strtoupper( trim( (string) $status ) ), (array) $statuses, true );
	}

	/** Single Commercial Outcomes resolution point (same as the Meta adapter). */
	private static function outcomes() {
		static $resolved = false;
		static $locator  = null;
		if ( ! $resolved ) {
			$resolved = true;
			$locator  = class_exists( 'Estandar_Commercial_Outcomes' )
				? Estandar_Commercial_Outcomes::locator()
				: null;
		}
		return $locator;
	}

	private static function outcome_status( $result, $dry_run ) {
		if ( ! empty( $result['success'] ) ) {
			return $dry_run ? 'validated' : 'success';
		}
		return ! empty( $result['temporary'] ) ? 'retry' : 'failed';
	}

	/** Response copy safe for the outcomes log — raw body stripped to essentials. */
	private static function sanitized_response( $result ) {
		return array(
			'success'     => (bool) ( $result['success'] ?? false ),
			'temporary'   => (bool) ( $result['temporary'] ?? false ),
			'http_code'   => (int) ( $result['http_code'] ?? 0 ),
			'message'     => substr( (string) ( $result['message'] ?? '' ), 0, 300 ),
			'request_id'  => (string) ( $result['request_id'] ?? '' ),
			'duration_ms' => (int) ( $result['duration_ms'] ?? 0 ),
		);
	}

	private static function get_lead( $lead_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
			(int) $lead_id
		) );
	}

	private static function platform_eligibility( $lead ) {
		$lead_id = (int) ( is_object( $lead ) ? ( $lead->id ?? 0 ) : ( $lead['id'] ?? 0 ) );
		$commercial_allowed = ! ( $lead_id > 0 && class_exists( 'Estandar_Leads_QA_Policy' )
			&& Estandar_Leads_QA_Policy::ads_feedback_blocked( $lead_id ) );
		return Estandar_Leads_Attribution::platform_eligibility( 'google', $lead, $commercial_allowed );
	}

	/**
	 * Master switch. DEFAULT OFF: a never-saved option ('') is disabled. This
	 * deliberately inverts the Meta backward-compat rule — there is no legacy
	 * Google install to protect, and an exporter must never turn itself on.
	 */
	public static function is_enabled() {
		return self::option( 'enabled' ) === '1';
	}

	/** Dry-run (validateOnly). DEFAULT ON: only an explicit '0' sends real conversions. */
	public static function dry_run_enabled() {
		return self::option( 'dry_run' ) !== '0';
	}

	/** Presence-only gate — no token exchange happens here. */
	public static function is_configured() {
		return self::option( 'customer_id' ) !== ''
			&& self::option( 'conversion_action_id' ) !== ''
			&& self::option( 'oauth_client_id' ) !== ''
			&& self::option( 'oauth_client_secret' ) !== ''
			&& self::option( 'oauth_refresh_token' ) !== '';
	}

	private static function option( $key ) {
		return (string) get_option( self::OPTION_PREFIX . $key, '' );
	}

	// ── Settings (POST contract: google_* field names) ───────────────────────────

	/** Toggle fields persisted as explicit '1'/'0' (checkbox semantics). */
	const TOGGLE_FIELDS = array( 'enabled', 'dry_run', 'test_mode', 'debug_logging' );

	public static function save_settings( $post ) {
		// ID fields: normalized to digits (CID "107-149-8042" → "1071498042").
		foreach ( array( 'customer_id', 'login_customer_id', 'conversion_action_id' ) as $field ) {
			if ( array_key_exists( 'google_' . $field, $post ) ) {
				$value = preg_replace( '/\D+/', '', sanitize_text_field( wp_unslash( $post[ 'google_' . $field ] ) ) );
				update_option( self::OPTION_PREFIX . $field, $value, false );
			}
		}

		if ( array_key_exists( 'google_oauth_client_id', $post ) ) {
			update_option(
				self::OPTION_PREFIX . 'oauth_client_id',
				sanitize_text_field( wp_unslash( $post['google_oauth_client_id'] ) ),
				false
			);
		}

		// Secrets (keep-if-empty): an empty field NEVER wipes a stored secret.
		// Secrets are never rendered back to the DOM; paste a new value to rotate.
		foreach ( array( 'oauth_client_secret', 'oauth_refresh_token' ) as $field ) {
			if ( array_key_exists( 'google_' . $field, $post ) ) {
				$secret = sanitize_text_field( wp_unslash( $post[ 'google_' . $field ] ) );
				if ( $secret !== '' ) {
					update_option( self::OPTION_PREFIX . $field, $secret, false );
				}
			}
		}

		foreach ( self::TOGGLE_FIELDS as $field ) {
			$raw = sanitize_text_field( wp_unslash( $post[ 'google_' . $field ] ?? '' ) );
			update_option( self::OPTION_PREFIX . $field, $raw === '1' ? '1' : '0', false );
		}
	}

	public static function get_settings() {
		return array(
			'customer_id'          => self::option( 'customer_id' ),
			'login_customer_id'    => self::option( 'login_customer_id' ),
			'conversion_action_id' => self::option( 'conversion_action_id' ),
			'oauth_client_id'      => self::option( 'oauth_client_id' ),
			'oauth_client_secret'  => self::option( 'oauth_client_secret' ),
			'oauth_refresh_token'  => self::option( 'oauth_refresh_token' ),
			'enabled'              => self::option( 'enabled' ),
			'dry_run'              => self::option( 'dry_run' ),
			'test_mode'            => self::option( 'test_mode' ),
			'debug_logging'        => self::option( 'debug_logging' ),
		);
	}

	// ── Diagnostics (read-only, no PII, no secrets) ──────────────────────────────

	public static function queue_stats() {
		global $wpdb;
		$table = $wpdb->prefix . 'es_google_queue';

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array( 'counts' => array(), 'last_attempt' => null, 'last_error' => null );
		}

		$counts = array();
		foreach ( (array) $wpdb->get_results( "SELECT queue_status, COUNT(*) AS c FROM {$table} GROUP BY queue_status" ) as $row ) {
			$counts[ (string) $row->queue_status ] = (int) $row->c;
		}

		$last = $wpdb->get_row(
			"SELECT last_attempted_at, last_error, queue_status
			 FROM {$table}
			 WHERE last_attempted_at IS NOT NULL
			 ORDER BY last_attempted_at DESC LIMIT 1"
		);

		return array(
			'counts'       => $counts,
			'last_attempt' => $last ? (string) $last->last_attempted_at : null,
			'last_error'   => ( $last && ! in_array( $last->queue_status, array( 'completed', 'validated' ), true ) && $last->last_error )
				? substr( (string) $last->last_error, 0, 200 ) : null,
		);
	}

	/**
	 * Recent outbox rows for the admin log viewer. Reads the outbox directly
	 * (identity + state only — never payloads, never lead PII).
	 *
	 * @return array[] { created_at, lead_uuid, transaction_id, export_status,
	 *                   queue_status, attempts, next_attempt_at, dm_request_id, last_error }
	 */
	public static function recent_log_rows( $limit = 20 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'es_google_queue';

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT q.*, l.lead_uuid
			 FROM {$table} q
			 LEFT JOIN {$wpdb->prefix}es_leads l ON l.id = q.lead_id
			 ORDER BY q.id DESC
			 LIMIT %d",
			max( 1, (int) $limit )
		) );

		$out = array();
		foreach ( $rows as $row ) {
			$lead_uuid = trim( (string) ( $row->lead_uuid ?? '' ) );
			if ( $lead_uuid === '' ) {
				$lead_uuid = 'lead-' . (int) $row->lead_id;
			}
			$out[] = array(
				'created_at'      => (string) $row->created_at,
				'lead_uuid'       => $lead_uuid,
				'transaction_id'  => $lead_uuid . ':' . strtoupper( (string) $row->export_status ),
				'export_status'   => (string) $row->export_status,
				'queue_status'    => (string) $row->queue_status,
				'attempts'        => (int) $row->attempts,
				'next_attempt_at' => (string) ( $row->next_attempt_at ?? '' ),
				'dm_request_id'   => (string) ( $row->dm_request_id ?? '' ),
				'last_error'      => substr( (string) ( $row->last_error ?? '' ), 0, 200 ),
			);
		}
		return $out;
	}

	/**
	 * External-configuration checklist for the BLOCKED banner in the panel.
	 *
	 * @return string[] Human labels of every missing external prerequisite.
	 */
	public static function missing_external_config() {
		$missing = array();
		if ( self::option( 'conversion_action_id' ) === '' ) {
			$missing[] = 'Conversion action "Lead Calificado" en Google Ads (fuente: Sitio web — Importar desde clics) y su ID';
		}
		if ( self::option( 'oauth_client_id' ) === '' || self::option( 'oauth_client_secret' ) === '' ) {
			$missing[] = 'OAuth Client (ID + Secret) en Google Cloud Console con la Data Manager API habilitada';
		}
		if ( self::option( 'oauth_refresh_token' ) === '' ) {
			$missing[] = 'Refresh token con scope https://www.googleapis.com/auth/datamanager (consentimiento del usuario dueño del acceso a la cuenta Ads)';
		}
		if ( self::option( 'customer_id' ) === '' ) {
			$missing[] = 'Customer ID (CID) de la cuenta Google Ads';
		}
		return $missing;
	}

	/** Telemetry — never breaks business flow. */
	private static function log( $severity, $code, $message, $context = array() ) {
		try {
			do_action( 'estandar_telemetry_event', array(
				'source_plugin' => 'estandar-leads',
				'type'          => 'business_event',
				'severity'      => $severity,
				'code'          => $code,
				'message'       => $message,
				'context'       => is_array( $context ) ? $context : array(),
			) );
		} catch ( \Throwable $e ) {
			// Telemetry must never break business flow.
		}
	}
}
