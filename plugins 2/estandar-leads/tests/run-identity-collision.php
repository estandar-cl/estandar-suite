<?php
/**
 * IDENTITY_COLLISION regression suite (COMMERCIAL_QUALIFICATION_V1 · Part 7).
 * Run: php tests/run-identity-collision.php
 *
 * False positive guard: one lead + one submission + one normalized identity
 * must NEVER emit an Identity Collision Warning — landing/campaign mismatches
 * are attribution notices, not identity conflicts.
 * True positive: conflicting normalized identities across submissions must
 * still be reported.
 */

define( 'ABSPATH', __DIR__ . '/' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return is_scalar( $v ) ? trim( (string) $v ) : ''; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_list_pluck( $list, $field ) {
	return array_map( static function( $row ) use ( $field ) {
		return is_object( $row ) ? ( $row->{$field} ?? null ) : ( $row[ $field ] ?? null );
	}, (array) $list );
}
function home_url( $path = '' ) { return 'https://example.test' . $path; }

class Estandar_Forms_Dataset_Provider {
	public static function get( $dataset ) { return array(); }
}
class Estandar_Leads_Compliance {
	public static function hours_since_public( string $datetime ): int { return 50; }
}

class Identity_Test_DB {
	public $prefix = 'wp_';
	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function suppress_errors( $v ) { return false; }
	public function get_col( $q ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'DESC wp_es_leads' ) !== false ) {
			return array( 'id', 'project_slug' );
		}
		return array();
	}
	public function get_var( $q ) { return null; }
	public function get_row( $q ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'FROM wp_es_leads' ) !== false ) {
			return $GLOBALS['test_lead'];
		}
		if ( strpos( $sql, 'FROM wp_ea_sessions' ) !== false ) {
			return (object) array(
				'session_id' => 'sid-1', 'landing_page' => 'https://example.test/otro-proyecto/',
				'page_count' => 2, 'duration' => 30, 'source' => 'google',
				'campaign' => 'session-campaign', 'campaign_id' => 'cmp-999',
			);
		}
		return null;
	}
	public function get_results( $q ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'FROM wp_es_lead_submissions' ) !== false && strpos( $sql, 'ORDER BY created_at ASC' ) !== false ) {
			return $GLOBALS['test_submissions'];
		}
		return array();
	}
}

$GLOBALS['wpdb'] = new Identity_Test_DB();
require dirname( __DIR__ ) . '/includes/class-estandar-leads-attribution.php';
require dirname( __DIR__ ) . '/includes/class-estandar-leads-ai-context.php';

$failed = 0;
function check( $label, $ok ) {
	global $failed;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}

function make_lead(): object {
	return (object) array(
		'id' => 7, 'lead_uuid' => 'uuid-7', 'name' => 'Lead Uno', 'email' => 'uno@example.test', 'phone' => '+56911112222',
		'project_slug' => 'proyecto-a', 'assigned_operator_name' => '', 'status' => 'NEW', 'quality' => '',
		'assigned_operator_id' => 0, 'first_session_id' => 'sid-1',
		'first_seen_at' => '2026-07-01 00:00:00', 'last_seen_at' => '2026-07-01 00:00:00',
		'created_at' => '2026-07-01 00:00:00', 'first_contact_at' => '', 'last_commercial_action' => '',
		'sales_stage' => '', 'lead_intent' => 'lead_capture', 'is_valid_lead' => null, 'invalid_reason' => '',
		'would_buy_again' => '', 'score_components_json' => '{}', 'behavioral_score' => null,
		'temperature' => '', 'priority' => '', 'score_updated_at' => '', 'feedback_status' => '',
		'feedback_completed' => 0, 'assigned_at' => '', 'contact_attempts' => 0, 'experience_data_json' => '',
		'first_landing_url' => 'https://example.test/proyecto-b/landing/',
		'first_utm_campaign' => 'utm-campaign-x',
	);
}

function make_submission( int $id, array $o = array() ): object {
	return (object) array_merge( array(
		'id' => $id, 'lead_id' => 7, 'source_plugin' => 'estandar-forms', 'source_form_id' => 'f1',
		'submitted_at' => '2026-07-0' . $id . ' 00:00:00', 'created_at' => '2026-07-0' . $id . ' 00:00:00',
		'name' => 'Lead Uno', 'email' => 'uno@example.test', 'phone' => '+56911112222',
		'email_normalized' => '', 'phone_normalized' => '',
		'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'utm-campaign-x',
		'utm_content' => '', 'gclid' => '', 'gbraid' => '', 'wbraid' => '', 'fbclid' => '',
		'session_id' => 'sid-1', 'landing_url' => '', 'referrer' => '',
		'context_json' => '{"project_slug":"proyecto-a"}',
		'form_fields_json' => '{}',
	), $o );
}

// ── CASE A — FALSE POSITIVE GUARD ─────────────────────────────────────────────
// One lead, one submission, one normalized identity. Landing basename and
// session campaign BOTH mismatch the project/campaign — previously this
// produced an "Identity Collision Warning". It must not.
$GLOBALS['test_lead'] = make_lead();
$GLOBALS['test_submissions'] = array( make_submission( 1 ) );

$md = Estandar_Leads_AI_Context::build_for_lead( 7 );
check( 'A: no Identity Collision Warning', strpos( $md, 'Identity Collision Warning' ) === false );
check( 'A: attribution notice present (campaign mismatch)', strpos( $md, 'Attribution Consistency Notice' ) !== false && strpos( $md, 'cmp-999' ) !== false );
check( 'A: attribution notice is informational', strpos( $md, 'INFO (informational' ) !== false );
check( 'A: default commercial policy exposed', strpos( $md, 'Routing Decision:** ROUTE (default policy)' ) !== false );

// ── CASE A2 — cosmetic differences are not a collision ───────────────────────
// Same identity written with different case/spacing/format across 2 submissions.
$GLOBALS['test_submissions'] = array(
	make_submission( 1, array( 'email' => 'Uno@Example.Test ', 'phone' => '+56 9 1111 2222' ) ),
	make_submission( 2, array( 'email' => 'uno@example.test', 'phone' => '56911112222' ) ),
);
$md = Estandar_Leads_AI_Context::build_for_lead( 7 );
check( 'A2: cosmetic variants → no collision', strpos( $md, 'Identity Collision Warning' ) === false );

// ── CASE A3 — different campaign ONLY → never a collision ────────────────────
$GLOBALS['test_submissions'] = array(
	make_submission( 1 ),
	make_submission( 2, array( 'utm_campaign' => 'utm-campaign-otro' ) ),
);
$md = Estandar_Leads_AI_Context::build_for_lead( 7 );
check( 'A3: campaign-only difference → no collision', strpos( $md, 'Identity Collision Warning' ) === false );
check( 'A3: campaign-only difference → no contact variation', strpos( $md, 'Contact Variation Notice' ) === false );

// ── CASE A4 — different landing ONLY → never a collision ─────────────────────
$GLOBALS['test_submissions'] = array(
	make_submission( 1 ),
	make_submission( 2, array( 'landing_url' => 'https://example.test/otra-landing/' ) ),
);
$md = Estandar_Leads_AI_Context::build_for_lead( 7 );
check( 'A4: landing-only difference → no collision', strpos( $md, 'Identity Collision Warning' ) === false );
check( 'A4: landing-only difference → no contact variation', strpos( $md, 'Contact Variation Notice' ) === false );

// ── CASE B — TRUE POSITIVE ────────────────────────────────────────────────────
// Two submissions consolidated on one lead (same phone) with DIFFERENT emails.
$GLOBALS['test_submissions'] = array(
	make_submission( 1 ),
	make_submission( 2, array( 'email' => 'otra-persona@example.test', 'name' => 'Otra Persona' ) ),
);
$md = Estandar_Leads_AI_Context::build_for_lead( 7 );
check( 'B: collision reported', strpos( $md, 'Identity Collision Warning' ) !== false );
check( 'B: shared phone anchor listed', strpos( $md, 'Shared phone: 56911112222' ) !== false );
check( 'B: conflicting emails listed', strpos( $md, 'uno@example.test | otra-persona@example.test' ) !== false );
check( 'B: conflicting names listed', strpos( $md, 'Lead Uno | Otra Persona' ) !== false );
check( 'B: reason mentions normalized identities', strpos( $md, 'Conflicting normalized identities' ) !== false );

// ── CASE B2 — same phone, different NAMES only (same email) → collision ──────
$GLOBALS['test_submissions'] = array(
	make_submission( 1 ),
	make_submission( 2, array( 'name' => 'Nombre Distinto' ) ),
);
$md = Estandar_Leads_AI_Context::build_for_lead( 7 );
check( 'B2: same phone + different names → collision', strpos( $md, 'Identity Collision Warning' ) !== false );
check( 'B2: conflicting names listed', strpos( $md, 'Lead Uno | Nombre Distinto' ) !== false );

// ── CASE C — same email, DIFFERENT phones → Contact Variation, NOT collision ─
// Canonical rule requires a SHARED phone with conflicting names/emails;
// distinct phones with one consistent identity are a contact variation.
$GLOBALS['test_submissions'] = array(
	make_submission( 1 ),
	make_submission( 2, array( 'phone' => '+56933334444' ) ),
);
$md = Estandar_Leads_AI_Context::build_for_lead( 7 );
check( 'C: different phones, same identity → no collision', strpos( $md, 'Identity Collision Warning' ) === false );
check( 'C: contact variation notice present', strpos( $md, 'Contact Variation Notice' ) !== false );
check( 'C: variant phones listed', strpos( $md, '56911112222 | 56933334444' ) !== false );
check( 'C: variation is informational', strpos( $md, 'not an identity conflict, not a failure' ) !== false );

echo "\n" . ( $failed === 0 ? "ALL PASS\n" : $failed . " FAILED\n" );
exit( $failed === 0 ? 0 : 1 );
