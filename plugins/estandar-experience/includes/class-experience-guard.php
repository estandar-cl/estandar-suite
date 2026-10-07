<?php
/**
 * Guard — política de coexistencia con el legacy estandar-experience-blocks.
 *
 * Patrón owner (como en la migración chatbot→capture) con flag de
 * habilitación seguro para la regla de dos:
 *
 * - El frontend v2 SOLO se activa con la opción
 *   `estandar_experience_frontend_enabled` = true (default OFF). Mientras
 *   esté OFF, el legacy sigue siendo el dueño en producción.
 * - Cuando el frontend v2 está activo y el legacy está presente, el legacy
 *   queda suprimido (owner). Un override explícito
 *   (`estandar_experience_legacy_owner` = 'legacy') fuerza al legacy.
 *
 * Lógica pura y testeable: las funciones reciben un contexto explícito y
 * `runtime_ctx()` lo deriva del runtime.
 *
 * @package Estandar\Experience
 */

namespace Estandar\Experience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Guard {

	const OVERRIDE_OPTION = 'estandar_experience_legacy_owner';
	const ENABLE_OPTION   = 'estandar_experience_frontend_enabled';

	/**
	 * Dueño del frontend de experiencia.
	 *
	 * @param array|null $ctx Contexto explícito para tests:
	 *   v2_active, legacy_active, override, enabled.
	 * @return string 'v2' | 'legacy' | 'none'
	 */
	public static function frontend_owner( ?array $ctx = null ): string {
		$ctx      = $ctx ?? self::runtime_ctx();
		$override = (string) ( $ctx['override'] ?? '' );
		$v2       = ! empty( $ctx['v2_active'] );
		$legacy   = ! empty( $ctx['legacy_active'] );

		// Override explícito.
		if ( 'legacy' === $override ) {
			return 'legacy';
		}
		if ( 'off' === $override ) {
			return 'none';
		}

		// v2 habilitado explícitamente.
		if ( $v2 && ! empty( $ctx['enabled'] ) ) {
			return 'v2';
		}

		// Sin legacy instalado, v2 es dueño por defecto (instalación limpia / FSE).
		if ( $v2 && ! $legacy ) {
			return 'v2';
		}

		// Con legacy presente, el legacy conserva la propiedad hasta habilitar v2.
		if ( $legacy ) {
			return 'legacy';
		}

		return 'none';
	}

	/**
	 * Contexto derivado del runtime real.
	 */
	public static function runtime_ctx(): array {
		return array(
			'v2_active'     => defined( 'ESTANDAR_EXPERIENCE_VERSION' ),
			'legacy_active' => defined( 'ESTANDAR_EFX_VERSION' ),
			'override'      => function_exists( 'get_option' )
				? (string) get_option( self::OVERRIDE_OPTION, '' )
				: '',
			'enabled'       => function_exists( 'get_option' )
				? (bool) get_option( self::ENABLE_OPTION, false )
				: false,
		);
	}

	/**
	 * ¿El frontend v2 debe activarse (owner v2)?
	 */
	public static function should_activate_v2_frontend( ?array $ctx = null ): bool {
		$ctx = $ctx ?? self::runtime_ctx();
		return self::frontend_owner( $ctx ) === 'v2';
	}

	/**
	 * ¿Este plugin suprime el frontend del legacy (patrón owner)?
	 */
	public static function should_suppress_legacy_frontend( ?array $ctx = null ): bool {
		$ctx = $ctx ?? self::runtime_ctx();
		return self::should_activate_v2_frontend( $ctx ) && ! empty( $ctx['legacy_active'] );
	}
}
