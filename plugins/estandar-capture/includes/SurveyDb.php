<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SurveyDb — almacenamiento nativo v2 de sesiones y respuestas de encuestas.
 *
 * F2 · traslado del modelo de storage legacy (SURVEYS_STORAGE_SIMPLIFICATION_V1)
 * a tablas propias de capture. NUNCA escribe tablas legacy:
 *  - Sesiones  → estandar_capture_survey_sessions (data_json con el estado)
 *  - Respuestas → estandar_capture_survey_responses (una fila por pregunta)
 * El esquema de respuestas es idéntico al legacy para que las queries de
 * analytics sean un port 1:1.
 */
class SurveyDb
{
	const SESSIONS_TABLE = 'estandar_capture_survey_sessions';
	const RESPONSES_TABLE = 'estandar_capture_survey_responses';
	const OPTION = 'estandar_capture_survey_schema_version';
	const VERSION = '1.1.0';

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::SESSIONS_TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_token varchar(64) NOT NULL DEFAULT '',
			form_id bigint(20) unsigned NOT NULL,
			status varchar(40) NOT NULL DEFAULT 'started',
			data_json longtext,
			created_at datetime NOT NULL,
			completed_at datetime NULL,
			PRIMARY KEY  (id),
			KEY session_token (session_token),
			KEY form_id (form_id),
			KEY status (status)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::RESPONSES_TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_token varchar(64) NOT NULL DEFAULT '',
			form_id bigint(20) unsigned NOT NULL,
			session_id bigint(20) unsigned NULL,
			question_index tinyint(3) unsigned NOT NULL DEFAULT 0,
			question_label varchar(500) NULL,
			question_type varchar(32) NOT NULL DEFAULT 'text',
			answer_value text NULL,
			answer_label text NULL,
			answer_attachment text NULL,
			answered_at datetime NOT NULL,
			time_spent_seconds smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY session_token (session_token),
			KEY form_id (form_id),
			KEY session_id (session_id),
			KEY question_type (question_type)
		) $charset;" );

		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/* ── Sesiones ────────────────────────────────────────────────────────── */

	/** Crea la sesión. @return int session id (0 si falla). */
	public static function session_create( string $token, int $form_id, array $data, string $status = 'started' ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $token === '' ) {
			return 0;
		}
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $wpdb->prefix . self::SESSIONS_TABLE . ' WHERE session_token = %s LIMIT 1', $token
		) );
		if ( $existing ) {
			self::session_update_data( (int) $existing, $data );
			return (int) $existing;
		}
		$ok = $wpdb->insert(
			$wpdb->prefix . self::SESSIONS_TABLE,
			array(
				'session_token' => $token,
				'form_id'       => $form_id,
				'status'        => $status,
				'data_json'     => wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Actualiza el data_json de una sesión. */
	public static function session_update_data( int $session_id, array $data ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $session_id <= 0 ) {
			return false;
		}
		return false !== $wpdb->update(
			$wpdb->prefix . self::SESSIONS_TABLE,
			array( 'data_json' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
			array( 'id' => $session_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/** Cambia el status de una sesión (started/completed/abandoned). */
	public static function session_set_status( int $session_id, string $status ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $session_id <= 0 ) {
			return false;
		}
		$data = array( 'status' => $status );
		if ( $status === 'completed' ) {
			$data['completed_at'] = current_time( 'mysql' );
		}
		return false !== $wpdb->update(
			$wpdb->prefix . self::SESSIONS_TABLE,
			$data,
			array( 'id' => $session_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/** Sesión por token. */
	public static function session_get( string $token ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $token === '' ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . $wpdb->prefix . self::SESSIONS_TABLE . ' WHERE session_token = %s LIMIT 1', $token
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/* ── Respuestas ──────────────────────────────────────────────────────── */

	/** Upsert de respuesta por (session_token, question_index). */
	public static function response_save( array $response ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$token = (string) ( $response['session_token'] ?? '' );
		$index = (int) ( $response['question_index'] ?? -1 );
		if ( $token === '' || $index < 0 ) {
			return false;
		}
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $wpdb->prefix . self::RESPONSES_TABLE
			. ' WHERE session_token = %s AND question_index = %d LIMIT 1', $token, $index
		) );
		if ( $existing > 0 ) {
			return false !== $wpdb->update(
				$wpdb->prefix . self::RESPONSES_TABLE,
				array(
					'answer_value'       => (string) ( $response['answer_value'] ?? '' ),
					'answer_label'       => (string) ( $response['answer_label'] ?? '' ),
					'answer_attachment'  => (string) ( $response['answer_attachment'] ?? '' ),
					'answered_at'        => (string) ( $response['answered_at'] ?? current_time( 'mysql' ) ),
					'time_spent_seconds' => (int) ( $response['time_spent_seconds'] ?? 0 ),
				),
				array( 'id' => $existing ),
				array( '%s', '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);
		}
		return (bool) $wpdb->insert(
			$wpdb->prefix . self::RESPONSES_TABLE,
			array(
				'session_token'      => $token,
				'form_id'            => (int) ( $response['form_id'] ?? 0 ),
				'session_id'         => (int) ( $response['session_id'] ?? 0 ) > 0 ? (int) $response['session_id'] : null,
				'question_index'     => $index,
				'question_label'     => (string) ( $response['question_label'] ?? '' ),
				'question_type'      => (string) ( $response['question_type'] ?? 'text' ),
				'answer_value'       => (string) ( $response['answer_value'] ?? '' ),
				'answer_label'       => (string) ( $response['answer_label'] ?? '' ),
				'answer_attachment'  => (string) ( $response['answer_attachment'] ?? '' ),
				'answered_at'        => (string) ( $response['answered_at'] ?? current_time( 'mysql' ) ),
				'time_spent_seconds' => (int) ( $response['time_spent_seconds'] ?? 0 ),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/** Linkea todas las respuestas de una sesión a su session_id. */
	public static function response_link_session( string $token, int $session_id, int $form_id ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $token === '' || $session_id <= 0 ) {
			return false;
		}
		return false !== $wpdb->update(
			$wpdb->prefix . self::RESPONSES_TABLE,
			array( 'session_id' => $session_id ),
			array( 'session_token' => $token, 'form_id' => $form_id ),
			array( '%d' ),
			array( '%s', '%d' )
		);
	}
}
