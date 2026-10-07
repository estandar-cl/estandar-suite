<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Signal_Core_Repository {

	public static function record_from_lead( int $lead_id, string $event_name, array $args = array() ): int {
		global $wpdb;

		$lead_id = absint( $lead_id );
		$event_name = self::event_name( $event_name );
		if ( $lead_id <= 0 || $event_name === '' ) {
			return 0;
		}

		$lead = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
				$lead_id
			),
			ARRAY_A
		);
		if ( ! is_array( $lead ) ) {
			return 0;
		}

		$event_time = self::datetime( $args['event_time'] ?? '' );
		$status = sanitize_key( (string) ( $args['status'] ?? ( $lead['status'] ?? '' ) ) );
		$client_id = self::client_id( (string) ( $lead['project_slug'] ?? '' ) );
		$project_slug = sanitize_title( (string) ( $lead['project_slug'] ?? '' ) );
		$source_event_id = absint( $args['source_event_id'] ?? 0 );
		$lifecycle_key = self::lifecycle_key( $lead_id, $event_name, $status, $event_time, $source_event_id );
		$idempotency_key = hash( 'sha256', implode( '|', array( 'signal_core_v2', $lead_id, $event_name, $lifecycle_key ) ) );
		$existing_id = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . Estandar_Signal_Core_Schema::events_table() . ' WHERE idempotency_key = %s LIMIT 1',
					$idempotency_key
				)
			)
		);
		if ( $existing_id > 0 ) {
			return $existing_id;
		}

		// Determine if any registered exporter handles this event.
		// Exporters are objects implementing get_id() and supports_event(string): bool.
		// Register via: add_filter('estandar_signal_core_exporters', fn($e) => [...$e, $myExporter]);
		$exporters      = (array) apply_filters( 'estandar_signal_core_exporters', array() );
		$has_exporter   = false;
		foreach ( $exporters as $exporter ) {
			if ( is_object( $exporter ) && method_exists( $exporter, 'supports_event' ) && $exporter->supports_event( $event_name ) ) {
				$has_exporter = true;
				break;
			}
		}
		$record_audit_only  = $has_exporter ? 0 : 1;
		$record_export_status = $has_exporter ? 'pending' : 'audit_only';

		$payload = array(
			'source'      => sanitize_key( (string) ( $args['source'] ?? 'runtime' ) ),
			'from_status' => sanitize_key( (string) ( $args['from_status'] ?? '' ) ),
			'to_status'   => sanitize_key( (string) ( $args['to_status'] ?? '' ) ),
			'audit_only'  => ! $has_exporter,
		);

		$identity = array(
			'client_id' => $client_id,
			'project_slug' => $project_slug,
			'lead_uuid' => (string) ( $lead['lead_uuid'] ?? '' ),
			'session_id' => (string) ( $lead['first_session_id'] ?? '' ),
			'gclid' => (string) ( $lead['first_gclid'] ?? '' ),
			'gbraid' => (string) ( $lead['first_gbraid'] ?? '' ),
			'wbraid' => (string) ( $lead['first_wbraid'] ?? '' ),
			'fbclid' => (string) ( $lead['first_fbclid'] ?? '' ),
			'meta_fbc' => (string) ( $lead['first_meta_fbc'] ?? '' ),
			'meta_fbp' => (string) ( $lead['first_meta_fbp'] ?? '' ),
			'ttclid' => (string) ( $lead['first_ttclid'] ?? '' ),
			'msclkid' => (string) ( $lead['first_msclkid'] ?? '' ),
			'utm_source' => (string) ( $lead['first_utm_source'] ?? '' ),
			'utm_medium' => (string) ( $lead['first_utm_medium'] ?? '' ),
			'utm_campaign' => (string) ( $lead['first_utm_campaign'] ?? '' ),
			'utm_term' => (string) ( $lead['first_utm_term'] ?? '' ),
			'utm_content' => (string) ( $lead['first_utm_content'] ?? '' ),
		);

		self::ensure_client( $client_id );

		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			Estandar_Signal_Core_Schema::events_table(),
			array(
				'signal_uuid' => wp_generate_uuid4(),
				'client_id' => $client_id,
				'project_slug' => $project_slug,
				'lead_id' => $lead_id,
				'submission_id' => null,
				'source_event_id' => $source_event_id > 0 ? $source_event_id : null,
				'source_plugin' => 'estandar-leads',
				'event_name' => $event_name,
				'event_stage' => self::event_stage( $event_name ),
				'lifecycle_key' => $lifecycle_key,
				'idempotency_key' => $idempotency_key,
				'occurrence_index' => self::next_occurrence_index( $lead_id, $event_name ),
				'audit_only'    => $record_audit_only,
				'export_status' => $record_export_status,
				'event_time' => $event_time,
				'value_decimal' => null,
				'currency' => '',
				'quality' => sanitize_key( (string) ( $lead['quality'] ?? '' ) ),
				'status' => $status,
				// Advertising hashes: SHA-256(normalized_value) — required by Meta CAPI and Google OCI.
				// Internal HMAC hashes stay in es_leads for deduplication; they are NOT used here.
				'email_hash' => self::advertising_hash( (string) ( $lead['email_normalized'] ?? '' ) ),
				'phone_hash' => self::advertising_hash( (string) ( $lead['phone_normalized'] ?? '' ) ),
				'session_id' => $identity['session_id'],
				'gclid' => $identity['gclid'],
				'gbraid' => $identity['gbraid'],
				'wbraid' => $identity['wbraid'],
				'fbclid' => $identity['fbclid'],
				'meta_fbc' => $identity['meta_fbc'],
				'meta_fbp' => $identity['meta_fbp'],
				'ttclid' => $identity['ttclid'],
				'msclkid' => $identity['msclkid'],
				'identity_json' => wp_json_encode( $identity ),
				'payload_json' => wp_json_encode( $payload ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		if ( ! $inserted ) {
			return 0;
		}

		$signal_id = absint( $wpdb->insert_id );
		self::enqueue( $signal_id, $record_export_status );
		do_action( 'estandar_signal_core_recorded', $signal_id, $event_name, $payload );

		return $signal_id;
	}

	/**
	 * Persist a signal from a normalized AdvertisingEvent (decoupled contract,
	 * Phase 1). Signal Core here is pure infrastructure: it derives ONLY technical
	 * fields (advertising_hash, idempotency_key, lifecycle_key fallback,
	 * export_status) and NEVER reads es_leads.status, applies a commercial
	 * whitelist, calls event_for_status(), nor re-interprets the domain — Leads
	 * already decided this fact is exportable.
	 *
	 * Shares the idempotency machine with record_from_lead(): identical
	 * idempotency_key → the existing-row guard dedups the same fact whether it
	 * arrives via this hook or the legacy fallback listener.
	 *
	 * @param array $event  estandar_advertising_event_ready payload.
	 * @return int signal_id, or 0 when invalid / duplicate / insert failed.
	 */
	public static function record_from_event( array $event ): int {
		global $wpdb;

		$lead_id    = absint( $event['lead_id'] ?? 0 );
		$event_name = self::event_name( (string) ( $event['event_name'] ?? '' ) );
		if ( $lead_id <= 0 || $event_name === '' ) {
			return 0; // minimal schema validation — technical, not commercial
		}

		$submission_id = absint( $event['submission_id'] ?? 0 );
		$event_time    = self::datetime( (string) ( $event['occurred_at'] ?? '' ) );
		$project_slug  = sanitize_title( (string) ( $event['project_slug'] ?? '' ) );
		$client_id     = self::client_id( $project_slug );

		$identifiers = is_array( $event['identifiers'] ?? null ) ? $event['identifiers'] : array();
		$identity_in = is_array( $event['identity'] ?? null ) ? $event['identity'] : array();

		// lifecycle_key: use the one Leads computed; derive only if absent.
		$lifecycle_key = (string) ( $event['lifecycle_key'] ?? '' );
		if ( $lifecycle_key === '' ) {
			$lifecycle_key = self::lifecycle_key( $lead_id, $event_name, '', $event_time, 0 );
		}
		$idempotency_key = hash( 'sha256', implode( '|', array( 'signal_core_v2', $lead_id, $event_name, $lifecycle_key ) ) );

		$existing_id = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . Estandar_Signal_Core_Schema::events_table() . ' WHERE idempotency_key = %s LIMIT 1',
					$idempotency_key
				)
			)
		);
		if ( $existing_id > 0 ) {
			return $existing_id; // dedup: same fact via new hook OR legacy fallback
		}

		// Canonical AdvertisingEvent rows are an audit ledger only. Platform
		// delivery belongs to the Leads adapters, which enforce channel + click
		// evidence. Enqueuing Signal exporters here would create a second send
		// path that cannot consume that canonical platform decision.
		$has_exporter         = false;
		$record_export_status = 'audit_only';

		$identity = array(
			'client_id'    => $client_id,
			'project_slug' => $project_slug,
			'lead_uuid'    => (string) ( $identity_in['lead_uuid'] ?? '' ),
			'session_id'   => (string) ( $identity_in['session_id'] ?? '' ),
			'gclid'        => (string) ( $identifiers['gclid'] ?? '' ),
			'gbraid'       => (string) ( $identifiers['gbraid'] ?? '' ),
			'wbraid'       => (string) ( $identifiers['wbraid'] ?? '' ),
			'fbclid'       => (string) ( $identifiers['fbclid'] ?? '' ),
			'meta_fbc'     => (string) ( $identifiers['meta_fbc'] ?? '' ),
			'meta_fbp'     => (string) ( $identifiers['meta_fbp'] ?? '' ),
			'ttclid'       => (string) ( $identifiers['ttclid'] ?? '' ),
			'msclkid'      => (string) ( $identifiers['msclkid'] ?? '' ),
		);

		$value    = ( isset( $event['value'] ) && is_numeric( $event['value'] ) ) ? (float) $event['value'] : null;
		$currency = sanitize_text_field( (string) ( $event['currency'] ?? '' ) );

		$payload = array(
			'source'     => 'advertising_event',
			'audit_only' => ! $has_exporter,
		);

		self::ensure_client( $client_id );

		$now      = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			Estandar_Signal_Core_Schema::events_table(),
			array(
				'signal_uuid'      => wp_generate_uuid4(),
				'client_id'        => $client_id,
				'project_slug'     => $project_slug,
				'lead_id'          => $lead_id,
				'submission_id'    => $submission_id > 0 ? $submission_id : null,
				'source_event_id'  => null,
				'source_plugin'    => 'estandar-leads',
				'event_name'       => $event_name,
				'event_stage'      => self::event_stage( $event_name ),
				'lifecycle_key'    => $lifecycle_key,
				'idempotency_key'  => $idempotency_key,
				'occurrence_index' => self::next_occurrence_index( $lead_id, $event_name ),
				'audit_only'       => $has_exporter ? 0 : 1,
				'export_status'    => $record_export_status,
				'event_time'       => $event_time,
				'value_decimal'    => $value,
				'currency'         => $currency,
				'quality'          => sanitize_key( (string) ( $event['meta']['quality'] ?? '' ) ),
				// New contract carries NO raw commercial status by design.
				'status'           => '',
				'email_hash'       => self::advertising_hash( (string) ( $identity_in['email_normalized'] ?? '' ) ),
				'phone_hash'       => self::advertising_hash( (string) ( $identity_in['phone_normalized'] ?? '' ) ),
				'session_id'       => $identity['session_id'],
				'gclid'            => $identity['gclid'],
				'gbraid'           => $identity['gbraid'],
				'wbraid'           => $identity['wbraid'],
				'fbclid'           => $identity['fbclid'],
				'meta_fbc'         => $identity['meta_fbc'],
				'meta_fbp'         => $identity['meta_fbp'],
				'ttclid'           => $identity['ttclid'],
				'msclkid'          => $identity['msclkid'],
				'identity_json'    => wp_json_encode( $identity ),
				'payload_json'     => wp_json_encode( $payload ),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		if ( ! $inserted ) {
			return 0;
		}

		$signal_id = absint( $wpdb->insert_id );
		self::enqueue( $signal_id, $record_export_status );
		do_action( 'estandar_signal_core_recorded', $signal_id, $event_name, $payload );

		return $signal_id;
	}

	private static function ensure_client( string $client_id ): void {
		global $wpdb;
		if ( $client_id === '' ) {
			return;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . Estandar_Signal_Core_Schema::clients_table() . "
				 (client_id, client_name, status, created_at, updated_at)
				 VALUES (%s, %s, 'active', %s, %s)",
				$client_id,
				$client_id,
				$now,
				$now
			)
		);
	}

	private static function enqueue( int $signal_id, string $queue_status = 'audit_only' ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . Estandar_Signal_Core_Schema::queue_table() . "
				 (signal_id, queue_status, attempts, next_run_at, created_at, updated_at)
				 VALUES (%d, %s, 0, %s, %s, %s)",
				$signal_id,
				$queue_status,
				$now,
				$now,
				$now
			)
		);
	}

	private static function next_occurrence_index( int $lead_id, string $event_name ): int {
		global $wpdb;
		return 1 + (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . Estandar_Signal_Core_Schema::events_table() . ' WHERE lead_id = %d AND event_name = %s',
				$lead_id,
				$event_name
			)
		);
	}

	/**
	 * Ensures estandar_signal_core_client_id is persisted on first use.
	 * Called on activation so the derived value is stable and doesn't
	 * re-derive from home_url() on every request.
	 */
	public static function bootstrap_client_id(): void {
		if ( get_option( 'estandar_signal_core_client_id' ) !== false ) {
			return; // Already set — never overwrite.
		}
		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$derived = $host ? preg_replace( '/^www\./', '', (string) $host ) : 'default';
		$derived = sanitize_title( str_replace( '.', '-', $derived ) );
		update_option( 'estandar_signal_core_client_id', $derived ?: 'default', false );
	}

	private static function client_id( string $project_slug ): string {
		$client_id = sanitize_title( (string) get_option( 'estandar_signal_core_client_id', 'default' ) );
		if ( $client_id === '' ) {
			$client_id = 'default';
		}
		return apply_filters( 'estandar_signal_core_client_id', $client_id, sanitize_title( $project_slug ) );
	}

	private static function lifecycle_key( int $lead_id, string $event_name, string $status, string $event_time, int $source_event_id ): string {
		if ( $source_event_id > 0 ) {
			return 'lead_event:' . $source_event_id;
		}
		return implode( ':', array( 'lead', $lead_id, $event_name, $status, $event_time ) );
	}

	private static function event_stage( string $event_name ): string {
		$map = array(
			'lead_created' => 'capture',
			'lead_contacted' => 'management',
			'lead_qualified' => 'qualification',
			'meeting_booked' => 'opportunity',
			'sale_closed' => 'sale',
			'lead_rejected' => 'rejection',
		);
		return $map[ $event_name ] ?? 'unknown';
	}

	private static function event_name( string $name ): string {
		$name = sanitize_key( $name );
		return in_array( $name, array( 'lead_created', 'lead_contacted', 'lead_qualified', 'meeting_booked', 'sale_closed', 'lead_rejected' ), true ) ? $name : '';
	}

	/**
	 * Advertising hash: SHA-256(lowercase trimmed value).
	 *
	 * Used by exporters (Meta CAPI, Google OCI). NOT the same as the HMAC stored
	 * in es_leads.email_hash — that is for internal identity/deduplication only.
	 *
	 * Public so Schema::repair_advertising_hashes() can call it during migration.
	 */
	public static function advertising_hash( string $value ): string {
		$value = strtolower( trim( $value ) );
		return $value !== '' ? hash( 'sha256', $value ) : '';
	}

	private static function datetime( $value ): string {
		$timestamp = is_string( $value ) && $value !== '' ? strtotime( $value ) : false;
		return gmdate( 'Y-m-d H:i:s', $timestamp ?: time() );
	}
}
