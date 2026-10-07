<?php
if ( ! defined( 'ABSPATH' ) ) exit;

return [
	'slug'        => 'clean-head',
	'label'       => __( 'Limpieza Head', 'estandar-kickoff' ),
	'description' => __( 'Quitar meta versión WP, oEmbed, RSD, emojis y más del head.', 'estandar-kickoff' ),
	'icon'        => '🧹',
	'order'       => 65,
];
