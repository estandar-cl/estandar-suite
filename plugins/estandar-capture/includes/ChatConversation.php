<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ChatConversation — persistencia de conversaciones del chatbot v2.
 *
 * Almacena cada mensaje como fila en `estandar_capture_chat_messages`
 * (una fila por turno, escalable) y expone helpers para lectura (historial de
 * una sesión, listado para la pestaña Conversaciones del admin) y agregación.
 *
 * Contrato: session_key + channel identifican la conversación; role en
 * user|assistant|system; content truncado a 4000.
 */
class ChatConversation
{
	const TABLE   = 'estandar_capture_chat_messages';
	const VERSION = '1.0.0';

	/** Create/upgrade the table. */
	public static function activate_schema(): void
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(190) NOT NULL,
			channel VARCHAR(40) NOT NULL DEFAULT 'web',
			role VARCHAR(20) NOT NULL,
			content LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_session_channel (session_key, channel),
			KEY idx_created_at (created_at),
			KEY idx_channel (channel)
		) {$charset_collate};" );
		update_option( 'estandar_capture_chat_messages_schema', self::VERSION, false );
	}

	/** Run schema migration if needed. */
	public static function maybe_migrate_schema(): void
	{
		if ( get_option( 'estandar_capture_chat_messages_schema', '' ) === self::VERSION ) {
			return;
		}
		self::activate_schema();
	}

	/** Append a message. */
	public static function append( string $session_key, string $channel, string $role, string $content ): int
	{
		global $wpdb;
		$session_key = self::normalize_session_key( $session_key );
		$channel = sanitize_key( $channel );
		if ( $channel === '' ) {
			$channel = 'web';
		}
		$role = sanitize_key( $role );
		if ( ! in_array( $role, array( 'user', 'assistant', 'system' ), true ) ) {
			$role = 'user';
		}
		$content = sanitize_textarea_field( (string) $content );
		$content = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 4000 ) : substr( $content, 0, 4000 );
		if ( $content === '' || $session_key === '' ) {
			return 0;
		}
		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'session_key' => $session_key,
			'channel'     => $channel,
			'role'        => $role,
			'content'     => $content,
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/** Últimos mensajes de una sesión (ascendente). */
	public static function get_messages( string $session_key, string $channel = 'web', int $limit = 50 ): array
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$session_key = self::normalize_session_key( $session_key );
		$limit = max( 1, min( 500, $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, session_key, channel, role, content, created_at FROM {$table}
			 WHERE session_key = %s AND channel = %s ORDER BY id ASC LIMIT %d",
			$session_key, $channel, $limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * CHAT_CONV_PAGING_V1: construye el WHERE de los filtros del visor de
	 * Conversaciones. Devuelve `array($where_sql, $args)`. Puro y tolerante a
	 * la ausencia de helpers de WP (tests).
	 *
	 * Filtros aceptados: `channel` (canal exacto), `q` (término en session_key o
	 * contenido), `date_from`/`date_to` (YYYY-MM-DD sobre created_at).
	 */
	public static function filter_where( array $filters ): array
	{
		$where = array();
		$args  = array();
		if ( ! empty( $filters['channel'] ) ) {
			$where[] = 'channel = %s';
			$args[]  = (string) $filters['channel'];
		}
		if ( ! empty( $filters['q'] ) ) {
			$like    = '%' . ( function_exists( 'esc_sql' ) ? esc_sql( $filters['q'] ) : addslashes( (string) $filters['q'] ) ) . '%';
			$where[] = '(session_key LIKE %s OR content LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = (string) $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = (string) $filters['date_to'] . ' 23:59:59';
		}
		return array( $where ? 'WHERE ' . implode( ' AND ', $where ) : '', $args );
	}

	/**
	 * Listado de conversaciones (agregado por sesión) para el admin.
	 *
	 * Retrocompatible: `conversations(100)` sigue funcionando. Nuevos argumentos
	 * opcionales `$filters` y `$offset` habilitan el visor paginado.
	 */
	public static function conversations( int $limit = 100, array $filters = array(), int $offset = 0 ): array
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$limit  = max( 1, min( 500, $limit ) );
		$offset = max( 0, $offset );
		list( $where, $args ) = self::filter_where( $filters );
		$args[] = $limit;
		$args[] = $offset;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT session_key, channel, COUNT(*) AS message_count,
			        MIN(created_at) AS first_message_at, MAX(created_at) AS last_message_at
			 FROM {$table} {$where} GROUP BY session_key, channel ORDER BY last_message_at DESC LIMIT %d OFFSET %d",
			$args
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** CHAT_CONV_PAGING_V1: total de conversaciones (respeta los filtros). */
	public static function count_conversations( array $filters = array() ): int
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		list( $where, $args ) = self::filter_where( $filters );
		$sql = "SELECT COUNT(DISTINCT session_key, channel) FROM {$table} {$where}";
		if ( $args ) {
			$sql = $wpdb->prepare( $sql, $args );
		}
		return (int) $wpdb->get_var( $sql );
	}

	/** Total de mensajes / conversaciones. */
	public static function stats(): array
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$messages = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$sessions = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_key, channel) FROM {$table}" );
		return array( 'messages' => $messages, 'conversations' => $sessions );
	}

	/** Normaliza la session key (mismo contrato legacy). */
	private static function normalize_session_key( string $session_key ): string
	{
		$session_key = sanitize_text_field( $session_key );
		$session_key = preg_replace( '/[^a-zA-Z0-9:_-]/', '', $session_key );
		return substr( (string) $session_key, 0, 190 );
	}
}
