<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GfNativeBridge — re-emisión del contrato Gravity Forms desde forms nativos v2
 * (GF_NATIVE_V1, 2026-09-02).
 *
 * Para que un form nativo (creado como copia de un form GF) se comporte IGUAL
 * que el GF original al capturar una submission: correo de notificación con el
 * template GF real (byte-idéntico vía GFCommon::replace_variables) y webhook
 * n8n con el mapa exacto del feed GF.
 *
 * 100% ADITIVO: solo actúa si el config_json del form nativo declara la clave
 * `gf_native` (opcional). Sin esa clave el módulo es inerte — no toca el form de
 * cámara, ni los forms sin contrato, ni el comportamiento por defecto de la
 * suite en otros sitios.
 *
 * Contrato por form (en config_json del form nativo):
 *   "gf_native": {
 *     "gf_form_id": 7,                 // form GF de origen (para el template/entry)
 *     "webhook_url": "https://n8n…",   // opcional; si falta, no dispara webhook
 *     "webhook_map": { "nombre":"59", "correo":"4", … }, // custom_key → field_id GF
 *     "mail_body": "<html>…{all_fields}…</html>",        // template GF (opcional)
 *     "mail_subject": "Informe… {campo}",                // subject (opcional)
 *     "notify_to": "a@b.cl, c@d.cl"                      // destinatarios (opcional;
 *                                                        // si falta usa notify_emails del form)
 *   }
 */
class GfNativeBridge
{
	const HOOK = 'estandar_suite_capture_event';
	const NOTIF_KEY = 'gf_native';

	public static function init(): void
	{
		// Additive: se registra SIEMPRE, pero no hace nada sin contrato en el form.
		add_action( self::HOOK, array( __CLASS__, 'on_capture' ), 20, 1 );
	}

	/** Contrato GF de un form nativo (o null si no lo declara). */
	public static function contract( int $form_id ): ?array
	{
		if ( $form_id <= 0 || ! class_exists( __NAMESPACE__ . '\\Forms' ) ) {
			return null;
		}
		$form = Forms::get( $form_id );
		if ( ! is_array( $form ) || (string) ( $form['status'] ?? '' ) !== 'active' ) {
			return null;
		}
		$cfg = json_decode( (string) ( $form['config_json'] ?? '{}' ), true );
		$c   = is_array( $cfg ) ? ( $cfg[ self::NOTIF_KEY ] ?? null ) : null;
		if ( ! is_array( $c ) || empty( $c['gf_form_id'] ) ) {
			return null;
		}
		return $c;
	}

	/** Hook: tras cada captura, si el form tiene contrato GF → re-emite. */
	public static function on_capture( array $capture ): void
	{
		$form_id = (int) ( $capture['source_form_id'] ?? 0 );
		$c       = self::contract( $form_id );
		if ( ! $c ) {
			return;
		}
		self::process( $form_id, $c, $capture );
	}

	/**
	 * Procesa una captura de un form nativo con contrato GF:
	 *  1) reconstruye la entry GF (mapeando field_ids/labels/names),
	 *  2) envía el correo de notificación con el template GF,
	 *  3) dispara el webhook n8n con el mapa del feed.
	 * Best-effort: nunca lanza.
	 */
	public static function process( int $form_id, array $contract, array $capture ): array
	{
		$out = array( 'ok' => false, 'steps' => array() );
		$gf_form_id = (int) ( $contract['gf_form_id'] ?? 0 );
		$fields     = is_array( $capture['fields'] ?? null )
			? $capture['fields']
			: ( is_array( $capture['fields_json'] ?? null ) ? $capture['fields_json'] : array() );
		if ( ! $fields ) {
			$decoded = json_decode( (string) ( $capture['fields_json'] ?? '{}' ), true );
			$fields  = is_array( $decoded ) ? $decoded : array();
		}

		// Entry GF: mapear field_id GF → valor desde la submission.
		$entry = self::build_entry( $gf_form_id, $fields );
		$out['steps']['entry'] = 'ok';

		// 1) Correo de notificación.
		if ( ! empty( $contract['notify_to'] ) || ! empty( $contract['mail_body'] ) ) {
			$mail = self::send_mail( $gf_form_id, $contract, $entry, $fields );
			$out['steps']['mail'] = $mail;
		}

		// 2) Webhook n8n.
		if ( ! empty( $contract['webhook_url'] ) ) {
			$wh = self::send_webhook( $gf_form_id, $contract, $entry, $fields );
			$out['steps']['webhook'] = $wh;
		}

		$out['ok'] = ! empty( $out['steps']['mail']['ok'] ) || ! empty( $out['steps']['webhook']['ok'] )
			|| ( empty( $contract['notify_to'] ) && empty( $contract['mail_body'] ) && empty( $contract['webhook_url'] ) );
		return $out;
	}

	/** Reconstruye una entry GF con field_ids como claves, desde la submission.
	 *  Soportado: field_id directo ("59"), label GF y name del form nativo. */
	public static function build_entry( int $gf_form_id, array $fields ): array
	{
		$entry = array( 'id' => (string) ( $fields['gf_entry_id'] ?? '' ), 'form_id' => (string) $gf_form_id );

		// Mapa field_id GF → (label, name probable) desde el form GF real.
		$gf_map = self::gf_field_map( $gf_form_id );

		// 1) Valores que ya vienen con field_id GF como clave (submissions migradas).
		foreach ( $gf_map as $fid => $info ) {
			$val = '';
			if ( isset( $fields[ (string) $fid ] ) ) {
				$val = (string) $fields[ (string) $fid ];
			} elseif ( isset( $fields[ (string) $info['label'] ] ) ) {
				$val = (string) $fields[ (string) $info['label'] ];
			} elseif ( isset( $fields[ $info['name'] ] ) ) {
				$val = (string) $fields[ $info['name'] ];
			}
			if ( $val !== '' ) {
				$entry[ (string) $fid ] = $val;
			}
		}
		// 2) El resto de claves del form (labels → field_id) se intenta re-mapear.
		foreach ( $fields as $k => $v ) {
			$fid = self::resolve_field_id( $gf_map, $k );
			if ( $fid !== null && ! isset( $entry[ (string) $fid ] ) && is_scalar( $v ) ) {
				$entry[ (string) $fid ] = (string) $v;
			}
		}
		// 2b) Claves que ya son field_ids GF (enteros o "N.N") se copian tal cual
		// (submissions migradas por FormBridge guardan el field_id como clave).
		foreach ( $fields as $k => $v ) {
			$ks = (string) $k;
			if ( is_scalar( $v ) && preg_match( '/^\d+(\.\d+)?$/', $ks ) && ! isset( $entry[ $ks ] ) ) {
				$entry[ $ks ] = (string) $v;
			}
		}
		// 3) Sub-inputs compuestos (checkbox/name multi-input) pasan tal cual.
		foreach ( $fields as $k => $v ) {
			if ( is_scalar( $v ) && preg_match( '/^\d+\.\d+$/', (string) $k ) ) {
				$entry[ (string) $k ] = (string) $v;
			}
		}
		return $entry;
	}

	/**
	 * Notificación ADMIN de un form GF (la de destinatarios, sin la de
	 * "guardar/continuar después"). Prefiere la notificación con `to` no vacío
	 * y sin `{save_link}`; fallback a cualquiera con destinatarios reales.
	 */
	static function gf_admin_notification( int $gf_form_id ): array
	{
		if ( ! class_exists( '\GFAPI' ) || ! method_exists( '\GFAPI', 'get_form' ) ) {
			return array();
		}
		try {
			$form = \GFAPI::get_form( $gf_form_id );
			$notifs = is_array( $form ) ? ( $form['notifications'] ?? array() ) : array();
			foreach ( $notifs as $n ) {
				if ( ! is_array( $n ) ) { continue; }
				$to = (string) ( $n['to'] ?? '' );
				$msg = (string) ( $n['message'] ?? '' );
				if ( $to === '' || $to === 'None' || $msg === '' ) { continue; }
				if ( strpos( $msg, '{save_link}' ) !== false || strpos( $msg, '{resume_link}' ) !== false ) { continue; }
				return array(
					'to'      => $to,
					'subject' => (string) ( $n['subject'] ?? '' ),
					'message' => $msg,
				);
			}
		} catch ( \Throwable $e ) {
			// best-effort
		}
		return array();
	}

	/** Mapa field_id GF → ['label','name'] derivado del form GF real (si GFAPI activo). */
	static function gf_field_map( int $gf_form_id ): array
	{
		$map = array();
		if ( ! class_exists( '\GFAPI' ) || ! method_exists( '\GFAPI', 'get_form' ) ) {
			return $map;
		}
		try {
			$form = \GFAPI::get_form( $gf_form_id );
			if ( ! is_array( $form ) ) {
				return $map;
			}
			foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
				if ( is_object( $f ) && ! empty( $f->id ) ) {
					$label = trim( (string) ( $f->adminLabel ?: $f->label ) );
					$map[ (string) $f->id ] = array(
						'label' => $label !== '' ? $label : (string) $f->id,
						'name'  => sanitize_key( $label !== '' ? $label : (string) $f->id ),
					);
				} elseif ( is_array( $f ) && ! empty( $f['id'] ) ) {
					$label = trim( (string) ( $f['adminLabel'] ?: ( $f['label'] ?? '' ) ) );
					$map[ (string) $f['id'] ] = array(
						'label' => $label !== '' ? $label : (string) $f['id'],
						'name'  => sanitize_key( $label !== '' ? $label : (string) $f['id'] ),
					);
				}
			}
		} catch ( \Throwable $e ) {
			// best-effort
		}
		return $map;
	}

	/** Resuelve un field_id GF desde una clave de la submission (label o name). */
	static function resolve_field_id( array $gf_map, string $key ): ?string
	{
		$k = trim( $key );
		if ( $k === '' ) {
			return null;
		}
		if ( isset( $gf_map[ $k ] ) ) {
			return $k;
		}
		foreach ( $gf_map as $fid => $info ) {
			if ( $info['label'] === $k || $info['name'] === sanitize_key( $k ) || $info['name'] === str_replace( array( ' ', '¿', '?', 'á', 'é', 'í', 'ó', 'ú', 'ñ' ), array( '-', '', '', 'a', 'e', 'i', 'o', 'u', 'n' ), strtolower( $k ) ) ) {
				return $fid;
			}
		}
		return null;
	}

	/** Envía el correo de notificación replicando el template GF (byte-idéntico). */
	public static function send_mail( int $gf_form_id, array $contract, array $entry, array $fields ): array
	{
		$to      = trim( (string) ( $contract['notify_to'] ?? '' ) );
		$subject = trim( (string) ( $contract['mail_subject'] ?? '' ) );
		$body    = (string) ( $contract['mail_body'] ?? '' );

		// Si el contrato no trae template propio, se lee la notificación ADMIN
		// real del form GF (la de destinatarios, sin la de "guardar/continuar"),
		// igual que hace GfPostventaBridge — así el correo es byte-idéntico y
		// refleja cualquier edición posterior en GF (sin duplicar el HTML).
		$gf_notif = self::gf_admin_notification( $gf_form_id );
		if ( $body === '' && ! empty( $gf_notif['message'] ) ) {
			$body = (string) $gf_notif['message'];
		}
		if ( $to === '' && ! empty( $gf_notif['to'] ) ) {
			$to = (string) $gf_notif['to'];
		}
		if ( $subject === '' && ! empty( $gf_notif['subject'] ) ) {
			$subject = (string) $gf_notif['subject'];
		}

		if ( $to === '' && $body === '' && $subject === '' ) {
			return array( 'ok' => false, 'error' => 'sin_config_correo' );
		}

		// Render del template GF con los valores reales (si GFCommon disponible).
		if ( class_exists( '\GFCommon' ) && method_exists( '\GFCommon', 'replace_variables' ) && class_exists( '\GFAPI' ) && $body !== '' ) {
			try {
				$body = \GFCommon::replace_variables( $body, \GFAPI::get_form( $gf_form_id ), $entry, false, false, true, 'html' );
			} catch ( \Throwable $e ) {
				// si falla, se mantiene el template con merge tags sin resolver
			}
		}
		// El subject GF usa los mismos merge tags ({Cliente o proyecto:50}, {Fecha:5}…):
		// se resuelven con GFCommon igual que el cuerpo (byte-idéntico).
		if ( class_exists( '\GFCommon' ) && method_exists( '\GFCommon', 'replace_variables' ) && class_exists( '\GFAPI' ) && strpos( $subject, '{' ) !== false ) {
			try {
				$subject = \GFCommon::replace_variables( $subject, \GFAPI::get_form( $gf_form_id ), $entry, false, false, true, 'html' );
			} catch ( \Throwable $e ) {
				// fallback manual debajo
			}
		}
		// Fallback de subject con merge tags simples {campo} o {Label:id} (sin GF).
		if ( strpos( $subject, '{' ) !== false && preg_match_all( '/\{([^}]+)\}/', $subject, $m ) ) {
			foreach ( $m[1] as $tag ) {
				$val = self::entry_value( $entry, $tag );
				if ( $val === '' && strpos( $tag, ':' ) !== false ) {
					// {Label:field_id} → usar el field_id.
					$fid = substr( $tag, (int) strrpos( $tag, ':' ) + 1 );
					$val = self::entry_value( $entry, $fid );
				}
				if ( $val !== '' ) {
					$subject = str_replace( '{' . $tag . '}', $val, $subject );
				}
			}
		}

		// Envolver en <html><body> como GF si el template no lo trae.
		if ( $body !== '' && ! preg_match( '/<html/i', $body ) ) {
			$body = "<html>\n\t<head>\n\t\t<title>" . esc_html( $subject ) . "</title>\n\t</head>\n\t<body>\n\t\t" . $body . "\n\t</body>\n</html>";
		}

		if ( $to === '' || ! function_exists( 'class_exists' ) || ! class_exists( '\Estandar\Core\Mail' ) ) {
			return array( 'ok' => false, 'error' => 'sin_destinatarios_o_mail' );
		}
		try {
			$r = \Estandar\Core\Mail::send( array(
				'to'        => $to,
				'subject'   => $subject,
				'html'      => $body,
				'alt'       => wp_strip_all_tags( $body ),
				'from_name' => (string) ( $contract['mail_from_name'] ?? '' ),
				'from_email'=> (string) ( $contract['mail_from_email'] ?? '' ),
			) );
			$r['contract'] = 'gf-native';
			return $r;
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'error' => 'mail_exception', 'detail' => $e->getMessage() );
		}
	}

	/** Dispara el webhook n8n con el mapa exacto del feed GF. */
	public static function send_webhook( int $gf_form_id, array $contract, array $entry, array $fields ): array
	{
		$url = trim( (string) ( $contract['webhook_url'] ?? '' ) );
		if ( $url === '' || ! function_exists( 'wp_http_validate_url' ) || ! wp_http_validate_url( $url ) ) {
			return array( 'ok' => false, 'error' => 'invalid_url' );
		}
		$map = is_array( $contract['webhook_map'] ?? null ) ? $contract['webhook_map'] : array();
		$payload = array();
		foreach ( $map as $custom_key => $gf_field_id ) {
			$val = self::entry_value( $entry, (string) $gf_field_id );
			$payload[ (string) $custom_key ] = $val;
		}
		// Añadir metadatos útiles (id/entry/form) sin pisar claves del cliente.
		$payload['entry_id']  = (string) ( $entry['id'] ?? '' );
		$payload['form_id']   = (string) $gf_form_id;

		$body = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
		if ( ! $body ) {
			return array( 'ok' => false, 'error' => 'encode_failed' );
		}
		try {
			$resp = wp_remote_post( $url, array(
				'timeout'   => 10,
				'blocking'  => true,
				'headers'   => array( 'Content-Type' => 'application/json', 'X-Source' => 'estandar-gf-native' ),
				'body'      => $body,
			) );
			$code = (int) wp_remote_retrieve_response_code( $resp );
			return array( 'ok' => $code >= 200 && $code < 300, 'code' => $code, 'url' => $url );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'error' => 'webhook_exception', 'detail' => $e->getMessage() );
		}
	}

	/** Valor de una clave en la entry (acepta field_id, label o name normalizado). */
	static function entry_value( array $entry, string $key ): string
	{
		$k = trim( $key );
		if ( $k === '' ) {
			return '';
		}
		if ( isset( $entry[ $k ] ) ) {
			return (string) $entry[ $k ];
		}
		// Normalización sin acentos para coincidir con names tipo GF/suite.
		$norm = static function ( string $s ): string {
			return strtolower( trim( str_replace( array( ' ', '¿', '?', '¡', '!', '/', '(', ')' ), '', (string) iconv( 'UTF-8', 'ASCII//TRANSLIT', $s ) ) ) );
		};
		$kn = $norm( $k );
		foreach ( $entry as $ek => $ev ) {
			if ( $norm( (string) $ek ) === $kn ) {
				return (string) $ev;
			}
		}
		return '';
	}
}
