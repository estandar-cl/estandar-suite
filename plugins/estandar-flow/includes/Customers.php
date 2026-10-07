<?php

namespace Estandar\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customers — commercial registry (absorbs es_customers capability). Own table;
 * first-writer-wins by email/phone fingerprint; adoption from legacy es_customers.
 */
class Customers
{
	const TABLE = 'estandar_flow_customers';

	public static function fingerprint( string $email, string $phone ): string
	{
		$email = strtolower( trim( $email ) );
		$phone = preg_replace( '/\D+/', '', $phone );
		$canon = $phone !== '' ? $phone : $email;
		return hash( 'sha256', $canon );
	}

	public static function create( array $data ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$email = strtolower( trim( (string) ( $data['email'] ?? '' ) ) );
		$phone = preg_replace( '/\D+/', '', (string) ( $data['phone'] ?? '' ) );
		if ( $email === '' && $phone === '' ) {
			return 0;
		}
		$fp = self::fingerprint( $email, $phone );
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}" . self::TABLE . " WHERE fingerprint = %s LIMIT 1", $fp
		) );
		if ( $existing ) {
			return (int) $existing; // first-writer-wins, no duplicate.
		}
		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'name' => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
			'email' => $email, 'phone' => $phone, 'fingerprint' => $fp,
			'origin_customer_id' => (int) ( $data['origin_customer_id'] ?? 0 ),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		), array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/** Read API. */
	public static function list( int $limit = 100 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, email, phone, origin_customer_id, created_at
			 FROM {$wpdb->prefix}" . self::TABLE . " ORDER BY id DESC LIMIT %d", $limit
		), ARRAY_A );
	}
}
