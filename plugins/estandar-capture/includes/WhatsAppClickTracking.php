<?php
/**
 * Estandar Suite — Capture · WhatsAppClickTracking
 *
 * Registro del CLICK al botón de WhatsApp (no solo la atribución posterior):
 *   - Cada vez que un usuario presiona un enlace de WhatsApp (botón flotante
 *     del plugin, botones wa.me del tema, api.whatsapp.com, go/whatsapp) se
 *     guarda una fila con el click ID de Google Ads (gclid/gbraid/wbraid), la
 *     página, el dispositivo y la fecha.
 *   - Emite un evento a `dataLayer` (`whatsapp_click`) para que el GTM/GA4
 *     pueda enviarlo a Google Ads como señal (cada click = intención).
 *
 * Dos cosas que Google NECESITA y antes no tenía:
 *   1) Que el usuario entró a la web desde un anuncio (gclid en la visita).
 *   2) Que ese usuario PINCHÓ "Hablar por WhatsApp" (evento de click).
 * El contenido de la conversación NO se captura (privacidad).
 *
 * Desde 1.2.0 se guarda además `slug` (etiqueta de la entrada: regla de
 * página/rama o 'global') y `message` (el mensaje PREFILL que el botón/enlace
 * envió, configurado por AK). Nunca el texto que el usuario escribe.
 *
 * Opt-in estricto: habilitado por la misma option del botón flotante o por
 * filter. Sin habilitación no se registra nada (cero impacto).
 */

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WhatsAppClickTracking
{
	const NS     = 'estandar-suite/capture/v1';
	const TABLE  = 'estandar_wa_clicks';
	const OPTION = 'estandar_suite_capture_wa_clicks_schema_version';
	// 1.1.0 (2026-09-01): cruce con capturas para atribución. Se agregan
	// session_id + ip_address para poder backfillear click_ids a una captura
	// que llegó a URL limpia (misma sesión/IP) → sube la cobertura GCLID.
	// 1.2.0 (2026-09-08): etiqueta de ENTRADA del clic. Se agregan `slug` +
	// `message` (prefill que el botón envió al momento del clic) para
	// identificar la fuente del WhatsApp (campaña/landing/branch) aunque el
	// contacto esté guardado por nombre en el WhatsApp del cliente.
	// 1.2.1 (2026-09-11): se agrega `number` (número de destino resuelto del
	// clic) para saber a qué WhatsApp derivó cada click, incluido el redirect
	// del chatbot. dbDelta agrega la columna sin tocar datos existentes.
	const SCHEMA_VERSION = '1.2.1';

	public static function init(): void
	{
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Opt-in: se activa con el botón flotante o con filter propio. */
	public static function enabled(): bool
	{
		$fwa = get_option( FloatingWhatsApp::SETTINGS_OPTION, array() );
		$fwa = is_array( $fwa ) ? $fwa : array();
		$from_fwa = ! empty( $fwa['enabled'] ) && ! empty( $fwa['track_clicks'] );
		$from_option = (string) get_option( 'estandar_suite_capture_wa_clicks_enabled', '' ) === '1';
		return (bool) apply_filters( 'estandar_suite_capture_wa_clicks_enabled', $from_fwa || $from_option );
	}

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			click_ids_json text,
			session_id varchar(64) NOT NULL DEFAULT '',
			ip_address varchar(45) NOT NULL DEFAULT '',
			page_path varchar(255) NOT NULL DEFAULT '',
			slug varchar(64) NOT NULL DEFAULT '',
			number varchar(32) NOT NULL DEFAULT '',
			message varchar(512) NOT NULL DEFAULT '',
			device varchar(20) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			referrer text,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY page_path (page_path),
			KEY session_id (session_id),
			KEY ip_address (ip_address)
		) $charset;" );
		update_option( self::OPTION, self::SCHEMA_VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::SCHEMA_VERSION ) {
			self::activate_schema();
		}
	}

	public static function routes(): void
	{
		register_rest_route( self::NS, '/wa/click', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'record_click' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** Guarda un click al botón de WhatsApp (POST público, con nonce suave). */
	public static function record_click( \WP_REST_Request $req ): \WP_REST_Response
	{
		// WA_CLICK_RL_V1 (2026-08-28): anti-spam del endpoint público (10/min/IP).
		$ip  = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key = 'es_wa_click_rl_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= 10 ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'rate_limited' ), 429 );
		}
		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );

		$json = $req->get_json_params();
		$json = is_array( $json ) ? $json : array();

		$data = array(
			'session' => (string) ( $json['session'] ?? '' ),
			'ip'      => (string) ( $json['ip'] ?? '' ),
			// Etiqueta de entrada (slug) + destino (number) + prefill (message).
			'slug'    => (string) ( $json['slug'] ?? '' ),
			'number'  => (string) ( $json['number'] ?? '' ),
			'message' => (string) ( $json['message'] ?? '' ),
			'page'    => (string) ( $json['page'] ?? '' ),
			'device'  => (string) ( $json['device'] ?? 'unknown' ),
			'ua'      => (string) ( $json['ua'] ?? '' ),
		);
		foreach ( Ingestion::ID_KEYS as $key ) {
			$data[ $key ] = (string) ( $json[ $key ] ?? '' );
		}
		$click_ids = self::record_event( $data );

		// El dato ya quedó local. A Google se lo da el GTM vía dataLayer (el
		// evento whatsapp_click se emite en el frontend; aquí solo se persiste).
		return new \WP_REST_Response( array( 'ok' => true, 'click_ids' => $click_ids ), 201 );
	}

	/**
	 * Persiste un click al botón de WhatsApp (uso interno de endpoints que no
	 * pasan por el rate-limit de `/wa/click`, p. ej. el redirect del chatbot).
	 * Devuelve las claves de click IDs presentes.
	 */
	public static function record_event( array $data ): array
	{
		$click_ids = array();
		foreach ( Ingestion::ID_KEYS as $key ) {
			$value = trim( (string) ( $data[ $key ] ?? '' ) );
			if ( $value !== '' ) {
				$click_ids[ $key ] = substr( $value, 0, 191 );
			}
		}

		global $wpdb;
		$session = sanitize_text_field( (string) ( $data['session'] ?? '' ) );
		$ip      = sanitize_text_field( (string) ( $data['ip'] ?? '' ) );
		if ( $ip === '' ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		}
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'click_ids_json' => wp_json_encode( $click_ids ),
				'session_id'     => substr( $session, 0, 63 ),
				'ip_address'     => substr( $ip, 0, 44 ),
				'page_path'      => substr( sanitize_text_field( (string) ( $data['page'] ?? '' ) ), 0, 254 ),
				'slug'           => sanitize_key( substr( (string) ( $data['slug'] ?? '' ), 0, 63 ) ),
				'number'         => substr( preg_replace( '/\D+/', '', (string) ( $data['number'] ?? '' ) ), 0, 31 ),
				'message'        => sanitize_text_field( substr( (string) ( $data['message'] ?? '' ), 0, 511 ) ),
				'device'         => substr( sanitize_key( (string) ( $data['device'] ?? 'unknown' ) ), 0, 19 ),
				'user_agent'     => substr( sanitize_text_field( (string) ( $data['ua'] ?? '' ) ), 0, 254 ),
				'referrer'       => isset( $_SERVER['HTTP_REFERER'] ) ? substr( sanitize_text_field( (string) $_SERVER['HTTP_REFERER'] ), 0, 254 ) : '',
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return array_keys( $click_ids );
	}
}
