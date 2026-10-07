<?php
/**
 * CORE_OPTIONALITY runtime matrix (CORE_OPTIONALITY_REMEDIATION_PLAN_V1, Fase 3).
 *
 * Uso (desde cualquier directorio, con WP local vivo):
 *   Escenario A (core presente):
 *     php wp-content/plugins/estandar-leads/tests/run-core-optionality-matrix.php
 *   Escenario B (core ausente):
 *     mv wp-content/plugins/estandar-primer wp-content/plugins/estandar-primer.off
 *     php wp-content/plugins/estandar-leads/tests/run-core-optionality-matrix.php
 *     mv wp-content/plugins/estandar-primer.off wp-content/plugins/estandar-primer
 *
 * Resultado esperado en AMBOS escenarios: todos los probes PASS (NO_FATAL / DEGRADE_GRACEFULLY).
 * El script no envía emails (bloquea wp_mail) y limpia el lead de prueba que crea.
 */

require_once dirname(__DIR__, 4) . '/wp-load.php';

$scenario = (function_exists('estandar_core_render_admin_shell')) ? 'A (core presente)' : 'B (core ausente)';
fwrite(STDOUT, "== CORE_OPTIONALITY MATRIX — escenario {$scenario} ==\n");

$results = array();
$probe = function (string $name, callable $fn) use (&$results) {
    try {
        $detail = $fn();
        $results[] = array($name, true, is_string($detail) ? $detail : 'OK');
    } catch (Throwable $e) {
        $results[] = array($name, false, get_class($e) . ': ' . $e->getMessage());
    }
};

// Bloquear todo envío real de email durante la corrida.
add_filter('pre_wp_mail', '__return_false', 10, 0);

// ── 1. Polyfill presente (Fase 1) ────────────────────────────────────────────
$probe('polyfill primer_is_active', function () {
    if (!function_exists('primer_is_active')) {
        throw new RuntimeException('No definida — polyfill ausente.');
    }
    return 'is_active=' . var_export(primer_is_active(), true);
});

// ── 1b. Coherencia de detección (CORE_OPTIONALITY_V1.1, caso orden de carga) ─
// Regresión 2026-06-10: con Primer como plugin normal, los consumidores
// alfabéticamente anteriores (analytics, chatbot) definían el polyfill estático
// `return false` antes de que core cargara → is_active() mentía con core vivo.
// Aserción: is_active() DEBE coincidir con la evidencia independiente de que
// core cargó (su clase de registry). En escenario A ambos true; en B ambos false.
// WP carga active_plugins en orden alfabético, por lo que esta corrida ya
// reproduce el orden real de carga — no requiere simulación adicional.
$probe('is_active coherente con core cargado (load order)', function () {
    $core_loaded = class_exists('Estandar_Core_Project_Registry');
    $is_active   = primer_is_active();
    if ($is_active !== $core_loaded) {
        throw new RuntimeException(sprintf(
            'Incoherencia: is_active()=%s pero core_loaded=%s — polyfill estático ganó por orden de carga.',
            var_export($is_active, true),
            var_export($core_loaded, true)
        ));
    }
    return sprintf('is_active=%s == core_loaded=%s', var_export($is_active, true), var_export($core_loaded, true));
});

// ── 2. ADMIN: construcción de menús (la superficie del incidente) ────────────
$probe('admin_menu leads/forms/analytics/chatbot', function () {
    if (!function_exists('add_menu_page')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    global $menu, $submenu;
    $menu = $menu ?? array();
    $submenu = $submenu ?? array();
    if (!class_exists('Estandar_Leads_Admin')) {
        throw new RuntimeException('Estandar_Leads_Admin no cargada.');
    }
    // Requiere usuario con manage_options para que add_*_page registre.
    wp_set_current_user(1);
    Estandar_Leads_Admin::menu();
    return 'menu() OK';
});

// ── 3. ADMIN: render_tabs rama standalone (guard Fase 2.1) ───────────────────
$probe('Estandar_Leads_Admin::render_tabs', function () {
    ob_start();
    Estandar_Leads_Admin::render_tabs('estandar-leads');
    $html = ob_get_clean();
    return strlen((string) $html) . ' bytes';
});

$probe('estandar_forms_render_admin_tabs', function () {
    if (!function_exists('estandar_forms_render_admin_tabs')) {
        return 'SKIP (forms admin no cargado en este contexto)';
    }
    ob_start();
    estandar_forms_render_admin_tabs('estandar-forms');
    $html = ob_get_clean();
    return strlen((string) $html) . ' bytes';
});

// ── 4. EMAIL: settings + filtros de sender (fatal silencioso del incidente) ──
$probe('Estandar_Leads_Email::get_settings', function () {
    $s = Estandar_Leads_Email::get_settings();
    if (!is_array($s)) {
        throw new RuntimeException('No devolvió array.');
    }
    return 'sender=' . ($s['sender_email'] ?? '?');
});

$probe('filtros wp_mail_from / from_name', function () {
    $from = apply_filters('wp_mail_from', 'probe@estandar.cl');
    $name = apply_filters('wp_mail_from_name', 'Probe');
    return $from . ' / ' . $name;
});

// ── 5. EMAIL: render del template de notificación ─────────────────────────────
$probe('Estandar_Leads_Email::render (notificación)', function () {
    $html = Estandar_Leads_Email::render(array(
        'operator_name' => 'Matrix Probe',
        'lead_name'     => 'Lead De Prueba',
        'lead_email'    => 'matrix-probe@estandar.cl',
        'lead_phone'    => '+56 9 1234 5678',
        'project_area'  => 'SALADEPRUEBAS',
        'project_slug'  => 'saladepruebas',
        'feedback_url'  => home_url('/?estandar_feedback=1&token=probe'),
        'lead_id'       => 0,
        'submission_id' => 0,
        'form_fields'   => array('mensaje' => 'probe'),
        'message'       => 'probe',
    ), Estandar_Leads_Email::get_settings());
    if (!is_string($html) || strlen($html) < 500) {
        throw new RuntimeException('Render vacío o truncado.');
    }
    return strlen($html) . ' bytes';
});

// ── 6. CAPTURA: ingestión end-to-end (lead se crea, notifier corre sin fatal) ─
$probe('captura estandar_lead_captured → ingestión + notifier', function () {
    global $wpdb;
    $email = 'core-matrix-' . time() . '@estandar.cl';
    $payload = array(
        'schema_version' => '1.0',
        'source_plugin'  => 'estandar-forms',
        'submitted_at'   => gmdate('c'),
        'contact'        => array('name' => 'Matrix Probe', 'email' => $email, 'phone' => '+56912345678'),
        'content'        => array('message' => 'CORE_OPTIONALITY matrix probe', 'source_form_id' => 'matrix'),
        'attribution'    => array(),
        'request'        => array('ip_address' => '127.0.0.1', 'user_agent' => 'matrix-probe'),
        'context'        => array('project_area' => 'SALADEPRUEBAS', 'project_slug' => 'saladepruebas'),
    );
    $lead_id = 0;
    if (class_exists('Estandar_Leads_Ingestion')) {
        $lead_id = (int) Estandar_Leads_Ingestion::capture($payload);
    } else {
        do_action('estandar_lead_captured', $payload);
        $lead_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}es_leads WHERE email = %s", $email));
    }
    if ($lead_id <= 0) {
        throw new RuntimeException('Lead no persistido (¿payload rechazado?).');
    }
    // Cleanup del lead de prueba.
    $wpdb->delete($wpdb->prefix . 'es_lead_events', array('lead_id' => $lead_id));
    $wpdb->delete($wpdb->prefix . 'es_lead_submissions', array('lead_id' => $lead_id));
    $wpdb->delete($wpdb->prefix . 'es_feedback_tokens', array('lead_id' => $lead_id));
    $wpdb->delete($wpdb->prefix . 'es_leads', array('id' => $lead_id));
    return "lead #{$lead_id} creado y limpiado";
});

// ── 7. CRON: compliance engine ────────────────────────────────────────────────
$probe('cron compliance (run completo)', function () {
    if (!class_exists('Estandar_Leads_Compliance')) {
        throw new RuntimeException('Compliance no cargada.');
    }
    do_action(Estandar_Leads_Compliance::CRON_HOOK);
    return 'hook ejecutado sin fatal';
});

// ── Reporte ───────────────────────────────────────────────────────────────────
$fail = 0;
foreach ($results as $r) {
    list($name, $ok, $detail) = $r;
    fwrite(STDOUT, sprintf("%-55s %s  %s\n", $name, $ok ? 'PASS' : 'FAIL', $detail));
    if (!$ok) {
        $fail++;
    }
}
fwrite(STDOUT, $fail === 0
    ? "\nRESULTADO: NO_FATAL / DEGRADE_GRACEFULLY — escenario {$scenario} PASS\n"
    : "\nRESULTADO: {$fail} probe(s) FAIL — revisar antes de deploy\n");
exit($fail === 0 ? 0 : 1);
