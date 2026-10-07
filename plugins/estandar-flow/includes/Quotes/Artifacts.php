<?php

namespace Estandar\Flow\Quotes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifacts — registro idempotente de los PDF generados de una cotización (M4).
 *
 * Cada artefacto queda identificado por la cuaterna
 * `(quote_id, revision_uuid, renderer, source_hash)` (UNIQUE en la tabla). Si se
 * vuelve a registrar el mismo par no se duplica: se devuelve la fila existente.
 *
 * Best-effort: sin `$wpdb`, sin tabla o con datos inválidos devuelve 0/null sin
 * fatalizar (coherente con Repository).
 */
class Artifacts
{
	/**
	 * Registra (o recupera) un artefacto. Devuelve el id de la fila (0 sin DB).
	 */
	public static function register( int $quote_id, string $revision_uuid, string $source_hash, string $pdf_sha256, int $byte_size, string $storage_key, string $renderer = 'dompdf' ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $quote_id <= 0 || ! Schema::exists( Schema::ARTIFACTS ) ) {
			return 0;
		}

		$existing = self::exists( $quote_id, $revision_uuid, $source_hash, $renderer );
		if ( is_array( $existing ) ) {
			return (int) ( $existing['id'] ?? 0 );
		}

		$table = Schema::name( Schema::ARTIFACTS );
		$ok    = $wpdb->insert( $table, array(
			'quote_id'      => $quote_id,
			'revision_uuid' => $revision_uuid,
			'source_hash'   => $source_hash,
			'renderer'      => $renderer,
			'pdf_sha256'    => $pdf_sha256,
			'byte_size'     => max( 0, $byte_size ),
			'storage_key'   => $storage_key,
			'status'        => 'ready',
			'generated_at'  => current_time( 'mysql' ),
		), array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/** Artefacto existente para la cuaterna idempotente (o null). */
	public static function exists( int $quote_id, string $revision_uuid, string $source_hash, string $renderer = 'dompdf' ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $quote_id <= 0 || ! Schema::exists( Schema::ARTIFACTS ) ) {
			return null;
		}
		$table = Schema::name( Schema::ARTIFACTS );
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE quote_id = %d AND revision_uuid = %s AND source_hash = %s AND renderer = %s LIMIT 1",
			$quote_id, $revision_uuid, $source_hash, $renderer
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Último artefacto de una cotización (o null). */
	public static function latest_for_quote( int $quote_id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $quote_id <= 0 || ! Schema::exists( Schema::ARTIFACTS ) ) {
			return null;
		}
		$table = Schema::name( Schema::ARTIFACTS );
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE quote_id = %d ORDER BY id DESC LIMIT 1",
			$quote_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}
