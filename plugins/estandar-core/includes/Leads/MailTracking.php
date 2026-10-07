<?php

namespace Estandar\Core\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MailTracking — tracking de correo de LEADS (v2). Revive con ruta propia lo que
 * hacía el plugin legacy `estandar-leads/v1/go/{token}` (retirado): registra
 * APERTURAS (píxel 1x1) y CLICS (redirección) en `es_lead_events`, con los
 * mismos event_type que ya leen OperatorNotifier/LeadsPanel/OperatorActivity:
 *   - autoresponse_email_sent        (envío)
 *   - autoresponse_email_open_signal (píxel de apertura)
 *   - autoresponse_link_click        (clic en enlace del correo, ej. PDF)
 *
 * Seguridad: el payload va firmado con HMAC (wp_salt) → no se puede inyectar un
 * lead/evento arbitrario. El destino del clic se valida a http(s).
 * El adjunto NO es medible; lo medible es el enlace (por eso el PDF se enlaza).
 */
class MailTracking
{
	const NS = 'estandar-suite/core/v1';

	/** Registra las rutas REST (llamar en plugins_loaded). */
	public static function init(): void
	{
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void
	{
		register_rest_route( self::NS, '/track/o', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_open' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/track/c', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_click' ),
			'permission_callback' => '__return_true',
		) );
	}

	/** Firma un payload (HMAC-SHA256 con el salt de auth del sitio). */
	private static function key(): string
	{
		if ( function_exists( 'wp_salt' ) ) {
			return (string) wp_salt( 'auth' );
		}
		return 'estandar-mail-tracking';
	}

	private static function b64url_encode( string $raw ): string
	{
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	private static function b64url_decode( string $enc ): string
	{
		$enc = strtr( $enc, '-_', '+/' );
		$pad = strlen( $enc ) % 4;
		if ( $pad ) {
			$enc .= str_repeat( '=', 4 - $pad );
		}
		$out = base64_decode( $enc, true );
		return is_string( $out ) ? $out : '';
	}

	/** Genera un token firmado para un payload. */
	public static function token( array $data ): string
	{
		$json = (string) wp_json_encode( $data );
		$sig  = hash_hmac( 'sha256', $json, self::key() );
		return self::b64url_encode( $json ) . '.' . $sig;
	}

	/** Verifica y decodifica un token. Devuelve [] si es inválido. */
	public static function decode( string $token ): array
	{
		$parts = explode( '.', $token, 2 );
		if ( count( $parts ) !== 2 ) {
			return array();
		}
		$json = self::b64url_decode( $parts[0] );
		if ( $json === '' ) {
			return array();
		}
		$expected = hash_hmac( 'sha256', $json, self::key() );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/** URL del píxel de apertura (con cache-buster). */
	public static function open_url( array $data ): string
	{
		$base = function_exists( 'rest_url' ) ? rest_url( self::NS . '/track/o' ) : '';
		if ( $base === '' ) {
			return '';
		}
		return $base . '?d=' . rawurlencode( self::token( $data ) ) . '&t=' . time();
	}

	/** HTML del píxel 1x1 (o '' si no se puede). */
	public static function pixel( array $data ): string
	{
		$url = self::open_url( $data );
		if ( $url === '' ) {
			return '';
		}
		return '<img src="' . esc_url( $url ) . '" width="1" height="1" alt="" style="display:none!important;width:1px;height:1px;border:0;outline:none;" />';
	}

	/** URL de clic trackeada que redirige a $target. */
	public static function click_url( string $target, array $data ): string
	{
		$base = function_exists( 'rest_url' ) ? rest_url( self::NS . '/track/c' ) : '';
		if ( $base === '' || $target === '' ) {
			return $target;
		}
		return $base . '?d=' . rawurlencode( self::token( $data ) ) . '&u=' . rawurlencode( $target );
	}

	/** GET /track/o — registra la apertura y devuelve un gif 1x1. */
	public static function handle_open( $request ): void
	{
		$d = self::decode( (string) $request->get_param( 'd' ) );
		if ( ! empty( $d ) ) {
			$evt = (string) ( $d['evt'] ?? '' );
			self::record( $evt !== '' ? sanitize_key( $evt ) : 'autoresponse_email_open_signal', $d, null );
		}
		self::emit_gif();
	}

	/** GET /track/c — registra el clic y redirige al destino. */
	public static function handle_click( $request ): void
	{
		$d      = self::decode( (string) $request->get_param( 'd' ) );
		$target = (string) $request->get_param( 'u' );
		$target = self::safe_url( $target );
		if ( ! empty( $d ) && $target !== '' ) {
			$evt = (string) ( $d['evt'] ?? '' );
			self::record( $evt !== '' ? sanitize_key( $evt ) : 'autoresponse_link_click', $d, array(
				'resource_type' => (string) ( $d['resource'] ?? 'link' ),
				'target'        => $target,
			) );
		}
		if ( $target !== '' ) {
			wp_redirect( $target, 302 );
		}
		exit;
	}

	/** Registra un evento en es_lead_events (vía CrmIngestion, port fiel). */
	public static function record( string $event_type, array $d, ?array $extra ): void
	{
		if ( ! class_exists( 'Estandar\\Core\\Leads\\CrmIngestion' ) ) {
			return;
		}
		$metadata = array(
			'actor_type'              => (string) ( $d['actor'] ?? 'lead' ),
			'autoresponse_project_id' => (string) ( $d['project'] ?? '' ),
			'capture_id'              => (int) ( $d['capture_id'] ?? 0 ),
		);
		if ( is_array( $extra ) ) {
			$metadata = array_merge( $metadata, $extra );
		}
		CrmIngestion::insert_event( (int) ( $d['lead_id'] ?? 0 ), $event_type, array(
			'submission_id' => (int) ( $d['submission_id'] ?? 0 ),
			'operator_id'   => (int) ( $d['operator_id'] ?? 0 ),
			'metadata'      => $metadata,
			'ip_address'    => self::client_ip(),
			'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ) : '',
		) );
	}

	/** Resuelve el lead_id de un lead del CRM por email/phone normalizados. */
	public static function resolve_lead_id( array $capture, string $email = '', string $phone = '' ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$email = strtolower( trim( $email !== '' ? $email : (string) ( $capture['email_normalized'] ?? '' ) ) );
		$phone = trim( $phone !== '' ? $phone : (string) ( $capture['phone_normalized'] ?? '' ) );
		$table = $wpdb->prefix . 'es_leads';
		if ( $email !== '' ) {
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_normalized = %s ORDER BY id DESC LIMIT 1", $email ) );
			if ( $id ) {
				return (int) $id;
			}
		}
		if ( $phone !== '' ) {
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE phone_normalized = %s ORDER BY id DESC LIMIT 1", $phone ) );
			if ( $id ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Reporte agregado por proyecto: envíos, aperturas (señales) y clics al PDF.
	 *
	 * @return array<string,array{sent:int,opens:int,clicks:int}>
	 */
	public static function report( int $days = 90 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'es_lead_events';
		$types = array( 'autoresponse_email_sent', 'autoresponse_email_open_signal', 'autoresponse_link_click' );
		$in    = "'" . implode( "','", $types ) . "'";
		$sql   = "SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.autoresponse_project_id')) proj, event_type, COUNT(*) c "
			. "FROM {$table} WHERE event_type IN ({$in}) ";
		if ( $days > 0 ) {
			$sql = $wpdb->prepare( $sql . 'AND created_at >= %s ', gmdate( 'Y-m-d H:i:s', time() - $days * 86400 ) );
		}
		$sql .= 'GROUP BY proj, event_type';
		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$p = (string) ( $r['proj'] ?? '' );
			if ( $p === '' || strtolower( $p ) === 'null' ) { $p = '(sin proyecto)'; }
			if ( ! isset( $out[ $p ] ) ) { $out[ $p ] = array( 'sent' => 0, 'opens' => 0, 'clicks' => 0 ); }
			$et = (string) ( $r['event_type'] ?? '' );
			if ( $et === 'autoresponse_email_sent' ) { $out[ $p ]['sent'] = (int) $r['c']; }
			elseif ( $et === 'autoresponse_email_open_signal' ) { $out[ $p ]['opens'] = (int) $r['c']; }
			elseif ( $et === 'autoresponse_link_click' ) { $out[ $p ]['clicks'] = (int) $r['c']; }
		}
		ksort( $out );
		return $out;
	}

	/** Renderiza el panel de métricas (Core → Leads → Métricas de correo). */
	public static function render_report(): string
	{
		$days = isset( $_GET['dias'] ) ? (int) $_GET['dias'] : 90;
		$data = self::report( $days );
		$base = admin_url( 'admin.php?page=estandar-suite&tab=config&sub=leads&ss=metricas' );
		$tot  = array( 'sent' => 0, 'opens' => 0, 'clicks' => 0 );

		$pct = static function ( int $n, int $d ): string {
			return $d > 0 ? number_format_i18n( $n / $d * 100, 1 ) . '%' : '—';
		};

		$h  = '<h3>Métricas de correo (autorespuesta de leads)</h3>';
		$h .= '<p class="description" style="max-width:900px;">Envíos, <strong>aperturas</strong> (píxel) y <strong>clics</strong> en el enlace del PDF, por proyecto. '
			. 'El <strong>PDF adjunto no es medible</strong>; sí el enlace. Datos desde el tracking v2 (y el histórico legacy con el mismo formato).</p>';
		$h .= '<p>Período: ';
		foreach ( array( 30 => '30 días', 90 => '90 días', 365 => '1 año', 0 => 'Todo' ) as $d => $lbl ) {
			$active = ( $d === $days );
			$h .= '<a class="button' . ( $active ? ' button-primary' : '' ) . '" style="margin-right:4px;" href="' . esc_url( $base . '&dias=' . $d ) . '">' . esc_html( $lbl ) . '</a>';
		}
		$h .= '</p>';

		$h .= '<table class="widefat striped" style="max-width:1000px;"><thead><tr>'
			. '<th>Proyecto</th><th style="text-align:right;">Envíos</th><th style="text-align:right;">Aperturas</th>'
			. '<th style="text-align:right;">% apertura</th><th style="text-align:right;">Clics PDF</th><th style="text-align:right;">% clic</th></tr></thead><tbody>';
		foreach ( $data as $proj => $r ) {
			$tot['sent'] += $r['sent'];
			$tot['opens'] += $r['opens'];
			$tot['clicks'] += $r['clicks'];
			$h .= '<tr><td><strong>' . esc_html( $proj ) . '</strong></td>'
				. '<td style="text-align:right;">' . (int) $r['sent'] . '</td>'
				. '<td style="text-align:right;">' . (int) $r['opens'] . '</td>'
				. '<td style="text-align:right;">' . esc_html( $pct( (int) $r['opens'], (int) $r['sent'] ) ) . '</td>'
				. '<td style="text-align:right;">' . (int) $r['clicks'] . '</td>'
				. '<td style="text-align:right;">' . esc_html( $pct( (int) $r['clicks'], (int) $r['sent'] ) ) . '</td></tr>';
		}
		if ( empty( $data ) ) {
			$h .= '<tr><td colspan="6">Sin datos en el período.</td></tr>';
		}
		$h .= '<tr style="font-weight:600;background:#f6f7f7;"><td>Total</td>'
			. '<td style="text-align:right;">' . (int) $tot['sent'] . '</td>'
			. '<td style="text-align:right;">' . (int) $tot['opens'] . '</td>'
			. '<td style="text-align:right;">' . esc_html( $pct( $tot['opens'], $tot['sent'] ) ) . '</td>'
			. '<td style="text-align:right;">' . (int) $tot['clicks'] . '</td>'
			. '<td style="text-align:right;">' . esc_html( $pct( $tot['clicks'], $tot['sent'] ) ) . '</td></tr>';
		$h .= '</tbody></table>';
		return $h;
	}

	/** Solo http(s). */
	private static function safe_url( string $url ): string
	{
		$url = trim( $url );
		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return $url;
	}

	private static function client_ip(): string
	{
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $ip ) : $ip;
	}

	/** Emite un GIF transparente 1x1 y termina. */
	private static function emit_gif(): void
	{
		if ( ! headers_sent() ) {
			header( 'Content-Type: image/gif' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
		}
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		exit;
	}
}
