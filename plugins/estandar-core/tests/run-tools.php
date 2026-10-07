<?php
/**
 * TOOLS — Herramientas de Core (portado de estandar-inicio):
 *   1. Sanitizador de SVG por **allowlist DOM** (no blocklist regex).
 *   2. Settings por sitio (switches de SVG y child theme).
 *
 *   php tests/run-tools.php   → expect "0 failed"
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

define( 'ABSPATH', __DIR__ );
$GLOBALS['__test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $k ] : $d; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $a = null ) { $GLOBALS['__test_options'][ $k ] = $v; return true; }
}

require dirname( __DIR__ ) . '/includes/Tools.php';

use Estandar\Core\Tools;

$passed = 0; $failed = 0;
function check( $label, $ok ) { global $passed, $failed; if ( $ok ) { $passed++; echo "PASS  {$label}\n"; } else { $failed++; echo "FAIL  {$label}\n"; } }

// ── 1. SVG válido: pasa y conserva lo esencial ────────────────────────────
$valid = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="1" y="1" width="8" height="8" fill="#f00"/></svg>';
$out   = Tools::sanitize_svg( $valid );
check( '1a SVG válido devuelve string', is_string( $out ) );
check( '1b conserva <svg> y atributos', is_string( $out ) && false !== strpos( $out, '<svg' ) && false !== strpos( $out, 'viewBox' ) && false !== strpos( $out, '<rect' ) );
check( '1c conserva xmlns', is_string( $out ) && false !== strpos( $out, 'xmlns' ) );

// ── 2. <script> se elimina ────────────────────────────────────────────────
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>';
$out = Tools::sanitize_svg( $svg );
check( '2a <script> eliminado', is_string( $out ) && false === stripos( $out, '<script' ) );
check( '2b resto conservado', is_string( $out ) && false !== strpos( $out, '<rect' ) );

// ── 3. Atributos de evento (on*) se eliminan ──────────────────────────────
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" onload="alert(1)" onclick="x()"/></svg>';
$out = Tools::sanitize_svg( $svg );
check( '3a onload/onclick fuera', is_string( $out ) && false === stripos( $out, 'onload' ) && false === stripos( $out, 'onclick' ) );
check( '3b rect sigue', is_string( $out ) && false !== strpos( $out, '<rect' ) );

// ── 4. <style> (elemento) se elimina ──────────────────────────────────────
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url("http://evil.example/x.css");</style><g/></svg>';
$out = Tools::sanitize_svg( $svg );
check( '4a <style> fuera', is_string( $out ) && false === stripos( $out, '<style' ) && false === stripos( $out, '@import' ) );

// ── 5. <foreignObject> y tags no permitidos se eliminan ───────────────────
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml">x</body></foreignObject><circle r="1"/></svg>';
$out = Tools::sanitize_svg( $svg );
check( '5a <foreignObject> fuera', is_string( $out ) && false === stripos( $out, 'foreignObject' ) );
check( '5b <circle> sigue', is_string( $out ) && false !== strpos( $out, '<circle' ) );

// ── 6. href/xlink:href externos se eliminan; internos se conservan ────────
$svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="http://evil.example/x.svg#a"/><use xlink:href="#ok"/></svg>';
$out = Tools::sanitize_svg( $svg );
check( '6a href externo fuera', is_string( $out ) && false === strpos( $out, 'evil.example' ) );
check( '6b href interno conservado', is_string( $out ) && false !== strpos( $out, '#ok' ) );

// ── 7. url() externo en atributo se elimina; interno se conserva ──────────
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" fill="url(https://evil.example/f.svg#g)"/><rect width="1" height="1" fill="url(#g)"/></svg>';
$out = Tools::sanitize_svg( $svg );
check( '7a url() externo fuera', is_string( $out ) && false === strpos( $out, 'evil.example' ) );
check( '7b url() interno conservado', is_string( $out ) && false !== strpos( $out, 'url(#g)' ) );

// ── 8. javascript: en atributo se elimina ─────────────────────────────────
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" fill="javascript:alert(1)"/></svg>';
$out = Tools::sanitize_svg( $svg );
check( '8a javascript: fuera', is_string( $out ) && false === stripos( $out, 'javascript:' ) );

// ── 9. Entradas inválidas → false ─────────────────────────────────────────
check( '9a texto plano → false', false === Tools::sanitize_svg( 'hola mundo' ) );
check( '9b vacío → false', false === Tools::sanitize_svg( '' ) );
check( '9c XML malformado → false', false === Tools::sanitize_svg( '<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>' ) );

// ── 9bis. Vectores endurecidos ────────────────────────────────────────────
$bomb = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>';
check( '9d DOCTYPE/ENTITY → false (anti-XXE)', false === Tools::sanitize_svg( $bomb ) );

$svg = '<svg xmlns="http://www.w3.org/2000/svg"><set attributeName="onload" to="alert(1)"/><animate attributeName="href" to="javascript:alert(1)"/><rect width="1" height="1"/></svg>';
$out = Tools::sanitize_svg( $svg );
check( '9e <set>/<animate> eliminados', is_string( $out ) && false === stripos( $out, '<set' ) && false === stripos( $out, '<animate' ) );

$svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" style="background:url(javascript:alert(1))"/></svg>';
$out = Tools::sanitize_svg( $svg );
check( '9f style con url(javascript:) fuera', is_string( $out ) && false === stripos( $out, 'javascript:' ) );

// ── 10. Settings: defaults y guardado ─────────────────────────────────────
$GLOBALS['__test_options'] = array();
$d = Tools::defaults();
check( '10a default svg = OFF (seguridad primero)', $d['svg'] === false );
check( '10b default child_theme = ON', $d['child_theme'] === true );
check( '10c enabled() respeta defaults', Tools::enabled( 'svg' ) === false && Tools::enabled( 'child_theme' ) === true );
$saved = Tools::save( array( 'svg' => 1 ) );
check( '10d save activa svg' , $saved['svg'] === true && Tools::enabled( 'svg' ) === true );
check( '10e save desactiva child_theme si no viene', Tools::enabled( 'child_theme' ) === false );
$saved = Tools::save( array( 'child_theme' => '1' ) );
check( '10f save activa child_theme', $saved['child_theme'] === true && Tools::enabled( 'child_theme' ) === true );
check( '10g save ignora claves desconocidas', ! array_key_exists( 'hack', Tools::save( array( 'hack' => 1, 'svg' => 1 ) ) ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
