<?php
/**
 * SEO AUDIT — tests de las partes PURAS (parseo HTML + puntuación).
 * No usa HTTP ni DB.
 *
 *   php tests/run-seo-audit.php   → expect "0 failed"
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

define( 'ABSPATH', __DIR__ );

if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = null ) { return $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { return true; } }

require dirname( __DIR__ ) . '/includes/SeoAudit.php';

use Estandar\Insights\SeoAudit;

$passed = 0; $failed = 0;
function check( $label, $ok ) { global $passed, $failed; if ( $ok ) { $passed++; echo "PASS  {$label}\n"; } else { $failed++; echo "FAIL  {$label}\n"; } }

// ── 1. parse_html: página correcta ────────────────────────────────────────
$good = '<!doctype html><html lang="es"><head>'
	. '<title>Compra de terrenos en la Región de O\'Higgins | Acacios Inmobiliaria</title>'
	. '<meta name="description" content="Proyectos de parcelas y terrenos con financiamiento directo en la Región de O\'Higgins, Chile. Agenda tu visita hoy mismo con un ejecutivo.">'
	. '<link rel="canonical" href="https://ejemplo.cl/proyectos">'
	. '<meta name="robots" content="index,follow">'
	. '<script type="application/ld+json">{"@type":"Organization"}</script>'
	. '</head><body><h1>Proyectos</h1><h2>Terrenos</h2>'
	. '<img src="a.jpg" alt="parcela"><img src="b.jpg" alt="">'
	. '<p>' . str_repeat( 'palabra ', 260 ) . '</p></body></html>';

$f = SeoAudit::parse_html( $good );
check( '1a title extraído', strpos( $f['title'], 'Compra de terrenos' ) === 0 );
check( '1b meta description extraída', strpos( $f['meta_description'], 'parcelas' ) !== false );
check( '1c canonical extraído', $f['canonical'] === 'https://ejemplo.cl/proyectos' );
check( '1d robots leído', $f['robots'] === 'index,follow' );
check( '1e H1=1 y H2=1', $f['h1'] === 1 && $f['h2'] === 1 );
check( '1f imágenes 2, con alt 1', $f['img_total'] === 2 && $f['img_alt'] === 1 );
check( '1g JSON-LD detectado', $f['jsonld'] === 1 );
check( '1h lang detectado', $f['lang'] === 'es' );
check( '1i conteo de palabras > 200', $f['words'] > 200 );

$score = SeoAudit::score_page( $f );
check( '1j buena página puntúa alto (>=85)', $score['score'] >= 85 );
check( '1k marca el alt faltante', in_array( 'Imágenes sin alt: 50% con alt', $score['issues'], true ) );

// ── 2. parse_html: entrada vacía / no-HTML ────────────────────────────────
check( '2a vacío → hechos neutros', SeoAudit::parse_html( '' )['title'] === '' && SeoAudit::parse_html( '' )['h1'] === 0 );
check( '2b no-HTML → hechos neutros', SeoAudit::parse_html( 'hola' )['words'] === 0 );

// ── 3. score_page: página mala ────────────────────────────────────────────
$bad = SeoAudit::score_page( array() );
check( '3a página vacía → score bajo (<=30)', $bad['score'] > 0 && $bad['score'] <= 30 );
check( '3b incluye "Falta <title>"', in_array( 'Falta <title>', $bad['issues'], true ) );
check( '3c incluye "Sin H1"', in_array( 'Sin H1', $bad['issues'], true ) );
check( '3d incluye falta meta description', in_array( 'Falta meta description', $bad['issues'], true ) );

// ── 4. Reglas específicas ─────────────────────────────────────────────────
$noindex = SeoAudit::score_page( array( 'robots' => 'noindex,nofollow', 'title' => str_repeat( 'x', 45 ), 'h1' => 1, 'h2' => 1, 'words' => 300, 'jsonld' => 1, 'lang' => 'es' ) );
check( '4a noindex penaliza fuerte (<=75)', $noindex['score'] <= 75 && in_array( 'Marcada como noindex', $noindex['issues'], true ) );

$twoh1 = SeoAudit::score_page( array( 'h1' => 2, 'title' => str_repeat( 'x', 45 ), 'h2' => 1, 'words' => 300, 'jsonld' => 1, 'lang' => 'es' ) );
check( '4b varios H1 se marca', in_array( 'Varios H1: 2', $twoh1['issues'], true ) );

$short = SeoAudit::score_page( array( 'title' => str_repeat( 'x', 45 ), 'h1' => 1, 'h2' => 1, 'words' => 90, 'jsonld' => 1, 'lang' => 'es' ) );
check( '4c contenido corto se marca', in_array( 'Contenido corto: 90 palabras', $short['issues'], true ) );

$noalt = SeoAudit::score_page( array( 'title' => str_repeat( 'x', 45 ), 'h1' => 1, 'h2' => 1, 'words' => 300, 'jsonld' => 1, 'lang' => 'es', 'img_total' => 10, 'img_alt' => 2 ) );
check( '4d alt <60% se marca', in_array( 'Imágenes sin alt: 20% con alt', $noalt['issues'], true ) );

$long = SeoAudit::score_page( array( 'title' => str_repeat( 'x', 120 ), 'h1' => 1, 'h2' => 1, 'words' => 300, 'jsonld' => 1, 'lang' => 'es' ) );
check( '4e title largo se marca', in_array( 'Title largo: 120 car.', $long['issues'], true ) );

$nolang = SeoAudit::score_page( array( 'title' => str_repeat( 'x', 45 ), 'h1' => 1, 'h2' => 1, 'words' => 300, 'jsonld' => 1 ) );
check( '4f sin lang se marca', in_array( 'Sin atributo lang', $nolang['issues'], true ) );

check( '4g score siempre en 0-100', $bad['score'] >= 0 && $score['score'] <= 100 );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
