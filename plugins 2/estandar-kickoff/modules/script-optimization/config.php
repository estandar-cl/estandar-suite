<?php
if ( ! defined( 'ABSPATH' ) ) exit;

return [
	'slug'        => 'script-optimization',
	'label'       => __( 'Scripts defer', 'estandar-kickoff' ),
	'description' => __( 'Aplica defer solo a scripts no críticos permitidos, sin cambiar orden ni dependencias.', 'estandar-kickoff' ),
	'icon'        => '⚡',
	'order'       => 30,
];
