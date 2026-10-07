<?php
/**
 * Standalone contract test for Estandar_Leads_Meta_Payload_Builder.
 * No WordPress required. Run: php tests/run-meta-payload-builder.php
 */

define( 'ABSPATH', __DIR__ );
require_once dirname( __DIR__ ) . '/includes/class-estandar-leads-meta-payload-builder.php';

$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}

$lead = array(
	'lead_uuid'              => '9724cf00-ad56-4418-b5ed-641d979bf806',
	'email_normalized'      => 'Juan@Example.com',
	'phone_normalized'      => '+56 9 1234 5678',
	'first_name'            => 'Juan',
	'last_name'             => 'Perez',
	'city'                  => 'Santiago',
	'country'               => 'CL',
	'project_slug'          => 'parronales',
	'first_landing_url'     => 'https://acacios.cl/parronales',
	'first_meta_fbc'        => 'fb.1.1700000000.ABC',
	'first_meta_fbp'        => 'fb.1.1700000000.XYZ',
	'first_fbclid'          => 'ABC123',
	'first_utm_source'      => 'facebook',
	'first_utm_medium'      => 'paid',
	'first_utm_campaign'    => 'parronales-junio',
	'assigned_operator_name' => 'Valentina',
	'conversion_value'      => '15000',
	'currency'              => 'clp',
	'updated_at'            => '2026-06-20 12:00:00',
);

// 1. Determinism — same input → same payload.
$a = Estandar_Leads_Meta_Payload_Builder::build( $lead, 'RESERVED' );
$b = Estandar_Leads_Meta_Payload_Builder::build( $lead, 'RESERVED' );
check( 'Deterministic (same input → same payload)', $a === $b );

// 2. Event mapping.
check( 'RESERVED → InitiateCheckout', $a['event_name'] === 'InitiateCheckout' );
check( 'CAPTURED → Lead (hecho de captura)', Estandar_Leads_Meta_Payload_Builder::build( $lead, 'CAPTURED' )['event_name'] === 'Lead' );
check( 'QUALIFIED → QualifiedLead (custom event, separado de la captura)', Estandar_Leads_Meta_Payload_Builder::build( $lead, 'QUALIFIED' )['event_name'] === 'QualifiedLead' );
check( 'VISITED → Contact', Estandar_Leads_Meta_Payload_Builder::build( $lead, 'VISITED' )['event_name'] === 'Contact' );
check( 'CONVERTED → Purchase', Estandar_Leads_Meta_Payload_Builder::build( $lead, 'CONVERTED' )['event_name'] === 'Purchase' );
check( 'Unmapped status → empty array', Estandar_Leads_Meta_Payload_Builder::build( $lead, 'NEW' ) === array() );

// 3. Valid associative array shape.
check( 'event_time is int', is_int( $a['event_time'] ) && $a['event_time'] === strtotime( '2026-06-20 12:00:00 UTC' ) );
check( 'action_source = website (url present)', $a['action_source'] === 'website' );
check( 'event_source_url present', ( $a['event_source_url'] ?? '' ) === 'https://acacios.cl/parronales' );

// 4. Hashing — required fields hashed, click params raw.
$ud = $a['user_data'];
check( 'email hashed (sha256 lower)', $ud['em'] === hash( 'sha256', 'juan@example.com' ) );
check( 'phone hashed on digits only', $ud['ph'] === hash( 'sha256', '56912345678' ) );
check( 'first_name hashed', $ud['fn'] === hash( 'sha256', 'juan' ) );
check( 'external_id NOT hashed', $ud['external_id'] === '9724cf00-ad56-4418-b5ed-641d979bf806' );
check( 'fbc NOT hashed', $ud['fbc'] === 'fb.1.1700000000.ABC' );
check( 'fbp NOT hashed', $ud['fbp'] === 'fb.1.1700000000.XYZ' );
check( 'fbclid NOT hashed', $ud['fbclid'] === 'ABC123' );

// 5. Already-hashed value never hashed twice.
$prehashed = $lead;
$prehashed['email_normalized'] = hash( 'sha256', 'juan@example.com' );
$ph = Estandar_Leads_Meta_Payload_Builder::build( $prehashed, 'RESERVED' )['user_data'];
check( 'pre-hashed email passed through (not double-hashed)', $ph['em'] === hash( 'sha256', 'juan@example.com' ) );

// 6. custom_data.
$cd = $a['custom_data'];
check( 'custom_data.commercial_status', $cd['commercial_status'] === 'RESERVED' );
check( 'custom_data.conversion_value float', $cd['conversion_value'] === 15000.0 );
check( 'custom_data.currency uppercased', $cd['currency'] === 'CLP' );
check( 'custom_data.operator', $cd['operator'] === 'Valentina' );
check( 'custom_data.utm_campaign', $cd['utm_campaign'] === 'parronales-junio' );

// 7. Missing values omitted automatically + no empty fields.
$minimal = array(
	'lead_uuid'   => 'abc',
	'email'       => 'x@y.com',
	'updated_at'  => '2026-06-20 12:00:00',
);
$m = Estandar_Leads_Meta_Payload_Builder::build( $minimal, 'QUALIFIED' );
check( 'no event_source_url when absent', ! isset( $m['event_source_url'] ) );
check( 'action_source = system_generated when no url', $m['action_source'] === 'system_generated' );
check( 'no phone key when absent', ! isset( $m['user_data']['ph'] ) );
check( 'no operator key when absent', ! isset( $m['custom_data']['operator'] ) );
$has_empty = false;
array_walk_recursive( $m, function ( $v ) use ( &$has_empty ) {
	if ( $v === '' || $v === null ) { $has_empty = true; }
} );
check( 'no empty fields anywhere', ! $has_empty );

// ── F1: derivación de fbc desde fbclid + timestamp persistido ────────────────
// meta_fbc capturado (cookie) SIEMPRE gana sobre la derivación.
$f = Estandar_Leads_Meta_Payload_Builder::build( $lead, 'CAPTURED' );
check( 'F1: meta_fbc persistido no se sobreescribe', $f['user_data']['fbc'] === 'fb.1.1700000000.ABC' );

// Sin fbc capturado + fbclid + first_seen_at → fbc derivado (spec: ms).
$derive = $lead;
unset( $derive['first_meta_fbc'] );
$derive['first_seen_at'] = '2026-06-20 12:00:00';
$expected_ms = strtotime( '2026-06-20 12:00:00 UTC' ) * 1000;
$f = Estandar_Leads_Meta_Payload_Builder::build( $derive, 'CAPTURED' );
check( 'F1: deriva fbc = fb.1.{ms}.{fbclid} desde first_seen_at',
	$f['user_data']['fbc'] === 'fb.1.' . $expected_ms . '.ABC123' );

// Fallback: sin first_seen_at usa created_at (nunca la hora actual).
unset( $derive['first_seen_at'] );
$derive['created_at'] = '2026-06-18 09:30:00';
$f = Estandar_Leads_Meta_Payload_Builder::build( $derive, 'CAPTURED' );
check( 'F1: fallback a created_at',
	$f['user_data']['fbc'] === 'fb.1.' . ( strtotime( '2026-06-18 09:30:00 UTC' ) * 1000 ) . '.ABC123' );

// Sin fbclid → no se emite fbc.
$no_clid = $derive;
unset( $no_clid['first_fbclid'] );
$f = Estandar_Leads_Meta_Payload_Builder::build( $no_clid, 'CAPTURED' );
check( 'F1: sin fbclid → sin clave fbc', ! isset( $f['user_data']['fbc'] ) );

// Timestamp inválido y sin otro persistido → no se emite fbc (jamás now()).
$bad_ts = $derive;
$bad_ts['created_at']    = 'not-a-date';
$bad_ts['first_seen_at'] = '';
$f = Estandar_Leads_Meta_Payload_Builder::build( $bad_ts, 'CAPTURED' );
check( 'F1: timestamp inválido → sin fbc (no usa hora actual)', ! isset( $f['user_data']['fbc'] ) );

// La derivación jamás introduce valores vacíos en el payload.
$has_empty = false;
array_walk_recursive( $f, function ( $v ) use ( &$has_empty ) {
	if ( $v === '' || $v === null ) { $has_empty = true; }
} );
check( 'F1: payload sin valores vacíos tras derivación', ! $has_empty );

// ── F2: client_ip_address / client_user_agent (raw, nunca hashed) ────────────
$net = $lead;
$net['first_ip_address'] = '190.161.10.20';
$net['first_user_agent'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X)';
$f2 = Estandar_Leads_Meta_Payload_Builder::build( $net, 'CAPTURED' )['user_data'];
check( 'F2: client_ip_address emitido RAW (sin hash)', $f2['client_ip_address'] === '190.161.10.20' );
check( 'F2: client_user_agent emitido RAW (sin hash)', $f2['client_user_agent'] === 'Mozilla/5.0 (Macintosh; Intel Mac OS X)' );

// Campo explícito gana sobre first_* (mismo patrón fbc/fbp).
$net['client_ip_address'] = '10.0.0.9';
$f2 = Estandar_Leads_Meta_Payload_Builder::build( $net, 'CAPTURED' )['user_data'];
check( 'F2: client_ip_address explícito gana sobre first_ip_address', $f2['client_ip_address'] === '10.0.0.9' );

// Sin valores → claves omitidas (put() omite vacíos).
$f2 = Estandar_Leads_Meta_Payload_Builder::build( $lead, 'CAPTURED' )['user_data'];
check( 'F2: sin IP → sin clave client_ip_address', ! isset( $f2['client_ip_address'] ) );
check( 'F2: sin UA → sin clave client_user_agent', ! isset( $f2['client_user_agent'] ) );

// Vacíos explícitos jamás viajan.
$empty_net = $lead;
$empty_net['first_ip_address'] = '';
$empty_net['first_user_agent'] = '  ';
$f2 = Estandar_Leads_Meta_Payload_Builder::build( $empty_net, 'CAPTURED' )['user_data'];
check( 'F2: IP vacía → clave omitida', ! isset( $f2['client_ip_address'] ) );
check( 'F2: UA whitespace → clave omitida', ! isset( $f2['client_user_agent'] ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
