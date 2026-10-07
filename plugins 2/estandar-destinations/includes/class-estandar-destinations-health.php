<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health bootstrap for estandar-destinations.
 *
 * Provides status() for admin UI and telemetry consumers.
 * Returns only what is deterministically knowable at Phase 0:
 *   - engine loaded
 *   - schema tables present
 *   - registered adapter count
 */
final class Estandar_Destinations_Health {

	/**
	 * @return array{
	 *   engine_loaded: bool,
	 *   schema_ok: bool,
	 *   adapters_registered: int,
	 *   adapters: array<string,array{id:string,label:string,configured:bool}>,
	 *   queue_pending: int,
	 *   queue_retry: int,
	 *   queue_dead: int,
	 *   queue_succeeded: int
	 * }
	 */
	public static function status(): array {
		global $wpdb;
		$adapters     = Estandar_Destinations_Engine::all();
		$adapter_list = array();
		foreach ( $adapters as $adapter ) {
			$adapter_list[] = array(
				'id'         => $adapter->id(),
				'label'      => $adapter->label(),
				'configured' => $adapter->is_configured(),
			);
		}

		$queue_pending   = 0;
		$queue_retry     = 0;
		$queue_dead      = 0;
		$queue_succeeded = 0;
		$queue_table     = $wpdb->prefix . 'es_signal_queue';
		$events_table    = $wpdb->prefix . 'es_signal_events';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) ) {
			$queue_pending   = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$queue_table} q INNER JOIN {$events_table} e ON e.id = q.signal_id WHERE e.source_plugin = 'estandar-destinations' AND q.queue_status = 'pending'" );
			$queue_retry     = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$queue_table} q INNER JOIN {$events_table} e ON e.id = q.signal_id WHERE e.source_plugin = 'estandar-destinations' AND q.queue_status = 'retry'" );
			$queue_dead      = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$queue_table} q INNER JOIN {$events_table} e ON e.id = q.signal_id WHERE e.source_plugin = 'estandar-destinations' AND q.queue_status = 'dead'" );
			$queue_succeeded = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$queue_table} q INNER JOIN {$events_table} e ON e.id = q.signal_id WHERE e.source_plugin = 'estandar-destinations' AND q.queue_status = 'succeeded'" );
		}

		return array(
			'engine_loaded'       => true,
			'schema_ok'           => class_exists( 'Estandar_Destinations_Schema' )
				? Estandar_Destinations_Schema::tables_exist()
				: false,
			'adapters_registered' => count( $adapters ),
			'adapters'            => $adapter_list,
			'queue_pending'       => $queue_pending,
			'queue_retry'         => $queue_retry,
			'queue_dead'          => $queue_dead,
			'queue_succeeded'     => $queue_succeeded,
		);
	}
}
