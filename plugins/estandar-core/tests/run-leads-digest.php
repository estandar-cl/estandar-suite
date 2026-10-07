<?php
/**
 * LEAD_DIGEST_V1 — tests del resumen periódico de leads (estandar-core).
 *
 *   php tests/run-leads-digest.php   → expect "0 failed"
 *
 * Cubre el NÚCLEO PURE (ventanas diaria/semanal/mensual, criterio contactado,
 * saneo de settings, asunto, render) sin WordPress. Las ramas con DB/WP quedan
 * por guards.
 */

define( 'ABSPATH', __DIR__ );
define( 'ESTANDAR_SUITE_CORE_PATH', dirname( __DIR__ ) . '/' );

$GLOBALS['__test_options'] = array();

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { return strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9_\- ]/', '', (string) $s ) ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $t ) { return trim( (string) $t ); } }
if ( ! function_exists( 'sanitize_email' ) ) { function sanitize_email( $e ) { return filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? (string) $e : ''; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $t ) { return (string) $t; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__test_options'][ $k ] = $v; return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return $t === 'timestamp' ? time() : gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'wp_timezone' ) ) { function wp_timezone() { return new DateTimeZone( 'America/Santiago' ); } }

require dirname( __DIR__ ) . '/includes/Leads/LeadsDigest.php';

use Estandar\Core\Leads\LeadsDigest as LD;

$passed = 0; $failed = 0;
function check( $label, $cond ) { global $passed, $failed; if ( $cond ) { $passed++; echo "PASS  {$label}\n"; } else { $failed++; echo "FAIL  {$label}\n"; } }

$tz = new DateTimeZone( 'America/Santiago' );
function day( $s ) { return new DateTimeImmutable( $s . ' 09:00:00', new DateTimeZone( 'America/Santiago' ) ); }
function wkey( array $w ) { $o = array(); foreach ( $w as $x ) { $o[ $x['key'] ] = $x['from'] . '|' . $x['to']; } return $o; }

echo "── 1. Ventanas ────────────────────────────────────────────────────────\n";
$tue = wkey( LD::windows( day( '2026-09-22' ) ) ); // martes normal
check( '1a martes → solo daily (ayer 21/09)', array_keys( $tue ) === array( 'daily' ) && $tue['daily'] === '2026-09-21 00:00:00|2026-09-21 23:59:59' );

$mon = wkey( LD::windows( day( '2026-09-21' ) ) ); // lunes
check( '1b lunes → daily + weekly', isset( $mon['daily'], $mon['weekly'] ) && ! isset( $mon['monthly'] ) );
check( '1c weekly = lun 14 → dom 20', $mon['weekly'] === '2026-09-14 00:00:00|2026-09-20 23:59:59' );

$d1 = wkey( LD::windows( day( '2026-09-01' ) ) ); // martes día 1
check( '1d día 1 (martes) → daily + monthly', isset( $d1['daily'], $d1['monthly'] ) && ! isset( $d1['weekly'] ) );
check( '1e monthly = 01/08 → 31/08', $d1['monthly'] === '2026-08-01 00:00:00|2026-08-31 23:59:59' );

$tri = wkey( LD::windows( day( '2026-06-01' ) ) ); // lunes día 1
check( '1f lunes día 1 → daily + weekly + monthly', isset( $tri['daily'], $tri['weekly'], $tri['monthly'] ) );
check( '1g weekly triple = 25→31/05', $tri['weekly'] === '2026-05-25 00:00:00|2026-05-31 23:59:59' );
check( '1h monthly triple = 01→31/05', $tri['monthly'] === '2026-05-01 00:00:00|2026-05-31 23:59:59' );

echo "── 2. Contactado / pendiente ──────────────────────────────────────────\n";
check( '2a sin datos → pendiente', LD::is_contacted( array( 'status' => 'NEW' ) ) === false );
check( '2b first_contact_at → contactado', LD::is_contacted( array( 'status' => 'NEW', 'first_contact_at' => '2026-09-01 10:00:00' ) ) === true );
check( '2c contact_attempts>0 → contactado', LD::is_contacted( array( 'status' => 'NEW', 'contact_attempts' => 2 ) ) === true );
check( '2d status CONTACTED → contactado', LD::is_contacted( array( 'status' => 'CONTACTED' ) ) === true );
check( '2e status CLIENT → contactado', LD::is_contacted( array( 'status' => 'client' ) ) === true );
check( '2f first_contact_at cero → pendiente', LD::is_contacted( array( 'status' => 'NEW', 'first_contact_at' => '0000-00-00 00:00:00' ) ) === false );

echo "── 3. Settings (opt-in) ───────────────────────────────────────────────\n";
$clean = LD::save_settings( array(
	'Parronales' => array( 'enabled' => 1, 'extra_cc' => ' jefe@x.cl ' ),
	''           => array( 'enabled' => 1 ),
	'altacruz'   => array( 'enabled' => 0 ),
) );
check( '3a slug normalizado', isset( $clean['parronales'] ) );
check( '3b enabled=1', (int) $clean['parronales']['enabled'] === 1 );
check( '3c cc recortado', $clean['parronales']['extra_cc'] === 'jefe@x.cl' );
check( '3d slug vacío descartado', ! isset( $clean[''] ) );
check( '3e enabled=0 se conserva', (int) $clean['altacruz']['enabled'] === 0 );

$GLOBALS['__test_options']['estandar_suite_core_leads_digest_settings'] = $clean;
check( '3f enabled_projects solo con enabled=1', LD::enabled_projects() === array( 'parronales' ) );

echo "── 4. Asunto ──────────────────────────────────────────────────────────\n";
check( '4a solo daily', LD::subject_suffix( array( array( 'key' => 'daily' ) ) ) === 'de ayer' );
check( '4b daily+weekly', LD::subject_suffix( array( array( 'key' => 'daily' ), array( 'key' => 'weekly' ) ) ) === 'de ayer + semanal' );
check( '4c daily+monthly', LD::subject_suffix( array( array( 'key' => 'daily' ), array( 'key' => 'monthly' ) ) ) === 'de ayer + mensual' );
check( '4d triple', LD::subject_suffix( array( array( 'key' => 'daily' ), array( 'key' => 'weekly' ), array( 'key' => 'monthly' ) ) ) === 'de ayer + semanal + mensual' );

echo "── 5. Render ──────────────────────────────────────────────────────────\n";
$sections = array(
	array( 'key' => 'daily', 'label' => 'Ayer', 'from' => '2026-09-21 00:00:00', 'to' => '2026-09-21 23:59:59', 'rows' => array(
		array( 'name' => 'Ana Pérez', 'email' => 'ana@x.cl', 'phone' => '+56911111111', 'status' => 'NEW', 'contacted' => false, 'repeat_count' => 3, 'contact_attempts' => 2, 'created_at' => '2026-09-21 12:30:00' ),
		array( 'name' => 'Beto Soto', 'email' => 'beto@x.cl', 'phone' => '', 'status' => 'CONTACTED', 'contacted' => true, 'repeat_count' => 1, 'contact_attempts' => 0, 'created_at' => '2026-09-21 09:10:00' ),
	) ),
);
$html = LD::render_email( 'parronales', $sections, array( 'brand_color' => '#931917', 'site_name' => 'Acacios' ) );
check( '5a título con día del lead', strpos( $html, 'Interesados en Parronales el día 21 de septiembre' ) !== false );
check( '5b contiene nombre', strpos( $html, 'Ana Pérez' ) !== false );
check( '5c cuenta total de ingresos + llamas', strpos( $html, 'Dejó sus datos 3×🔥🔥🔥' ) !== false );
check( '5c2 2× → 2 llamas', strpos( LD::render_email( 'x', array( array( 'key' => 'daily', 'from' => '2026-09-21 00:00:00', 'to' => '2026-09-21 23:59:59', 'rows' => array( array( 'name' => 'Z', 'repeat_count' => 2, 'contacted' => false, 'email' => '', 'phone' => '' ) ) ) ), array() ), 'Dejó sus datos 2×🔥🔥' ) !== false );
check( '5c3 1× sin llamas', strpos( $html, 'Dejó sus datos 1 vez' ) !== false );
check( '5d ambos leads muestran conteo', substr_count( $html, 'Dejó sus datos' ) === 2 );
check( '5d2 sin "intentos de contacto"', strpos( $html, 'intentos de contacto' ) === false );
check( '5e marca pendiente ○ y contactado ✔', strpos( $html, '○' ) !== false && strpos( $html, '✔' ) !== false );
check( '5f label del proyecto', strpos( $html, 'Parronales' ) !== false );
check( '5g icono correo (mailto)', strpos( $html, 'mailto:ana@x.cl' ) !== false );
check( '5h icono WhatsApp (wa.me)', strpos( $html, 'https://wa.me/56911111111' ) !== false );
check( '5i solo 1 WhatsApp (Beto no dejó teléfono)', substr_count( $html, 'wa.me/' ) === 1 );

$htmlW = LD::render_email( 'altacruz', array( array( 'key' => 'weekly', 'from' => '2026-09-14 00:00:00', 'to' => '2026-09-20 23:59:59', 'rows' => array() ) ), array() );
check( '5j título semanal', strpos( $htmlW, 'Interesados en Altacruz la semana del 14/09 al 20/09' ) !== false );
check( '5k sin filas → "Sin leads."', strpos( $htmlW, 'Sin leads.' ) !== false );

echo "── 6. Guard anti-duplicado ────────────────────────────────────────────\n";
$today = ( new DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
$GLOBALS['__test_options']['estandar_suite_core_leads_digest_last_run'] = $today;
$run = LD::run( false );
check( '6a ya corrió hoy → ran=false', $run['ran'] === false && $run['reason'] === 'already_ran_today' );
$GLOBALS['__test_options']['estandar_suite_core_leads_digest_last_run'] = '2000-01-01';
$run2 = LD::run( false );
check( '6b otro día → ran=true', $run2['ran'] === true );

echo "── 7. Sin WP/DB no rompe ──────────────────────────────────────────────\n";
check( '7a leads_in_window sin wpdb → []', LD::leads_in_window( 'parronales', '2026-09-01 00:00:00', '2026-09-01 23:59:59' ) === array() );
check( '7b projects_with_leads sin wpdb → []', LD::projects_with_leads() === array() );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
