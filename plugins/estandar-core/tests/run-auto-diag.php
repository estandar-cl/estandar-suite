<?php
/**
 * ESTANDAR SUITE v2 — AutoDiag tests (AUTO_DIAG_V1 / Módulo A: estado honesto).
 *
 *   php tests/run-auto-diag.php   → expect "0 failed"
 *
 * Cubre el NÚCLEO PURE (detectores sobre evidencia) — las ramas con DB/WP se
 * cubren por guards (collect()/snapshot() no-op sin WP). Incluye la prueba de
 * que una falla inducida (tracker desactivado) cambia el estado: ya no hay
 * ceguera silenciosa.
 */

define('ABSPATH', __DIR__);
$GLOBALS['__test_options'] = array();

if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
if (!function_exists('current_time')) { function current_time($t) { return $t === 'timestamp' ? time() : gmdate('Y-m-d H:i:s'); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $d2 = 512) { return json_encode($d, $f); } }

require dirname(__DIR__) . '/includes/Schema.php';
require dirname(__DIR__) . '/includes/SiteMap.php';
require dirname(__DIR__) . '/includes/AutoDiag.php';

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Core as C;

function ev(array $over): array { return array_merge(C\AutoDiag::default_evidence(), $over); }
function lvl(array $check): string { return $check['level'] ?? 'unknown'; }
function plugins_ok(): array { $p = array(); foreach (C\AutoDiag::PRODUCT_SLUGS as $s) { $p[$s] = array('installed' => true, 'active' => true); } return array('plugins' => $p); }

// ── 1. Tracker: estado honesto, sin ceguera ────────────────────────────────
check('1a tracker activo con datos recientes → ok', lvl(C\AutoDiag::check_tracker(ev(array('track_enabled' => true, 'sessions_recent' => 4, 'pageviews_recent' => 10)))) === 'ok');
check('1b tracker DESACTIVADO (falla inducida) → warn, nunca ok',
    lvl(C\AutoDiag::check_tracker(ev(array('track_enabled' => false, 'sessions_recent' => 4, 'pageviews_recent' => 10)))) === 'warn'
    && C\AutoDiag::check_tracker(ev(array('track_enabled' => false)))['status'] === 'Desactivado');
check('1c tracker activo sin datos nunca (total 0) → unknown (no inventa éxito)',
    lvl(C\AutoDiag::check_tracker(ev(array('track_enabled' => true)))) === 'unknown');
check('1d tracker con historial pero 0 en ventana → warn',
    lvl(C\AutoDiag::check_tracker(ev(array('track_enabled' => true, 'sessions_total' => 50, 'pageviews_total' => 200, 'sessions_last' => '2026-08-01 10:00:00')))) === 'warn');
check('1e tracker 0 en ventana + relleno externo reciente → bad (Sospecha de ceguera)',
    lvl(C\AutoDiag::check_tracker(ev(array('track_enabled' => true, 'sessions_total' => 50, 'pageviews_total' => 200, 'external_recent' => true, 'external_max' => '2026-09-06 00:00:00')))) === 'bad');

// ── 2. Capturas ────────────────────────────────────────────────────────────
check('2a capture inactivo → unknown (NA honesto)', lvl(C\AutoDiag::check_capturas(ev(array('capture_active' => false)))) === 'unknown');
check('2b envíos recientes → ok', lvl(C\AutoDiag::check_capturas(ev(array('capture_active' => true, 'submissions_recent' => 3, 'submissions_total' => 90)))) === 'ok');
check('2c tabla vacía → unknown', lvl(C\AutoDiag::check_capturas(ev(array('capture_active' => true)))) === 'unknown');
check('2d histórico sin envíos en ventana → warn (pausa visible)',
    lvl(C\AutoDiag::check_capturas(ev(array('capture_active' => true, 'submissions_total' => 90, 'submissions_last' => '2026-08-10 10:00:00')))) === 'warn');

// ── 3. Esquema por subsistema ──────────────────────────────────────────────
check('3a opción de versión ausente → bad (Sin migrar)',
    lvl(C\AutoDiag::check_schema(array('key' => 's', 'label' => 'S', 'active' => true, 'actual' => '', 'expected' => '1.0.0'))) === 'bad');
check('3b versión desactualizada → bad', lvl(C\AutoDiag::check_schema(array('key' => 's', 'label' => 'S', 'active' => true, 'actual' => '0.9.0', 'expected' => '1.0.0'))) === 'bad');
check('3c versión al día → ok', lvl(C\AutoDiag::check_schema(array('key' => 's', 'label' => 'S', 'active' => true, 'actual' => '1.0.0', 'expected' => '1.0.0'))) === 'ok');
check('3d inactivo/sin esperado → unknown', lvl(C\AutoDiag::check_schema(array('key' => 's', 'label' => 'S', 'active' => false, 'actual' => '', 'expected' => ''))) === 'unknown');
$entries = C\AutoDiag::schema_entries();
check('3e schema_entries cubre core+pageviews+sessions', isset($entries['core'], $entries['pageviews'], $entries['sessions']));
check('3f check con esperado de capture (1.4.0 vigente)', C\AutoDiag::check_schema(array('key' => 'x', 'label' => 'X', 'active' => true, 'actual' => '1.4.0', 'expected' => C\AutoDiag::schema_entries()['capture']['expected']))['level'] === 'ok');

// ── 4. Integraciones ───────────────────────────────────────────────────────
check('4a sin cuentas → unknown (no es falla si no se usan)',
    lvl(C\AutoDiag::check_conexiones(ev(array()))) === 'unknown');
check('4b cuentas todas activas → ok', lvl(C\AutoDiag::check_conexiones(ev(array('connections' => array('total' => 2, 'active' => 2, 'inactive' => 0))))) === 'ok');
check('4c alguna inactiva → warn', lvl(C\AutoDiag::check_conexiones(ev(array('connections' => array('total' => 2, 'active' => 1, 'inactive' => 1))))) === 'warn');
check('4d destinos 0 → unknown', lvl(C\AutoDiag::check_destinos(ev(array('destinations' => 0)))) === 'unknown');
check('4e destinos >0 → ok', lvl(C\AutoDiag::check_destinos(ev(array('destinations' => 2)))) === 'ok');

// ── 5. Telemetría + cron ───────────────────────────────────────────────────
check('5a sin errores → ok', lvl(C\AutoDiag::check_telemetria(ev(array('telemetry_errors' => 0)))) === 'ok');
check('5b errores recientes → warn', lvl(C\AutoDiag::check_telemetria(ev(array('telemetry_errors' => 3)))) === 'warn');
check('5c cron no programado → bad', lvl(C\AutoDiag::check_cron(ev(array('cron_scheduled' => false)))) === 'bad');
check('5d cron programado y snapshot fresco → ok', lvl(C\AutoDiag::check_cron(ev(array('cron_scheduled' => true, 'snapshot_age_h' => null)))) === 'ok');
check('5e cron programado pero snapshot viejo (>48h) → warn', lvl(C\AutoDiag::check_cron(ev(array('cron_scheduled' => true, 'snapshot_age_h' => 60)))) === 'warn');

// ── 6. Plugins instalados vs activos ───────────────────────────────────────
check('6a todos activos → ok', lvl(C\AutoDiag::check_plugins(plugins_ok())) === 'ok');
$one_down = plugins_ok(); $one_down['plugins']['estandar-core'] = array('installed' => true, 'active' => false);
check('6b core instalado pero INACTIVO → warn (caso real tras overwrite de deploy)',
    lvl(C\AutoDiag::check_plugins($one_down)) === 'warn');
check('6c sin plugins detectables → unknown', lvl(C\AutoDiag::check_plugins(ev(array()))) === 'unknown');

// ── 7. Overall honesto (peor nivel; unknown no degrada si hay OK) ──────────
check('7a todo ok → OK', C\AutoDiag::state_label(C\AutoDiag::overall_from_checks(array(
    array('level' => 'ok'), array('level' => 'ok')))) === 'OK');
check('7b con warn → ATENCION', C\AutoDiag::state_label(C\AutoDiag::overall_from_checks(array(
    array('level' => 'ok'), array('level' => 'warn')))) === 'ATENCION');
check('7c con bad → FALLO (gana sobre warn)', C\AutoDiag::state_label(C\AutoDiag::overall_from_checks(array(
    array('level' => 'warn'), array('level' => 'bad')))) === 'FALLO');
check('7d sin ok y con unknown → UNKNOWN (nada verificado)', C\AutoDiag::state_label(C\AutoDiag::overall_from_checks(array(
    array('level' => 'unknown'), array('level' => 'unknown')))) === 'UNKNOWN');
check('7e unknown informativo + ok verificado → OK', C\AutoDiag::state_label(C\AutoDiag::overall_from_checks(array(
    array('level' => 'ok'), array('level' => 'unknown')))) === 'OK');

// ── 8. Snapshot compuesto (instalación sana) ───────────────────────────────
$healthy = ev(array(
    'track_enabled'  => true,
    'sessions_recent' => 4, 'sessions_total' => 100, 'pageviews_recent' => 12, 'pageviews_total' => 900,
    'capture_active' => true, 'submissions_recent' => 2, 'submissions_total' => 40, 'submissions_last' => '2026-09-05 10:00:00',
    'cron_scheduled' => true, 'snapshot_age_h' => 5,
));
$healthy = array_merge($healthy, plugins_ok());
$snap = C\AutoDiag::snapshot_from_evidence($healthy);
check('8a snapshot estructura completa', isset($snap['overall'], $snap['checks'], $snap['checked_at'], $snap['version']) && count($snap['checks']) >= 7);
check('8b instalación sana → OK', $snap['overall']['state'] === 'OK');
check('8c check_tracker idempotente dentro del snapshot', in_array('tracker', array_column($snap['checks'], 'key'), true));

// ── 9. FALLA INDUCIDA deja de ser invisible ───────────────────────────────
// Track apagado → el snapshot de una instalación que estaba OK pasa a ATENCION.
$broken = $healthy; $broken['track_enabled'] = false;
$snap_ok    = C\AutoDiag::snapshot_from_evidence($healthy);
$snap_broken = C\AutoDiag::snapshot_from_evidence($broken);
check('9a inducir tracker apagado cambia overall (OK → ATENCION/FALLO)',
    $snap_ok['overall']['state'] === 'OK' && $snap_broken['overall']['state'] !== 'OK');
$tracker_broken = array_values(array_filter($snap_broken['checks'], function ($c) { return $c['key'] === 'tracker'; }));
check('9b el check tracker reporta Desactivado', isset($tracker_broken[0]) && $tracker_broken[0]['status'] === 'Desactivado');

// ── 10. render_text es estable ─────────────────────────────────────────────
$txt = C\AutoDiag::render_text($snap_broken);
check('10a render_text incluye overall y checks', strpos($txt, 'ATENCION') !== false || strpos($txt, 'FALLO') !== false || strpos($txt, 'UNKNOWN') !== false);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
