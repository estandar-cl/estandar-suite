<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LeadsPanel — panel de leads para operadores + export (CSV/XLSX/PDF) + API.
 *
 * - Vista de operador (es_leads): fecha, recontactos (iconos 🔥 como el mail),
 *   nombre, teléfono, email, proyecto y enlace directo a calificar.
 * - Acceso por login simple: nombre + PIN de 4 letras (cookie persistente).
 *   Cada vista de un operador queda registrada (auditoría). El admin de WP y
 *   el api_key para terceros siguen funcionando; ?access= queda como enlace
 *   directo opcional (retrocompatible).
 * - URL amigable: /panel-leads/ · exports CSV/XLSX/PDF · API JSON por API key.
 * - Branding: logo de estandar.cl (linkeado → si cambia ahí, cambia en todos
 *   los sitios) + naranja de la Suite (#f95b1f, mismo header del backend).
 */
class LeadsPanel
{
	const ACCESS_OPTION     = 'estandar_suite_core_leads_access';
	const OPERATORS_OPTION  = 'estandar_suite_core_leads_operators';
	const LOG_OPTION        = 'estandar_suite_core_leads_panel_log';
	const PANEL_SLUG        = 'panel-leads';
	const AUTH_COOKIE       = 'estandar_lead_panel_auth';
	const MAGIC_COOKIE      = 'estandar_lead_panel_magic';
	const MAGIC_TOKENS_OPT  = 'estandar_suite_core_leads_magic_tokens';
	const MAGIC_LOG_OPT     = 'estandar_suite_core_leads_magic_log';
	const MAGIC_RATE_OPT    = 'estandar_suite_core_leads_magic_ratelimit';
	const MAGIC_TTL         = 1800;            // validez del enlace: 30 min
	const MAGIC_COOKIE_TTL  = 30 * DAY_IN_SECONDS; // sesión: 30 días deslizante
	const MAGIC_COOLDOWN    = 300;            // anti-bombardeo: 1 enlace / 5 min por correo
	// LOGIN_LOCKOUT_V1 (2026-08-28): brute-force del PIN (4 letras) sin límite.
	const LOGIN_MAX_FAILS   = 5;              // fallos permitidos por ventana
	const LOGIN_WINDOW      = 900;            // ventana de bloqueo: 15 min
	const LOGIN_LOCK_OPT    = 'estandar_suite_core_leads_login_lockout';
	const LOGIN_LOG_OPT     = 'estandar_suite_core_leads_login_log';
	const VIEWS_LOG_OPT     = 'estandar_suite_core_leads_views_log';
	const VIEWS_LOG_CAP     = 2000;
	// GATE_METHODS_V1 (2026-09-21): métodos de acceso que OFRECE el gate de
	// operador embebido (shortcodes estandar_form_gate / estandar_camara_registros).
	// Default = comportamiento histórico: PIN + magic link. Opción ausente o
	// inválida → ambos (retrocompatible: ninguna instalación cambia al actualizar).
	const GATE_METHODS_OPTION = 'estandar_suite_core_leads_gate_methods';
	const GATE_METHODS_DEFAULT = array( 'pin', 'magic' );
	const BRAND_BG          = '#f95b1f';
	const BRAND_LOGO        = 'https://estandar.cl/wp-content/uploads/2024/07/estandar_logo_color.png';

	/** Columnas de la vista unificada (CRM + submissions) para la API. */
	public static function columns(): array
	{
		return array( 'id', 'name', 'email', 'phone', 'project', 'intent', 'source', 'landing_url', 'created_at', 'origin' );
	}

	/** Columnas relevantes para el operador (vista es_leads). */
	public static function operator_columns(): array
	{
		return array( 'id', 'created_at', 'recontacts', 'status', 'name', 'phone', 'email', 'project' );
	}

	// ── Tokens de acceso (API key + enlace directo opcional) ──────────────

	public static function tokens(): array
	{
		$saved = function_exists( 'get_option' ) ? get_option( self::ACCESS_OPTION, array() ) : array();
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'viewer'  => (string) ( $saved['viewer'] ?? '' ),
			'api_key' => (string) ( $saved['api_key'] ?? '' ),
		);
	}

	public static function ensure_tokens(): array
	{
		$t  = self::tokens();
		$ch = false;
		if ( $t['viewer'] === '' ) {
			$t['viewer'] = self::generate( 24 );
			$ch = true;
		}
		if ( $t['api_key'] === '' ) {
			$t['api_key'] = self::generate( 32 );
			$ch = true;
		}
		if ( $ch && function_exists( 'update_option' ) ) {
			update_option( self::ACCESS_OPTION, $t, false );
		}
		return $t;
	}

	public static function regenerate( string $which ): array
	{
		$t = self::tokens();
		if ( $which === 'viewer' ) {
			$t['viewer'] = self::generate( 24 );
		} elseif ( $which === 'api_key' ) {
			$t['api_key'] = self::generate( 32 );
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::ACCESS_OPTION, $t, false );
		}
		return $t;
	}

	private static function generate( int $len ): string
	{
		return bin2hex( function_exists( 'random_bytes' ) ? random_bytes( $len ) : openssl_random_pseudo_bytes( $len ) );
	}

	public static function verify( string $given, string $stored ): bool
	{
		return $given !== '' && $stored !== '' && function_exists( 'hash_equals' ) && hash_equals( $stored, $given );
	}

	// ── Operadores (nombre + PIN de 4 letras) ─────────────────────────────

	/** Mapa de operadores: name => array('pin_hash','created'). */
	public static function operators(): array
	{
		$saved = function_exists( 'get_option' ) ? get_option( self::OPERATORS_OPTION, array() ) : array();
		return is_array( $saved ) ? $saved : array();
	}

	/** PURE: valida un PIN de 4 letras. */
	public static function valid_pin( string $pin ): bool
	{
		return (bool) preg_match( '/^[A-Za-z]{4}$/', $pin );
	}

	/** Alta/actualización de un operador. */
	public static function save_operator( string $name, string $pin ): bool
	{
		$name = trim( $name );
		if ( $name === '' || strlen( $name ) > 40 || ! self::valid_pin( $pin ) ) {
			return false;
		}
		$ops = self::operators();
		$ops[ $name ] = array(
			'pin_hash' => password_hash( strtolower( $pin ), PASSWORD_DEFAULT ),
			'created'  => isset( $ops[ $name ]['created'] ) ? (string) $ops[ $name ]['created'] : \Estandar\Core\Time::now(),
		);
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPERATORS_OPTION, $ops, false );
		}
		return true;
	}

	public static function remove_operator( string $name ): void
	{
		$ops = self::operators();
		if ( isset( $ops[ $name ] ) ) {
			unset( $ops[ $name ] );
			update_option( self::OPERATORS_OPTION, $ops, false );
		}
	}

	// ── Métodos de acceso del gate (GATE_METHODS_V1) ──────────────────────

	/** Métodos CONFIGURADOS para el gate: subconjunto de ['pin','magic']. */
	public static function gate_methods(): array
	{
		$saved = function_exists( 'get_option' ) ? get_option( self::GATE_METHODS_OPTION, null ) : null;
		if ( is_array( $saved ) ) {
			$list = $saved;
		} elseif ( is_string( $saved ) && $saved !== '' ) {
			$list = explode( ',', $saved );
		} else {
			return self::GATE_METHODS_DEFAULT;
		}
		$out = array();
		foreach ( $list as $m ) {
			$m = strtolower( trim( (string) $m ) );
			if ( ( $m === 'pin' || $m === 'magic' ) && ! in_array( $m, $out, true ) ) {
				$out[] = $m;
			}
		}
		return $out ? $out : self::GATE_METHODS_DEFAULT;
	}

	/** Guarda los métodos del gate aceptando 'pin' | 'magic' | 'both'. */
	public static function set_gate_methods( string $mode ): void
	{
		$mode = strtolower( trim( $mode ) );
		if ( $mode === 'pin' || $mode === 'magic' ) {
			$methods = array( $mode );
		} else {
			$methods = self::GATE_METHODS_DEFAULT;
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::GATE_METHODS_OPTION, $methods, false );
		}
	}

	/**
	 * Métodos EFECTIVOS del gate: los configurados depurados contra la config
	 * real, para no dejar el acceso sin salida viable:
	 *  - 'pin' se descarta si no hay operadores con PIN (nadie podría entrar).
	 *  - 'magic' se descarta si el sitio no tiene dominio de correo utilizable.
	 *  - si no queda ninguno, se vuelve al default (ambos): NUNCA rompe el login.
	 */
	public static function effective_gate_methods(): array
	{
		return self::viable_methods( self::gate_methods() );
	}

	/**
	 * Filtra una lista de métodos contra la realidad del sitio y garantiza que
	 * NUNCA quede vacía (si nada es viable, cae al default).
	 */
	private static function viable_methods( array $methods ): array
	{
		$has_ops    = ! empty( self::operators() );
		$has_domain = self::magic_domain() !== '';
		$out        = array();
		foreach ( $methods as $method ) {
			if ( $method === 'pin' && ! $has_ops ) {
				continue;
			}
			if ( $method === 'magic' && ! $has_domain ) {
				continue;
			}
			$out[] = $method;
		}
		if ( $out ) {
			return $out;
		}
		// Nada viable entre los pedidos: ofrecer lo que SÍ sea viable.
		if ( $has_ops ) {
			return array( 'pin' );
		}
		if ( $has_domain ) {
			return array( 'magic' );
		}
		return self::GATE_METHODS_DEFAULT;
	}

	/**
	 * Métodos efectivos aceptando un override por form (GATE_PER_FORM_V1).
	 * $override: null | 'inherit' | 'both' | 'pin' | 'magic' | array('pin','magic').
	 */
	public static function resolve_gate_methods( $override = null ): array
	{
		if ( is_string( $override ) ) {
			$override = strtolower( trim( $override ) );
			if ( $override === '' || $override === 'inherit' ) {
				$override = null;
			} elseif ( $override === 'both' ) {
				$override = array( 'pin', 'magic' );
			} elseif ( $override === 'pin' || $override === 'magic' ) {
				$override = array( $override );
			} else {
				$override = null;
			}
		}
		if ( ! is_array( $override ) || ! $override ) {
			return self::effective_gate_methods();
		}
		$clean = array();
		foreach ( $override as $m ) {
			$m = strtolower( trim( (string) $m ) );
			if ( ( $m === 'pin' || $m === 'magic' ) && ! in_array( $m, $clean, true ) ) {
				$clean[] = $m;
			}
		}
		return $clean ? self::viable_methods( $clean ) : self::effective_gate_methods();
	}

	/** ¿El gate ofrece HOY este método? Con override opcional por form. */
	public static function gate_offers( string $method, $override = null ): bool
	{
		return in_array( $method, self::resolve_gate_methods( $override ), true );
	}

	/** PID de los métodos configurados (para el admin): 'both' | 'pin' | 'magic'. */
	public static function gate_methods_mode(): string
	{
		$m = self::gate_methods();
		$pin   = in_array( 'pin', $m, true );
		$magic = in_array( 'magic', $m, true );
		if ( $pin && ! $magic ) {
			return 'pin';
		}
		if ( $magic && ! $pin ) {
			return 'magic';
		}
		return 'both';
	}

	/** Autentica nombre+PIN → cookie persistente + registro de acceso. */
	public static function authenticate( string $name, string $pin ): bool
	{
		$name = trim( $name );
		$ops  = self::operators();
		if ( ! isset( $ops[ $name ] ) || ! password_verify( strtolower( (string) $pin ), (string) $ops[ $name ]['pin_hash'] ) ) {
			return false;
		}
		$expiry = time() + 30 * DAY_IN_SECONDS;
		$token  = $name . '|' . $expiry . '|' . self::auth_sig( $name, $expiry );
		if ( function_exists( 'setcookie' ) ) {
			setcookie( self::AUTH_COOKIE, $token, array(
				'expires'  => $expiry,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			) );
		}
		self::log_view( $name );
		return true;
	}

	public static function logout(): void
	{
		if ( function_exists( 'setcookie' ) ) {
			setcookie( self::AUTH_COOKIE, '', array(
				'expires'  => time() - 3600,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			) );
		}
	}

	// ── LOGIN_LOCKOUT_V1: anti-brute-force del PIN ──────────────────────────

	/** Clave del registro de fallos para un nombre+IP. */
	private static function login_lock_key( string $name, string $ip ): string
	{
		return md5( strtolower( trim( $name ) ) . '|' . $ip );
	}

	/** Estado de fallos [f, t] para nombre+IP. */
	private static function login_failures( string $name, string $ip ): array
	{
		$all = get_option( self::LOGIN_LOCK_OPT, array() );
		$all = is_array( $all ) ? $all : array();
		$s = $all[ self::login_lock_key( $name, $ip ) ] ?? array( 'f' => 0, 't' => time() );
		return array( 'f' => (int) $s['f'], 't' => (int) $s['t'] );
	}

	/** ¿Está bloqueado el nombre+IP (>= MAX_FAILS en la ventana)? */
	private static function login_locked( string $name, string $ip ): bool
	{
		if ( $name === '' || $ip === '' ) {
			return false;
		}
		$s = self::login_failures( $name, $ip );
		return $s['f'] >= self::LOGIN_MAX_FAILS && ( time() - $s['t'] ) < self::LOGIN_WINDOW;
	}

	/** Registra un fallo de login (y deja registro auditable). */
	private static function register_login_failure( string $name, string $ip ): void
	{
		$all = get_option( self::LOGIN_LOCK_OPT, array() );
		$all = is_array( $all ) ? $all : array();
		$k = self::login_lock_key( $name, $ip );
		$s = $all[ $k ] ?? array( 'f' => 0, 't' => time() );
		$s['f'] = (int) $s['f'] + 1;
		$s['t'] = time();
		$all[ $k ] = $s;
		update_option( self::LOGIN_LOCK_OPT, $all, false );
		self::log_login( 'failed', $name, $ip );
	}

	/** Registro auditable de intentos de login (últimos 200). */
	private static function log_login( string $event, string $name, string $ip ): void
	{
		$log = get_option( self::LOGIN_LOG_OPT, array() );
		$log = is_array( $log ) ? $log : array();
		$log[] = array(
			'event' => sanitize_key( $event ),
			'name'  => sanitize_text_field( $name ),
			'ip'    => sanitize_text_field( $ip ),
			'ts'    => \Estandar\Core\Time::now(),
		);
		$log = array_slice( $log, -200 );
		update_option( self::LOGIN_LOG_OPT, $log, false );
	}

	/** Limpia los fallos tras un login exitoso. */
	private static function clear_login_failures( string $name, string $ip ): void
	{
		$all = get_option( self::LOGIN_LOCK_OPT, array() );
		if ( ! is_array( $all ) ) {
			return;
		}
		unset( $all[ self::login_lock_key( $name, $ip ) ] );
		update_option( self::LOGIN_LOCK_OPT, $all, false );
	}

	/** Operador autenticado por cookie ('' si ninguno). */
	public static function current_operator(): string
	{
		$raw = (string) ( $_COOKIE[ self::AUTH_COOKIE ] ?? '' );
		if ( $raw === '' ) {
			return '';
		}
		$parts = explode( '|', $raw );
		if ( count( $parts ) !== 3 ) {
			return '';
		}
		list( $name, $expiry, $sig ) = $parts;
		$name   = sanitize_text_field( $name );
		$expiry = (int) $expiry;
		if ( $expiry < time() || ! hash_equals( self::auth_sig( $name, $expiry ), (string) $sig ) ) {
			return '';
		}
		// El operador debe seguir existiendo.
		$ops = self::operators();
		return isset( $ops[ $name ] ) ? $name : '';
	}

	private static function auth_sig( string $name, int $expiry ): string
	{
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'leads-panel' );
		return hash_hmac( 'sha256', $name . '|' . $expiry, (string) $secret );
	}

	// ── Acceso por correo (magic link, sin contraseña) ────────────────────

	/** Dominio corporativo del sitio (cliente1.example.com, cliente3.example.com, …). Filtrable. */
	public static function magic_domain(): string
	{
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = strtolower( trim( $host ) );
		$host = (string) preg_replace( '/^www\./', '', $host );
		return (string) apply_filters( 'estandar_suite_leads_panel_magic_domain', $host );
	}

	/** Dominio del email (lo que va después de @), en minúsculas. */
	private static function magic_email_domain( string $email ): string
	{
		$at = strrpos( $email, '@' );
		return $at === false ? '' : strtolower( substr( $email, $at + 1 ) );
	}

	/** PURE: resuelve el correo a partir del prefijo (o correo completo del dominio). */
	public static function magic_resolve_email( string $input ): string
	{
		$input = trim( $input );
		if ( $input === '' ) {
			return '';
		}
		$domain = self::magic_domain();
		if ( strpos( $input, '@' ) !== false ) {
			$email = sanitize_email( $input );
			if ( ! is_email( $email ) ) {
				return '';
			}
			return self::magic_email_domain( $email ) === $domain ? $email : '';
		}
		$email = sanitize_email( $input . '@' . $domain );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Correos reales de operadores conocidos (registry de proyectos): mapas
	 * nombre→correo y parte-local→correo. Solo los del dominio del sitio.
	 * Filtrable para tests/overrides (estandar_suite_leads_panel_known_operators).
	 */
	private static function known_operator_emails(): array
	{
		$known = array_change_key_case( (array) apply_filters( 'estandar_suite_leads_panel_known_operators', array() ), CASE_LOWER );

		global $wpdb;
		$table = $wpdb->prefix . \Estandar\Core\Leads\ProjectRegistry::TABLE_NAME;
		$rows  = $wpdb->get_results(
			"SELECT operator_name, operator_email FROM {$table} WHERE active = 1 AND operator_email <> ''",
			ARRAY_A
		);
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$email = strtolower( trim( (string) ( $row['operator_email'] ?? '' ) ) );
				if ( ! is_email( $email ) ) {
					continue;
				}
				$name = strtolower( trim( (string) ( $row['operator_name'] ?? '' ) ) );
				if ( $name !== '' ) {
					$known[ $name ] = $email;
				}
				$local = strstr( $email, '@', true );
				if ( $local !== false && $local !== '' ) {
					$known[ $local ] = $email;
				}
			}
		}

		$domain = self::magic_domain();
		foreach ( $known as $k => $email ) {
			if ( self::magic_email_domain( (string) $email ) !== $domain ) {
				unset( $known[ $k ] );
			}
		}
		return $known;
	}

	/**
	 * Resuelve el correo destino del magic link. Si la entrada coincide con un
	 * operador conocido del registry (por nombre o parte local), usa su correo
	 * registrado (ej. "valentina" → e.desarrolladora@…); si no, cae al
	 * comportamiento original (<prefijo>@<dominio>).
	 */
	public static function resolve_magic_target( string $input ): string
	{
		$input = trim( $input );
		if ( $input === '' ) {
			return '';
		}
		$known = self::known_operator_emails();
		$key   = strtolower( $input );
		if ( isset( $known[ $key ] ) ) {
			return (string) $known[ $key ];
		}
		return self::magic_resolve_email( $input );
	}

	/** HMAC estable del token (nunca se guarda el token en claro). */
	public static function magic_hash( string $raw ): string
	{
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'leads-panel-magic' );
		return hash_hmac( 'sha256', $raw, (string) $secret );
	}

	private static function magic_tokens(): array
	{
		$t = get_option( self::MAGIC_TOKENS_OPT, array() );
		return is_array( $t ) ? $t : array();
	}

	private static function save_magic_tokens( array $t ): void
	{
		update_option( self::MAGIC_TOKENS_OPT, $t, false );
	}

	/** Descarta tokens vencidos y devuelve la lista viva. */
	private static function prune_magic_tokens(): array
	{
		$t = self::magic_tokens();
		$now = time();
		$changed = false;
		foreach ( $t as $hash => $row ) {
			if ( (int) ( $row['expires'] ?? 0 ) < $now ) {
				unset( $t[ $hash ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::save_magic_tokens( $t );
		}
		return $t;
	}

	/** Auditoría de magic link (request/login). */
	private static function log_magic( string $event, string $email ): void
	{
		$log = get_option( self::MAGIC_LOG_OPT, array() );
		$log = is_array( $log ) ? $log : array();
		$log[] = array(
			'event' => sanitize_key( $event ),
			'email' => sanitize_email( $email ),
			'ip'    => sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'ts'    => \Estandar\Core\Time::now(),
		);
		$log = array_slice( $log, -500 );
		update_option( self::MAGIC_LOG_OPT, $log, false );
	}

	/** Registro reciente de accesos por correo (reversa, primero el más nuevo). */
	public static function magic_log( int $limit = 20 ): array
	{
		$log = get_option( self::MAGIC_LOG_OPT, array() );
		$log = is_array( $log ) ? $log : array();
		return array_slice( array_reverse( $log ), 0, max( 1, min( 500, $limit ) ) );
	}

	/**
	 * Solicita un magic link: valida dominio, cooldown anti-bombardeo por correo,
	 * genera token (solo se guarda el hash) y envía el correo con el enlace.
	 *
	 * @param string $email   Correo del operador.
	 * @param string $return_url Destino tras consumir el enlace (página del form,
	 *                           panel, etc.). Vacío = /panel-leads/ (retrocompatible).
	 * @param array  $context  Overrides opcionales del correo: subject, button, intro.
	 */
	public static function request_magic_link( string $email, string $return_url = '', array $context = array() ): array
	{
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) {
			return array( 'ok' => false, 'message' => 'Correo no válido.' );
		}
		if ( self::magic_email_domain( $email ) !== self::magic_domain() ) {
			return array( 'ok' => false, 'message' => 'Usa un correo de ' . self::magic_domain() . '.' );
		}

		$now = time();
		$key = md5( $email );
		$rl  = get_option( self::MAGIC_RATE_OPT, array() );
		$rl  = is_array( $rl ) ? $rl : array();
		if ( isset( $rl[ $key ] ) && (int) $rl[ $key ] > ( $now - self::MAGIC_COOLDOWN ) ) {
			return array( 'ok' => false, 'message' => 'Ya enviamos un enlace hace poco. Revisa tu correo o espera unos minutos.' );
		}

		$raw  = bin2hex( function_exists( 'random_bytes' ) ? random_bytes( 24 ) : openssl_random_pseudo_bytes( 24 ) );
		$toks = self::prune_magic_tokens();
		$toks[ self::magic_hash( $raw ) ] = array(
			'email'      => $email,
			'expires'    => $now + self::MAGIC_TTL,
			'created'    => gmdate( 'Y-m-d H:i:s' ),
			'return_url' => sanitize_text_field( (string) $return_url ),
		);
		self::save_magic_tokens( $toks );

		$url  = $return_url !== ''
			? esc_url_raw( add_query_arg( 'magic', $raw, $return_url ) )
			: home_url( '/' . self::PANEL_SLUG . '/?magic=' . $raw );
		if ( ! self::mail_magic_link( $email, $url, $context ) ) {
			return array( 'ok' => false, 'message' => 'No se pudo enviar el correo. Inténtalo de nuevo.' );
		}

		$rl[ $key ] = $now;
		update_option( self::MAGIC_RATE_OPT, $rl, false );
		self::log_magic( 'request', $email );
		return array( 'ok' => true, 'message' => 'Revisa tu correo ' . $email . ': te enviamos un enlace de acceso (si no está en Recibidos, revisa también la carpeta No deseado/Spam).' );
	}

	/** Consume un magic token (un solo uso). Devuelve el correo o ''. */
	public static function consume_magic_token( string $raw ): string
	{
		$full = self::consume_magic_token_full( $raw );
		return (string) ( $full['email'] ?? '' );
	}

	/**
	 * Consume un magic token y establece la sesión mágica (cookie). Reutilizable
	 * desde cualquier gate de operadores (panel o forms). @return array
	 * ['email'=>, 'return_url'=>] o vacío si el token es inválido/vencido.
	 */
	public static function establish_magic_session( string $raw ): array
	{
		$full = self::consume_magic_token_full( $raw );
		if ( ! empty( $full['email'] ) ) {
			self::set_magic_cookie( (string) $full['email'] );
		}
		return $full;
	}

	/**
	 * Consume un magic token (un solo uso) devolviendo también su destino.
	 * @return array ['email'=>, 'return_url'=>] o vacío si inválido/vencido.
	 */
	public static function consume_magic_token_full( string $raw ): array
	{
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return array();
		}
		$toks = self::prune_magic_tokens();
		$hash = self::magic_hash( $raw );
		if ( ! isset( $toks[ $hash ] ) ) {
			return array();
		}
		$email = (string) ( $toks[ $hash ]['email'] ?? '' );
		$ret   = (string) ( $toks[ $hash ]['return_url'] ?? '' );
		unset( $toks[ $hash ] );
		self::save_magic_tokens( $toks );
		if ( $email !== '' ) {
			self::log_magic( 'login', $email );
		}
		return array( 'email' => $email, 'return_url' => $ret );
	}

	/** Envía el correo del magic link por el motor v2 (Core\Mail), con la
	 * identidad de la Suite (igual que las notificaciones de leads, para no caer
	 * en No deseado como "wordpress@…"). Acepta contexto opcional (subject/button/
	 * intro) para reutilizarse en el gate de operadores de forms.
	 *
	 * @param string $email   Correo destino.
	 * @param string $url     Enlace de acceso (panel o página del form).
	 * @param array  $context Overrides: subject, button, intro.
	 */
	private static function mail_magic_link( string $email, string $url, array $context = array() ): bool
	{
		if ( ! class_exists( '\Estandar\Core\Mail' ) ) {
			return false;
		}
		$site       = get_bloginfo( 'name' );
		$label      = (string) ( $context['label'] ?? 'Panel de Leads' );
		$subject    = (string) ( $context['subject'] ?? 'Acceso al ' . $label . ' — ' . $site );
		$button     = (string) ( $context['button'] ?? 'Abrir ' . $label );
		$intro      = (string) ( $context['intro'] ?? 'Para abrir el <strong>' . esc_html( $label ) . '</strong> pulsa el botón:' );
		$identity   = \Estandar\Core\Mail::identity();
		$admin      = (string) get_option( 'admin_email', '' );
		$from_name  = (string) ( $identity['sender_name'] !== '' ? $identity['sender_name'] : $identity['site_name'] );
		$from_email = (string) ( $identity['sender_email'] !== '' ? $identity['sender_email'] : $admin );
		$html       = '<p>Hola,</p>'
			. '<p>' . $intro . '</p>'
			. '<p style="margin:18px 0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#f95b1f;color:#fff;padding:11px 20px;border-radius:6px;text-decoration:none;font-weight:700;">' . esc_html( $button ) . '</a></p>'
			. '<p style="color:#646970;font-size:13px;">El enlace es de un solo uso y caduca en 30 minutos. Si no pediste este acceso, ignora este correo.</p>'
			. '<p style="color:#646970;font-size:13px;">— Estandar Suite</p>';
		$r = \Estandar\Core\Mail::send( array(
			'to'         => array( $email ),
			'subject'    => $subject,
			'html'       => $html,
			'from_name'  => $from_name,
			'from_email' => $from_email,
		) );
		return (bool) ( $r['ok'] ?? false );
	}

	/** Sesión mágica vigente (cookie firmada) con renovación deslizante. */
	public static function magic_session_email(): string
	{
		$raw = (string) ( $_COOKIE[ self::MAGIC_COOKIE ] ?? '' );
		if ( $raw === '' ) {
			return '';
		}
		$parts = explode( '|', $raw );
		if ( count( $parts ) !== 3 ) {
			return '';
		}
		list( $email, $expiry, $sig ) = $parts;
		$email  = sanitize_email( $email );
		$expiry = (int) $expiry;
		if ( $expiry < time() || ! hash_equals( self::magic_sig( $email, $expiry ), (string) $sig ) ) {
			return '';
		}
		self::set_magic_cookie( $email );
		return $email;
	}

	private static function magic_sig( string $email, int $expiry ): string
	{
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'leads-panel-magic' );
		return hash_hmac( 'sha256', $email . '|' . $expiry, (string) $secret );
	}

	private static function set_magic_cookie( string $email ): void
	{
		$expiry = time() + self::MAGIC_COOKIE_TTL;
		$token  = $email . '|' . $expiry . '|' . self::magic_sig( $email, $expiry );
		if ( function_exists( 'setcookie' ) ) {
			setcookie( self::MAGIC_COOKIE, $token, $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
	}

	public static function magic_logout(): void
	{
		if ( function_exists( 'setcookie' ) ) {
			setcookie( self::MAGIC_COOKIE, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
	}

	/** Etiqueta del usuario actual (operador por PIN o prefijo del correo mágico). */
	public static function current_user_label(): string
	{
		$op = self::current_operator();
		if ( $op !== '' ) {
			return $op;
		}
		$email = self::magic_session_email();
		if ( $email !== '' ) {
			$p = explode( '@', $email, 2 );
			return $p[0];
		}
		return '';
	}

	// ── Auditoría de acceso ───────────────────────────────────────────────

	/** Registra una vista de panel por operador (cap 1000, recientes). */
	public static function log_view( string $operator ): void
	{
		if ( $operator === '' ) {
			return;
		}
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array( 'operator' => $operator, 'ts' => \Estandar\Core\Time::now() );
		$log = array_slice( $log, -1000 );
		update_option( self::LOG_OPTION, $log, false );
	}

	/** Registro reciente (reversa, primero el más nuevo). */
	public static function view_log( int $limit = 30 ): array
	{
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		return array_slice( array_reverse( $log ), 0, max( 1, min( 500, $limit ) ) );
	}

	// ── Auditoría de actividad: quién abrió la lista / un lead (identificado) ──

	/**
	 * Identidad del usuario actual para auditoría: operador PIN, prefijo del
	 * correo mágico o el login del usuario de WP (admins). Vacío si es solo
	 * acceso por token compartido (?access=) o API key (sin identidad humana).
	 */
	public static function activity_identity(): string
	{
		$op = self::current_operator();
		if ( $op !== '' ) {
			return $op;
		}
		$email = self::magic_session_email();
		if ( $email !== '' ) {
			$p = explode( '@', $email, 2 );
			return $p[0] . ' <' . $email . '>';
		}
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$u = wp_get_current_user();
			if ( $u && ! empty( $u->user_login ) ) {
				return (string) $u->user_login;
			}
		}
		return '';
	}

	/**
	 * ACTIVITY_LOG_V1 — registra una acción del operador en el panel: 'list'
	 * (abrió la lista de leads) o 'lead' (abrió la ficha de un lead). Solo se
	 * registra cuando hay identidad humana; el acceso por token compartido no
	 * deja rastro de persona. Aditivo: no reemplaza magic_log/view_log.
	 */
	public static function log_activity( string $kind, int $lead_id = 0 ): void
	{
		$kind = $kind === 'lead' ? 'lead' : 'list';
		$who  = self::activity_identity();
		if ( $who === '' ) {
			return;
		}
		$log = get_option( self::VIEWS_LOG_OPT, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'kind'    => $kind,
			'who'     => $who,
			'lead_id' => $kind === 'lead' ? absint( $lead_id ) : 0,
			'ip'      => sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'ts'      => \Estandar\Core\Time::now(),
		);
		$log = array_slice( $log, -self::VIEWS_LOG_CAP );
		update_option( self::VIEWS_LOG_OPT, $log, false );
	}

	/** Registro reciente de actividad (reversa, primero el más nuevo). */
	public static function activity_log( int $limit = 100 ): array
	{
		$log = get_option( self::VIEWS_LOG_OPT, array() );
		$log = is_array( $log ) ? $log : array();
		return array_slice( array_reverse( $log ), 0, max( 1, min( self::VIEWS_LOG_CAP, $limit ) ) );
	}

	/**
	 * Refleja la revisión de un lead en el CRM (evento `lead_viewed`) para que el
	 * pipeline de la ficha marque "Operador revisó el lead" como hecho. Usa el
	 * mismo camino que log_contact_event: puente de estandar-leads si existe, o
	 * insert directo en es_lead_events. No-ops si no hay tabla o no hay identidad.
	 */
	public static function log_lead_viewed( int $lead_id ): void
	{
		$lead_id = absint( $lead_id );
		if ( $lead_id <= 0 ) {
			return;
		}
		$who  = self::current_operator();
		$email = self::magic_session_email();
		if ( $who === '' && $email === '' && ! ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) ) {
			return;
		}
		$operator_id = self::current_operator_id();
		if ( class_exists( '\Estandar_Leads_Portal' ) ) {
			\Estandar_Leads_Portal::log_portal_event( $lead_id, 'lead_viewed', array(
				'workspace'     => 'panel-leads',
				'submission_id' => 0,
			), $operator_id );
			return;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$table = $wpdb->prefix . 'es_lead_events';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$wpdb->insert( $table, array(
			'lead_id'     => $lead_id,
			'operator_id' => $operator_id ?: null,
			'event_type'  => 'lead_viewed',
			'metadata'    => wp_json_encode( array( 'source' => 'panel-leads', 'workspace' => 'panel-leads' ) ),
			'ip_address'  => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'user_agent'  => sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			'created_at'  => \Estandar\Core\Time::now(),
		) );
	}

	// ── Autorización ──────────────────────────────────────────────────────

	/**
	 * ¿Autorizado? (admin WP | operador logueado por cookie | ?access= | API key).
	 */
	public static function authorized(): bool
	{
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( self::current_operator() !== '' ) {
			return true;
		}
		if ( self::magic_session_email() !== '' ) {
			return true;
		}
		$t = self::tokens();
		$access = sanitize_text_field( (string) ( $_GET['access'] ?? $_GET['pass'] ?? '' ) );
		if ( self::verify( $access, $t['viewer'] ) ) {
			return true;
		}
		return self::verify( self::bearer_key(), $t['api_key'] );
	}

	private static function bearer_key(): string
	{
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $k => $v ) {
				if ( strtolower( (string) $k ) === 'authorization' && preg_match( '/Bearer\s+(\S+)/i', (string) $v, $m ) ) {
					return $m[1];
				}
			}
		}
		return sanitize_text_field( (string) ( $_GET['api_key'] ?? '' ) );
	}

	// ── CRM del operador: crear / editar leads (CRM_LEAD_EDIT_V1) ──────────

	/** Estados de negocio editables por el operador (mini-CRM). */
	const LEAD_STATUSES = array( 'NEW', 'CONTACTED', 'QUALIFIED', 'PROPOSAL_SENT', 'NEGOTIATION', 'CLIENT', 'LOST', 'INVALID' );

	/** Crea un lead desde el panel (dedupe por email/tel). Reutiliza el motor
	 *  de ingesta (CrmIngestion) para normalización y eventos. */
	public static function operator_create_lead( array $input ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array( 'ok' => false, 'error' => 'sin_db' );
		}
		$name    = trim( (string) ( $input['name'] ?? '' ) );
		$email   = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$phone   = trim( (string) ( $input['phone'] ?? '' ) );
		$project = sanitize_key( (string) ( $input['project_slug'] ?? '' ) );
		if ( $name === '' || ( $email === '' && $phone === '' ) ) {
			return array( 'ok' => false, 'error' => 'datos_incompletos' );
		}

		$payload = array(
			'source_plugin' => 'estandar-core-panel',
			'contact'       => array( 'name' => $name, 'email' => $email, 'phone' => $phone ),
			'context'       => array( 'project_slug' => $project, 'project_area' => '' ),
			'meta'          => array(),
			'attribution'   => array(),
			'request'       => array(),
		);

		$id = \Estandar\Core\Leads\CrmIngestion::identity( $payload );
		$existing = \Estandar\Core\Leads\CrmIngestion::find_lead_id( $id['email_hash'], $id['phone_hash'], $id['legacy_phone_hash'] );
		if ( $existing > 0 ) {
			return array( 'ok' => true, 'lead_id' => (int) $existing, 'action' => 'existing' );
		}

		$projection = \Estandar\Core\Leads\CrmIngestion::project_lead( $payload );
		$ok = $wpdb->insert( $wpdb->prefix . 'es_leads', $projection );
		$lead_id = $ok ? absint( $wpdb->insert_id ) : 0;
		if ( ! $lead_id ) {
			return array( 'ok' => false, 'error' => 'insert_fallido' );
		}
		\Estandar\Core\Leads\CrmIngestion::insert_event( $lead_id, 'lead_created', array(
			'metadata' => array(
				'source'     => 'panel-leads',
				'created_by' => self::current_operator(),
			),
		) );
		return array( 'ok' => true, 'lead_id' => $lead_id, 'action' => 'created' );
	}

	/** Edita campos del lead (nombre/email/tel/proyecto/estado) + nota. */
	public static function operator_edit_lead( int $lead_id, array $input ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $lead_id <= 0 ) {
			return array( 'ok' => false, 'error' => 'lead_invalido' );
		}
		$table = $wpdb->prefix . 'es_leads';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $lead_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array( 'ok' => false, 'error' => 'no_encontrado' );
		}

		$name    = trim( (string) ( $input['name'] ?? '' ) );
		$email   = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$phone   = trim( (string) ( $input['phone'] ?? '' ) );
		$project = sanitize_key( (string) ( $input['project_slug'] ?? '' ) );
		$status  = strtoupper( sanitize_key( (string) ( $input['status'] ?? '' ) ) );
		if ( ! in_array( $status, self::LEAD_STATUSES, true ) ) {
			$status = (string) ( $row['status'] ?: 'NEW' );
		}

		$update = array( 'updated_at' => \Estandar\Core\Time::now() );
		if ( $name !== '' ) {
			$update['name'] = $name;
		}
		if ( $email !== '' ) {
			$update['email']             = $email;
			$update['email_normalized']  = strtolower( $email );
			$update['email_hash']        = \Estandar\Core\Leads\CrmIngestion::hash( strtolower( $email ) );
		}
		if ( $phone !== '' ) {
			$phone_n = \Estandar\Core\Leads\CrmIngestion::normalize_phone_storage( $phone );
			$update['phone']             = $phone;
			$update['phone_normalized']  = $phone_n;
			$update['phone_hash']        = \Estandar\Core\Leads\CrmIngestion::hash( $phone_n );
		}
		if ( $project !== '' ) {
			$update['project_slug'] = $project;
		}
		$update['status'] = $status;

		$ok = $wpdb->update( $table, $update, array( 'id' => $lead_id ) );
		if ( $ok === false ) {
			return array( 'ok' => false, 'error' => 'update_fallido' );
		}

		$metadata = array(
			'source'       => 'panel-leads',
			'edited_by'    => self::current_operator(),
			'from_status'  => (string) ( $row['status'] ?: 'NEW' ),
			'to_status'    => $status,
		);
		if ( isset( $input['note'] ) && trim( (string) $input['note'] ) !== '' ) {
			$metadata['note'] = trim( (string) $input['note'] );
		}
		\Estandar\Core\Leads\CrmIngestion::insert_event( $lead_id, 'operator_edited', array( 'metadata' => $metadata ) );
		return array( 'ok' => true, 'lead_id' => $lead_id );
	}

	/** Añade una nota del operador (evento append-only en el timeline). */
	public static function operator_add_note( int $lead_id, string $note ): bool
	{
		$note = trim( $note );
		if ( $lead_id <= 0 || $note === '' ) {
			return false;
		}
		$inserted = \Estandar\Core\Leads\CrmIngestion::insert_event( $lead_id, 'operator_note', array(
			'reason'   => $note,
			'metadata' => array(
				'source'    => 'panel-leads',
				'note_by'   => self::current_operator(),
			),
		) );
		return $inserted > 0;
	}

	/** <option> de proyectos activos del registry para el editor. */
	public static function lead_project_options( string $selected = '' ): string
	{
		$out = '<option value="">— Sin proyecto —</option>';
		if ( class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ) {
			foreach ( (array) \Estandar\Core\Leads\ProjectRegistry::registry() as $p ) {
				$slug = (string) ( $p['project_id'] ?? '' );
				$label = (string) ( $p['label'] ?? $slug );
				$out .= '<option value="' . esc_attr( $slug ) . '"'
					. ( $selected === $slug ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
			}
		}
		return $out;
	}

	/** Render del editor de cotización (QUOTE_V2) para un lead. */
	public static function render_quote_editor( int $lead_id, string $notice = '', string $notice_type = 'info' ): string
	{
		global $wpdb;
		$lead = (array) $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, email, phone FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1", $lead_id
		), ARRAY_A );
		if ( ! $lead ) {
			return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Lead no encontrado</title></head>'
				. '<body style="font-family:sans-serif;padding:40px;"><a href="' . esc_url( home_url( '/' . self::PANEL_SLUG . '/' ) ) . '">← Volver</a><h2>Lead no encontrado</h2></body></html>';
		}

		$quotes = class_exists( '\Estandar\Flow\Quotes' ) ? \Estandar\Flow\Quotes::for_lead( $lead_id ) : array();

		$notice_html = '';
		if ( $notice !== '' ) {
			$bg = $notice_type === 'ok' ? '#e7f6ec' : ( $notice_type === 'error' ? '#fcf0f1' : '#f0f6fc' );
			$color = $notice_type === 'ok' ? '#01651f' : ( $notice_type === 'error' ? '#b32d2e' : '#2271b1' );
			$notice_html = '<div class="notice" style="background:' . $bg . ';color:' . $color . ';border-radius:6px;padding:9px 11px;font-size:13px;margin-bottom:10px;">' . esc_html( $notice ) . '</div>';
		}

		$action = esc_url( home_url( '/' . self::PANEL_SLUG . '/' ) );
		$nonce  = wp_create_nonce( 'lp_quote_save' );
		$back   = esc_url( home_url( '/' . self::PANEL_SLUG . '/?lead=' . $lead_id ) );

		// M6: editor WYSIWYG tipo SII (solo si Flow trae Editor + DocumentBuilder).
		// Sin Flow activo se conserva EXACTAMENTE el editor actual (fallback).
		if ( class_exists( '\Estandar\Flow\Quotes\Editor' ) && class_exists( '\Estandar\Flow\Quotes\DocumentBuilder' ) ) {
			$quote_id = (int) ( $_GET['quote_id'] ?? 0 );
			$doc      = \Estandar\Flow\Quotes\DocumentBuilder::for_quote( $quote_id, $lead );
			$saved    = \Estandar\Flow\Quotes\Editor::load_saved( $quote_id );
			if ( ! empty( $saved ) ) {
				$doc = \Estandar\Flow\Quotes\Editor::merge_saved( $doc, $saved );
			}
			return \Estandar\Flow\Quotes\Editor::page( $lead_id, $quote_id, $notice, $notice_type, $action, $nonce, $doc, array(
				'quotes' => $quotes,
				'back'   => $back,
				'lead'   => $lead,
			) );
		}

		$quotes_html = '';
		if ( $quotes ) {
			$quotes_html = '<div class="card"><h3>Cotizaciones de este lead</h3>';
			foreach ( $quotes as $q ) {
				$total = class_exists( '\Estandar\Flow\Quotes' )
					? \Estandar\Flow\Quotes::format_amount( \Estandar\Flow\Quotes::total( $q ), (string) ( $q['currency'] ?? 'CLP' ) )
					: '';
				$quotes_html .= '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;border-bottom:1px dashed #f0f0f1;padding:8px 0;">'
					. '<div><strong>' . esc_html( (string) ( $q['title'] ?: ( 'COT-' . (int) $q['id'] ) ) ) . '</strong><br>'
					. '<span class="muted">' . esc_html( $total ) . ' · ' . esc_html( (string) ( $q['status'] ) )
					. ( ! empty( $q['sent_at'] ) ? ' · enviada ' . esc_html( (string) $q['sent_at'] ) : '' ) . '</span></div>'
					. '<form method="post" action="' . $action . '"><input type="hidden" name="lp_action" value="quote_send">'
					. '<input type="hidden" name="quote_id" value="' . (int) $q['id'] . '">'
					. '<input type="hidden" name="lead_id" value="' . $lead_id . '">'
					. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
					. '<button class="btn sm" type="submit">' . ( (string) ( $q['status'] ) === 'sent' ? 'Reenviar' : 'Enviar' ) . '</button></form></div>';
			}
			$quotes_html .= '</div>';
		}

		$form = '<form method="post" action="' . $action . '">'
			. '<input type="hidden" name="lp_action" value="quote_save">'
			. '<input type="hidden" name="lead_id" value="' . $lead_id . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
			. '<label>Cliente <input type="text" value="' . esc_attr( (string) ( $lead['name'] ?? '' ) ) . '" disabled></label>'
			. '<label>Email del cliente <input type="email" name="to" value="' . esc_attr( (string) ( $lead['email'] ?? '' ) ) . '" required></label>'
			. '<label>Título <input type="text" name="title" value="Cotización de ' . esc_attr( (string) ( $lead['name'] ?? '' ) ) . '"></label>'
			. '<label>Ítems (una por línea: <code>descripción|monto</code>) <textarea name="items" rows="4" placeholder="Terreno 5.000 m²|120000000&#10;Documentación|50000"></textarea></label>'
			. '<label>Moneda <select name="currency"><option value="CLP">CLP</option><option value="UF">UF</option></select></label>'
			. '<label>Validez (días) <input type="number" name="validity_days" value="15" min="1" max="365"></label>'
			. '<label>Notas <textarea name="notes" rows="2" placeholder="Condiciones, forma de pago…"></textarea></label>'
			. '<button class="btn" type="submit">Crear cotización</button>'
			. '</form>';

		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Cotizar lead #' . $lead_id . '</title>'
			. '<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f6f7f7;color:#1d2327;padding:16px;}
.wrap{max-width:640px;margin:0 auto;}
.card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;margin-bottom:14px;}
.card h3{margin:0 0 10px;font-size:14px;}
label{display:block;font-size:12px;color:#646970;margin:12px 0 4px;}
input,select,textarea{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #dcdcde;border-radius:6px;font-size:15px;}
textarea{min-height:64px;padding:10px 12px;}
.btn{width:100%;margin-top:14px;background:#f95b1f;color:#fff;border:none;padding:11px;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;}
.btn.sm{width:auto;padding:6px 14px;font-size:13px;margin:0;background:#2271b1;}
.btn.sub{background:#fff;color:#2271b1;border:1px solid #c5d9ed;margin-top:8px;}
.muted{color:#8c8f94;font-size:12px;}
.notice{border-radius:6px;padding:9px 11px;font-size:13px;margin-bottom:10px;}</style></head><body>'
			. '<div class="wrap">' . self::brand_top( 'Cotización — ' . (string) ( $lead['name'] ?? 'lead #' . $lead_id ) )
			. $notice_html . $quotes_html
			. '<div class="card"><h3>Nueva cotización</h3>' . $form
			. '<a class="btn sub" href="' . $back . '">← Volver al lead</a>'
			. '</div>'
			. self::brand_footer()
			. '</div></body></html>';
	}

	/** Render del editor de lead (crear o editar) para el operador. */
	public static function render_lead_editor( int $lead_id = 0, string $notice = '', string $notice_type = 'info' ): string
	{
		$lead = array();
		if ( $lead_id > 0 ) {
			global $wpdb;
			$lead = (array) $wpdb->get_row( $wpdb->prepare(
				"SELECT id, name, email, phone, project_slug, status FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
				$lead_id
			), ARRAY_A );
		}
		$title  = $lead_id > 0 ? 'Editar lead #' . (int) $lead_id : 'Nuevo lead';
		$name   = esc_attr( (string) ( $lead['name'] ?? '' ) );
		$email  = esc_attr( (string) ( $lead['email'] ?? '' ) );
		$phone  = esc_attr( (string) ( $lead['phone'] ?? '' ) );
		$status = (string) ( $lead['status'] ?? 'NEW' );
		$opts   = self::lead_project_options( (string) ( $lead['project_slug'] ?? '' ) );

		$status_opts = '';
		foreach ( self::LEAD_STATUSES as $s ) {
			$status_opts .= '<option value="' . esc_attr( $s ) . '"' . ( $status === $s ? ' selected' : '' ) . '>' . esc_html( self::lead_status_label( $s ) ) . '</option>';
		}

		$notice_html = '';
		if ( $notice !== '' ) {
			$bg = $notice_type === 'ok' ? '#e7f6ec' : ( $notice_type === 'error' ? '#fcf0f1' : '#f0f6fc' );
			$color = $notice_type === 'ok' ? '#01651f' : ( $notice_type === 'error' ? '#b32d2e' : '#2271b1' );
			$notice_html = '<div class="notice" style="background:' . $bg . ';color:' . $color . ';border-radius:6px;padding:9px 11px;font-size:13px;margin-bottom:10px;">' . esc_html( $notice ) . '</div>';
		}

		$action = esc_url( home_url( '/' . self::PANEL_SLUG . '/' ) );
		$nonce  = wp_create_nonce( 'lp_lead_save' );
		$back   = esc_url( home_url( '/' . self::PANEL_SLUG . '/' ) . ( $lead_id > 0 ? '?lead=' . (int) $lead_id : '' ) );

		$form = '<form method="post" action="' . $action . '">'
			. '<input type="hidden" name="lp_action" value="lead_save">'
			. '<input type="hidden" name="lead_id" value="' . (int) $lead_id . '">'
			. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
			. '<label>Nombre <input type="text" name="name" value="' . $name . '" required></label>'
			. '<label>Email <input type="email" name="email" value="' . $email . '"' . ( $lead_id > 0 ? '' : ' required' ) . '></label>'
			. '<label>Teléfono <input type="tel" name="phone" value="' . $phone . '"' . ( $lead_id > 0 ? '' : ' required' ) . '></label>'
			. '<label>Proyecto <select name="project_slug">' . $opts . '</select></label>'
			. '<label>Estado <select name="status">' . $status_opts . '</select></label>'
			. '<label>Nota (opcional) <textarea name="note" rows="2" placeholder="Observación que quedará en el timeline…"></textarea></label>'
			. '<button class="btn" type="submit">' . ( $lead_id > 0 ? 'Guardar cambios' : 'Crear lead' ) . '</button>'
			. '</form>';

		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $title ) . '</title>'
			. '<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f6f7f7;color:#1d2327;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:16px;}
.wrap{width:100%;max-width:520px;}
.card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:24px;box-shadow:0 4px 16px rgba(0,0,0,.08);margin-bottom:14px;}
label{display:block;font-size:12px;color:#646970;margin:12px 0 4px;}
input,select,textarea{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #dcdcde;border-radius:6px;font-size:15px;}
textarea{min-height:64px;padding:10px 12px;}
.btn{width:100%;margin-top:16px;background:#f95b1f;color:#fff;border:none;padding:11px;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;}
.btn.sub{background:#fff;color:#2271b1;border:1px solid #c5d9ed;margin-top:8px;}
.notice{border-radius:6px;padding:9px 11px;font-size:13px;margin-bottom:10px;}</style></head><body>'
			. '<div class="wrap">' . self::brand_top( $title )
			. '<div class="card">' . $notice_html . $form
			. '<a class="btn sub" href="' . $back . '">← Volver</a>'
			. '</div>'
			. self::brand_footer()
			. '</div></body></html>';
	}

	public static function request_filters( $request ): array
	{
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			return array(
				'from'     => sanitize_text_field( (string) $request->get_param( 'from' ) ),
				'to'       => sanitize_text_field( (string) $request->get_param( 'to' ) ),
				'project'  => sanitize_text_field( (string) $request->get_param( 'project' ) ),
				'operator' => sanitize_text_field( (string) $request->get_param( 'operator' ) ),
				'name'     => sanitize_text_field( (string) $request->get_param( 'name' ) ),
				'orderby'  => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'order'    => strtolower( (string) $request->get_param( 'order' ) ) === 'asc' ? 'asc' : 'desc',
			);
		}
		return array();
	}

	// ── Vista de operador (es_leads) ──────────────────────────────────────

	/** Página de es_leads filtrada en SQL (sin límite histórico). */
	public static function operator_leads( array $filters = array(), int $limit = 50, int $offset = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}

		// Orden por columna (whitelist). Default: last_submitted_at DESC (última
		// actividad primero) — así un recontacto reciente (lead viejo + envío
		// nuevo) aparece arriba, no solo los leads recién creados.
		$orderby = sanitize_key( (string) ( $filters['orderby'] ?? 'last_submitted_at' ) );
		$order   = strtolower( (string) ( $filters['order'] ?? 'desc' ) ) === 'asc' ? 'ASC' : 'DESC';
		$order_map = array(
			'id'                => 'id',
			'created_at'        => 'created_at',
			'last_submitted_at' => 'last_submitted_at',
			'name'              => 'name',
			'email'             => 'email',
			'phone'             => 'phone',
			'project'           => 'project_slug',
			'recontacts'        => 'recontacts',
			'status'            => 'status',
		);
		$order_col = $order_map[ $orderby ] ?? 'last_submitted_at';

		$args  = array();
		$where = self::operator_where( $filters, $args );
		$limit  = max( 1, min( 10000, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$select = "SELECT l.id, l.created_at, l.contact_attempts, l.first_contact_at, l.feedback_completed,
		                   l.last_classified_at, l.status, l.name, l.email, l.phone, l.project_slug, l.assigned_operator_name,
		                   (SELECT MAX(s.submitted_at) FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id) AS last_submitted_at
		            FROM {$wpdb->prefix}es_leads l WHERE {$where}";

		if ( in_array( $order_col, array( 'recontacts', 'status' ), true ) ) {
			// Columnas computadas → orden en PHP (no existen como columna SQL).
			$sql = $args ? $wpdb->prepare( $select, $args ) : $select;
			$rows = (array) $wpdb->get_results( $sql, ARRAY_A );
			$recontacts = self::recontact_counts( array_map( static function ( $r ) { return (int) $r['id']; }, $rows ) );
			$build = array();
			foreach ( $rows as $r ) {
				$build[] = self::build_operator_row( $r, $recontacts );
			}
			$sort_key = static function ( array $r ) use ( $order_col ) {
				if ( $order_col === 'recontacts' ) {
					return (int) $r['recontacts'];
				}
				$prio = array( 'Nunca contactado' => 0, 'Contactado' => 1, 'Calificado' => 2 );
				return $prio[ (string) $r['status'] ] ?? 3;
			};
			usort( $build, static function ( $a, $b ) use ( $sort_key, $order ) {
				$ka = $sort_key( $a );
				$kb = $sort_key( $b );
				if ( $ka === $kb ) {
					return $b['id'] <=> $a['id'];
				}
				return $order === 'ASC' ? ( $ka <=> $kb ) : ( $kb <=> $ka );
			} );
			return array_slice( $build, $offset, $limit );
		}

		$args[] = $limit;
		$args[] = $offset;
		$sql = $select . " ORDER BY {$order_col} {$order}, id DESC LIMIT %d OFFSET %d";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$recontacts = self::recontact_counts( array_map( static function ( $r ) { return (int) $r['id']; }, $rows ) );

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = self::build_operator_row( $r, $recontacts );
		}
		return $out;
	}

	/** Fila de operador a partir de una fila SQL + mapa de recontactos. */
	private static function build_operator_row( array $r, array $recontacts ): array
	{
		$lid = (int) $r['id'];
		return array(
			'id'          => $lid,
			'created_at'  => self::display_date( (string) ( $r['created_at'] ?? '' ) ),
			'last_submitted_at' => self::display_date( (string) ( $r['last_submitted_at'] ?? $r['created_at'] ?? '' ) ),
			'recontacts'  => (int) ( $recontacts[ $lid ] ?? 0 ),
			'status'      => self::status_label(
				(int) ( $r['feedback_completed'] ?? 0 ),
				(string) ( $r['last_classified_at'] ?? '' ),
				(string) ( $r['first_contact_at'] ?? '' ),
				(int) ( $r['contact_attempts'] ?? 0 )
			),
			'name'        => (string) $r['name'],
			'phone'       => (string) $r['phone'],
			'email'       => (string) $r['email'],
			'project'     => (string) $r['project_slug'],
			'operator'    => (string) $r['assigned_operator_name'],
			'qualify_url' => home_url( '/' . self::PANEL_SLUG . '/?lead=' . $lid ),
		);
	}

	/**
	 * RECONTACT_COUNT_V1 — veces que un lead "volvió a comunicarse" (nueva
	 * submission separada > ventana de sesión del mismo lead, default 3h).
	 *
	 * Misma semántica que el 🔥 del mail de recontacto del notifier
	 * (`Estandar_Leads_Notifier::get_commercial_recontact_for_submission`):
	 * una submission dentro de la ventana cuenta como misma sesión y no suma.
	 *
	 * @param int[] $lead_ids
	 * @return array lead_id => count
	 */
	public static function recontact_counts( array $lead_ids ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$ids = array_values( array_filter( array_map( 'absint', $lead_ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'es_lead_submissions';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT lead_id, submitted_at FROM {$table}
				 WHERE lead_id IN ({$in})
				 ORDER BY lead_id ASC, submitted_at ASC, id ASC",
				$ids
			),
			ARRAY_A
		);
		$window = (int) apply_filters( 'estandar_leads_recontact_session_window_seconds', 3 * HOUR_IN_SECONDS );
		$groups = array();
		foreach ( $rows as $r ) {
			$groups[ (int) $r['lead_id'] ][] = strtotime( (string) $r['submitted_at'] );
		}
		$out = array();
		foreach ( $groups as $lid => $times ) {
			$count = 0;
			$n     = count( $times );
			for ( $i = 1; $i < $n; $i++ ) {
				if ( ( $times[ $i ] - $times[ $i - 1 ] ) > $window ) {
					$count++;
				}
			}
			$out[ $lid ] = $count;
		}
		return $out;
	}

	/** Estado comercial del lead para el panel: Calificado / Contactado / Nunca contactado. */
	public static function status_label( int $feedback_completed, string $last_classified_at, string $first_contact_at, int $contact_attempts ): string
	{
		if ( $feedback_completed === 1 || $last_classified_at !== '' ) {
			return 'Calificado';
		}
		if ( $first_contact_at !== '' || $contact_attempts > 0 ) {
			return 'Contactado';
		}
		return 'Nunca contactado';
	}

	/** Total de leads que cumplen los filtros (para paginación). */
	public static function count_operator_leads( array $filters = array() ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$args  = array();
		$where = self::operator_where( $filters, $args );
		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}es_leads WHERE {$where}";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/** PURE-ish: WHERE para es_leads con los filtros del operador (rellena $args). */
	private static function operator_where( array $filters, array &$args ): string
	{
		global $wpdb;
		$where = array( '1=1' );
		$from  = (string) ( $filters['from'] ?? '' );
		$to    = (string) ( $filters['to'] ?? '' );
		$proj  = (string) ( $filters['project'] ?? '' );
		$oper  = (string) ( $filters['operator'] ?? '' );
		$name  = (string) ( $filters['name'] ?? '' );
		if ( $from !== '' ) {
			$where[] = 'created_at >= %s';
			$args[]  = $from . ' 00:00:00';
		}
		if ( $to !== '' ) {
			$where[] = 'created_at <= %s';
			$args[]  = $to . ' 23:59:59';
		}
		if ( $proj === '__unrouted__' ) {
			// "Sin proyecto" (diagnóstico/limpieza): solo leads sin routing.
			$where[] = "(project_slug IS NULL OR project_slug = '')";
		} else {
			// HIDE_UNROUTED_V1 (2026-08-25): los leads sin routing hacia un
			// proyecto no aparecen en el panel (ruido/pruebas). Escapatoria:
			// filtro "Sin proyecto" o $filters['show_unrouted'].
			if ( (bool) apply_filters( 'estandar_suite_core_leads_hide_unrouted', true ) && empty( $filters['show_unrouted'] ) ) {
				$where[] = "(project_slug IS NOT NULL AND project_slug != '')";
			}
			if ( $proj !== '' ) {
				$where[] = 'project_slug LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( $proj ) . '%';
			}
		}
		if ( $oper !== '' ) {
			$where[] = 'assigned_operator_name LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $oper ) . '%';
		}
		if ( $name !== '' ) {
			$where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			$l = '%' . $wpdb->esc_like( $name ) . '%';
			$args[] = $l;
			$args[] = $l;
			$args[] = $l;
		}
		return implode( ' AND ', $where );
	}

	/** Fecha legible DD-MM-YYYY (los created_at se guardan en UTC). */
	public static function display_date( string $datetime ): string
	{
		if ( $datetime === '' ) {
			return '';
		}
		$ts = strtotime( $datetime );
		return $ts !== false ? gmdate( 'd-m-Y', $ts ) : $datetime;
	}

	public static function project_options(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', (array) $wpdb->get_col(
			"SELECT DISTINCT project_slug FROM {$wpdb->prefix}es_leads WHERE project_slug != '' AND project_slug IS NOT NULL ORDER BY project_slug ASC"
		) ) ) );
	}

	public static function operator_options(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', (array) $wpdb->get_col(
			"SELECT DISTINCT assigned_operator_name FROM {$wpdb->prefix}es_leads WHERE assigned_operator_name != '' AND assigned_operator_name IS NOT NULL ORDER BY assigned_operator_name ASC"
		) ) ) );
	}

	public static function recontact_badge( int $count ): string
	{
		if ( $count <= 0 ) {
			return '<span class="muted">—</span>';
		}
		$flames = str_repeat( '🔥', min( $count, 5 ) );
		return '<span title="Recontactos realizados: ' . (int) $count . '">' . $flames . ' <span class="muted">x' . (int) $count . '</span></span>';
	}

	/** Badge de estado comercial: Calificado (verde) / Contactado (azul) / Nunca contactado (rojo). */
	public static function status_badge( string $status ): string
	{
		if ( $status === 'Calificado' ) {
			return '<span class="badge" style="background:#00a32a;color:#fff;display:inline-block;border-radius:99px;padding:2px 8px;font-size:11px;font-weight:600;white-space:nowrap;" title="Lead calificado por la operadora">✔ Calificado</span>';
		}
		if ( $status === 'Contactado' ) {
			return '<span class="badge" style="background:#2271b1;color:#fff;display:inline-block;border-radius:99px;padding:2px 8px;font-size:11px;font-weight:600;white-space:nowrap;" title="Lead contactado">✆ Contactado</span>';
		}
		// Nunca contactado (y 'Sin contacto' legacy por compat).
		return '<span class="badge" style="background:#b32d2e;color:#fff;display:inline-block;border-radius:99px;padding:2px 8px;font-size:11px;font-weight:700;white-space:nowrap;" title="Lead sin contacto registrado — requiere gestión">⚠ Nunca contactado</span>';
	}

	// ── Vista unificada (CRM + submissions, para la API) ──────────────────

	public static function unified_leads( array $filters = array(), int $limit = 200 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$rows = array();

		$es = (array) $wpdb->get_results(
			"SELECT id, name, email, phone, project_slug AS project, lead_intent AS intent,
			        first_source_plugin AS src, first_landing_url AS landing_url, first_seen_at AS created_at
			 FROM {$wpdb->prefix}es_leads ORDER BY id DESC LIMIT 500", ARRAY_A
		);
		foreach ( $es as $r ) {
			$rows[] = self::row(
				(int) $r['id'], (string) $r['name'], (string) $r['email'], (string) $r['phone'],
				(string) $r['project'], (string) $r['intent'], (string) $r['src'],
				(string) $r['landing_url'], (string) $r['created_at'], 'crm'
			);
		}

		$subs = (array) $wpdb->get_results(
			"SELECT id, name, email_normalized AS email, phone_normalized AS phone, message AS intent,
			        source AS src, landing_url, created_at, fields_json
			 FROM {$wpdb->prefix}estandar_capture_submissions
			 WHERE (origin_lead_id IS NULL OR origin_lead_id = 0)
			   AND (spam_honeypot IS NULL OR spam_honeypot != 1)
			   AND (source_form_id NOT LIKE 'gf-%' AND source != 'gf_postventa')
			 ORDER BY id DESC LIMIT 500", ARRAY_A
		);
		foreach ( $subs as $r ) {
			$project = '';
			$fj = json_decode( (string) ( $r['fields_json'] ?? '' ), true );
			if ( is_array( $fj ) ) {
				$project = (string) ( $fj['project'] ?? $fj['project_slug'] ?? '' );
			}
			$rows[] = self::row(
				(int) $r['id'], (string) $r['name'], (string) $r['email'], (string) $r['phone'],
				$project, (string) $r['intent'], (string) $r['src'],
				(string) $r['landing_url'], (string) $r['created_at'], 'submission'
			);
		}

		$rows = self::filter_rows( $rows, $filters );
		usort( $rows, static function ( $a, $b ) {
			return strcmp( (string) $b['created_at'], (string) $a['created_at'] );
		} );
		$limit = max( 1, min( 1000, (int) $limit ) );
		return array_slice( $rows, 0, $limit );
	}

	private static function row( int $id, string $name, string $email, string $phone, string $project, string $intent, string $src, string $landing_url, string $created_at, string $origin ): array
	{
		return array(
			'id'          => $id,
			'name'        => $name,
			'email'       => $email,
			'phone'       => $phone,
			'project'     => $project,
			'intent'      => $intent,
			'source'      => $src,
			'landing_url' => $landing_url,
			'created_at'  => $created_at,
			'origin'      => $origin,
		);
	}

	private static function filter_rows( array $rows, array $filters ): array
	{
		$from = (string) ( $filters['from'] ?? '' );
		$to   = (string) ( $filters['to'] ?? '' );
		$proj = mb_strtolower( (string) ( $filters['project'] ?? '' ) );
		$src  = mb_strtolower( (string) ( $filters['source'] ?? '' ) );
		return array_values( array_filter( $rows, static function ( $r ) use ( $from, $to, $proj, $src ) {
			$d = (string) $r['created_at'];
			if ( $from !== '' && $d < $from . ' 00:00:00' ) {
				return false;
			}
			if ( $to !== '' && $d > $to . ' 23:59:59' ) {
				return false;
			}
			if ( $proj !== '' && mb_strpos( mb_strtolower( (string) $r['project'] ), $proj ) === false ) {
				return false;
			}
			if ( $src !== '' && mb_strpos( mb_strtolower( (string) $r['source'] ), $src ) === false ) {
				return false;
			}
			return true;
		} ) );
	}

	// ── Exports ───────────────────────────────────────────────────────────

	public static function export_csv( array $rows, array $columns = array() ): string
	{
		$columns = $columns ?: self::columns();
		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, $columns );
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $columns as $c ) {
				$line[ $c ] = (string) ( $r[ $c ] ?? '' );
			}
			fputcsv( $fh, $line );
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	public static function export_xlsx( array $rows, array $columns = array() ): string
	{
		$columns = $columns ?: self::columns();
		$cell = static function ( string $v, int $r, string $ref ) {
			return '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars( $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</t></is></c>';
		};
		$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
		$headerCells = '';
		$col = 0;
		foreach ( $columns as $h ) {
			$headerCells .= $cell( $h, 1, self::col_ref( $col ) . '1' );
			$col++;
		}
		$sheet .= '<row r="1">' . $headerCells . '</row>';
		foreach ( $rows as $i => $r ) {
			$rn = $i + 2;
			$cells = '';
			$col = 0;
			foreach ( $columns as $h ) {
				$cells .= $cell( (string) ( $r[ $h ] ?? '' ), $rn, self::col_ref( $col ) . $rn );
				$col++;
			}
			$sheet .= '<row r="' . $rn . '">' . $cells . '</row>';
		}
		$sheet .= '</sheetData></worksheet>';

		$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';
		$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
		$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Leads" sheetId="1" r:id="rId1"/></sheets></workbook>';
		$workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
		$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
			. '<borders count="1"><border/></borders>'
			. '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
			. '<cellXfs count="1"><xf/></cellXfs>'
			. '</styleSheet>';

		$zip = new \ZipArchive();
		$tmp = tempnam( sys_get_temp_dir(), 'lpx' );
		if ( $zip->open( $tmp, \ZipArchive::OVERWRITE ) !== true ) {
			return '';
		}
		$zip->addFromString( '[Content_Types].xml', $content_types );
		$zip->addFromString( '_rels/.rels', $rels );
		$zip->addFromString( 'xl/workbook.xml', $workbook );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $workbook_rels );
		$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet );
		$zip->addFromString( 'xl/styles.xml', $styles );
		$zip->close();
		$bytes = (string) file_get_contents( $tmp );
		@unlink( $tmp );
		return $bytes;
	}

	public static function export_pdf( array $rows, array $columns = array() ): string
	{
		$columns = $columns ?: self::columns();
		$html = '<style>body{font-family:Helvetica,Arial,sans-serif;font-size:11px;color:#222;}
			table{border-collapse:collapse;width:100%;}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;}
			th{background:#f0f0f0;font-weight:bold;}h1{font-size:18px;}</style>'
			. '<h1>Leads</h1><table><thead><tr>';
		foreach ( $columns as $h ) {
			$html .= '<th>' . esc_html( (string) $h ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$html .= '<tr>';
			foreach ( $columns as $c ) {
				$html .= '<td>' . esc_html( (string) ( $r[ $c ] ?? '' ) ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';
		try {
			$pdf = new \Estandar\Core\Communications\Documents\Pdf();
			$bytes = $pdf->render( $html, array( 'paper' => 'landscape' ) );
			return (string) $bytes;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	private static function col_ref( int $i ): string
	{
		$s = '';
		$i++;
		while ( $i > 0 ) {
			$m = ( $i - 1 ) % 26;
			$s = chr( 65 + $m ) . $s;
			$i = (int) ( ( $i - 1 ) / 26 );
		}
		return $s;
	}

	// ── Branding (logo estandar.cl + naranja de la Suite) ─────────────────

	/** Cabecera de marca (logo linkeado a estandar.cl + color del backend). */
	public static function brand_top( string $title, string $right = '' ): string
	{
		return '<header style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:' . self::BRAND_BG . ';color:#fff;border-radius:10px;padding:10px 16px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.12);">'
			. '<div style="display:flex;align-items:center;gap:10px;">'
			. '<a href="' . esc_url( 'https://estandar.cl' ) . '" target="_blank" rel="noopener" style="display:inline-flex;line-height:0;" title="Estandar.cl"><img src="' . esc_url( self::BRAND_LOGO ) . '" alt="Estandar" style="max-height:26px;width:auto;display:block;filter:brightness(0) invert(1);"></a>'
			. '<span style="display:flex;flex-direction:column;line-height:1.2;"><strong style="font-size:16px;letter-spacing:.02em;">' . esc_html( $title ) . '</strong><span style="font-size:11px;opacity:.85;">Estandar Suite · Plataforma comercial</span></span>'
			. '</div>'
			. ( $right !== '' ? '<div style="display:flex;align-items:center;gap:10px;font-size:13px;">' . $right . '</div>' : '' )
			. '</header>';
	}

	/** Pie de página discreto: branding Estandar Estudio → estandar.cl. */
	public static function brand_footer(): string
	{
		return '<footer style="text-align:center;font-size:11px;color:#a7aaad;margin:22px 0 6px;line-height:1.7;">'
			. 'Impulsado por <a href="' . esc_url( 'https://estandar.cl' ) . '" target="_blank" rel="noopener" style="color:#a7aaad;text-decoration:underline;">Estandar Studio</a>'
			. ' — soluciones digitales de IA.</footer>';
	}

	/** Página de login (acceso por correo, sin contraseña), con marca. */
	public static function render_login_page( bool $error = false, string $notice = '', string $notice_type = 'info' ): string
	{
		$action  = esc_url( home_url( '/' . self::PANEL_SLUG . '/' ) );
		$domain  = self::magic_domain();
		$styles  = array( 'ok' => '#e7f6ec', 'error' => '#fcf0f1', 'info' => '#f0f6fc' );
		$colors  = array( 'ok' => '#01651f', 'error' => '#b32d2e', 'info' => '#2271b1' );
		$ntype   = isset( $styles[ $notice_type ] ) ? $notice_type : 'info';
		$notice_html = '';
		if ( $notice !== '' ) {
			$notice_html = '<div class="notice" style="background:' . $styles[ $ntype ] . ';color:' . $colors[ $ntype ] . ';border-radius:6px;padding:9px 11px;font-size:13px;margin-bottom:10px;">' . esc_html( $notice ) . '</div>';
		}
		$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );

		// Tras enviar el enlace con éxito: se oculta el formulario y se muestra la
		// confirmación + la vía de contacto al administrador si no llegó el correo.
		if ( $ntype === 'ok' ) {
			$card = '<div class="card" style="text-align:center;">'
				. '<div style="font-size:34px;margin:0 0 6px;">📬</div>'
				. '<h3 style="margin:0 0 8px;font-size:16px;">Revisa tu correo</h3>'
				. $notice_html
				. '<p class="hint" style="text-align:left;">El enlace es de un solo uso, caduca en 30 minutos y tu sesión se mantiene por 30 días. Si no está en Recibidos, revisa la carpeta No deseado/Spam.</p>'
				. ( $admin_email !== ''
					? '<p class="hint" style="text-align:left;margin-top:10px;"><strong>¿No recibiste el correo?</strong> Avisa al administrador de la web: <a href="mailto:' . esc_attr( $admin_email ) . '" style="color:#2271b1;">' . esc_html( $admin_email ) . '</a>, para revisar el problema.</p>'
					: '' )
				. '<p style="margin-top:14px;"><a class="btn sub" href="' . $action . '">Volver a intentar</a></p>'
				. '</div>';
		} else {
			$card = '<div class="card">'
				. '<h3 style="margin:0 0 4px;font-size:15px;">Acceso por correo</h3>'
				. $notice_html
				. '<p class="hint" style="margin:0 0 6px;">Escribe tu correo completo o tu <strong>nombre de usuario</strong> (ej. <code>e.desarrolladora</code>) — el <code>@' . esc_html( $domain ) . '</code> se agrega solo si escribes solo el nombre. Te enviamos un enlace de acceso.</p>'
				. '<form method="post" action="' . $action . '">'
				. '<input type="hidden" name="lp_action" value="magic_request">'
				. '<label for="lp-magic">Tu nombre</label>'
				. '<div class="email-field"><input id="lp-magic" name="email" required autocomplete="username" inputmode="text" placeholder="ej. valentina" title="Escribe solo tu nombre, sin @' . esc_attr( $domain ) . '"><span class="email-suffix">@' . esc_html( $domain ) . '</span></div>'
				. '<button class="btn" type="submit">Enviar enlace de acceso</button>'
				. '</form>'
				. '<p class="hint">El enlace es de un solo uso, caduca en 30 minutos y tu sesión se mantiene por 30 días.</p>'
				. '</div>';
		}

		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Panel de Leads — Acceso</title>'
			. '<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f6f7f7;color:#1d2327;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:16px;}
			.wrap{width:100%;max-width:400px;}
			.card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:24px;box-shadow:0 4px 16px rgba(0,0,0,.08);margin-bottom:14px;}
			label{display:block;font-size:12px;color:#646970;margin:12px 0 4px;}
			input{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #dcdcde;border-radius:6px;font-size:15px;}
			.btn{width:100%;margin-top:14px;background:' . self::BRAND_BG . ';color:#fff;border:none;padding:11px;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;}
			.btn:hover{filter:brightness(.94);}
			.btn.sub{background:#fff;color:#2271b1;border:1px solid #c5d9ed;}
			.err{background:#fcf0f1;color:#b32d2e;border:1px solid #ff8085;border-radius:6px;padding:8px 10px;font-size:13px;margin-bottom:8px;}
			.hint{font-size:12px;color:#8c8f94;margin-top:8px;}
			.or{text-align:center;font-size:12px;color:#8c8f94;margin:14px 0 4px;}
			.email-field{display:flex;align-items:stretch;margin-top:4px;}
			.email-field input{flex:1;min-width:0;border-top-right-radius:0;border-bottom-right-radius:0;}
			.email-field .email-suffix{display:flex;align-items:center;background:#f0f0f1;border:1px solid #dcdcde;border-left:none;border-radius:0 6px 6px 0;padding:0 12px;font-size:14px;color:#646970;white-space:nowrap;}</style></head><body>'
			. '<div class="wrap">'
			. self::brand_top( 'Panel de Leads' )
			. $card
			. self::brand_footer()
			. '</div></body></html>';
	}

	// ── Gate de operadores embebido (forms / páginas propias) ─────────────
	//
	// GATE_V1 (2026-09-01): permite proteger una página/form con el MISMO motor
	// de operadores (PIN + magic link) que el panel, pero apuntando a un destino
	// propio (`$action_url` / `$return_url`). Dos accesos independientes:
	//   - Panel de Leads  → /panel-leads/ (comportamiento original).
	//   - Form de cámara  → la URL de la página que contiene [estandar_form_gate].
	// Ambos comparten cookies/autorización: un operador autenticado entra a
	// cualquiera "indistintamente".

	/**
	 * Procesa un POST de login (nombre+PIN) o magic_request y un ?magic= de un
	 * gate de operadores. Devuelve un array con 'redirect' (URL a la que saltar)
	 * o 'notice' (mensaje a mostrar). No emite salida.
	 *
	 * @param string $action_url URL a la que POSTear (página del form).
	 * @param string $return_url Destino tras autenticar (normalmente la misma).
	 * @param string $gate_key   Prefijo de las acciones (evita colisión con el panel).
	 * @param array  $context    Overrides del correo (label/subject/button/intro).
	 */
	public static function process_gate_post( string $action_url, string $return_url = '', string $gate_key = 'es_og', array $context = array() ): array
	{
		if ( $return_url === '' ) {
			$return_url = $action_url;
		}

		// Consumo de magic token (?magic=) antes que cualquier POST.
		$magic = isset( $_GET[ $gate_key . '_magic' ] ) ? (string) $_GET[ $gate_key . '_magic' ] : '';
		if ( $magic !== '' ) {
			$full = self::establish_magic_session( $magic );
			if ( ! empty( $full['email'] ) ) {
				$ret = (string) ( $full['return_url'] ?? '' );
				if ( $ret !== '' && strpos( $ret, home_url( '/' ) ) === 0 ) {
					return array( 'redirect' => $ret );
				}
				return array( 'redirect' => $return_url );
			}
			return array( 'notice' => 'El enlace no es válido o ya fue usado.', 'notice_type' => 'error' );
		}

		if ( empty( $_POST[ $gate_key . '_action' ] ) ) {
			return array();
		}

		$action = sanitize_key( (string) $_POST[ $gate_key . '_action' ] );

		// Login por nombre + PIN de 4 letras (mismo motor que el panel).
		if ( $action === 'login' ) {
			// GATE_METHODS_V1: respetar el método habilitado (server-side).
			if ( ! self::gate_offers( 'pin', $context['methods'] ?? null ) ) {
				return array( 'notice' => 'El acceso por PIN no está disponible.', 'notice_type' => 'error' );
			}
			$name = sanitize_text_field( (string) ( $_POST[ $gate_key . '_name' ] ?? '' ) );
			$pin  = (string) ( $_POST[ $gate_key . '_pin' ] ?? '' );
			$ip   = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			if ( self::login_locked( $name, $ip ) ) {
				return array( 'notice' => 'Demasiados intentos fallidos. Intenta nuevamente en 15 minutos.', 'notice_type' => 'error' );
			}
			if ( self::authenticate( $name, $pin ) ) {
				self::clear_login_failures( $name, $ip );
				self::log_login( 'success', $name, $ip );
				return array( 'redirect' => $return_url );
			}
			self::register_login_failure( $name, $ip );
			return array( 'notice' => 'Nombre o PIN incorrecto.', 'notice_type' => 'error' );
		}

		// Solicitud de magic link por correo.
		if ( $action === 'magic_request' ) {
			// GATE_METHODS_V1: respetar el método habilitado (server-side).
			if ( ! self::gate_offers( 'magic', $context['methods'] ?? null ) ) {
				return array( 'notice' => 'El acceso por correo no está disponible.', 'notice_type' => 'error' );
			}
			$input = sanitize_text_field( (string) ( $_POST[ $gate_key . '_email' ] ?? '' ) );
			$at = strpos( $input, '@' );
			if ( $at !== false ) {
				$input = substr( $input, 0, $at );
			}
			$email = self::resolve_magic_target( $input );
			if ( $email === '' ) {
				return array( 'notice' => 'Correo no válido para ' . self::magic_domain() . '.', 'notice_type' => 'error' );
			}
			$r = self::request_magic_link( $email, $return_url, $context );
			return array(
				'notice'      => (string) $r['message'],
				'notice_type' => $r['ok'] ? 'ok' : 'error',
			);
		}

		return array();
	}

	/**
	 * Render embebido del login de operador (PIN + magic link) para incluir en
	 * una página WP (no es una página completa como render_login_page). El POST
	 * va a `$action_url` con prefijos `$gate_key`_*.
	 *
	 * @param string $action_url URL destino del form de login.
	 * @param string $return_url Destino tras autenticar (hidden, para magic).
	 * @param string $title      Título del gate (p. ej. "Registro de Cámara").
	 * @param string $notice     Mensaje opcional (éxito/error/info).
	 * @param string $notice_type ok|error|info.
	 * @param string $gate_key   Prefijo de acciones/campos.
	 */
	public static function render_gate_login( string $action_url, string $return_url = '', string $title = 'Acceso de operador', string $notice = '', string $notice_type = 'info', string $gate_key = 'es_og', $methods_override = null ): string
	{
		$domain  = self::magic_domain();
		$styles  = array( 'ok' => '#e7f6ec', 'error' => '#fcf0f1', 'info' => '#f0f6fc' );
		$colors  = array( 'ok' => '#01651f', 'error' => '#b32d2e', 'info' => '#2271b1' );
		$ntype   = isset( $styles[ $notice_type ] ) ? $notice_type : 'info';
		$notice_html = '';
		if ( $notice !== '' ) {
			$notice_html = '<div style="background:' . $styles[ $ntype ] . ';color:' . $colors[ $ntype ] . ';border-radius:6px;padding:9px 11px;font-size:13px;margin:0 0 12px;">' . esc_html( $notice ) . '</div>';
		}
		$action = esc_url( $action_url );
		$ret    = esc_url( $return_url !== '' ? $return_url : $action_url );
		$gk     = esc_attr( $gate_key );

		// GATE_METHODS_V1: mostrar solo los métodos habilitados y viables.
		$methods    = self::resolve_gate_methods( $methods_override );
		$show_pin   = in_array( 'pin', $methods, true );
		$show_magic = in_array( 'magic', $methods, true );
		$intro      = ( $show_pin && $show_magic )
			? 'Inicia sesión para continuar. Acceso con PIN o por correo.'
			: ( $show_pin ? 'Inicia sesión para continuar. Acceso con nombre y PIN.' : 'Inicia sesión para continuar. Acceso por correo.' );

		$html = '<div class="estandar-form-gate" style="max-width:420px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:22px;box-shadow:0 4px 16px rgba(0,0,0,.06);font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1d2327;">'
			. '<h3 style="margin:0 0 4px;font-size:16px;">' . esc_html( $title ) . '</h3>'
			. '<p style="margin:0 0 14px;font-size:13px;color:#646970;">' . esc_html( $intro ) . '</p>'
			. $notice_html;

		if ( $show_pin ) {
			$html .= '<form method="post" action="' . $action . '">'
				. '<input type="hidden" name="' . $gk . '_action" value="login">'
				. '<label style="display:block;font-size:12px;color:#646970;margin:10px 0 4px;">Nombre de operador</label>'
				. '<input type="text" name="' . $gk . '_name" required autocomplete="username" style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #dcdcde;border-radius:6px;font-size:15px;">'
				. '<label style="display:block;font-size:12px;color:#646970;margin:10px 0 4px;">PIN (4 letras)</label>'
				. '<input type="password" name="' . $gk . '_pin" required maxlength="4" pattern="[A-Za-z]{4}" autocomplete="current-password" placeholder="abcd" style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #dcdcde;border-radius:6px;font-size:15px;">'
				. '<button type="submit" style="width:100%;margin-top:14px;background:#f95b1f;color:#fff;border:none;padding:11px;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;">Entrar</button>'
				. '</form>';
		}

		if ( $show_pin && $show_magic ) {
			$html .= '<div style="display:flex;align-items:center;gap:8px;margin:16px 0 4px;color:#8c8f94;font-size:12px;"><span style="flex:1;height:1px;background:#e2e4e7;"></span>o<span style="flex:1;height:1px;background:#e2e4e7;"></span></div>';
		}

		if ( $show_magic ) {
			$html .= '<form method="post" action="' . $action . '">'
				. '<input type="hidden" name="' . $gk . '_action" value="magic_request">'
				. '<input type="hidden" name="' . $gk . '_return_url" value="' . $ret . '">'
				. '<label style="display:block;font-size:12px;color:#646970;margin:10px 0 4px;">Tu nombre o correo</label>'
				. '<input type="text" name="' . $gk . '_email" required autocomplete="username" inputmode="text" placeholder="ej. valentina" title="Escribe tu nombre o correo completo" style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #dcdcde;border-radius:6px;font-size:15px;">'
				. '<button type="submit" style="width:100%;margin-top:14px;background:#fff;color:#2271b1;border:1px solid #c5d9ed;padding:11px;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;">Enviar enlace por correo</button>'
				. '</form>'
				. '<p style="font-size:12px;color:#8c8f94;margin-top:10px;">El enlace es de un solo uso, caduca en 30 minutos y tu sesión se mantiene por 30 días.</p>';
		}

		return $html . '</div>';
	}

	// ── Viewer (página de operador, sin login WP) ─────────────────────────

	public static function render_viewer( array $rows, array $filters, string $export_base, int $page = 1, int $total_pages = 1, int $total = 0, int $per_page = 30 ): string
	{
		$esc = static function ( string $v ): string {
			return esc_html( $v );
		};
		$q = '';
		foreach ( array( 'from', 'to', 'project', 'operator', 'name' ) as $k ) {
			if ( ! empty( $filters[ $k ] ) ) {
				$q .= '&' . $k . '=' . rawurlencode( (string) $filters[ $k ] );
			}
		}
		// Estado del orden (default: última actividad DESC = más reciente primero).
		$orderby = sanitize_key( (string) ( $filters['orderby'] ?? 'last_submitted_at' ) );
		$order   = strtolower( (string) ( $filters['order'] ?? 'desc' ) ) === 'asc' ? 'asc' : 'desc';
		$th = static function ( string $label, string $key ) use ( $filters, $orderby, $order ) {
			$next = ( $orderby === $key && $order === 'desc' ) ? 'asc' : 'desc';
			$arrow = $orderby === $key ? ( $order === 'desc' ? ' ↓' : ' ↑' ) : '';
			$qs = array();
			foreach ( array( 'from', 'to', 'project', 'operator', 'name', 'access' ) as $k ) {
				if ( ! empty( $filters[ $k ] ) ) {
					$qs[ $k ] = (string) $filters[ $k ];
				}
			}
			$qs['orderby'] = $key;
			$qs['order']   = $next;
			$href = '?' . http_build_query( $qs );
			return '<a href="' . esc_attr( $href ) . '" style="color:inherit;text-decoration:none;" title="Ordenar por ' . esc_attr( $label ) . '">'
				. esc_html( $label ) . '<span style="color:#f95b1f;">' . $arrow . '</span></a>';
		};

		$project_opts = '<option value="__unrouted__"' . selected( (string) ( $filters['project'] ?? '' ), '__unrouted__', false ) . '>— Sin proyecto —</option>';
		foreach ( self::project_options() as $po ) {
			$project_opts .= '<option value="' . esc_attr( $po ) . '"' . selected( (string) ( $filters['project'] ?? '' ), $po, false ) . '>' . $esc( $po ) . '</option>';
		}
		$operator_opts = '';
		foreach ( self::operator_options() as $oo ) {
			$operator_opts .= '<option value="' . esc_attr( $oo ) . '"' . selected( (string) ( $filters['operator'] ?? '' ), $oo, false ) . '>' . $esc( $oo ) . '</option>';
		}

		// Enlace a la ficha del lead, conservando filtros + access.
		$lead_href = static function ( int $id ) use ( $filters ) {
			$qs = array();
			foreach ( array( 'from', 'to', 'project', 'operator', 'name', 'access' ) as $k ) {
				if ( ! empty( $filters[ $k ] ) ) {
					$qs[ $k ] = (string) $filters[ $k ];
				}
			}
			$qs['lead'] = $id;
			return '?' . http_build_query( $qs );
		};

		$rows_html = '';
		// PANEL_PAGINATION_V1: paginador (mantiene filtros, orden, access y per_page).
		$pager_qs = static function ( int $p ) use ( $filters, $per_page ) {
			$qs = array();
			foreach ( array( 'from', 'to', 'project', 'operator', 'name', 'access' ) as $k ) {
				if ( ! empty( $filters[ $k ] ) ) { $qs[ $k ] = (string) $filters[ $k ]; }
			}
			$qs['orderby']  = (string) ( $filters['orderby'] ?? 'last_submitted_at' );
			$qs['order']    = (string) ( $filters['order'] ?? 'desc' );
			$qs['per_page'] = $per_page;
			$qs['page']     = $p;
			return '?' . http_build_query( $qs );
		};
		$per_page_form = static function () use ( $filters, $per_page ) {
			$qs = array();
			foreach ( array( 'from', 'to', 'project', 'operator', 'name', 'access' ) as $k ) {
				if ( ! empty( $filters[ $k ] ) ) { $qs[ $k ] = (string) $filters[ $k ]; }
			}
			$qs['orderby']  = (string) ( $filters['orderby'] ?? 'last_submitted_at' );
			$qs['order']    = (string) ( $filters['order'] ?? 'desc' );
			$qs['page']     = '1';
			$per_opts = '';
			foreach ( array( 30, 50, 100, 250 ) as $pp ) {
				$per_opts .= '<option value="' . (int) $pp . '"' . selected( $per_page, $pp, false ) . '>' . (int) $pp . '</option>';
			}
			$hidden = '';
			foreach ( $qs as $k => $v ) {
				$hidden .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
			}
			return '<form method="get" style="display:inline;"><select name="per_page" onchange="this.form.submit()" style="padding:3px 6px;">'
				. $per_opts . '</select>' . $hidden . '</form>';
		};
		$pager = '';
		if ( $total_pages > 1 || $total > 0 ) {
			$range_start = $total > 0 ? ( $page - 1 ) * $per_page + 1 : 0;
			$range_end   = min( $page * $per_page, $total );
			$pager = '<div class="pager">'
				. '<span class="meta">Mostrando ' . (int) $range_start . '–' . (int) $range_end . ' de ' . (int) $total . ' · '
				. 'Ver ' . $per_page_form() . ' por página</span>';
			if ( $total_pages > 1 ) {
				if ( $page > 1 ) {
					$pager .= '<a class="btn small" href="' . esc_attr( $pager_qs( $page - 1 ) ) . '">← Anterior</a>';
				}
				$start = max( 1, $page - 3 );
				$end   = min( $total_pages, $page + 3 );
				for ( $p = $start; $p <= $end; $p++ ) {
					if ( $p === $page ) {
						$pager .= '<strong style="padding:4px 10px;background:#f95b1f;color:#fff;border-radius:6px;">' . (int) $p . '</strong>';
					} else {
						$pager .= '<a class="btn small" href="' . esc_attr( $pager_qs( $p ) ) . '">' . (int) $p . '</a>';
					}
				}
				if ( $page < $total_pages ) {
					$pager .= '<a class="btn small" href="' . esc_attr( $pager_qs( $page + 1 ) ) . '">Siguiente →</a>';
				}
			}
			$pager .= '</div>';
		}
		foreach ( $rows as $r ) {
			$lead_url = esc_url( $lead_href( (int) $r['id'] ) );
			$rows_html .= '<tr>'
				. '<td class="nowrap" data-label="ID"><a href="' . $lead_url . '" class="leadlink" title="Ver ficha del pipeline">#' . esc_html( (string) $r['id'] ) . '</a></td>'
				. '<td class="nowrap" data-label="Última actividad">' . $esc( (string) $r['last_submitted_at'] ) . '</td>'
				. '<td class="center" data-label="Recontactos">' . self::recontact_badge( (int) $r['recontacts'] ) . '</td>'
				. '<td data-label="Nombre"><strong><a href="' . $lead_url . '" class="leadlink" title="Ver ficha del pipeline">' . $esc( (string) $r['name'] ) . '</a></strong></td>'
				. '<td data-label="Proyecto">' . ( ! empty( $r['project'] ) ? '<span class="tag">' . $esc( (string) $r['project'] ) . '</span>' : '<span class="muted">—</span>' ) . '</td>'
				. '<td class="nowrap" data-label="Teléfono">' . $esc( (string) $r['phone'] ) . '</td>'
				. '<td data-label="Email"><a href="mailto:' . esc_attr( $r['email'] ) . '">' . $esc( (string) $r['email'] ) . '</a></td>'
				. '<td class="center" data-label="Estado">' . self::status_badge( (string) $r['status'] ) . '</td>'
				. '<td class="nowrap" data-label="Acción"><a class="btn small" href="' . esc_url( (string) $r['qualify_url'] ) . '" target="_blank">Calificar ↗</a></td>'
				. '</tr>';
		}
		if ( empty( $rows ) ) {
			$rows_html = '<tr><td colspan="9">Sin leads para los filtros.</td></tr>';
		}

		$operator = self::current_user_label();
		$right = $operator !== ''
			? '<span>👤 ' . $esc( $operator ) . '</span><a href="' . esc_url( home_url( '/' . self::PANEL_SLUG . '/?logout=1' ) ) . '" style="color:#fff;">Salir</a>'
			: '';

		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panel de Leads</title>
<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f6f7f7;color:#1d2327;}
.wrap{max-width:1280px;margin:0 auto;padding:24px 16px;}
.sub{color:#646970;font-size:13px;margin-bottom:18px;}
.card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin-bottom:16px;}
form.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;}
form.filters label{font-size:12px;color:#646970;display:flex;flex-direction:column;gap:3px;}
form.filters input,form.filters select{padding:6px 8px;border:1px solid #dcdcde;border-radius:6px;background:#fff;}
.btn{display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600;margin-right:8px;}
.btn.green{background:#00a32a;}.btn.orange{background:#dba617;}
.btn.small{padding:4px 10px;font-size:12px;background:#7e57c2;}
.btn.small:hover{background:#6a47ad;}
.muted{color:#8c8f94;font-size:12px;}
.tag{display:inline-block;background:#f0f6fc;color:#2271b1;border-radius:10px;padding:1px 8px;font-size:11px;margin-top:3px;}
table{width:100%;border-collapse:collapse;background:#fff;font-size:13px;}
th,td{border:1px solid #dcdcde;padding:7px 9px;text-align:left;vertical-align:middle;}
th{background:#f0f6fc;font-weight:600;white-space:nowrap;}
.nowrap{white-space:nowrap;}
.center{text-align:center;}
.leadlink{color:#2271b1;text-decoration:none;}
.leadlink:hover{text-decoration:underline;}
.meta{color:#646970;font-size:12px;margin-top:10px;}
.pager{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;margin:8px 0 14px;}
.pg{display:inline-block;min-width:32px;padding:6px 10px;border:1px solid #dcdcde;border-radius:6px;background:#fff;text-decoration:none;color:#1d2327;font-size:13px;text-align:center;}
.pg.cur{background:#f95b1f;border-color:#f95b1f;color:#fff;font-weight:700;}
.pg.nav{font-weight:600;}
.pg.off{opacity:.45;background:#f6f7f7;}
.pg.meta{min-width:auto;border:none;background:none;color:#646970;font-size:12px;}
.emailbox{display:flex;gap:8px;margin:8px 0 6px;flex-wrap:wrap;}
.emailbox input{flex:1 1 220px;padding:8px 10px;border:1px solid #dcdcde;border-radius:6px;}
.emailbox select{padding:8px 10px;border:1px solid #dcdcde;border-radius:6px;background:#fff;}
.emailbox .btn{margin:0;}
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.toolbar .btn{margin:0;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:flex-start;justify-content:center;padding:24px 12px;overflow-y:auto;}
.modal.open{display:flex;}
.modal-card{background:#fff;border-radius:12px;max-width:560px;width:100%;box-shadow:0 12px 40px rgba(0,0,0,.25);}
.modal-head{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f0f0f1;font-size:15px;}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#646970;line-height:1;}
.modal-body{padding:16px 18px;}
.dlrow{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 4px;}
.btn.sm{display:inline-block;padding:6px 12px;font-size:12px;margin:0;}
@media (max-width:720px){
.wrap{padding:12px 10px;}
.modal{padding:0;align-items:stretch;}
.modal-card{border-radius:0;min-height:100vh;box-shadow:none;}
form.filters{gap:8px;}
form.filters label{flex:1 1 100%;}
form.filters input,form.filters select{width:100%;box-sizing:border-box;}
.btn{display:block;width:100%;box-sizing:border-box;margin:0 0 8px;text-align:center;}
.btn.small{display:inline-block;width:auto;margin:0;padding:6px 12px;}
.emailbox input,.emailbox select{flex:1 1 100%;}
.emailbox .btn{flex:1 1 100%;}
table thead{display:none;}
table,table tbody,table tr,table td{display:block;width:100%;box-sizing:border-box;}
table tr{background:#fff;border:1px solid #dcdcde;border-radius:10px;margin-bottom:10px;padding:6px 12px;}
table td{display:flex;justify-content:space-between;align-items:center;gap:12px;border:none;border-bottom:1px solid #f0f0f1;padding:8px 0;}
table td:last-child{border-bottom:none;}
td::before{content:attr(data-label);font-weight:600;color:#646970;font-size:12px;white-space:nowrap;flex-shrink:0;}
td.nowrap,td.center{white-space:normal;text-align:left;}
.pager{justify-content:flex-start;}
}
</style></head><body><div class="wrap">'
			. self::brand_top( 'Panel de Leads', $right )
			. '<div class="sub">Leads del CRM (es_leads) · los 🔥 indican cuántas veces el lead volvió a comunicarse (reformulario, +3h) · Estado delata si ya fue contactado o calificado.</div>'

			. '<div class="toolbar">
<a class="btn" href="' . esc_url( home_url( '/' . self::PANEL_SLUG . '/?new=1' ) ) . '">＋ Nuevo lead</a>
<button type="button" id="es-open-modal" class="btn">⚙ Filtros y descargas</button>
</div>

<div id="es-modal" class="modal" aria-hidden="true">
<div class="modal-card">
<div class="modal-head"><strong>Filtros y descargas</strong><button type="button" id="es-close-modal" class="modal-close" aria-label="Cerrar">✕</button></div>
<div class="modal-body">
<form class="filters" method="get">
<input type="hidden" name="access" value="' . esc_attr( (string) ( $filters['access'] ?? '' ) ) . '">
<label>Desde <input type="date" name="from" value="' . esc_attr( (string) ( $filters['from'] ?? '' ) ) . '"></label>
<label>Hasta <input type="date" name="to" value="' . esc_attr( (string) ( $filters['to'] ?? '' ) ) . '"></label>
<label>Proyecto <select name="project"><option value="">Todos</option>' . $project_opts . '</select></label>
<label>Operador <select name="operator"><option value="">Todos</option>' . $operator_opts . '</select></label>
<label>Nombre / email / tel <input type="text" name="name" value="' . esc_attr( (string) ( $filters['name'] ?? '' ) ) . '" placeholder="Buscar…"></label>
<button class="btn" type="submit">Filtrar</button>
</form>

<hr style="border:none;border-top:1px solid #f0f0f1;margin:14px 0;">
<strong>Descargar (por correo)</strong>
<div class="emailbox">
<input type="email" id="es-send-to" placeholder="destino@correo.cl" required>
<select id="es-send-fmt"><option value="csv">CSV</option><option value="xlsx">Excel</option><option value="pdf">PDF</option></select>
<button type="button" id="es-send-btn" class="btn sm green">Enviar</button>
</div>
<div id="es-send-msg" class="meta"></div>
</div>
</div>
</div>

<div class="card">
<table><thead><tr>
<th>' . $th( 'ID', 'id' ) . '</th><th title="Última vez que el lead envió una solicitud (reformulario cuenta)">' . $th( 'Última actividad', 'last_submitted_at' ) . '</th><th title="Veces que el lead volvió a comunicarse (nueva solicitud tras +3h)">' . $th( 'Recontactos', 'recontacts' ) . '</th><th>' . $th( 'Nombre', 'name' ) . '</th><th>' . $th( 'Proyecto', 'project' ) . '</th><th>' . $th( 'Teléfono', 'phone' ) . '</th><th>' . $th( 'Email', 'email' ) . '</th><th title="Estado comercial: contacto o calificación registrada">' . $th( 'Estado', 'status' ) . '</th><th></th>
 </tr></thead><tbody>' . $rows_html . '</tbody></table>
</div>

' . $pager . '

<div style="text-align:center;margin:6px 0 8px;">
<button type="button" id="es-download-all" class="btn">⬇ Descargar todos</button>
<p class="meta">Al pulsar se abren los filtros y el envío por correo. El archivo se descarga en el formato elegido y queda registrado.</p>
</div>
<script>
(function(){
  var modal = document.getElementById("es-modal");
  var openBtn = document.getElementById("es-open-modal");
  var closeBtn = document.getElementById("es-close-modal");
  var dlAll = document.getElementById("es-download-all");
  function openModal(){ if(modal){ modal.classList.add("open"); modal.setAttribute("aria-hidden","false"); } }
  function closeModal(){ if(modal){ modal.classList.remove("open"); modal.setAttribute("aria-hidden","true"); } }
  if (openBtn) { openBtn.addEventListener("click", openModal); }
  if (dlAll) { dlAll.addEventListener("click", openModal); }
  if (closeBtn) { closeBtn.addEventListener("click", closeModal); }
  if (modal) {
    modal.addEventListener("click", function (e) { if (e.target === modal) { closeModal(); } });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") { closeModal(); } });
  }
})();
(function(){
  var to = document.getElementById("es-send-to");
  var fmt = document.getElementById("es-send-fmt");
  var btn = document.getElementById("es-send-btn");
  var msg = document.getElementById("es-send-msg");
  if (!to || !fmt || !btn || !msg) { return; }
  var url = ' . wp_json_encode( rest_url( 'estandar-suite/core/v1/leads/email' ) ) . ';
  var qs = ' . wp_json_encode( $q ) . ';
  var nonce = ' . wp_json_encode( function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '' ) . ';
  btn.addEventListener("click", function () {
    var email = to.value.trim();
    if (!email) { msg.textContent = "Ingresa el correo destino."; return; }
    btn.disabled = true; msg.textContent = "Enviando…";
    fetch(url + "?email=" + encodeURIComponent(email) + "&format=" + fmt.value + qs, {
      method: "POST", credentials: "same-origin",
      headers: { "X-WP-Nonce": nonce, "Content-Type": "application/json" }
    }).then(function (r) { return r.json(); }).then(function (d) {
      btn.disabled = false;
      msg.textContent = d && d.ok ? ("Enviado a " + d.to + " (" + d.format + " · " + d.rows + " registros).") : (d && d.message ? "Error: " + d.message : "Error al enviar.");
    }).catch(function () { btn.disabled = false; msg.textContent = "Error de conexión."; });
  });
})();
</script>
'
			. self::brand_footer()
			. '</div></body></html>';
	}

	// ── Ficha del pipeline de un lead ────────────────────────────────────

	/** Fecha legible DD-MM-YYYY HH:mm (almacenadas en UTC). */
	private static function fecha_hora( string $datetime ): string
	{
		$ts = strtotime( $datetime );
		return $ts !== false ? gmdate( 'd-m-Y H:i', $ts ) : $datetime;
	}

	/** Etiqueta del motivo de recontacto (código canónico de capture). */
	private static function repeat_reason_label( string $code ): string
	{
		$map = array(
			'not_contacted_yet' => 'Aún no me contactan',
			'add_info'          => 'Quiero agregar información',
			'request_changed'   => 'Mi solicitud cambió',
			'urgent'            => 'Necesito atención urgente',
			'other'             => 'Otro',
		);
		return $map[ $code ] ?? $code;
	}

	/** Etiqueta en español de un estado comercial canónico (value se conserva). */
	public static function lead_status_label( string $code ): string
	{
		$map = array(
			'NEW'             => 'Nuevo',
			'CONTACTED'       => 'Contactado',
			'NO_RESPONSE'     => 'Sin respuesta',
			'UNREACHABLE'     => 'No contactable',
			'QUALIFIED'       => 'Calificado',
			'MEETING_BOOKED'  => 'Reunión agendada',
			'PROPOSAL_SENT'   => 'Propuesta enviada',
			'NEGOTIATION'     => 'En negociación',
			'CONVERTED'       => 'Convertido',
			'CLIENT'          => 'Cliente',
			'LOST'            => 'Perdido',
			'INVALID'         => 'Inválido',
		);
		return $map[ strtoupper( $code ) ] ?? $code;
	}

	/** Canal de adquisición legible: red social / buscador / terceros / directo. */
	private static function pipeline_channel( string $source, string $medium, string $referrer ): string
	{
		$s = mb_strtolower( $source );
		$m = mb_strtolower( $medium );
		if ( in_array( $s, array( 'fb', 'ig', 'meta', 'facebook', 'instagram', 'messenger' ), true ) ) {
			return 'Red social (Meta)';
		}
		if ( in_array( $s, array( 'tt', 'tiktok' ), true ) ) {
			return 'TikTok';
		}
		if ( $s === 'google' ) {
			return $m === 'organic' ? 'Google orgánico' : ( ( $m === 'cpc' || $m === 'paid' ) ? 'Google Ads' : 'Google' );
		}
		if ( in_array( $s, array( 'bing', 'yahoo', 'duckduckgo' ), true ) ) {
			return 'Buscador (' . ucfirst( $s ) . ')';
		}
		if ( $referrer !== '' ) {
			$host = parse_url( $referrer, PHP_URL_HOST );
			return 'Link de terceros (' . ( $host ?: $referrer ) . ')';
		}
		if ( $s !== '' ) {
			return ucfirst( $s );
		}
		return 'Directo';
	}

	/** Etiqueta humana de un evento del pipeline. */
	public static function pipeline_event_label( string $type ): string
	{
		$map = array(
			'lead_created'                        => 'Lead creado',
			'lead_submitted'                      => 'Formulario enviado',
			'lead_recontacted'                    => 'El lead volvió a comunicarse',
			'lead_viewed'                         => 'Lead abierto (detalle)',
			'lead_list_opened'                    => 'Lista de leads abierta',
			'workspace_opened'                    => 'Workspace abierto (lead visible)',
			'routing_unresolved'                  => 'Routing sin resolver',
			'routing_skipped_by_policy'           => 'Routing omitido por política',
			'operator_assigned'                   => 'Operador asignado',
			'operator_notified'                   => 'Operador notificado',
			'operator_notification_deferred'      => 'Notificación diferida (horario)',
			'operator_notification_consolidated'  => 'Notificación consolidada',
			'notification_skipped_by_policy'      => 'Notificación omitida por política',
			'autoresponse_email_sent'             => 'Correo de confirmación enviado',
			'autoresponse_email_failed'           => 'Correo de confirmación FALLÓ',
			'autoresponse_skipped'                => 'Correo de confirmación omitido',
			'autoresponse_skipped_by_policy'      => 'Correo de confirmación omitido por política',
			'autoresponse_email_opened'           => 'Correo de confirmación abierto',
			'autoresponse_email_open_signal'      => 'Señal de apertura del correo',
			'autoresponse_link_click'             => 'Clic en enlace del correo',
			'autoresponse_engaged'                => 'El lead interactuó con el correo',
			'autoresponse_pdf_clicked'            => 'PDF del correo descargado',
			'whatsapp_clicked'                    => 'WhatsApp abierto por el operador',
			'email_clicked'                       => 'Email abierto por el operador',
			'call_clicked'                        => 'Llamada iniciada por el operador',
			'operator_clicked_whatsapp'           => 'Operador pinchó WhatsApp',
			'operator_clicked_email'              => 'Operador pinchó email',
			'operator_clicked_call'               => 'Operador pinchó teléfono',
			'operator_clicked_lead'               => 'Operador abrió el lead',
			'operator_clicked_maps'               => 'Operador consultó maps',
			'contact_logged'                      => 'Contacto registrado',
			'contact_registered'                  => 'Contacto registrado',
			'lead_contacted'                      => 'Lead contactado',
			'status_changed'                      => 'Estado comercial cambiado',
			'status_overridden'                   => 'Estado comercial sobreescrito',
			'lead_classified'                     => 'Lead clasificado',
			'lead_qualified'                      => 'Lead calificado',
			'feedback_status_changed'             => 'Estado de feedback cambiado',
			'quality_changed'                     => 'Calidad cambiada',
			'commercial_stage_changed'            => 'Etapa comercial cambiada',
			'operator_feedback_started'           => 'Operador inició la clasificación',
			'operator_feedback_completed'         => 'Operador completó la clasificación',
			'chatbot_conversation_started'        => 'Conversación de chatbot iniciada',
			'chatbot_started'                     => 'Chatbot iniciado',
			'experience_started'                  => 'Experiencia iniciada',
			'experience_completed'                => 'Experiencia completada',
		);
		return $map[ $type ] ?? str_replace( array( '_', '-' ), ' ', ucfirst( (string) $type ) );
	}

	/** Razón corta del fallo de autorespuesta (para el timeline). */
	public static function autoresponse_failure_reason( array $events ): string
	{
		foreach ( $events as $ev ) {
			if ( (string) $ev['event_type'] !== 'autoresponse_email_failed' ) {
				continue;
			}
			$md = json_decode( (string) ( $ev['metadata'] ?? '' ), true );
			$md = is_array( $md ) ? $md : array();
			$r  = (string) ( $md['reason'] ?? '' );
			$m  = (string) ( $md['send_error_message'] ?? '' );
			if ( $r === 'send_error' && $m !== '' ) {
				return $m;
			}
			if ( $r === 'send_error' ) {
				$lerr = get_option( 'estandar_leads_smtp_last_error', array() );
				if ( is_array( $lerr ) && ! empty( $lerr['message'] ) ) {
					return (string) $lerr['message'];
				}
			}
			return $r !== '' ? $r : 'send_error';
		}
		return '';
	}

	/** Detalle accionable del fallo de autorespuesta (razón + error + cómo arreglarlo). */
	public static function autoresponse_failure_detail( array $events ): string
	{
		foreach ( $events as $ev ) {
			if ( (string) $ev['event_type'] !== 'autoresponse_email_failed' ) {
				continue;
			}
			$md = json_decode( (string) ( $ev['metadata'] ?? '' ), true );
			$md = is_array( $md ) ? $md : array();
			$reason = (string) ( $md['reason'] ?? '' );
			$msg    = (string) ( $md['send_error_message'] ?? '' );
			$hints  = array(
				'send_error'      => 'error de envío (SMTP/correo). Revisa Configuración → Correo y prueba el envío de prueba.',
				'missing_project' => 'el lead no tiene proyecto asignado (routing sin resolver). Revisa el mapeo del formulario.',
				'suspect'         => 'descartado por filtro anti-spam (posible bot: honeypot/rapidez/user-agent). No es un fallo de envío.',
				'throttled'       => 'ya se envió una autorespuesta para este email+proyecto en la ventana configurada.',
			);
			if ( $reason === 'send_error' && $msg !== '' ) {
				return $reason . ': ' . $msg . ' → ' . $hints['send_error'];
			}
			if ( $reason === 'send_error' ) {
				// Fallback v2: el error SMTP real lo escribe estandar-leads en esta opción.
				$lerr = get_option( 'estandar_leads_smtp_last_error', array() );
				if ( is_array( $lerr ) && ! empty( $lerr['message'] ) ) {
					return $reason . ': ' . (string) $lerr['message'] . ' (último error de envío del sistema) → ' . $hints['send_error'];
				}
				return $hints['send_error'];
			}
			if ( isset( $hints[ $reason ] ) ) {
				return $hints[ $reason ];
			}
			return $reason !== '' ? $reason : 'error de envío desconocido';
		}
		return 'error de envío';
	}

	/**
	 * PIPELINE_LEAD_V1 — reúne todo lo relevante del recorrido de un lead:
	 * origen/canal, chatbot, formularios, autorespuesta, routing, notificación,
	 * revisión/acción del operador, contacto y clasificación. Cada paso lleva
	 * status (done|pending|missing) para renderizarlo con énfasis en lo que falta.
	 */
	public static function lead_pipeline( int $lead_id ): array
	{
		global $wpdb;
		$lead_id = absint( $lead_id );
		if ( $lead_id <= 0 || ! isset( $wpdb ) ) {
			return array();
		}

		$lead = (array) $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}es_leads WHERE id = %d", $lead_id ), ARRAY_A );
		if ( empty( $lead ) ) {
			return array();
		}

		$subs = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, submitted_at, utm_source, utm_medium, utm_campaign, landing_url,
			        referrer, session_id, source_plugin, form_fields_json
			 FROM {$wpdb->prefix}es_lead_submissions
			 WHERE lead_id = %d ORDER BY submitted_at ASC, id ASC", $lead_id ), ARRAY_A );

		$events = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT event_type, metadata, created_at, operator_id, to_status
			 FROM {$wpdb->prefix}es_lead_events
			 WHERE lead_id = %d ORDER BY created_at ASC, id ASC", $lead_id ), ARRAY_A );

		$contacts = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT channel, outcome, notes, contacted_at, operator_id
			 FROM {$wpdb->prefix}es_lead_contacts
			 WHERE lead_id = %d ORDER BY contacted_at ASC, id ASC", $lead_id ), ARRAY_A );

		// ── Chatbot: capture (por sesión) + legacy (por identidad) ────────
		$chat = array( 'messages' => 0, 'conversations' => 0, 'first' => '', 'last' => '' );
		$sessions = array();
		foreach ( $subs as $s ) {
			$sid = (string) ( $s['session_id'] ?? '' );
			if ( $sid !== '' ) {
				$sessions[ $sid ] = true;
			}
		}
		$chat_sessions = array_keys( $sessions );

		if ( $chat_sessions ) {
			$tbl = $wpdb->prefix . 'estandar_capture_chat_messages';
			if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
				$in  = implode( ',', array_fill( 0, count( $chat_sessions ), '%s' ) );
				$row = (array) $wpdb->get_row( $wpdb->prepare(
					"SELECT COUNT(*) AS n, MIN(created_at) AS mn, MAX(created_at) AS mx
					 FROM {$tbl} WHERE session_key IN ({$in})", $chat_sessions ), ARRAY_A );
				if ( $row ) {
					$chat['messages'] += (int) $row['n'];
					if ( (string) $row['mn'] !== '' && ( $chat['first'] === '' || $row['mn'] < $chat['first'] ) ) { $chat['first'] = (string) $row['mn']; }
					if ( (string) $row['mx'] !== '' && ( $chat['last'] === '' || $row['mx'] > $chat['last'] ) ) { $chat['last'] = (string) $row['mx']; }
				}
			}
		}
		// Conversaciones del chatbot legacy (por identidad email/phone).
		// v2-first: prefiere el espejo estandar_capture_legacy_conversations
		// (herencia de estandar-chatbot) con fallback a ecb_conversations.
		$conv_tbl = '';
		if ( class_exists( 'Estandar\\Capture\\ChatbotAdoption' ) ) {
			$conv_tbl = \Estandar\Capture\ChatbotAdoption::source_table( 'conversations' );
		}
		if ( $conv_tbl === '' ) {
			$legacy_conv = $wpdb->prefix . 'ecb_conversations';
			$conv_tbl = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_conv ) ) === $legacy_conv ) ? $legacy_conv : '';
		}
		if ( $conv_tbl !== '' ) {
			$w = array();
			$v = array();
			$email = (string) ( $lead['email'] ?? '' );
			$phone = (string) ( $lead['phone'] ?? '' );
			if ( $email !== '' ) { $w[] = 'lead_email = %s'; $v[] = $email; }
			if ( $phone !== '' ) { $w[] = 'lead_phone = %s'; $v[] = $phone; }
			if ( $w ) {
				$row = (array) $wpdb->get_row( $wpdb->prepare(
					"SELECT COUNT(*) AS n, MIN(first_message_at) AS mn, MAX(last_message_at) AS mx
					 FROM {$conv_tbl} WHERE " . implode( ' OR ', $w ), $v ), ARRAY_A );
				if ( $row ) {
					$chat['conversations'] += (int) $row['n'];
					if ( (string) $row['mn'] !== '' && ( $chat['first'] === '' || $row['mn'] < $chat['first'] ) ) { $chat['first'] = (string) $row['mn']; }
					if ( (string) $row['mx'] !== '' && ( $chat['last'] === '' || $row['mx'] > $chat['last'] ) ) { $chat['last'] = (string) $row['mx']; }
				}
			}
		}

		// ── Analytics de sesión (opcional) ────────────────────────────────
		// v2-first: prefiere la tabla espejo estandar_insights_analytics_sessions
		// (herencia de estandar-analytics) con fallback a la tabla legacy ea_sessions.
		$analytics = array();
		if ( $chat_sessions ) {
			$ea = '';
			if ( class_exists( 'Estandar\\Insights\\Analytics' ) ) {
				$ea = \Estandar\Insights\Analytics::source_table( 'sessions' );
			}
			if ( $ea === '' ) {
				$legacy = $wpdb->prefix . 'ea_sessions';
				$ea = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) === $legacy ) ? $legacy : '';
			}
			if ( $ea !== '' ) {
				$in = implode( ',', array_fill( 0, count( $chat_sessions ), '%s' ) );
				$analytics = (array) $wpdb->get_row( $wpdb->prepare(
					"SELECT SUM(page_count) AS pageviews, SUM(duration) AS duration_sec,
					        MIN(first_seen) AS first_seen, MAX(last_activity) AS last_activity
					 FROM {$ea} WHERE session_id IN ({$in})", $chat_sessions ), ARRAY_A );
			}
		}

		// Motivos de recontacto declarados (REPEAT_REASON_V1). Se guardan en la
		// captura (estandar_capture_submissions.repeat_reason / repeat_note) y
		// NO en el CRM es_lead_submissions; se exponen aquí por identidad email.
		$repeats = array();
		$cap_tbl = $wpdb->prefix . 'estandar_capture_submissions';
		$email_n = sanitize_email( (string) ( $lead['email'] ?? '' ) );
		if ( $email_n !== '' && (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cap_tbl ) ) === $cap_tbl ) {
			$cap_rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, repeat_reason, repeat_note, created_at
				 FROM {$cap_tbl} WHERE email_normalized = %s AND repeat_reason <> ''
				 ORDER BY id DESC", $email_n ), ARRAY_A );
			foreach ( $cap_rows as $cr ) {
				$repeats[] = array(
					'capture_id' => (int) $cr['id'],
					'code'       => (string) $cr['repeat_reason'],
					'note'       => (string) $cr['repeat_note'],
					'created_at' => (string) $cr['created_at'],
				);
			}
		}

		return array(
			'id'          => $lead_id,
			'lead'        => $lead,
			'submissions' => $subs,
			'events'      => $events,
			'contacts'    => $contacts,
			'chatbot'     => $chat,
			'analytics'   => $analytics,
			'repeats'     => $repeats,
			'steps'       => self::pipeline_steps( $lead, $subs, $events, $contacts, $chat ),
		);
	}

	/** Computa los pasos del pipeline con su estado (done|pending|missing). */
	private static function pipeline_steps( array $lead, array $subs, array $events, array $contacts, array $chat ): array
	{
		$has = static function ( string $type ) use ( $events ): bool {
			foreach ( $events as $e ) {
				if ( (string) $e['event_type'] === $type ) {
					return true;
				}
			}
			return false;
		};
		$first_of = static function ( array $types ) use ( $events ): ?array {
			foreach ( $events as $e ) {
				if ( in_array( (string) $e['event_type'], $types, true ) ) {
					return $e;
				}
			}
			return null;
		};
		$steps = array();

		// 1. Origen / canal de captura.
		$first    = $subs[0] ?? array();
		$source   = (string) ( $first['utm_source'] ?? ( $lead['first_utm_source'] ?? '' ) );
		$medium   = (string) ( $first['utm_medium'] ?? ( $lead['first_utm_medium'] ?? '' ) );
		$campaign = (string) ( $first['utm_campaign'] ?? ( $lead['first_utm_campaign'] ?? '' ) );
		$referrer = (string) ( $first['referrer'] ?? ( $lead['first_referrer'] ?? '' ) );
		$landing  = (string) ( $first['landing_url'] ?? ( $lead['first_landing_url'] ?? '' ) );
		$steps[] = array(
			'id'     => 'origen',
			'label'  => 'Origen / canal de captura',
			'status' => ( $source !== '' || $referrer !== '' || $landing !== '' ) ? 'done' : 'skip',
			'detail' => trim( ( $source !== '' || $referrer !== '' || $landing !== '' )
				? self::pipeline_channel( $source, $medium, $referrer )
					. ( $campaign !== '' ? ' · ' . $campaign : '' )
					. ( $source !== '' ? ' (' . $source . '/' . $medium . ')' : '' )
				: 'Sin datos de origen' ),
		);

		// 2. Chatbot — "no lo usó" es gris, no un fallo.
		if ( (int) $chat['messages'] > 0 || (int) $chat['conversations'] > 0 ) {
			$parts = array();
			if ( (int) $chat['messages'] > 0 ) { $parts[] = (int) $chat['messages'] . ' mensajes'; }
			if ( (int) $chat['conversations'] > 0 ) { $parts[] = (int) $chat['conversations'] . ' conversación(es)'; }
			if ( $chat['last'] !== '' ) { $parts[] = 'última ' . self::fecha_hora( $chat['last'] ); }
			$steps[] = array( 'id' => 'chatbot', 'label' => 'Chatbot', 'status' => 'done',
				'detail' => 'Interactuó con el chatbot · ' . implode( ' · ', $parts ) );
		} else {
			$steps[] = array( 'id' => 'chatbot', 'label' => 'Chatbot', 'status' => 'skip',
				'detail' => 'No usó el chatbot (capturó por otro canal)' );
		}

		// 3. Formulario — se usa uno u otro canal; sin envíos es gris.
		$n = count( $subs );
		if ( $n > 0 ) {
			$steps[] = array( 'id' => 'form', 'label' => 'Formulario', 'status' => 'done',
				'detail' => $n . ( $n === 1 ? ' envío' : ' envíos' )
					. ( $n > 1 ? ' — el lead recontactó ' . ( $n - 1 ) . ' vez/veces' : '' ) );
		} else {
			$steps[] = array( 'id' => 'form', 'label' => 'Formulario', 'status' => 'skip',
				'detail' => 'No se registró envío de formulario' );
		}

		// 4. Email de confirmación (autoresponse).
		$sent   = $first_of( array( 'autoresponse_email_sent' ) );
		$failed = $has( 'autoresponse_email_failed' );
		if ( $failed && ! $sent ) {
			$steps[] = array( 'id' => 'autoresponse', 'label' => 'Email de confirmación', 'status' => 'missing',
				'detail' => '⚠ Falló el envío del correo de confirmación · ' . self::autoresponse_failure_detail( $events ) );
		} elseif ( $sent ) {
			$opened  = $first_of( array( 'autoresponse_email_opened', 'autoresponse_email_open_signal' ) );
			$clicked = $first_of( array( 'autoresponse_link_click', 'autoresponse_engaged', 'autoresponse_pdf_clicked' ) );
			$steps[] = array( 'id' => 'autoresponse', 'label' => 'Email de confirmación', 'status' => 'done',
				'detail' => 'Enviado'
					. ( $opened ? ' · abierto ' . self::fecha_hora( (string) $opened['created_at'] ) : ' · el lead aún no lo abre' )
					. ( $clicked ? ' · hizo clic ' . self::fecha_hora( (string) $clicked['created_at'] ) : '' ) );
		} else {
			$steps[] = array( 'id' => 'autoresponse', 'label' => 'Email de confirmación', 'status' => 'skip',
				'detail' => 'Sin telemetría de autorespuesta (no enviado / omitido)' );
		}

		// 5. Routing.
		$assigned = $first_of( array( 'operator_assigned' ) );
		if ( $has( 'routing_unresolved' ) ) {
			$steps[] = array( 'id' => 'routing', 'label' => 'Routing', 'status' => 'missing',
				'detail' => '⚠ Routing sin resolver — el lead no fue ruteado a ningún operador' );
		} elseif ( $assigned ) {
			$steps[] = array( 'id' => 'routing', 'label' => 'Routing', 'status' => 'done',
				'detail' => 'Ruteado a ' . ( (string) ( $lead['assigned_operator_name'] ?? '' ) !== ''
					? (string) $lead['assigned_operator_name']
					: '#' . (string) ( $assigned['operator_id'] ?? '' ) ) );
		} else {
			$steps[] = array( 'id' => 'routing', 'label' => 'Routing', 'status' => 'missing',
				'detail' => 'Sin operador asignado' );
		}

		// 6. Notificación al operador.
		$notified = $first_of( array( 'operator_notified' ) );
		if ( $notified ) {
			$steps[] = array( 'id' => 'notify', 'label' => 'Notificación al operador', 'status' => 'done',
				'detail' => 'Notificado ' . self::fecha_hora( (string) $notified['created_at'] ) );
		} elseif ( $has( 'operator_notification_deferred' ) ) {
			$steps[] = array( 'id' => 'notify', 'label' => 'Notificación al operador', 'status' => 'pending',
				'detail' => 'Diferida (fuera de horario comercial)' );
		} elseif ( $has( 'operator_notification_consolidated' ) ) {
			$steps[] = array( 'id' => 'notify', 'label' => 'Notificación al operador', 'status' => 'pending',
				'detail' => 'Consolidada en otro aviso' );
		} elseif ( $has( 'notification_skipped_by_policy' ) || $has( 'routing_skipped_by_policy' ) ) {
			$steps[] = array( 'id' => 'notify', 'label' => 'Notificación al operador', 'status' => 'skip',
				'detail' => 'Omitida por política (decisión del sistema)' );
		} else {
			$steps[] = array( 'id' => 'notify', 'label' => 'Notificación al operador', 'status' => 'skip',
				'detail' => 'Sin telemetría de notificación' );
		}

		// 7. Operador revisó el lead — pendiente de la operadora, no un fallo.
		$seen = $first_of( array( 'lead_viewed', 'workspace_opened', 'lead_list_opened' ) );
		$steps[] = $seen
			? array( 'id' => 'seen', 'label' => 'Operador revisó el lead', 'status' => 'done',
				'detail' => self::pipeline_event_label( (string) $seen['event_type'] ) . ' ' . self::fecha_hora( (string) $seen['created_at'] ) )
			: array( 'id' => 'seen', 'label' => 'Operador revisó el lead', 'status' => 'pending',
				'detail' => 'El operador aún no abre este lead', 'highlight' => true );

		// 8. Operador accionó (whatsapp / email / teléfono).
		$acted = $first_of( array( 'whatsapp_clicked', 'email_clicked', 'call_clicked',
			'operator_clicked_whatsapp', 'operator_clicked_email', 'operator_clicked_call', 'operator_clicked_lead' ) );
		$steps[] = $acted
			? array( 'id' => 'act', 'label' => 'Operador accionó', 'status' => 'done',
				'detail' => self::pipeline_event_label( (string) $acted['event_type'] ) . ' ' . self::fecha_hora( (string) $acted['created_at'] ) )
			: array( 'id' => 'act', 'label' => 'Operador accionó', 'status' => 'pending',
				'detail' => 'Pendiente: abrir WhatsApp / email / teléfono del lead', 'highlight' => true );

		// 9. Contacto con el lead — pendiente de la operadora.
		$attempts      = (int) ( $lead['contact_attempts'] ?? 0 );
		$first_contact = (string) ( $lead['first_contact_at'] ?? '' );
		if ( $attempts > 0 || $first_contact !== '' || ! empty( $contacts ) ) {
			$parts = array();
			if ( $attempts > 0 ) { $parts[] = $attempts . ' intento(s) de contacto'; }
			if ( $first_contact !== '' ) { $parts[] = 'primero ' . self::fecha_hora( $first_contact ); }
			if ( ! empty( $contacts ) ) { $parts[] = count( $contacts ) . ' contacto(s) registrado(s)'; }
			$steps[] = array( 'id' => 'contact', 'label' => 'Contacto con el lead', 'status' => 'done',
				'detail' => implode( ' · ', $parts ) );
		} else {
			$steps[] = array( 'id' => 'contact', 'label' => 'Contacto con el lead', 'status' => 'pending',
				'detail' => 'Nunca contactado — pendiente de la operadora', 'highlight' => true );
		}

		// 10. Clasificación — pendiente de la operadora.
		$classified = (int) ( $lead['feedback_completed'] ?? 0 ) === 1 || (string) ( $lead['last_classified_at'] ?? '' ) !== '';
		if ( $classified ) {
			$steps[] = array( 'id' => 'classify', 'label' => 'Clasificación', 'status' => 'done',
				'detail' => 'Calificado · ' . (string) ( $lead['status'] ?? '' )
					. ( (string) ( $lead['quality'] ?? '' ) !== '' ? ' · ' . (string) $lead['quality'] : '' ) );
		} else {
			$steps[] = array( 'id' => 'classify', 'label' => 'Clasificación', 'status' => 'pending',
				'detail' => 'Pendiente de clasificación por el operador', 'highlight' => true );
		}

		return $steps;
	}

	/** Página HTML de la ficha del pipeline de un lead. */
	public static function render_lead_ficha( array $p ): string
	{
		$esc = static function ( string $v ): string {
			return esc_html( $v );
		};
		$lead = $p['lead'];

		// Enlace de vuelta conservando filtros + access.
		$back_qs = array();
		foreach ( array( 'access', 'from', 'to', 'project', 'operator', 'name', 'orderby', 'order' ) as $k ) {
			$v = (string) ( $_GET[ $k ] ?? '' );
			if ( $v !== '' ) {
				$back_qs[ $k ] = $v;
			}
		}
		$back = home_url( '/' . self::PANEL_SLUG . '/' ) . ( $back_qs ? '?' . http_build_query( $back_qs ) : '' );

		$operator = self::current_user_label();
		$right = $operator !== ''
			? '<span>👤 ' . $esc( $operator ) . '</span><a href="' . esc_url( home_url( '/' . self::PANEL_SLUG . '/?logout=1' ) ) . '" style="color:#fff;">Salir</a>'
			: '';

		// Banner de lo que falta / está pendiente (solo acciones y fallos reales).
		$alerts_html = '';
		$alerts = array();
		foreach ( $p['steps'] as $st ) {
			if ( in_array( $st['status'], array( 'missing', 'pending' ), true ) ) {
				$alerts[] = $st;
			}
		}
		if ( $alerts ) {
			$alerts_html = '<div class="card"><h3>⚠ Lo que falta / está pendiente</h3>';
			foreach ( $alerts as $st ) {
				$color = $st['status'] === 'missing' ? '#b32d2e' : '#b26e00';
				$alerts_html .= '<div class="alert" style="border-left:4px solid ' . $color . ';background:#fcf9f2;">'
					. '<strong>' . $esc( $st['label'] ) . '</strong> · ' . $esc( (string) $st['detail'] ) . '</div>';
			}
			$alerts_html .= '</div>';
		} else {
			$alerts_html = '<div class="card ok"><strong>✔ Todo el pipeline está cubierto.</strong></div>';
		}

		// Pasos del pipeline.
		$icons = array(
			'origen' => '🎯', 'chatbot' => '💬', 'form' => '📋', 'autoresponse' => '📧',
			'routing' => '🧭', 'notify' => '🔔', 'seen' => '👁', 'act' => '🖱',
			'contact' => '📞', 'classify' => '🏷',
		);
		$steps_html = '';
		foreach ( $p['steps'] as $st ) {
			$color = array( 'done' => '#00a32a', 'pending' => '#b26e00', 'missing' => '#b32d2e', 'skip' => '#8c8f94' )[ $st['status'] ] ?? '#8c8f94';
			$tag   = array( 'done' => 'Hecho', 'pending' => 'Pendiente', 'missing' => 'Falta', 'skip' => 'No usado' )[ $st['status'] ] ?? '—';
			$steps_html .= '<div class="step"><div class="step-icon">' . ( $icons[ $st['id'] ] ?? '•' ) . '</div>'
				. '<div class="step-body"><div class="step-title">' . $esc( $st['label'] ) . '</div>'
				. '<div class="step-detail">' . $esc( (string) $st['detail'] ) . '</div></div>'
				. '<div class="step-chip" style="background:' . $color . ';">' . $esc( $tag ) . '</div></div>';
		}

		// Timeline de eventos.
		$timeline = '';
		foreach ( $p['events'] as $ev ) {
			$label = self::pipeline_event_label( (string) $ev['event_type'] );
			if ( (string) $ev['event_type'] === 'autoresponse_email_failed' ) {
				$rs = self::autoresponse_failure_reason( array( $ev ) );
				if ( $rs !== '' ) {
					$label .= ' · ' . $rs;
				}
			}
			$timeline .= '<div class="tl"><span class="tl-t">' . $esc( self::fecha_hora( (string) $ev['created_at'] ) ) . '</span>'
				. '<span class="tl-l">' . $esc( $label ) . '</span></div>';
		}
		if ( $timeline === '' ) {
			$timeline = '<p class="muted">Sin eventos registrados.</p>';
		}

		// Contactos registrados.
		$contacts_html = '';
		foreach ( $p['contacts'] as $c ) {
			$contacts_html .= '<div class="tl"><span class="tl-t">' . $esc( self::fecha_hora( (string) $c['contacted_at'] ) ) . '</span>'
				. '<span class="tl-l"><strong>' . $esc( (string) $c['channel'] ) . '</strong> · ' . $esc( (string) $c['outcome'] )
				. ( (string) $c['notes'] !== '' ? ' · ' . $esc( (string) $c['notes'] ) : '' ) . '</span></div>';
		}
		if ( $contacts_html === '' ) {
			$contacts_html = '<p class="muted">Sin contactos registrados.</p>';
		}

		// Formularios enviados.
		$subs_html = '';
		// Recontactos declarados: motivo que el lead eligió al reenviar
		// (REPEAT_REASON_V1, vive en estandar_capture_submissions).
		if ( ! empty( $p['repeats'] ) ) {
			$subs_html .= '<h4 style="margin:0 0 6px;font-size:13px;color:#b26e00;">🔄 Recontactos declarados</h4>';
			foreach ( $p['repeats'] as $rp ) {
				$subs_html .= '<div class="tl"><span class="tl-t">#' . (int) $rp['capture_id'] . ' · ' . $esc( self::fecha_hora( (string) $rp['created_at'] ) ) . '</span>'
					. '<span class="tl-l"><strong>' . $esc( self::repeat_reason_label( (string) $rp['code'] ) ) . '</strong>'
					. ( (string) $rp['note'] !== '' ? ' · ' . $esc( (string) $rp['note'] ) : '' ) . '</span></div>';
			}
		}
		foreach ( $p['submissions'] as $s ) {
			$project = '';
			$fj = json_decode( (string) ( $s['form_fields_json'] ?? '' ), true );
			if ( is_array( $fj ) ) {
				$project = (string) ( $fj['project'] ?? $fj['project_slug'] ?? '' );
			}
			$subs_html .= '<div class="tl"><span class="tl-t">#' . (int) $s['id'] . ' · ' . $esc( self::fecha_hora( (string) $s['submitted_at'] ) ) . '</span>'
				. '<span class="tl-l">' . ( $project !== '' ? $esc( $project ) . ' · ' : '' )
				. $esc( (string) ( $s['utm_source'] ?? '' ) )
				. ( (string) ( $s['utm_medium'] ?? '' ) !== '' ? '/' . $esc( (string) $s['utm_medium'] ) : '' )
				. ( (string) ( $s['utm_campaign'] ?? '' ) !== '' ? ' · campaña ' . $esc( (string) $s['utm_campaign'] ) : '' )
				. '</span></div>';
		}
		if ( $subs_html === '' ) {
			$subs_html = '<p class="muted">Sin submissions.</p>';
		}

		// Datos del lead.
		$meta = array(
			'Estado'           => (string) ( $lead['status'] ?? '' ),
			'Temperatura'      => (string) ( $lead['temperature'] ?? '' ),
			'Prioridad'        => (string) ( $lead['priority'] ?? '' ),
			'Calidad'          => (string) ( $lead['quality'] ?? '' ),
			'Operador'         => (string) ( $lead['assigned_operator_name'] ?? '' ),
			'Primera captura'  => self::fecha_hora( (string) ( $lead['first_seen_at'] ?? '' ) ),
			'Última actividad' => self::fecha_hora( (string) ( $lead['last_seen_at'] ?? '' ) ),
			'Score'            => (string) ( $lead['behavioral_score'] ?? '' ),
		);
		$meta_html = '';
		foreach ( $meta as $k => $v ) {
			$meta_html .= '<div class="kv"><span class="kv-k">' . $esc( $k ) . '</span><span class="kv-v">' . $esc( $v !== '' ? $v : '—' ) . '</span></div>';
		}
		if ( ! empty( $p['analytics'] ) && (int) ( $p['analytics']['pageviews'] ?? 0 ) > 0 ) {
			$meta_html .= '<div class="kv"><span class="kv-k">Sesión</span><span class="kv-v">'
				. (int) $p['analytics']['pageviews'] . ' páginas · '
				. gmdate( 'i\\m s\\s', (int) ( $p['analytics']['duration_sec'] ?? 0 ) ) . '</span></div>';
		}

		// ── Acciones de la ficha: contactar + calificar (feedback al sistema) ──
		$qs_base = array();
		foreach ( array( 'access', 'from', 'to', 'project', 'operator', 'name', 'orderby', 'order' ) as $k ) {
			$v = (string) ( $_GET[ $k ] ?? '' );
			if ( $v !== '' ) {
				$qs_base[ $k ] = $v;
			}
		}
		$act = static function ( string $action ) use ( $p, $qs_base ) {
			$qs = $qs_base;
			$qs['lead'] = (int) $p['id'];
			if ( $action === 'feedback' ) {
				$qs['feedback'] = '1';
			} else {
				$qs['contact'] = $action;
			}
			return '?' . http_build_query( $qs );
		};
		$lead_phone = (string) ( $lead['phone'] ?? '' );
		$lead_email = (string) ( $lead['email'] ?? '' );
		$actions_html = '<div class="actions">'
			. '<div class="actions-row">'
			. ( $lead_phone !== ''
				? '<a class="btn act-wa" href="' . esc_url( $act( 'whatsapp' ) ) . '">📲 Contactar ahora</a>'
				: '<span class="muted">Sin teléfono para contactar</span>' )
			. ( $lead_phone !== '' ? ' <a class="btn act-call" href="' . esc_url( $act( 'call' ) ) . '" title="Llamar al lead">📞</a>' : '' )
			. ( $lead_email !== '' ? ' <a class="btn act-mail" href="' . esc_url( $act( 'email' ) ) . '" title="Enviar email al lead">✉️</a>' : '' )
			. '</div>'
			. '<div class="actions-row"><a class="btn act-feedback" href="' . esc_url( $act( 'feedback' ) ) . '">🏷 Calificar calidad del lead</a>'
			. ' <a class="btn act-edit" href="' . esc_url( '?' . http_build_query( $qs_base + array( 'lead' => (int) $p['id'], 'edit' => '1' ) ) ) . '" title="Editar datos del lead">✏️ Editar lead</a>'
			. ' <a class="btn act-quote" href="' . esc_url( '?' . http_build_query( $qs_base + array( 'lead' => (int) $p['id'], 'quote' => 'new' ) ) ) . '" title="Crear y enviar una cotización al cliente">💵 Cotizar</a></div>'
			. '<p class="actions-note">Es imprescindible dar feedback al sistema: cada contacto y cada clasificación alimenta el CRM y las métricas comerciales. Sin eso trabajamos a ciegas y la empresa pierde visibilidad sobre lo que pasa con sus leads.</p>'
			. '</div>';

		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ficha del lead #' . (int) $p['id'] . '</title>'
			. '<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f6f7f7;color:#1d2327;}
.wrap{max-width:1180px;margin:0 auto;padding:24px 16px;}
.card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin-bottom:16px;}
.card h3{margin:0 0 12px;font-size:14px;color:#1d2327;}
.card.ok{border-color:#00a32a;background:#f0f8f1;color:#01651f;}
.grid{display:grid;grid-template-columns:1.2fr .8fr;gap:16px;}
@media(max-width:860px){.grid{grid-template-columns:1fr;}}
.alert{padding:8px 10px;border-radius:6px;margin-bottom:8px;font-size:13px;background:#fff;}
.step{display:flex;align-items:center;gap:12px;padding:9px 10px;border-bottom:1px solid #f0f0f1;}
.step:last-child{border-bottom:none;}
.step-icon{font-size:18px;width:26px;text-align:center;}
.step-body{flex:1;min-width:0;}
.step-title{font-size:13px;font-weight:700;}
.step-detail{font-size:12px;color:#646970;margin-top:2px;}
.step-chip{color:#fff;font-size:11px;font-weight:700;border-radius:99px;padding:2px 9px;white-space:nowrap;}
.tl{display:flex;gap:10px;padding:5px 0;border-bottom:1px dashed #f0f0f1;font-size:12.5px;}
.tl:last-child{border-bottom:none;}
.tl-t{color:#8c8f94;white-space:nowrap;flex-shrink:0;}
.tl-l{flex:1;}
.kv{display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px dashed #f0f0f1;font-size:13px;}
.kv-k{color:#646970;}
.kv-v{font-weight:600;text-align:right;}
.muted{color:#8c8f94;font-size:12px;}
.lead-title{font-size:20px;font-weight:800;color:#1d2327;margin:0 0 4px;}
.lead-sub{font-size:12px;color:#646970;margin:0 0 14px;}
.lead-sub a.lead-mail{color:#2271b1;text-decoration:none;font-weight:600;}
.lead-sub a.lead-mail:hover{text-decoration:underline;}
.comunicarse{position:relative;display:inline-block;vertical-align:middle;}
.comunicarse summary{list-style:none;display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;margin-left:4px;}
.comunicarse summary::-webkit-details-marker{display:none;}
.comunicarse summary:hover{filter:brightness(.95);}
.comunicarse[open] summary{border-radius:6px 6px 0 0;}
.comunicarse-menu{position:absolute;right:0;top:calc(100% + 2px);background:#fff;border:1px solid #dcdcde;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.14);min-width:200px;padding:6px;z-index:30;}
.comunicarse-menu a{display:block;padding:9px 12px;color:#1d2327;text-decoration:none;font-size:13px;border-radius:6px;}
.comunicarse-menu a:hover{background:#f0f6fc;color:#2271b1;}
.btn{display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600;}
.btn.sm{padding:5px 12px;font-size:12px;}
</style></head><body><div class="wrap">'
			. self::brand_top( 'Ficha del lead', $right )
			. '<p><a class="btn sm" href="' . esc_url( $back ) . '">← Volver al panel</a></p>'
			. '<h2 class="lead-title">#' . (int) $p['id'] . ' — ' . $esc( (string) ( $lead['name'] ?? '' ) ) . '</h2>'
			. '<p class="lead-sub">'
			. ( $lead_email !== ''
				? '<a class="lead-mail" href="mailto:' . esc_attr( $lead_email ) . '" title="Escribir al lead">' . $esc( $lead_email ) . '</a>'
				: '<span class="muted">sin email</span>' )
			. ( $lead_phone !== '' ? ' · ' . $esc( $lead_phone ) : '' )
			. ( $lead_phone !== ''
				? '<details class="comunicarse"><summary>📞 Comunicarse ▾</summary><div class="comunicarse-menu">'
					. '<a href="' . esc_url( $act( 'whatsapp' ) ) . '">💬 Mensaje de WhatsApp</a>'
					. '<a href="' . esc_url( $act( 'call' ) ) . '">📞 Llamada</a>'
					. ( $lead_email !== '' ? '<a href="' . esc_url( $act( 'email' ) ) . '">✉️ Correo</a>' : '' )
					. '</div></details>'
				: '' )
			. '</p>'
			. $actions_html
			. '<div class="grid"><div class="card"><h3>Pipeline</h3>' . $steps_html . '</div><div class="card"><h3>Datos del lead</h3>' . $meta_html . '</div></div>'
			. $alerts_html
			. '<div class="grid"><div class="card"><h3>Timeline de eventos</h3>' . $timeline . '</div><div class="card"><h3>Contactos</h3>' . $contacts_html . '</div></div>'
			. '<div class="card"><h3>Formularios enviados</h3>' . $subs_html . '</div>'
			. self::brand_footer()
			. '</div></body></html>';
	}

	// ── Acciones de la ficha: contactar / calificar ─────────────────────

	/** Teléfono en formato de marcación (56…). Usa el normalizador de leads si existe. */
	private static function phone_digits( string $phone ): string {
		if ( class_exists( '\Estandar_Leads_Normalizer' ) ) {
			return \Estandar_Leads_Normalizer::phone_storage( $phone );
		}
		$d = preg_replace( '/\D+/', '', (string) $phone );
		if ( strpos( $d, '00' ) === 0 ) {
			$d = substr( $d, 2 );
		}
		if ( $d !== '' && $d[0] === '0' ) {
			$d = '56' . substr( $d, 1 );
		}
		return $d;
	}

	/** PURE: destino de contacto (wa.me / tel: / mailto:) para un lead y canal. */
	public static function contact_target( array $lead, string $channel ): string {
		$phone = (string) ( $lead['phone'] ?? '' );
		$email = (string) ( $lead['email'] ?? '' );
		if ( $channel === 'email' && is_email( $email ) ) {
			return 'mailto:' . sanitize_email( $email ) . '?subject=' . rawurlencode( 'Contacto desde panel: ' . (string) ( $lead['name'] ?? '' ) );
		}
		if ( $channel === 'call' && $phone !== '' ) {
			return 'tel:+' . self::phone_digits( $phone );
		}
		if ( $channel === 'whatsapp' && $phone !== '' ) {
			return 'https://wa.me/' . self::phone_digits( $phone );
		}
		return '';
	}

	/** ID del operador autenticado por PIN (0 si ninguno / sin tabla). */
	private static function current_operator_id(): int {
		$name = self::current_operator();
		if ( $name === '' ) {
			return 0;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'es_operators';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s LIMIT 1", $name ) );
	}

	/** Acción "Contactar ahora" desde la ficha: registra el clic y redirige al canal. */
	public static function handle_contact_action( int $lead_id, string $channel ): void {
		$channel = sanitize_key( $channel );
		if ( ! in_array( $channel, array( 'whatsapp', 'email', 'call' ), true ) ) {
			wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/?lead=' . $lead_id ) );
			exit;
		}
		global $wpdb;
		$lead = (array) $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, email, phone, project_slug FROM {$wpdb->prefix}es_leads WHERE id = %d", $lead_id ), ARRAY_A );
		if ( empty( $lead ) ) {
			status_header( 404 );
			exit;
		}
		// Registrar la acción en el CRM para no quedarnos ciegos. Preferimos el
		// puente de estandar-leads si existe (mantiene scoring/journey); si no,
		// escribimos el evento directo en es_lead_events (v2, sin dependencia).
		$operator_id = self::current_operator_id();
		if ( class_exists( '\Estandar_Leads_Portal' ) ) {
			$map = array( 'whatsapp' => 'whatsapp_clicked', 'email' => 'email_clicked', 'call' => 'call_clicked' );
			\Estandar_Leads_Portal::log_portal_event( $lead_id, $map[ $channel ], array(
				'project'       => sanitize_key( (string) ( $lead['project_slug'] ?? '' ) ),
				'workspace'     => 'panel-leads',
				'submission_id' => 0,
			), $operator_id );
		} else {
			self::log_contact_event( $lead_id, $channel, $operator_id );
		}
		$target = self::contact_target( $lead, $channel );
		if ( $target === '' ) {
			wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/?lead=' . $lead_id ) );
			exit;
		}
		wp_redirect( $target );
		exit;
	}

	/**
	 * Registra el clic de contacto directo en es_lead_events (v2, sin depender
	 * de estandar-leads). Usado como fallback cuando el puente legacy no existe.
	 */
	public static function log_contact_event( int $lead_id, string $channel, int $operator_id = 0 ): bool {
		$map = array( 'whatsapp' => 'whatsapp_clicked', 'email' => 'email_clicked', 'call' => 'call_clicked' );
		if ( ! isset( $map[ $channel ] ) ) {
			return false;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || $lead_id <= 0 ) {
			return false;
		}
		$table = $wpdb->prefix . 'es_lead_events';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return false;
		}
		return (bool) $wpdb->insert( $table, array(
			'lead_id'     => $lead_id,
			'operator_id' => $operator_id ?: null,
			'event_type'  => $map[ $channel ],
			'metadata'    => wp_json_encode( array(
				'source'    => 'panel-leads',
				'channel'   => $channel,
				'workspace' => 'panel-leads',
			) ),
			'ip_address'  => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			'user_agent'  => sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			'created_at'  => \Estandar\Core\Time::now(),
		) );
	}

	/** Acción "Calificar calidad del lead": crea el token de feedback y redirige. */
	public static function handle_feedback_action( int $lead_id ): void {
		$lead_id = absint( $lead_id );
		if ( $lead_id <= 0 ) {
			wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) );
			exit;
		}
		$url = '';
		if ( class_exists( '\Estandar_Leads_Tokens' ) ) {
			$token = \Estandar_Leads_Tokens::create( $lead_id, self::current_operator_id() );
			if ( $token !== '' ) {
				$url = \Estandar_Leads_Tokens::url( $token );
			}
		}
		if ( $url === '' ) {
			// Fallback v2: creamos el token de feedback directo (mismo formato de
			// es_feedback_tokens) para no depender del plugin legacy.
			$url = self::create_feedback_url( $lead_id, self::current_operator_id() );
		}
		if ( $url !== '' ) {
			wp_redirect( $url );
			exit;
		}
		// Fallback: detalle del admin de leads (para admins de WP).
		if ( function_exists( 'admin_url' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-leads&view=detail&lead_id=' . $lead_id ) );
			exit;
		}
		wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/?lead=' . $lead_id ) );
		exit;
	}

	/**
	 * Crea un token de feedback directo en es_feedback_tokens (mismo formato que
	 * estandar-leads, mismo secreto de hash) y devuelve la URL de clasificación.
	 */
	public static function create_feedback_url( int $lead_id, int $operator_id = 0 ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || $lead_id <= 0 ) {
			return '';
		}
		$table = $wpdb->prefix . 'es_feedback_tokens';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return '';
		}
		$raw    = bin2hex( function_exists( 'random_bytes' ) ? random_bytes( 32 ) : openssl_random_pseudo_bytes( 32 ) );
		$ok = (bool) $wpdb->insert( $table, array(
			'lead_id'     => $lead_id,
			'operator_id' => $operator_id ?: null,
			'token_hash'  => self::feedback_token_hash( $raw ),
			'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 21 * DAY_IN_SECONDS ),
			'created_at'  => \Estandar\Core\Time::now(),
			'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
		) );
		if ( ! $ok ) {
			return '';
		}
		return add_query_arg( array( 'estandar_feedback' => '1', 'token' => $raw ), home_url( '/' ) );
	}

	// ── Calificación de leads v2 (FEEDBACK_V2) ──────────────────────────────
	// Antes la resolvía estandar-leads (retirado). Este port es autocontenido:
	// mismo motor (es_feedback_tokens / es_leads), sin dependencia legacy.
	// UX mobile-first: misma lógica desde el celular o el computador.

	/** Estatus canónicos para el selector (mismos valores del CRM v1/v2). */
	public static function feedback_statuses(): array
	{
		return array( 'NEW', 'CONTACTED', 'NO_RESPONSE', 'UNREACHABLE', 'QUALIFIED', 'MEETING_BOOKED', 'PROPOSAL_SENT', 'NEGOTIATION', 'CONVERTED', 'CLIENT', 'LOST', 'INVALID' );
	}

	/** Calidades canónicas del lead. */
	public static function feedback_qualities(): array
	{
		return array( 'HOT', 'WARM', 'COLD', 'JUNK' );
	}

	/** HMAC del token (mismo secreto que create_feedback_url: wp_salt auth). */
	private static function feedback_token_hash( string $raw ): string
	{
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'estandar-suite-feedback' );
		return hash_hmac( 'sha256', $raw, (string) $secret );
	}

	/** Crea es_feedback_tokens si no existe (site sin estandar-leads). */
	private static function feedback_ensure_table(): void
	{
		global $wpdb;
		$table = $wpdb->prefix . 'es_feedback_tokens';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned NOT NULL,
			operator_id bigint(20) unsigned DEFAULT NULL,
			token_hash char(64) NOT NULL,
			expires_at datetime NOT NULL,
			used_at datetime DEFAULT NULL,
			last_seen_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY token_hash (token_hash)
		) {$wpdb->get_charset_collate()};" );
	}

	/** Ruta /?estandar_feedback=1&token=… (GET form / POST guarda). */
	public static function maybe_handle_feedback(): void
	{
		if ( empty( $_GET['estandar_feedback'] ) ) {
			return;
		}
		self::feedback_ensure_table();
		$token  = sanitize_text_field( wp_unslash( (string) ( $_GET['token'] ?? '' ) ) );
		$record = self::feedback_record( $token );

		if ( ! $record ) {
			status_header( 200 );
			echo self::render_feedback_message( 'Enlace no disponible', 'Este enlace no es válido o ya expiró. Pide un enlace nuevo desde el panel o reabre el correo de notificación del lead.' );
			exit;
		}

		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			self::feedback_save( $record, $token, $_POST );
		}

		// Marca visto (solo la primera vez por token) y pinta el formulario.
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( $record->last_seen_at === null ) {
			$wpdb = $GLOBALS['wpdb'];
			$wpdb->update( $wpdb->prefix . 'es_feedback_tokens', array( 'last_seen_at' => $now, 'updated_at' => $now ), array( 'id' => absint( $record->token_id ) ) );
		}
		status_header( 200 );
		echo self::render_feedback_form( $record, $token );
		exit;
	}

	/** Registro del token (join lead + último envío + operador). NULL si inválido/expirado. */
	private static function feedback_record( string $token ): ?object
	{
		global $wpdb;
		if ( $token === '' || ! isset( $wpdb ) ) {
			return null;
		}
		$hash = self::feedback_token_hash( $token );
		$row  = $wpdb->get_row( $wpdb->prepare(
			"SELECT t.id AS token_id, t.lead_id, t.operator_id, t.token_hash, t.expires_at, t.used_at, t.last_seen_at,
			        l.status, l.quality, l.name, l.email, l.phone, l.project_slug, l.feedback_completed, l.first_feedback_at, l.latest_operator_id, l.latest_feedback_reason,
			        s.message, s.context_json,
			        o.name AS operator_name
			 FROM {$wpdb->prefix}es_feedback_tokens t
			 INNER JOIN {$wpdb->prefix}es_leads l ON l.id = t.lead_id
			 LEFT JOIN {$wpdb->prefix}es_lead_submissions s ON s.id = (
			   SELECT s2.id FROM {$wpdb->prefix}es_lead_submissions s2
			   WHERE s2.lead_id = l.id ORDER BY s2.submitted_at DESC, s2.id DESC LIMIT 1
			 )
			 LEFT JOIN {$wpdb->prefix}es_operators o ON o.id = t.operator_id
			 WHERE t.token_hash = %s LIMIT 1",
			$hash
		) );
		if ( ! $row || ! hash_equals( (string) $row->token_hash, $hash ) ) {
			return null;
		}
		if ( strtotime( (string) $row->expires_at . ' UTC' ) < time() || $row->used_at !== null ) {
			return null;
		}
		return $row;
	}

	/** Valida y persiste la calificación (es_leads + evento + token usado). */
	private static function feedback_save( object $record, string $token, array $post ): void
	{
		global $wpdb;
		$status  = strtoupper( sanitize_key( wp_unslash( (string) ( $post['status'] ?? '' ) ) ) );
		$quality = strtoupper( sanitize_key( wp_unslash( (string) ( $post['quality'] ?? '' ) ) ) );
		$valid_raw = sanitize_key( wp_unslash( (string) ( $post['is_valid_lead'] ?? '' ) ) );
		$valid   = $valid_raw === 'yes' ? 1 : ( $valid_raw === 'no' ? 0 : null );
		$reason  = sanitize_textarea_field( wp_unslash( (string) ( $post['reason'] ?? '' ) ) );

		$errors = array();
		if ( ! in_array( $status, self::feedback_statuses(), true ) ) {
			$errors[] = 'Selecciona un estado comercial.';
		}
		if ( ! in_array( $quality, self::feedback_qualities(), true ) ) {
			$errors[] = 'Selecciona la calidad del lead.';
		}
		if ( $valid === null ) {
			$errors[] = 'Indica si es un lead válido.';
		}
		if ( $errors ) {
			status_header( 200 );
			echo self::render_feedback_message( 'Falta una respuesta', implode( ' ', $errors ), false );
			exit;
		}

		$now    = \Estandar\Core\Time::now();
		$to     = $status;
		$from   = (string) ( $record->status ?: 'NEW' );
		$evtype = (int) $record->feedback_completed === 1 ? 'status_overridden' : 'status_changed';

		$wpdb->update(
			$wpdb->prefix . 'es_leads',
			array(
				'status'                 => $to,
				'feedback_status'        => 'completed',
				'feedback_completed'     => 1,
				'first_feedback_at'      => (string) $record->first_feedback_at ?: $now,
				'latest_feedback_at'     => $now,
				'latest_operator_id'     => $record->operator_id ?: null,
				'latest_feedback_reason' => $reason,
				'last_classified_at'     => $now,
				'last_classification_operator_id' => $record->operator_id ?: null,
				'is_valid_lead'          => $valid,
				'quality'                => $quality,
				'updated_at'             => $now,
			),
			array( 'id' => absint( $record->lead_id ) ),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		$wpdb->insert( $wpdb->prefix . 'es_lead_events', array(
			'lead_id'     => absint( $record->lead_id ),
			'operator_id' => $record->operator_id ?: null,
			'event_type'  => $evtype,
			'from_status' => $from,
			'to_status'   => $to,
			'reason'      => $reason,
			'metadata'    => wp_json_encode( array( 'source' => 'feedback_v2', 'quality' => $quality, 'is_valid_lead' => $valid ) ),
			'created_at'  => $now,
		), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );

		// Señal de calidad para el ledger de Brain → exportación a Ads (Meta/Google).
		do_action( 'estandar_suite_lead_feedback', absint( $record->lead_id ), $to, $quality, $valid, $reason );

		// Token de un solo uso.
		$wpdb->update( $wpdb->prefix . 'es_feedback_tokens', array( 'used_at' => $now, 'updated_at' => $now ), array( 'id' => absint( $record->token_id ) ) );

		$label = array( 'HOT' => 'Caliente', 'WARM' => 'Tibio', 'COLD' => 'Frío', 'JUNK' => 'Descartado' );
		status_header( 200 );
		echo self::render_feedback_message(
			'¡Gracias por clasificar!',
			'Guardamos: estado <strong>' . esc_html( $to ) . '</strong> · calidad <strong>' . esc_html( (string) ( $label[ $quality ] ?? $quality ) ) . '</strong>. Este feedback ya alimenta el CRM y las métricas comerciales.',
			true
		);
		exit;
	}

	/** Formulario de calificación mobile-first (token = acceso). */
	private static function render_feedback_form( object $record, string $token ): string
	{
		$q = $GLOBALS['wpdb'];
		$ctx = json_decode( (string) ( $record->context_json ?? '' ), true );
		$ctx = is_array( $ctx ) ? $ctx : array();
		$project = (string) ( $record->project_slug ?? '' );
		$quality_labels = array(
			'HOT'  => '🔥 Caliente — interés real',
			'WARM' => '🌤️ Tibio — posible interés',
			'COLD' => '❄️ Frío — bajo interés',
			'JUNK' => '🗑️ Descartado — spam/error',
		);
		// Estados canónicos del CRM con etiqueta legible en español. El VALUE
		// siempre es el código canónico (NEW/CONTACTED/…) que persiste el CRM.
		$status_options = '';
		foreach ( self::feedback_statuses() as $st ) {
			$label = self::lead_status_label( $st );
			$status_options .= '<option value="' . esc_attr( $st ) . '"' . selected( strtoupper( (string) ( $record->status ?? '' ) ), $st, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$quality_chips = '';
		foreach ( self::feedback_qualities() as $qq ) {
			$quality_chips .= '<label class="chip"><input type="radio" name="quality" value="' . esc_attr( $qq ) . '"' . checked( strtoupper( (string) ( $record->quality ?? '' ) ), $qq, false ) . '> <span>' . esc_html( (string) ( $quality_labels[ $qq ] ?? $qq ) ) . '</span></label>';
		}
		$message = (string) ( $record->message ?? '' );
		if ( $message === '' && isset( $ctx['message'] ) ) {
			$message = (string) $ctx['message'];
		}

		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Calificar lead</title>'
			. '<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f3f4f6;color:#1d2327;padding:16px 14px 110px;}
.wrap{max-width:640px;margin:0 auto;}
.card{background:#fff;border:1px solid #e0e1e3;border-radius:14px;padding:18px;margin-bottom:14px;}
h1{font-size:20px;margin:2px 0 14px;font-weight:800;}
.sub{color:#5c6066;font-size:13px;margin:2px 0 14px;}
.kv{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px dashed #ececec;font-size:14px;}
.kv:last-child{border:none;}
.kv-k{color:#5c6066;} .kv-v{font-weight:600;text-align:right;}
.msg{background:#fff7f2;border-left:4px solid #f95b1f;border-radius:8px;padding:12px 14px;font-size:14px;color:#1d2327;margin:0 0 14px;}
.q{font-size:14px;font-weight:700;margin:0 0 8px;color:#1d2327;}
.field{margin-bottom:16px;}
.chips{display:flex;flex-wrap:wrap;gap:8px;}
.chip{flex:1 1 100%;}
.chip input{position:absolute;opacity:0;pointer-events:none;}
.chip span{display:block;border:2px solid #d4d5d7;border-radius:12px;padding:14px 14px;font-size:15px;font-weight:600;text-align:center;background:#fff;cursor:pointer;transition:border-color .15s ease,background .15s ease;}
.chip input:checked + span{border-color:#f95b1f;background:#fff2eb;color:#a22a00;}
select,textarea{width:100%;box-sizing:border-box;min-height:52px;padding:14px;font-size:16px;border:1px solid #c3c4c7;border-radius:10px;background:#fff;}
textarea{min-height:96px;}
.btn{display:block;width:100%;box-sizing:border-box;min-height:56px;font-size:17px;font-weight:700;color:#fff;background:#f95b1f;border:none;border-radius:12px;cursor:pointer;position:sticky;bottom:12px;box-shadow:0 4px 14px rgba(249,91,31,.35);}
@media(min-width:640px){.chip{flex:1 1 45%;}}
</style></head><body><div class="wrap">'
			. '<h1>Calificar lead</h1><p class="sub">Tu respuesta queda registrada en el CRM. Es rápida: <strong>4 preguntas</strong>.</p>'
			. '<div class="card"><h1 style="font-size:17px;">' . esc_html( (string) ( $record->name ?? 'Lead #' . (int) $record->lead_id ) ) . '</h1>'
			. '<div class="kv"><span class="kv-k">Email</span><span class="kv-v">' . ( is_email( (string) $record->email ) ? '<a href="mailto:' . esc_attr( $record->email ) . '" style="color:#2271b1;text-decoration:none;">' . esc_html( (string) $record->email ) . '</a>' : esc_html( (string) ( $record->email ?? '—' ) ) ) . '</span></div>'
			. ( (string) ( $record->phone ?? '' ) !== '' ? '<div class="kv"><span class="kv-k">Teléfono</span><span class="kv-v">' . esc_html( (string) $record->phone ) . '</span></div>' : '' )
			. ( $project !== '' ? '<div class="kv"><span class="kv-k">Proyecto</span><span class="kv-v">' . esc_html( $project ) . '</span></div>' : '' )
			. ( $message !== '' ? '<div class="msg">' . esc_html( $message ) . '</div>' : '' )
			. '</div>'
			. '<form method="post" action="' . esc_url( add_query_arg( array( 'estandar_feedback' => '1', 'token' => $token ), home_url( '/' ) ) ) . '">'
			. '<div class="card">'
			. '<div class="field"><div class="q">¿Es un lead válido? *</div><div class="chips">'
			. '<label class="chip"><input type="radio" name="is_valid_lead" value="yes" required> <span>✅ Sí, es un lead válido</span></label>'
			. '<label class="chip"><input type="radio" name="is_valid_lead" value="no" required> <span>❌ No Califica</span></label></div></div>'
			. '<div class="field"><div class="q">Calidad del lead *</div><div class="chips">' . $quality_chips . '</div></div>'
			. '<div class="field"><div class="q">Estado comercial *</div><select name="status" required>' . $status_options . '</select></div>'
			. '<div class="field"><div class="q">Razón / detalle</div><textarea name="reason" placeholder="Contexto, motivo del estado o lo que haga falta.">' . esc_textarea( (string) $record->latest_feedback_reason ?? '' ) . '</textarea></div>'
			. '</div>'
			. '<button type="submit" class="btn">Guardar calificación</button>'
			. '</form>'
			. self::brand_footer()
			. '</div></body></html>';
	}

	/** Mensaje simple mobile-first (éxito / error). */
	private static function render_feedback_message( string $title, string $msg, bool $ok = false ): string
	{
		$color = $ok ? '#1e8e3e' : '#b3261e';
		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $title ) . '</title>'
			. '<style>body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:#f3f4f6;color:#1d2327;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;box-sizing:border-box;}
.card{background:#fff;border-radius:16px;padding:28px 24px;max-width:460px;width:100%;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,.08);}
h1{font-size:20px;margin:0 0 12px;color:' . $color . ';}
p{font-size:15px;line-height:1.55;color:#3c4043;margin:0;}
a{color:#f95b1f;}</style></head><body><div class="card"><h1>' . esc_html( $title ) . '</h1><p>' . wp_kses_post( $msg ) . '</p>' . self::brand_footer() . '</div></body></html>';
	}

	// ── Página amigable /panel-leads/ (template_redirect) ─────────────────

	public static function maybe_render_panel_page(): void
	{
		if ( (int) get_query_var( 'estandar_suite_leads_panel' ) !== 1 ) {
			return;
		}
		nocache_headers();

		// Logout (cierra PIN y sesión mágica).
		if ( isset( $_GET['logout'] ) ) {
			self::logout();
			self::magic_logout();
			wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) );
			exit;
		}

		// POST de login (nombre + PIN).
		if ( ! empty( $_POST['lp_action'] ) && $_POST['lp_action'] === 'login' ) {
			$name = sanitize_text_field( (string) ( $_POST['name'] ?? '' ) );
			$pin  = (string) ( $_POST['pin'] ?? '' );
			$ip   = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			// LOGIN_LOCKOUT_V1: bloqueo anti-brute-force del PIN.
			if ( self::login_locked( $name, $ip ) ) {
				status_header( 200 );
				echo self::render_login_page( false, 'Demasiados intentos fallidos. Intenta nuevamente en 15 minutos.', 'error' );
				exit;
			}
			if ( self::authenticate( $name, $pin ) ) {
				self::clear_login_failures( $name, $ip );
				self::log_login( 'success', $name, $ip );
				wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) );
				exit;
			}
			self::register_login_failure( $name, $ip );
			status_header( 200 );
			echo self::render_login_page( true );
			exit;
		}

		// POST de solicitud de magic link (acceso por correo, solo el prefijo).
		if ( ! empty( $_POST['lp_action'] ) && $_POST['lp_action'] === 'magic_request' ) {
			$input = sanitize_text_field( (string) ( $_POST['email'] ?? '' ) );
			$at = strpos( $input, '@' );
			if ( $at !== false ) {
				$input = substr( $input, 0, $at ); // solo el nombre antes de @
			}
			$email = self::resolve_magic_target( $input );
			if ( $email === '' ) {
				status_header( 200 );
				echo self::render_login_page( false, 'Correo no válido para ' . self::magic_domain() . '.', 'error' );
				exit;
			}
			$r = self::request_magic_link( $email );
			status_header( 200 );
			echo self::render_login_page( false, (string) $r['message'], $r['ok'] ? 'ok' : 'error' );
			exit;
		}

		// Consumo de magic token (?magic=).
		if ( isset( $_GET['magic'] ) ) {
			$full = self::consume_magic_token_full( (string) $_GET['magic'] );
			if ( ! empty( $full['email'] ) ) {
				self::set_magic_cookie( (string) $full['email'] );
				$ret = (string) ( $full['return_url'] ?? '' );
				// El token puede apuntar a otro destino (p. ej. la página del form):
				// se respeta si es del mismo host; si no, cae al panel.
				if ( $ret !== '' && strpos( $ret, home_url( '/' ) ) === 0 ) {
					wp_safe_redirect( $ret );
				} else {
					wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) );
				}
				exit;
			}
			status_header( 200 );
			echo self::render_login_page( false, 'El enlace no es válido o ya fue usado.', 'error' );
			exit;
		}

		// Autorizado: panel.
		if ( self::authorized() ) {
			$operator = self::current_operator();
			if ( $operator !== '' ) {
				self::log_view( $operator );
			}

			// CRM_LEAD_EDIT_V1: guardar creación/edición de lead (POST, nonce).
			if ( ! empty( $_POST['lp_action'] ) && $_POST['lp_action'] === 'lead_save' ) {
				if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'lp_lead_save' ) ) {
					status_header( 403 );
					echo 'Nonce inválido';
					exit;
				}
				$save_id = absint( $_POST['lead_id'] ?? 0 );
				$input = array(
					'name'         => sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ),
					'email'        => sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) ),
					'phone'        => sanitize_text_field( wp_unslash( (string) ( $_POST['phone'] ?? '' ) ) ),
					'project_slug' => sanitize_key( (string) ( $_POST['project_slug'] ?? '' ) ),
					'status'       => sanitize_key( (string) ( $_POST['status'] ?? '' ) ),
					'note'         => sanitize_textarea_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) ),
				);
				if ( $save_id > 0 ) {
					$r = self::operator_edit_lead( $save_id, $input );
					wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) . '?lead=' . $save_id . '&saved=' . ( ! empty( $r['ok'] ) ? '1' : '0' ) );
					exit;
				}
				$r = self::operator_create_lead( $input );
				if ( ! empty( $r['ok'] ) && $r['action'] === 'created' ) {
					if ( $input['note'] !== '' ) {
						self::operator_add_note( (int) $r['lead_id'], $input['note'] );
					}
					wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) . '?lead=' . (int) $r['lead_id'] . '&saved=1' );
					exit;
				}
				if ( ! empty( $r['ok'] ) ) { // ya existía por email/tel
					wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) . '?lead=' . (int) $r['lead_id'] . '&exists=1' );
					exit;
				}
				status_header( 200 );
				echo self::render_lead_editor( $save_id, 'No se pudo guardar el lead: ' . ( (string) ( $r['error'] ?? 'desconocido' ) ), 'error' );
				exit;
			}

			// QUOTE_V2: guardar cotización de un lead (POST, nonce).
			if ( ! empty( $_POST['lp_action'] ) && $_POST['lp_action'] === 'quote_save' ) {
				if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'lp_quote_save' ) ) {
					status_header( 403 );
					echo 'Nonce inválido';
					exit;
				}
				$q_lead_id = absint( $_POST['lead_id'] ?? 0 );
				if ( ! class_exists( '\Estandar\Flow\Quotes' ) || $q_lead_id <= 0 ) {
					status_header( 200 );
					echo self::render_quote_editor( $q_lead_id, 'El módulo de cotizaciones no está disponible.', 'error' );
					exit;
				}
				// M6: editor WYSIWYG — persiste desde `document_json` (aditivo).
				if ( class_exists( '\Estandar\Flow\Quotes\Editor' ) && method_exists( '\Estandar\Flow\Quotes\Editor', 'save_from_post' ) ) {
					$r = \Estandar\Flow\Quotes\Editor::save_from_post( $q_lead_id, $_POST, self::current_operator() );
					if ( ! empty( $r['ok'] ) ) {
						wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/?lead=' . $q_lead_id . '&quote=new&saved=1&quote_id=' . (int) $r['quote_id'] ) );
						exit;
					}
					status_header( 200 );
					echo self::render_quote_editor( $q_lead_id, 'No se pudo guardar la cotización: ' . ( (string) ( $r['error'] ?? 'desconocido' ) ), 'error' );
					exit;
				}
				$items_text = sanitize_textarea_field( wp_unslash( (string) ( $_POST['items'] ?? '' ) ) );
				$items = array();
				foreach ( preg_split( "/\r\n|\n|\r/", $items_text ) as $line ) {
					$line = trim( $line );
					if ( $line === '' ) {
						continue;
					}
					$parts = array_map( 'trim', explode( '|', $line ) );
					if ( count( $parts ) >= 2 && $parts[0] !== '' && is_numeric( $parts[1] ) ) {
						$items[] = array( 'label' => $parts[0], 'amount' => (float) $parts[1], 'qty' => isset( $parts[2] ) && is_numeric( $parts[2] ) ? (float) $parts[2] : 1, 'unit' => '' );
					}
				}
				$q_id = \Estandar\Flow\Quotes::create( array(
					'lead_id'       => $q_lead_id,
					'title'         => sanitize_text_field( wp_unslash( (string) ( $_POST['title'] ?? '' ) ) ),
					'items'         => $items,
					'currency'      => sanitize_key( (string) ( $_POST['currency'] ?? 'CLP' ) ),
					'validity_days' => (int) ( $_POST['validity_days'] ?? 15 ),
					'notes'         => sanitize_textarea_field( wp_unslash( (string) ( $_POST['notes'] ?? '' ) ) ),
					'status'        => 'draft',
				) );
				if ( $q_id <= 0 ) {
					status_header( 200 );
					echo self::render_quote_editor( $q_lead_id, 'No se pudo crear la cotización.', 'error' );
					exit;
				}
				wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/?lead=' . $q_lead_id . '&quote=new&saved=1' ) );
				exit;
			}

			// QUOTE_V2: enviar cotización al cliente (POST, nonce).
			if ( ! empty( $_POST['lp_action'] ) && $_POST['lp_action'] === 'quote_send' ) {
				if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'lp_quote_save' ) ) {
					status_header( 403 );
					echo 'Nonce inválido';
					exit;
				}
				$q_lead_id = absint( $_POST['lead_id'] ?? 0 );
				$q_id      = absint( $_POST['quote_id'] ?? 0 );
				$lead_row  = (array) $wpdb->get_row( $wpdb->prepare(
					"SELECT id, name, email FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1", $q_lead_id
				), ARRAY_A );
				$r = class_exists( '\Estandar\Flow\Quotes' )
					? \Estandar\Flow\Quotes::send( $q_id, (string) ( $lead_row['email'] ?? '' ), $lead_row )
					: array( 'ok' => false, 'error' => 'sin_modulo' );
				$notice = ! empty( $r['ok'] ) ? 'Cotización enviada al cliente.' : 'No se pudo enviar: ' . ( (string) ( $r['error'] ?? 'desconocido' ) );
				status_header( 200 );
				echo self::render_quote_editor( $q_lead_id, $notice, ! empty( $r['ok'] ) ? 'ok' : 'error' );
				exit;
			}

			// IA Context: export markdown para análisis / reportes / mejora del
			// sistema (equivalente v2 del módulo legacy). Misma autorización que
			// la ficha; salida text/markdown para copiar o guardar.
			if ( isset( $_GET['ai_context'] ) ) {
				$ai_id = (int) $_GET['ai_context'];
				$md    = class_exists( '\Estandar\Core\Leads\AiContext' )
					? \Estandar\Core\Leads\AiContext::build_for_lead( $ai_id )
					: '';
				if ( $md === '' ) {
					status_header( 404 );
					echo 'Lead no encontrado';
					exit;
				}
				self::log_activity( 'lead', $ai_id );
				header( 'Content-Type: text/markdown; charset=utf-8' );
				header( 'Content-Disposition: inline; filename="lead-' . $ai_id . '-context.md"' );
				header( 'X-Content-Type-Options: nosniff' );
				echo $md;
				exit;
			}

			// CRM_LEAD_EDIT_V1: editor de lead (alta o edición) para operadores.
			if ( isset( $_GET['new'] ) && $_GET['new'] === '1' ) {
				status_header( 200 );
				echo self::render_lead_editor( 0, 'Crea un lead nuevo (nombre + email o teléfono).', 'info' );
				exit;
			}
			$lead_id = (int) ( $_GET['lead'] ?? 0 );
			if ( $lead_id > 0 && isset( $_GET['edit'] ) && $_GET['edit'] === '1' ) {
				status_header( 200 );
				echo self::render_lead_editor( $lead_id );
				exit;
			}
			// QUOTE_V2: editor de cotización de un lead.
			if ( $lead_id > 0 && isset( $_GET['quote'] ) && $_GET['quote'] === 'new' ) {
				status_header( 200 );
				echo self::render_quote_editor( $lead_id,
					! empty( $_GET['saved'] ) ? 'Cotización creada.' : '', ! empty( $_GET['saved'] ) ? 'ok' : 'info' );
				exit;
			}

			// Ficha del pipeline de un lead (?lead=ID).
			if ( $lead_id > 0 ) {
				// Acción "Contactar ahora": registra el clic y redirige al canal.
				if ( isset( $_GET['contact'] ) ) {
					self::handle_contact_action( $lead_id, (string) $_GET['contact'] );
				}
				// Acción "Calificar calidad del lead": crea token de feedback y redirige.
				if ( isset( $_GET['feedback'] ) ) {
					self::handle_feedback_action( $lead_id );
				}
				$pipeline = self::lead_pipeline( $lead_id );
				if ( empty( $pipeline ) ) {
					status_header( 404 );
					echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Lead no encontrado</title></head>'
						. '<body style="font-family:sans-serif;padding:40px;"><a href="' . esc_url( home_url( '/' . self::PANEL_SLUG . '/' ) ) . '">← Volver</a>'
						. '<h2>Lead no encontrado</h2></body></html>';
					exit;
				}
				// Auditoría: quién abrió la ficha de qué lead + reflejo en el CRM.
				self::log_activity( 'lead', $lead_id );
				self::log_lead_viewed( $lead_id );
				status_header( 200 );
				echo self::render_lead_ficha( $pipeline );
				exit;
			}

			$filters = array(
				'from'     => sanitize_text_field( (string) ( $_GET['from'] ?? '' ) ),
				'to'       => sanitize_text_field( (string) ( $_GET['to'] ?? '' ) ),
				'project'  => sanitize_text_field( (string) ( $_GET['project'] ?? '' ) ),
				'operator' => sanitize_text_field( (string) ( $_GET['operator'] ?? '' ) ),
				'name'     => sanitize_text_field( (string) ( $_GET['name'] ?? '' ) ),
				'access'   => sanitize_text_field( (string) ( $_GET['access'] ?? $_GET['pass'] ?? '' ) ),
				'orderby'  => sanitize_key( (string) ( $_GET['orderby'] ?? 'last_submitted_at' ) ),
				'order'    => strtolower( (string) ( $_GET['order'] ?? 'desc' ) ) === 'asc' ? 'asc' : 'desc',
			);
			// PANEL_PAGINATION_V1: paginación para ver TODOS los leads (no solo
			// los últimos 30). `per_page` por GET (30/50/100/250) y `page`.
			$per_page   = (int) apply_filters( 'estandar_suite_core_leads_panel_per_page', (int) ( $_GET['per_page'] ?? 30 ) );
			$per_page   = in_array( $per_page, array( 30, 50, 100, 250 ), true ) ? $per_page : 30;
			$page       = max( 1, (int) ( $_GET['page'] ?? 1 ) );
			$total      = self::count_operator_leads( $filters );
			$total_pages = max( 1, (int) ceil( $total / $per_page ) );
			$page       = min( $page, $total_pages );
			$rows       = self::operator_leads( $filters, $per_page, ( $page - 1 ) * $per_page );
			$export_base = rest_url( 'estandar-suite/core/v1/leads/export' );
			// Auditoría: quién abrió la lista de leads.
			self::log_activity( 'list' );
			status_header( 200 );
			echo self::render_viewer( $rows, $filters, $export_base, $page, $total_pages, $total, $per_page );
			exit;
		}

		// No autorizado: login.
		status_header( 200 );
		echo self::render_login_page( false );
		exit;
	}
}
