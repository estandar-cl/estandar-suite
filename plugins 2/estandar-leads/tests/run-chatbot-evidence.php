<?php
/**
 * CHATBOT_SUBMISSION_EVIDENCE_V1 (Lead #478) — suite live-WP.
 * Run: php tests/run-chatbot-evidence.php   (requiere WP vivo: SALADEPRUEBAS)
 *
 * A. Chatbot phone-only: form_fields_json con nombre/telefono/intent/
 *    requirement, SIN clave email; ingesta y captura por teléfono operativas.
 * B. Dedupe/payload hash intactos: re-ingesta del mismo payload NO crea
 *    segunda submission (la evidencia derivada no entra al hash).
 * C. Chatbot con email: form_fields_json incluye email.
 * D. Autoresponse phone-only: autoresponse_skipped/no_email, sin
 *    autoresponse_email_failed. Routing/notification no se tocan.
 * E. Fallo genuino de transporte (pre_wp_mail=false): sigue siendo
 *    autoresponse_email_failed reason=send_error.
 * F. Forms intacto: meta.sanitized_raw_fields se persiste tal cual, sin
 *    claves de evidencia inyectadas.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    require_once dirname(__DIR__, 4) . '/wp-load.php';
}

global $wpdb;

$failures = array();
$check = static function ($label, $ok) use (&$failures) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) { $failures[] = $label; }
};

// Aislar side-effects: sin notifier/autoresponse/etc. durante la ingesta;
// autoresponse se invoca explícitamente en los casos D/E.
remove_all_actions('estandar_lead_ingested');

$suffix = (string) random_int(100000, 999999);
$created_lead_ids = array();
$settings_backup = get_option('estandar_leads_autoresponse_settings', null);

// D/E ejercitan Autoresponse::maybe_send(), que resuelve project_slug contra
// el Project Registry real (PROJECT_REGISTRY_ENFORCEMENT_V1, RULE_04): un
// slug inventado que no matchea ningún proyecto registrado resuelve a ''
// intencionalmente, no a sí mismo. Usar un proyecto real del registry (mismo
// patrón que run-autoresponse-settings.php) para que D/E ejerciten el gate de
// email real en vez del gate de missing_project.
$registry_project = reset(Estandar_Leads_Projects::registry());
if (!is_array($registry_project) || empty($registry_project['project_slug'])) {
    fwrite(STDERR, "No registry project available\n");
    exit(1);
}
$evidence_project_slug = sanitize_key((string) $registry_project['project_slug']);
$evidence_project_area = (string) ($registry_project['name'] ?? $evidence_project_slug);
$evidence_project_uuid = sanitize_text_field((string) ($registry_project['project_uuid'] ?? ''));
$evidence_settings_key = $evidence_project_uuid !== '' ? $evidence_project_uuid : $evidence_project_slug;

$base_payload = static function (array $overrides) use ($suffix, $evidence_project_slug, $evidence_project_area): array {
    $contact = array(
        'name'  => 'Prueba Evidencia',
        'email' => '',
        'phone' => '+5691' . $suffix,
    );
    if (isset($overrides['contact'])) {
        $contact = array_merge($contact, $overrides['contact']);
    }
    return array(
        'schema_version' => '1.0',
        'source_plugin'  => $overrides['source_plugin'] ?? 'estandar-chatbot',
        'submitted_at'   => gmdate('c'),
        'contact'        => $contact,
        'content'        => array(
            'message'        => $overrides['message'] ?? 'Necesito informacion',
            'source_form_id' => $overrides['source_form_id'] ?? 'chatbot',
        ),
        'context'        => array(
            'project_slug' => $evidence_project_slug,
            'project_area' => $evidence_project_area,
            'intent'       => $overrides['intent'] ?? 'lead_capture',
            'requirement'  => $overrides['requirement'] ?? 'Requerimiento de prueba',
        ),
        'attribution'    => array('landing_url' => 'https://example.test/evidencia/'),
        'request'        => array('ip_address' => '127.0.0.1', 'user_agent' => 'evidence-test'),
        'spam'           => array('honeypot_filled' => false, 'seconds_to_submit' => 30),
        'meta'           => $overrides['meta'] ?? array(),
    );
};

$fetch_submission = static function (int $lead_id) use ($wpdb) {
    return $wpdb->get_results($wpdb->prepare(
        "SELECT id, form_fields_json, payload_hash FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d ORDER BY id ASC",
        $lead_id
    ));
};

try {
    // ── A · Chatbot phone-only ───────────────────────────────────────────
    $payload_a = $base_payload(array());
    do_action('estandar_lead_captured', $payload_a);
    $ingested = Estandar_Leads_Ingestion::last_ingested();
    $lead_a = (int) ($ingested['lead_id'] ?? 0);
    if ($lead_a) { $created_lead_ids[] = $lead_a; }

    $subs = $fetch_submission($lead_a);
    $submission_a = !empty($subs) ? (int) $subs[0]->id : 0;
    $check('A: lead phone-only ingresado', $lead_a > 0 && $submission_a > 0);
    $fields = !empty($subs) ? json_decode((string) $subs[0]->form_fields_json, true) : null;
    $fields = is_array($fields) ? $fields : array();
    $check('A: form_fields_json ya no es []', !empty($fields));
    $check('A: nombre presente', ($fields['nombre'] ?? '') === 'Prueba Evidencia');
    $check('A: telefono presente', ($fields['telefono'] ?? '') !== '');
    $check('A: intent presente', ($fields['intent'] ?? '') === 'lead_capture');
    $check('A: requirement presente', ($fields['requirement'] ?? '') === 'Requerimiento de prueba');
    $check('A: sin clave email (no requerido)', !array_key_exists('email', $fields));

    $lead_row = $wpdb->get_row($wpdb->prepare(
        "SELECT email, phone_hash, project_slug FROM {$wpdb->prefix}es_leads WHERE id = %d",
        $lead_a
    ));
    $check('A: identidad por telefono (email vacio, phone_hash presente)',
        $lead_row && (string) $lead_row->email === '' && (string) $lead_row->phone_hash !== '');

    // ── B · Dedupe / payload hash intactos ──────────────────────────────
    $hash_stored = !empty($subs) ? (string) $subs[0]->payload_hash : '';
    $expected_payload = $payload_a;
    $expected_payload['attribution'] = Estandar_Leads_Attribution::merge_analytics($expected_payload['attribution']);
    $check('B: payload_hash = hash del payload (sin evidencia derivada)',
        $hash_stored !== '' && $hash_stored === Estandar_Leads_Normalizer::payload_hash($expected_payload));

    do_action('estandar_lead_captured', $payload_a);
    $re = Estandar_Leads_Ingestion::last_ingested();
    $subs_after = $fetch_submission($lead_a);
    $check('B: re-ingesta consolida en el mismo lead', (int) ($re['lead_id'] ?? 0) === $lead_a);
    $check('B: dedupe intacto (sin segunda submission)', count($subs_after) === count($subs));

    // ── C · Chatbot con email ────────────────────────────────────────────
    $payload_c = $base_payload(array(
        'contact' => array('email' => 'evidencia+' . $suffix . '@example.test', 'phone' => '+5692' . $suffix),
        'message' => 'Mensaje con email',
    ));
    do_action('estandar_lead_captured', $payload_c);
    $ingested_c = Estandar_Leads_Ingestion::last_ingested();
    $lead_c = (int) ($ingested_c['lead_id'] ?? 0);
    if ($lead_c) { $created_lead_ids[] = $lead_c; }
    $subs_c = $fetch_submission($lead_c);
    $fields_c = !empty($subs_c) ? json_decode((string) $subs_c[0]->form_fields_json, true) : array();
    $fields_c = is_array($fields_c) ? $fields_c : array();
    $check('C: email presente cuando existe', ($fields_c['email'] ?? '') === 'evidencia+' . $suffix . '@example.test');
    $check('C: nombre/telefono tambien presentes', ($fields_c['nombre'] ?? '') !== '' && ($fields_c['telefono'] ?? '') !== '');

    // ── D · Autoresponse phone-only = SKIPPED/no_email ───────────────────
    update_option('estandar_leads_autoresponse_settings', array(
        $evidence_settings_key => array(
            'enabled' => true,
            'subject' => 'Test',
            'body' => 'Test',
            'throttle_hours' => 0,
            'project_slug' => $evidence_project_slug,
            'project_uuid' => $evidence_project_uuid,
        ),
    ), false);

    Estandar_Leads_Autoresponse::on_lead_ingested($lead_a, $submission_a, true, $payload_a);
    $skipped = $wpdb->get_row($wpdb->prepare(
        "SELECT reason, metadata FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d AND event_type = 'autoresponse_skipped' ORDER BY id DESC LIMIT 1",
        $lead_a
    ));
    $failed_a = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d AND event_type = 'autoresponse_email_failed'",
        $lead_a
    ));
    $check('D: autoresponse_skipped emitido', $skipped !== null);
    $check('D: reason = no_email', $skipped && strpos((string) $skipped->metadata, 'no_email') !== false);
    $check('D: sin autoresponse_email_failed', (int) $failed_a === 0);

    // ── E · Fallo genuino de transporte sigue siendo FAILED ─────────────
    $submission_c = !empty($subs_c) ? (int) $subs_c[0]->id : 0;
    add_filter('pre_wp_mail', '__return_false');
    Estandar_Leads_Autoresponse::on_lead_ingested($lead_c, $submission_c, true, $payload_c);
    remove_filter('pre_wp_mail', '__return_false');
    $failed_c = $wpdb->get_row($wpdb->prepare(
        "SELECT reason, metadata FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d AND event_type = 'autoresponse_email_failed' ORDER BY id DESC LIMIT 1",
        $lead_c
    ));
    $check('E: fallo real de envio = autoresponse_email_failed/send_error',
        $failed_c && strpos((string) $failed_c->metadata, 'send_error') !== false);

    // ── F · Forms intacto ────────────────────────────────────────────────
    $payload_f = $base_payload(array(
        'source_plugin'  => 'estandar-forms',
        'source_form_id' => 'form-evidence',
        'contact'        => array('email' => 'forms+' . $suffix . '@example.test', 'phone' => '+5693' . $suffix),
        'meta'           => array('sanitized_raw_fields' => array(
            'your-name'  => 'Forms User',
            'custom_ref' => 'REF-' . $suffix,
        )),
    ));
    do_action('estandar_lead_captured', $payload_f);
    $ingested_f = Estandar_Leads_Ingestion::last_ingested();
    $lead_f = (int) ($ingested_f['lead_id'] ?? 0);
    if ($lead_f) { $created_lead_ids[] = $lead_f; }
    $subs_f = $fetch_submission($lead_f);
    $fields_f = !empty($subs_f) ? json_decode((string) $subs_f[0]->form_fields_json, true) : array();
    $fields_f = is_array($fields_f) ? $fields_f : array();
    $check('F: forms conserva sus campos exactos',
        array_keys($fields_f) === array('your-name', 'custom_ref')
        && $fields_f['your-name'] === 'Forms User');
    $check('F: forms sin claves de evidencia inyectadas',
        !isset($fields_f['nombre']) && !isset($fields_f['telefono']) && !isset($fields_f['intent']));
} finally {
    // Cleanup: leads/submissions/eventos de prueba + settings.
    $created_lead_ids = array_values(array_unique(array_filter($created_lead_ids)));
    if (!empty($created_lead_ids)) {
        $in = implode(',', array_map('absint', $created_lead_ids));
        $wpdb->query("DELETE FROM {$wpdb->prefix}es_lead_events WHERE lead_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}es_leads WHERE id IN ({$in})");
    }
    if ($settings_backup === null) {
        delete_option('estandar_leads_autoresponse_settings');
    } else {
        update_option('estandar_leads_autoresponse_settings', $settings_backup, false);
    }
}

echo empty($failures) ? "ALL PASS\n" : count($failures) . " FAILED\n";
exit(empty($failures) ? 0 : 1);
