<?php
/**
 * COMMERCIAL_PROFILE_V1 + OPERATIONAL_PRIORITY_V2 — acceptance A & D.
 *
 * A. Lead Meta con clic en autorespuesta: prioridad superior a un lead con
 *    solo apertura; sigue sin Qualification real; Meta CAPI sigue elegible.
 * D. Lead AK con empresa operando + contador en evaluación + inicio inmediato:
 *    Profile comercial no es NULL; score/prioridad lo reflejan; Qualification
 *    del operador continúa pendiente.
 *
 * Run: php tests/run-commercial-profile.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

require __DIR__ . '/../includes/class-estandar-leads-commercial-profile.php';
require __DIR__ . '/../includes/class-estandar-leads-scoring.php';
require __DIR__ . '/../includes/class-estandar-leads-attribution.php';

$failed = 0;
function check( $label, $ok ) {
	global $failed;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}

// ── D · Perfil comercial desde campos AK ─────────────────────────────────────
$ak_fields = array(
	'necesidad_principal' => 'Contabilidad mensual completa',
	'situacion_empresa'   => 'Empresa operando',
	'contador_actual'     => 'Evaluando cambio de contador',
	'plazo_inicio'        => 'Inicio inmediato',
	'ciudad'              => 'Talca',
);
$profile = Estandar_Leads_Commercial_Profile::derive( $ak_fields );

check( 'D: profile is not NULL', is_array( $profile ) );
check( 'D: profile_id present', $profile !== null && $profile['profile_id'] !== '' );
check( 'D: score > 0', $profile !== null && $profile['score'] > 0 );
check( 'D: high intent (operando + evaluando + inmediato)', $profile !== null && $profile['high_intent'] === true );
check( 'D: breakdown auditable (5 signals with reasons)', $profile !== null && count( $profile['signals'] ) === 5 && count( array_filter( $profile['reasons'] ) ) === 5 );
check( 'D: no QUALIFIED label anywhere in the profile', $profile !== null && stripos( wp_json_encode_fallback( $profile ), 'qualified' ) === false );

function wp_json_encode_fallback( $v ) { return json_encode( $v ); }

// Perfil ausente cuando no hay campos comerciales — jamás fabricado.
check( 'D: no commercial fields → NULL profile', Estandar_Leads_Commercial_Profile::derive( array( 'nombre' => 'Ana', 'email' => 'a@b.cl' ) ) === null );

// D · scoring refleja el perfil, qualification sigue pendiente.
$base = array(
	'capture'         => 100,
	'qualification'   => null,
	'behavior_events' => array(),
	'is_valid_lead'   => null,
	'quality'         => '',
	'status'          => 'NEW',
	'sales_stage'     => '',
	'gestion'         => array( 'contact_attempts' => 0, 'feedback_completed' => 0, 'workspace_opened' => false ),
	'thresholds'      => array( 'hot' => 70, 'warm' => 40 ),
);
$without = Estandar_Leads_Scoring::calculate( $base );
$with    = Estandar_Leads_Scoring::calculate( array( 'profile' => $profile ) + $base );

check( 'D: score reflects profile (total changes)', $with['total'] !== $without['total'] );
check( 'D: qualification component still NULL (operator pending)', $with['components']['qualification'] === null );
check( 'D: components carry profile score + id + detail', $with['components']['profile'] === $profile['score']
	&& $with['components']['profile_id'] === $profile['profile_id']
	&& count( $with['components']['profile_detail'] ) === 5 );
check( 'D: priority elevated with explicit reason', $with['priority'] !== $without['priority']
	&& strpos( $with['components']['priority_reason'], 'perfil_comercial_alto' ) !== false );
check( 'D: profile weight participates only when present', $with['components']['weights_used']['profile'] > 0
	&& $without['components']['weights_used']['profile'] === 0 );

// ── A · clic pesa más que apertura y eleva prioridad operativa ───────────────
$click_lead = Estandar_Leads_Scoring::calculate( array(
	'behavior_events' => array(
		array( 'type' => 'autoresponse_link_click', 'actor_type' => 'lead', 'age_days' => 1 ),
	),
) + $base );
$open_lead = Estandar_Leads_Scoring::calculate( array(
	'behavior_events' => array(
		array( 'type' => 'autoresponse_email_open_signal', 'actor_type' => 'unknown', 'age_days' => 1 ),
	),
) + $base );

$rank = array( 'BAJA' => 0, 'MEDIA' => 1, 'ALTA' => 2, 'CRITICA' => 3 );
check( 'A: click score > open score', $click_lead['total'] > $open_lead['total'] );
check( 'A: click priority above open priority', $rank[ $click_lead['priority'] ] > $rank[ $open_lead['priority'] ] );
check( 'A: click priority reason auditable', strpos( $click_lead['components']['priority_reason'], 'engagement_reciente' ) !== false );
check( 'A: open alone never counts as deep engagement', strpos( $open_lead['components']['priority_reason'], 'engagement' ) === false );
check( 'A: neither behavior produces qualification', $click_lead['components']['qualification'] === null && $open_lead['components']['qualification'] === null );

// Engagement viejo (fuera de ventana de decay) no eleva prioridad.
$stale_click = Estandar_Leads_Scoring::calculate( array(
	'behavior_events' => array(
		array( 'type' => 'autoresponse_link_click', 'actor_type' => 'lead', 'age_days' => 30 ),
	),
) + $base );
check( 'A: stale click (30d) does not elevate priority', $stale_click['priority'] === $without['priority'] );

// A · Meta CAPI sigue elegible: la prioridad operativa no toca la elegibilidad.
$meta_lead = (object) array(
	'id'           => 9,
	'first_fbclid' => 'fb.click.123',
	'utm_source'   => 'facebook',
	'utm_medium'   => 'paid',
);
$eligibility = Estandar_Leads_Attribution::platform_eligibility( 'meta', $meta_lead, true );
check( 'A: Meta CAPI remains eligible (fbclid evidence)', ! empty( $eligibility['eligible'] ) && $eligibility['evidence'] === 'fbclid' );

// El comportamiento jamás altera el canal ni la evidencia (no feedback QUALIFIED).
check( 'A: behavior does not alter channel', Estandar_Leads_Attribution::resolve_channel( $meta_lead ) === 'meta_ads' );

echo "\n" . ( $failed === 0 ? 'ALL PASS' : $failed . ' FAILED' ) . "\n";
exit( $failed === 0 ? 0 : 1 );
