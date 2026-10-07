<?php
/**
 * ESTANDAR SUITE v2 — Web funnel tests (pure logic + DB-guarded reads).
 *
 * Embudo web sesiones → pageviews → leads (WebFunnel) y su serie diaria.
 *
 *   php tests/run-web-funnel.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); } }
if (!function_exists('sanitize_title')) { function sanitize_title($t) { return strtolower((string) preg_replace('/[^a-z0-9\-_]/', '-', (string) $t)); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }

require dirname(__DIR__) . '/includes/Analytics.php';
require dirname(__DIR__) . '/includes/WebFunnel.php';

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Insights as I;

// ── 1. normalize_range: saneo, default, clamp ─────────────────────────────
$r = I\WebFunnel::normalize_range('2026-09-01', '2026-09-06');
check('1a rango respeta fechas', $r === array('2026-09-01', '2026-09-06'));
$r = I\WebFunnel::normalize_range('', '');
check('1b sin fechas → default 30d', strtotime($r[1]) - strtotime($r[0]) <= (29 * DAY_IN_SECONDS) + 5);
check('1c sin fechas → default desde >= -30d', $r[1] >= $r[0]);
$r = I\WebFunnel::normalize_range('bad!!!input', '2026-09-06');
check('1d saneo quita basura (input inválido → default)', $r[1] === gmdate('Y-m-d', time()));

// ── 2. compute: tasas paso-anterior + cuellos <30% ────────────────────────
$f = I\WebFunnel::compute(array('SESIONES' => 100, 'PAGEVIEWS' => 250, 'LEADS' => 20));
check('2a orden de etapas', array_keys($f['stages']) === I\WebFunnel::STAGE_ORDER);
check('2b primera etapa sin tasa', $f['stages']['SESIONES']['rate_from_prev'] === null);
check('2c sesiones→pageviews 250%', $f['stages']['PAGEVIEWS']['rate_from_prev'] === 250.0);
check('2d pageviews→leads 8%', $f['stages']['LEADS']['rate_from_prev'] === 8.0);
check('2e leads es cuello (<30%)', $f['stages']['LEADS']['bottleneck'] === true);
check('2f sesiones no es cuello', $f['stages']['SESIONES']['bottleneck'] === false);
check('2g pageviews no es cuello', $f['stages']['PAGEVIEWS']['bottleneck'] === false);
check('2h first = sesiones', $f['first'] === 100);

$f2 = I\WebFunnel::compute(array('SESIONES' => 0, 'PAGEVIEWS' => 0, 'LEADS' => 0));
check('2i todo cero → tasas null sin división por cero', $f2['stages']['LEADS']['rate_from_prev'] === null);
// Regresión: compute debe aceptar las claves minúsculas de totals() (bug 2026-09-27:
// los stages salían 0 porque totals() usa lowercase y compute leía MAYÚSCULA).
$fl = I\WebFunnel::compute(I\WebFunnel::totals(array('sessions' => array(10), 'pageviews' => array(25), 'leads' => array(2))));
check('2j compute acepta claves minúsculas de totals()', $fl['stages']['SESIONES']['count'] === 10 && $fl['stages']['LEADS']['count'] === 2);
check('2k conversion acepta claves minúsculas', I\WebFunnel::conversion(array('sessions' => 100, 'leads' => 20)) === 20.0);

// ── 3. conversion global ───────────────────────────────────────────────────
check('3a conversion 20%', I\WebFunnel::conversion(array('SESIONES' => 100, 'LEADS' => 20)) === 20.0);
check('3b sin sesiones → null', I\WebFunnel::conversion(array('SESIONES' => 0, 'LEADS' => 5)) === null);

// ── 4. totals (puro) ───────────────────────────────────────────────────────
$t = I\WebFunnel::totals(array('sessions' => array(3, 2), 'pageviews' => array(10, 5), 'leads' => array(1, 0)));
check('4a totales suman', $t === array('sessions' => 5, 'pageviews' => 15, 'leads' => 1));

// ── 5. daily/snapshot sin DB (guards, sin datos) ──────────────────────────
$d = I\WebFunnel::daily('2026-09-01', '2026-09-03');
check('5a labels 3 días', count($d['labels']) === 3);
// Sin DB, Analytics::series_between devuelve series vacías (no inventa ceros).
check('5b sesiones alineadas o vacías sin DB', ( count($d['sessions']) === 3 && array_sum($d['sessions']) === 0 ) || count($d['sessions']) === 0);
check('5c leads alineados 0 sin DB', count($d['leads']) === 3 && array_sum($d['leads']) === 0);
$snap = I\WebFunnel::snapshot('2026-09-01', '2026-09-03');
check('5d snapshot sin DB → counts 0', array_sum($snap['counts']) === 0);
check('5e snapshot expone rango', $snap['range'] === array('2026-09-01', '2026-09-03'));

// ── Resultado ──────────────────────────────────────────────────────────────
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
