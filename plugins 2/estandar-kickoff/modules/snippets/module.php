<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-snippets.php';

Estandar_KickOff_Snippets::get_instance();

return [
	'slug'   => 'snippets',
	'label'  => __( 'Snippets', 'estandar-kickoff' ),
	'icon'   => '📝',
	'order'  => 70,
	'render' => [ Estandar_KickOff_Snippets::class, 'render_tab' ],
];
