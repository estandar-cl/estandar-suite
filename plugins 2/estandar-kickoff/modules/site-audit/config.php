<?php
if ( ! defined( 'ABSPATH' ) ) exit;

return [
	'slug'        => 'site-audit',
	'label'       => __( 'Site Audit', 'estandar-kickoff' ),
	'description' => __( 'Snapshot técnico read-only del sitio: core, PHP, tema, plugins y riesgos.', 'estandar-kickoff' ),
	'icon'        => '🔍',
	'order'       => 90,
];
