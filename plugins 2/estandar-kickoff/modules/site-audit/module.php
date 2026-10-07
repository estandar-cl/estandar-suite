<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-site-audit.php';

Estandar_KickOff_Site_Audit::get_instance();

return [
	'slug'   => 'site-audit',
	'label'  => __( 'Site Audit', 'estandar-kickoff' ),
	'icon'   => '🔍',
	'order'  => 90,
	'render' => [ Estandar_KickOff_Site_Audit::class, 'render_tab' ],
];
