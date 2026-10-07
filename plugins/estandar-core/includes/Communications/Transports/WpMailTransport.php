<?php

namespace Estandar\Core\Communications\Transports;

use Estandar\Core\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WpMailTransport — transporte nativo wp_mail.
 *
 * Reutiliza el mecanismo estándar de WordPress y respeta TODOS los filtros
 * existentes (wp_mail_from, phpmailer_init, etc.) que terceros (plugins SMTP,
 * CF7, temas) hayan registrado. Es el fallback explícito de la Suite, no la
 * opción preferente automática.
 */
class WpMailTransport implements TransportInterface
{
	public function name(): string
	{
		return 'wp_mail';
	}

	public function is_configured(): bool
	{
		return function_exists( 'wp_mail' );
	}

	public function is_inherited(): bool
	{
		// Si hay filtros wp_mail de terceros, el transporte reutiliza esa config.
		return self::has_external_wp_mail_config();
	}

	public static function has_external_wp_mail_config(): bool
	{
		global $wp_filter;
		foreach ( array( 'wp_mail_from', 'wp_mail_from_name', 'pre_wp_mail', 'phpmailer_init' ) as $tag ) {
			if ( ! empty( $wp_filter[ $tag ]->callbacks ) ) {
				return true;
			}
		}
		return false;
	}

	public function describe(): array
	{
		$inherited = self::has_external_wp_mail_config();
		return array(
			'transport' => 'wp_mail',
			'status'    => $inherited ? 'Heredada / Reutilizada' : 'Nativo (fallback)',
			'inherited' => $inherited,
			'notes'     => $inherited ? 'Reutiliza filtros wp_mail existentes (From/SMTP de terceros).' : 'Fallback nativo de WordPress.',
		);
	}

	public function send( array $opts ): array
	{
		$opts['transport'] = 'wp_mail';
		$r = Mail::send_raw( $opts );
		$r['transport'] = 'wp_mail';
		return $r;
	}

	public function health_check(): array
	{
		return array(
			'ok'      => function_exists( 'wp_mail' ),
			'status'  => function_exists( 'wp_mail' ) ? 'ok' : 'unavailable',
			'detail'  => 'wp_mail ' . ( function_exists( 'wp_mail' ) ? 'disponible' : 'no disponible' ),
			'inherited' => self::has_external_wp_mail_config(),
		);
	}

	public function test( array $opts ): array
	{
		return $this->send( $opts );
	}
}
