<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ads Data Manager — OCI (offline conversions) vía Data Manager API.
 *
 * Suite v2, aditivo y OPT-IN: si la opción no está habilitada o no hay
 * credenciales, este módulo NO hace nada (no rompe ni altera nada existente).
 * Consume los hooks canónicos que la suite ya emite:
 *   - 'estandar_suite_ad_conversion_send' (platform=google_ads) — journey/flow.
 *   - 'estandar_suite_capture_event' — captura con click IDs (CAPTURED).
 *
 * Vía: POST https://datamanager.googleapis.com/v1/events:ingest
 *   - Scope OAuth: https://www.googleapis.com/auth/datamanager
 *   - NO usa developer token (es concepto de la Google Ads API clásica).
 *   - conversionValue por evento → discrimina ticket por calidad (value_by_quality).
 *   - transactionId dedup (idempotencia) — mismo contrato que estandar-leads.
 *
 * Credenciales: client_id / client_secret / refresh_token CIFRADOS AES-256-GCM
 * (Crypto). Nunca se exponen por REST/CLI (solo redactadas). Nada de lo aquí
 * presente pisa tokens, API keys ni datos de producción: todo vive en options
 * propias con prefijo estandar_suite_core_ads_dm_*.
 *
 * Referencia: developers.google.com/data-manager/api (Send events → Google Ads
 * offline conversions) y requestStatus:retrieve para reconciliación asíncrona.
 */
class AdsDataManager
{
	const OPTION       = 'estandar_suite_core_ads_dm';
	const LOG_OPTION   = 'estandar_suite_core_ads_dm_log';
	const TOKEN_TX     = 'estandar_suite_core_ads_dm_token';
	const API_BASE     = 'https://datamanager.googleapis.com/v1';
	const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
	const DM_SCOPE     = 'https://www.googleapis.com/auth/datamanager';
	const MAX_LOG      = 60;
	const TIMEOUT      = 20;

	const QUALITY_DEFAULTS = array(
		'EXCEPTIONAL' => 300000,
		'HIGH'        => 300000,
		'MEDIUM'      => 100000,
		'LOW'         => 30000,
		'UNCLASSIFIED'=> 0,
	);

	// ── Bootstrap (aditivo; nunca lanzado si no hay config) ──────────────────

	public static function init(): void
	{
		add_action( 'estandar_suite_ad_conversion_send', array( __CLASS__, 'on_conversion_send' ), 20, 2 );
		add_action( 'estandar_suite_capture_event', array( __CLASS__, 'on_capture' ), 30 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	// ── Hooks de entrada ──────────────────────────────────────────────────────

	/**
	 * Hook del journey/flow: platform=google_ads → envío OCI con valor por calidad.
	 */
	public static function on_conversion_send( string $platform, array $payload ): void
	{
		if ( $platform !== 'google_ads' || ! self::is_ready() ) {
			return;
		}
		self::maybe_send( $payload );
	}

	/**
	 * Captura v2 (estandar_suite_capture_event): si trae click ID de Google y
	 * está habilitado, emite el evento CAPTURED por la misma vía (value 0 si no
	 * hay calidad; el objetivo es señal de atribución, no valor).
	 */
	public static function on_capture( array $capture ): void
	{
		if ( ! self::is_ready() ) {
			return;
		}
		$click = self::click_ids( $capture );
		if ( empty( $click ) ) {
			return; // orgánico/directo: sin click ID no hay conversión atribuible.
		}
		self::maybe_send( array(
			'platform'        => 'google_ads',
			'event'           => 'lead_captured',
			'lead_id'         => (int) ( $capture['id'] ?? 0 ),
			'capture_uuid'    => (string) ( $capture['capture_uuid'] ?? '' ),
			'click_ids'       => $click,
			'revenue'         => 0,
			'currency'        => self::config( 'currency', 'CLP' ),
			'quality'         => (string) ( $capture['quality'] ?? '' ),
			'occurred_at'     => (string) ( $capture['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		) );
	}

	// ── Envío ────────────────────────────────────────────────────────────────

	/** Decide y envía (validate_only si dry_run). Retorna el resultado. */
	public static function maybe_send( array $payload ): array
	{
		$event = self::build_event( $payload );
		if ( empty( $event ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'no_event' );
		}
		$destination = self::build_destination();
		if ( empty( $destination ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'not_configured' );
		}

		$resp = self::ingest( array( $event ), $destination, (bool) self::config( 'dry_run', true ) );
		if ( $resp['ok'] ) {
			$request_id = (string) ( is_array( $resp['body'] ) ? ( $resp['body']['requestId'] ?? '' ) : '' );
			self::log_request( $request_id, $event, $resp['http'], $resp['error'] );
		}
		return $resp;
	}

	// ── Data Manager transport (puro, no toca credenciales) ─────────────────

	public static function build_destination(): array
	{
		$customer_id   = preg_replace( '/\D+/', '', (string) self::config( 'customer_id', '' ) );
		$conversion_id = preg_replace( '/\D+/', '', (string) self::config( 'conversion_action_id', '' ) );
		if ( $customer_id === '' || $conversion_id === '' ) {
			return array();
		}
		$dest = array(
			'operatingAccount'     => array( 'accountType' => 'GOOGLE_ADS', 'accountId' => $customer_id ),
			'productDestinationId' => $conversion_id,
		);
		$login_id = preg_replace( '/\D+/', '', (string) self::config( 'login_customer_id', '' ) );
		if ( $login_id !== '' ) {
			$dest['loginAccount'] = array( 'accountType' => 'GOOGLE_ADS', 'accountId' => $login_id );
		}
		return $dest;
	}

	/**
	 * Build one Data Manager Event from a conversion payload. [] si no hay
	 * click ID atribuible. conversionValue resuelto por calidad (value_by_quality)
	 * o por revenue real; los negativos fuerzan 0.
	 *
	 * @param array $payload { event, lead_id, capture_uuid?, gclid?, gbraid?,
	 *                        wbraid?, click_ids?, revenue?, currency?, quality?,
	 *                        occurred_at? }
	 */
	public static function build_event( array $payload ): array
	{
		$click = self::click_ids( $payload );
		if ( empty( $click ) ) {
			return array();
		}

		$event = array(
			'transactionId'  => self::transaction_id( $payload ),
			'eventTimestamp' => self::rfc3339( (string) ( $payload['occurred_at'] ?? '' ) ),
			'eventSource'    => 'WEB',
			'adIdentifiers'  => array(),
		);
		foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $k ) {
			if ( ! empty( $click[ $k ] ) ) {
				$event['adIdentifiers'][ $k ] = $click[ $k ];
			}
		}
		if ( empty( $event['adIdentifiers'] ) ) {
			return array();
		}

		$value = self::conversion_value( $payload );
		if ( $value > 0 ) {
			$event['conversionValue'] = $value;
			$event['currency']        = strtoupper( (string) self::config( 'currency', 'CLP' ) );
		}

		$event['consent'] = array(
			'adUserData'        => 'CONSENT_GRANTED',
			'adPersonalization' => 'CONSENT_GRANTED',
		);

		return $event;
	}

	/** Deterministic dedup key: lead_uuid|id + event. */
	public static function transaction_id( array $payload ): string
	{
		$id = (string) ( $payload['capture_uuid'] ?? '' );
		if ( $id === '' ) {
			$id = 'lead-' . (int) ( $payload['lead_id'] ?? 0 );
		}
		$event = strtoupper( (string) ( $payload['event'] ?? 'lead_captured' ) );
		return $id . ':' . $event;
	}

	/** conversionValue por calidad (discriminación de ticket) o revenue real. */
	public static function conversion_value( array $payload ): float
	{
		// Negativos (leads no deseados) → 0 siempre.
		if ( ! empty( $payload['negative'] ) ) {
			return 0.0;
		}
		$quality = strtoupper( trim( (string) ( $payload['quality'] ?? '' ) ) );
		$map     = array_merge( self::QUALITY_DEFAULTS, (array) self::config( 'value_by_quality', array() ) );
		if ( $quality !== '' && array_key_exists( $quality, $map ) && is_numeric( $map[ $quality ] ) ) {
			return (float) $map[ $quality ];
		}
		$revenue = (float) ( $payload['revenue'] ?? 0 );
		return max( 0.0, $revenue );
	}

	/** RFC 3339 UTC (momento del evento de negocio, no "now"). */
	public static function rfc3339( string $raw ): string
	{
		$raw = trim( $raw );
		if ( $raw !== '' ) {
			$ts = ctype_digit( $raw ) ? (int) $raw : strtotime( $raw . ' UTC' );
			if ( $ts ) {
				return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
			}
		}
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Send events to Data Manager API (fast-fail). Retorna {ok,http,body,error}.
	 */
	public static function ingest( array $events, array $destination, bool $validate_only ): array
	{
		if ( empty( $events ) ) {
			return array( 'ok' => false, 'http' => 0, 'body' => array(), 'error' => 'no_events' );
		}
		$token = self::access_token();
		if ( $token === '' ) {
			return array( 'ok' => false, 'http' => 0, 'body' => array(), 'error' => 'auth_unavailable' );
		}
		$resp = Http::request( 'POST', self::API_BASE . '/events:ingest', $token, array(
			'timeout' => self::TIMEOUT,
			'json'    => array(
				'destinations' => array( $destination ),
				'encoding'     => 'HEX',
				'events'       => array_values( $events ),
				'consent'      => array( 'adUserData' => 'CONSENT_GRANTED', 'adPersonalization' => 'CONSENT_GRANTED' ),
				'validateOnly' => (bool) $validate_only,
			),
		) );
		return $resp;
	}

	/** GET requestStatus:retrieve — diagnóstico asíncrono de un requestId. */
	public static function request_status( string $request_id ): array
	{
		$request_id = trim( $request_id );
		if ( $request_id === '' ) {
			return array( 'ok' => false, 'http' => 0, 'body' => array(), 'error' => 'missing_request_id' );
		}
		$token = self::access_token();
		if ( $token === '' ) {
			return array( 'ok' => false, 'http' => 0, 'body' => array(), 'error' => 'auth_unavailable' );
		}
		return Http::request( 'GET', self::API_BASE . '/requestStatus:retrieve?requestId=' . rawurlencode( $request_id ), $token, array(
			'timeout' => self::TIMEOUT,
		) );
	}

	/** Reconcilia los últimos requestIds registrados (read-only, backoff opcional). */
	public static function reconcile_recent( int $limit = 5 ): array
	{
		$rows = array_slice( (array) get_option( self::LOG_OPTION, array() ), 0, max( 1, $limit ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$rid   = (string) ( $row['request_id'] ?? '' );
			$entry = array(
				'request_id' => $rid,
				'sent_at'    => (string) ( $row['sent_at'] ?? '' ),
				'event'      => (string) ( $row['event'] ?? '' ),
				'http'       => (int) ( $row['http'] ?? 0 ),
				'status'     => 'pending',
			);
			if ( $rid !== '' ) {
				$st             = self::request_status( $rid );
				$entry['status'] = $st['ok'] ? 'ok' : 'error';
				$entry['http']   = (int) ( $st['http'] ?? 0 );
				$entry['body']   = is_array( $st['body'] ) ? $st['body'] : $st['body'];
			}
			$out[] = $entry;
		}
		return $out;
	}

	// ── Config (options propias; secretos cifrados) ──────────────────────────

	/** Config plana + secretos descifrados (SOLO uso interno). */
	public static function settings(): array
	{
		$opt = get_option( self::OPTION, array() );
		$opt = is_array( $opt ) ? $opt : array();
		return array_merge( array(
			'enabled'              => false,
			'dry_run'              => true,
			'customer_id'          => '',
			'conversion_action_id' => '',
			'login_customer_id'    => '',
			'oauth_client_id'      => '',
			'oauth_client_secret'  => '',
			'oauth_refresh_token'  => '',
			'currency'             => 'CLP',
			'value_by_quality'     => self::QUALITY_DEFAULTS,
		), $opt );
	}

	public static function config( string $key, $default = '' ) {
		$s = self::settings();
		return $s[ $key ] ?? $default;
	}

	/** ¿Config lista (credenciales + destino) y enabled? */
	public static function is_ready(): bool
	{
		$s = self::settings();
		if ( empty( $s['enabled'] ) || empty( $s['oauth_client_id'] ) || empty( $s['oauth_refresh_token'] ) ) {
			return false;
		}
		return preg_replace( '/\D+/', '', (string) $s['customer_id'] ) !== ''
			&& preg_replace( '/\D+/', '', (string) $s['conversion_action_id'] ) !== '';
	}

	/** Guarda config; los campos de secretos se cifran en reposo. */
	public static function save( array $raw ): void
	{
		$cur   = self::settings();
		$next  = $cur;
		foreach ( array( 'enabled', 'dry_run', 'customer_id', 'conversion_action_id', 'login_customer_id', 'oauth_client_id', 'currency' ) as $k ) {
			if ( isset( $raw[ $k ] ) ) {
				$next[ $k ] = is_bool( $raw[ $k ] ) ? $raw[ $k ] : sanitize_text_field( (string) $raw[ $k ] );
			}
		}
		if ( isset( $raw['value_by_quality'] ) && is_array( $raw['value_by_quality'] ) ) {
			$map = array();
			foreach ( $raw['value_by_quality'] as $q => $v ) {
				$q = strtoupper( sanitize_key( (string) $q ) );
				if ( $q !== '' && is_numeric( $v ) ) {
					$map[ $q ] = (float) $v;
				}
			}
			$next['value_by_quality'] = $map;
		}
		// Secretos: cifrar solo si se entrega uno nuevo no vacío.
		foreach ( array( 'oauth_client_secret', 'oauth_refresh_token' ) as $k ) {
			if ( isset( $raw[ $k ] ) && (string) $raw[ $k ] !== '' && (string) $raw[ $k ] !== '[REDACTED]' ) {
				$next[ $k ] = Crypto::encrypt( (string) $raw[ $k ] );
			} else {
				$next[ $k ] = $cur[ $k ] ?? '';
			}
		}
		update_option( self::OPTION, $next, false );
	}

	/** Máscara read-only para UI/CLI: secretos nunca en claro. */
	public static function masked(): array
	{
		$s   = self::settings();
		$red = static function ( string $v ): string {
			if ( $v === '' ) {
				return '';
			}
			return strlen( $v ) <= 6 ? '••••' : substr( $v, 0, 3 ) . '••••' . substr( $v, -3 );
		};
		return array(
			'enabled'              => (bool) $s['enabled'],
			'dry_run'              => (bool) $s['dry_run'],
			'customer_id'          => (string) $s['customer_id'],
			'conversion_action_id' => (string) $s['conversion_action_id'],
			'login_customer_id'    => (string) $s['login_customer_id'],
			'oauth_client_id'      => $red( (string) $s['oauth_client_id'] ),
			'oauth_client_secret'  => $s['oauth_client_secret'] !== '' ? '••••' : '',
			'oauth_refresh_token'  => $s['oauth_refresh_token'] !== '' ? '••••' : '',
			'currency'             => (string) $s['currency'],
			'value_by_quality'     => (array) $s['value_by_quality'],
			'ready'                => self::is_ready(),
		);
	}

	// ── Token (refresh grant, cacheado; scope datamanager) ───────────────────

	public static function access_token(): string
	{
		$cached = (string) get_transient( self::TOKEN_TX );
		if ( $cached !== '' ) {
			return $cached;
		}
		$s = self::settings();
		$client_id     = (string) $s['oauth_client_id'];
		$client_secret = (string) Crypto::decrypt( (string) ( $s['oauth_client_secret'] ?? '' ) );
		$refresh       = (string) Crypto::decrypt( (string) ( $s['oauth_refresh_token'] ?? '' ) );
		if ( $client_id === '' || $client_secret === '' || $refresh === '' ) {
			return '';
		}
		$resp = Http::request( 'POST', self::TOKEN_URL, '', array(
			'timeout' => self::TIMEOUT,
			'form'    => array(
				'grant_type'    => 'refresh_token',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'refresh_token' => $refresh,
				'scope'         => self::DM_SCOPE,
			),
		) );
		if ( ! $resp['ok'] ) {
			return '';
		}
		$token = (string) ( is_array( $resp['body'] ) ? ( $resp['body']['access_token'] ?? '' ) : '' );
		if ( $token !== '' ) {
			$expires = (int) ( is_array( $resp['body'] ) ? ( $resp['body']['expires_in'] ?? 3600 ) : 3600 );
			set_transient( self::TOKEN_TX, $token, max( 60, $expires - 60 ) );
		}
		return $token;
	}

	// ── Log de requests (para reconciliación) ───────────────────────────────

	public static function log_request( string $request_id, array $event, int $http, string $error ): void
	{
		$rows   = (array) get_option( self::LOG_OPTION, array() );
		$rows[] = array(
			'request_id' => $request_id,
			'event'      => (string) ( $event['transactionId'] ?? '' ),
			'http'       => $http,
			'error'      => substr( $error, 0, 200 ),
			'sent_at'    => gmdate( 'Y-m-d H:i:s' ),
		);
		$rows = array_slice( $rows, 0, self::MAX_LOG );
		update_option( self::LOG_OPTION, $rows, false );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/** Click IDs de Google presentes en un payload/captura. */
	public static function click_ids( array $data ): array
	{
		$out = array();
		if ( ! empty( $data['click_ids'] ) && is_array( $data['click_ids'] ) ) {
			foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $k ) {
				$v = trim( (string) ( $data['click_ids'][ $k ] ?? '' ) );
				if ( $v !== '' ) {
					$out[ $k ] = $v;
				}
			}
			return $out;
		}
		foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $k ) {
			$v = trim( (string) ( $data[ $k ] ?? '' ) );
			if ( $v !== '' ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	// ── Capturas v2 con calidad (para el radar / diagnóstico) ───────────────

	/**
	 * Lectura read-only de capturas v2 con click IDs + calidad estimada.
	 * Equivalente en suite v2 al contrato que el radar consumía vía
	 * `/estandar/v1/leads` (legacy): click IDs, UTM, landing, fecha, calidad.
	 * No muta nada. Si Brain persiste un signal con score, la calidad se
	 * deriva de él; si no, queda UNCLASSIFIED (el radar clasifica por su cuenta).
	 *
	 * @param array $filters { from?, to?, limit?, project? }
	 */
	public static function captures_with_quality( array $filters = array() ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'estandar_capture_submissions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$where  = array( "spam_honeypot IS NULL OR spam_honeypot != 1" );
		$params = array();
		$from   = trim( (string) ( $filters['from'] ?? '' ) );
		$to     = trim( (string) ( $filters['to'] ?? '' ) );
		if ( $from !== '' ) {
			$where[]  = 'created_at >= %s';
			$params[] = $from;
		}
		if ( $to !== '' ) {
			$where[]  = 'created_at <= %s';
			$params[] = $to;
		}
		$limit = max( 1, min( 500, (int) ( $filters['limit'] ?? 200 ) ) );
		$sql   = "SELECT id, capture_uuid, source, source_form_id, name, email_normalized,
		                 phone_normalized, message, fields_json, utm_source, utm_medium,
		                 utm_campaign, utm_term, utm_content, click_ids_json, landing_url,
		                 referrer, session_id, ip_address, created_at
		          FROM {$table}
		          WHERE " . implode( ' AND ', $where ) . "
		          ORDER BY id DESC LIMIT %d";
		$params[] = $limit;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$click   = json_decode( (string) ( $r['click_ids_json'] ?? '' ), true );
			$click   = is_array( $click ) ? $click : array();
			// WA_BACKFILL_V1: si la captura llegó a URL limpia (sin click ID),
			// busca el click más reciente de WhatsApp de la misma sesión/IP y
			// lo fusiona → sube la cobertura de atribución (GCLID).
			if ( empty( $click ) || ! self::_has_google_click( $click ) ) {
				$wa = self::_latest_wa_click_ids(
					(string) ( $r['session_id'] ?? '' ),
					(string) ( $r['ip_address'] ?? '' )
				);
				if ( ! empty( $wa ) ) {
					$click = self::_merge_click_ids( $click, $wa );
				}
			}
			$fields  = json_decode( (string) ( $r['fields_json'] ?? '' ), true );
			$fields  = is_array( $fields ) ? $fields : array();
			$out[]   = array(
				'id'          => (int) $r['id'],
				'capture_uuid'=> (string) $r['capture_uuid'],
				'source'      => (string) $r['source'],
				'source_form_id' => (string) $r['source_form_id'],
				'name'        => (string) $r['name'],
				'email'       => (string) $r['email_normalized'],
				'phone'       => (string) $r['phone_normalized'],
				'message'     => (string) $r['message'],
				'fields'      => $fields,
				'attribution' => array(
					'utm_source'   => (string) $r['utm_source'],
					'utm_medium'   => (string) $r['utm_medium'],
					'utm_campaign' => (string) $r['utm_campaign'],
					'utm_term'     => (string) $r['utm_term'],
					'utm_content'  => (string) $r['utm_content'],
					'gclid'        => (string) ( $click['gclid'] ?? '' ),
					'gbraid'       => (string) ( $click['gbraid'] ?? '' ),
					'wbraid'       => (string) ( $click['wbraid'] ?? '' ),
					// META_ATTR_V1: atribución de Meta Instant Form (sin click-id).
					'fbclid'          => (string) ( $click['fbclid'] ?? '' ),
					'meta_ad_id'      => (string) ( $click['meta_ad_id'] ?? '' ),
					'meta_campaign_id'=> (string) ( $click['meta_campaign_id'] ?? '' ),
					'meta_adset_id'   => (string) ( $click['meta_adset_id'] ?? '' ),
					'landing_url'  => (string) $r['landing_url'],
					'referrer'     => (string) $r['referrer'],
				),
				'quality'     => self::quality_for_capture( (int) $r['id'] ),
				'created_at'  => (string) $r['created_at'],
			);
		}
		return $out;
	}

	/** Deriva calidad HIGH/MEDIUM/LOW desde el signal de Brain (si existe). */
	public static function quality_for_capture( int $capture_id ): string
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $capture_id <= 0 ) {
			return 'UNCLASSIFIED';
		}
		$table = $wpdb->prefix . 'estandar_brain_signals';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 'UNCLASSIFIED';
		}
		$score = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT payload_json FROM {$table}
			 WHERE entity_type = 'capture' AND entity_id = %d AND event_name = 'lead_created'
			 ORDER BY id DESC LIMIT 1",
			$capture_id
		) );
		if ( $score ) {
			$data  = json_decode( (string) $score, true );
			// LEAD_QUALITY_ALIGN_V1 (2026-09-30): la calidad del radar sigue la
			// temperatura del score (CALIENTE/TIBIO/FRIO → HIGH/MEDIUM/LOW), que
			// incorpora la cualificación del form. El umbral numérico 80 era
			// inalcanzable con el motor ponderado (máx 70 con behavior=0).
			$temp = is_array( $data ) ? strtoupper( (string) ( $data['temperature'] ?? '' ) ) : '';
			if ( $temp === 'CALIENTE' ) { return 'HIGH'; }
			if ( $temp === 'TIBIO' )    { return 'MEDIUM'; }
			if ( $temp === 'FRIO' )     { return 'LOW'; }
			$total = (int) ( is_array( $data ) ? ( $data['score'] ?? 0 ) : 0 );
			if ( $total >= 80 ) { return 'HIGH'; }
			if ( $total >= 50 ) { return 'MEDIUM'; }
			return 'LOW';
		}
		// Fallback self-healing: capturas registradas con el bug de payload_json
		// (señal sin score persistido). Recalcula el score con la MISMA lógica
		// de SignalLedger::on_capture() para no dejar históricas sin clasificar.
		return self::_fallback_capture_quality( $capture_id );
	}

	/** PURE fallback: calidad determinística desde la fila de captura. */
	private static function _fallback_capture_quality( int $capture_id ): string
	{
		global $wpdb;
		$table = $wpdb->prefix . 'estandar_capture_submissions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 'UNCLASSIFIED';
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT name, email_normalized, phone_normalized, fields_json
			 FROM {$table} WHERE id = %d LIMIT 1", $capture_id
		), ARRAY_A );
		if ( ! $row ) {
			return 'UNCLASSIFIED';
		}
		$capture_score = 0;
		if ( trim( (string) ( $row['phone_normalized'] ?? '' ) ) !== '' ) { $capture_score += 50; }
		if ( trim( (string) ( $row['email_normalized'] ?? '' ) ) !== '' ) { $capture_score += 30; }
		if ( trim( (string) ( $row['name'] ?? '' ) ) !== '' )             { $capture_score += 20; }
		$intent = '';
		$fields = json_decode( (string) ( $row['fields_json'] ?? '' ), true );
		if ( is_array( $fields ) ) {
			foreach ( array( 'intent', 'lead_intent', 'requirement' ) as $k ) {
				if ( ! empty( $fields[ $k ] ) ) { $intent = (string) $fields[ $k ]; break; }
			}
		}
		// QUALIFICATION_V1: usa la misma cualificación que el scorer del lead.
		$qualification = null;
		if ( is_array( $fields ) && class_exists( '\Estandar\Brain\LeadScorer' ) ) {
			$qualification = \Estandar\Brain\LeadScorer::qualification_from_fields( $fields );
		}
		$total = $capture_score;
		if ( class_exists( '\Estandar\Brain\Scoring' ) && class_exists( '\Estandar\Brain\IntentMap' ) ) {
			$intent_points = \Estandar\Brain\IntentMap::derive( $intent );
			$score = \Estandar\Brain\Scoring::calculate( array(
				'capture'         => $capture_score,
				'qualification'   => $qualification,
				'behavior_events' => array(),
				'profile'         => null,
				'intent'          => $intent_points ? $intent_points['points'] : null,
			) );
			$temp = strtoupper( (string) ( $score['temperature'] ?? '' ) );
			if ( $temp === 'CALIENTE' ) { return 'HIGH'; }
			if ( $temp === 'TIBIO' )    { return 'MEDIUM'; }
			if ( $temp === 'FRIO' )     { return 'LOW'; }
			$total = (int) $score['total'];
		}
		if ( $total >= 80 ) { return 'HIGH'; }
		if ( $total >= 50 ) { return 'MEDIUM'; }
		return 'LOW';
	}

	// ── REST (read-only diagnóstico; opt-in, autenticado) ────────────────────

	public static function register_routes(): void
	{
		register_rest_route( 'estandar-suite/core/v1', '/ads-oci/status', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
			},
			'callback' => static function () {
				return rest_ensure_response( self::masked() );
			},
		) );

		register_rest_route( 'estandar-suite/core/v1', '/ads-oci/reconcile', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
			},
			'callback' => static function ( \WP_REST_Request $request ) {
				$limit = max( 1, (int) ( $request->get_param( 'limit' ) ?: 5 ) );
				return rest_ensure_response( array(
					'ready'   => self::is_ready(),
					'count'   => count( (array) get_option( self::LOG_OPTION, array() ) ),
					'entries' => self::reconcile_recent( $limit ),
				) );
			},
		) );

		// Capturas v2 con click IDs + calidad (contrato para el radar / suite v2).
		register_rest_route( 'estandar-suite/core/v1', '/ads-oci/captures', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
			},
			'callback' => static function ( \WP_REST_Request $request ) {
				$rows = self::captures_with_quality( array(
					'from'  => sanitize_text_field( (string) ( $request->get_param( 'from' ) ?? '' ) ),
					'to'    => sanitize_text_field( (string) ( $request->get_param( 'to' ) ?? '' ) ),
					'limit' => max( 1, (int) ( $request->get_param( 'limit' ) ?: 200 ) ),
				) );
				return rest_ensure_response( array(
					'count' => count( $rows ),
					'leads' => $rows,
				) );
			},
		) );
	}

	// ── WA_BACKFILL_V1: cruce estandar_wa_clicks ↔ capturas ───────────────────

	/** ¿Algún click ID de Google (gclid/gbraid/wbraid) presente? */
	public static function _has_google_click( array $click ): bool
	{
		foreach ( array( 'gclid', 'gbraid', 'wbraid' ) as $k ) {
			if ( ! empty( trim( (string) ( $click[ $k ] ?? '' ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** PURE merge: click IDs del WA llenan huecos sin pisar los existentes. */
	public static function _merge_click_ids( array $base, array $wa ): array
	{
		foreach ( array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid' ) as $k ) {
			if ( empty( trim( (string) ( $base[ $k ] ?? '' ) ) ) && ! empty( trim( (string) ( $wa[ $k ] ?? '' ) ) ) ) {
				$base[ $k ] = trim( (string) $wa[ $k ] );
			}
		}
		return $base;
	}

	/**
	 * Último click de WhatsApp con click IDs de la misma sesión o IP.
	 * Solo lectura; ventana ±15 min desde "hace 24h" a "ahora" (el click del
	 * botón ocurre típicamente en la misma visita que el form).
	 */
	public static function _latest_wa_click_ids( string $session_id, string $ip_address ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'estandar_wa_clicks';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$where  = array( "click_ids_json <> '' AND click_ids_json IS NOT NULL" );
		$params = array();
		$bound  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		if ( $session_id !== '' ) {
			$where[]  = 'session_id = %s';
			$params[] = $session_id;
		} elseif ( $ip_address !== '' ) {
			$where[]  = 'ip_address = %s';
			$params[] = $ip_address;
		} else {
			return array();
		}
		$where[]  = 'created_at >= %s';
		$params[] = $bound;
		$sql = "SELECT click_ids_json FROM {$table} WHERE " . implode( ' AND ', $where )
			. ' ORDER BY id DESC LIMIT 1';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! $row ) {
			return array();
		}
		$ids = json_decode( (string) ( $row['click_ids_json'] ?? '' ), true );
		return is_array( $ids ) ? $ids : array();
	}
}
