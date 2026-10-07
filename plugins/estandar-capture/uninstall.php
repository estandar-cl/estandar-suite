<?php
/**
 * CHATBOT_UNINSTALL_V1 (2026-09-27): desinstalación de estandar-capture.
 *
 * Solo borra datos si el administrador activó explícitamente
 * `uninstall_wipe` en Chatbot → Sistema (`estandar_suite_capture_chatbot_settings`).
 * Por defecto NO borra nada (los datos de chats/leads se conservan).
 *
 * @package Estandar\Capture
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'estandar_suite_capture_chatbot_settings', array() );
$wipe     = is_array( $settings ) && ! empty( $settings['uninstall_wipe'] );

if ( ! $wipe ) {
	return;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'estandar_capture_chat_messages',
	$wpdb->prefix . 'estandar_capture_chat_logs',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$options = array(
	'estandar_capture_chat_messages_schema',
	'estandar_capture_chat_logs_schema',
	'estandar_suite_capture_chatbot_settings',
	'estandar_chatbot_branding_migrated',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
