<?php
/**
 * Helper de IA para Estandar KickOff.
 * Las credenciales se leen desde Core Credenciales Compartidas.
 *
 * @package Estandar_KickOff
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_AI_Api {

	/**
	 * Inicializa el helper y el filtro para módulos.
	 */
	public static function init() {
		add_filter( 'estandar_chatbot_openai_api_key', [ __CLASS__, 'filter_api_key' ], 5 );
	}

	/**
	 * Filtro: usa la clave compartida de Core cuando no hay clave previa.
	 */
	public static function filter_api_key( $key ) {
		if ( ! empty( $key ) ) {
			return $key;
		}
		return self::get_api_key();
	}

	/**
	 * Obtiene la API key de forma segura.
	 * Prioridad: Core Credenciales Compartidas > vacío.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		if ( function_exists( 'estandar_core_get_secret' ) ) {
			return estandar_core_get_secret( 'openai' );
		}
		return '';
	}

}
