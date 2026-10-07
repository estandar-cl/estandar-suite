<?php
/**
 * IEE-7 — Suite v2 InterestAdapter tests.
 *
 *   php tests/run-flow-interest.php   → expect "0 failed"
 *
 * Verifica que la Suite v2 reutiliza EXACTAMENTE el mismo motor de core
 * (no implementación paralela): el adapter de flow traduce datos del
 * pipeline/capture y el resultado es el mismo InterestDecision.
 */

define('ABSPATH', __DIR__);
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); } }
if (!function_exists('sanitize_title')) { function sanitize_title($t) { return strtolower((string) preg_replace('/[^a-z0-9\-_]/', '-', (string) $t)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) strip_tags((string) $t)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return trim((string) $t); } }
if (!function_exists('absint')) { function absint($n) { return abs((int) $n); } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('get_option')) { function get_option($k, $d = null) { return null; } }

// Motor de core (compartido por ambas Suites).
require dirname(__DIR__, 2) . '/estandar-core/includes/Interest/InterestSignals.php';
require dirname(__DIR__, 2) . '/estandar-core/includes/Interest/InterestChannel.php';
require dirname(__DIR__, 2) . '/estandar-core/includes/Interest/TimeContext.php';
require dirname(__DIR__, 2) . '/estandar-core/includes/Interest/InterestState.php';
require dirname(__DIR__, 2) . '/estandar-core/includes/Interest/InterestPriority.php';
require dirname(__DIR__, 2) . '/estandar-core/includes/Interest/NextBestAction.php';
require dirname(__DIR__, 2) . '/estandar-core/includes/Interest/InterestEngine.php';
require dirname(__DIR__, 2) . '/estandar-core/includes/Interest/InterestEventLog.php';
require dirname(__DIR__, 2) . '/estandar-core/includes/Interest/InterestContext.php';
require dirname(__DIR__, 2) . '/estandar-core/includes/Interest/EngagementEngine.php';
// Adapter de Suite v2.
require dirname(__DIR__) . '/includes/Journey/InterestAdapter.php';

$passed = 0; $failed = 0;
function check2($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

echo "── IEE-7: Suite v2 reutiliza el MISMO motor (no paralelo) ──\n";
// Primer ingreso en v2 (pipeline NEW, capture sin comportamiento).
$new = Estandar\Flow\Journey\InterestAdapter::decide(
    array('journey_state' => 'NEW', 'submission_count' => 1, 'assigned_operator' => 'Demo'),
    array()
);
check2('1a absent=false (motor presente)', empty($new['absent']));
check2('1b nivel CAPTURE', $new['interest_level'] === 'CAPTURE');
check2('1c narrative headline correcta', Estandar\Flow\Journey\InterestAdapter::narratives($new)['headline'] === 'Nuevo prospecto recibido.');

// Evolución v2 (pipeline CONTACTED con comportamiento).
$evolved = Estandar\Flow\Journey\InterestAdapter::decide(
    array('journey_state' => 'ENGAGED', 'submission_count' => 2, 'assigned_operator' => 'Demo'),
    array('email_opened' => true, 'whatsapp_opened' => true, 'channel' => 'google_ads', 'return_visit_after_capture' => true)
);
check2('2a nivel con señales ≥ REVISITED', in_array($evolved['interest_level'], array('REVISITED', 'ENGAGED', 'HIGH'), true));
check2('2b narratives de evolución no vacías', count(Estandar\Flow\Journey\InterestAdapter::narratives($evolved)['evolution']) > 0);

// Engagement configurable en v2.
check2('3a engagement sin reglas → array vacío', Estandar\Flow\Journey\InterestAdapter::engagement('demo', $new, array()) === array());

// PARIDAD conceptual: el mismo contexto produce el mismo nivel en ambos adapters.
$ctx_legacy = Estandar\Core\Interest\InterestContext::build(array(
    'submission_count' => 2,
    'channel' => 'google_ads',
    'behavior' => array('email_opened' => true, 'whatsapp_opened' => true),
    'operator_assigned' => true,
));
$legacy_decision = Estandar\Core\Interest\InterestEngine::decide($ctx_legacy);
$v2_decision = Estandar\Flow\Journey\InterestAdapter::decide(
    array('journey_state' => 'NEW', 'submission_count' => 2, 'assigned_operator' => 'Demo'),
    array('email_opened' => true, 'whatsapp_opened' => true, 'channel' => 'google_ads')
);
check2('4a paridad de nivel entre adapters', $legacy_decision['interest_level'] === $v2_decision['interest_level']);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
