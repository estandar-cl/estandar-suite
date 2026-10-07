<?php

namespace Estandar\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * QuotesAdoption — herencia read-only del plugin legacy estandar-quotes.
 *
 * El legacy guarda la cotización completa en es_quotes (con snapshots JSON).
 * Esta clase:
 *  1. Proyecta cada es_quotes a una fila en estandar_flow_quotes (la tabla v2
 *     de Quotes) con marcador origin_quote_id → el v2 muestra el histórico.
 *  2. Conserva el detalle original intacto (nunca se escribe en es_quotes).
 *
 * Contrato (igual que el resto de adopciones):
 *   - first-writer-wins por origin_quote_id + quote_uuid → idempotente + resumible;
 *   - rollback borra SOLO las filas adoptadas (origin_quote_id > 0);
 *   - dry-run por defecto; commit explícito.
 */
class QuotesAdoption
{
	const LEGACY_QUOTES = 'es_quotes';
	const SOURCE        = 'flow_quotes';

	/** PURE: prefijo de BD. */
	private static function prefix(): string
	{
		global $wpdb;
		return ( isset( $wpdb ) && ! empty( $wpdb->prefix ) ) ? $wpdb->prefix : 'wp_';
	}

	/** True cuando la tabla legacy es_quotes existe. */
	public static function legacy_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . self::LEGACY_QUOTES ) );
	}

	/** Proyección PURE de una fila es_quotes a estandar_flow_quotes. */
	public static function project( array $row ): array
	{
		$amount = null;
		foreach ( array( 'lead_snapshot_json', 'draft_document_json' ) as $col ) {
			$json = json_decode( (string) ( $row[ $col ] ?? '' ), true );
			if ( is_array( $json ) ) {
				foreach ( array( 'amount', 'total', 'total_amount', 'quote_total', 'price' ) as $k ) {
					if ( isset( $json[ $k ] ) && is_numeric( $json[ $k ] ) ) {
						$amount = (float) $json[ $k ];
						break 2;
					}
				}
				foreach ( array( 'units', 'items', 'lines' ) as $coll ) {
					if ( isset( $json[ $coll ] ) && is_array( $json[ $coll ] ) ) {
						$sum = 0.0;
						foreach ( $json[ $coll ] as $item ) {
							if ( is_array( $item ) && isset( $item['amount'] ) && is_numeric( $item['amount'] ) ) {
								$sum += (float) $item['amount'];
							} elseif ( is_array( $item ) && isset( $item['total'] ) && is_numeric( $item['total'] ) ) {
								$sum += (float) $item['total'];
							}
						}
						if ( $sum > 0 ) {
							$amount = $sum;
							break 2;
						}
					}
				}
			}
		}
		return array(
			'quote_uuid'     => (string) ( $row['quote_uuid'] ?? '' ),
			'pipeline_id'    => 0,
			'customer_id'    => 0,
			'amount'         => $amount,
			'currency'       => 'CLP',
			'status'         => sanitize_key( (string) ( $row['status'] ?? 'draft' ) ),
			'origin_quote_id'=> (int) ( $row['id'] ?? 0 ),
			'created_at'     => (string) ( $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'updated_at'     => (string) ( $row['updated_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
	}

	/** Conteos read-only: legacy vs adoptado. */
	public static function counts(): array
	{
		global $wpdb;
		$out = array( 'legacy_total' => 0, 'adopted' => 0, 'v2_total' => 0 );
		if ( ! isset( $wpdb ) ) {
			return $out;
		}
		if ( self::legacy_present() ) {
			$out['legacy_total'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . self::LEGACY_QUOTES );
		}
		$table = $wpdb->prefix . Quotes::TABLE;
		$out['adopted'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . ' WHERE origin_quote_id > 0' );
		$out['v2_total'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
		return $out;
	}

	/** Import idempotente de es_quotes → estandar_flow_quotes. */
	public static function import( bool $dry_run = true, int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		$counts = array( 'imported' => 0, 'existing' => 0, 'dry_run' => $dry_run );
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return $counts;
		}
		$legacy = $wpdb->prefix . self::LEGACY_QUOTES;
		$v2     = $wpdb->prefix . Quotes::TABLE;
		$rows   = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$legacy} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset
		), ARRAY_A );
		foreach ( $rows as $row ) {
			$origin = (int) ( $row['id'] ?? 0 );
			if ( $origin <= 0 ) {
				continue;
			}
			$exists = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$v2} WHERE origin_quote_id = %d LIMIT 1", $origin
			) );
			if ( $exists ) {
				$counts['existing']++;
				continue;
			}
			if ( ! $dry_run ) {
				Quotes::create( self::project( $row ) );
			}
			$counts['imported']++;
		}
		return $counts;
	}

	/** Importa todas las filas legacy (loop en lotes). */
	public static function import_all( bool $dry_run = true, int $limit = 100 ): array
	{
		$total = array( 'imported' => 0, 'existing' => 0, 'dry_run' => $dry_run );
		$offset = 0;
		do {
			$batch = self::import( $dry_run, $limit, $offset );
			$total['imported'] += $batch['imported'];
			$total['existing'] += $batch['existing'];
			$offset += $limit;
		} while ( $batch['imported'] > 0 && $offset < 100000 );
		return $total;
	}

	/** Rollback: borra SOLO las filas adoptadas (origin_quote_id > 0). */
	public static function rollback(): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		return (int) $wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Quotes::TABLE . ' WHERE origin_quote_id > 0' );
	}
}
