<?php
/**
 * Read-side invariant regression tests.
 * Run: php tests/run-invariants.php
 */

define('ABSPATH', __DIR__);

function absint($v) { return abs((int) $v); }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $v)); }
function sanitize_text_field($v) { return is_scalar($v) ? trim((string) $v) : ''; }
function sanitize_title($v) { return trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', (string) $v)), '-'); }
function remove_accents($v) { return $v; }

class FakeInvariantWpdb {
    public $prefix = 'wp_';
    public function prepare($q, ...$a) { return array('q' => $q, 'a' => $a); }
    public function get_col($q) {
        $sql = is_array($q) ? $q['q'] : $q;
        if (strpos($sql, 'DESC wp_es_leads') !== false) {
            return array('id', 'email_hash', 'phone_hash', 'rut', 'project_slug');
        }
        if (strpos($sql, 'LEFT JOIN wp_es_leads') !== false && strpos($sql, 'wp_es_lead_submissions') !== false) {
            return array(99);
        }
        if (strpos($sql, 'assigned_operator_id') !== false) {
            return array(4);
        }
        return array();
    }
    public function get_var($q) { return null; }
    public function get_results($q) {
        $sql = is_array($q) ? $q['q'] : $q;
        if (strpos($sql, 'COUNT(s.id) AS submission_count') !== false) {
            return array(
                (object) array('id' => 1, 'score_components_json' => '{"capture":80}', 'submission_count' => 1),
                (object) array('id' => 2, 'score_components_json' => '{}', 'submission_count' => 1),
            );
        }
        if (strpos($sql, 's.context_json') !== false && strpos($sql, 'lead_project_slug') !== false) {
            return array(
                (object) array('id' => 10, 'lead_id' => 1, 'context_json' => '{"project_slug":"alpha"}', 'lead_project_slug' => 'alpha'),
                (object) array('id' => 11, 'lead_id' => 2, 'context_json' => '{"project_slug":"beta"}', 'lead_project_slug' => 'alpha'),
            );
        }
        if (strpos($sql, 'wp_es_lead_events') !== false && strpos($sql, 'ORDER BY lead_id ASC, id ASC') !== false) {
            return array(
                (object) array('lead_id' => 1, 'id' => 1, 'created_at' => '2026-01-01 10:00:00'),
                (object) array('lead_id' => 1, 'id' => 2, 'created_at' => '2026-01-01 10:05:00'),
                (object) array('lead_id' => 3, 'id' => 3, 'created_at' => '2026-01-01 10:10:00'),
                (object) array('lead_id' => 3, 'id' => 4, 'created_at' => '2026-01-01 10:00:00'),
            );
        }
        if (strpos($sql, 'GROUP BY email_hash') !== false) {
            return array((object) array('identity_value' => 'e1', 'count' => 2, 'lead_ids' => '1,3'));
        }
        if (strpos($sql, 'GROUP BY phone_hash') !== false || strpos($sql, 'GROUP BY rut') !== false) {
            return array();
        }
        if (strpos($sql, 'wp_es_customers') !== false && strpos($sql, 'LEFT JOIN wp_es_leads') !== false) {
            return array(
                (object) array('id' => 7, 'matched_lead_id' => 1, 'project_slug' => 'alpha', 'lead_project_slug' => 'alpha'),
                (object) array('id' => 8, 'matched_lead_id' => 2, 'project_slug' => 'beta', 'lead_project_slug' => 'alpha'),
            );
        }
        return array();
    }
}

$GLOBALS['wpdb'] = new FakeInvariantWpdb();

// PROJECT_REGISTRY_ENFORCEMENT_V1 (RULE_04): resolve_slug() only recognizes
// slugs present in the Project Registry — an unregistered candidate resolves
// to '' by design. Without a registry stub every slug in the fixtures below
// would resolve to '', collapsing all precedence/mismatch checks. Stub the
// registry with exactly the slugs the fixtures reference.
class FakeInvariantProjectRegistry {
    public static function get_all() {
        return array(
            array('project_id' => 'alpha'),
            array('project_id' => 'beta'),
            array('project_id' => 'lead-project'),
            array('project_id' => 'submission-project'),
        );
    }
    public static function get_all_for_client($client_id) {
        return self::get_all();
    }
}
class_alias('FakeInvariantProjectRegistry', 'Estandar_Core_Project_Registry');

require dirname(__DIR__) . '/includes/class-estandar-leads-projects.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-repository.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-scoring.php';

$passed = 0;
$failed = 0;
function check($label, $cond) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS  $label\n"; }
    else { $failed++; echo "FAIL  $label\n"; }
}

$report = Estandar_Leads_Repository::health_report(20);

check('new lead: 1 lead supports N submissions', empty($report['issues']['submission_without_lead']) === false);
check('existing lead: orphan submission detected', in_array(99, $report['issues']['submission_without_lead'], true));
check('recurrent lead: duplicate identity detected', ($report['issues']['duplicate_identity'][0]['lead_ids'] ?? '') === '1,3');
check('merged lead: duplicate identity lead count', (int) ($report['issues']['duplicate_identity'][0]['count'] ?? 0) === 2);
check('customer match: valid match not reported, mismatch reported', count($report['issues']['customer_mismatch']) === 1 && ($report['issues']['customer_mismatch'][0]['customer_id'] ?? 0) === 8);
check('project consistency: canonical project detects mismatch', count($report['issues']['project_mismatch']) === 1 && ($report['issues']['project_mismatch'][0]['submission_id'] ?? 0) === 11);
check('capture consistency: capture null with submissions detected', in_array(2, $report['issues']['capture_null_with_submissions'], true));
check('timeline consistency: chronological disorder detected', count($report['issues']['timeline_disorder']) === 1);

$project = Estandar_Leads_Repository::canonical_project(
    (object) array('project_slug' => 'lead-project'),
    (object) array('context_json' => '{"project_slug":"submission-project"}')
);
check('one canonical project: submission context wins', $project === 'submission-project');

$score = Estandar_Leads_Scoring::calculate(array(
    'capture' => 100,
    'qualification' => null,
    'behavior_events' => array(),
    'is_valid_lead' => 1,
    'quality' => '',
    'status' => 'NEW',
    'sales_stage' => '',
    'gestion' => array(),
));
check('capture consistency: non-null capture persists in components', ($score['components']['capture'] ?? null) === 100);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
