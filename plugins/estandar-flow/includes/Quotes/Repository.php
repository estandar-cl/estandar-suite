<?php

namespace Estandar\Flow\Quotes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository — CRUD de datos del módulo de cotizaciones (M1): correlativo por
 * sitio, revisiones inmutables append-only y ledger de eventos.
 *
 * Todas las operaciones son best-effort: sin `$wpdb` o sin las tablas satélite
 * devuelven valores seguros (no fatalizan). Siempre usa `$wpdb->prefix`.
 */
class Repository
{
	/**
	 * Siguiente correlativo por sitio: `MAX(quote_number)` numérico + 1 con
	 * formato `PREFIX-000123` (6 dígitos). Sin tabla/sin DB → `PREFIX-000001`.
	 */
	public static function next_quote_number( string $prefix = 'COT' ): string
	{
		$prefix = strtoupper( trim( $prefix ) );
		if ( '' === $prefix ) {
			$prefix = 'COT';
		}
		$next = 1;
		global $wpdb;
		if ( isset( $wpdb ) && Schema::exists( \Estandar\Flow\Quotes::TABLE ) ) {
			$table = $wpdb->prefix . \Estandar\Flow\Quotes::TABLE;
			$max   = $wpdb->get_var( $wpdb->prepare(
				"SELECT MAX(quote_number) FROM {$table} WHERE quote_number LIKE %s",
				$prefix . '-%'
			) );
			if ( is_string( $max ) && preg_match( '/(\d+)\s*$/', $max, $m ) ) {
				$next = (int) $m[1] + 1;
			}
		}
		return sprintf( '%s-%06d', $prefix, max( 1, $next ) );
	}

	/**
	 * Append-only de una revisión inmutable del quote.
	 *
	 * - `revision_no = COALESCE(MAX(revision_no),0)+1` del quote.
	 * - `revision_uuid` uuid4; `snapshot_json` canónico (ksort); `snapshot_hash`
	 *   sha256 del JSON canónico.
	 * - Actualiza `revision_counter` del quote y registra evento
	 *   `revision.created`.
	 *
	 * @return array{revision_no:int,revision_uuid:string,hash:string}|array
	 */
	public static function add_revision( int $quote_id, array $snapshot, string $created_by = '' ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $quote_id <= 0 || ! Schema::exists( Schema::REVISIONS ) ) {
			return array();
		}
		$rev_table   = Schema::name( Schema::REVISIONS );
		$quote_table = Schema::name( \Estandar\Flow\Quotes::TABLE );

		$revision_no = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(revision_no),0)+1 FROM {$rev_table} WHERE quote_id = %d",
			$quote_id
		) );
		if ( $revision_no <= 0 ) {
			$revision_no = 1;
		}
		$revision_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::uuid4();
		$json          = self::canonical_json( $snapshot );
		$hash          = hash( 'sha256', $json );

		$ok = $wpdb->insert( $rev_table, array(
			'quote_id'      => $quote_id,
			'revision_no'   => $revision_no,
			'revision_uuid' => $revision_uuid,
			'snapshot_json' => $json,
			'snapshot_hash' => $hash,
			'created_by'    => $created_by,
			'created_at'    => current_time( 'mysql' ),
		), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );
		if ( false === $ok ) {
			return array();
		}

		$wpdb->update( $quote_table, array( 'revision_counter' => $revision_no ), array( 'id' => $quote_id ), array( '%d' ), array( '%d' ) );

		self::log_event( $quote_id, 'revision.created', array(
			'revision_no'   => $revision_no,
			'revision_uuid' => $revision_uuid,
			'hash'          => $hash,
		), $created_by );

		return array(
			'revision_no'   => $revision_no,
			'revision_uuid' => $revision_uuid,
			'hash'          => $hash,
		);
	}

	/** Revisiones de un quote, en orden ascendente. Sin DB → array vacío. */
	public static function list_revisions( int $quote_id ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $quote_id <= 0 || ! Schema::exists( Schema::REVISIONS ) ) {
			return array();
		}
		$table = Schema::name( Schema::REVISIONS );
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE quote_id = %d ORDER BY revision_no ASC", $quote_id
		), ARRAY_A );
	}

	/** Una revisión concreta (o null). */
	public static function get_revision( int $quote_id, int $revision_no ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $quote_id <= 0 || $revision_no <= 0 || ! Schema::exists( Schema::REVISIONS ) ) {
			return null;
		}
		$table = Schema::name( Schema::REVISIONS );
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE quote_id = %d AND revision_no = %d LIMIT 1", $quote_id, $revision_no
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Eventos del ledger de un quote, en orden de inserción. Sin DB → array. */
	public static function list_events( int $quote_id, int $limit = 100 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $quote_id <= 0 || ! Schema::exists( Schema::EVENTS ) ) {
			return array();
		}
		$table = Schema::name( Schema::EVENTS );
		$limit = max( 1, min( 500, $limit ) );
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE quote_id = %d ORDER BY id ASC LIMIT %d", $quote_id, $limit
		), ARRAY_A );
	}

	/** Registra un evento del ledger. Devuelve el id insertado (0 sin DB). */
	public static function log_event( int $quote_id, string $event_type, array $meta = array(), string $actor = '' ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $quote_id <= 0 || ! Schema::exists( Schema::EVENTS ) ) {
			return 0;
		}
		$table = Schema::name( Schema::EVENTS );
		$ok    = $wpdb->insert( $table, array(
			'quote_id'      => $quote_id,
			'event_type'    => substr( $event_type, 0, 64 ),
			'actor'         => $actor,
			'metadata_json' => $meta ? self::canonical_json( $meta ) : null,
			'created_at'    => current_time( 'mysql' ),
		), array( '%d', '%s', '%s', '%s', '%s' ) );
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	// ── Helpers PURE ────────────────────────────────────────────────────────

	/** JSON canónico: claves ordenadas (ksort recursivo). */
	private static function canonical_json( array $data ): string
	{
		$norm = self::ksort_recursive( $data );
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $norm )
			: json_encode( $norm, JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	/** ksort recursivo (los índices secuenciales conservan su orden). */
	private static function ksort_recursive( array $data ): array
	{
		ksort( $data );
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = self::ksort_recursive( $v );
			}
		}
		return $data;
	}

	/** uuid4 de respaldo cuando `wp_generate_uuid4()` no existe. */
	private static function uuid4(): string
	{
		$b = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
	}
}
