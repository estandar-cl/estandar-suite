<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spam — protección anti-spam multicapa del renderer v2 (F1).
 *
 * Porta la cadena de defensas del pipeline legacy al FormRenderer v2:
 *  - tiempo mínimo de envío (estandar_ts);
 *  - rate-limit por IP + form (transient);
 *  - token anti-duplicado (_estandar_token) con ventana de 1 min;
 *  - reCAPTCHA v3 opcional (require_recaptcha + constantes).
 *
 * Todas las capas tienen guard: sin función/DB relevante → permitir (no romper
 * el form). Son determinísticas y testables sin WP.
 */
class Spam
{
	const RATE_WINDOW = 600;   // 10 min
	const RATE_MAX    = 5;     // intentos por ventana
	const MIN_SECONDS = 3;     // tiempo mínimo entre render y submit
	const DUP_WINDOW  = 60;    // vida del transient del token anti-duplicado
	const DOUBLE_CLICK_WINDOW = 5; // solo bloquea el doble envío inmediato (mismo token)

	/**
	 * Evaluación completa anti-spam. @return array{ok:bool, reason:string}
	 * El token anti-duplicado solo se MARCA al consumar un envío (mark_duplicate)
	 * para no bloquear reintentos tras un error de validación.
	 *
	 * @param bool $skip_rate_limit Forms always-refillable (ALWAYS_REFILLABLE_V1)
	 *                              no aplican rate-limit: aceptan reenvíos ilimitados.
	 * @param int  $rate_max        Tope de envíos por IP+form (config por form);
	 *                              <= 0 usa RATE_MAX (fábrica).
	 * @param int  $rate_window     Ventana en segundos; <= 0 usa RATE_WINDOW.
	 */
	public static function check( int $form_id, string $ip, array $post, bool $skip_rate_limit = false, int $rate_max = 0, int $rate_window = 0 ): array
	{
		if ( self::submitted_too_fast( $post ) ) {
			return array( 'ok' => false, 'reason' => 'submit_speed' );
		}
		if ( self::duplicate_submit( $form_id, $post ) ) {
			return array( 'ok' => false, 'reason' => 'duplicate_request' );
		}
		if ( ! $skip_rate_limit && ! self::rate_limit_ok( $form_id, $ip, $rate_max, $rate_window ) ) {
			return array( 'ok' => false, 'reason' => 'rate_limited' );
		}
		return array( 'ok' => true, 'reason' => '' );
	}

	/** Tiempo mínimo entre render y envío (estandar_ts + estandar_form_v2_ts). */
	public static function submitted_too_fast( array $post ): bool
	{
		$ts = (int) ( $post['estandar_form_v2_ts'] ?? 0 );
		if ( $ts <= 0 ) {
			$ts = (int) ( $post['estandar_ts'] ?? 0 );
		}
		if ( $ts <= 0 ) {
			return false; // sin referencia temporal → no bloquear (form legacy)
		}
		return ( time() - $ts ) < self::MIN_SECONDS;
	}

	/**
	 * Verifica si un token anti-duplicado fue usado hace MOMENTOS (doble-clic).
	 *
	 * El token viaja en el HTML del form; si la página está en caché (p. ej.
	 * SG Optimizer/Cloudflare) TODAS las cargas reutilizan el MISMO token. Con
	 * una ventana de 1 min se bloqueaban reenvíos legítimos separados en el
	 * tiempo (un mismo visitante que elige otra opción de routing, o corrige
	 * y reenvía). Ahora solo bloquea el POST duplicado inmediato (doble clic /
	 * doble submit del mismo form renderizado).
	 */
	public static function duplicate_submit( int $form_id, array $post ): bool
	{
		$token = self::token_from_post( $post );
		if ( $token === '' || ! function_exists( 'get_transient' ) ) {
			return false;
		}
		$marked = (int) get_transient( 'estandar_form_dup_' . md5( $form_id . '|' . $token ) );
		return $marked > 0 && ( time() - $marked ) < self::DOUBLE_CLICK_WINDOW;
	}

	/** Marca el token como usado SOLO tras un envío consumado. */
	public static function mark_duplicate( int $form_id, array $post ): void
	{
		$token = self::token_from_post( $post );
		if ( $token === '' || ! function_exists( 'set_transient' ) ) {
			return;
		}
		set_transient( 'estandar_form_dup_' . md5( $form_id . '|' . $token ), time(), self::DUP_WINDOW );
	}

	private static function token_from_post( array $post ): string
	{
		return sanitize_key( (string) ( $post['_estandar_token'] ?? ( $post['estandar_form_v2_token'] ?? '' ) ) );
	}

	/**
	 * Rate-limit por IP + form. El tope y la ventana son configurables por form
	 * (RATE_MAX/RATE_WINDOW como fábrica cuando se pasa 0).
	 * @return bool true si el intento está permitido.
	 */
	public static function rate_limit_ok( int $form_id, string $ip, int $rate_max = 0, int $rate_window = 0 ): bool
	{
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return true;
		}
		$ip = sanitize_text_field( $ip );
		if ( $ip === '' || filter_var( $ip, FILTER_VALIDATE_IP ) === false ) {
			return true;
		}
		$max = $rate_max > 0 ? $rate_max : self::RATE_MAX;
		$win = $rate_window > 0 ? $rate_window : self::RATE_WINDOW;
		$key  = 'estandar_form_rl_' . md5( $form_id . '|' . $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, $win );
		return true;
	}

	/**
	 * reCAPTCHA v3 server-side. @return array{ok:bool, reason:string}
	 * Sin constantes/config → se omite (no rompe el form).
	 */
	public static function recaptcha_verify( array $config, array $post ): array
	{
		if ( empty( $config['require_recaptcha'] ) ) {
			return array( 'ok' => true, 'reason' => '' );
		}
		if ( ! defined( 'ESTANDAR_FORMS_RECAPTCHA_SITE_KEY' )
			|| ! defined( 'ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY' )
			|| ! ESTANDAR_FORMS_RECAPTCHA_SITE_KEY
			|| ! ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY ) {
			return array( 'ok' => true, 'reason' => 'recaptcha_no_config' );
		}
		$token = (string) ( $post['estandar_form_v2_recaptcha'] ?? ( $post['estandar_recaptcha_token'] ?? '' ) );
		if ( $token === '' ) {
			return array( 'ok' => false, 'reason' => 'recaptcha_missing_token' );
		}
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return array( 'ok' => true, 'reason' => 'recaptcha_no_transport' );
		}

		$resp = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
			'timeout' => 10,
			'body'    => array(
				'secret'   => (string) ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY,
				'response' => $token,
			),
		) );
		$body = is_wp_error( $resp ) ? null : json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$ok   = is_array( $body ) && ! empty( $body['success'] );
		$score = (float) ( $body['score'] ?? 0 );
		$min  = (float) ( defined( 'ESTANDAR_FORMS_RECAPTCHA_MIN_SCORE' ) ? ESTANDAR_FORMS_RECAPTCHA_MIN_SCORE : 0.5 );
		if ( $ok && $score < $min ) {
			return array( 'ok' => false, 'reason' => 'recaptcha_low_score' );
		}
		return $ok
			? array( 'ok' => true, 'reason' => '' )
			: array( 'ok' => false, 'reason' => 'recaptcha_failed' );
	}
}
