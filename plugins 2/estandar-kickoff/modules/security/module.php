<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-security.php';

Estandar_KickOff_Security::get_instance();

return [
	'slug'   => 'security',
	'label'  => __( 'Seguridad', 'estandar-kickoff' ),
	'icon'   => '🔒',
	'order'  => 80,
	'render' => [ Estandar_KickOff_Security::class, 'render_tab' ],
];
