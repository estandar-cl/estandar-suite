<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Notifications_Health {

	public static function status(): array {
		return array(
			'engine'    => Estandar_Notifications_Engine::initialized() ? 'initialized' : 'loaded',
			'registry'  => 'ready',
			'adapters'  => Estandar_Notifications_Registry::count(),
			'configured'=> 0,
			'enabled'   => 0,
		);
	}
}
