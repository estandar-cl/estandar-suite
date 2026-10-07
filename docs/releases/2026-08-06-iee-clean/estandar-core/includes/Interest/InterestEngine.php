<?php

namespace Estandar\Core\Interest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * InterestEngine — motor puro de evolución del interés comercial.
 *
 * INTEREST_EVOLUTION_ENGINE_V1 (IEE-1, ADR-0044).
 *
 * INDEPENDIENTE DE WORDPRESS: acepta únicamente objetos/arrays de dominio y
 * no depende de Email, Forms, UI, DB ni hooks de la Suite. Los adapters
 * (IEE-2) traducen datos del mundo real a este contrato.
 *
 * Responsabilidades:
 *   - interpretar la evolución del interés (no solo la existencia del lead);
 *   - acumular señales con pesos configurables;
 *   - derivar estado de interés (CAPTURE→…→HIGH/COOLING);
 *   - derivar prioridad y Next Best Action;
 *   - generar narratives de correo SIN lógica en los templates.
 *
 * Salida: InterestDecision (array versionado, ver DECISION_CONTRACT_V1).
 *
 * AUSENCIA = DEFAULT: si no hay evidencia de interés, el motor devuelve
 * `absent=true` y NO se altera el comportamiento del consumidor.
 */
final class InterestEngine
{
	const VERSION = 'IEE_V1';

	/** Umbral de horas sin gestión para decaimiento a COOLING (default). */
	const DEFAULT_COOLING_HOURS = 48;

	/** Umbral de score para clasificar "navegación profunda". */
	const PAGE_DEPTH_HIGH_MIN = 5;

	/**
	 * Decide el estado comercial de interés para un contexto dado.
	 *
	 * Contrato de entrada (arrays de dominio):
	 *   'signals'            array<int,string>  ids de señales activadas
	 *   'channel'            string             canal normalizado (opcional)
	 *   'submission_count'   int                nº de ingresos/formularios
	 *   'is_commercial_recontact' bool
	 *   'operator_assigned'  bool
	 *   'contact_registered' bool
	 *   'classification_done' bool
	 *   'quote_created'      bool
	 *   'elapsed_hours'      int                horas desde última evidencia
	 *   'cooling_hours'      int                umbral configurable (opcional)
	 *   'sla_violated'       bool
	 *
	 * @param array $context
	 * @return array InterestDecision (ver contracto abajo).
	 */
	public static function decide( array $context ): array
	{
		$signals = array();
		$raw = isset( $context['signals'] ) && is_array( $context['signals'] ) ? $context['signals'] : array();
		foreach ( $raw as $id ) {
			$id = (string) $id;
			if ( $id !== '' ) {
				$signals[] = $id;
			}
		}
		$signals = array_values( array_unique( $signals ) );

		// AUSENCIA = DEFAULT: sin señales ni formularios no hay evidencia.
		$submission_count = max( 0, (int) ( $context['submission_count'] ?? 0 ) );
		$absent = empty( $signals ) && $submission_count < 1;
		if ( $absent ) {
			return self::absent_decision();
		}

		// Peso total con multiplicador de canal.
		$channel = (string) ( $context['channel'] ?? '' );
		$resolved_signals = InterestSignals::resolved( $signals );
		$raw_score = 0;
		foreach ( $resolved_signals as $s ) {
			$raw_score += $s['weight'];
		}
		$score = (int) round( $raw_score * InterestChannel::weight( $channel ) );
		$score = max( 0, min( 100, $score ) );

		$interest_level = InterestState::from_score( $score );

		// Decaimiento a COOLING solo con interés previo y sin gestión.
		$contact_registered = ! empty( $context['contact_registered'] );
		$elapsed_hours = max( 0, (int) ( $context['elapsed_hours'] ?? 0 ) );
		$cooling_hours = isset( $context['cooling_hours'] ) ? max( 1, (int) $context['cooling_hours'] ) : self::DEFAULT_COOLING_HOURS;
		$interest_level = InterestState::apply_decay( $interest_level, $elapsed_hours, $contact_registered, $cooling_hours );

		$operator_assigned = ! empty( $context['operator_assigned'] );
		$classification_done = ! empty( $context['classification_done'] );
		$quote_created = ! empty( $context['quote_created'] ) || in_array( 'quote_created', $signals, true );
		$sla_violated = ! empty( $context['sla_violated'] );

		$priority = InterestPriority::derive( $interest_level, $contact_registered, $sla_violated );
		$nba = NextBestAction::derive( $interest_level, $operator_assigned, $contact_registered, $classification_done, $quote_created );

		return array(
			'version'        => self::VERSION,
			'absent'         => false,
			'interest_level' => $interest_level,
			'interest_label' => InterestState::label( $interest_level ),
			'score'          => $score,
			'raw_score'      => $raw_score,
			'channel'        => array(
				'channel' => $channel,
				'weight'  => InterestChannel::weight( $channel ),
				'label'   => InterestChannel::label( $channel ),
			),
			'signals'        => $resolved_signals,
			'signal_ids'     => $signals,
			'priority'       => array(
				'level' => $priority,
				'label' => InterestPriority::label( $priority ),
			),
			'next_best_action' => $nba,
			'capture'        => array(
				'submission_count'  => $submission_count,
				'is_recontact'      => ! empty( $context['is_commercial_recontact'] ),
			),
			'management'     => array(
				'operator_assigned'  => $operator_assigned,
				'contact_registered' => $contact_registered,
				'classification_done'=> $classification_done,
			),
			'explain'        => array(
				'reason' => self::explain( $signals, $score, $channel ),
			),
		);
	}

	/**
	 * Narratives de correo derivadas (sin lógica en el template).
	 *
	 * Para un primer ingreso sin evolución muestra exactamente:
	 *   "Nuevo prospecto recibido." + "Operador asignado." + "Primer contacto pendiente."
	 * Cuando el motor detecta evolución, muestra las señales y la recomendación.
	 *
	 * @param array $decision InterestDecision (salida de decide()).
	 * @param array $extra    contexto extra para narratives (lead_name, project, etc.).
	 * @return array
	 */
	public static function narratives( array $decision, array $extra = array() ): array
	{
		$level = (string) ( $decision['interest_level'] ?? 'CAPTURE' );
		$nba = isset( $decision['next_best_action'] ) && is_array( $decision['next_best_action'] ) ? $decision['next_best_action'] : array();
		$nba_label = (string) ( $nba['label'] ?? '' );

		// Estados "primer contacto": captura sin señales de evolución.
		if ( $level === 'CAPTURE' || $decision['absent'] ) {
			return array(
				'headline'   => 'Nuevo prospecto recibido.',
				'status'     => 'Operador asignado.',
				'pending'    => 'Primer contacto pendiente.',
				'evolution'  => array(),
				'action'     => $nba_label !== '' ? $nba_label : 'Primer contacto pendiente',
			);
		}

		// Evolución detectada: describir las señales y la recomendación.
		$evolution = array();
		if ( ! empty( $decision['capture']['is_recontact'] ) ) {
			$evolution[] = 'Recontacto comercial';
		}
		foreach ( (array) ( $decision['signals'] ?? array() ) as $s ) {
			$evolution[] = (string) ( $s['label'] ?? '' );
		}
		$evolution = array_values( array_unique( array_filter( $evolution ) ) );

		$headline = self::evolution_headline( $level );
		$status = (string) ( $decision['interest_label'] ?? $level );
		if ( ! empty( $decision['capture']['submission_count'] ) && (int) $decision['capture']['submission_count'] > 1 ) {
			$status .= ' · Ingreso #' . (int) $decision['capture']['submission_count'];
		}

		return array(
			'headline'   => $headline,
			'status'     => $status,
			'pending'    => $nba_label !== '' ? $nba_label : 'Registrar siguiente paso',
			'evolution'  => $evolution,
			'action'     => $nba_label,
		);
	}

	/** Titular según nivel de interés. */
	private static function evolution_headline( string $level ): string
	{
		switch ( $level ) {
			case 'HIGH':
				return 'Alto interés comercial detectado.';
			case 'ENGAGED':
				return 'El prospecto muestra interés activo.';
			case 'REVISITED':
				return 'El prospecto volvió a interesarse.';
			case 'COOLING':
				return 'Interés comercial enfriándose.';
			default:
				return 'Nuevo prospecto recibido.';
		}
	}

	/** Explicación legible de por qué se llegó al estado. */
	private static function explain( array $signals, int $score, string $channel ): string
	{
		$parts = array();
		if ( $signals ) {
			$parts[] = count( $signals ) . ' señales';
		}
		if ( $channel !== '' ) {
			$parts[] = 'canal ' . $channel;
		}
		$parts[] = 'score ' . $score;
		return implode( ' · ', $parts );
	}

	/** Decisión de ausencia (comportamiento por defecto, byte-idéntico). */
	private static function absent_decision(): array
	{
		return array(
			'version'        => self::VERSION,
			'absent'         => true,
			'interest_level' => 'CAPTURE',
			'interest_label' => InterestState::label( 'CAPTURE' ),
			'score'          => 0,
			'raw_score'      => 0,
			'channel'        => array( 'channel' => '', 'weight' => 1.0, 'label' => 'Sitio' ),
			'signals'        => array(),
			'signal_ids'     => array(),
			'priority'       => array( 'level' => 'low', 'label' => 'Baja' ),
			'next_best_action' => array( 'action' => 'wait', 'label' => 'Primer contacto pendiente', 'confidence' => 0.6 ),
			'capture'        => array( 'submission_count' => 0, 'is_recontact' => false ),
			'management'     => array( 'operator_assigned' => false, 'contact_registered' => false, 'classification_done' => false ),
			'explain'        => array( 'reason' => 'sin evidencia' ),
		);
	}
}
