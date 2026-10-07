<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Signal_Core_Scheduler {

	const CRON_HOOK   = 'estandar_signal_core_process_queue';
	const MAX_ATTEMPTS = 3;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_queue' ) );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function process_queue(): void {
		global $wpdb;

		$queue  = Estandar_Signal_Core_Schema::queue_table();
		$events = Estandar_Signal_Core_Schema::events_table();
		if ( ! Estandar_Signal_Core_Schema::table_exists( $queue ) || ! Estandar_Signal_Core_Schema::table_exists( $events ) ) {
			return;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		// ── Bloque 1: audit_only ─────────────────────────────────────────────────
		// Comportamiento original intacto: marcar como audit_recorded y listo.
		$audit_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.id, q.signal_id
				 FROM {$queue} q
				 INNER JOIN {$events} e ON e.id = q.signal_id
				 WHERE q.queue_status = 'audit_only'
				   AND (q.next_run_at IS NULL OR q.next_run_at <= %s)
				 ORDER BY q.id ASC
				 LIMIT 50",
				$now
			),
			ARRAY_A
		);

		foreach ( (array) $audit_rows as $row ) {
			$wpdb->update(
				$queue,
				array(
					'queue_status' => 'audit_recorded',
					'attempts'     => 1,
					'updated_at'   => $now,
				),
				array( 'id' => absint( $row['id'] ) ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			$wpdb->update(
				$events,
				array(
					'export_status' => 'audit_recorded',
					'updated_at'    => $now,
				),
				array( 'id' => absint( $row['signal_id'] ) ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		// ── Bloque 2: pending (exporters activos) ────────────────────────────────
		// Solo corre si hay exporters registrados. Sin exporters = cero filas pending.
		$exporters = (array) apply_filters( 'estandar_signal_core_exporters', array() );
		if ( empty( $exporters ) ) {
			return;
		}

		$pending_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.id, q.signal_id, q.attempts
				 FROM {$queue} q
				 INNER JOIN {$events} e ON e.id = q.signal_id
				 WHERE q.queue_status = 'pending'
				   AND q.attempts < %d
				   AND (q.next_run_at IS NULL OR q.next_run_at <= %s)
				 ORDER BY q.id ASC
				 LIMIT 50",
				self::MAX_ATTEMPTS,
				$now
			),
			ARRAY_A
		);

		foreach ( (array) $pending_rows as $row ) {
			$queue_id  = absint( $row['id'] );
			$signal_id = absint( $row['signal_id'] );
			$attempts  = absint( $row['attempts'] );

			$signal = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$events} WHERE id = %d LIMIT 1", $signal_id ),
				ARRAY_A
			);
			if ( ! is_array( $signal ) ) {
				continue;
			}

			$event_name   = (string) ( $signal['event_name'] ?? '' );
			$all_ok       = true;
			$error_msgs   = array();

			foreach ( $exporters as $exporter ) {
				if ( ! ( $exporter instanceof Estandar_Signal_Core_Exporter_Interface ) ) {
					continue;
				}
				if ( ! $exporter->supports_event( $event_name ) ) {
					continue;
				}

				try {
					$ok = $exporter->export( $signal );
					if ( ! $ok ) {
						$all_ok       = false;
						$error_msgs[] = $exporter->get_id() . ': transient failure';
					}
				} catch ( \Exception $e ) {
					// Permanent failure: stop retrying.
					$all_ok       = false;
					$error_msgs[] = $exporter->get_id() . ': ' . $e->getMessage();

					$wpdb->update(
						$queue,
						array(
							'queue_status' => 'failed',
							'attempts'     => $attempts + 1,
							'last_error'   => implode( ' | ', $error_msgs ),
							'updated_at'   => $now,
						),
						array( 'id' => $queue_id ),
						array( '%s', '%d', '%s', '%s' ),
						array( '%d' )
					);
					$wpdb->update(
						$events,
						array( 'export_status' => 'failed', 'updated_at' => $now ),
						array( 'id' => $signal_id ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					continue 2; // Next queue row.
				}
			}

			if ( $all_ok ) {
				$wpdb->update(
					$queue,
					array(
						'queue_status' => 'exported',
						'attempts'     => $attempts + 1,
						'last_error'   => null,
						'updated_at'   => $now,
					),
					array( 'id' => $queue_id ),
					array( '%s', '%d', null, '%s' ),
					array( '%d' )
				);
				$wpdb->update(
					$events,
					array( 'export_status' => 'exported', 'updated_at' => $now ),
					array( 'id' => $signal_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			} else {
				// Transient failure: schedule retry with backoff.
				$next_attempt = $attempts + 1;
				$backoff_mins = (int) pow( 2, $next_attempt ) * 15; // 30m, 60m, 120m
				$next_run     = gmdate( 'Y-m-d H:i:s', time() + $backoff_mins * MINUTE_IN_SECONDS );

				$wpdb->update(
					$queue,
					array(
						'queue_status' => $next_attempt >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
						'attempts'     => $next_attempt,
						'last_error'   => implode( ' | ', $error_msgs ),
						'next_run_at'  => $next_run,
						'updated_at'   => $now,
					),
					array( 'id' => $queue_id ),
					array( '%s', '%d', '%s', '%s', '%s' ),
					array( '%d' )
				);
				if ( $next_attempt >= self::MAX_ATTEMPTS ) {
					$wpdb->update(
						$events,
						array( 'export_status' => 'failed', 'updated_at' => $now ),
						array( 'id' => $signal_id ),
						array( '%s', '%s' ),
						array( '%d' )
					);
				}
			}
		}
	}
}
