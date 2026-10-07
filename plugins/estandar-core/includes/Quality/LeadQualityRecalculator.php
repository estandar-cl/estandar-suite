<?php

namespace Estandar\Core\Quality;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LeadQualityRecalculator — recálculo de calidad tras feedback humano.
 *
 * LEAD_QUALITY_RECALC_V1 — precedencia explícita entre fuentes de evidencia:
 *
 *   1. capture evidence       (lo que el lead declaró)
 *   2. automated validation   (contactabilidad técnica)
 *   3. behavior evidence      (interés observado)
 *   4. operator feedback      (contacto/clasificación humana)  ← prevalece
 *   5. commercial outcome     (resultado comercial real)
 *
 * El feedback humano estructurado PREVALECE cuando corresponde, pero NUNCA
 * borra el historial: la salida incluye provenance (previous/result/source/
 * timestamp/confidence/reason) y un evento sugerido para persistir.
 *
 * Salida (dimensión recalculada):
 *   - lead_quality           : HIGH/MEDIUM/LOW/'' recalculado.
 *   - quality_reason         : razón estructurada (invalid_contact_data, …).
 *   - commercial_qualification : pendiente/estándar/etc (no toca si no hay evidencia).
 *   - temperature            : score re-derivado coherente ('' si no hay motor).
 *   - recalculated           : bool (¿cambió algo?).
 *   - provenance             : { field, previous, result, source, confidence,
 *                               reason, timestamp } por cambio.
 *   - event_suggestion       : { event_type, reason, metadata } para insert_event.
 *
 * Reglas de no-inferencia:
 *   - Sin respuesta SIN dato inválido → NO baja calidad por sí solo.
 *   - Dato de contacto invalidado (número/email mal) → LOW + invalid_contact_data.
 *   - No se tocan dimensiones sin evidencia que las respalde.
 */
final class LeadQualityRecalculator
{
	const VERSION = 'LEAD_QUALITY_RECALC_V1';

	const SOURCE_CAPTURE   = 'capture';
	const SOURCE_VALIDATION= 'automated_validation';
	const SOURCE_BEHAVIOR  = 'behavior';
	const SOURCE_OPERATOR  = 'operator_feedback';
	const SOURCE_OUTCOME   = 'commercial_outcome';

	/**
	 * Recalcula la calidad del lead a partir de feedback estructurado.
	 *
	 * @param array $current estado actual: {
	 *   lead_quality, commercial_qualification, temperature, is_valid_lead
	 * }.
	 * @param array $feedback evidencia estructurada del operador (salida de
	 *                        OperatorFeedbackParser::parse()).
	 * @return array dimensión recalculada + provenance + evento sugerido.
	 */
	public static function recalculate( array $current, array $feedback ): array
	{
		$current_quality = (string) ( $current['lead_quality'] ?? LeadQualityModel::QUALITY_UNSET );
		$current_temp    = (string) ( $current['temperature'] ?? '' );
		$current_qualif  = (string) ( $current['commercial_qualification'] ?? LeadQualityModel::QUALIFICATION_PENDING );
		$now             = gmdate( 'c' );

		$provenance = array();
		$changed = false;
		$event_metadata = array();

		$invalid = ! empty( $feedback['invalid_reason'] );
		$outcome = (string) ( $feedback['contact_outcome'] ?? '' );

		// 1) Dato de contacto invalidado → calidad LOW con razón estructurada.
		if ( $invalid ) {
			if ( $current_quality !== LeadQualityModel::QUALITY_LOW ) {
				$provenance[] = array(
					'field'    => 'lead_quality',
					'previous' => $current_quality,
					'result'   => LeadQualityModel::QUALITY_LOW,
					'source'   => (string) ( $feedback['source'] ?? self::SOURCE_OPERATOR ),
					'confidence' => (float) ( $feedback['confidence'] ?? 0.6 ),
					'reason'   => (string) ( $feedback['invalid_reason'] ?? '' ),
					'timestamp'=> $now,
				);
				$current_quality = LeadQualityModel::QUALITY_LOW;
				$changed = true;
			}
			$event_metadata['quality_reason'] = (string) ( $feedback['invalid_reason'] ?? '' );
			$event_metadata['contact_outcome'] = $outcome;
			$event_metadata['invalid_reason'] = (string) ( $feedback['invalid_reason'] ?? '' );
		}

		// 2) Resultado comercial explícito NO interesado / spam → no toca
		//    calidad si no hay evidencia de dato inválido (no-inferencia).
		//    (La clasificación comercial es competencia del operator pipeline.)

		// 3) Temperatura coherente: si el dato es inválido, ningún score de
		//    comportamiento puede justificar un lead "caliente" (contradicción
		//    AK #94). Solo se desescala si había temperatura.
		if ( $invalid && in_array( strtoupper( $current_temp ), array( 'CALIENTE', 'TIBIO' ), true ) ) {
			$provenance[] = array(
				'field'    => 'temperature',
				'previous' => $current_temp,
				'result'   => '', // sin temperatura: no hay base comportamental válida.
				'source'   => self::SOURCE_OPERATOR,
				'confidence' => (float) ( $feedback['confidence'] ?? 0.6 ),
				'reason'   => 'invalid_contact_data_downgrades_temperature',
				'timestamp'=> $now,
			);
			$current_temp = '';
			$changed = true;
		}

		$is_valid = $current['is_valid_lead'] ?? null;
		// El feedback del operador redefine semántica: is_valid_lead TRUE se
		// conserva SOLO si la semántica es "lead real/no spam". Un dato de
		// contacto inválido con lead real → sigue is_valid_lead=1 pero calidad LOW
		// y reason estructurado (no se pisa el historial).

		return array(
			'lead_quality'            => $current_quality,
			'quality_reason'          => (string) ( $event_metadata['quality_reason'] ?? '' ),
			'commercial_qualification'=> $current_qualif,
			'temperature'             => $current_temp,
			'is_valid_lead'           => $is_valid,
			'recalculated'            => $changed,
			'provenance'              => $provenance,
			'event_suggestion'        => array(
				'event_type' => 'quality_recalculated',
				'reason'     => (string) ( $event_metadata['quality_reason'] ?? 'operator_feedback' ),
				'metadata'   => array_merge( $event_metadata, array(
					'previous_quality'   => $current['lead_quality'] ?? '',
					'previous_temperature'=> $current['temperature'] ?? '',
					'operator_outcome'   => $outcome,
					'engine'             => self::VERSION,
				) ),
			),
		);
	}
}
