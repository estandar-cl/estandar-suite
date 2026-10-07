<?php
/**
 * Dependent Select — Project Registry integration for Estandar Forms.
 *
 * Provides:
 *  - AJAX endpoint ef_get_streets: retorna calles de un proyecto del registry.
 *  - has_dependent_fields(): detecta si un fields_json contiene project_select
 *    o dependent_select, para que el shortcode sepa si encolar el JS.
 *
 * Tipos de campo soportados:
 *  - project_select  : select poblado con proyectos del Project Registry de Core.
 *  - dependent_select: select vacío en load; JS lo puebla con calles al cambiar proyecto.
 *
 * @package Estandar_Forms
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Forms_Dependent_Select' ) ) :

class Estandar_Forms_Dependent_Select {

	// ---------------------------------------------------------------------------
	// Init
	// ---------------------------------------------------------------------------

	public static function init(): void {
		add_action( 'wp_ajax_ef_get_streets',        [ __CLASS__, 'ajax_get_streets' ] );
		add_action( 'wp_ajax_nopriv_ef_get_streets', [ __CLASS__, 'ajax_get_streets' ] );
	}

	// ---------------------------------------------------------------------------
	// AJAX: retorna calles para un project_id
	// ---------------------------------------------------------------------------

	public static function ajax_get_streets(): void {
		check_ajax_referer( 'ef_get_streets', 'nonce' );

		$project_id = isset( $_GET['project_id'] )
			? sanitize_key( (string) wp_unslash( $_GET['project_id'] ) )
			: '';

		if ( '' === $project_id ) {
			wp_send_json_success( [ 'streets' => [] ] );
		}

		$streets = function_exists( 'estandar_core_get_project_streets' )
			? estandar_core_get_project_streets( $project_id )
			: [];

		wp_send_json_success( [ 'streets' => array_values( (array) $streets ) ] );
	}

	// ---------------------------------------------------------------------------
	// Helpers para el shortcode
	// ---------------------------------------------------------------------------

	/**
	 * Detecta si un fields_json contiene project_select o dependent_select.
	 * Usado por el shortcode para encolar estandar-dependent-select.js.
	 *
	 * @param string $fields_json
	 * @return bool
	 */
	public static function has_dependent_fields( string $fields_json ): bool {
		if ( '' === $fields_json ) {
			return false;
		}
		// Búsqueda rápida antes de decodificar
		if (
			strpos( $fields_json, 'project_select' ) === false &&
			strpos( $fields_json, 'dependent_select' ) === false
		) {
			return false;
		}
		$fields = json_decode( $fields_json, true );
		if ( ! is_array( $fields ) ) {
			return false;
		}
		foreach ( $fields as $field ) {
			$t = $field['type'] ?? '';
			if ( 'project_select' === $t || 'dependent_select' === $t ) {
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------------------
	// Render helpers (llamados desde form-render.php)
	// ---------------------------------------------------------------------------

	/**
	 * Retorna proyectos del Core Project Registry para poblar un <select>.
	 *
	 * @return array<int, array{value:string, label:string}>
	 */
	public static function get_projects_for_select(): array {
		if ( function_exists( 'estandar_core_get_projects_for_select' ) ) {
			return (array) estandar_core_get_projects_for_select();
		}
		return [];
	}

	/**
	 * Retorna calles de un proyecto para pre-poblar un dependent_select
	 * al restaurar valores en un formulario con error.
	 *
	 * @param string $project_id
	 * @return string[]
	 */
	public static function get_streets_for_project( string $project_id ): array {
		if ( '' === $project_id ) {
			return [];
		}
		if ( function_exists( 'estandar_core_get_project_streets' ) ) {
			return (array) estandar_core_get_project_streets( $project_id );
		}
		return [];
	}
}

endif; // class_exists
