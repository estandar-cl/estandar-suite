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

if (!function_exists('esc_html')) { function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('number_format_i18n')) { function number_format_i18n($n, $d = 0) { return number_format((float) $n, (int) $d, ',', '.'); } }

require dirname(__DIR__) . '/includes/Events.php';
require dirname(__DIR__) . '/includes/Funnel.php';
require dirname(__DIR__) . '/includes/Visual.php';
require dirname(__DIR__) . '/includes/Adoption.php';
require dirname(__DIR__) . '/includes/Analytics.php';

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

// ── 6. Analytics inheritance (mirror mapping + guards) ─────────────────────
check('6a mirror conocido', I\Analytics::is_mirror('sessions'));
check('6b mirror desconocido', ! I\Analytics::is_mirror('bogus'));
check('6c mirror normalize', I\Analytics::is_mirror('SESSIONS'));
check('6d legacy_table mapea', I\Analytics::legacy_table('events') !== '');
check('6e mirror_table prefijo v2', strpos(I\Analytics::mirror_table('sessions'), 'estandar_insights_analytics_') !== false);
check('6f import no-op sin DB', I\Analytics::import(true, 5)['sessions']['dry_run'] === true);
check('6g rollback no-op sin DB', I\Analytics::rollback() === 0);
check('6h status vacío sin DB', I\Analytics::status()['sessions']['legacy'] === -1);
check('6i rows vacío sin DB', I\Analytics::rows('sessions') === array());
check('6j count 0 sin DB', I\Analytics::count('sessions') === 0);
check('6k group vacío sin DB', I\Analytics::group('sessions', 'source') === array());
check('6l source_table vacío sin DB', I\Analytics::source_table('sessions') === '');

// ── X. Visual::funnel_svg (embudo gráfico SVG, PURE) ──────────────────────
$rows = array(
	array( 'label' => 'NEW', 'count' => 10, 'rate' => null ),
	array( 'label' => 'CONTACTED', 'count' => 5, 'rate' => 50.0 ),
	array( 'label' => 'QUALIFIED', 'count' => 0, 'rate' => 0.0, 'bottleneck' => true ),
);
$svg = \Estandar\Insights\Visual::funnel_svg( $rows );
check('X1 embudo vacío → mensaje honesto', strpos( \Estandar\Insights\Visual::funnel_svg( array() ), 'Sin datos' ) !== false );
check('X2 svg con trapecios', strpos( $svg, '<polygon' ) !== false );
check('X3 etiquetas visibles', strpos( $svg, 'CONTACTED' ) !== false );
check('X4 conversión visible', strpos( $svg, 'conv.' ) !== false );
check('X5 cuello marcado', strpos( $svg, 'cuello' ) !== false );
check('X6 unidad opcional', strpos( \Estandar\Insights\Visual::funnel_svg( $rows, 'registros' ), 'registros' ) !== false );
$rs = \Estandar\Insights\Visual::rows_from_stages( array( 'NEW' => array( 'count' => 3, 'rate_from_prev' => null ) ) );
check('X7 rows_from_stages mapea count/rate/label', $rs[0]['label'] === 'NEW' && $rs[0]['count'] === 3 );
check('X8 rows_from_stages no marca cuello por defecto', $rs[0]['bottleneck'] === false );

// ── Y. Visual::funnel_svg (embudo gráfico SVG, PURE) ──────────────────────
$rows2 = array(
	array( 'label' => 'Sesiones', 'count' => 1800, 'rate' => null ),
	array( 'label' => 'Páginas vistas', 'count' => 3500, 'rate' => 100.0 ),
	array( 'label' => 'Leads', 'count' => 90, 'rate' => 2.6, 'bottleneck' => true ),
);
$fsvg = \Estandar\Insights\Visual::funnel_svg( $rows2 );
check('Y1 vacío → mensaje honesto', strpos( \Estandar\Insights\Visual::funnel_svg( array() ), 'Sin datos' ) !== false );
check('Y2 genera SVG', strpos( $fsvg, '<svg' ) !== false && strpos( $fsvg, '</svg>' ) !== false );
check('Y3 usa trapecios (polygon)', substr_count( $fsvg, '<polygon' ) === 3 );
check('Y4 etiqueta visible', strpos( $fsvg, 'Sesiones' ) !== false );
check('Y5 conteo formateado', strpos( $fsvg, '1.800' ) !== false );
check('Y6 conversión del paso', strpos( $fsvg, 'conv. 2,6%' ) !== false );
check('Y7 cuello marcado', strpos( $fsvg, 'cuello' ) !== false );
check('Y8 unidad opcional', strpos( \Estandar\Insights\Visual::funnel_svg( $rows2, 'registros' ), 'registros' ) !== false );
// El ancho es proporcional: el trapecio del medio (3500) es más ancho que el de leads (90).
preg_match_all( '/polygon points="([^"]+)"/', $fsvg, $m );
$w = static function ( $pts ) { $xy = explode( ' ', $pts ); $a = explode( ',', $xy[0] ); $b = explode( ',', $xy[1] ); return abs( (float) $b[0] - (float) $a[0] ); };
check('Y9 ancho proporcional al volumen', count( $m[1] ) === 3 && $w( $m[1][1] ) > $w( $m[1][2] ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
