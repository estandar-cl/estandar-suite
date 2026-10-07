<?php
/**
 * SEMANTIC_STATUS_V1 (Lead #478) — suite de estados semánticos del AI Context.
 * Run: php tests/run-semantic-status.php
 *
 * Vocabulario único: PASS · PENDING · SKIPPED · WARNING · FAIL.
 * - Lead awaiting_contact: Commercial Contact / Qualification / Experience =
 *   PENDING, nunca FAIL sin fallo real.
 * - Intent presente + sales_stage vacío: Sales stage = PENDING, Lead intent
 *   visible, y Missing Context NO lista Sales Stage (sin contradicción).
 * - Intent ausente + sales_stage vacío: no se infiere Sales Stage PENDING.
 *   Missing Context ya no se renderiza en el export canónico v5.1.
 * - Automation events: failed/no_email histórico = [SKIPPED]; failed real
 *   (send_error) = [FAIL]; autoresponse_skipped nuevo = [SKIPPED].
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

class Estandar_Forms_Dataset_Provider {
	public static function get( $dataset ) { return array(); }
}
class Estandar_Leads_Compliance {
	public static function hours_since_public( string $datetime ): int { return 50; }
}

class Semantic_Test_DB {
	public $prefix = 'wp_';
	public $lead_intent = 'lead_capture';
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
			// Lead awaiting_contact: contact_attempts=0, sin quality, sin experience.
			return (object) array(
				'id' => 478, 'lead_uuid' => 'uuid-478', 'name' => 'Lead', 'email' => '', 'phone' => '+56911111111',
				'project_slug' => 'project-a', 'assigned_operator_name' => '', 'status' => 'NEW', 'quality' => '',
				'assigned_operator_id' => 0, 'first_session_id' => 'sid-478',
				'first_seen_at' => '2026-07-01 00:00:00', 'last_seen_at' => '2026-07-01 00:00:00',
				'created_at' => '2026-07-01 00:00:00', 'first_contact_at' => '', 'last_commercial_action' => '',
				'sales_stage' => '', 'lead_intent' => $this->lead_intent, 'is_valid_lead' => null, 'invalid_reason' => '',
				'would_buy_again' => '', 'score_components_json' => '{}', 'behavioral_score' => null,
				'temperature' => '', 'priority' => '', 'score_updated_at' => '', 'feedback_status' => 'pending',
				'feedback_completed' => 0, 'assigned_at' => '', 'contact_attempts' => 0, 'experience_data_json' => '',
			);
		}
		return null;
	}
	public function get_results( $q ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'FROM wp_es_lead_submissions' ) !== false && strpos( $sql, 'ORDER BY created_at ASC' ) !== false ) {
			return array( (object) array(
				'id' => 21, 'lead_id' => 478, 'source_plugin' => 'estandar-chatbot', 'source_form_id' => 'chatbot',
				'submitted_at' => '2026-07-01 00:00:00', 'created_at' => '2026-07-01 00:00:00',
				'utm_source' => 'meta', 'utm_medium' => 'paid', 'utm_campaign' => 'camp-1',
				'utm_content' => '', 'gclid' => '', 'gbraid' => '', 'wbraid' => '',
				'fbclid' => 'fb-1', 'session_id' => 'sid-478',
				'context_json' => '{"intent":"lead_capture"}',
				'form_fields_json' => '{"nombre":"Lead","telefono":"+56911111111","intent":"lead_capture"}',
			) );
		}
		if ( strpos( $sql, 'FROM wp_es_lead_events' ) !== false && strpos( $sql, 'autoresponse' ) !== false ) {
			return array(
				(object) array(
					'created_at' => '2026-07-01 00:01:00', 'event_type' => 'autoresponse_email_failed',
					'metadata' => '{"reason":"no_email"}',
				),
				(object) array(
					'created_at' => '2026-07-01 00:02:00', 'event_type' => 'autoresponse_skipped',
					'metadata' => '{"reason":"no_email"}',
				),
				(object) array(
					'created_at' => '2026-07-01 00:03:00', 'event_type' => 'autoresponse_email_failed',
					'metadata' => '{"reason":"send_error"}',
				),
			);
		}
		return array();
	}
}

$GLOBALS['wpdb'] = new Semantic_Test_DB();
require dirname( __DIR__ ) . '/includes/class-estandar-leads-attribution.php';
require dirname( __DIR__ ) . '/includes/class-estandar-leads-ai-context.php';

$md = Estandar_Leads_AI_Context::build_for_lead( 478 );

// Consistency Checks: aislar la sección para no matchear otros bloques.
$checks_start = strpos( $md, 'Consistency Checks' );
$checks_block = $checks_start !== false ? substr( $md, $checks_start, 1200 ) : '';
$line = static function ( $label ) use ( $checks_block ) {
	if ( preg_match( '/^' . preg_quote( $label, '/' ) . ' \.+ (.+)$/m', $checks_block, $m ) ) {
		return trim( $m[1] );
	}
	return '';
};

$checks = array(
	'awaiting_contact: Commercial Contact = PENDING' => $line( 'Commercial Contact' ) === 'PENDING',
	'awaiting_contact: Qualification = PENDING'      => $line( 'Qualification' ) === 'PENDING',
	'awaiting_contact: Experience = PENDING'         => $line( 'Experience' ) === 'PENDING',
	'no FAIL sin fallo real (checks)'                => ! preg_match( '/ \.+ FAIL\b/', $checks_block ),
	'Submission Linked = PASS'                       => $line( 'Submission Linked' ) === 'PASS',
	'evidencia ausente = WARNING, no FAIL'           => $line( 'Operator Assigned' ) === 'WARNING' && $line( 'Scoring Available' ) === 'WARNING',
	'pipeline sin NO DATA (vocabulario)'             => strpos( $md, 'NO DATA' ) === false,
	'pipeline: qualification PENDING'                => strpos( $md, "Qualification Engine — PENDING" ) !== false,
	'intent + stage vacío: Sales stage = PENDING'    => strpos( $md, 'Sales stage:** PENDING' ) !== false,
	'intent visible'                                 => strpos( $md, 'Lead intent:** lead_capture' ) !== false,
	'Missing Context no lista Sales Stage'           => ! preg_match( '/^- Sales Stage$/m', $md ),
	'automation: failed/no_email histórico = SKIPPED' => substr_count( $md, '[SKIPPED]' ) >= 2,
	'automation: autoresponse_skipped label'         => strpos( $md, 'Autoresponse skipped' ) !== false,
	'automation: send_error real = FAIL'             => strpos( $md, '[FAIL]' ) !== false,
);

// Contrafactual: sin intent no se infiere Sales Stage PENDING. Missing Context
// fue retirado del render canónico v5.1 (COMMERCIAL_AI_CONTEXT_FIXES_V1 §10).
$db2 = new Semantic_Test_DB();
$db2->lead_intent = '';
$GLOBALS['wpdb'] = $db2;
$md2 = Estandar_Leads_AI_Context::build_for_lead( 478 );
$checks['sin intent: Missing Context no se renderiza (v5.1)'] = strpos( $md2, '## Missing Context' ) === false;
$checks['sin intent: no hay Sales stage PENDING inferido'] = strpos( $md2, 'Sales stage:** PENDING' ) === false;

$failed = 0;
foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}
echo $failed === 0 ? "ALL PASS\n" : "$failed FAILED\n";
exit( $failed === 0 ? 0 : 1 );
