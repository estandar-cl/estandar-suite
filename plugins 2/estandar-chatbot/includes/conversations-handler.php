<?php
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ESTANDAR_CHATBOT_CONVERSATIONS_COUNT_TRANSIENT', 'ecb_conversations_count_v1' );

function estandar_chatbot_get_conversations_table() {
	global $wpdb;
	return $wpdb->prefix . 'ecb_conversations';
}

function estandar_chatbot_ensure_conversations_table() {
	global $wpdb;

	$table = estandar_chatbot_get_conversations_table();
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		session_key VARCHAR(190) NOT NULL,
		channel VARCHAR(40) NOT NULL,
		user_id BIGINT UNSIGNED NULL,
		lead_email VARCHAR(190) NULL,
		lead_phone VARCHAR(40) NULL,
		conversation_status VARCHAR(40) NOT NULL DEFAULT 'open',
		message_count INT UNSIGNED NOT NULL DEFAULT 0,
		first_message_at DATETIME NOT NULL,
		last_message_at DATETIME NOT NULL,
		summary LONGTEXT NULL,
		messages LONGTEXT NOT NULL,
		meta LONGTEXT NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_session_channel (session_key, channel),
		KEY idx_last_message_at (last_message_at),
		KEY idx_channel (channel)
	) {$charset_collate};";

	dbDelta( $sql );
}

function estandar_chatbot_normalize_session_key( $session_key ) {
	$session_key = sanitize_text_field( (string) $session_key );
	$session_key = preg_replace( '/[^a-zA-Z0-9:_-]/', '', $session_key );
	return substr( (string) $session_key, 0, 190 );
}

function estandar_chatbot_normalize_channel( $channel ) {
	$channel = sanitize_key( (string) $channel );
	$allowed = function_exists( 'estandar_chatbot_get_allowed_channels' )
		? estandar_chatbot_get_allowed_channels()
		: [ 'web', 'whatsapp', 'telegram', 'api' ];
	return in_array( $channel, $allowed, true ) ? $channel : 'web';
}

function estandar_chatbot_conversations_cache_bust() {
	delete_transient( ESTANDAR_CHATBOT_CONVERSATIONS_COUNT_TRANSIENT );
}

function estandar_chatbot_upsert_conversation( $session_key, $channel = 'web', array $attrs = [] ) {
	global $wpdb;

	estandar_chatbot_ensure_conversations_table();

	$session_key = estandar_chatbot_normalize_session_key( $session_key );
	$channel = estandar_chatbot_normalize_channel( $channel );
	if ( $session_key === '' ) {
		return 0;
	}

	$table = estandar_chatbot_get_conversations_table();
	$existing = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE session_key = %s AND channel = %s LIMIT 1",
			$session_key,
			$channel
		),
		ARRAY_A
	);

	$now = current_time( 'mysql' );
	$user_id = isset( $attrs['user_id'] ) ? max( 0, (int) $attrs['user_id'] ) : 0;
	$lead_email = isset( $attrs['lead_email'] ) ? estandar_chatbot_normalize_email( $attrs['lead_email'] ) : '';
	$lead_phone = isset( $attrs['lead_phone'] ) ? estandar_chatbot_normalize_phone( $attrs['lead_phone'] ) : '';
	$status = isset( $attrs['conversation_status'] ) ? sanitize_key( (string) $attrs['conversation_status'] ) : '';
	$summary = isset( $attrs['summary'] ) ? sanitize_textarea_field( (string) $attrs['summary'] ) : '';
	$meta = isset( $attrs['meta'] ) && is_array( $attrs['meta'] ) ? $attrs['meta'] : [];

	if ( is_array( $existing ) ) {
		$existing_meta = json_decode( (string) ( $existing['meta'] ?? '{}' ), true );
		if ( ! is_array( $existing_meta ) ) {
			$existing_meta = [];
		}
		$new_meta = array_merge( $existing_meta, $meta );
		$data = [];
		if ( $user_id > 0 ) {
			$data['user_id'] = $user_id;
		}
		if ( $lead_email !== '' ) {
			$data['lead_email'] = $lead_email;
		}
		if ( $lead_phone !== '' ) {
			$data['lead_phone'] = $lead_phone;
		}
		if ( $status !== '' ) {
			$data['conversation_status'] = $status;
		}
		if ( $summary !== '' ) {
			$data['summary'] = $summary;
		}
		if ( ! empty( $new_meta ) ) {
			$data['meta'] = wp_json_encode( $new_meta );
		}
		if ( ! empty( $data ) ) {
			$wpdb->update( $table, $data, [ 'id' => (int) $existing['id'] ] );
		}
		return (int) $existing['id'];
	}

	$ok = $wpdb->insert(
		$table,
		[
			'session_key'         => $session_key,
			'channel'             => $channel,
			'user_id'             => $user_id > 0 ? $user_id : null,
			'lead_email'          => $lead_email !== '' ? $lead_email : null,
			'lead_phone'          => $lead_phone !== '' ? $lead_phone : null,
			'conversation_status' => $status !== '' ? $status : 'open',
			'message_count'       => 0,
			'first_message_at'    => $now,
			'last_message_at'     => $now,
			'summary'             => $summary !== '' ? $summary : null,
			'messages'            => '[]',
			'meta'                => wp_json_encode( $meta ),
		],
		[ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
	);

	if ( ! $ok ) {
		return 0;
	}

	estandar_chatbot_conversations_cache_bust();
	return (int) $wpdb->insert_id;
}

function estandar_chatbot_append_conversation_message( $session_key, $channel, $role, $content, array $meta = [] ) {
	global $wpdb;

	$content = sanitize_textarea_field( (string) $content );
	$role = sanitize_key( (string) $role );
	$session_key = estandar_chatbot_normalize_session_key( $session_key );
	$channel = estandar_chatbot_normalize_channel( $channel );

	if ( $session_key === '' || $content === '' || ! in_array( $role, [ 'user', 'assistant', 'system' ], true ) ) {
		return 0;
	}

	$lock_name = 'ecb_conv_' . md5( $session_key . ':' . $channel );
	if ( ! estandar_chatbot_acquire_db_lock( $lock_name, 5 ) ) {
		return 0;
	}

	try {
		$conversation_id = estandar_chatbot_upsert_conversation( $session_key, $channel );
		if ( $conversation_id <= 0 ) {
			return 0;
		}

		$table = estandar_chatbot_get_conversations_table();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $conversation_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return 0;
		}

		$messages = json_decode( (string) ( $row['messages'] ?? '[]' ), true );
		if ( ! is_array( $messages ) ) {
			$messages = [];
		}

		$messages[] = [
			'at'      => current_time( 'mysql' ),
			'role'    => $role,
			'content' => $content,
			'meta'    => $meta,
		];
		$messages = array_slice( $messages, -80 );

		$ok = $wpdb->update(
			$table,
			[
				'messages'        => wp_json_encode( $messages ),
				'message_count'   => count( $messages ),
				'last_message_at' => current_time( 'mysql' ),
			],
			[ 'id' => $conversation_id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);

		if ( $ok !== false ) {
			estandar_chatbot_conversations_cache_bust();
		}

		return $conversation_id;
	} finally {
		estandar_chatbot_release_db_lock( $lock_name );
	}
}

function estandar_chatbot_get_conversation_messages( $session_key, $channel = 'web', $limit = 80 ) {
	global $wpdb;

	estandar_chatbot_ensure_conversations_table();

	$session_key = estandar_chatbot_normalize_session_key( $session_key );
	$channel = estandar_chatbot_normalize_channel( $channel );
	$limit = max( 1, min( 80, (int) $limit ) );

	if ( $session_key === '' ) {
		return [];
	}

	$table = estandar_chatbot_get_conversations_table();
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT messages FROM {$table} WHERE session_key = %s AND channel = %s LIMIT 1",
			$session_key,
			$channel
		),
		ARRAY_A
	);
	if ( ! is_array( $row ) ) {
		return [];
	}

	$messages = json_decode( (string) ( $row['messages'] ?? '[]' ), true );
	if ( ! is_array( $messages ) ) {
		return [];
	}

	return array_slice( $messages, -1 * $limit );
}

/**
 * CONVERSATION_AI_META_V1 — Gather AI metadata for the current request.
 *
 * Returns a structured array of metadata to store in the conversation meta field.
 * All fields are optional; consumers merge new keys without overwriting existing.
 *
 * Metadata schema (stored as JSON in ecb_conversations.meta):
 *   conversation_ai_meta_v1: {
 *     provider_id, model, adapter, gateway_version,
 *     prompt_version, kb_version, kb_hash,
 *     tools, context_slug,
 *     temperature, max_tokens, response_format,
 *     duration_ms, tokens_prompt, tokens_completion, tokens_total, cost_estimate,
 *     error_flag, error_code
 *   }
 */
function estandar_chatbot_ai_conversation_meta( array $extra = array() ): array {
	$meta = array();

	// Provider info (from AI Gateway when available)
	if ( class_exists( 'Estandar_AI_Gateway' ) ) {
		$meta['provider_id'] = Estandar_AI_Gateway::active_provider_id();
		$meta['adapter']     = Estandar_AI_Gateway::active_provider_label();
		$meta['gateway_version'] = '1.0';
	}

	// Model used (from request params or global default)
	$model = $extra['model'] ?? '';
	if ( $model === '' && class_exists( 'Estandar_AI_Gateway' ) ) {
		$model = Estandar_AI_Gateway::default_model();
	}
	if ( $model !== '' ) {
		$meta['model'] = $model;
	}

	// Prompt version
	$prompt_version = defined( 'ESTANDAR_CHATBOT_PROMPT_VERSION' ) ? ESTANDAR_CHATBOT_PROMPT_VERSION : '1.0';
	$meta['prompt_version'] = apply_filters( 'estandar_chatbot_prompt_version', $prompt_version );

	// Knowledge Base
	$context_path = (string) get_option( 'chatbot_context_txt_path', '' );
	if ( $context_path !== '' && file_exists( $context_path ) ) {
		$meta['kb_hash'] = hash_file( 'sha256', $context_path );
		$meta['kb_version'] = (string) get_option( 'chatbot_context_version', '' );
	}

	// Context slug
	if ( ! empty( $extra['context_slug'] ) ) {
		$meta['context_slug'] = sanitize_key( (string) $extra['context_slug'] );
	}

	// Tools used (from request)
	if ( ! empty( $extra['tools'] ) ) {
		$meta['tools'] = $extra['tools'];
	}

	// Configuration snapshot
	if ( isset( $extra['temperature'] ) ) {
		$meta['temperature'] = (float) $extra['temperature'];
	}
	if ( isset( $extra['max_tokens'] ) ) {
		$meta['max_tokens'] = (int) $extra['max_tokens'];
	}
	if ( ! empty( $extra['response_format'] ) ) {
		$meta['response_format'] = (string) $extra['response_format'];
	}

	// Timing (computed here if not provided externally)
	$meta['recorded_at'] = current_time( 'mysql' );

	// Token usage and errors — passed through from the AI response
	foreach ( array( 'tokens_prompt', 'tokens_completion', 'tokens_total', 'cost_estimate', 'duration_ms', 'error_flag', 'error_code' ) as $k ) {
		if ( isset( $extra[ $k ] ) ) {
			$meta[ $k ] = $extra[ $k ];
		}
	}

	return array( 'conversation_ai_meta_v1' => $meta );
}

function estandar_chatbot_bind_conversation_to_lead( $session_key, $channel, array $lead ) {
	$email = estandar_chatbot_normalize_email( $lead['email'] ?? '' );
	$phone = estandar_chatbot_normalize_phone( $lead['phone'] ?? '' );
	$meta = [];
	if ( ! empty( $lead['name'] ) ) {
		$meta['lead_name'] = sanitize_text_field( (string) $lead['name'] );
	}
	return estandar_chatbot_upsert_conversation( $session_key, $channel, [
		'lead_email' => $email,
		'lead_phone' => $phone,
		'meta'       => $meta,
	] );
}

function estandar_chatbot_count_conversations() {
	global $wpdb;

	$cached = get_transient( ESTANDAR_CHATBOT_CONVERSATIONS_COUNT_TRANSIENT );
	if ( $cached !== false ) {
		return (int) $cached;
	}

	estandar_chatbot_ensure_conversations_table();
	$table = estandar_chatbot_get_conversations_table();
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	set_transient( ESTANDAR_CHATBOT_CONVERSATIONS_COUNT_TRANSIENT, $count, 30 );
	return $count;
}

function estandar_chatbot_get_conversations( $limit = 100, $offset = 0, array $filters = [] ) {
	global $wpdb;

	estandar_chatbot_ensure_conversations_table();
	$limit = max( 1, min( 500, (int) $limit ) );
	$offset = max( 0, (int) $offset );

	$table = estandar_chatbot_get_conversations_table();
	$where = [];
	$params = [];

	$channel = isset( $filters['channel'] ) ? estandar_chatbot_normalize_channel( $filters['channel'] ) : '';
	if ( $channel !== '' && ! empty( $filters['channel'] ) ) {
		$where[] = 'channel = %s';
		$params[] = $channel;
	}

	$status = isset( $filters['conversation_status'] ) ? sanitize_key( (string) $filters['conversation_status'] ) : '';
	if ( $status !== '' ) {
		$where[] = 'conversation_status = %s';
		$params[] = $status;
	}

	$search = isset( $filters['search'] ) ? sanitize_text_field( (string) $filters['search'] ) : '';
	if ( $search !== '' ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = '(session_key LIKE %s OR lead_email LIKE %s OR lead_phone LIKE %s OR summary LIKE %s)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}

	$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
	$params[] = $limit;
	$params[] = $offset;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} {$where_sql} ORDER BY last_message_at DESC LIMIT %d OFFSET %d",
			$params
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return [];
	}

	$out = [];
	foreach ( $rows as $row ) {
		$messages = json_decode( (string) ( $row['messages'] ?? '[]' ), true );
		if ( ! is_array( $messages ) ) {
			$messages = [];
		}
		$meta = json_decode( (string) ( $row['meta'] ?? '{}' ), true );
		if ( ! is_array( $meta ) ) {
			$meta = [];
		}
		$out[] = [
			'id'                  => (int) ( $row['id'] ?? 0 ),
			'session_key'         => (string) ( $row['session_key'] ?? '' ),
			'channel'             => (string) ( $row['channel'] ?? '' ),
			'user_id'             => (int) ( $row['user_id'] ?? 0 ),
			'lead_email'          => (string) ( $row['lead_email'] ?? '' ),
			'lead_phone'          => (string) ( $row['lead_phone'] ?? '' ),
			'conversation_status' => (string) ( $row['conversation_status'] ?? '' ),
			'message_count'       => (int) ( $row['message_count'] ?? 0 ),
			'first_message_at'    => (string) ( $row['first_message_at'] ?? '' ),
			'last_message_at'     => (string) ( $row['last_message_at'] ?? '' ),
			'summary'             => (string) ( $row['summary'] ?? '' ),
			'messages'            => $messages,
			'meta'                => $meta,
		];
	}

	return $out;
}

function estandar_chatbot_count_conversations_filtered( array $filters = [] ) {
	global $wpdb;

	estandar_chatbot_ensure_conversations_table();
	$table = estandar_chatbot_get_conversations_table();
	$where = [];
	$params = [];

	$channel = isset( $filters['channel'] ) ? estandar_chatbot_normalize_channel( $filters['channel'] ) : '';
	if ( $channel !== '' && ! empty( $filters['channel'] ) ) {
		$where[] = 'channel = %s';
		$params[] = $channel;
	}

	$status = isset( $filters['conversation_status'] ) ? sanitize_key( (string) $filters['conversation_status'] ) : '';
	if ( $status !== '' ) {
		$where[] = 'conversation_status = %s';
		$params[] = $status;
	}

	$search = isset( $filters['search'] ) ? sanitize_text_field( (string) $filters['search'] ) : '';
	if ( $search !== '' ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = '(session_key LIKE %s OR lead_email LIKE %s OR lead_phone LIKE %s OR summary LIKE %s)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
	}

	$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
	if ( empty( $params ) ) {
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
	}
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $params )
	);
}

function estandar_chatbot_get_conversation_by_id( $id ) {
	$id = (int) $id;
	if ( $id <= 0 ) {
		return null;
	}

	$list = estandar_chatbot_get_conversations( 1, 0 );
	foreach ( $list as $item ) {
		if ( (int) $item['id'] === $id ) {
			return $item;
		}
	}

	global $wpdb;
	$table = estandar_chatbot_get_conversations_table();
	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
		ARRAY_A
	);
	if ( ! is_array( $row ) ) {
		return null;
	}
	$messages = json_decode( (string) ( $row['messages'] ?? '[]' ), true );
	$meta = json_decode( (string) ( $row['meta'] ?? '{}' ), true );
	return [
		'id'                  => (int) ( $row['id'] ?? 0 ),
		'session_key'         => (string) ( $row['session_key'] ?? '' ),
		'channel'             => (string) ( $row['channel'] ?? '' ),
		'user_id'             => (int) ( $row['user_id'] ?? 0 ),
		'lead_email'          => (string) ( $row['lead_email'] ?? '' ),
		'lead_phone'          => (string) ( $row['lead_phone'] ?? '' ),
		'conversation_status' => (string) ( $row['conversation_status'] ?? '' ),
		'message_count'       => (int) ( $row['message_count'] ?? 0 ),
		'first_message_at'    => (string) ( $row['first_message_at'] ?? '' ),
		'last_message_at'     => (string) ( $row['last_message_at'] ?? '' ),
		'summary'             => (string) ( $row['summary'] ?? '' ),
		'messages'            => is_array( $messages ) ? $messages : [],
		'meta'                => is_array( $meta ) ? $meta : [],
	];
}

function estandar_chatbot_get_conversation_display_name( array $conversation, $fallback_index = 0 ) {
	$meta = isset( $conversation['meta'] ) && is_array( $conversation['meta'] ) ? $conversation['meta'] : [];
	$name = sanitize_text_field( (string) ( $meta['lead_name'] ?? '' ) );
	$email = estandar_chatbot_normalize_email( (string) ( $conversation['lead_email'] ?? '' ) );
	$phone = estandar_chatbot_normalize_phone( (string) ( $conversation['lead_phone'] ?? '' ) );

	if ( $name !== '' ) {
		return $name;
	}
	if ( $email !== '' ) {
		return $email;
	}
	if ( $phone !== '' ) {
		return $phone;
	}

	$time_label = '';
	$last_message_at = (string) ( $conversation['last_message_at'] ?? '' );
	if ( $last_message_at !== '' ) {
		$timestamp = strtotime( $last_message_at );
		if ( $timestamp ) {
			$time_label = wp_date( 'H:i', $timestamp );
		}
	}

	if ( $time_label !== '' ) {
		return sprintf( 'Usuario sin datos - %s', $time_label );
	}

	return sprintf( 'Usuario desconocido #%d', max( 1, (int) $fallback_index ) );
}

function estandar_chatbot_get_conversation_contact_state( array $conversation ) {
	$meta = isset( $conversation['meta'] ) && is_array( $conversation['meta'] ) ? $conversation['meta'] : [];
	$name = sanitize_text_field( (string) ( $meta['lead_name'] ?? '' ) );
	$email = estandar_chatbot_normalize_email( (string) ( $conversation['lead_email'] ?? '' ) );
	$phone = estandar_chatbot_normalize_phone( (string) ( $conversation['lead_phone'] ?? '' ) );

	if ( $email !== '' || $phone !== '' ) {
		return 'Lead capturado';
	}
	if ( $name !== '' ) {
		return 'Lead parcial';
	}
	return 'Sin datos';
}

function estandar_chatbot_get_conversation_export_name( array $conversation ) {
	$meta = isset( $conversation['meta'] ) && is_array( $conversation['meta'] ) ? $conversation['meta'] : [];
	$name = trim( sanitize_text_field( (string) ( $meta['lead_name'] ?? $meta['name'] ?? '' ) ) );

	if ( $name !== '' ) {
		return $name;
	}

	$lead = function_exists( 'estandar_chatbot_find_lead' )
		? estandar_chatbot_find_lead(
			(string) ( $conversation['lead_email'] ?? '' ),
			(string) ( $conversation['lead_phone'] ?? '' )
		)
		: null;

	return is_array( $lead ) ? trim( sanitize_text_field( (string) ( $lead['name'] ?? '' ) ) ) : '';
}

function estandar_chatbot_get_conversation_export_intent( array $conversation ) {
	$meta = isset( $conversation['meta'] ) && is_array( $conversation['meta'] ) ? $conversation['meta'] : [];

	foreach ( [ 'intent_label', 'intent' ] as $key ) {
		$value = trim( sanitize_text_field( (string) ( $meta[ $key ] ?? '' ) ) );
		if ( $value !== '' ) {
			if ( $key === 'intent' && function_exists( 'estandar_chatbot_humanize_lead_intent' ) ) {
				return estandar_chatbot_humanize_lead_intent( $value, [] );
			}
			return $value;
		}
	}

	$lead = function_exists( 'estandar_chatbot_find_lead' )
		? estandar_chatbot_find_lead(
			(string) ( $conversation['lead_email'] ?? '' ),
			(string) ( $conversation['lead_phone'] ?? '' )
		)
		: null;

	if ( is_array( $lead ) ) {
		$intent = trim( sanitize_text_field( (string) ( $lead['intent'] ?? '' ) ) );
		if ( $intent !== '' && function_exists( 'estandar_chatbot_humanize_lead_intent' ) ) {
			return estandar_chatbot_humanize_lead_intent( $intent, $lead );
		}
		return $intent;
	}

	return '';
}

function estandar_chatbot_format_conversation_messages_for_export( array $messages ) {
	$lines = [];

	foreach ( $messages as $message ) {
		if ( ! is_array( $message ) ) {
			continue;
		}

		$role = sanitize_key( (string) ( $message['role'] ?? '' ) );
		$prefix = 'SYSTEM';
		if ( $role === 'user' ) {
			$prefix = 'USER';
		} elseif ( $role === 'assistant' ) {
			$prefix = 'ASSISTANT';
		}

		$content = wp_check_invalid_utf8( (string) ( $message['content'] ?? '' ) );
		$content = str_replace( [ "\r\n", "\r" ], "\n", $content );
		$content = trim( $content );

		if ( $content === '' ) {
			continue;
		}

		$lines[] = $prefix . ': ' . $content;
	}

	return implode( "\n", $lines );
}

function estandar_chatbot_get_conversations_csv_content( array $filters = [] ) {
	$conversations = function_exists( 'estandar_chatbot_get_conversations' )
		? estandar_chatbot_get_conversations( 1000000, 0, $filters )
		: [];
	$out = fopen( 'php://temp', 'r+' );

	fputcsv(
		$out,
		[ 'fecha', 'nombre', 'email', 'telefono', 'sesion', 'canal', 'estado', 'intencion', 'mensajes' ]
	);

	foreach ( array_reverse( (array) $conversations ) as $conversation ) {
		$row = [
			estandar_chatbot_csv_safe_cell( (string) ( $conversation['first_message_at'] ?? $conversation['last_message_at'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( estandar_chatbot_get_conversation_export_name( $conversation ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $conversation['lead_email'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $conversation['lead_phone'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $conversation['session_key'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( (string) ( $conversation['channel'] ?? '' ) ),
			estandar_chatbot_csv_safe_cell( strtoupper( (string) ( $conversation['conversation_status'] ?? '' ) ) ),
			estandar_chatbot_csv_safe_cell( estandar_chatbot_get_conversation_export_intent( $conversation ) ),
			estandar_chatbot_csv_safe_cell(
				estandar_chatbot_format_conversation_messages_for_export(
					is_array( $conversation['messages'] ?? null ) ? $conversation['messages'] : []
				)
			),
		];
		fputcsv( $out, $row );
	}

	rewind( $out );
	$content = stream_get_contents( $out );
	fclose( $out );

	return $content;
}

function estandar_chatbot_output_conversations_csv( $download_name = 'estandar-chatbot-conversaciones.csv', array $filters = [] ) {
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . basename( $download_name ) . '"' );
	echo estandar_chatbot_get_conversations_csv_content( $filters );
	exit;
}

function estandar_chatbot_update_conversation_status( $id, $status, $summary = '' ) {
	global $wpdb;

	$id = (int) $id;
	if ( $id <= 0 ) {
		return [ 'ok' => false, 'message' => 'ID inválido.' ];
	}

	$status = sanitize_key( (string) $status );
	$allowed = [ 'open', 'qualified', 'human_handoff', 'closed', 'archived' ];
	if ( ! in_array( $status, $allowed, true ) ) {
		$status = 'open';
	}

	$data = [ 'conversation_status' => $status ];
	$formats = [ '%s' ];
	if ( $summary !== '' ) {
		$data['summary'] = sanitize_textarea_field( $summary );
		$formats[] = '%s';
	}

	$ok = $wpdb->update(
		estandar_chatbot_get_conversations_table(),
		$data,
		[ 'id' => $id ],
		$formats,
		[ '%d' ]
	);

	if ( $ok === false ) {
		return [ 'ok' => false, 'message' => 'No se pudo actualizar la conversación.' ];
	}

	estandar_chatbot_conversations_cache_bust();
	return [ 'ok' => true, 'message' => 'Conversación actualizada.' ];
}

function estandar_chatbot_update_conversations_status_by_ids( array $ids, $status ) {
	global $wpdb;

	$ids = array_values( array_filter( array_map( 'intval', $ids ), function( $v ) { return $v > 0; } ) );
	if ( empty( $ids ) ) {
		return 0;
	}

	$status = sanitize_key( (string) $status );
	$allowed = [ 'open', 'qualified', 'human_handoff', 'closed', 'archived' ];
	if ( ! in_array( $status, $allowed, true ) ) {
		$status = 'open';
	}

	$table = estandar_chatbot_get_conversations_table();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$params = array_merge( [ $status ], $ids );
	$sql = "UPDATE {$table} SET conversation_status = %s WHERE id IN ({$placeholders})";
	$updated = $wpdb->query( $wpdb->prepare( $sql, $params ) );
	if ( $updated !== false ) {
		estandar_chatbot_conversations_cache_bust();
	}
	return max( 0, (int) $updated );
}

function estandar_chatbot_get_conversation_refs_by_ids( array $ids ) {
	global $wpdb;

	$ids = array_values( array_filter( array_map( 'intval', $ids ), function( $v ) { return $v > 0; } ) );
	if ( empty( $ids ) ) {
		return [];
	}

	estandar_chatbot_ensure_conversations_table();
	$table = estandar_chatbot_get_conversations_table();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, session_key, channel FROM {$table} WHERE id IN ({$placeholders})",
			$ids
		),
		ARRAY_A
	);

	return is_array( $rows ) ? $rows : [];
}

function estandar_chatbot_delete_conversation_by_id( $id ) {
	return estandar_chatbot_delete_conversations_by_ids( [ (int) $id ] );
}

function estandar_chatbot_delete_conversations_by_ids( array $ids ) {
	global $wpdb;

	$ids = array_values( array_filter( array_map( 'intval', $ids ), function( $v ) { return $v > 0; } ) );
	if ( empty( $ids ) ) {
		return 0;
	}

	$refs = estandar_chatbot_get_conversation_refs_by_ids( $ids );
	if ( empty( $refs ) ) {
		return 0;
	}

	$table = estandar_chatbot_get_conversations_table();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE id IN ({$placeholders})",
			$ids
		)
	);

	if ( $deleted === false ) {
		return 0;
	}

	if ( function_exists( 'estandar_chatbot_delete_events_for_conversation_refs' ) ) {
		estandar_chatbot_delete_events_for_conversation_refs( $refs );
	}

	estandar_chatbot_conversations_cache_bust();
	return (int) $deleted;
}

function estandar_chatbot_delete_all_conversations() {
	global $wpdb;

	estandar_chatbot_ensure_conversations_table();
	$table = estandar_chatbot_get_conversations_table();
	$refs = $wpdb->get_results( "SELECT id, session_key, channel FROM {$table}", ARRAY_A );
	$refs = is_array( $refs ) ? $refs : [];

	$deleted = $wpdb->query( "DELETE FROM {$table}" );
	if ( $deleted === false ) {
		return 0;
	}

	if ( function_exists( 'estandar_chatbot_delete_events_for_conversation_refs' ) && ! empty( $refs ) ) {
		estandar_chatbot_delete_events_for_conversation_refs( $refs );
	}

	estandar_chatbot_conversations_cache_bust();
	return (int) $deleted;
}

/**
 * ── CONVERSATION_EFFECTIVENESS_EXPORT_V1 — lógica compartida ─────────────────
 *
 * Misma lógica que tools/estandar-conversation-effectiveness-export.php (script CLI,
 * aprobado para CONVERSATION_EFFECTIVENESS_AUDIT_V1). Extraída aquí como fuente única
 * de verdad: el script CLI y el botón de Herramientas → Operación segura llaman a
 * estandar_chatbot_build_conversation_effectiveness_export() — sin duplicar
 * redacción ni lookup de lead.
 *
 * READ-ONLY: solo SELECT sobre wp_ecb_conversations / wp_ecb_leads. Cero escrituras
 * en base de datos. Cero cambios de comportamiento del chatbot.
 */

/**
 * Redacción defensiva de secretos/credenciales (API keys, tokens, contraseñas, etc.)
 * que pudieran haber quedado pegados en un mensaje de usuario. No toca PII de
 * negocio (nombres, emails, teléfonos, intención) — ese es el dato que esta
 * exportación existe para capturar.
 */
function estandar_chatbot_ecee_redact_secrets( $text ) {
	if ( ! is_string( $text ) || $text === '' ) {
		return $text;
	}

	$patterns = [
		// Bearer / Authorization headers pegados por error
		'/\b(Bearer|Authorization:\s*Bearer)\s+[A-Za-z0-9\-_\.=]+/i' => '$1 [REDACTED]',
		// API keys con prefijos comunes (OpenAI, Stripe, AWS, etc.)
		'/\b(sk|pk|rk|whsec|AKIA)[A-Za-z0-9_\-]{12,}/' => '[REDACTED_KEY]',
		// pares clave=valor / clave: valor para password/token/secret/api_key
		'/\b((?:api[_-]?key|token|secret|password|contrase[ñn]a|pass)\s*[:=]\s*)\S+/i' => '$1[REDACTED]',
		// cadenas largas hex (>=32) o base64 (>=40) sueltas — probable credencial/hash
		'/\b[A-Fa-f0-9]{32,}\b/' => '[REDACTED_HEX]',
		'/\b[A-Za-z0-9+\/]{40,}={0,2}\b/' => '[REDACTED_B64]',
	];

	foreach ( $patterns as $pattern => $replacement ) {
		$text = preg_replace( $pattern, $replacement, $text );
	}

	return $text;
}

/**
 * Aplica estandar_chatbot_ecee_redact_secrets recursivamente a cualquier string
 * dentro de un array/valor arbitrario (usado para meta y para mensajes).
 */
function estandar_chatbot_ecee_redact_recursive( $value ) {
	if ( is_string( $value ) ) {
		return estandar_chatbot_ecee_redact_secrets( $value );
	}
	if ( is_array( $value ) ) {
		$out = [];
		foreach ( $value as $k => $v ) {
			$out[ $k ] = estandar_chatbot_ecee_redact_recursive( $v );
		}
		return $out;
	}
	return $value;
}

/**
 * Busca el lead asociado (mismo criterio que estandar_chatbot_find_lead: email O
 * phone, primer match por id ASC) y devuelve id + proyecto si existe. Lectura
 * directa (SELECT) para no depender de la forma de retorno de otros helpers.
 */
function estandar_chatbot_ecee_find_lead_project( $leads_table, $email, $phone ) {
	global $wpdb;

	$email = trim( (string) $email );
	$phone = trim( (string) $phone );

	if ( $email === '' && $phone === '' ) {
		return [ 'lead_id' => null, 'project' => '' ];
	}

	$where  = [];
	$params = [];
	if ( $email !== '' ) {
		$where[]  = 'email = %s';
		$params[] = $email;
	}
	if ( $phone !== '' ) {
		$where[]  = 'phone = %s';
		$params[] = $phone;
	}

	$sql = "SELECT id, data FROM {$leads_table} WHERE " . implode( ' OR ', $where ) . ' ORDER BY id ASC LIMIT 1';
	$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

	if ( ! is_array( $row ) ) {
		return [ 'lead_id' => null, 'project' => '' ];
	}

	$data = json_decode( (string) ( $row['data'] ?? '{}' ), true );
	if ( ! is_array( $data ) ) {
		$data = [];
	}

	$project = trim( (string) ( $data['project_area'] ?? $data['project_slug'] ?? '' ) );

	return [
		'lead_id' => isset( $row['id'] ) ? (int) $row['id'] : null,
		'project' => $project,
	];
}

/**
 * Construye el array de export CONVERSATION_EFFECTIVENESS_EXPORT_V1 (misma forma
 * documentada en ESTANDAR_CONVERSATION_EFFECTIVENESS_EXPORT_V1.md). Read-only:
 * SELECTs sobre wp_ecb_conversations / wp_ecb_leads, cero escrituras.
 *
 * @return array{ok:bool, export:?array, message:string}
 *         'ok' => false solo si wp_ecb_conversations no existe (no es un error duro,
 *         puede pasar en instalaciones nuevas).
 */
function estandar_chatbot_build_conversation_effectiveness_export( $limit = 100, $min_messages = 2 ) {
	global $wpdb;

	$conv_table  = $wpdb->prefix . 'ecb_conversations';
	$leads_table = $wpdb->prefix . 'ecb_leads';

	$limit        = max( 1, (int) $limit );
	$min_messages = max( 1, (int) $min_messages );

	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conv_table ) ) ) {
		return [ 'ok' => false, 'export' => null, 'message' => 'wp_ecb_conversations no existe en esta instalación.' ];
	}

	$total_sessions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$conv_table}" );
	$short_sessions = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$conv_table} WHERE message_count < %d", $min_messages )
	);

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, session_key, channel, lead_email, lead_phone, conversation_status,
			        message_count, first_message_at, last_message_at, summary, messages, meta
			 FROM {$conv_table}
			 WHERE message_count >= %d
			 ORDER BY last_message_at DESC
			 LIMIT %d",
			$min_messages,
			$limit
		),
		ARRAY_A
	);
	$rows = is_array( $rows ) ? $rows : [];

	$sessions  = [];
	$no_lead   = 0;
	$with_lead = 0;

	foreach ( $rows as $row ) {
		$messages_raw = json_decode( (string) ( $row['messages'] ?? '[]' ), true );
		if ( ! is_array( $messages_raw ) ) {
			$messages_raw = [];
		}
		$meta_raw = json_decode( (string) ( $row['meta'] ?? '{}' ), true );
		if ( ! is_array( $meta_raw ) ) {
			$meta_raw = [];
		}

		$lead_email = (string) ( $row['lead_email'] ?? '' );
		$lead_phone = (string) ( $row['lead_phone'] ?? '' );

		$lead_info = estandar_chatbot_ecee_find_lead_project( $leads_table, $lead_email, $lead_phone );

		// fallback de proyecto: si no hay lead vinculado, intentar meta de la conversación
		$project = $lead_info['project'];
		if ( $project === '' ) {
			$project = trim( (string) ( $meta_raw['project_area'] ?? $meta_raw['project_slug'] ?? '' ) );
		}

		if ( $lead_info['lead_id'] !== null ) {
			$with_lead++;
		} else {
			$no_lead++;
		}

		$messages = [];
		foreach ( $messages_raw as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$messages[] = [
				'at'      => (string) ( $m['at'] ?? '' ),
				'role'    => (string) ( $m['role'] ?? '' ),
				'content' => estandar_chatbot_ecee_redact_secrets( (string) ( $m['content'] ?? '' ) ),
			];
		}

		$sessions[] = [
			'conversation_id'     => (int) ( $row['id'] ?? 0 ),
			'session_key'         => (string) ( $row['session_key'] ?? '' ),
			'channel'             => (string) ( $row['channel'] ?? '' ),
			'conversation_status' => (string) ( $row['conversation_status'] ?? '' ),
			'first_message_at'    => (string) ( $row['first_message_at'] ?? '' ),
			'last_message_at'     => (string) ( $row['last_message_at'] ?? '' ),
			'message_count'       => (int) ( $row['message_count'] ?? 0 ),
			'lead'                => [
				'lead_id' => $lead_info['lead_id'],
				'email'   => $lead_email,
				'phone'   => $lead_phone,
				'project' => $project,
			],
			'summary'  => estandar_chatbot_ecee_redact_secrets( (string) ( $row['summary'] ?? '' ) ),
			'meta'     => estandar_chatbot_ecee_redact_recursive( $meta_raw ),
			'messages' => $messages,
		];
	}

	$export = [
		'export_meta' => [
			'tool'                    => 'estandar-conversation-effectiveness-export',
			'version'                 => '1.0',
			'spec'                    => 'ESTANDAR_CONVERSATION_EFFECTIVENESS_AUDIT_V1',
			'site'                    => home_url(),
			'generated_at'            => current_time( 'mysql' ),
			'criteria'                => [
				'limit'        => $limit,
				'min_messages' => $min_messages,
				'order'        => 'last_message_at DESC',
			],
			'sessions_total_db'       => $total_sessions,
			'sessions_excluded_short' => $short_sessions,
			'sessions_exported'       => count( $sessions ),
			'sessions_with_lead'      => $with_lead,
			'sessions_without_lead'   => $no_lead,
		],
		'sessions' => $sessions,
	];

	return [ 'ok' => true, 'export' => $export, 'message' => '' ];
}
