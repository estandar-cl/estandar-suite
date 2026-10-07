<?php
/**
 * AK-INVALID-CONTACT-001 — Fixture/Test del caso canónico AK #94 / Submission #118.
 *
 *   php tests/run-ak-invalid-contact.php   → expect "0 failed"
 *
 * Reproduce el caso real de producción:
 *   Acquisition: Google Ads · Form: complete · Email: valid
 *   Phone: syntactically accepted · Operator contact attempted
 *   Outcome: unreachable ("Sin respuesta") · Operator evidence: "dio el numero mal"
 *   Estado previo problemático: is_valid_lead=TRUE · Quality=LOW
 *   Qualification Engine=PENDING · política mostró Decision: QUALIFIED.
 *
 * Resultado esperado (modelo LEAN, sin contradicciones):
 *   - admission_status     = accepted        (legacy qualified = admisión)
 *   - commercial_qualification = pending     (no confundir con admisión)
 *   - contactability.phone = invalid         (evidencia operador + validación)
 *   - lead_quality         = LOW             (con quality_reason)
 *   - quality_reason       = invalid_contact_data
 *   - temperature          = ''              (desescalado, coherente)
 *   - sin contradicciones del modelo.
 */

define('ABSPATH', __DIR__);

foreach (glob(dirname(__DIR__) . '/includes/Quality/*.php') as $f) {
    require $f;
}

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

// ── Captura AK #94 ──────────────────────────────────────────────────
// Teléfono "aceptado técnicamente" por el normalizer legacy.
$phone_raw = '569 1111 1111'; // formato aceptable, pero relleno evidente.
$email_raw = 'cliente@empresa.cl';

// 1. Validación de contactabilidad (C1).
$phone = CE::phone($phone_raw);
$email = CE::email($email_raw);

// 2. Admisión: legacy decision qualified → admission accepted (C2).
$admission = LQM::admission_from_legacy_decision('qualified');

// 3. Feedback del operador: "Sin respuesta" + nota "dio el numero mal" (C3).
$feedback = OFP::parse('no_response', 'dio el numero mal', array(
    'phone' => $phone,
    'email' => $email,
));

// 4. Recalculación con precedencia humana (C4/C6).
$recalc = LQR::recalculate(array(
    'lead_quality' => 'LOW',            // estado previo real.
    'temperature'  => 'CALIENTE',       // estado previo real (contradicción).
    'commercial_qualification' => 'pending',
    'is_valid_lead' => 1,               // estado previo real.
), $feedback);

// 5. Consistencia final (C5).
$audit = LQC::audit(array(
    'is_valid_lead' => 1,
    'contactability_phone' => $phone['contactability_status'],
    'contactability_email' => $email['contactability_status'],
    'lead_quality' => $recalc['lead_quality'],
    'quality_reason' => $recalc['quality_reason'],
    'admission_status' => $admission,
    'commercial_qualification' => $recalc['commercial_qualification'],
    'temperature' => $recalc['temperature'],
    'contact_outcome' => $feedback['contact_outcome'],
    'invalid_reason' => $feedback['invalid_reason'],
));

echo "── AK-INVALID-CONTACT-001 ─────────────────────────────────────\n";
echo "  phone raw: {$phone_raw}\n";
echo "  phone status: {$phone['contactability_status']} / {$phone['validation_reason']}\n";
echo "  admission: {$admission}\n";
echo "  outcome: {$feedback['contact_outcome']} / reason: {$feedback['invalid_reason']}\n";
echo "  quality: {$recalc['lead_quality']} / temperature: " . var_export($recalc['temperature'], true) . "\n\n";

check('a admission_status = accepted (legacy qualified = admisión)', $admission === 'accepted');
check('b commercial_qualification = pending (no confundida)', $recalc['commercial_qualification'] === 'pending');
check('c contactability.phone = invalid (evidencia operador)', $feedback['phone_invalidated'] === true);
check('d lead_quality = LOW', $recalc['lead_quality'] === 'LOW');
check('e quality_reason = invalid_contact_data', $recalc['quality_reason'] === 'invalid_contact_data');
check('f temperatura desescalada (sin contradicción)', $recalc['temperature'] === '');
check('g nota original conservada', $feedback['original_notes'] === 'dio el numero mal');
check('h provenance registrada (previous/result/source/reason/ts/confidence)', (function() use ($recalc) {
    foreach ($recalc['provenance'] as $p) {
        if (!array_key_exists('previous', $p) || !array_key_exists('result', $p)
            || !array_key_exists('source', $p) || !array_key_exists('reason', $p)
            || empty($p['timestamp']) || !isset($p['confidence'])) {
            return false;
        }
    }
    return count($recalc['provenance']) >= 1;
})());
check('i sin errores duros de consistencia (solo warning informativo)', $audit['ok'] === true || (function() use ($audit) {
    foreach ($audit['checks'] as $c) { if (!$c['ok'] && $c['issue'] !== 'valid_lead_with_invalid_phone') { return false; } }
    return true;
})());
check('j recalculated = true', $recalc['recalculated'] === true);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
