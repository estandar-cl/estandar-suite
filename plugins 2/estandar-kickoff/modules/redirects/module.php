<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-redirects.php';

Estandar_KickOff_Redirects::get_instance();

return [
	'slug'   => 'redirects',
	'label'  => __( 'Redirecciones', 'estandar-kickoff' ),
	'icon'   => '↪️',
	'order'  => 90,
	'render' => [ Estandar_KickOff_Redirects::class, 'render_tab' ],
];
