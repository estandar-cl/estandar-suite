<?php
/**
 * ESTANDAR SUITE v2 — Core · Harness PDF REAL (S16, motor único dompdf).
 *
 * Verifica en WordPress real (Playground local) que el motor único de PDF de la
 * Suite renderiza con dompdf (copía única embebida en estandar-core) y NO cae
 * al fallback de texto: output %PDF real de longitud relevante.
 *
 * Uso: php tests/functional/pdf-real-harness.php [wp-path]
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$wp_path = rtrim( $argv[1] ?? '', '/' );
if ( $wp_path === '' ) {
	$wp_path = dirname( __DIR__, 4 ) . '/Playground';
}
if ( ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress no encontrado en $wp_path (levanta el Playground)\n" );
	exit( 2 );
}

define( 'WP_USE_THEMES', false );
require_once $wp_path . '/wp-load.php';

use Estandar\Core\Communications\Documents\Pdf as Pdf;

$passed = 0; $failed = 0;
function check( $label, $cond ) {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n"; }
}

check( 'Pdf cargada', class_exists( 'Estandar\Core\Communications\Documents\Pdf' ) );
check( 'dompdf_available resuelve la copia única embebida', Pdf::dompdf_available() );

$out = ( new Pdf() )->render( '<html><body style="font-family:Helvetica,Arial,sans-serif"><h1>Leads</h1>' .
	'<table border="1" cellpadding="3"><tr><th>Nombre</th><th>Correo</th></tr>' .
	'<tr><td>Ana</td><td>a@x.cl</td></tr></table></body></html>',
	array( 'paper' => 'letter', 'orientation' => 'portrait' ) );

check( 'render real empieza con %PDF (dompdf, no fallback texto)', strpos( $out, '%PDF' ) === 0 );
check( 'output es un PDF de longitud real (>1800 bytes, con tabla/objetos)', strlen( $out ) > 1800 );
check( 'cierra con %%EOF', strpos( $out, '%%EOF' ) !== false );
// El fallback mínimo del motor produce %PDF-1.4; dompdf produce 1.7.
check( 'usa dompdf real (cabecera %PDF-1.7)', strpos( $out, '%PDF-1.7' ) === 0 );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
