<?php
/**
 * Integration test (cross-plugin, clases REALES): la respuesta AJAX de forms
 * entrega al browser el MISMO event_id de captura que usará el Lead de CAPI.
 *
 * Seam cubierto: Estandar_Forms_Handler::redirect_with_feedback()
 *   → Estandar_Leads_Ingestion::last_ingested()   (uuid canónico persistido)
 *   → Estandar_Leads_Meta_Adapter::capture_event_id()
 *   → payload JSON meta_lead_event_id → consumido por trackMetaLead (Node suite).
 *
 * Run: php tests/run-meta-lead-response.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['__json'] = null;
class JsonSent extends Exception {}
function add_action() {}
function add_filter() {}
function do_action() {}
function get_option( $k, $d = false ) { return $d; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function wp_generate_uuid4() { return 'ffffffff-0000-4000-8000-000000000000'; }
function remove_accents( $s ) { return $s; }
function wp_send_json_success( $data ) { $GLOBALS['__json'] = array( 'success' => true, 'data' => $data ); throw new JsonSent(); }
function wp_send_json_error( $data, $code = 0 ) { $GLOBALS['__json'] = array( 'success' => false, 'data' => $data ); throw new JsonSent(); }

// ── Clases REALES de ambos plugins ───────────────────────────────────────────
$leads = dirname( __DIR__, 2 ) . '/estandar-leads/includes/';
require_once $leads . 'class-estandar-leads-ingestion.php';
require_once $leads . 'class-estandar-leads-meta-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-forms.php'; // ejecuta init() → add_action stub

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}

// Simula el estado post-persistencia que capture() deja tras COMMIT.
function set_last_ingested( $value ) {
	$ref = new ReflectionProperty( 'Estandar_Leads_Ingestion', 'last_ingested' );
	$ref->setValue( null, $value );
}
function respond( $status, $recontact_context = array() ) {
	$GLOBALS['__json'] = null;
	$m = new ReflectionMethod( 'Estandar_Forms_Handler', 'redirect_with_feedback' );
	try {
		$m->invoke( null, '/gracias', 7, $status, array( 'Mensaje.' ), array(), array(), $recontact_context );
	} catch ( JsonSent $e ) {
		// respuesta capturada
	}
	return $GLOBALS['__json'];
}
$_POST['estandar_ajax'] = '1'; // fuerza la rama AJAX (la que consume el JS)

// El uuid CANÓNICO difiere del que el navegador posteó (regeneración
// anti-colisión / recontacto) — exactamente el caso que obliga a que el
// event_id venga del servidor y no se reconstruya en el cliente.
$canonical = '99999999-8888-4777-8666-555555555555';

// ── 1. success → la respuesta entrega el event_id del lead PERSISTIDO ───────
set_last_ingested( array( 'lead_id' => 87, 'lead_uuid' => $canonical ) );
$r = respond( 'success' );
check( 'success → respuesta success=true', $r['success'] === true );
check( 'meta_lead_event_id presente en data', isset( $r['data']['meta_lead_event_id'] ) );
check( 'meta_lead_event_id = <uuid canónico>:CAPTURED', $r['data']['meta_lead_event_id'] === $canonical . ':CAPTURED' );
check( 'misma derivación que usará el worker CAPI (capture_event_id)',
	$r['data']['meta_lead_event_id'] === Estandar_Leads_Meta_Adapter::capture_event_id( $canonical ) );

// ── 2. recontact (rama early con contexto) también lo entrega ────────────────
set_last_ingested( array( 'lead_id' => 87, 'lead_uuid' => $canonical ) );
$r = respond( 'recontact', array( 'project_name' => 'Altacruz', 'project_slug' => 'altacruz' ) );
check( 'recontact → success=true con flag recontact', $r['success'] === true && ! empty( $r['data']['recontact'] ) );
check( 'recontact → meta_lead_event_id presente', ( $r['data']['meta_lead_event_id'] ?? '' ) === $canonical . ':CAPTURED' );

// ── 3. error → jamás entrega event_id (nada que trackear sin persistencia) ──
set_last_ingested( array( 'lead_id' => 87, 'lead_uuid' => $canonical ) );
$r = respond( 'error' );
check( 'error → respuesta error (422)', $r['success'] === false );
check( 'error → sin meta_lead_event_id', ! isset( $r['data']['meta_lead_event_id'] ) );

// ── 4. success SIN persistencia previa (last_ingested null) → clave ausente ─
set_last_ingested( null );
$r = respond( 'success' );
check( 'sin lead persistido → respuesta sin meta_lead_event_id', ! isset( $r['data']['meta_lead_event_id'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
