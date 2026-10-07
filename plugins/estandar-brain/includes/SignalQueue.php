<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SignalQueue — procesador de la cola de señales del ledger Brain
 * (`estandar_brain_signal_queue`). Exporta cada señal pendiente vía los
 * exportadores configurados (hoy: Meta CAPI) con reintentos y backoff.
 *
 * Estados de cola: pending → exported | skipped | failed.
 * - skipped: no hay nada que enviar (evento no exportable o sin datos de match).
 * - failed: error permanente de Meta o se agotaron los reintentos.
 */
class SignalQueue
{
	const CRON_HOOK    = 'estandar_brain_process_signal_queue';
	const MAX_ATTEMPTS = 3;

	public static function init(): void
	{
		add_action( self::CRON_HOOK, array( __CLASS__, 'process' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	/** Programa el cron horario si no existe. */
	public static function ensure_schedule(): void
	{
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void
	{
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Procesa hasta $limit filas pendientes. Devuelve estadísticas.
	 * Si no hay exportador configurado, no toca nada (deja la cola intacta).
	 */
	public static function process( int $limit = 50 ): array
	{
		global $wpdb;
		$stats = array( 'checked' => 0, 'sent' => 0, 'skip' => 0, 'retry' => 0, 'failed' => 0 );
		$exporters = self::exporters();
		if ( ! isset( $wpdb ) || ! $exporters ) {
			return $stats;
		}

		$queue = $wpdb->prefix . SignalLedger::QUEUE;
		$table = $wpdb->prefix . SignalLedger::TABLE;
		$now   = gmdate( 'Y-m-d H:i:s' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT q.id AS qid, q.signal_id, q.attempts
			 FROM {$queue} q
			 WHERE q.queue_status = 'pending' AND q.attempts < %d
			   AND (q.next_run_at IS NULL OR q.next_run_at <= %s)
			 ORDER BY q.id ASC LIMIT %d",
			self::MAX_ATTEMPTS,
			$now,
			$limit
		), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$stats['checked']++;
			$qid     = (int) $row['qid'];
			$attempt = (int) $row['attempts'];

			$signal = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $row['signal_id']
			), ARRAY_A );
			if ( ! $signal ) {
				self::finish( $qid, 'skipped', $attempt + 1, 'señal inexistente' );
				$stats['skip']++;
				continue;
			}

			$states = array();
			foreach ( $exporters as $key => $class ) {
				$states[ $key ] = (string) call_user_func( array( $class, 'export' ), $signal );
			}
			$values        = array_values( $states );
			$has_sent      = in_array( 'sent', $values, true );
			$has_permanent = in_array( 'permanent', $values, true );
			$has_transient = in_array( 'transient', $values, true );
			$json          = function_exists( 'wp_json_encode' ) ? wp_json_encode( $states ) : json_encode( $states );

			if ( $has_permanent ) {
				self::finish( $qid, 'failed', $attempt + 1, 'error permanente de exportador', null, $json );
				$stats['failed']++;
			} elseif ( $has_transient ) {
				$next    = $attempt + 1;
				$status  = $next >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
				$next_at = gmdate( 'Y-m-d H:i:s', time() + (int) pow( 2, $next ) * 15 * MINUTE_IN_SECONDS );
				self::finish( $qid, $status, $next, 'fallo transitorio', $next_at, $json );
				if ( 'failed' === $status ) { $stats['failed']++; } else { $stats['retry']++; }
			} elseif ( $has_sent ) {
				self::finish( $qid, 'exported', $attempt + 1, null, null, $json );
				$stats['sent']++;
			} else {
				self::finish( $qid, 'skipped', $attempt + 1, 'sin datos de match o evento no exportable', null, $json );
				$stats['skip']++;
			}
		}
		return $stats;
	}

	/** Reprocesa en lote el backlog (alias explícito de process). */
	public static function backfill( int $limit = 2000 ): array
	{
		return self::process( $limit );
	}

	private static function finish( int $qid, string $status, int $attempts, ?string $error, ?string $next = null, ?string $exporters_json = null ): void
	{
		global $wpdb;
		$data   = array(
			'queue_status' => $status,
			'attempts'     => $attempts,
			'last_error'   => $error,
			'next_run_at'  => $next,
			'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
		);
		$format = array( '%s', '%d', '%s', '%s', '%s' );
		if ( null !== $exporters_json ) {
			$data['exporters_json'] = $exporters_json;
			$format[]               = '%s';
		}
		$wpdb->update(
			$wpdb->prefix . SignalLedger::QUEUE,
			$data,
			array( 'id' => $qid ),
			$format,
			array( '%d' )
		);
	}

	/**
	 * Exportadores disponibles y configurados: clave => clase.
	 *
	 * @return array<string,string>
	 */
	public static function exporters(): array
	{
		$out = array();
		if ( MetaCapi::is_configured() ) {
			$out['meta'] = MetaCapi::class;
		}
		if ( class_exists( __NAMESPACE__ . '\\GoogleOci' ) && GoogleOci::is_configured() ) {
			$out['google'] = GoogleOci::class;
		}
		return $out;
	}
}
