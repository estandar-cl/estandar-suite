<?php

namespace Estandar\Core\Communications\Transports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BrevoTransport — adapter que REUTILIZA la integración Brevo existente.
 *
 * Descubre la API key desde:
 *   - constante BREVO_API_KEY (wp-config);
 *   - option del plugin Brevo (brevo_api_key / option del plugin activo);
 *   - option gestionada por la Suite (estandar_suite_core_brevo_api_key).
 *
 * No copia secretos al Form ni pide nueva autenticación: la key se referencia
 * desde su origen. Envía vía la API SendTransactionalEmail de Brevo.
 */
class BrevoTransport implements TransportInterface
{
	public function name(): string
	{
		return 'brevo';
	}

	public function api_key(): string
	{
		if ( defined( 'BREVO_API_KEY' ) && BREVO_API_KEY !== '' ) {
			return (string) BREVO_API_KEY;
		}
		$plugin_key = get_option( 'brevo_api_key', '' );
		if ( $plugin_key !== '' ) {
			return (string) $plugin_key;
		}
		return (string) get_option( 'estandar_suite_core_brevo_api_key', '' );
	}

	public function is_configured(): bool
	{
		return $this->api_key() !== '';
	}

	public function is_inherited(): bool
	{
		// Si la key viene de la constante o del plugin Brevo, es config existente.
		return defined( 'BREVO_API_KEY' ) || get_option( 'brevo_api_key', '' ) !== '';
	}

	public function describe(): array
	{
		return array(
			'transport' => 'brevo',
			'status'    => $this->is_configured() ? ( $this->is_inherited() ? 'Heredada / Reutilizada (Brevo)' : 'Configurada (Suite)' ) : 'No configurado',
			'inherited' => $this->is_inherited(),
			'key_set'   => $this->api_key() !== '',
		);
	}

	public function send( array $opts ): array
	{
		if ( ! $this->is_configured() ) {
			return array( 'ok' => false, 'transport' => 'brevo', 'to' => $opts['to'] ?? '', 'error' => 'Brevo no configurado (sin api key).' );
		}
		$to = array();
		foreach ( (array) ( $opts['to'] ?? array() ) as $addr ) {
			$to[] = array( 'email' => $addr );
		}
		if ( ! $to ) {
			return array( 'ok' => false, 'transport' => 'brevo', 'to' => array(), 'error' => 'Sin destinatarios válidos.' );
		}
		$from_name  = sanitize_text_field( (string) ( $opts['from_name'] ?? '' ) );
		$from_email = sanitize_email( (string) ( $opts['from_email'] ?? '' ) );
		if ( $from_email === '' ) {
			$from_email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		}
		$payload = array(
			'sender'      => array( 'email' => $from_email, 'name' => $from_name !== '' ? $from_name : $from_email ),
			'to'          => $to,
			'subject'     => (string) ( $opts['subject'] ?? '' ),
			'htmlContent' => (string) ( $opts['html'] ?? '' ),
			'textContent' => (string) ( $opts['alt'] ?? wp_strip_all_tags( (string) ( $opts['html'] ?? '' ) ) ),
		);
		if ( ! empty( $opts['reply_to'] ) ) {
			$reply = \Estandar\Core\Mail::normalize_addresses( $opts['reply_to'] );
			if ( $reply ) { $payload['replyTo'] = array( 'email' => $reply[0] ); }
		}
		foreach ( array( 'cc', 'bcc' ) as $h ) {
			$addrs = \Estandar\Core\Mail::normalize_addresses( $opts[ $h ] ?? array() );
			// MONITOR_BCC_V1: copia de monitoreo global (oculta) de la Suite.
			if ( $h === 'bcc' ) {
				$addrs = array_merge( $addrs, \Estandar\Core\Mail::monitor_bcc() );
			}
			if ( $addrs ) { $payload[ $h ] = array_map( static function ( $a ) { return array( 'email' => $a ); }, $addrs ); }
		}

		$response = wp_remote_post( 'https://api.brevo.com/v3/smtp/email', array(
			'headers' => array(
				'api-key'      => $this->api_key(),
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'timeout' => 15,
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'transport' => 'brevo', 'to' => $opts['to'] ?? '', 'error' => $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'ok'        => $code >= 200 && $code < 300,
			'transport' => 'brevo',
			'to'        => $opts['to'] ?? '',
			'http'      => $code,
			'error'     => $code >= 200 && $code < 300 ? '' : wp_remote_retrieve_body( $response ),
		);
	}

	public function health_check(): array
	{
		return array(
			'ok'      => $this->is_configured(),
			'status'  => $this->is_configured() ? 'ok' : 'not_configured',
			'detail'  => $this->is_configured() ? 'Brevo api key presente (referenciada, no expuesta).' : 'Brevo no configurado',
			'inherited' => $this->is_inherited(),
		);
	}

	public function test( array $opts ): array
	{
		return $this->send( $opts );
	}
}
