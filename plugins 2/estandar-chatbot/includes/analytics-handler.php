<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// v2: bump de caché al cambiar el proveedor de geolocalización IP
// (ipwho.is → ip-api.com, 2026-07) — evita mezclar resultados viejos y nuevos.
define( 'ESTANDAR_CHATBOT_GEO_IP_CACHE_PREFIX', 'ecb_geo_ip_v2_' );
define( 'ESTANDAR_CHATBOT_GEO_POINTS_CACHE_PREFIX', 'ecb_geo_points_v2_' );
define( 'ESTANDAR_CHATBOT_GEO_FILTERS_CACHE_PREFIX', 'ecb_geo_filters_v1_' );

define( 'ESTANDAR_CHATBOT_EVENTS_COUNT_TRANSIENT', 'ecb_events_count_v1' );

function estandar_chatbot_get_events_table() {
	global $wpdb;
	return $wpdb->prefix . 'ecb_events';
}

function estandar_chatbot_ensure_events_table() {
	global $wpdb;

	$table = estandar_chatbot_get_events_table();
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL,
		event_name VARCHAR(90) NOT NULL,
		channel VARCHAR(40) NOT NULL,
		session_key VARCHAR(190) NULL,
		conversation_id BIGINT UNSIGNED NULL,
		lead_id BIGINT UNSIGNED NULL,
		lead_email VARCHAR(190) NULL,
		lead_phone VARCHAR(40) NULL,
		meta LONGTEXT NOT NULL,
		PRIMARY KEY (id),
		KEY idx_created_at (created_at),
		KEY idx_event_name (event_name),
		KEY idx_channel (channel),
		KEY idx_session_key (session_key)
	) {$charset_collate};";

	dbDelta( $sql );
}

function estandar_chatbot_events_cache_bust() {
	delete_transient( ESTANDAR_CHATBOT_EVENTS_COUNT_TRANSIENT );
}

/**
 * Fila vacía de cohorte por canal (analítica).
 *
 * @return array{conversations:int,events:int,leads:int,won:int}
 */
function estandar_chatbot_analytics_empty_cohort_row() {
	return [
		'conversations' => 0,
		'events'        => 0,
		'leads'         => 0,
		'won'           => 0,
	];
}

/**
 * Asegura un bucket de cohorte para un canal (p. ej. datos históricos con slug nuevo).
 *
 * @param array<string, array> $cohorts
 */
function estandar_chatbot_analytics_ensure_cohort( array &$cohorts, $channel ) {
	$channel = sanitize_key( (string) $channel );
	if ( $channel === '' ) {
		return;
	}
	if ( ! isset( $cohorts[ $channel ] ) ) {
		$cohorts[ $channel ] = estandar_chatbot_analytics_empty_cohort_row();
	}
}

function estandar_chatbot_log_event( array $event ) {
	global $wpdb;

	estandar_chatbot_ensure_events_table();

	$event_name = strtolower( trim( (string) ( $event['event_name'] ?? '' ) ) );
	$event_name = preg_replace( '/[^a-z0-9._:-]/', '', $event_name );
	if ( $event_name === '' ) {
		return false;
	}

	$channel = estandar_chatbot_normalize_channel( $event['channel'] ?? 'web' );
	$session_key = estandar_chatbot_normalize_session_key( $event['session_key'] ?? '' );
	$lead_email = estandar_chatbot_normalize_email( $event['lead_email'] ?? '' );
	$lead_phone = estandar_chatbot_normalize_phone( $event['lead_phone'] ?? '' );
	$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : [];

	$ok = $wpdb->insert(
		estandar_chatbot_get_events_table(),
		[
			'created_at'      => current_time( 'mysql' ),
			'event_name'      => $event_name,
			'channel'         => $channel,
			'session_key'     => $session_key !== '' ? $session_key : null,
			'conversation_id' => ! empty( $event['conversation_id'] ) ? (int) $event['conversation_id'] : null,
			'lead_id'         => ! empty( $event['lead_id'] ) ? (int) $event['lead_id'] : null,
			'lead_email'      => $lead_email !== '' ? $lead_email : null,
			'lead_phone'      => $lead_phone !== '' ? $lead_phone : null,
			'meta'            => wp_json_encode( $meta ),
		],
		[ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
	);

	if ( ! $ok ) {
		return false;
	}

	estandar_chatbot_events_cache_bust();
	return (int) $wpdb->insert_id;
}

function estandar_chatbot_count_events() {
	global $wpdb;

	$cached = get_transient( ESTANDAR_CHATBOT_EVENTS_COUNT_TRANSIENT );
	if ( $cached !== false ) {
		return (int) $cached;
	}

	estandar_chatbot_ensure_events_table();
	$table = estandar_chatbot_get_events_table();
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	set_transient( ESTANDAR_CHATBOT_EVENTS_COUNT_TRANSIENT, $count, 30 );
	return $count;
}

function estandar_chatbot_get_events( $limit = 200, $offset = 0 ) {
	global $wpdb;

	estandar_chatbot_ensure_events_table();
	$limit = max( 1, min( 500, (int) $limit ) );
	$offset = max( 0, (int) $offset );
	$table = estandar_chatbot_get_events_table();
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
			$limit,
			$offset
		),
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return [];
	}

	foreach ( $rows as &$row ) {
		$meta = json_decode( (string) ( $row['meta'] ?? '{}' ), true );
		$row['meta'] = is_array( $meta ) ? $meta : [];
	}
	unset( $row );

	return $rows;
}

function estandar_chatbot_delete_events_for_conversation_refs( array $refs ) {
	global $wpdb;

	if ( empty( $refs ) ) {
		return 0;
	}

	estandar_chatbot_ensure_events_table();
	$table = estandar_chatbot_get_events_table();
	$deleted_total = 0;
	$conversation_ids = array_values(
		array_filter(
			array_map(
				function( $ref ) {
					return isset( $ref['id'] ) ? (int) $ref['id'] : 0;
				},
				$refs
			),
			function( $id ) {
				return $id > 0;
			}
		)
	);

	if ( ! empty( $conversation_ids ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $conversation_ids ), '%d' ) );
		$sql = "DELETE FROM {$table} WHERE conversation_id IN ({$placeholders})";
		$deleted = $wpdb->query( $wpdb->prepare( $sql, $conversation_ids ) );
		if ( $deleted !== false ) {
			$deleted_total += (int) $deleted;
		}
	}

	foreach ( $refs as $ref ) {
		$session_key = function_exists( 'estandar_chatbot_normalize_session_key' )
			? estandar_chatbot_normalize_session_key( (string) ( $ref['session_key'] ?? '' ) )
			: sanitize_text_field( (string) ( $ref['session_key'] ?? '' ) );
		$channel = function_exists( 'estandar_chatbot_normalize_channel' )
			? estandar_chatbot_normalize_channel( (string) ( $ref['channel'] ?? 'web' ) )
			: sanitize_key( (string) ( $ref['channel'] ?? 'web' ) );

		if ( $session_key === '' || $channel === '' ) {
			continue;
		}

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE session_key = %s AND channel = %s",
				$session_key,
				$channel
			)
		);
		if ( $deleted !== false ) {
			$deleted_total += (int) $deleted;
		}
	}

	if ( $deleted_total > 0 ) {
		estandar_chatbot_events_cache_bust();
	}

	return $deleted_total;
}

function estandar_chatbot_get_analytics_summary( $days = 30 ) {
	global $wpdb;

	$days = max( 1, (int) $days );
	$since = estandar_chatbot_local_mysql_time( current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );
	$events_table = estandar_chatbot_get_events_table();
	$leads_table = estandar_chatbot_get_leads_table();
	$conversations_table = estandar_chatbot_get_conversations_table();

	estandar_chatbot_ensure_events_table();
	estandar_chatbot_ensure_leads_table();
	estandar_chatbot_ensure_conversations_table();

	$total_events = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$events_table} WHERE created_at >= %s", $since )
	);
	$total_conversations = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$conversations_table} WHERE first_message_at >= %s", $since )
	);
	$total_leads = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$leads_table} WHERE created_at >= %s", $since )
	);

	$event_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT event_name, COUNT(*) as qty FROM {$events_table} WHERE created_at >= %s GROUP BY event_name ORDER BY qty DESC",
			$since
		),
		ARRAY_A
	);

	$latency_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta FROM {$events_table} WHERE created_at >= %s AND event_name = %s",
			$since,
			'ai.reply_generated'
		),
		ARRAY_A
	);

	$latencies = [];
	foreach ( $latency_rows as $row ) {
		$meta = json_decode( (string) ( $row['meta'] ?? '{}' ), true );
		$latency = isset( $meta['latency_ms'] ) ? (int) $meta['latency_ms'] : 0;
		if ( $latency > 0 ) {
			$latencies[] = $latency;
		}
	}

	$avg_latency = 0;
	if ( ! empty( $latencies ) ) {
		$avg_latency = (int) round( array_sum( $latencies ) / count( $latencies ) );
	}

	$conversion_rate = $total_conversations > 0
		? round( ( $total_leads / max( 1, $total_conversations ) ) * 100, 1 )
		: 0;

	$funnel = [
		'conversations' => $total_conversations,
		'leads'         => $total_leads,
		'human_handoff' => 0,
		'qualified'     => 0,
		'won'           => 0,
	];

	$cohorts = [];
	$channel_seed = function_exists( 'estandar_chatbot_get_allowed_channels' )
		? estandar_chatbot_get_allowed_channels()
		: [ 'web', 'whatsapp', 'telegram', 'api' ];
	foreach ( $channel_seed as $channel_name ) {
		$k = sanitize_key( (string) $channel_name );
		if ( $k === '' ) {
			continue;
		}
		$cohorts[ $k ] = estandar_chatbot_analytics_empty_cohort_row();
	}

	$lead_rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT data FROM {$leads_table} WHERE created_at >= %s", $since ),
		ARRAY_A
	);
	foreach ( $lead_rows as $row ) {
		$data = json_decode( (string) ( $row['data'] ?? '{}' ), true );
		if ( ! is_array( $data ) ) {
			continue;
		}
		$source_channel = sanitize_key( (string) ( $data['source_channel'] ?? 'web' ) );
		$lead_status = sanitize_key( (string) ( $data['lead_status'] ?? 'new' ) );
		$route = sanitize_key( (string) ( $data['lead_route'] ?? '' ) );
		estandar_chatbot_analytics_ensure_cohort( $cohorts, $source_channel );
		$cohorts[ $source_channel ]['leads']++;
		if ( $route === 'human_handoff' ) {
			$funnel['human_handoff']++;
		}
		if ( in_array( $lead_status, [ 'qualified', 'proposal', 'won' ], true ) ) {
			$funnel['qualified']++;
		}
		if ( $lead_status === 'won' ) {
			$funnel['won']++;
			estandar_chatbot_analytics_ensure_cohort( $cohorts, $source_channel );
			$cohorts[ $source_channel ]['won']++;
		}
	}

	$abandonment_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT messages FROM {$conversations_table} WHERE last_message_at >= %s",
			$since
		),
		ARRAY_A
	);
	$abandonments = 0;
	foreach ( (array) $abandonment_rows as $row ) {
		$messages = json_decode( (string) ( $row['messages'] ?? '[]' ), true );
		$messages = is_array( $messages ) ? $messages : [];
		$last = ! empty( $messages ) ? end( $messages ) : null;
		if ( is_array( $last ) && sanitize_key( (string) ( $last['role'] ?? '' ) ) === 'user' ) {
			$abandonments++;
		}
	}
	$abandonment_rate = $total_conversations > 0
		? round( ( $abandonments / max( 1, $total_conversations ) ) * 100, 1 )
		: 0;

	$time_to_lead_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT created_at, data FROM {$leads_table} WHERE created_at >= %s",
			$since
		),
		ARRAY_A
	);
	$time_to_lead_values = [];
	$assisted_count = 0;
	foreach ( (array) $time_to_lead_rows as $row ) {
		$data = json_decode( (string) ( $row['data'] ?? '{}' ), true );
		$data = is_array( $data ) ? $data : [];
		$created = strtotime( (string) ( $row['created_at'] ?? '' ) );
		$first_interaction = strtotime( (string) ( $data['first_interaction_at'] ?? '' ) );
		if ( $created && $first_interaction && $created >= $first_interaction ) {
			$time_to_lead_values[] = (int) round( ( $created - $first_interaction ) / 60 );
		}
		if ( ! empty( $data['force_human'] ) || (string) ( $data['lead_route'] ?? '' ) === 'human_handoff' ) {
			$assisted_count++;
		}
	}
	$avg_time_to_lead_minutes = ! empty( $time_to_lead_values )
		? (int) round( array_sum( $time_to_lead_values ) / count( $time_to_lead_values ) )
		: 0;
	$assisted_usage_rate = $total_leads > 0
		? round( ( $assisted_count / max( 1, $total_leads ) ) * 100, 1 )
		: 0;

	$channel_conversations_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT channel, COUNT(*) as qty FROM {$conversations_table} WHERE first_message_at >= %s GROUP BY channel ORDER BY qty DESC",
			$since
		),
		ARRAY_A
	);

	$channel_events_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT channel, COUNT(*) as qty FROM {$events_table} WHERE created_at >= %s GROUP BY channel ORDER BY qty DESC",
			$since
		),
		ARRAY_A
	);

	foreach ( (array) $channel_conversations_rows as $row ) {
		$channel = sanitize_key( (string) ( $row['channel'] ?? '' ) );
		estandar_chatbot_analytics_ensure_cohort( $cohorts, $channel );
		if ( $channel !== '' ) {
			$cohorts[ $channel ]['conversations'] = (int) ( $row['qty'] ?? 0 );
		}
	}

	foreach ( (array) $channel_events_rows as $row ) {
		$channel = sanitize_key( (string) ( $row['channel'] ?? '' ) );
		estandar_chatbot_analytics_ensure_cohort( $cohorts, $channel );
		if ( $channel !== '' ) {
			$cohorts[ $channel ]['events'] = (int) ( $row['qty'] ?? 0 );
		}
	}

	$timeline = [];
	$base_timestamp = current_time( 'timestamp' );
	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$point = $base_timestamp - ( $i * DAY_IN_SECONDS );
		$key = wp_date( 'Y-m-d', $point, wp_timezone() );
		$timeline[ $key ] = [
			'label'         => wp_date( 'd/m', $point, wp_timezone() ),
			'conversations' => 0,
			'leads'         => 0,
			'events'        => 0,
		];
	}

	$daily_conversations_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DATE(first_message_at) as day_key, COUNT(*) as qty FROM {$conversations_table} WHERE first_message_at >= %s GROUP BY DATE(first_message_at)",
			$since
		),
		ARRAY_A
	);
	$daily_events_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DATE(created_at) as day_key, COUNT(*) as qty FROM {$events_table} WHERE created_at >= %s GROUP BY DATE(created_at)",
			$since
		),
		ARRAY_A
	);
	$daily_leads_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DATE(created_at) as day_key, COUNT(*) as qty FROM {$leads_table} WHERE created_at >= %s GROUP BY DATE(created_at)",
			$since
		),
		ARRAY_A
	);

	foreach ( (array) $daily_conversations_rows as $row ) {
		$day_key = (string) ( $row['day_key'] ?? '' );
		if ( isset( $timeline[ $day_key ] ) ) {
			$timeline[ $day_key ]['conversations'] = (int) ( $row['qty'] ?? 0 );
		}
	}
	foreach ( (array) $daily_events_rows as $row ) {
		$day_key = (string) ( $row['day_key'] ?? '' );
		if ( isset( $timeline[ $day_key ] ) ) {
			$timeline[ $day_key ]['events'] = (int) ( $row['qty'] ?? 0 );
		}
	}
	foreach ( (array) $daily_leads_rows as $row ) {
		$day_key = (string) ( $row['day_key'] ?? '' );
		if ( isset( $timeline[ $day_key ] ) ) {
			$timeline[ $day_key ]['leads'] = (int) ( $row['qty'] ?? 0 );
		}
	}

	return [
		'total_events'        => $total_events,
		'total_conversations' => $total_conversations,
		'total_leads'         => $total_leads,
		'avg_latency_ms'      => $avg_latency,
		'conversion_rate'     => $conversion_rate,
		'abandonment_rate'    => $abandonment_rate,
		'avg_time_to_lead_minutes' => $avg_time_to_lead_minutes,
		'assisted_usage_rate' => $assisted_usage_rate,
		'event_breakdown'     => is_array( $event_rows ) ? $event_rows : [],
		'funnel'              => $funnel,
		'cohorts'             => $cohorts,
		'timeline'            => array_values( $timeline ),
	];
}

function estandar_chatbot_get_metric_insights( $days = 30 ) {
	$summary = estandar_chatbot_get_analytics_summary( $days );
	$friction = function_exists( 'estandar_chatbot_get_logs_friction_insights' )
		? estandar_chatbot_get_logs_friction_insights( $days )
		: [ 'top_questions' => [], 'fallbacks' => [] ];
	$conversation_insights = function_exists( 'estandar_chatbot_get_conversation_learning_insights' )
		? estandar_chatbot_get_conversation_learning_insights( $days )
		: [ 'lost_high_intent' => [] ];

	$conversion_rate = (float) ( $summary['conversion_rate'] ?? 0 );
	$abandonment_rate = (float) ( $summary['abandonment_rate'] ?? 0 );
	$avg_time_to_lead = (int) ( $summary['avg_time_to_lead_minutes'] ?? 0 );
	$top_question = $friction['top_questions'][0] ?? null;
	$lost_high_intent = count( (array) ( $conversation_insights['lost_high_intent'] ?? [] ) );
	$insights = [];

	if ( $conversion_rate < 5 ) {
		$insights[] = [
			'severity' => 'high',
			'title' => 'El chatbot no está cerrando suficiente',
			'message' => 'La conversión chat → lead está por debajo de 5%. Esto suele indicar CTA débil o demasiada explicación antes de pedir acción.',
			'action' => 'Simplificar respuestas comerciales y empujar una sola acción clara antes.',
		];
	}

	if ( $abandonment_rate >= 60 ) {
		$insights[] = [
			'severity' => 'high',
			'title' => 'Hay abandono alto antes del siguiente paso',
			'message' => 'Muchos usuarios se van sin actuar. Normalmente esto se relaciona con respuestas largas, fricción o CTA poco visible.',
			'action' => 'Reducir longitud de respuesta y mostrar CTA más visible o salida humana más temprana.',
		];
	}

	if ( $avg_time_to_lead >= 10 ) {
		$insights[] = [
			'severity' => 'medium',
			'title' => 'El tiempo a lead es más lento de lo ideal',
			'message' => 'Los usuarios están tardando demasiado en dejar datos o pasar al siguiente paso.',
			'action' => 'Reducir pasos y pedir menos datos antes del cierre.',
		];
	}

	if ( $top_question && (int) ( $top_question['count'] ?? 0 ) >= 3 ) {
		$insights[] = [
			'severity' => 'medium',
			'title' => 'Hay una duda repetida que está frenando la acción',
			'message' => 'La pregunta "' . (string) ( $top_question['question'] ?? '' ) . '" se repite varias veces.',
			'action' => 'Responder esa duda en formato corto y con CTA visible.',
		];
	}

	if ( $lost_high_intent >= 2 ) {
		$insights[] = [
			'severity' => 'high',
			'title' => 'Se están perdiendo usuarios con intención alta',
			'message' => 'Hay conversaciones calientes que no terminan en lead o contacto.',
			'action' => 'Activar cierre directo o derivación humana antes cuando detectes urgencia.',
		];
	}

	if ( empty( $insights ) ) {
		$insights[] = [
			'severity' => 'low',
			'title' => 'No hay alertas fuertes en esta ventana',
			'message' => 'La lectura actual no muestra un cuello de botella crítico.',
			'action' => 'Sigue acumulando datos y revisa cambios en preguntas repetidas, abandono y tiempo a lead.',
		];
	}

	return $insights;
}

function estandar_chatbot_is_public_ip( $ip ) {
	$ip = trim( (string) $ip );
	if ( $ip === '' ) {
		return false;
	}

	return (bool) filter_var(
		$ip,
		FILTER_VALIDATE_IP,
		FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
	);
}

function estandar_chatbot_get_ip_geolocation( $ip ) {
	$ip = trim( (string) $ip );
	if ( ! estandar_chatbot_is_public_ip( $ip ) ) {
		return [];
	}

	$cache_key = ESTANDAR_CHATBOT_GEO_IP_CACHE_PREFIX . md5( $ip );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	// Proveedor cambiado de ipwho.is a ip-api.com (2026-07): mismo esfuerzo,
	// probando si mejora la precisión rural — sin garantía, es limitación de
	// datos del proveedor gratuito, no de este código.
	$response = wp_remote_get(
		'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,country,regionName,city,lat,lon',
		[
			'timeout'    => 2,
			'redirection'=> 1,
			'user-agent' => 'EstandarChatbot/' . ESTANDAR_CHATBOT_VERSION,
		]
	);

	if ( is_wp_error( $response ) ) {
		set_transient( $cache_key, [], HOUR_IN_SECONDS );
		return [];
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( $code !== 200 || ! is_array( $body ) || ( $body['status'] ?? '' ) !== 'success' ) {
		set_transient( $cache_key, [], HOUR_IN_SECONDS );
		return [];
	}

	$lat = isset( $body['lat'] ) ? (float) $body['lat'] : null;
	$lng = isset( $body['lon'] ) ? (float) $body['lon'] : null;
	if ( $lat === null || $lng === null ) {
		set_transient( $cache_key, [], HOUR_IN_SECONDS );
		return [];
	}

	$data = [
		'ip'       => $ip,
		'city'     => sanitize_text_field( (string) ( $body['city'] ?? '' ) ),
		'region'   => sanitize_text_field( (string) ( $body['regionName'] ?? '' ) ),
		'country'  => sanitize_text_field( (string) ( $body['country'] ?? '' ) ),
		'lat'      => round( $lat, 4 ),
		'lng'      => round( $lng, 4 ),
		'provider' => 'ip-api.com',
	];

	set_transient( $cache_key, $data, 14 * DAY_IN_SECONDS );
	return $data;
}

function estandar_chatbot_get_geo_filter_options( $days = 30, $sample_limit = 800 ) {
	global $wpdb;

	$days  = max( 7, min( 90, (int) $days ) );
	$sample_limit = max( 100, min( 2000, (int) $sample_limit ) );

	$cache_key = ESTANDAR_CHATBOT_GEO_FILTERS_CACHE_PREFIX . $days . '_' . $sample_limit;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	estandar_chatbot_ensure_logs_table();
	$table = estandar_chatbot_get_logs_table();
	$since = estandar_chatbot_local_mysql_time( current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT datetime, url, meta
			 FROM {$table}
			 WHERE datetime >= %s
			 ORDER BY datetime DESC
			 LIMIT %d",
			$since,
			$sample_limit
		),
		ARRAY_A
	);

	$channels = [];
	$pages = [];
	foreach ( (array) $rows as $row ) {
		$url = esc_url_raw( (string) ( $row['url'] ?? '' ) );
		if ( $url !== '' ) {
			$pages[ $url ] = true;
		}

		$meta = json_decode( (string) ( $row['meta'] ?? '{}' ), true );
		if ( is_array( $meta ) ) {
			$channel = sanitize_key( (string) ( $meta['channel'] ?? '' ) );
			if ( $channel !== '' ) {
				$channels[ $channel ] = true;
			}
		}
	}

	ksort( $channels );
	ksort( $pages );

	$result = [
		'channels' => array_keys( $channels ),
		'pages'    => array_slice( array_keys( $pages ), 0, 40 ),
	];

	set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
	return $result;
}

function estandar_chatbot_get_geo_interaction_points( $days = 30, $limit = 80, array $filters = [] ) {
	global $wpdb;

	$days  = max( 7, min( 90, (int) $days ) );
	$limit = max( 10, min( 200, (int) $limit ) );
	$channel_filter = sanitize_key( (string) ( $filters['channel'] ?? '' ) );
	$page_filter    = esc_url_raw( (string) ( $filters['page'] ?? '' ) );
	$cache_suffix   = md5( wp_json_encode( [ $days, $limit, $channel_filter, $page_filter ] ) );

	$cache_key = ESTANDAR_CHATBOT_GEO_POINTS_CACHE_PREFIX . $cache_suffix;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	estandar_chatbot_ensure_logs_table();
	$table = estandar_chatbot_get_logs_table();
	$since = estandar_chatbot_local_mysql_time( current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ip, url, meta, datetime
			 FROM {$table}
			 WHERE datetime >= %s
			   AND ip IS NOT NULL
			   AND ip <> ''
			 ORDER BY datetime DESC
			 LIMIT %d",
			$since,
			1200
		),
		ARRAY_A
	);

	$raw_points = [];
	$mapped_interactions = 0;
	foreach ( (array) $rows as $row ) {
		$ip = trim( (string) ( $row['ip'] ?? '' ) );
		if ( ! estandar_chatbot_is_public_ip( $ip ) ) {
			continue;
		}

		$url = esc_url_raw( (string) ( $row['url'] ?? '' ) );
		if ( $page_filter !== '' && $url !== $page_filter ) {
			continue;
		}

		$meta = json_decode( (string) ( $row['meta'] ?? '{}' ), true );
		$channel = is_array( $meta ) ? sanitize_key( (string) ( $meta['channel'] ?? '' ) ) : '';
		if ( $channel_filter !== '' && $channel !== $channel_filter ) {
			continue;
		}

		$geo = estandar_chatbot_get_ip_geolocation( $ip );
		if ( empty( $geo['lat'] ) || empty( $geo['lng'] ) ) {
			continue;
		}

		$key = implode(
			'|',
			[
				number_format( (float) $geo['lat'], 2, '.', '' ),
				number_format( (float) $geo['lng'], 2, '.', '' ),
				(string) ( $geo['city'] ?? '' ),
				(string) ( $geo['country'] ?? '' ),
			]
		);

		if ( ! isset( $raw_points[ $key ] ) ) {
			$location_bits = array_filter(
				[
					(string) ( $geo['city'] ?? '' ),
					(string) ( $geo['region'] ?? '' ),
					(string) ( $geo['country'] ?? '' ),
				]
			);
			$raw_points[ $key ] = [
				'lat'          => (float) $geo['lat'],
				'lng'          => (float) $geo['lng'],
				'label'        => ! empty( $location_bits ) ? implode( ', ', $location_bits ) : __( 'Ubicación aproximada', 'estandar-chatbot' ),
				'interactions' => 0,
				'last_seen'    => (string) ( $row['datetime'] ?? '' ),
				'channel'      => $channel,
				'page_url'     => $url,
			];
		}

		$raw_points[ $key ]['interactions']++;
		if ( strcmp( (string) ( $row['datetime'] ?? '' ), (string) $raw_points[ $key ]['last_seen'] ) > 0 ) {
			$raw_points[ $key ]['last_seen'] = (string) ( $row['datetime'] ?? '' );
		}
		$mapped_interactions++;
	}

	$points = array_values( $raw_points );
	usort(
		$points,
		static function ( $a, $b ) {
			return (int) ( $b['interactions'] ?? 0 ) <=> (int) ( $a['interactions'] ?? 0 );
		}
	);
	$points = array_slice( $points, 0, $limit );

	$result = [
		'points'              => $points,
		'unique_places'       => count( $points ),
		'mapped_interactions' => $mapped_interactions,
		'provider'            => 'ip-api.com',
		'window_days'         => $days,
		'filters'             => [
			'channel' => $channel_filter,
			'page'    => $page_filter,
		],
	];

	set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
	return $result;
}
