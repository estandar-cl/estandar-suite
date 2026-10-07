<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-taxonomy-manager.php';

Estandar_KickOff_Taxonomy_Manager::get_instance();

return [
	'slug'   => 'taxonomy-manager',
	'label'  => __( 'Taxonomy Manager', 'estandar-kickoff' ),
	'icon'   => '🏷️',
	'order'  => 85,
	'render' => [ Estandar_KickOff_Taxonomy_Manager::class, 'render_tab' ],
];
