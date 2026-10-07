<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registrar tabs adicionales del admin (PRO / extensiones)
 */

add_filter( 'estandar_chatbot_admin_tabs', function ( $tabs ) {
	if ( isset( $tabs['herramientas'] ) ) {
		$tabs['herramientas']['label'] = __( 'Herramientas', 'estandar-chatbot' );
		$tabs['herramientas']['group'] = $tabs['herramientas']['group'] ?? 'sistema';
	}
	if ( isset( $tabs['licencia'] ) ) {
		$tabs['licencia']['label'] = __( 'Licencia', 'estandar-chatbot' );
		$tabs['licencia']['group'] = $tabs['licencia']['group'] ?? 'sistema';
	}
	return $tabs;
});
