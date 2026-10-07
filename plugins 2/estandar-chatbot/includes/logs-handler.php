<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ======================================================
 * LOGS HANDLER — ESTANDAR CHATBOT IA
 * Storage principal: DB (tabla wp_ecb_logs)
 * CSV legacy + interactions_audit.csv (extensible, append-only)
 * Escritura diferida: cola en shutdown (no bloquea respuesta AJAX).
 * ======================================================
 */

define( 'ESTANDAR_CHATBOT_LOGS_FILE', estandar_chatbot_storage_path( 'logs/logs.csv' ) );
define( 'ESTANDAR_CHATBOT_INTERACTIONS_AUDIT_CSV', estandar_chatbot_storage_path( 'logs/interactions_audit.csv' ) );
define( 'ESTANDAR_CHATBOT_LOGS_COUNT_TRANSIENT', 'ecb_logs_count_v2' );
define( 'ESTANDAR_CHATBOT_LOGS_LIST_TRANSIENT_PREFIX', 'ecb_logs_list_v2_' );
define( 'ESTANDAR_CHATBOT_LOGS_LIST_CACHE_GEN_OPTION', 'ecb_logs_list_cache_gen' );
define( 'ESTANDAR_CHATBOT_LOGS_MIGRATION_OPTION', 'chatbot_logs_migrated_to_db' );
define( 'ESTANDAR_CHATBOT_LOG_META_SCHEMA_VERSION', 1 );

function estandar_chatbot_get_logs_table() {
	global $wpdb;
	return $wpdb->prefix . 'ecb_logs';
}

/**
 * Modo diferido: 1 = cola en shutdown (por defecto), 0 = sincrónico (tests/migraciones).
 */
function estandar_chatbot_log_defer_enabled() {
	return (int) get_option( 'chatbot_log_defer', 1 ) === 1;
}

function estandar_chatbot_ensure_logs_table() {
	global $wpdb;

	$table = estandar_chatbot_get_logs_table();
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		datetime DATETIME NOT NULL,
		question LONGTEXT NOT NULL,
		answer LONGTEXT NOT NULL,
		ip VARCHAR(45) NULL,
		user_agent TEXT NULL,
		url TEXT NULL,
		meta LONGTEXT NULL,
		PRIMARY KEY (id),
		KEY idx_datetime (datetime)
	) {$charset_collate};";

	dbDelta( $sql );
}

function estandar_chatbot_logs_cache_bust() {
	delete_transient( ESTANDAR_CHATBOT_LOGS_COUNT_TRANSIENT );
	$gen = (int) get_option( ESTANDAR_CHATBOT_LOGS_LIST_CACHE_GEN_OPTION, 1 );
	update_option( ESTANDAR_CHATBOT_LOGS_LIST_CACHE_GEN_OPTION, $gen + 1, false );
}

function estandar_chatbot_purge_old_logs() {
	global $wpdb;

	$days = (int) get_option( 'chatbot_logs_retention_days', 0 );
	if ( $days <= 0 ) {
		return 0;
	}

	estandar_chatbot_ensure_logs_table();
	$table = estandar_chatbot_get_logs_table();
	$threshold = estandar_chatbot_local_mysql_time( current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

	$deleted = $wpdb->query(
		$wpdb->prepare( "DELETE FROM {$table} WHERE datetime < %s", $threshold )
	);

	if ( $deleted && $deleted > 0 ) {
		estandar_chatbot_logs_cache_bust();
	}

	return max( 0, (int) $deleted );
}

function estandar_chatbot_maybe_migrate_csv_logs_to_db() {
	if ( (int) get_option( ESTANDAR_CHATBOT_LOGS_MIGRATION_OPTION, 0 ) === 1 ) {
		return;
	}

	if ( ! file_exists( ESTANDAR_CHATBOT_LOGS_FILE ) ) {
		update_option( ESTANDAR_CHATBOT_LOGS_MIGRATION_OPTION, 1, false );
		return;
	}

	$rows = estandar_chatbot_csv_read( ESTANDAR_CHATBOT_LOGS_FILE, 0 );
	if ( count( $rows ) <= 1 ) {
		update_option( ESTANDAR_CHATBOT_LOGS_MIGRATION_OPTION, 1, false );
		return;
	}

	$header = array_shift( $rows );
	if ( ! is_array( $header ) ) {
		update_option( ESTANDAR_CHATBOT_LOGS_MIGRATION_OPTION, 1, false );
		return;
	}

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$item = [];
		foreach ( $header as $i => $key ) {
			$item[ sanitize_key( (string) $key ) ] = (string) ( $row[ $i ] ?? '' );
		}
		estandar_chatbot_log_interaction_sync(
			[
				'question'         => (string) ( $item['question'] ?? '' ),
				'answer'           => (string) ( $item['answer'] ?? '' ),
				'ip'               => (string) ( $item['ip'] ?? '' ),
				'ua'               => (string) ( $item['user_agent'] ?? '' ),
				'url'              => (string) ( $item['url'] ?? '' ),
				'interaction_type' => 'question',
				'skip_defer'       => true,
				'skip_csv_audit'   => true,
			]
		);
	}

	update_option( ESTANDAR_CHATBOT_LOGS_MIGRATION_OPTION, 1, false );
}

/**
 * Heurística ligera para marcar posible FAQ (procesamiento batch futuro).
 *
 * @param string $question Texto usuario.
 * @return bool
 */
function estandar_chatbot_guess_potential_faq( $question ) {
	$q = trim( (string) $question );
	if ( strlen( $q ) < 12 ) {
		return false;
	}
	if ( strpos( $q, '?' ) !== false ) {
		return true;
	}
	return (bool) preg_match( '/\b(cómo|como|qué|que|cuándo|cuando|dónde|donde|por qué|porque|puedo|puede)\b/iu', $q );
}

/**
 * Clave anti-duplicado en ventana corta (misma sesión + mismo texto).
 *
 * @param array $data Datos normalizados.
 * @return string
 */
function estandar_chatbot_log_interaction_dedupe_key( array $data ) {
	$session = (string) ( $data['session_key'] ?? '' );
	$type    = (string) ( $data['interaction_type'] ?? 'question' );
	$q       = substr( (string) ( $data['question'] ?? '' ), 0, 400 );
	$a       = substr( (string) ( $data['answer'] ?? '' ), 0, 400 );
	return md5( $session . '|' . $type . '|' . $q . '|' . $a );
}

/**
 * Inserta interacción en BD + CSV auditoría (sincrónico).
 *
 * @param array $data Raw.
 * @return bool
 */
function estandar_chatbot_log_interaction_sync( array $data ) {
	global $wpdb;

	estandar_chatbot_ensure_logs_table();

	$interaction_type = sanitize_key( (string) ( $data['interaction_type'] ?? 'question' ) );
	$allowed_types    = [ 'question', 'button_click', 'lead_capture', 'system_response', 'hot_lead_redirect' ];
	if ( ! in_array( $interaction_type, $allowed_types, true ) ) {
		$interaction_type = 'question';
	}

	$question = sanitize_textarea_field( (string) ( $data['question'] ?? '' ) );
	$answer   = sanitize_textarea_field( (string) ( $data['answer'] ?? '' ) );

	if ( $question === '' && $answer === '' ) {
		return false;
	}

	$session_key = '';
	if ( ! empty( $data['session_key'] ) ) {
		if ( function_exists( 'estandar_chatbot_normalize_session_key' ) ) {
			$session_key = estandar_chatbot_normalize_session_key( (string) $data['session_key'] );
		} else {
			$session_key = substr( preg_replace( '/[^a-zA-Z0-9:_-]/', '', (string) $data['session_key'] ), 0, 190 );
		}
	}
	$lead_id     = sanitize_text_field( (string) ( $data['lead_identifier'] ?? '' ) );

	$page_client = '';
	if ( function_exists( 'estandar_chatbot_sanitize_source_page_url' ) ) {
		$page_client = estandar_chatbot_sanitize_source_page_url( $data['page_url'] ?? '' );
	}
	if ( $page_client === '' && ! empty( $data['page_url'] ) ) {
		$page_client = esc_url_raw( (string) $data['page_url'] );
	}

	$server_url = esc_url_raw( (string) ( $data['server_request_url'] ?? ( $data['url'] ?? '' ) ) );
	$url_stored = $page_client !== '' ? $page_client : $server_url;

	$potential_faq = ! empty( $data['potential_faq'] );
	if ( isset( $data['potential_faq'] ) && ! $potential_faq && $interaction_type === 'question' ) {
		$potential_faq = estandar_chatbot_guess_potential_faq( $question );
	}

	$meta_arr = [
		'schema_version'       => ESTANDAR_CHATBOT_LOG_META_SCHEMA_VERSION,
		'interaction_type'     => $interaction_type,
		'session_key'          => $session_key,
		'lead_identifier'      => $lead_id,
		'server_request_url'   => $server_url,
		'page_url'             => $page_client,
		'potential_faq'        => $potential_faq ? 1 : 0,
		'channel'              => sanitize_key( (string) ( $data['channel'] ?? 'web' ) ),
	];
	$meta_arr = apply_filters( 'estandar_chatbot_log_meta', $meta_arr, $data );
	$meta_json = wp_json_encode( $meta_arr );

	$dedupe_key = estandar_chatbot_log_interaction_dedupe_key(
		array_merge( $data, [ 'question' => $question, 'answer' => $answer, 'interaction_type' => $interaction_type, 'session_key' => $session_key ] )
	);
	if ( empty( $data['skip_dedupe'] ) ) {
		$tkey = 'ecb_log_dd_' . $dedupe_key;
		if ( get_transient( $tkey ) ) {
			return false;
		}
		set_transient( $tkey, 1, 120 );
	}

	$table = estandar_chatbot_get_logs_table();
	$ok    = $wpdb->insert(
		$table,
		[
			'datetime'     => current_time( 'mysql' ),
			'question'     => $question,
			'answer'       => $answer,
			'ip'           => sanitize_text_field( $data['ip'] ?? '' ),
			'user_agent'   => sanitize_text_field( $data['ua'] ?? '' ),
			'url'          => $url_stored,
			'meta'         => $meta_json,
		],
		[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
	);

	if ( ! $ok ) {
		return false;
	}

	estandar_chatbot_logs_cache_bust();

	if ( empty( $data['skip_csv_audit'] ) ) {
		estandar_chatbot_interactions_audit_csv_append(
			[
				'datetime'          => current_time( 'mysql' ),
				'interaction_type'  => $interaction_type,
				'session_key'       => $session_key,
				'lead_identifier'   => $lead_id,
				'page_url'          => $page_client,
				'question'          => $question,
				'answer'            => $answer,
				'potential_faq'     => $potential_faq ? '1' : '0',
				'dedupe_key'        => $dedupe_key,
			]
		);
	}

	return true;
}

/**
 * Append-only CSV extensible (cabecera v1).
 *
 * @param array $row
 */
function estandar_chatbot_interactions_audit_csv_append( array $row ) {
	$path = ESTANDAR_CHATBOT_INTERACTIONS_AUDIT_CSV;
	$dir  = dirname( $path );
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$header = [
		'datetime',
		'interaction_type',
		'session_key',
		'lead_identifier',
		'page_url',
		'question',
		'answer',
		'potential_faq',
		'dedupe_key',
		'schema',
	];

	$need_header = ! file_exists( $path ) || filesize( $path ) < 5;

	$fh = fopen( $path, 'ab' );
	if ( ! $fh ) {
		return;
	}
	if ( ! flock( $fh, LOCK_EX ) ) {
		fclose( $fh );
		return;
	}
	if ( $need_header ) {
		fputcsv( $fh, $header );
	}
	$row['schema'] = (string) ESTANDAR_CHATBOT_LOG_META_SCHEMA_VERSION;
	fputcsv(
		$fh,
		[
			estandar_chatbot_csv_safe_cell( (string) ( $row['datetime'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $row['interaction_type'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $row['session_key'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $row['lead_identifier'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $row['page_url'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $row['question'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $row['answer'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $row['potential_faq'] ?? '0' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $row['dedupe_key'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $row['schema'] ?? '' ) ),
		]
	);
	fflush( $fh );
	flock( $fh, LOCK_UN );
	fclose( $fh );
}

/**
 * Cola de logs para ejecutar en shutdown (respuesta AJAX ya preparada).
 */
function estandar_chatbot_flush_log_queue() {
	if ( empty( $GLOBALS['ecb_log_queue'] ) || ! is_array( $GLOBALS['ecb_log_queue'] ) ) {
		return;
	}
	foreach ( $GLOBALS['ecb_log_queue'] as $row ) {
		if ( is_array( $row ) ) {
			estandar_chatbot_log_interaction_sync( $row );
		}
	}
	$GLOBALS['ecb_log_queue'] = [];
}

/**
 * API pública: registra interacción (diferida por defecto).
 *
 * @param array $data question, answer, ip, ua, url (server), page_url (cliente),
 *                    interaction_type, session_key, lead_identifier, potential_faq, channel.
 * @return bool
 */
function estandar_chatbot_log_interaction( array $data ) {
	if ( ! empty( $data['skip_defer'] ) || ! estandar_chatbot_log_defer_enabled() ) {
		return estandar_chatbot_log_interaction_sync( $data );
	}

	if ( ! isset( $GLOBALS['ecb_log_queue'] ) ) {
		$GLOBALS['ecb_log_queue'] = [];
	}
	$GLOBALS['ecb_log_queue'][] = $data;

	if ( empty( $GLOBALS['ecb_log_shutdown_registered'] ) ) {
		$GLOBALS['ecb_log_shutdown_registered'] = true;
		add_action( 'shutdown', 'estandar_chatbot_flush_log_queue', 5 );
	}

	return true;
}

function estandar_chatbot_count_logs() {
	global $wpdb;

	$cached = get_transient( ESTANDAR_CHATBOT_LOGS_COUNT_TRANSIENT );
	if ( $cached !== false ) {
		return (int) $cached;
	}

	estandar_chatbot_ensure_logs_table();
	$table = estandar_chatbot_get_logs_table();
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	set_transient( ESTANDAR_CHATBOT_LOGS_COUNT_TRANSIENT, $total, 30 );
	return $total;
}

function estandar_chatbot_get_logs( $limit = 200, $offset = 0 ) {
	global $wpdb;

	$limit  = max( 1, (int) $limit );
	$offset = max( 0, (int) $offset );
	$gen    = (int) get_option( ESTANDAR_CHATBOT_LOGS_LIST_CACHE_GEN_OPTION, 1 );
	$cache_key = ESTANDAR_CHATBOT_LOGS_LIST_TRANSIENT_PREFIX . $gen . '_' . $limit . '_' . $offset;
	$cached = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	estandar_chatbot_ensure_logs_table();
	$table = estandar_chatbot_get_logs_table();
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, datetime, question, answer, ip, user_agent, url, meta FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
			$limit,
			$offset
		),
		ARRAY_A
	);

	$out = is_array( $rows ) ? $rows : [];
	set_transient( $cache_key, $out, 30 );
	return $out;
}

function estandar_chatbot_delete_log_by_id( $id ) {
	global $wpdb;

	$id = (int) $id;
	if ( $id <= 0 ) {
		return 0;
	}

	estandar_chatbot_ensure_logs_table();
	$table   = estandar_chatbot_get_logs_table();
	$deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	if ( $deleted ) {
		estandar_chatbot_logs_cache_bust();
	}
	return max( 0, (int) $deleted );
}

function estandar_chatbot_delete_logs_by_ids( array $ids ) {
	global $wpdb;

	$ids = array_values( array_filter( array_map( 'intval', $ids ), function ( $v ) {
		return $v > 0;
	} ) );
	if ( empty( $ids ) ) {
		return 0;
	}

	estandar_chatbot_ensure_logs_table();
	$table        = estandar_chatbot_get_logs_table();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$sql          = "DELETE FROM {$table} WHERE id IN ({$placeholders})";
	$deleted      = $wpdb->query( $wpdb->prepare( $sql, $ids ) );
	if ( $deleted ) {
		estandar_chatbot_logs_cache_bust();
	}
	return max( 0, (int) $deleted );
}

function estandar_chatbot_update_log_by_id( $id, array $fields ) {
	global $wpdb;

	$id = (int) $id;
	if ( $id <= 0 ) {
		return [ 'ok' => false, 'message' => 'ID inválido.' ];
	}

	$question = sanitize_textarea_field( (string) ( $fields['question'] ?? '' ) );
	$answer   = sanitize_textarea_field( (string) ( $fields['answer'] ?? '' ) );
	$url      = esc_url_raw( (string) ( $fields['url'] ?? '' ) );

	if ( $question === '' && $answer === '' ) {
		return [ 'ok' => false, 'message' => 'Pregunta y respuesta no pueden quedar vacías.' ];
	}

	estandar_chatbot_ensure_logs_table();
	$table = estandar_chatbot_get_logs_table();
	$ok    = $wpdb->update(
		$table,
		[
			'question' => $question,
			'answer'   => $answer,
			'url'      => $url,
		],
		[ 'id' => $id ],
		[ '%s', '%s', '%s' ],
		[ '%d' ]
	);

	if ( $ok === false ) {
		return [ 'ok' => false, 'message' => 'No se pudo actualizar el log.' ];
	}

	estandar_chatbot_logs_cache_bust();
	return [ 'ok' => true, 'message' => 'Log actualizado correctamente.' ];
}

function estandar_chatbot_get_logs_csv_content() {
	$logs = estandar_chatbot_get_logs( 1000000 );
	$out  = fopen( 'php://temp', 'r+' );
	$header = [
		'datetime',
		'interaction_type',
		'session_key',
		'lead_identifier',
		'page_url',
		'potential_faq',
		'question',
		'answer',
		'ip',
		'user_agent',
		'url',
	];
	fputcsv( $out, $header );
	foreach ( array_reverse( $logs ) as $row ) {
		$meta = [];
		if ( ! empty( $row['meta'] ) ) {
			$decoded = json_decode( (string) $row['meta'], true );
			$meta    = is_array( $decoded ) ? $decoded : [];
		}
		fputcsv(
			$out,
			[
				estandar_chatbot_csv_safe_cell( (string) ( $row['datetime'] ?? '' ) ),
				estandar_chatbot_csv_safe_cell( (string) ( $meta['interaction_type'] ?? '' ) ),
				estandar_chatbot_csv_safe_cell( (string) ( $meta['session_key'] ?? '' ) ),
				estandar_chatbot_csv_safe_cell( (string) ( $meta['lead_identifier'] ?? '' ) ),
				estandar_chatbot_csv_safe_cell( (string) ( $meta['page_url'] ?? '' ) ),
				estandar_chatbot_csv_safe_cell( (string) ( $meta['potential_faq'] ?? '' ) ),
				estandar_chatbot_csv_safe_cell( (string) ( $row['question'] ?? '' ) ),
				estandar_chatbot_csv_safe_cell( (string) ( $row['answer'] ?? '' ) ),
				estandar_chatbot_csv_safe_cell( (string) ( $row['ip'] ?? '' ) ),
				estandar_chatbot_csv_safe_cell( (string) ( $row['user_agent'] ?? '' ) ),
				estandar_chatbot_csv_safe_cell( (string) ( $row['url'] ?? '' ) ),
			]
		);
	}
	rewind( $out );
	$content = stream_get_contents( $out );
	fclose( $out );
	return $content;
}

function estandar_chatbot_output_logs_csv( $download_name = 'estandar-chatbot-logs.csv' ) {
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . basename( $download_name ) . '"' );
	echo estandar_chatbot_get_logs_csv_content();
	exit;
}

add_action( 'estandar_chatbot_daily_maintenance', 'estandar_chatbot_daily_maintenance_handler' );
function estandar_chatbot_daily_maintenance_handler() {
	estandar_chatbot_purge_old_logs();
}
