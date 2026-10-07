<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-child-theme.php';

Estandar_KickOff_Child_Theme::get_instance();

return [
	'slug'   => 'child-theme',
	'label'  => __( 'Child Theme', 'estandar-kickoff' ),
	'icon'   => '🎨',
	'order'  => 35,
	'render' => [ Estandar_KickOff_Child_Theme::class, 'render_tab' ],
];
