<?php

namespace Estandar\Core\Quality;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LeadQualityModel — taxonomía canónica de calidad de lead.
 *
 * LEAD_QUALITY_MODEL_V1 — motor reutilizable compartido por la Suite actual
 * (v1) y la Suite v2 (Brain/Capture/Catch/Experience/Core).
 *
 * ELIMINA LA AMBIGÜEDAD entre admisión técnica y calificación comercial
 * (caso AK #94): un lead puede ser ADMITIDO (pasó la política) sin estar
 * CALIFICADO comercialmente, y puede ser válido estructuralmente con datos
 * de contacto inutilizables.
 *
 * Dimensiones separadas (nunca se mezclan):
 *   - admission_status         : política de admisión técnica (captura aceptada).
 *   - commercial_qualification : calificación comercial (pendiente/estándar/etc).
 *   - lead_quality             : dimensión operativa de calidad (''/HIGH/MEDIUM/LOW).
 *   - contactability           : utilidad comercial de los datos de contacto.
 *   - contact_outcome          : resultado del intento de contacto (si existe).
 *   - invalid_reason           : razón estructurada de invalidez (si aplica).
 *
 * COMPATIBILIDAD: el contrato legacy `decision` del qualification engine
 * (qualified/review/disqualified) se interpreta como ADMISIÓN (routing), no
 * como calificación comercial. Este modelo expone el mapping explícito.
 *
 * Puro: sin WordPress, sin DB.
 */
final class LeadQualityModel
{
	const VERSION = 'LEAD_QUALITY_MODEL_V1';

	// ── admission_status (política de admisión de captura) ────────────────
	const ADMISSION_ACCEPTED = 'accepted';   // captura aceptada (routing proceed).
	const ADMISSION_REVIEW   = 'review';     // requiere revisión antes de enrutar.
	const ADMISSION_REJECTED = 'rejected';   // captura rechazada por política.
	const ADMISSION_PENDING  = 'pending';    // aún no evaluado.

	// ── commercial_qualification (calificación comercial) ─────────────────
	const QUALIFICATION_PENDING      = 'pending';      // sin calificar aún.
	const QUALIFICATION_STANDARD     = 'standard';     // calificado estándar.
	const QUALIFICATION_QUALIFIED    = 'qualified';    // calificación comercial fuerte.
	const QUALIFICATION_DISQUALIFIED = 'disqualified'; // no califica comercialmente.

	// ── lead_quality (dimensión operativa existente) ──────────────────────
	const QUALITY_HIGH   = 'HIGH';
	const QUALITY_MEDIUM = 'MEDIUM';
	const QUALITY_LOW    = 'LOW';
	const QUALITY_UNSET  = '';

	// ── contact_outcome (resultado del intento de contacto) ───────────────
	const OUTCOME_UNREACHABLE    = 'unreachable';
	const OUTCOME_RESPONDED      = 'responded';
	const OUTCOME_INTERESTED     = 'interested';
	const OUTCOME_NOT_INTERESTED = 'not_interested';
	const OUTCOME_FOLLOW_UP      = 'follow_up';
	const OUTCOME_NONE           = '';

	// ── invalid_reason (razón estructurada de invalidez) ──────────────────
	const REASON_INVALID_CONTACT_DATA = 'invalid_contact_data';
	const REASON_WRONG_CONTACT        = 'wrong_contact';
	const REASON_SPAM                 = 'spam';
	const REASON_OTHER_SERVICE        = 'other_service';
	const REASON_OUTSIDE_AREA         = 'outside_area';
	const REASON_TEST                 = 'test';
	const REASON_FRAUD                = 'fraud';

	/** Estados válidos por dimensión. */
	const ADMISSION_STATES = array(
		self::ADMISSION_ACCEPTED,
		self::ADMISSION_REVIEW,
		self::ADMISSION_REJECTED,
		self::ADMISSION_PENDING,
	);
	const QUALIFICATION_STATES = array(
		self::QUALIFICATION_PENDING,
		self::QUALIFICATION_STANDARD,
		self::QUALIFICATION_QUALIFIED,
		self::QUALIFICATION_DISQUALIFIED,
	);
	const QUALITY_STATES = array(
		self::QUALITY_HIGH,
		self::QUALITY_MEDIUM,
		self::QUALITY_LOW,
		self::QUALITY_UNSET,
	);
	const OUTCOME_STATES = array(
		self::OUTCOME_UNREACHABLE,
		self::OUTCOME_RESPONDED,
		self::OUTCOME_INTERESTED,
		self::OUTCOME_NOT_INTERESTED,
		self::OUTCOME_FOLLOW_UP,
		self::OUTCOME_NONE,
	);

	/**
	 * Mapping de compatibilidad: decisión del qualification engine (legacy) →
	 * admission_status. El legacy `qualified` es la ADMISIÓN (pasó la política
	 * de captura), NO la calificación comercial. Este mapping documenta y
	 * traduce sin romper los contratos públicos.
	 *
	 * @param string $legacy_decision 'qualified'|'review'|'disqualified'|'test'|''.
	 * @return string admission_status.
	 */
	public static function admission_from_legacy_decision( string $legacy_decision ): string
	{
		switch ( strtolower( trim( $legacy_decision ) ) ) {
			case 'qualified':
				return self::ADMISSION_ACCEPTED;
			case 'review':
				return self::ADMISSION_REVIEW;
			case 'disqualified':
			case 'test':
				return self::ADMISSION_REJECTED;
			default:
				return self::ADMISSION_PENDING;
		}
	}

	/**
	 * Contact outcome canónico a partir del valor del feedback v1.
	 *
	 * @param string $legacy_result 'no_response'|'responded'|'interested'|'not_interested'|'follow_up'.
	 * @return string contact_outcome canónico ('' si desconocido).
	 */
	public static function outcome_from_feedback_result( string $legacy_result ): string
	{
		$map = array(
			'no_response'    => self::OUTCOME_UNREACHABLE,
			'responded'      => self::OUTCOME_RESPONDED,
			'interested'     => self::OUTCOME_INTERESTED,
			'not_interested' => self::OUTCOME_NOT_INTERESTED,
			'follow_up'      => self::OUTCOME_FOLLOW_UP,
		);
		return $map[ strtolower( trim( $legacy_result ) ) ] ?? self::OUTCOME_NONE;
	}

	/**
	 * invalid_reason estructurado a partir de la taxonomía legacy v1.
	 *
	 * @param string $legacy 'SPAM'|'WRONG_CONTACT'|'OTHER_SERVICE'|'OUTSIDE_AREA'|'OTHER'.
	 * @return string invalid_reason canónico.
	 */
	public static function invalid_reason_from_legacy( string $legacy ): string
	{
		$map = array(
			'SPAM'           => self::REASON_SPAM,
			'WRONG_CONTACT'  => self::REASON_WRONG_CONTACT,
			'OTHER_SERVICE'  => self::REASON_OTHER_SERVICE,
			'OUTSIDE_AREA'   => self::REASON_OUTSIDE_AREA,
			'OTHER'          => self::REASON_INVALID_CONTACT_DATA,
		);
		return $map[ strtoupper( trim( $legacy ) ) ] ?? self::REASON_INVALID_CONTACT_DATA;
	}

	/**
	 * ¿El dato de contacto invalida la utilidad comercial del lead?
	 * TRUE cuando el feedback humano indica contacto inutilizable
	 * (número mal, email inválido, no corresponde) sin llegar a declarar
	 * spam/fraude.
	 */
	public static function is_contact_data_invalid( string $outcome, string $invalid_reason, string $contactability_phone ): bool
	{
		if ( $outcome === self::OUTCOME_UNREACHABLE ) {
			// Sin respuesta NO implica dato inválido por sí solo.
			if ( in_array( $invalid_reason, array(
				self::REASON_INVALID_CONTACT_DATA,
				self::REASON_WRONG_CONTACT,
			), true ) ) {
				return true;
			}
			if ( in_array( $contactability_phone, array( 'invalid', 'suspicious' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Valida un valor contra una lista de estados permitidos. */
	public static function is_valid_state( string $value, array $states ): bool
	{
		return in_array( $value, $states, true );
	}
}
