<?php
/**
 * ROUTING_NOTIFICATION_EXPLAINABILITY_V1
 * Run: php tests/run-ai-context-notification-explainability.php
 *
 * Covers the Notification Delivery / Behavior Evidence blocks added to the
 * AI Context export (class-estandar-leads-ai-context.php) and the fix that
 * makes Routing Decision sourced exclusively from operator_assigned.
 * Standalone runner (no framework), mirrors the mocking pattern used by
 * tests/run-ai-context-export.php but with its own lightweight $wpdb double
 * so es_lead_events can be scripted per scenario.
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
	public static function hours_since_public( string $datetime ): int { return 1; }
}

/**
 * Minimal $wpdb double. `events` is an array of associative rows the test
 * pre-loads per scenario; get_results() returns them filtered/ordered the
 * same way the real query does (event_type IN (...) ORDER BY created_at ASC).
 */
class Notif_Test_DB {
	public $prefix = 'wp_';
	public $events = array();

	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function suppress_errors( $v ) { return false; }
	public function get_col( $q ) { return array(); }
	public function get_var( $q ) { return null; }

	public function get_row( $q ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'FROM wp_es_leads' ) !== false ) {
			return (object) array(
				'id' => 1, 'lead_uuid' => 'uuid-1', 'name' => 'Lead', 'email' => 'l@example.test', 'phone' => '123',
				'project_slug' => 'project-a', 'assigned_operator_name' => 'Op A', 'status' => 'NEW', 'quality' => '',
				'assigned_operator_id' => 9, 'first_session_id' => '',
				'first_seen_at' => '2026-07-01 00:00:00', 'last_seen_at' => '2026-07-01 00:00:00',
				'created_at' => '2026-07-01 00:00:00', 'first_contact_at' => '', 'last_commercial_action' => '',
				'sales_stage' => '', 'lead_intent' => '', 'is_valid_lead' => null, 'invalid_reason' => '',
				'would_buy_again' => '', 'score_components_json' => '{}', 'behavioral_score' => null,
				'temperature' => '', 'priority' => '', 'score_updated_at' => '',
				'feedback_status' => 'overdue',
				'feedback_completed' => 0, 'assigned_at' => '', 'contact_attempts' => 0, 'experience_data_json' => '',
				'first_landing_url' => '',
			);
		}
		return null;
	}

	public function get_results( $q ) {
		$sql = is_array( $q ) ? $q['q'] : $q;
		if ( strpos( $sql, 'FROM wp_es_lead_submissions' ) !== false ) {
			return array(); // no submissions needed for these scenarios
		}
		if ( strpos( $sql, 'FROM wp_es_lead_events' ) !== false ) {
			$rows = array();
			foreach ( $this->events as $ev ) {
				$rows[] = (object) array(
					'event_type'    => $ev['type'],
					'metadata'      => wp_json_encode( $ev['meta'] ?? array() ),
					'created_at'    => $ev['created'],
					'operator_id'   => $ev['operator_id'] ?? null,
					'submission_id' => $ev['submission_id'] ?? null,
					'to_status'     => $ev['to_status'] ?? '',
					'from_status'   => $ev['from_status'] ?? '',
					'reason'        => $ev['reason'] ?? '',
				);
			}
			usort( $rows, static function ( $a, $b ) { return strcmp( $a->created_at, $b->created_at ); } );
			return $rows;
		}
		return array();
	}
}

$GLOBALS['wpdb'] = new Notif_Test_DB();
require dirname( __DIR__ ) . '/includes/class-estandar-leads-attribution.php';
require dirname( __DIR__ ) . '/includes/class-estandar-leads-qa-policy.php';
require dirname( __DIR__ ) . '/includes/class-estandar-leads-ai-context.php';

$checks = array();

// ── 1. Immediate assignment + immediate notification ─────────────────────
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'operator_assigned', 'submission_id' => 101, 'operator_id' => 9,
		'meta' => array( 'project_slug' => 'project-a', 'routing_mode' => 'project_match' ), 'created' => '2026-07-01 10:00:00' ),
	array( 'type' => 'operator_notified', 'submission_id' => 101, 'operator_id' => 9,
		'meta' => array( 'routing_mode' => 'project', 'project_slug' => 'project-a' ), 'created' => '2026-07-01 10:00:05' ),
);
$md1 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['immediate assignment + notification: routing sourced from operator_assigned'] =
	strpos( $md1, 'Latest routing_mode:** project_match' ) !== false;
$checks['immediate assignment + notification: routing_mode NOT overwritten by operator_notified (project)'] =
	strpos( $md1, 'routing_mode: **project**' ) === false;
$checks['immediate assignment + notification: source note present'] =
	strpos( $md1, 'operator_notified never overwrites this decision' ) !== false;
$checks['immediate assignment + notification: delivery source = immediate'] =
	strpos( $md1, '**Delivery source:** immediate' ) !== false;
$checks['immediate assignment + notification: delivered status'] =
	strpos( $md1, '**Status:** delivered' ) !== false;

// ── 2. Notification delayed by business hours (policy, not a problem) ────
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'operator_assigned', 'submission_id' => 102, 'operator_id' => 9,
		'meta' => array( 'project_slug' => 'project-a', 'routing_mode' => 'allowed_project_match' ), 'created' => '2026-07-03 19:00:00' ),
	array( 'type' => 'operator_notification_deferred', 'submission_id' => 102,
		'meta' => array( 'reason' => 'outside_operational_hours', 'deferred_minutes' => 660, 'scheduled_for' => '2026-07-04 09:00:00' ), 'created' => '2026-07-03 19:00:01' ),
	array( 'type' => 'operator_notified', 'submission_id' => 102, 'operator_id' => 9,
		'meta' => array( 'routing_mode' => 'project', 'project_slug' => 'project-a' ), 'created' => '2026-07-04 09:00:02' ),
);
$md2 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['business-hours delay: delivery source = business_hours_queue'] =
	strpos( $md2, '**Delivery source:** business_hours_queue' ) !== false;
$checks['business-hours delay: reason surfaced'] =
	strpos( $md2, 'outside_operational_hours' ) !== false;
$checks['business-hours delay: framed as POLICY not a problem'] =
	strpos( $md2, 'delivery POLICY (business hours), not a problem' ) !== false;
$checks['business-hours delay: delay computed'] =
	strpos( $md2, '**Delay:** 14h0m' ) !== false;

// ── 3. Real retry (requeue) evidence: 2 deferred events before delivery ──
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'operator_assigned', 'submission_id' => 103, 'operator_id' => 9,
		'meta' => array( 'project_slug' => 'project-a', 'routing_mode' => 'project_match' ), 'created' => '2026-07-05 08:00:00' ),
	array( 'type' => 'operator_notification_deferred', 'submission_id' => 103,
		'meta' => array( 'reason' => 'outside_operational_hours', 'scheduled_for' => '2026-07-05 09:00:00' ), 'created' => '2026-07-05 08:00:01' ),
	array( 'type' => 'operator_notification_deferred', 'submission_id' => 103,
		'meta' => array( 'reason' => 'outside_operational_hours', 'scheduled_for' => '2026-07-05 10:00:00' ), 'created' => '2026-07-05 09:05:00' ),
	array( 'type' => 'operator_notified', 'submission_id' => 103, 'operator_id' => 9,
		'meta' => array( 'routing_mode' => 'project' ), 'created' => '2026-07-05 10:00:03' ),
);
$md3 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['retry evidence: requeue count surfaced (2x), not fabricated'] =
	strpos( $md3, 'requeued 2x' ) !== false;
$checks['retry evidence: honest note (real re-queueing, not a fabricated retry count)'] =
	strpos( $md3, 'real re-queueing evidence' ) !== false;

// ── 4. Absence of telemetry: no notification events at all ───────────────
$GLOBALS['wpdb']->events = array();
$md4 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['absence of telemetry: explicit absence, not fabricated status'] =
	strpos( $md4, 'No notification delivery events recorded for this lead.' ) !== false
	&& strpos( $md4, 'No autoresponse/behavior telemetry recorded for this lead.' ) !== false;

// ── 5. Routing project_match preserved after notification (no overwrite) ─
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'operator_assigned', 'submission_id' => 105, 'operator_id' => 9,
		'meta' => array( 'project_slug' => 'project-a', 'routing_mode' => 'allowed_project_match' ), 'created' => '2026-07-06 11:00:00' ),
	array( 'type' => 'operator_notified', 'submission_id' => 105, 'operator_id' => 9,
		'meta' => array( 'routing_mode' => 'fallback', 'project_slug' => 'project-a' ), 'created' => '2026-07-06 11:00:05' ),
);
$md5 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['operator_notified does not overwrite operator_assigned routing_mode'] =
	strpos( $md5, 'Latest routing_mode:** allowed_project_match' ) !== false
	&& strpos( $md5, 'Latest routing_mode:** fallback' ) === false;

// ── 6. autoresponse_engaged explained with its evidence ───────────────────
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'autoresponse_email_sent', 'submission_id' => 106,
		'meta' => array(), 'created' => '2026-07-07 08:00:00' ),
	array( 'type' => 'autoresponse_link_click', 'submission_id' => 106,
		'meta' => array( 'resource_type' => 'pdf' ), 'created' => '2026-07-07 08:05:00' ),
	array( 'type' => 'autoresponse_engaged', 'submission_id' => 106,
		'meta' => array( 'channel' => 'pdf' ), 'created' => '2026-07-07 08:05:00' ),
);
$md6 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['autoresponse_engaged cites its triggering evidence (channel)'] =
	strpos( $md6, 'autoresponse_engaged** [2026-07-07 08:05:00] — evidence: first `pdf` interaction' ) !== false;

// ── 7. Repeated clicks vs unique links: distinct resource types, no fabricated URL uniqueness ─
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'autoresponse_link_click', 'submission_id' => 107, 'meta' => array( 'resource_type' => 'pdf' ), 'created' => '2026-07-08 08:00:00' ),
	array( 'type' => 'autoresponse_link_click', 'submission_id' => 107, 'meta' => array( 'resource_type' => 'pdf' ), 'created' => '2026-07-08 08:01:00' ),
	array( 'type' => 'autoresponse_link_click', 'submission_id' => 107, 'meta' => array( 'resource_type' => 'landing' ), 'created' => '2026-07-08 08:02:00' ),
);
$md7 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['repeated clicks counted (3 total)'] = strpos( $md7, '**Link clicks (total events):** 3' ) !== false;
$checks['distinct resource types = 2 (pdf, landing), not fabricated per-URL uniqueness'] =
	strpos( $md7, '**Distinct resource types clicked:** 2' ) !== false
	&& strpos( $md7, 'per-URL uniqueness is not calculable' ) !== false;

// ── 8. Historical export (old leads without these new fields) must not break ─
$GLOBALS['wpdb']->events = array(
	// Pre-ROUTING_MATCH_V2 shape: operator_assigned with no routing_mode key at all.
	array( 'type' => 'operator_assigned', 'submission_id' => 108, 'operator_id' => 9,
		'meta' => array( 'project_slug' => 'project-a' ), 'created' => '2025-01-01 10:00:00' ),
);
$md8 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['historical export: no routing_mode renders NULL, does not fatal'] =
	strpos( $md8, 'Latest routing_mode:** NULL' ) !== false;
$checks['historical export: absence of notification events surfaces explicitly'] =
	strpos( $md8, 'No notification delivery events recorded for this lead.' ) !== false;

// ── 9. No duplication between the new blocks and Timeline/Score Breakdown/Raw Sources ─
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'operator_assigned', 'submission_id' => 109, 'operator_id' => 9,
		'meta' => array( 'project_slug' => 'project-a', 'routing_mode' => 'project_match' ), 'created' => '2026-07-09 08:00:00' ),
	array( 'type' => 'operator_notified', 'submission_id' => 109, 'operator_id' => 9,
		'meta' => array( 'routing_mode' => 'project' ), 'created' => '2026-07-09 08:00:05' ),
);
$md9 = Estandar_Leads_AI_Context::build_for_lead( 1 );
// The new headings must exist exactly once each (no accidental duplication of the block itself).
$checks['Notification Delivery block appears exactly once'] =
	substr_count( $md9, '### Notification Delivery (v1)' ) === 1;
$checks['Behavior Evidence block appears exactly once'] =
	substr_count( $md9, '### Behavior Evidence (v1)' ) === 1;
// Raw Sources / Score Breakdown / Timeline headings still present and untouched by this change.
$checks['Raw Sources section still present'] = strpos( $md9, '## 11. Raw Sources' ) !== false;
$checks['Timeline section still present'] = strpos( $md9, '### Timeline (merged chronological)' ) !== false;

// ── NOTIFICATION_POLICY_VS_DELIVERY_V1 hardening pass ─────────────────────
// Confirms Routing Decision / Notification Policy / Notification Delivery
// render as three independent blocks, each field owned by exactly one of
// them (policy reason text never repeated inside Delivery; Delivery status
// uses only the canonical vocabulary).

// ── 10. Routing immediate + notification deferred (policy vs delivery split) ─
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'operator_assigned', 'submission_id' => 110, 'operator_id' => 9,
		'meta' => array( 'project_slug' => 'project-a', 'routing_mode' => 'project_match' ), 'created' => '2026-07-10 19:00:00' ),
	array( 'type' => 'operator_notification_deferred', 'submission_id' => 110,
		'meta' => array( 'reason' => 'outside_operational_hours', 'scheduled_for' => '2026-07-11 09:00:00' ), 'created' => '2026-07-10 19:00:01' ),
);
$md10 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['3 blocks present: Routing Decision, Notification Policy, Notification Delivery'] =
	substr_count( $md10, '### Routing Decision (v5.1)' ) === 1
	&& substr_count( $md10, '### Notification Policy (v1)' ) === 1
	&& substr_count( $md10, '### Notification Delivery (v1)' ) === 1;
$checks['Notification Policy block carries the business-hours reason'] =
	strpos( $md10, '**Business Hours Gate:** applied — reason: `outside_operational_hours`' ) !== false;
$checks['Notification Delivery uses canonical status deferred_by_policy (not "queued")'] =
	strpos( $md10, '**Status:** deferred_by_policy' ) !== false
	&& strpos( $md10, '**Status:** queued' ) === false;
$checks['Notification Delivery points back to Policy instead of repeating the reason inline'] =
	strpos( $md10, 'see Notification Policy above for reason/schedule' ) !== false;
$checks['Routing Decision block does not contain notification policy reason text'] =
	( function () use ( $md10 ) {
		$routing_block = substr( $md10, strpos( $md10, '### Routing Decision (v5.1)' ), strpos( $md10, '### Notification Policy (v1)' ) - strpos( $md10, '### Routing Decision (v5.1)' ) );
		return strpos( $routing_block, 'outside_operational_hours' ) === false;
	} )();

// ── 11. Routing immediate + delivery immediate (no policy gate at all) ────
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'operator_assigned', 'submission_id' => 111, 'operator_id' => 9,
		'meta' => array( 'project_slug' => 'project-a', 'routing_mode' => 'project_match' ), 'created' => '2026-07-11 10:00:00' ),
	array( 'type' => 'operator_notified', 'submission_id' => 111, 'operator_id' => 9,
		'meta' => array( 'routing_mode' => 'project' ), 'created' => '2026-07-11 10:00:03' ),
);
$md11 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['no policy gate: Notification Policy states no gate intervened'] =
	strpos( $md11, 'No notification policy gate intervened for this lead' ) !== false;
$checks['no policy gate: Delivery status = delivered, source = immediate'] =
	strpos( $md11, '**Status:** delivered' ) !== false
	&& strpos( $md11, '**Delivery source:** immediate' ) !== false;

// ── 12. Multiple business-hours defers (requeue) — Policy shows both gate ──
//        events; Delivery shows the requeue count without policy reason text ─
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'operator_assigned', 'submission_id' => 112, 'operator_id' => 9,
		'meta' => array( 'project_slug' => 'project-a', 'routing_mode' => 'project_match' ), 'created' => '2026-07-12 08:00:00' ),
	array( 'type' => 'operator_notification_deferred', 'submission_id' => 112,
		'meta' => array( 'reason' => 'outside_operational_hours', 'scheduled_for' => '2026-07-12 09:00:00' ), 'created' => '2026-07-12 08:00:01' ),
	array( 'type' => 'operator_notification_deferred', 'submission_id' => 112,
		'meta' => array( 'reason' => 'outside_operational_hours', 'scheduled_for' => '2026-07-12 10:00:00' ), 'created' => '2026-07-12 09:05:00' ),
	array( 'type' => 'operator_notified', 'submission_id' => 112, 'operator_id' => 9,
		'meta' => array( 'routing_mode' => 'project' ), 'created' => '2026-07-12 10:00:03' ),
);
$md12 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['multiple defers: Notification Policy shows both gate applications'] =
	substr_count( $md12, '**Business Hours Gate:** applied' ) === 2;
$checks['multiple defers: Notification Delivery shows requeue count, delivered'] =
	strpos( $md12, 'requeued 2x' ) !== false && strpos( $md12, '**Status:** delivered' ) !== false;

// ── 13. Absence of delivery evidence: routing exists, no notification family ─
//         events at all — Policy and Delivery both surface honest absence ──
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'operator_assigned', 'submission_id' => 113, 'operator_id' => 9,
		'meta' => array( 'project_slug' => 'project-a', 'routing_mode' => 'project_match' ), 'created' => '2026-07-13 08:00:00' ),
);
$md13 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['absence of delivery: routing still renders (independent of notification)'] =
	strpos( $md13, 'Latest routing_mode:** project_match' ) !== false;
$checks['absence of delivery: Notification Policy states no gate intervened'] =
	strpos( $md13, 'No notification policy gate intervened for this lead' ) !== false;
$checks['absence of delivery: Notification Delivery states no events recorded'] =
	strpos( $md13, 'No notification delivery events recorded for this lead.' ) !== false;
$checks['absence of delivery: no fabricated "delivered" or "failed" status'] =
	strpos( $md13, '**Status:** delivered' ) === false && strpos( $md13, '**Status:** failed' ) === false;

// ── 14. Historical compatibility: pre-existing event types only, no new  ──
//         fields present, must not fatal and must not fabricate any of the  ─
//         5 canonical statuses that lack real evidence today (queued/       ─
//         scheduled/failed) ────────────────────────────────────────────────
$GLOBALS['wpdb']->events = array(
	array( 'type' => 'operator_assigned', 'submission_id' => 114, 'operator_id' => 9,
		'meta' => array( 'project_slug' => 'project-a' ), 'created' => '2024-01-01 10:00:00' ),
	array( 'type' => 'operator_notified', 'submission_id' => 114, 'operator_id' => 9,
		'meta' => array(), 'created' => '2024-01-01 10:00:05' ),
);
$md14 = Estandar_Leads_AI_Context::build_for_lead( 1 );
$checks['historical: old events without routing_mode render NULL, no fatal'] =
	strpos( $md14, 'Latest routing_mode:** NULL' ) !== false;
$checks['historical: delivered still recognized from bare operator_notified'] =
	strpos( $md14, '**Status:** delivered' ) !== false;
$checks['historical: unevidenced statuses (queued/scheduled/failed) never appear'] =
	strpos( $md14, '**Status:** queued' ) === false
	&& strpos( $md14, '**Status:** scheduled' ) === false
	&& strpos( $md14, '**Status:** failed' ) === false;

$failed = 0;
foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$failed += $ok ? 0 : 1;
}
echo "\n" . count( $checks ) . " checks, " . ( count( $checks ) - $failed ) . " passed, $failed failed.\n";
exit( $failed === 0 ? 0 : 1 );
