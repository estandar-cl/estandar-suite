<?php
/**
 * LEAD_RESPONSE_ACCELERATION_V1 — Autoresponse engine (Fase 1-2).
 *
 * Subscriber de `estandar_lead_ingested` (prioridad 20, después de Notifier=10).
 * Aditivo: cero columnas/tablas nuevas, cero cambios a Compliance/Interest
 * Assignment. Vocabulario nuevo en `es_lead_events`:
 *   - autoresponse_email_sent    (to_status = project_slug)
 *   - autoresponse_email_failed  (to_status = project_slug, reason en metadata;
 *     SOLO fallos reales: send_error / missing_project / suspect / throttled)
 *   - autoresponse_skipped       (skip intencional, reason=no_email — un lead
 *     phone-only sin destino no es un fallo; SEMANTIC_STATUS_V1 · Lead #478)
 *   - autoresponse_email_open_signal (pixel, señal referencial — no KPI)
 *   - autoresponse_link_click    (metadata.resource_type = pdf|landing)
 *   - autoresponse_engaged       (derivado: primer pdf/landing/whatsapp click)
 *
 * Throttle = (submission email_hash, project_slug), with an atomic reservation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Autoresponse
{
    const EVENT_SENT = 'autoresponse_email_sent';
    const EVENT_FAILED = 'autoresponse_email_failed';
    const EVENT_SKIPPED = 'autoresponse_skipped';
    const EVENT_OPEN_SIGNAL = 'autoresponse_email_open_signal';
    const EVENT_LINK_CLICK = 'autoresponse_link_click';
    const EVENT_ENGAGED = 'autoresponse_engaged';
    const EVENT_OPEN_CLASSIFIED = 'autoresponse_open_classified';

    const OPEN_CLASS_TECHNICAL_FETCH     = 'technical_fetch';
    const OPEN_CLASS_PROBABLE_MACHINE     = 'probable_machine_open';
    const OPEN_CLASS_PROBABLE_HUMAN       = 'probable_human_open';
    const OPEN_CLASS_CONFIRMED_HUMAN      = 'confirmed_human_engagement';

    public static function init()
    {
        add_action('estandar_lead_ingested', array(__CLASS__, 'on_lead_ingested'), 20, 4);
    }

    /**
     * Hook entrypoint. Never lets an exception bubble back into ingestion —
     * autoresponse is best-effort and must not block lead capture.
     */
    public static function on_lead_ingested($lead_id, $submission_id, $is_new, $payload)
    {
        try {
            self::maybe_send($lead_id, $submission_id, is_array($payload) ? $payload : array());
        } catch (Throwable $e) {
            error_log('[estandar-leads][autoresponse] ' . $e->getMessage());
        }
    }

    private static function maybe_send($lead_id, $submission_id, array $payload)
    {
        $lead_id = absint($lead_id);
        $submission_id = absint($submission_id);
        if ($lead_id === 0 || $submission_id === 0) {
            return;
        }

        $contact = is_array($payload['contact'] ?? null) ? $payload['contact'] : array();
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : array();
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : array();
        $spam = is_array($payload['spam'] ?? null) ? $payload['spam'] : array();
        $request = is_array($payload['request'] ?? null) ? $payload['request'] : array();

        $project_area = sanitize_text_field($context['project_area'] ?? '');
        $project_slug = Estandar_Leads_Projects::resolve_slug($context['project_slug'] ?? '', $project_area);

        $settings = Estandar_Leads_Email::get_autoresponse_settings($project_slug);

        // Gate 0: opt-in per project. Disabled is the default — no event noise
        // for the common case of a project that hasn't configured autoresponse.
        if (empty($settings['enabled'])) {
            return;
        }

        $operator = Estandar_Leads_Repository::operator_for_submission($lead_id, $submission_id);
        $operator_id = $operator ? absint($operator->operator_id) : 0;

        // LEAD_RESPONSE_ACCELERATION_V1 refinamiento (15-jun, Rodrigo):
        // autoresponse_project_id / autoresponse_operator_id en metadata de
        // todos los eventos de esta familia, para comparar proyecto/operador
        // sin rediseño futuro.
        $base_metadata = array(
            'autoresponse_project_id' => $project_slug,
            'autoresponse_operator_id' => $operator_id,
        );

        // COMMERCIAL_QUALIFICATION_V1 — autoresponse policy gate. Checked only
        // once the project opted in (Gate 0), so projects without autoresponse
        // generate no policy noise. A policy skip is an intentional decision:
        // recorded as its own event, never as an autoresponse failure.
        if (class_exists('Estandar_Commercial_Qualification_Engine')) {
            $qualification = Estandar_Commercial_Qualification_Engine::decision_for($lead_id, $submission_id, $payload);
            if (($qualification['autoresponse_policy'] ?? Estandar_Commercial_Qualification_Engine::ALLOW) === Estandar_Commercial_Qualification_Engine::SKIP) {
                Estandar_Leads_Repository::insert_event($lead_id, 'autoresponse_skipped_by_policy', array(
                    'submission_id' => $submission_id,
                    'to_status'     => $project_slug,
                    'reason'        => sanitize_text_field((string) ($qualification['policy_reason'] ?? '')),
                    'metadata'      => $base_metadata + array(
                        'matched_rules' => (array) ($qualification['matched_rules'] ?? array()),
                        'rule_sources'  => (array) ($qualification['rule_sources'] ?? array()),
                        'policy_reason' => (string) ($qualification['policy_reason'] ?? ''),
                    ),
                ));
                return;
            }
        }

        if ($project_slug === '') {
            self::log_failed($lead_id, $submission_id, $project_slug, $operator_id, 'missing_project', $base_metadata);
            return;
        }

        // Gate 1: valid email — required, no email = nowhere to send.
        // AUTORESPONSE_EMAIL_FALLBACK_V1 (2026-06-23): fallback chain
        // submission.email → lead.email → fail
        $email = Estandar_Leads_Normalizer::email($contact['email'] ?? '');
        $email_source = 'submission';

        if (!is_email($email)) {
            $email = self::resolve_fallback_email($lead_id);
            if (is_email($email)) {
                $email_source = 'lead';
                self::telemetry_email_source($lead_id, $submission_id, $email_source, $base_metadata);
            } else {
                // Lead sin email (phone-only, email opcional): no hay destino,
                // no hubo intento — skip intencional, nunca un fallo.
                self::log_skipped($lead_id, $submission_id, $project_slug, $operator_id, 'no_email', $base_metadata);
                return;
            }
        } else {
            self::telemetry_email_source($lead_id, $submission_id, $email_source, $base_metadata);
        }

        // Gate 2: suspect — same criteria as Estandar_Leads_Ingestion::suspect_reasons()
        // (honeypot / too_fast / missing_user_agent). R1 (AUTORESPONSE_PROJECT_V1):
        // do not relay autoresponse traffic to suspected-bot submissions.
        $suspect_reasons = self::suspect_reasons($spam, $request);
        if (!empty($suspect_reasons)) {
            self::log_failed($lead_id, $submission_id, $project_slug, $operator_id, 'suspect', $base_metadata + array(
                'suspect_reasons' => $suspect_reasons,
            ));
            return;
        }

        // Gate 3: atomic throttle reservation by submission email+project.
        $window_hours = max(0, (int) ($settings['throttle_hours'] ?? 24));
        $email_hash = Estandar_Leads_Normalizer::hash($email);
        $reservation = Estandar_Leads_Repository::reserve_autoresponse($email_hash, $project_slug, $window_hours);
        if ($reservation === false) {
            self::log_failed($lead_id, $submission_id, $project_slug, $operator_id, 'throttled', $base_metadata);
            return;
        }

        try {
            // -------------------------------------------------------------
            // Build template data
            // -------------------------------------------------------------
            $lead_name = sanitize_text_field($contact['name'] ?? '');
            $message = sanitize_textarea_field($content['message'] ?? '');
            $project_name = self::project_name($project_slug, $project_area);

        $operator_name = $operator ? sanitize_text_field((string) $operator->operator_name) : '';
        $operator_email = $operator ? sanitize_email((string) $operator->operator_email) : '';

        // OPERATIONAL_HOURS_V1: contextual message for out-of-hours autoresponse.
        // Uses per-operator hours when available; falls back to global hours.
        $horario_mensaje = '';
        $out_of_hours = false;
        if ( $operator && class_exists( 'Estandar_Leads_Operator_Hours' ) ) {
            $out_of_hours = ! Estandar_Leads_Operator_Hours::is_open( $operator );
        } elseif ( class_exists( 'Estandar_Operational_Hours' ) ) {
            $out_of_hours = ! Estandar_Operational_Hours::is_open();
        }

        if ( $out_of_hours ) {
            $horario_mensaje = apply_filters(
                'estandar_leads_autoresponse_out_of_hours_line',
                __( 'Recibimos correctamente tu solicitud. Un ejecutivo se pondrá en contacto contigo lo antes posible, dentro de nuestro horario laboral.', 'estandar-leads' ),
                $project_slug,
                $operator
            );
        }

        $vars = array(
            'nombre'         => $lead_name !== '' ? $lead_name : 'hola',
            'email'          => $email,
            'telefono'       => sanitize_text_field((string) ($contact['phone'] ?? '')),
            'proyecto'       => $project_name !== '' ? $project_name : $project_slug,
            'mensaje'        => $message,
            'fecha'          => date_i18n(get_option('date_format')),
            'sitio'          => get_bloginfo('name'),
            'operador'       => $operator_name !== '' ? $operator_name : get_bloginfo('name'),
            // AUTORESPONSE_TEMPLATE_FIX_V1 — extended vars.
            'operator_email' => $operator_email,
            'operator_phone' => '',   // Not in operator schema — always empty.
            'website'        => home_url('/'),
            'cargo'          => '',   // Not in operator schema — always empty.
            // CTA placeholders in body are stripped (system buttons are the only CTA source).
            'pdf_link'       => '',
            'landing_link'   => '',
            'whatsapp_link'  => '',
            // OPERATIONAL_HOURS_V1
            'horario_mensaje' => $horario_mensaje,
        );

        $subject = Estandar_Leads_Email::substitute_placeholders((string) $settings['subject'], $vars);
        $body    = Estandar_Leads_Email::substitute_placeholders((string) $settings['body'], $vars);

        // AUTORESPONSE_TEMPLATE_FIX_V1 — strip any remaining unresolved {{placeholders}}.
        $subject = preg_replace('/\{\{[^}]+\}\}/', '', $subject);
        $body    = preg_replace('/\{\{[^}]+\}\}/', '', $body);

        // AUTORESPONSE_TEMPLATE_FIX_V1 — collapse 3+ consecutive newlines to 2
        // (prevents double-paragraph gaps introduced by textarea editing).
        $body = preg_replace('/\n{3,}/', "\n\n", $body);
        $body = trim($body);

        // -------------------------------------------------------------
        // Links — PDF / landing via /go/{token} (Fase 2), WhatsApp via
        // /wp-json/estandar-leads/v1/whatsapp-redirect (with tracking params).
        // -------------------------------------------------------------
        $attachments = array();
        $pdf_attached = false;
        $pdf_link = '';
        $pdf_attachment_id = absint($settings['pdf_attachment_id'] ?? 0);
        if ($pdf_attachment_id > 0) {
            $attached_file = get_attached_file($pdf_attachment_id);
            // R3 (AUTORESPONSE_PROJECT_V1): unreadable attachment never blocks
            // send — fall back to sending without it.
            if ($attached_file && is_readable($attached_file)) {
                $attachments[] = $attached_file;
                $pdf_attached = true;
            }
            $pdf_url = wp_get_attachment_url($pdf_attachment_id);
            if (is_string($pdf_url) && $pdf_url !== '') {
                $pdf_token = Estandar_Leads_Tokens::sign_link($lead_id, $submission_id, 'pdf', $pdf_url, 30, $project_slug);
                $pdf_link = self::go_url($pdf_token);
            }
        }

        $landing_link = '';
        $landing_url = (string) ($settings['landing_url'] ?? '');
        if ($landing_url !== '') {
            $landing_token = Estandar_Leads_Tokens::sign_link($lead_id, $submission_id, 'landing', $landing_url, 30, $project_slug);
            $landing_link = self::go_url($landing_token);
        }

        $whatsapp_token = Estandar_Leads_Tokens::sign_link(
            $lead_id,
            $submission_id,
            'whatsapp',
            rest_url('estandar-leads/v1/whatsapp-redirect'),
            30,
            $project_slug
        );
        $whatsapp_link = add_query_arg(array(
            'project_slug' => $project_slug,
            'source' => 'email_autoresponse',
            'lead_id' => $lead_id,
            'submission_id' => $submission_id,
            'tracking_token' => $whatsapp_token,
        ), rest_url('estandar-leads/v1/whatsapp-redirect'));

        // Open signal pixel (referencial, ver §4 spec — no es KPI).
        $pixel_token = Estandar_Leads_Tokens::sign_link($lead_id, $submission_id, 'pixel', home_url('/'), 30, $project_slug);
        $pixel_url = self::go_url($pixel_token);

        // -------------------------------------------------------------
        // Sender resolution — From/Reply-To = operador (Refinamientos §1)
        // -------------------------------------------------------------
        $brand_settings = Estandar_Leads_Email::get_settings();
        $send_settings = $brand_settings;
        $headers = array();

        if ($operator_email !== '' && is_email($operator_email)) {
            $operator_domain = self::email_domain($operator_email);
            $default_domain = self::email_domain((string) ($brand_settings['sender_email'] ?? ''));

            if ($default_domain !== '' && $operator_domain === $default_domain) {
                // Domains aligned — From = operador passes SPF/DKIM/DMARC.
                $send_settings['sender_email'] = $operator_email;
                $send_settings['sender_name'] = $operator_name !== '' ? $operator_name : ($brand_settings['sender_name'] ?? '');
            }
            // Either way, Reply-To = operador — no alignment restriction.
            $headers[] = 'Reply-To: ' . ($operator_name !== '' ? $operator_name . ' <' . $operator_email . '>' : $operator_email);
        }

        $html = Estandar_Leads_Email::render_autoresponse(array(
            'project_slug' => $project_slug,
            'project_name' => $project_name,
            'lead_name' => $lead_name,
            'operator_name' => $operator_name,
            'operator_email' => $operator_email,
            'subject' => $subject,
            'body' => $body,
            'pdf_link' => $pdf_link,
            'landing_link' => $landing_link,
            'whatsapp_link' => $whatsapp_link,
            'pixel_url' => $pixel_url,
        ), $settings);

        $sent = Estandar_Leads_Email::send($email, $subject, $html, $send_settings, $headers, $attachments);

            if ($sent) {
                Estandar_Leads_Repository::insert_event($lead_id, self::EVENT_SENT, array(
                    'submission_id' => $submission_id,
                    'operator_id' => $operator_id ?: null,
                    'to_status' => $project_slug,
                    'metadata' => $base_metadata + array(
                        'pdf_attached' => $pdf_attached,
                        'autoresponse_variant' => $out_of_hours ? 'out_of_hours' : 'in_hours',
                        'out_of_hours' => $out_of_hours,
                    ),
                ));
            } else {
                Estandar_Leads_Repository::release_autoresponse_reservation($email_hash, $project_slug, $reservation);
                self::log_failed($lead_id, $submission_id, $project_slug, $operator_id, 'send_error', $base_metadata);
            }
        } catch (Throwable $e) {
            Estandar_Leads_Repository::release_autoresponse_reservation($email_hash, $project_slug, $reservation);
            throw $e;
        }
    }

    /**
     * LEAD_RESPONSE_ACCELERATION_V1 (Fase 2) — record a /go/{token} click.
     * Called by Estandar_Leads_Link_Redirect::handle().
     * ACTOR_AUDIT_FIX_V1 (17-jun): captures actor_type, IP, user_agent, referer, session_id.
     */
    public static function record_link_click($lead_id, $submission_id, $resource_type, $project_slug, array $context = array())
    {
        $lead_id = absint($lead_id);
        if ($lead_id === 0) {
            return;
        }

        $resource_type = sanitize_key($resource_type);
        $project_slug = sanitize_key($project_slug);

        $operator = $submission_id ? Estandar_Leads_Repository::operator_for_submission($lead_id, $submission_id) : null;
        $operator_id = $operator ? absint($operator->operator_id) : 0;

        // ACTOR_AUDIT_FIX_V1 — actor detection
        $actor_type = is_user_logged_in() ? 'admin' : 'lead';
        $ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $user_agent = sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $referer = sanitize_textarea_field(wp_unslash($_SERVER['HTTP_REFERER'] ?? ''));

        $metadata = array(
            'resource_type' => $resource_type,
            'autoresponse_project_id' => $project_slug,
            'autoresponse_operator_id' => $operator_id,
            'actor_type' => $actor_type,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'referer' => $referer,
        );

        if ($actor_type === 'admin') {
            $user_id = get_current_user_id();
            $metadata['actor_user_id'] = $user_id;
            // Register admin email for audit trail
            $user = get_user_by('id', $user_id);
            if ($user && isset($user->user_email)) {
                $metadata['actor_email'] = sanitize_email($user->user_email);
            }
        }

        if ($submission_id > 0) {
            $metadata['session_id'] = session_id() ?: wp_get_session_token();
        }

        Estandar_Leads_Repository::insert_event($lead_id, self::EVENT_LINK_CLICK, array(
            'submission_id' => $submission_id,
            'operator_id' => $operator_id ?: null,
            'to_status' => $project_slug,
            'metadata' => $metadata,
        ));

        if (in_array($resource_type, array('pdf', 'landing'), true)) {
            self::record_engagement_if_new($lead_id, $submission_id, $resource_type, $metadata);
        }

        // OPEN_EVIDENCE_V1 — a link click provides confirmed human evidence;
        // upgrade any prior ambiguous opens for this submission.
        if (class_exists('Estandar_Leads_Autoresponse')) {
            self::upgrade_open_classification($lead_id, $submission_id, $project_slug);
        }
    }

    /**
     * LEAD_RESPONSE_ACCELERATION_V1 (Fase 2) — record the open-signal pixel.
     * Referencial only (ver §4 spec) — never feeds a KPI.
     *
     * OPEN_EVIDENCE_V1: records the raw `autoresponse_email_open_signal` event
     * (backward-compatible, untouched) PLUS a derived classification event
     * `autoresponse_open_classified` with one of:
     *   technical_fetch / probable_machine_open / probable_human_open / confirmed_human_engagement
     *
     * Classification is based on available evidence only: time since send, UA/IP
     * patterns, multiple opens, subsequent clicks. No OCR, no external services.
     * An isolated immediate open does NOT add score; an open followed by a click
     * upgrades to confirmed_human_engagement.
     */
    public static function record_open_signal($lead_id, $submission_id, $project_slug)
    {
        $lead_id = absint($lead_id);
        if ($lead_id === 0) {
            return;
        }

        $project_slug = sanitize_key($project_slug);

        $ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $user_agent = sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $referer = sanitize_textarea_field(wp_unslash($_SERVER['HTTP_REFERER'] ?? ''));

        $actor_type = self::classify_open_signal_actor($user_agent);

        $metadata = array(
            'autoresponse_project_id' => $project_slug,
            'actor_type' => $actor_type,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'referer' => $referer,
        );

        // Raw event — backward compatible, untouched
        Estandar_Leads_Repository::insert_event($lead_id, self::EVENT_OPEN_SIGNAL, array(
            'submission_id' => $submission_id,
            'to_status' => $project_slug,
            'metadata' => $metadata,
        ));

        // OPEN_EVIDENCE_V1 — derive classification from available evidence
        $classification = self::classify_open_evidence($lead_id, $submission_id, $actor_type, $user_agent, $ip_address);

        $score_eligible = ! in_array( $classification['class'], array( self::OPEN_CLASS_TECHNICAL_FETCH, self::OPEN_CLASS_PROBABLE_MACHINE ), true );

        Estandar_Leads_Repository::insert_event($lead_id, self::EVENT_OPEN_CLASSIFIED, array(
            'submission_id' => $submission_id,
            'to_status' => $project_slug,
            'metadata' => $metadata + array(
                'open_classification' => $classification['class'],
                'classification_evidence' => $classification['evidence'],
                'classification_confidence' => $classification['confidence'],
                'score_eligible' => $score_eligible,
            ),
        ));
    }

    /**
     * OPEN_EVIDENCE_V1 — classify an open signal based on available evidence.
     *
     * Evidence hierarchy (best to weakest):
     * 1. Previous confirmed click → confirmed_human_engagement
     * 2. Time since send < 5s → technical_fetch (pre-fetch/proxy/anti-spam)
     * 3. Known scanner/proxy UA → probable_machine_open
     * 4. Multiple opens from different IPs → probable_human_open
     * 5. Single open, reasonable timing → probable_human_open
     * 6. Single open, immediate/ambiguous → probable_machine_open
     *
     * @return array{class:string,evidence:string,confidence:string}
     */
    private static function classify_open_evidence($lead_id, $submission_id, $actor_type, $user_agent, $ip_address): array
    {
        global $wpdb;

        if (self::has_prior_confirmed_click($lead_id, $submission_id)) {
            return array(
                'class' => self::OPEN_CLASS_CONFIRMED_HUMAN,
                'evidence' => 'prior_link_click',
                'confidence' => 'high',
            );
        }

        $sent_event = $wpdb->get_row($wpdb->prepare(
            "SELECT created_at FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND submission_id = %d AND event_type = 'autoresponse_email_sent'
             ORDER BY created_at DESC LIMIT 1",
            $lead_id, $submission_id
        ));

        if ($sent_event) {
            $sent_ts = strtotime((string) $sent_event->created_at);
            $now_ts = time();
            $seconds_since_send = $sent_ts ? ($now_ts - $sent_ts) : null;

            if ($seconds_since_send !== null && $seconds_since_send < 5) {
                return array(
                    'class' => self::OPEN_CLASS_TECHNICAL_FETCH,
                    'evidence' => 'open_within_' . $seconds_since_send . 's_of_send',
                    'confidence' => 'high',
                );
            }
        }

        if ($actor_type === 'system') {
            return array(
                'class' => self::OPEN_CLASS_PROBABLE_MACHINE,
                'evidence' => 'known_scanner_ua',
                'confidence' => 'high',
            );
        }

        $open_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND event_type = 'autoresponse_email_open_signal'
             AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $lead_id
        ));

        $from_ips = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ip_address FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND event_type = 'autoresponse_email_open_signal'
             AND ip_address != '' AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $lead_id
        ));

        if (count($from_ips) > 1) {
            return array(
                'class' => self::OPEN_CLASS_PROBABLE_HUMAN,
                'evidence' => 'multiple_open_ips (' . count($from_ips) . ')',
                'confidence' => 'medium',
            );
        }

        if ($open_count >= 2) {
            return array(
                'class' => self::OPEN_CLASS_PROBABLE_HUMAN,
                'evidence' => 'multiple_opens (' . $open_count . ')',
                'confidence' => 'medium',
            );
        }

        if (isset($seconds_since_send) && $seconds_since_send > 30) {
            return array(
                'class' => self::OPEN_CLASS_PROBABLE_HUMAN,
                'evidence' => 'open_after_' . $seconds_since_send . 's',
                'confidence' => 'low',
            );
        }

        return array(
            'class' => self::OPEN_CLASS_PROBABLE_MACHINE,
            'evidence' => 'isolated_immediate_open',
            'confidence' => 'low',
        );
    }

    /**
     * Check whether a confirmed human click (link click or engagement) exists
     * prior to this open signal.
     */
    private static function has_prior_confirmed_click($lead_id, $submission_id): bool
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d
               AND (submission_id = %d OR submission_id IS NULL)
               AND event_type IN ('autoresponse_link_click', 'autoresponse_engaged')
             LIMIT 1",
            $lead_id, $submission_id
        ));
        return $found !== null;
    }

    /**
     * OPEN_EVIDENCE_V1 — upgrade open signal classification when a link click
     * provides confirmed human evidence. Called after recording a link click
     * to reclassify prior ambiguous opens as confirmed human engagement.
     */
    public static function upgrade_open_classification($lead_id, $submission_id, $project_slug): void
    {
        $lead_id = absint($lead_id);
        if ($lead_id === 0) {
            return;
        }

        $submission_id = absint($submission_id);
        $project_slug = sanitize_key($project_slug);

        Estandar_Leads_Repository::insert_event($lead_id, self::EVENT_OPEN_CLASSIFIED, array(
            'submission_id' => $submission_id,
            'to_status' => $project_slug,
            'metadata' => array(
                'open_classification' => self::OPEN_CLASS_CONFIRMED_HUMAN,
                'classification_evidence' => 'confirmed_by_click',
                'classification_confidence' => 'high',
                'score_eligible' => true,
                'autoresponse_project_id' => $project_slug,
            ),
        ));
    }

    /**
     * Classify open signal actor based on user agent patterns.
     * Returns 'system' for known auto-scanners, 'unknown' otherwise.
     */
    private static function classify_open_signal_actor($user_agent)
    {
        $user_agent = strtolower((string) $user_agent);

        $system_patterns = array(
            'applemail',
            'mail.ru',
            'outlook',
            'msoutlook',
            'thunderbird',
            'getlasso',
            'opsgenie',
            'slack',
            'crawler',
            'bot',
            'spider',
            'scanner',
            'antivirus',
            'barracuda',
            'mimecast',
            'proofpoint',
            'symantec',
            'trendmicro',
            'microsoft office',
            'windows-mail',
            'gmail-image-proxy',
            'google-image-proxy',
            'yahoo! mail proxy',
        );

        foreach ($system_patterns as $pattern) {
            if (strpos($user_agent, $pattern) !== false) {
                return 'system';
            }
        }

        return 'unknown';
    }

    /**
     * LEAD_RESPONSE_ACCELERATION_V1 (refinamiento 15-jun, Rodrigo) — derived
     * `autoresponse_engaged` event: fires on the FIRST of pdf/landing/whatsapp
     * click per (lead_id, submission_id). De-duplicated via
     * Repository::has_autoresponse_engagement(). Called from
     * record_link_click() (pdf/landing) and from the WhatsApp redirect
     * (channel='whatsapp') when source=email_autoresponse.
     * ACTOR_AUDIT_FIX_V1 (17-jun): inherits actor metadata from triggering event.
     */
    public static function record_engagement_if_new($lead_id, $submission_id, $channel, array $extra_metadata = array())
    {
        $lead_id = absint($lead_id);
        if ($lead_id === 0) {
            return;
        }

        $submission_id = absint($submission_id);
        $channel = sanitize_key($channel);

        if (Estandar_Leads_Repository::has_autoresponse_engagement($lead_id, $submission_id)) {
            return;
        }

        // ACTOR_AUDIT_FIX_V1 — inherit actor context from triggering event metadata
        $inherited_metadata = array();
        foreach (array('actor_type', 'actor_user_id', 'ip_address', 'user_agent', 'session_id', 'autoresponse_project_id', 'autoresponse_operator_id') as $key) {
            if (isset($extra_metadata[$key])) {
                $inherited_metadata[$key] = $extra_metadata[$key];
            }
        }

        Estandar_Leads_Repository::insert_event($lead_id, self::EVENT_ENGAGED, array(
            'submission_id' => $submission_id,
            'to_status' => $channel,
            'metadata' => array_merge(array('channel' => $channel), $inherited_metadata),
        ));
    }

    public static function build_test_message($project_slug, $project_name)
    {
        $project_slug = sanitize_key($project_slug);
        $project_name = sanitize_text_field($project_name);
        $settings = Estandar_Leads_Email::get_autoresponse_settings($project_slug);
        $brand_settings = Estandar_Leads_Email::get_settings();

        $vars = array(
            'nombre'         => 'Juan Pérez',
            'email'          => 'juan.perez@example.com',
            'telefono'       => '+56900000000',
            'proyecto'       => $project_name !== '' ? $project_name : $project_slug,
            'mensaje'        => 'Necesito más información del proyecto.',
            'fecha'          => date_i18n(get_option('date_format')),
            'sitio'          => get_bloginfo('name'),
            'operador'       => get_bloginfo('name'),
            'operator_email' => '',
            'operator_phone' => '',
            'website'        => home_url('/'),
            'cargo'          => '',
            'pdf_link'       => '',
            'landing_link'   => '',
            'whatsapp_link'  => '',
        );

        $subject = Estandar_Leads_Email::substitute_placeholders((string) $settings['subject'], $vars);
        $body    = Estandar_Leads_Email::substitute_placeholders((string) $settings['body'], $vars);
        $subject = preg_replace('/\{\{[^}]+\}\}/', '', $subject);
        $body    = preg_replace('/\{\{[^}]+\}\}/', '', $body);
        $body    = trim(preg_replace('/\n{3,}/', "\n\n", $body));

        $attachments = array();
        $pdf_link = '';
        $pdf_attachment_id = absint($settings['pdf_attachment_id'] ?? 0);
        if ($pdf_attachment_id > 0) {
            $attached_file = get_attached_file($pdf_attachment_id);
            if ($attached_file && is_readable($attached_file)) {
                $attachments[] = $attached_file;
            }
            $pdf_url = wp_get_attachment_url($pdf_attachment_id);
            if (is_string($pdf_url) && $pdf_url !== '') {
                $pdf_link = $pdf_url;
            }
        }

        $landing_link = esc_url_raw((string) ($settings['landing_url'] ?? ''));
        $whatsapp_token = Estandar_Leads_Tokens::sign_link(0, 0, 'whatsapp', rest_url('estandar-leads/v1/whatsapp-redirect'), 30, $project_slug);
        $whatsapp_link = add_query_arg(array(
            'project_slug' => $project_slug,
            'source' => 'email_autoresponse',
            'lead_id' => 0,
            'submission_id' => 0,
            'tracking_token' => $whatsapp_token,
        ), rest_url('estandar-leads/v1/whatsapp-redirect'));

        $html = Estandar_Leads_Email::render_autoresponse(array(
            'project_slug' => $project_slug,
            'project_name' => $project_name,
            'lead_name' => 'Juan Pérez',
            'operator_name' => get_bloginfo('name'),
            'operator_email' => '',
            'subject' => $subject,
            'body' => $body,
            'pdf_link' => $pdf_link,
            'landing_link' => $landing_link,
            'whatsapp_link' => $whatsapp_link,
            'pixel_url' => '',
        ), $settings);

        return array(
            'subject' => $subject,
            'body' => $body,
            'html' => $html,
            'settings' => $brand_settings,
            'attachments' => $attachments,
        );
    }

    private static function log_failed($lead_id, $submission_id, $project_slug, $operator_id, $reason, array $extra_metadata = array())
    {
        Estandar_Leads_Repository::insert_event($lead_id, self::EVENT_FAILED, array(
            'submission_id' => $submission_id,
            'operator_id' => $operator_id ?: null,
            'to_status' => $project_slug,
            'reason' => $reason,
            'metadata' => array_merge(array('reason' => $reason), $extra_metadata),
        ));
    }

    /** Skip intencional (sin intento de envío) — mismo shape que log_failed. */
    private static function log_skipped($lead_id, $submission_id, $project_slug, $operator_id, $reason, array $extra_metadata = array())
    {
        Estandar_Leads_Repository::insert_event($lead_id, self::EVENT_SKIPPED, array(
            'submission_id' => $submission_id,
            'operator_id' => $operator_id ?: null,
            'to_status' => $project_slug,
            'reason' => $reason,
            'metadata' => array_merge(array('reason' => $reason), $extra_metadata),
        ));
    }

    /**
     * Same criteria as Estandar_Leads_Ingestion::suspect_reasons() (private,
     * not reusable as-is) — honeypot / too_fast / missing_user_agent.
     */
    private static function suspect_reasons($spam, $request)
    {
        $reasons = array();
        if (!empty($spam['honeypot_filled'])) {
            $reasons[] = 'honeypot';
        }
        if (isset($spam['seconds_to_submit']) && $spam['seconds_to_submit'] !== null && (float) $spam['seconds_to_submit'] < 3) {
            $reasons[] = 'too_fast';
        }
        if (empty($request['user_agent'])) {
            $reasons[] = 'missing_user_agent';
        }
        return $reasons;
    }

    private static function project_name($project_slug, $project_area)
    {
        if (function_exists('resolve_project_display_name')) {
            $name = resolve_project_display_name($project_slug, $project_area);
            if ($name !== '') {
                return $name;
            }
        }

        foreach (Estandar_Leads_Projects::registry() as $project) {
            if (($project['project_slug'] ?? '') === $project_slug) {
                return (string) ($project['name'] ?? '');
            }
        }

        return $project_area !== '' ? $project_area : $project_slug;
    }

    private static function email_domain($email)
    {
        $email = (string) $email;
        $at = strrpos($email, '@');
        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }

    private static function go_url($token)
    {
        return rest_url('estandar-leads/v1/go/' . $token);
    }

    /**
     * AUTORESPONSE_EMAIL_FALLBACK_V1 (2026-06-23) — Resolve email from lead record
     * when submission.email is empty. Fallback chain: submission.email → lead.email.
     * Safe: read-only query, returns '' if lead not found or email not available.
     */
    private static function resolve_fallback_email($lead_id)
    {
        global $wpdb;

        $lead_id = absint($lead_id);
        if ($lead_id === 0) {
            return '';
        }

        $email = $wpdb->get_var($wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            $lead_id
        ));

        if (!$email || !is_email($email)) {
            return '';
        }

        return Estandar_Leads_Normalizer::email($email);
    }

    /**
     * AUTORESPONSE_EMAIL_FALLBACK_V1 (2026-06-23) — Record which source was used
     * for email resolution. Telemetry events:
     * - AUTORESPONSE_EMAIL_SOURCE_SUBMISSION (contact.email used)
     * - AUTORESPONSE_EMAIL_SOURCE_LEAD (lead.email fallback used)
     */
    private static function telemetry_email_source($lead_id, $submission_id, $email_source, array $base_metadata = array())
    {
        $lead_id = absint($lead_id);
        $submission_id = absint($submission_id);
        $email_source = sanitize_key($email_source);

        if ($lead_id === 0 || $submission_id === 0 || $email_source === '') {
            return;
        }

        $event_type = $email_source === 'submission'
            ? 'AUTORESPONSE_EMAIL_SOURCE_SUBMISSION'
            : 'AUTORESPONSE_EMAIL_SOURCE_LEAD';

        do_action('estandar_telemetry_event', array(
            'severity' => 'info',
            'code' => $event_type,
            'message' => 'Autoresponse resolved email from ' . $email_source . '.',
            'context' => array(
                'lead_id' => $lead_id,
                'submission_id' => $submission_id,
                'email_source' => $email_source,
            ) + $base_metadata,
        ));
    }
}
