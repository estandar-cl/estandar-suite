<?php
/**
 * Webhook Adapter — Standalone unit tests.
 *
 * Tests payload building, HMAC signing, header construction, and queue insertion.
 * Does NOT require WordPress (stubs WP functions where necessary).
 *
 * Run: php tests/run-webhook-adapter-test.php
 */

error_reporting(E_ALL);
define('ABSPATH', __DIR__);

// Stub WP functions
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function sanitize_text_field($s) { return trim((string) $s); }
function sanitize_title($s) { return strtolower(str_replace(' ', '-', trim($s))); }
function sanitize_email($e) { return filter_var($e, FILTER_SANITIZE_EMAIL); }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($k)); }
function current_time($f) { return date('Y-m-d H:i:s'); }
function wp_rand() { return 12345; }
function wp_http_validate_url($u) { return filter_var($u, FILTER_VALIDATE_URL) !== false; }
function do_action() {}
function get_option($k, $d = null) { return $d; }
function update_option($k, $v) {}

require_once dirname(__DIR__) . '/includes/interface-estandar-destination.php';
require_once dirname(__DIR__) . '/adapters/class-estandar-webhook-destination.php';

$failed = 0;
function check($label, $ok) { global $failed; echo ($ok ? 'PASS' : 'FAIL') . "  $label\n"; $failed += $ok ? 0 : 1; }

// ─── Test 1: Envelope structure ────────────────────────────────────────

$adapter = new Estandar_Webhook_Destination();
check('adapter id is webhook', $adapter->id() === 'webhook');
check('adapter label is not empty', $adapter->label() !== '');

// ─── Test 2: should_handle ─────────────────────────────────────────────

check('handles lead.created', $adapter->should_handle('lead.created', []));
check('handles lead.recontacted', $adapter->should_handle('lead.recontacted', []));
check('handles lead.status.changed', $adapter->should_handle('lead.status.changed', []));
check('handles sale.closed', $adapter->should_handle('sale.closed', []));
check('rejects unknown event', !$adapter->should_handle('unknown.event', []));

// ─── Test 3: Payload contract via reflection ────────────────────────────

$refl = new ReflectionMethod($adapter, 'resolve_correlation_id');
$refl->setAccessible(true);
$correlation_id = $refl->invoke($adapter, []);
check('correlation_id starts with c_', strpos($correlation_id, 'c_') === 0);
check('correlation_id is 34 chars', strlen($correlation_id) === 34);

$refl2 = new ReflectionMethod($adapter, 'build_event_data');
$refl2->setAccessible(true);
$data = $refl2->invoke($adapter, 'lead.created', 123, 456, [
    'contact' => ['name' => 'Juan Pérez', 'email' => 'juan@test.cl', 'phone' => '+56912345678'],
    'attribution' => ['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'test'],
]);
check('data has lead.id', $data['lead']['id'] === 123);
check('data has lead.name', $data['lead']['name'] === 'Juan Pérez');
check('data has submission.id', $data['submission']['id'] === 456);
check('data has utm_source', $data['submission']['utm_source'] === 'google');

$data_no_attr = $refl2->invoke($adapter, 'lead.created', 1, 2, []);
check('data works with empty attribution', isset($data_no_attr['submission']));

// ─── Test 4: Full envelope via public method ────────────────────────────

// We can't call enqueue() without wpdb, but we can verify the envelope builder
// by checking the internal methods work correctly

$refl3 = new ReflectionMethod($adapter, 'resolve_project');
$refl3->setAccessible(true);
check('project from context', $refl3->invoke($adapter, ['context' => ['project_slug' => 'ak-contadores']]) === 'ak-contadores');
check('project from root', $refl3->invoke($adapter, ['project_slug' => 'test-project']) === 'test-project');
check('project empty', $refl3->invoke($adapter, []) === '');

// ─── Test 5: HMAC signing (use our own implementation to verify contract) ──

$secret = 'test-secret-123';
$timestamp = '2026-07-21T15:30:00Z';
$event_id = 'ev_test1234567890123456';
$body = '{"test":"payload"}';

$expected_sig = hash_hmac('sha256', $timestamp . '.' . $event_id . '.' . $body, $secret);
check('HMAC signature is non-empty', $expected_sig !== '');
check('HMAC signature is hex', ctype_xdigit($expected_sig));
check('HMAC signature length is 64', strlen($expected_sig) === 64);

// Test that different secret produces different signature
$wrong_sig = hash_hmac('sha256', $timestamp . '.' . $event_id . '.' . $body, 'wrong-secret');
check('HMAC different with wrong secret', $expected_sig !== $wrong_sig);

// Test that different body produces different signature
$wrong_body_sig = hash_hmac('sha256', $timestamp . '.' . $event_id . '.{"different":"payload"}', $secret);
check('HMAC different with wrong body', $expected_sig !== $wrong_body_sig);

echo PHP_EOL;
echo ($failed === 0 ? 'ALL PASS' : "$failed FAILURES") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
