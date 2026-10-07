<?php
/**
 * Módulo SVG Upload - Estandar KickOff
 * Permite subir archivos SVG de forma segura (sanitización anti-XSS).
 *
 * @package Estandar_KickOff
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-svg-upload.php';

Estandar_KickOff_SVG_Upload::get_instance();

return [
	'slug'   => 'svg-upload',
	'label'  => __( 'Subir SVG', 'estandar-kickoff' ),
	'icon'   => '🖼️',
	'order'  => 20,
	'render' => [ Estandar_KickOff_SVG_Upload::class, 'render_tab' ],
];
