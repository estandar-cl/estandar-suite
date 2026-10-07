<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-script-optimization.php';

Estandar_KickOff_Script_Optimization::get_instance();

return [
	'slug'   => 'script-optimization',
	'label'  => __( 'Scripts defer', 'estandar-kickoff' ),
	'icon'   => '⚡',
	'order'  => 30,
	'render' => [ Estandar_KickOff_Script_Optimization::class, 'render_tab' ],
];
