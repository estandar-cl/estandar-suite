<?php
/**
 * F2 contract test: first_ip_address / first_user_agent en ingestion.
 * Sin WordPress. Run: php tests/run-first-capture-network-write-once.php
 *
 * Verifica:
 *   1. Helpers: REMOTE_ADDR validado (FILTER_VALIDATE_IP), X-Forwarded-For
 *      ignorado, UA sanitizado y truncado a 255.
 *   2. Write-once estructural: los campos solo existen en el INSERT de
 *      creación inicial — jamás en la rama de recontacto/update ni en el
 *      backfill de schema.
 */

define( 'ABSPATH', __DIR__ );

// Stub mínimo WP (mimetiza sanitize_text_field: strip tags + colapsa whitespace).
function sanitize_text_field( $str ) {
	$str = strip_tags( (string) $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}

require_once dirname( __DIR__ ) . '/includes/class-estandar-leads-ingestion.php';

$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}

function call_private( $method ) {
	$ref = new ReflectionMethod( 'Estandar_Leads_Ingestion', $method );
	return $ref->invoke( null );
}

// ── 1. first_capture_ip ──────────────────────────────────────────────────────

$_SERVER['REMOTE_ADDR'] = '190.161.10.20';
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
check( 'IPv4 válida → se conserva', call_private( 'first_capture_ip' ) === '190.161.10.20' );

$_SERVER['REMOTE_ADDR'] = '2001:db8::8a2e:370:7334';
check( 'IPv6 válida → se conserva', call_private( 'first_capture_ip' ) === '2001:db8::8a2e:370:7334' );

$_SERVER['REMOTE_ADDR'] = '999.999.999.999';
check( 'IP inválida → cadena vacía', call_private( 'first_capture_ip' ) === '' );

$_SERVER['REMOTE_ADDR'] = "190.161.10.20' OR 1=1";
check( 'IP con inyección → cadena vacía', call_private( 'first_capture_ip' ) === '' );

unset( $_SERVER['REMOTE_ADDR'] );
check( 'REMOTE_ADDR ausente → cadena vacía', call_private( 'first_capture_ip' ) === '' );

$_SERVER['REMOTE_ADDR'] = '10.0.0.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77';
check( 'X-Forwarded-For IGNORADO (usa REMOTE_ADDR)', call_private( 'first_capture_ip' ) === '10.0.0.9' );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

// ── 2. first_capture_user_agent ──────────────────────────────────────────────

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X)';
check( 'UA normal → se conserva', call_private( 'first_capture_user_agent' ) === 'Mozilla/5.0 (Macintosh; Intel Mac OS X)' );

$_SERVER['HTTP_USER_AGENT'] = str_repeat( 'A', 300 );
check( 'UA de 300 chars → truncado a 255', strlen( call_private( 'first_capture_user_agent' ) ) === 255 );

$_SERVER['HTTP_USER_AGENT'] = "Mozilla<script>alert(1)</script>\n5.0";
$ua = call_private( 'first_capture_user_agent' );
check( 'UA con tags/newline → sanitizado', strpos( $ua, '<' ) === false && strpos( $ua, "\n" ) === false );

unset( $_SERVER['HTTP_USER_AGENT'] );
check( 'HTTP_USER_AGENT ausente → cadena vacía', call_private( 'first_capture_user_agent' ) === '' );

// ── 3. Write-once estructural (regresión: jamás en recontacto/update) ────────

$ingestion_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-leads-ingestion.php' );
$schema_src    = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-leads-schema.php' );

check( "ingestion: 'first_ip_address =>' aparece exactamente 1 vez (solo INSERT)",
	substr_count( $ingestion_src, "'first_ip_address' =>" ) === 1 );
check( "ingestion: 'first_user_agent =>' aparece exactamente 1 vez (solo INSERT)",
	substr_count( $ingestion_src, "'first_user_agent' =>" ) === 1 );

// El único write está ANTES de la rama de recontacto (marcador T2).
$recontact_pos = strpos( $ingestion_src, 'T2: read operator fields' );
check( 'ingestion: write ocurre antes de la rama de recontacto',
	$recontact_pos !== false
	&& strpos( $ingestion_src, "'first_ip_address' =>" ) < $recontact_pos
	&& strpos( $ingestion_src, "'first_user_agent' =>" ) < $recontact_pos );

check( 'schema: columna first_ip_address definida (varchar 64)',
	strpos( $schema_src, "first_ip_address varchar(64)" ) !== false );
check( 'schema: columna first_user_agent definida (varchar 255)',
	strpos( $schema_src, "first_user_agent varchar(255)" ) !== false );
check( 'schema: campos FUERA del backfill (solo CREATE TABLE, 1 mención c/u)',
	substr_count( $schema_src, 'first_ip_address' ) === 1
	&& substr_count( $schema_src, 'first_user_agent' ) === 1 );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
