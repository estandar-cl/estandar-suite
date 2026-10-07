<?php

namespace Estandar\Core\Quality;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OperatorFeedbackParser — convierte feedback libre del operador en evidencia
 * estructurada de calidad, sin perder la nota original.
 *
 * OPERATOR_FEEDBACK_V1 — caso AK #94: el operador registró "Sin respuesta"
 * (contact_result) y la nota "dio el numero mal". La nota libre contiene
 * evidencia de contacto inutilizable que el sistema debe estructurar, pero
 * NUNCA inferir más de lo sustentado por la evidencia.
 *
 * Entrada:
 *   - contact_result : resultado canónico del intento (no_response/responded/…).
 *   - notes          : nota libre del operador (se conserva íntegra).
 *   - contactability : { phone: {contactability_status, validation_reason},
 *                       email: {...} } opcional (validación técnica).
 *
 * Salida (evidencia estructurada):
 *   - contact_outcome   : outcome canónico.
 *   - phone_invalidated : TRUE si la nota señala número inválido/mal.
 *   - email_invalidated : TRUE si la nota señala email inválido.
 *   - invalid_reason    : reason estructurada (invalid_contact_data si aplica).
 *   - confidence        : 0..1 (baja = solo por nota; media = nota + resultado).
 *   - source            : 'operator_feedback' | 'operator_feedback+validation'.
 *   - original_notes    : la nota intacta (nunca se reemplaza).
 *   - matched_patterns  : patrones que dispararon (para auditabilidad).
 *
 * Reglas de no-inferencia:
 *   - "Sin respuesta" (unreachable) SIN nota ni validación → NO se declara
 *     dato inválido (podría ser ausencia legítima).
 *   - Nota con señal de dato mal → invalid_contact_data, SOLO con señales
 *     explícitas de contacto (número/email), no por "no responde".
 */
final class OperatorFeedbackParser
{
	const VERSION = 'OPERATOR_FEEDBACK_V1';

	/** Señales léxicas de número inválido/mal (es-CL, case-insensitive). */
	const PHONE_INVALID_PATTERNS = array(
		'numero mal',
		'número mal',
		'número inválido',
		'numero invalido',
		'numero equivocado',
		'número equivocado',
		'dio el numero mal',
		'dio el número mal',
		'teléfono inválido',
		'telefono invalido',
		'teléfono mal',
		'telefono mal',
		'numero no existe',
		'número no existe',
		'numero incorrecto',
		'número incorrecto',
		'no es el numero',
		'no es el número',
		'mal el telefono',
		'mal el teléfono',
	);

	/** Señales léxicas de email inválido. */
	const EMAIL_INVALID_PATTERNS = array(
		'email inválido',
		'email invalido',
		'email mal',
		'correo inválido',
		'correo invalido',
		'correo mal',
		'mail inválido',
		'mail invalido',
		'email no existe',
		'correo no existe',
	);

	/** Señales léxicas de "no corresponde" / contacto erróneo. */
	const CONTACT_WRONG_PATTERNS = array(
		'no corresponde',
		'datos incorrectos',
		'datos mal',
		'no es la persona',
		'no es el contacto',
		'contacto incorrecto',
	);

	/**
	 * Estructura el feedback del operador.
	 *
	 * @param string $contact_result resultado canónico legacy
	 *                               (no_response|responded|interested|not_interested|follow_up|'').
	 * @param string $notes          nota libre del operador (se conserva íntegra).
	 * @param array  $contactability validación técnica opcional:
	 *                               { phone: array, email: array }.
	 * @return array evidencia estructurada.
	 */
	public static function parse( string $contact_result, string $notes, array $contactability = array() ): array
	{
		$notes = (string) $notes;
		$notes_lower = function_exists( 'mb_strtolower' )
			? mb_strtolower( $notes, 'UTF-8' )
			: strtolower( $notes );

		$matched = array();
		$phone_invalidated = false;
		$email_invalidated = false;

		foreach ( self::PHONE_INVALID_PATTERNS as $p ) {
			if ( strpos( $notes_lower, $p ) !== false ) {
				$phone_invalidated = true;
				$matched[] = 'phone:' . $p;
				break;
			}
		}
		foreach ( self::EMAIL_INVALID_PATTERNS as $p ) {
			if ( strpos( $notes_lower, $p ) !== false ) {
				$email_invalidated = true;
				$matched[] = 'email:' . $p;
				break;
			}
		}
		$contact_wrong = false;
		foreach ( self::CONTACT_WRONG_PATTERNS as $p ) {
			if ( strpos( $notes_lower, $p ) !== false ) {
				$contact_wrong = true;
				$matched[] = 'contact:' . $p;
				break;
			}
		}

		$outcome = LeadQualityModel::outcome_from_feedback_result( $contact_result );

		// Validación técnica refuerza (no reemplaza) la evidencia.
		$phone_status = isset( $contactability['phone']['contactability_status'] )
			? (string) $contactability['phone']['contactability_status']
			: '';
		$email_status = isset( $contactability['email']['contactability_status'] )
			? (string) $contactability['email']['contactability_status']
			: '';

		$source = 'operator_feedback';
		$confidence = 0.6;
		if ( $phone_invalidated || $email_invalidated || $contact_wrong ) {
			$confidence = 0.8; // Nota explícita del operador.
			if ( ( $phone_invalidated && in_array( $phone_status, array( 'invalid', 'suspicious' ), true ) )
				|| ( $email_invalidated && $email_status === 'invalid' ) ) {
				$source = 'operator_feedback+validation';
				$confidence = 0.9;
			}
		}

		$invalid_reason = '';
		if ( $phone_invalidated || $email_invalidated || $contact_wrong ) {
			$invalid_reason = LeadQualityModel::REASON_INVALID_CONTACT_DATA;
		}

		return array(
			'contact_outcome'   => $outcome,
			'phone_invalidated' => $phone_invalidated,
			'email_invalidated' => $email_invalidated,
			'contact_wrong'     => $contact_wrong,
			'invalid_reason'    => $invalid_reason,
			'confidence'        => $confidence,
			'source'            => $source,
			'original_notes'    => $notes,
			'matched_patterns'  => $matched,
			'contactability'    => array(
				'phone' => $phone_status,
				'email' => $email_status,
			),
		);
	}
}
