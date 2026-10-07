<?php

namespace Estandar\Flow\Quotes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lifecycle — ciclo de vida de una cotización (M7): máquina de estados
 * `draft → sent → accepted|rejected|expired`, timestamps de decisión, validez
 * derivada del envío y expiración por vencimiento. Aditivo y best-effort:
 * sin `$wpdb` u o sin WordPress no fataliza (devuelve valores seguros).
 */
class Lifecycle
{
	/** Transiciones válidas entre estados de la cotización. */
	const TRANSITIONS = array(
		'draft'    => array( 'sent' ),
		'sent'     => array( 'accepted', 'rejected', 'expired' ),
		'accepted' => array(),
		'rejected' => array(),
		'expired'  => array(),
	);

	/** PURE: ¿es válida la transición `$from → $to`? */
	public static function can_transition( string $from, string $to ): bool
	{
		$from = sanitize_key( $from );
		$to   = sanitize_key( $to );
		if ( ! isset( self::TRANSITIONS[ $from ] ) ) {
			return false;
		}
		return in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/**
	 * Aplica una transición de estado validada contra el estado actual.
	 *
	 * Actualiza estado + `updated_at` + timestamps (`accepted_at`/`rejected_at`
	 * según destino; `reject_reason` desde `$meta`), registra el evento
	 * `quote.{to}` y avanza el pipeline (best-effort, §M9).
	 *
	 * @return bool false si el estado actual no existe o la transición es inválida.
	 */
	public static function transition( int $id, string $to, array $meta = array(), string $actor = '' ): bool
	{
		$quote = \Estandar\Flow\Quotes::get( $id );
		if ( ! is_array( $quote ) ) {
			return false;
		}
		$from = sanitize_key( (string) ( $quote['status'] ?? '' ) );
		$to   = sanitize_key( $to );
		if ( ! self::can_transition( $from, $to ) ) {
			return false;
		}

		$data = array( 'status' => $to );
		if ( 'accepted' === $to ) {
			$data['accepted_at'] = current_time( 'mysql' );
		} elseif ( 'rejected' === $to ) {
			$data['rejected_at'] = current_time( 'mysql' );
			$reason              = trim( (string) ( $meta['reject_reason'] ?? '' ) );
			if ( '' !== $reason ) {
				$data['reject_reason'] = $reason;
			}
		}

		if ( ! \Estandar\Flow\Quotes::update( $id, $data ) ) {
			return false;
		}

		if ( class_exists( 'Estandar\\Flow\\Quotes\\Repository' ) ) {
			\Estandar\Flow\Quotes\Repository::log_event( $id, 'quote.' . $to, $meta, $actor );
		}

		self::advance_pipeline( $id, $to );

		return true;
	}

	/**
	 * Fija `valid_until` a `now + validity_days` (Settings) si aún está vacío.
	 * Se invoca desde `Quotes::send()` tras marcar `sent`.
	 */
	public static function set_valid_until_from_send( int $id ): void
	{
		$quote = \Estandar\Flow\Quotes::get( $id );
		if ( ! is_array( $quote ) ) {
			return;
		}
		if ( ! empty( $quote['valid_until'] ) ) {
			return;
		}

		$days = 15;
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			$days = (int) \Estandar\Flow\Quotes\Settings::validity_days();
		}
		if ( $days <= 0 ) {
			$days = 15;
		}

		$now = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : date( 'Y-m-d H:i:s' );
		$ts  = strtotime( $now );
		if ( false === $ts ) {
			$ts = time();
		}
		$until = date( 'Y-m-d H:i:s', $ts + ( $days * 86400 ) );

		\Estandar\Flow\Quotes::update( $id, array( 'valid_until' => $until ) );
	}

	/**
	 * Expira las cotizaciones `sent` vencidas (`valid_until` no nulo y < NOW()).
	 *
	 * @return int Número de cotizaciones efectivamente expiradas. Sin DB → 0.
	 */
	public static function expire_due( int $limit = 50 ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$table = $wpdb->prefix . \Estandar\Flow\Quotes::TABLE;
		$limit = max( 1, min( 500, $limit ) );
		$ids   = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE status = 'sent' AND valid_until IS NOT NULL AND valid_until < %s ORDER BY id ASC LIMIT %d",
			current_time( 'mysql' ),
			$limit
		) );

		$expired = 0;
		foreach ( $ids as $id ) {
			if ( self::transition( (int) $id, 'expired' ) ) {
				$expired++;
			}
		}
		return $expired;
	}

	/** Avanza la cotización recién enviada a PROPOSAL (entrada de `Quotes::send`). */
	public static function advance_proposal( int $id ): void
	{
		self::advance_pipeline( $id, 'sent' );
	}

	/**
	 * Best-effort: avanza el journey del lead asociado a la cotización.
	 * Nunca propaga errores y NO emite `estandar_suite_signal_recorded`
	 * (evita doble dispatch; `emit_issued` ya emite `quote_issued`).
	 */
	private static function advance_pipeline( int $id, string $state ): void
	{
		if ( ! class_exists( 'Estandar\\Flow\\Pipeline' ) ) {
			return;
		}
		$map = array(
			'sent'     => 'PROPOSAL',
			'accepted' => 'CLOSED_WON',
			'rejected' => 'LOST',
		);
		$target = $map[ $state ] ?? '';
		if ( '' === $target ) {
			return;
		}

		try {
			$quote = \Estandar\Flow\Quotes::get( $id );
			if ( ! is_array( $quote ) ) {
				return;
			}
			$lead_id = (int) ( $quote['lead_id'] ?? 0 );
			if ( $lead_id <= 0 ) {
				return;
			}
			$pid = \Estandar\Flow\Pipeline::resolve_pipeline_id(
				array( 'entity_type' => 'lead', 'entity_id' => $lead_id )
			);
			if ( $pid > 0 ) {
				\Estandar\Flow\Pipeline::advance_to( $pid, $target, 'cotización ' . $state );
			}
		} catch ( \Throwable $e ) {
			// Best-effort: el ciclo de vida del quote no depende del pipeline.
		}
	}
}
