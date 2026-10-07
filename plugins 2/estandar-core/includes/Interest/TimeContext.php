<?php

namespace Estandar\Core\Interest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TimeContext — descomposición canónica del tiempo en dimensiones accionables.
 *
 * INTEREST_EVOLUTION_ENGINE_V1 (IEE-7, ADR-0044).
 *
 * La Suite opera en tiempo COMERCIAL, no cronológico. Una sola variable
 * `elapsed_hours` destruye información necesaria para decisiones seguras.
 *
 * DIMENSIONES INDEPENDIENTES (ninguna se deriva automáticamente de otra):
 *
 *   chronological_age_hours        — horas totales desde la captura del lead.
 *   operator_actionable_hours      — horas desde que el lead quedó accionable
 *                                    para el operador (después de que la política
 *                                    de horario operacional lo liberó).
 *   time_deferred_by_policy_hours  — horas que el lead estuvo bloqueado por
 *                                    política operacional (horario, SLA, reglas
 *                                    de envío configuradas).
 *   hours_since_last_engagement    — horas desde la última evidencia de interés
 *                                    del prospecto (CTA, visita, formulario,
 *                                    clic, chatbot…).
 *   hours_since_last_operator_action — horas desde la última acción registrada
 *                                    del operador (llamada, email, clasificación).
 *   hours_since_delivery           — horas desde que la notificación fue
 *                                    efectivamente entregada al operador.
 *
 * REGLAS:
 *
 *   - El tiempo deferred_by_policy NUNCA debe, por sí solo: enfriar el lead,
 *     penalizar al operador, generar recovery, incrementar antigüedad accionable,
 *     ni producir escalación.
 *   - operator_actionable_hours es la dimensión que gobierna cooling/recovery.
 *   - hours_since_last_engagement es la dimensión que puede SUPRIMIR cooling
 *     incluso cuando operator_actionable_hours es alto.
 *   - cooling representa deterioro REAL del interés, no antigüedad cronológica.
 *
 * Puro: sin WordPress, sin DB, testeable standalone.
 */
final class TimeContext
{
	const VERSION = 'IEE_V1';

	/** @var int */
	private $chronological_age_hours;

	/** @var int */
	private $operator_actionable_hours;

	/** @var int */
	private $time_deferred_by_policy_hours;

	/** @var int */
	private $hours_since_last_engagement;

	/** @var int */
	private $hours_since_last_operator_action;

	/** @var int */
	private $hours_since_delivery;

	/**
	 * @param array $dims {
	 *   chronological_age_hours        ?int  — default 0.
	 *   operator_actionable_hours      ?int  — default = chronological (si no se especifica).
	 *   time_deferred_by_policy_hours  ?int  — default 0.
	 *   hours_since_last_engagement    ?int  — default = chronological (si no se especifica).
	 *   hours_since_last_operator_action ?int — default = chronological (si no se especifica).
	 *   hours_since_delivery           ?int  — default 0.
	 * }
	 */
	public function __construct( array $dims = array() )
	{
		$chrono = max( 0, (int) ( $dims['chronological_age_hours'] ?? 0 ) );

		$this->chronological_age_hours = $chrono;
		$this->operator_actionable_hours = array_key_exists( 'operator_actionable_hours', $dims )
			? max( 0, (int) $dims['operator_actionable_hours'] )
			: $chrono;
		$this->time_deferred_by_policy_hours = max( 0, (int) ( $dims['time_deferred_by_policy_hours'] ?? 0 ) );
		$this->hours_since_last_engagement = array_key_exists( 'hours_since_last_engagement', $dims )
			? max( 0, (int) $dims['hours_since_last_engagement'] )
			: $chrono;
		$this->hours_since_last_operator_action = array_key_exists( 'hours_since_last_operator_action', $dims )
			? max( 0, (int) $dims['hours_since_last_operator_action'] )
			: $chrono;
		$this->hours_since_delivery = max( 0, (int) ( $dims['hours_since_delivery'] ?? 0 ) );
	}

	/**
	 * Factory desde un único elapsed_hours (backward-compat).
	 *
	 * Cuando el consumidor solo proporciona horas transcurridas genéricas,
	 * asumimos que NO hubo deferral (caso más seguro: todo el tiempo es
	 * accionable). La responsabilidad de descomponer es del adapter.
	 *
	 * @param int $elapsed
	 * @return self
	 */
	public static function from_elapsed( int $elapsed ): self
	{
		return new self( array(
			'chronological_age_hours' => $elapsed,
			'operator_actionable_hours' => $elapsed,
			'time_deferred_by_policy_hours' => 0,
			'hours_since_last_engagement' => $elapsed,
			'hours_since_last_operator_action' => $elapsed,
			'hours_since_delivery' => 0,
		) );
	}

	/**
	 * Horas totales desde la captura del lead (cronológico puro).
	 */
	public function chronologicalAgeHours(): int
	{
		return $this->chronological_age_hours;
	}

	/**
	 * Horas desde que el lead quedó accionable para el operador.
	 * Gobierna cooling y recovery.
	 */
	public function operatorActionableHours(): int
	{
		return $this->operator_actionable_hours;
	}

	/**
	 * Horas que el lead estuvo diferido por política.
	 * NUNCA debe usarse para cooling, penalización ni escalación.
	 */
	public function timeDeferredByPolicyHours(): int
	{
		return $this->time_deferred_by_policy_hours;
	}

	/**
	 * Horas desde la última evidencia de interés del prospecto.
	 * Puede suprimir cooling incluso con operator_actionable alto.
	 */
	public function hoursSinceLastEngagement(): int
	{
		return $this->hours_since_last_engagement;
	}

	/**
	 * Horas desde la última acción del operador.
	 */
	public function hoursSinceLastOperatorAction(): int
	{
		return $this->hours_since_last_operator_action;
	}

	/**
	 * Horas desde que la notificación fue entregada al operador.
	 */
	public function hoursSinceDelivery(): int
	{
		return $this->hours_since_delivery;
	}

	/**
	 * ¿El lead está actualmente en período de deferral activo?
	 * TRUE cuando operator_actionable_hours es 0 pero chronological > 0.
	 */
	public function isDeferred(): bool
	{
		return $this->chronological_age_hours > 0 && $this->operator_actionable_hours === 0;
	}

	/**
	 * ¿Hay engagement reciente del prospecto?
	 *
	 * @param int $window_hours ventana configurable (default 6h).
	 * @return bool
	 */
	public function hasRecentEngagement( int $window_hours = 6 ): bool
	{
		return $this->hours_since_last_engagement < max( 1, $window_hours );
	}

	/**
	 * Elapsed hours para backward-compat (usa operator_actionable como primario).
	 *
	 * @return int
	 */
	public function toElapsedCompat(): int
	{
		return $this->operator_actionable_hours;
	}

	/**
	 * Array exportable para serialización/contratos.
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		return array(
			'chronological_age_hours'         => $this->chronological_age_hours,
			'operator_actionable_hours'       => $this->operator_actionable_hours,
			'time_deferred_by_policy_hours'   => $this->time_deferred_by_policy_hours,
			'hours_since_last_engagement'     => $this->hours_since_last_engagement,
			'hours_since_last_operator_action' => $this->hours_since_last_operator_action,
			'hours_since_delivery'            => $this->hours_since_delivery,
			'is_deferred'                     => $this->isDeferred(),
		);
	}
}
