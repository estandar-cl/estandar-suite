<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Estandar_Quotes_Lead_Integration {
	const HANDOFF_PREFIX = 'estandar_quotes_handoff_';
	public static function init(): void { add_filter( 'estandar_leads_quote_create_url', array( __CLASS__, 'url_for_lead' ), 10, 2 ); add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) ); }
	public static function admin_menu(): void { /* Workspace is registered by Estandar_Quotes_Admin. */ }
	public static function render_entry(): void { Estandar_Quotes_Admin::render_workspace(); }
	/**
	 * Durable, single-use handoff stored in es_quote_handoffs (no TTL).
	 * URL never contains lead data or IDs; consumed_at gates single-use.
	 */
	public static function url_for_lead( string $url, array $context ): string {
		global $wpdb;
		$submission_id = absint( $context['submission_id'] ?? 0 ); if ( ! $submission_id ) { return $url; }
		$token = bin2hex( random_bytes( 32 ) );
		$wpdb->insert( Estandar_Quotes_Schema::handoffs_table(), array(
			'token_hash'   => self::hash_token( $token ),
			'submission_id' => $submission_id,
			'operator_id'  => absint( $context['operator_id'] ?? 0 ),
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		) );
		return home_url( '/cotizacion/' . rawurlencode( $token ) );
	}
	/** Read-only peek at a still-unconsumed handoff row. */
	public static function peek_handoff( string $token ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . Estandar_Quotes_Schema::handoffs_table() . ' WHERE token_hash = %s AND consumed_at IS NULL LIMIT 1',
			self::hash_token( $token )
		), ARRAY_A );
		if ( ! is_array( $row ) ) { return array(); }
		return array( 'submission_id' => absint( $row['submission_id'] ), 'operator_id' => absint( $row['operator_id'] ) );
	}
	/** Resolve submission_id from a token even after consumption. */
	public static function resolve_submission_from_token( string $token ): int {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT submission_id FROM ' . Estandar_Quotes_Schema::handoffs_table() . ' WHERE token_hash = %s LIMIT 1',
			self::hash_token( $token )
		), ARRAY_A );
		return is_array( $row ) ? absint( $row['submission_id'] ) : 0;
	}
	/**
	 * Atomic single-use consumption: single UPDATE with consumed_at IS NULL + operator_id
	 * as the sole gate. No SELECT-before-UPDATE TOCTOU window.
	 * Returns the server-side submission snapshot on success, empty array on any failure.
	 */
	public static function consume_handoff( string $token, int $operator_id = 0 ): array {
		global $wpdb;
		$hash = self::hash_token( $token );
		$operator_id = absint( $operator_id );
		$updated = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . Estandar_Quotes_Schema::handoffs_table() . ' SET consumed_at = %s WHERE token_hash = %s AND consumed_at IS NULL AND operator_id = %d',
			gmdate( 'Y-m-d H:i:s' ), $hash, $operator_id
		) );
		if ( $updated !== 1 ) { return array(); }
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT submission_id FROM ' . Estandar_Quotes_Schema::handoffs_table() . ' WHERE token_hash = %s LIMIT 1',
			$hash
		), ARRAY_A );
		if ( ! is_array( $row ) ) { return array(); }
		return self::snapshot_for_submission( absint( $row['submission_id'] ) );
	}
	public static function snapshot_for_submission( int $submission_id ): array {
		global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( "SELECT l.id AS lead_id, l.name, l.email, l.phone, l.project_slug, s.id AS submission_id, s.source_plugin, s.message, s.form_fields_json, s.context_json, s.submitted_at FROM {$wpdb->prefix}es_lead_submissions s INNER JOIN {$wpdb->prefix}es_leads l ON l.id = s.lead_id WHERE s.id = %d LIMIT 1", $submission_id ), ARRAY_A ); if ( ! is_array( $row ) ) { return array(); }
		$project_slug = sanitize_title( (string) $row['project_slug'] );
		return array( 'project_slug' => $project_slug, 'lead_id' => absint( $row['lead_id'] ), 'submission_id' => absint( $row['submission_id'] ), 'lead_snapshot' => array( 'lead' => array( 'name' => sanitize_text_field( $row['name'] ), 'email' => sanitize_email( $row['email'] ), 'phone' => sanitize_text_field( $row['phone'] ), 'project_slug' => $project_slug ), 'submission' => array( 'source_plugin' => sanitize_key( $row['source_plugin'] ), 'message' => sanitize_textarea_field( $row['message'] ), 'form_fields' => json_decode( (string) $row['form_fields_json'], true ) ?: array(), 'context' => json_decode( (string) $row['context_json'], true ) ?: array(), 'submitted_at' => sanitize_text_field( $row['submitted_at'] ) ) ) );
	}
	private static function hash_token( string $token ): string { return hash( 'sha256', $token ); }
}
