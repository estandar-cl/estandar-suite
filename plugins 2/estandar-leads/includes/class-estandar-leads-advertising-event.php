<?php
/**
 * AdvertisingEvent emitter — Phase 1 of the Leads ↔ Signal Core decoupling.
 *
 * estandar-leads is the commercial Source of Truth: it decides which commercial
 * facts are advertising-exportable and emits a NORMALIZED, domain-free event.
 * Signal Core consumes it as infrastructure — it never reads es_leads.status,
 * applies whitelists, nor interprets the lead domain.
 *
 * Contract (fired as: do_action( 'estandar_advertising_event_ready', array $event )):
 *   event_id        string  trace id (uuid) — unique per emission
 *   event_name      string  one of EVENT_NAMES (technical advertising vocabulary)
 *   occurred_at     string  MySQL UTC datetime
 *   project_slug    string
 *   lead_id         int
 *   submission_id   int     0 when none
 *   transaction_id  string  stable per (lead, event) — platform idempotency
 *   lifecycle_key   string  deterministic idempotency basis (opaque to Signal)
 *   identifiers     array   { gclid, gbraid, wbraid, fbclid, meta_fbc, meta_fbp, ttclid, msclkid }
 *   identity        array   { lead_uuid, session_id, email_normalized, phone_normalized } (normalized, NOT hashed)
 *   currency        string  '' when none
 *   value           float|null  present only when a real value exists
 *   meta            array   { engine, quality } — minimal technical metadata
 *
 * NEVER carries: raw status, sales_stage, whitelist, qualification rules, or the
 * full Lead object. Exportability is decided HERE via the canonical Leads policy
 * (Estandar_Leads_QA_Policy::ads_feedback_blocked) + the status→event map below.
 *
 * @package EstandarLeads
 * @since   13.0.68
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Advertising_Event {

	const HOOK = 'estandar_advertising_event_ready';

	/** Stable capability filter — true ONLY once emission is fully wired. */
	const CAPABILITY = 'estandar_advertising_event_emitter_available';

	/**
	 * INTERNAL PII carried inside event['identity']. These normalized values are
	 * required for platform Enhanced Matching and travel in-memory to Signal Core,
	 * which persists them ONLY as SHA-256 advertising hashes. They must NEVER be
	 * written to logs, telemetry, exceptions or debug payloads.
	 */
	const PII_KEYS = array( 'email_normalized', 'phone_normalized' );

	/** Canonical advertising event vocabulary (technical event names). */
	const EVENT_NAMES = array(
		'lead_created',
		'lead_contacted',
		'lead_qualified',
		'meeting_booked',
		'sale_closed',
		'lead_rejected',
	);

	/** Set true only when init() finished and every emission hook is registered. */
	private static $ready = false;

	public static function init() {
		// Priority 25: after the qualification engine (15) so ads_feedback_policy
		// is already persisted, and after the legacy Signal listener (20).
		add_action( 'estandar_lead_ingested', array( __CLASS__, 'on_lead_ingested' ), 25, 4 );
		add_action( 'estandar_lead_status_changed', array( __CLASS__, 'on_status_changed' ), 25, 4 );

		// Explicit capability: registered LAST, only after both emission hooks are
		// wired. Signal Core consults this filter (never class_exists) to decide
		// whether to stand its legacy listener down. No hard cross-plugin coupling.
		self::$ready = true;
		add_filter( self::CAPABILITY, array( __CLASS__, 'declare_available' ) );
	}

	/** Capability callback: true only when emission is actually wired. */
	public static function declare_available( $available ) {
		return self::$ready === true ? true : $available;
	}

	public static function on_lead_ingested( $lead_id, $submission_id, $is_new, $payload ) {
		if ( ! $is_new ) {
			return; // capture fact fires once, on first persistence
		}
		if ( self::blocked( (int) $lead_id, (int) $submission_id, is_array( $payload ) ? $payload : array() ) ) {
			return;
		}
		self::emit( (int) $lead_id, 'lead_created', (int) $submission_id, '', gmdate( 'Y-m-d H:i:s' ) );
	}

	public static function on_status_changed( $lead_id, $old_status, $new_status, $changed_at ) {
		if ( self::blocked( (int) $lead_id, 0, array() ) ) {
			return;
		}
		$event_name = self::event_for_status( (string) $new_status );
		if ( $event_name === '' ) {
			return; // not an advertising-exportable commercial fact
		}
		self::emit( (int) $lead_id, $event_name, 0, (string) $new_status, (string) $changed_at );
	}

	/**
	 * Canonical status → advertising event-name map. This is the domain decision
	 * relocated from Signal Core into Leads (the Source of Truth). Signal never
	 * sees the raw status — only the resolved event name.
	 */
	public static function event_for_status( $status ) {
		$s = strtoupper( sanitize_key( (string) $status ) );
		if ( $s === 'CONTACTED' ) {
			return 'lead_contacted';
		}
		if ( $s === 'QUALIFIED' ) {
			return 'lead_qualified';
		}
		if ( in_array( $s, array( 'VISIT_SCHEDULED', 'VISITED', 'MEETING', 'MEETING_BOOKED' ), true ) ) {
			return 'meeting_booked';
		}
		if ( in_array( $s, array( 'RESERVED', 'DEED_SIGNED', 'DELIVERED', 'CONVERTED', 'SALE' ), true ) ) {
			return 'sale_closed';
		}
		if ( in_array( $s, array( 'LOST', 'UNREACHABLE', 'INVALID' ), true ) ) {
			return 'lead_rejected';
		}
		return '';
	}

	/** Canonical Leads exportability policy — reused, never duplicated. */
	private static function blocked( $lead_id, $submission_id, array $payload ) {
		return class_exists( 'Estandar_Leads_QA_Policy' )
			&& Estandar_Leads_QA_Policy::ads_feedback_blocked( $lead_id, $submission_id, $payload );
	}

	private static function emit( $lead_id, $event_name, $submission_id, $status, $occurred_at ) {
		$event = self::build( $lead_id, $event_name, $submission_id, $status, $occurred_at );
		if ( empty( $event ) ) {
			return;
		}
		do_action( self::HOOK, $event );
	}

	/**
	 * Build the normalized AdvertisingEvent from the lead row. Leads is allowed to
	 * read es_leads — it owns it. The emitted event carries NO raw status.
	 */
	public static function build( $lead_id, $event_name, $submission_id, $status, $occurred_at ) {
		global $wpdb;

		$lead_id = (int) $lead_id;
		$lead    = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
			$lead_id
		) );
		if ( ! $lead ) {
			return array();
		}

		$occurred_at = self::datetime( $occurred_at );

		// Deterministic (no now()) so retries / multiple listeners dedup on the
		// same idempotency_key inside Signal.
		$lifecycle_key = $submission_id > 0
			? 'submission:' . (int) $submission_id . ':' . $event_name
			: 'lead:' . $lead_id . ':' . $event_name . ':' . sanitize_key( (string) $status ) . ':' . $occurred_at;

		$lead_uuid      = (string) ( $lead->lead_uuid ?? '' );
		$transaction_id = ( $lead_uuid !== '' ? $lead_uuid : 'lead-' . $lead_id ) . ':' . strtoupper( $event_name );

		$value    = null;
		$currency = '';
		if ( class_exists( 'Estandar_Commercial_Outcomes' ) && $status !== '' ) {
			$outcomes = Estandar_Commercial_Outcomes::locator();
			if ( $outcomes ) {
				$money = $outcomes->value_provider()->value_for( $lead, (string) $status );
				if ( $money !== null ) {
					$value = $money->amount();
					if ( $money->has_currency() ) {
						$currency = $money->currency();
					}
				}
			}
		}

		return array(
			'event_id'       => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : $transaction_id,
			'event_name'     => $event_name,
			'occurred_at'    => $occurred_at,
			'project_slug'   => sanitize_title( (string) ( $lead->project_slug ?? '' ) ),
			'lead_id'        => $lead_id,
			'submission_id'  => (int) $submission_id,
			'transaction_id' => $transaction_id,
			'lifecycle_key'  => $lifecycle_key,
			'identifiers'    => array(
				'gclid'    => (string) ( $lead->first_gclid ?? '' ),
				'gbraid'   => (string) ( $lead->first_gbraid ?? '' ),
				'wbraid'   => (string) ( $lead->first_wbraid ?? '' ),
				'fbclid'   => (string) ( $lead->first_fbclid ?? '' ),
				'meta_fbc' => (string) ( $lead->first_meta_fbc ?? '' ),
				'meta_fbp' => (string) ( $lead->first_meta_fbp ?? '' ),
				'ttclid'   => (string) ( $lead->first_ttclid ?? '' ),
				'msclkid'  => (string) ( $lead->first_msclkid ?? '' ),
			),
			'identity'       => array(
				'lead_uuid'        => $lead_uuid,
				'session_id'       => (string) ( $lead->first_session_id ?? '' ),
				'email_normalized' => (string) ( $lead->email_normalized ?? '' ),
				'phone_normalized' => (string) ( $lead->phone_normalized ?? '' ),
			),
			'currency'       => $currency,
			'value'          => $value,
			'meta'           => array(
				'engine'  => 'advertising_event_v1',
				'quality' => (string) ( $lead->quality ?? '' ),
			),
		);
	}

	private static function datetime( $value ) {
		$ts = is_string( $value ) && $value !== '' ? strtotime( $value ) : false;
		return gmdate( 'Y-m-d H:i:s', $ts ?: time() );
	}
}
