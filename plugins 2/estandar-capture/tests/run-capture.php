<?php
/**
 * ESTANDAR SUITE v2 — Capture tests (pure logic + guarded DB paths).
 *
 *   php tests/run-capture.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
$_SERVER['HTTP_REFERER'] = '';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$GLOBALS['__test_options'] = array();
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); } }
if (!function_exists('sanitize_title')) { function sanitize_title($t) { return strtolower((string) preg_replace('/[^a-z0-9\-_]/', '-', strtolower(trim((string) $t)))); } }
if (!function_exists('current_time')) { function current_time($t) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) strip_tags((string) $t)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return trim((string) $t); } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($u) { return (string) $u; } }
if (!function_exists('esc_url')) { function esc_url($u) { return (string) $u; } }
if (!function_exists('esc_html')) { function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('is_email')) { function is_email($e) { return filter_var((string) $e, FILTER_VALIDATE_EMAIL) !== false; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); } }
if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
if (!function_exists('wp_generate_uuid4')) { function wp_generate_uuid4() { return sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0x0fff), mt_rand(0,0x3fff)|0x8000, mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }
if (!function_exists('do_action')) { function do_action() {} }

require dirname(__DIR__) . '/includes/Ingestion.php';
require dirname(__DIR__) . '/includes/ConversationEngine.php';
require dirname(__DIR__) . '/includes/Attribution.php';
require dirname(__DIR__) . '/includes/Forms.php';
require dirname(__DIR__) . '/includes/FormBridge.php';
require dirname(__DIR__) . '/includes/Adoption.php';
require dirname(__DIR__) . '/includes/FormsAdoption.php';
require_once dirname(__DIR__) . '/includes/Admin.php';

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Capture as C;

// ── 1. normalize: identidad + atribución + spam ───────────────────────────
$n = C\Ingestion::normalize(array(
    'source' => 'form',
    'contact' => array('name' => '  Sandra Melisa ', 'email' => ' SANDRA@X.CL ', 'phone' => '+56912345678'),
    'content' => array('message' => 'Necesito ayuda para comprar materiales', 'source_form_id' => 'contacto'),
    'attribution' => array('utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => 'ABC123', 'landing_url' => 'https://x.cl/contacto'),
    'spam' => array('honeypot_filled' => true),
));
check('1a email normalizado lowercase', $n['email_normalized'] === 'sandra@x.cl');
check('1b phone normalizado', $n['phone_normalized'] === '56912345678');
check('1c gclid capturado', strpos($n['click_ids_json'], 'ABC123') !== false);
check('1d honeypot → spam flag', $n['spam_honeypot'] === 1);
check('1e uuid presente', strlen($n['capture_uuid']) === 36);
check('1f source_form_id', $n['source_form_id'] === 'contacto');

// ── 2. normalize_phone edge cases ─────────────────────────────────────────
check('2a vacío', C\Ingestion::normalize_phone('') === '');
check('2b 9 dígitos → prefijo 56', C\Ingestion::normalize_phone('9 1234 5678') === '56912345678');
check('2c 8 dígitos → prefijo 56', C\Ingestion::normalize_phone('12345678') === '5612345678');
check('2d 0056 → quitar 00', C\Ingestion::normalize_phone('005612345678') === '5612345678');
check('2e ya con 56 se mantiene', C\Ingestion::normalize_phone('5612345678') === '5612345678');
check('2f no dígitos → vacío', C\Ingestion::normalize_phone('abc') === '');

// ── 3. Attribution: canal y evidencia de click ────────────────────────────
check('3a google cpc → google_ads', C\Attribution::channel('google', 'cpc') === 'google_ads');
check('3b fb paid → meta_ads', C\Attribution::channel('fb', 'paid') === 'meta_ads');
check('3c organic → google_organic', C\Attribution::channel('google', 'organic') === 'google_organic');
check('3d direct', C\Attribution::channel('', '') === 'direct');
check('3e gclid es evidencia', C\Attribution::has_click_evidence(array('gclid' => 'x'), 'google_ads') === true);
check('3f fbp SOLO no es evidencia (paridad contrato)', C\Attribution::has_click_evidence(array('meta_fbp' => 'x'), 'meta_ads') === false);
check('3g fbc sí es evidencia', C\Attribution::has_click_evidence(array('meta_fbc' => 'x'), 'meta_ads') === true);

// ── 4. Adoption: proyección de submission histórica (read-only) ───────────
$p = C\Adoption::project_submission(array(
    'lead_id' => 42, 'lead_uuid' => 'uuid-42', 'source_plugin' => 'estandar-chatbot',
    'submitted_at' => '2026-01-02 03:04:05', 'name' => 'Juan', 'email_normalized' => 'j@x.cl',
    'phone_normalized' => '56911112222', 'message' => 'hola', 'gclid' => 'G1', 'suspect' => 1,
));
check('4a origin_lead_id preservado', $p['origin_lead_id'] === 42);
check('4b uuid preservado', $p['capture_uuid'] === 'uuid-42');
check('4c timestamp preservado', $p['created_at'] === '2026-01-02 03:04:05');
check('4d click id del histórico', strpos($p['click_ids_json'], 'G1') !== false);
check('4e suspect → spam flag', $p['spam_honeypot'] === 1);
check('4f import no-op sin DB (guard)', C\Adoption::import(true, 5)['dry_run'] === true);
check('4g historical vacío sin DB', C\Adoption::historical(5) === array());
check('4h rollback no-op sin DB', C\Adoption::rollback_import() === 0);

// ── 5. Safety gate: proyección nunca toca tablas legadas ──────────────────
check('5a tabla de captura propia (no es_*)', strpos(C\Ingestion::TABLE, 'es_') !== 0);

// ── 6. Persist row convergencia (bug adopción: 20 cols vs 19 formats) ─────
$row = C\Ingestion::row_for_insert(C\Ingestion::normalize(array('contact' => array('name' => 'A'))));
check('6a data tiene 22 columnas', count($row['data']) === 22);
check('6b formats tiene 22', count($row['formats']) === 22);
check('6c origin_lead_id default 0', $row['data']['origin_lead_id'] === 0);
check('6d spam_honeypot default 0', $row['data']['spam_honeypot'] === 0);
$proj = C\Adoption::project_submission(array('lead_id' => 99, 'submitted_at' => '2026-01-01 00:00:00'));
$prow = C\Ingestion::row_for_insert($proj);
check('6e proyección adopción → 22 columnas y 22 formats', count($prow['data']) === 22 && count($prow['formats']) === 22);
check('6f origin preservado en proyección', $prow['data']['origin_lead_id'] === 99);
check('6g formats alineados con columnas', implode(',', array_keys($row['data'])) === implode(',', array_keys(C\Ingestion::columns())));

// ── 7. FormBridge: hooks guardados + ingesta + descubrimiento ─────────────
$k1 = C\FormBridge::webhook_key();
check('7a webhook key generada', strlen($k1) === 48);
check('7b webhook key estable', C\FormBridge::webhook_key() === $k1);
check('7c forms cf7 sin plugin → vacío', C\FormBridge::forms('cf7') === array());
check('7d forms gravity sin plugin → vacío', C\FormBridge::forms('gravity') === array());
check('7e fields v2 sin DB → vacío', C\FormBridge::fields('v2', 0) === array());
$r = C\FormBridge::ingest_form('cf7', 5, array('your-name' => 'Ana', 'your-email' => 'a@b.cl'));
check('7f ingest sin DB → id 0 (guard)', is_array($r) && (int) $r['id'] === 0);
check('7g fuentes válidas del bridge', is_array(C\FormBridge::forms('jotform')));

// ── 8. FormsAdoption: proyecciones puras (entries + survey responses) ─────
$entry = C\FormsAdoption::project_entry(array('id' => 41, 'form_id' => 3, 'data_json' => '{"name":"Ana Pérez","email":"ana@x.cl","mensaje":"Hola"}', 'status' => 'active', 'created_at' => '2026-01-02 03:04:05'));
check('8a entry source=form + origen form_id', $entry['source'] === 'form' && $entry['source_form_id'] === '3');
check('8b entry origin_lead_id = id legacy', (int) $entry['origin_lead_id'] === 41);
check('8c entry name/email extraídos de data_json', $entry['name'] === 'Ana Pérez' && $entry['email_normalized'] === 'ana@x.cl');
check('8d entry fields_json preserva data', json_decode($entry['fields_json'], true)['mensaje'] === 'Hola');
check('8e entry created_at heredado', $entry['created_at'] === '2026-01-02 03:04:05');
$resp = C\FormsAdoption::project_response(array('id' => 9, 'form_id' => 3, 'question_index' => 1, 'question_label' => 'Edad', 'question_type' => 'text', 'answer_value' => '34', 'answered_at' => '2026-02-02 03:04:05'));
check('8f response source=survey', $resp['source'] === 'survey');
check('8g response origin = id legacy', (int) $resp['origin_lead_id'] === 9);
check('8h response fields_json lleva pregunta+respuesta', json_decode($resp['fields_json'], true)['question_label'] === 'Edad' && json_decode($resp['fields_json'], true)['answer_value'] === '34');
check('8i import sin DB → imported 0 (guard)', C\FormsAdoption::import_entries(false)['imported'] === 0);
check('8j rollback sin DB → 0 (guard)', C\FormsAdoption::rollback_entries() === 0 && C\FormsAdoption::rollback_responses() === 0);

// ── 9. Migración controlada Forms legacy → nativos (pure logic) ───────────
check('9a legacy_form_type_map general → lead', C\Forms::legacy_form_type_map('general') === 'lead');
check('9b legacy_form_type_map lead_capture → lead', C\Forms::legacy_form_type_map('lead_capture') === 'lead');
check('9c legacy_form_type_map survey → vacío (fuera de alcance Forms)', C\Forms::legacy_form_type_map('survey') === '');
$lf_sample = array(
    'id' => 3, 'name' => 'AltaCruz', 'slug' => 'acacios-altacruz', 'form_type' => 'lead_capture',
    'form_context' => 'generic', 'webhook_url' => 'https://x.cl/h', 'notify_emails' => 'a@b.cl',
    'fields_json' => '[{"type":"text","name":"nombre"}]', 'created_at' => '2026-01-01 00:00:00',
);
$native = C\Forms::legacy_to_native($lf_sample, 'lead');
check('9d legacy_to_native preserva id', (int) $native['id'] === 3);
check('9e legacy_to_native mapea tipo a lead', $native['form_type'] === 'lead');
$nc = json_decode($native['config_json'], true);
check('9f marcador _migrated_legacy presente', isset($nc[C\Forms::MIG_MARKER]) && (int) $nc[C\Forms::MIG_MARKER]['id'] === 3);
check('9g webhook + notify conservados', ($nc['webhook_url'] ?? '') === 'https://x.cl/h' && ($nc['notify_emails'] ?? '') === 'a@b.cl');
check('9h fields_json conservado', ($nc['fields_json'] ?? '') === '[{"type":"text","name":"nombre"}]');
$parity = C\Forms::parity_diff($lf_sample, $native);
check('9i parity_diff OK cuando coincide', ! empty($parity['parity']));
$parity_bad = C\Forms::parity_diff(array('id' => 3, 'webhook_url' => 'https://old.cl'), $native);
check('9j parity_diff detecta diffs (webhook distinto)', empty($parity_bad['parity']));

// ── 10. Forms: correo por Form (mail_settings + guards) ───────────────────
$ms = C\Forms::mail_settings(array('send_email' => 1, 'notify_emails' => 'a@b.cl', 'mail_subject' => 'S {{form_name}}'));
check('10a notify enabled + destinatarios', ! empty($ms['notify']['enabled']) && $ms['notify']['to'] === 'a@b.cl');
$ms2 = C\Forms::mail_settings(array('autoresponse' => array('enabled' => 1, 'email_field' => 'mail', 'subject' => 'Gracias')));
check('10b autoresponse config', ! empty($ms2['autoresponse']['enabled']) && $ms2['autoresponse']['email_field'] === 'mail');
$no = C\Forms::notify_submission(2, array(), array());
check('10c notify sin config → disabled (no wp_mail)', ! empty($no['error']) && $no['error'] === 'disabled');
$ar = C\Forms::autoresponse_submission(2, array(), array());
check('10d autoresponse sin config → disabled', ! empty($ar['error']) && $ar['error'] === 'disabled');
check('10e submissions sin DB → vacío (guard)', C\Forms::submissions(2) === array());
check('10f pipeline_for_capture sin DB → null (guard)', C\Forms::pipeline_for_capture(1) === null);

// ── 11. Editor por subtabs: registro canónico + resolución + nav ────────────
require_once dirname(__DIR__) . '/../estandar-core/includes/Admin.php';
$st_subtabs = C\Admin::editor_subtabs();
check('11a registro de subtabs (4 módulos: diseno/configuracion/correo/entradas)', array_keys($st_subtabs) === array('diseno', 'configuracion', 'correo', 'entradas'));
check('11b subtab por defecto = diseno (primera clave)', array_key_first($st_subtabs) === 'diseno');
check('11b2 general y repeticion ya no son subtabs', !isset($st_subtabs['general']) && !isset($st_subtabs['repeticion']));
unset($_GET['st']);
check('11c sin st → diseno (default)', C\Admin::resolve_editor_subtab() === 'diseno');
$_GET['st'] = 'diseno';
check('11d st válido → diseno', C\Admin::resolve_editor_subtab() === 'diseno');
$_GET['st'] = 'zzz';
check('11e st inválido → diseno (fallback seguro)', C\Admin::resolve_editor_subtab() === 'diseno');
$_GET['st'] = 'CORREO';
check('11f st mayúsculas → normalizado a correo', C\Admin::resolve_editor_subtab() === 'correo');
$_GET['st'] = 'general';
check('11g deep-link st=general → alias configuracion', C\Admin::resolve_editor_subtab() === 'configuracion');
$_GET['st'] = 'repeticion';
check('11h deep-link st=repeticion → alias configuracion', C\Admin::resolve_editor_subtab() === 'configuracion');
$_GET['st'] = 'entradas';
check('11i st=entradas válido', C\Admin::resolve_editor_subtab() === 'entradas');
$nav = \Estandar\Core\Admin::subtab_nav_html(array('general' => array('label' => 'General', 'url' => 'http://x/?st=general'), 'correo' => array('label' => 'Correo', 'url' => 'http://x/?st=correo')), 'correo');
check('11g nav marca la subtab activa', strpos($nav, 'nav-tab-active') !== false && strpos($nav, 'data-st="correo"') !== false);
check('11h nav escapa etiquetas', strpos(\Estandar\Core\Admin::subtab_nav_html(array('x' => array('label' => '<b>X</b>', 'url' => '#')), 'x'), '&lt;b&gt;X&lt;/b&gt;') !== false);
$_GET['st'] = '';

// ── 12. Regresión: merge, renderable_fields, repeat_visibility, reason ──────
// renderable_fields: sections y fields_json
$rf = C\Forms::renderable_fields(array('sections' => array(array('title' => 'A', 'fields' => array(array('name' => 'x', 'label' => 'X', 'type' => 'text'), array('name' => 'y', 'label' => 'Y', 'type' => 'email'))), array('title' => 'B', 'fields' => array(array('name' => 'z', 'label' => 'Z', 'type' => 'tel'))))));
check('12a renderable_fields sections → plano', count($rf) === 3 && $rf[0]['name'] === 'x' && $rf[2]['name'] === 'z');
check('12b renderable_fields fields_json → plano', count(C\Forms::renderable_fields(array('fields_json' => array(array('name' => 'a'), array('name' => 'b'))))) === 2);
check('12c renderable_fields vacío → []', C\Forms::renderable_fields(array()) === array());
// merge_config_json: no destructivo + parcial
$cur = json_encode(array('sections' => array(array('title' => 'A', 'fields' => array(array('name' => 'x', 'label' => 'X', 'type' => 'text')))), 'repeat_visibility' => array('mode' => 'hide_temporarily', 'duration' => 30, 'unit' => 'minutes', 'behavior' => 'show_again', 'message' => 'Vuelve'), 'mail_body' => 'Cuerpo {{fields}}'));
$m_empty = C\Forms::merge_config_json($cur, '{}');
check('12d merge con post vacío conserva documento', strlen($m_empty) > 100 && isset(json_decode($m_empty, true)['mail_body']));
$m_partial = C\Forms::merge_config_json($cur, json_encode(array('sections' => array(array('title' => 'A', 'fields' => array(array('name' => 'x', 'label' => 'X2', 'type' => 'text')))))));
$mp = json_decode($m_partial, true);
check('12e merge parcial: sección actualizada', $mp['sections'][0]['fields'][0]['label'] === 'X2');
check('12f merge parcial: claves ausentes preservadas', isset($mp['repeat_visibility']) && isset($mp['mail_body']));
check('12g merge no vacía secciones por POST parcial', count($mp['sections']) === 1);
check('12h merge post inválido → conserva actual', C\Forms::merge_config_json($cur, 'no-json') === json_encode(json_decode($cur, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
// repeat_visibility: fallback always_visible
$rv = C\Forms::repeat_visibility(array());
check('12i default always_visible', $rv['mode'] === 'always_visible' && $rv['enabled'] === false);
$rv2 = C\Forms::repeat_visibility(array('repeat_visibility' => array('mode' => 'hide_temporarily', 'duration' => 30, 'unit' => 'days')));
check('12j hide_temporarily válido', $rv2['mode'] === 'hide_temporarily' && $rv2['duration'] === 30 && $rv2['unit'] === 'days' && $rv2['enabled'] === true);
$rv3 = C\Forms::repeat_visibility(array('repeat_visibility' => array('mode' => 'hide_temporarily', 'duration' => 0, 'unit' => 'semanas')));
check('12k duración 0/unidad inválida → no oculta (fallback seguro)', $rv3['enabled'] === false && $rv3['unit'] === 'minutes');
check('12l repeat_visibility_seconds días', C\Forms::repeat_visibility_seconds(array('duration' => 2, 'unit' => 'days')) === 172800);
// repeat_reasons + repeat_priority determinística
check('12m razones canónicas', array_keys(C\Forms::repeat_reasons()) === array('not_contacted_yet', 'add_info', 'request_changed', 'urgent', 'other'));
$p1 = C\Forms::repeat_priority('not_contacted_yet', 2, false);
check('12n not_contacted_yet sin contacto → escalación', $p1['score_delta'] === 30 && $p1['escalation'] === true);
$p2 = C\Forms::repeat_priority('not_contacted_yet', 2, true);
check('12o not_contacted_yet con contacto → prioridad, sin escalación', $p2['score_delta'] === 30 && $p2['escalation'] === false);
check('12p urgent → escalación', C\Forms::repeat_priority('urgent', 1, true)['escalation'] === true && C\Forms::repeat_priority('urgent', 1, true)['score_delta'] === 50);
check('12q request_changed/add_info/other', C\Forms::repeat_priority('request_changed', 1, true)['score_delta'] === 20 && C\Forms::repeat_priority('add_info', 1, true)['score_delta'] === 10 && C\Forms::repeat_priority('invalido', 1, true)['score_delta'] === 5);
// guards (sin DB / formularios inexistentes)
check('12r record_repeat_reason sin DB → no_db', !empty(C\Forms::record_repeat_reason(1, 'urgent')['error']));
check('12s validate sin DB → inexistente', C\Forms::validate(1)['valid'] === false);
check('12t restore_legacy_config sin DB → no_db', in_array('no_db', C\Forms::restore_legacy_config(2)['reasons'], true));
check('12u inspect sin DB → inexistente', C\Forms::inspect(1)['ok'] === false);
check('12v snapshot_config sin DB → null', C\Forms::snapshot_config(1) === null);
// config_loss_reason (protección permanente contra degradación a {})
$cur_full = array('sections' => array(array('title' => 'A', 'fields' => array(array('name' => 'x', 'label' => 'X', 'type' => 'text')))), 'fields_json' => array(array('name' => 'y')));
check('12w pérdida no solicitada de contenido → motivo', C\Forms::config_loss_reason($cur_full, array()) === 'perdida_de_contenido_no_solicitada');
check('12x config actual vacía → sin bloqueo', C\Forms::config_loss_reason(array(), array()) === '');
check('12y config nueva inválida → motivo', C\Forms::config_loss_reason($cur_full, null) === 'config_invalida');
check('12z secciones presentes en nuevo → sin bloqueo', C\Forms::config_loss_reason($cur_full, array('sections' => array(array('title' => 'B', 'fields' => array())))) === '');

// ── 13. Tipo canónico intercambiable (mismo motor) + conversión ────────────
check('13a types canónicos lead/survey/incident', array_keys(C\Forms::types()) === array('lead', 'incident', 'survey'));
check('13b type_exclusive_keys survey incluye survey_config', in_array('survey_config', C\Forms::type_exclusive_keys('survey'), true));
check('13c type_exclusive_keys incident incluye incidents_config', in_array('incidents_config', C\Forms::type_exclusive_keys('incident'), true));
check('13d type_exclusive_keys lead vacío', C\Forms::type_exclusive_keys('lead') === array());
check('13e convert_type sin DB → inexistente', C\Forms::convert_type(1, 'survey', array())['error'] === 'inexistente');
check('13f type_conversion_info sin DB → inexistente', C\Forms::type_conversion_info(1, 'survey')['error'] === 'inexistente');
$info_lead_survey = C\Forms::type_conversion_info(0, 'survey');
check('13g type_conversion_info documento vacío → warning frontend lead-only', !empty($info_lead_survey['warnings']) || $info_lead_survey['error'] === 'inexistente');

// ── 14. MS-2: Conversation Engine ──────────────────────────────────────────
use Estandar\Capture\ConversationEngine as CE;
check('14a 10 experiencias', count(CE::EXPERIENCES) === 10);
check('14b lead en experiencias', CE::is_conversational('lead'));
check('14c survey en experiencias', CE::is_conversational('survey'));
check('14d quote en experiencias', CE::is_conversational('quote'));
check('14e diagnostico en experiencias', CE::is_conversational('diagnostico'));
check('14f recovery en experiencias', CE::is_conversational('recovery'));
check('14g assessment en experiencias', CE::is_conversational('assessment'));
check('14h no conversacional → false', !CE::is_conversational('blog_post'));
check('14i experience label lead', CE::experience_label('lead') === 'Contacto');
check('14j experience label survey', CE::experience_label('survey') === 'Encuesta');
check('14k desconocida → raw', CE::experience_label('bogus') === 'bogus');
check('14l widgets catálogo', in_array('text', CE::widgets(), true) && in_array('select', CE::widgets(), true));
check('14m completion strategies', in_array('create_lead', CE::completion_strategies(), true));

// ── 15. MS-2: Session + Steps ──────────────────────────────────────────────
$session = CE::start_session(1, array('source' => 'landing'));
check('15a start session', isset($session['started_at']) && $session['form_id'] === 1);
check('15b session has context', $session['context']['source'] === 'landing');
check('15c session initial score', $session['score'] === 0);
check('15d session initial step', $session['current_step'] === 0);

$steps = array(
	array('id' => 'name', 'widget' => 'text', 'scoring' => array('rules' => array())),
	array('id' => 'need', 'widget' => 'select', 'scoring' => array('rules' => array(
		array('field' => 'need', 'operator' => 'eq', 'value' => 'urgente', 'score' => 30),
	)), 'branching' => array(
		array('condition' => 'need:low', 'goto' => 'final'),
	)),
	array('id' => 'budget', 'widget' => 'number'),
	array('id' => 'final', 'widget' => 'info'),
);

$r1 = CE::process_step($session, $steps, 0, array('name' => 'Josias', 'value' => 'Josias'));
check('15e step 0 completed', !$r1['completed'] && $r1['next_step']['id'] === 'need');

$r2 = CE::process_step($session, $steps, 1, array('need' => 'urgente', 'value' => 'urgente'));
check('15f step 1 scored', $session['score'] > 0);

$completion = CE::complete_session($session, array('strategy' => 'create_lead'));
check('15g complete session', $completion['strategy'] === 'create_lead');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
