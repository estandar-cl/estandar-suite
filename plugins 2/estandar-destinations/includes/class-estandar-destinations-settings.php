<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings bootstrap for estandar-destinations.
 *
 * Central registry for adapter settings pages.
 * Adapters register settings pages via add_filter('estandar_destinations_settings_pages', ...).
 */
final class Estandar_Destinations_Settings {

	/**
	 * Returns all registered settings pages from adapters.
	 *
	 * @return array<array{id:string,label:string,callback:callable}>
	 */
	public static function pages(): array {
		return (array) apply_filters( 'estandar_destinations_settings_pages', array() );
	}

	/**
	 * True when at least one settings page is registered.
	 */
	public static function has_pages(): bool {
		return count( self::pages() ) > 0;
	}
}
