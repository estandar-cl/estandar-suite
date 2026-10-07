<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MetaCapi — exportador v2 de señales a la Meta Conversions API (server-side).
 *
 * Port del legacy `Estandar_Signal_Core_Meta_Capi` adaptado al ledger v2
 * (`estandar_brain_signals`). Consume la fila del ledger tal cual (sin tocar el
 * CRM) y arma el evento CAPI:
 *   - user_data: em/ph (SHA-256 de los valores NORMALIZADOS), fbc, fbp,
 *     external_id (SHA-256 del lead_uuid).
 *   - event_id: entity_uuid (dedup con el pixel cuando comparte id).
 *   - mapeo: lead_created→Lead, lead_qualified→LeadQualified,
 *     meeting_booked→Schedule, sale_closed→Purchase. lead_rejected NO se
 *     exporta (no debe contarse como conversión).
 *
 * Credenciales: option `estandar_suite_brain_exporters_settings` (grupo meta).
 */
class MetaCapi
{
	const API_VERSION = 'v21.0';

	/** event_name del ledger → evento estándar de Meta. */
	const EVENT_MAP = array(
		'lead_created'   => 'Lead',
		'lead_qualified' => 'LeadQualified',
		'meeting_booked' => 'Schedule',
		'sale_closed'    => 'Purchase',
	);

	/** Configuración efectiva del exportador Meta. */
	public static function config(): array
	{
		$all  = get_option( 'estandar_suite_brain_exporters_settings', array() );
		$all  = is_array( $all ) ? $all : array();
		$meta = isset( $all['meta'] ) && is_array( $all['meta'] ) ? $all['meta'] : array();
		if ( ! $meta ) {
			// FEATURE_GROUP_NESTED_V1 · fallback legacy plano: versiones previas
			// escribían las claves en la raíz (y `enabled` colisionaba). Se
			// recuperan para no perder la config; al guardar se migra a anidado.
			foreach ( array( 'enabled', 'pixel_id', 'access_token', 'test_event_code', 'send_lead' ) as $k ) {
				if ( array_key_exists( $k, $all ) ) {
					$meta[ $k ] = $all[ $k ];
				}
			}
		}
		return array(
			'enabled'         => ! empty( $meta['enabled'] ),
			'pixel_id'        => (string) ( $meta['pixel_id'] ?? '' ),
			'access_token'    => (string) ( $meta['access_token'] ?? '' ),
			'test_event_code' => (string) ( $meta['test_event_code'] ?? '' ),
			'send_lead'       => ! empty( $meta['send_lead'] ),
		);
	}

	public static function is_configured(): bool
	{
		$c = self::config();
		return $c['enabled'] && $c['pixel_id'] !== '' && $c['access_token'] !== '';
	}

	/** ¿Esta señal se exporta a Meta? */
	public static function supports( string $event_name ): bool
	{
		if ( ! isset( self::EVENT_MAP[ $event_name ] ) ) {
			return false; // lead_rejected y desconocidos.
		}
		if ( 'lead_created' === $event_name && empty( self::config()['send_lead'] ) ) {
			return false; // El pixel del navegador ya lo envía: evita doble conteo.
		}
		return true;
	}

	/**
	 * Exporta una fila del ledger. Devuelve el resultado:
	 * 'sent' | 'skip' | 'permanent' | 'transient'.
	 */
	public static function export( array $signal ): string
	{
		$event_name = (string) ( $signal['event_name'] ?? '' );
		if ( ! self::supports( $event_name ) ) {
			return 'skip';
		}
		if ( ! self::is_configured() ) {
			return 'skip';
		}

		$user = self::user_data( $signal );
		if ( empty( $user ) ) {
			return 'skip'; // Sin datos de match: no aporta y ensucia.
		}

		$c      = self::config();
		$ts     = strtotime( (string) ( $signal['event_time'] ?? '' ) );
		$ts     = $ts ? $ts : time();
		$event_id = trim( (string) ( $signal['entity_uuid'] ?? '' ) );
		if ( $event_id === '' ) {
			$event_id = 'brain-' . (int) ( $signal['id'] ?? 0 ) . '-' . $event_name;
		}

		$event = array(
			'event_name'       => self::EVENT_MAP[ $event_name ],
			'event_time'       => $ts,
			'action_source'    => 'website',
			'event_id'         => $event_id,
			'event_source_url' => home_url( '/' ),
			'user_data'        => $user,
		);

		$custom = array();
		$project = (string) ( $signal['project_slug'] ?? '' );
		if ( $project !== '' ) {
			$custom['project_slug'] = $project;
		}
		if ( 'Purchase' === self::EVENT_MAP[ $event_name ] ) {
			$value = (float) ( $signal['value_decimal'] ?? 0 );
			$custom['value']    = $value > 0 ? $value : 0;
			$custom['currency'] = (string) ( $signal['currency'] ?? '' ) ?: 'CLP';
		}
		if ( $custom ) {
			$event['custom_data'] = $custom;
		}

		$body = array( 'data' => array( $event ) );
		if ( $c['test_event_code'] !== '' ) {
			$body['test_event_code'] = $c['test_event_code'];
		}

		$endpoint = sprintf(
			'https://graph.facebook.com/%s/%s/events?access_token=%s',
			self::API_VERSION,
			rawurlencode( $c['pixel_id'] ),
			rawurlencode( $c['access_token'] )
		);

		$response = wp_remote_post( $endpoint, array(
			'body'    => wp_json_encode( $body ),
			'headers' => array( 'Content-Type' => 'application/json' ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return 'transient';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return 'sent';
		}
		if ( $code >= 400 && $code < 500 ) {
			return 'permanent';
		}
		return 'transient';
	}

	/** Payload decodificado de la fila del ledger. */
	private static function payload( array $signal ): array
	{
		$p = $signal['payload_json'] ?? '';
		if ( is_string( $p ) && $p !== '' ) {
			$d = json_decode( $p, true );
			if ( is_array( $d ) ) {
				return $d;
			}
		}
		return is_array( $p ) ? $p : array();
	}

	/**
	 * user_data CAPI. Meta exige SHA-256 de los valores normalizados: el CRM
	 * guarda los normalizados en es_leads (email_normalized/phone_normalized),
	 * así que se resuelve el lead y se hashean aquí (NUNCA el HMAC interno).
	 */
	private static function user_data( array $signal ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$payload = self::payload( $signal );
		$lead_id = (int) ( $payload['lead_id'] ?? 0 );
		if ( $lead_id <= 0 && 'lead' === (string) ( $signal['entity_type'] ?? '' ) ) {
			$lead_id = (int) ( $signal['entity_id'] ?? 0 );
		}
		$fbc = (string) ( $payload['fbc'] ?? '' );
		$fbp = (string) ( $payload['fbp'] ?? '' );
		$ext = (string) ( $payload['external_id'] ?? '' );

		if ( $lead_id > 0 ) {
			$lead = $wpdb->get_row( $wpdb->prepare(
				"SELECT email_normalized, phone_normalized, first_meta_fbc, first_meta_fbp, lead_uuid
				 FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
				$lead_id
			), ARRAY_A );
			if ( $lead ) {
				if ( $fbc === '' ) { $fbc = (string) ( $lead['first_meta_fbc'] ?? '' ); }
				if ( $fbp === '' ) { $fbp = (string) ( $lead['first_meta_fbp'] ?? '' ); }
				if ( $ext === '' ) { $ext = (string) ( $lead['lead_uuid'] ?? '' ); }
				$email = strtolower( trim( (string) ( $lead['email_normalized'] ?? '' ) ) );
				$phone = preg_replace( '/\D+/', '', (string) ( $lead['phone_normalized'] ?? '' ) );
				if ( $email !== '' ) { $user['em'] = array( hash( 'sha256', $email ) ); }
				if ( $phone !== '' ) { $user['ph'] = array( hash( 'sha256', (string) $phone ) ); }
			}
		}

		if ( isset( $user ) && ! empty( $user ) ) {
			if ( $fbc !== '' ) { $user['fbc'] = $fbc; }
			if ( $fbp !== '' ) { $user['fbp'] = $fbp; }
			if ( $ext !== '' ) { $user['external_id'] = array( hash( 'sha256', $ext ) ); }
			return $user;
		}
		return array();
	}
}
