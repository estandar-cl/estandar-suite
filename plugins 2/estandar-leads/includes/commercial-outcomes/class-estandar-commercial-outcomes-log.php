<?php
/**
 * Commercial Outcomes — execution log (dedicated table).
 *
 * Persists outcome dispatch history to `es_commercial_outcomes_log`. Execution
 * logs MUST NOT live in wp_options — this writes to its own table and mirrors to
 * the established telemetry channel under an independent namespace.
 *
 * @package EstandarLeads\CommercialOutcomes
 * @since   10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Commercial_Outcomes_Log {

	const TABLE = 'es_commercial_outcomes_log';

	/**
	 * Record one dispatched outcome. Returns the inserted row id (0 on failure).
	 */
	public function record( Estandar_Commercial_Outcome $outcome ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'lead_id'       => (int) $outcome->lead_id,
				'company_id'    => $outcome->company_id,
				'project_id'    => $outcome->project_id,
				'stage'         => $outcome->stage_code(),
				'adapter'       => $outcome->adapter,
				'event'         => $outcome->event,
				'payload_json'  => wp_json_encode( $outcome->payload ),
				'response_json' => wp_json_encode( $outcome->response ),
				'http_code'     => (int) $outcome->http_code,
				'status'        => $outcome->status,
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$id = $inserted ? (int) $wpdb->insert_id : 0;

		// Mirror to telemetry under an independent namespace (additive).
		try {
			do_action( 'estandar_telemetry_event', array(
				'source_plugin' => 'estandar-leads',
				'type'          => 'commercial_outcome',
				'severity'      => $outcome->status === 'failed' ? 'warning' : 'info',
				'code'          => 'COMMERCIAL_OUTCOME_' . strtoupper( $outcome->status ?: 'recorded' ),
				'message'       => sprintf( '%s outcome %s', $outcome->adapter, $outcome->status ),
				'context'       => array(
					'lead_id'   => (int) $outcome->lead_id,
					'stage'     => $outcome->stage_code(),
					'adapter'   => $outcome->adapter,
					'http_code' => (int) $outcome->http_code,
				),
			) );
		} catch ( \Throwable $e ) {
			// Logging must never break dispatch.
		}

		return $id;
	}

	/**
	 * Most recent log rows (newest first).
	 *
	 * @return array
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array();
		}

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
			max( 1, $limit )
		) );
	}
}
