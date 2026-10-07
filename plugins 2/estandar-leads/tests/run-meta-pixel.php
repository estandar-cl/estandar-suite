<?php
/**
 * Contract test: Estandar_Leads_Meta_Pixel (Browser Pixel).
 * Cubre: gates (enabled/browser_events/pixel_id) · nunca imprime desactivado ·
 * una sola inicialización por request · PageView/ViewContent sin duplicar ·
 * sin token en el markup. Stubs de options/escapers — sin WP. Run:
 *   php tests/run-meta-pixel.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['__opts']     = array();
$GLOBALS['__singular'] = false;

function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function is_admin() { return false; }
function is_singular() { return $GLOBALS['__singular']; }
function get_the_title() { return "Depto Piloto 'Altacruz'"; }
function esc_js( $s ) { return str_replace( array( '\\', "'", '"' ), array( '\\\\', "\\'", '\\"' ), (string) $s ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__hooks'][ $hook ][] = $cb; }

require_once dirname( __DIR__ ) . '/includes/class-estandar-leads-meta-pixel.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}
function pixel_output() {
	ob_start();
	Estandar_Leads_Meta_Pixel::print_pixel();
	return (string) ob_get_clean();
}
function reset_printed() {
	// El guard es estado por-request; los tests simulan requests con reflection.
	$ref = new ReflectionProperty( 'Estandar_Leads_Meta_Pixel', 'printed' );
	$ref->setValue( null, false );
}
function opts( array $o ) { $GLOBALS['__opts'] = $o; }
const BASE = array(
	'estandar_leads_meta_pixel_id'       => '123456789012345',
	'estandar_leads_meta_access_token'   => 'SECRET-TOKEN-XYZ',
	'estandar_leads_meta_browser_events' => '1',
);

// ── 1. Gates: nunca imprimir si está desactivado ─────────────────────────────
reset_printed();
opts( array_merge( BASE, array( 'estandar_leads_meta_browser_events' => '0' ) ) );
check( 'browser_events=0 → is_active()=false', ! Estandar_Leads_Meta_Pixel::is_active() );
check( 'browser_events=0 → salida vacía (ni un byte)', pixel_output() === '' );

reset_printed();
opts( array_merge( BASE, array( 'estandar_leads_meta_browser_events' => '' ) ) );
check( "browser_events '' (default) → NO imprime (opt-in explícito)", pixel_output() === '' );

reset_printed();
opts( array_merge( BASE, array( 'estandar_leads_meta_enabled' => '0' ) ) );
check( 'enabled=0 (maestro) → NO imprime aunque browser_events=1', pixel_output() === '' );

reset_printed();
opts( array_merge( BASE, array( 'estandar_leads_meta_pixel_id' => '' ) ) );
check( 'sin pixel_id → NO imprime', pixel_output() === '' );

// ── 2. Activo: init + PageView una sola vez ──────────────────────────────────
reset_printed();
opts( BASE );
$GLOBALS['__singular'] = false;
$out = pixel_output();
check( 'activo → imprime base code', $out !== '' );
check( "fbq('init') exactamente 1 vez", substr_count( $out, "fbq('init'" ) === 1 );
check( 'init usa el Pixel ID configurado', strpos( $out, "fbq('init', '123456789012345')" ) !== false );
check( "PageView exactamente 1 vez", substr_count( $out, "fbq('track', 'PageView')" ) === 1 );
check( 'página no-singular → sin ViewContent', strpos( $out, 'ViewContent' ) === false );
check( 'noscript fallback presente con el Pixel ID', strpos( $out, 'facebook.com/tr?id=123456789012345' ) !== false );
check( 'markup sin access token', strpos( $out, 'SECRET-TOKEN-XYZ' ) === false );

// ── 3. Guard de idempotencia: segunda llamada en el mismo request = nada ────
$second = pixel_output(); // sin reset_printed
check( 'segunda llamada mismo request → 0 bytes (sin doble init/PageView)', $second === '' );

// ── 4. ViewContent solo en singular, una vez, con título escapado ───────────
reset_printed();
$GLOBALS['__singular'] = true;
$out = pixel_output();
check( 'singular → ViewContent exactamente 1 vez', substr_count( $out, "fbq('track', 'ViewContent'" ) === 1 );
check( 'singular → PageView sigue siendo 1 (no duplica)', substr_count( $out, "fbq('track', 'PageView')" ) === 1 );
check( 'content_name con comilla escapada (esc_js)', strpos( $out, "Depto Piloto \\'Altacruz\\'" ) !== false );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
