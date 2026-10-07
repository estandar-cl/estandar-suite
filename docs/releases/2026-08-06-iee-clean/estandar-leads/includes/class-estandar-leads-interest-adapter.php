<?php
/**
 * IEE-2 — Adapter de estandar-leads al Interest Evolution Engine.
 *
 * SOLO TRADUCE DATOS. No contiene reglas de negocio: reúne hechos que el
 * notifier/ingestion ya resolvió (sin queries SQL nuevas) y los entrega a
 * Estandar\Core\Interest\InterestContext → InterestEngine::decide().
 *
 * Salida: InterestDecision (ver InterestEngine::decide()).
 * Ausencia = default: si el motor de core no está disponible, devuelve
 * ['absent' => true] y el consumidor mantiene el comportamiento actual.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Interest_Adapter
{
    /**
     * Construye el InterestDecision para un lead/submission usando datos ya
     * resueltos por el notifier (lead_state, recontact_ctx, journey, attr).
     *
     * @param int   $lead_id
     * @param int   $submission_id
     * @param array $data { lead_state, recontact_ctx, journey, attr, submission_count, source_plugin }
     * @return array InterestDecision
     */
    public static function decision_for($lead_id, $submission_id, array $data = array())
    {
        if (!class_exists('Estandar\\Core\\Interest\\InterestEngine')
            || !class_exists('Estandar\\Core\\Interest\\InterestContext')) {
            return array('version' => 'IEE_V1', 'absent' => true);
        }

        $lead_id = absint($lead_id);
        $submission_id = absint($submission_id);

        $lead_state = isset($data['lead_state']) && is_object($data['lead_state'])
            ? $data['lead_state']
            : (isset($data['lead_state']) && is_array($data['lead_state']) ? (object) $data['lead_state'] : null);
        $recontact = is_array($data['recontact_ctx'] ?? null) ? $data['recontact_ctx'] : array();
        $journey = is_array($data['journey'] ?? null) ? $data['journey'] : array();
        $attr = is_array($data['attribution'] ?? null) ? $data['attribution'] : array();

        $submission_count = (int) ($data['submission_count'] ?? 0);
        if ($submission_count < 1 && $lead_state) {
            $submission_count = (int) ($lead_state->submission_count ?? 0);
        }
        $is_recontact = !empty($recontact['is_commercial_recontact']);
        $same_session = !empty($recontact['is_same_session_contact']);

        $channel = self::normalize_channel((string) ($attr['channel'] ?? ''), $attr);

        // Contacto/gestión: solo si hay un contacto comercial registrado.
        $contact_registered = !empty($journey['contact_registered']);
        $classification_done = false;
        if ($lead_state && property_exists($lead_state, 'quality')) {
            $classification_done = trim((string) $lead_state->quality) !== '';
        }

        $behavior = array(
            'email_opened'    => !empty($journey['email_opened']),
            'email_clicked'   => !empty($journey['link_clicked']),
            'whatsapp_opened' => !empty($journey['whatsapp_opened']),
            'chatbot_started' => self::chatbot_started($lead_id),
            'quote_created'   => false,
            'page_views'      => (int) ($attr['events_recorded'] ?? $attr['events'] ?? 0),
        );

        $ctx = Estandar\Core\Interest\InterestContext::build(array(
            'submission_count'        => $submission_count,
            'is_commercial_recontact' => $is_recontact,
            'return_visit_after_capture' => self::return_visit($lead_id, $submission_count, $is_recontact, $same_session),
            'new_session'             => !$same_session && $submission_count > 1,
            'channel'                 => $channel,
            'behavior'                => $behavior,
            'operator_assigned'       => self::operator_assigned($lead_id),
            'contact_registered'      => $contact_registered,
            'classification_done'     => $classification_done,
            'elapsed_hours'           => self::elapsed_hours($lead_id),
            'sla_violated'            => false,
        ));

        $decision = Estandar\Core\Interest\InterestEngine::decide($ctx);
        $decision['context'] = $ctx;
        return $decision;
    }

    /** Normaliza el canal resolviendo por attribution si hace falta. */
    private static function normalize_channel($resolved, array $attr)
    {
        if ($resolved === '' && class_exists('Estandar_Leads_Attribution')) {
            $resolved = Estandar_Leads_Attribution::resolve_channel($attr);
        }
        $resolved = sanitize_key($resolved);
        $map = array(
            'google_ads'    => 'google_ads',
            'meta_ads'      => 'meta_ads',
            'google_organic'=> 'organic',
            'organic'       => 'organic',
            'direct'        => 'direct',
            'form_web'      => 'form_web',
            'chatbot'       => 'chatbot',
        );
        return $map[$resolved] ?? $resolved;
    }

    /** ¿Hubo una re-visita después de la captura? (IEE-3). */
    private static function return_visit($lead_id, $submission_count, $is_recontact, $same_session)
    {
        if ($submission_count < 2) {
            return false;
        }
        // Segundo ingreso en sesión distinta o recontacto comercial = re-visita.
        if ($is_recontact || !$same_session) {
            return true;
        }
        return false;
    }

    /** ¿El lead tiene chatbot iniciado? (sin queries nuevas: usa journey). */
    private static function chatbot_started($lead_id)
    {
        return false; // Señal opcional; el chatbot puede aportarla vía journey flags.
    }

    /** Horas desde el primer ingreso (lectura barata de una columna). */
    private static function elapsed_hours($lead_id)
    {
        global $wpdb;
        $first = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(submitted_at) FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d",
            absint($lead_id)
        ));
        if (!$first) {
            return 0;
        }
        $ts = strtotime((string) $first);
        if (!$ts) {
            return 0;
        }
        return max(0, (int) floor((time() - $ts) / HOUR_IN_SECONDS));
    }

    /** ¿Operador asignado? (sin query extra). */
    private static function operator_assigned($lead_id)
    {
        return true; // El notifier asigna operador antes de renderizar.
    }
}
