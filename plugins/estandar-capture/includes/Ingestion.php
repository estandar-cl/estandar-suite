<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture ingestion — normalizes and persists a capture (form/chat/landing/
 * popup/omnichannel) into estandar_capture_submissions and emits the canonical
 * `estandar_suite_capture_event` contract consumed by Brain/Flow/Insights.
 *
 * PURE normalize() is unit-testable; persist() is DB-bound and never writes to
 * legacy es_* tables (data safety gate).
 */
class Ingestion
{
	const TABLE   = 'estandar_capture_submissions';
	const OPTION  = 'estandar_suite_capture_schema_version';
	// STATUS_V1 (2026-08-28): se agrega la columna `status` (active/trash) que
	// submissions_for_form / submissions_count / set_submission_status ya usaban
	// y faltaba en el CREATE TABLE → DB error "Unknown column 'status'".
	// OPERATOR_V1 (2026-09-02): columnas `operator_name` / `operator_email` —
	// quién registró cada submission (desde la sesión de operador: PIN o magic).
	const VERSION = '1.4.0';

	/** Click/attribution identifiers the Capture product owns. */
	const ID_KEYS = array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'meta_fbc', 'meta_fbp', 'ttclid', 'msclkid' );

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			capture_uuid char(36) NOT NULL DEFAULT '',
			source varchar(40) NOT NULL DEFAULT 'form',
			source_form_id varchar(128) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			email_normalized varchar(190) NOT NULL DEFAULT '',
			phone_normalized varchar(64) NOT NULL DEFAULT '',
			message text,
			fields_json longtext,
			utm_source varchar(190) NOT NULL DEFAULT '',
			utm_medium varchar(190) NOT NULL DEFAULT '',
			utm_campaign varchar(190) NOT NULL DEFAULT '',
			utm_term varchar(190) NOT NULL DEFAULT '',
			utm_content varchar(190) NOT NULL DEFAULT '',
			click_ids_json text,
			landing_url text,
			referrer text,
			ip_address varchar(45) NOT NULL DEFAULT '',
			session_id varchar(64) NOT NULL DEFAULT '',
			time_on_page int(10) unsigned NOT NULL DEFAULT 0,
			geo_country varchar(100) NOT NULL DEFAULT '',
			geo_region varchar(100) NOT NULL DEFAULT '',
			geo_city varchar(100) NOT NULL DEFAULT '',
			spam_honeypot tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			repeat_reason varchar(64) NOT NULL DEFAULT '',
			repeat_note text,
			origin_lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
			operator_name varchar(190) NOT NULL DEFAULT '',
			operator_email varchar(190) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY capture_uuid (capture_uuid),
			KEY source (source),
			KEY email (email_normalized),
			KEY created_at (created_at),
			KEY operator_name (operator_name)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/**
	 * PURE normalize. Input: arbitrary capture payload. Output: canonical
	 * capture array with normalized identity + attribution + spam flags.
	 */
	public static function normalize( array $raw ): array
	{
		$contact = is_array( $raw['contact'] ?? null ) ? $raw['contact'] : array();
		$content = is_array( $raw['content'] ?? null ) ? $raw['content'] : array();
		$attr    = is_array( $raw['attribution'] ?? null ) ? $raw['attribution'] : array();
		$spam    = is_array( $raw['spam'] ?? null ) ? $raw['spam'] : array();

		// CAPTURE_CONTEXT_V1 — geolocalización por IP vía helper de primer
		// (graceful: sin primer no hay geo, sin llamada externa).
		$geo = array();
		$ip  = sanitize_text_field( (string) ( $raw['ip'] ?? '' ) );
		if ( $ip !== '' && function_exists( 'estandar_core_geo_ip_lookup' ) ) {
			$lookup = estandar_core_geo_ip_lookup( $ip );
			if ( is_array( $lookup ) ) {
				$geo = $lookup;
			}
		}

		$email = strtolower( trim( (string) ( $contact['email'] ?? '' ) ) );
		$phone = preg_replace( '/\D+/', '', (string) ( $contact['phone'] ?? '' ) );

		// OPERATOR_V1: quién registró la submission. Prioridad: valor explícito
		// en el payload (edición manual del visor) → sesión de operador activa
		// (PIN o magic link). Vía pública de LeadsPanel, sin acoplarse a Core.
		$operator_name  = sanitize_text_field( (string) ( $raw['operator_name'] ?? '' ) );
		$operator_email = sanitize_email( (string) ( $raw['operator_email'] ?? '' ) );
		if ( $operator_name === '' && function_exists( 'class_exists' ) && class_exists( 'Estandar\\Core\\LeadsPanel' ) ) {
			$LP = 'Estandar\\Core\\LeadsPanel';
			if ( $operator_name === '' ) {
				$operator_name = (string) $LP::current_operator();
			}
			if ( $operator_name === '' && method_exists( $LP, 'magic_session_email' ) ) {
				$magic = (string) $LP::magic_session_email();
				if ( $magic !== '' ) {
					$operator_email = $magic;
					$operator_name  = $magic;
				}
			}
		}

		$click_ids = array();
		foreach ( self::ID_KEYS as $key ) {
			$value = trim( (string) ( $attr[ $key ] ?? '' ) );
			if ( $value !== '' ) {
				$click_ids[ $key ] = $value;
			}
		}

		return array(
			'capture_uuid'     => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::fallback_uuid(),
			'source'           => sanitize_key( (string) ( $raw['source'] ?? 'form' ) ),
			'source_form_id'   => sanitize_text_field( (string) ( $content['source_form_id'] ?? '' ) ),
			// PROJECT_SLUG_V1 (2026-09-11): el proyecto resuelto por el renderer
			// (form_project / routing) viaja en `fields.project_slug`. Sin esto,
			// el pipeline (estandar-flow) y el brain quedaban SIN proyecto
			// (project_slug='') y no se ruteaban los leads. Se conserva top-level
			// para consumidores del contrato `estandar_suite_capture_event`.
			'project_slug'     => sanitize_title( remove_accents( (string) ( $raw['project_slug'] ?? ( is_array( $raw['fields'] ?? null ) ? ( $raw['fields']['project_slug'] ?? '' ) : '' ) ) ) ),
			'name'             => self::normalize_name( (string) ( $contact['name'] ?? '' ) ),
			'email_normalized' => is_email( $email ) ? $email : '',
			'phone_normalized' => self::normalize_phone( $phone ),
			'message'          => sanitize_textarea_field( (string) ( $content['message'] ?? '' ) ),
			'fields_json'      => wp_json_encode( is_array( $raw['fields'] ?? null ) ? $raw['fields'] : array() ),
			'utm_source'       => sanitize_key( (string) ( $attr['utm_source'] ?? '' ) ),
			'utm_medium'       => sanitize_key( (string) ( $attr['utm_medium'] ?? '' ) ),
			'utm_campaign'     => sanitize_text_field( (string) ( $attr['utm_campaign'] ?? '' ) ),
			'utm_term'         => sanitize_text_field( (string) ( $attr['utm_term'] ?? '' ) ),
			'utm_content'      => sanitize_text_field( (string) ( $attr['utm_content'] ?? '' ) ),
			'click_ids_json'   => wp_json_encode( $click_ids ),
			'landing_url'      => esc_url_raw( (string) ( $attr['landing_url'] ?? '' ) ),
			'referrer'         => esc_url_raw( (string) ( $attr['referrer'] ?? '' ) ),
			'ip_address'       => sanitize_text_field( (string) ( $raw['ip'] ?? '' ) ),
			// CAPTURE_CONTEXT_V1: sesión, tiempo en página y geolocalización
			// (geo vía helper de primer cuando está presente; los plugins
			// sobreviven por separado: sin primer, se omite sin error).
			'session_id'       => sanitize_text_field( (string) ( $raw['session_id'] ?? '' ) ),
			'time_on_page'     => (int) ( $raw['time_on_page'] ?? 0 ),
			'geo_country'      => sanitize_text_field( (string) ( $geo['country'] ?? '' ) ),
			'geo_region'       => sanitize_text_field( (string) ( $geo['region'] ?? '' ) ),
			'geo_city'         => sanitize_text_field( (string) ( $geo['city'] ?? '' ) ),
			'spam_honeypot'    => ! empty( $spam['honeypot_filled'] ) ? 1 : 0,
			'operator_name'    => $operator_name,
			'operator_email'   => $operator_email,
			'created_at'       => self::normalize_created_at( $raw ),
		);
	}

	/**
	 * PURE: timestamp de creación. Por defecto = ahora en zona de WordPress
	 * (TIMEZONE_V1). Permite un override legítimo (`created_at` en el payload)
	 * para retro-imports / adopciones donde se debe preservar la fecha ORIGINAL
	 * del evento (p.ej. backfill de entries de Gravity Forms — GF guarda en
	 * zona WP, así que se conserva la hora de pared). Ignora valores inválidos.
	 */
	public static function normalize_created_at( array $raw ): string
	{
		$candidate = trim( (string) ( $raw['created_at'] ?? '' ) );
		if ( $candidate !== '' ) {
			$ts = strtotime( $candidate );
			if ( $ts !== false ) {
				// TIMEZONE_V1: si el candidato trae zona explícita (Z/±HHMM) se
				// convierte a la zona de WP; si es naive (GF/imports guardan en
				// zona WP) se conserva su hora de pared.
				$has_tz = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $candidate );
				if ( $has_tz ) {
					$offset = function_exists( 'get_option' ) ? (int) get_option( 'gmt_offset', 0 ) : 0;
					return gmdate( 'Y-m-d H:i:s', $ts - $offset * 3600 );
				}
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		return function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	public static function fallback_uuid(): string
	{
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
	}

	/** Pure phone → E.164-style digits (56 prefix heuristic, no region hardcode). */
	public static function normalize_phone( string $digits ): string
	{
		$digits = preg_replace( '/\D+/', '', $digits );
		if ( $digits === '' ) {
			return '';
		}
		if ( strpos( $digits, '0056' ) === 0 ) {
			$digits = substr( $digits, 2 );
		}
		if ( strlen( $digits ) === 8 || strlen( $digits ) === 9 ) {
			return '56' . $digits;
		}
		// Teléfono inválido/incompleto (menos de 8 dígitos efectivos): no aceptar.
		return strlen( $digits ) >= 8 ? $digits : '';
	}

	/**
	 * PURE: extrae datos de contacto (email/teléfono/nombre) de texto libre.
	 * Equivalente PHP del `extractLeadFromText` del widget. Se usa para
	 * capturar un lead que el visitante escribió dentro de la conversación.
	 * Devuelve '' en cada campo sin coincidencia.
	 */
	public static function extract_contact( string $text ): array
	{
		$text  = (string) $text;
		$email = '';
		$phone = '';
		$name  = '';

		if ( preg_match( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m ) ) {
			$email = (string) sanitize_email( $m[0] );
		}

		// Teléfono: al menos 8 dígitos efectivos (con separadores opcionales).
		if ( preg_match( '/(?:\+?\d[\d\s().\-]{6,}\d)/', $text, $m ) ) {
			$digits = preg_replace( '/\D+/', '', $m[0] );
			if ( strlen( (string) $digits ) >= 8 ) {
				$phone = self::normalize_phone( (string) $digits );
			}
		}

		// Nombre: solo cuando el visitante lo declara explícitamente.
		if ( preg_match( '/(?:me llamo|mi nombre es)\s+([A-Za-zÁÉÍÓÚáéíóúÑñ]+(?:\s+[A-Za-zÁÉÍÓÚáéíóúÑñ]+){0,3})/iu', $text, $m ) ) {
			$name = self::normalize_name( trim( (string) $m[1] ) );
		}

		return array( 'email' => $email, 'phone' => $phone, 'name' => $name );
	}

	/**
	 * PURE: normaliza un nombre a "solo iniciales en mayúscula, resto en
	 * minúsculas" (p.ej. "MARÍA JOSÉ DE LA CRUZ" → "María José De La Cruz").
	 * Limpia espacios múltiples y aplica ucfirst por palabra (MB_SAFE).
	 * Translitera fuentes fancy (matemática alfanumérica unicode, full-width)
	 * a su forma normal vía NFKC cuando intl está disponible (fallback puro).
	 * NAME_ADORN_V1 (2026-09-15): descarta el adorno con que la gente decora
	 * su nombre (emoji, símbolos, caracteres "decorativos" tipo ꧁ ✷ ★) y reúne
	 * los nombres deletreados con espacios ("R O S A R I O" → "Rosario").
	 */
	public static function normalize_name( string $name ): string
	{
		$name = trim( (string) $name );
		if ( $name === '' ) {
			return '';
		}
		if ( class_exists( '\\Normalizer' ) && function_exists( '\\normalizer_normalize' ) ) {
			$name = normalizer_normalize( $name, \Normalizer::NFKC );
			if ( $name === false ) {
				$name = (string) $name;
			}
		}
		// Solo letras, marcas diacríticas, espacios, guion, apóstrofo y punto.
		$clean = preg_replace( '/[^\p{L}\p{M}\s\'\-\.]/u', ' ', $name );
		if ( $clean !== null ) {
			$name = $clean;
		}
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		// Corridas de 3+ letras sueltas (nombre "deletreado") → una sola palabra.
		$joined = preg_replace_callback(
			'/(?<![\p{L}])(?:\p{L}\s){2,}\p{L}(?![\p{L}])/u',
			static function ( $m ) {
				return preg_replace( '/\s+/u', '', (string) $m[0] );
			},
			$name
		);
		if ( $joined !== null ) {
			$name = trim( (string) preg_replace( '/\s+/u', ' ', $joined ) );
		}
		$words = explode( ' ', $name );
		$out = array();
		foreach ( $words as $word ) {
			$word = trim( $word );
			if ( $word === '' ) {
				continue;
			}
			$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );
			$out[] = function_exists( 'mb_convert_case' )
				? mb_convert_case( $lower, MB_CASE_TITLE, 'UTF-8' )
				: ucfirst( $lower );
		}
		$result = implode( ' ', $out );
		if ( function_exists( 'apply_filters' ) ) {
			$result = (string) apply_filters( 'estandar_suite_capture_normalize_name', $result, $name );
		}
		return $result;
	}

	/**
	 * PURE: normaliza un RUT chileno a formato canónico único "12.345.678-9"
	 * (grupos de miles con punto, guion antes del DV, DV en mayúscula).
	 * Acepta "17.716.480-1", "177164801", "17.716.4801"… Devuelve '' si el RUT
	 * no es parseable (menos de 8 dígitos, DV no alfanumérico).
	 */
	public static function normalize_rut( string $rut ): string
	{
		$rut = strtoupper( $rut );
		$rut = preg_replace( '/[^0-9K]/', '', $rut );
		if ( $rut === '' || strlen( $rut ) < 8 ) {
			return '';
		}
		$dv  = substr( $rut, -1 );
		$num = substr( $rut, 0, -1 );
		if ( ! ctype_digit( $num ) || ( ! ctype_digit( $dv ) && $dv !== 'K' ) ) {
			return '';
		}
		$num = number_format( (int) $num, 0, '', '.' );
		return $num . '-' . $dv;
	}

	/** Column→format map (single source for insert + row_for_insert). */
	public static function columns(): array
	{
		return array(
			'capture_uuid'     => '%s',
			'source'           => '%s',
			'source_form_id'   => '%s',
			'name'             => '%s',
			'email_normalized' => '%s',
			'phone_normalized' => '%s',
			'message'          => '%s',
			'fields_json'      => '%s',
			'utm_source'       => '%s',
			'utm_medium'       => '%s',
			'utm_campaign'     => '%s',
			'utm_term'         => '%s',
			'utm_content'      => '%s',
			'click_ids_json'   => '%s',
			'landing_url'      => '%s',
			'referrer'         => '%s',
			'ip_address'       => '%s',
			'session_id'       => '%s',
			'time_on_page'     => '%d',
			'geo_country'      => '%s',
			'geo_region'       => '%s',
			'geo_city'         => '%s',
			'spam_honeypot'    => '%d',
			'repeat_reason'    => '%s',
			'repeat_note'      => '%s',
			'origin_lead_id'   => '%d',
			'operator_name'    => '%s',
			'operator_email'   => '%s',
			'created_at'       => '%s',
		);
	}

	/** PURE: build the insert row (data + formats) from any capture shape. */
	public static function row_for_insert( array $capture ): array
	{
		$data    = array();
		$formats = array();
		foreach ( self::columns() as $column => $format ) {
			$data[ $column ] = isset( $capture[ $column ] ) ? $capture[ $column ] : ( $format === '%d' ? 0 : '' );
			$formats[]       = $format;
		}
		return array( 'data' => $data, 'formats' => $formats );
	}

	/** Persist a normalized capture. Returns id (0 on failure). */
	public static function persist( array $capture ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$row = self::row_for_insert( $capture );
		$wpdb->insert( $wpdb->prefix . self::TABLE, $row['data'], $row['formats'] );
		return (int) $wpdb->insert_id;
	}

	/** Ingest a raw capture end-to-end: normalize → persist → emit contract. */
	public static function ingest( array $raw ): array
	{
		$capture = self::normalize( $raw );
		$id      = self::persist( $capture );
		if ( $id > 0 ) {
			$capture['id'] = $id;
			do_action( 'estandar_suite_capture_event', $capture );
		}
		return array( 'id' => $id, 'capture' => $capture );
	}

	/** Read API for a capture. */
	public static function get( int $id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d LIMIT 1", $id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}
