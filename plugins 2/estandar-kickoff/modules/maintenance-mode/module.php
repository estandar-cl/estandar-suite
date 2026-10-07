<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-maintenance-mode.php';

Estandar_KickOff_Maintenance_Mode::get_instance();

return [
	'slug'   => 'maintenance-mode',
	'label'  => __( 'Modo mantenimiento', 'estandar-kickoff' ),
	'icon'   => '🔧',
	'order'  => 70,
	'render' => [ Estandar_KickOff_Maintenance_Mode::class, 'render_tab' ],
];
