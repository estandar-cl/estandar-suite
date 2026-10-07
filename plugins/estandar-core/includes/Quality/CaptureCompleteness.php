<?php

namespace Estandar\Core\Quality;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CaptureCompleteness — dimensión independiente de completitud de datos.
 *
 * IEE-7: ORTOGONAL a commercial_qualification y lead_quality.
 *
 * La ausencia de un canal de contacto opcional NO debe convertirse
 * automáticamente en baja calidad comercial si existe otro medio de
 * contacto utilizable.
 *
 * Dimensiones evaluadas (independientes):
 *   - has_usable_contact    : ¿al menos un medio de contacto es utilizable?
 *   - missing_channels      : canales ausentes (email, phone, whatsapp…).
 *   - available_channels    : canales disponibles y utilizables.
 *   - completeness_score    : 0.0–1.0 (proporción de canales disponibles).
 *   - completeness_label    : complete / partial_usable / incomplete / unusable.
 *
 * Puro: sin WordPress, sin DB, testeable standalone.
 */
final class CaptureCompleteness
{
	const VERSION = 'IEE_V1';

	const COMPLETE        = 'complete';
	const PARTIAL_USABLE  = 'partial_usable';
	const INCOMPLETE      = 'incomplete';
	const UNUSABLE        = 'unusable';

	/**
	 * Evalúa la completitud de datos de contacto de una captura.
	 *
	 * @param array $contact {
	 *   email        ?string  dirección de email (vacía si no se proporcionó).
	 *   phone        ?string  número de teléfono (vacío si no se proporcionó).
	 *   email_valid  ?bool    ¿el email es contactable? (resultado de ContactabilityEngine).
	 *   phone_valid  ?bool    ¿el teléfono es contactable? (resultado de ContactabilityEngine).
	 *   name         ?string  nombre (vacío si no se proporcionó).
	 * }
	 * @param array $required_channels  lista de canales requeridos (default: ['email','phone']).
	 * @return array {
	 *   has_usable_contact    bool
	 *   missing_channels      array<string>
	 *   available_channels    array<string>
	 *   completeness_score    float  0.0–1.0
	 *   completeness_label    string
	 *   reason                string
	 * }
	 */
	public static function evaluate( array $contact, array $required_channels = array( 'email', 'phone' ) ): array
	{
		if ( empty( $required_channels ) ) {
			$required_channels = array( 'email', 'phone' );
		}

		$email       = trim( (string) ( $contact['email'] ?? '' ) );
		$phone       = trim( (string) ( $contact['phone'] ?? '' ) );
		$email_valid = ! empty( $contact['email_valid'] );
		$phone_valid = ! empty( $contact['phone_valid'] );
		$has_name    = trim( (string) ( $contact['name'] ?? '' ) ) !== '';

		$available = array();
		$missing   = array();

		foreach ( $required_channels as $ch ) {
			if ( $ch === 'email' && $email !== '' && $email_valid ) {
				$available[] = 'email';
			} elseif ( $ch === 'email' ) {
				$missing[] = 'email';
			}
			if ( $ch === 'phone' && $phone !== '' && $phone_valid ) {
				$available[] = 'phone';
			} elseif ( $ch === 'phone' ) {
				$missing[] = 'phone';
			}
		}

		$total = count( $required_channels );
		$available_count = count( $available );
		$score = $total > 0 ? round( $available_count / $total, 2 ) : 0.0;

		$has_usable = $available_count > 0;

		// Label.
		if ( $available_count === $total && $total > 0 ) {
			$label = self::COMPLETE;
			$reason = 'todos los canales requeridos disponibles';
		} elseif ( $has_usable && count( $missing ) > 0 ) {
			$label = self::PARTIAL_USABLE;
			$channels_missing = implode( ', ', $missing );
			$channels_avail   = implode( ', ', $available );
			$reason = "faltan: {$channels_missing}; disponible: {$channels_avail}";
		} elseif ( ! $has_usable && ( $email !== '' || $phone !== '' ) ) {
			// Se proporcionaron datos pero no son contactables.
			$label = self::INCOMPLETE;
			$reason = 'datos proporcionados pero no contactables';
		} else {
			$label = self::UNUSABLE;
			$reason = 'sin datos de contacto utilizables';
		}

		return array(
			'has_usable_contact'  => $has_usable,
			'missing_channels'    => $missing,
			'available_channels'  => $available,
			'completeness_score'  => $score,
			'completeness_label'  => $label,
			'reason'              => $reason,
		);
	}
}
