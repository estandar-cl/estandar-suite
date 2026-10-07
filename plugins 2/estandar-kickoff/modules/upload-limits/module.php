<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-upload-limits.php';

Estandar_KickOff_Upload_Limits::get_instance();

return [
	'slug'   => 'upload-limits',
	'label'  => __( 'Límites de subida', 'estandar-kickoff' ),
	'icon'   => '📤',
	'order'  => 75,
	'render' => [ Estandar_KickOff_Upload_Limits::class, 'render_tab' ],
];
