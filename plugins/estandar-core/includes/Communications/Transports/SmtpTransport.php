<?php

namespace Estandar\Core\Communications\Transports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SmtpTransport — adapter que REUTILIZA una configuración SMTP existente.
 *
 * No crea ni mueve secretos. Descubre SMTP desde:
 *   - constantes de wp-config (SMTP_HOST/PORT/USER/PASS/AUTH/SECURE);
 *   - options del host (smtp_host, smtp_port, …) gestionadas por la Suite;
 *   - plugins SMTP de terceros (registran phpmailer_init → wp_mail ya los usa).
 *
 * Si el SMTP está configurado por un tercero (plugin SMTP), el transporte actúa
 * vía wp_mail (que ya respeta phpmailer_init). Solo inyecta ajustes SMTP propios
 * cuando la configuración es nativa de la Suite (sin plugin externo).
 */
class SmtpTransport implements TransportInterface
{
	public function name(): string
	{
		return 'smtp';
	}

	public function is_configured(): bool
	{
		// Host configurado (constantes/Suite) o plugin SMTP externo activo
		// (el plugin entrega por SMTP vía phpmailer_init → wp_mail lo respeta).
		return $this->host() !== '' || self::has_external_smtp_plugin();
	}

	public function is_inherited(): bool
	{
		return self::has_external_smtp_plugin();
	}

	public static function has_external_smtp_plugin(): bool
	{
		$plugins = (array) get_option( 'active_plugins', array() );
		foreach ( $plugins as $p ) {
			if ( preg_match( '/smtp|postman|wp-mail|easy-wp-smtp/i', $p ) ) {
				return true;
			}
		}
		return false;
	}

	public function host(): string
	{
		if ( defined( 'SMTP_HOST' ) && SMTP_HOST !== '' ) {
			return (string) SMTP_HOST;
		}
		return (string) get_option( 'estandar_suite_core_smtp_host', '' );
	}

	public function describe(): array
	{
		$host = $this->host();
		if ( $host === '' && self::has_external_smtp_plugin() ) {
			$status = 'Heredada / Reutilizada (plugin SMTP)';
		} elseif ( $host !== '' && self::has_external_smtp_plugin() ) {
			$status = 'Heredada / Reutilizada (plugin SMTP + host)';
		} elseif ( $host !== '' ) {
			$status = 'Configurada (Suite)';
		} else {
			$status = 'No configurado';
		}
		return array(
			'transport' => 'smtp',
			'status'    => $status,
			'inherited' => $this->is_inherited(),
			'host'      => $host !== '' ? $host : '',
		);
	}

	/**
	 * Envía. Si hay plugin SMTP externo, delega en wp_mail (respeta phpmailer_init).
	 * Si la configuración es de la Suite, inyecta los ajustes en phpmailer_init.
	 */
	public function send( array $opts ): array
	{
		if ( self::has_external_smtp_plugin() || $this->host() === '' ) {
			$opts['transport'] = 'smtp';
			$r = \Estandar\Core\Mail::send_raw( $opts );
			$r['transport'] = 'smtp';
			return $r;
		}
		// Config SMTP nativa de la Suite: hook phpmailer_init temporal.
		$host = $this->host();
		$port = defined( 'SMTP_PORT' ) ? (int) SMTP_PORT : (int) get_option( 'estandar_suite_core_smtp_port', 587 );
		$user = defined( 'SMTP_USER' ) ? (string) SMTP_USER : (string) get_option( 'estandar_suite_core_smtp_user', '' );
		$pass = defined( 'SMTP_PASS' ) ? (string) SMTP_PASS : (string) get_option( 'estandar_suite_core_smtp_pass', '' );
		$auth = ( defined( 'SMTP_AUTH' ) ? SMTP_AUTH : get_option( 'estandar_suite_core_smtp_auth', 1 ) );
		$sec  = defined( 'SMTP_SECURE' ) ? (string) SMTP_SECURE : (string) get_option( 'estandar_suite_core_smtp_secure', 'tls' );

		$cb = static function ( $phpmailer ) use ( $host, $port, $user, $pass, $auth, $sec ) {
			$phpmailer->isSMTP();
			$phpmailer->Host = $host;
			$phpmailer->Port = $port;
			$phpmailer->SMTPAuth = (bool) $auth;
			if ( $user !== '' ) { $phpmailer->Username = $user; }
			if ( $pass !== '' ) { $phpmailer->Password = $pass; }
			if ( $sec !== '' ) { $phpmailer->SMTPSecure = $sec; }
			// FORCE FROM (DMARC/SPF): el From debe ser la cuenta autenticada;
			// el From original se conserva en Reply-To.
			if ( (bool) $auth && $user !== '' ) {
				$from_email = (string) $phpmailer->From;
				if ( $from_email !== '' && strcasecmp( $from_email, $user ) !== 0 ) {
					$original_name = (string) $phpmailer->FromName;
					if ( empty( $phpmailer->getReplyToAddresses() ) ) {
						$phpmailer->addReplyTo( $from_email, $original_name );
					}
					$phpmailer->setFrom( $user, $original_name !== '' ? $original_name : $user );
				}
			}
		};
		add_action( 'phpmailer_init', $cb, 20 );
		$r = \Estandar\Core\Mail::send_raw( $opts );
		remove_action( 'phpmailer_init', $cb, 20 );
		$r['transport'] = 'smtp';
		return $r;
	}

	/**
	 * Register (once) a GLOBAL phpmailer_init hook so that EVERY wp_mail()
	 * in the site — including legacy plugins bypassing Core\Mail — goes
	 * through the Suite's native SMTP configuration. No external SMTP plugin
	 * needed. Never registers when an external SMTP plugin is active (that
	 * plugin owns phpmailer). Idempotent.
	 */
	public static function register_global_mailer(): void
	{
		if ( self::has_external_smtp_plugin() ) {
			return; // plugin SMTP externo manda.
		}
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer_globally' ), 20 );
	}

	/** Global phpmailer_init callback: applies Suite SMTP settings to all mail. */
	public static function configure_phpmailer_globally( $phpmailer ): void
	{
		$host = defined( 'SMTP_HOST' ) ? (string) SMTP_HOST : (string) get_option( 'estandar_suite_core_smtp_host', '' );
		if ( $host === '' ) {
			return; // sin SMTP nativo configurado: no intervenir (wp_mail por defecto).
		}
		$port = defined( 'SMTP_PORT' ) ? (int) SMTP_PORT : (int) get_option( 'estandar_suite_core_smtp_port', 587 );
		$user = defined( 'SMTP_USER' ) ? (string) SMTP_USER : (string) get_option( 'estandar_suite_core_smtp_user', '' );
		$pass = defined( 'SMTP_PASS' ) ? (string) SMTP_PASS : (string) get_option( 'estandar_suite_core_smtp_pass', '' );
		$auth = ( defined( 'SMTP_AUTH' ) ? SMTP_AUTH : get_option( 'estandar_suite_core_smtp_auth', 1 ) );
		$sec  = defined( 'SMTP_SECURE' ) ? (string) SMTP_SECURE : (string) get_option( 'estandar_suite_core_smtp_secure', 'tls' );

		$phpmailer->isSMTP();
		$phpmailer->Host = $host;
		$phpmailer->Port = $port;
		$phpmailer->SMTPAuth = (bool) $auth;
		if ( $user !== '' ) {
			$phpmailer->Username = $user;
		}
		if ( $pass !== '' ) {
			$phpmailer->Password = $pass;
		}
		if ( $sec !== '' ) {
			$phpmailer->SMTPSecure = $sec;
		}

		// FORCE FROM (DMARC/SPF de Google y similares): el From debe ser la
		// cuenta autenticada; el From original se conserva en Reply-To.
		if ( (bool) $auth && $user !== '' ) {
			$from_email = (string) $phpmailer->From;
			if ( $from_email !== '' && strcasecmp( $from_email, $user ) !== 0 ) {
				$original_name = (string) $phpmailer->FromName;
				// Conserva el remitente real como Reply-To (respuestas legítimas).
				if ( empty( $phpmailer->getReplyToAddresses() ) ) {
					$phpmailer->addReplyTo( $from_email, $original_name );
				}
				$phpmailer->setFrom( $user, $original_name !== '' ? $original_name : $user );
			}
		}
	}

	public function health_check(): array
	{
		$configured = $this->is_configured();
		return array(
			'ok'      => $configured,
			'status'  => $configured ? 'ok' : 'not_configured',
			'detail'  => $configured ? 'SMTP ' . ( $this->host() !== '' ? 'host: ' . $this->host() : 'vía plugin externo (phpmailer_init)' ) : 'SMTP no configurado',
			'inherited' => $this->is_inherited(),
		);
	}

	public function test( array $opts ): array
	{
		return $this->send( $opts );
	}
}
