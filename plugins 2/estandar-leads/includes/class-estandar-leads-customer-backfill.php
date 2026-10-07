<?php
/**
 * CUSTOMER_BACKFILL_V1 — reconciled customers → Meta Purchase (historical).
 *
 * Bridges the Customer Registry to the pre-existing Conversion Backfill:
 *
 *   es_customers.matched_lead_id
 *     → eligibility (reconciled + canonical lead + QA policy + not already sent)
 *     → Estandar_Leads_Conversion_Backfill_Service::enqueue()   (es_conversion_backfill_audit)
 *     → Estandar_Leads_Conversion_Backfill_Service::dispatch()  (Meta Adapter → CAPI)
 *
 * Boundaries (do not blur):
 *   - Read-only over es_customers and es_leads. NEVER writes either table.
 *   - NEVER changes lead commercial status and NEVER fires estandar_lead_status_changed.
 *   - Admin-triggered only. No cron, no hooks, no automatic future dispatch.
 *   - Identity and attribution are hydrated from the canonical es_leads row by the
 *     service at dispatch time. The buyer record carries NO PII — only the commercial
 *     facts owned by the customer registry (value, currency, event_time).
 *
 * Idempotency is inherited, not reinvented: the audit event_id is
 * `conversion-backfill:{lead_uuid}:{buyer_uuid}:Purchase` with a UNIQUE index, and
 * buyer_uuid is derived deterministically from the customer id — so the same customer
 * always resolves to the same event_id and a re-run can never emit a duplicate.
 *
 * Second mode (HISTORICAL_CUSTOMER_BACKFILL): customers WITHOUT matched_lead_id.
 * Identity (first/last name, email, phone — SHA-256 downstream) comes from the
 * customer row itself; NOTHING else is invented (no fbp/fbc/UTM/IP/UA/landing/lead
 * uuid → action_source=system_generated). Requires valid email or phone, a
 * project_slug and a conversion date: the customer's completed_at when present,
 * else the request-scoped global fallback date (never persisted); with neither,
 * the row is skipped. event_id = `historical-customer:{customer_id}:Purchase`
 * (same UNIQUE audit) — deliberately date-independent, so editing a date can
 * never make an already-sent customer eligible again.
 * Never creates synthetic leads; reconciled customers are excluded from this mode.
 *
 * @package EstandarLeads
 * @since   13.0.72
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Leads_Customer_Backfill {

	const DEFAULT_LIMIT = 200;
	const MAX_LIMIT     = 1000;

	/**
	 * Backfill modes.
	 *   reconciled — matched_lead_id → canonical es_leads hydration (original flow).
	 *   historical — customers WITHOUT matched_lead_id → identity from es_customers,
	 *                zero attribution invented, action_source=system_generated.
	 */
	const MODE_RECONCILED = 'reconciled';
	const MODE_HISTORICAL = 'historical';

	/** Skip reasons (stable keys — surfaced in the admin report). */
	const SKIP_UNMATCHED         = 'unmatched';
	const SKIP_LEAD_MISSING      = 'lead_missing';
	const SKIP_LEAD_UUID_MISSING = 'lead_uuid_missing';
	const SKIP_QA_BLOCKED        = 'qa_blocked';
	const SKIP_PLATFORM_INELIGIBLE = 'meta_platform_ineligible';
	const SKIP_ALREADY_SENT      = 'already_dispatched';

	/** Historical-mode skip reasons. */
	const SKIP_ALREADY_MATCHED     = 'already_matched';
	const SKIP_IDENTITY_MISSING    = 'identity_missing';
	const SKIP_PROJECT_MISSING     = 'project_slug_missing';
	const SKIP_COMPLETED_AT_MISSING = 'completed_at_missing';

	/**
	 * Deterministic buyer identity for a customer row.
	 *
	 * The customer registry has no uuid column and this phase adds no schema, so the
	 * buyer_uuid is derived from the primary key. Stable across runs → stable event_id.
	 */
	public static function buyer_uuid( int $customer_id ): string {
		$hash = hash( 'sha256', 'estandar-customer:' . $customer_id );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-' . substr( $hash, 12, 4 )
			. '-' . substr( $hash, 16, 4 ) . '-' . substr( $hash, 20, 12 );
	}

	/**
	 * Deterministic event_id for the historical (no-lead) mode. Keyed ONLY by the
	 * customer's primary key — deliberately date-independent, so the audit's
	 * UNIQUE(event_id) makes every re-run a no-op even if completed_at (or the
	 * global fallback date) changes between runs: one Purchase per customer, ever.
	 */
	public static function historical_event_id( int $customer_id ): string {
		return 'historical-customer:' . $customer_id . ':Purchase';
	}

	/**
	 * Classify every customer in scope. Pure read — writes nothing, sends nothing.
	 *
	 * @param array  $filters { project_slug?: string, customer_stage?: string, ids?: int[],
	 *                          fallback_completed_at?: string  (historical mode only: request-scoped
	 *                          global conversion date used when a customer has no completed_at;
	 *                          strictly validated, never persisted, never a WHERE clause) }
	 * @param int    $limit
	 * @param string $mode    self::MODE_RECONCILED (default) | self::MODE_HISTORICAL.
	 * @return array {
	 *   mode:       string
	 *   buyers:     array[]  Buyer records ready for the backfill service.
	 *   eligible:   int
	 *   duplicate:  int
	 *   skipped:    int
	 *   reasons:    array<string,int>
	 *   preview:    array[]  Human-readable rows for the admin screen.
	 * }
	 */
	public static function plan( array $filters = array(), int $limit = self::DEFAULT_LIMIT, string $mode = self::MODE_RECONCILED ): array {
		global $wpdb;

		$mode  = $mode === self::MODE_HISTORICAL ? self::MODE_HISTORICAL : self::MODE_RECONCILED;
		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );

		// Request-scoped global conversion date (historical mode only). Invalid or
		// future values are discarded here — downstream code only ever sees '' or a
		// normalized 'Y-m-d H:i:s'. It is never persisted and never filters the query.
		$fallback = $mode === self::MODE_HISTORICAL
			? self::sanitize_fallback_date( (string) ( $filters['fallback_completed_at'] ?? '' ) )
			: '';

		$where  = array( '1=1' );
		$params = array();

		$project = trim( (string) ( $filters['project_slug'] ?? '' ) );
		if ( $project !== '' ) {
			$where[]  = 'project_slug = %s';
			$params[] = $project;
		}

		$stage = trim( (string) ( $filters['customer_stage'] ?? '' ) );
		if ( $stage !== '' ) {
			$where[]  = 'customer_stage = %s';
			$params[] = $stage;
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) ( $filters['ids'] ?? array() ) ) ) );
		if ( ! empty( $ids ) ) {
			$where[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}

		$sql = 'SELECT id, project_slug, customer_stage, first_name, last_name, email, phone, amount,'
			. ' completed_at, matched_lead_id, matched_at'
			. " FROM {$wpdb->prefix}es_customers"
			. ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY id ASC LIMIT %d';

		$params[] = $limit;
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$out = array(
			'mode'      => $mode,
			'buyers'    => array(),
			'eligible'  => 0,
			'duplicate' => 0,
			'skipped'   => 0,
			'reasons'   => array(),
			'preview'   => array(),
		);

		foreach ( $rows as $row ) {
			$customer_id = (int) ( $row['id'] ?? 0 );
			$lead_id     = (int) ( $row['matched_lead_id'] ?? 0 );

			$decision = $mode === self::MODE_HISTORICAL
				? self::classify_historical( $row, $fallback )
				: self::classify( $customer_id, $lead_id );

			$out['preview'][] = array(
				'customer_id' => $customer_id,
				'name'        => trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) ),
				'project'     => (string) ( $row['project_slug'] ?? '' ),
				'lead_id'     => $lead_id,
				'lead_uuid'   => (string) $decision['lead_uuid'],
				'status'      => (string) $decision['status'],
				'reason'      => (string) $decision['reason'],
			);

			if ( $decision['status'] === 'eligible' ) {
				$out['eligible']++;
				$out['buyers'][] = $mode === self::MODE_HISTORICAL
					? self::buyer_record_historical( $row, $fallback )
					: self::buyer_record( $row, $decision['lead_uuid'], $lead_id );
				continue;
			}

			if ( $decision['status'] === 'duplicate' ) {
				$out['duplicate']++;
				continue;
			}

			$out['skipped']++;
			$reason = (string) $decision['reason'];
			$out['reasons'][ $reason ] = ( $out['reasons'][ $reason ] ?? 0 ) + 1;
		}

		return $out;
	}

	/**
	 * Plan, then (unless dry-run) enqueue + dispatch through the existing service.
	 *
	 * Dry-run is the default: nothing is written to the audit table and nothing is
	 * sent to Meta. It exists so the operator can see exactly what would be emitted.
	 *
	 * @return array { mode, dry_run, eligible, duplicate, skipped, sent, failed, retry, reasons, preview }
	 */
	public static function run( array $filters = array(), int $limit = self::DEFAULT_LIMIT, bool $dry_run = true, string $mode = self::MODE_RECONCILED ): array {
		$plan = self::plan( $filters, $limit, $mode );

		$result = array(
			'mode'      => (string) $plan['mode'],
			'dry_run'   => $dry_run,
			'eligible'  => (int) $plan['eligible'],
			'duplicate' => (int) $plan['duplicate'],
			'skipped'   => (int) $plan['skipped'],
			'sent'      => 0,
			'failed'    => 0,
			'retry'     => 0,
			'reasons'   => $plan['reasons'],
			'preview'   => $plan['preview'],
		);

		if ( $dry_run ) {
			return $result;
		}

		$service = new Estandar_Leads_Conversion_Backfill_Service();

		if ( ! empty( $plan['buyers'] ) ) {
			// enqueue() re-checks event_exists() — a row created by a concurrent run is
			// absorbed there, so the pre-computed duplicate count is a report, not a gate.
			$service->enqueue( $plan['buyers'] );
		}

		// Drain unconditionally, even when nothing new was enqueued: a previous run that
		// died between enqueue and dispatch leaves 'pending' rows which plan() now reports
		// as duplicates. Without this, those rows could never be delivered.
		//
		// dispatch() hydrates the canonical lead from es_leads, re-applies the QA gate,
		// and delivers through the Meta Adapter.
		$dispatched = $service->dispatch( max( 1, min( self::MAX_LIMIT, $limit ) ) );

		$result['sent']    = (int) ( $dispatched['sent'] ?? 0 );
		$result['failed']  = (int) ( $dispatched['failed'] ?? 0 );
		$result['retry']   = (int) ( $dispatched['retry'] ?? 0 );
		$result['skipped'] += (int) ( $dispatched['skipped'] ?? 0 );

		return $result;
	}

	// ── Internals ────────────────────────────────────────────────────────────────

	/**
	 * Eligibility decision for one customer. Read-only.
	 *
	 * Order matters: cheapest disqualifier first, QA policy before the audit lookup.
	 *
	 * @return array { status: eligible|duplicate|skipped, reason: string, lead_uuid: string }
	 */
	private static function classify( int $customer_id, int $lead_id ): array {
		if ( $customer_id <= 0 || $lead_id <= 0 ) {
			return self::decision( 'skipped', self::SKIP_UNMATCHED );
		}

		$lead = self::lead_identity( $lead_id );
		if ( $lead === null ) {
			return self::decision( 'skipped', self::SKIP_LEAD_MISSING );
		}

		$lead_uuid = trim( (string) ( $lead['lead_uuid'] ?? '' ) );
		if ( $lead_uuid === '' ) {
			// Without a lead_uuid there is no deterministic event_id — and therefore no
			// idempotency guarantee. Fail closed rather than emit an unrepeatable event.
			return self::decision( 'skipped', self::SKIP_LEAD_UUID_MISSING );
		}

		if ( class_exists( 'Estandar_Leads_QA_Policy' )
			&& Estandar_Leads_QA_Policy::ads_feedback_blocked( $lead_id ) ) {
			return self::decision( 'skipped', self::SKIP_QA_BLOCKED, $lead_uuid );
		}

		$platform = Estandar_Leads_Attribution::platform_eligibility( 'meta', $lead, true );
		if ( empty( $platform['eligible'] ) ) {
			return self::decision( 'skipped', self::SKIP_PLATFORM_INELIGIBLE, $lead_uuid );
		}

		if ( self::already_dispatched( $lead_uuid, self::buyer_uuid( $customer_id ) ) ) {
			return self::decision( 'duplicate', self::SKIP_ALREADY_SENT, $lead_uuid );
		}

		return self::decision( 'eligible', '', $lead_uuid );
	}

	/**
	 * Historical (no-lead) eligibility decision for one customer row. Read-only.
	 *
	 * Order: reconciled rows are excluded first (they belong to the reconciled mode —
	 * letting them through here would emit a second Purchase under a different
	 * event_id), then identity, project, conversion date, and finally the audit lookup.
	 *
	 * @param array  $row       es_customers row (ARRAY_A) from plan()'s SELECT.
	 * @param string $fallback  Pre-validated global conversion date ('' = none).
	 * @return array { status: eligible|duplicate|skipped, reason: string, lead_uuid: string }
	 */
	private static function classify_historical( array $row, string $fallback = '' ): array {
		$customer_id = (int) ( $row['id'] ?? 0 );
		if ( $customer_id <= 0 ) {
			return self::decision( 'skipped', self::SKIP_IDENTITY_MISSING );
		}

		if ( (int) ( $row['matched_lead_id'] ?? 0 ) > 0 ) {
			return self::decision( 'skipped', self::SKIP_ALREADY_MATCHED );
		}

		if ( ! self::has_valid_identity( $row ) ) {
			return self::decision( 'skipped', self::SKIP_IDENTITY_MISSING );
		}

		if ( trim( (string) ( $row['project_slug'] ?? '' ) ) === '' ) {
			return self::decision( 'skipped', self::SKIP_PROJECT_MISSING );
		}

		if ( self::resolved_completed_at( $row, $fallback ) === '' ) {
			// No customer completed_at AND no global fallback date → there is no
			// truthful event_time — fail closed, the send is blocked.
			return self::decision( 'skipped', self::SKIP_COMPLETED_AT_MISSING );
		}

		if ( self::event_id_exists( self::historical_event_id( $customer_id ) ) ) {
			return self::decision( 'duplicate', self::SKIP_ALREADY_SENT );
		}

		// Historical customers intentionally carry no advertising attribution.
		// The canonical Meta gate therefore blocks them before the audit enqueue.
		return self::decision( 'skipped', self::SKIP_PLATFORM_INELIGIBLE );
	}

	/**
	 * Conversion date resolution (historical mode): the customer's own completed_at
	 * always wins; the request-scoped global date is only a fallback for rows
	 * without one. Returns '' when neither exists.
	 */
	private static function resolved_completed_at( array $row, string $fallback ): string {
		$completed_at = trim( (string) ( $row['completed_at'] ?? '' ) );
		return $completed_at !== '' ? $completed_at : $fallback;
	}

	/**
	 * Strict validation of the operator-supplied global conversion date. Accepts
	 * exactly 'Y-m-d' or 'Y-m-d H:i:s' (round-trip checked, so 2026-02-30 fails),
	 * rejects future dates (this is a HISTORICAL backfill), and normalizes to
	 * 'Y-m-d H:i:s' UTC. Anything else → '' (treated as "no fallback provided").
	 */
	private static function sanitize_fallback_date( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}
		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d' ) as $format ) {
			$dt = DateTime::createFromFormat( $format, $raw, new DateTimeZone( 'UTC' ) );
			if ( $dt === false || $dt->format( $format ) !== $raw ) {
				continue;
			}
			if ( $format === 'Y-m-d' ) {
				$dt->setTime( 0, 0, 0 );
			}
			if ( $dt->getTimestamp() > time() ) {
				return '';
			}
			return $dt->format( 'Y-m-d H:i:s' );
		}
		return '';
	}

	/**
	 * Valid email OR phone — mirrors exactly the condition under which the Meta
	 * payload builder will emit em/ph, so an "eligible" row can never produce an
	 * identity-less payload. Normalization = same helpers the matching engine uses.
	 */
	private static function has_valid_identity( array $row ): bool {
		$email = trim( (string) ( $row['email'] ?? '' ) );
		if ( $email !== '' && filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ) {
			return true;
		}
		$phone  = (string) ( $row['phone'] ?? '' );
		$digits = class_exists( 'Estandar_Leads_Normalizer' )
			? Estandar_Leads_Normalizer::phone_storage( $phone )
			: preg_replace( '/\D+/', '', $phone );
		return $digits !== '';
	}

	private static function decision( string $status, string $reason, string $lead_uuid = '' ): array {
		return array( 'status' => $status, 'reason' => $reason, 'lead_uuid' => $lead_uuid );
	}

	/**
	 * Buyer record for the backfill service.
	 *
	 * Deliberately carries NO identity or attribution: 'lead' is left empty so the
	 * service hydrates the canonical es_leads row at dispatch time (single source of
	 * truth for em/ph/fbc/fbp/utm/ip/ua). Only commercial facts owned by the customer
	 * registry travel here — and none of them are PII, so buyer_json stays clean.
	 */
	private static function buyer_record( array $customer, string $lead_uuid, int $lead_id ): array {
		$amount = $customer['amount'] ?? '';
		$value  = ( $amount !== '' && $amount !== null && is_numeric( $amount ) ) ? (float) $amount : '';

		$event_time = trim( (string) ( $customer['completed_at'] ?? '' ) );
		if ( $event_time === '' ) {
			$event_time = trim( (string) ( $customer['matched_at'] ?? '' ) );
		}

		return array(
			'approved'          => true,
			'buyer_uuid'        => self::buyer_uuid( (int) $customer['id'] ),
			'lead_uuid'         => $lead_uuid,
			'lead_id'           => $lead_id,
			'customer_id'       => (int) $customer['id'],
			'purchase_value'    => $value,
			'currency'          => self::currency(),
			'purchase_metadata' => array( 'event_time' => $event_time ),
			'lead'              => array(), // hydrated from es_leads by the service
		);
	}

	/**
	 * Buyer record for the historical (no-lead) mode.
	 *
	 * Carries NO identity either: 'customer_id' at the record root makes the service
	 * hydrate name/email/phone/project fresh from es_customers at dispatch time, so
	 * buyer_json stays PII-free (same contract as the reconciled mode). Only the
	 * commercial facts and the pre-computed deterministic event_id travel here.
	 * No lead_uuid, fbc/fbp, UTM, IP, UA or landing URL is ever fabricated.
	 */
	private static function buyer_record_historical( array $customer, string $fallback = '' ): array {
		$amount = $customer['amount'] ?? '';
		$value  = ( $amount !== '' && $amount !== null && is_numeric( $amount ) ) ? (float) $amount : '';

		$customer_id = (int) $customer['id'];

		return array(
			'approved'          => true,
			'buyer_uuid'        => self::buyer_uuid( $customer_id ),
			'lead_uuid'         => '',
			'lead_id'           => 0,
			'customer_id'       => $customer_id,
			'event_id'          => self::historical_event_id( $customer_id ),
			'purchase_value'    => $value,
			'currency'          => self::currency(),
			// event_time = resolved conversion date (customer completed_at wins over
			// the global fallback). The event_id above deliberately ignores it.
			'purchase_metadata' => array( 'event_time' => self::resolved_completed_at( $customer, $fallback ) ),
			'lead'              => array(), // hydrated from es_customers by the service
		);
	}

	/** Canonical lead read for identity plus platform attribution. Never mutates. */
	private static function lead_identity( int $lead_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
			$lead_id
		), ARRAY_A );
		return $row ?: null;
	}

	private static function already_dispatched( string $lead_uuid, string $buyer_uuid ): bool {
		return self::event_id_exists( 'conversion-backfill:' . $lead_uuid . ':' . $buyer_uuid . ':Purchase' );
	}

	private static function event_id_exists( string $event_id ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}es_conversion_backfill_audit WHERE event_id = %s",
			$event_id
		) ) > 0;
	}

	/** Same currency option the Commercial Outcomes value provider reads. */
	private static function currency(): string {
		$currency = strtoupper( trim( (string) get_option( 'estandar_commercial_currency', '' ) ) );
		return $currency !== '' ? $currency : 'CLP';
	}
}
