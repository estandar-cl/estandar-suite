<?php
/**
 * ESTANDAR SUITE v2 — Insights tests (pure logic + guarded DB paths).
 *
 *   php tests/run-insights.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); } }
if (!function_exists('sanitize_title')) { function sanitize_title($t) { return strtolower((string) preg_replace('/[^a-z0-9\-_]/', '-', (string) $t)); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); } }

require dirname(__DIR__) . '/includes/Events.php';
require dirname(__DIR__) . '/includes/Funnel.php';
require dirname(__DIR__) . '/includes/Observability.php';
require dirname(__DIR__) . '/includes/Adoption.php';

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Insights as I;

// ── 1. Events: tipos cerrados + guards ────────────────────────────────────
check('1a tipo válido', I\Events::sanitize_type('capture') === 'capture');
check('1b tipo inválido → vacío', I\Events::sanitize_type('bogus') === '');
check('1c record no-op sin DB', I\Events::record(array('event_type' => 'capture')) === 0);
check('1d record tipo inválido → 0', I\Events::record(array('event_type' => 'x')) === 0);

// ── 2. Funnel: orden canónico, tasas, conversión, mix ─────────────────────
$counts = array('NEW' => 100, 'CONTACTED' => 60, 'QUALIFIED' => 30, 'MEETING_BOOKED' => 15, 'PROPOSAL_SENT' => 10, 'NEGOTIATION' => 6, 'CLIENT' => 3);
$f = I\Funnel::compute($counts);
check('2a primer conteo NEW', $f['first'] === 100);
check('2b tasa NEW→CONTACTED 60%', $f['stages']['CONTACTED']['rate_from_prev'] === 60.0);
check('2c CLIENT → 3', $f['stages']['CLIENT']['count'] === 3);
check('2d conversión NEW→CLIENT 3%', I\Funnel::conversion($counts) === 3.0);
check('2e conversión sin NEW → null', I\Funnel::conversion(array('CLIENT' => 5)) === null);
$mix = I\Funnel::channel_mix(array('google_ads' => 40, 'meta_ads' => 60));
check('2f mix google 40%', $mix['google_ads'] === 40.0 && $mix['meta_ads'] === 60.0);
check('2g mix vacío', I\Funnel::channel_mix(array()) === array());

// ── 3. Observability: honestidad (ausencia → UNKNOWN, no inventa éxito) ───
$r = I\Observability::resolve_state(array());
check('3a sin config → NOT_CONFIGURED', $r['state'] === 'NOT_CONFIGURED');
check('3b sin evidencia nunca ACCEPTED', I\Observability::resolve_state(array('configured' => 1))['state'] === 'UNKNOWN');
check('3c no elegible → NOT_ELIGIBLE', I\Observability::resolve_state(array('configured' => 1, 'eligible' => false))['state'] === 'NOT_ELIGIBLE');
check('3d aceptado con api ack', I\Observability::resolve_state(array('configured' => 1, 'eligible' => true, 'accepted' => true))['state'] === 'ACCEPTED');
check('3e render_line estable', strpos(I\Observability::render_line($r), 'NOT_CONFIGURED') !== false);

// ── 4. Adoption: proyección + guards ──────────────────────────────────────
$p = I\Adoption::project(array('event_name' => 'conversion', 'event_time' => '2026-01-01 00:00:00', 'utm_source' => 'google'));
check('4a tipo proyectado', $p['event_type'] === 'conversion');
check('4b timestamp preservado', $p['occurred_at'] === '2026-01-01 00:00:00');
$p2 = I\Adoption::project(array('event_name' => 'weird', 'created_at' => '2026-02-02 00:00:00'));
check('4c tipo desconocido → pageview', $p2['event_type'] === 'pageview');
check('4d historical vacío sin DB', I\Adoption::historical(5) === array());
check('4e import no-op sin DB', I\Adoption::import(true, 5)['dry_run'] === true);
check('4f rollback no-op sin DB', I\Adoption::rollback() === 0);

// ── 5. Safety gate ────────────────────────────────────────────────────────
check('5a tabla eventos propia', strpos(I\Events::TABLE, 'ea_') !== 0);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
