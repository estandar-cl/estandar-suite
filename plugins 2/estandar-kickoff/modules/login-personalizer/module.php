<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-login-personalizer.php';

Estandar_KickOff_Login_Personalizer::get_instance();

return [
	'slug'   => 'login-personalizer',
	'label'  => __( 'Login Personalizer', 'estandar-kickoff' ),
	'icon'   => '🔐',
	'order'  => 20,
	'render' => [ Estandar_KickOff_Login_Personalizer::class, 'render_tab' ],
];
