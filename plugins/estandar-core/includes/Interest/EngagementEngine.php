<?php

namespace Estandar\Core\Interest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EngagementEngine — motor de compromiso configurable.
 *
 * INTEREST_EVOLUTION_ENGINE_V1 (IEE-6, ADR-0044).
 *
 * Decide CUÁNDO ejecutar acciones automáticas de compromiso, según reglas
 * configurables por instalación/proyecto. NUNCA contiene conocimiento del
 * negocio de un cliente concreto (no sabe qué es Empresa Demo o AK): solo
 * interpreta la configuración que cada proyecto define.
 *
 * Configuración (option `estandar_suite_engagement_rules` o filtro
 * `estandar_suite_engagement_rules`):
 *   [
 *     'default' => [
 *       'enabled'      => true,
 *       'interval_hours' => 24,           // evaluar cada X horas
 *       'min_interest'  => 'REVISITED',   // nivel mínimo para actuar
 *       'conditions'   => [
 *           ['field' => 'contact_registered', 'op' => 'is_false'],
 *       ],
 *       'actions'      => [
 *           ['type' => 'email', 'template' => 'recontact', 'channel' => 'email'],
 *           ['type' => 'task', 'assignee' => 'operator'],
 *       ],
 *     ],
 *     'demo' => [ 'actions' => [ ['type' => 'visit', 'template' => 'casa_piloto'] ] ],
 *     'ak-contadores' => [ 'actions' => [ ['type' => 'meeting', 'template' => 'agendar_reunion'] ] ],
 *   ]
 *
 * Salida: lista de acciones propuestas (NUNCA las ejecuta; emite eventos).
 * La ejecución real la realiza el consumidor (workflow de la Suite).
 */
final class EngagementEngine
{
	const VERSION = 'IEE_V1';

	const OPTION = 'estandar_suite_engagement_rules';

	/**
	 * Reglas configuradas (option + filtro, por slug de proyecto).
	 *
	 * @return array
	 */
	public static function rules(): array
	{
		$default = array(
			'default' => array(
				'enabled'        => true,
				'interval_hours' => 24,
				'min_interest'   => 'REVISITED',
				'conditions'     => array(),
				'actions'        => array(),
			),
		);
		$stored = array();
		if ( function_exists( 'get_option' ) ) {
			$option = get_option( self::OPTION, null );
			if ( is_array( $option ) ) {
				$stored = $option;
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'estandar_suite_engagement_rules', $stored );
			if ( is_array( $filtered ) ) {
				$stored = $filtered;
			}
		}

		// Merge por perfil: cada proyecto hereda los defaults (default) y puede
		// sobrescribir cualquier campo (enabled, interval, min_interest,
		// conditions, actions). array_replace_recursive simple no propaga los
		// defaults dentro de perfiles con claves parciales.
		$base = $default['default'];
		$merged = array();
		foreach ( $stored as $slug => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}
			$merged[ $slug ] = array_replace_recursive( $base, $profile );
		}
		if ( empty( $merged['default'] ) ) {
			$merged['default'] = $base;
		}
		return $merged;
	}

	/**
	 * Evalúa las reglas de compromiso para una decisión de interés.
	 *
	 * @param string $project_slug     slug del proyecto ('' → 'default').
	 * @param array  $decision         InterestDecision (InterestEngine::decide()).
	 * @param array  $state            estado extra (contact_registered, elapsed_hours, business_hours…).
	 * @return array lista de acciones propuestas.
	 */
	public static function evaluate( string $project_slug, array $decision, array $state = array() ): array
	{
		$rules = self::rules();
		$profile = $rules[ $project_slug ] ?? $rules['default'] ?? array();
		if ( empty( $profile['enabled'] ) ) {
			return array();
		}

		$interest_level = (string) ( $decision['interest_level'] ?? 'CAPTURE' );
		$min_interest = (string) ( $profile['min_interest'] ?? 'REVISITED' );
		if ( ! self::level_reaches( $interest_level, $min_interest ) ) {
			return array();
		}

		// Condiciones configurables.
		foreach ( (array) ( $profile['conditions'] ?? array() ) as $condition ) {
			if ( ! self::condition_met( $condition, $state, $decision ) ) {
				return array();
			}
		}

		// Intervalo: solo actuar si pasaron X horas desde la última acción.
		$interval = max( 0, (int) ( $profile['interval_hours'] ?? 0 ) );
		if ( $interval > 0 ) {
			$elapsed = max( 0, (int) ( $state['elapsed_hours'] ?? 0 ) );
			if ( $elapsed < $interval ) {
				return array();
			}
		}

		$actions = array();
		foreach ( (array) ( $profile['actions'] ?? array() ) as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$actions[] = array(
				'type'     => (string) ( $action['type'] ?? '' ),
				'template' => (string) ( $action['template'] ?? '' ),
				'channel'  => (string) ( $action['channel'] ?? '' ),
				'assignee' => (string) ( $action['assignee'] ?? '' ),
			);
		}
		return $actions;
	}

	/** ¿Un nivel alcanza (o supera) otro? Orden canónico CAPTURE<REVISITED<ENGAGED<HIGH. */
	private static function level_reaches( string $level, string $min ): bool
	{
		$order = array( 'CAPTURE' => 0, 'REVISITED' => 1, 'ENGAGED' => 2, 'HIGH' => 3, 'COOLING' => 4 );
		$level_i = $order[ $level ] ?? 0;
		$min_i = $order[ $min ] ?? 1;
		return $level_i >= $min_i;
	}

	/** Evalúa una condición configurable. */
	private static function condition_met( array $condition, array $state, array $decision ): bool
	{
		$field = (string) ( $condition['field'] ?? '' );
		$op = (string) ( $condition['op'] ?? 'is_true' );
		$value = $condition['value'] ?? null;

		$actual = $state[ $field ] ?? $decision[ $field ] ?? null;
		if ( $actual === null && strpos( $field, 'management.' ) === 0 ) {
			$actual = $decision['management'][ substr( $field, 11 ) ] ?? null;
		}

		switch ( $op ) {
			case 'is_true':
				return (bool) $actual === true;
			case 'is_false':
				return (bool) $actual === false;
			case 'equals':
				return (string) $actual === (string) $value;
			case 'not_equals':
				return (string) $actual !== (string) $value;
			case 'gt':
				return (float) $actual > (float) $value;
			case 'lt':
				return (float) $actual < (float) $value;
		}
		return true;
	}
}
