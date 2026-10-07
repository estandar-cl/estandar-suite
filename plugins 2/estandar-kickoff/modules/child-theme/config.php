<?php
if ( ! defined( 'ABSPATH' ) ) exit;

return [
	'slug'        => 'child-theme',
	'label'       => __( 'Child Theme', 'estandar-kickoff' ),
	'description' => __( 'Genera un child theme mínimo para el tema activo, sin copiar archivos del padre.', 'estandar-kickoff' ),
	'icon'        => '🎨',
	'order'       => 35,
];
