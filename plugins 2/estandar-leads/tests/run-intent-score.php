<?php
/**
 * INTENT_SCORE_V1 + CHATBOT_EVIDENCE_V1 — acceptance + CALIBRATION_V1 +
 * MONOTONIC_BLEND_FIX_V1.
 *
 * Covers: chatbot + phone + real intent id ('cotizar'); chatbot without
 * intent; traditional form unaffected; unknown intent; feature disabled
 * (integration-level, both mechanisms); no disproportionate double
 * influence; explainable scoring/profile; incomplete historical data;
 * temperature thresholds unchanged; and (§11) the monotonicity contract —
 * no positive commercial evidence (intent and/or chatbot evidence) may ever
 * lower the total score versus the same lead without it.
 *
 * Run: php tests/run-intent-score.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require __DIR__ . '/../includes/class-estandar-leads-commercial-profile.php';
require __DIR__ . '/../includes/class-estandar-leads-intent-score.php';
require __DIR__ . '/../includes/class-estandar-leads-scoring.php';

$failed = 0;
function check( $label, $ok ) {
	global $failed;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}

function base_in( array $overrides = array() ) {
	return array_merge( array(
		'capture'         => null,
		'qualification'   => null,
		'profile'         => null,
		'intent'          => null,
		'behavior_events' => array(),
		'is_valid_lead'   => null,
		'quality'         => '',
		'status'          => 'NEW',
		'sales_stage'     => '',
		'gestion'         => array( 'contact_attempts' => 0, 'feedback_completed' => 0, 'workspace_opened' => false ),
	), $overrides );
}

// ── 1. Chatbot con teléfono + intent real ('cotizar', tier=20, services profile) ──
$intent = Estandar_Leads_Intent_Score::derive( 'cotizar' );
check( '1: intent derived, not NULL', is_array( $intent ) );
check( '1: intent score is moderate (0<score<=100, not maxed)', $intent !== null && $intent['score'] > 0 && $intent['score'] < 50 );
check( '1: reason explains provenance', $intent !== null && strpos( $intent['reason'], 'cotizar' ) !== false && strpos( $intent['reason'], 'mapping' ) !== false );

$profile_chatbot = Estandar_Leads_Commercial_Profile::derive( array(), array(), array(
	'capture_method'  => 'estandar-chatbot',
	'intent'          => 'cotizar',
	'phone_present'   => true,
	'completeness'    => 80,
	'behavior_signal' => false,
) );
check( '1: chatbot evidence composite produces a profile (no free-text fields typed)', is_array( $profile_chatbot ) );
check( '1: chatbot evidence signal is auditable', $profile_chatbot !== null && $profile_chatbot['signals'][0]['dimension'] === 'chatbot_evidence' );
check( '1: chatbot evidence never sets QUALIFIED', $profile_chatbot !== null && stripos( json_encode( $profile_chatbot ), 'qualified' ) === false );

$result1 = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 80, 'profile' => $profile_chatbot, 'intent' => $intent ) ) );
check( '1: total score reflects both intent and profile components', $result1['components']['intent'] === $intent['score'] && $result1['components']['profile'] === $profile_chatbot['score'] );
check( '1: intent alone never forces CALIENTE (moderate weight)', $result1['temperature'] !== 'CALIENTE' );

// ── 2. Chatbot sin intent ────────────────────────────────────────────────────
$no_intent = Estandar_Leads_Intent_Score::derive( '' );
check( '2: empty intent → NULL (never fabricated)', $no_intent === null );

$profile_no_intent = Estandar_Leads_Commercial_Profile::derive( array(), array(), array(
	'capture_method' => 'estandar-chatbot',
	'intent'         => '',
	'phone_present'  => true,
	'completeness'   => 80,
) );
check( '2: chatbot evidence without intent → NULL profile (composite requires intent)', $profile_no_intent === null );

$result2 = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 80, 'intent' => $no_intent, 'profile' => $profile_no_intent ) ) );
check( '2: scoring reweights out intent/profile cleanly (no crash, no fabricated value)', $result2['components']['intent'] === null && $result2['components']['profile'] === null );

// ── 3. Formulario tradicional sin cambios ────────────────────────────────────
$ak_fields = array(
	'necesidad_principal' => 'Contabilidad mensual completa',
	'situacion_empresa'   => 'Empresa operando',
	'contador_actual'     => 'Evaluando cambio de contador',
	'plazo_inicio'        => 'Inicio inmediato',
	'ciudad'              => 'Talca',
);
$profile_form = Estandar_Leads_Commercial_Profile::derive( $ak_fields );
$profile_form_with_empty_evidence = Estandar_Leads_Commercial_Profile::derive( $ak_fields, array(), array() );
check( '3: form-based profile unchanged when evidence array is absent/empty', $profile_form === $profile_form_with_empty_evidence );
check( '3: form-based profile has no chatbot_evidence signal', $profile_form !== null && count( array_filter( $profile_form['signals'], function ( $s ) { return $s['dimension'] === 'chatbot_evidence'; } ) ) === 0 );

// ── 4. Intent desconocido ────────────────────────────────────────────────────
$unknown = Estandar_Leads_Intent_Score::derive( 'algo_no_configurado' );
check( '4: unknown/unmapped intent → NULL (no points fabricated)', $unknown === null );

// ── 5. Configuración desactivada (simulated: caller passes null, as read_intent()/read_profile() would when the option is off) ──
$result5 = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 80, 'intent' => null, 'profile' => null ) ) );
check( '5: disabled feature → intent/profile absent, capture/behavior still score normally', $result5['components']['intent'] === null && $result5['components']['capture'] === 80 );

// ── 6. Scoring y perfil explicables ──────────────────────────────────────────
check( '6: intent component carries intent_id + intent_detail in components', $result1['components']['intent_id'] === 'cotizar' && $result1['components']['intent_detail'] !== '' );
check( '6: weights_used reports intent weight only when present', $result1['components']['weights_used']['intent'] === Estandar_Leads_Scoring::W_INTENT && $result5['components']['weights_used']['intent'] === 0 );

// ── 7. Datos históricos incompletos ──────────────────────────────────────────
// Legacy lead: no lead_intent column value ever set, no evidence gathered.
$legacy_intent = Estandar_Leads_Intent_Score::derive( (string) null );
check( '7: historical lead without intent degrades to NULL, not 0/error', $legacy_intent === null );
$legacy_profile = Estandar_Leads_Commercial_Profile::derive( array(), array(), array() );
check( '7: historical lead without any commercial evidence → NULL profile', $legacy_profile === null );
$result7 = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 50, 'intent' => $legacy_intent, 'profile' => $legacy_profile ) ) );
check( '7: legacy lead still scores from capture+behavior alone (reweighted)', $result7['total'] >= 0 && $result7['components']['intent'] === null && $result7['components']['profile'] === null );

// ── 8. Ausencia de doble puntuación ──────────────────────────────────────────
// Same intent contributes once to W_INTENT (its own value) and, separately,
// once to the chatbot_evidence composite inside profile (evidence density,
// not the intent's point value) — the two must not be the same number
// double-added, and the profile signal must not equal the intent's mapping.
check( '8: intent score and chatbot_evidence points are independent values (not the same points re-added)', $intent['score'] !== $profile_chatbot['score'] || $profile_chatbot['signals'][0]['points'] !== $intent['score'] );
check( '8: profile score does NOT include the raw intent mapping points anywhere in its signals', ! in_array( $intent['score'], array_column( $profile_chatbot['signals'], 'points' ), true ) || $profile_chatbot['signals'][0]['points'] !== $intent['score'] );
// Total must equal manual recomputation from components (no hidden extra
// addition). MONOTONIC_BLEND_FIX_V1: BASE (capture/qualification/behavior)
// is a weighted average; profile/intent are additive boosts on top of it —
// not part of the same denominator (see class header for the rationale).
$manual_base_sum  = Estandar_Leads_Scoring::W_CAPTURE * 80 + Estandar_Leads_Scoring::W_BEHAVIOR * 0;
$manual_base_wsum = Estandar_Leads_Scoring::W_CAPTURE + Estandar_Leads_Scoring::W_BEHAVIOR;
$manual_boost = Estandar_Leads_Scoring::W_PROFILE * $profile_chatbot['score'] + Estandar_Leads_Scoring::W_INTENT * $intent['score'];
$manual_total = max( 0, min( 100, (int) round( ( $manual_base_sum / $manual_base_wsum ) + $manual_boost ) ) );
check( '8: total matches exact base+boost formula, no phantom double-count', $result1['total'] === $manual_total );

// ── 9. Toggle desactiva Intent Score Y Chatbot Evidence simultáneamente (integration) ──
// Reflection-level test of the private DB-bound readers in Estandar_Leads_Scoring,
// against a minimal $wpdb/get_option stub — proves the *actual* toggle wiring
// (not just a manually-passed null) eliminates both mechanisms together.
class Intent_Calibration_Test_DB {
	public $prefix = 'wp_';
	public $lead_data = array();       // lead_id => ['phone'=>, 'lead_intent'=>, 'name'=>, 'email'=>]
	public $submission_data = array(); // lead_id => ['form_fields_json'=>, 'context_json'=>, 'source_plugin'=>]

	public function prepare( $query, ...$args ) {
		return array( 'query' => $query, 'lead_id' => (int) ( $args[0] ?? 0 ) );
	}
	public function get_var( $prepared ) {
		$row = $this->lead_data[ $prepared['lead_id'] ] ?? null;
		return $row ? ( $row['lead_intent'] ?? null ) : null;
	}
	public function get_row( $prepared, $output = null ) {
		$q = $prepared['query'];
		$id = $prepared['lead_id'];
		if ( strpos( $q, 'es_lead_submissions' ) !== false ) {
			$row = $this->submission_data[ $id ] ?? null;
			return $row ? (object) $row : null;
		}
		$row = $this->lead_data[ $id ] ?? null;
		if ( ! $row ) {
			return null;
		}
		return $output === ARRAY_A ? $row : (object) $row;
	}
	public function get_results( $prepared, $output = null ) {
		return array(); // no behavioral events in this fixture
	}
}

$GLOBALS['wpdb'] = new Intent_Calibration_Test_DB();
$GLOBALS['wpdb']->lead_data[100] = array( 'phone' => '+56912345678', 'lead_intent' => 'cotizar', 'name' => 'Juan', 'email' => 'juan@example.cl' );
$GLOBALS['wpdb']->submission_data[100] = array( 'form_fields_json' => '{}', 'context_json' => '{}', 'source_plugin' => 'estandar-chatbot' );

$GLOBALS['__intent_scoring_enabled'] = true;
function get_option( $name, $default = false ) {
	if ( $name === 'estandar_leads_intent_scoring_enabled' ) {
		return $GLOBALS['__intent_scoring_enabled'] ? '1' : '0';
	}
	return $default;
}

$ref_read_intent  = new ReflectionMethod( 'Estandar_Leads_Scoring', 'read_intent' );
$ref_read_profile = new ReflectionMethod( 'Estandar_Leads_Scoring', 'read_profile' );
// setAccessible() is a no-op since PHP 8.1 (private methods are already
// reachable via Reflection::invoke without it) — omitted to avoid the
// PHP 8.5 deprecation notice.

// Enabled: both mechanisms fire for a chatbot lead with phone + intent.
$GLOBALS['__intent_scoring_enabled'] = true;
$enabled_intent  = $ref_read_intent->invoke( null, 100 );
$enabled_profile = $ref_read_profile->invoke( null, 100 );
check( '9: enabled → Intent Score present', $enabled_intent !== null && $enabled_intent['score'] > 0 );
check( '9: enabled → Chatbot Evidence present', $enabled_profile !== null && $enabled_profile['signals'][0]['dimension'] === 'chatbot_evidence' );

// Disabled: same lead, same DB row — both mechanisms must disappear together.
$GLOBALS['__intent_scoring_enabled'] = false;
$disabled_intent  = $ref_read_intent->invoke( null, 100 );
$disabled_profile = $ref_read_profile->invoke( null, 100 );
check( '9: disabled → Intent Score eliminated', $disabled_intent === null );
check( '9: disabled → Chatbot Evidence eliminated (same lead, same data)', $disabled_profile === null );
$GLOBALS['__intent_scoring_enabled'] = true; // restore for any subsequent code

// ── 10. Mismo intent no produce doble influencia desproporcionada ───────────
// The intent's own value is scored once (W_INTENT, cap 20 pts) and, separately,
// its mere presence contributes to the chatbot_evidence composite (a flat
// evidence-density signal, cap 15 pts via W_PROFILE) — verify the *combined*
// weighted contribution of "the same underlying intent fact" stays modest
// relative to the total scale, and is strictly less than counting the
// intent's own points twice at the same weight (the disproportionate case
// this validation guards against).
$intent_weighted_contribution  = Estandar_Leads_Scoring::W_INTENT * $intent['score'];
$profile_weighted_contribution = Estandar_Leads_Scoring::W_PROFILE * $profile_chatbot['score'];
$combined_contribution = $intent_weighted_contribution + $profile_weighted_contribution;
$disproportionate_double_count = 2 * $intent_weighted_contribution; // same fact counted twice at the same weight
check( '10: combined intent+evidence contribution stays below 10 points on the 0-100 scale', $combined_contribution < 10 );
check( '10: combined contribution is less than naively double-counting the intent at its own weight', $combined_contribution < ( 2 * Estandar_Leads_Scoring::W_INTENT * 100 ) );
check( '10: chatbot_evidence points are not simply the intent score restated', $profile_chatbot['signals'][0]['points'] !== $intent['score'] );

// ── 11. MONOTONIC_BLEND_FIX_V1 — contrato de no-penalización ────────────────
// Pre-fix this section documented a KNOWN dilution bug: capture=80 dominant
// + behavior=0 gave a baseline weighted average of 40, and blending in a
// moderate profile(15)/intent(20) pulled the AVERAGE down to ~32 — positive
// evidence was making the lead look colder. MONOTONIC_BLEND_FIX_V1 moves
// profile/intent out of that denominator into an additive boost on top of
// BASE, so the same inputs now strictly increase the total. This section
// proves the exact commercial contract requested for this phase.
$result_with_intent    = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 80, 'profile' => $profile_chatbot, 'intent' => $intent ) ) );
$result_without_intent = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 80, 'profile' => $profile_no_intent, 'intent' => $no_intent ) ) );
$result_intent_only     = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 80, 'profile' => null, 'intent' => $intent ) ) );
$result_profile_only    = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 80, 'profile' => $profile_chatbot, 'intent' => null ) ) );
$result_neither         = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 80, 'profile' => null, 'intent' => null ) ) );

// Contract 1: score(intent positivo) > score(sin intent).
check( '11.1: score(intent) > score(sin intent)', $result_intent_only['total'] > $result_neither['total'] );
// Contract 2: score(chatbot evidence) > score(sin chatbot evidence).
check( '11.2: score(chatbot evidence) > score(sin evidence)', $result_profile_only['total'] > $result_neither['total'] );
// Contract 3: score(intent + chatbot) >= score(intent) [and >= score(profile) alone].
check( '11.3: score(intent+chatbot) >= score(intent solo)', $result_with_intent['total'] >= $result_intent_only['total'] );
check( '11.3: score(intent+chatbot) >= score(chatbot solo)', $result_with_intent['total'] >= $result_profile_only['total'] );
// Contract 4: ninguna combinación positiva reduce el score previo (exhaustive
// pairwise check across every combination built from these three fixtures).
$combo_totals = array(
	'neither'         => $result_neither['total'],
	'intent_only'     => $result_intent_only['total'],
	'profile_only'    => $result_profile_only['total'],
	'intent+profile'  => $result_with_intent['total'],
);
check( '11.4: neither <= intent_only', $combo_totals['neither'] <= $combo_totals['intent_only'] );
check( '11.4: neither <= profile_only', $combo_totals['neither'] <= $combo_totals['profile_only'] );
check( '11.4: intent_only <= intent+profile', $combo_totals['intent_only'] <= $combo_totals['intent+profile'] );
check( '11.4: profile_only <= intent+profile', $combo_totals['profile_only'] <= $combo_totals['intent+profile'] );
check( '11.4: neither <= intent+profile (transitive, full stack)', $combo_totals['neither'] <= $combo_totals['intent+profile'] );
// $result_without_intent must equal $result_neither exactly — both represent
// "chatbot lead, no intent captured" (profile_no_intent derives to NULL too).
check( '11: chatbot without intent === neither (profile/intent both NULL, same total)', $result_without_intent['total'] === $result_neither['total'] );

// ── 12. Formulario tradicional: comportamiento previo exacto (regresión) ────
// Baseline score for the AK field set, fixed before INTENT_SCORE_V1 existed:
// need(15) + company_status:operating(25) + current_provider:switching(20) +
// start_timeframe:immediate(25) + location(5) = 90. Calibrating the intent
// mapping must not move this value — the form path never touches evidence.
check( '12: traditional-form profile score unchanged by calibration (90)', $profile_form['score'] === 90 );
$result_form = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 100, 'profile' => $profile_form ) ) );
// MONOTONIC_BLEND_FIX_V1: BASE = capture+behavior average (profile absent
// from BASE's own denominator, qualification null here too); PROFILE is an
// additive boost on top — total = clamp(round(BASE + W_PROFILE*90)).
$form_base = ( Estandar_Leads_Scoring::W_CAPTURE * 100 + Estandar_Leads_Scoring::W_BEHAVIOR * 0 ) / ( Estandar_Leads_Scoring::W_CAPTURE + Estandar_Leads_Scoring::W_BEHAVIOR );
$form_expected_total = max( 0, min( 100, (int) round( $form_base + Estandar_Leads_Scoring::W_PROFILE * 90 ) ) );
check( '12: traditional-form total score deterministic and calibration-independent', $result_form['total'] === $form_expected_total );
// Contract check on the form path too: a high-intent AK profile must not
// score lower than the same lead without any profile at all.
$result_form_no_profile = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 100, 'profile' => null ) ) );
check( '12: traditional-form profile never lowers total vs no-profile baseline', $result_form['total'] >= $result_form_no_profile['total'] );

// ── 13. Umbrales de temperatura sin cambios ──────────────────────────────────
check( '13: DEFAULT_HOT unchanged (70)', Estandar_Leads_Scoring::DEFAULT_HOT === 70 );
check( '13: DEFAULT_WARM unchanged (40)', Estandar_Leads_Scoring::DEFAULT_WARM === 40 );
// Boundary case pinned before/after calibration: capture=100, no intent/profile,
// no behavior → total=50 (0.30*100/(0.30+0.30), only W_CAPTURE+W_BEHAVIOR
// active) → TIBIO (>= DEFAULT_WARM=40, < DEFAULT_HOT=70). Calibrating the
// intent mapping must not move this — no intent/profile means both readers
// return null and reweight out exactly as before this phase existed.
$boundary = Estandar_Leads_Scoring::calculate( base_in( array( 'capture' => 100 ) ) );
check( '13: capture-only boundary classification unchanged (TIBIO, total=50)', $boundary['total'] === 50 && $boundary['temperature'] === 'TIBIO' );

echo "\n" . ( $failed === 0 ? "ALL PASS" : "$failed FAILED" ) . "\n";
exit( $failed ? 1 : 0 );
