<?php

namespace Estandar\Core\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OperatorNotifier — notificación por correo al operador (v2, estandar-core).
 *
 * Port del `Estandar_Leads_Notifier` legacy al v2 con el correo ENRIQUECIDO
 * (NOTIFICATION_ENRICHMENT_V1: contexto de negocio, score numérico, SLA/
 * antigüedad, acción sugerida con gesto óptimo y link al IA Context).
 *
 * Estrategia de migración incremental (decisión 2026-08-24):
 *   - Escucha el MISMO evento `estandar_lead_ingested` (se emite tras la
 *     escritura al CRM es_*, que hoy sigue corriendo vía LegacyMap) para tener
 *     el contexto completo del lead (score, SLA, recontactos).
 *   - Cuando está HABILITADO, suprime el hook del notifier legacy (solo el
 *     email) y envía el correo enriquecido con el motor v2 (Core\Mail).
 *   - Política/routing/horarios/tokens legacy se REUTILIZAN con guards
 *     (class_exists): siguen siendo la fuente de verdad mientras el legacy
 *     esté presente; al retirar estandar-leads habrá que portar la ingesta.
 *   - Fallback: si algo falla en el envío v2, se delega al notifier legacy
 *     para no perder la notificación al operador.
 *
 * Aditivo y retrocompatible: el toggle (`estandar_suite_core_leads_notifier_enabled`)
 * controla si toma el control (default ON para verlo en el próximo deploy).
 */
class OperatorNotifier {

	const OPTION_ENABLED = 'estandar_suite_core_leads_notifier_enabled';
	const EMAIL_TEMPLATE = 'operator-notification.php';

	public static function init(): void
	{
		// Después de LegacyMap (init prio 20) para poder remover el hook legacy.
		add_action( 'init', array( __CLASS__, 'register' ), 25 );
	}

	/** ¿El notifier v2 toma el control? Option + filter (default ON). */
	public static function enabled(): bool
	{
		$value = get_option( self::OPTION_ENABLED, '1' );
		return (bool) apply_filters(
			'estandar_suite_core_leads_notifier_enabled',
			$value !== '0' && $value !== 0 && $value !== false && $value !== ''
		);
	}

	public static function set_enabled( bool $on ): void
	{
		update_option( self::OPTION_ENABLED, $on ? '1' : '0', false );
	}

	/** Suprime el email legacy y registra el hook v2 (solo si está habilitado). */
	public static function register(): void
	{
		if ( ! self::enabled() ) {
			return;
		}
		if ( class_exists( 'Estandar_Leads_Notifier' ) ) {
			remove_action( 'estandar_lead_ingested', array( 'Estandar_Leads_Notifier', 'notify_operators' ), 25 );
		}
		add_action( 'estandar_lead_ingested', array( __CLASS__, 'notify_operators' ), 25, 4 );
	}

	/**
	 * Entrypoint del evento. Envuelve en try/catch y, ante excepción, delega
	 * al notifier legacy (fallback) para no perder la notificación.
	 */
	public static function notify_operators( $lead_id, $submission_id, $is_new_lead, $payload ): void
	{
		try {
			self::dispatch( (int) $lead_id, (int) $submission_id, (bool) $is_new_lead, is_array( $payload ) ? $payload : array() );
		} catch ( \Throwable $e ) {
			self::telemetry( 'error', 'V2_NOTIFIER_EXCEPTION', 'v2 notifier threw; falling back to legacy notifier.', array(
				'lead_id'       => absint( $lead_id ),
				'submission_id' => absint( $submission_id ),
				'exception'     => get_class( $e ),
			) );
			if ( class_exists( 'Estandar_Leads_Notifier' ) ) {
				try {
					\Estandar_Leads_Notifier::notify_operators( $lead_id, $submission_id, $is_new_lead, $payload );
				} catch ( \Throwable $e2 ) {
					self::telemetry( 'error', 'V2_NOTIFIER_FALLBACK_FAILED', 'Legacy fallback also failed.', array(
						'lead_id'       => absint( $lead_id ),
						'submission_id' => absint( $submission_id ),
						'exception'     => get_class( $e2 ),
					) );
				}
			}
		}
	}

	/** Flujo completo (port fiel del notifier legacy, email v2). */
	public static function dispatch( int $lead_id, int $submission_id, bool $is_new_lead, array $payload ): void
	{
		global $wpdb;

		// FUNNEL_V1 (reactivado 2026-09-22): el modo del form manda.
		//   ''/heredado → aviso simple del form + correo enriquecido del operador.
		//   'funnel'    → SOLO el correo del operador (el aviso simple se suprime
		//                 en Actions::run).
		//   'simple'    → SOLO el aviso simple; aquí se omite el enriquecido (ver
		//                 gate más abajo), pero el lead igual queda en es_leads.

		// COMMERCIAL_QUALIFICATION_V1 — routing policy gate (misma semántica que legacy).
		$qualification = null;
		if ( class_exists( 'Estandar_Commercial_Qualification_Engine' ) ) {
			$qualification = \Estandar_Commercial_Qualification_Engine::decision_for( $lead_id, $submission_id, $payload ?: null );
			if ( is_array( $qualification ) && self::routing_policy_gate( $lead_id, $submission_id, $qualification ) === false ) {
				return;
			}
		}

		$operators = $wpdb->get_results(
			"SELECT id, name, email, project_slug, area, allowed_projects_json FROM {$wpdb->prefix}es_operators WHERE active = 1 AND email <> '' ORDER BY name ASC"
		);
		if ( empty( $operators ) ) {
			// ADMIN_FALLBACK_V1 (extensión): SIN operadores activos el aviso NO
			// se pierde. Se sigue el flujo normal y `resolve_target_operators()`
			// notifica al administrador de la web (último y peor de los casos).
			self::telemetry( 'warning', 'V2_NOTIFIER_NO_OPERATORS_ADMIN_FALLBACK', 'No active operators; falling back to site admin.', array(
				'lead_id'       => $lead_id,
				'submission_id' => $submission_id,
			) );
		}

		$contact      = isset( $payload['contact'] ) && is_array( $payload['contact'] ) ? $payload['contact'] : array();
		$attr         = isset( $payload['attribution'] ) && is_array( $payload['attribution'] ) ? $payload['attribution'] : array();
		$context      = isset( $payload['context'] ) && is_array( $payload['context'] ) ? $payload['context'] : array();
		$project_area = sanitize_text_field( (string) ( $context['project_area'] ?? '' ) );

		// FUNNEL_V1: form en modo 'simple' → el aviso simple del form ya notifica
		// a los correos configurados; aquí NO se envía el correo enriquecido del
		// operador (el lead igual queda registrado en es_leads).
		if ( sanitize_key( (string) ( $context['funnel_mode'] ?? '' ) ) === 'simple' ) {
			self::insert_event( $lead_id, 'notification_skipped_funnel_simple', array(
				'submission_id' => $submission_id,
			) );
			return;
		}

		$project_slug = '';
		if ( class_exists( 'Estandar_Leads_Projects' ) ) {
			$project_slug = \Estandar_Leads_Projects::resolve_slug( (string) ( $context['project_slug'] ?? '' ), $project_area );
		} elseif ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) ) {
			// v2: mismo resolver que la ingesta (registry de la tabla fuente).
			$project_slug = \Estandar\Core\Leads\ProjectRegistry::resolve_slug( (string) ( $context['project_slug'] ?? '' ), $project_area );
		} else {
			$project_slug = sanitize_key( (string) ( $context['project_slug'] ?? $context['project'] ?? '' ) );
		}

		$submission_project = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.context_json, s.source_plugin, s.submitted_at,
			        s.utm_source, s.utm_medium, s.utm_campaign, s.utm_term, s.utm_content,
			        s.landing_url, s.referrer, s.session_id,
			        l.project_slug AS lead_project_slug
			 FROM {$wpdb->prefix}es_lead_submissions s
			 INNER JOIN {$wpdb->prefix}es_leads l ON l.id = s.lead_id
			 WHERE s.id = %d LIMIT 1",
			$submission_id
		) );
		if ( $submission_project ) {
			foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_url', 'referrer', 'session_id' ) as $field ) {
				$attr[ $field ] = (string) ( $submission_project->{$field} ?? '' );
			}
			if ( class_exists( 'Estandar_Leads_Repository' ) ) {
				$canonical = \Estandar_Leads_Repository::canonical_project( null, $submission_project );
				$project_slug = $canonical === \Estandar_Leads_Repository::UNRESOLVED_PROJECT ? '' : $canonical;
			}
		}
		$source_plugin = sanitize_key( (string) ( $submission_project->source_plugin ?? ( $payload['source_plugin'] ?? '' ) ) );

		if ( $project_slug === '' ) {
			self::record_routing_event( $lead_id, $submission_id, $project_area, $source_plugin );
			return;
		}

		// Routing a operadores por proyecto.
		$routing = array( 'operators' => array(), 'fallback' => true, 'routing_mode' => 'fallback_no_match' );
		if ( class_exists( 'Estandar_Leads_Operator_Resolver' ) ) {
			$routing = \Estandar_Leads_Operator_Resolver::resolve_for_project( $project_slug, array(
				'operators'    => $operators,
				'require_email' => true,
			) );
		} else {
			$matched = array();
			foreach ( $operators as $_op ) {
				if ( in_array( $project_slug, array( $_op->project_slug, $_op->area ), true ) ) {
					$matched[] = $_op;
				}
			}
			$routing = array(
				// ADMIN_FALLBACK_V1: sin match NO se difunde a todos (ver abajo);
				// el fallback notifica solo al administrador de la web.
				'operators'    => $matched,
				'fallback'     => empty( $matched ),
				'routing_mode' => empty( $matched ) ? 'fallback' : 'project_match',
			);
		}
		// ADMIN_FALLBACK_V1 (2026-09-17): cuando el proyecto está RESUELTO pero
		// ningún operador lo tiene asignado, NO se difunde a todos los
		// operadores (generaba un correo por operador, incl. a quien no
		// corresponde). Se avisa ÚNICAMENTE al administrador de la web.
		list( $target_operators, $admin_fallback ) = self::resolve_target_operators( $routing, $project_slug, $lead_id );
		if ( ! empty( $routing['fallback'] ) ) {
			self::telemetry( 'warning', 'V2_NOTIFIER_NO_OPERATOR_ADMIN_FALLBACK', 'No operator matched project; notifying admin only (no broadcast).', array(
				'lead_id' => $lead_id, 'project_slug' => $project_slug,
				'admin_fallback' => $admin_fallback,
			) );
			self::insert_event( $lead_id, 'routing_fallback_used', array(
				'submission_id' => $submission_id,
				'metadata'      => array(
					'routing_mode'    => $admin_fallback ? 'admin_fallback' : 'fallback_no_admin',
					'project_slug'    => $project_slug,
					'operators_count' => count( $target_operators ),
				),
			) );
		}
		if ( empty( $target_operators ) ) {
			self::insert_event( $lead_id, 'notification_zero_delivery', array(
				'submission_id' => $submission_id,
				'metadata'      => array(
					'reason'       => 'no_operator_no_admin',
					'project_slug' => $project_slug,
				),
			) );
			return;
		}

		// Notification policy gate (SKIP) — misma semántica que legacy.
		if ( is_array( $qualification )
			&& ( $qualification['notification_policy'] ?? ( class_exists( 'Estandar_Commercial_Qualification_Engine' ) ? \Estandar_Commercial_Qualification_Engine::NOTIFY : 'notify' ) )
				=== ( class_exists( 'Estandar_Commercial_Qualification_Engine' ) ? \Estandar_Commercial_Qualification_Engine::SKIP : 'skip' ) ) {
			self::insert_event( $lead_id, 'notification_skipped_by_policy', array(
				'submission_id' => $submission_id,
				'reason'        => sanitize_text_field( (string) ( $qualification['policy_reason'] ?? '' ) ),
				'metadata'      => array(
					'project_slug'  => $project_slug,
					'matched_rules' => (array) ( $qualification['matched_rules'] ?? array() ),
					'policy_reason' => (string) ( $qualification['policy_reason'] ?? '' ),
				),
			) );
			return;
		}

		// LEAD-INVARIANT-001: asignación del operador tras los gates.
		// ADMIN_FALLBACK_V1: si el aviso va al admin (sin operador del proyecto),
		// NO se asigna operador al lead (queda sin asignar, es el dato correcto).
		$already_assigned = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
			 WHERE lead_id = %d AND submission_id = %d AND event_type = 'operator_assigned'",
			$lead_id, $submission_id
		) );
		if ( $already_assigned === 0 && ! $admin_fallback ) {
			$primary = $target_operators[0];
			$now = \Estandar\Core\Time::now();
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}es_leads
				 SET assigned_operator_id = %d, assigned_operator_email = %s,
				     assigned_operator_name = %s, assigned_at = %s, updated_at = %s
				 WHERE id = %d AND (assigned_operator_id IS NULL OR assigned_operator_id = 0)",
				absint( $primary->id ), sanitize_email( $primary->email ),
				sanitize_text_field( $primary->name ), $now, $now, $lead_id
			) );
			foreach ( $target_operators as $_op ) {
				self::insert_event( $lead_id, 'operator_assigned', array(
					'submission_id' => $submission_id,
					'operator_id'   => absint( $_op->id ),
					'to_status'     => $project_slug,
					'metadata'      => array(
						'project_slug' => $project_slug,
						'routing_mode' => $routing['fallback'] ? 'fallback' : ( $routing['routing_mode'] ?? 'project_match' ),
						'source'       => 'v2_notifier_routing',
					),
				) );
			}
		}

		// OPERATIONAL_HOURS_PER_OPERATOR_V1 — diferir operadores fuera de horario.
		list( $open_operators, $deferred_operators ) = self::split_operators_by_hours( $lead_id, $submission_id, $project_slug, $target_operators, $source_plugin );
		if ( empty( $open_operators ) ) {
			self::insert_event( $lead_id, 'notification_zero_delivery', array(
				'submission_id' => $submission_id,
				'metadata'      => array(
					'reason'         => 'all_operators_closed',
					'project_slug'   => $project_slug,
					'deferred_count' => count( $deferred_operators ),
				),
			) );
			return;
		}
		$target_operators = $open_operators;

		// RC1 — debounce/consolidación (misma semántica que legacy).
		if ( self::should_consolidate( $lead_id, $submission_id, $contact, $project_slug, $source_plugin ) ) {
			return;
		}

		// Instrumentación P2 (pasiva, misma que legacy).
		$contact_sig = md5( sanitize_email( (string) ( $contact['email'] ?? '' ) ) . '|' . sanitize_text_field( (string) ( $contact['phone'] ?? '' ) ) );
		$recent_key  = 'es_notif_recent_' . $contact_sig;
		$previous    = get_transient( $recent_key );
		if ( $previous ) {
			self::telemetry( 'warning', 'V2_NOTIFIER_POSSIBLE_DUPLICATE', 'Operator notification fired for a recently-notified contact.', array(
				'lead_id' => $lead_id, 'submission_id' => $submission_id,
				'seconds_apart' => time() - (int) ( $previous['ts'] ?? 0 ),
			) );
		}
		set_transient( $recent_key, array( 'source_plugin' => $source_plugin, 'ts' => time() ), 10 * MINUTE_IN_SECONDS );

		// Datos del lead + enriquecimiento.
		$lead_state = $wpdb->get_row( $wpdb->prepare(
			"SELECT l.quality, l.name, l.email, l.phone, l.priority, l.temperature,
			        l.score_components_json, l.feedback_status, l.created_at,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id) AS submission_count,
			        (SELECT MIN(s.submitted_at) FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id) AS first_submitted_at,
			        (SELECT MAX(s.submitted_at) FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id) AS last_submitted_at
			 FROM {$wpdb->prefix}es_leads l WHERE l.id = %d LIMIT 1",
			$lead_id
		) );
		if ( ! $lead_state ) {
			return;
		}

		$recontact_ctx = self::contact_recontact_context( $lead_id, $submission_id );
		$enriched      = self::enrichment( $lead_state, $recontact_ctx );
		$journey       = self::journey( $lead_id );
		$campaign_name = self::campaign_name( (string) ( $attr['utm_campaign'] ?? '' ) );

		$settings = self::email_settings();

		// REPLY_TO_LEAD_V1 (paridad con el aviso del form): el botón "Responder"
		// del operador va al email del prospecto. Si el sitio fuerza otro
		// Reply-To vía wp_mail filter (p. ej. cliente3.example.com), eso lo pisa —
		// pero el suite no lo fuerza.
		$reply_to = (string) ( $contact['email'] ?? '' );
		if ( $reply_to !== '' && class_exists( 'Estandar\\Core\\Mail' ) && ! \Estandar\Core\Mail::validate_address( $reply_to ) ) {
			$reply_to = '';
		}

		foreach ( $target_operators as $operator ) {
			$data = self::email_data( array(
				'lead_state'    => $lead_state,
				'enriched'      => $enriched,
				'recontact_ctx' => $recontact_ctx,
				'journey'       => $journey,
				'campaign_name' => $campaign_name,
				'operator'      => $operator,
				'lead_id'       => $lead_id,
				'submission_id' => $submission_id,
				'payload'       => $payload,
				'contact'       => $contact,
				'attr'          => $attr,
				'context'       => $context,
				'project_slug'  => $project_slug,
				'project_area'  => $project_area,
				'source_plugin' => $source_plugin,
				'submission_project' => $submission_project,
			) );

			$subject = self::build_subject( $data );
			$settings['subject'] = $subject; // el título del HTML refleja el asunto final
			$html    = self::render_email( $data, $settings );
			$sent    = \Estandar\Core\Mail::send( array(
				'to'        => sanitize_email( $operator->email ),
				'cc'        => self::cc(),
				'subject'   => $subject,
				'html'      => $html,
				'from_name' => $settings['sender_name'],
				'from_email'=> $settings['sender_email'],
				'reply_to'  => $reply_to,
			) );

			if ( empty( $sent['ok'] ) ) {
				self::telemetry( 'error', 'V2_NOTIFIER_MAIL_FAILED', 'Operator notification (v2) email failed.', array(
					'lead_id' => $lead_id, 'operator_id' => absint( $operator->id ),
				) );
			} else {
				self::insert_event( $lead_id, 'operator_notified', array(
					'submission_id' => $submission_id,
					'operator_id'   => absint( $operator->id ),
					'metadata'      => array(
						'channel'       => 'email',
						'email'         => sanitize_email( $operator->email ),
						'routing_mode'  => $admin_fallback ? 'admin_fallback' : ( $routing['fallback'] ? 'fallback' : 'project' ),
						'project_slug'  => $project_slug,
						'source'        => 'v2_notifier',
						'subject'       => $subject,
					),
				) );
			}
		}

		// ROUTING_FIELD_V1: los correos extra de la opción de routing también
		// reciben el correo enriquecido (mismo contenido, contexto del operador
		// principal) para que la notificación llegue "a los mails del proyecto
		// + los extra" configurados en la opción.
		$extra_emails = isset( $context['notify_extra'] )
			? \Estandar\Core\Mail::normalize_addresses( (string) $context['notify_extra'] )
			: array();
		if ( $extra_emails && ! empty( $target_operators ) ) {
			foreach ( $extra_emails as $extra_to ) {
				$ed   = self::email_data( array(
					'lead_state'    => $lead_state,
					'enriched'      => $enriched,
					'recontact_ctx' => $recontact_ctx,
					'journey'       => $journey,
					'campaign_name' => $campaign_name,
					'operator'      => $target_operators[0],
					'lead_id'       => $lead_id,
					'submission_id' => $submission_id,
					'payload'       => $payload,
					'contact'       => $contact,
					'attr'          => $attr,
					'context'       => $context,
					'project_slug'  => $project_slug,
					'project_area'  => $project_area,
					'source_plugin' => $source_plugin,
					'submission_project' => $submission_project,
				) );
				$esubject = self::build_subject( $ed );
				$ehtml    = self::render_email( $ed, $settings );
				\Estandar\Core\Mail::send( array(
					'to'        => $extra_to,
					'cc'        => self::cc(),
					'subject'   => $esubject,
					'html'      => $ehtml,
					'from_name' => $settings['sender_name'],
					'from_email'=> $settings['sender_email'],
					'reply_to'  => $reply_to,
				) );
				self::insert_event( $lead_id, 'operator_notified', array(
					'submission_id' => $submission_id,
					'operator_id'   => absint( $target_operators[0]->id ),
					'metadata'      => array(
						'channel'       => 'email',
						'email'         => $extra_to,
						'routing_mode'  => 'routing_extra',
						'project_slug'  => $project_slug,
						'source'        => 'v2_notifier',
						'subject'       => $esubject,
					),
				) );
			}
		}
	}

	/**
	 * ADMIN_FALLBACK_V1 — resuelve los destinatarios del aviso enriquecido.
	 *
	 * Con match de proyecto → los operadores del proyecto (routing normal).
	 * Sin match → NUNCA difunde a todos: devuelve SOLO el administrador de la
	 * web (por defecto `admin_email`, configurable con el filtro
	 * `estandar_suite_core_notifier_admin_fallback_email`).
	 *
	 * @param array  $routing      salida del resolver (operators/fallback).
	 * @param string $project_slug proyecto resuelto del lead.
	 * @param int    $lead_id      id del lead (contexto del filtro).
	 * @return array{0: array, 1: bool} [ operadores, admin_fallback ]
	 */
	public static function resolve_target_operators( array $routing, string $project_slug, int $lead_id ): array
	{
		$target = empty( $routing['fallback'] ) ? (array) ( $routing['operators'] ?? array() ) : array();
		$admin_fallback = false;
		if ( empty( $target ) ) {
			$admin_email = sanitize_email( (string) apply_filters(
				'estandar_suite_core_notifier_admin_fallback_email',
				(string) get_option( 'admin_email' ),
				$project_slug,
				$lead_id
			) );
			if ( $admin_email !== '' ) {
				$admin_fallback = true;
				$target         = array( (object) array(
					'id'                    => 0,
					'name'                  => 'Administrador',
					'email'                 => $admin_email,
					'project_slug'          => $project_slug,
					'area'                  => '',
					'allowed_projects_json' => null,
				) );
			}
		}
		return array( $target, $admin_fallback );
	}

	// ── Enriquecimiento (NOTIFICATION_ENRICHMENT_V1) ────────────────────────

	/**
	 * Puro: dado el estado del lead y el contexto de recontacto, computa score,
	 * SLA/antigüedad, señales y flags. Tests sobre esto con fixtures.
	 */
	public static function enrichment( $lead_state, array $recontact_ctx ): array
	{
		$score_components = json_decode( (string) ( $lead_state->score_components_json ?? '' ), true );
		$score_components = is_array( $score_components ) ? $score_components : array();

		$submission_count = (int) ( $lead_state->submission_count ?? 0 );
		$sin_gestion      = trim( (string) ( $lead_state->quality ?? '' ) ) === '';
		$behavior_score   = (float) ( $score_components['behavior'] ?? 0 );
		$temperature      = strtoupper( (string) ( $lead_state->temperature ?? '' ) );

		$lead_age_days = 0;
		$first_ts = \Estandar\Core\Time::epoch( (string) ( $lead_state->first_submitted_at ?? '' ) );
		if ( ! $first_ts ) {
			$first_ts = \Estandar\Core\Time::epoch( (string) ( $lead_state->created_at ?? '' ) );
		}
		if ( $first_ts ) {
			$lead_age_days = (int) floor( max( 0, time() - $first_ts ) / DAY_IN_SECONDS );
		}

		$feedback_status = strtolower( (string) ( $lead_state->feedback_status ?? '' ) );
		if ( in_array( $feedback_status, array( 'escalated', 'overdue' ), true ) ) {
			$sla_state = $feedback_status;
		} elseif ( $lead_age_days >= 2 ) {
			$sla_state = 'overdue';
		} else {
			$sla_state = 'within';
		}

		return array(
			'score_total'    => (float) ( $score_components['total'] ?? 0 ),
			'score_behavior' => $behavior_score,
			'score_capture'  => (float) ( $score_components['capture'] ?? 0 ),
			'lead_age_days'  => $lead_age_days,
			'sla_state'      => $sla_state,
			'submission_count' => $submission_count,
			'sin_gestion'    => $sin_gestion,
			'repeated_lead'  => $submission_count > 1,
			'behavior_high_intent' => $behavior_score > 0 && $temperature === 'CALIENTE',
			'eval_temperature'  => self::human_temperature( $temperature ),
			'eval_priority_reason' => self::human_priority_reason( (string) ( $score_components['priority_reason'] ?? '' ) ),
			'eval_behavior_signals' => self::human_behavior_signals( (array) ( $score_components['behavior_detail'] ?? array() ) ),
			'commercial_recontact_count' => (int) ( $recontact_ctx['commercial_recontact_count'] ?? 0 ),
			'is_commercial_recontact'    => ! empty( $recontact_ctx['is_commercial_recontact'] ),
			'is_same_session_contact'    => ! empty( $recontact_ctx['is_same_session_contact'] ),
			'commercial_risk_signal'     => ! empty( $recontact_ctx['is_commercial_recontact'] ) && $sin_gestion,
		);
	}

	/** Puro: asunto del correo (misma semántica que legacy). */
	public static function build_subject( array $data ): string
	{
		$e    = (array) ( $data['enriched'] ?? array() );
		$name = sanitize_text_field( (string) ( $data['lead_name'] ?? $data['lead_state']->name ?? 'Lead' ) );

		if ( ! empty( $e['commercial_risk_signal'] ) ) {
			$flames = str_repeat( '🔥', min( $e['commercial_recontact_count'], 5 ) );
			return '⚠ Lead reincidente sin gestión: ' . $flames . ' Recontacto x' . $e['commercial_recontact_count'] . ' - ' . $name;
		}
		if ( ! empty( $e['is_commercial_recontact'] ) ) {
			$flames = str_repeat( '🔥', min( $e['commercial_recontact_count'], 5 ) );
			return $flames . ' Recontacto x' . $e['commercial_recontact_count'] . ': ' . $name;
		}
		if ( ! empty( $e['behavior_high_intent'] ) ) {
			return '🔥 Lead de alta intención: ' . $name;
		}
		// SUBJECT_TEMPLATE_V1: asunto configurable en settings (no hardcodeado).
		// Tokens: {proyecto}/{project} · {nombre}/{name}. Default:
		// "Nuevo Lead de {proyecto}: {name}".
		$project = '';
		if ( function_exists( 'resolve_project_display_name' ) ) {
			$project = (string) resolve_project_display_name( (string) ( $data['project_slug'] ?? '' ), (string) ( $data['project_area'] ?? '' ) );
		}
		if ( $project === '' ) {
			$project = (string) ( $data['project_slug'] ?? '' );
		}
		$subject_tpl = trim( (string) ( self::email_settings()['subject'] ?? '' ) );
		if ( $subject_tpl === '' ) {
			return 'Lead nuevo: ' . $name;
		}
		$subject = trim( str_replace(
			array( '{proyecto}', '{project}', '{nombre}', '{name}' ),
			array( $project, $project, $name, $name ),
			$subject_tpl
		) );
		return $subject !== '' ? $subject : ( 'Lead nuevo: ' . $name );
	}

	public static function human_temperature( string $temperature ): string
	{
		$map = array(
			'CALIENTE' => 'Caliente', 'TIBIO' => 'Tibio', 'FRIO' => 'Frío',
			'COLD' => 'Frío', 'WARM' => 'Tibio', 'HOT' => 'Caliente',
		);
		return isset( $map[ strtoupper( $temperature ) ] ) ? $map[ strtoupper( $temperature ) ] : '';
	}

	public static function human_priority_reason( string $key ): string
	{
		$map = array(
			'caliente_sin_gestion_con_senal'          => 'Lead caliente, sin gestión y con señales de comportamiento activas.',
			'caliente_sin_gestion'                    => 'Lead caliente sin gestión registrada.',
			'caliente_en_gestion'                     => 'Lead caliente con gestión en curso.',
			'tibio_recontacto_sin_gestion'            => 'Lead tibio con recontacto y sin gestión.',
			'tibio_engagement_reciente_sin_gestion'   => 'Lead tibio con interacción reciente y sin gestión.',
			'tibio_perfil_comercial_alto_sin_gestion' => 'Lead tibio con perfil comercial alto y sin gestión.',
			'tibio'                                   => 'Lead tibio: captura completa pero sin señales de comportamiento.',
			'frio_engagement_reciente_sin_gestion'    => 'Lead frío con interacción reciente y sin gestión.',
			'frio_perfil_comercial_alto_sin_gestion'  => 'Lead frío con perfil comercial alto y sin gestión.',
			'frio'                                    => 'Lead frío: sin señales de comportamiento recientes.',
			// INVALID_REASON_V1: el scorer excluyó el lead (invalid) y su total
			// quedó en 0 — explicar en el correo en vez de un 0 sin contexto.
			'invalid'                                 => 'Lead marcado como inválido por un operador (score 0).',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	public static function human_behavior_signals( array $detail ): array
	{
		$labels = array(
			'lead_recontacted'               => 'Volvió a contactar',
			'autoresponse_engaged'           => 'Interactuó con el correo (clic)',
			'autoresponse_link_click'        => 'Hizo clic en un enlace del correo',
			'autoresponse_email_open_signal' => 'Abrió el correo',
			'autoresponse_open_classified'   => 'Apertura del correo clasificada',
		);
		$out = array();
		foreach ( $detail as $ev ) {
			$type = (string) ( is_array( $ev ) ? ( $ev['type'] ?? '' ) : '' );
			if ( isset( $labels[ $type ] ) && ! in_array( $labels[ $type ], $out, true ) ) {
				$out[] = $labels[ $type ];
			}
		}
		return $out;
	}

	// ── Recontacto, journey, campaña (misma semántica que legacy) ───────────

	public static function contact_recontact_context( int $lead_id, int $current_submission_id ): array
	{
		global $wpdb;
		$window = (int) apply_filters( 'estandar_leads_recontact_session_window_seconds', 3 * HOUR_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, submitted_at FROM {$wpdb->prefix}es_lead_submissions
			 WHERE lead_id = %d ORDER BY submitted_at ASC, id ASC",
			$lead_id
		) );

		$result = array(
			'commercial_recontact_count' => 0,
			'is_commercial_recontact'    => false,
			'is_same_session_contact'    => false,
			'total_sessions'             => $rows ? 1 : 0,
			'session_window_hours'       => round( $window / HOUR_IN_SECONDS, 1 ),
		);
		if ( ! $rows || count( $rows ) < 2 ) {
			return $result;
		}

		$times = array();
		foreach ( $rows as $r ) {
			$times[] = array( 'id' => (int) $r->id, 'ts' => strtotime( $r->submitted_at ) );
		}
		$count = 0;
		for ( $i = 1; $i < count( $times ); $i++ ) {
			$gap = $times[ $i ]['ts'] - $times[ $i - 1 ]['ts'];
			if ( $gap > $window ) {
				$count++;
				if ( $times[ $i ]['id'] === $current_submission_id ) {
					$result['is_commercial_recontact'] = true;
				}
			} elseif ( $times[ $i ]['id'] === $current_submission_id ) {
				$result['is_same_session_contact'] = true;
			}
		}
		$result['commercial_recontact_count'] = $count;
		$result['total_sessions']             = $count + 1;
		return $result;
	}

	public static function journey( int $lead_id ): array
	{
		global $wpdb;
		if ( $lead_id < 1 ) {
			return array(
				'autoresponse_sent' => false, 'email_opened' => false, 'link_clicked' => false,
				'whatsapp_opened' => false, 'contact_registered' => false,
			);
		}
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT event_type FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d",
			$lead_id
		) );
		$types = is_array( $rows ) ? $rows : array();

		$has_human_open = false;
		if ( in_array( 'autoresponse_open_classified', $types, true ) ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
				 WHERE lead_id = %d AND event_type = 'autoresponse_open_classified'
				 AND (metadata LIKE %s OR metadata LIKE %s)",
				$lead_id,
				'%"open_classification":"probable_human_open"%',
				'%"open_classification":"confirmed_human_engagement"%'
			) );
			$has_human_open = $count > 0;
		}

		return array(
			'autoresponse_sent'  => in_array( 'autoresponse_email_sent', $types, true ),
			'email_opened'       => $has_human_open,
			'link_clicked'       => in_array( 'autoresponse_link_click', $types, true ) || in_array( 'autoresponse_engaged', $types, true ),
			'whatsapp_opened'    => in_array( 'whatsapp_clicked', $types, true ),
			'contact_registered' => in_array( 'contact_logged', $types, true ) || in_array( 'call_clicked', $types, true ) || in_array( 'email_clicked', $types, true ),
		);
	}

	public static function campaign_name( string $campaign_raw ): string
	{
		if ( $campaign_raw === '' ) {
			return '';
		}
		if ( class_exists( 'Estandar_Campaign_Label_Resolver' ) ) {
			$resolved = \Estandar_Campaign_Label_Resolver::campaign( $campaign_raw );
			if ( $resolved !== '' && strpos( $resolved, '(#' . $campaign_raw . ')' ) === false ) {
				return $resolved;
			}
		}
		return ctype_digit( $campaign_raw ) ? '' : $campaign_raw;
	}

	// ── Construcción de datos y render ──────────────────────────────────────

	public static function email_data( array $ctx ): array
	{
		$lead_state  = $ctx['lead_state'];
		$enriched    = $ctx['enriched'];
		$rc          = $ctx['recontact_ctx'];
		$operator    = $ctx['operator'];
		$payload     = $ctx['payload'];
		$contact     = $ctx['contact'];
		$attr        = $ctx['attr'];
		$context     = $ctx['context'];

		$lead_name = sanitize_text_field( (string) ( $contact['name'] ?? '' ) );
		if ( $lead_name === '' ) {
			$lead_name = sanitize_text_field( (string) ( $lead_state->name ?? 'Lead' ) );
		}

		$submission_count  = (int) $enriched['submission_count'];
		$is_commercial_rc  = (bool) $enriched['is_commercial_recontact'];
		$repeated_lead     = (bool) $enriched['repeated_lead'];
		$is_same_session   = (bool) $enriched['is_same_session_contact'];

		// IEE-5 (motor v2) con fallback a adapter legacy.
		$interest_decision = array( 'version' => 'IEE_V1', 'absent' => true );
		$interest_narratives = array();
		if ( class_exists( 'Estandar_Leads_Interest_Adapter' ) ) {
			$interest_decision = \Estandar_Leads_Interest_Adapter::decision_for( (int) $ctx['lead_id'], (int) $ctx['submission_id'], array(
				'lead_state'       => $lead_state,
				'recontact_ctx'    => $rc,
				'journey'          => $ctx['journey'],
				'attribution'      => $attr,
				'submission_count' => $submission_count,
			) );
			if ( is_array( $interest_decision ) && empty( $interest_decision['absent'] ) && class_exists( 'Estandar\\Core\\Interest\\InterestEngine' ) ) {
				$interest_narratives = \Estandar\Core\Interest\InterestEngine::narratives( $interest_decision, array(
					'lead_name' => $lead_name,
					'project'   => $ctx['project_slug'],
				) );
			}
		}

		$form_fields = is_array( $payload['meta']['sanitized_raw_fields'] ?? null ) ? $payload['meta']['sanitized_raw_fields'] : array();
		$message     = sanitize_textarea_field( (string) ( $payload['content']['message'] ?? '' ) );

		$previous_status = '';
		if ( class_exists( 'Estandar_Leads_Repository' ) ) {
			global $wpdb;
			$previous_status = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT from_status FROM {$wpdb->prefix}es_lead_events
				 WHERE lead_id = %d AND event_type IN ('status_changed','status_overridden') AND from_status != ''
				 ORDER BY created_at DESC, id DESC LIMIT 1",
				absint( $ctx['lead_id'] )
			) );
		}

		$matched_customer = false;
		if ( class_exists( 'Estandar_Leads_Customer_Service' ) && \Estandar_Leads_Customer_Service::get_by_lead( (int) $ctx['lead_id'] ) ) {
			$matched_customer = true;
		}

		$lead_fit = array();
		if ( class_exists( 'Estandar_Leads_Assessment_Engine' ) && class_exists( 'Estandar_Leads_Fit_Indicator' ) && \Estandar_Leads_Fit_Indicator::is_enabled() ) {
			$lead_fit = \Estandar_Leads_Assessment_Engine::read_lead_fit( (int) $ctx['lead_id'] );
		}

		// Tokens de clasificación y workspace (legacy guarded; siguen siendo fuente).
		// FEEDBACK_V2: si estandar-leads está retirado, el token lo crea el panel
		// v2 (LeadsPanel::create_feedback_url) — mismo enlace /?estandar_feedback=1&token=.
		$feedback_url = '';
		if ( class_exists( 'Estandar_Leads_Tokens' ) ) {
			$token = \Estandar_Leads_Tokens::create( (int) $ctx['lead_id'], absint( $operator->id ) );
			if ( $token !== '' ) {
				$feedback_url = \Estandar_Leads_Tokens::url( $token );
			}
		}
		if ( $feedback_url === '' && class_exists( 'Estandar\\Core\\LeadsPanel' ) ) {
			$feedback_url = \Estandar\Core\LeadsPanel::create_feedback_url( (int) $ctx['lead_id'], absint( $operator->id ) );
		}
		$workspace_url   = '';
		$workspace_backlog = array();
		if ( class_exists( 'Estandar_Leads_Operator_Workspace' ) ) {
			$workspace_token = \Estandar_Leads_Operator_Workspace::create_token( absint( $operator->id ) );
			$workspace_url = $workspace_token !== '' ? \Estandar_Leads_Operator_Workspace::workspace_url( $workspace_token ) : '';
			$workspace_backlog = \Estandar_Leads_Operator_Workspace::operator_backlog( absint( $operator->id ) );
		}

		$ai_context_url = home_url( '/panel-leads/?ai_context=' . absint( $ctx['lead_id'] ) );

		return array(
			'operator_name' => sanitize_text_field( $operator->name ),
			'operator_id'   => absint( $operator->id ),
			'lead_name'     => $lead_name,
			'lead_email'    => sanitize_email( (string) ( $contact['email'] ?? $lead_state->email ?? '' ) ),
			'lead_phone'    => sanitize_text_field( (string) ( $contact['phone'] ?? $lead_state->phone ?? '' ) ),
			'project_area'  => sanitize_text_field( $ctx['project_area'] ),
			'project_slug'  => sanitize_text_field( $ctx['project_slug'] ),
			'campaign'      => sanitize_text_field( (string) ( $attr['utm_campaign'] ?? '' ) ),
			'campaign_name' => $ctx['campaign_name'],
			'journey'       => $ctx['journey'],
			'ad'            => sanitize_text_field( (string) ( $attr['utm_content'] ?? '' ) ),
			'keyword'       => sanitize_text_field( (string) ( $attr['utm_term'] ?? '' ) ),
			'landing_url'   => esc_url_raw( (string) ( $attr['landing_url'] ?? '' ) ),
			'utm_source'    => sanitize_text_field( (string) ( $attr['utm_source'] ?? '' ) ),
			'utm_medium'    => sanitize_text_field( (string) ( $attr['utm_medium'] ?? '' ) ),
			'submitted_at'  => sanitize_text_field( (string) ( $ctx['submission_project']->submitted_at ?? ( $payload['submitted_at'] ?? '' ) ) ),
			'submission_count' => $submission_count,
			'lead_type' => $is_commercial_rc ? 'Recontacto comercial' : ( $repeated_lead ? 'Recurrente' : 'Nuevo' ),
			'submission_position' => $submission_count,
			'commercial_recontact_count' => (int) $enriched['commercial_recontact_count'],
			'is_commercial_recontact' => $is_commercial_rc,
			'is_same_session_contact' => $is_same_session,
			'previous_status' => sanitize_text_field( $previous_status ),
			'customer_matched' => $matched_customer,
			'first_submitted_at' => sanitize_text_field( (string) ( $lead_state->first_submitted_at ?? '' ) ),
			'last_submitted_at'  => sanitize_text_field( (string) ( $lead_state->last_submitted_at ?? '' ) ),
			'commercial_risk_signal' => (bool) $enriched['commercial_risk_signal'],
			'repeated_lead' => $repeated_lead,
			'behavior_high_intent' => (bool) $enriched['behavior_high_intent'],
			'unmanaged_lead' => (bool) $enriched['sin_gestion'],
			'lead_priority' => sanitize_text_field( (string) ( $lead_state->priority ?? '' ) ),
			'lead_priority_label' => (string) ( $lead_state->priority ?? '' ) !== '' ? strtoupper( (string) $lead_state->priority ) : '',
			'eval_temperature' => (string) $enriched['eval_temperature'],
			'eval_priority_reason' => (string) $enriched['eval_priority_reason'],
			'eval_behavior_signals' => $enriched['eval_behavior_signals'],
			'score_total'    => (float) $enriched['score_total'],
			'score_behavior' => (float) $enriched['score_behavior'],
			'score_capture'  => (float) $enriched['score_capture'],
			'lead_age_days'  => (int) $enriched['lead_age_days'],
			'sla_state'      => (string) $enriched['sla_state'],
			'fit'            => sanitize_text_field( (string) ( $lead_fit['fit'] ?? '' ) ),
			'fit_reason'     => sanitize_text_field( (string) ( $lead_fit['fit_reason'] ?? '' ) ),
			'interest_decision' => $interest_decision,
			'interest_narratives' => $interest_narratives,
			'form_fields'   => $form_fields,
			'message'       => $message,
			'lead_id'       => absint( $ctx['lead_id'] ),
			'submission_id' => absint( $ctx['submission_id'] ),
			'source_plugin' => sanitize_key( $ctx['source_plugin'] ),
			'qualify'       => self::qualification_settings(),
			'source_form_id' => sanitize_text_field( (string) ( $payload['content']['source_form_id'] ?? '' ) ),
			'source_entry_id' => absint( $payload['meta']['source_entry_id'] ?? 0 ),
			'feedback_url'  => $feedback_url,
			'workspace_url' => $workspace_url,
			'workspace_backlog' => $workspace_backlog,
			'ai_context_url' => $ai_context_url,
			'consolidated_sheet_url' => esc_url_raw( (string) get_option( 'estandar_leads_consolidated_sheet_url', '' ) ),
		);
	}

	public static function render_email( array $data, ?array $settings = null ): string
	{
		$es_s = is_array( $settings ) ? $settings : self::email_settings();
		$es_d = $data;
		// Identidad compartida de la Suite como base + override del notifier.
		$es_identity = \Estandar\Core\Mail::identity();
		if ( ! empty( $es_s['brand_color'] ) ) { $es_identity['brand_color'] = $es_s['brand_color']; }
		if ( ! empty( $es_s['logo_url'] ) )    { $es_identity['logo_url']    = $es_s['logo_url']; }
		if ( ! empty( $es_s['sender_name'] ) ) { $es_identity['header_text'] = $es_s['sender_name']; }
		if ( ! empty( $es_s['preheader'] ) )   { $es_identity['preheader']   = $es_s['preheader']; }
		ob_start();
		include ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/templates/' . self::EMAIL_TEMPLATE;
		return (string) ob_get_clean();
	}

	public static function email_settings(): array
	{
		$identity = \Estandar\Core\Mail::identity();
		$admin    = function_exists( 'get_option' ) ? (string) get_option( 'admin_email', '' ) : '';
		$saved    = get_option( 'estandar_suite_core_leads_notifier_settings', array() );
		$saved    = is_array( $saved ) ? $saved : array();

		return array_merge( array(
			'sender_name'  => (string) ( $identity['sender_name'] !== '' ? $identity['sender_name'] : $identity['site_name'] ),
			'sender_email' => (string) ( $identity['sender_email'] !== '' ? $identity['sender_email'] : $admin ),
			'brand_color'  => $identity['brand_color'],
			'logo_url'     => $identity['logo_url'],
			'header_text'  => $identity['header_text'],
			'footer_text'  => $identity['footer_text'],
			'subject'      => 'Nuevo Lead de {proyecto}: {name}',
			'preheader'    => (string) ( $identity['preheader'] !== '' ? $identity['preheader'] : 'Nuevo contacto registrado con información para seguimiento comercial.' ),
		), $saved );
	}

	/**
	 * QUALIFY_CONFIG_V1: pesos y umbrales del criterio Frío/Tibio/Caliente que
	 * usa el correo al operador (formularios con preguntas de plazo y
	 * financiamiento). Configurable en settings (Core → Calificación); default
	 * con el criterio aprobado 2026-09-15 (contado/ahorro = señal fuerte;
	 * "solo averiguando" neutro).
	 */
	public static function qualification_settings(): array
	{
		$saved = get_option( 'estandar_suite_core_qualification_settings', array() );
		$saved = is_array( $saved ) ? array_map( 'intval', $saved ) : array();
		return array_merge( array(
			'plazo_asap'      => 3,
			'plazo_m1_3'      => 2,
			'plazo_m3_6'      => 1,
			'plazo_exploring' => 0,
			'fin_contado'     => 3,
			'fin_subsidio'    => 2,
			'fin_credito'     => 1,
			'fin_nose'        => 0,
			'hot'             => 5,
			'warm'            => 2,
		), $saved );
	}

	/** Copia global (CC) configurable para todo aviso de operador. */
	private static function cc(): string
	{
		$saved = get_option( 'estandar_suite_core_leads_notifier_settings', array() );
		if ( ! is_array( $saved ) || empty( $saved['cc_emails'] ) ) {
			return '';
		}
		$list = \Estandar\Core\Mail::normalize_addresses( (string) $saved['cc_emails'] );
		return implode( ',', $list );
	}

	// ── Gates, horas, debounce, eventos, telemetría ─────────────────────────

	private static function routing_policy_gate( int $lead_id, int $submission_id, array $qualification ): bool
	{
		$routing_policy = (string) ( $qualification['routing_policy'] ?? \Estandar_Commercial_Qualification_Engine::ROUTING_ROUTE );
		if ( $routing_policy === \Estandar_Commercial_Qualification_Engine::ROUTING_ROUTE ) {
			return true;
		}
		$metadata = array(
			'decision'       => (string) ( $qualification['decision'] ?? '' ),
			'qualification'  => (string) ( $qualification['qualification'] ?? '' ),
			'routing_policy' => $routing_policy,
			'matched_rules'  => (array) ( $qualification['matched_rules'] ?? array() ),
			'rule_sources'   => (array) ( $qualification['rule_sources'] ?? array() ),
			'policy_reason'  => (string) ( $qualification['policy_reason'] ?? '' ),
		);
		if ( $routing_policy === \Estandar_Commercial_Qualification_Engine::ROUTING_REVIEW ) {
			self::insert_event( $lead_id, \Estandar_Commercial_Qualification_Engine::EVENT_REVIEW, array(
				'submission_id' => $submission_id,
				'reason'        => sanitize_text_field( (string) ( $qualification['policy_reason'] ?? '' ) ),
				'metadata'      => $metadata,
			) );
			return false;
		}
		self::insert_event( $lead_id, 'routing_skipped_by_policy', array(
			'submission_id' => $submission_id,
			'reason'        => sanitize_text_field( (string) ( $qualification['policy_reason'] ?? '' ) ),
			'metadata'      => $metadata,
		) );
		return false;
	}

	private static function split_operators_by_hours( int $lead_id, int $submission_id, string $project_slug, array $target_operators, string $source_plugin ): array
	{
		$open = array();
		$deferred = array();
		if ( ! class_exists( 'Estandar_Leads_Operator_Hours' ) ) {
			return array( $target_operators, array() );
		}
		foreach ( $target_operators as $op ) {
			if ( \Estandar_Leads_Operator_Hours::is_open( $op ) ) {
				$open[] = $op;
			} else {
				$deferred[] = $op;
			}
		}
		foreach ( $deferred as $op ) {
			$op_id = absint( $op->id );
			$next_ts = \Estandar_Leads_Operator_Hours::next_open_timestamp( $op );
			$existing = $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
				"SELECT e.id FROM {$GLOBALS['wpdb']->prefix}es_lead_events e
				 WHERE e.lead_id = %d AND e.submission_id = %d
				   AND e.event_type = 'operator_notification_deferred'
				   AND JSON_EXTRACT(e.metadata, '$.operator_id') = %d
				   AND NOT EXISTS (
				       SELECT 1 FROM {$GLOBALS['wpdb']->prefix}es_lead_events n
				       WHERE n.lead_id = e.lead_id AND n.submission_id = e.submission_id
				         AND n.event_type = 'operator_notified'
				   )
				 ORDER BY e.id DESC LIMIT 1",
				$lead_id, $submission_id, $op_id
			) );
			if ( $existing ) {
				continue;
			}
			self::insert_event( $lead_id, 'operator_notification_deferred', array(
				'submission_id' => $submission_id,
				'operator_id'   => $op_id,
				'metadata'      => array(
					'reason'           => 'outside_operational_hours',
					'deferred_minutes' => \Estandar_Leads_Operator_Hours::deferred_minutes( $op ),
					'scheduled_for'    => $next_ts ? gmdate( 'Y-m-d H:i:s', $next_ts ) : '',
					'project_slug'     => $project_slug,
					'operator_id'      => $op_id,
					'source_plugin'    => $source_plugin,
				),
			) );
			if ( $next_ts ) {
				wp_schedule_single_event( $next_ts, 'estandar_deferred_lead_notification', array(
					$lead_id, $submission_id, true, array(),
				) );
			}
		}
		return array( $open, $deferred );
	}

	private static function should_consolidate( int $lead_id, int $submission_id, array $contact, string $project_slug, string $source_plugin ): bool
	{
		global $wpdb;
		$debounce_seconds = (int) apply_filters( 'estandar_leads_notification_debounce_seconds', 300 );
		if ( $debounce_seconds <= 0 ) {
			return false;
		}

		$contact_email = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		$contact_phone = sanitize_text_field( (string) ( $contact['phone'] ?? '' ) );
		$contact_hash  = md5( $contact_email . '|' . $contact_phone );

		$submission_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d", $lead_id
		) );
		$is_recontact = $submission_count > 1;
		$classified_as = $is_recontact ? 'recurrent' : 'new';

		$consolidate = static function ( string $reason ) use ( $lead_id, $submission_id, $project_slug, $source_plugin, $submission_count, $classified_as ) {
			self::insert_event( $lead_id, 'operator_notification_consolidated', array(
				'submission_id' => $submission_id,
				'metadata'      => array(
					'reason'           => $reason,
					'debounce_seconds' => (int) apply_filters( 'estandar_leads_notification_debounce_seconds', 300 ),
					'submission_count' => $submission_count,
					'last_source'      => $source_plugin,
					'project_slug'     => $project_slug,
					'classified_as'    => $classified_as,
				),
			) );
			return true;
		};

		if ( $contact_hash !== md5( '|' ) ) {
			$cutoff = \Estandar\Core\Time::from_epoch( \Estandar\Core\Time::now_epoch() - (int) apply_filters( 'estandar_leads_notification_debounce_seconds', 300 ) );
			$recent = $wpdb->get_var( $wpdb->prepare(
				"SELECT e.created_at FROM {$wpdb->prefix}es_lead_events e
				 INNER JOIN {$wpdb->prefix}es_lead_submissions s ON s.id = e.submission_id
				 INNER JOIN {$wpdb->prefix}es_leads l ON l.id = e.lead_id
				 WHERE e.event_type = 'operator_notified'
				   AND e.created_at >= %s
				   AND MD5(CONCAT(COALESCE(l.email,''), '|', COALESCE(l.phone,''))) = %s
				 ORDER BY e.created_at DESC, e.id DESC LIMIT 1",
				$cutoff,
				$contact_hash
			) );
			if ( $recent ) {
				return $consolidate( 'debounce_window_contact' );
			}
		}
		$cutoff_lead = \Estandar\Core\Time::from_epoch( \Estandar\Core\Time::now_epoch() - (int) apply_filters( 'estandar_leads_notification_debounce_seconds', 300 ) );
		$recent_lead = $wpdb->get_var( $wpdb->prepare(
			"SELECT created_at FROM {$wpdb->prefix}es_lead_events
			 WHERE lead_id = %d AND event_type = 'operator_notified'
			   AND created_at >= %s
			 ORDER BY created_at DESC, id DESC LIMIT 1",
			$lead_id,
			$cutoff_lead
		) );
		if ( $recent_lead ) {
			return $consolidate( 'debounce_window' );
		}
		return false;
	}

	private static function record_routing_event( int $lead_id, int $submission_id, string $project_area, string $source_plugin ): void
	{
		self::insert_event( $lead_id, 'routing_unresolved', array(
			'submission_id' => $submission_id,
			'metadata'      => array(
				'routing_mode' => 'fallback_no_project',
				'project_slug' => '',
				'project_area' => $project_area,
				'reason'       => 'missing_project_context',
			),
		) );
		self::telemetry( 'warning', 'V2_NOTIFIER_PROJECT_UNRESOLVED', 'Lead project context missing; notification skipped.', array(
			'lead_id' => $lead_id, 'submission_id' => $submission_id,
			'source_plugin' => $source_plugin,
		) );
	}

	private static function insert_event( int $lead_id, string $event_type, array $args = array() ): int
	{
		if ( class_exists( 'Estandar_Leads_Repository' ) ) {
			return \Estandar_Leads_Repository::insert_event( $lead_id, $event_type, $args );
		}
		global $wpdb;
		$operator_id = ! empty( $args['operator_id'] ) ? absint( $args['operator_id'] ) : 0;
		$metadata = is_array( $args['metadata'] ?? null ) ? $args['metadata'] : array();
		$wpdb->insert( $wpdb->prefix . 'es_lead_events', array(
			'lead_id'       => $lead_id,
			'submission_id' => ! empty( $args['submission_id'] ) ? absint( $args['submission_id'] ) : null,
			'operator_id'   => $operator_id ?: null,
			'event_type'    => sanitize_key( $event_type ),
			'from_status'   => sanitize_text_field( $args['from_status'] ?? '' ),
			'to_status'     => sanitize_text_field( $args['to_status'] ?? '' ),
			'reason'        => sanitize_textarea_field( $args['reason'] ?? '' ),
			'metadata'      => wp_json_encode( $metadata ),
			'ip_address'    => sanitize_text_field( $args['ip_address'] ?? '' ),
			'user_agent'    => sanitize_textarea_field( $args['user_agent'] ?? '' ),
			'created_at'    => \Estandar\Core\Time::now(),
		) );
		return $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
	}

	private static function telemetry( string $severity, string $code, string $message, array $context = array() ): void
	{
		try {
			do_action( 'estandar_telemetry_event', array(
				'source_plugin' => 'estandar-core',
				'type'          => 'business_event',
				'severity'      => $severity,
				'code'          => $code,
				'message'       => $message,
				'context'       => $context,
			) );
		} catch ( \Throwable $e ) {
			// noop
		}
	}
}
