<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-clean-head.php';

Estandar_KickOff_Clean_Head::get_instance();

return [
	'slug'   => 'clean-head',
	'label'  => __( 'Limpieza Head', 'estandar-kickoff' ),
	'icon'   => '🧹',
	'order'  => 65,
	'render' => [ Estandar_KickOff_Clean_Head::class, 'render_tab' ],
];
