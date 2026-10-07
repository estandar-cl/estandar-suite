<?php
/**
 * Webhook Worker — drains es_signal_queue for webhook destinations.
 *
 * Cron-driven worker: reads pending queue rows, builds signed HTTP requests,
 * dispatches, and updates queue_status (succeeded/retry/dead).
 *
 * @package Estandar_Destinations
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Webhook_Worker {

	const MAX_ATTEMPTS   = 5;
	const BATCH_SIZE     = 20;
	const CRON_HOOK      = 'estandar_webhook_process_queue';

	/**
	 * Schedule the cron hook on plugin activation.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'every_minute', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the cron hook on deactivation.
	 */
	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Process the queue: pick pending/failed rows and dispatch.
	 */
	public static function process(): void {
		global $wpdb;

		$queue_table  = $wpdb->prefix . 'es_signal_queue';
		$events_table = $wpdb->prefix . 'es_signal_events';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT q.id AS queue_id, q.signal_id, q.attempts, q.last_error AS queue_meta,
			        e.payload_json, e.event_name, e.idempotency_key
			 FROM {$queue_table} q
			 INNER JOIN {$events_table} e ON e.id = q.signal_id
			 WHERE q.queue_status IN ('pending', 'retry')
			   AND q.next_run_at <= %s
			 ORDER BY q.created_at ASC
			 LIMIT %d",
			current_time( 'mysql' ),
			self::BATCH_SIZE
		) );

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			self::process_row( $row, $queue_table, $events_table );
		}
	}

	private static function process_row( $row, string $queue_table, string $events_table ): void {
		global $wpdb;

		$queue_id   = (int) $row->queue_id;
		$signal_id  = (int) $row->signal_id;
		$attempts   = (int) $row->attempts;
		$payload    = (string) ( $row->payload_json ?? '' );
		$event_name = (string) ( $row->event_name ?? '' );
		$queue_meta = json_decode( (string) ( $row->queue_meta ?? '{}' ), true );
		$queue_meta = is_array( $queue_meta ) ? $queue_meta : array();

		$url    = (string) ( $queue_meta['url'] ?? '' );
		$secret = (string) ( $queue_meta['secret'] ?? '' );

		if ( $url === '' || $payload === '' ) {
			$wpdb->update( $queue_table, array( 'queue_status' => 'dead', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $queue_id ) );
			return;
		}

		// Build signed request
		$timestamp = gmdate( 'Y-m-d\TH:i:s\Z' );
		$body_raw  = $payload;
		$signature = self::sign_body( $secret, $timestamp, $row->idempotency_key, $body_raw );

		$response = wp_remote_post( $url, array(
			'timeout'   => 15,
			'blocking'  => true,
			'sslverify' => true,
			'headers'   => array(
				'Content-Type'              => 'application/json',
				'X-Estandar-Event'          => $event_name,
				'X-Estandar-Event-ID'       => (string) ( $row->idempotency_key ?? '' ),
				'X-Estandar-Schema-Version' => '1.0',
				'X-Estandar-Timestamp'      => $timestamp,
				'X-Estandar-Signature'      => 'sha256=' . $signature,
				'User-Agent'                => 'Estandar-Webhook/1.0',
			),
			'body' => $body_raw,
		) );

		$now       = current_time( 'mysql' );
		$new_status = 'succeeded';
		$error_msg  = '';

		if ( is_wp_error( $response ) ) {
			$new_status = 'retry';
			$error_msg  = $response->get_error_message();
		} else {
			$code = wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				$new_status = 'succeeded';
			} elseif ( $code === 429 || $code >= 500 ) {
				$new_status = 'retry';
				$error_msg  = 'HTTP ' . $code;
			} else {
				// 4xx (except 429) → permanent failure
				$new_status = 'dead';
				$error_msg  = 'HTTP ' . $code . ' ' . wp_remote_retrieve_response_message( $response );
			}
		}

		$new_attempts = $attempts + 1;

		// If retry exceeded max attempts → dead
		if ( $new_status === 'retry' && $new_attempts >= self::MAX_ATTEMPTS ) {
			$new_status = 'dead';
		}

		$update_data = array(
			'queue_status' => $new_status,
			'attempts'     => $new_attempts,
			'last_error'   => $error_msg !== '' ? $error_msg : '',
			'updated_at'   => $now,
			'next_run_at'  => $new_status === 'retry' ? self::backoff_time( $new_attempts ) : null,
		);
		$wpdb->update( $queue_table, $update_data, array( 'id' => $queue_id ) );

		// Update signal event status
		$wpdb->update( $events_table, array(
			'export_status' => $new_status,
			'updated_at'    => $now,
		), array( 'id' => $signal_id ) );

		do_action( 'estandar_telemetry_event', array(
			'source_plugin' => 'estandar-destinations',
			'type'          => 'business_event',
			'severity'      => $new_status === 'succeeded' ? 'info' : ( $new_status === 'dead' ? 'error' : 'warning' ),
			'code'          => 'WEBHOOK_DELIVERY_' . strtoupper( $new_status ),
			'message'       => "Webhook delivery {$new_status} after {$new_attempts} attempt(s)",
			'context'       => array(
				'url'          => $url,
				'event'        => $event_name,
				'status'       => $new_status,
				'attempts'     => $new_attempts,
				'signal_id'    => $signal_id,
				'error'        => $error_msg,
			),
		) );
	}

	private static function sign_body( string $secret, string $timestamp, string $event_id, string $body ): string {
		if ( $secret === '' ) {
			return '';
		}
		return hash_hmac( 'sha256', $timestamp . '.' . $event_id . '.' . $body, $secret );
	}

	private static function backoff_time( int $attempt ): string {
		$minutes = array( 0, 1, 5, 15, 30, 60 );
		$wait    = $minutes[ min( $attempt, count( $minutes ) - 1 ) ];
		return gmdate( 'Y-m-d H:i:s', time() + $wait * 60 );
	}
}
