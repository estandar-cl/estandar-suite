<?php
/**
 * Plugin Name: Estandar Chatbot IA
 * Plugin URI: https://www.estandar.cl
 * Description: Chatbot IA reutilizable para WordPress. Conversación, botones, leads y logs.
 * Version: 13.0.172
 * Author: Luis Valdés de Estandar Studios CL
 * Author URI: https://www.estandar.cl
 * Text Domain: estandar-chatbot
 * Update URI: https://updates.estandar.cl
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ======================================================
 * CONSTANTES GLOBALES
 * ====================================================== */

define( 'ESTANDAR_CHATBOT_VERSION', '13.0.158' );
define( 'ESTANDAR_CHATBOT_DB_VERSION', '2.1.0' );
defined( 'CONTACT_VALID' ) || define( 'CONTACT_VALID', 'valid' );
defined( 'CONTACT_SUSPECT' ) || define( 'CONTACT_SUSPECT', 'suspect' );
defined( 'CONTACT_REJECTED' ) || define( 'CONTACT_REJECTED', 'rejected' );
defined( 'CONTACT_WARNING_NONE' ) || define( 'CONTACT_WARNING_NONE', 'none' );
defined( 'CONTACT_WARNING_PHONE_INVALID' ) || define( 'CONTACT_WARNING_PHONE_INVALID', 'phone_invalid' );
defined( 'CONTACT_WARNING_EMAIL_INVALID' ) || define( 'CONTACT_WARNING_EMAIL_INVALID', 'email_invalid' );

// Bootstrap fallback for primer_is_active().
// Executes only when Primer has not yet loaded (WordPress plugin load order is not
// guaranteed). Skipped entirely once Primer is present — Primer's own definition
// takes ownership.
if ( ! function_exists( 'primer_is_active' ) ) {
	function primer_is_active(): bool {
		return defined( 'PRIMER_VERSION' );
	}
}

// Bootstrap fallback for resolve_project_display_name() — definida en Primer,
// llamada sin function_exists() guard en este plugin. Sin este polyfill,
// desactivar Primer produce un fatal en vez de degradar con gracia.
if ( ! function_exists( 'resolve_project_display_name' ) ) {
	function resolve_project_display_name( $slug, $fallback_area = '' ): string {
		return '' !== (string) $fallback_area ? (string) $fallback_area : (string) $slug;
	}
}

function estandar_chatbot_mail_from_filter( $email ) {
	return function_exists( 'estandar_core_get_sender_email' )
		? estandar_core_get_sender_email( '', (string) $email )
		: $email;
}

function estandar_chatbot_mail_from_name_filter( $name ) {
	return function_exists( 'estandar_core_get_sender_name' )
		? estandar_core_get_sender_name( '', (string) $name )
		: $name;
}

function estandar_chatbot_add_mail_sender_filters(): void {
	add_filter( 'wp_mail_from', 'estandar_chatbot_mail_from_filter' );
	add_filter( 'wp_mail_from_name', 'estandar_chatbot_mail_from_name_filter' );
}

function estandar_chatbot_remove_mail_sender_filters(): void {
	remove_filter( 'wp_mail_from', 'estandar_chatbot_mail_from_filter' );
	remove_filter( 'wp_mail_from_name', 'estandar_chatbot_mail_from_name_filter' );
}

function estandar_chatbot_get_openai_api_key(): string {
	$api_key = (string) get_option( 'chatbot_openai_api_key', '' );
	$api_key = str_replace( [ "\r", "\n", "\t" ], '', $api_key );
	$api_key = trim( $api_key );

	if ( '' === $api_key ) {
		$api_key = (string) apply_filters( 'estandar_chatbot_openai_api_key', '' );
		$api_key = trim( str_replace( [ "\r", "\n", "\t" ], '', $api_key ) );
	}

	if ( '' === $api_key && defined( 'ESTANDAR_CHATBOT_OPENAI_KEY' ) ) {
		$api_key = trim( (string) ESTANDAR_CHATBOT_OPENAI_KEY );
	}

	return $api_key;
}

function estandar_chatbot_get_business_context_defaults() {
	return [
		'chatbot_business_industry'         => 'servicios',
		'chatbot_business_goal'             => 'captar contactos',
		'chatbot_business_primary_cta'      => 'Quiero avanzar',
		'chatbot_business_sensitive_topics' => 'precio, plazos, disponibilidad',
		'chatbot_business_tone'             => 'claro y profesional',
	];
}

function estandar_chatbot_maybe_migrate_business_context_options() {
	$defaults = estandar_chatbot_get_business_context_defaults();
	$aliases  = [
		'chatbot_business_industry'         => [ 'chatbot_rubro', 'chatbot_business_type', 'chatbot_industry' ],
		'chatbot_business_goal'             => [ 'chatbot_objetivo', 'chatbot_goal', 'chatbot_main_goal' ],
		'chatbot_business_primary_cta'      => [ 'chatbot_cta_principal', 'chatbot_cta', 'chatbot_primary_cta' ],
		'chatbot_business_sensitive_topics' => [ 'chatbot_temas_sensibles', 'chatbot_sensitive_topics' ],
		'chatbot_business_tone'             => [ 'chatbot_tono', 'chatbot_tone' ],
	];
	$cta_labels    = get_option( 'chatbot_cta_labels', [] );
	$behavior_copy = get_option( 'chatbot_behavior_copy', [] );
	$changed       = false;

	foreach ( $defaults as $option_name => $fallback ) {
		$current = get_option( $option_name, null );
		$current = is_string( $current ) ? trim( $current ) : $current;
		if ( $current !== null && $current !== '' ) {
			continue;
		}

		$resolved = '';
		foreach ( $aliases[ $option_name ] ?? [] as $legacy_option ) {
			$legacy_value = get_option( $legacy_option, null );
			$legacy_value = is_string( $legacy_value ) ? trim( $legacy_value ) : $legacy_value;
			if ( $legacy_value !== null && $legacy_value !== '' ) {
				$resolved = (string) $legacy_value;
				break;
			}
		}

		if ( $resolved === '' ) {
			if ( $option_name === 'chatbot_business_primary_cta' ) {
				$resolved = trim( (string) ( $behavior_copy['special_service_cta_label'] ?? '' ) );
				if ( $resolved === '' ) {
					$resolved = trim( (string) ( $cta_labels['default'] ?? '' ) );
				}
			} elseif ( $option_name === 'chatbot_business_goal' ) {
				$lead_capture_enabled = (int) get_option( 'chatbot_lead_capture_enabled', 1 );
				$resolved = $lead_capture_enabled ? 'captar contactos y avanzar conversaciones' : $fallback;
			} elseif ( $option_name === 'chatbot_business_sensitive_topics' ) {
				$resolved = trim( (string) ( $behavior_copy['tax_question_keywords'] ?? '' ) );
				if ( $resolved !== '' ) {
					$resolved = str_replace( "\n", ', ', $resolved );
				}
			}
		}

		if ( $resolved === '' ) {
			$resolved = $fallback;
		}

		update_option( $option_name, $resolved, false );
		$changed = true;
	}

	return $changed;
}

function estandar_chatbot_get_legacy_behavior_copy_defaults() {
	return [
		'special_service_keyword'            => 'renta',
		'special_service_cta_label'          => 'Hacer mi renta',
		'high_intent_keywords'               => "precio\npagar\ncontratar\nok\nsi\nsí\ncotizar\nplan\nrenta\ncomprar\navanzar",
		'tax_question_keywords'              => "cuanto tengo que pagar\ncuánto tengo que pagar\ndevolucion\ndevolución\nimpuesto",
		'high_intent_transition_message'     => "Perfecto 👌\n\nPara avanzar ahora mismo, te pido un dato breve.\n¿Cuál es tu nombre? 👇",
		'special_service_transition_message' => "Perfecto 👌\n\nPara ayudarte con tu renta, déjame tu nombre 👇",
		'price_transition_message'           => "Depende de tu caso.\n\nEl valor final puede cambiar según lo que tengas que pagar o incluso si te corresponde devolución.\nPara decirte bien cómo aplica, dime tu nombre 👇",
		'price_cta_message'                  => 'Depende del caso, pero puedes revisar opciones ahora mismo 👇',
		'price_whatsapp_message'             => 'Depende del caso, pero te dejo el acceso directo para verlo contigo 👇',
		'objection_message'                  => "Da lo mismo el monto 👌\n\nLo importante es que no pagues de más.\nMuchas veces incluso puedes recibir devolución.",
		'objection_repeat_message'           => 'Lo clave aquí es que revisemos tu caso para que no pagues de más 👇',
		'tax_cta_message'                    => 'Depende de tu caso, pero muchas veces se puede bajar bastante el monto o incluso generar devolución.',
		'post_lead_hot_message'              => "Perfecto 👌\n\nYa tengo tus datos.\n\nSi quieres que te contacten ahora, toca el botón de abajo 👇",
		'website_input_message'              => "Perfecto 👌\n\nYa vi tu web: {{website}}.\n\nVeamos cómo funcionaría ahí 👇",
	];
}

function estandar_chatbot_get_behavior_copy_defaults() {
	return [
		'special_service_keyword'            => 'servicio',
		'special_service_cta_label'          => 'Quiero avanzar',
		'high_intent_keywords'               => "precio\ncontratar\ncotizar\ncomprar\nreservar\nagendar\navanzar\nme interesa\nquiero",
		'tax_question_keywords'              => "precio\ncosto\nstock\ndisponibilidad\nplazo\ncobertura\ndespacho",
		'high_intent_transition_message'     => "Perfecto 👌\n\nPara avanzar ahora mismo, te pido un dato breve.\n¿Cuál es tu nombre? 👇",
			'special_service_transition_message' => "Perfecto 👌\n\nPara ayudarte con eso, dime tu nombre 👇",
			'price_transition_message'           => "Depende de lo que necesites.\n\nPara orientarte mejor, dime tu nombre 👇",
		'price_cta_message'                  => 'Depende de lo que necesites, pero puedo orientarte rápido ahora mismo 👇',
		'price_whatsapp_message'             => 'Te dejo el acceso directo para verlo contigo ahora mismo 👇',
		'objection_message'                  => "Lo importante aquí es encontrar la mejor opción para tu caso 👌\n\nSi quieres, lo revisamos contigo paso a paso.",
		'objection_repeat_message'           => 'Si quieres, revisamos tu caso contigo y te orientamos con la mejor opción 👇',
		'tax_cta_message'                    => 'Eso depende del caso, pero te puedo orientar rápido para ver qué aplica aquí 👇',
		'post_lead_hot_message'              => "Perfecto 👌\n\nYa tengo tus datos.\n\nSi quieres que te contacten ahora, toca el botón de abajo 👇",
		'website_input_message'              => "Perfecto 👌\n\nYa vi tu web: {{website}}.\n\nVeamos cómo funcionaría ahí 👇",
	];
}

function estandar_chatbot_maybe_migrate_behavior_copy_to_generic_defaults() {
	$current = get_option( 'chatbot_behavior_copy', null );
	if ( ! is_array( $current ) ) {
		return;
	}

	$legacy   = estandar_chatbot_get_legacy_behavior_copy_defaults();
	$generic  = estandar_chatbot_get_behavior_copy_defaults();
	$updated  = $current;
	$changed  = false;

	foreach ( $generic as $key => $default_value ) {
		$current_value = isset( $current[ $key ] ) ? (string) $current[ $key ] : null;
		$legacy_value  = isset( $legacy[ $key ] ) ? (string) $legacy[ $key ] : null;

		if ( $current_value === null || ( $legacy_value !== null && $current_value === $legacy_value ) ) {
			$updated[ $key ] = $default_value;
			$changed = true;
		}
	}

	if ( $changed ) {
		update_option( 'chatbot_behavior_copy', $updated, false );
	}
}

/*
 * Changelog (interno):
 * 10.3.10 — Política comercial de contacto: conserva leads recuperables y marca datos sospechosos.
 * 10.3.9 — Validación y normalización estricta de celulares chilenos antes de crear leads.
 * 1.0.2 — Logging de interacciones ampliado (tipo, sesión, URL página, lead ref, potential_faq);
 *          CSV interactions_audit + export admin; logs diferidos (shutdown); webhook enriquecido
 *          con conversación y handoff_context; flujo JSON opcional (intro); page_url en IA AJAX.
 * 1.0.1 — Registro de canales extensible, source_page_url en leads.
 */
define( 'ESTANDAR_CHATBOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_CHATBOT_URL', plugin_dir_url( __FILE__ ) );
// Storage portable (wp-content/uploads)
$upload_dir = wp_upload_dir();
define( 'ESTANDAR_CHATBOT_STORAGE', trailingslashit( $upload_dir['basedir'] ) . 'estandar-chatbot/' );

/**
 * ⚠️ OPENAI API KEY (NO HARDCODEAR EN PRODUCCIÓN)
 * Recomendado: usar el campo en admin (wp_options) o definir en wp-config.php.
 * Esta constante es solo un fallback opcional.
 */
if ( ! defined( 'ESTANDAR_CHATBOT_OPENAI_KEY' ) ) {
	define( 'ESTANDAR_CHATBOT_OPENAI_KEY', '' );
}

/* ======================================================
 * ACTIVACIÓN / DESACTIVACIÓN
 * ====================================================== */

register_activation_hook( __FILE__, 'estandar_chatbot_activate' );
function estandar_chatbot_activate() {

	$dirs = [
		ESTANDAR_CHATBOT_STORAGE,
		ESTANDAR_CHATBOT_STORAGE . 'leads/',
		ESTANDAR_CHATBOT_STORAGE . 'logs/',
	];

	foreach ( $dirs as $dir ) {
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}

	$htaccess = ESTANDAR_CHATBOT_STORAGE . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		// Bloquea acceso directo al storage
		file_put_contents( $htaccess, "Deny from all\nRequire all denied\n" );
	}

	if ( function_exists( 'estandar_chatbot_ensure_leads_table' ) ) {
		estandar_chatbot_ensure_leads_table();
	}
	if ( function_exists( 'estandar_chatbot_ensure_logs_table' ) ) {
		estandar_chatbot_ensure_logs_table();
	}
	if ( function_exists( 'estandar_chatbot_ensure_conversations_table' ) ) {
		estandar_chatbot_ensure_conversations_table();
	}
	if ( function_exists( 'estandar_chatbot_ensure_events_table' ) ) {
		estandar_chatbot_ensure_events_table();
	}
	if ( function_exists( 'estandar_chatbot_maybe_migrate_csv_leads_to_db' ) ) {
		estandar_chatbot_maybe_migrate_csv_leads_to_db();
	}
	if ( function_exists( 'estandar_chatbot_maybe_migrate_csv_logs_to_db' ) ) {
		estandar_chatbot_maybe_migrate_csv_logs_to_db();
	}
	update_option( 'estandar_chatbot_db_version', ESTANDAR_CHATBOT_DB_VERSION, false );

	if ( ! wp_next_scheduled( 'estandar_chatbot_daily_maintenance' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'estandar_chatbot_daily_maintenance' );
	}

	estandar_chatbot_bootstrap_defaults();
	estandar_chatbot_ensure_inline_page();
	if ( function_exists( 'estandar_chatbot_check_environment' ) ) {
		estandar_chatbot_check_environment();
	}
	if ( function_exists( 'estandar_chatbot_mark_environment_success_notice_pending' ) ) {
		estandar_chatbot_mark_environment_success_notice_pending();
	}
}

function estandar_chatbot_bootstrap_defaults() {
	// Defaults de primera instalación para experiencia inline estilo chat limpio.
	$defaults = [
		'chatbot_activar'                        => 1,
		'chatbot_inline_sidebar_enabled'         => 1,
		'chatbot_inline_fixed_composer_enabled'  => 1,
		'chatbot_inline_fullpage_enabled'        => 1,
	];

	foreach ( $defaults as $key => $value ) {
		if ( get_option( $key, null ) === null ) {
			add_option( $key, $value, '', false );
		}
	}

	estandar_chatbot_maybe_migrate_behavior_copy_to_generic_defaults();
	estandar_chatbot_maybe_migrate_business_context_options();
}

function estandar_chatbot_maybe_repair_business_context_on_admin() {
	if ( ! is_admin() ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	estandar_chatbot_maybe_migrate_business_context_options();
}
add_action( 'admin_init', 'estandar_chatbot_maybe_repair_business_context_on_admin', 4 );

function estandar_chatbot_ensure_inline_page() {
	$stored_page_id = (int) get_option( 'estandar_chatbot_inline_page_id', 0 );
	if ( $stored_page_id > 0 && get_post_status( $stored_page_id ) ) {
		return $stored_page_id;
	}

	$query = new WP_Query( [
		'post_type'      => 'page',
		'post_status'    => [ 'publish', 'draft', 'private' ],
		'posts_per_page' => 1,
		'meta_key'       => '_estandar_chatbot_auto_page',
		'meta_value'     => '1',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	] );
	if ( ! empty( $query->posts ) ) {
		$page_id = (int) $query->posts[0];
		update_option( 'estandar_chatbot_inline_page_id', $page_id, false );
		return $page_id;
	}

	$page_id = wp_insert_post( [
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Asistente IA',
		'post_name'    => 'asistente-ia',
		'post_content' => '[estandar_chatbot mode="inline" height="860"]',
	] );

	if ( is_wp_error( $page_id ) || ! $page_id ) {
		return 0;
	}

	update_post_meta( $page_id, '_estandar_chatbot_auto_page', '1' );
	update_option( 'estandar_chatbot_inline_page_id', (int) $page_id, false );

	return (int) $page_id;
}

add_action( 'wp_head', 'estandar_chatbot_inline_page_layout_guard', 99 );
function estandar_chatbot_inline_page_layout_guard() {
	$page_id = (int) get_option( 'estandar_chatbot_inline_page_id', 0 );
	if ( $page_id <= 0 || ! is_page( $page_id ) ) {
		return;
	}
	if ( (int) get_option( 'chatbot_inline_fullpage_enabled', 0 ) !== 1 ) {
		return;
	}
	?>
	<style id="estandar-chatbot-inline-page-guard">
		.page-id-<?php echo (int) $page_id; ?> .entry-title,
		.page-id-<?php echo (int) $page_id; ?> .wp-block-post-title,
		.page-id-<?php echo (int) $page_id; ?> .entry-header,
		.page-id-<?php echo (int) $page_id; ?> .page-header,
		.page-id-<?php echo (int) $page_id; ?> header.entry-header{
			display:none !important;
		}
		.page-id-<?php echo (int) $page_id; ?> .entry-content,
		.page-id-<?php echo (int) $page_id; ?> .wp-site-blocks,
		.page-id-<?php echo (int) $page_id; ?> main{
			margin-top:0 !important;
			padding-top:0 !important;
		}
		.page-id-<?php echo (int) $page_id; ?> .entry-content .estandar-chatbot-shortcode[data-ecb-shortcode="1"]{
			display:block;
			width:100vw;
			max-width:100vw;
			margin-left:calc(50% - 50vw);
			margin-right:calc(50% - 50vw);
		}
	</style>
	<?php
}

register_deactivation_hook( __FILE__, 'estandar_chatbot_deactivate' );
function estandar_chatbot_deactivate() {
	$timestamp = wp_next_scheduled( 'estandar_chatbot_daily_maintenance' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'estandar_chatbot_daily_maintenance' );
	}
}

/* ======================================================
 * INCLUDES
 * ====================================================== */

require_once ESTANDAR_CHATBOT_PATH . 'includes/helpers.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/config-resolver.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/env-checker.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/channels-registry.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/permissions.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/openai-proxy.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/context-loader.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/leads-handler.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/leads-endpoint.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/logs-handler.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/conversations-handler.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/webhook-enrichment.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/interaction-log-endpoint.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/analytics-handler.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/operational-readiness.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/insights-handler.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/whatsapp-handler.php';
require_once ESTANDAR_CHATBOT_PATH . 'includes/project-status-widget.php';

// Este archivo debería ser el que agrega/inyecta tabs (ej: Licencia)
require_once ESTANDAR_CHATBOT_PATH . 'includes/admin-tabs.php';

add_action( 'init', 'estandar_chatbot_maybe_upgrade_storage' );
function estandar_chatbot_maybe_upgrade_storage() {
	$current = (string) get_option( 'estandar_chatbot_db_version', '' );
	if ( $current === ESTANDAR_CHATBOT_DB_VERSION ) {
		return;
	}

	if ( function_exists( 'estandar_chatbot_ensure_leads_table' ) ) {
		estandar_chatbot_ensure_leads_table();
	}
	if ( function_exists( 'estandar_chatbot_ensure_logs_table' ) ) {
		estandar_chatbot_ensure_logs_table();
	}
	if ( function_exists( 'estandar_chatbot_ensure_conversations_table' ) ) {
		estandar_chatbot_ensure_conversations_table();
	}
	if ( function_exists( 'estandar_chatbot_ensure_events_table' ) ) {
		estandar_chatbot_ensure_events_table();
	}
	if ( function_exists( 'estandar_chatbot_maybe_migrate_csv_leads_to_db' ) ) {
		estandar_chatbot_maybe_migrate_csv_leads_to_db();
	}
	if ( function_exists( 'estandar_chatbot_maybe_migrate_csv_logs_to_db' ) ) {
		estandar_chatbot_maybe_migrate_csv_logs_to_db();
	}
	estandar_chatbot_maybe_migrate_behavior_copy_to_generic_defaults();
	update_option( 'estandar_chatbot_db_version', ESTANDAR_CHATBOT_DB_VERSION, false );
}

add_action( 'init', 'estandar_chatbot_maybe_bootstrap_inline_experience', 20 );
function estandar_chatbot_maybe_bootstrap_inline_experience() {
	if ( get_option( 'estandar_chatbot_inline_bootstrap_done', 0 ) ) {
		return;
	}

	estandar_chatbot_bootstrap_defaults();
	estandar_chatbot_ensure_inline_page();
	update_option( 'estandar_chatbot_inline_bootstrap_done', 1, false );
}

add_action( 'admin_post_estandar_chatbot_tools_purge_logs', 'estandar_chatbot_tools_purge_logs_action' );
add_action( 'admin_post_estandar_chatbot_tools_retry_migration', 'estandar_chatbot_tools_retry_migration_action' );
add_action( 'admin_post_estandar_chatbot_tools_test_email', 'estandar_chatbot_tools_test_email_action' );
add_action( 'admin_post_estandar_chatbot_tools_export_data', 'estandar_chatbot_tools_export_data_action' );
add_action( 'admin_post_estandar_chatbot_apply_profile', 'estandar_chatbot_apply_profile_action' );
add_action( 'admin_post_estandar_chatbot_export_leads_csv', 'estandar_chatbot_export_leads_csv_action' );
add_action( 'admin_post_estandar_chatbot_export_logs_csv', 'estandar_chatbot_export_logs_csv_action' );
add_action( 'admin_post_estandar_chatbot_tools_wipe_data', 'estandar_chatbot_tools_wipe_data_action' );
add_action( 'admin_post_estandar_chatbot_lead_delete', 'estandar_chatbot_lead_delete_action' );
add_action( 'admin_post_estandar_chatbot_leads_bulk_delete', 'estandar_chatbot_leads_bulk_delete_action' );
add_action( 'admin_post_estandar_chatbot_lead_update', 'estandar_chatbot_lead_update_action' );
add_action( 'admin_post_estandar_chatbot_lead_assign_responsable', 'estandar_chatbot_lead_assign_responsable_action' );
add_action( 'admin_post_estandar_chatbot_reprocess_lead_intents', 'estandar_chatbot_reprocess_lead_intents_action' );
add_action( 'admin_post_estandar_chatbot_log_delete', 'estandar_chatbot_log_delete_action' );
add_action( 'admin_post_estandar_chatbot_logs_bulk_delete', 'estandar_chatbot_logs_bulk_delete_action' );
add_action( 'admin_post_estandar_chatbot_log_update', 'estandar_chatbot_log_update_action' );
add_action( 'admin_post_estandar_chatbot_conversation_update', 'estandar_chatbot_conversation_update_action' );
add_action( 'admin_post_estandar_chatbot_conversations_bulk_update', 'estandar_chatbot_conversations_bulk_update_action' );
add_action( 'admin_post_estandar_chatbot_conversation_delete', 'estandar_chatbot_conversation_delete_action' );
add_action( 'admin_post_estandar_chatbot_conversations_bulk_delete', 'estandar_chatbot_conversations_bulk_delete_action' );
add_action( 'admin_post_estandar_chatbot_conversations_wipe', 'estandar_chatbot_conversations_wipe_action' );
add_action( 'admin_post_estandar_chatbot_conversations_export', 'estandar_chatbot_conversations_export_action' );
add_action( 'admin_post_estandar_chatbot_export_conversation_effectiveness', 'estandar_chatbot_export_conversation_effectiveness_action' );
add_action( 'admin_post_estandar_chatbot_save_contexts', 'estandar_chatbot_save_contexts_action' );

/* ======================================================
 * CONTEXTS V1.1 — helpers
 * ====================================================== */

function estandar_chatbot_contexts_get_all(): array {
	$raw = get_option( 'chatbot_contexts_v1', [] );
	if ( ! is_array( $raw ) ) {
		$raw = [];
	}
	return $raw;
}

function estandar_chatbot_contexts_get_prompt( string $slug ): string {
	if ( $slug === '' || $slug === 'global' ) {
		return '';
	}
	$all = estandar_chatbot_contexts_get_all();
	return isset( $all[ $slug ] ) ? (string) ( $all[ $slug ]['prompt'] ?? '' ) : '';
}

function estandar_chatbot_contexts_get_name( string $slug ): string {
	if ( $slug === '' || $slug === 'global' ) {
		return '';
	}
	$all = estandar_chatbot_contexts_get_all();
	return isset( $all[ $slug ] ) ? (string) ( $all[ $slug ]['name'] ?? $slug ) : '';
}

function estandar_chatbot_contexts_validate_slug( string $raw ): string {
	$slug = sanitize_key( $raw );
	if ( $slug === '' || $slug === 'global' ) {
		return '';
	}
	$all = estandar_chatbot_contexts_get_all();
	return isset( $all[ $slug ] ) ? $slug : '';
}

function estandar_chatbot_save_contexts_action(): void {
	estandar_chatbot_require_permission( 'manage_settings' );
	check_admin_referer( 'estandar_chatbot_save_contexts_nonce' );

	$contexts = [];
	for ( $i = 1; $i <= 4; $i++ ) {
		$slug  = sanitize_key( (string) ( $_POST[ 'ctx_slug_' . $i ] ?? '' ) );
		$name  = sanitize_text_field( (string) wp_unslash( $_POST[ 'ctx_name_' . $i ] ?? '' ) );
		$prompt = sanitize_textarea_field( (string) wp_unslash( $_POST[ 'ctx_prompt_' . $i ] ?? '' ) );

		if ( $slug !== '' && $slug !== 'global' && preg_match( '/^[a-z0-9-]{1,40}$/', $slug ) ) {
			$contexts[ $slug ] = [
				'name'   => $name ?: $slug,
				'prompt' => $prompt,
			];
		}
	}

	update_option( 'chatbot_contexts_v1', $contexts, false );

	wp_redirect( add_query_arg(
		[ 'page' => 'estandar-chatbot', 'tab' => 'configuracion', 'ecb_contexts_saved' => '1', '_section' => 'contextos' ],
		admin_url( 'admin.php' )
	) );
	exit;
}

function estandar_chatbot_tools_redirect( $status, $message ) {
	$url = add_query_arg(
		[
			'page'       => 'estandar-chatbot',
			'tab'        => 'herramientas',
			'ecb_status' => sanitize_key( (string) $status ),
			'ecb_msg'    => (string) $message,
		],
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $url );
	exit;
}

function estandar_chatbot_admin_tab_redirect( $tab, $status, $message ) {
	$url = add_query_arg(
		[
			'page'       => 'estandar-chatbot',
			'tab'        => sanitize_key( (string) $tab ),
			'ecb_status' => sanitize_key( (string) $status ),
			'ecb_msg'    => (string) $message,
		],
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $url );
	exit;
}

function estandar_chatbot_admin_tab_redirect_url( $tab, $status, $message, $extra_args = [] ) {
	$args = array_merge(
		[
			'page'       => 'estandar-chatbot',
			'tab'        => sanitize_key( (string) $tab ),
			'ecb_status' => sanitize_key( (string) $status ),
			'ecb_msg'    => (string) $message,
		],
		(array) $extra_args
	);
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

function estandar_chatbot_clean_export_buffers() {
	while ( ob_get_level() > 0 ) {
		$level = ob_get_level();
		if ( ! @ob_end_clean() || ob_get_level() >= $level ) {
			break;
		}
	}
}

function estandar_chatbot_log_csv_export_event( $source, $message ) {
	if ( ! function_exists( 'estandar_chatbot_log_event' ) ) {
		return;
	}

	$source = sanitize_key( (string) $source );
	if ( ! in_array( $source, [ 'leads', 'logs' ], true ) ) {
		return;
	}

	estandar_chatbot_log_event(
		[
			'event_name' => 'csv.export',
			'channel'    => 'web',
			'meta'       => [
				'event_type' => 'csv.export',
				'source'     => $source,
				'status'     => 'success',
				'user_id'    => (int) get_current_user_id(),
				'timestamp'  => current_time( 'mysql' ),
				'message'    => sanitize_text_field( (string) $message ),
			],
		]
	);
}

function estandar_chatbot_export_leads_csv_action() {
	if ( ! estandar_chatbot_user_can_view_tab( 'leads' ) ) {
		wp_die( 'No autorizado.' );
	}
	check_admin_referer( 'ecb_download_leads' );

	if ( function_exists( 'estandar_chatbot_output_leads_csv' ) ) {
		estandar_chatbot_log_csv_export_event( 'leads', 'Leads CSV export requested' );
		estandar_chatbot_clean_export_buffers();
		estandar_chatbot_output_leads_csv( 'estandar-chatbot-leads.csv' );
	}

	wp_die( 'Exportador de leads no disponible.' );
}

function estandar_chatbot_export_logs_csv_action() {
	if ( ! estandar_chatbot_user_can_view_tab( 'logs' ) ) {
		wp_die( 'No autorizado.' );
	}
	check_admin_referer( 'ecb_download_logs' );

	if ( function_exists( 'estandar_chatbot_output_logs_csv' ) ) {
		estandar_chatbot_log_csv_export_event( 'logs', 'Logs CSV export requested' );
		estandar_chatbot_clean_export_buffers();
		estandar_chatbot_output_logs_csv( 'estandar-chatbot-logs.csv' );
	}

	wp_die( 'Exportador de logs no disponible.' );
}

function estandar_chatbot_apply_profile_action() {
	if ( ! estandar_chatbot_user_can_view_tab( 'configuracion' ) ) {
		wp_die( esc_html__( 'No autorizado.', 'estandar-chatbot' ) );
	}

	check_admin_referer( 'estandar_chatbot_apply_profile' );

	$profile   = isset( $_POST['chatbot_profile'] ) ? sanitize_key( wp_unslash( $_POST['chatbot_profile'] ) ) : '';
	$overwrite = ! empty( $_POST['chatbot_profile_overwrite'] );
	$confirmed = ! empty( $_POST['chatbot_profile_confirm_apply'] );
	$previewed = ! empty( $_POST['chatbot_profile_previewed'] );
	$registry  = function_exists( 'estandar_chatbot_get_profile_registry' ) ? estandar_chatbot_get_profile_registry() : [];

	if ( $profile !== '' && ! isset( $registry[ $profile ] ) ) {
		$profile = '';
	}

	if ( ! $confirmed || ! $previewed ) {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'              => 'estandar-chatbot',
					'tab'               => 'configuracion',
					'ecb_profile_error' => $previewed ? 'confirm_required' : 'preview_required',
					'ecb_profile_preview' => $profile,
					'ecb_profile_overwrite' => $overwrite ? 1 : 0,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$result = function_exists( 'estandar_chatbot_apply_profile_defaults' )
		? estandar_chatbot_apply_profile_defaults( $profile, $overwrite )
		: [ 'applied' => [], 'skipped' => [] ];

	// Guardar nombre del bot si fue enviado desde el formulario de aplicación.
	$nombre_from_profile = isset( $_POST['chatbot_nombre_from_profile'] )
		? sanitize_text_field( wp_unslash( $_POST['chatbot_nombre_from_profile'] ) )
		: '';
	if ( $nombre_from_profile !== '' ) {
		update_option( 'chatbot_nombre', $nombre_from_profile, false );
		$result['applied'][] = 'chatbot_nombre';
	}

	$redirect = add_query_arg(
		[
			'page'                  => 'estandar-chatbot',
			'tab'                   => 'configuracion',
			'ecb_profile_applied'   => count( (array) ( $result['applied'] ?? [] ) ),
			'ecb_profile_skipped'   => count( (array) ( $result['skipped'] ?? [] ) ),
			'ecb_profile_overwrite' => $overwrite ? 1 : 0,
		],
		admin_url( 'admin.php' )
	);

	wp_safe_redirect( $redirect );
	exit;
}

function estandar_chatbot_tools_purge_logs_action() {
	estandar_chatbot_require_permission( 'manage_settings' );
	check_admin_referer( 'estandar_chatbot_tools_purge_logs' );

	if ( ! function_exists( 'estandar_chatbot_purge_old_logs' ) ) {
		estandar_chatbot_tools_redirect( 'error', 'Función de purga no disponible.' );
	}

	$deleted = (int) estandar_chatbot_purge_old_logs();
	estandar_chatbot_tools_redirect( 'ok', 'Purga ejecutada. Registros eliminados: ' . $deleted );
}

function estandar_chatbot_tools_retry_migration_action() {
	estandar_chatbot_require_permission( 'manage_settings' );
	check_admin_referer( 'estandar_chatbot_tools_retry_migration' );

	if ( defined( 'ESTANDAR_CHATBOT_LEADS_MIGRATION_OPTION' ) ) {
		delete_option( ESTANDAR_CHATBOT_LEADS_MIGRATION_OPTION );
	}
	if ( defined( 'ESTANDAR_CHATBOT_LOGS_MIGRATION_OPTION' ) ) {
		delete_option( ESTANDAR_CHATBOT_LOGS_MIGRATION_OPTION );
	}

	estandar_chatbot_maybe_upgrade_storage();

	estandar_chatbot_tools_redirect( 'ok', 'Migración reintentada correctamente.' );
}

function estandar_chatbot_tools_test_email_action() {
	estandar_chatbot_require_permission( 'manage_settings' );
	check_admin_referer( 'estandar_chatbot_tools_test_email' );

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
		estandar_chatbot_tools_redirect( 'error', 'No hay destinatarios válidos para el email de prueba.' );
	}

	$subject = '[Estandar Chatbot] Prueba de notificación';
	$body = "Prueba de envío correcta.\n\nSitio: " . home_url( '/' ) . "\nFecha: " . current_time( 'mysql' );
	$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
	estandar_chatbot_add_mail_sender_filters();
	$ok = wp_mail( $recipients, $subject, $body, $headers );
	estandar_chatbot_remove_mail_sender_filters();

	if ( function_exists( 'estandar_chatbot_log_event' ) ) {
		estandar_chatbot_log_event(
			[
				'event_name' => 'email.delivery_validation',
				'channel'    => 'admin',
				'meta'       => [
					'delivery_channel' => 'email',
					'status'           => $ok ? 'success' : 'failed',
					'message'          => $ok ? 'test_email_sent' : 'wp_mail_false',
					'source'           => 'tools_test_email',
					'recipients_count' => count( $recipients ),
					'actor_user_id'    => get_current_user_id(),
				],
			]
		);
	}

	if ( ! $ok ) {
		estandar_chatbot_tools_redirect( 'error', 'No se pudo enviar el email de prueba (wp_mail devolvió false).' );
	}

	estandar_chatbot_tools_redirect( 'ok', 'Email de prueba enviado a: ' . implode( ', ', $recipients ) );
}

function estandar_chatbot_tools_export_data_action() {
	estandar_chatbot_require_permission( 'manage_settings' );
	check_admin_referer( 'estandar_chatbot_tools_export_data' );

	if ( ! class_exists( 'ZipArchive' ) ) {
		estandar_chatbot_tools_redirect( 'error', 'ZipArchive no disponible en este servidor.' );
	}

	$zip = new ZipArchive();
	$tmp = wp_tempnam( 'ecb_export' );
	if ( $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
		estandar_chatbot_tools_redirect( 'error', 'No se pudo crear el archivo ZIP.' );
	}

	if ( function_exists( 'estandar_chatbot_get_leads_csv_content' ) ) {
		$zip->addFromString( 'leads.csv', estandar_chatbot_get_leads_csv_content() );
	}
	if ( function_exists( 'estandar_chatbot_get_logs_csv_content' ) ) {
		$zip->addFromString( 'logs.csv', estandar_chatbot_get_logs_csv_content() );
	}

	$zip->close();

	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="estandar-chatbot-export-' . gmdate( 'Y-m-d-His' ) . '.zip"' );
	readfile( $tmp );
	@unlink( $tmp );
	exit;
}

/**
 * CONVERSATION_EFFECTIVENESS_EXPORT_V1 — botón de Herramientas → Operación segura.
 *
 * Misma lógica que tools/estandar-conversation-effectiveness-export.php (CLI,
 * aprobado para CONVERSATION_EFFECTIVENESS_AUDIT_V1), vía
 * estandar_chatbot_build_conversation_effectiveness_export() (conversations-handler.php).
 *
 * Streaming download, sin persistencia en servidor: el JSON se genera en memoria
 * y se envía directo como adjunto (CONVERSATION_RETENTION_POLICY_V1 — transcripts
 * con PII no deben quedar en wp-content/uploads).
 */
function estandar_chatbot_export_conversation_effectiveness_action() {
	estandar_chatbot_require_permission( 'manage_settings' );
	check_admin_referer( 'estandar_chatbot_export_conversation_effectiveness' );

	if ( ! function_exists( 'estandar_chatbot_build_conversation_effectiveness_export' ) ) {
		estandar_chatbot_tools_redirect( 'error', 'Función de exportación no disponible.' );
	}

	$result = estandar_chatbot_build_conversation_effectiveness_export( 100, 2 );

	if ( ! $result['ok'] ) {
		estandar_chatbot_tools_redirect( 'error', $result['message'] );
	}

	$json = wp_json_encode( $result['export'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="conversation-effectiveness-export-' . gmdate( 'Ymd-His' ) . '.json"' );
	echo $json;
	exit;
}

function estandar_chatbot_tools_wipe_data_action() {
	estandar_chatbot_require_permission( 'manage_settings' );
	check_admin_referer( 'estandar_chatbot_tools_wipe_data' );

	if ( empty( $_POST['ecb_wipe_confirm'] ) || $_POST['ecb_wipe_confirm'] !== '1' ) {
		estandar_chatbot_tools_redirect( 'error', 'Debes confirmar la acción marcando la casilla.' );
	}

	global $wpdb;
	$tables = [
		$wpdb->prefix . 'ecb_leads',
		$wpdb->prefix . 'ecb_logs',
		$wpdb->prefix . 'ecb_conversations',
		$wpdb->prefix . 'ecb_events',
	];

	$deleted = 0;
	foreach ( $tables as $table ) {
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists === $table ) {
			$n = $wpdb->query( "TRUNCATE TABLE `{$table}`" );
			if ( $n !== false ) {
				$deleted++;
			}
		}
	}

	if ( function_exists( 'estandar_chatbot_logs_cache_bust' ) ) {
		estandar_chatbot_logs_cache_bust();
	}
	if ( function_exists( 'estandar_chatbot_leads_cache_bust' ) ) {
		estandar_chatbot_leads_cache_bust();
	}
	if ( function_exists( 'estandar_chatbot_conversations_cache_bust' ) ) {
		estandar_chatbot_conversations_cache_bust();
	}
	if ( function_exists( 'estandar_chatbot_events_cache_bust' ) ) {
		estandar_chatbot_events_cache_bust();
	}

	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ecb_%' OR option_name LIKE '_transient_timeout_ecb_%'" );

	estandar_chatbot_tools_redirect( 'ok', 'Datos eliminados correctamente. Tablas vaciadas: ' . $deleted );
}

function estandar_chatbot_lead_delete_action() {
	estandar_chatbot_require_permission( 'manage_leads' );
	check_admin_referer( 'estandar_chatbot_lead_delete' );
	$id = isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0;
	if ( ! function_exists( 'estandar_chatbot_delete_lead_by_id' ) ) {
		estandar_chatbot_admin_tab_redirect( 'leads', 'error', 'Handler de borrado de leads no disponible.' );
	}
	$deleted = (int) estandar_chatbot_delete_lead_by_id( $id );
	estandar_chatbot_admin_tab_redirect( 'leads', $deleted > 0 ? 'ok' : 'error', $deleted > 0 ? 'Lead eliminado.' : 'No se pudo eliminar el lead.' );
}

function estandar_chatbot_leads_bulk_delete_action() {
	estandar_chatbot_require_permission( 'manage_leads' );
	check_admin_referer( 'estandar_chatbot_leads_bulk_delete' );
	$ids = isset( $_POST['lead_ids'] ) && is_array( $_POST['lead_ids'] ) ? array_map( 'intval', $_POST['lead_ids'] ) : [];
	if ( ! function_exists( 'estandar_chatbot_delete_leads_by_ids' ) ) {
		estandar_chatbot_admin_tab_redirect( 'leads', 'error', 'Handler de borrado masivo no disponible.' );
	}
	$deleted = (int) estandar_chatbot_delete_leads_by_ids( $ids );
	estandar_chatbot_admin_tab_redirect( 'leads', $deleted > 0 ? 'ok' : 'error', 'Leads eliminados: ' . $deleted );
}

function estandar_chatbot_lead_update_action() {
	estandar_chatbot_require_permission( 'manage_leads' );
	check_admin_referer( 'estandar_chatbot_lead_update' );
	$id = isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0;
	$fields = [
		'name'       => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
		'email'      => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
		'phone'      => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
		'intent'     => isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : '',
		'project_slug' => isset( $_POST['project_slug'] ) ? estandar_chatbot_normalize_project_slug( wp_unslash( $_POST['project_slug'] ) ) : '',
		'project_area' => isset( $_POST['project_area'] ) ? sanitize_text_field( wp_unslash( $_POST['project_area'] ) ) : '',
		'requirement'=> isset( $_POST['requirement'] ) ? sanitize_textarea_field( wp_unslash( $_POST['requirement'] ) ) : '',
		'lead_status'=> isset( $_POST['lead_status'] ) ? sanitize_key( wp_unslash( $_POST['lead_status'] ) ) : '',
		'lead_tier'  => isset( $_POST['lead_tier'] ) ? sanitize_key( wp_unslash( $_POST['lead_tier'] ) ) : '',
		'lead_score' => isset( $_POST['lead_score'] ) ? intval( $_POST['lead_score'] ) : 0,
		'lead_route' => isset( $_POST['lead_route'] ) ? sanitize_text_field( wp_unslash( $_POST['lead_route'] ) ) : '',
		'assigned_to'=> isset( $_POST['assigned_to'] ) ? sanitize_text_field( wp_unslash( $_POST['assigned_to'] ) ) : '',
		'notes'      => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
		'source_page_url' => isset( $_POST['source_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_page_url'] ) ) : '',
	];
	if ( ! function_exists( 'estandar_chatbot_update_lead_by_id' ) ) {
		estandar_chatbot_admin_tab_redirect( 'leads', 'error', 'Handler de edición de leads no disponible.' );
	}
	$res = estandar_chatbot_update_lead_by_id( $id, $fields );
	$ok = is_array( $res ) && ! empty( $res['ok'] );
	$msg = is_array( $res ) ? (string) ( $res['message'] ?? '' ) : 'No se pudo actualizar.';
	estandar_chatbot_admin_tab_redirect( 'leads', $ok ? 'ok' : 'error', $msg );
}

function estandar_chatbot_get_responsables() {
	$responsables = get_option( 'chatbot_responsables', [] );
	return is_array( $responsables ) ? array_values( $responsables ) : [];
}

	function estandar_chatbot_normalize_project_slug( $value ) {
		return trim( sanitize_title( remove_accents( (string) $value ) ), "- \t\n\r\0\x0B" );
	}

	function estandar_chatbot_derive_sid_prefix( $project_id ) {
		$slug_upper = strtoupper( preg_replace( '/[^A-Z]/i', '', estandar_chatbot_normalize_project_slug( $project_id ) ) );
		return substr( $slug_upper . 'XX', 0, 2 );
	}

	function estandar_chatbot_adapt_core_project( array $project ) {
		$slug = estandar_chatbot_normalize_project_slug( $project['project_id'] ?? $project['project_slug'] ?? '' );
		if ( $slug === '' ) {
			return null;
		}

		return [
			'project_slug' => $slug,
			'name'         => sanitize_text_field( (string) ( $project['label'] ?? $project['name'] ?? ucwords( str_replace( '-', ' ', $slug ) ) ) ),
			'city'         => sanitize_text_field( (string) ( $project['city'] ?? '' ) ),
			'sid_prefix'   => estandar_chatbot_derive_sid_prefix( $slug ),
			'active'       => array_key_exists( 'active', $project ) ? (bool) $project['active'] : true,
			'url_patterns' => array_values( array_filter( (array) ( $project['url_patterns'] ?? [] ) ) ),
		];
	}

	function estandar_chatbot_sanitize_project_registry( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}
	$seen_prefixes = [];
	$out           = [];
	foreach ( $value as $item ) {
		$slug = estandar_chatbot_normalize_project_slug( $item['project_slug'] ?? $item['slug'] ?? '' );
		$name = sanitize_text_field( (string) ( $item['name'] ?? $item['label'] ?? $item['area'] ?? '' ) );
		$city = sanitize_text_field( (string) ( $item['city'] ?? '' ) );
		if ( $slug === '' ) {
			$slug = estandar_chatbot_normalize_project_slug( $name );
		}
		if ( $slug === '' ) {
			continue;
		}

		// url_patterns: stored as array; also accepts comma-separated string.
		// Full URLs (https://...) are reduced to path-only before storage.
		$raw_patterns = $item['url_patterns'] ?? [];
		if ( is_string( $raw_patterns ) ) {
			$raw_patterns = array_filter( array_map( 'trim', explode( ',', $raw_patterns ) ) );
		}
		$url_patterns = [];
		foreach ( (array) $raw_patterns as $pattern ) {
			$pattern = sanitize_text_field( (string) $pattern );
			if ( $pattern === '' ) {
				continue;
			}
			// Strip protocol and host if a full URL is provided.
			if ( preg_match( '#^https?://#i', $pattern ) ) {
				$parsed  = wp_parse_url( $pattern );
				$pattern = ( $parsed['path'] ?? '/' );
			}
			$pattern = '/' . ltrim( $pattern, '/' );
			// Skip degenerate patterns that would match everything.
			if ( $pattern === '/' ) {
				continue;
			}
			$url_patterns[] = rtrim( $pattern, '/' );
		}

		// active: absent key defaults to true (backward-compat with old entries).
		$active = array_key_exists( 'active', $item ) ? (bool) $item['active'] : true;

		// sid_prefix: 2 uppercase letters, unique within this registry save.
		$raw_prefix = strtoupper( preg_replace( '/[^A-Z]/i', '', (string) ( $item['sid_prefix'] ?? '' ) ) );
		$sid_prefix = substr( $raw_prefix, 0, 2 );
		if ( strlen( $sid_prefix ) < 2 ) {
			// Auto-derive from slug when not provided or too short.
			$slug_upper = strtoupper( preg_replace( '/[^A-Z]/i', '', $slug ) );
			$sid_prefix = substr( $slug_upper . 'XX', 0, 2 );
		}
		// Enforce uniqueness: if another slug already claimed this prefix, append a letter.
		if ( isset( $seen_prefixes[ $sid_prefix ] ) && $seen_prefixes[ $sid_prefix ] !== $slug ) {
			$base    = substr( $sid_prefix, 0, 1 );
			$counter = ord( 'A' );
			while ( isset( $seen_prefixes[ $base . chr( $counter ) ] ) && $counter <= ord( 'Z' ) ) {
				$counter++;
			}
			$sid_prefix = $base . chr( $counter );
		}
		$seen_prefixes[ $sid_prefix ] = $slug;

		$out[ $slug ] = [
			'project_slug' => $slug,
			'name'         => $name !== '' ? $name : ucwords( str_replace( '-', ' ', $slug ) ),
			'city'         => $city,
			'sid_prefix'   => $sid_prefix,
			'active'       => $active,
			'url_patterns' => $url_patterns,
		];
	}
	return array_values( $out );
}

	function estandar_chatbot_sanitize_legacy_project_registry( $value ) {
		return estandar_chatbot_sanitize_project_registry( get_option( 'chatbot_project_registry', [] ) );
	}

		function estandar_chatbot_build_project_registry() {
			$registry = [];

		if ( ! class_exists( 'Estandar_Core_Project_Registry' ) ) {
			return [];
		}

		$client_id    = function_exists( 'estandar_core_get_client_slug' ) ? (string) estandar_core_get_client_slug() : '';
		$all_projects = $client_id !== ''
			? Estandar_Core_Project_Registry::get_all_for_client( $client_id )
			: Estandar_Core_Project_Registry::get_all();

		foreach ( (array) $all_projects as $project ) {
			$adapted = estandar_chatbot_adapt_core_project( (array) $project );
			if ( ! is_array( $adapted ) ) {
				continue;
			}

			$registry[ $adapted['project_slug'] ] = $adapted;
		}

		return array_values( $registry );
	}

	function estandar_chatbot_get_project_registry() {
		return estandar_chatbot_build_project_registry();
	}

function estandar_chatbot_get_project_by_slug( $slug ) {
	$slug = estandar_chatbot_normalize_project_slug( $slug );
	if ( $slug === '' ) {
		return null;
	}
	foreach ( estandar_chatbot_get_project_registry() as $project ) {
		if ( estandar_chatbot_normalize_project_slug( $project['project_slug'] ?? '' ) === $slug ) {
			return $project;
		}
	}
	return null;
}

/**
 * Backfill chatbot_project_registry: ensure every stored entry has sid_prefix and url_patterns.
 * Runs on admin_init priority 4 — before migrate_project_registry_values (priority 5).
 * Idempotent: only writes if something changed. Never overwrites non-empty user values.
 */
	add_action( 'admin_init', 'estandar_chatbot_backfill_project_registry', 4 );
	function estandar_chatbot_backfill_project_registry() {
		return;
	}

	add_action( 'admin_init', 'estandar_chatbot_migrate_project_registry_values', 5 );
	function estandar_chatbot_migrate_project_registry_values() {
		return;
	}

function estandar_chatbot_get_responsable_by_index( $index ) {
	$responsables = estandar_chatbot_get_responsables();
	return isset( $responsables[ $index ] ) && is_array( $responsables[ $index ] ) ? $responsables[ $index ] : null;
}

function estandar_chatbot_build_responsable_lead_message( array $lead ) {
	$lineas = [
		'Nuevo lead desde chatbot:',
		'',
		'Nombre: ' . (string) ( $lead['name'] ?? 'Sin nombre' ),
		'Teléfono: ' . (string) ( $lead['phone'] ?? 'Sin teléfono' ),
		'Email: ' . (string) ( $lead['email'] ?? 'Sin email' ),
		'Intención: ' . (string) ( $lead['intent'] ?? '-' ),
		'Área / Proyecto: ' . (string) ( $lead['project_area'] ?? '-' ),
		'Fecha: ' . (string) ( $lead['created_at'] ?? current_time( 'mysql' ) ),
		'',
		'Link origen: ' . (string) ( $lead['source_page_url'] ?? '-' ),
	];
	return implode( "\n", $lineas );
}

function estandar_chatbot_send_lead_to_responsable_email( array $lead, array $responsable ) {
	$email = sanitize_email( (string) ( $responsable['email'] ?? '' ) );
	if ( ! is_email( $email ) ) {
		return [ 'ok' => false, 'message' => 'El responsable no tiene email válido.' ];
	}

	$template = function_exists( 'estandar_chatbot_get_default_lead_email_template' )
		? estandar_chatbot_get_default_lead_email_template()
		: '<p><strong>Nuevo lead desde chatbot</strong></p><p>{{nombre}}</p>';

	$body = estandar_chatbot_render_lead_email_template(
		$template,
		[
			'nombre'   => (string) ( $lead['name'] ?? 'Sin nombre' ),
			'email'    => (string) ( $lead['email'] ?? 'Sin email' ),
			'telefono' => (string) ( $lead['phone'] ?? 'Sin teléfono' ),
			'mensaje'  => (string) ( $lead['requirement'] ?? 'Sin mensaje adicional' ),
			'intent'   => (string) ( $lead['intent'] ?? '-' ),
			'origen'   => (string) ( $lead['source_page_url'] ?? '-' ),
		]
	);

	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
	$subject = 'Lead asignado desde chatbot';
	estandar_chatbot_add_mail_sender_filters();
	$sent = wp_mail( [ $email ], $subject, wp_kses( $body, estandar_chatbot_get_allowed_email_template_tags() ), $headers );
	estandar_chatbot_remove_mail_sender_filters();

	return [
		'ok'      => (bool) $sent,
		'message' => $sent ? 'Lead enviado por email al responsable.' : 'No se pudo enviar el email al responsable.',
	];
}

function estandar_chatbot_lead_assign_responsable_action() {
	estandar_chatbot_require_permission( 'manage_leads' );
	check_admin_referer( 'estandar_chatbot_lead_assign_responsable' );

	$lead_id = isset( $_POST['lead_id'] ) ? (int) $_POST['lead_id'] : 0;
	$responsable_index = isset( $_POST['responsable_index'] ) ? (int) $_POST['responsable_index'] : -1;
	$redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';

	if ( ! function_exists( 'estandar_chatbot_get_lead_by_id' ) || ! function_exists( 'estandar_chatbot_update_lead_by_id' ) ) {
		estandar_chatbot_admin_tab_redirect( 'leads', 'error', 'Handler de asignación no disponible.' );
	}

	$lead = estandar_chatbot_get_lead_by_id( $lead_id );
	if ( ! is_array( $lead ) ) {
		estandar_chatbot_admin_tab_redirect( 'leads', 'error', 'Lead no encontrado.' );
	}

	$responsable = estandar_chatbot_get_responsable_by_index( $responsable_index );
	if ( ! is_array( $responsable ) ) {
		estandar_chatbot_admin_tab_redirect( 'leads', 'error', 'Responsable no válido.' );
	}

	$nombre_responsable = sanitize_text_field( (string) ( $responsable['nombre'] ?? '' ) );
	$area_responsable = sanitize_text_field( (string) ( $responsable['area'] ?? '' ) );
	$assigned_to = trim( $nombre_responsable . ( $area_responsable !== '' ? ' (' . $area_responsable . ')' : '' ) );
	estandar_chatbot_update_lead_by_id( $lead_id, [ 'assigned_to' => $assigned_to ] );

	$telefono = preg_replace( '/\D+/', '', (string) ( $responsable['telefono'] ?? '' ) );
	$email = sanitize_email( (string) ( $responsable['email'] ?? '' ) );
	$message = estandar_chatbot_build_responsable_lead_message( $lead );

	$base_redirect = $redirect_to !== '' ? $redirect_to : admin_url( 'admin.php?page=estandar-chatbot&tab=leads' );
	$extra_args = [];
	$status = 'ok';
	$feedback = 'Responsable asignado correctamente.';

	if ( $telefono !== '' ) {
		$extra_args['ecb_open_whatsapp'] = rawurlencode( 'https://wa.me/' . $telefono . '?text=' . rawurlencode( $message ) );
		$feedback = 'Listo, abrimos WhatsApp con el lead.';
	} elseif ( $email !== '' ) {
		$sent = estandar_chatbot_send_lead_to_responsable_email( $lead, $responsable );
		$status = ! empty( $sent['ok'] ) ? 'ok' : 'error';
		$feedback = (string) ( $sent['message'] ?? 'No se pudo enviar al responsable.' );
	} else {
		$status = 'error';
		$feedback = 'El responsable no tiene email ni teléfono configurado.';
	}

	$url = add_query_arg(
		array_merge(
			[
				'ecb_status' => $status,
				'ecb_msg'    => $feedback,
			],
			$extra_args
		),
		$base_redirect
	);

	wp_safe_redirect( $url );
	exit;
}

function estandar_chatbot_reprocess_lead_intents_action() {
	estandar_chatbot_require_permission( 'manage_leads' );
	check_admin_referer( 'estandar_chatbot_reprocess_lead_intents' );

	if ( ! function_exists( 'estandar_chatbot_reprocess_lead_source_intents' ) ) {
		estandar_chatbot_admin_tab_redirect( 'leads', 'error', 'Reproceso de intenciones no disponible.' );
	}

	$result = estandar_chatbot_reprocess_lead_source_intents();
	$updated = (int) ( $result['updated'] ?? 0 );
	$matched = (int) ( $result['matched'] ?? 0 );
	$message = $updated > 0
		? 'Se actualizaron ' . $updated . ' leads usando la URL de origen.'
		: ( $matched > 0 ? 'Se analizaron ' . $matched . ' leads, pero no hubo cambios necesarios.' : 'No se encontraron leads con coincidencias por URL.' );

	estandar_chatbot_admin_tab_redirect( 'leads', 'ok', $message );
}

function estandar_chatbot_log_delete_action() {
	estandar_chatbot_require_permission( 'manage_logs' );
	check_admin_referer( 'estandar_chatbot_log_delete' );
	$id = isset( $_POST['log_id'] ) ? (int) $_POST['log_id'] : 0;
	if ( ! function_exists( 'estandar_chatbot_delete_log_by_id' ) ) {
		estandar_chatbot_admin_tab_redirect( 'logs', 'error', 'Handler de borrado de logs no disponible.' );
	}
	$deleted = (int) estandar_chatbot_delete_log_by_id( $id );
	estandar_chatbot_admin_tab_redirect( 'logs', $deleted > 0 ? 'ok' : 'error', $deleted > 0 ? 'Log eliminado.' : 'No se pudo eliminar el log.' );
}

function estandar_chatbot_logs_bulk_delete_action() {
	estandar_chatbot_require_permission( 'manage_logs' );
	check_admin_referer( 'estandar_chatbot_logs_bulk_delete' );
	$ids = isset( $_POST['log_ids'] ) && is_array( $_POST['log_ids'] ) ? array_map( 'intval', $_POST['log_ids'] ) : [];
	if ( ! function_exists( 'estandar_chatbot_delete_logs_by_ids' ) ) {
		estandar_chatbot_admin_tab_redirect( 'logs', 'error', 'Handler de borrado masivo de logs no disponible.' );
	}
	$deleted = (int) estandar_chatbot_delete_logs_by_ids( $ids );
	estandar_chatbot_admin_tab_redirect( 'logs', $deleted > 0 ? 'ok' : 'error', 'Logs eliminados: ' . $deleted );
}

function estandar_chatbot_log_update_action() {
	estandar_chatbot_require_permission( 'manage_logs' );
	check_admin_referer( 'estandar_chatbot_log_update' );
	$id = isset( $_POST['log_id'] ) ? (int) $_POST['log_id'] : 0;
	$fields = [
		'question' => isset( $_POST['question'] ) ? sanitize_textarea_field( wp_unslash( $_POST['question'] ) ) : '',
		'answer'   => isset( $_POST['answer'] ) ? sanitize_textarea_field( wp_unslash( $_POST['answer'] ) ) : '',
		'url'      => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
	];
	if ( ! function_exists( 'estandar_chatbot_update_log_by_id' ) ) {
		estandar_chatbot_admin_tab_redirect( 'logs', 'error', 'Handler de edición de logs no disponible.' );
	}
	$res = estandar_chatbot_update_log_by_id( $id, $fields );
	$ok = is_array( $res ) && ! empty( $res['ok'] );
	$msg = is_array( $res ) ? (string) ( $res['message'] ?? '' ) : 'No se pudo actualizar.';
	estandar_chatbot_admin_tab_redirect( 'logs', $ok ? 'ok' : 'error', $msg );
}

function estandar_chatbot_conversation_update_action() {
	estandar_chatbot_require_permission( 'manage_conversations' );
	check_admin_referer( 'estandar_chatbot_conversation_update' );
	$id = isset( $_POST['conversation_id'] ) ? (int) $_POST['conversation_id'] : 0;
	$status = isset( $_POST['conversation_status'] ) ? wp_unslash( $_POST['conversation_status'] ) : 'open';
	$summary = isset( $_POST['summary'] ) ? wp_unslash( $_POST['summary'] ) : '';
	if ( ! function_exists( 'estandar_chatbot_update_conversation_status' ) ) {
		estandar_chatbot_admin_tab_redirect( 'conversaciones', 'error', 'Handler de conversaciones no disponible.' );
	}
	$res = estandar_chatbot_update_conversation_status( $id, $status, $summary );
	$ok = is_array( $res ) && ! empty( $res['ok'] );
	$msg = is_array( $res ) ? (string) ( $res['message'] ?? '' ) : 'No se pudo actualizar.';
	estandar_chatbot_admin_tab_redirect( 'conversaciones', $ok ? 'ok' : 'error', $msg );
}

function estandar_chatbot_conversations_bulk_update_action() {
	estandar_chatbot_require_permission( 'manage_conversations' );
	check_admin_referer( 'estandar_chatbot_conversations_bulk_update' );
	$ids = isset( $_POST['conversation_ids'] ) && is_array( $_POST['conversation_ids'] ) ? array_map( 'intval', $_POST['conversation_ids'] ) : [];
	$status = isset( $_POST['bulk_conversation_status'] ) ? wp_unslash( $_POST['bulk_conversation_status'] ) : 'open';
	if ( ! function_exists( 'estandar_chatbot_update_conversations_status_by_ids' ) ) {
		estandar_chatbot_admin_tab_redirect( 'conversaciones', 'error', 'Handler masivo de conversaciones no disponible.' );
	}
	$updated = (int) estandar_chatbot_update_conversations_status_by_ids( $ids, $status );
	estandar_chatbot_admin_tab_redirect( 'conversaciones', $updated > 0 ? 'ok' : 'error', 'Conversaciones actualizadas: ' . $updated );
}

function estandar_chatbot_conversation_delete_action() {
	estandar_chatbot_require_permission( 'manage_conversations' );
	check_admin_referer( 'estandar_chatbot_conversation_delete' );
	$id = isset( $_REQUEST['conversation_id'] ) ? (int) $_REQUEST['conversation_id'] : 0;
	if ( ! function_exists( 'estandar_chatbot_delete_conversation_by_id' ) ) {
		estandar_chatbot_admin_tab_redirect( 'conversaciones', 'error', 'Handler de borrado de conversaciones no disponible.' );
	}
	$deleted = (int) estandar_chatbot_delete_conversation_by_id( $id );
	estandar_chatbot_admin_tab_redirect( 'conversaciones', $deleted > 0 ? 'ok' : 'error', $deleted > 0 ? 'Conversación eliminada correctamente.' : 'No se pudo eliminar la conversación.' );
}

function estandar_chatbot_conversations_bulk_delete_action() {
	estandar_chatbot_require_permission( 'manage_conversations' );
	check_admin_referer( 'estandar_chatbot_conversations_bulk_delete', 'ecb_bulk_delete_nonce' );
	$ids = isset( $_POST['conversation_ids'] ) && is_array( $_POST['conversation_ids'] ) ? array_map( 'intval', $_POST['conversation_ids'] ) : [];
	if ( ! function_exists( 'estandar_chatbot_delete_conversations_by_ids' ) ) {
		estandar_chatbot_admin_tab_redirect( 'conversaciones', 'error', 'Handler masivo de borrado de conversaciones no disponible.' );
	}
	$deleted = (int) estandar_chatbot_delete_conversations_by_ids( $ids );
	estandar_chatbot_admin_tab_redirect( 'conversaciones', $deleted > 0 ? 'ok' : 'error', 'Conversaciones eliminadas: ' . $deleted );
}

function estandar_chatbot_conversations_wipe_action() {
	estandar_chatbot_require_permission( 'manage_conversations' );
	check_admin_referer( 'estandar_chatbot_conversations_wipe' );
	if ( ! function_exists( 'estandar_chatbot_delete_all_conversations' ) ) {
		estandar_chatbot_admin_tab_redirect( 'conversaciones', 'error', 'Handler de limpieza total de conversaciones no disponible.' );
	}
	$deleted = (int) estandar_chatbot_delete_all_conversations();
	estandar_chatbot_admin_tab_redirect( 'conversaciones', 'ok', 'Conversaciones eliminadas correctamente: ' . $deleted );
}

function estandar_chatbot_conversations_export_action() {
	estandar_chatbot_require_permission( 'manage_conversations' );
	check_admin_referer( 'estandar_chatbot_conversations_export' );

	if ( ! function_exists( 'estandar_chatbot_output_conversations_csv' ) ) {
		wp_die( 'Exportador de conversaciones no disponible.' );
	}

	$filters = [
		'search'              => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		'channel'             => isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '',
		'conversation_status' => isset( $_GET['conversation_status'] ) ? sanitize_key( wp_unslash( $_GET['conversation_status'] ) ) : '',
	];

	estandar_chatbot_output_conversations_csv(
		'estandar-chatbot-conversaciones-' . gmdate( 'Y-m-d-His' ) . '.csv',
		$filters
	);
}

/* ======================================================
 * SETTINGS (REGISTER_SETTING – CRÍTICO)
 * ====================================================== */

add_action( 'admin_init', 'estandar_chatbot_register_settings' );
function estandar_chatbot_register_settings() {

	/* ===============================
	   SETTINGS BASE (FREE)
	   =============================== */

	register_setting( 'estandar_chatbot_settings', 'chatbot_profile', [
		'type' => 'string',
		'sanitize_callback' => function( $v ) {
			$profile  = sanitize_key( (string) $v );
			$registry = function_exists( 'estandar_chatbot_get_profile_registry' ) ? estandar_chatbot_get_profile_registry() : [];
			return isset( $registry[ $profile ] ) ? $profile : '';
		},
		'default' => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_profile_overrides', [
		'type' => 'array',
		'sanitize_callback' => function( $v ) {
			return is_array( $v ) ? $v : [];
		},
		'default' => [],
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_activar', [
		'type' => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default' => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_solo_loggeados', [
		'type' => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default' => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_nombre', [
		'type' => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default' => 'Asistente',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_mostrar_nombre', [
		'type' => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default' => 1,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_idioma', [
		'type' => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default' => 'es',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_paginas_visibles', [
		'type' => 'array',
		'sanitize_callback' => function ( $val ) {
			return is_array( $val ) ? array_values( array_map( 'intval', $val ) ) : [];
		},
		'default' => [],
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_mensaje_ya_registrado', [
		'type' => 'string',
		'sanitize_callback' => function( $v ){
			return trim( wp_kses_post( wp_unslash( $v ) ) );
		},
		'default' => 'Ya tengo tus datos registrados de una vez anterior en la que ya conversamos. ¿Deseas actualizar tu información o continuar?',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_lead_fields', [
		'type' => 'array',
		'sanitize_callback' => function ( $value ) {

			// Permitimos editar como JSON en textarea
			if ( is_string( $value ) ) {
				$value = json_decode( $value, true );
			}

			if ( ! is_array( $value ) ) return [];

			$out = [];
			foreach ( $value as $field ) {
				if ( empty( $field['key'] ) || empty( $field['label'] ) ) continue;

				$out[] = [
					'key'      => sanitize_key( $field['key'] ),
					'label'    => wp_kses_post( $field['label'] ),
					'required' => ! empty( $field['required'] ),
				];
			}

			return $out;
		},
		'default' => [
			[ 'key' => 'name',  'label' => '¿Cómo te llamas?', 'required' => true ],
			[ 'key' => 'email', 'label' => '¿Cuál es tu correo electrónico?', 'required' => true ],
			[ 'key' => 'phone', 'label' => '¿Tu teléfono?', 'required' => false ],
		],
	]);

	/* ===============================
	   OPENAI API KEY (ADMIN)
	   Guardada en wp_options.
	   =============================== */

	register_setting( 'estandar_chatbot_settings', 'chatbot_openai_api_key', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			// No usar sanitize_text_field para no alterar claves con caracteres "no-alfanuméricos".
			// Solo normalizamos whitespace típico de copy/paste.
			$v = (string) wp_unslash( $v );
			$v = str_replace( ["\r", "\n", "\t"], '', $v );
			$v = trim( $v );
			return '' === $v ? (string) get_option( 'chatbot_openai_api_key', '' ) : $v;
		},
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_system_prompt', [
		'type'              => 'string',
		'sanitize_callback' => 'estandar_chatbot_sanitize_system_prompt_option',
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_openai_model', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			$v = sanitize_text_field( (string) wp_unslash( $v ) );
			$allowed = class_exists( 'Estandar_AI_Gateway' )
				? Estandar_AI_Gateway::available_models()
				: [ 'gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo' ];
			return in_array( $v, $allowed, true ) ? $v : 'gpt-4o';
		},
		'default'           => 'gpt-4o',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_openai_max_tokens', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ) {
			$n = max( 100, min( 8000, (int) $v ) );
			return $n;
		},
		'default'           => 500,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_openai_temperature', [
		'type'              => 'number',
		'sanitize_callback' => function( $v ) {
			$f = (float) $v;
			$f = max( 0.0, min( 2.0, $f ) );
			return round( $f, 1 );
		},
		'default'           => 0.4,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_notify_email_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 1,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_notification_mode', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			$mode = sanitize_key( wp_unslash( $v ) );
			return in_array( $mode, [ 'legacy', 'leads' ], true ) ? $mode : 'legacy';
		},
		'default'           => 'legacy',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_notify_email_to', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){
			$list = array_filter( array_map( 'trim', explode( ',', (string) wp_unslash( $v ) ) ) );
			$valid = [];
			foreach ( $list as $mail ) {
				$san = sanitize_email( $mail );
				if ( is_email( $san ) ) {
					$valid[] = $san;
				}
			}
			return implode( ',', array_unique( $valid ) );
		},
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_notify_email_subject', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){
			return sanitize_text_field( wp_unslash( $v ) );
		},
		'default'           => 'Nuevo lead desde chatbot',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_notify_email_template', [
		'type'              => 'string',
		'sanitize_callback' => 'estandar_chatbot_sanitize_email_template_option',
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_notify_email_include_signature', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 1,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_logs_retention_days', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){
			$v = (int) $v;
			return max( 0, $v );
		},
		'default'           => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_log_defer', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ) { return $v ? 1 : 0; },
		'default'           => 1,
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_excluded_question_patterns', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => '',
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_flow_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ) { return $v ? 1 : 0; },
		'default'           => 0,
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_flow_json', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			$raw = (string) wp_unslash( $v );
			if ( trim( $raw ) === '' ) {
				return '';
			}
			$dec = json_decode( $raw, true );
			return is_array( $dec ) ? wp_json_encode( $dec ) : '';
		},
		'default'           => '',
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_lead_capture_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 1,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_auto_open_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_enable_website_demo', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_requires_disambiguation', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_business_industry', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'servicios',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_business_goal', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'captar contactos',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_business_primary_cta', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'Quiero avanzar',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_business_sensitive_topics', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => 'precio, plazos, disponibilidad',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_business_tone', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => 'claro y profesional',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_auto_open_type', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			$type = sanitize_key( wp_unslash( $v ) );
			return in_array( $type, [ 'time', 'scroll' ], true ) ? $type : 'time';
		},
		'default'           => 'time',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_auto_open_value', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ) {
			return max( 1, min( 100, (int) $v ) );
		},
		'default'           => 6,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_human_escalation_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 1,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_human_escalation_text', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){
			return sanitize_text_field( wp_unslash( $v ) );
		},
		'default'           => 'Si prefieres, te puedo derivar ahora mismo con una persona del equipo.',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_human_escalation_mode', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			$mode = sanitize_key( wp_unslash( $v ) );
			return in_array( $mode, [ 'lead_form', 'whatsapp' ], true ) ? $mode : 'lead_form';
		},
		'default'           => 'lead_form',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_human_whatsapp_number', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			return preg_replace( '/\D+/', '', (string) wp_unslash( $v ) );
		},
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_human_whatsapp_message', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			return sanitize_textarea_field( wp_unslash( $v ) );
		},
		'default'           => 'Hola, quiero hablar con una persona del equipo. Dejé mis datos en la web.',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_post_lead_message', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			return sanitize_textarea_field( wp_unslash( $v ) );
		},
		'default'           => "Perfecto 👌 ya tengo tus datos.\n\nSi quieres avanzar ahora mismo, podemos verlo por WhatsApp.",
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_post_lead_actions', [
		'type'              => 'array',
		'sanitize_callback' => function( $value ) {
			if ( ! is_array( $value ) ) {
				return [];
			}
			$allowed_actions = [ 'whatsapp', 'deferred', 'reset' ];
			$out = [];
			foreach ( $value as $item ) {
				$action  = sanitize_key( $item['action'] ?? '' );
				$label   = sanitize_text_field( $item['label'] ?? '' );
				$enabled = ! empty( $item['enabled'] ) ? 1 : 0;
				if ( ! in_array( $action, $allowed_actions, true ) ) {
					continue;
				}
				$out[] = [
					'action'  => $action,
					'label'   => $label,
					'enabled' => $enabled,
				];
			}
			$has_enabled = false;
			foreach ( $out as $item ) {
				if ( ! empty( $item['enabled'] ) ) {
					$has_enabled = true;
					break;
				}
			}
			if ( ! $has_enabled ) {
				foreach ( $out as &$item ) {
					if ( ( $item['action'] ?? '' ) === 'whatsapp' ) {
						$item['enabled'] = 1;
						break;
					}
				}
				unset( $item );
			}
			return array_values( $out );
		},
		'default'           => [
			[ 'action' => 'whatsapp', 'label' => 'Hablar por WhatsApp', 'enabled' => 1 ],
			[ 'action' => 'deferred', 'label' => 'Prefiero que me contacten', 'enabled' => 1 ],
			[ 'action' => 'reset', 'label' => 'Volver al inicio', 'enabled' => 1 ],
		],
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_cta_labels', [
		'type'              => 'array',
		'sanitize_callback' => function( $value ) {
			$defaults = [
				'default'   => 'Ver más',
				'pagar'     => 'Pagar ahora',
				'precio'    => 'Ver precios',
				'cotizar'   => 'Cotizar ahora',
				'contratar' => 'Contratar ahora',
			];
			if ( ! is_array( $value ) ) {
				return $defaults;
			}
			$out = [];
			foreach ( $defaults as $key => $fallback ) {
				$label = sanitize_text_field( wp_unslash( $value[ $key ] ?? '' ) );
				$out[ $key ] = $label !== '' ? $label : $fallback;
			}
			return $out;
		},
		'default'           => [
			'default'   => 'Ver más',
			'pagar'     => 'Pagar ahora',
			'precio'    => 'Ver precios',
			'cotizar'   => 'Cotizar ahora',
			'contratar' => 'Contratar ahora',
		],
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_behavior_copy', [
		'type'              => 'array',
		'sanitize_callback' => function( $value ) {
			$defaults = estandar_chatbot_get_behavior_copy_defaults();
			if ( ! is_array( $value ) ) {
				return $defaults;
			}
			$out = [];
			foreach ( $defaults as $key => $fallback ) {
				$raw = wp_unslash( $value[ $key ] ?? '' );
				$out[ $key ] = is_string( $fallback )
					? sanitize_textarea_field( $raw )
					: $fallback;
				if ( '' === trim( (string) $out[ $key ] ) ) {
					$out[ $key ] = $fallback;
				}
			}
			return $out;
		},
		'default'           => estandar_chatbot_get_behavior_copy_defaults(),
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_project_registry', [
		'type'              => 'array',
		'sanitize_callback' => 'estandar_chatbot_sanitize_legacy_project_registry',
		'default'           => [],
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_responsables', [
		'type'              => 'array',
		'sanitize_callback' => function( $value ) {
			if ( ! is_array( $value ) ) {
				return [];
			}
			$out = [];
			foreach ( $value as $item ) {
				$nombre = sanitize_text_field( $item['nombre'] ?? '' );
				$area = sanitize_text_field( $item['area'] ?? '' );
				$project_slug = estandar_chatbot_normalize_project_slug( $item['project_slug'] ?? $area );
				$email = sanitize_email( $item['email'] ?? '' );
				$telefono = preg_replace( '/\D+/', '', (string) ( $item['telefono'] ?? '' ) );
				if ( $nombre === '' ) {
					continue;
				}
				$out[] = [
					'nombre'   => $nombre,
					'project_slug' => $project_slug,
					'area'     => $area,
					'email'    => $email,
					'telefono' => $telefono,
				];
			}
			return array_values( $out );
		},
		'default'           => [],
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_source_intent_rules', [
		'type'              => 'array',
		'sanitize_callback' => function( $value ) {
			if ( ! is_array( $value ) ) {
				return [];
			}
			$out = [];
			foreach ( $value as $item ) {
				$slug   = sanitize_title( remove_accents( (string) ( $item['slug'] ?? '' ) ) );
				$intent = sanitize_key( (string) ( $item['intent'] ?? '' ) );
				$project_slug = estandar_chatbot_normalize_project_slug( $item['project_slug'] ?? $item['area'] ?? '' );
				$area   = sanitize_text_field( (string) ( $item['area'] ?? '' ) );
				if ( $slug === '' ) {
					continue;
				}
				$out[] = [
					'slug'   => $slug,
					'intent' => $intent,
					'project_slug' => $project_slug,
					'area'   => $area,
				];
			}
			return array_values( $out );
		},
		'default'           => [],
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_text_intent_rules', [
		'type'              => 'array',
		'sanitize_callback' => function( $value ) {
			if ( ! is_array( $value ) ) {
				return [];
			}
			$out = [];
			foreach ( $value as $item ) {
				$keyword = sanitize_text_field( (string) ( $item['keyword'] ?? '' ) );
				$intent  = sanitize_key( (string) ( $item['intent'] ?? '' ) );
				$project_slug = estandar_chatbot_normalize_project_slug( $item['project_slug'] ?? $item['area'] ?? '' );
				$area    = sanitize_text_field( (string) ( $item['area'] ?? '' ) );
				if ( $keyword === '' || $intent === '' ) {
					continue;
				}
				$out[] = [
					'keyword' => $keyword,
					'intent'  => $intent,
					'project_slug' => $project_slug,
					'area'    => $area,
				];
			}
			return array_values( $out );
		},
		'default'           => [
			[ 'keyword' => 'cotizar', 'intent' => 'ventas', 'area' => '' ],
			[ 'keyword' => 'precio', 'intent' => 'ventas', 'area' => '' ],
			[ 'keyword' => 'informacion', 'intent' => 'info', 'area' => '' ],
			[ 'keyword' => 'contacto', 'intent' => 'humano', 'area' => '' ],
		],
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_botones_iniciales', [
		'type'              => 'array',
		'sanitize_callback' => 'estandar_chatbot_sanitize_botones',
		'default'           => [],
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_hide_menu_on_click', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 1,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_theme_accent', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_hex_color',
		'default'           => '#111827',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_theme_visual_style', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			$v = sanitize_key( (string) $v );
			return in_array( $v, [ 'default', 'corporativo_minimalista', 'minimal_corporate' ], true ) ? $v : 'default';
		},
		'default'           => 'default',
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_visual_style', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			$v = sanitize_key( (string) $v );
			return in_array( $v, [ 'default', 'corporativo_minimalista' ], true ) ? $v : 'default';
		},
		'default'           => 'default',
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_theme_shadow_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 1,
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_branding_config', [
		'type'              => 'array',
		'sanitize_callback' => function( $value ) {
			$defaults = [
				'bg_color'         => '#ffffff',
				'bot_bubble_color' => '#f3f4f6',
				'user_bubble_color'=> '#e8f0fe',
				'button_color'     => '#2563eb',
				'text_color'       => '#111827',
				'background_image' => '',
				'bot_avatar'       => '',
				'shadow_enabled'   => 1,
				'shadow_intensity' => 6,
			];
			if ( ! is_array( $value ) ) {
				return $defaults;
			}

			$out = [];
			$out['bg_color'] = sanitize_hex_color( $value['bg_color'] ?? '' ) ?: $defaults['bg_color'];
			$out['bot_bubble_color'] = sanitize_hex_color( $value['bot_bubble_color'] ?? '' ) ?: $defaults['bot_bubble_color'];
			$out['user_bubble_color'] = sanitize_hex_color( $value['user_bubble_color'] ?? '' ) ?: $defaults['user_bubble_color'];
			$out['button_color'] = sanitize_hex_color( $value['button_color'] ?? '' ) ?: $defaults['button_color'];
			$out['text_color'] = sanitize_hex_color( $value['text_color'] ?? '' ) ?: $defaults['text_color'];
			$out['background_image'] = esc_url_raw( (string) ( $value['background_image'] ?? '' ) );
			$out['bot_avatar'] = esc_url_raw( (string) ( $value['bot_avatar'] ?? '' ) );
			$out['shadow_enabled'] = ! empty( $value['shadow_enabled'] ) ? 1 : 0;
			$out['shadow_intensity'] = min( 24, max( 0, (int) ( $value['shadow_intensity'] ?? $defaults['shadow_intensity'] ) ) );
			return $out;
		},
		'default'           => [
			'bg_color'         => '#ffffff',
			'bot_bubble_color' => '#f3f4f6',
			'user_bubble_color'=> '#e8f0fe',
			'button_color'     => '#2563eb',
			'text_color'       => '#111827',
			'background_image' => '',
			'bot_avatar'       => '',
			'shadow_enabled'   => 1,
			'shadow_intensity' => 6,
		],
	] );

	register_setting( 'estandar_chatbot_settings', 'chatbot_theme_user_bubble', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_hex_color',
		'default'           => '#111827',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_theme_bot_bubble', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_hex_color',
		'default'           => '#ffffff',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_theme_radius', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return min( 28, max( 12, (int) $v ) ); },
		'default'           => 22,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_theme_bubble_shape', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			$v = sanitize_key( (string) $v );
			$allowed = [ 'rounded', 'square', 'rounded_corners' ];
			return in_array( $v, $allowed, true ) ? $v : 'rounded';
		},
		'default'           => 'rounded',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_theme_position', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){
			$v = sanitize_key( (string) $v );
			return in_array( $v, [ 'right', 'left', 'custom' ], true ) ? $v : 'right';
		},
		'default'           => 'right',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_position_bottom', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return min( 240, max( 0, (int) $v ) ); },
		'default'           => 22,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_position_left', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			$v = trim( (string) wp_unslash( $v ) );
			if ( '' === $v ) {
				return '';
			}
			return (string) min( 240, max( 0, (int) $v ) );
		},
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_position_right', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ) {
			$v = trim( (string) wp_unslash( $v ) );
			if ( '' === $v ) {
				return '';
			}
			return (string) min( 240, max( 0, (int) $v ) );
		},
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_bubble_image_url', [
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_bubble_size', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return min( 88, max( 40, (int) $v ) ); },
		'default'           => 58,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_inline_sidebar_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_inline_fixed_composer_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_inline_fullpage_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_lead_intro_text', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){
			$text = sanitize_text_field( wp_unslash( $v ) );
			return '' !== trim( $text ) ? $text : 'Hola 👋 ¿Qué necesitas hoy? Elige una opción y te ayudo a avanzar rápido.';
		},
		'default'           => 'Te ayudo rápidamente. Primero dime qué necesitas para derivarte con el equipo correcto.',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_lead_intents', [
		'type'              => 'array',
		'sanitize_callback' => function( $value ) {
			if ( is_string( $value ) ) {
				$value = json_decode( $value, true );
			}
			if ( ! is_array( $value ) ) return [];
			$out = [];
			foreach ( $value as $item ) {
				if ( empty( $item['id'] ) || empty( $item['label'] ) ) continue;
				$out[] = [
					'id'    => sanitize_key( $item['id'] ),
					'label' => sanitize_text_field( $item['label'] ),
				];
			}
			return $out;
		},
		'default'           => [
			[ 'id' => 'ventas', 'label' => 'Quiero cotizar' ],
			[ 'id' => 'soporte', 'label' => 'Necesito soporte' ],
			[ 'id' => 'info', 'label' => 'Quiero más información' ],
		],
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_lead_hot_threshold', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return max( 0, (int) $v ); },
		'default'           => 80,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_lead_warm_threshold', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return max( 0, (int) $v ); },
		'default'           => 50,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_webhook_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_webhook_url', [
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_webhook_provider', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_key',
		'default'           => 'n8n',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_webhook_secret', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){
			return sanitize_text_field( wp_unslash( $v ) );
		},
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_crm_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_crm_url', [
		'type'              => 'string',
		'sanitize_callback' => 'esc_url_raw',
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_crm_provider', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_key',
		'default'           => 'generic',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_crm_secret', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){
			return sanitize_text_field( wp_unslash( $v ) );
		},
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_whatsapp_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 0,
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_whatsapp_phone_number_id', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){ return sanitize_text_field( wp_unslash( $v ) ); },
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_whatsapp_access_token', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){ return sanitize_text_field( wp_unslash( $v ) ); },
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_whatsapp_verify_token', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){ return sanitize_text_field( wp_unslash( $v ) ); },
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_whatsapp_business_account_id', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){ return sanitize_text_field( wp_unslash( $v ) ); },
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_whatsapp_app_secret', [
		'type'              => 'string',
		'sanitize_callback' => function( $v ){ return sanitize_text_field( wp_unslash( $v ) ); },
		'default'           => '',
	]);

	register_setting( 'estandar_chatbot_settings', 'chatbot_pro_enabled', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ){ return $v ? 1 : 0; },
		'default'           => 0,
	]);

	/* ===============================
	   BOTONES
	   =============================== */
	register_setting( 'estandar_chatbot_botones', 'chatbot_botones_iniciales', [
		'type'              => 'array',
		'sanitize_callback' => 'estandar_chatbot_sanitize_botones',
		'default'           => [],
	]);

	// CONTEXTO tab removed (Fase A1, 2026-06) — chatbot_context_txt_path is registered in ia-contexto
	// chatbot_use_pinecone deleted: feature was never implemented

	register_setting( 'estandar_chatbot_tools', 'ecb_uninstall_wipe_data', [
		'type'              => 'integer',
		'sanitize_callback' => function( $v ) { return $v ? 1 : 0; },
		'default'           => 0,
	] );

	/* ===============================
	   LICENCIA
	   =============================== */
	register_setting( 'estandar_chatbot_license', 'estandar_chatbot_license', [
		'type'              => 'array',
		'sanitize_callback' => function( $value ) {
			if ( ! is_array( $value ) ) {
				return [
					'status' => 'free',
					'key'    => '',
					'source' => 'manual',
				];
			}
			return [
				'status' => sanitize_key( $value['status'] ?? 'free' ),
				'key'    => sanitize_text_field( $value['key'] ?? '' ),
				'source' => 'manual',
			];
		},
		'default'           => [
			'status' => 'free',
			'key'    => '',
			'source' => 'manual',
		],
	]);
}

// Evita que la API key se borre si el campo llega vacío en el submit
add_filter( 'pre_update_option_chatbot_openai_api_key', function( $new_value, $old_value ) {
    if ( $new_value === '' || $new_value === null ) {
        return $old_value;
    }
    return $new_value;
}, 10, 2 );

function estandar_chatbot_get_registered_option_groups() {
	return [
			'estandar_chatbot_settings' => [
				'chatbot_profile',
				'chatbot_profile_overrides',
				'chatbot_activar',
				'chatbot_solo_loggeados',
			'chatbot_nombre',
			'chatbot_mostrar_nombre',
			'chatbot_idioma',
			'chatbot_paginas_visibles',
			'chatbot_mensaje_ya_registrado',
			'chatbot_lead_fields',
			'chatbot_openai_api_key',
			'chatbot_system_prompt',
			'chatbot_openai_model',
			'chatbot_openai_max_tokens',
			'chatbot_openai_temperature',
			'chatbot_notify_email_enabled',
			'chatbot_notification_mode',
			'chatbot_notify_email_to',
			'chatbot_notify_email_subject',
			'chatbot_notify_email_template',
			'chatbot_notify_email_include_signature',
			'chatbot_logs_retention_days',
			'chatbot_log_defer',
			'chatbot_flow_enabled',
			'chatbot_flow_json',
			'chatbot_lead_capture_enabled',
			'chatbot_auto_open_enabled',
			'chatbot_enable_website_demo',
			'chatbot_requires_disambiguation',
			'chatbot_business_industry',
			'chatbot_business_goal',
			'chatbot_business_primary_cta',
			'chatbot_business_sensitive_topics',
			'chatbot_business_tone',
			'chatbot_auto_open_type',
			'chatbot_auto_open_value',
			'chatbot_human_escalation_enabled',
			'chatbot_human_escalation_text',
			'chatbot_human_escalation_mode',
			'chatbot_human_whatsapp_number',
			'chatbot_human_whatsapp_message',
			'chatbot_post_lead_message',
				'chatbot_post_lead_actions',
				'chatbot_cta_labels',
					'chatbot_responsables',
					'chatbot_source_intent_rules',
					'chatbot_text_intent_rules',
			'chatbot_hide_menu_on_click',
			'chatbot_theme_accent',
			'chatbot_theme_visual_style',
			'chatbot_visual_style',
			'chatbot_theme_shadow_enabled',
			'chatbot_branding_config',
			'chatbot_theme_user_bubble',
			'chatbot_theme_bot_bubble',
			'chatbot_theme_radius',
			'chatbot_theme_bubble_shape',
			'chatbot_theme_position',
			'chatbot_position_bottom',
			'chatbot_position_left',
			'chatbot_position_right',
			'chatbot_bubble_image_url',
			'chatbot_bubble_size',
			'chatbot_inline_sidebar_enabled',
			'chatbot_inline_fixed_composer_enabled',
			'chatbot_inline_fullpage_enabled',
			'chatbot_lead_intro_text',
			'chatbot_lead_intents',
			'chatbot_lead_hot_threshold',
			'chatbot_lead_warm_threshold',
			'chatbot_webhook_enabled',
			'chatbot_webhook_url',
			'chatbot_webhook_provider',
			'chatbot_webhook_secret',
			'chatbot_crm_enabled',
			'chatbot_crm_url',
			'chatbot_crm_provider',
			'chatbot_crm_secret',
			'chatbot_whatsapp_enabled',
			'chatbot_whatsapp_phone_number_id',
			'chatbot_whatsapp_access_token',
			'chatbot_whatsapp_verify_token',
			'chatbot_whatsapp_business_account_id',
			'chatbot_whatsapp_app_secret',
			'chatbot_pro_enabled',
		],
		'estandar_chatbot_botones' => [
			'chatbot_botones_iniciales',
		],
		// estandar_chatbot_contexto removed (Fase A1, 2026-06)
		'estandar_chatbot_tools' => [
			'ecb_uninstall_wipe_data',
		],
	];
}

function estandar_chatbot_should_preserve_missing_option( $option_name ) {
	if ( ! is_admin() || ! isset( $_POST['option_page'] ) ) {
		return false;
	}

	$option_page = sanitize_text_field( wp_unslash( $_POST['option_page'] ) );
	$groups      = estandar_chatbot_get_registered_option_groups();
	$options     = isset( $groups[ $option_page ] ) ? $groups[ $option_page ] : [];

	if ( ! in_array( $option_name, $options, true ) ) {
		return false;
	}

	if ( array_key_exists( $option_name, $_POST ) ) {
		return false;
	}

	$presence_markers = [
			'chatbot_paginas_visibles'  => 'chatbot_paginas_visibles__present',
			'chatbot_botones_iniciales' => 'chatbot_botones_iniciales__present',
			'chatbot_lead_intents'      => 'chatbot_lead_intents__present',
				'chatbot_responsables'      => 'chatbot_responsables__present',
			'chatbot_source_intent_rules' => 'chatbot_source_intent_rules__present',
			'chatbot_text_intent_rules' => 'chatbot_text_intent_rules__present',
	];

	if ( isset( $presence_markers[ $option_name ] ) && isset( $_POST[ $presence_markers[ $option_name ] ] ) ) {
		return false;
	}

	return true;
}

function estandar_chatbot_protect_option_generic( $new, $old, $option_name ) {
	if ( estandar_chatbot_should_preserve_missing_option( $option_name ) ) {
		return ( $old !== null ) ? $old : get_option( $option_name );
	}

	return $new;
}

foreach ( estandar_chatbot_get_registered_option_groups() as $option_names ) {
	foreach ( $option_names as $option_name ) {
		add_filter(
			'pre_update_option_' . $option_name,
			function( $new, $old ) use ( $option_name ) {
				return estandar_chatbot_protect_option_generic( $new, $old, $option_name );
			},
			10,
			2
		);
	}
}

if ( ! function_exists( 'estandar_chatbot_sanitize_botones' ) ) {
	function estandar_chatbot_sanitize_botones( $val ) {
		if ( ! is_array( $val ) ) return [];
		$out = [];
		foreach ( $val as $btn ) {
			if ( empty( $btn['texto'] ) ) continue;
			$item = [
				'id'      => sanitize_key( $btn['id'] ?? uniqid( 'btn_' ) ),
				'icono'   => sanitize_text_field( $btn['icono'] ?? '' ),
				'texto'   => sanitize_text_field( $btn['texto'] ),
				'tipo'    => sanitize_key( $btn['tipo'] ?? 'info' ),
				'payload' => sanitize_textarea_field( $btn['payload'] ?? '' ),
			];
			if ( $item['tipo'] === 'submenu' && ! empty( $btn['children'] ) && is_array( $btn['children'] ) ) {
				$item['children'] = [];
				foreach ( $btn['children'] as $child ) {
					if ( empty( $child['texto'] ) ) continue;
					$item['children'][] = [
						'id'      => sanitize_key( $child['id'] ?? uniqid( 'sub_' ) ),
						'icono'   => sanitize_text_field( $child['icono'] ?? '' ),
						'texto'   => sanitize_text_field( $child['texto'] ),
						'tipo'    => sanitize_key( $child['tipo'] ?? 'info' ),
						'payload' => sanitize_textarea_field( $child['payload'] ?? '' ),
					];
				}
			}
			$out[] = $item;
		}
		return array_values( $out );
	}
}

/* ======================================================
 * ADMIN MENU
 * ====================================================== */

add_action( 'admin_menu', 'estandar_chatbot_admin_menu' );
function estandar_chatbot_admin_menu() {
	if ( ! estandar_chatbot_user_can_access_dashboard() ) {
		return;
	}

	// Prefer Estandar Core (MU-plugin). Fallback to standalone menu if Core is absent.
	if ( function_exists( 'estandar_register_submenu' ) ) {
		estandar_register_submenu(
			[
				'slug'       => 'estandar-chatbot',
				'title'      => 'Estandar Chatbot',
				'menu_title' => 'Chatbot IA',
				'capability' => 'read',
				'callback'   => 'estandar_chatbot_render_dashboard',
			]
		);
		return;
	}

	add_menu_page(
		'Estandar Chatbot',
		'Chatbot IA',
		'read',
		'estandar-chatbot',
		'estandar_chatbot_render_dashboard',
		'dashicons-format-chat',
		58
	);
}

function estandar_chatbot_render_dashboard() {
	require ESTANDAR_CHATBOT_PATH . 'admin/dashboard.php';
}

/* ======================================================
 * ADMIN ASSETS
 * ====================================================== */

add_action( 'admin_enqueue_scripts', 'estandar_chatbot_admin_assets' );

function estandar_chatbot_is_admin_screen( $hook = '' ): bool {
	$hook = (string) $hook;
	if ( $hook !== '' && strpos( $hook, 'estandar-chatbot' ) !== false ) {
		return true;
	}

	if ( function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		if ( $screen && strpos( (string) $screen->id, 'estandar-chatbot' ) !== false ) {
			return true;
		}
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( $page !== 'estandar-chatbot' ) {
		return false;
	}

	$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
	return $pagenow === 'admin.php';
}

function estandar_chatbot_admin_assets( $hook ) {

	if ( ! estandar_chatbot_is_admin_screen( $hook ) ) return;

	wp_enqueue_media();

	// If Estandar Core is active, use the shared admin UI layer to avoid duplicated base CSS.
	// If Core is absent, keep the plugin's own admin CSS as fallback.
	if ( ! primer_is_active() ) {
		$admin_css = ESTANDAR_CHATBOT_PATH . 'admin/assets/admin.css';
		if ( file_exists( $admin_css ) ) {
			wp_enqueue_style(
				'estandar-chatbot-admin-style',
				ESTANDAR_CHATBOT_URL . 'admin/assets/admin.css',
				[],
				ESTANDAR_CHATBOT_VERSION
			);
		}
	}

}

/* ======================================================
 * FRONTEND ASSETS
 * ====================================================== */

add_action( 'wp_enqueue_scripts', 'estandar_chatbot_frontend_assets' );
function estandar_chatbot_current_page_has_shortcode() {
	if ( ! is_singular() ) {
		return false;
	}

	global $post;
	if ( ! ( $post instanceof WP_Post ) ) {
		return false;
	}

	$content = (string) $post->post_content;
	return has_shortcode( $content, 'estandar_chatbot' );
}

function estandar_chatbot_current_view_page_ids() {
	$ids = [];

	if ( is_singular() ) {
		$ids[] = (int) get_queried_object_id();
	}

	if ( is_front_page() ) {
		$front_page_id = (int) get_option( 'page_on_front', 0 );
		if ( $front_page_id > 0 ) {
			$ids[] = $front_page_id;
		}
	}

	if ( is_home() ) {
		$posts_page_id = (int) get_option( 'page_for_posts', 0 );
		if ( $posts_page_id > 0 ) {
			$ids[] = $posts_page_id;
		}
	}

	return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
}

function estandar_chatbot_is_visible_for_current_user() {
	if ( ! get_option( 'chatbot_activar', 0 ) ) {
		return false;
	}

	if ( (int) get_option( 'chatbot_solo_loggeados', 0 ) === 1 && ! is_user_logged_in() ) {
		return false;
	}

	return true;
}

function estandar_chatbot_should_enqueue_frontend() {
	if ( ! estandar_chatbot_is_visible_for_current_user() ) {
		return false;
	}

	if ( estandar_chatbot_current_page_has_shortcode() ) {
		return true;
	}

	$paginas = estandar_chatbot_get_option_array( 'chatbot_paginas_visibles', [] );
	if ( empty( $paginas ) ) {
		return true;
	}

	$current_page_ids = estandar_chatbot_current_view_page_ids();
	if ( empty( $current_page_ids ) ) {
		return false;
	}

	return ! empty( array_intersect( $current_page_ids, array_map( 'intval', $paginas ) ) );
}

function estandar_chatbot_frontend_url_scheme() {
	if ( is_ssl() ) {
		return 'https';
	}

	if ( function_exists( 'wp_is_using_https' ) && wp_is_using_https() ) {
		return 'https';
	}

	return 'http';
}

function estandar_chatbot_frontend_asset_url( $path ) {
	return esc_url_raw( set_url_scheme( ESTANDAR_CHATBOT_URL . ltrim( (string) $path, '/' ), estandar_chatbot_frontend_url_scheme() ) );
}

function estandar_chatbot_frontend_media_url( $url ) {
	$url = trim( (string) $url );
	if ( $url === '' ) {
		return '';
	}

	return esc_url_raw( set_url_scheme( $url, 'https' ) );
}

function estandar_chatbot_frontend_assets() {

	if ( ! estandar_chatbot_should_enqueue_frontend() ) return;

	$effective_config = function_exists( 'estandar_chatbot_get_effective_config' ) ? estandar_chatbot_get_effective_config() : [];
	$ecb_option = function( $key, $default = null ) use ( $effective_config ) {
		return array_key_exists( $key, $effective_config ) ? $effective_config[ $key ] : get_option( $key, $default );
	};

	$branding_config = $ecb_option( 'chatbot_branding_config', [
		'bg_color'         => '#ffffff',
		'bot_bubble_color' => '#f3f4f6',
		'user_bubble_color'=> '#e8f0fe',
		'button_color'     => '#2563eb',
		'text_color'       => '#111827',
		'background_image' => '',
		'bot_avatar'       => '',
		'shadow_enabled'   => 1,
		'shadow_intensity' => 6,
	] );
	if ( ! is_array( $branding_config ) ) {
		$branding_config = [];
	}
	$branding_config['background_image'] = estandar_chatbot_frontend_media_url( $branding_config['background_image'] ?? '' );
	$branding_config['bot_avatar']       = estandar_chatbot_frontend_media_url( $branding_config['bot_avatar'] ?? '' );

	wp_enqueue_style(
		'estandar-chatbot-frontend-style',
		estandar_chatbot_frontend_asset_url( 'frontend/chatbot.css' ),
		[],
		ESTANDAR_CHATBOT_VERSION
	);

	wp_enqueue_script(
		'estandar-chatbot-frontend-script',
		estandar_chatbot_frontend_asset_url( 'frontend/chatbot-loader.js' ),
		[],
		ESTANDAR_CHATBOT_VERSION,
		true
	);

	wp_localize_script(
			'estandar-chatbot-frontend-script',
			'chatbotConfig',
			[
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'estandar_chatbot_nonce' ),
			'cssUrl'  => estandar_chatbot_frontend_asset_url( 'frontend/chatbot.css' ) . '?ver=' . rawurlencode( ESTANDAR_CHATBOT_VERSION ),
			'scriptUrls' => [
				'stateManager'   => estandar_chatbot_frontend_asset_url( 'frontend/state-manager.js' ) . '?ver=' . rawurlencode( ESTANDAR_CHATBOT_VERSION ),
				'decisionEngine' => estandar_chatbot_frontend_asset_url( 'frontend/decision-engine.js' ) . '?ver=' . rawurlencode( ESTANDAR_CHATBOT_VERSION ),
				'chatbot'        => estandar_chatbot_frontend_asset_url( 'frontend/chatbot.js' ) . '?ver=' . rawurlencode( ESTANDAR_CHATBOT_VERSION ),
			],
			'activo'  => true,
				'siteName' => estandar_chatbot_get_brand_label(),
					'botName' => $ecb_option( 'chatbot_nombre', 'Asistente' ),
					'mostrarNombre' => (int) $ecb_option( 'chatbot_mostrar_nombre', 1 ) === 1,
					'botones' => $ecb_option( 'chatbot_botones_iniciales', [] ),
					'idioma'  => $ecb_option( 'chatbot_idioma', 'es' ),
					'leadCaptureEnabled' => (int) $ecb_option( 'chatbot_lead_capture_enabled', 1 ) === 1,
					'autoOpen'           => [
						'enabled' => (int) $ecb_option( 'chatbot_auto_open_enabled', 0 ) === 1,
						'type'    => $ecb_option( 'chatbot_auto_open_type', 'time' ),
						'value'   => (int) $ecb_option( 'chatbot_auto_open_value', 6 ),
					],
					'leadIntroText'      => $ecb_option( 'chatbot_lead_intro_text', 'Te ayudo rápidamente. Primero dime qué necesitas para derivarte con el equipo correcto.' ),
					'leadFields'         => $ecb_option( 'chatbot_lead_fields', [] ),
					'leadIntents'        => $ecb_option( 'chatbot_lead_intents', [] ),
					'hideMenuOnClick'    => (int) $ecb_option( 'chatbot_hide_menu_on_click', 1 ) === 1,
					'humanEscalationEnabled' => (int) $ecb_option( 'chatbot_human_escalation_enabled', 1 ) === 1,
					'humanEscalationText'    => $ecb_option( 'chatbot_human_escalation_text', 'Si prefieres, te puedo derivar ahora mismo con una persona del equipo.' ),
					'humanEscalationMode'    => $ecb_option( 'chatbot_human_escalation_mode', 'lead_form' ),
					'humanEscalationWhatsAppNumber' => $ecb_option( 'chatbot_human_whatsapp_number', '' ),
					'humanEscalationWhatsAppMessage' => $ecb_option( 'chatbot_human_whatsapp_message', 'Hola, quiero hablar con una persona del equipo. Dejé mis datos en la web.' ),
					'projectRegistry'    => function_exists( 'estandar_chatbot_get_project_registry' ) ? estandar_chatbot_get_project_registry() : [],
					'capabilities'       => [
						'enableWebsiteDemo'      => (int) $ecb_option( 'chatbot_enable_website_demo', 0 ) === 1,
						'requiresDisambiguation' => (int) $ecb_option( 'chatbot_requires_disambiguation', 0 ) === 1,
					],
					'businessContext'    => [
						'industry'        => $ecb_option( 'chatbot_business_industry', 'servicios' ),
						'goal'            => $ecb_option( 'chatbot_business_goal', 'captar contactos' ),
						'primaryCta'      => $ecb_option( 'chatbot_business_primary_cta', 'Quiero avanzar' ),
						'sensitiveTopics' => $ecb_option( 'chatbot_business_sensitive_topics', 'precio, plazos, disponibilidad' ),
						'tone'            => $ecb_option( 'chatbot_business_tone', 'claro y profesional' ),
					],
					'postLeadMessage'    => $ecb_option( 'chatbot_post_lead_message', "Perfecto 👌 ya tengo tus datos.\n\nSi quieres avanzar ahora mismo, podemos verlo por WhatsApp." ),
					'postLeadActions'    => $ecb_option( 'chatbot_post_lead_actions', [
						[ 'action' => 'whatsapp', 'label' => 'Hablar por WhatsApp', 'enabled' => 1 ],
						[ 'action' => 'deferred', 'label' => 'Prefiero que me contacten', 'enabled' => 1 ],
						[ 'action' => 'reset', 'label' => 'Volver al inicio', 'enabled' => 1 ],
					] ),
					'cta'                => $ecb_option( 'chatbot_cta_labels', [
						'default'   => 'Ver más',
						'pagar'     => 'Pagar ahora',
						'precio'    => 'Ver precios',
						'cotizar'   => 'Cotizar ahora',
						'contratar' => 'Contratar ahora',
					] ),
					'behaviorCopy'       => $ecb_option( 'chatbot_behavior_copy', estandar_chatbot_get_behavior_copy_defaults() ),
					'theme' => [
						'accent'     => $ecb_option( 'chatbot_theme_accent', '#111827' ),
						'visualStyle' => ( $ecb_option( 'chatbot_visual_style', '' ) ?: $ecb_option( 'chatbot_theme_visual_style', 'default' ) ),
						'shadowEnabled' => (int) $ecb_option( 'chatbot_theme_shadow_enabled', 1 ) === 1,
						'userBubble' => $ecb_option( 'chatbot_theme_user_bubble', '#111827' ),
						'botBubble'  => $ecb_option( 'chatbot_theme_bot_bubble', '#ffffff' ),
						'radius'     => (int) $ecb_option( 'chatbot_theme_radius', 22 ),
						'bubbleShape' => $ecb_option( 'chatbot_theme_bubble_shape', 'rounded' ),
						'position'   => $ecb_option( 'chatbot_theme_position', 'right' ),
						'bubbleSize' => (int) $ecb_option( 'chatbot_bubble_size', 58 ),
					],
					'branding' => $branding_config,
					'posicion' => [
						'modo'   => $ecb_option( 'chatbot_theme_position', 'right' ),
						'bottom' => (int) $ecb_option( 'chatbot_position_bottom', 22 ),
						'left'   => $ecb_option( 'chatbot_position_left', '' ),
						'right'  => $ecb_option( 'chatbot_position_right', '' ),
					],
					'inlineFeatures' => [
						'sidebar'       => (int) $ecb_option( 'chatbot_inline_sidebar_enabled', 0 ) === 1,
						'fixedComposer' => (int) $ecb_option( 'chatbot_inline_fixed_composer_enabled', 0 ) === 1,
						'fullpage'      => (int) $ecb_option( 'chatbot_inline_fullpage_enabled', 0 ) === 1,
					],
					'bubbleImageUrl' => estandar_chatbot_frontend_media_url( $ecb_option( 'chatbot_bubble_image_url', '' ) ),
				'bubbleTooltip'   => __( '¿Necesitas ayuda?', 'estandar-chatbot' ),
				'leadConfirmMsg'  => __( '¡Gracias! Hemos recibido tu información. Te contactaremos pronto.', 'estandar-chatbot' ),
				'errorFriendly'   => [
					'ai_fail'    => __( 'No pude procesar tu mensaje en este momento. ¿Quieres intentar de nuevo?', 'estandar-chatbot' ),
					'lead_fail'  => __( 'No pude registrar tus datos. ¿Quieres intentarlo nuevamente?', 'estandar-chatbot' ),
					'offline'    => __( 'En este momento no tengo conexión. Puedes intentar en unos minutos o usar los botones disponibles.', 'estandar-chatbot' ),
				],
					'flowEnabled'     => (int) $ecb_option( 'chatbot_flow_enabled', 0 ) === 1,
					'flowJson'        => (string) $ecb_option( 'chatbot_flow_json', '' ),
					'whatsappRedirectUrl' => rest_url( 'estandar-leads/v1/whatsapp-redirect' ),
					'operationalHours' => estandar_chatbot_get_operational_hours_state(),
			]
		);
	}

/* ======================================================
 * VISIBILIDAD CONDICIONAL POR PÁGINA
 * ====================================================== */

add_shortcode( 'estandar_chatbot', 'estandar_chatbot_shortcode' );
function estandar_chatbot_shortcode( $atts = [] ) {

	if ( ! estandar_chatbot_is_visible_for_current_user() ) {
		return '';
	}

	$atts = shortcode_atts(
		[
			'mode'    => 'inline',
			'height'  => 680,
			'context' => '',
		],
		(array) $atts,
		'estandar_chatbot'
	);

	$mode = sanitize_key( (string) $atts['mode'] );
	if ( ! in_array( $mode, [ 'inline', 'widget' ], true ) ) {
		$mode = 'inline';
	}

	$height = (int) $atts['height'];
	$height = min( 1100, max( 420, $height ) );

	$context_slug = estandar_chatbot_contexts_validate_slug( (string) $atts['context'] );

	return sprintf(
		'<div class="estandar-chatbot-shortcode" data-ecb-shortcode="1" data-mode="%1$s" data-height="%2$d"%3$s></div>',
		esc_attr( $mode ),
		$height,
		$context_slug !== '' ? ' data-context-slug="' . esc_attr( $context_slug ) . '"' : ''
	);
}

/* ======================================================
 * AJAX / ENDPOINTS
 * ====================================================== */

add_action( 'wp_ajax_estandar_chatbot_ai', 'estandar_chatbot_ai_endpoint' );
add_action( 'wp_ajax_nopriv_estandar_chatbot_ai', 'estandar_chatbot_ai_endpoint' );
add_action( 'wp_ajax_estandar_chatbot_get_conversation', 'estandar_chatbot_get_conversation_endpoint' );
add_action( 'wp_ajax_nopriv_estandar_chatbot_get_conversation', 'estandar_chatbot_get_conversation_endpoint' );
add_action( 'wp_ajax_estandar_chatbot_runtime_nonce', 'estandar_chatbot_runtime_nonce_endpoint' );
add_action( 'wp_ajax_nopriv_estandar_chatbot_runtime_nonce', 'estandar_chatbot_runtime_nonce_endpoint' );

function estandar_chatbot_ai_endpoint() {
	estandar_chatbot_handle_ai_request();
}

function estandar_chatbot_get_conversation_endpoint() {
	estandar_chatbot_verify_nonce_or_die();

	// P7 — rate limit: 30 req/min per IP
	$meta    = estandar_chatbot_get_request_meta();
	$ip      = $meta['ip'] ?: 'unknown';
	$rl_key  = 'ecb_rl_conv_' . md5( $ip );
	$rl_hits = (int) get_transient( $rl_key );
	if ( $rl_hits >= 30 ) {
		wp_send_json_error( [ 'message' => 'Demasiadas solicitudes.' ], 429 );
	}
	set_transient( $rl_key, $rl_hits + 1, 60 );

	$session_key = isset( $_POST['session_id'] ) ? estandar_chatbot_normalize_session_key( wp_unslash( $_POST['session_id'] ) ) : '';
	$channel = isset( $_POST['channel'] ) ? estandar_chatbot_normalize_channel( wp_unslash( $_POST['channel'] ) ) : 'web';
	if ( $session_key === '' ) {
		wp_send_json_success( [ 'messages' => [] ] );
	}

	// P7 — límite 20 mensajes, solo role + content (sin metadata interna)
	$raw = function_exists( 'estandar_chatbot_get_conversation_messages' )
		? estandar_chatbot_get_conversation_messages( $session_key, $channel, 20 )
		: [];

	$messages = [];
	foreach ( $raw as $item ) {
		if ( empty( $item['role'] ) || ! isset( $item['content'] ) ) {
			continue;
		}
		$messages[] = [
			'role'    => (string) $item['role'],
			'content' => (string) $item['content'],
		];
	}

	wp_send_json_success( [ 'messages' => $messages ] );
}

function estandar_chatbot_runtime_nonce_endpoint() {
	wp_send_json_success( estandar_chatbot_get_runtime_nonce_payload() );
}
