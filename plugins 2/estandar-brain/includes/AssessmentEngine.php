<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AssessmentEngine
{
	const OPTION = 'estandar_suite_brain_assessment_config';

	const FIT_VALUES = array( 'MATCH', 'MATCH_PARCIAL', 'DUDOSO', 'FUERA_DE_PERFIL' );
	const TEMPERATURE_VALUES = array( 'HOT', 'WARM', 'COLD' );
	const PRIORITY_VALUES = array( 'HIGH', 'MEDIUM', 'LOW' );

	const INTEGRITY_VALUES = array( 'VALID', 'TEST', 'SPAM', 'FRAUD' );

	public static function evaluate( array $lead_data, array $rules_payload = array() ): array
	{
		$result = array(
			'commercial_fit' => '',
			'fit_reason'     => '',
			'temperature'    => '',
			'priority'       => '',
			'integrity'      => 'VALID',
			'confidence'     => 0,
			'decision_source' => 'deterministic',
			'activated_rules' => array(),
			'evidence_used'  => array(),
			'evidence_missing' => array(),
		);

		$integrity = self::check_integrity( $lead_data );
		if ( $integrity !== 'VALID' ) {
			$result['integrity'] = $integrity;
			$result['commercial_fit'] = 'FUERA_DE_PERFIL';
			$result['fit_reason'] = $integrity;
			return $result;
		}

		if ( ! $rules_payload ) {
			$rules_payload = get_option( Rules::OPTION, array() );
			$rules_payload = is_array( $rules_payload ) ? $rules_payload : array();
		}
		$rules_payload = $rules_payload['rules'] ?? $rules_payload;
		$rules_list = is_array( $rules_payload ) ? $rules_payload : array();

		$eval = Rules::evaluate( $lead_data, $rules_list );
		$action = $eval['action'] ?? array();

		$fit = $action['commercial_fit'] ?? '';
		if ( ! $fit && ! empty( $action['status'] ) ) {
			$fit_map = array( 'HIGH' => 'MATCH', 'MEDIUM' => 'MATCH_PARCIAL', 'LOW' => 'DUDOSO' );
			$fit = $fit_map[ strtoupper( $action['status'] ) ] ?? '';
		}
		$fit_reason = $action['reason'] ?? ( $action['label'] ?? '' );

		$temperature = 'COLD';
		if ( isset( $action['temperature'] ) ) {
			$temperature = $action['temperature'];
		} elseif ( isset( $action['status'] ) && strtoupper( $action['status'] ) === 'HIGH' ) {
			$temperature = 'HOT';
		} elseif ( isset( $action['status'] ) && strtoupper( $action['status'] ) === 'MEDIUM' ) {
			$temperature = 'WARM';
		}

		$priority = $action['priority'] ?? ( $action['status'] ?? 'LOW' );
		if ( ! in_array( strtoupper( $priority ), self::PRIORITY_VALUES, true ) ) {
			$priority_map = array( 'HIGH' => 'HIGH', 'MEDIUM' => 'MEDIUM', 'LOW' => 'LOW' );
			$priority = $priority_map[ strtoupper( $priority ) ] ?? 'LOW';
		}

		$result['activated_rules'] = $eval['matched'] ?? array();
		$result['evidence_used'] = array_keys( $eval['sources'] ?? array() );

		$result['commercial_fit'] = $fit ?: 'DUDOSO';
		if ( ! $fit ) {
			$result['evidence_missing'][] = 'classification_rules';
		}
		$result['fit_reason'] = $fit_reason;
		$result['temperature'] = $temperature;
		$result['priority'] = strtoupper( $priority );

		return $result;
	}

	public static function check_integrity( array $lead_data ): string
	{
		$message = strtolower( (string) ( $lead_data['message'] ?? '' ) );
		$email   = strtolower( (string) ( $lead_data['email'] ?? '' ) );
		$name    = strtolower( (string) ( $lead_data['name'] ?? '' ) );

		if ( strpos( $message, 'test' ) !== false && strlen( $message ) < 20 ) return 'TEST';
		if ( strpos( $email, 'test@' ) === 0 ) return 'TEST';

		$spam_patterns = array( 'buy now', 'click here', 'free money', 'casino', 'viagra', 'seo services' );
		foreach ( $spam_patterns as $pattern ) {
			if ( strpos( $message, $pattern ) !== false ) return 'SPAM';
		}

		if ( empty( $name ) && empty( $message ) && empty( $email ) ) return 'SPAM';

		return 'VALID';
	}

	private static function priority_ordinal( string $p ): int
	{
		return array( 'HIGH' => 3, 'MEDIUM' => 2, 'LOW' => 1 )[ strtoupper( $p ) ] ?? 0;
	}

	public static function format_operator_summary( array $assessment, array $lead_data ): array
	{
		return array(
			'fit'              => $assessment['commercial_fit'] ?? 'DUDOSO',
			'fit_label'        => self::fit_label( $assessment['commercial_fit'] ?? '' ),
			'temperature'      => $assessment['temperature'] ?? 'COLD',
			'priority'         => $assessment['priority'] ?? 'LOW',
			'integrity'        => $assessment['integrity'] ?? 'VALID',
			'confidence'       => $assessment['confidence'] ?? 0,
			'activated_rules'  => $assessment['activated_rules'] ?? array(),
			'evidence_missing' => $assessment['evidence_missing'] ?? array(),
			'suggested_action' => self::suggested_action( $assessment ),
			'suggested_questions' => $assessment['evidence_missing'] ?? array(),
		);
	}

	public static function fit_label( string $fit ): string
	{
		$labels = array(
			'MATCH' => 'Encaja', 'MATCH_PARCIAL' => 'Encaje parcial',
			'DUDOSO' => 'Dudoso', 'FUERA_DE_PERFIL' => 'Fuera de perfil',
		);
		return $labels[ $fit ] ?? $fit;
	}

	public static function suggested_action( array $assessment ): string
	{
		$priority = $assessment['priority'] ?? 'LOW';
		if ( $priority === 'HIGH' ) return 'Llamar hoy. Contacto inmediato.';
		if ( $priority === 'MEDIUM' ) return 'Contactar en las próximas 4 horas.';
		return 'Agregar a cola de seguimiento.';
	}
}
