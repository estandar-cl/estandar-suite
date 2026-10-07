<?php

namespace Estandar\Flow\Journey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IEE-7 — Adapter de Suite v2 al Interest Evolution Engine (core).
 *
 * Reutiliza EXACTAMENTE el mismo motor que la Suite actual
 * (Estandar\Core\Interest\*). No copia lógica: traduce el pipeline de la
 * Suite v2 (Journey + captures) al contrato de dominio del motor.
 *
 * SOLO TRADUCE DATOS. Sin reglas de negocio.
 *
 * Ausencia = default: si core no tiene el motor, devuelve
 * ['version'=>'IEE_V1','absent'=>true].
 */
class InterestAdapter
{
	const VERSION = 'IEE_V1';

	/** Crea el InterestDecision a partir de un pipeline/capture de Suite v2. */
	public static function decide( array $pipeline, array $capture = array() ): array
	{
		if ( ! class_exists( 'Estandar\\Core\\Interest\\InterestEngine' )
			|| ! class_exists( 'Estandar\\Core\\Interest\\InterestContext' ) ) {
			return array( 'version' => self::VERSION, 'absent' => true );
		}

		$pipeline  = is_array( $pipeline ) ? $pipeline : array();
		$capture   = is_array( $capture ) ? $capture : array();

		$submissions = max( 0, (int) ( $pipeline['submission_count'] ?? $capture['submission_count'] ?? 0 ) );
		$stage       = strtoupper( (string) ( $pipeline['journey_state'] ?? $capture['journey_state'] ?? 'NEW' ) );
		$contacted   = in_array( $stage, array( 'CONTACTED', 'ENGAGED', 'QUALIFIED', 'MEETING', 'PROPOSAL', 'NEGOTIATING', 'CLOSED_WON', 'ACTIVE_CLIENT' ), true );
		$classified  = in_array( $stage, array( 'QUALIFIED', 'MEETING', 'PROPOSAL', 'NEGOTIATING', 'CLOSED_WON', 'ACTIVE_CLIENT' ), true );

		$behavior = array(
			'email_opened'    => ! empty( $capture['email_opened'] ),
			'email_clicked'   => ! empty( $capture['email_clicked'] ),
			'whatsapp_opened' => ! empty( $capture['whatsapp_opened'] ),
			'chatbot_started' => ! empty( $capture['chatbot_started'] ),
			'quote_created'   => in_array( $stage, array( 'PROPOSAL', 'NEGOTIATING', 'CLOSED_WON' ), true ),
			'page_views'      => max( 0, (int) ( $capture['page_views'] ?? 0 ) ),
		);

		$ctx = \Estandar\Core\Interest\InterestContext::build( array(
			'submission_count'        => $submissions,
			'is_commercial_recontact' => ! empty( $capture['is_recontact'] ),
			'return_visit_after_capture' => ! empty( $capture['return_visit_after_capture'] ),
			'new_session'             => ! empty( $capture['new_session'] ),
			'channel'                 => (string) ( $capture['channel'] ?? '' ),
			'behavior'                => $behavior,
			'operator_assigned'       => ! empty( $pipeline['assigned_operator'] ) || ! empty( $capture['operator_assigned'] ),
			'contact_registered'      => $contacted || ! empty( $capture['contact_registered'] ),
			'classification_done'     => $classified || ! empty( $capture['classification_done'] ),
			'elapsed_hours'           => max( 0, (int) ( $capture['elapsed_hours'] ?? 0 ) ),
			'sla_violated'            => ! empty( $capture['sla_violated'] ),

			// IEE-7: dimensiones de tiempo expandidas desde el pipeline v2.
			'chronological_age_hours'       => max( 0, (int) ( $pipeline['chronological_age_hours'] ?? $capture['chronological_age_hours'] ?? $capture['elapsed_hours'] ?? 0 ) ),
			'operator_actionable_hours'     => max( 0, (int) ( $pipeline['operator_actionable_hours'] ?? $capture['operator_actionable_hours'] ?? 0 ) ),
			'time_deferred_by_policy_hours' => max( 0, (int) ( $pipeline['time_deferred_by_policy_hours'] ?? $capture['time_deferred_by_policy_hours'] ?? 0 ) ),
			'hours_since_last_engagement'   => max( 0, (int) ( $pipeline['hours_since_last_engagement'] ?? $capture['hours_since_last_engagement'] ?? $capture['elapsed_hours'] ?? 0 ) ),
			'hours_since_last_operator_action' => max( 0, (int) ( $pipeline['hours_since_last_operator_action'] ?? $capture['hours_since_last_operator_action'] ?? $capture['elapsed_hours'] ?? 0 ) ),
			'hours_since_delivery'          => max( 0, (int) ( $capture['hours_since_delivery'] ?? 0 ) ),
		) );

		$decision = \Estandar\Core\Interest\InterestEngine::decide( $ctx );
		$decision['context'] = $ctx;
		return $decision;
	}

	/** Narratives de correo/UI v2 (mismo motor). */
	public static function narratives( array $decision, array $extra = array() ): array
	{
		if ( ! class_exists( 'Estandar\\Core\\Interest\\InterestEngine' ) ) {
			return array();
		}
		return \Estandar\Core\Interest\InterestEngine::narratives( $decision, $extra );
	}

	/** Acciones de compromiso (mismo motor, reglas por proyecto). */
	public static function engagement( string $project_slug, array $decision, array $state = array() ): array
	{
		if ( ! class_exists( 'Estandar\\Core\\Interest\\EngagementEngine' ) ) {
			return array();
		}
		return \Estandar\Core\Interest\EngagementEngine::evaluate( $project_slug, $decision, $state );
	}
}
