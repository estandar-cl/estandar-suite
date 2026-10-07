<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook — webhook de salida del renderer v2 (F1).
 *
 * Puerto de Estandar_Forms_Webhook al producto nativo: dispara un POST
 * no-bloqueante al webhook_url configurado cuando send_webhook=1, con payload
 * schema estandar (v1.0, JSON) o cf7_compat (campos your-name/your-email/…)
 * para n8n/workflows existentes.
 *
 * A diferencia del legacy, se dispara desde el pipeline v2 con el capture
 * normalizado (no desde estandar_lead_captured), manteniendo el contrato
 * webhook_payload_mode + filtros de compatibilidad.
 */
class Webhook
{
	/** Dispara el webhook si el form lo tiene configurado. */
	public static function dispatch( array $config, array $capture ): array
	{
		$url = trim( (string) ( $config['webhook_url'] ?? '' ) );
		if ( empty( $config['send_webhook'] ) || $url === '' ) {
			return array( 'ok' => false, 'error' => 'disabled' );
		}
		if ( ! function_exists( 'wp_http_validate_url' ) || ! wp_http_validate_url( $url ) ) {
			return array( 'ok' => false, 'error' => 'invalid_url' );
		}
		$mode = sanitize_key( (string) ( $config['webhook_payload_mode'] ?? 'estandar' ) );
		if ( ! in_array( $mode, array( 'estandar', 'cf7_compat', 'gf_compat' ), true ) ) {
			$mode = 'estandar';
		}
		return self::send( $url, $capture, (int) ( $capture['source_form_id'] ?? 0 ), $mode, $config );
	}

	/**
	 * Envío no-bloqueante. Pure hasta el transporte (guard sin wp_remote_post).
	 */
	public static function send( string $url, array $capture, int $form_id, string $mode, array $config ): array
	{
		$args = array(
			'timeout'   => 8,
			'blocking'  => false,
			'headers'   => array( 'X-Source' => 'estandar-capture' ),
			'body'      => '',
			'sslverify' => true,
		);

		if ( $mode === 'cf7_compat' ) {
			$payload = self::build_cf7_payload( $capture );
			$payload = (array) apply_filters( 'estandar_forms_webhook_cf7_payload', $payload, $capture, $form_id );
			$args['body'] = $payload;
		} elseif ( $mode === 'gf_compat' ) {
			// CONTRATO GRAVITY FORMS: el workflow n8n del cliente lee
			// `body.lead["1.3"]`, `lead["16"]`, `lead["8"]`… (meta keys GF).
			// Si el payload no replica esa estructura, la hoja queda en blanco.
			$payload = self::build_gf_payload( $capture, $form_id, $config );
			$payload = (array) apply_filters( 'estandar_forms_webhook_gf_payload', $payload, $capture, $form_id );
			$body = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
			if ( ! $body ) {
				return array( 'ok' => false, 'error' => 'encode_failed' );
			}
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = $body;
		} else {
			$payload = (array) apply_filters( 'estandar_forms_webhook_payload', self::build_estandar_payload( $capture ), $form_id );
			$body = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
			if ( ! $body ) {
				return array( 'ok' => false, 'error' => 'encode_failed' );
			}
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = $body;
		}

		$args = (array) apply_filters( 'estandar_forms_webhook_args', $args, $url, $form_id );

		if ( ! function_exists( 'wp_remote_post' ) ) {
			return array( 'ok' => true, 'error' => 'no_transport', 'dry_run' => true );
		}
		wp_remote_post( $url, $args );

		$sent = is_array( $args['body'] ) ? $args['body'] : array( 'json' => (string) $args['body'] );
		do_action( 'estandar_forms_webhook_sent', $url, $sent, $form_id );
		return array( 'ok' => true, 'error' => '', 'mode' => $mode );
	}

	/** Payload estandar (schema v1.0) desde un capture normalizado v2. */
	public static function build_estandar_payload( array $capture ): array
	{
		$contact = array(
			'name'  => (string) ( $capture['name'] ?? '' ),
			'email' => (string) ( $capture['email_normalized'] ?? '' ),
			'phone' => (string) ( $capture['phone_normalized'] ?? '' ),
		);
		$fields = self::capture_fields( $capture );
		return array(
			'version'      => '1.0',
			'source_plugin' => 'estandar-capture',
			'content'      => array(
				'source_form_id' => (string) ( $capture['source_form_id'] ?? '' ),
				'message'        => (string) ( $capture['message'] ?? '' ),
			),
			'contact'      => $contact,
			'fields'       => $fields,
			'attribution'  => self::attribution_payload( $capture ),
			'created_at'   => (string) ( $capture['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
	}

	/** Payload cf7_compat (para n8n/workflows que esperan campos CF7). */
	public static function build_cf7_payload( array $capture ): array
	{
		$fields = self::capture_fields( $capture );
		return array(
			'_date'         => gmdate( 'j \d\e F \d\e Y', time() ),
			'your-name'     => self::first_value( $fields, array( 'your-name', 'nombre', 'nombre_propietario', 'name', 'first_name' ), (string) ( $capture['name'] ?? '' ) ),
			'your-apellido' => self::first_value( $fields, array( 'your-apellido', 'apellido', 'last_name' ) ),
			'your-email'    => self::first_value( $fields, array( 'your-email', 'email', 'correo', 'mail' ), (string) ( $capture['email_normalized'] ?? '' ) ),
			'your-phone'    => self::first_value( $fields, array( 'your-phone', 'telefono', 'phone', 'tel', 'celular' ), (string) ( $capture['phone_normalized'] ?? '' ) ),
			'tu-rut'        => self::first_value( $fields, array( 'tu-rut', 'rut' ) ),
		);
	}

	/** Campos del capture en forma de array: acepta `fields` (nativo) o `fields_json` (persistido). */
	private static function capture_fields( array $capture ): array
	{
		if ( is_array( $capture['fields'] ?? null ) ) {
			return $capture['fields'];
		}
		$decoded = json_decode( (string) ( $capture['fields_json'] ?? '{}' ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Payload GF compat (CONTRATO Gravity Forms → n8n).
	 *
	 * El workflow n8n del cliente (webhook 458e6ced) lee `body.lead` con las
	 * meta keys del form GF 14: "1.3" (nombre), "1.6" (apellido), "8" (RUT),
	 * "56" (DV), "16" (email), "3" (teléfono), "7" (proyecto), "12" (nº),
	 * "13" (tipo), "14" (requerimiento), "18" (solicitud), "62"/"43"/"44"/"57"/
	 * "46"/"64"/"58"/"63" (calle por proyecto) y "47"/"51"/"54"/"55" (lugar),
	 * "27"/"29"/"33"/"35" (descripción), "31"/"32"/"34"/"36" (fotos).
	 * Replica esa estructura exacta para que n8n llene la hoja igual que GF.
	 *
	 * @return array{form_id:int, lead:array, form:array}
	 */
	public static function build_gf_payload( array $capture, int $form_id, array $config = array() ): array
	{
		$posted = self::capture_fields( $capture );

		// Nombre: "nombre_propietario" (completo) o nombre+apellido separados.
		$nombre    = (string) ( $posted['nombre_propietario'] ?? $posted['nombre'] ?? '' );
		$apellido  = (string) ( $posted['apellido'] ?? '' );
		if ( $nombre === '' && $apellido === '' ) {
			$nombre = (string) ( $capture['name'] ?? '' );
		}

		// RUT: "12874376-6" (completo) o rut+dv separados.
		$rut = trim( (string) ( $posted['rut'] ?? '' ) );
		$dv  = trim( (string) ( $posted['dv'] ?? '' ) );
		if ( $dv === '' && preg_match( '/^(\d{7,8})([0-9kK])$/', preg_replace( '/[^0-9kK]/', '', $rut ), $m ) ) {
			$rut = $m[1];
			$dv  = '-' . strtolower( $m[2] );
		}
		if ( $dv !== '' && $dv[0] !== '-' ) {
			$dv = '-' . $dv;
		}

		$proyecto = (string) ( $posted['proyecto'] ?? '' );

		// Calle: según el proyecto (misma lógica que GF form 14).
		$calle_field = self::gf_calle_field_for_project( $proyecto );
		$calle       = (string) ( $posted['calle'] ?? '' );

		// Problemas: incidents[] (nativo) o lugar_problema_#/problema_# (survey).
		$incidents = json_decode( (string) ( $posted['incidents'] ?? '[]' ), true );
		$incidents = is_array( $incidents ) ? $incidents : array();

		$lead = array(
			'1.3'          => $nombre,
			'1.6'          => $apellido,
			'8'            => $rut,
			'56'           => $dv,
			'16'           => (string) ( $posted['email'] ?? $capture['email_normalized'] ?? '' ),
			'3'            => (string) ( $posted['telefono'] ?? $posted['phone'] ?? $capture['phone_normalized'] ?? '' ),
			'7'            => $proyecto,
			'12'           => (string) ( $posted['numero'] ?? '' ),
			'13'           => (string) ( $posted['tipo_postventa'] ?? '' ),
			'14'           => (string) ( $posted['requerimiento'] ?? '' ),
			'18'           => (string) ( $posted['solicitud'] ?? '' ),
			'id'           => (string) ( $capture['id'] ?? $capture['session_id'] ?? '' ),
			'status'       => 'active',
			'form_id'      => (string) $form_id,
			'ip'           => (string) ( $posted['ip'] ?? $capture['ip_address'] ?? '' ),
			'source_url'   => (string) ( $posted['source_url'] ?? $capture['landing_url'] ?? '' ),
			'date_created' => (string) ( $capture['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'date_updated' => (string) ( $capture['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
		if ( $calle_field !== '' && $calle !== '' ) {
			$lead[ $calle_field ] = $calle;
		}

		// Problemas: mapear incidents → campos GF (lugar/descripción/fotos).
		$lugar = array( 1 => '47', 2 => '51', 3 => '54', 4 => '55' );
		$desc  = array( 1 => '27', 2 => '29', 3 => '33', 4 => '35' );
		$foto  = array( 1 => '31', 2 => '32', 3 => '34', 4 => '36' );
		foreach ( array( 1, 2, 3, 4 ) as $i ) {
			$inc = isset( $incidents[ $i - 1 ] ) && is_array( $incidents[ $i - 1 ] ) ? $incidents[ $i - 1 ] : array();
			$loc = (string) ( $inc['location'] ?? ( $posted[ 'lugar_problema_' . $i ] ?? '' ) );
			$dsc = (string) ( $inc['description'] ?? ( $posted[ 'problema_' . $i ] ?? '' ) );
			$fots = array();
			foreach ( (array) ( $inc['fotos'] ?? array() ) as $u ) {
				$u = trim( (string) $u );
				if ( $u !== '' ) {
					$fots[] = $u;
				}
			}
			// LEAD_FLAT_PHOTOS_V1: un form LEAD guarda cada foto en un campo
			// plano type=file (p. ej. "adjunta_o_toma_una_imagen_con_tu_telefono"
			// para el bloque 1 y "_N" para 2-4) en vez de incidents[].fotos. Si el
			// incident no trajo fotos, se buscan en esos campos planos para que la
			// columna "Foto N" del Excel no quede vacía (mismo emparejamiento que
			// GfPostventaBridge::flat_incidents_from_fields).
			if ( ! $fots ) {
				$foto_flat = self::flat_photo_url_for( $posted, $i );
				if ( $foto_flat !== '' ) {
					$fots[] = $foto_flat;
				}
			}
			if ( $loc !== '' ) { $lead[ $lugar[ $i ] ] = $loc; }
			if ( $dsc !== '' ) { $lead[ $desc[ $i ] ] = $dsc; }
			if ( $fots !== array() ) {
				$lead[ $foto[ $i ] ] = wp_json_encode( $fots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		}

		// Form (GF envía la definición; el workflow solo usa lead, pero se
		// incluye por fidelidad al contrato).
		$form_title = (string) ( $config['name'] ?? 'Form' );
		return array(
			'form_id' => (int) $form_id,
			'lead'    => $lead,
			'form'    => array(
				'id'    => (int) $form_id,
				'title' => $form_title,
			),
		);
	}

	/** LEAD_FLAT_PHOTOS_V1 (PURE): URL de la foto plana (type=file) del bloque N
	 *  de un form lead. Bloque 1 = campo file cuyo name NO termina en _N; bloques
	 *  2-4 = campo file cuyo name termina en _N. Devuelve '' si no hay. */
	private static function flat_photo_url_for( array $posted, int $n ): string
	{
		foreach ( $posted as $name => $value ) {
			if ( ! is_string( $value ) || $value === '' ) {
				continue;
			}
			// solo URLs de uploads (subidas por el form)
			if ( strpos( $value, '/uploads/estandar-capture/survey/' ) === false && strpos( $value, 'uploads/estandar-capture/survey/' ) === false ) {
				continue;
			}
			$is_n = (bool) preg_match( '/_(' . $n . ')$/', (string) $name );
			$is_1 = ( $n === 1 && ! preg_match( '/_(\d+)$/', (string) $name ) );
			if ( $is_n || $is_1 ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Campo de calle de GF según el proyecto (mismo mapeo de field_id que el
	 * form 14).
	 *
	 * GF_CALLE_SLUG_V1 (2026-10-02): el value del select v2 es un **slug**
	 * (`villa_el_estero_casas`, `los_vinedos_nancagua`…) mientras los needles
	 * tenían espacios/acentos (`'villa el estero casas'`, `'viñedo'`) → no
	 * calzaban y la columna de calle de la hoja quedaba VACÍA para esos
	 * proyectos. Ahora se normaliza el value (acentos fuera + `_`/`-` → espacio)
	 * antes de comparar, sin cambiar los field_id.
	 */
	private static function gf_calle_field_for_project( string $proyecto ): string
	{
		$p = trim( $proyecto );
		if ( function_exists( 'remove_accents' ) ) {
			$p = remove_accents( $p );
		}
		$p = function_exists( 'mb_strtolower' ) ? mb_strtolower( $p, 'UTF-8' ) : strtolower( $p );
		$p = (string) preg_replace( '/[\s_\-]+/', ' ', $p );
		$map = array(
			'altacruz'                 => '62',
			'terracruz'                => '43',
			'villa el estero casas'    => '44',
			'villa el estero edificio' => '57',
			'vinedo'                   => '46',
			'parronal'                 => '64',
			'casas de quinta'          => '58',
			'esfuerzo'                 => '63',
		);
		foreach ( $map as $needle => $field_id ) {
			if ( strpos( $p, $needle ) !== false ) {
				return $field_id;
			}
		}
		return '';
	}

	private static function attribution_payload( array $capture ): array
	{
		$click_ids = json_decode( (string) ( $capture['click_ids_json'] ?? '{}' ), true );
		return array(
			'utm_source'   => (string) ( $capture['utm_source'] ?? '' ),
			'utm_medium'   => (string) ( $capture['utm_medium'] ?? '' ),
			'utm_campaign' => (string) ( $capture['utm_campaign'] ?? '' ),
			'click_ids'    => is_array( $click_ids ) ? $click_ids : array(),
			'landing_url'  => (string) ( $capture['landing_url'] ?? '' ),
			'referrer'     => (string) ( $capture['referrer'] ?? '' ),
			'ip'           => (string) ( $capture['ip_address'] ?? '' ),
			'session_id'   => (string) ( $capture['session_id'] ?? '' ),
		);
	}

	private static function first_value( array $fields, array $keys, string $fallback = '' ): string
	{
		foreach ( $keys as $key ) {
			if ( isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) ) {
				$value = trim( (string) $fields[ $key ] );
				if ( $value !== '' ) {
					return $value;
				}
			}
		}
		return trim( $fallback );
	}
}
