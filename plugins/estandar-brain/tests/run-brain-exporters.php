<?php
/**
 * ESTANDAR SUITE v2 — Brain exporters tests (Meta CAPI + SignalQueue, pure paths).
 *
 *   php tests/run-brain-exporters.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); } }
if (!function_exists('sanitize_title')) { function sanitize_title($t) { return strtolower((string) preg_replace('/[^a-z0-9\-_]/', '-', (string) $t)); } }
$GLOBALS['__opts'] = array();
if (!function_exists('get_option')) { function get_option($name = '', $default = false) { return array_key_exists($name, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$name] : $default; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); } }
if (!function_exists('home_url')) { function home_url($p = '/') { return 'https://example.test' . $p; } }
if (!function_exists('current_time')) { function current_time($type = 'mysql') { return date('Y-m-d H:i:s'); } }
if (!function_exists('wp_remote_post')) { function wp_remote_post($url, $args = array()) { return array('response' => array('code' => 200), 'body' => '{}'); } }
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return false; } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return (int) ($r['response']['code'] ?? 0); } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return (string) ($r['body'] ?? ''); } }

require dirname(__DIR__) . '/includes/SignalLedger.php';
require dirname(__DIR__) . '/includes/MetaCapi.php';
require dirname(__DIR__) . '/includes/SignalQueue.php';

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Brain as B;

// ── 1. Mapa de eventos Meta ──────────────────────────────────────────────
check('1a lead_created → Lead', B\MetaCapi::EVENT_MAP['lead_created'] === 'Lead');
check('1b lead_qualified → LeadQualified', B\MetaCapi::EVENT_MAP['lead_qualified'] === 'LeadQualified');
check('1c meeting_booked → Schedule', B\MetaCapi::EVENT_MAP['meeting_booked'] === 'Schedule');
check('1d sale_closed → Purchase', B\MetaCapi::EVENT_MAP['sale_closed'] === 'Purchase');

// ── 2. supports(): calidad sí, rechazo no, lead_created por defecto no ─────
check('2a lead_qualified soportado', B\MetaCapi::supports('lead_qualified') === true);
check('2b meeting_booked soportado', B\MetaCapi::supports('meeting_booked') === true);
check('2c sale_closed soportado', B\MetaCapi::supports('sale_closed') === true);
check('2d lead_rejected NO se exporta', B\MetaCapi::supports('lead_rejected') === false);
check('2e lead_created NO por defecto (evita doble conteo)', B\MetaCapi::supports('lead_created') === false);
check('2f evento desconocido no soportado', B\MetaCapi::supports('nope') === false);

// ── 3. Config por defecto = apagado ───────────────────────────────────────
check('3a no configurado por defecto', B\MetaCapi::is_configured() === false);
$cfg = B\MetaCapi::config();
check('3b send_lead por defecto false', empty($cfg['send_lead']));

// ── 4. export(): sin config o no soportado → skip (sin HTTP) ──────────────
check('4a export no soportado → skip', B\MetaCapi::export(array('event_name' => 'lead_rejected')) === 'skip');
check('4b export sin config → skip', B\MetaCapi::export(array('event_name' => 'lead_qualified', 'id' => 1)) === 'skip');

// ── 5. SignalQueue sin config no toca la cola ─────────────────────────────
$stats = B\SignalQueue::process(10);
check('5a process sin config = ceros', $stats['checked'] === 0 && $stats['sent'] === 0);
check('5b backfill alias de process', B\SignalQueue::backfill(10) === $stats);
check('5c cron hook estable', B\SignalQueue::CRON_HOOK === 'estandar_brain_process_signal_queue');

// ── 6. SignalLedger: hash de identidad determinista (fallback sin core) ────
$h1 = B\SignalLedger::identity_hash('Juan@Example.com ', 'email');
$h2 = B\SignalLedger::identity_hash('juan@example.com', 'email');
check('6a email hash determinista y case-insensitive', $h1 === $h2 && $h1 !== '');
check('6b email vacío → hash vacío', B\SignalLedger::identity_hash('', 'email') === '');
check('6c hash long = 64 hex', strlen($h1) === 64 && ctype_xdigit($h1));

// ── 7. on_feedback: idempotencia del mapeo (sin DB no registra) ───────────
// Sin $wpdb, on_feedback debe salir limpio (no fatal).
B\SignalLedger::on_feedback(123, 'QUALIFIED', 'HOT', 1, '');
B\SignalLedger::on_feedback(123, 'INVALID', 'JUNK', 0, 'spam');
check('7a on_feedback no fatal sin DB', true);

// ── 8. key de idempotencia estable ────────────────────────────────────────
$k1 = B\SignalLedger::idempotency_key('lead_qualified', 'lead', 9, '2026-09-10 10:00:00');
$k2 = B\SignalLedger::idempotency_key('lead_qualified', 'lead', 9, '2026-09-10 10:00:00');
check('8a key estable', $k1 === $k2);
check('8b key distinta por evento', B\SignalLedger::idempotency_key('lead_rejected', 'lead', 9, '2026-09-10 10:00:00') !== $k1);

// ── 9. config ANIDADA ['meta'] — estructura canónica ──────────────────────
$GLOBALS['__opts']['estandar_suite_brain_exporters_settings'] = array(
	'meta' => array( 'enabled' => 1, 'pixel_id' => '123', 'access_token' => 'tok', 'send_lead' => 1 ),
);
$c = B\MetaCapi::config();
check('9a pixel_id anidado leído', $c['pixel_id'] === '123');
check('9b access_token anidado leído', $c['access_token'] === 'tok');
check('9c is_configured true con anidado', B\MetaCapi::is_configured() === true);
check('9d send_lead anidado leído', ! empty( $c['send_lead'] ) );

// ── 10. fallback LEGACY plano (versiones que escribían en la raíz) ─────────
$GLOBALS['__opts']['estandar_suite_brain_exporters_settings'] = array(
	'enabled' => 1, 'pixel_id' => '999', 'access_token' => 'old',
);
$c2 = B\MetaCapi::config();
check('10a fallback plano pixel_id', $c2['pixel_id'] === '999');
check('10b fallback plano is_configured', B\MetaCapi::is_configured() === true);
$GLOBALS['__opts']['estandar_suite_brain_exporters_settings'] = array(
	'meta' => array( 'enabled' => 1, 'pixel_id' => 'nested' ), 'pixel_id' => 'flat',
);
check('10c anidado tiene prioridad sobre plano', B\MetaCapi::config()['pixel_id'] === 'nested');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
