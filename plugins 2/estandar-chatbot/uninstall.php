<?php
/**
 * Uninstall — Estandar Chatbot IA
 * Se ejecuta al eliminar el plugin (no al desactivar).
 * Limpia opciones, transients y tablas propias.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/* ======================================================
 * 1. OPCIONES (wp_options)
 * ====================================================== */

$options = [
	'chatbot_activar',
	'chatbot_nombre',
	'chatbot_idioma',
	'chatbot_paginas_visibles',
	'chatbot_mensaje_ya_registrado',
	'chatbot_lead_fields',
	'chatbot_openai_api_key',
	'chatbot_openai_model',
	'chatbot_openai_max_tokens',
	'chatbot_openai_temperature',
	'chatbot_system_prompt',
	'chatbot_notify_email_enabled',
	'chatbot_notify_email_to',
	'chatbot_notify_email_subject',
	'chatbot_logs_retention_days',
	'chatbot_log_defer',
	'chatbot_flow_enabled',
	'chatbot_flow_json',
	'chatbot_lead_capture_enabled',
	'chatbot_human_escalation_enabled',
	'chatbot_human_escalation_text',
	'chatbot_hide_menu_on_click',
	'chatbot_theme_accent',
	'chatbot_theme_user_bubble',
	'chatbot_theme_bot_bubble',
	'chatbot_theme_radius',
	'chatbot_theme_bubble_shape',
	'chatbot_theme_position',
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
	'chatbot_botones_iniciales',
	'chatbot_context_txt_path',
	'chatbot_use_pinecone',
	'chatbot_leads_columns',
	'chatbot_leads_migrated_to_db',
	'chatbot_logs_migrated_to_db',
	'estandar_chatbot_db_version',
	'estandar_chatbot_inline_page_id',
	'estandar_chatbot_inline_bootstrap_done',
	'estandar_chatbot_license',
	'ecb_logs_list_cache_gen',
	'ecb_leads_list_cache_gen',
];

foreach ( $options as $opt ) {
	delete_option( $opt );
}

/* ======================================================
 * 2. TRANSIENTS (rate limit, caché de listas)
 * ====================================================== */

$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ecb_%' OR option_name LIKE '_transient_timeout_ecb_%'"
);

/* ======================================================
 * 3. TABLAS PROPIAS (solo si el usuario lo configuró)
 * ====================================================== */

$wipe_on_uninstall = (int) get_option( 'ecb_uninstall_wipe_data', 0 );
if ( $wipe_on_uninstall === 1 ) {
	$tables = [
		$wpdb->prefix . 'ecb_leads',
		$wpdb->prefix . 'ecb_logs',
		$wpdb->prefix . 'ecb_conversations',
		$wpdb->prefix . 'ecb_events',
	];
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
	}
}

// Eliminar la opción de preferencia
delete_option( 'ecb_uninstall_wipe_data' );
