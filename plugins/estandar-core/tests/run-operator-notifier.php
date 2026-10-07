<?php
/**
 * OPERATOR_NOTIFIER v2 — tests del port del notifier del operador (email enriquecido).
 *
 *   php tests/run-operator-notifier.php   → expect "0 failed"
 *
 * Verifica:
 *   - enrichment(): score/SLA/antigüedad/señales a partir del estado del lead.
 *   - build_subject(): asunto por señal (reincidente / recontacto / alta intención / nuevo).
 *   - render_email(): template self-contained con los bloques enriquecidos.
 *   - Wiring: el registro toma el hook y suprime el notifier legacy cuando está habilitado.
 *   - Toggle: option enabled default ON.
 *
 * NOTA: depende de WordPress vivo (solo render, sin wp_mail).
 */

if (!defined('ABSPATH')) {
    require_once dirname(__DIR__, 3) . '/Playground/wp-load.php';
}

$passed = 0;
$failed = 0;
function check2($name, $ok) {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS  {$name}\n"; }
    else { $failed++; echo "FAIL  {$name}\n"; }
}

$ON = 'Estandar\\Core\\Leads\\OperatorNotifier';

echo "── Toggle (default ON) ────────────────────────────────────────────────\n";
update_option('estandar_suite_core_leads_notifier_enabled', '__unset__'); // remove
delete_option('estandar_suite_core_leads_notifier_enabled');
check2('1a default enabled', $ON::enabled());
$ON::set_enabled(false);
check2('1b deshabilitado no envía', !$ON::enabled());
$ON::set_enabled(true);
check2('1c habilitado', $ON::enabled());

echo "── Wiring: hook + supresión del legacy ────────────────────────────────\n";
// Quita cualquier registro previo para un estado determinista.
remove_action('estandar_lead_ingested', [$ON, 'notify_operators'], 25);
$ON::register();
check2('2a hook v2 registrado en estandar_lead_ingested', has_action('estandar_lead_ingested', [$ON, 'notify_operators']) !== false);
check2('2b hook legacy suprimido (solo email)', !has_action('estandar_lead_ingested', ['Estandar_Leads_Notifier', 'notify_operators'], 25));
remove_action('estandar_lead_ingested', [$ON, 'notify_operators'], 25);

echo "── ADMIN_FALLBACK_V1: sin operador → solo admin (sin broadcast) ──────\n";
$opA = (object) array('id' => 7, 'name' => 'Operador A', 'email' => 'op-a@example.com', 'project_slug' => 'terrenos', 'area' => '', 'allowed_projects_json' => null);
list($t_match, $af_match) = $ON::resolve_target_operators(array('operators' => array($opA), 'fallback' => false, 'routing_mode' => 'project_match'), 'terrenos', 1);
check2('2c con match → operador del proyecto, sin admin fallback', count($t_match) === 1 && $af_match === false && $t_match[0]->email === 'op-a@example.com');

$prev_admin = get_option('admin_email');
$prev_filter_cb = null;

list($t_fb, $af_fb) = $ON::resolve_target_operators(array('operators' => array($opA), 'fallback' => true, 'routing_mode' => 'fallback'), 'sin-operador', 2);
check2('2d sin match → NO usa los operadores recibidos (no broadcast)', $af_fb === true && count($t_fb) === 1 && $t_fb[0]->email === sanitize_email($prev_admin) && $t_fb[0]->email !== 'op-a@example.com');

add_filter('estandar_suite_core_notifier_admin_fallback_email', static function () { return 'admin-custom@example.com'; });
list($t_cf, $af_cf) = $ON::resolve_target_operators(array('operators' => array(), 'fallback' => true), 'x', 3);
check2('2e filtro override del admin', $af_cf === true && $t_cf[0]->email === 'admin-custom@example.com');
remove_all_filters('estandar_suite_core_notifier_admin_fallback_email');

add_filter('estandar_suite_core_notifier_admin_fallback_email', static function () { return ''; });
list($t_na, $af_na) = $ON::resolve_target_operators(array('operators' => array(), 'fallback' => true), 'x', 4);
check2('2f sin admin → vacío (no broadcast)', $t_na === array() && $af_na === false);
remove_all_filters('estandar_suite_core_notifier_admin_fallback_email');

// Extensión ADMIN_FALLBACK_V1: si la tabla no tiene NINGÚN operador activo,
// dispatch NO debe retornar en silencio — cae al admin (resolve_target_operators).
$on_src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/Leads/OperatorNotifier.php' );
check2('2g dispatch: sin operadores activos → admin fallback (no return)', strpos( $on_src, 'NO_OPERATORS_ADMIN_FALLBACK' ) !== false );
check2('2h FUNNEL_V1: dispatch omite el enriquecido en modo simple', strpos( $on_src, 'notification_skipped_funnel_simple' ) !== false );

echo "── human_* helpers ────────────────────────────────────────────────────\n";
check2('3a human_temperature', $ON::human_temperature('CALIENTE') === 'Caliente' && $ON::human_temperature('FRIO') === 'Frío');
check2('3b human_priority_reason', $ON::human_priority_reason('caliente_sin_gestion_con_senal') !== '');
check2('3b2 INVALID_REASON_V1 mapea invalid', $ON::human_priority_reason('invalid') === 'Lead marcado como inválido por un operador (score 0).');
check2('3c human_behavior_signals', in_array('Volvió a contactar', $ON::human_behavior_signals(array(array('type' => 'lead_recontacted'))), true));

echo "── enrichment(): score / SLA / antigüedad ─────────────────────────────\n";
$state = (object) array(
    'name' => 'Alejandra Soto',
    'quality' => '',
    'priority' => 'alta',
    'temperature' => 'TIBIO',
    'score_components_json' => json_encode(array(
        'total' => 45, 'behavior' => 33, 'capture' => 12,
        'priority_reason' => 'tibio_recontacto_sin_gestion',
        'behavior_detail' => array(array('type' => 'lead_recontacted')),
    )),
    'feedback_status' => 'overdue',
    'created_at' => gmdate('Y-m-d H:i:s', time() - 73 * DAY_IN_SECONDS),
    'submission_count' => 2,
    'first_submitted_at' => gmdate('Y-m-d H:i:s', time() - 73 * DAY_IN_SECONDS),
    'last_submitted_at' => gmdate('Y-m-d H:i:s'),
);
$rc = array('commercial_recontact_count' => 1, 'is_commercial_recontact' => true, 'is_same_session_contact' => false);
$e = $ON::enrichment($state, $rc);
check2('4a score_total 45', (float) $e['score_total'] === 45.0);
check2('4b score_behavior 33', (float) $e['score_behavior'] === 33.0);
check2('4c score_capture 12', (float) $e['score_capture'] === 12.0);
check2('4d sla_state overdue (feedback_status)', $e['sla_state'] === 'overdue');
check2('4e lead_age_days 73', (int) $e['lead_age_days'] === 73);
check2('4f eval_temperature Tibio', $e['eval_temperature'] === 'Tibio');
check2('4g eval_priority_reason mapeado', $e['eval_priority_reason'] !== '');
check2('4h señal humanizada', in_array('Volvió a contactar', $e['eval_behavior_signals'], true));
check2('4i commercial_risk_signal (recontact + sin gestión)', !empty($e['commercial_risk_signal']));

$state_fresh = (object) array(
    'name' => 'Nuevo', 'quality' => 'ok', 'priority' => '', 'temperature' => '',
    'score_components_json' => '', 'feedback_status' => 'pending',
    'created_at' => gmdate('Y-m-d H:i:s'), 'submission_count' => 1,
    'first_submitted_at' => gmdate('Y-m-d H:i:s'), 'last_submitted_at' => gmdate('Y-m-d H:i:s'),
);
$e2 = $ON::enrichment($state_fresh, array());
check2('4j lead fresco: sla within', $e2['sla_state'] === 'within');
check2('4k lead fresco: sin score', (float) $e2['score_total'] === 0.0);
check2('4l lead fresco: sin riesgo', empty($e2['commercial_risk_signal']));

echo "── build_subject() ────────────────────────────────────────────────────\n";
$subj_data = function (array $enriched) use ($state) {
    return array('enriched' => $enriched, 'lead_name' => 'Alejandra Soto', 'lead_state' => $state, 'project_slug' => 'demo');
};
check2('5a reincidente sin gestión → subject alerta', strpos($ON::build_subject($subj_data(array_merge($e, array('commercial_risk_signal' => true, 'commercial_recontact_count' => 1)))), '⚠ Lead reincidente sin gestión') === 0);
check2('5b recontacto comercial', strpos($ON::build_subject($subj_data(array('is_commercial_recontact' => true, 'commercial_recontact_count' => 1, 'commercial_risk_signal' => false, 'behavior_high_intent' => false))), 'Recontacto x1') !== false);
check2('5c alta intención', $ON::build_subject($subj_data(array('behavior_high_intent' => true, 'is_commercial_recontact' => false, 'commercial_risk_signal' => false))) === '🔥 Lead de alta intención: Alejandra Soto');
check2('5d nuevo (subject configurable por settings)', $ON::build_subject($subj_data(array('behavior_high_intent' => false, 'is_commercial_recontact' => false, 'commercial_risk_signal' => false))) === 'Nuevo Lead de demo: Alejandra Soto');
// SUBJECT_TEMPLATE_V1: el asunto sale del setting ({proyecto}/{nombre}).
$_saved_notifier = get_option('estandar_suite_core_leads_notifier_settings', null);
update_option('estandar_suite_core_leads_notifier_settings', array('subject' => 'Lead {proyecto}: {nombre}'));
check2('5e subject configurable (tokens {proyecto}/{nombre})', $ON::build_subject($subj_data(array('behavior_high_intent' => false, 'is_commercial_recontact' => false, 'commercial_risk_signal' => false))) === 'Lead demo: Alejandra Soto');
if ($_saved_notifier === null) { delete_option('estandar_suite_core_leads_notifier_settings'); } else { update_option('estandar_suite_core_leads_notifier_settings', $_saved_notifier); }

echo "── render_email(): template self-contained con enriquecimiento ────────\n";
$settings = array('sender_name' => 'Empresa Demo', 'sender_email' => 'noreply@cliente1.example.com', 'brand_color' => '#1a73e8', 'logo_url' => '', 'subject' => 'Lead nuevo: Alejandra Soto', 'preheader' => 'Contacto registrado.');
$data = array(
    'operator_name' => 'Marta Navarro', 'operator_id' => 1,
    'lead_name' => 'Alejandra Soto', 'lead_email' => 'alejandra@correo.cl', 'lead_phone' => '+56 9 1234 5678',
    'project_area' => 'demo', 'project_slug' => 'demo',
    'campaign' => '', 'campaign_name' => 'google-casas-2025',
    'journey' => array('autoresponse_sent' => true, 'email_opened' => true, 'link_clicked' => false, 'whatsapp_opened' => false, 'contact_registered' => false),
    'ad' => '', 'keyword' => '', 'landing_url' => home_url('/contacto/'), 'utm_source' => 'google', 'utm_medium' => 'cpc',
    'submitted_at' => gmdate('c'), 'submission_count' => 2, 'lead_type' => 'Recurrente', 'submission_position' => 2,
    'commercial_recontact_count' => 1, 'is_commercial_recontact' => true, 'is_same_session_contact' => false,
    'previous_status' => '', 'customer_matched' => false,
    'first_submitted_at' => gmdate('c', time() - 73 * DAY_IN_SECONDS), 'last_submitted_at' => gmdate('c'),
    'commercial_risk_signal' => true, 'repeated_lead' => true, 'behavior_high_intent' => false, 'unmanaged_lead' => true,
    'lead_priority' => 'alta', 'lead_priority_label' => 'ALTA',
    'eval_temperature' => 'Tibio', 'eval_priority_reason' => 'Lead tibio con recontacto y sin gestión.',
    'eval_behavior_signals' => array('Volvió a contactar'),
    'score_total' => 45, 'score_behavior' => 33, 'score_capture' => 12,
    'lead_age_days' => 73, 'sla_state' => 'overdue',
    'fit' => '', 'fit_reason' => '',
    'interest_decision' => array('version' => 'IEE_V1', 'absent' => true), 'interest_narratives' => array(),
    'form_fields' => array('servicio' => 'Asesoría contable mensual'), 'message' => 'Necesito cotizar contabilidad mensual.',
    'lead_id' => 935, 'submission_id' => 936, 'source_plugin' => 'estandar-forms', 'source_form_id' => '3', 'source_entry_id' => 0,
    'feedback_url' => home_url('/?estandar_feedback=1&token=T'), 'workspace_url' => home_url('/workspace/T'), 'workspace_backlog' => array(),
    'ai_context_url' => home_url('/panel-leads/?ai_context=935'), 'consolidated_sheet_url' => '',
);
$html = $ON::render_email($data, $settings);
check2('6a "SLA y antigüedad" presente', strpos($html, 'SLA y antigüedad') !== false);
check2('6b "73 días sin gestión" presente', strpos($html, '73 días sin gestión') !== false);
check2('6c SLA "Vencido" presente', strpos($html, 'Vencido') !== false);
check2('6d "Score:" presente', strpos($html, 'Score:') !== false);
check2('6e "Comportamiento 33" presente', strpos($html, 'Comportamiento 33') !== false);
check2('6f "Acción sugerida" presente', strpos($html, 'Acción sugerida') !== false);
check2('6g gesto recontacto → WhatsApp', strpos($html, 'Recontacto x1 → WhatsApp primero.') !== false);
check2('6h IA Context NO va en el correo (solo panel interno)', strpos($html, '🤖 IA Context') === false && strpos($html, 'ai_context=935') === false);
check2('6i mensaje del prospecto presente', strpos($html, 'Necesito cotizar contabilidad mensual.') !== false);
check2('6j "Por qué esta prioridad" presente', strpos($html, 'Por qué esta prioridad') !== false);
check2('6k NO usa clases legacy (autónomo)', strpos($html, 'Estandar_Leads_') === false);
// EMAIL_IDENTITY_V1: cabecera/footer compartidos en el template.
check2('6n cabecera usa identidad (sender_name como nombre)', strpos($html, 'Empresa Demo') !== false);
check2('6o footer compartido presente', strpos($html, 'estandar.cl') !== false);
// ORIGIN_V1 + QUALIFY_LABELS_V1: 4 orígenes y etiquetas legibles del calificador.
$data_o = $data;
$data_o['source_plugin'] = 'instagram';
$data_o['form_fields'] = array('when_buy' => 'exploring', 'how_finance' => 'nose', '_origin' => 'instant_ig');
$html_o = $ON::render_email($data_o, $settings);
check2('6p origen: Formulario instantáneo de Instagram', strpos($html_o, 'Formulario instantáneo de Instagram') !== false);
check2('6q plazo legible (Solo averiguando), sin la key cruda', strpos($html_o, 'Solo averiguando') !== false && strpos($html_o, '>exploring<') === false);
check2('6r financiamiento legible (Aún no sé), sin la key cruda', strpos($html_o, 'Aún no sé') !== false && strpos($html_o, '>nose<') === false);
$data_p = $data; $data_p['source_plugin'] = 'estandar-capture'; $data_p['form_fields'] = array('_origin' => 'web_popup');
check2('6s origen: Popup Web', strpos($ON::render_email($data_p, $settings), 'Popup Web') !== false);
$data_w = $data; $data_w['source_plugin'] = 'estandar-capture'; $data_w['form_fields'] = array('_origin' => 'web_form');
check2('6t origen: Formulario Web', strpos($ON::render_email($data_w, $settings), 'Formulario Web') !== false);
$data_f = $data; $data_f['source_plugin'] = 'facebook'; $data_f['form_fields'] = array('_origin' => 'instant_fb');
check2('6u origen: Formulario instantáneo de Facebook', strpos($ON::render_email($data_f, $settings), 'Formulario instantáneo de Facebook') !== false);
// QUALIFY_CONFIG_V1: criterio Frío/Tibio/Caliente configurable.
check2('6v qualify defaults (contado=3, warm=2)', $ON::qualification_settings()['fin_contado'] === 3 && $ON::qualification_settings()['warm'] === 2);
$q_s = $ON::qualification_settings();
$d1 = $data; $d1['form_fields'] = array('when_buy' => 'exploring', 'how_finance' => 'contado'); $d1['qualify'] = $q_s;
check2('6w exploring + contado → Tibio (no Frío)', strpos($ON::render_email($d1, $settings), '>Tibio</span>') !== false);
$d2 = $data; $d2['form_fields'] = array('when_buy' => 'asap', 'how_finance' => 'contado'); $d2['qualify'] = $q_s;
check2('6x asap + contado → Caliente', strpos($ON::render_email($d2, $settings), '>Caliente</span>') !== false);
$d3 = $data; $d3['form_fields'] = array('when_buy' => 'exploring', 'how_finance' => 'nose'); $d3['qualify'] = $q_s;
check2('6y exploring + aún no sé → Frío', strpos($ON::render_email($d3, $settings), '>Frío</span>') !== false);

$data_basico = array_merge($data, array('form_fields' => array(), 'message' => '', 'score_total' => 0, 'score_behavior' => 0, 'score_capture' => 0, 'lead_age_days' => 0, 'sla_state' => 'within', 'is_commercial_recontact' => false, 'repeated_lead' => false, 'unmanaged_lead' => true));
$html_basico = $ON::render_email($data_basico, $settings);
check2('6l form básico → marcador visible', strpos($html_basico, 'Form básico — sin mensaje/necesidad capturada') !== false);
check2('6m sin score → no "Score:"', strpos($html_basico, 'Score:') === false);

// LEAD_FRESH_V1: primer contacto real (1 ingreso, sin recontactos) → los
// estados "aún no contactado / sin clasificación" y el SLA "Dentro de plazo"
// son obvios e irrelevantes → no deben aparecer. El enlace al panel sí.
$data_fresh = array_merge($data, array(
    'submission_count' => 1, 'lead_type' => 'Nuevo', 'submission_position' => 1,
    'is_commercial_recontact' => false, 'is_same_session_contact' => false,
    'repeated_lead' => false, 'unmanaged_lead' => true, 'commercial_risk_signal' => false,
    'behavior_high_intent' => false, 'lead_age_days' => 0, 'sla_state' => 'within',
    'score_total' => 0, 'score_behavior' => 0, 'score_capture' => 0,
    'eval_temperature' => '', 'eval_priority_reason' => '', 'eval_behavior_signals' => array(),
    'first_submitted_at' => gmdate('c'), 'last_submitted_at' => gmdate('c'),
));
$html_fresh = $ON::render_email($data_fresh, $settings);
check2('6p lead fresco NO "El prospecto aún no ha sido contactado."', strpos($html_fresh, 'El prospecto aún no ha sido contactado.') === false);
check2('6q lead fresco NO "El prospecto aún no registra clasificación comercial."', strpos($html_fresh, 'El prospecto aún no registra clasificación comercial.') === false);
check2('6r lead fresco NO bloque "SLA y antigüedad"', strpos($html_fresh, 'SLA y antigüedad') === false);
check2('6s lead fresco conserva estado "Primer contacto pendiente"', strpos($html_fresh, 'Primer contacto pendiente') !== false);
check2('6t enlace al panel /panel-leads/ presente', strpos($html_fresh, '/panel-leads/') !== false && strpos($html_fresh, 'Panel de Leads') !== false);
check2('6u lead fresco NO "Registrar resultado y clasificar" se mantiene como acción', strpos($html_fresh, 'Registrar resultado y clasificar después del contacto.') !== false);

// NAME_FALLBACK_V1: es_leads.name vacío pero el form capturó el nombre (campo
// no-canónico, p. ej. "como_te_llamas") → el template lo recupera de form_fields
// y NO muestra "Contacto sin nombre".
$data_namefallback = array_merge($data_fresh, array(
    'lead_name' => '',
    'form_fields' => array(
        'que_necesitas_resolver' => 'necesito_llevar_la_contabilidad_de_mi_empresa',
        'como_te_llamas' => 'Julio Quezada',
        'cual_es_tu_email' => 'contacto@ckodelab.com',
    ),
    'message' => '',
));
$html_nf = $ON::render_email($data_namefallback, $settings);
check2('6x name fallback: nombre recuperado desde como_te_llamas', strpos($html_nf, 'Julio Quezada') !== false && strpos($html_nf, 'Contacto sin nombre') === false);

// FORM_PERFILADO_V1: perfil presente sin mensaje libre → texto neutro (la
// necesidad ya está declarada en el perfil), NO el marcador ámbar de form básico.
$data_perfilado = array_merge($data_namefallback, array('lead_name' => 'Julio Quezada'));
$html_perf = $ON::render_email($data_perfilado, $settings);
check2('6y perfilado sin mensaje → "Sin mensaje libre — necesidad declarada en el formulario."', strpos($html_perf, 'Sin mensaje libre — necesidad declarada en el formulario.') !== false);
check2('6z perfilado sin mensaje NO "Form básico — sin mensaje/necesidad capturada"', strpos($html_perf, 'Form básico — sin mensaje/necesidad capturada') === false);

// Leads NO frescos (recurrentes / recontactos) → SÍ conservan los estados de
// gestión (no se pierden): "aún no contactado" y el bloque SLA.
$data_gest = array_merge($data_fresh, array(
    'submission_count' => 2, 'lead_type' => 'Recurrente', 'repeated_lead' => true,
    'lead_age_days' => 3, 'sla_state' => 'overdue',
    'first_submitted_at' => gmdate('c', time() - 3 * DAY_IN_SECONDS),
));
$html_gest = $ON::render_email($data_gest, $settings);
check2('6v no-fresco conserva "El prospecto aún no ha sido contactado."', strpos($html_gest, 'El prospecto aún no ha sido contactado.') !== false);
check2('6w no-fresco conserva bloque SLA', strpos($html_gest, 'SLA y antigüedad') !== false);
echo "── email_settings(): hereda identidad compartida ───────────────────────\n";
update_option('estandar_suite_core_leads_notifier_settings', array('sender_name' => 'Mi Sitio Override', 'brand_color' => '#ff0000'));
$es = $ON::email_settings();
check2('7a sender_name propio (override)', $es['sender_name'] === 'Mi Sitio Override');
check2('7b brand_color propio (override)', $es['brand_color'] === '#ff0000');
delete_option('estandar_suite_core_leads_notifier_settings');
$es2 = $ON::email_settings();
check2('7c sin saved → brand_color desde identidad (hex válido)', preg_match('/^#[0-9a-fA-F]{3,8}$/', $es2['brand_color']) === 1);
check2('7d sin saved → logo desde identidad (string)', is_string($es2['logo_url']));
check2('7e sin saved → footer desde identidad (no vacío)', trim((string) $es2['footer_text']) !== '');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
