<?php

namespace Estandar\Core\Communications;

use Estandar\Core\Communications\Transports\TransportInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TransportRegistry — registro y resolución de transportes de correo de la Suite.
 *
 * - Registra adapters (wp_mail, SMTP, Brevo, futuros).
 * - Descubre configuraciones heredadas (Brevo/SMTP/plugins SMTP/wp_mail filters)
 *   y las marca como "heredada / reutilizada".
 * - Resolución (orden canónico):
 *     1) transporte asignado a la acción/formulario;
 *     2) perfil predeterminado de la Suite (option estandar_suite_core_mail_transport);
 *     3) transporte heredado detectado y validado (brevo → smtp → wp_mail con filtros);
 *     4) wp_mail configurado por tercero;
 *     5) hosting directo solo si está permitido (option).
 *   Fallback explícito = wp_mail. Nunca fallback silencioso: el transporte
 *   realmente utilizado se registra en cada resultado.
 *
 * No almacena secretos: los adapters referencian la configuración desde su origen.
 */
class TransportRegistry
{
	const OPTION = 'estandar_suite_core_mail_transport';
	const ALLOW_DIRECT = 'estandar_suite_core_mail_allow_direct';

	/** @var TransportInterface[] */
	private static $transports = array();

	public static function register( TransportInterface $t ): void
	{
		self::$transports[ $t->name() ] = $t;
	}

	public static function all(): array
	{
		if ( ! self::$transports ) {
			self::register( new \Estandar\Core\Communications\Transports\WpMailTransport() );
			self::register( new \Estandar\Core\Communications\Transports\SmtpTransport() );
			self::register( new \Estandar\Core\Communications\Transports\BrevoTransport() );
		}
		return self::$transports;
	}

	public static function get( string $name ): ?TransportInterface
	{
		return self::all()[ $name ] ?? null;
	}

	/** Detecta configuraciones heredadas disponibles, en orden de preferencia. */
	public static function discover(): array
	{
		$order = array( 'brevo', 'smtp', 'wp_mail' );
		$found = array();
		foreach ( $order as $name ) {
			$t = self::get( $name );
			if ( $t && $t->is_configured() ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	/**
	 * Resuelve el transporte efectivo.
	 * $preferred = transporte pedido por la acción/formulario.
	 */
	public static function resolve( ?string $preferred = null ): TransportInterface
	{
		$all = self::all();
		if ( $preferred && isset( $all[ $preferred ] ) && $all[ $preferred ]->is_configured() ) {
			return $all[ $preferred ];
		}
		$default = (string) get_option( self::OPTION, '' );
		if ( $default !== '' && isset( $all[ $default ] ) && $all[ $default ]->is_configured() ) {
			return $all[ $default ];
		}
		foreach ( self::discover() as $name ) {
			return $all[ $name ]; // primero configurado en orden brevo→smtp→wp_mail
		}
		return $all['wp_mail']; // fallback explícito
	}

	/** Envía usando el transporte resuelto. Registra el transporte usado en el resultado. */
	public static function send( array $opts, ?string $preferred = null ): array
	{
		$transport = self::resolve( $preferred );
		$r = $transport->send( $opts );
		$r['transport'] = $transport->name();
		$r['transport_label'] = $transport->describe()['status'] ?? $transport->name();
		DeliveryLog::record( $r );
		return $r;
	}

	/** Health checks de todos los transportes SIN enviar correos reales. */
	public static function health_checks(): array
	{
		$out = array();
		foreach ( self::all() as $t ) {
			$out[ $t->name() ] = $t->health_check();
		}
		return $out;
	}

	/** Reporte completo (origen/estado de cada transporte). */
	public static function report(): array
	{
		$out = array();
		foreach ( self::all() as $t ) {
			$out[ $t->name() ] = $t->describe();
		}
		$out['_selected'] = self::resolve()->name();
		return $out;
	}
}
