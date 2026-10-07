<?php
/**
 * Meta Ads adapter (skeleton) for Estandar Leads.
 *
 * Mirrors the architectural pattern of Estandar_Leads_Ads_OCI:
 *   estandar_lead_status_changed (existing Business Event)
 *     → on_status_changed()  exportability + config gate
 *     → enqueue()            writes to es_meta_queue (persistent, idempotent)
 *     → process_queue()      WP-Cron worker: batch → Payload Builder → Graph Client
 *
 * Layer boundaries (do not blur):
 *   Adapter        = subscriber + queue + worker + gates (this class)
 *   Payload Builder = pure data shaping (Estandar_Leads_Meta_Payload_Builder)
 *   Graph Client   = transport only (Estandar_Leads_Meta_Graph_Client)
 *
 * wp_options (prefix: estandar_leads_meta_):
 *   pixel_id         Meta Pixel / Dataset ID
 *   access_token     Meta Conversions API access token
 *   test_event_code  Optional — routes real sends to Events Manager → Test Events
 *   test_mode        '1' = enqueue + log, never send (queue stays pending)
 *   enabled          Master switch. '' (pre-existing installs) counts as enabled.
 *   server_events    CAPI on/off. '' counts as enabled (backward compatible).
 *   browser_events   Browser Pixel on/off. Default OFF (snippet coexistence).
 *   debug_logging    '1' = per-send telemetry (event_id/http/duration — no PII)
 *
 * @package EstandarLeads
 * @since   10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Meta_Adapter {

	const OPTION_PREFIX = 'estandar_leads_meta_';
	const CRON_HOOK     = 'estandar_leads_meta_process_queue';
	const CRON_INTERVAL = 'hourly';
	const MAX_ATTEMPTS  = 3;
	const RETRY_AFTER   = 3600; // seconds — minimum gap between retry attempts
	const BATCH_SIZE    = 20;

	// Immediate dispatch (in-process, cron-independent) + worker mutex.
	const IMMEDIATE_BATCH = 5;   // cap por ejecución inmediata (el cron drena el resto)
	const LOCK_OPTION     = 'estandar_leads_meta_worker_lock';
	const LOCK_TTL        = 120; // seconds — un lock más viejo es de un worker muerto

	/**
	 * Lead statuses that qualify for a Meta export.
	 *
	 * Skeleton placeholder: refinement of the Meta-specific whitelist
	 * (OCI_EXPORT_WHITELIST reconciliation) is deferred to a later phase.
	 * This is domain status data, not Google-specific logic.
	 */
	const EXPORT_STATUSES = array(
		'QUALIFIED',
		'MEETING_BOOKED',
		'CONVERTED',
		'VISIT_SCHEDULED',
		'VISITED',
		'RESERVED',
		'CLIENT',
		'CLIENT_SIGNED',
		'DEED_SIGNED',
		'DELIVERED',
	);

	// ── Bootstrap ──────────────────────────────────────────────────────────────

	/**
	 * Estado sintético de captura. NO es un estado comercial (nunca entra a
	 * EXPORT_STATUSES ni al stages registry): representa "lead persistido".
	 * Contrato de eventos:
	 *   CAPTURED  → event_name 'Lead'          · event_id <uuid>:CAPTURED
	 *               (Browser y Server emiten el MISMO hecho → Meta dedup real)
	 *   QUALIFIED → event_name 'QualifiedLead' · event_id <uuid>:QUALIFIED
	 *               (hecho comercial separado — jamás dedup contra la captura)
	 */
	const CAPTURE_STATUS = 'CAPTURED';

	public static function init() {
		// Reuse the existing Business Event transport — no new hook is introduced.
		add_action( 'estandar_lead_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );

		// Server Lead de captura: SOLO desde el evento canónico post-persistencia
		// (estandar_lead_ingested se dispara tras COMMIT; los rollbacks retornan
		// antes). Nunca desde estandar_lead_captured (pre-persistencia) ni desde
		// páginas de agradecimiento.
		add_action( 'estandar_lead_ingested', array( __CLASS__, 'on_lead_ingested' ), 10, 4 );

		// Async worker — registered exactly as OCI does (WP-Cron, recurring).
		// Con el dispatch inmediato activo, el cron es la capa de RECOVERY.
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	// ── Event subscriber ───────────────────────────────────────────────────────

	/**
	 * Called when a lead status changes. Enqueues a Meta job if it qualifies.
	 *
	 * Payload contract is identical to the existing event (do not modify):
	 *   estandar_lead_status_changed( $lead_id, $old_status, $new_status, $changed_at )
	 *
	 * @param int    $lead_id
	 * @param string $old_status
	 * @param string $new_status
	 * @param string $changed_at  MySQL datetime UTC
	 */
	public static function on_status_changed( $lead_id, $old_status, $new_status, $changed_at ) {
		if ( ! self::is_exportable_status( $new_status ) ) {
			return;
		}
		if ( ! self::server_events_enabled() || ! self::is_configured() ) {
			return;
		}
		// META_GATE_V1: single canonical gate (QA + ads_feedback policy +
		// canonical Meta channel + valid Meta evidence) before any enqueue.
		$gate = self::dispatch_gate( (int) $lead_id, (string) $new_status );
		if ( empty( $gate['allowed'] ) ) {
			self::record_skip( (int) $lead_id, (string) $new_status, $gate );
			return;
		}
		self::enqueue( (int) $lead_id, (string) $new_status, (string) $changed_at, $gate );
	}

	/**
	 * Lead persistido correctamente → encolar el Lead de captura del servidor.
	 *
	 * Idempotente por diseño: UNIQUE (lead_id, export_status) + INSERT IGNORE —
	 * doble submit, reintento del navegador o recontacto del mismo lead NUNCA
	 * generan un segundo Lead Server. Reusa cola/worker/retries existentes.
	 */
	public static function on_lead_ingested( $lead_id, $submission_id, $is_new, $payload ) {
		if ( ! self::server_events_enabled() || ! self::is_configured() ) {
			return;
		}
		// META_GATE_V1: single canonical gate. The payload is passed so the
		// policy decision is derived from it directly — this hook runs
		// (priority 10) before the qualification engine persists (priority 15),
		// and decision_for() evaluates the same payload, so a review or
		// disqualified capture is suppressed without any hook-ordering change.
		$gate = self::dispatch_gate( (int) $lead_id, self::CAPTURE_STATUS, (int) $submission_id, is_array( $payload ) ? $payload : array() );
		if ( empty( $gate['allowed'] ) ) {
			self::record_skip( (int) $lead_id, self::CAPTURE_STATUS, $gate );
			return;
		}
		self::enqueue( (int) $lead_id, self::CAPTURE_STATUS, gmdate( 'Y-m-d H:i:s' ), $gate );
	}

	// ── META_GATE_V1 — single canonical dispatch gate ────────────────────────────

	/**
	 * The ONE decision point before any Meta enqueue/dispatch. Composes, in
	 * order, the already-canonical sources (no rule is re-implemented here):
	 *   1. QA/test protection + ads_feedback_policy
	 *      (Estandar_Leads_QA_Policy::ads_feedback_blocked — SSOT)
	 *   2. canonical Meta acquisition channel + valid Meta evidence
	 *      (Estandar_Leads_Attribution::platform_eligibility('meta') — SSOT:
	 *       requires channel=meta_ads AND fbclid/fbc; _fbp alone NEVER
	 *       qualifies; a Google acquisition can never produce Meta events).
	 *
	 * @return array {allowed:bool, decision:string, reason:string,
	 *                channel:string, evidence:string, persist:bool}
	 */
	public static function dispatch_gate( $lead_id, $export_status, $submission_id = 0, $payload = array() ) {
		$lead_id = (int) $lead_id;

		// QA/test lead — suppressed silently (test traffic leaves no decision
		// trace); a real lead blocked by commercial policy IS persisted below.
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
		$eligibility = Estandar_Leads_Attribution::platform_eligibility( 'meta', $lead, true );
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

	/**
	 * Persist a gate rejection as an auditable decision trace
	 * (`ads_dispatch_skipped` lead event) — skipped/not_eligible with the
	 * exact reason. Historical traces are never rewritten: each capture or
	 * status transition that is rejected leaves its own decision row.
	 */
	private static function record_skip( $lead_id, $export_status, array $gate ) {
		if ( empty( $gate['persist'] ) ) {
			return;
		}
		if ( class_exists( 'Estandar_Leads_Repository' ) ) {
			Estandar_Leads_Repository::insert_event( (int) $lead_id, 'ads_dispatch_skipped', array(
				'to_status' => (string) $export_status,
				'reason'    => (string) $gate['reason'],
				'metadata'  => array(
					'platform'      => 'meta',
					'export_status' => (string) $export_status,
					'decision'      => (string) $gate['decision'],
					'channel'       => (string) $gate['channel'],
					'evidence'      => (string) $gate['evidence'],
					'gate'          => 'META_GATE_V1',
				),
			) );
		}
		self::log( 'info', 'META_DISPATCH_SKIPPED', 'Meta dispatch skipped by canonical gate.', array(
			'lead_id'       => (int) $lead_id,
			'export_status' => (string) $export_status,
			'decision'      => (string) $gate['decision'],
			'reason'        => (string) $gate['reason'],
			'channel'       => (string) $gate['channel'],
		) );
	}

	/**
	 * event_id canónico del hecho "captura" — única derivación compartida con
	 * la respuesta AJAX de forms (Browser recibe este mismo valor).
	 */
	public static function capture_event_id( $lead_uuid ) {
		return trim( (string) $lead_uuid ) . ':' . self::CAPTURE_STATUS;
	}

	// ── Queue (persistence only) ─────────────────────────────────────────────────

	/**
	 * Write a Meta export job to the queue.
	 *
	 * Idempotent: UNIQUE KEY (lead_id, export_status) silently absorbs a
	 * duplicate insert — mirrors the OCI queue contract. No payload mapping,
	 * no HTTP, no worker: persistence only.
	 *
	 * @param int    $lead_id
	 * @param string $export_status
	 * @param string $changed_at  MySQL datetime UTC
	 */
	private static function enqueue( $lead_id, $export_status, $changed_at, $gate = null ) {
		global $wpdb;
		// META_GATE_V1: the single canonical gate guards every enqueue. Callers
		// that already ran dispatch_gate() pass their result; any other caller
		// is gated here — a rejection is persisted, never a silent drop.
		if ( ! is_array( $gate ) ) {
			$gate = self::dispatch_gate( (int) $lead_id, (string) $export_status );
		}
		if ( empty( $gate['allowed'] ) ) {
			self::record_skip( (int) $lead_id, (string) $export_status, $gate );
			return;
		}

		$event_time = $changed_at !== '' ? $changed_at : gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}es_meta_queue
			 (lead_id, export_status, event_time, queue_status, attempts, created_at)
			 VALUES (%d, %s, %s, 'pending', 0, %s)",
			$lead_id,
			$export_status,
			$event_time,
			gmdate( 'Y-m-d H:i:s' )
		) );

		self::log( 'info', 'META_ENQUEUED', 'Meta export job queued.', array(
			'lead_id'       => $lead_id,
			'export_status' => $export_status,
		) );

		// Intento inmediato solo cuando hubo fila nueva: el duplicado absorbido
		// por UNIQUE ya tiene (o tuvo) su propio dispatch.
		if ( $inserted ) {
			self::dispatch_async();
		}
	}

	// ── Async worker ─────────────────────────────────────────────────────────────

	/**
	 * WP-Cron callback. Capa de RECOVERY: recoge lo que el dispatch inmediato
	 * no procesó (loopback bloqueado, worker caído, retries con gap horario).
	 */
	public static function process_queue() {
		self::run_worker( self::BATCH_SIZE );
	}

	/**
	 * Núcleo compartido del worker — única implementación para ambos entry
	 * points (cron y dispatch inmediato). Config gate → test-mode hold →
	 * lock anti-concurrencia → batch acotado → per-item.
	 *
	 * @param int $batch_size  Cap de items por ejecución.
	 */
	private static function run_worker( $batch_size ) {
		global $wpdb;

		if ( ! self::server_events_enabled() || ! self::is_configured() ) {
			return; // Inactive unless enabled + configured — exit immediately.
		}

		// TEST MODE: jobs accumulate as 'pending' and are delivered when the
		// flag is cleared — nothing leaves the installation while it is on.
		// The hold must be observable whenever it is actually holding jobs:
		// gated behind debug_logging, a held queue is indistinguishable from
		// a broken worker (pending forever, attempts=0, no telemetry).
		if ( self::option( 'test_mode' ) === '1' ) {
			$held = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}es_meta_queue
				 WHERE queue_status IN ('pending', 'retry')"
			);
			if ( $held > 0 ) {
				self::log( 'warning', 'META_TEST_MODE_HOLD', 'Test mode active — queue held, nothing sent.', array(
					'held_jobs' => $held,
				) );
			}
			return;
		}

		// Mutex: dispatch inmediato y cron pueden coincidir; solo uno procesa.
		// El que no obtiene el lock sale sin tocar nada — las filas siguen
		// 'pending'/'retry' y el cron las recupera (cero doble envío).
		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			$retry_threshold = gmdate( 'Y-m-d H:i:s', time() - self::RETRY_AFTER );

			$items = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}es_meta_queue
				 WHERE queue_status IN ('pending', 'retry')
				   AND attempts < %d
				   AND (last_attempted_at IS NULL OR last_attempted_at <= %s)
				 ORDER BY created_at ASC
				 LIMIT %d",
				self::MAX_ATTEMPTS,
				$retry_threshold,
				max( 1, (int) $batch_size )
			) );

			foreach ( (array) $items as $item ) {
				self::process_item( $item );
			}
		} finally {
			self::release_lock();
		}
	}

	// ── Immediate dispatch (in-process, cron-independent) ────────────────────────

	/** Un solo registro de shutdown por request, vengan cuantos enqueues vengan. */
	private static $shutdown_registered = false;

	/**
	 * Despacho inmediato sin red: difiere el worker al hook 'shutdown' del
	 * MISMO request que persistió el lead — mecanismo nativo de WP, sin
	 * loopback, endpoints ni schedules. Si el request muere antes del
	 * shutdown o el worker falla, la fila queda 'pending' y el cron horario
	 * la recupera — retries y transiciones no cambian.
	 */
	private static function dispatch_async() {
		if ( self::$shutdown_registered ) {
			return;
		}
		if ( self::option( 'test_mode' ) === '1' ) {
			return; // cola retenida — no hay nada que despachar
		}
		self::$shutdown_registered = true;
		// PHP_INT_MAX: correr al FINAL del shutdown — fastcgi_finish_request
		// cierra la respuesta, y nada registrado después debe emitir output.
		add_action( 'shutdown', array( __CLASS__, 'dispatch_on_shutdown' ), PHP_INT_MAX );
	}

	/**
	 * Callback de 'shutdown'. En FPM la respuesta viaja al visitante ANTES de
	 * tocar red (fastcgi_finish_request); en otros SAPI el envío corre al
	 * final del request. El lock de run_worker() serializa contra el cron.
	 */
	public static function dispatch_on_shutdown() {
		self::$shutdown_registered = false;
		if ( PHP_SAPI !== 'cli' && function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request(); // el visitante no espera el envío a Meta
		}
		try {
			self::run_worker( self::IMMEDIATE_BATCH );
		} catch ( \Throwable $e ) {
			// El intento inmediato JAMÁS propaga al shutdown del sitio. La fila
			// queda 'pending' (el lock se liberó en el finally del worker) y el
			// cron la recupera. Telemetría sin PII ni payload.
			self::log( 'error', 'META_IMMEDIATE_DISPATCH_FAILED', 'Immediate dispatch failed — cron will recover.', array(
				'exception' => get_class( $e ),
				'message'   => substr( (string) $e->getMessage(), 0, 200 ),
			) );
		}
	}

	// ── Worker mutex (wp_options, sin schema nuevo) ─────────────────────────────

	/**
	 * Lock atómico vía UNIQUE(option_name): add_option solo puede ganar una
	 * vez. Un lock más viejo que LOCK_TTL es de un worker muerto y se toma
	 * (misma semántica que WP_Upgrader::create_lock).
	 */
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

	/**
	 * Process a single queue item. Increments attempts, marks the row in-progress,
	 * delegates delivery to send(), then applies the terminal/retry transition.
	 *
	 * @param object $item  Row from es_meta_queue.
	 */
	private static function process_item( $item ) {
		global $wpdb;

		$now          = gmdate( 'Y-m-d H:i:s' );
		$new_attempts = (int) $item->attempts + 1;

		// Increment attempts and stamp the attempt time — but leave queue_status as
		// 'pending'/'retry' until a terminal outcome is known (mirrors OCI). This
		// keeps the row re-selectable if the worker dies mid-flight: no orphans.
		$wpdb->update(
			$wpdb->prefix . 'es_meta_queue',
			array(
				'attempts'          => $new_attempts,
				'last_attempted_at' => $now,
			),
			array( 'id' => (int) $item->id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		// Platform-agnostic delivery boundary.
		$response = self::send( $item );

		if ( ! empty( $response['success'] ) ) {
			self::mark_completed( $item, $response );
			return;
		}

		// META_GATE_V1: a gate rejection at dispatch time is a decision, not a
		// failure — terminal 'skipped/not_eligible' with the reason persisted.
		if ( ! empty( $response['skipped'] ) ) {
			self::mark_skipped( $item, $response );
			return;
		}

		$temporary = ! empty( $response['temporary'] );
		if ( $temporary && $new_attempts < self::MAX_ATTEMPTS ) {
			self::mark_retry( $item, $new_attempts, $response );
			return;
		}

		// Permanent failure, or retries exhausted → dead letter.
		self::mark_dead( $item, $new_attempts, $response );
	}

	// ── Queue state transitions ──────────────────────────────────────────────────

	private static function mark_completed( $item, $response ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'es_meta_queue',
			array(
				'queue_status' => 'completed',
				'last_error'   => substr( (string) ( $response['message'] ?? '' ), 0, 500 ),
			),
			array( 'id' => (int) $item->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		self::log( 'info', 'META_UPLOAD_SUCCESS', 'Meta export completed.', array(
			'queue_id'      => (int) $item->id,
			'lead_id'       => (int) $item->lead_id,
			'export_status' => (string) $item->export_status,
		) );
	}

	private static function mark_retry( $item, $attempts, $response ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'es_meta_queue',
			array(
				'queue_status' => 'retry',
				'last_error'   => substr( (string) ( $response['message'] ?? 'temporary failure' ), 0, 500 ),
			),
			array( 'id' => (int) $item->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		self::log( 'warning', 'META_UPLOAD_RETRY', 'Meta export deferred for retry.', array(
			'queue_id'      => (int) $item->id,
			'lead_id'       => (int) $item->lead_id,
			'export_status' => (string) $item->export_status,
			'attempts'      => (int) $attempts,
		) );
	}

	/**
	 * Terminal skip: the row leaves the active queue (enum has no 'skipped'
	 * value — schema untouched) but its last_error is prefixed
	 * 'skipped/not_eligible:' so observability renders NOT_ELIGIBLE, never
	 * FAILED. The decision is also persisted as an ads_dispatch_skipped event.
	 */
	private static function mark_skipped( $item, $response ) {
		global $wpdb;
		$reason = (string) ( $response['message'] ?? 'not eligible' );
		$wpdb->update(
			$wpdb->prefix . 'es_meta_queue',
			array(
				'queue_status' => 'dead',
				'last_error'   => substr( 'skipped/not_eligible: ' . $reason, 0, 500 ),
			),
			array( 'id' => (int) $item->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( class_exists( 'Estandar_Leads_Repository' ) ) {
			Estandar_Leads_Repository::insert_event( (int) $item->lead_id, 'ads_dispatch_skipped', array(
				'to_status' => (string) $item->export_status,
				'reason'    => $reason,
				'metadata'  => array(
					'platform'      => 'meta',
					'export_status' => (string) $item->export_status,
					'decision'      => 'not_eligible',
					'gate'          => 'META_GATE_V1',
					'stage'         => 'dispatch',
				),
			) );
		}

		self::log( 'info', 'META_DISPATCH_SKIPPED', 'Meta dispatch skipped at send time (not eligible).', array(
			'queue_id'      => (int) $item->id,
			'lead_id'       => (int) $item->lead_id,
			'export_status' => (string) $item->export_status,
			'reason'        => substr( $reason, 0, 200 ),
		) );
	}

	private static function mark_dead( $item, $attempts, $response ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'es_meta_queue',
			array(
				'queue_status' => 'dead',
				'last_error'   => substr( (string) ( $response['message'] ?? 'permanent failure' ), 0, 500 ),
			),
			array( 'id' => (int) $item->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		self::log( 'error', 'META_DEAD_LETTER', 'Meta export dead-lettered.', array(
			'queue_id'      => (int) $item->id,
			'lead_id'       => (int) $item->lead_id,
			'export_status' => (string) $item->export_status,
			'attempts'      => (int) $attempts,
		) );
	}

	/**
	 * Delivery boundary. Wires the already-built pieces:
	 *   load lead → Payload Builder → Graph Client.
	 * No HTTP, payload or business logic lives here — it only connects them.
	 * Returns the Graph Client's normalized response (the shape process_item expects).
	 *
	 * @param object $item  Row from es_meta_queue.
	 * @return array { success, temporary, configured, http_code, message, body }
	 */
	private static function send( $item ) {
		$lead = self::get_lead( (int) $item->lead_id );
		if ( ! $lead ) {
			// Lead no longer exists — terminal, not retryable.
			return array(
				'success'    => false,
				'temporary'  => false,
				'configured' => true,
				'http_code'  => 0,
				'message'    => 'Lead not found',
				'body'       => '',
			);
		}
		$eligibility = self::platform_eligibility( $lead );
		if ( empty( $eligibility['eligible'] ) ) {
			$response = self::not_eligible_response( (string) $eligibility['reason'] );
			// META_GATE_V1: dispatch-time ineligibility is an auditable skip —
			// persist the decision to the execution log (status 'skipped'),
			// never a silent drop nor a fake failure.
			$outcomes = self::outcomes();
			if ( $outcomes ) {
				$outcomes->log()->record( new Estandar_Commercial_Outcome( array(
					'lead_id'    => (int) $item->lead_id,
					'project_id' => (string) ( $lead->project_slug ?? '' ),
					'stage'      => (string) $item->export_status,
					'adapter'    => 'meta',
					'event'      => 'dispatch_skipped',
					'payload'    => array(),
					'response'   => array(
						'skipped'  => true,
						'reason'   => (string) $eligibility['reason'],
						'channel'  => (string) $eligibility['channel'],
						'evidence' => (string) $eligibility['evidence'],
					),
					'http_code'  => 0,
					'status'     => 'skipped',
				) ) );
			}
			return $response;
		}

		// P2: use the canonical business timestamp persisted on the queue row, not
		// one rebuilt from the live lead. The builder reads 'event_time' first, so
		// injecting it here needs no Payload Builder API change.
		$lead->event_time = (string) $item->event_time;

		$status  = (string) $item->export_status;

		// Commercial Outcomes: enrich with the configured commercial value (Money).
		// Additive — null when unconfigured, so the builder omits conversionValue
		// exactly as before. The Payload Builder API is unchanged.
		$outcomes = self::outcomes();
		if ( $outcomes ) {
			$money = $outcomes->value_provider()->value_for( $lead, $status );
			if ( $money !== null ) {
				$lead->conversion_value = $money->amount();
				if ( $money->has_currency() ) {
					$lead->currency = $money->currency();
				}
			}
		}

		$payload = Estandar_Leads_Meta_Payload_Builder::build( $lead, $status );

		if ( empty( $payload ) ) {
			// Status maps to no Meta event — a clean, terminal non-error.
			return array(
				'success'    => true,
				'temporary'  => false,
				'configured' => true,
				'http_code'  => 0,
				'message'    => 'Status not exportable',
				'body'       => '',
			);
		}

		// Deterministic event_id: lead identity + status. Same lead+status → same
		// id; different status → different id. No uniqid()/rand()/time().
		$event_id = self::event_id( $lead, $status );

		$t0     = microtime( true );
		$result = Estandar_Leads_Meta_Graph_Client::send( $payload, $event_id );
		// Additive observability field — rides into the outcome log's response_json.
		$result['duration_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( self::option( 'debug_logging' ) === '1' ) {
			self::log(
				empty( $result['success'] ) ? 'warning' : 'info',
				'META_DEBUG_SEND',
				'Meta send (debug logging).',
				array(
					'lead_id'     => (int) $item->lead_id,
					'event_id'    => $event_id,
					'event_name'  => (string) ( $payload['event_name'] ?? '' ),
					'http_code'   => (int) ( $result['http_code'] ?? 0 ),
					'duration_ms' => (int) $result['duration_ms'],
					'temporary'   => ! empty( $result['temporary'] ),
					'message'     => substr( (string) ( $result['message'] ?? '' ), 0, 200 ),
				)
			);
		}

		// Commercial Outcomes: persist the dispatch to its dedicated execution log.
		if ( $outcomes ) {
			$outcomes->log()->record( new Estandar_Commercial_Outcome( array(
				'lead_id'    => (int) $item->lead_id,
				'project_id' => (string) ( $lead->project_slug ?? '' ),
				'stage'      => $status,
				'adapter'    => 'meta',
				'event'      => (string) ( $payload['event_name'] ?? '' ),
				'payload'    => $payload,
				'response'   => $result,
				'http_code'  => (int) ( $result['http_code'] ?? 0 ),
				'status'     => self::outcome_status( $result ),
			) ) );
		}

		return $result;
	}

	/**
	 * Conversion Backfill V1 delivery boundary.
	 *
	 * Reuses the existing Meta payload builder and Graph client through this
	 * adapter. No queue mutation, retry policy, matching, or admin behavior.
	 */
	public static function dispatch_backfill_purchase( $lead, $event_id ) {
		$eligibility = self::platform_eligibility( $lead );
		if ( empty( $eligibility['eligible'] ) ) {
			return array(
				'payload'  => array(),
				'response' => self::not_eligible_response( (string) $eligibility['reason'] ),
			);
		}
		$payload = Estandar_Leads_Meta_Payload_Builder::build( $lead, 'CONVERTED' );
		if ( empty( $payload ) ) {
			return array(
				'payload'  => array(),
				'response' => array(
					'success'    => false,
					'temporary'  => false,
					'configured' => true,
					'http_code'  => 0,
					'message'    => 'Purchase payload not generated',
					'body'       => '',
				),
			);
		}

		return array(
			'payload'  => $payload,
			'response' => Estandar_Leads_Meta_Graph_Client::send( $payload, (string) $event_id ),
		);
	}

	/**
	 * Deterministic Meta event identifier from existing lead identity + status.
	 */
	private static function event_id( $lead, $status ) {
		$lead_uuid = trim( (string) ( $lead->lead_uuid ?? '' ) );
		if ( $lead_uuid === '' ) {
			$lead_uuid = 'lead-' . (int) ( $lead->id ?? 0 );
		}
		return $lead_uuid . ':' . strtoupper( trim( (string) $status ) );
	}

	/**
	 * Read-only loader for the full lead row the Payload Builder consumes.
	 * Self-contained private helper, mirroring the OCI adapter convention
	 * (no shared service exposes a full attribution-bearing lead row).
	 *
	 * @param int $lead_id
	 * @return object|null
	 */
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
		return Estandar_Leads_Attribution::platform_eligibility( 'meta', $lead, $commercial_allowed );
	}

	private static function not_eligible_response( $reason ) {
		return array(
			'success'    => false,
			'temporary'  => false,
			'configured' => true,
			'http_code'  => 0,
			'message'    => 'Meta not eligible: ' . (string) $reason,
			'body'       => '',
			'skipped'    => true,
		);
	}

	// ── Internal helpers ─────────────────────────────────────────────────────────

	private static function is_exportable_status( $status ) {
		// Commercial Outcomes: defer to the configurable stage registry when the
		// domain module is present. Its defaults equal EXPORT_STATUSES, so an
		// unconfigured install behaves identically; the const is the standalone fallback.
		$outcomes = self::outcomes();
		if ( $outcomes ) {
			return $outcomes->stages()->is_exportable( $status );
		}
		return in_array( (string) $status, self::EXPORT_STATUSES, true );
	}

	/**
	 * Single resolution point for the Commercial Outcomes domain — the only
	 * class_exists() in this adapter. One decision, many consumers: returns the
	 * locator when the domain module is loaded, else null (Meta runs standalone
	 * with its EXPORT_STATUSES const and no value/log enrichment — behavior
	 * unchanged, which keeps the standalone Meta test suite green).
	 *
	 * @return Estandar_Commercial_Outcomes|null
	 */
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

	private static function outcome_status( $result ) {
		if ( ! empty( $result['success'] ) ) {
			return 'success';
		}
		return ! empty( $result['temporary'] ) ? 'retry' : 'failed';
	}

	/**
	 * Remain completely inactive unless configured — same philosophy as OCI.
	 * Presence-only gate; no token exchange is performed here.
	 */
	private static function is_configured() {
		return self::option( 'pixel_id' ) !== ''
			&& self::option( 'access_token' ) !== '';
	}

	/**
	 * Master switch. Empty string (installs configured before the toggle
	 * existed) counts as enabled — never silently deactivate a live install.
	 */
	public static function is_enabled() {
		return self::option( 'enabled' ) !== '0';
	}

	/**
	 * Server Events (CAPI) gate. Same backward-compatible default as enabled.
	 */
	public static function server_events_enabled() {
		return self::is_enabled() && self::option( 'server_events' ) !== '0';
	}

	private static function option( $key ) {
		return (string) get_option( self::OPTION_PREFIX . $key, '' );
	}

	/** Toggle fields persisted as explicit '1'/'0' (checkbox semantics). */
	const TOGGLE_FIELDS = array( 'test_mode', 'enabled', 'browser_events', 'server_events', 'debug_logging' );

	public static function save_settings( $post ) {
		// Campos de texto: solo se escriben si la clave viene en el POST —
		// un guardado parcial jamás borra configuración no enviada.
		foreach ( array( 'pixel_id', 'test_event_code' ) as $field ) {
			if ( array_key_exists( 'meta_' . $field, $post ) ) {
				$value = sanitize_text_field( wp_unslash( $post[ 'meta_' . $field ] ) );
				update_option( self::OPTION_PREFIX . $field, $value, false );
			}
		}

		// Secreto (keep-if-empty): un Access Token vacío NUNCA pisa el token
		// guardado. El campo se renderiza vacío (el secreto no viaja al DOM);
		// para rotarlo se pega el nuevo valor. No existe "borrar token" desde
		// el form — para apagar la integración está el interruptor maestro.
		if ( array_key_exists( 'meta_access_token', $post ) ) {
			$token = sanitize_text_field( wp_unslash( $post['meta_access_token'] ) );
			if ( $token !== '' ) {
				update_option( self::OPTION_PREFIX . 'access_token', $token, false );
			}
		}

		// Toggles: checkbox semantics — presencia = '1', ausencia = '0'.
		foreach ( self::TOGGLE_FIELDS as $field ) {
			$raw = sanitize_text_field( wp_unslash( $post[ 'meta_' . $field ] ?? '' ) );
			update_option( self::OPTION_PREFIX . $field, $raw === '1' ? '1' : '0', false );
		}
	}

	public static function get_settings() {
		return array(
			'pixel_id'         => self::option( 'pixel_id' ),
			'access_token'     => self::option( 'access_token' ),
			'test_event_code'  => self::option( 'test_event_code' ),
			'test_mode'        => self::option( 'test_mode' ),
			// Raw option values; '' means "never saved" and the render layer
			// applies the backward-compatible defaults (enabled/server on).
			'enabled'          => self::option( 'enabled' ),
			'browser_events'   => self::option( 'browser_events' ),
			'server_events'    => self::option( 'server_events' ),
			'debug_logging'    => self::option( 'debug_logging' ),
		);
	}

	// ── Diagnostics / log viewer (read-only, no PII, no tokens) ──────────────────

	/**
	 * Queue counters + last activity for the admin diagnostics card.
	 *
	 * @return array { counts: array<status,int>, last_attempt: ?string, last_error: ?string }
	 */
	public static function queue_stats() {
		global $wpdb;
		$table = $wpdb->prefix . 'es_meta_queue';

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
			'last_error'   => ( $last && $last->queue_status !== 'completed' && $last->last_error )
				? substr( (string) $last->last_error, 0, 200 ) : null,
		);
	}

	/**
	 * Recent Meta dispatches for the admin log viewer.
	 *
	 * Reads the Commercial Outcomes execution log joined with lead identity and
	 * queue attempts. Deliberately never selects payload_json (hashed PII) nor
	 * any credential; response_json is reduced to duration_ms and discarded.
	 *
	 * @return array[] { created_at, event_name, lead_uuid, event_id, status, http_code, retry_count, duration_ms }
	 */
	public static function recent_log_rows( $limit = 20 ) {
		global $wpdb;
		$log_table = $wpdb->prefix . 'es_commercial_outcomes_log';

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) ) ) {
			return array();
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT o.created_at, o.event AS event_name, o.stage, o.status, o.http_code,
			        o.response_json, o.lead_id, l.lead_uuid, q.attempts AS retry_count
			 FROM {$log_table} o
			 LEFT JOIN {$wpdb->prefix}es_leads l ON l.id = o.lead_id
			 LEFT JOIN {$wpdb->prefix}es_meta_queue q
			        ON q.lead_id = o.lead_id AND q.export_status = o.stage
			 WHERE o.adapter = 'meta'
			 ORDER BY o.id DESC
			 LIMIT %d",
			max( 1, (int) $limit )
		) );

		$out = array();
		foreach ( $rows as $row ) {
			$response    = json_decode( (string) $row->response_json, true );
			$lead_uuid   = trim( (string) ( $row->lead_uuid ?? '' ) );
			if ( $lead_uuid === '' ) {
				$lead_uuid = 'lead-' . (int) $row->lead_id;
			}
			$out[] = array(
				'created_at'  => (string) $row->created_at,
				'event_name'  => (string) $row->event_name,
				'lead_uuid'   => $lead_uuid,
				// Same derivation as event_id() — deterministic, reconstructable.
				'event_id'    => $lead_uuid . ':' . strtoupper( (string) $row->stage ),
				'status'      => (string) $row->status,
				'http_code'   => (int) $row->http_code,
				'retry_count' => $row->retry_count !== null ? (int) $row->retry_count : 0,
				'duration_ms' => is_array( $response ) && isset( $response['duration_ms'] )
					? (int) $response['duration_ms'] : null,
			);
		}
		return $out;
	}

	/**
	 * Telemetry, mirroring the OCI logging style. Never breaks business flow.
	 */
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
