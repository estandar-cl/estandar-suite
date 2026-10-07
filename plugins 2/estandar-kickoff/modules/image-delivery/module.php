<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-image-delivery.php';

Estandar_KickOff_Image_Delivery::get_instance();

return [
	'slug'   => 'image-delivery',
	'label'  => __( 'Entrega de imágenes', 'estandar-kickoff' ),
	'icon'   => '🖼️',
	'order'  => 25,
	'render' => [ Estandar_KickOff_Image_Delivery::class, 'render_tab' ],
];
