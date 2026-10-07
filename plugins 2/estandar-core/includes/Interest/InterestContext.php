<?php

namespace Estandar\Core\Interest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * InterestContext — adapter de traducción (contexto de dominio → señales).
 *
 * INTEREST_EVOLUTION_ENGINE_V1 (IEE-2, ADR-0044).
 *
 * SOLO TRADUCE DATOS. No contiene reglas de negocio ni toma decisiones:
 * decide() es quien interpreta. Este adapter mapea un contexto del mundo
 * real (ya resuelto por el productor) a la lista de señales canónicas y a
 * un contexto normalizado para InterestEngine::decide().
 *
 * Los adapters de cada Suite (estandar-leads, estandar-flow/capture,
 * chatbot, analytics…) entregan aquí SOLO hechos; este traduce a señales.
 *
 * Reglas de mapeo (puramente de traducción):
 *   - submission_count 1  → first_form
 *   - submission_count 2  → first_form + second_form
 *   - submission_count 3+ → first_form + second_form + third_form
 *   - return_visit_after_capture → señal dedicada (IEE-3)
 *   - new_session            → señal new_session
 *   - channel de regreso      → direct_return / organic_return / ads_return
 *   - email_opened / clicked / whatsapp / chatbot / quote → señales
 *   - page_views >= umbral    → page_depth_high (si no, low)
 */
final class InterestContext
{
	const VERSION = 'IEE_V1';

	/** Umbral de páginas vistas para "navegación profunda". */
	const PAGE_VIEWS_HIGH = 5;

	/**
	 * Canales que representan un REGRESO (no la captura inicial).
	 *
	 * IMPORTANTE (IEE-7): 'referral' NO se mapea automáticamente.
	 * Un dominio de redirect/referral no debe convertirse en canal comercial
	 * sin evidencia suficiente. El adapter debe proporcionar el canal resuelto,
	 * no el referrer técnico crudo. Si solo se tiene el referrer técnico sin
	 * resolución, se omite la señal de canal de regreso.
	 */
	const RETURN_CHANNEL_MAP = array(
		'direct'    => 'direct_return',
		'organic'   => 'organic_return',
		'google_ads' => 'ads_return',
		'meta_ads'  => 'ads_return',
	);

	/**
	 * Traduce un contexto de dominio a señales canónicas.
	 *
	 * @param array $ctx ver build() para el contrato.
	 * @return array<int,string> ids de señales.
	 */
	public static function to_signals( array $ctx ): array
	{
		$signals = array();

		$count = max( 0, (int) ( $ctx['submission_count'] ?? 0 ) );
		if ( $count >= 1 ) {
			$signals[] = 'first_form';
		}
		if ( $count >= 2 ) {
			$signals[] = 'second_form';
		}
		if ( $count >= 3 ) {
			$signals[] = 'third_form';
		}

		if ( ! empty( $ctx['return_visit_after_capture'] ) ) {
			$signals[] = 'return_visit_after_capture';
		}
		if ( ! empty( $ctx['new_session'] ) && $count >= 1 ) {
			$signals[] = 'new_session';
		}

		$behavior = isset( $ctx['behavior'] ) && is_array( $ctx['behavior'] ) ? $ctx['behavior'] : array();
		if ( ! empty( $behavior['email_opened'] ) ) {
			$signals[] = 'email_opened';
		}
		if ( ! empty( $behavior['email_clicked'] ) ) {
			$signals[] = 'email_click';
		}
		if ( ! empty( $behavior['whatsapp_opened'] ) ) {
			$signals[] = 'whatsapp_opened';
		}
		if ( ! empty( $behavior['chatbot_started'] ) ) {
			$signals[] = 'chatbot_start';
		}
		if ( ! empty( $behavior['quote_created'] ) ) {
			$signals[] = 'quote_created';
		}

		$page_views = max( 0, (int) ( $behavior['page_views'] ?? 0 ) );
		if ( $page_views > 0 ) {
			$signals[] = $page_views >= self::PAGE_VIEWS_HIGH ? 'page_depth_high' : 'page_depth_low';
		}

		// Canal de regreso (solo cuando NO es el primer ingreso).
		$channel = sanitize_key( (string) ( $ctx['channel'] ?? '' ) );
		if ( $count >= 2 && isset( self::RETURN_CHANNEL_MAP[ $channel ] ) ) {
			$signals[] = self::RETURN_CHANNEL_MAP[ $channel ];
		}

		return array_values( array_unique( $signals ) );
	}

	/**
	 * Normaliza el contexto de dominio para InterestEngine::decide().
	 *
	 * Contrato de entrada (los adapters de cada Suite entregan estos hechos):
	 *   'submission_count'           int
	 *   'is_commercial_recontact'    bool
	 *   'return_visit_after_capture' bool   (IEE-3)
	 *   'new_session'                bool
	 *   'channel'                    string ('direct','organic','google_ads','meta_ads','referral','')
	 *   'behavior'                   array{ email_opened, email_clicked, whatsapp_opened,
	 *                                     chatbot_started, quote_created, page_views }
	 *   'operator_assigned'          bool
	 *   'contact_registered'         bool
	 *   'classification_done'        bool
	 *   'elapsed_hours'              int    ← backward-compat (usa operator_actionable si no se especifica).
	 *   'cooling_hours'              int (opcional)
	 *   'sla_violated'               bool
	 *
	 *   IEE-7 — dimensiones de tiempo expandidas (todas opcionales):
	 *   'chronological_age_hours'         int   horas totales desde captura.
	 *   'operator_actionable_hours'       int   horas desde que es accionable.
	 *   'time_deferred_by_policy_hours'   int   horas diferidas por política.
	 *   'hours_since_last_engagement'     int   horas desde última señal del prospecto.
	 *   'hours_since_last_operator_action' int  horas desde última acción del operador.
	 *   'hours_since_delivery'            int   horas desde entrega de notificación.
	 *
	 *   IEE-7 — engagement signals (señales que pueden suprimir cooling):
	 *   'recent_engagement'              bool   hubo interacción reciente del prospecto.
	 *   'has_positive_engagement'        bool   interacción explícita con CTA.
	 *
	 * @param array $ctx
	 * @return array contexto listo para InterestEngine::decide().
	 */
	public static function build( array $ctx ): array
	{
		$built = array(
			'signals'                 => self::to_signals( $ctx ),
			'channel'                 => (string) ( $ctx['channel'] ?? '' ),
			'submission_count'        => max( 0, (int) ( $ctx['submission_count'] ?? 0 ) ),
			'is_commercial_recontact' => ! empty( $ctx['is_commercial_recontact'] ),
			'operator_assigned'       => ! empty( $ctx['operator_assigned'] ),
			'contact_registered'      => ! empty( $ctx['contact_registered'] ),
			'classification_done'     => ! empty( $ctx['classification_done'] ),
			'quote_created'           => ! empty( $ctx['behavior']['quote_created'] ?? null ),
			'sla_violated'            => ! empty( $ctx['sla_violated'] ),
		);

		// IEE-7: construir TimeContext si hay dimensiones expandidas.
		$has_time_dims = isset( $ctx['operator_actionable_hours'] )
			|| isset( $ctx['time_deferred_by_policy_hours'] )
			|| isset( $ctx['hours_since_last_engagement'] );

		if ( $has_time_dims ) {
			$built['time_context'] = new TimeContext( array(
				'chronological_age_hours'          => (int) ( $ctx['chronological_age_hours'] ?? $ctx['elapsed_hours'] ?? 0 ),
				'operator_actionable_hours'        => (int) ( $ctx['operator_actionable_hours'] ?? $ctx['elapsed_hours'] ?? 0 ),
				'time_deferred_by_policy_hours'    => (int) ( $ctx['time_deferred_by_policy_hours'] ?? 0 ),
				'hours_since_last_engagement'      => (int) ( $ctx['hours_since_last_engagement'] ?? $ctx['elapsed_hours'] ?? 0 ),
				'hours_since_last_operator_action' => (int) ( $ctx['hours_since_last_operator_action'] ?? $ctx['elapsed_hours'] ?? 0 ),
				'hours_since_delivery'             => (int) ( $ctx['hours_since_delivery'] ?? 0 ),
			) );
		}

		// IEE-7: engagement signals para supresión de cooling.
		if ( ! empty( $ctx['recent_engagement'] ) ) {
			$built['recent_engagement'] = true;
		}
		if ( ! empty( $ctx['has_positive_engagement'] ) ) {
			$built['has_positive_engagement'] = true;
		}

		// backward-compat: elapsed_hours como fallback.
		$built['elapsed_hours'] = isset( $ctx['elapsed_hours'] )
			? max( 0, (int) $ctx['elapsed_hours'] )
			: ( isset( $built['time_context'] ) ? $built['time_context']->toElapsedCompat() : 0 );

		$built['cooling_hours'] = isset( $ctx['cooling_hours'] ) ? (int) $ctx['cooling_hours'] : 0;

		return $built;
	}
}
