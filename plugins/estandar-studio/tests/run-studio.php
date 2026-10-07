<?php
/**
 * estandar-studio — tests (Playground).
 *
 *   php plugins/estandar-studio/tests/run-studio.php   → expect "0 failed"
 *
 * Cubre: Render::resolve (marcadores), Pages::sanitize_slug, y el roundtrip de
 * almacenamiento (upsert post + bundle en uploads).
 */

require dirname( __DIR__, 3 ) . '/Playground/wp-load.php';

if ( ! class_exists( '\Estandar\Studio\Pages' ) ) {
	fwrite( STDERR, "run-studio: clase Pages no cargada — ¿estandar-studio activo en Playground?\n" );
	exit( 1 );
}

use Estandar\Studio\Pages;
use Estandar\Studio\Render;

$passed = 0;
$failed = 0;
function st_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

// ── Render::resolve (marcadores) ────────────────────────────────────────
$ctx = array(
	'do_shortcode' => function ( $sc ) { return 'SC' . $sc; },
	'home'         => 'https://demo.cl/',
);

st_check( 'form simple', Render::resolve( '{{form:7}}', $ctx ) === 'SC[estandar_form_v2 id="7"]' );
st_check( 'form con args', Render::resolve( '{{form:7 preset_city="temuco"}}', $ctx ) === 'SC[estandar_form_v2 id="7" preset_city="temuco"]' );
st_check( 'chatbot', Render::resolve( '{{chatbot}}', $ctx ) === 'SC[estandar_chatbot]' );
st_check( 'shortcode genérico', Render::resolve( '{{shortcode:[estandar_x a="1"]}}', $ctx ) === 'SC[estandar_x a="1"]' );
st_check( 'shortcode sin corchetes', Render::resolve( '{{shortcode:estandar_y}}', $ctx ) === 'SC[estandar_y]' );
st_check( 'wa dígitos', strpos( Render::resolve( '{{wa:56912345678}}', $ctx ), 'https://wa.me/56912345678' ) !== false );
st_check( 'wa con texto y formato', strpos( Render::resolve( '{{wa:+56 9 1234 5678|Hola}}', $ctx ), 'https://wa.me/56912345678?text=Hola' ) !== false );
st_check( 'home', Render::resolve( '{{home}}', $ctx ) === 'https://demo.cl/' );
st_check( 'texto sin marcadores intacto', Render::resolve( '<h1>Hola</h1>', $ctx ) === '<h1>Hola</h1>' );
st_check( 'dos form en una página', substr_count( Render::resolve( '{{form:1}} x {{form:2}}', $ctx ), 'SC[estandar_form_v2' ) === 2 );

// ── Pages::sanitize_slug ────────────────────────────────────────────────
st_check( 'slug normaliza acentos', Pages::sanitize_slug( 'Mi Página' ) === 'mi-pagina' );
st_check( 'slug solo ASCII', (bool) preg_match( '/^[a-z0-9\-]+$/', Pages::sanitize_slug( 'María José <>' ) ) );
st_check( 'slug vacío → pagina', Pages::sanitize_slug( '///' ) === 'pagina' );

// ── Roundtrip de almacenamiento ─────────────────────────────────────────
$slug = 'poc-studio-test';
$id   = Pages::upsert( $slug, array(
	'title'  => 'PoC Studio Test',
	'status' => 'publish',
	'html'   => '<section class="hero"><h1>Hola {{form:7}}</h1></section>',
	'css'    => '.hero{color:red}',
	'js'     => 'console.log("studio");',
) );
st_check( 'upsert devuelve ID', is_int( $id ) && $id > 0 );

$b = Pages::read( $slug );
st_check( 'bundle html', strpos( $b['html'], 'Hola {{form:7}}' ) !== false );
st_check( 'bundle css', $b['css'] === '.hero{color:red}' );
st_check( 'bundle js', $b['js'] === 'console.log("studio");' );
st_check( 'post creado y resoluble', Pages::post_for_slug( $slug ) instanceof WP_Post );
st_check( 'permite get_permalink', is_string( get_permalink( Pages::post_for_slug( $slug ) ) ) );

// limpieza
$post = Pages::post_for_slug( $slug );
if ( $post ) { wp_delete_post( $post->ID, true ); }
$dir = Pages::bundle_dir( $slug );
if ( is_dir( $dir ) ) {
	foreach ( array( 'index.html', 'style.css', 'app.js' ) as $f ) {
		if ( is_file( "$dir/$f" ) ) { @unlink( "$dir/$f" ); }
	}
	@rmdir( $dir );
}
st_check( 'limpieza ok', ! Pages::post_for_slug( $slug ) );

// ── FRONT_PAGE_ROOT_V1: portada Studio servida en `/` ───────────────────
$orig_front = (int) get_option( 'page_on_front', 0 );

$fp_slug = 'poc-front-page';
$fp_id   = Pages::upsert( $fp_slug, array( 'title' => 'PoC Front', 'status' => 'publish', 'html' => '<section id="poc-home"><h1>HOME POC</h1></section>' ) );
$fp_id   = (int) $fp_id;
update_option( 'page_on_front', $fp_id );

st_check( 'portada Studio detectada', Render::is_studio_front_page() === true );

// is_root_request según REQUEST_URI (home del Playground = /)
$orig_uri = $_SERVER['REQUEST_URI'] ?? null;
$_SERVER['REQUEST_URI'] = '/';
st_check( 'raíz detectada', Render::is_root_request() === true );
$_SERVER['REQUEST_URI'] = '/?utm=1';
st_check( 'raíz con query detectada', Render::is_root_request() === true );
$_SERVER['REQUEST_URI'] = '/otra/';
st_check( 'ruta interna no es raíz', Render::is_root_request() === false );
$_SERVER['REQUEST_URI'] = '/estudio/poc-front-page/';
st_check( 'permalink no es raíz', Render::is_root_request() === false );
st_check( 'permalink propio detectado (301)', Render::is_front_permalink_request( $fp_id ) === true );
$_SERVER['REQUEST_URI'] = '/otra/';
st_check( 'otra ruta no es permalink propio', Render::is_front_permalink_request( $fp_id ) === false );
$_SERVER['REQUEST_URI'] = '/';
st_check( 'raíz no es permalink propio', Render::is_front_permalink_request( $fp_id ) === false );
if ( $orig_uri !== null ) { $_SERVER['REQUEST_URI'] = $orig_uri; } else { unset( $_SERVER['REQUEST_URI'] ); }

// document($id) renderiza el bundle de la página explícita (sin query object)
$doc = Render::document( $fp_id );
st_check( 'document(id) renderiza el bundle', strpos( $doc, 'HOME POC' ) !== false );

// Portada que NO es Studio: no se intercepta.
$plain_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Portada Normal', 'post_status' => 'publish' ) );
$plain_id = (int) $plain_id;
update_option( 'page_on_front', $plain_id );
st_check( 'portada no-Studio no se toca', Render::is_studio_front_page() === false );

update_option( 'page_on_front', $orig_front );
$fp_post = Pages::post_for_slug( $fp_slug );
if ( $fp_post ) { wp_delete_post( $fp_post->ID, true ); }
if ( $plain_id ) { wp_delete_post( $plain_id, true ); }
$fp_dir = Pages::bundle_dir( $fp_slug );
if ( is_dir( $fp_dir ) ) {
	foreach ( array( 'index.html', 'style.css', 'app.js' ) as $f ) { if ( is_file( "$fp_dir/$f" ) ) { @unlink( "$fp_dir/$f" ); } }
	@rmdir( $fp_dir );
}
st_check( 'front page: limpieza ok', ! Pages::post_for_slug( $fp_slug ) && (int) get_option( 'page_on_front' ) === $orig_front );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
