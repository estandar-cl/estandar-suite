<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Notifier
{
    public static function init()
    {
        add_action('estandar_lead_ingested', array(__CLASS__, 'notify_operators'), 25, 4);
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
        add_action('estandar_deferred_lead_notification', array(__CLASS__, 'send_deferred_notification'), 10, 4);
        add_action('estandar_sweep_deferred_notifications', array(__CLASS__, 'sweep_deferred_notifications'));
    }

    public static function notify_operators($lead_id, $submission_id, $is_new_lead, $payload)
    {
        global $wpdb;

        // COMMERCIAL_QUALIFICATION_V1 — routing policy gate. Runs BEFORE any
        // routing work: a skipped/review routing decision is an intentional
        // policy state, never an operational failure. The lead stays persisted
        // with attribution and analytics intact; only operator engagement is
        // withheld. Default decision (no rules configured) always routes.
        $qualification = class_exists('Estandar_Commercial_Qualification_Engine')
            ? Estandar_Commercial_Qualification_Engine::decision_for($lead_id, $submission_id, is_array($payload) ? $payload : null)
            : null;
        if (is_array($qualification) && self::routing_policy_gate($lead_id, $submission_id, $qualification) === false) {
            return;
        }

        $operators = $wpdb->get_results(
            "SELECT id, name, email, project_slug, area, allowed_projects_json FROM {$wpdb->prefix}es_operators WHERE active = 1 AND email <> '' ORDER BY name ASC"
        );

        if (empty($operators)) {
            self::telemetry('warning', 'LEADS_NOTIFIER_NO_OPERATORS', 'No active operators available for lead notification.', array(
                'lead_id' => absint($lead_id),
                'submission_id' => absint($submission_id),
            ));
            error_log('[estandar-leads] No active operators available for lead notification.');
            set_transient('estandar_leads_no_active_operators_notice', 1, 300);
            return;
        }

        $contact = isset($payload['contact']) && is_array($payload['contact']) ? $payload['contact'] : array();
        $attr = isset($payload['attribution']) && is_array($payload['attribution']) ? $payload['attribution'] : array();
        $context = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : array();
        $project_area = sanitize_text_field($context['project_area'] ?? '');
        $project_slug = Estandar_Leads_Projects::resolve_slug($context['project_slug'] ?? '', $project_area);
        $submission_project = $wpdb->get_row($wpdb->prepare(
            "SELECT s.context_json, s.source_plugin, s.submitted_at,
                    s.utm_source, s.utm_medium, s.utm_campaign, s.utm_term, s.utm_content,
                    s.landing_url, s.referrer, s.session_id,
                    l.project_slug AS lead_project_slug
             FROM {$wpdb->prefix}es_lead_submissions s
             INNER JOIN {$wpdb->prefix}es_leads l ON l.id = s.lead_id
             WHERE s.id = %d LIMIT 1",
            absint($submission_id)
        ));
        if ($submission_project) {
            foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_url', 'referrer', 'session_id') as $field) {
                $attr[$field] = (string) ($submission_project->{$field} ?? '');
            }
            $project_slug = Estandar_Leads_Repository::canonical_project(null, $submission_project);
            if ($project_slug === Estandar_Leads_Repository::UNRESOLVED_PROJECT) {
                $project_slug = '';
            }
        }
        $source_plugin = sanitize_key($submission_project->source_plugin ?? ($payload['source_plugin'] ?? ''));

        if ($project_slug === '') {
            self::telemetry('warning', 'LEADS_NOTIFIER_PROJECT_UNRESOLVED_BLOCKED', 'Lead project context missing; operator notification skipped.', array(
                'lead_id' => absint($lead_id),
                'submission_id' => absint($submission_id),
                'project_area' => $project_area,
                'source_plugin' => $source_plugin,
            ));
            Estandar_Leads_Repository::insert_event($lead_id, 'routing_unresolved', array(
                'submission_id' => $submission_id,
                'metadata' => array(
                    'routing_mode' => 'fallback_no_project',
                    'project_slug' => '',
                    'project_area' => $project_area,
                    'reason' => 'missing_project_context',
                ),
            ));
            set_transient('estandar_leads_project_unresolved_notice', 1, 300);
            return;
        } else {
            $routing = Estandar_Leads_Operator_Resolver::resolve_for_project($project_slug, array(
                'operators' => $operators,
                'require_email' => true,
            ));
        }

        $target_operators = $routing['operators'];

        if ($project_slug !== '' && !$routing['fallback']) {
            self::telemetry('info', 'LEADS_NOTIFIER_PROJECT_ROUTE', 'Lead notification routed by project slug.', array(
                'lead_id' => absint($lead_id),
                'submission_id' => absint($submission_id),
                'project_slug' => $project_slug,
                'project_area' => $project_area,
                'operators_count' => count($target_operators),
                'routing_mode' => $routing['routing_mode'] ?? 'project_match',
            ));
        } elseif ($project_slug !== '' && $routing['fallback']) {
            self::telemetry('warning', 'LEADS_NOTIFIER_PROJECT_FALLBACK_BROADCAST', 'No operator matched lead project slug; broadcasting to all active operators.', array(
                'lead_id' => absint($lead_id),
                'submission_id' => absint($submission_id),
                'project_slug' => $project_slug,
                'project_area' => $project_area,
            ));
            Estandar_Leads_Repository::insert_event($lead_id, 'routing_fallback_used', array(
                'submission_id' => $submission_id,
                'metadata' => array(
                    'routing_mode' => 'fallback',
                    'project_slug' => $project_slug,
                    'project_area' => $project_area,
                    'operators_count' => count($target_operators),
                ),
            ));
        }

        // COMMERCIAL_QUALIFICATION_V1 — notification policy gate. Policy runs
        // BEFORE operator assignment: if notification_policy=SKIP, the operator
        // is never assigned and no notification is sent. This prevents the
        // inconsistency where a lead had assigned_operator_id set but never
        // received a notification.
        if (is_array($qualification) && ($qualification['notification_policy'] ?? Estandar_Commercial_Qualification_Engine::NOTIFY) === Estandar_Commercial_Qualification_Engine::SKIP) {
            Estandar_Leads_Repository::insert_event($lead_id, 'notification_skipped_by_policy', array(
                'submission_id' => $submission_id,
                'reason'        => sanitize_text_field((string) ($qualification['policy_reason'] ?? '')),
                'metadata'      => array(
                    'project_slug'  => $project_slug,
                    'matched_rules' => (array) ($qualification['matched_rules'] ?? array()),
                    'rule_sources'  => (array) ($qualification['rule_sources'] ?? array()),
                    'policy_reason' => (string) ($qualification['policy_reason'] ?? ''),
                ),
            ));
            self::telemetry('info', 'LEADS_NOTIFICATION_SKIPPED_BY_POLICY', 'Operator notification skipped by commercial qualification policy.', array(
                'lead_id'       => absint($lead_id),
                'submission_id' => absint($submission_id),
                'project_slug'  => $project_slug,
                'policy_reason' => (string) ($qualification['policy_reason'] ?? ''),
            ));
            return;
        }

        // LEAD-INVARIANT-001: assign operator AFTER the notification policy gate.
        // Assignment depends on routing, never on notification channel/schedule.
        // Guard: skip if this submission already has operator_assigned events
        // (deferred cron re-enters notify_operators; don't duplicate).
        if ( ! empty( $target_operators ) ) {
            $already_assigned = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
                 WHERE lead_id = %d AND submission_id = %d AND event_type = 'operator_assigned'",
                absint( $lead_id ),
                absint( $submission_id )
            ) );

            if ( $already_assigned === 0 ) {
                $primary_operator = $target_operators[0];
                self::sync_assigned_operator( $lead_id, $primary_operator );

                foreach ( $target_operators as $_op ) {
                    Estandar_Leads_Repository::insert_event( $lead_id, 'operator_assigned', array(
                        'submission_id' => $submission_id,
                        'operator_id'   => absint( $_op->id ),
                        'to_status'     => $project_slug,
                        'metadata'      => array(
                            'project_slug' => $project_slug,
                            'routing_mode' => $routing['fallback'] ? 'fallback' : ( $routing['routing_mode'] ?? 'project_match' ),
                            'source'       => 'notifier_routing',
                        ),
                    ) );
                }
            }
        }

        // OPERATIONAL_HOURS_PER_OPERATOR_V1: check per-operator hours.
        // Each operator may have their own schedule. Open operators get the
        // notification immediately; closed operators are deferred individually
        // with an operator_id in the deferred event metadata.
        // The global gate (single is_open check for the whole batch) is replaced
        // by this per-operator evaluation.
        $deferred_operators = array();
        $open_operators = array();
        $schedule_sweeper = false;

        if ( class_exists( 'Estandar_Leads_Operator_Hours' ) ) {
            foreach ( $target_operators as $op ) {
                if ( Estandar_Leads_Operator_Hours::is_open( $op ) ) {
                    $open_operators[] = $op;
                } else {
                    $deferred_operators[] = $op;
                }
            }

            foreach ( $deferred_operators as $op ) {
                $operator_id   = absint( $op->id );
                $def_minutes   = Estandar_Leads_Operator_Hours::deferred_minutes( $op );
                $next_ts       = Estandar_Leads_Operator_Hours::next_open_timestamp( $op );

                // IDEMPOTENCY_V1: skip event insertion if a deferred event already
                // exists for this (submission_id, operator_id) without a matching
                // operator_notified. Prevents duplicate events when notify_operators()
                // is called multiple times for the same deferred operator.
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT e.id FROM {$wpdb->prefix}es_lead_events e
                     WHERE e.lead_id = %d AND e.submission_id = %d
                       AND e.event_type = 'operator_notification_deferred'
                       AND JSON_EXTRACT(e.metadata, '$.operator_id') = %d
                       AND NOT EXISTS (
                           SELECT 1 FROM {$wpdb->prefix}es_lead_events n
                           WHERE n.lead_id = e.lead_id AND n.submission_id = e.submission_id
                             AND n.event_type = 'operator_notified'
                       )
                     ORDER BY e.id DESC LIMIT 1",
                    $lead_id, $submission_id, $operator_id
                ) );

                if ( $existing ) {
                    self::telemetry( 'debug', 'LEADS_NOTIFIER_DEFERRED_SKIP', 'Deferred event already exists for this submission/operator; skipping duplicate.', array(
                        'lead_id'       => absint( $lead_id ),
                        'submission_id' => absint( $submission_id ),
                        'operator_id'   => $operator_id,
                    ) );
                } else {
                    Estandar_Leads_Repository::insert_event( $lead_id, 'operator_notification_deferred', array(
                        'submission_id' => $submission_id,
                        'operator_id'   => $operator_id,
                        'metadata' => array(
                            'reason'            => 'outside_operational_hours',
                            'deferred_minutes'  => $def_minutes,
                            'scheduled_for'     => $next_ts ? gmdate( 'Y-m-d H:i:s', $next_ts ) : '',
                            'project_slug'      => $project_slug,
                            'operator_id'       => $operator_id,
                            'is_new_lead'       => $is_new_lead,
                            'source_plugin'     => $source_plugin,
                        ),
                    ) );
                }

                if ( $next_ts ) {
                    $payload_with_op = is_array( $payload ) ? $payload : array();
                    $payload_with_op['_deferred_operator_id'] = absint( $op->id );
                    wp_schedule_single_event( $next_ts, 'estandar_deferred_lead_notification', array(
                        $lead_id, $submission_id, $is_new_lead, $payload_with_op,
                    ) );
                    $schedule_sweeper = true;
                }

                self::telemetry( 'info', 'LEADS_NOTIFIER_DEFERRED_OPERATOR', 'Operator notification deferred (operator outside hours).', array(
                    'lead_id'          => absint( $lead_id ),
                    'submission_id'    => absint( $submission_id ),
                    'operator_id'      => absint( $op->id ),
                    'deferred_minutes' => $def_minutes,
                    'project_slug'     => $project_slug,
                ) );
            }

            if ( $schedule_sweeper && ! wp_next_scheduled( 'estandar_sweep_deferred_notifications' ) ) {
                wp_schedule_event( time() + 300, 'hourly', 'estandar_sweep_deferred_notifications' );
            }

            if ( empty( $open_operators ) ) {
                Estandar_Leads_Repository::insert_event($lead_id, 'notification_zero_delivery', array(
                    'submission_id' => $submission_id,
                    'metadata' => array(
                        'reason'            => 'all_operators_closed',
                        'project_slug'      => $project_slug,
                        'deferred_count'    => count($deferred_operators),
                        'source_plugin'     => $source_plugin,
                    ),
                ));
                self::telemetry('warning', 'LEADS_NOTIFIER_ZERO_DELIVERY', 'All operators closed or unavailable; zero notifications delivered.', array(
                    'lead_id'          => absint($lead_id),
                    'submission_id'    => absint($submission_id),
                    'project_slug'     => $project_slug,
                    'deferred_count'   => count($deferred_operators),
                ));
                return;
            }

            $target_operators = $open_operators;
        }

        $email_settings = Estandar_Leads_Email::get_settings();
        $lead_state = $wpdb->get_row($wpdb->prepare(
            "SELECT l.quality, l.name, l.priority, l.temperature, l.score_components_json,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id) AS submission_count,
                    (SELECT MIN(s.submitted_at) FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id) AS first_submitted_at,
                    (SELECT MAX(s.submitted_at) FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id) AS last_submitted_at
             FROM {$wpdb->prefix}es_leads l WHERE l.id = %d LIMIT 1",
            absint($lead_id)
        ));
        $submission_count = (int) ($lead_state->submission_count ?? 0);
        $sin_gestion = trim((string) ($lead_state->quality ?? '')) === '';
        $repeated_lead = $submission_count > 1;
        $recontact_ctx = self::get_commercial_recontact_for_submission(absint($lead_id), absint($submission_id));
        $commercial_recontact_count = (int) ($recontact_ctx['commercial_recontact_count'] ?? 0);
        $is_commercial_recontact = !empty($recontact_ctx['is_commercial_recontact']);
        $is_same_session_contact = !empty($recontact_ctx['is_same_session_contact']);
        $score_components = json_decode((string) ($lead_state->score_components_json ?? ''), true);
        $behavior_score = is_array($score_components) ? (float) ($score_components['behavior'] ?? 0) : 0;
        $behavior_high_intent = $behavior_score > 0 && strtoupper((string) ($lead_state->temperature ?? '')) === 'CALIENTE';
        $previous_status = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT from_status FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND event_type IN ('status_changed','status_overridden') AND from_status != ''
             ORDER BY created_at DESC, id DESC LIMIT 1",
            absint($lead_id)
        ));
        $matched_customer = (class_exists('Estandar_Leads_Customer_Service'))
            ? Estandar_Leads_Customer_Service::get_by_lead((int) $lead_id)
            : null;
        $commercial_risk_signal = $is_commercial_recontact && $sin_gestion;

        // RC1 — Notification consolidation. When several submissions land on the
        // same lead inside a short debounce window, an operator would otherwise
        // receive a NEW notification followed by a RECURRENT one for the same
        // event chain. Operator assignment (above) already ran per submission and
        // is untouched; here we only collapse the *email* so a single, correctly
        // classified notification reaches the operator. The surviving notification
        // already carries submission count, latest source and history summary.
        $debounce_seconds = (int) apply_filters('estandar_leads_notification_debounce_seconds', 300);
        $contact_email = sanitize_email($contact['email'] ?? '');
        $contact_phone = sanitize_text_field($contact['phone'] ?? '');
        $contact_hash = md5($contact_email . '|' . $contact_phone);
        if ($debounce_seconds > 0) {
            if ($contact_hash !== md5('|')) {
                $recent_contact = $wpdb->get_var($wpdb->prepare(
                    "SELECT e.created_at FROM {$wpdb->prefix}es_lead_events e
                     INNER JOIN {$wpdb->prefix}es_lead_submissions s ON s.id = e.submission_id
                     INNER JOIN {$wpdb->prefix}es_leads l ON l.id = e.lead_id
                     WHERE e.event_type = 'operator_notified'
                       AND e.created_at > DATE_SUB(NOW(), INTERVAL %d SECOND)
                       AND MD5(CONCAT(COALESCE(l.email,''), '|', COALESCE(l.phone,''))) = %s
                     ORDER BY e.created_at DESC, e.id DESC LIMIT 1",
                    $debounce_seconds,
                    $contact_hash
                ));
                if ($recent_contact) {
                    Estandar_Leads_Repository::insert_event($lead_id, 'operator_notification_consolidated', array(
                        'submission_id' => $submission_id,
                        'metadata' => array(
                            'reason'            => 'debounce_window_contact',
                            'debounce_seconds'  => $debounce_seconds,
                            'submission_count'  => $submission_count,
                            'last_source'       => $source_plugin,
                            'project_slug'      => $project_slug,
                            'classified_as'     => $is_commercial_recontact ? 'commercial_recontact' : ($is_same_session_contact ? 'same_session' : ($repeated_lead ? 'recurrent' : 'new')),
                        ),
                    ));
                    self::telemetry('info', 'LEADS_NOTIFIER_CONSOLIDATED_CONTACT', 'Operator notification consolidated by contact signature within debounce window.', array(
                        'lead_id'          => absint($lead_id),
                        'submission_id'    => absint($submission_id),
                        'debounce_seconds' => $debounce_seconds,
                        'contact_hash'     => $contact_hash,
                    ));
                    return;
                }
            }
            $recent_notify = $wpdb->get_var($wpdb->prepare(
                "SELECT created_at FROM {$wpdb->prefix}es_lead_events
                 WHERE lead_id = %d AND event_type = 'operator_notified'
                   AND created_at > DATE_SUB(NOW(), INTERVAL %d SECOND)
                 ORDER BY created_at DESC, id DESC LIMIT 1",
                absint($lead_id),
                $debounce_seconds
            ));
            if ($recent_notify) {
                Estandar_Leads_Repository::insert_event($lead_id, 'operator_notification_consolidated', array(
                    'submission_id' => $submission_id,
                    'metadata' => array(
                        'reason'            => 'debounce_window',
                        'debounce_seconds'  => $debounce_seconds,
                        'submission_count'  => $submission_count,
                        'last_source'       => $source_plugin,
                        'project_slug'      => $project_slug,
                        'classified_as'     => $is_commercial_recontact ? 'commercial_recontact' : ($is_same_session_contact ? 'same_session' : ($repeated_lead ? 'recurrent' : 'new')),
                    ),
                ));
                self::telemetry('info', 'LEADS_NOTIFIER_CONSOLIDATED', 'Operator notification consolidated within debounce window (NEW→RECURRENT collapse).', array(
                    'lead_id'          => absint($lead_id),
                    'submission_id'    => absint($submission_id),
                    'debounce_seconds' => $debounce_seconds,
                    'submission_count' => $submission_count,
                ));
                return;
            }
        }

        $lead_name = sanitize_text_field($contact['name'] ?? '');
        if ($lead_name === '') {
            $lead_name = sanitize_text_field($lead_state->name ?? 'Lead');
        }
        if ($commercial_risk_signal) {
            $flames = str_repeat('🔥', min($commercial_recontact_count, 5));
            $email_subject = '⚠ Lead reincidente sin gestión: ' . $flames . ' Recontacto x' . $commercial_recontact_count . ' - ' . $lead_name;
        } elseif ($is_commercial_recontact) {
            $flames = str_repeat('🔥', min($commercial_recontact_count, 5));
            $email_subject = $flames . ' Recontacto x' . $commercial_recontact_count . ': ' . $lead_name;
        } elseif ($behavior_high_intent) {
            $email_subject = '🔥 Lead de alta intención: ' . $lead_name;
        } else {
            $email_subject = 'Lead nuevo: ' . $lead_name;
        }
        $policy_headers = self::notification_policy_headers($email_settings);
        $cc_email = $policy_headers['cc'];
        $bcc_email = $policy_headers['bcc'];
        $reply_to_email = $policy_headers['reply_to'];
        $mail_headers = $policy_headers['headers'];

        // ── Instrumentación P2: detectar posibles emails duplicados ──────────
        // Telemetría pasiva — no cambia el comportamiento.
        // Permite medir frecuencia de "mismo contacto, múltiples notificaciones"
        // antes de decidir si implementar deduplicación.
        $contact_sig = md5(
            sanitize_email( $contact['email'] ?? '' ) . '|' .
            sanitize_text_field( $contact['phone'] ?? '' )
        );
        $recent_key = 'es_notif_recent_' . $contact_sig;
        $previous   = get_transient( $recent_key );
        if ( $previous ) {
            self::telemetry( 'warning', 'LEADS_NOTIFIER_POSSIBLE_DUPLICATE', 'Operator notification fired for a contact that received a notification recently.', array(
                'lead_id'         => absint( $lead_id ),
                'submission_id'   => absint( $submission_id ),
                'source_plugin'   => $source_plugin,
                'previous_source' => $previous['source_plugin'] ?? '',
                'seconds_apart'   => time() - (int) ( $previous['ts'] ?? 0 ),
            ) );
        }
        set_transient( $recent_key, array( 'source_plugin' => $source_plugin, 'ts' => time() ), 10 * MINUTE_IN_SECONDS );
        // ── Fin instrumentación ───────────────────────────────────────────────

        // ── EMAIL_CAMPAIGN_NAME_V1: nombre humano de campaña para el correo ──
        // Reutiliza el resolver de CAMPAIGN_MAPPINGS (core opcional, guard
        // class_exists). El correo comercial solo recibe un nombre legible; si
        // el valor almacenado es un id crudo sin mapping, no se pasa nada y el
        // template oculta la fila. El tracking (utm_campaign) no se toca.
        $campaign_raw  = sanitize_text_field($attr['utm_campaign'] ?? '');
        $campaign_name = '';
        if ($campaign_raw !== '') {
            if (class_exists('Estandar_Campaign_Label_Resolver')) {
                $resolved = Estandar_Campaign_Label_Resolver::campaign($campaign_raw);
                // El fallback del resolver embebe "(#<id>)": eso no es un nombre.
                if ($resolved !== '' && strpos($resolved, '(#' . $campaign_raw . ')') === false) {
                    $campaign_name = $resolved;
                }
            }
            if ($campaign_name === '' && !ctype_digit($campaign_raw)) {
                // utm_campaign textual (ej. "google-casas-2025") ya es un
                // nombre de campaña, no un id de plataforma.
                $campaign_name = $campaign_raw;
            }
        }

        // ── EMAIL_JOURNEY_V1: hitos reales del lead desde es_lead_events ─────
        // Lectura read-only de eventos ya registrados; sin motores nuevos.
        $journey_event_types = array();
        if (absint($lead_id) > 0) {
            $journey_rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT event_type FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d",
                absint($lead_id)
            ));
            $journey_event_types = is_array($journey_rows) ? $journey_rows : array();
        }
        $journey_has = static function ($type) use ($journey_event_types) {
            return in_array($type, $journey_event_types, true);
        };
        $journey = array(
            'autoresponse_sent'  => $journey_has('autoresponse_email_sent'),
            'email_opened'       => self::has_human_email_open( absint( $lead_id ) ),
            'link_clicked'       => $journey_has('autoresponse_link_click') || $journey_has('autoresponse_engaged'),
            'whatsapp_opened'    => $journey_has('whatsapp_clicked'),
            'contact_registered' => $journey_has('contact_logged') || $journey_has('call_clicked') || $journey_has('email_clicked'),
        );

        // LEAD_ASSESSMENT_V1 — persisted fit axis for the email indicator
        // (computed once for all operators). Only queried when the indicator
        // is enabled — the email renders it exclusively in that case, so
        // disabled installations keep zero extra queries.
        $lead_fit = array();
        if (class_exists('Estandar_Leads_Assessment_Engine')
            && class_exists('Estandar_Leads_Fit_Indicator')
            && Estandar_Leads_Fit_Indicator::is_enabled()) {
            $lead_fit = Estandar_Leads_Assessment_Engine::read_lead_fit((int) $lead_id);
        }

        // ── IEE-5: Interest Evolution Engine — decisión comercial ──────────
        // INTEREST_EVOLUTION_ENGINE_V1 (ADR-0044). Reutiliza datos ya
        // resueltos por el notifier (lead_state, recontact_ctx, journey, attr)
        // SIN queries SQL nuevas. El template solo renderiza esta decisión.
        // Ausencia = default: si core no tiene el motor, interest_decision
        // llega con absent=true y el template conserva el comportamiento actual.
        $interest_decision = array('version' => 'IEE_V1', 'absent' => true);
        $interest_narratives = array();
        if (class_exists('Estandar_Leads_Interest_Adapter')) {
            $interest_decision = Estandar_Leads_Interest_Adapter::decision_for((int) $lead_id, (int) $submission_id, array(
                'lead_state'      => $lead_state,
                'recontact_ctx'   => $recontact_ctx,
                'journey'         => $journey,
                'attribution'     => $attr,
                'submission_count'=> $submission_count,
            ));
            if (is_array($interest_decision) && empty($interest_decision['absent'])
                && class_exists('Estandar\\Core\\Interest\\InterestEngine')) {
                $interest_narratives = Estandar\Core\Interest\InterestEngine::narratives($interest_decision, array(
                    'lead_name'   => $lead_name,
                    'project'     => $project_slug,
                ));
            }
        }

        foreach ($target_operators as $operator) {
            try {
                $token = Estandar_Leads_Tokens::create($lead_id, $operator->id);
                if ($token === '') {
                    self::telemetry('error', 'LEADS_FEEDBACK_TOKEN_CREATE_FAILED', 'Feedback token creation failed for operator notification.', array(
                        'lead_id' => absint($lead_id),
                        'operator_id' => absint($operator->id),
                    ));
                    error_log('[estandar-leads] Feedback token creation failed for operator ' . absint($operator->id) . ' and lead ' . absint($lead_id));
                    continue;
                }

                $feedback_url = Estandar_Leads_Tokens::url($token);
                $workspace_url = '';
                $workspace_backlog = array();
                if (class_exists('Estandar_Leads_Operator_Workspace')) {
                    $workspace_token = Estandar_Leads_Operator_Workspace::create_token((int) $operator->id);
                    $workspace_url = $workspace_token !== '' ? Estandar_Leads_Operator_Workspace::workspace_url($workspace_token) : '';
                    $workspace_backlog = Estandar_Leads_Operator_Workspace::operator_backlog((int) $operator->id);
                }
                $html = Estandar_Leads_Email::render(array(
                    'operator_name' => sanitize_text_field($operator->name),
                    'operator_id'   => absint($operator->id),
                    'lead_name'     => sanitize_text_field($contact['name'] ?? ''),
                    'lead_email'    => sanitize_email($contact['email'] ?? ''),
                    'lead_phone'    => sanitize_text_field($contact['phone'] ?? ''),
                    'project_area'  => sanitize_text_field($project_area),
                    'project_slug'  => sanitize_text_field($project_slug),
                    'campaign'      => sanitize_text_field($attr['utm_campaign'] ?? ''),
                    'campaign_name' => $campaign_name,
                    'journey'       => $journey,
                    'ad'            => sanitize_text_field($attr['utm_content'] ?? ''),
                    'keyword'       => sanitize_text_field($attr['utm_term'] ?? ''),
                    'landing_url'   => esc_url_raw($attr['landing_url'] ?? ''),
                    'utm_source'    => sanitize_text_field($attr['utm_source'] ?? ''),
                    'utm_medium'    => sanitize_text_field($attr['utm_medium'] ?? ''),
                    'submitted_at'  => sanitize_text_field($submission_project->submitted_at ?? ($payload['submitted_at'] ?? '')),
                    'submission_count' => $submission_count,
                    'lead_type' => $is_commercial_recontact ? 'Recontacto comercial' : ($repeated_lead ? 'Recurrente' : 'Nuevo'),
                    'submission_position' => $submission_count,
                    'commercial_recontact_count' => $commercial_recontact_count,
                    'is_commercial_recontact' => $is_commercial_recontact,
                    'is_same_session_contact' => $is_same_session_contact,
                    'previous_status' => sanitize_text_field($previous_status),
                    'customer_matched' => !empty($matched_customer),
                    'first_submitted_at' => sanitize_text_field($lead_state->first_submitted_at ?? ''),
                    'last_submitted_at' => sanitize_text_field($lead_state->last_submitted_at ?? ''),
                    'commercial_risk_signal' => $commercial_risk_signal,
                    'repeated_lead' => $repeated_lead,
                    'behavior_high_intent' => $behavior_high_intent,
                    'unmanaged_lead' => $sin_gestion,
                    'lead_priority' => sanitize_text_field((string) ($lead_state->priority ?? '')),
                    'fit'           => sanitize_text_field((string) ($lead_fit['fit'] ?? '')),
                    'fit_reason'    => sanitize_text_field((string) ($lead_fit['fit_reason'] ?? '')),
                    'interest_decision' => $interest_decision,
                    'interest_narratives' => $interest_narratives,
                    'form_fields'   => is_array($payload['meta']['sanitized_raw_fields'] ?? null) ? $payload['meta']['sanitized_raw_fields'] : array(),
                    'message'       => sanitize_textarea_field($payload['content']['message'] ?? ''),
                    'lead_id'       => absint($lead_id),
                    'submission_id' => absint($submission_id),
                    'source_plugin' => sanitize_key($source_plugin),
                    'source_form_id' => sanitize_text_field($payload['content']['source_form_id'] ?? ''),
                    'source_entry_id' => absint($payload['meta']['source_entry_id'] ?? 0),
                    'feedback_url'  => $feedback_url,
                    'workspace_url' => $workspace_url,
                    'workspace_backlog' => $workspace_backlog,
                    'consolidated_sheet_url' => esc_url_raw((string) get_option('estandar_leads_consolidated_sheet_url', '')),
                ), $email_settings);

                $sent = Estandar_Leads_Email::send(
                    sanitize_email($operator->email),
                    $email_subject,
                    $html,
                    $email_settings,
                    $mail_headers
                );

                if (!$sent) {
                    self::telemetry('error', 'LEADS_NOTIFIER_WP_MAIL_FAILED', 'Lead notification email failed.', array(
                        'lead_id' => absint($lead_id),
                        'operator_id' => absint($operator->id),
                    ));
                    error_log('[estandar-leads] Lead notification email failed for operator ' . absint($operator->id) . ' and lead ' . absint($lead_id));
                } else {
                    Estandar_Leads_Repository::insert_event($lead_id, 'operator_notified', array(
                        'submission_id' => $submission_id,
                        'operator_id' => absint($operator->id),
                        'metadata' => array(
                            'channel' => 'email',
                            'email' => sanitize_email($operator->email),
                            'cc_email' => $cc_email,
                            'bcc_email' => $bcc_email,
                            'reply_to_email' => $reply_to_email,
                            'routing_mode' => $routing['fallback'] ? 'fallback' : 'project',
                            'project_slug' => $project_slug,
                        ),
                    ));
                }
            } catch (Throwable $e) {
                self::telemetry('error', 'LEADS_NOTIFIER_EXCEPTION', 'Lead notification threw an exception.', array(
                    'lead_id' => absint($lead_id),
                    'operator_id' => absint($operator->id),
                    'exception' => get_class($e),
                ));
                error_log('[estandar-leads] Lead notification exception for operator ' . absint($operator->id) . ' and lead ' . absint($lead_id) . ': ' . $e->getMessage());
                continue;
        }
    }
    }

    /**
     * COMMERCIAL_QUALIFICATION_V1 — routing policy gate.
     *
     * Returns false when routing must stop here (policy skip or review) after
     * recording the corresponding auditable event; true when routing proceeds.
     * Idempotent per submission: a deferred/cron re-entry does not duplicate
     * policy events because skip/review decisions return before any deferral
     * is ever scheduled.
     */
    private static function routing_policy_gate($lead_id, $submission_id, array $qualification): bool
    {
        $routing_policy = (string) ($qualification['routing_policy'] ?? Estandar_Commercial_Qualification_Engine::ROUTING_ROUTE);
        if ($routing_policy === Estandar_Commercial_Qualification_Engine::ROUTING_ROUTE) {
            return true;
        }

        $metadata = array(
            'decision'       => (string) ($qualification['decision'] ?? ''),
            'qualification'  => (string) ($qualification['qualification'] ?? ''),
            'routing_policy' => $routing_policy,
            'matched_rules'  => (array) ($qualification['matched_rules'] ?? array()),
            'rule_sources'   => (array) ($qualification['rule_sources'] ?? array()),
            'policy_reason'  => (string) ($qualification['policy_reason'] ?? ''),
            'invalid_reason' => (string) ($qualification['invalid_reason'] ?? ''),
        );

        if ($routing_policy === Estandar_Commercial_Qualification_Engine::ROUTING_REVIEW) {
            Estandar_Leads_Repository::insert_event($lead_id, Estandar_Commercial_Qualification_Engine::EVENT_REVIEW, array(
                'submission_id' => $submission_id,
                'reason'        => sanitize_text_field((string) ($qualification['policy_reason'] ?? '')),
                'metadata'      => $metadata,
            ));
            self::telemetry('info', 'LEADS_QUALIFICATION_REVIEW', 'Lead held for commercial review by qualification policy.', array(
                'lead_id'       => absint($lead_id),
                'submission_id' => absint($submission_id),
                'policy_reason' => (string) ($qualification['policy_reason'] ?? ''),
            ));
            return false;
        }

        Estandar_Leads_Repository::insert_event($lead_id, 'routing_skipped_by_policy', array(
            'submission_id' => $submission_id,
            'reason'        => sanitize_text_field((string) ($qualification['policy_reason'] ?? '')),
            'metadata'      => $metadata,
        ));
        self::telemetry('info', 'LEADS_ROUTING_SKIPPED_BY_POLICY', 'Operator routing skipped by commercial qualification policy.', array(
            'lead_id'       => absint($lead_id),
            'submission_id' => absint($submission_id),
            'policy_reason' => (string) ($qualification['policy_reason'] ?? ''),
        ));
        return false;
    }

    private static function sync_assigned_operator($lead_id, $operator)
    {
        global $wpdb;

        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}es_leads
             SET assigned_operator_id = %d,
                 assigned_operator_email = %s,
                 assigned_operator_name = %s,
                 assigned_at = %s,
                 updated_at = %s
             WHERE id = %d
               AND (assigned_operator_id IS NULL OR assigned_operator_id = 0)",
            absint($operator->id),
            sanitize_email($operator->email),
            sanitize_text_field($operator->name),
            $now,
            $now,
            absint($lead_id)
        ));
    }

    /**
     * OPERATIONAL_HOURS_V1: callback for deferred single-event cron.
     * Re-invokes notify_operators now that we're in business hours.
     * Idempotent: checks if operator_notified already exists for this submission.
     */
    public static function send_deferred_notification( $lead_id, $submission_id, $is_new_lead, $payload ): void {
        // OPERATIONAL_HOURS_PER_OPERATOR_V1: operator_id is embedded in the payload
        // for backward-compatible cron (4-arg hook, no signature change).
        // Old crons without _deferred_operator_id get $op_id = 0 and fall through
        // to the global operational hours check.
        $safe_payload = is_array( $payload ) ? $payload : array();
        $op_id = (int) ( $safe_payload['_deferred_operator_id'] ?? 0 );

        if ( $op_id > 0 && class_exists( 'Estandar_Leads_Operator_Hours' ) ) {
            global $wpdb;
            $op = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}es_operators WHERE id = %d LIMIT 1", $op_id
            ) );
            if ( $op && ! Estandar_Leads_Operator_Hours::is_open( $op ) ) {
                $next_ts = Estandar_Leads_Operator_Hours::next_open_timestamp( $op );
                if ( $next_ts ) {
                    $safe_payload['_deferred_operator_id'] = $op_id;
                    wp_schedule_single_event( $next_ts, 'estandar_deferred_lead_notification', array(
                        $lead_id, $submission_id, $is_new_lead, $safe_payload,
                    ) );
                }
                return;
            }
        } elseif ( class_exists( 'Estandar_Operational_Hours' ) && ! Estandar_Operational_Hours::is_open() ) {
            $next_ts = Estandar_Operational_Hours::next_open_timestamp();
            if ( $next_ts ) {
                wp_schedule_single_event( $next_ts, 'estandar_deferred_lead_notification', array(
                    $lead_id, $submission_id, $is_new_lead, $safe_payload,
                ) );
            }
            return;
        }

        if ( self::already_notified_for_submission( (int) $lead_id, (int) $submission_id ) ) {
            return;
        }

        // OPERATOR_RESPONSE_SLA_V1 — record explicit delivery event when a
        // previously-deferred notification is finally sent. This marks the
        // start of the operator response SLA and creates an auditable link
        // between deferral and delivery.
        self::record_delivered_event( (int) $lead_id, (int) $submission_id, $safe_payload );

        self::notify_operators( $lead_id, $submission_id, $is_new_lead, $payload );
    }

    /**
     * OPERATIONAL_HOURS_V1: sweeper — picks up any deferred notifications
     * that missed their scheduled cron (wp-cron depends on traffic).
     * Authority = event 'operator_notification_deferred' without a matching 'operator_notified'.
     */
    public static function sweep_deferred_notifications(): void {
        // Per-operator hours: if there are pending deferred events with operator_id,
        // check each operator individually. Only skip the sweep entirely if
        // there are NO deferred events with open operators.
        global $wpdb;
        $events_table = $wpdb->prefix . 'es_lead_events';

        $pending = $wpdb->get_results(
            "SELECT e.id AS event_id, e.lead_id, e.submission_id, e.metadata
             FROM {$events_table} e
             WHERE e.event_type = 'operator_notification_deferred'
               AND e.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND NOT EXISTS (
                   SELECT 1 FROM {$events_table} n
                   WHERE n.lead_id = e.lead_id
                     AND n.submission_id = e.submission_id
                     AND n.event_type = 'operator_notified'
                     AND n.created_at > e.created_at
               )
             ORDER BY e.created_at ASC
             LIMIT 20"
        );

        if ( empty( $pending ) ) {
            return;
        }

        foreach ( $pending as $row ) {
            $lead_id = (int) $row->lead_id;
            $meta    = json_decode( (string) $row->metadata, true );
            $meta    = is_array( $meta ) ? $meta : array();
            $op_id   = (int) ( $meta['operator_id'] ?? 0 );

            $submission_id = (int) ( $row->submission_id ?? 0 );
            if ( $submission_id < 1 || $lead_id < 1 ) {
                continue;
            }

            // Per-operator hours check
            if ( $op_id > 0 && class_exists( 'Estandar_Leads_Operator_Hours' ) ) {
                $operator = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}es_operators WHERE id = %d LIMIT 1", $op_id
                ) );
                if ( $operator && ! Estandar_Leads_Operator_Hours::is_open( $operator ) ) {
                    continue;
                }
            } elseif ( class_exists( 'Estandar_Operational_Hours' ) && ! Estandar_Operational_Hours::is_open() ) {
                continue;
            }

            $is_new_lead = false;
            $source_plugin = sanitize_key( $meta['source_plugin'] ?? '' );

            if ( self::already_notified_for_submission( $lead_id, $submission_id ) ) {
                continue;
            }

            $sub_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT context_json, form_fields_json FROM {$wpdb->prefix}es_lead_submissions WHERE id = %d LIMIT 1",
                $submission_id
            ) );

            $lead_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT name, email, phone FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
                $lead_id
            ) );

            if ( ! $lead_row ) {
                continue;
            }

            $context_json = json_decode( (string) ( $sub_row->context_json ?? '{}' ), true );
            $context_json = is_array( $context_json ) ? $context_json : array();

            $payload_reconstructed = array(
                'contact' => array(
                    'name'  => $lead_row->name ?? '',
                    'email' => $lead_row->email ?? '',
                    'phone' => $lead_row->phone ?? '',
                ),
                'attribution' => array(),
                'context' => $context_json,
                'source_plugin' => $source_plugin,
                'submitted_at' => '',
                'content' => array( 'message' => '' ),
                'meta' => array( 'sanitized_raw_fields' => array() ),
            );

            self::notify_operators( $lead_id, $submission_id, $is_new_lead, $payload_reconstructed );
        }
    }

    /**
     * OPEN_EVIDENCE_V1 — whether this lead has at least one human-relevant
     * email open. A technical_fetch or probable_machine_open never counts.
     * Only probable_human_open or confirmed_human_engagement qualify.
     */
    private static function has_human_email_open( int $lead_id ): bool {
        if ( $lead_id < 1 ) {
            return false;
        }
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND event_type = 'autoresponse_open_classified'
             AND (metadata LIKE %s OR metadata LIKE %s)",
            $lead_id,
            '%"open_classification":"probable_human_open"%',
            '%"open_classification":"confirmed_human_engagement"%'
        ) );
        return $count > 0;
    }

    /**
     * OPERATOR_RESPONSE_SLA_V1 — record explicit delivery event when a
     * previously-deferred notification is actually sent. The event bridges
     * `operator_notification_deferred` → `operator_notified` and marks the
     * moment the operator SLA clock starts ticking.
     */
    private static function record_delivered_event( int $lead_id, int $submission_id, array $payload ): void {
        if ( $lead_id < 1 || $submission_id < 1 ) {
            return;
        }
        global $wpdb;

        $deferred = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, created_at, metadata FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND submission_id = %d AND event_type = 'operator_notification_deferred'
             ORDER BY created_at ASC LIMIT 1",
            $lead_id, $submission_id
        ) );

        if ( ! $deferred ) {
            return;
        }

        $deferred_meta = json_decode( (string) ( $deferred->metadata ?? '' ), true );
        $deferred_meta = is_array( $deferred_meta ) ? $deferred_meta : array();

        $deferred_ts = strtotime( (string) $deferred->created_at );
        $now_ts      = time();
        $delay_hours = $deferred_ts ? (int) floor( ( $now_ts - $deferred_ts ) / HOUR_IN_SECONDS ) : 0;

        $metadata = array(
            'deferred_event_id'   => (int) $deferred->id,
            'deferred_at'         => (string) $deferred->created_at,
            'delay_hours'         => $delay_hours,
            'deferral_reason'     => (string) ( $deferred_meta['reason'] ?? 'outside_operational_hours' ),
            'project_slug'        => (string) ( $deferred_meta['project_slug'] ?? '' ),
            'operator_id'         => (int) ( $payload['_deferred_operator_id'] ?? $deferred_meta['operator_id'] ?? 0 ),
            'sla_start'           => gmdate( 'Y-m-d H:i:s' ),
        );

        Estandar_Leads_Repository::insert_event( $lead_id, 'operator_notification_delivered', array(
            'submission_id' => $submission_id,
            'metadata'      => $metadata,
        ) );
    }

    /**
     * Idempotency guard: true if operator_notified already exists for this lead+submission.
     */
    private static function already_notified_for_submission( int $lead_id, int $submission_id ): bool {
        if ( $lead_id < 1 || $submission_id < 1 ) {
            return false;
        }
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND submission_id = %d AND event_type = 'operator_notified'
             LIMIT 1",
            $lead_id,
            $submission_id
        ) );
        return (bool) $exists;
    }

    private static function notification_policy_headers(array $settings)
    {
        $cc_email = Estandar_Leads_Email::sanitize_email_list($settings['notification_cc_email'] ?? get_option('estandar_leads_notification_cc_email', ''));
        if ($cc_email === '') {
            $cc_email = Estandar_Leads_Email::sanitize_email_list(get_option('estandar_leads_notification_cc_email', ''));
        }
        $bcc_email = Estandar_Leads_Email::sanitize_email_list($settings['notification_bcc_email'] ?? '');
        $reply_to_email = sanitize_email((string)($settings['reply_to_email'] ?? ''));
        $headers = array();
        if ($reply_to_email !== '' && is_email($reply_to_email)) {
            $headers[] = 'Reply-To: ' . $reply_to_email;
        }
        if ($cc_email !== '') {
            $headers[] = 'Cc: ' . $cc_email;
        }
        if ($bcc_email !== '') {
            $headers[] = 'Bcc: ' . $bcc_email;
        }
        return array(
            'headers' => $headers,
            'cc' => $cc_email,
            'bcc' => $bcc_email,
            'reply_to' => is_email($reply_to_email) ? $reply_to_email : '',
        );
    }

    public static function admin_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (get_transient('estandar_leads_project_unresolved_notice')) {
            delete_transient('estandar_leads_project_unresolved_notice');
            echo '<div class="notice notice-warning"><p>Estandar Leads: se bloqueó una notificación porque el lead no tenía contexto de proyecto. Revise el formulario o shortcode de origen.</p></div>';
        }
        if (!get_transient('estandar_leads_no_active_operators_notice')) {
            return;
        }
        delete_transient('estandar_leads_no_active_operators_notice');
        echo '<div class="notice notice-warning"><p>Estandar Leads: no hay operadores activos con email para notificar nuevos leads.</p></div>';
    }

    public static function email_body($contact, $attr, $source_plugin, $project_area, $feedback_url)
    {
        return implode("\n", array(
            'Nuevo lead recibido',
            '',
            'Nombre: ' . sanitize_text_field($contact['name'] ?? ''),
            'Email: ' . sanitize_email($contact['email'] ?? ''),
            'Teléfono: ' . sanitize_text_field($contact['phone'] ?? ''),
            'Origen: ' . sanitize_text_field($source_plugin),
            'Área / Proyecto: ' . sanitize_text_field($project_area),
            'Campaña: ' . (class_exists('Estandar_Campaign_Label_Resolver') ? Estandar_Campaign_Label_Resolver::campaign($attr['utm_campaign'] ?? '') : sanitize_text_field($attr['utm_campaign'] ?? '')),
            'Conjunto: ' . (class_exists('Estandar_Campaign_Label_Resolver') ? Estandar_Campaign_Label_Resolver::adset($attr['utm_term'] ?? '') : sanitize_text_field($attr['utm_term'] ?? '')),
            'Anuncio: ' . (class_exists('Estandar_Campaign_Label_Resolver') ? Estandar_Campaign_Label_Resolver::ad($attr['utm_content'] ?? '') : sanitize_text_field($attr['utm_content'] ?? '')),
            '',
            'Clasificar lead:',
            esc_url_raw($feedback_url),
        ));
    }

    /**
     * COMMERCIAL_RECONTACT_CONTEXT_V1 — compute recontact context for a submission.
     *
     * Walks all submissions of a lead in chronological order. A "commercial
     * recontact" is counted every time the gap between consecutive submissions
     * exceeds the session window (default 3 h, filterable). Submissions inside
     * the window are same-session contacts and are recorded for audit but
     * produce no commercial alert.
     *
     * @param int $lead_id
     * @param int $current_submission_id The submission being notified.
     * @return array{ commercial_recontact_count: int, is_commercial_recontact: bool, is_same_session_contact: bool, total_sessions: int, session_window_hours: float }
     */
    public static function get_commercial_recontact_for_submission($lead_id, $current_submission_id)
    {
        global $wpdb;

        $window_seconds = (int) apply_filters('estandar_leads_recontact_session_window_seconds', 3 * HOUR_IN_SECONDS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, submitted_at FROM {$wpdb->prefix}es_lead_submissions
             WHERE lead_id = %d
             ORDER BY submitted_at ASC, id ASC",
            absint($lead_id)
        ));

        $result = array(
            'commercial_recontact_count' => 0,
            'is_commercial_recontact'    => false,
            'is_same_session_contact'    => false,
            'total_sessions'             => 0,
            'session_window_hours'       => round($window_seconds / HOUR_IN_SECONDS, 1),
        );

        if (!$rows || count($rows) < 2) {
            $result['total_sessions'] = $rows ? 1 : 0;
            return $result;
        }

        $times = array();
        foreach ($rows as $r) {
            $times[] = array('id' => (int) $r->id, 'ts' => strtotime($r->submitted_at));
        }

        $commercial_recontact_count = 0;
        $current_is_recontact = false;
        $current_is_same_session = false;

        for ($i = 1; $i < count($times); $i++) {
            $gap = $times[$i]['ts'] - $times[$i - 1]['ts'];

            if ($gap > $window_seconds) {
                $commercial_recontact_count++;
                if ($times[$i]['id'] === $current_submission_id) {
                    $current_is_recontact = true;
                }
            } else {
                if ($times[$i]['id'] === $current_submission_id) {
                    $current_is_same_session = true;
                }
            }
        }

        $result['commercial_recontact_count'] = $commercial_recontact_count;
        $result['is_commercial_recontact']    = $current_is_recontact;
        $result['is_same_session_contact']    = $current_is_same_session;
        $result['total_sessions']             = $commercial_recontact_count + 1;

        return $result;
    }

    private static function telemetry($severity, $code, $message, $context = array())
    {
        try {
            do_action('estandar_telemetry_event', array(
                'source_plugin' => 'estandar-leads',
                'type' => 'business_event',
                'severity' => $severity,
                'code' => $code,
                'message' => $message,
                'context' => is_array($context) ? $context : array(),
            ));
        } catch (Throwable $e) {
            return;
        }
    }
}
