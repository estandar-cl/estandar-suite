<?php
/**
 * GoogleOci — exportador v2 de señales a Google Ads (Offline/Click Conversions).
 *
 * Sube conversiones con **click id** (gclid/wbraid/gbraid) al endpoint
 * `uploadClickConversions` de la Google Ads API. Consume la fila del ledger tal
 * cual (no toca el CRM) y usa las credenciales del grupo `google` de
 * `estandar_suite_brain_exporters_settings` (OAuth refresh token).
 *
 * Mapeo: lead_created → conv_lead_created · meeting_booked → conv_meeting_booked ·
 * sale_closed → conv_sale_closed. `lead_qualified`/`lead_rejected` NO se suben.
 *
 * Sin click id no hay match posible → 'skip' (no ensucia la cuenta).
 *
 * @package Estandar\Brain
 */

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exportador Google Ads OCI (server-side, por click id).
 */
class GoogleOci {

	const API_BASE   = 'https://googleads.googleapis.com/v21';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const TOKEN_KEY  = 'estandar_brain_google_access_token';

	/** event_name del ledger → clave de acción de conversión en la config. */
	const EVENT_MAP = array(
		'lead_created'   => 'conv_lead_created',
		'meeting_booked' => 'conv_meeting_booked',
		'sale_closed'    => 'conv_sale_closed',
	);

	/** Click ids aceptados por la API. */
	const CLICK_IDS = array( 'gclid', 'wbraid', 'gbraid' );

	/** Configuración efectiva del exportador Google. */
	public static function config(): array {
		$all = get_option( 'estandar_suite_brain_exporters_settings', array() );
		$all = is_array( $all ) ? $all : array();
		$g   = isset( $all['google'] ) && is_array( $all['google'] ) ? $all['google'] : array();
		if ( ! $g ) {
			// FEATURE_GROUP_NESTED_V1 · fallback legacy plano (ver MetaCapi::config()).
			foreach ( array( 'enabled', 'developer_token', 'client_id', 'client_secret', 'customer_id', 'refresh_token', 'conv_lead_created', 'conv_meeting_booked', 'conv_sale_closed' ) as $k ) {
				if ( array_key_exists( $k, $all ) ) {
					$g[ $k ] = $all[ $k ];
				}
			}
		}
		return array(
			'enabled'             => ! empty( $g['enabled'] ),
			'developer_token'     => (string) ( $g['developer_token'] ?? '' ),
			'client_id'           => (string) ( $g['client_id'] ?? '' ),
			'client_secret'       => (string) ( $g['client_secret'] ?? '' ),
			'customer_id'         => preg_replace( '/[^0-9]/', '', (string) ( $g['customer_id'] ?? '' ) ),
			'refresh_token'       => (string) ( $g['refresh_token'] ?? '' ),
			'conv_lead_created'   => (string) ( $g['conv_lead_created'] ?? '' ),
			'conv_meeting_booked' => (string) ( $g['conv_meeting_booked'] ?? '' ),
			'conv_sale_closed'    => (string) ( $g['conv_sale_closed'] ?? '' ),
		);
	}

	public static function is_configured(): bool {
		$c = self::config();
		return $c['enabled']
			&& $c['developer_token'] !== ''
			&& $c['client_id'] !== ''
			&& $c['client_secret'] !== ''
			&& $c['customer_id'] !== ''
			&& $c['refresh_token'] !== '';
	}

	/** ¿Esta señal se exporta a Google? (solo si hay acción de conversión). */
	public static function supports( string $event_name ): bool {
		if ( ! isset( self::EVENT_MAP[ $event_name ] ) ) {
			return false;
		}
		$c = self::config();
		return (string) $c[ self::EVENT_MAP[ $event_name ] ] !== '';
	}

	/**
	 * Extrae el click id disponible de una señal.
	 *
	 * @param array $signal Fila del ledger.
	 * @return array{type:string,value:string}
	 */
	public static function click_id( array $signal ): array {
		$payload = array();
		if ( ! empty( $signal['payload_json'] ) ) {
			$decoded = is_array( $signal['payload_json'] ) ? $signal['payload_json'] : json_decode( (string) $signal['payload_json'], true );
			$payload = is_array( $decoded ) ? $decoded : array();
		}
		$ids = isset( $payload['click_ids'] ) && is_array( $payload['click_ids'] ) ? $payload['click_ids'] : $payload;
		foreach ( self::CLICK_IDS as $k ) {
			if ( ! empty( $ids[ $k ] ) && is_string( $ids[ $k ] ) ) {
				return array( 'type' => $k, 'value' => trim( (string) $ids[ $k ] ) );
			}
		}
		return array( 'type' => '', 'value' => '' );
	}

	/**
	 * PURE: construye la conversión para la API (o null si no aplica).
	 *
	 * @param array $signal Fila del ledger.
	 * @param array $config Config efectiva.
	 * @return array|null
	 */
	public static function build_conversion( array $signal, array $config ): ?array {
		$event = (string) ( $signal['event_name'] ?? '' );
		if ( ! isset( self::EVENT_MAP[ $event ] ) ) {
			return null;
		}
		$action = (string) ( $config[ self::EVENT_MAP[ $event ] ] ?? '' );
		if ( $action === '' ) {
			return null;
		}
		$click = self::click_id( $signal );
		if ( $click['value'] === '' ) {
			return null;
		}

		$conversion = array(
			$click['type']       => $click['value'],
			'conversionAction'   => 'customers/' . ( $config['customer_id'] ?? '' ) . '/conversionActions/' . $action,
			'conversionDateTime' => gmdate( 'Y-m-d H:i:s+00:00', strtotime( (string) ( $signal['event_time'] ?? 'now' ) ) ?: time() ),
		);

		if ( isset( $signal['value_decimal'] ) && $signal['value_decimal'] !== null && $signal['value_decimal'] !== '' ) {
			$conversion['conversionValue'] = (float) $signal['value_decimal'];
			$currency = strtoupper( (string) ( $signal['currency'] ?? '' ) );
			if ( '' !== $currency ) {
				$conversion['currencyCode'] = $currency;
			}
		}

		return $conversion;
	}

	/**
	 * Exporta una fila del ledger. Devuelve:
	 * 'sent' | 'skip' | 'permanent' | 'transient'.
	 */
	public static function export( array $signal ): string {
		$event = (string) ( $signal['event_name'] ?? '' );
		if ( ! self::supports( $event ) ) {
			return 'skip';
		}
		$c = self::config();
		if ( ! self::is_configured() ) {
			return 'skip';
		}
		$conversion = self::build_conversion( $signal, $c );
		if ( null === $conversion ) {
			return 'skip'; // sin click id o sin acción configurada.
		}

		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return 'transient';
		}

		$url  = self::API_BASE . '/customers/' . $c['customer_id'] . ':uploadClickConversions';
		$body = array(
			'conversions'    => array( $conversion ),
			'partialFailure' => false,
		);

		$response = wp_remote_post( $url, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization'   => 'Bearer ' . $token,
				'developer-token' => $c['developer_token'],
				'Content-Type'    => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return 'transient';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return 'sent';
		}
		// 401/403 → credenciales/permisos (permanente). 429/5xx → transitorio.
		if ( in_array( $code, array( 400, 401, 403, 404 ), true ) ) {
			return 'permanent';
		}
		return 'transient';
	}

	/**
	 * Access token OAuth (con cache en transient). Devuelve string|WP_Error.
	 *
	 * @return string|\WP_Error
	 */
	public static function access_token() {
		$cached = get_transient( self::TOKEN_KEY );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$c        = self::config();
		$response = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => 20,
			'body'    => array(
				'client_id'     => $c['client_id'],
				'client_secret' => $c['client_secret'],
				'refresh_token' => $c['refresh_token'],
				'grant_type'    => 'refresh_token',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
			return new \WP_Error( 'google_token', 'No se pudo obtener el access token de Google.' );
		}

		$ttl = isset( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 120 ) : 3300;
		set_transient( self::TOKEN_KEY, (string) $data['access_token'], $ttl );

		return (string) $data['access_token'];
	}

	/** Limpia el token cacheado (al cambiar credenciales). */
	public static function forget_token(): void {
		delete_transient( self::TOKEN_KEY );
	}
}
