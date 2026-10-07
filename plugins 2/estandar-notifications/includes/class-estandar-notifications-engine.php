<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Notifications_Engine {

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		do_action( 'estandar_notifications_register' );
	}

	public static function initialized(): bool {
		return self::$initialized;
	}
}
