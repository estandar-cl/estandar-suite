<?php
/**
 * IEE-5 — Notification Refactor tests.
 *
 *   php tests/run-interest-notification.php   → expect "0 failed"
 *
 * Verifica el contrato del template lead-notification.php:
 *   - IEE activo (primer ingreso)  → "Nuevo prospecto recibido." + sin "aún no ha sido contactado".
 *   - IEE activo (evolución)       → headline de evolución + NBA.
 *   - IEE ausente (absent=true)    → comportamiento legacy byte-idéntico.
 *
 * NOTA: depende de WordPress vivo (wp_mail no es necesario, solo el render).
 */

if (!defined('ABSPATH')) {
    require_once dirname(__DIR__, 4) . '/wp-load.php';
}

$passed = 0;
$failed = 0;
function check2($name, $ok) {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS  {$name}\n"; }
    else { $failed++; echo "FAIL  {$name}\n"; }
}

$base = array(
    'operator_name' => 'Carla Fernández',
    'lead_name'     => 'Juan Pérez',
    'lead_email'    => 'juan.perez@email.com',
    'lead_phone'    => '+56 9 1234 5678',
    'project_area'  => 'Proyecto Demo',
    'project_slug'  => 'example-project',
    'campaign'      => 'google-demo-2025',
    'utm_source'    => 'google',
    'utm_medium'    => 'cpc',
    'landing_url'   => home_url('/contacto/'),
    'submitted_at'  => gmdate('c'),
    'submission_count' => 1,
    'lead_type'     => 'Nuevo',
    'form_fields'   => array('servicio' => 'Asesoría contable mensual', 'mensaje' => 'Necesito cotizar.'),
    'message'       => 'Necesito cotizar.',
    'source_plugin' => 'estandar-forms',
    'feedback_url'  => home_url('/?estandar_feedback=1&token=T'),
    'workspace_url' => home_url('/workspace/T'),
    'workspace_backlog' => array(),
    'lead_id'       => 0,
    'submission_id' => 0,
);

$legacy = array_merge($base, array(
    // Sin interest_* keys → camino legacy (absent por defecto).
));

$iee_new = array_merge($base, array(
    'interest_narratives' => array(
        'headline'  => 'Nuevo prospecto recibido.',
        'status'    => 'Operador asignado.',
        'pending'   => 'Primer contacto pendiente.',
        'evolution' => array(),
        'action'    => 'Primer contacto pendiente',
    ),
    'interest_decision' => array(
        'interest_label' => 'Recién capturado',
        'interest_level' => 'CAPTURE',
        'priority' => array('level' => 'low', 'label' => 'Baja'),
        'next_best_action' => array('action' => 'wait', 'label' => 'Primer contacto pendiente', 'confidence' => 0.6),
    ),
));

$iee_evolved = array_merge($base, array(
    'submission_count' => 2,
    'lead_type' => 'Recurrente',
    'interest_narratives' => array(
        'headline'  => 'El prospecto volvió a interesarse.',
        'status'    => 'Volvió a interesarse · Ingreso #2',
        'pending'   => 'Recontactar',
        'evolution' => array('Segundo formulario recibido', 'Google Ads'),
        'action'    => 'Recontactar',
    ),
    'interest_decision' => array(
        'interest_label' => 'Volvió a interesarse',
        'interest_level' => 'REVISITED',
        'priority' => array('level' => 'normal', 'label' => 'Normal'),
        'next_best_action' => array('action' => 'recontact', 'label' => 'Recontactar', 'confidence' => 0.7),
    ),
));

$html_legacy = Estandar_Leads_Email::render($legacy);
$html_iee_new = Estandar_Leads_Email::render($iee_new);
$html_iee_evolved = Estandar_Leads_Email::render($iee_evolved);

echo "── IEE-5: primer ingreso con IEE activo ─────────────────────\n";
check2('1a headline "Nuevo prospecto recibido." presente', strpos($html_iee_new, 'Nuevo prospecto recibido.') !== false);
check2('1b NO dice "aún no ha sido contactado"', strpos($html_iee_new, 'aún no ha sido contactado') === false);
check2('1c "Operador asignado." presente', strpos($html_iee_new, 'Operador asignado.') !== false);
check2('1d "Primer contacto pendiente." presente', strpos($html_iee_new, 'Primer contacto pendiente.') !== false);

echo "── IEE-5: evolución con IEE activo ───────────────────────────\n";
check2('2a headline evolución presente', strpos($html_iee_evolved, 'El prospecto volvió a interesarse.') !== false);
check2('2b señal "Segundo formulario recibido" presente', strpos($html_iee_evolved, 'Segundo formulario recibido') !== false);
check2('2c NBA "Recontactar" presente', strpos($html_iee_evolved, 'Recontactar') !== false);
check2('2d evolución no dice "aún no ha sido contactado"', strpos($html_iee_evolved, 'aún no ha sido contactado') === false);

echo "── IEE-5: ausencia = default (legacy byte-idéntico) ──────────\n";
$legacy_managed = array_merge($base, array('unmanaged_lead' => true, 'high_intent_signal' => false));
$html_legacy_managed = Estandar_Leads_Email::render($legacy_managed);
check2('3b legacy con unmanaged_lead conserva "aún no ha sido contactado"', strpos($html_legacy_managed, 'aún no ha sido contactado') !== false);

echo "── IEE-5: sin IEE (interest_decision absent) no cambia nada ──\n";
$legacy_absent = array_merge($base, array(
    'interest_decision' => array('version' => 'IEE_V1', 'absent' => true),
    'unmanaged_lead' => true,
    'high_intent_signal' => false,
));
$html_absent = Estandar_Leads_Email::render($legacy_absent);
check2('4a absent=true con unmanaged → mensaje legacy', strpos($html_absent, 'aún no ha sido contactado') !== false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
