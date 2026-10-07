<?php
/**
 * Webhook Destination — Estandar_Destination adapter for external HTTP endpoints.
 *
 * Sends events to configured URLs using the canonical event contract.
 * Uses es_signal_queue for durability, retry, and idempotency.
 *
 * @package Estandar_Destinations
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Webhook_Destination implements Estandar_Destination {

	const OPTION_PREFIX = 'estandar_webhook_dest_';
	const QUEUE_TABLE   = 'es_signal_queue';

	public function id(): string { return 'webhook'; }
	public function label(): string { return 'Webhook'; }

	public function capabilities(): array {
		return array( 'chat', 'stream' );
	}

	/**
	 * Returns true when at least one webhook endpoint is configured.
	 */
	public function is_configured(): bool {
		$destinations = $this->get_destinations();
		return ! empty( $destinations );
	}

	/**
	 * Handle all supported events.
	 */
	public function should_handle( string $event, array $payload ): bool {
		return in_array( $event, array( 'lead.created', 'lead.recontacted', 'lead.status.changed', 'sale.closed' ), true );
	}

	/**
	 * Enqueue the event to es_signal_queue for each configured webhook URL.
	 */
	public function enqueue( string $event, array $payload ): void {
		$destinations = $this->get_destinations();
		if ( empty( $destinations ) ) {
			return;
		}

		$lead_id       = (int) ( $payload['lead_id'] ?? 0 );
		$submission_id = (int) ( $payload['submission_id'] ?? 0 );
		$raw           = is_array( $payload['payload'] ?? null ) ? $payload['payload'] : array();

		// Build canonical envelope
		$occurred_at = gmdate( 'Y-m-d\TH:i:s\Z' );
		$producer    = (string) ( $raw['source_plugin'] ?? 'estandar-leads' );
		$project     = $this->resolve_project( $raw );
		$source      = (string) ( $raw['source_plugin'] ?? '' );
		$correlation_id = $this->resolve_correlation_id( $raw );

		$event_id_input = $producer . '|' . $event . '|' . $lead_id . '|' . $submission_id . '|' . $occurred_at;
		$event_id       = 'ev_' . substr( hash( 'sha256', $event_id_input ), 0, 20 );

		$envelope = array(
			'schema_version' => '1.0',
			'event'          => $event,
			'event_id'       => $event_id,
			'occurred_at'    => $occurred_at,
			'producer'       => $producer,
			'project'        => $project,
			'source'         => $source,
			'correlation_id' => $correlation_id,
			'entity'         => array( 'type' => 'lead', 'id' => $lead_id ),
			'data'           => $this->build_event_data( $event, $lead_id, $submission_id, $raw ),
		);

		$body = wp_json_encode( $envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! $body ) {
			return;
		}

		foreach ( $destinations as $dest ) {
			$url = trim( (string) ( $dest['url'] ?? '' ) );
			if ( $url === '' || ! wp_http_validate_url( $url ) ) {
				continue;
			}
			$secret = (string) ( $dest['secret'] ?? '' );
			$this->enqueue_for_url( $event_id, $url, $secret, $body, $envelope, $lead_id, $submission_id );
		}
	}

	// ─── Queue ───────────────────────────────────────────────────────────

	private function enqueue_for_url( string $event_id, string $url, string $secret, string $body, array $envelope, int $lead_id, int $submission_id ): void {
		global $wpdb;

		$now      = current_time( 'mysql' );
		$events_table = $wpdb->prefix . 'es_signal_events';
		$queue_table  = $wpdb->prefix . 'es_signal_queue';

		$signal_uuid = 'wh_' . substr( hash( 'sha256', $event_id . '|' . $url . '|' . $secret ), 0, 16 );

		// 1. Insert or skip signal event (idempotent via UNIQUE idempotency_key)
		$idempotency_key = 'wh:' . $event_id . ':' . md5( $url );
		$wpdb->suppress_errors = true;
		$wpdb->insert(
			$events_table,
			array(
				'signal_uuid'     => $signal_uuid,
				'client_id'       => 'estandar-webhook',
				'source_plugin'   => 'estandar-destinations',
				'event_name'      => $envelope['event'],
				'idempotency_key' => $idempotency_key,
				'audit_only'      => 0,
				'export_status'   => 'pending',
				'payload_json'    => $body,
				'lead_id'         => $lead_id,
				'event_time'      => $envelope['occurred_at'],
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors = false;
		$signal_id = $wpdb->insert_id;
		if ( ! $signal_id ) {
			return;
		}

		// 2. Enqueue to signal queue (UNIQUE signal_id prevents duplicates)
		$wpdb->suppress_errors = true;
		$wpdb->insert(
			$queue_table,
			array(
				'signal_id'    => $signal_id,
				'queue_status' => 'pending',
				'attempts'     => 0,
				'next_run_at'  => $now,
				'last_error'   => wp_json_encode( array(
					'url'    => $url,
					'secret' => $secret !== '' ? 'configured' : '',
				) ),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors = false;
	}

	// ─── Helpers ─────────────────────────────────────────────────────────

	private function get_destinations(): array {
		$raw = get_option( self::OPTION_PREFIX . 'list', array() );
		return is_array( $raw ) ? $raw : array();
	}

	private function resolve_project( array $raw ): string {
		if ( ! empty( $raw['context']['project_slug'] ) ) {
			return sanitize_title( (string) $raw['context']['project_slug'] );
		}
		if ( ! empty( $raw['project_slug'] ) ) {
			return sanitize_title( (string) $raw['project_slug'] );
		}
		return '';
	}

	private function resolve_correlation_id( array $raw ): string {
		if ( ! empty( $raw['correlation_id'] ) ) {
			return sanitize_text_field( (string) $raw['correlation_id'] );
		}
		return 'c_' . substr( hash( 'sha256', microtime( true ) . wp_rand() ), 0, 32 );
	}

	private function build_event_data( string $event, int $lead_id, int $submission_id, array $raw ): array {
		$contact = is_array( $raw['contact'] ?? null ) ? $raw['contact'] : array();
		$attr    = is_array( $raw['attribution'] ?? null ) ? $raw['attribution'] : array();

		$data = array(
			'lead' => array(
				'id'    => $lead_id,
				'name'  => sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
				'email' => sanitize_email( (string) ( $contact['email'] ?? '' ) ),
				'phone' => sanitize_text_field( (string) ( $contact['phone'] ?? '' ) ),
			),
			'submission' => array(
				'id'            => $submission_id,
				'source_plugin' => sanitize_key( (string) ( $raw['source_plugin'] ?? '' ) ),
				'utm_source'    => sanitize_text_field( (string) ( $attr['utm_source'] ?? '' ) ),
				'utm_medium'    => sanitize_text_field( (string) ( $attr['utm_medium'] ?? '' ) ),
				'utm_campaign'  => sanitize_text_field( (string) ( $attr['utm_campaign'] ?? '' ) ),
			),
		);

		$project_slug = $this->resolve_project( $raw );
		if ( $project_slug !== '' ) {
			$data['commercial'] = array( 'project_slug' => $project_slug );
		}

		return $data;
	}
}
