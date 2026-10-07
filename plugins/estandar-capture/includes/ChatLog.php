<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ChatLog — registro de interacciones del chatbot v2 (pregunta → respuesta).
 *
 * Almacena cada intercambio en `estandar_capture_chat_logs` y expone KPIs de
 * fricción para la pestaña Logs del admin: preguntas repetidas, fallbacks
 * (respuesta de error), mensajes sin respuesta útil y abandonos.
 *
 * Una fila = un turno user→assistant. `user_message`/`bot_answer` se truncan
 * para el listado; `user_full`/`answer_full` conservan el contenido completo.
 */
class ChatLog
{
	const TABLE   = 'estandar_capture_chat_logs';
	const VERSION = '1.0.0';

	const RESULT_OK      = 'ok';
	const RESULT_FALLBACK = 'fallback';   // respuesta de error genérica.
	const RESULT_EMPTY   = 'empty';       // respuesta vacía/corta.
	const RESULT_ERROR   = 'error';       // el motor falló (AI_FAIL).

	public static function activate_schema(): void
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(190) NOT NULL DEFAULT '',
			channel VARCHAR(40) NOT NULL DEFAULT 'web',
			user_message VARCHAR(255) NOT NULL DEFAULT '',
			user_full LONGTEXT NULL,
			bot_answer VARCHAR(255) NOT NULL DEFAULT '',
			answer_full LONGTEXT NULL,
			result VARCHAR(20) NOT NULL DEFAULT 'ok',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_created_at (created_at),
			KEY idx_result (result),
			KEY idx_channel (channel)
		) {$charset_collate};" );
		update_option( 'estandar_capture_chat_logs_schema', self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( 'estandar_capture_chat_logs_schema', '' ) === self::VERSION ) {
			return;
		}
		self::activate_schema();
	}

	/** Registra una interacción y devuelve el resultado inferido. */
	public static function log( string $session_key, string $channel, string $user, string $answer, string $result = '' ): int
	{
		global $wpdb;
		$session_key = substr( sanitize_text_field( $session_key ), 0, 190 );
		$channel = sanitize_key( $channel ) ?: 'web';
		$user  = sanitize_textarea_field( $user );
		$answer = sanitize_textarea_field( $answer );
		if ( $user === '' ) {
			return 0;
		}
		$user_short = function_exists( 'mb_substr' ) ? mb_substr( $user, 0, 255 ) : substr( $user, 0, 255 );
		$answer_short = function_exists( 'mb_substr' ) ? mb_substr( $answer, 0, 255 ) : substr( $answer, 0, 255 );
		// CHATLOG_RESULT_V1: si el llamador conoce el resultado (p. ej. fallback
		// por error de IA) se respeta; si no, se clasifica por el texto.
		$result = in_array( $result, array( self::RESULT_OK, self::RESULT_FALLBACK, self::RESULT_EMPTY ), true )
			? $result
			: self::classify( $answer );
		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'session_key'  => $session_key,
			'channel'      => $channel,
			'user_message' => $user_short,
			'user_full'    => $user,
			'bot_answer'   => $answer_short,
			'answer_full'  => $answer,
			'result'       => $result,
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/** Clasifica el resultado según la respuesta del bot. */
	public static function classify( string $answer ): string
	{
		$answer = trim( (string) $answer );
		if ( $answer === '' ) {
			return self::RESULT_EMPTY;
		}
		if ( strlen( $answer ) < 3 ) {
			return self::RESULT_EMPTY;
		}
		// Respuestas de error genéricas del frontend/motor.
		$fallback = array( 'no pude', 'disculpa', 'intenta en unos minutos', 'AI_FAIL', 'error' );
		foreach ( $fallback as $f ) {
			if ( stripos( $answer, $f ) !== false ) {
				return self::RESULT_FALLBACK;
			}
		}
		return self::RESULT_OK;
	}

	/** Últimos N logs (para la pestaña Logs). */
	public static function recent( int $limit = 200 ): array
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$limit = max( 1, min( 500, $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, session_key, channel, user_message, bot_answer, result, created_at
			 FROM {$table} ORDER BY id DESC LIMIT %d",
			$limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** KPIs de fricción en la ventana dada. */
	public static function friction( int $days = 7 ): array
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$since_sql = $wpdb->prepare( 'created_at >= %s', $since );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$since_sql}" );
		$empty = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$since_sql} AND result = 'empty'" );
		$fallbacks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$since_sql} AND result = 'fallback'" );
		$errors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$since_sql} AND result = 'error'" );

		$repeated_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_message, COUNT(*) AS n FROM {$table} WHERE {$since_sql}
			 GROUP BY user_message HAVING n > 1 ORDER BY n DESC LIMIT 10"
		), ARRAY_A );

		return array(
			'total'      => $total,
			'empty'      => $empty,
			'fallbacks'  => $fallbacks,
			'errors'     => $errors,
			'repeated'   => is_array( $repeated_rows ) ? $repeated_rows : array(),
			'days'       => $days,
		);
	}
}
