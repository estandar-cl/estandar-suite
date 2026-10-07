<?php

namespace Estandar\Core\Quality;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LeadQualityConsistency — checks automáticos de consistencia del modelo.
 *
 * LEAD_QUALITY_CONSISTENCY_V1 — detecta las incoherencias del caso AK #94:
 *   - is_valid_lead TRUE con dato de contacto comercialmente inválido;
 *   - decisión de admisión QUALIFIED mientras la calificación comercial está
 *     PENDING (ambigüedad qualified = admisión vs calificación);
 *   - Quality LOW sin razón estructurada;
 *   - temperatura/score que contradice evidencia comercial más fuerte.
 *
 * Cada check devuelve: ok (bool), issue (código), detail (mensaje), severity
 * (info|warning|error). Los checks son PUNTOS y no mutan nada; la corrección
 * es responsabilidad del flujo que los consume.
 */
final class LeadQualityConsistency
{
	const VERSION = 'LEAD_QUALITY_CONSISTENCY_V1';

	/**
	 * Audita la consistencia del modelo para un lead.
	 *
	 * @param array $s state: {
	 *   is_valid_lead, contactability_phone, contactability_email,
	 *   lead_quality, quality_reason, admission_status, commercial_qualification,
	 *   temperature, contact_outcome, invalid_reason
	 * }.
	 * @return array{ ok: bool, checks: array[] }
	 */
	public static function audit( array $s ): array
	{
		$checks = array();

		// 1) is_valid_lead TRUE + dato de contacto inválido/sospechoso.
		$phone = (string) ( $s['contactability_phone'] ?? '' );
		$email = (string) ( $s['contactability_email'] ?? '' );
		$is_valid = $s['is_valid_lead'] ?? null;
		if ( $is_valid === 1 && in_array( $phone, array( 'invalid', 'suspicious' ), true ) ) {
			$checks[] = array(
				'ok'       => false,
				'issue'    => 'valid_lead_with_invalid_phone',
				'detail'   => 'is_valid_lead=TRUE pero el teléfono no es comercialmente utilizable (' . $phone . ').',
				'severity' => 'warning',
			);
		}
		if ( $is_valid === 1 && $email === 'invalid' ) {
			$checks[] = array(
				'ok'       => false,
				'issue'    => 'valid_lead_with_invalid_email',
				'detail'   => 'is_valid_lead=TRUE pero el email no es utilizable.',
				'severity' => 'warning',
			);
		}

		// 2) Decisión de admisión QUALIFIED con calificación comercial PENDING.
		//    (qualified legacy = admisión accepted; no debe confundirse con
		//    calificación comercial.) Detecta el caso donde el sistema lo
		//    presenta como "calificado" sin calificación real.
		$admission = (string) ( $s['admission_status'] ?? '' );
		$qualif = (string) ( $s['commercial_qualification'] ?? '' );
		if ( $admission === LeadQualityModel::ADMISSION_ACCEPTED
			&& $qualif === LeadQualityModel::QUALIFICATION_PENDING ) {
			$checks[] = array(
				'ok'       => true, // Semánticamente correcto: admitido ≠ calificado.
				'issue'    => 'admitted_not_qualified',
				'detail'   => 'Lead admitido (pasó la política) con calificación comercial pendiente. No es contradicción.',
				'severity' => 'info',
			);
		}

		// 3) Quality LOW sin razón estructurada.
		$quality = (string) ( $s['lead_quality'] ?? '' );
		$qreason = (string) ( $s['quality_reason'] ?? '' );
		$outcome = (string) ( $s['contact_outcome'] ?? '' );
		if ( strtoupper( $quality ) === LeadQualityModel::QUALITY_LOW && $qreason === '' && $outcome === '' ) {
			$checks[] = array(
				'ok'       => false,
				'issue'    => 'low_quality_without_reason',
				'detail'   => 'Quality=LOW sin quality_reason ni outcome estructurado.',
				'severity' => 'warning',
			);
		}

		// 4) Temperatura caliente con dato de contacto inválido (contradicción).
		$temperature = strtoupper( (string) ( $s['temperature'] ?? '' ) );
		if ( in_array( $phone, array( 'invalid', 'suspicious' ), true )
			&& in_array( $temperature, array( 'CALIENTE', 'TIBIO' ), true ) ) {
			$checks[] = array(
				'ok'       => false,
				'issue'    => 'hot_lead_with_invalid_phone',
				'detail'   => 'Temperatura ' . $temperature . ' con teléfono ' . $phone . ': contradice la evidencia de contacto.',
				'severity' => 'warning',
			);
		}

		// 5) Outcome unreachable + invalid_reason ausente: no es incoherencia
		//    (puede ser ausencia legítima), solo información.
		if ( $outcome === LeadQualityModel::OUTCOME_UNREACHABLE
			&& (string) ( $s['invalid_reason'] ?? '' ) === '' ) {
			$checks[] = array(
				'ok'       => true,
				'issue'    => 'unreachable_without_reason',
				'detail'   => 'Contacto unreachable sin invalid_reason: no implica dato inválido.',
				'severity' => 'info',
			);
		}

		$all_ok = true;
		foreach ( $checks as $c ) {
			if ( ! $c['ok'] ) {
				$all_ok = false;
				break;
			}
		}

		return array(
			'ok'     => $all_ok,
			'checks' => $checks,
		);
	}
}
