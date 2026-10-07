<?php
/**
 * Admin locale and text domain loading.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_I18n {

	public static function init() {
		add_filter( 'plugin_locale', array( __CLASS__, 'filter_locale' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );
	}

	public static function filter_locale( $locale, $domain ) {
		if ( 'estandar-analytics' !== $domain || ! is_admin() ) {
			return $locale;
		}

		$chosen = EA_Settings::get()['admin_locale'] ?? 'es_ES';
		$map    = array(
			'es'    => 'es_ES',
			'es_ES' => 'es_ES',
			'en'    => 'en_US',
			'en_US' => 'en_US',
		);

		return $map[ $chosen ] ?? 'es_ES';
	}

	public static function load_textdomain() {
		load_plugin_textdomain(
			'estandar-analytics',
			false,
			dirname( plugin_basename( EA_PATH . 'estandar-analytics.php' ) ) . '/languages'
		);

		$locale = apply_filters( 'plugin_locale', determine_locale(), 'estandar-analytics' );
		$mofile = EA_PATH . 'languages/estandar-analytics-' . $locale . '.mo';

		if ( is_readable( $mofile ) ) {
			load_textdomain( 'estandar-analytics', $mofile );
		}
	}

	public static function available_locales() {
		return array(
			'es_ES' => __( 'Español', 'estandar-analytics' ),
			'en_US' => __( 'English', 'estandar-analytics' ),
		);
	}
}
