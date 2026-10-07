<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Notifications_Registry {

	/** @var array<string,Estandar_Notification> */
	private static $adapters = array();

	public static function register( Estandar_Notification $adapter ): void {
		$id = sanitize_key( $adapter->id() );
		if ( $id === '' ) {
			return;
		}

		self::$adapters[ $id ] = $adapter;
	}

	/** @return Estandar_Notification[] */
	public static function all(): array {
		return array_values( self::$adapters );
	}

	public static function count(): int {
		return count( self::$adapters );
	}
}
