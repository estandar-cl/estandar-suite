<?php
/**
 * Contract test: meta_lead_event_id_for_response() SIN estandar-leads
 * (forms standalone, sin CAPI → no hay Server Lead con quien deduplicar).
 * El fallback vive en el SERVIDOR y valida uuid v4; el JS nunca reconstruye.
 * Corre en proceso propio: class_exists('Estandar_Leads_Ingestion') debe ser
 * false, imposible en el proceso del test de integración.
 * Run: php tests/run-meta-lead-response-standalone.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

function add_action() {}
function add_filter() {}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }

require_once dirname( __DIR__ ) . '/includes/class-forms.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}

check( 'precondición: Estandar_Leads_Ingestion NO cargada', ! class_exists( 'Estandar_Leads_Ingestion' ) );

$_POST['lead_uuid'] = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE';
check( 'standalone: fallback server-side = <uuid v4 posteado>:CAPTURED (lowercased)',
	Estandar_Forms_Handler::meta_lead_event_id_for_response() === 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee:CAPTURED' );

$_POST['lead_uuid'] = 'token-cacheado-no-uuid';
check( 'standalone: valor no-v4 → cadena vacía (browser no dispara)',
	Estandar_Forms_Handler::meta_lead_event_id_for_response() === '' );

$_POST['lead_uuid'] = '';
check( 'standalone: sin uuid posteado → cadena vacía',
	Estandar_Forms_Handler::meta_lead_event_id_for_response() === '' );

unset( $_POST['lead_uuid'] );
check( 'standalone: clave ausente del POST → cadena vacía',
	Estandar_Forms_Handler::meta_lead_event_id_for_response() === '' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
