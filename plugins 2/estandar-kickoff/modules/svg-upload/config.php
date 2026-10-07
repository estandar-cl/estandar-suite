<?php
/**
 * Config del módulo SVG Upload (sin cargar lógica).
 *
 * @package Estandar_KickOff
 */

if ( ! defined( 'ABSPATH' ) ) exit;

return [
	'slug'        => 'svg-upload',
	'label'       => __( 'Subir SVG', 'estandar-kickoff' ),
	'description' => __( 'Permitir subir archivos SVG de forma segura (sanitización anti-XSS).', 'estandar-kickoff' ),
	'icon'        => '🖼️',
	'order'       => 20,
];
