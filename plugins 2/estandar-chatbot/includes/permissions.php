<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Capacidades para escalado PRO futuro.
 * En Free, se controla por manage_options.
 * En PRO, se podría separar:
 * - ver_leads
 * - ver_logs
 * - configurar_chatbot
 * - exportar_datos
 */

add_action( 'init', 'estandar_chatbot_register_capabilities' );
function estandar_chatbot_register_capabilities() {

	// Por defecto se asigna al admin.
	$role = get_role( 'administrator' );
	if ( ! $role ) return;

	$caps = [
		'estandar_chatbot_manage',   // gestión completa
		'estandar_chatbot_manage_settings',
		'estandar_chatbot_view_leads',
		'estandar_chatbot_manage_leads',
		'estandar_chatbot_view_logs',
		'estandar_chatbot_manage_logs',
		'estandar_chatbot_manage_conversations',
		'estandar_chatbot_view_analytics',
		'estandar_chatbot_export',
	];

	foreach ( $caps as $cap ) {
		if ( ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
	}
}

/**
 * Helper de permiso (para usar en tabs si quieres granularidad).
 */
function estandar_chatbot_user_can_manage() {
	return current_user_can( 'estandar_chatbot_manage' ) || current_user_can( 'manage_options' );
}

function estandar_chatbot_user_can( $cap ) {
	if ( current_user_can( 'manage_options' ) || current_user_can( 'estandar_chatbot_manage' ) ) {
		return true;
	}
	return current_user_can( 'estandar_chatbot_' . sanitize_key( (string) $cap ) );
}

function estandar_chatbot_user_can_access_dashboard() {
	$caps = [
		'manage_settings',
		'view_leads',
		'manage_leads',
		'view_logs',
		'manage_logs',
		'manage_conversations',
		'view_analytics',
		'export',
	];

	foreach ( $caps as $cap ) {
		if ( estandar_chatbot_user_can( $cap ) ) {
			return true;
		}
	}

	return false;
}

function estandar_chatbot_user_can_view_tab( $tab ) {
	$map = [
		'inicio'          => [ 'manage_settings' ],
		'configuracion'   => [ 'manage_settings' ],
		'ia-contexto'     => [ 'manage_settings' ],
		'apariencia'      => [ 'manage_settings' ],
		'leads-captacion' => [ 'manage_settings' ],
		'botones'         => [ 'manage_settings' ],
		'integraciones'   => [ 'manage_settings' ],
		'herramientas'    => [ 'manage_settings' ],
		'licencia'        => [ 'manage_settings' ],
		'leads'           => [ 'view_leads', 'manage_leads' ],
		'conversaciones'  => [ 'manage_conversations' ],
		'logs'            => [ 'view_logs', 'manage_logs' ],
		'analitica'       => [ 'view_analytics' ],
		'sugerencias'     => [ 'view_analytics', 'manage_settings' ],
		'estado-operacional' => [ 'manage_settings' ],
	];

	$tab = sanitize_key( (string) $tab );
	$caps = isset( $map[ $tab ] ) ? (array) $map[ $tab ] : [ 'manage_settings' ];
	foreach ( $caps as $cap ) {
		if ( estandar_chatbot_user_can( $cap ) ) {
			return true;
		}
	}
	return false;
}

function estandar_chatbot_require_permission( $cap, $message = 'No autorizado.' ) {
	if ( estandar_chatbot_user_can( $cap ) ) {
		return;
	}
	wp_die( $message );
}
