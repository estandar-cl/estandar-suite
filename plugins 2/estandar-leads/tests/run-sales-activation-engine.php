<?php
/** Run: php tests/run-sales-activation-engine.php */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return trim( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_email( $v ) { return filter_var( $v, FILTER_SANITIZE_EMAIL ); }
function is_email( $v ) { return (bool) filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function remove_accents( $v ) { return (string) $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }

require dirname( __DIR__ ) . '/includes/class-estandar-leads-sales-activation.php';

$fail = 0;
function check_activation( $label, $ok ) {
	global $fail;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	if ( ! $ok ) { $fail++; }
}

$now = strtotime( '2026-07-15 12:00:00 UTC' );
$base = array(
	'lead_id' => 1, 'submission_id' => 1, 'name' => 'Lead', 'email' => 'lead@example.com', 'phone' => '+56911111111',
	'project_slug' => 'demo', 'project_name' => 'Demo', 'status' => 'NEW', 'feedback_status' => 'pending',
	'correct_operator' => true, 'project_valid' => true, 'is_qa' => false, 'policy_allowed' => true,
	'terminal_reopened' => false, 'effective_contact' => false, 'new_activity_after_contact' => false,
	'submission_count' => 1, 'commercial_facts' => array(), 'latest_activity_at' => '2026-07-15 10:00:00', 'events' => array(),
);

$bianca = array_merge( $base, array( 'lead_id' => 10, 'name' => 'Bianca', 'submission_count' => 2, 'feedback_status' => 'escalated' ) );
$r_bianca = Estandar_Leads_Sales_Activation::evaluate( $bianca, 4, $now );
check_activation( 'Bianca: recontacto + SLA escalado => ALTA', $r_bianca && $r_bianca['priority'] === 'ALTA' && in_array( 'recontact', $r_bianca['reason_codes'], true ) && in_array( 'sla_escalated', $r_bianca['reason_codes'], true ) );

$tahia = array_merge( $base, array( 'lead_id' => 11, 'name' => 'Tahia', 'events' => array(
	array( 'type' => 'autoresponse_email_open_signal', 'created_at' => '2026-07-15 08:00:00' ),
	array( 'type' => 'autoresponse_link_click', 'created_at' => '2026-07-15 09:00:00', 'metadata' => array( 'resource_type' => 'landing' ) ),
	array( 'type' => 'autoresponse_engaged', 'created_at' => '2026-07-15 09:01:00', 'metadata' => array( 'resource_type' => 'whatsapp' ) ),
) ) );
$r_tahia = Estandar_Leads_Sales_Activation::evaluate( $tahia, 4, $now );
check_activation( 'Tahia: apertura + clic + engagement recomendada', $r_tahia && in_array( 'email_click', $r_tahia['reason_codes'], true ) && in_array( 'multi_channel', $r_tahia['reason_codes'], true ) );

$edgardo = array_merge( $base, array( 'lead_id' => 12, 'name' => 'Edgardo', 'commercial_facts' => array( 'high_value' => true, 'immediate_start' => true, 'operating_company' => true ) ) );
$r_edgardo = Estandar_Leads_Sales_Activation::evaluate( $edgardo, 4, $now );
check_activation( 'Edgardo: perfil explícito + inicio inmediato recomendado', $r_edgardo && $r_edgardo['priority'] === 'ALTA' && in_array( 'high_value', $r_edgardo['reason_codes'], true ) && in_array( 'immediate_start', $r_edgardo['reason_codes'], true ) );

$dayna = array_merge( $base, array( 'lead_id' => 13, 'name' => 'Dayna', 'events' => array( array( 'type' => 'autoresponse_email_open_signal', 'created_at' => '2026-07-15 08:00:00' ) ) ) );
$r_dayna = Estandar_Leads_Sales_Activation::evaluate( $dayna, 4, $now );
check_activation( 'Dayna: apertura única queda en prioridad menor', $r_dayna && $r_dayna['priority'] === 'BAJA' && $r_dayna['activation_score'] < $r_edgardo['activation_score'] );

check_activation( 'Lead sin señales suficientes excluido', Estandar_Leads_Sales_Activation::evaluate( $base, 4, $now ) === null );

$exclusions = array(
	'QA' => array( 'is_qa' => true ),
	'terminal sin reapertura' => array( 'status' => 'LOST' ),
	'operador incorrecto' => array( 'correct_operator' => false ),
	'teléfono inválido' => array( 'phone' => '123' ),
);
foreach ( $exclusions as $label => $override ) {
	$candidate = array_merge( $bianca, $override );
	check_activation( $label . ' excluido', Estandar_Leads_Sales_Activation::evaluate( $candidate, 4, $now ) === null );
}

$items = array( $r_dayna, $r_edgardo, $r_bianca, $r_tahia );
foreach ( range( 20, 23 ) as $id ) {
	$items[] = array_merge( $r_dayna, array( 'lead_id' => $id ) );
}
usort( $items, array( 'Estandar_Leads_Sales_Activation', 'compare_recommendations' ) );
$items = array_slice( $items, 0, 5 );
check_activation( 'Máximo 5 aplicado', count( $items ) === 5 );
check_activation( 'Orden determinista por score/actividad/id', $items[0]['activation_score'] >= $items[1]['activation_score'] );

$renderer = file_get_contents( dirname( __DIR__ ) . '/templates/email/sales-activation.php' );
check_activation( 'Renderer no contiene selección ni activation_score', strpos( $renderer, 'evaluate(' ) === false && strpos( $renderer, 'activation_score' ) === false );

$ref = new ReflectionClass( 'Estandar_Leads_Sales_Activation' );
$fingerprint = $ref->getMethod( 'fingerprint' );
$a = $fingerprint->invoke( null, array( $r_bianca, $r_tahia ) );
$b = $fingerprint->invoke( null, array( $r_bianca, $r_tahia ) );
$changed = $r_tahia; $changed['latest_activity'] = '2026-07-15 11:59:00';
$c = $fingerprint->invoke( null, array( $r_bianca, $changed ) );
check_activation( 'Deduplicación estable y sensible a actividad relevante', $a === $b && $a !== $c );

$empty_reason = $ref->getMethod( 'empty_reason' );
check_activation( 'Motivo explícito: operadores ausentes', strpos( $empty_reason->invoke( null, array( 'operators' => 0 ) ), 'operadores' ) !== false );
check_activation( 'Motivo explícito: leads pendientes ausentes', strpos( $empty_reason->invoke( null, array( 'operators' => 1, 'scanned' => 0 ) ), 'leads pendientes' ) !== false );
check_activation( 'Motivo explícito: score mínimo', strpos( $empty_reason->invoke( null, array( 'operators' => 1, 'scanned' => 4, 'below_min_score' => 4 ) ), 'score mínimo' ) !== false );

exit( $fail ? 1 : 0 );
