<?php

namespace Estandar\Core\Interest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * InterestState — estados de evolución del interés comercial.
 *
 * INTEREST_EVOLUTION_ENGINE_V1 (IEE-1, ADR-0044).
 *
 * ORTOGONAL al Lead Lifecycle (estandar-flow / es_commercial_journey_events).
 * El Lifecycle dice en qué paso operativo está el pipeline (NEW→…→CUSTOMER);
 * este motor dice cómo evoluciona el INTERÉS comercial del prospecto.
 *
 *   CAPTURE   → recién recibido, sin evidencia adicional.
 *   REVISITED → volvió (sesión, formulario, canal, sin formulario).
 *   ENGAGED   → interacción activa (email abierto, clic, WhatsApp, chatbot).
 *   HIGH      → máxima evidencia de interés (cotización, múltiples señales).
 *   COOLING   → interés previo que se enfría por tiempo sin gestión.
 *
 * La transición a COOLING es la ÚNICA dependiente del tiempo y solo se
 * evalúa cuando ya hubo interés (nunca un lead recién capturado).
 */
final class InterestState
{
	const VERSION = 'IEE_V1';

	/** Niveles canónicos en orden ascendente de interés. */
	const LEVELS = array(
		'CAPTURE',
		'REVISITED',
		'ENGAGED',
		'HIGH',
	);

	/** Umbrales de score acumulado para cada nivel (inclusive). */
	const THRESHOLDS = array(
		'CAPTURE'   => 0,
		'REVISITED' => 10,
		'ENGAGED'   => 30,
		'HIGH'      => 60,
	);

	/**
	 * Nivel de interés a partir de un score acumulado (0-100).
	 *
	 * @param int $score
	 * @return string uno de self::LEVELS
	 */
	public static function from_score( int $score ): string
	{
		$score = max( 0, min( 100, $score ) );
		if ( $score >= self::THRESHOLDS['HIGH'] ) {
			return 'HIGH';
		}
		if ( $score >= self::THRESHOLDS['ENGAGED'] ) {
			return 'ENGAGED';
		}
		if ( $score >= self::THRESHOLDS['REVISITED'] ) {
			return 'REVISITED';
		}
		return 'CAPTURE';
	}

	/**
	 * Aplica el decaimiento a COOLING.
	 *
	 * Solo pasa a COOLING si:
	 *   - hubo interés previo (nivel >= REVISITED), y
	 *   - pasó el umbral de horas sin gestión registrada, y
	 *   - no hay contacto comercial registrado.
	 *
	 * Un lead recién capturado (CAPTURE) NUNCA es COOLING: la captura no es
	 * atraso comercial.
	 *
	 * @param string $current      nivel calculado por from_score().
	 * @param int    $elapsed_hours horas desde la última evidencia de interés.
	 * @param bool   $contact_registered  contacto comercial registrado.
	 * @param int    $cooling_hours       umbral configurable (default 48).
	 * @return string
	 */
	public static function apply_decay( string $current, int $elapsed_hours, bool $contact_registered, int $cooling_hours = 48 ): string
	{
		$current = in_array( $current, self::LEVELS, true ) ? $current : 'CAPTURE';
		if ( $contact_registered || $current === 'CAPTURE' ) {
			return $current;
		}
		$index = array_search( $current, self::LEVELS, true );
		if ( $index === false || $index < 1 ) {
			return $current; // REVISITED o superior (no CAPTURE).
		}
		if ( $elapsed_hours >= max( 1, $cooling_hours ) ) {
			return 'COOLING';
		}
		return $current;
	}

	/** Etiqueta legible por nivel. */
	public static function label( string $level ): string
	{
		$labels = array(
			'CAPTURE'   => 'Recién capturado',
			'REVISITED' => 'Volvió a interesarse',
			'ENGAGED'   => 'Interacción activa',
			'HIGH'      => 'Alto interés',
			'COOLING'   => 'Interés enfriándose',
		);
		return isset( $labels[ $level ] ) ? $labels[ $level ] : $level;
	}
}
