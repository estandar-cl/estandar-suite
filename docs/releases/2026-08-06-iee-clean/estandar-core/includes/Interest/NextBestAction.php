<?php

namespace Estandar\Core\Interest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NextBestAction — recomendación de siguiente acción comercial.
 *
 * INTEREST_EVOLUTION_ENGINE_V1 (IEE-1, ADR-0044).
 *
 * El motor produce la recomendación; los templates/canales SOLO la renderizan.
 * Nunca texto fijo en correos.
 *
 *   wait              → primer contacto pendiente, sin urgencia.
 *   recontact         → volvió a interesarse, re-contactar.
 *   call_now          → interés activo/alto, llamar ya.
 *   schedule_visit    → alto interés, agendar visita.
 *   request_classification → ya hubo contacto, falta clasificar.
 *   escalate          → interés enfriándose, escalar al supervisor.
 */
final class NextBestAction
{
	const VERSION = 'IEE_V1';

	const WAIT       = 'wait';
	const RECONTACT  = 'recontact';
	const CALL_NOW   = 'call_now';
	const VISIT      = 'schedule_visit';
	const CLASSIFY   = 'request_classification';
	const ESCALATE   = 'escalate';

	/**
	 * Deriva la siguiente acción.
	 *
	 * @param string $interest_level     uno de InterestState::LEVELS|COOLING.
	 * @param bool   $operator_assigned  operador asignado.
	 * @param bool   $contact_registered contacto comercial registrado.
	 * @param bool   $classification_done clasificación registrada.
	 * @param bool   $quote_created      cotización creada.
	 * @return array { action, label, confidence }
	 */
	public static function derive(
		string $interest_level,
		bool $operator_assigned,
		bool $contact_registered,
		bool $classification_done,
		bool $quote_created
	): array {
		$level = $interest_level;

		if ( $level === 'COOLING' && ! $contact_registered ) {
			return self::make( self::ESCALATE, 'Escalar para recuperar el interés', 0.8 );
		}
		if ( $level === 'HIGH' && $quote_created && ! $contact_registered ) {
			return self::make( self::VISIT, 'Agendar visita/reunión', 0.85 );
		}
		if ( $level === 'HIGH' && ! $contact_registered ) {
			return self::make( self::CALL_NOW, 'Llamar ahora', 0.9 );
		}
		if ( $level === 'ENGAGED' && ! $contact_registered ) {
			return self::make( self::CALL_NOW, 'Contactar en el momento', 0.8 );
		}
		if ( $level === 'REVISITED' && ! $contact_registered ) {
			return self::make( self::RECONTACT, 'Recontactar', 0.7 );
		}
		if ( $contact_registered && ! $classification_done ) {
			return self::make( self::CLASSIFY, 'Registrar clasificación', 0.9 );
		}
		if ( $contact_registered ) {
			return self::make( self::CLASSIFY, 'Registrar resultado comercial', 0.7 );
		}
		return self::make( self::WAIT, 'Primer contacto pendiente', 0.6 );
	}

	private static function make( string $action, string $label, float $confidence ): array
	{
		return array(
			'action'     => $action,
			'label'      => $label,
			'confidence' => $confidence,
		);
	}
}
