<?php
/**
 * IEE-3/IEE-4 — Listener de Interest Evolution Events.
 *
 * Convierte eventos dispersos del lead (es_lead_events) en señales de
 * evolución de interés, registradas de forma idempotente en
 * es_interest_events (InterestEventLog). NO modifica el lead, NO decide.
 *
 * Mapeo de traducción (eventos ya existentes → señales canónicas):
 *   - autoresponse_open_classified (humano) → email_opened
 *   - autoresponse_link_click / autoresponse_engaged → email_click
 *   - whatsapp_clicked → whatsapp_opened
 *   - chatbot_conversation_started → chatbot_start
 *   - quote_created / quote_generated → quote_created
 *   - lead_recontacted → return_visit_after_capture (evolución)
 *   - lead_created con submission > 1 → second_form / third_form
 *   - sesión nueva (metadata.session_id) → new_session
 *
 * AUSENCIA = DEFAULT: si la tabla no existe o el evento no mapea, no pasa
 * nada. Nunca se rompe el flujo existente.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Interest_Listener
{
    const VERSION = 'IEE_V1';

    /** Mapa event_type → señal canónica. */
    const EVENT_SIGNAL_MAP = array(
        'autoresponse_email_sent'       => 'email_sent',
        'autoresponse_open_classified'  => 'email_opened',
        'autoresponse_link_click'       => 'email_click',
        'autoresponse_engaged'          => 'email_click',
        'whatsapp_clicked'              => 'whatsapp_opened',
        'chatbot_conversation_started'  => 'chatbot_start',
        'quote_created'                 => 'quote_created',
        'quote_generated'               => 'quote_created',
        'lead_recontacted'              => 'return_visit_after_capture',
        'return_visit_detected'         => 'return_visit_after_capture',
        'contact_logged'                => 'contact_logged', // señal de gestión, no de interés
        'call_clicked'                  => 'call_clicked',
    );

    public static function init()
    {
        add_action('estandar_lead_event_recorded', array(__CLASS__, 'on_event_recorded'), 40, 4);
    }

    /**
     * Traduce un evento de lead a una Interest Evolution Event (idempotente).
     *
     * @param int    $event_id
     * @param int    $lead_id
     * @param string $event_type
     * @param array  $metadata
     */
    public static function on_event_recorded($event_id, $lead_id, $event_type, $metadata = array())
    {
        if (!class_exists('Estandar\\Core\\Interest\\InterestEventLog')) {
            return;
        }
        $event_id = absint($event_id);
        $lead_id = absint($lead_id);
        $event_type = sanitize_key((string) $event_type);
        $metadata = is_array($metadata) ? $metadata : array();
        if ($lead_id < 1) {
            return;
        }

        // Señal por tipo de evento.
        $signal_id = self::EVENT_SIGNAL_MAP[$event_type] ?? '';
        if ($signal_id === '' || in_array($signal_id, array('contact_logged', 'call_clicked'), true)) {
            return; // Gestión ≠ interés; no se registra como evolución.
        }

        // Open de email: solo humano cuenta (misma regla OPEN_EVIDENCE_V1).
        if ($event_type === 'autoresponse_open_classified') {
            $classification = (string) ($metadata['open_classification'] ?? '');
            if (!in_array($classification, array('probable_human_open', 'confirmed_human_engagement'), true)) {
                return;
            }
        }

        // Resolver la señal de formularios según el conteo de ingresos.
        $submission_count = self::submission_count($lead_id);
        if ($event_type === 'lead_created' && $submission_count > 1) {
            $signal_id = $submission_count >= 3 ? 'third_form' : 'second_form';
        }

        // Nueva sesión: si el evento trae session_id distinto al del primer ingreso.
        if ($event_type === 'new_session_detected') {
            $signal_id = 'new_session';
        }

        $scope = '';
        if (!empty($metadata['submission_id'])) {
            $scope = 'submission:' . absint($metadata['submission_id']);
        } elseif (!empty($metadata['session_id'])) {
            $scope = 'session:' . sanitize_key((string) $metadata['session_id']);
        }

        $context = array(
            'occurred_at' => (string) ($metadata['created_at'] ?? gmdate('Y-m-d H:i:s')),
            'submission_id' => isset($metadata['submission_id']) ? absint($metadata['submission_id']) : 0,
            'session_id' => isset($metadata['session_id']) ? sanitize_text_field((string) $metadata['session_id']) : '',
            'source_plugin' => sanitize_key((string) ($metadata['source_plugin'] ?? '')),
            'idempotency_scope' => $scope,
            'weight' => Estandar\Core\Interest\InterestSignals::weight($signal_id),
        );

        Estandar\Core\Interest\InterestEventLog::record('lead', $lead_id, $signal_id, $context);
    }

    /** Conteo de ingresos del lead (lectura barata, cacheada por request). */
    private static function submission_count($lead_id)
    {
        static $cache = array();
        $lead_id = absint($lead_id);
        if (isset($cache[$lead_id])) {
            return $cache[$lead_id];
        }
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d",
            $lead_id
        ));
        $cache[$lead_id] = $count;
        return $count;
    }
}
