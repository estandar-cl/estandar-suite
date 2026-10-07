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
 *   COOLING   → interés previo que se enfría por tiempo SIN GESTIÓN ACCIONABLE.
 *
 * La transición a COOLING es la ÚNICA dependiente del tiempo y solo se
 * evalúa cuando ya hubo interés (nunca un lead recién capturado).
 *
 * COOLING usa operator_actionable_hours del TimeContext, NO chronological_age.
 * El tiempo deferred_by_policy NUNCA contribuye al cooling.
 * Engagement reciente del prospecto suprime el cooling.
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
	 * Aplica el decaimiento a COOLING usando tiempo cronológico simple
	 * (backward-compat). Preferir evaluate_cooling() con TimeContext.
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

	/**
	 * Evalúa el enfriamiento con TimeContext multi-dimensional (IEE-7).
	 *
	 * REGLAS (orden de evaluación):
	 *
	 *   1. CAPTURE nunca es COOLING.
	 *   2. Contacto registrado → no hay cooling.
	 *   3. El lead está diferido (isDeferred) → no hay cooling: el operador
	 *      aún no ha tenido oportunidad de actuar.
	 *   4. Engagement reciente del prospecto (dentro de la ventana configurable)
	 *      → suprime cooling independientemente del tiempo accionable.
	 *      El engagement dice que el interés NO se ha deteriorado.
	 *   5. operator_actionable_hours >= cooling_hours → COOLING.
	 *
	 * El tiempo deferred_by_policy NUNCA contribuye al cooling (regla 3).
	 *
	 * @param string     $current             nivel calculado por from_score().
	 * @param TimeContext $time               contexto temporal multi-dimensional.
	 * @param bool       $contact_registered  contacto comercial registrado.
	 * @param int        $cooling_hours       umbral configurable (default 48).
	 * @param int        $engagement_window_hours ventana de engagement reciente (default 6).
	 * @return string
	 */
	public static function evaluate_cooling(
		string $current,
		TimeContext $time,
		bool $contact_registered,
		int $cooling_hours = 48,
		int $engagement_window_hours = 6
	): string {
		$current = in_array( $current, self::LEVELS, true ) ? $current : 'CAPTURE';

		// Regla 1: CAPTURE nunca enfría.
		if ( $current === 'CAPTURE' ) {
			return $current;
		}

		// Regla 2: contacto registrado suprime cooling.
		if ( $contact_registered ) {
			return $current;
		}

		// Regla 3: lead diferido por política → el operador no ha tenido
		// oportunidad. El tiempo deferred NO debe enfriar.
		if ( $time->isDeferred() ) {
			return $current;
		}

		// Regla 4: engagement reciente del prospecto suprime cooling.
		// Si el prospecto interactuó hace < N horas, su interés no se ha
		// deteriorado independientemente de cuánto tiempo pasó desde la captura.
		if ( $time->hasRecentEngagement( $engagement_window_hours ) ) {
			return $current;
		}

		// Regla 5: solo operator_actionable_hours gobierna el cooling.
		$actionable = $time->operatorActionableHours();
		if ( $actionable >= max( 1, $cooling_hours ) ) {
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
