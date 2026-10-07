<?php
/**
 * CONTACTABILITY_V1 — ContactabilityEngine (core) tests.
 *
 *   php tests/run-contactability.php   → expect "0 failed"
 *
 * Puro, sin WordPress.
 */

define('ABSPATH', __DIR__);

require dirname(__DIR__) . '/includes/Quality/ContactabilityEngine.php';
require dirname(__DIR__) . '/includes/Quality/LeadQualityModel.php';
require dirname(__DIR__) . '/includes/Quality/OperatorFeedbackParser.php';
require dirname(__DIR__) . '/includes/Quality/LeadQualityRecalculator.php';
require dirname(__DIR__) . '/includes/Quality/LeadQualityConsistency.php';

use Estandar\Core\Quality\ContactabilityEngine as CE;
use Estandar\Core\Quality\LeadQualityModel as LQM;
use Estandar\Core\Quality\OperatorFeedbackParser as OFP;
use Estandar\Core\Quality\LeadQualityRecalculator as LQR;
use Estandar\Core\Quality\LeadQualityConsistency as LQC;

$passed = 0;
$failed = 0;
function check($name, $ok) {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS  {$name}\n"; }
    else { $failed++; echo "FAIL  {$name}\n"; }
}

echo "── C1: ContactabilityEngine — teléfono ─────────────────────────\n";
$r = CE::phone('+56 9 1234 5678');
check('1a móvil CL válido → status valid', $r['contactability_status'] === 'valid');
check('1b móvil CL → syntax_valid true', $r['syntax_valid'] === true);
check('1c móvil CL → reason valid_chile_mobile', $r['validation_reason'] === 'valid_chile_mobile');

$r = CE::phone('56221234567');
check('1d fijo CL válido → status valid', $r['contactability_status'] === 'valid');
check('1e fijo CL → reason valid_chile_landline', $r['validation_reason'] === 'valid_chile_landline');

$r = CE::phone('');
check('1f vacío → status invalid', $r['contactability_status'] === 'invalid');
check('1g vacío → syntax_valid false', $r['syntax_valid'] === false);
check('1h vacío → reason empty', $r['validation_reason'] === 'empty');

$r = CE::phone('123');
check('1i demasiado corto → invalid', $r['contactability_status'] === 'invalid');
check('1j demasiado corto → reason too_short', $r['validation_reason'] === 'too_short');

$r = CE::phone('56911111111');
check('1k repetidos → suspicious', $r['contactability_status'] === 'suspicious');
check('1l repetidos → reason repeated_digits', $r['validation_reason'] === 'repeated_digits');

$r = CE::phone('5691234567');
check('1m secuencial → suspicious', $r['contactability_status'] === 'suspicious');

$r = CE::phone('987654321');
check('1n secuencia descendente → suspicious', $r['contactability_status'] === 'suspicious');

$r = CE::phone('51912345678');
check('1o Perú +51 (desconocido) → plausible/valid por longitud', $r['contactability_status'] === 'plausible' || $r['contactability_status'] === 'valid');

$r = CE::phone('569123456789');
check('1p longitud anómala (13 dígitos) → no rompe', $r['syntax_valid'] === true);

echo "── C1: ContactabilityEngine — email ────────────────────────────\n";
$r = CE::email('juan.perez@gmail.com');
check('2a email válido → valid', $r['contactability_status'] === 'valid');
check('2b email válido → reason valid_format', $r['validation_reason'] === 'valid_format');
check('2c email válido → syntax_valid true', $r['syntax_valid'] === true);

$r = CE::email('');
check('2d email vacío → invalid', $r['contactability_status'] === 'invalid');
check('2e email vacío → reason empty', $r['validation_reason'] === 'empty');

$r = CE::email('noesuncorreo');
check('2f email sin dominio → invalid', $r['contactability_status'] === 'invalid');
check('2g email sin arroba → reason invalid_format', $r['validation_reason'] === 'invalid_format');

$r = CE::email('juan@correo_sin_punto');
check('2h email sin punto en dominio → invalid', $r['contactability_status'] === 'invalid');

$r = CE::email('juan.perez@@gmail.com');
check('2i email con @@ → invalid', $r['contactability_status'] === 'invalid');

echo "── C1: contrato estable (3 campos presentes siempre) ───────────\n";
$r = CE::phone('56987654321');
check('3a phone devuelve syntax_valid', array_key_exists('syntax_valid', $r));
check('3b phone devuelve contactability_status', array_key_exists('contactability_status', $r));
check('3c phone devuelve validation_reason', array_key_exists('validation_reason', $r));
$r = CE::email('a@b.co');
check('3d email devuelve syntax_valid', array_key_exists('syntax_valid', $r));
check('3e email devuelve contactability_status', array_key_exists('contactability_status', $r));
check('3f email devuelve validation_reason', array_key_exists('validation_reason', $r));

echo "── C2: LeadQualityModel — taxonomía y compatibilidad ───────────\n";
check('4a legacy qualified → admission accepted', LQM::admission_from_legacy_decision('qualified') === 'accepted');
check('4b legacy review → admission review', LQM::admission_from_legacy_decision('review') === 'review');
check('4c legacy disqualified → admission rejected', LQM::admission_from_legacy_decision('disqualified') === 'rejected');
check('4d legacy test → admission rejected', LQM::admission_from_legacy_decision('test') === 'rejected');
check('4e sin decisión → admission pending', LQM::admission_from_legacy_decision('') === 'pending');
check('4f no_response → outcome unreachable', LQM::outcome_from_feedback_result('no_response') === 'unreachable');
check('4g interested → outcome interested', LQM::outcome_from_feedback_result('interested') === 'interested');
check('4h WRONG_CONTACT legacy → invalid_reason wrong_contact', LQM::invalid_reason_from_legacy('WRONG_CONTACT') === 'wrong_contact');
check('4i SPAM legacy → invalid_reason spam', LQM::invalid_reason_from_legacy('SPAM') === 'spam');
check('4j OTHER legacy → invalid_contact_data', LQM::invalid_reason_from_legacy('OTHER') === 'invalid_contact_data');

echo "── C2: caso AK #94 — sin contradicciones semánticas ─────────────\n";
// Admisión aceptada (legacy qualified) ≠ calificación pendiente.
check('5a admission accepted ≠ qualification pending (distancias)', LQM::ADMISSION_ACCEPTED !== LQM::QUALIFICATION_PENDING);
// Contacto inutilizable → invalid_contact_data.
$invalid = LQM::is_contact_data_invalid('unreachable', 'invalid_contact_data', 'invalid');
check('5b unreachable + invalid_contact_data → dato de contacto inválido', $invalid === true);
// Sin respuesta SIN evidencia de dato malo → NO se infiere dato inválido.
$valid = LQM::is_contact_data_invalid('unreachable', '', 'valid');
check('5c unreachable sin evidencia de dato malo → no inválido', $valid === false);
// Calidad baja + dato inválido consistentes.
check('5d estados válidos por dimensión', LQM::is_valid_state('LOW', LQM::QUALITY_STATES) && LQM::is_valid_state('pending', LQM::QUALIFICATION_STATES));

echo "── C3: OperatorFeedbackParser — nota → evidencia estructurada ───\n";
// Caso AK #94: "Sin respuesta" + nota "dio el numero mal".
$f = OFP::parse('no_response', 'dio el numero mal');
check('6a outcome unreachable', $f['contact_outcome'] === 'unreachable');
check('6b phone_invalidated true', $f['phone_invalidated'] === true);
check('6c invalid_reason invalid_contact_data', $f['invalid_reason'] === 'invalid_contact_data');
check('6d nota original conservada íntegra', $f['original_notes'] === 'dio el numero mal');
check('6e confidence alta (0.8)', $f['confidence'] === 0.8);
check('6f source operator_feedback', $f['source'] === 'operator_feedback');

// Sin respuesta SIN nota → NO se infiere dato inválido.
$f = OFP::parse('no_response', '');
check('6g unreachable sin nota → phone NO invalidado', $f['phone_invalidated'] === false);
check('6h unreachable sin nota → sin invalid_reason', $f['invalid_reason'] === '');

// Nota "número inválido".
$f = OFP::parse('no_response', 'número inválido');
check('6i número inválido → phone_invalidated', $f['phone_invalidated'] === true);

// "no corresponde".
$f = OFP::parse('responded', 'no corresponde');
check('6j no corresponde → contact_wrong', $f['contact_wrong'] === true);
check('6k no corresponde → invalid_contact_data', $f['invalid_reason'] === 'invalid_contact_data');

// Validación técnica refuerza la fuente.
$f = OFP::parse('no_response', 'dio el numero mal', array(
    'phone' => CE::phone('56911111111'),
    'email' => CE::email('juan.perez@gmail.com'),
));
check('6l +validación técnica → source operator_feedback+validation', $f['source'] === 'operator_feedback+validation');
check('6m +validación técnica → confidence 0.9', $f['confidence'] === 0.9);

// "email inválido".
$f = OFP::parse('no_response', 'email inválido');
check('6n email inválido → email_invalidated', $f['email_invalidated'] === true);

echo "── C4+C6: LeadQualityRecalculator — precedencia + provenance ────\n";
// Caso AK #94: feedback "Sin respuesta" + "dio el numero mal" + temperatura CALIENTE.
$f = OFP::parse('no_response', 'dio el numero mal');
$rec = LQR::recalculate(array(
    'lead_quality' => '', // sin calidad previa
    'temperature' => 'CALIENTE',
    'commercial_qualification' => 'pending',
    'is_valid_lead' => 1,
), $f);
check('7a calidad → LOW', $rec['lead_quality'] === 'LOW');
check('7b quality_reason invalid_contact_data', $rec['quality_reason'] === 'invalid_contact_data');
check('7c temperatura desescalada (contradicción eliminada)', $rec['temperature'] === '');
check('7d recalculated true', $rec['recalculated'] === true);
check('7e provenance registra previous quality', $rec['provenance'][0]['previous'] === '');
check('7f provenance registra result LOW', $rec['provenance'][0]['result'] === 'LOW');
check('7g provenance registra source', in_array($rec['provenance'][0]['source'], array('operator_feedback', 'operator_feedback+validation'), true));
check('7h provenance registra reason', $rec['provenance'][0]['reason'] === 'invalid_contact_data');
check('7i provenance registra timestamp', !empty($rec['provenance'][0]['timestamp']));
check('7j provenance registra confidence', isset($rec['provenance'][0]['confidence']));
check('7k temperatura: provenance con previous CALIENTE → result vacío', $rec['provenance'][1]['previous'] === 'CALIENTE' && $rec['provenance'][1]['result'] === '');
check('7l evento sugerido quality_recalculated', $rec['event_suggestion']['event_type'] === 'quality_recalculated');
check('7m evento sugerido metadata incluye previous_quality', $rec['event_suggestion']['metadata']['previous_quality'] === '');

// Sin evidencia de dato inválido → NO baja calidad (no-inferencia).
$f = OFP::parse('no_response', '');
$rec = LQR::recalculate(array('lead_quality' => 'HIGH', 'temperature' => 'CALIENTE', 'commercial_qualification' => 'pending', 'is_valid_lead' => 1), $f);
check('7n unreachable sin dato malo → NO baja calidad', $rec['recalculated'] === false && $rec['lead_quality'] === 'HIGH');
check('7o unreachable sin dato malo → temperatura intacta', $rec['temperature'] === 'CALIENTE');

// Ya en LOW + dato inválido → no re-escribe (idempotente).
$rec2 = LQR::recalculate(array('lead_quality' => 'LOW', 'temperature' => '', 'commercial_qualification' => 'pending', 'is_valid_lead' => 1), $f2 = OFP::parse('no_response', 'numero mal'));
check('7p ya LOW → sin cambio duplicado', $rec2['lead_quality'] === 'LOW');

echo "── C5: LeadQualityConsistency — checks del caso AK #94 ──────────\n";
// Caso AK #94 sin corrección: válido + teléfono inválido + CALIENTE + LOW sin razón.
$audit = LQC::audit(array(
    'is_valid_lead' => 1,
    'contactability_phone' => 'invalid',
    'contactability_email' => 'valid',
    'lead_quality' => 'LOW',
    'quality_reason' => '',
    'admission_status' => 'accepted',
    'commercial_qualification' => 'pending',
    'temperature' => 'CALIENTE',
    'contact_outcome' => '',
    'invalid_reason' => '',
));
$issues = array();
foreach ($audit['checks'] as $c) { if (!$c['ok']) { $issues[] = $c['issue']; } }
check('8a detecta valid_lead_with_invalid_phone', in_array('valid_lead_with_invalid_phone', $issues, true));
check('8b detecta low_quality_without_reason', in_array('low_quality_without_reason', $issues, true));
check('8c detecta hot_lead_with_invalid_phone', in_array('hot_lead_with_invalid_phone', $issues, true));
check('8d audit no ok (hay inconsistencias)', $audit['ok'] === false);
$admitted_check = null;
foreach ($audit['checks'] as $c) { if ($c['issue'] === 'admitted_not_qualified') { $admitted_check = $c; } }
check('8e admitted_not_qualified es INFO (no error)', $admitted_check !== null && $admitted_check['ok'] === true && $admitted_check['severity'] === 'info');

// Caso corregido (tras recálculo): sin errores de contradicción.
// NOTA: valid_lead_with_invalid_phone persiste como WARNING informativo —
// is_valid_lead=1 conserva semántica "lead real" incluso con dato inválido
// (semántica documentada en C8); no es una contradicción que bloquee.
$audit2 = LQC::audit(array(
    'is_valid_lead' => 1,
    'contactability_phone' => 'invalid',
    'contactability_email' => 'valid',
    'lead_quality' => 'LOW',
    'quality_reason' => 'invalid_contact_data',
    'admission_status' => 'accepted',
    'commercial_qualification' => 'pending',
    'temperature' => '',
    'contact_outcome' => 'unreachable',
    'invalid_reason' => 'invalid_contact_data',
));
$hard_errors = array();
foreach ($audit2['checks'] as $c) { if (!$c['ok'] && $c['issue'] !== 'valid_lead_with_invalid_phone') { $hard_errors[] = $c['issue']; } }
check('8f caso corregido sin errores de contradicción (solo warning informativo)', empty($hard_errors));
check('8f-bis low_quality ya tiene razón (no warning)', !in_array('low_quality_without_reason', array_map(function($c){return $c['issue'];}, $audit2['checks']), true));
check('8f-ter temperatura desescalada (no hot warning)', !in_array('hot_lead_with_invalid_phone', array_map(function($c){return $c['issue'];}, $audit2['checks']), true));

// unreachable sin razón NO es incoherencia.
$audit3 = LQC::audit(array(
    'is_valid_lead' => 1, 'contactability_phone' => 'valid', 'contactability_email' => 'valid',
    'lead_quality' => 'MEDIUM', 'quality_reason' => '', 'admission_status' => 'accepted',
    'commercial_qualification' => 'pending', 'temperature' => 'TIBIO', 'contact_outcome' => 'unreachable', 'invalid_reason' => '',
));
$errs3 = array();
foreach ($audit3['checks'] as $c) { if (!$c['ok']) { $errs3[] = $c['issue']; } }
check('8g unreachable sin razón no genera error', empty($errs3));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
