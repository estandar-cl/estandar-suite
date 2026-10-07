<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mail — motor único de correo de la Suite.
 *
 * Centraliza: plantillas HTML, variables, texto alternativo, remitente, reply-to,
 * CC/BCC, validación de direcciones, preview, transporte (wp_mail), envío de
 * prueba y log de resultados. Reutilizable por Forms (notificaciones y
 * autorespuesta), Surveys, Leads, Cotizaciones, Flow y futuros correos.
 *
 * No se crean motores separados: notificación y autorespuesta usan este motor.
 */
class Mail
{
	/** Valida una dirección de correo. */
	public static function validate_address( $email ): bool
	{
		if ( ! is_string( $email ) ) {
			return false;
		}
		$email = trim( $email );
		return $email !== '' && is_email( $email );
	}

	/** Normaliza una lista de destinatarios (string separado por comas o array). */
	public static function normalize_addresses( $list ): array
	{
		$out = array();
		$items = is_array( $list ) ? $list : preg_split( '/[\s,;]+/', (string) $list );
		foreach ( (array) $items as $item ) {
			$item = trim( (string) $item );
			if ( $item !== '' && self::validate_address( $item ) && ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/** Reemplaza variables {{clave}} en una plantilla HTML o texto. */
	public static function render( string $template, array $vars ): string
	{
		$map = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{{' . $k . '}}' ] = (string) ( is_scalar( $v ) ? $v : '' );
		}
		return strtr( $template, $map );
	}

	/**
	 * Envía un correo con el motor de la Suite, enrutado por TransportRegistry.
	 *
	 * El transporte efectivo se resuelve (acción → default Suite → heredado →
	 * wp_mail) y se registra en el resultado y en DeliveryLog. El resultado
	 * SIEMPRE incluye 'transport'.
	 *
	 * @param array $opts {
	 *   to, subject, html, alt, from_name, from_email, reply_to, cc, bcc, vars
	 * }
	 * @return array { ok, to, subject, error, transport }
	 */
	public static function send( array $opts ): array
	{
		return \Estandar\Core\Communications\TransportRegistry::send( $opts );
	}

	/**
	 * Envío de bajo nivel vía wp_mail (usado por WpMailTransport/SmtpTransport).
	 * No registra en DeliveryLog (lo hace TransportRegistry::send). No resuelve
	 * transporte: siempre wp_mail.
	 */
	public static function send_raw( array $opts ): array
	{
		$to      = self::normalize_addresses( $opts['to'] ?? array() );
		$subject = sanitize_text_field( (string) ( $opts['subject'] ?? '' ) );
		$html    = (string) ( $opts['html'] ?? '' );
		$alt     = (string) ( $opts['alt'] ?? wp_strip_all_tags( $html ) );
		$vars    = is_array( $opts['vars'] ?? null ) ? $opts['vars'] : array();

		if ( ! $to ) {
			return array( 'ok' => false, 'to' => $to, 'subject' => $subject, 'error' => 'Sin destinatarios válidos.' );
		}
		if ( $subject === '' || $html === '' ) {
			return array( 'ok' => false, 'to' => $to, 'subject' => $subject, 'error' => 'Asunto o HTML vacío.' );
		}

		$html = self::render( $html, $vars );
		$alt  = self::render( $alt, $vars );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $opts['from_name'] ) || ! empty( $opts['from_email'] ) ) {
			$from_name  = sanitize_text_field( (string) ( $opts['from_name'] ?? '' ) );
			$from_email = sanitize_email( (string) ( $opts['from_email'] ?? '' ) );
			if ( $from_email !== '' ) {
				$headers[] = 'From: ' . ( $from_name !== '' ? $from_name . ' <' . $from_email . '>' : $from_email );
			}
		}
		if ( ! empty( $opts['reply_to'] ) ) {
			$reply = self::normalize_addresses( $opts['reply_to'] );
			if ( $reply ) {
				$headers[] = 'Reply-To: ' . implode( ',', $reply );
			}
		}
		foreach ( array( 'cc', 'bcc' ) as $h ) {
			$addrs = self::normalize_addresses( $opts[ $h ] ?? array() );
			if ( $addrs ) {
				$headers[] = ucfirst( $h ) . ': ' . implode( ',', $addrs );
			}
		}

		try {
			$sent = wp_mail( $to, $subject, $html, $headers );
			return array( 'ok' => (bool) $sent, 'to' => $to, 'subject' => $subject, 'error' => $sent ? '' : 'wp_mail devolvió false.' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'to' => $to, 'subject' => $subject, 'error' => $e->getMessage() );
		}
	}

	/** Envío de prueba seguro (valida la configuración sin efectos externos reales si se desea). */
	public static function test( array $opts ): array
	{
		return self::send( $opts );
	}

	/** Preview (render de variables) sin enviar. */
	public static function preview( array $opts ): array
	{
		$vars = is_array( $opts['vars'] ?? null ) ? $opts['vars'] : array();
		return array(
			'html'    => self::render( (string) ( $opts['html'] ?? '' ), $vars ),
			'alt'     => self::render( (string) ( $opts['alt'] ?? wp_strip_all_tags( (string) ( $opts['html'] ?? '' ) ) ), $vars ),
			'subject' => self::render( (string) ( $opts['subject'] ?? '' ), $vars ),
		);
	}

	/** Registro de éxito/error (via telemetría de Core si existe). */
	public static function log( array $result ): void
	{
		if ( class_exists( 'Estandar\\Core\\Telemetry' ) ) {
			\Estandar\Core\Telemetry::emit(
				$result['ok'] ? 'info' : 'error',
				'SUITE_MAIL',
				$result['ok'] ? 'Email enviado.' : 'Email fallido.',
				array(
					'source_plugin' => 'estandar-core',
					'to'       => implode( ',', (array) ( $result['to'] ?? array() ) ),
					'subject'  => (string) ( $result['subject'] ?? '' ),
					'error'    => (string) ( $result['error'] ?? '' ),
				)
			);
		}
	}
}
