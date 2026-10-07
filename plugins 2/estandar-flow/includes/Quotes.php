<?php

namespace Estandar\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quotes — auditable commercial quotes (absorbs estandar-quotes capability).
 * Own table; revision history lives in a simple events row per change.
 */
class Quotes
{
	const TABLE = 'estandar_flow_quotes';

	/** Pure: deterministic quote key (stable for adoption/idempotency). */
	public static function quote_uuid( int $pipeline_id, string $nonce ): string
	{
		return md5( $pipeline_id . ':' . $nonce );
	}

	public static function create( array $data ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$amount = isset( $data['amount'] ) && is_numeric( $data['amount'] ) ? (float) $data['amount'] : null;
		$uuid   = (string) ( $data['quote_uuid'] ?? '' );
		if ( $uuid === '' ) {
			$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : md5( uniqid( '', true ) );
		}
		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'quote_uuid'  => $uuid,
			'pipeline_id' => (int) ( $data['pipeline_id'] ?? 0 ),
			'customer_id' => (int) ( $data['customer_id'] ?? 0 ),
			'amount'      => $amount,
			'currency'    => strtoupper( substr( (string) ( $data['currency'] ?? 'CLP' ), 0, 8 ) ),
			'status'      => sanitize_key( (string) ( $data['status'] ?? 'draft' ) ),
			'origin_quote_id' => (int) ( $data['origin_quote_id'] ?? 0 ),
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%d', '%d', '%f', '%s', '%s', '%d', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	public static function list( int $limit = 100 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, quote_uuid, pipeline_id, customer_id, amount, currency, status, origin_quote_id, created_at
			 FROM {$wpdb->prefix}" . self::TABLE . " ORDER BY id DESC LIMIT %d", $limit
		), ARRAY_A );
	}
}
