<?php
/** Run: php tests/run-commercial-journey-phase-1.php */

define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_title($value) { return trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', (string) $value)), '-'); }
function apply_filters($hook, $value) { return $value; }
function add_action() {}
function wp_generate_uuid4() {
    static $i = 0;
    $i++;
    return sprintf('00000000-0000-4000-8000-%012d', $i);
}
function wp_json_encode($value) { return json_encode($value); }

final class JourneyPhase1Wpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $source_type = 'lead_created';
    public $source_id = 10;
    public $rows = array();

    public function prepare($query, ...$args) { return array('query' => $query, 'args' => $args); }
    public function get_row($prepared, $format = null) {
        return array(
            'id' => $this->source_id,
            'lead_id' => 7,
            'submission_id' => $this->source_type === 'lead_created' ? null : 21,
            'operator_id' => strpos($this->source_type, 'manual') !== false ? 9 : null,
            'event_type' => $this->source_type,
            'from_status' => 'NEW',
            'to_status' => 'QUALIFIED',
            'reason' => 'fixture',
            'metadata' => json_encode(array(
                'actor_type' => strpos($this->source_type, 'manual') !== false ? 'operator' : 'system',
                'actor_id' => strpos($this->source_type, 'manual') !== false ? 9 : null,
                'qualification' => array(
                    'decision' => 'qualified',
                    'qualification' => 'standard',
                    'commercial_fit' => 'fit',
                    'matched_rules' => array('rule-1'),
                ),
                'overridden' => array('decision'),
            )),
            'created_at' => '2026-07-12 12:00:00',
            'lead_uuid' => '11111111-1111-4111-8111-111111111111',
            'project_slug' => 'alpha',
            'first_source_plugin' => 'estandar-forms',
            'submission_source_plugin' => 'estandar-forms',
        );
    }
    public function get_var($prepared) {
        $key = $prepared['args'][0] ?? '';
        return isset($this->rows[$key]) ? $this->rows[$key]['id'] : null;
    }
    public function insert($table, $row) {
        if (isset($this->rows[$row['idempotency_key']])) {
            return false;
        }
        $this->insert_id++;
        $row['id'] = $this->insert_id;
        $this->rows[$row['idempotency_key']] = $row;
        return true;
    }
}

$GLOBALS['wpdb'] = new JourneyPhase1Wpdb();
require dirname(__DIR__) . '/includes/class-estandar-leads-commercial-journey.php';

$passed = 0;
$failed = 0;
function check($label, $condition) {
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS  {$label}\n"; }
    else { $failed++; echo "FAIL  {$label}\n"; }
}

$aliases = array(
    'lead_created' => 'lead.created',
    'lead_recontacted' => 'lead.recontacted',
    'qualification_completed' => 'classification.automatic.recorded',
    'qualification_review' => 'classification.automatic.recorded',
    'qualification_manual_override' => 'classification.human.recorded',
    'status_changed' => 'lead.status.changed',
    'status_overridden' => 'lead.status.changed',
);
foreach ($aliases as $source => $canonical) {
    check("maps {$source}", Estandar_Leads_Commercial_Journey::canonical_name($source) === $canonical);
}
check('ignores events outside phase 1', Estandar_Leads_Commercial_Journey::canonical_name('quality_changed') === '');

$db = $GLOBALS['wpdb'];
$first_id = Estandar_Leads_Commercial_Journey::record(10, 7, 'lead_created', array());
$retry_id = Estandar_Leads_Commercial_Journey::record(10, 7, 'lead_created', array());
check('writes one auditable shadow event', count($db->rows) === 1 && $first_id > 0);
check('retry returns existing row', $retry_id === $first_id && count($db->rows) === 1);
$lead_row = reset($db->rows);
$envelope = json_decode($lead_row['envelope_json'], true);
check('envelope v1 has stable source and correlation',
    $envelope['version'] === 1
    &&
    $envelope['event_version'] === 1
    && $envelope['source_event_id'] === 10
    && $envelope['correlation_id'] === 'lead:7'
    && strlen($envelope['idempotency_key']) === 64
);
check('envelope v1 exposes frozen contract fields',
    array_diff(array('version', 'event_id', 'occurred_at', 'lead_id', 'event_type', 'origin', 'actor', 'payload', 'metadata'), array_keys($envelope)) === array()
);

$db->source_type = 'qualification_completed';
$db->source_id = 11;
Estandar_Leads_Commercial_Journey::record(11, 7, 'qualification_completed', array());
$db->source_type = 'qualification_manual_override';
$db->source_id = 12;
Estandar_Leads_Commercial_Journey::record(12, 7, 'qualification_manual_override', array());
check('automatic and human classifications remain separate', count($db->rows) === 3);
$names = array_column(array_values($db->rows), 'event_name');
check('classification event names remain distinct',
    in_array('classification.automatic.recorded', $names, true)
    && in_array('classification.human.recorded', $names, true)
);

$sequences = array_column(array_values($db->rows), 'sequence_no');
check('source ids preserve per-lead order', $sequences === array(10, 11, 12));

$schema = file_get_contents(dirname(__DIR__) . '/includes/class-estandar-leads-schema.php');
check('schema migration creates Journey ledger', strpos($schema, 'es_commercial_journey_events') !== false);
check('schema enforces event and idempotency uniqueness',
    strpos($schema, 'UNIQUE KEY event_id (event_id)') !== false
    && strpos($schema, 'UNIQUE KEY idempotency_key (idempotency_key)') !== false
);

$catalog = array(
    array('lead_created', '', 'LeadCaptured'),
    array('lead_recontacted', '', 'LeadRecontacted'),
    array('qualification_completed', '', 'LeadQualifiedAI'),
    array('qualification_manual_override', '', 'LeadQualifiedOperator'),
    array('status_changed', 'CONTACTED', 'LeadContacted'),
    array('status_changed', 'WON', 'LeadWon'),
    array('status_changed', 'LOST', 'LeadLost'),
    array('status_changed', 'DISQUALIFIED', 'LeadDisqualified'),
);
foreach ($catalog as $item) {
    check("catalog {$item[2]}", Estandar_Leads_Commercial_Journey::catalog_event($item[0], $item[1]) === $item[2]);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
