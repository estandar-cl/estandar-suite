<?php
/**
 * Google Ads Offline Conversion Import — Destinations adapter.
 *
 * Extracted from estandar-leads/includes/class-estandar-leads-ads-oci.php.
 * Implements Estandar_Destination so the Destinations Engine can dispatch to it.
 *
 * PHASE 1 — RETIRED (2026-07-21):
 *   - Adapter kept for health-check enumeration only.
 *   - Does NOT subscribe to estandar_lead_status_changed.
 *   - Does NOT schedule a cron worker.
 *   - All Google Ads conversions are handled by Estandar_Leads_Google_Adapter
 *     (Data Manager API) in estandar-leads.
 *   - enqueue() and process_queue() remain implemented for reference only.
 *
 * @see Estandar_Leads_Google_Adapter (canonical Google Ads implementation)
 *
 * Option prefix:  estandar_leads_gads_   (preserved — legacy, not used)
 * Queue table:    es_oci_queue           (legacy, 0 active rows)
 *
 * @package EstandarDestinations
 * @since   13.0.15
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Google_Ads_Destination implements Estandar_Destination {

	const OPTION_PREFIX = 'estandar_leads_gads_';
	const API_VERSION   = 'v24';
	const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
	const API_BASE      = 'https://googleads.googleapis.com';
	const MAX_ATTEMPTS  = 3;
	const RETRY_AFTER   = 3600;

	/**
	 * Standalone fallback — exportable statuses when Commercial Outcomes is absent.
	 * Must stay identical to Estandar_Leads_Ads_OCI::CONVERSION_STATUSES.
	 */
	const CONVERSION_STATUSES = array(
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

	// ── Estandar_Destination interface ─────────────────────────────────────────

	public function id(): string {
		return 'google-ads-oci';
	}

	public function label(): string {
		return 'Google Ads — Conversiones Offline';
	}

	public function is_configured(): bool {
		return $this->option( 'customer_id' ) !== ''
			&& $this->option( 'developer_token' ) !== ''
			&& $this->option( 'client_id' ) !== ''
			&& $this->option( 'client_secret' ) !== ''
			&& $this->option( 'refresh_token' ) !== ''
			&& ( $this->option( 'conversion_action_id' ) !== '' || $this->option( 'conversion_action_map' ) !== '' );
	}

	/**
	 * Returns true when the engine should route this event to this adapter.
	 *
	 * Expected event: 'lead_status_changed'
	 * Expected payload keys: lead_id (int), new_status (string), changed_at (string)
	 *
	 * @param string $event
	 * @param array  $payload
	 */
	public function should_handle( string $event, array $payload ): bool {
		if ( $event !== 'lead_status_changed' ) {
			return false;
		}
		if ( ! $this->is_configured() ) {
			return false;
		}
		$status = (string) ( $payload['new_status'] ?? '' );
		return $this->is_exportable_status( $status );
	}

	/**
	 * Enqueue a conversion upload from a Destinations Engine dispatch call.
	 *
	 * @param string $event
	 * @param array  $payload  { lead_id: int, new_status: string, changed_at: string }
	 */
	public function enqueue( string $event, array $payload ): void {
		$lead_id    = (int) ( $payload['lead_id'] ?? 0 );
		$status     = (string) ( $payload['new_status'] ?? '' );
		$changed_at = (string) ( $payload['changed_at'] ?? gmdate( 'Y-m-d H:i:s' ) );

		if ( $lead_id <= 0 || $status === '' ) {
			return;
		}

		$this->enqueue_item( $lead_id, $status, $changed_at );
	}

	// ── Event subscriber ───────────────────────────────────────────────────────

	/**
	 * Matches the estandar_lead_status_changed hook signature (4 args).
	 */
	public function on_status_changed( $lead_id, $old_status, $new_status, $changed_at ): void {
		if ( ! $this->is_exportable_status( (string) $new_status ) ) {
			return;
		}
		if ( ! $this->is_configured() ) {
			return;
		}
		$this->enqueue_item( (int) $lead_id, (string) $new_status, (string) $changed_at );
	}

	// ── Queue ──────────────────────────────────────────────────────────────────

	/**
	 * Write a conversion job to the queue.
	 * Idempotent: UNIQUE KEY (lead_id, conversion_status) absorbs duplicates silently.
	 */
	private function enqueue_item( int $lead_id, string $conversion_status, string $changed_at ): void {
		global $wpdb;

		$lead = $this->get_lead( $lead_id );
		if ( ! $lead ) {
			return;
		}

		$click = $this->click_id_for_lead( $lead );
		if ( $click['value'] === '' ) {
			return;
		}

		$conversion_action = $this->conversion_action_resource( $lead, $conversion_status );
		if ( $conversion_action === '' ) {
			$this->log( 'warning', 'OCI_MAPPING_MISSING', 'No conversion action mapping for lead/status.', array(
				'lead_id'      => $lead_id,
				'project_uuid' => $this->project_uuid_for_lead( $lead ),
				'status'       => $conversion_status,
			) );
			return;
		}

		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->prefix}es_oci_queue
			 (lead_id, conversion_status, gclid, conversion_action, conversion_time, queue_status, attempts, created_at)
			 VALUES (%d, %s, %s, %s, %s, 'pending', 0, %s)",
			$lead_id,
			$conversion_status,
			$click['value'],
			$conversion_action,
			$this->format_datetime( $changed_at ?: gmdate( 'Y-m-d H:i:s' ) ),
			gmdate( 'Y-m-d H:i:s' )
		) );
	}

	/**
	 * WP-Cron callback — processes pending and retryable failed items.
	 * Up to 20 items per run to avoid timeouts.
	 * Called by the Destinations Scheduler in Phase 2.
	 */
	public function process_queue(): void {
		global $wpdb;

		if ( ! $this->is_configured() ) {
			return;
		}

		$retry_threshold = gmdate( 'Y-m-d H:i:s', time() - self::RETRY_AFTER );

		$items = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}es_oci_queue
			 WHERE queue_status IN ('pending', 'failed')
			   AND attempts < %d
			   AND (last_attempted_at IS NULL OR last_attempted_at <= %s)
			 ORDER BY created_at ASC
			 LIMIT 20",
			self::MAX_ATTEMPTS,
			$retry_threshold
		) );

		if ( empty( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			$this->process_item( $item );
		}
	}

	private function process_item( object $item ): void {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$wpdb->update(
			$wpdb->prefix . 'es_oci_queue',
			array(
				'attempts'          => (int) $item->attempts + 1,
				'last_attempted_at' => $now,
			),
			array( 'id' => (int) $item->id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		$lead      = $this->get_lead( (int) $item->lead_id );
		$lead_lost = ! $lead;
		if ( $lead_lost ) {
			$lead = (object) array(
				'id'           => (int) $item->lead_id,
				'lead_uuid'    => '',
				'project_slug' => '',
				'gclid'        => (string) $item->gclid,
				'gbraid'       => '',
				'wbraid'       => '',
			);
		}

		$event_id   = $this->event_id( $lead, $item->conversion_status );
		$project_id = $this->project_uuid_for_lead( $lead );
		$click      = $this->click_id_for_lead( $lead, (string) $item->gclid );
		$value      = $lead_lost ? null : $this->conversion_value( $lead, $item->conversion_status );
		$currency   = $lead_lost ? '' : $this->currency_for( $lead, $item->conversion_status );

		if ( $this->option( 'test_mode' ) === '1' ) {
			$this->log( 'info', 'OCI_TEST_MODE', 'Test mode active — conversion not sent.', array(
				'queue_id'          => (int) $item->id,
				'lead_id'           => (int) $item->lead_id,
				'project_uuid'      => $project_id,
				'conversion_status' => $item->conversion_status,
				'event_id'          => $event_id,
				'click_id_type'     => $click['field'],
				'click_id_pfx'      => substr( (string) $click['value'], 0, 8 ) . '***',
				'conversion_action' => $item->conversion_action,
				'conversion_time'   => $item->conversion_time,
				'conversion_value'  => $value,
			) );
			$wpdb->update(
				$wpdb->prefix . 'es_oci_queue',
				array( 'queue_status' => 'success', 'last_error' => 'TEST_MODE ' . $event_id ),
				array( 'id' => (int) $item->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$result = $lead_lost
			? new WP_Error( 'oci_lead_missing', 'Lead missing for queued OCI export.' )
			: $this->upload_click_conversion( array(
				'click_id_field'    => $click['field'],
				'click_id'          => $click['value'],
				'conversion_action' => $item->conversion_action,
				'conversion_time'   => $item->conversion_time,
				'event_id'          => $event_id,
				'conversion_value'  => $value,
				'currency'          => $currency,
			) );

		if ( is_wp_error( $result ) ) {
			$new_attempts = (int) $item->attempts + 1;
			$new_status   = $new_attempts >= self::MAX_ATTEMPTS ? 'dead' : 'failed';

			$wpdb->update(
				$wpdb->prefix . 'es_oci_queue',
				array(
					'queue_status' => $new_status,
					'last_error'   => substr( $result->get_error_message(), 0, 500 ),
				),
				array( 'id' => (int) $item->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$this->log(
				$new_status === 'dead' ? 'error' : 'warning',
				$new_status === 'dead' ? 'OCI_DEAD_LETTER' : 'OCI_UPLOAD_FAILED',
				$result->get_error_message(),
				array(
					'queue_id'      => (int) $item->id,
					'lead_id'       => (int) $item->lead_id,
					'event_id'      => $event_id,
					'status'        => $item->conversion_status,
					'attempts'      => $new_attempts,
					'click_id_type' => $click['field'],
					'click_id_pfx'  => substr( (string) $click['value'], 0, 8 ) . '***',
				)
			);
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'es_oci_queue',
			array( 'queue_status' => 'success', 'last_error' => substr( wp_json_encode( $result ), 0, 500 ) ),
			array( 'id' => (int) $item->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$this->log( 'info', 'OCI_UPLOAD_SUCCESS', 'Conversion uploaded to Google Ads.', array(
			'queue_id'     => (int) $item->id,
			'lead_id'      => (int) $item->lead_id,
			'project_uuid' => $project_id,
			'event_id'     => $event_id,
			'status'       => $item->conversion_status,
		) );
	}

	// ── Google Ads API ─────────────────────────────────────────────────────────

	private function upload_click_conversion( array $conversion ) {
		$customer_id = $this->option( 'customer_id' );
		$dev_token   = $this->option( 'developer_token' );

		if ( ! $customer_id || ! $dev_token ) {
			return new WP_Error( 'gads_not_configured', 'Google Ads credentials not configured.' );
		}

		$access_token = $this->get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$url = sprintf(
			'%s/%s/customers/%s:uploadClickConversions',
			self::API_BASE,
			self::API_VERSION,
			$customer_id
		);

		$item = array(
			$conversion['click_id_field'] => $conversion['click_id'],
			'conversionAction'            => $conversion['conversion_action'],
			'conversionDateTime'          => $conversion['conversion_time'],
			'orderId'                     => $conversion['event_id'],
		);
		if ( $conversion['conversion_value'] !== null ) {
			$item['conversionValue'] = (float) $conversion['conversion_value'];
			$currency = (string) ( $conversion['currency'] ?? '' );
			if ( $currency !== '' ) {
				$item['currencyCode'] = $currency;
			}
		}

		$body = array(
			'conversions'    => array( $item ),
			'partialFailure' => true,
		);

		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization'   => 'Bearer ' . $access_token,
				'developer-token' => $dev_token,
				'Content-Type'    => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code !== 200 ) {
			$msg = $data['error']['message'] ?? "HTTP {$code}";
			return new WP_Error( 'gads_api_error', $msg, array( 'http_code' => $code, 'body' => $raw ) );
		}

		if ( ! empty( $data['partialFailureError'] ) ) {
			$msg = $data['partialFailureError']['message'] ?? 'Partial failure in conversion upload.';
			return new WP_Error( 'gads_partial_failure', $msg, $data );
		}

		return is_array( $data ) ? $data : array();
	}

	private function get_access_token() {
		$client_id     = $this->option( 'client_id' );
		$client_secret = $this->option( 'client_secret' );
		$refresh_token = $this->option( 'refresh_token' );

		if ( ! $client_id || ! $client_secret || ! $refresh_token ) {
			return new WP_Error( 'gads_missing_oauth', 'OAuth2 credentials not configured.' );
		}

		$response = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 10,
			'body'    => array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'refresh_token' => $refresh_token,
				'grant_type'    => 'refresh_token',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error(
				'gads_oauth_failed',
				$data['error_description'] ?? ( $data['error'] ?? 'Token refresh failed.' )
			);
		}

		return $data['access_token'];
	}

	// ── Admin helpers ──────────────────────────────────────────────────────────

	public function queue_summary(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'es_oci_queue';

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array( 'pending' => 0, 'success' => 0, 'failed' => 0, 'dead' => 0, 'total' => 0 );
		}

		$rows = $wpdb->get_results(
			"SELECT queue_status, COUNT(*) AS cnt FROM {$table} GROUP BY queue_status",
			ARRAY_A
		);

		$summary = array( 'pending' => 0, 'success' => 0, 'failed' => 0, 'dead' => 0, 'total' => 0 );
		foreach ( (array) $rows as $row ) {
			$key = sanitize_key( $row['queue_status'] );
			if ( isset( $summary[ $key ] ) ) {
				$summary[ $key ] = (int) $row['cnt'];
			}
			$summary['total'] += (int) $row['cnt'];
		}
		return $summary;
	}

	public function queue_recent( int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'es_oci_queue';

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT q.id, q.lead_id, q.conversion_status, q.gclid, q.conversion_action, q.queue_status,
			        q.attempts, q.last_attempted_at, q.last_error, q.created_at,
			        l.lead_uuid, l.project_slug, l.first_gclid, l.first_gbraid, l.first_wbraid
			 FROM {$table} q
			 LEFT JOIN {$wpdb->prefix}es_leads l ON l.id = q.lead_id
			 ORDER BY q.id DESC
			 LIMIT %d",
			max( 1, $limit )
		) );

		foreach ( $rows as $row ) {
			$lead = (object) array(
				'id'           => (int) $row->lead_id,
				'lead_uuid'    => (string) $row->lead_uuid,
				'project_slug' => (string) $row->project_slug,
				'gclid'        => (string) $row->first_gclid,
				'gbraid'       => (string) $row->first_gbraid,
				'wbraid'       => (string) $row->first_wbraid,
			);
			$click = $this->click_id_for_lead( $lead, (string) $row->gclid );
			$row->platform      = 'google';
			$row->project_uuid  = $this->project_uuid_for_lead( $lead );
			$row->event_id      = $this->event_id( $lead, $row->conversion_status );
			$row->click_id_type = $click['field'];
			$row->queued_at     = $row->created_at;
			$row->sent_at       = $row->queue_status === 'success' ? $row->last_attempted_at : '';
		}

		return $rows;
	}

	public function save_settings( array $post ): void {
		$fields = array(
			'developer_token',
			'client_id',
			'client_secret',
			'refresh_token',
			'customer_id',
			'conversion_action_id',
			'conversion_action_map',
			'conversion_value_map',
			'currency_code',
			'test_mode',
		);
		foreach ( $fields as $field ) {
			$raw   = wp_unslash( $post[ 'gads_' . $field ] ?? '' );
			$value = in_array( $field, array( 'conversion_action_map', 'conversion_value_map' ), true )
				? sanitize_textarea_field( $raw )
				: sanitize_text_field( $raw );
			if ( $field === 'customer_id' || $field === 'conversion_action_id' ) {
				$value = preg_replace( '/\D/', '', $value );
			}
			if ( $field === 'currency_code' ) {
				$value = strtoupper( preg_replace( '/[^A-Z]/', '', strtoupper( $value ) ) );
			}
			if ( $field === 'test_mode' ) {
				$value = $value === '1' ? '1' : '0';
			}
			update_option( self::OPTION_PREFIX . $field, $value, false );
		}
	}

	public function get_settings(): array {
		return array(
			'developer_token'       => $this->option( 'developer_token' ),
			'client_id'             => $this->option( 'client_id' ),
			'client_secret'         => $this->option( 'client_secret' ),
			'refresh_token'         => $this->option( 'refresh_token' ),
			'customer_id'           => $this->option( 'customer_id' ),
			'conversion_action_id'  => $this->option( 'conversion_action_id' ),
			'conversion_action_map' => $this->option( 'conversion_action_map' ),
			'conversion_value_map'  => $this->option( 'conversion_value_map' ),
			'currency_code'         => $this->option( 'currency_code' ),
			'test_mode'             => $this->option( 'test_mode' ),
		);
	}

	// ── Internal helpers ───────────────────────────────────────────────────────

	private function conversion_action_resource( $lead, string $status ): string {
		$action_id    = '';
		$project_uuid = $lead ? $this->project_uuid_for_lead( $lead ) : '';
		$status       = strtoupper( sanitize_key( $status ) );
		$map          = $this->parse_pipe_map( $this->option( 'conversion_action_map' ) );

		foreach ( array( $project_uuid . '|' . $status, $status ) as $key ) {
			if ( isset( $map[ $key ] ) ) {
				$action_id = preg_replace( '/\D/', '', $map[ $key ] );
				break;
			}
		}
		if ( $action_id === '' ) {
			$action_id = preg_replace( '/\D/', '', $this->option( 'conversion_action_id' ) );
		}
		if ( $action_id === '' ) {
			return '';
		}
		return sprintf(
			'customers/%s/conversionActions/%s',
			$this->option( 'customer_id' ),
			$action_id
		);
	}

	private function format_datetime( string $mysql_utc ): string {
		$ts = strtotime( $mysql_utc . ' UTC' );
		return gmdate( 'Y-m-d H:i:s', $ts ?: time() ) . '+00:00';
	}

	private function get_lead( int $lead_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, lead_uuid, project_slug, first_gclid AS gclid, first_gbraid AS gbraid, first_wbraid AS wbraid FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
			$lead_id
		) );
	}

	private function is_exportable_status( string $status ): bool {
		$outcomes = $this->outcomes();
		if ( $outcomes ) {
			return $outcomes->stages()->is_exportable( $status );
		}
		return in_array( $status, self::CONVERSION_STATUSES, true );
	}

	private function outcomes() {
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

	private function click_id_for_lead( $lead, string $stored = '' ): array {
		$ids = array(
			'gclid'  => trim( (string) ( $lead->gclid ?? '' ) ),
			'gbraid' => trim( (string) ( $lead->gbraid ?? '' ) ),
			'wbraid' => trim( (string) ( $lead->wbraid ?? '' ) ),
		);
		$stored = trim( $stored );
		if ( $stored !== '' ) {
			foreach ( $ids as $field => $value ) {
				if ( $value === $stored ) {
					return array( 'field' => $field, 'value' => $value );
				}
			}
		}
		foreach ( $ids as $field => $value ) {
			if ( $value !== '' ) {
				return array( 'field' => $field, 'value' => $value );
			}
		}
		return array( 'field' => 'gclid', 'value' => '' );
	}

	private function event_id( $lead, string $status ): string {
		$lead_uuid = $lead ? trim( (string) ( $lead->lead_uuid ?? '' ) ) : '';
		if ( $lead_uuid === '' && $lead ) {
			$lead_uuid = 'lead-' . (int) $lead->id;
		}
		return $lead_uuid . ':' . strtoupper( sanitize_key( $status ) );
	}

	private function project_uuid_for_lead( $lead ): string {
		$slug = trim( (string) ( $lead->project_slug ?? '' ) );
		if ( $slug === '' || ! class_exists( 'Estandar_Leads_Projects' ) ) {
			return '';
		}
		foreach ( Estandar_Leads_Projects::registry() as $project ) {
			if ( (string) ( $project['project_slug'] ?? '' ) === $slug ) {
				return sanitize_text_field( (string) ( $project['project_uuid'] ?? '' ) );
			}
		}
		return '';
	}

	private function conversion_value( $lead, string $status ) {
		$outcomes = $this->outcomes();
		if ( $outcomes ) {
			$money = $outcomes->value_provider()->value_for( $lead, $status );
			return $money !== null ? $money->amount() : null;
		}

		$project_uuid = $this->project_uuid_for_lead( $lead );
		$status       = strtoupper( sanitize_key( $status ) );
		$map          = $this->parse_pipe_map( $this->option( 'conversion_value_map' ) );
		foreach ( array( $project_uuid . '|' . $status, $status ) as $key ) {
			if ( isset( $map[ $key ] ) && is_numeric( $map[ $key ] ) ) {
				return (float) $map[ $key ];
			}
		}
		return null;
	}

	private function currency_for( $lead, string $status ): string {
		$outcomes = $this->outcomes();
		if ( $outcomes ) {
			$money = $outcomes->value_provider()->value_for( $lead, $status );
			return ( $money !== null && $money->has_currency() ) ? $money->currency() : '';
		}
		return $this->option( 'currency_code' );
	}

	private function parse_pipe_map( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/\R/', $raw ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, '#' ) === 0 ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) === 2 ) {
				$out[ strtoupper( sanitize_key( $parts[0] ) ) ] = $parts[1];
			} elseif ( count( $parts ) >= 3 ) {
				$out[ sanitize_text_field( $parts[0] ) . '|' . strtoupper( sanitize_key( $parts[1] ) ) ] = $parts[2];
			}
		}
		return $out;
	}

	private function option( string $key ): string {
		return (string) get_option( self::OPTION_PREFIX . $key, '' );
	}

	private function log( string $severity, string $code, string $message, array $context = array() ): void {
		try {
			do_action( 'estandar_telemetry_event', array(
				'source_plugin' => 'estandar-destinations',
				'type'          => 'business_event',
				'severity'      => $severity,
				'code'          => $code,
				'message'       => $message,
				'context'       => $context,
			) );
		} catch ( \Throwable $e ) {
			// Telemetry must never break business flow.
		}
	}
}

// ── Registration (health-check only) ──────────────────────────────────────────
add_action( 'estandar_destinations_register', function () {
	$adapter = new Estandar_Google_Ads_Destination();
	Estandar_Destinations_Engine::register( $adapter );

	// RETIRED: event subscription and cron worker removed 2026-07-21.
	// All Google Ads conversions handled by Estandar_Leads_Google_Adapter.
} );
