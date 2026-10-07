<?php
/**
 * CONTACTABILITY_V1 — Suite v2 (brain) Contactability adapter tests.
 *
 *   php tests/run-brain-contactability.php   → expect "0 failed"
 *
 * Verifica que brain reutiliza el MISMO motor de core (no implementación
 * paralela): paridad de contrato con el adapter v1.
 */

define('ABSPATH', __DIR__);
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }

// Motor de core (compartido por ambas Suites).
require dirname(__DIR__, 2) . '/estandar-core/includes/Quality/ContactabilityEngine.php';
// Adapter v2.
require dirname(__DIR__) . '/includes/Contactability.php';

$passed = 0; $failed = 0;
function check2($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

echo "── C1: Suite v2 Contactability (mismo motor de core) ──────────\n";
$r = Estandar\Brain\Contactability::phone('+56 9 1234 5678');
check2('1a phone válido → valid', $r['contactability_status'] === 'valid');
check2('1b phone válido → reason valid_chile_mobile', $r['validation_reason'] === 'valid_chile_mobile');
check2('1c contrato 3 campos', array_key_exists('syntax_valid', $r) && array_key_exists('contactability_status', $r) && array_key_exists('validation_reason', $r));

$r = Estandar\Brain\Contactability::phone('56911111111');
check2('1d relleno → suspicious', $r['contactability_status'] === 'suspicious');

$r = Estandar\Brain\Contactability::phone('123');
check2('1e corto → invalid', $r['contactability_status'] === 'invalid');

$r = Estandar\Brain\Contactability::email('juan.perez@gmail.com');
check2('1f email válido → valid', $r['contactability_status'] === 'valid');

$r = Estandar\Brain\Contactability::email('noesuncorreo');
check2('1g email inválido → invalid', $r['contactability_status'] === 'invalid');

// Paridad conceptual con el motor directo.
$direct = Estandar\Core\Quality\ContactabilityEngine::phone('56221234567');
$via_v2 = Estandar\Brain\Contactability::phone('56221234567');
check2('1h paridad de nivel con el motor', $direct['contactability_status'] === $via_v2['contactability_status']);
check2('1i paridad de reason con el motor', $direct['validation_reason'] === $via_v2['validation_reason']);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
