<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ======================================================
 * LEADS HANDLER — ESTANDAR CHATBOT IA
 * Storage principal: DB (tabla wp_ecb_leads)
 * ======================================================
 */

require_once __DIR__ . '/lead-contact-merge.php';

define( 'ESTANDAR_CHATBOT_LEADS_FILE', estandar_chatbot_storage_path( 'leads/leads.csv' ) );
define( 'ESTANDAR_CHATBOT_LEADS_COUNT_TRANSIENT', 'ecb_leads_count_v2' );
define( 'ESTANDAR_CHATBOT_LEADS_LIST_TRANSIENT_PREFIX', 'ecb_leads_list_v2_' );
define( 'ESTANDAR_CHATBOT_LEADS_LIST_CACHE_GEN_OPTION', 'ecb_leads_list_cache_gen' );
define( 'ESTANDAR_CHATBOT_LEADS_COLUMNS_OPTION', 'chatbot_leads_columns' );
define( 'ESTANDAR_CHATBOT_LEADS_MIGRATION_OPTION', 'chatbot_leads_migrated_to_db' );

function estandar_chatbot_get_allowed_email_template_tags() {
	return [
		'p' => [],
		'br' => [],
		'strong' => [],
		'b' => [],
		'em' => [],
		'i' => [],
		'hr' => [],
		'a' => [
			'href' => true,
			'target' => true,
			'rel' => true,
		],
		'img' => [
			'src' => true,
			'alt' => true,
			'width' => true,
			'height' => true,
		],
	];
}

function estandar_chatbot_sanitize_email_template_option( $value ) {
	$value = (string) wp_unslash( $value );
	$value = wp_check_invalid_utf8( $value );
	$value = str_replace( [ "\r\n", "\r" ], "\n", $value );
	$value = wp_kses( $value, estandar_chatbot_get_allowed_email_template_tags() );
	return trim( $value );
}

function estandar_chatbot_get_default_lead_email_template() {
	return '<p><strong>Nuevo lead recibido desde el chatbot</strong></p>'
		. "\n\n"
		. '<p><strong>RESUMEN:</strong><br>{{conversation_summary}}</p>'
		. "\n"
		. '<p><strong>INTENCIÓN:</strong><br>{{lead_intent}}</p>'
		. "\n"
		. '<p><strong>ÚLTIMO MENSAJE:</strong><br>{{last_user_message}}</p>'
		. "\n"
		. '<p><strong>CONVERSACIÓN RECIENTE:</strong><br>{{conversation_recent}}</p>'
		. "\n\n"
		. '<hr>'
		. "\n\n"
		. '<p><strong>Nombre:</strong> {{nombre}}</p>'
		. "\n"
		. '<p><strong>Email:</strong> {{email}}</p>'
		. "\n"
		. '<p><strong>Teléfono:</strong> {{telefono}}</p>'
		. "\n\n"
		. '<hr>'
		. "\n\n"
		. '<p><strong>¿Qué necesita?</strong><br>{{intent}}</p>'
		. "\n"
		. '<p><strong>Mensaje del cliente:</strong><br>{{mensaje}}</p>'
		. "\n"
		. '<p><strong>Origen:</strong><br>{{origen}}</p>';
}

function estandar_chatbot_get_default_email_signature_template() {
	return '<hr>'
		. "\n"
		. '<p>Este mensaje fue generado automáticamente por el chatbot del sitio.</p>'
		. "\n"
		. '<p>Para consultas sobre integraciones, automatización o desarrollo de soluciones con IA, puedes visitar:<br><a href="https://estandar.cl" target="_blank" rel="noopener noreferrer">https://estandar.cl</a></p>'
		. "\n"
		. '<p><img src="https://estandar.cl/wp-content/uploads/2024/07/estandar_logo_color-768x227.png" alt="Estandar Studio" width="180"></p>'
		. "\n"
		. '<p>Estandar Studio - Soluciones digitales, automatización e inteligencia artificial aplicada.</p>';
}

function estandar_chatbot_render_lead_email_template( $template, array $vars ) {
	$template = estandar_chatbot_sanitize_email_template_option( $template );
	$replacements = [];
	foreach ( $vars as $key => $value ) {
		$replacements[ '{{' . $key . '}}' ] = nl2br( esc_html( (string) $value ) );
	}
	return strtr( $template, $replacements );
}

function estandar_chatbot_get_leads_table() {
	global $wpdb;
	return $wpdb->prefix . 'ecb_leads';
}

function estandar_chatbot_get_leads_indexes( $table ) {
	global $wpdb;

	$indexes = [];
	$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
	foreach ( (array) $rows as $row ) {
		$key_name = (string) ( $row['Key_name'] ?? '' );
		if ( $key_name !== '' ) {
			$indexes[ $key_name ] = true;
		}
	}

	return $indexes;
}

function estandar_chatbot_merge_lead_rows( array $primary, array $duplicate ) {
	global $wpdb;

	$table = estandar_chatbot_get_leads_table();
	$primary_data = json_decode( (string) ( $primary['data'] ?? '{}' ), true );
	$duplicate_data = json_decode( (string) ( $duplicate['data'] ?? '{}' ), true );
	$primary_data = is_array( $primary_data ) ? $primary_data : [];
	$duplicate_data = is_array( $duplicate_data ) ? $duplicate_data : [];

	$merged = $primary_data;
	foreach ( $duplicate_data as $key => $value ) {
		if ( ! isset( $merged[ $key ] ) || $merged[ $key ] === '' ) {
			$merged[ $key ] = $value;
		}
	}

	$last_interaction = max(
		strtotime( (string) ( $primary['last_interaction_at'] ?? '' ) ) ?: 0,
		strtotime( (string) ( $duplicate['last_interaction_at'] ?? '' ) ) ?: 0
	);

	$wpdb->update(
		$table,
		[
			'email'               => ! empty( $primary['email'] ) ? $primary['email'] : ( $duplicate['email'] ?? null ),
			'phone'               => ! empty( $primary['phone'] ) ? $primary['phone'] : ( $duplicate['phone'] ?? null ),
			'last_interaction_at' => $last_interaction > 0 ? estandar_chatbot_local_mysql_time( $last_interaction ) : current_time( 'mysql' ),
			'data'                => wp_json_encode( $merged ),
		],
		[ 'id' => (int) $primary['id'] ],
		[ '%s', '%s', '%s', '%s' ],
		[ '%d' ]
	);

	$wpdb->delete( $table, [ 'id' => (int) $duplicate['id'] ], [ '%d' ] );
}

function estandar_chatbot_deduplicate_leads_table() {
	global $wpdb;

	$table = estandar_chatbot_get_leads_table();
	$passes = [
		'email' => 'email',
		'phone' => 'phone',
	];

	$merged_total = 0;
	for ( $loop = 0; $loop < 4; $loop++ ) {
		$merged_this_round = 0;
		foreach ( $passes as $column ) {
			$groups = $wpdb->get_results(
				"SELECT {$column} AS identity_value, COUNT(*) AS qty
				FROM {$table}
				WHERE {$column} IS NOT NULL AND {$column} <> ''
				GROUP BY {$column}
				HAVING COUNT(*) > 1",
				ARRAY_A
			);

			foreach ( (array) $groups as $group ) {
				$value = (string) ( $group['identity_value'] ?? '' );
				if ( $value === '' ) {
					continue;
				}
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE {$column} = %s ORDER BY id ASC",
						$value
					),
					ARRAY_A
				);
				if ( count( $rows ) < 2 ) {
					continue;
				}

				$primary = array_shift( $rows );
				foreach ( $rows as $duplicate ) {
					estandar_chatbot_merge_lead_rows( $primary, $duplicate );
					$merged_this_round++;
				}
			}
		}

		$merged_total += $merged_this_round;
		if ( $merged_this_round === 0 ) {
			break;
		}
	}

	return $merged_total;
}

function estandar_chatbot_ensure_leads_unique_indexes() {
	global $wpdb;

	$table = estandar_chatbot_get_leads_table();
	$indexes = estandar_chatbot_get_leads_indexes( $table );

	if ( empty( $indexes['uniq_email'] ) || empty( $indexes['uniq_phone'] ) ) {
		estandar_chatbot_deduplicate_leads_table();
		$indexes = estandar_chatbot_get_leads_indexes( $table );
	}

	if ( empty( $indexes['uniq_email'] ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_email (email)" );
	}
	if ( empty( $indexes['uniq_phone'] ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_phone (phone)" );
	}
}

function estandar_chatbot_ensure_leads_table() {
	global $wpdb;

	$table = estandar_chatbot_get_leads_table();
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL,
		last_interaction_at DATETIME NOT NULL,
		email VARCHAR(190) NULL,
		phone VARCHAR(40) NULL,
		data LONGTEXT NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_email (email),
		UNIQUE KEY uniq_phone (phone),
		KEY idx_last_interaction (last_interaction_at)
	) {$charset_collate};";

	dbDelta( $sql );
	estandar_chatbot_ensure_leads_unique_indexes();
}

function estandar_chatbot_leads_cache_bust() {
	delete_transient( ESTANDAR_CHATBOT_LEADS_COUNT_TRANSIENT );
	$gen = (int) get_option( ESTANDAR_CHATBOT_LEADS_LIST_CACHE_GEN_OPTION, 1 );
	update_option( ESTANDAR_CHATBOT_LEADS_LIST_CACHE_GEN_OPTION, $gen + 1, false );
}

function estandar_chatbot_get_leads_columns() {
	$default = estandar_chatbot_leads_base_header();
	$cols = get_option( ESTANDAR_CHATBOT_LEADS_COLUMNS_OPTION, $default );
	if ( ! is_array( $cols ) || empty( $cols ) ) {
		return $default;
	}
	$out = [];
	foreach ( $cols as $col ) {
		$key = sanitize_key( (string) $col );
		if ( $key !== '' ) {
			$out[] = $key;
		}
	}
	if ( empty( $out ) ) {
		return $default;
	}
	return array_values( array_unique( array_merge( $default, $out ) ) );
}

function estandar_chatbot_update_leads_columns( array $keys ) {
	$base = estandar_chatbot_leads_base_header();
	$current = estandar_chatbot_get_leads_columns();
	$set = array_values( array_unique( array_merge( $current, $keys ) ) );

	$ordered = [];
	foreach ( $base as $b ) {
		if ( in_array( $b, $set, true ) ) {
			$ordered[] = $b;
		}
	}
	foreach ( $set as $k ) {
		if ( ! in_array( $k, $ordered, true ) ) {
			$ordered[] = $k;
		}
	}

	update_option( ESTANDAR_CHATBOT_LEADS_COLUMNS_OPTION, $ordered, false );
}

function estandar_chatbot_maybe_migrate_csv_leads_to_db() {
	if ( (int) get_option( ESTANDAR_CHATBOT_LEADS_MIGRATION_OPTION, 0 ) === 1 ) {
		return;
	}

	if ( ! file_exists( ESTANDAR_CHATBOT_LEADS_FILE ) ) {
		update_option( ESTANDAR_CHATBOT_LEADS_MIGRATION_OPTION, 1, false );
		return;
	}

	$raw = estandar_chatbot_csv_read( ESTANDAR_CHATBOT_LEADS_FILE, 0 );
	if ( count( $raw ) <= 1 ) {
		update_option( ESTANDAR_CHATBOT_LEADS_MIGRATION_OPTION, 1, false );
		return;
	}

	$header = array_map( 'trim', $raw[0] );
	if ( empty( $header ) ) {
		update_option( ESTANDAR_CHATBOT_LEADS_MIGRATION_OPTION, 1, false );
		return;
	}

	foreach ( array_slice( $raw, 1 ) as $row ) {
		if ( ! is_array( $row ) ) continue;
		$item = [];
		foreach ( $header as $idx => $k ) {
			$item[ sanitize_key( $k ) ] = (string) ( $row[ $idx ] ?? '' );
		}
		estandar_chatbot_save_lead( $item );
	}

	update_option( ESTANDAR_CHATBOT_LEADS_MIGRATION_OPTION, 1, false );
}

/**
 * Normaliza email para deduplicación
 */
function estandar_chatbot_normalize_email( $email ) {
	$email = sanitize_email( (string) $email );
	$email = strtolower( trim( $email ) );
	return is_email( $email ) ? $email : '';
}

/**
 * Normaliza teléfono para deduplicación.
 */
function estandar_chatbot_normalize_phone( $phone ) {
	$phone = trim( (string) $phone );
	if ( $phone === '' ) {
		return '';
	}

	if ( ! preg_match( '/^\+?[\d\s().-]+$/', $phone ) ) {
		return '';
	}

	$digits = preg_replace( '/\D+/', '', $phone );
	if ( ! is_string( $digits ) ) {
		return '';
	}

	if ( preg_match( '/^9\d{8}$/', $digits ) ) {
		$digits = '56' . $digits;
	}

	if ( ! preg_match( '/^569\d{8}$/', $digits ) ) {
		return '';
	}

	return $digits;
}

function estandar_chatbot_evaluate_contact( $email, $phone ) {
	$email_raw = trim( (string) $email );
	$phone_raw = trim( (string) $phone );
	$email_norm = estandar_chatbot_normalize_email( $email_raw );
	$phone_norm = estandar_chatbot_normalize_phone( $phone_raw );
	$email_invalid = $email_raw !== '' && $email_norm === '';
	$phone_invalid = $phone_raw !== '' && $phone_norm === '';

	if ( $email_norm === '' && $phone_norm === '' ) {
		$quality = CONTACT_REJECTED;
		$warning = $phone_invalid ? CONTACT_WARNING_PHONE_INVALID : CONTACT_WARNING_EMAIL_INVALID;
	} elseif ( $phone_invalid ) {
		$quality = CONTACT_SUSPECT;
		$warning = CONTACT_WARNING_PHONE_INVALID;
	} elseif ( $email_invalid ) {
		$quality = CONTACT_SUSPECT;
		$warning = CONTACT_WARNING_EMAIL_INVALID;
	} else {
		$quality = CONTACT_VALID;
		$warning = CONTACT_WARNING_NONE;
	}

	return [
		'email'           => $email_norm,
		'phone'           => $phone_norm,
		'email_raw'       => $email_invalid ? $email_raw : '',
		'phone_raw'       => $phone_invalid ? $phone_raw : '',
		'contact_quality' => $quality,
		'contact_warning' => $warning,
	];
}

function estandar_chatbot_log_phone_validation_failure( $phone, array $context = [] ) {
	$digits = preg_replace( '/\D+/', '', (string) $phone );
	$meta = array_merge( [
		'status'       => 'invalid',
		'digit_length' => is_string( $digits ) ? strlen( $digits ) : 0,
		'message'      => 'Invalid Chilean mobile phone detected before lead persistence.',
	], $context );

	if ( function_exists( 'estandar_chatbot_log_event' ) ) {
		estandar_chatbot_log_event( [
			'event_name'  => 'lead.phone_validation_failed',
			'channel'     => sanitize_key( (string) ( $context['channel'] ?? 'web' ) ),
			'session_key' => sanitize_text_field( (string) ( $context['session_id'] ?? '' ) ),
			'meta'        => $meta,
		] );
	}

	if ( function_exists( 'estandar_chatbot_telemetry_event' ) ) {
		estandar_chatbot_telemetry_event(
			'warning',
			'CHATBOT_PHONE_VALIDATION_FAILED',
			'Chatbot detected an invalid Chilean mobile phone.',
			$meta
		);
	}
}

function estandar_chatbot_log_contact_validation( array $contact, array $context = [] ) {
	if ( ! function_exists( 'estandar_chatbot_log_event' ) ) {
		return;
	}

	estandar_chatbot_log_event( [
		'event_name'  => 'lead.contact_validation',
		'channel'     => sanitize_key( (string) ( $context['channel'] ?? 'web' ) ),
		'session_key' => sanitize_text_field( (string) ( $context['session_id'] ?? '' ) ),
		'meta'        => [
			'contact_quality' => sanitize_key( (string) ( $contact['contact_quality'] ?? CONTACT_REJECTED ) ),
			'contact_warning' => sanitize_key( (string) ( $contact['contact_warning'] ?? CONTACT_WARNING_NONE ) ),
			'suspect_contacts'=> ( $contact['contact_quality'] ?? '' ) === CONTACT_SUSPECT ? 1 : 0,
		],
	] );
}

function estandar_chatbot_phone_lookup_variants( $phone ) {
	// 2.0.92: Added bare local format for legacy dedup compatibility.
	// Covers records stored before country prefix normalization was introduced.
	$phone = trim( (string) $phone );
	if ( $phone === '' ) {
		return [];
	}

	$variants = [ $phone ];
	$digits   = preg_replace( '/\D+/', '', $phone );
	if ( is_string( $digits ) && $digits !== '' ) {
		$variants[] = $digits;
		if ( strpos( $digits, '56' ) === 0 ) {
			$variants[] = '+' . $digits;
			// Legacy: also match bare local format (e.g. 942305982) stored before prefix was added
			$local = substr( $digits, 2 );
			if ( strlen( $local ) >= 7 ) {
				$variants[] = $local;
			}
		}
	}

	return array_values( array_unique( array_filter( $variants ) ) );
}

function estandar_chatbot_missing_required_lead_fields( array $lead ) {
	$fields = function_exists( 'estandar_chatbot_effective_option' )
		? estandar_chatbot_effective_option( 'chatbot_lead_fields', [] )
		: get_option( 'chatbot_lead_fields', [] );
	if ( ! is_array( $fields ) ) {
		return [];
	}

	$missing = [];
	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) || empty( $field['required'] ) ) {
			continue;
		}
		$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
		if ( $key !== '' && trim( (string) ( $lead[ $key ] ?? '' ) ) === '' ) {
			$missing[] = $key;
		}
	}

	return array_values( array_unique( $missing ) );
}

function estandar_chatbot_log_lead_validation_warning( array $result ) {
	if ( ! function_exists( 'estandar_chatbot_log_event' ) ) {
		return;
	}

	$lead = is_array( $result['lead'] ?? null ) ? $result['lead'] : [];
	$missing = estandar_chatbot_missing_required_lead_fields( $lead );
	if ( empty( $missing ) ) {
		return;
	}

	estandar_chatbot_log_event([
		'event_name' => 'lead.validation_warning',
		'lead_id'    => (int) ( $result['lead_id'] ?? 0 ),
		'meta'       => [
			'status'         => 'warning',
			'missing_fields' => $missing,
			'message'        => 'Lead persisted with missing configured required fields.',
		],
	]);
}

function estandar_chatbot_normalize_mapping_slug( $value ) {
	if ( function_exists( 'estandar_chatbot_normalize_project_slug' ) ) {
		return estandar_chatbot_normalize_project_slug( $value );
	}
	$value = sanitize_title( remove_accents( (string) $value ) );
	return trim( $value, "- \t\n\r\0\x0B" );
}

/**
 * PROJECT_REGISTRY_ENFORCEMENT_V1 / PHASE_1 — single resolution gate.
 *
 * RULE_01: project_slug solo puede provenir del Registry CORE
 * (Estandar_Core_Project_Registry), NUNCA del registry derivado del chatbot
 * (build_project_registry se auto-contamina sintetizando proyectos desde 'area').
 * RULE_04: candidato desconocido → '' (unresolved), nunca el string crudo.
 *
 * @param string $candidate       Slug candidato (project_slug de una regla).
 * @param array  $telemetry_ctx   Contexto para observabilidad (source/area/keyword).
 * @return string                 Slug registrado y activo, o '' (unresolved).
 */
function estandar_chatbot_registry_resolve_slug( $candidate, array $telemetry_ctx = [] ) {
	$slug = estandar_chatbot_normalize_mapping_slug( (string) $candidate );
	if ( $slug === '' ) {
		return '';
	}

	// CORE_OPTIONALITY: sin core no se puede validar → no promover (sin telemetría de error).
	if ( ! class_exists( 'Estandar_Core_Project_Registry' ) ) {
		return '';
	}

	$_client_id    = function_exists( 'estandar_core_get_client_slug' ) ? (string) estandar_core_get_client_slug() : '';
	$_all_projects = $_client_id !== ''
		? Estandar_Core_Project_Registry::get_all_for_client( $_client_id )
		: Estandar_Core_Project_Registry::get_all();
	foreach ( (array) $_all_projects as $project ) {
		// get_all_for_client() / get_all() ya filtran active=1.
		if ( estandar_chatbot_normalize_mapping_slug( (string) ( $project['project_id'] ?? '' ) ) === $slug ) {
			return $slug; // RULE_01 OK.
		}
	}

	// Core presente y el candidato NO es un proyecto registrado → unresolved.
	estandar_chatbot_emit_project_unresolved( $slug, $telemetry_ctx );
	return '';
}

/**
 * Telemetría ligera: una regla/área intentó emitir un project_slug inválido.
 * Observabilidad del enforcement (cuántos leads / qué reglas / qué áreas).
 * No cambia routing ni estado.
 */
function estandar_chatbot_emit_project_unresolved( $candidate_slug, array $ctx = [] ) {
	try {
		do_action( 'estandar_telemetry_event', [
			'source_plugin' => 'estandar-chatbot',
			'type'          => 'business_event',
			'severity'      => 'warning',
			'code'          => 'CHATBOT_PROJECT_UNRESOLVED',
			'message'       => 'Chatbot intentó emitir un project_slug ausente del Registry core.',
			'context'       => array_merge( [ 'candidate_slug' => (string) $candidate_slug ], $ctx ),
		] );
	} catch ( \Throwable $e ) {
		return;
	}
}

function estandar_chatbot_normalize_source_path( $page_url ) {
	$page_url = estandar_chatbot_sanitize_source_page_url( $page_url );
	if ( $page_url === '' ) {
		return '';
	}

	$path = wp_parse_url( $page_url, PHP_URL_PATH );
	if ( ! is_string( $path ) ) {
		return '';
	}

	$path = strtolower( $path );
	$path = preg_replace( '#/+#', '/', $path );
	return trim( $path );
}

function estandar_chatbot_get_source_intent_rules() {
	$rules = get_option( 'chatbot_source_intent_rules', [] );
	return is_array( $rules ) ? array_values( $rules ) : [];
}

function estandar_chatbot_get_text_intent_rules() {
	$rules = get_option( 'chatbot_text_intent_rules', [] );
	return is_array( $rules ) ? array_values( $rules ) : [];
}

function estandar_chatbot_match_source_context( $page_url ) {
	$page_url = estandar_chatbot_sanitize_source_page_url( $page_url );
	if ( $page_url === '' ) {
		return [];
	}

	$path = estandar_chatbot_normalize_source_path( $page_url );
	$full = strtolower( $page_url );
	if ( $path === '' && $full === '' ) {
		return [];
	}

	foreach ( estandar_chatbot_get_source_intent_rules() as $rule ) {
		$slug = estandar_chatbot_normalize_mapping_slug( $rule['slug'] ?? '' );
		if ( $slug === '' ) {
			continue;
		}
		if ( strpos( $path, $slug ) === false && strpos( $full, $slug ) === false ) {
			continue;
		}

		$intent = sanitize_key( (string) ( $rule['intent'] ?? '' ) );
		// RULE_01/02: solo project_slug del Registry core; area NUNCA se promueve a slug.
		$project_slug = estandar_chatbot_registry_resolve_slug(
			$rule['project_slug'] ?? '',
			[ 'source' => 'source_intent_rule', 'rule_slug' => $slug, 'area' => (string) ( $rule['area'] ?? '' ) ]
		);
		$area   = sanitize_text_field( (string) ( $rule['area'] ?? '' ) );
		$project = function_exists( 'estandar_chatbot_get_project_by_slug' ) ? estandar_chatbot_get_project_by_slug( $project_slug ) : null;

		return [
			'match_type' => 'rule',
			'slug'       => $slug,
			'intent'     => $intent,
			'project_slug' => $project_slug,
			'area'       => $area !== '' ? $area : sanitize_text_field( $project['name'] ?? ucfirst( str_replace( '-', ' ', $project_slug ?: $slug ) ) ),
			'city'       => sanitize_text_field( $project['city'] ?? '' ),
		];
	}

	$responsables = function_exists( 'estandar_chatbot_get_responsables' ) ? estandar_chatbot_get_responsables() : [];
	foreach ( $responsables as $responsable ) {
		$area = sanitize_text_field( (string) ( $responsable['area'] ?? '' ) );
		$slug = estandar_chatbot_normalize_mapping_slug( $area );
		if ( $slug === '' ) {
			continue;
		}
		if ( strpos( $path, $slug ) === false && strpos( $full, $slug ) === false ) {
			continue;
		}

		return [
			'match_type' => 'responsable_area',
			'slug'       => $slug,
			'intent'     => '',
			// RULE_01/02: area del responsable NUNCA es project_slug; resolver contra Registry core.
			'project_slug' => estandar_chatbot_registry_resolve_slug(
				$responsable['project_slug'] ?? '',
				[ 'source' => 'responsable_area', 'area' => $area ]
			),
			'area'       => $area,
			'city'       => '',
		];
	}

	return [];
}

function estandar_chatbot_apply_source_context_to_lead( array $lead ) {
	$source_url = (string) ( $lead['source_page_url'] ?? '' );
	$match = estandar_chatbot_match_source_context( $source_url );
	if ( empty( $match ) ) {
		return $lead;
	}

	$current_intent = sanitize_key( (string) ( $lead['intent'] ?? '' ) );
	$matched_intent = sanitize_key( (string) ( $match['intent'] ?? '' ) );
	$matched_project_slug = estandar_chatbot_normalize_mapping_slug( (string) ( $match['project_slug'] ?? '' ) );
	$matched_area   = sanitize_text_field( (string) ( $match['area'] ?? '' ) );
	$matched_city   = sanitize_text_field( (string) ( $match['city'] ?? '' ) );

	if ( $matched_intent !== '' ) {
		$lead['intent'] = $matched_intent;
		$lead['intent_detection_source'] = 'source_url';
	} elseif ( $current_intent === '' ) {
		$lead['intent_detection_source'] = 'source_url';
	}

	if ( $matched_project_slug !== '' ) {
		$lead['project_slug'] = $matched_project_slug;
		$lead['project_resolution_source'] = 'url';
	}

	if ( $matched_area !== '' ) {
		$lead['project_area'] = $matched_area;
	}
	if ( $matched_city !== '' ) {
		$lead['city'] = $matched_city;
	}

	if ( ! empty( $match['slug'] ) ) {
		$lead['source_match_slug'] = sanitize_key( (string) $match['slug'] );
	}

	if ( ! empty( $match['match_type'] ) ) {
		$lead['source_match_type'] = sanitize_key( (string) $match['match_type'] );
	}

	return $lead;
}

function estandar_chatbot_match_text_intent_context( array $lead ) {
	$haystack = [];
	foreach ( [ 'requirement', 'intent', 'project_area', 'name' ] as $key ) {
		if ( ! empty( $lead[ $key ] ) ) {
			$haystack[] = (string) $lead[ $key ];
		}
	}

	$text = strtolower( remove_accents( implode( ' ', $haystack ) ) );
	$text = trim( preg_replace( '/\s+/', ' ', $text ) );
	if ( $text === '' ) {
		return [];
	}

	foreach ( estandar_chatbot_get_text_intent_rules() as $rule ) {
		$keyword = strtolower( remove_accents( trim( (string) ( $rule['keyword'] ?? '' ) ) ) );
		if ( $keyword === '' || strpos( $text, $keyword ) === false ) {
			continue;
		}

		return [
			'match_type' => 'text_rule',
			'keyword'    => $keyword,
			'intent'     => sanitize_key( (string) ( $rule['intent'] ?? '' ) ),
			// RULE_01/02: solo project_slug del Registry core; area NUNCA se promueve a slug.
			'project_slug' => estandar_chatbot_registry_resolve_slug(
				(string) ( $rule['project_slug'] ?? '' ),
				[ 'source' => 'text_intent_rule', 'keyword' => $keyword, 'area' => (string) ( $rule['area'] ?? '' ) ]
			),
			'area'       => sanitize_text_field( (string) ( $rule['area'] ?? '' ) ),
		];
	}

	return [];
}

function estandar_chatbot_detect_lead_context( array $lead ) {
	// Priority 1 & 2: explicit project selection + current page URL matching.
	$lead = estandar_chatbot_apply_source_context_to_lead( $lead );
	if ( ! empty( $lead['intent_detection_source'] ) || ! empty( $lead['project_slug'] ) || ! empty( $lead['project_area'] ) ) {
		if ( empty( $lead['project_resolution_source'] ) && ! empty( $lead['project_slug'] ) ) {
			$lead['project_resolution_source'] = 'chatbot';
		}
		return $lead;
	}

	// Priority 3: text intent rules.
	$match = estandar_chatbot_match_text_intent_context( $lead );
	if ( ! empty( $match ) ) {
		$matched_intent       = sanitize_key( (string) ( $match['intent'] ?? '' ) );
		$matched_project_slug = estandar_chatbot_normalize_mapping_slug( (string) ( $match['project_slug'] ?? '' ) );
		$matched_area         = sanitize_text_field( (string) ( $match['area'] ?? '' ) );
		if ( $matched_intent !== '' ) {
			$lead['intent']                  = $matched_intent;
			$lead['intent_detection_source'] = 'text_rule';
		}
			if ( $matched_project_slug !== '' ) {
				$lead['project_slug'] = $matched_project_slug;
				$lead['project_resolution_source'] = 'chatbot';
			}
		if ( $matched_area !== '' ) {
			$lead['project_area'] = $matched_area;
		}
		if ( ! empty( $match['keyword'] ) ) {
			$lead['source_match_slug'] = sanitize_key( str_replace( ' ', '_', (string) $match['keyword'] ) );
		}
		$lead['source_match_type'] = 'text_rule';
		return $lead;
	}

	// Priority 4: analytics session first_project_slug fallback.
	$session_id = (string) ( $lead['analytics_session_id'] ?? '' );
	if ( $session_id !== '' && function_exists( 'estandar_analytics_get_attribution_by_session' ) ) {
		$attribution = estandar_analytics_get_attribution_by_session( $session_id );
		if ( is_array( $attribution ) ) {
			// RULE_01: la sesión analytics puede traer un slug contaminado histórico; validar contra Registry core.
			$analytics_slug = estandar_chatbot_registry_resolve_slug(
				(string) ( $attribution['first_project_slug'] ?? '' ),
				[ 'source' => 'analytics_session' ]
			);
			if ( $analytics_slug !== '' ) {
				$lead['project_slug']            = $analytics_slug;
				$lead['intent_detection_source'] = 'analytics_session';
				$lead['source_match_type']       = 'analytics_session';
				$lead['project_resolution_source'] = 'analytics';
			}
		}
	}

	// Priority 5: monoproyecto default fallback.
	// Si el sitio tiene un único proyecto activo en el registry y el lead aún no tiene
	// project_slug asignado, lo asignamos automáticamente. Cubre casos en que el chatbot
	// no tiene reglas de mapeo configuradas (monoproyecto sin multiproyecto).
	if ( empty( $lead['project_slug'] ) ) {
		$registry = function_exists( 'estandar_chatbot_get_project_registry' ) ? estandar_chatbot_get_project_registry() : (array) get_option( 'chatbot_project_registry', [] );
		$active_projects = array_values( array_filter( $registry, function( $p ) {
			return ! empty( $p['active'] ) && ! empty( $p['project_slug'] );
		} ) );
		if ( count( $active_projects ) === 1 ) {
			$lead['project_slug']            = estandar_chatbot_normalize_mapping_slug( (string) $active_projects[0]['project_slug'] );
			$lead['intent_detection_source'] = 'monoproyecto_default';
			$lead['source_match_type']       = 'monoproyecto_default';
			$lead['project_resolution_source'] = 'fallback';
		}
	}

	return $lead;
}

function estandar_chatbot_find_suggested_responsable_index( array $lead, ?array $responsables = null ) {
	$responsables = is_array( $responsables ) ? $responsables : ( function_exists( 'estandar_chatbot_get_responsables' ) ? estandar_chatbot_get_responsables() : [] );
	if ( empty( $responsables ) ) {
		return -1;
	}

	$intent = sanitize_key( (string) ( $lead['intent'] ?? '' ) );
	if ( $intent !== '' ) {
		foreach ( $responsables as $index => $responsable ) {
			$area_slug = sanitize_key( (string) ( $responsable['area'] ?? '' ) );
			if ( $area_slug !== '' && $area_slug === $intent ) {
				return (int) $index;
			}
		}
	}

	$project_slug = estandar_chatbot_normalize_mapping_slug( (string) ( $lead['project_slug'] ?? '' ) );
	if ( $project_slug === '' ) {
		$project_slug = estandar_chatbot_normalize_mapping_slug( (string) ( $lead['project_area'] ?? '' ) );
	}
	if ( $project_slug === '' ) {
		$match = estandar_chatbot_match_source_context( (string) ( $lead['source_page_url'] ?? '' ) );
		$project_slug = estandar_chatbot_normalize_mapping_slug( (string) ( $match['project_slug'] ?? $match['area'] ?? '' ) );
	}

	if ( $project_slug === '' ) {
		return -1;
	}

	foreach ( $responsables as $index => $responsable ) {
		$responsable_slug = estandar_chatbot_normalize_mapping_slug( (string) ( $responsable['project_slug'] ?? $responsable['area'] ?? '' ) );
		if ( $responsable_slug !== '' && $responsable_slug === $project_slug ) {
			return (int) $index;
		}
	}

	return -1;
}

function estandar_chatbot_leads_base_header() {
	return [
		'created_at',
		'last_interaction_at',
		'lead_status',
		'lead_intent',
		'conversation_summary',
		'last_user_message',
		'conversation_recent',
		'email',
		'phone',
	];
}

function estandar_chatbot_sanitize_lead_status( $status ) {
	$status = sanitize_key( (string) $status );
	$allowed = [ 'new', 'contacted', 'qualified', 'proposal', 'won', 'lost', 'spam' ];
	return in_array( $status, $allowed, true ) ? $status : 'new';
}

function estandar_chatbot_default_lead_status( array $lead ) {
	if ( ! empty( $lead['lead_status'] ) ) {
		return estandar_chatbot_sanitize_lead_status( $lead['lead_status'] );
	}
	if ( ! empty( $lead['force_human'] ) || ! empty( $lead['escalation_requested'] ) ) {
		return 'contacted';
	}
	return 'new';
}

function estandar_chatbot_classify_lead_intent( array $lead ) {
	$text = strtolower( trim( implode( ' ', [
		(string) ( $lead['intent'] ?? '' ),
		(string) ( $lead['lead_intent'] ?? '' ),
		(string) ( $lead['requirement'] ?? '' ),
		(string) ( $lead['message'] ?? '' ),
		(string) ( $lead['last_user_message'] ?? '' ),
		(string) ( $lead['conversation_recent'] ?? '' ),
	] ) ) );

	$matches = [
		'educacion' => [ 'suma', 'resta', 'denominador', 'denominadores', 'fraccion', 'fracciones', 'tarea', 'matematica', 'matemática', 'colegio', 'educacion', 'educación' ],
		'spam' => [ 'casino', 'apuesta', 'cripto gratis', 'seo barato', 'viagra' ],
		'apertura_empresa' => [ 'abrir empresa', 'crear empresa', 'constituir empresa', 'inicio de actividades', 'spa', 'empresa' ],
		'cambio_contador' => [ 'cambiar contador', 'cambio de contador', 'nuevo contador' ],
		'remuneraciones' => [ 'remuneracion', 'remuneración', 'remuneraciones', 'sueldo', 'liquidacion', 'liquidación' ],
		'rrhh' => [ 'rrhh', 'recursos humanos', 'contrato', 'finiquito', 'laboral' ],
		'tributario' => [ 'tributario', 'impuesto', 'iva', 'renta', 'declaracion', 'declaración', 'sii' ],
		'contabilidad' => [ 'contabilidad', 'contable', 'contador', 'factura', 'balance' ],
		'legal_empresa' => [ 'legal', 'societario', 'estatuto', 'poder', 'escritura' ],
	];

	foreach ( $matches as $category => $keywords ) {
		foreach ( $keywords as $keyword ) {
			if ( $text !== '' && strpos( $text, $keyword ) !== false ) {
				return [
					'category'        => $category,
					'outside_service' => in_array( $category, [ 'educacion', 'spam' ], true ),
				];
			}
		}
	}

	return [
		'category'        => 'otro',
		'outside_service' => false,
	];
}

function estandar_chatbot_has_intent_mismatch( array $lead, array $classification ) {
	$selected = sanitize_key( (string) ( $lead['intent'] ?? $lead['lead_intent'] ?? '' ) );
	$category = sanitize_key( (string) ( $classification['category'] ?? '' ) );
	if ( $selected === '' || $category === '' || in_array( $category, [ 'otro', 'spam' ], true ) ) {
		return false;
	}

	$aliases = [
		'abrir_empresa' => 'apertura_empresa',
		'apertura'      => 'apertura_empresa',
		'legal'         => 'legal_empresa',
		'laboral'       => 'rrhh',
	];
	$selected = $aliases[ $selected ] ?? $selected;

	return $selected !== $category;
}

function estandar_chatbot_leads_with_lock( callable $callback, $lock_name = '' ) {
	$db_locked = false;
	if ( $lock_name !== '' ) {
		$db_locked = estandar_chatbot_acquire_db_lock( $lock_name, 5 );
		if ( ! $db_locked ) {
			return [ 'ok' => false, 'value' => null ];
		}
	}

	$lock_file = ESTANDAR_CHATBOT_LEADS_FILE . '.lock';
	$dir = dirname( $lock_file );
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$fh = fopen( $lock_file, 'c+' );
	if ( ! $fh ) {
		if ( $db_locked ) {
			estandar_chatbot_release_db_lock( $lock_name );
		}
		return [ 'ok' => false, 'value' => null ];
	}
	if ( ! flock( $fh, LOCK_EX ) ) {
		fclose( $fh );
		if ( $db_locked ) {
			estandar_chatbot_release_db_lock( $lock_name );
		}
		return [ 'ok' => false, 'value' => null ];
	}

	try {
		$value = $callback();
	} finally {
		flock( $fh, LOCK_UN );
		fclose( $fh );
		if ( $db_locked ) {
			estandar_chatbot_release_db_lock( $lock_name );
		}
	}

	return [ 'ok' => true, 'value' => $value ];
}

function estandar_chatbot_merge_lead_payload( array $base, array $data ) {
	$out = $base;
	foreach ( $data as $k => $v ) {
		$key = sanitize_key( (string) $k );
		if ( $key === '' ) continue;
		$out[ $key ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
	}
	return $out;
}

/**
 * Guarda/actualiza un lead. Storage DB con deduplicación por email/teléfono.
 */
function estandar_chatbot_save_lead( array $lead ) {
	global $wpdb;

	estandar_chatbot_ensure_leads_table();

	$clean = [];
	foreach ( $lead as $k => $v ) {
		$key = sanitize_key( (string) $k );
		if ( $key === '' ) continue;
			if ( $key === 'source_page_url' ) {
				$clean[ $key ] = estandar_chatbot_sanitize_source_page_url( is_scalar( $v ) ? (string) $v : '' );
				continue;
			}
			if ( in_array( $key, [ 'conversation_summary', 'last_user_message', 'conversation_recent' ], true ) ) {
				$clean[ $key ] = sanitize_textarea_field( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
				continue;
			}
			$val = sanitize_text_field( is_scalar($v) ? (string)$v : wp_json_encode($v) );
			$clean[$key] = $val;
		}

	$contact = estandar_chatbot_evaluate_contact(
		$clean['email_raw'] ?? $clean['email'] ?? '',
		$clean['phone_raw'] ?? $clean['phone'] ?? ''
	);
	$email_norm = $contact['email'];
	$phone_norm = $contact['phone'];
	estandar_chatbot_log_contact_validation( $contact, [
		'channel'    => sanitize_key( (string) ( $clean['source_channel'] ?? 'web' ) ),
		'session_id' => sanitize_text_field( (string) ( $clean['session_id'] ?? '' ) ),
	] );

	if ( $contact['phone_raw'] !== '' ) {
		estandar_chatbot_log_phone_validation_failure( $contact['phone_raw'], [
			'channel'    => sanitize_key( (string) ( $clean['source_channel'] ?? 'web' ) ),
			'session_id' => sanitize_text_field( (string) ( $clean['session_id'] ?? '' ) ),
			'contact_quality' => $contact['contact_quality'],
		] );
	}
	if ( $contact['contact_quality'] === CONTACT_REJECTED ) {
		return [
			'status'  => 'error',
			'code'    => 'contact_rejected',
			'lead'    => [],
			'message' => 'Necesito un correo válido o un celular chileno válido para continuar.',
		];
	}

	unset( $clean['email'], $clean['phone'], $clean['email_raw'], $clean['phone_raw'] );
	if ( $email_norm !== '' ) {
		$clean['email'] = $email_norm;
	}
	if ( $phone_norm !== '' ) {
		$clean['phone'] = $phone_norm;
	}
	if ( $contact['email_raw'] !== '' ) {
		$clean['email_raw'] = sanitize_text_field( $contact['email_raw'] );
	}
	if ( $contact['phone_raw'] !== '' ) {
		$clean['phone_raw'] = sanitize_text_field( $contact['phone_raw'] );
	}
	$clean['contact_quality'] = $contact['contact_quality'];
	$clean['contact_warning'] = $contact['contact_warning'];
	$clean = estandar_chatbot_detect_lead_context( $clean );
	$clean['lead_status'] = estandar_chatbot_default_lead_status( $clean );
	if ( empty( $clean['source_channel'] ) ) {
		$clean['source_channel'] = ! empty( $clean['escalation_channel'] ) ? sanitize_key( (string) $clean['escalation_channel'] ) : 'web';
	}

	$table = estandar_chatbot_get_leads_table();
	$now = current_time('mysql');

	$lead_lock_name = 'ecb_lead_' . md5( $email_norm . '|' . $phone_norm );
	$lock = estandar_chatbot_leads_with_lock( function () use ( $wpdb, $table, $clean, $email_norm, $phone_norm, $now ) {
		$where_parts = [];
		$where_vals = [];
		if ( $email_norm !== '' ) {
			$where_parts[] = 'email = %s';
			$where_vals[] = $email_norm;
		}
		if ( $phone_norm !== '' ) {
			$phone_variants = estandar_chatbot_phone_lookup_variants( $phone_norm );
			if ( count( $phone_variants ) > 1 ) {
				$where_parts[] = 'phone IN (' . implode( ',', array_fill( 0, count( $phone_variants ), '%s' ) ) . ')';
				$where_vals = array_merge( $where_vals, $phone_variants );
			} else {
				$where_parts[] = 'phone = %s';
				$where_vals[] = $phone_norm;
			}
		}

		$existing = null;
		if ( ! empty( $where_parts ) ) {
			$sql = "SELECT * FROM {$table} WHERE " . implode( ' OR ', $where_parts ) . ' ORDER BY id ASC LIMIT 1';
			$existing = $wpdb->get_row( $wpdb->prepare( $sql, $where_vals ), ARRAY_A );
		}

		if ( is_array( $existing ) ) {
			$current_data = json_decode( (string) ( $existing['data'] ?? '{}' ), true );
			if ( ! is_array( $current_data ) ) {
				$current_data = [];
			}

			$merged_data = $current_data;
			foreach ( $clean as $k => $v ) {
				if ( $v !== '' ) {
					$merged_data[ $k ] = $v;
				}
			}
			if ( $email_norm !== '' ) {
				unset( $merged_data['email_raw'] );
			}
			if ( $phone_norm !== '' ) {
				unset( $merged_data['phone_raw'] );
			}

			// CONTACT_MERGE_PRESERVATION_V1: an invalid recontact attempt
			// (email_raw/phone_raw) stays in data for review but never erases
			// a previously valid stored value.
			$new_email = estandar_chatbot_merge_contact_value( $email_norm, $existing['email'] ?? '' );
			$new_phone = estandar_chatbot_merge_contact_value( $phone_norm, $existing['phone'] ?? '' );

			$ok = $wpdb->update(
				$table,
				[
					'last_interaction_at' => $now,
					'email'               => $new_email ?: null,
					'phone'               => $new_phone ?: null,
					'data'                => wp_json_encode( $merged_data ),
				],
				[ 'id' => (int) $existing['id'] ],
				[ '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);

			if ( $ok === false ) {
				return [
					'status'  => 'error',
					'lead'    => [],
					'message' => 'No se pudo actualizar el lead en base de datos.'
				];
			}

			$lead_out = estandar_chatbot_merge_lead_payload([
				'_db_id'               => (int) $existing['id'],
				'created_at'          => (string) $existing['created_at'],
				'last_interaction_at' => $now,
				'email'               => $new_email,
				'phone'               => $new_phone,
			], $merged_data);

			return [
				'status'  => 'updated',
				'lead_id' => (int) $existing['id'],
				'lead'    => $lead_out,
				'message' => 'Lead actualizado correctamente.'
			];
		}

		$data_payload = $clean;
		$ok = $wpdb->insert(
			$table,
			[
				'created_at'          => $now,
				'last_interaction_at' => $now,
				'email'               => $email_norm ?: null,
				'phone'               => $phone_norm ?: null,
				'data'                => wp_json_encode( $data_payload ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $ok ) {
			$recovered = null;
			if ( $email_norm !== '' || $phone_norm !== '' ) {
				$sql = "SELECT * FROM {$table} WHERE ";
				$where_parts = [];
				$where_vals = [];
			if ( $email_norm !== '' ) {
				$where_parts[] = 'email = %s';
				$where_vals[] = $email_norm;
			}
			if ( $phone_norm !== '' ) {
				$phone_variants = estandar_chatbot_phone_lookup_variants( $phone_norm );
				if ( count( $phone_variants ) > 1 ) {
					$where_parts[] = 'phone IN (' . implode( ',', array_fill( 0, count( $phone_variants ), '%s' ) ) . ')';
					$where_vals = array_merge( $where_vals, $phone_variants );
				} else {
					$where_parts[] = 'phone = %s';
					$where_vals[] = $phone_norm;
				}
			}
				$sql .= implode( ' OR ', $where_parts ) . ' ORDER BY id ASC LIMIT 1';
				$recovered = $wpdb->get_row( $wpdb->prepare( $sql, $where_vals ), ARRAY_A );
			}
			if ( is_array( $recovered ) ) {
				$current_data = json_decode( (string) ( $recovered['data'] ?? '{}' ), true );
				$current_data = is_array( $current_data ) ? $current_data : [];
				foreach ( $clean as $k => $v ) {
					if ( $v !== '' ) {
						$current_data[ $k ] = $v;
					}
				}
				if ( $email_norm !== '' ) {
					unset( $current_data['email_raw'] );
				}
				if ( $phone_norm !== '' ) {
					unset( $current_data['phone_raw'] );
				}
				$wpdb->update(
					$table,
					[
						'last_interaction_at' => $now,
						'data'                => wp_json_encode( $current_data ),
					],
					[ 'id' => (int) $recovered['id'] ],
					[ '%s', '%s' ],
					[ '%d' ]
				);

				$lead_out = estandar_chatbot_merge_lead_payload([
					'_db_id'               => (int) $recovered['id'],
					'created_at'           => (string) ( $recovered['created_at'] ?? $now ),
					'last_interaction_at'  => $now,
					'email'                => (string) ( $recovered['email'] ?? $email_norm ),
					'phone'                => (string) ( $recovered['phone'] ?? $phone_norm ),
				], $current_data);

				return [
					'status'  => 'updated',
					'lead_id' => (int) $recovered['id'],
					'lead'    => $lead_out,
					'message' => 'Lead actualizado correctamente.'
				];
			}

			return [
				'status'  => 'error',
				'lead'    => [],
				'message' => 'No se pudo crear el lead en base de datos.'
			];
		}

		$lead_out = estandar_chatbot_merge_lead_payload([
			'_db_id'               => (int) $wpdb->insert_id,
			'created_at'          => $now,
			'last_interaction_at' => $now,
			'email'               => $email_norm,
			'phone'               => $phone_norm,
		], $data_payload);

		return [
			'status'  => 'created',
			'lead_id' => (int) $wpdb->insert_id,
			'lead'    => $lead_out,
			'message' => 'Lead guardado correctamente.'
		];
	}, $lead_lock_name );

	if ( ! $lock['ok'] ) {
		return [
			'status'  => 'error',
			'lead'    => [],
			'message' => 'No se pudo bloquear el recurso de leads.'
		];
	}

	$result = is_array( $lock['value'] ) ? $lock['value'] : [
		'status'  => 'error',
		'lead'    => [],
		'message' => 'Error interno al guardar lead.',
	];

	if ( in_array( $result['status'] ?? '', [ 'created', 'updated' ], true ) ) {
		estandar_chatbot_update_leads_columns( array_keys( (array) ( $result['lead'] ?? [] ) ) );
		estandar_chatbot_leads_cache_bust();
		estandar_chatbot_log_lead_validation_warning( $result );
	}

	return $result;
}

/**
 * Envía email de notificación cuando se concreta un lead.
 */
function estandar_chatbot_humanize_lead_intent( $intent, array $lead = [] ) {
	$intent = sanitize_key( (string) $intent );
	$configured_intents = estandar_chatbot_get_option_array( 'chatbot_lead_intents', [] );
	foreach ( $configured_intents as $configured_intent ) {
		$id = sanitize_key( (string) ( $configured_intent['id'] ?? '' ) );
		$label = trim( (string) ( $configured_intent['label'] ?? '' ) );
		if ( $id !== '' && $id === $intent && $label !== '' ) {
			return $label;
		}
	}
		$map = [
			'humano' => 'Quiere hablar con una persona',
			'urgencia' => 'Quiere resolverlo lo antes posible',
			'ventas' => 'Quiere cotizar',
			'soporte' => 'Necesita soporte',
			'info' => 'Quiere más información',
			'declaracion_renta' => 'Declaración de renta',
			'cambio_contador' => 'Cambio de contador',
			'contabilidad_mensual' => 'Contabilidad mensual',
			'tributaria' => 'Asesoría tributaria',
				'remuneraciones' => 'Remuneraciones / Payroll',
				'inicio_actividades' => 'Inicio de actividades',
				'cotizacion' => 'Cotización',
				'cotizacion_inmobiliaria' => 'Cotización inmobiliaria',
				'contacto_humano' => 'Contacto humano',
			'consulta_general' => 'Consulta general',
		];
	if ( isset( $map[ $intent ] ) ) {
		return $map[ $intent ];
	}
	$requirement = trim( (string) ( $lead['requirement'] ?? '' ) );
	if ( $requirement !== '' ) {
		return $requirement;
	}
	if ( $intent !== '' ) {
		return ucfirst( str_replace( '_', ' ', $intent ) );
	}
	return 'Necesita ayuda';
}

function estandar_chatbot_humanize_lead_priority( $tier, $score = 0 ) {
	$tier = sanitize_key( (string) $tier );
	$score = (int) $score;
	if ( $tier === 'hot' ) {
		return 'Alta (listo para avanzar)';
	}
	if ( $tier === 'warm' ) {
		return 'Media (interés real)';
	}
	return $score > 0 ? 'Baja (requiere activación)' : 'Baja';
}

function estandar_chatbot_lead_quality_label( $score ) {
	$score = (int) $score;
	if ( $score >= 85 ) {
		return 'HIGH';
	}
	if ( $score >= 50 ) {
		return 'MEDIUM';
	}
	if ( $score >= 20 ) {
		return 'LOW';
	}
	return 'VERY_LOW';
}

function estandar_chatbot_commercial_priority_label( $score ) {
	$score = (int) $score;
	if ( $score >= 90 ) {
		return 'URGENTE';
	}
	if ( $score >= 70 ) {
		return 'ALTA';
	}
	if ( $score >= 40 ) {
		return 'MEDIA';
	}
	return 'BAJA';
}

function estandar_chatbot_humanize_intent_category( $category ) {
	$category = sanitize_key( (string) $category );
	$map = [
		'apertura_empresa' => 'Apertura empresa',
		'cambio_contador'  => 'Cambio de contador',
		'contabilidad'     => 'Contabilidad',
		'tributario'       => 'Tributario',
		'rrhh'             => 'RRHH',
		'remuneraciones'   => 'Remuneraciones',
		'legal_empresa'    => 'Legal empresa',
		'educacion'        => 'Educación',
		'spam'             => 'Spam',
		'otro'             => 'Otro',
	];
	return $map[ $category ] ?? ucfirst( str_replace( '_', ' ', $category ) );
}

function estandar_chatbot_humanize_source_page_label( $page_url ) {
	$page_url = esc_url_raw( (string) $page_url );
	if ( $page_url === '' ) {
		return 'No disponible';
	}
	$path = wp_parse_url( $page_url, PHP_URL_PATH );
	$path = is_string( $path ) ? trim( $path, '/' ) : '';
	if ( $path === '' ) {
		return 'Inicio';
	}
	$last = basename( $path );
	$last = str_replace( [ '-', '_' ], ' ', $last );
	$last = trim( $last );
	return ucfirst( $last !== '' ? $last : $path );
}

function estandar_chatbot_humanize_relative_minutes() {
	return 'Acaba de escribir (hace unos minutos)';
}

function estandar_chatbot_lowercase_text( $text ) {
	$text = (string) $text;
	if ( function_exists( 'mb_strtolower' ) ) {
		return mb_strtolower( $text, 'UTF-8' );
	}
	return strtolower( $text );
}

function estandar_chatbot_notify_lead_by_email_result( array $result ) {
	$enabled = (int) get_option( 'chatbot_notify_email_enabled', 1 );
	if ( $enabled !== 1 ) {
		return [
			'ok'               => false,
			'status'           => 'skipped',
			'message'          => 'disabled',
			'recipients_count' => 0,
		];
	}

	$raw_to = (string) get_option( 'chatbot_notify_email_to', '' );
	if ( trim( $raw_to ) === '' ) {
		$raw_to = (string) get_option( 'admin_email', '' );
	}

	$recipients = [];
	foreach ( explode( ',', $raw_to ) as $mail ) {
		$san = sanitize_email( trim( $mail ) );
		if ( is_email( $san ) ) {
			$recipients[] = $san;
		}
	}
	$recipients = array_values( array_unique( $recipients ) );
	if ( empty( $recipients ) ) {
		return [
			'ok'               => false,
			'status'           => 'failed',
			'message'          => 'missing_recipients',
			'recipients_count' => 0,
		];
	}

	$lead   = (array) ( $result['lead'] ?? [] );
	$status = sanitize_key( $result['status'] ?? 'created' );

	$subject = (string) get_option( 'chatbot_notify_email_subject', 'Nuevo lead desde chatbot' );
	$subject = trim( sanitize_text_field( $subject ) );
	if ( $subject === '' ) {
		$subject = 'Nuevo lead desde chatbot';
	}

	$name = trim( (string) ( $lead['name'] ?? '' ) );
	$phone = trim( (string) ( $lead['phone'] ?? '' ) );
	$intent_text = estandar_chatbot_humanize_lead_intent( $lead['intent'] ?? '', $lead );
	$lead_intent_text = estandar_chatbot_humanize_lead_intent( $lead['lead_intent'] ?? $lead['intent'] ?? '', $lead );
	$last_user_message = trim( (string) ( $lead['last_user_message'] ?? '' ) );
	$conversation_summary = trim( (string) ( $lead['conversation_summary'] ?? '' ) );
	$conversation_recent = trim( (string) ( $lead['conversation_recent'] ?? '' ) );
	$client_message = trim( (string) ( $lead['requirement'] ?? $last_user_message ) );
	$page_url = (string) ( $lead['source_page_url'] ?? '' );
	$page_label = estandar_chatbot_humanize_source_page_label( $page_url );
	$priority = estandar_chatbot_humanize_lead_priority( $lead['lead_tier'] ?? '', $lead['lead_score'] ?? 0 );
	$lead_score = (int) ( $lead['lead_score'] ?? 0 );
	$lead_quality = estandar_chatbot_lead_quality_label( $lead_score );
	$commercial_priority = estandar_chatbot_commercial_priority_label( $lead_score );
	$intent_category = sanitize_key( (string) ( $lead['intent_category'] ?? '' ) );
	$intent_mismatch = ! empty( $lead['intent_mismatch'] );
	$status_label = sanitize_text_field( (string) ( $lead['lead_status'] ?? 'new' ) );
	$email = trim( (string) ( $lead['email'] ?? '' ) );
	$contact_quality = sanitize_key( (string) ( $lead['contact_quality'] ?? CONTACT_VALID ) );
	$contact_warning = sanitize_key( (string) ( $lead['contact_warning'] ?? CONTACT_WARNING_NONE ) );
	$template = trim( (string) get_option( 'chatbot_notify_email_template', '' ) );
	if ( $template === '' ) {
		$template = estandar_chatbot_get_default_lead_email_template();
	}

	$brand = estandar_chatbot_get_brand_label();
	$opening_line = $name !== '' ? 'Hola ' . $name . ', te escribo de ' . $brand . '. Vi que ' . estandar_chatbot_lowercase_text( $intent_text ) . ' y te ayudo ahora mismo.' : 'Hola, te escribo de ' . $brand . '. Vi que ' . estandar_chatbot_lowercase_text( $intent_text ) . ' y te ayudo ahora mismo.';
	$body = estandar_chatbot_render_lead_email_template(
		$template,
		[
			'nombre' => $name !== '' ? $name : 'Sin nombre',
			'email' => $email !== '' ? $email : 'Sin email',
				'telefono' => $phone !== '' ? $phone : 'Sin teléfono',
				'mensaje' => $client_message !== '' ? $client_message : 'Sin mensaje adicional',
				'intent' => $intent_text,
				'lead_intent' => $lead_intent_text,
				'lead_score' => (string) $lead_score,
				'lead_quality' => $lead_quality,
				'commercial_priority' => $commercial_priority,
				'intent_category' => estandar_chatbot_humanize_intent_category( $intent_category ),
				'intent_mismatch' => $intent_mismatch ? 'Sí' : 'No',
				'conversation_summary' => $conversation_summary !== '' ? $conversation_summary : 'Sin resumen disponible',
				'last_user_message' => $last_user_message !== '' ? $last_user_message : 'Sin último mensaje disponible',
				'conversation_recent' => $conversation_recent !== '' ? $conversation_recent : 'Sin conversación reciente disponible',
			'origen' => $page_label . ( $page_url !== '' ? "\n" . $page_url : '' ),
		]
	);
	if ( strpos( $template, '{{conversation_summary}}' ) === false && strpos( $template, '{{conversation_recent}}' ) === false ) {
		$context_block = '<p><strong>RESUMEN:</strong><br>' . nl2br( esc_html( $conversation_summary !== '' ? $conversation_summary : 'Sin resumen disponible' ) ) . '</p>';
		$context_block .= '<p><strong>INTENCIÓN:</strong><br>' . esc_html( $lead_intent_text ) . '</p>';
		$context_block .= '<p><strong>ÚLTIMO MENSAJE:</strong><br>' . nl2br( esc_html( $last_user_message !== '' ? $last_user_message : 'Sin último mensaje disponible' ) ) . '</p>';
		$context_block .= '<p><strong>CONVERSACIÓN RECIENTE:</strong><br>' . nl2br( esc_html( $conversation_recent !== '' ? $conversation_recent : 'Sin conversación reciente disponible' ) ) . '</p><hr>';
		$body = $context_block . $body;
	}
	if ( $contact_quality === CONTACT_SUSPECT ) {
		$body = '<p style="padding:10px 12px;background:#fff7ed;color:#9a3412;border:1px solid #fdba74;"><strong>⚠ Datos de contacto requieren revisión</strong><br>' . esc_html( $contact_warning ) . '</p>' . $body;
	}

	$body .= '<hr>';
	$body .= '<p><strong>¿Qué hacer ahora?</strong></p>';
	$body .= '<p>Contáctalo lo antes posible por WhatsApp o llamada.</p>';
	$body .= '<p><strong>Guion sugerido:</strong><br>' . esc_html( $opening_line ) . '</p>';
	$body .= '<p><strong>Info interna:</strong><br>Estado: ' . esc_html( ucfirst( $status_label !== '' ? $status_label : ( $status === 'updated' ? 'actualizado' : 'nuevo' ) ) ) . '<br>Lead Score: ' . esc_html( (string) $lead_score ) . '/100<br>Quality: ' . esc_html( $lead_quality ) . '<br>Commercial Priority: ' . esc_html( $commercial_priority ) . '<br>Intent Category: ' . esc_html( estandar_chatbot_humanize_intent_category( $intent_category ) ) . '<br>Intent Mismatch: ' . esc_html( $intent_mismatch ? 'Sí' : 'No' ) . '<br>Prioridad: ' . esc_html( $priority ) . '<br>Tiempo: ' . esc_html( estandar_chatbot_humanize_relative_minutes() ) . '</p>';

	$include_signature = (int) get_option( 'chatbot_notify_email_include_signature', 1 ) === 1;
	if ( $include_signature ) {
		$body .= estandar_chatbot_get_default_email_signature_template();
	}

	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

	if ( function_exists( 'estandar_chatbot_add_mail_sender_filters' ) ) {
		estandar_chatbot_add_mail_sender_filters();
	}
	$sent = wp_mail( $recipients, $subject, wp_kses( $body, estandar_chatbot_get_allowed_email_template_tags() ), $headers );
	if ( function_exists( 'estandar_chatbot_remove_mail_sender_filters' ) ) {
		estandar_chatbot_remove_mail_sender_filters();
	}
	return [
		'ok'               => (bool) $sent,
		'status'           => $sent ? 'success' : 'failed',
		'message'          => $sent ? 'sent' : 'wp_mail_false',
		'recipients_count' => count( $recipients ),
	];
}

function estandar_chatbot_notify_lead_by_email( array $result ) {
	$email_result = estandar_chatbot_notify_lead_by_email_result( $result );
	return ! empty( $email_result['ok'] );
}

function estandar_chatbot_log_lead_delivery_status( array $result, $channel, $session_key, array $delivery ) {
	if ( ! function_exists( 'estandar_chatbot_log_event' ) ) {
		return false;
	}

	$lead = (array) ( $result['lead'] ?? [] );
	return estandar_chatbot_log_event([
		'event_name' => 'lead.delivery_status',
		'channel'    => $channel,
		'session_key'=> $session_key,
		'lead_id'    => (int) ( $result['lead_id'] ?? 0 ),
		'lead_email' => (string) ( $lead['email'] ?? '' ),
		'lead_phone' => (string) ( $lead['phone'] ?? '' ),
		'meta'       => [
			'lead_id'          => (int) ( $result['lead_id'] ?? 0 ),
			'delivery_channel' => sanitize_key( (string) ( $delivery['delivery_channel'] ?? '' ) ),
			'status'           => sanitize_key( (string) ( $delivery['status'] ?? '' ) ),
			'message'          => sanitize_text_field( (string) ( $delivery['message'] ?? '' ) ),
			'http_status'      => (int) ( $delivery['http_status'] ?? 0 ),
			'recipients_count' => (int) ( $delivery['recipients_count'] ?? 0 ),
			'timestamp'        => current_time( 'mysql' ),
		],
	]);
}

function estandar_chatbot_score_and_route_lead( array $lead ) {
	$score = 0;
	$reasons = [];

	$email = estandar_chatbot_normalize_email( $lead['email'] ?? '' );
	$phone = estandar_chatbot_normalize_phone( $lead['phone'] ?? '' );
	$name  = sanitize_text_field( $lead['name'] ?? '' );
	$intent = sanitize_key( $lead['intent'] ?? '' );
	$requirement = strtolower( sanitize_text_field( $lead['requirement'] ?? '' ) );
	$commercial_text = strtolower( trim( implode( ' ', [
		(string) ( $lead['requirement'] ?? '' ),
		(string) ( $lead['message'] ?? '' ),
		(string) ( $lead['last_user_message'] ?? '' ),
		(string) ( $lead['conversation_recent'] ?? '' ),
	] ) ) );
	$force_human = ! empty( $lead['force_human'] ) || ! empty( $lead['escalation_requested'] );
	$classification = function_exists( 'estandar_chatbot_classify_lead_intent' ) ? estandar_chatbot_classify_lead_intent( $lead ) : [ 'category' => 'otro', 'outside_service' => false ];

	if ( $email !== '' ) {
		$score += 30;
		$reasons[] = 'email';
	}
	if ( $phone !== '' ) {
		$score += 20;
		$reasons[] = 'phone';
	}
	if ( $name !== '' ) {
		$score += 10;
		$reasons[] = 'name';
	}

	if ( in_array( $intent, [ 'ventas', 'cotizacion', 'compra' ], true ) ) {
		$score += 30;
		$reasons[] = 'intent_sales';
	}

	if ( in_array( $intent, [ 'soporte', 'support' ], true ) ) {
		$score += 15;
		$reasons[] = 'intent_support';
	}

	if ( $force_human ) {
		$score += 40;
		$reasons[] = 'forced_human';
	}

	if ( preg_match( '/(crear|creando|abrir|constituir|formar).{0,30}empresa|inicio de actividades|antes de constituir/', $commercial_text ) ) {
		$score += 15;
		$reasons[] = 'company_creation';
	}
	if ( preg_match( '/(reunion|reunión|agenda|agendar|llamada|videollamada|meet)/', $commercial_text ) ) {
		$score += 20;
		$reasons[] = 'meeting_requested';
	}
	if ( preg_match( '/(proyecto|modelo de negocio|servicio|cliente|clientes|operacion|operación)/', $commercial_text ) ) {
		$score += 20;
		$reasons[] = 'project_context';
	}
	if ( preg_match( '/(exportacion|exportación|exportar|extranjero|exterior|servicios al exterior)/', $commercial_text ) ) {
		$score += 15;
		$reasons[] = 'services_export';
	}
	if ( preg_match( '/(tributario|tributaria|impuesto|iva|renta|regimen|régimen|sii)/', $commercial_text ) ) {
		$score += 15;
		$reasons[] = 'tax_context';
	}
	if ( strlen( $commercial_text ) >= 220 ) {
		$score += 15;
		$reasons[] = 'specific_long_message';
	}

	$urgent_keywords = [ 'urgente', 'hoy', 'ahora', 'inmediato', 'asap' ];
	foreach ( $urgent_keywords as $kw ) {
		if ( $requirement !== '' && strpos( $requirement, $kw ) !== false ) {
			$score += 12;
			$reasons[] = 'urgent_kw';
			break;
		}
	}
	if ( ! empty( $classification['outside_service'] ) ) {
		$score -= 50;
		$reasons[] = 'outside_service';
	}
	if ( ! empty( $lead['intent_mismatch'] ) || ( function_exists( 'estandar_chatbot_has_intent_mismatch' ) && estandar_chatbot_has_intent_mismatch( $lead, $classification ) ) ) {
		$score -= 30;
		$reasons[] = 'intent_mismatch';
	}
	$score = max( 0, min( 100, $score ) );

	$hot_threshold = max( 0, (int) get_option( 'chatbot_lead_hot_threshold', 80 ) );
	$warm_threshold = max( 0, (int) get_option( 'chatbot_lead_warm_threshold', 50 ) );
	if ( $warm_threshold > $hot_threshold ) {
		$warm_threshold = $hot_threshold;
	}

	if ( $score >= $hot_threshold ) {
		$tier = 'hot';
	} elseif ( $score >= $warm_threshold ) {
		$tier = 'warm';
	} else {
		$tier = 'cold';
	}

	$route = 'nurturing';
	if ( $force_human ) {
		$route = 'human_handoff';
	} elseif ( in_array( $intent, [ 'soporte', 'support' ], true ) ) {
		$route = 'support_team';
	} elseif ( $tier === 'hot' ) {
		$route = 'sales_senior';
	} elseif ( $tier === 'warm' ) {
		$route = 'sales_inbound';
	}

	return [
		'score'   => (int) $score,
		'tier'    => $tier,
		'route'   => $route,
		'reasons' => $reasons,
	];
}

function estandar_chatbot_dispatch_lead_webhook( array $event ) {
	if ( get_option( 'estandar_leads_webhook_owner', 'legacy_forms' ) === 'leads' ) {
		return [ 'ok' => false, 'status' => 0, 'message' => 'owned_by_estandar_leads' ];
	}

	$enabled = (int) get_option( 'chatbot_webhook_enabled', 0 );
	if ( $enabled !== 1 ) {
		return [ 'ok' => false, 'status' => 0, 'message' => 'disabled' ];
	}

	$url = esc_url_raw( (string) get_option( 'chatbot_webhook_url', '' ) );
	if ( ! $url ) {
		return [ 'ok' => false, 'status' => 0, 'message' => 'missing_url' ];
	}

	$provider = sanitize_key( (string) get_option( 'chatbot_webhook_provider', 'n8n' ) );
	$secret   = (string) get_option( 'chatbot_webhook_secret', '' );
	$event_name = strtolower( trim( (string) ( $event['event'] ?? 'lead.captured' ) ) );
	$event_name = preg_replace( '/[^a-z0-9._-]/', '', $event_name );
	if ( $event_name === '' ) {
		$event_name = 'lead.captured';
	}
	$body     = wp_json_encode( $event );

	$headers = [
		'Content-Type'     => 'application/json',
		'X-ECB-Provider'   => $provider,
		'X-ECB-Event'      => $event_name,
	];
	if ( $secret !== '' ) {
		$headers['X-ECB-Signature'] = hash_hmac( 'sha256', $body, $secret );
	}

	$response = wp_remote_post( $url, [
		'timeout' => 10,
		'headers' => $headers,
		'body'    => $body,
	] );

	if ( is_wp_error( $response ) ) {
		return [ 'ok' => false, 'status' => 0, 'message' => $response->get_error_message() ];
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	return [
		'ok'      => $status >= 200 && $status < 300,
		'status'  => $status,
		'message' => (string) wp_remote_retrieve_body( $response ),
	];
}

function estandar_chatbot_count_leads() {
	global $wpdb;

	$cached = get_transient( ESTANDAR_CHATBOT_LEADS_COUNT_TRANSIENT );
	if ( $cached !== false ) {
		return (int) $cached;
	}

	estandar_chatbot_ensure_leads_table();
	$table = estandar_chatbot_get_leads_table();
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	set_transient( ESTANDAR_CHATBOT_LEADS_COUNT_TRANSIENT, $count, 30 );
	return $count;
}

function estandar_chatbot_count_leads_today() {
	global $wpdb;

	$cache_key = ESTANDAR_CHATBOT_LEADS_COUNT_TRANSIENT . '_today';
	$cached = get_transient( $cache_key );
	if ( $cached !== false ) {
		return (int) $cached;
	}

	estandar_chatbot_ensure_leads_table();
	$table = estandar_chatbot_get_leads_table();
	$today = current_time( 'Y-m-d' );
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = %s",
			$today
		)
	);
	set_transient( $cache_key, $count, 30 );
	return $count;
}

function estandar_chatbot_get_leads( $limit = 200, $offset = 0 ) {
	global $wpdb;

	$limit = max( 1, (int) $limit );
	$offset = max( 0, (int) $offset );
	$gen       = (int) get_option( ESTANDAR_CHATBOT_LEADS_LIST_CACHE_GEN_OPTION, 1 );
	$cache_key = ESTANDAR_CHATBOT_LEADS_LIST_TRANSIENT_PREFIX . $gen . '_' . $limit . '_' . $offset;
	$cached = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	estandar_chatbot_ensure_leads_table();
	$table = estandar_chatbot_get_leads_table();
	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ),
		ARRAY_A
	);

	$out = [];
	foreach ( $rows as $row ) {
		$data = json_decode( (string) ( $row['data'] ?? '{}' ), true );
		if ( ! is_array( $data ) ) {
			$data = [];
		}
		$base = [
			'_db_id'              => (int) ( $row['id'] ?? 0 ),
			'created_at'          => (string) ( $row['created_at'] ?? '' ),
			'last_interaction_at' => (string) ( $row['last_interaction_at'] ?? '' ),
			'email'               => (string) ( $row['email'] ?? '' ),
			'phone'               => (string) ( $row['phone'] ?? '' ),
		];
		$out[] = estandar_chatbot_merge_lead_payload( $base, $data );
	}

	set_transient( $cache_key, $out, 30 );
	return $out;
}

function estandar_chatbot_get_lead_by_id( $id ) {
	global $wpdb;

	$id = (int) $id;
	if ( $id <= 0 ) {
		return null;
	}

	estandar_chatbot_ensure_leads_table();
	$table = estandar_chatbot_get_leads_table();
	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return null;
	}

	$data = json_decode( (string) ( $row['data'] ?? '{}' ), true );
	if ( ! is_array( $data ) ) {
		$data = [];
	}

	$base = [
		'_db_id'              => (int) ( $row['id'] ?? 0 ),
		'created_at'          => (string) ( $row['created_at'] ?? '' ),
		'last_interaction_at' => (string) ( $row['last_interaction_at'] ?? '' ),
		'email'               => (string) ( $row['email'] ?? '' ),
		'phone'               => (string) ( $row['phone'] ?? '' ),
	];

	return estandar_chatbot_merge_lead_payload( $base, $data );
}

function estandar_chatbot_delete_lead_by_id( $id ) {
	global $wpdb;

	$id = (int) $id;
	if ( $id <= 0 ) return 0;

	estandar_chatbot_ensure_leads_table();
	$table = estandar_chatbot_get_leads_table();
	$deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	if ( $deleted ) {
		estandar_chatbot_leads_cache_bust();
	}
	return max( 0, (int) $deleted );
}

function estandar_chatbot_delete_leads_by_ids( array $ids ) {
	global $wpdb;

	$ids = array_values( array_filter( array_map( 'intval', $ids ), function( $v ){ return $v > 0; } ) );
	if ( empty( $ids ) ) return 0;

	estandar_chatbot_ensure_leads_table();
	$table = estandar_chatbot_get_leads_table();
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$sql = "DELETE FROM {$table} WHERE id IN ({$placeholders})";
	$deleted = $wpdb->query( $wpdb->prepare( $sql, $ids ) );
	if ( $deleted ) {
		estandar_chatbot_leads_cache_bust();
	}
	return max( 0, (int) $deleted );
}

function estandar_chatbot_update_lead_by_id( $id, array $fields ) {
	global $wpdb;

	$id = (int) $id;
	if ( $id <= 0 ) {
		return [ 'ok' => false, 'message' => 'ID inválido.' ];
	}

	estandar_chatbot_ensure_leads_table();
	$table = estandar_chatbot_get_leads_table();
	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
		ARRAY_A
	);
	if ( ! is_array( $row ) ) {
		return [ 'ok' => false, 'message' => 'Lead no encontrado.' ];
	}

	$data = json_decode( (string) ( $row['data'] ?? '{}' ), true );
	if ( ! is_array( $data ) ) {
		$data = [];
	}

	$allowed = [ 'name', 'intent', 'requirement', 'lead_tier', 'lead_score', 'lead_route', 'lead_status', 'assigned_to', 'notes', 'crm_sync_status', 'crm_sync_at', 'crm_sync_message', 'source_channel', 'source_page_url', 'project_slug', 'project_area', 'city', 'intent_category', 'intent_mismatch', 'intent_detection_source', 'source_match_slug', 'source_match_type' ];
	foreach ( $allowed as $key ) {
		if ( array_key_exists( $key, $fields ) ) {
			if ( $key === 'lead_status' ) {
				$data[ $key ] = estandar_chatbot_sanitize_lead_status( $fields[ $key ] );
			} elseif ( $key === 'source_page_url' ) {
				$data[ $key ] = estandar_chatbot_sanitize_source_page_url( $fields[ $key ] );
			} else {
				$data[ $key ] = sanitize_text_field( (string) $fields[ $key ] );
			}
		}
	}

	$email_in = array_key_exists( 'email', $fields ) ? (string) $fields['email'] : (string) ( $row['email'] ?? '' );
	$phone_in = array_key_exists( 'phone', $fields ) ? (string) $fields['phone'] : (string) ( $row['phone'] ?? '' );
	$email = estandar_chatbot_normalize_email( $email_in );
	$phone = estandar_chatbot_normalize_phone( $phone_in );

	if ( $email === '' && $phone === '' ) {
		return [ 'ok' => false, 'message' => 'Debe existir email o teléfono.' ];
	}

	$data = estandar_chatbot_detect_lead_context( $data );

	$where_parts = [];
	$where_vals = [];
	if ( $email !== '' ) {
		$where_parts[] = 'email = %s';
		$where_vals[] = $email;
	}
	if ( $phone !== '' ) {
		$where_parts[] = 'phone = %s';
		$where_vals[] = $phone;
	}
	if ( ! empty( $where_parts ) ) {
		$where_vals[] = $id;
		$conflict = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE (" . implode( ' OR ', $where_parts ) . ') AND id <> %d LIMIT 1',
				$where_vals
			)
		);
		if ( ! empty( $conflict ) ) {
			return [ 'ok' => false, 'message' => 'Email o teléfono ya pertenecen a otro lead.' ];
		}
	}

	$now = current_time( 'mysql' );
	$ok = $wpdb->update(
		$table,
		[
			'email'               => $email ?: null,
			'phone'               => $phone ?: null,
			'data'                => wp_json_encode( $data ),
			'last_interaction_at' => $now,
		],
		[ 'id' => $id ],
		[ '%s', '%s', '%s', '%s' ],
		[ '%d' ]
	);

	if ( $ok === false ) {
		return [ 'ok' => false, 'message' => 'No se pudo actualizar el lead.' ];
	}

	estandar_chatbot_update_leads_columns( array_keys( $data ) );
	estandar_chatbot_leads_cache_bust();
	return [ 'ok' => true, 'message' => 'Lead actualizado correctamente.' ];
}

function estandar_chatbot_mark_lead_crm_sync( $id, $status, $message = '' ) {
	return estandar_chatbot_update_lead_by_id( (int) $id, [
		'crm_sync_status'  => sanitize_key( (string) $status ),
		'crm_sync_at'      => current_time( 'mysql' ),
		'crm_sync_message' => sanitize_text_field( (string) $message ),
	] );
}

function estandar_chatbot_reprocess_lead_source_intents() {
	global $wpdb;

	estandar_chatbot_ensure_leads_table();
	$table = estandar_chatbot_get_leads_table();
	$rows = $wpdb->get_results( "SELECT id FROM {$table} ORDER BY id ASC", ARRAY_A );
	if ( empty( $rows ) ) {
		return [ 'updated' => 0, 'matched' => 0 ];
	}

	$updated = 0;
	$matched = 0;
	foreach ( $rows as $row ) {
		$lead_id = (int) ( $row['id'] ?? 0 );
		if ( $lead_id <= 0 ) {
			continue;
		}

		$lead = estandar_chatbot_get_lead_by_id( $lead_id );
		if ( ! is_array( $lead ) ) {
			continue;
		}

		$match = estandar_chatbot_match_source_context( (string) ( $lead['source_page_url'] ?? '' ) );
		if ( empty( $match ) ) {
			continue;
		}

		$matched++;
		$enriched = estandar_chatbot_detect_lead_context( $lead );
		$fields = [];

		foreach ( [ 'intent', 'project_slug', 'project_area', 'city', 'intent_detection_source', 'source_match_slug', 'source_match_type' ] as $key ) {
			$new_value = isset( $enriched[ $key ] ) ? (string) $enriched[ $key ] : '';
			$old_value = isset( $lead[ $key ] ) ? (string) $lead[ $key ] : '';
			if ( $new_value !== '' && $new_value !== $old_value ) {
				$fields[ $key ] = $new_value;
			}
		}

		if ( empty( $fields ) ) {
			continue;
		}

		$result = estandar_chatbot_update_lead_by_id( $lead_id, $fields );
		if ( ! empty( $result['ok'] ) ) {
			$updated++;
		}
	}

	return [
		'updated' => $updated,
		'matched' => $matched,
	];
}

function estandar_chatbot_dispatch_crm_lead( array $result, array $context = [] ) {
	$enabled = (int) get_option( 'chatbot_crm_enabled', 0 );
	if ( $enabled !== 1 ) {
		return [ 'ok' => false, 'status' => 0, 'message' => 'disabled' ];
	}

	$url = esc_url_raw( (string) get_option( 'chatbot_crm_url', '' ) );
	if ( ! $url ) {
		return [ 'ok' => false, 'status' => 0, 'message' => 'missing_url' ];
	}

	$provider = sanitize_key( (string) get_option( 'chatbot_crm_provider', 'generic' ) );
	$secret = (string) get_option( 'chatbot_crm_secret', '' );
	$lead = (array) ( $result['lead'] ?? [] );
	$body_payload = [
		'event'     => 'lead.sync',
		'provider'  => $provider,
		'site'      => home_url( '/' ),
		'timestamp' => current_time( 'mysql' ),
		'status'    => (string) ( $result['status'] ?? 'created' ),
		'lead_id'   => (int) ( $result['lead_id'] ?? 0 ),
		'lead'      => $lead,
		'context'   => $context,
	];
	$body = wp_json_encode( $body_payload );
	$headers = [
		'Content-Type'   => 'application/json',
		'X-ECB-Provider' => $provider,
	];
	if ( $secret !== '' ) {
		$headers['X-ECB-Signature'] = hash_hmac( 'sha256', $body, $secret );
	}

	$response = wp_remote_post( $url, [
		'timeout' => 12,
		'headers' => $headers,
		'body'    => $body,
	] );
	if ( is_wp_error( $response ) ) {
		return [ 'ok' => false, 'status' => 0, 'message' => $response->get_error_message() ];
	}

	$status_code = (int) wp_remote_retrieve_response_code( $response );
	return [
		'ok'      => $status_code >= 200 && $status_code < 300,
		'status'  => $status_code,
		'message' => (string) wp_remote_retrieve_body( $response ),
	];
}

function estandar_chatbot_find_lead( $email = '', $phone = '' ) {
	global $wpdb;

	$email_norm = estandar_chatbot_normalize_email( $email );
	$phone_norm = estandar_chatbot_normalize_phone( $phone );

	if ( $email_norm === '' && $phone_norm === '' ) return null;

	estandar_chatbot_ensure_leads_table();
	$table = estandar_chatbot_get_leads_table();

	$where_parts = [];
	$where_vals = [];
	if ( $email_norm !== '' ) {
		$where_parts[] = 'email = %s';
		$where_vals[] = $email_norm;
	}
	if ( $phone_norm !== '' ) {
		$where_parts[] = 'phone = %s';
		$where_vals[] = $phone_norm;
	}

	$sql = "SELECT * FROM {$table} WHERE " . implode( ' OR ', $where_parts ) . ' ORDER BY id ASC LIMIT 1';
	$row = $wpdb->get_row( $wpdb->prepare( $sql, $where_vals ), ARRAY_A );
	if ( ! is_array( $row ) ) return null;

	$data = json_decode( (string) ( $row['data'] ?? '{}' ), true );
	if ( ! is_array( $data ) ) {
		$data = [];
	}

	$base = [
		'created_at'          => (string) ( $row['created_at'] ?? '' ),
		'last_interaction_at' => (string) ( $row['last_interaction_at'] ?? '' ),
		'email'               => (string) ( $row['email'] ?? '' ),
		'phone'               => (string) ( $row['phone'] ?? '' ),
	];

	return estandar_chatbot_merge_lead_payload( $base, $data );
}

function estandar_chatbot_is_known_lead( $email = '', $phone = '' ) {
	return estandar_chatbot_find_lead( $email, $phone ) ? true : false;
}

function estandar_chatbot_get_leads_csv_content() {
	$columns = estandar_chatbot_get_leads_columns();
	$leads   = estandar_chatbot_get_leads( 1000000 );
	$out     = fopen( 'php://temp', 'r+' );
	fputcsv( $out, $columns, ',', '"', '\\' );
	foreach ( array_reverse( $leads ) as $lead ) {
		$line = [];
		foreach ( $columns as $col ) {
			$line[] = estandar_chatbot_csv_safe_cell( (string) ( $lead[ $col ] ?? '' ) );
		}
		fputcsv( $out, $line, ',', '"', '\\' );
	}
	rewind( $out );
	$content = stream_get_contents( $out );
	fclose( $out );
	return $content;
}

function estandar_chatbot_output_leads_csv( $download_name = 'estandar-chatbot-leads.csv' ) {
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . basename( $download_name ) . '"' );
	echo estandar_chatbot_get_leads_csv_content();
	exit;
}

add_action( 'wp_ajax_estandar_chatbot_check_lead', 'estandar_chatbot_check_lead_ajax' );
add_action( 'wp_ajax_nopriv_estandar_chatbot_check_lead', 'estandar_chatbot_check_lead_ajax' );

function estandar_chatbot_check_lead_ajax() {
	estandar_chatbot_verify_nonce_or_die();

	// Rate limit (anti-enumeración en endpoint público)
	$meta = estandar_chatbot_get_request_meta();
	$ip   = $meta['ip'] ?: 'unknown';
	$key  = 'ecb_rl_check_lead_' . md5( $ip );
	$hits = (int) get_transient( $key );
	if ( $hits >= 15 ) {
		wp_send_json_error( [ 'message' => 'Demasiadas solicitudes. Intenta más tarde.' ], 429 );
	}
	set_transient( $key, $hits + 1, 60 );

	$email = isset($_POST['email'])
		? sanitize_email( wp_unslash($_POST['email']) )
		: '';

	$phone = isset($_POST['phone'])
		? sanitize_text_field( wp_unslash($_POST['phone']) )
		: '';

	$exists = estandar_chatbot_is_known_lead( $email, $phone );
	$is_public = ! is_user_logged_in();
	if ( $is_public ) {
		// Endurecido: no exponer existencia de PII en endpoint público.
		$exists = false;
	}

	wp_send_json_success([
		'exists'  => $exists,
		'message' => ( $exists && ! $is_public )
			? get_option(
				'chatbot_mensaje_ya_registrado',
				'Ya tengo tus datos registrados de una vez anterior en la que ya conversamos. ¿Deseas actualizar tu información o continuar?'
			)
			: '',
	]);
}
