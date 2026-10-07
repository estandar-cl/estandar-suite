<?php
/**
 * Journey builder — EMAIL_JOURNEY_V1 (refactor presentacional).
 *
 * Construye el "Journey del Prospecto" como estructura de datos, fuera del
 * template. Misma lógica que la versión inline del template: hitos reales en
 * orden fijo de funnel (nunca ordenados por fecha), estado actual = primer
 * paso pendiente.
 *
 * Cada paso: array( 'label' => string, 'status' => 'done'|'current'|'future' ).
 *
 * @package Estandar_Leads
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_Journey_Builder
{
    /**
     * Build the prospect journey.
     *
     * @param int   $lead_id Lead id; used to read es_lead_events only when the
     *                       caller did not already provide `journey` flags.
     * @param array $data    Email data array ($es_d). Signals read — identical
     *                       to the previous inline template logic: journey,
     *                       operator_name, repeated_lead, and the legacy keys
     *                       autoresponse_email_opened / autoresponse_pdf_clicked /
     *                       pdf_clicked / whatsapp_clicked.
     *
     * @return array[] Steps in fixed funnel order.
     */
    public static function build($lead_id, array $data = array())
    {
        $flags = is_array($data['journey'] ?? null)
            ? $data['journey']
            : self::event_flags($lead_id);

        $plugin_key = sanitize_key( (string) ( $data['source_plugin'] ?? '' ) );
        if ( strpos( $plugin_key, 'chatbot' ) !== false ) {
            $capture_label = 'Chatbot';
        } elseif ( strpos( $plugin_key, 'forms' ) !== false ) {
            $capture_label = 'Formulario recibido';
        } elseif ( strpos( $plugin_key, 'whatsapp' ) !== false || strpos( $plugin_key, '-wa-' ) !== false ) {
            $capture_label = 'WhatsApp';
        } else {
            $capture_label = 'Lead recibido';
        }

        $steps = array(
            array('label' => $capture_label, 'status' => 'done'),
        );

        $operator_name = trim((string) ($data['operator_name'] ?? ''));
        if ($operator_name !== '') {
            $steps[] = array('label' => 'Operador asignado (' . $operator_name . ')', 'status' => 'done');
        } else {
            $steps[] = array('label' => 'Operador asignado', 'status' => 'done');
        }
        if (! empty($flags['autoresponse_sent'])) {
            $steps[] = array('label' => 'Email automático enviado', 'status' => 'done');
        }
        $is_commercial_recontact = ! empty($data['is_commercial_recontact']);
        $commercial_recontact_count = (int) ($data['commercial_recontact_count'] ?? 0);
        if ($is_commercial_recontact && $commercial_recontact_count > 0) {
            $steps[] = array('label' => '✓ Recontacto #' . $commercial_recontact_count, 'status' => 'done');
        } elseif (! empty($data['repeated_lead'])) {
            $steps[] = array('label' => 'Prospecto volvió a contactar', 'status' => 'done');
        }
        if (! empty($flags['email_opened']) || ! empty($data['autoresponse_email_opened'])) {
            $steps[] = array('label' => 'Email abierto', 'status' => 'done');
        }
        if (! empty($flags['link_clicked']) || ! empty($data['autoresponse_pdf_clicked']) || ! empty($data['pdf_clicked'])) {
            $steps[] = array('label' => 'Enlace del email abierto', 'status' => 'done');
        }
        if (! empty($flags['whatsapp_opened']) || ! empty($data['whatsapp_clicked'])) {
            $steps[] = array('label' => 'WhatsApp abierto', 'status' => 'done');
        }

        if (! empty($flags['contact_registered'])) {
            $steps[] = array('label' => 'Contacto comercial registrado', 'status' => 'done');
            $steps[] = array('label' => 'Clasificación pendiente', 'status' => 'current');
            $steps[] = array('label' => 'Resultado comercial', 'status' => 'future');
        } else {
            $steps[] = array('label' => 'Esperando contacto comercial', 'status' => 'current');
            $steps[] = array('label' => 'Clasificación pendiente', 'status' => 'future');
            $steps[] = array('label' => 'Resultado comercial', 'status' => 'future');
        }

        return $steps;
    }

    /**
     * Derive journey flags from es_lead_events — same read-only query and
     * event names used by the notifier (EMAIL_JOURNEY_V1).
     *
     * OPEN_EVIDENCE_V1: email_opened only triggers when there is at least one
     * classified open with human evidence (probable_human_open or
     * confirmed_human_engagement). A technical_fetch or probable_machine_open
     * never shows as "Email abierto" in operator templates.
     *
     * @param int $lead_id Lead id.
     * @return array
     */
    public static function event_flags($lead_id)
    {
        $types = array();

        if (absint($lead_id) > 0) {
            global $wpdb;
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT event_type FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d",
                absint($lead_id)
            ));
            $types = is_array($rows) ? $rows : array();
        }

        $has = static function ($type) use ($types) {
            return in_array($type, $types, true);
        };

        $email_opened = false;
        if ($has('autoresponse_open_classified')) {
            global $wpdb;
            $human_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
                 WHERE lead_id = %d AND event_type = 'autoresponse_open_classified'
                 AND (metadata LIKE %s OR metadata LIKE %s)",
                absint($lead_id),
                '%"open_classification":"probable_human_open"%',
                '%"open_classification":"confirmed_human_engagement"%'
            ));
            $email_opened = $human_count > 0;
        }

        return array(
            'autoresponse_sent'  => $has('autoresponse_email_sent'),
            'email_opened'       => $email_opened,
            'link_clicked'       => $has('autoresponse_link_click') || $has('autoresponse_engaged'),
            'whatsapp_opened'    => $has('whatsapp_clicked'),
            'contact_registered' => $has('contact_logged') || $has('call_clicked') || $has('email_clicked'),
        );
    }
}
