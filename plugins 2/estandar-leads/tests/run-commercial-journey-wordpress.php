<?php
/** Integration test against the configured local WordPress database. Rolls back all fixtures. */

if (getenv('ESTANDAR_MYSQL_GATE_DB')) {
    require __DIR__ . '/bootstrap-wordpress-mysql.php';
} else {
    require dirname(__DIR__, 4) . '/wp-load.php';
}

global $wpdb;
$source_table = $wpdb->prefix . 'es_lead_events';
$journey_table = $wpdb->prefix . 'es_commercial_journey_events';
$passed = 0;
$failed = 0;

function journey_wp_check(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS  {$label}\n"; }
    else { $failed++; echo "FAIL  {$label}\n"; }
}

$table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $journey_table));
journey_wp_check('real WordPress schema contains Journey ledger', $table_exists === $journey_table);

$wpdb->query('START TRANSACTION');
$fixture_created = false;
$fixture = $wpdb->get_row(
    "SELECT l.id AS lead_id, s.id AS submission_id
     FROM {$wpdb->prefix}es_leads l
     LEFT JOIN {$wpdb->prefix}es_lead_submissions s ON s.lead_id = l.id
     ORDER BY l.id ASC, s.id ASC LIMIT 1",
    ARRAY_A
);
if (!is_array($fixture) || empty($fixture['lead_id'])) {
    $now = gmdate('Y-m-d H:i:s');
    $lead_uuid = wp_generate_uuid4();
    $wpdb->insert($wpdb->prefix . 'es_leads', array(
        'lead_uuid' => $lead_uuid,
        'status' => 'NEW',
        'first_source_plugin' => 'estandar-forms',
        'latest_source_plugin' => 'estandar-forms',
        'project_slug' => 'journey-gate',
        'first_seen_at' => $now,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ));
    $fixture_lead_id = absint($wpdb->insert_id);
    $wpdb->insert($wpdb->prefix . 'es_lead_submissions', array(
        'lead_id' => $fixture_lead_id,
        'lead_uuid' => $lead_uuid,
        'source_plugin' => 'estandar-forms',
        'schema_version' => '1.0',
        'submitted_at' => $now,
        'created_at' => $now,
    ));
    $fixture = array('lead_id' => $fixture_lead_id, 'submission_id' => absint($wpdb->insert_id));
    $fixture_created = true;
}

$lead_id = absint($fixture['lead_id']);
$submission_id = absint($fixture['submission_id'] ?? 0);
$source_ids = array();
$journey_ids = array();
try {
    $scenarios = array(
        array('lead_created', '', '', 'LeadCaptured', 'system', 0),
        array('lead_recontacted', '', '', 'LeadRecontacted', 'system', $submission_id),
        array('qualification_completed', '', 'QUALIFIED', 'LeadQualifiedAI', 'system', $submission_id),
        array('qualification_manual_override', '', 'QUALIFIED', 'LeadQualifiedOperator', 'operator', $submission_id),
        array('status_changed', 'NEW', 'CONTACTED', 'LeadContacted', 'operator', $submission_id),
        array('status_changed', 'CONTACTED', 'WON', 'LeadWon', 'operator', $submission_id),
        array('status_changed', 'CONTACTED', 'LOST', 'LeadLost', 'operator', $submission_id),
        array('status_changed', 'NEW', 'DISQUALIFIED', 'LeadDisqualified', 'operator', $submission_id),
    );

    foreach ($scenarios as $index => $scenario) {
        [$source_type, $from, $to, $catalog_event, $actor_type, $scenario_submission_id] = $scenario;
        $metadata = array('actor_type' => $actor_type, 'actor_id' => $actor_type === 'operator' ? 77 : null);
        if (strpos($source_type, 'qualification_') === 0) {
            $metadata['qualification'] = array(
                'decision' => 'qualified',
                'qualification' => 'standard',
                'commercial_fit' => 'fit',
                'matched_rules' => array('integration-fixture'),
            );
            if ($source_type === 'qualification_manual_override') {
                $metadata['overridden'] = array('decision');
            }
        }
        $source_id = Estandar_Leads_Repository::insert_event($lead_id, $source_type, array(
            'submission_id' => $scenario_submission_id,
            'operator_id' => $actor_type === 'operator' ? 77 : 0,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => 'journey_phase_1_integration_fixture',
            'metadata' => $metadata,
        ));
        $source_ids[] = $source_id;
        $journey = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$journey_table} WHERE source_event_id = %d LIMIT 1",
            $source_id
        ), ARRAY_A);
        journey_wp_check("{$catalog_event}: source and Journey rows correspond", is_array($journey) && absint($journey['lead_id']) === $lead_id);
        if (!is_array($journey)) {
            continue;
        }
        $journey_ids[] = absint($journey['id']);
        $envelope = json_decode((string) $journey['envelope_json'], true);
        $required = array('version', 'event_id', 'occurred_at', 'lead_id', 'event_type', 'origin', 'actor', 'payload', 'metadata');
        journey_wp_check("{$catalog_event}: envelope v1", is_array($envelope) && empty(array_diff($required, array_keys($envelope))) && $envelope['event_type'] === $catalog_event);
        journey_wp_check("{$catalog_event}: timestamp conserved", $journey['occurred_at'] === $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$source_table} WHERE id = %d", $source_id)));
        journey_wp_check("{$catalog_event}: actor and origin conserved", ($envelope['actor']['type'] ?? '') === $actor_type && absint($envelope['origin']['source_event_id'] ?? 0) === $source_id);
        $retry_id = Estandar_Leads_Commercial_Journey::record($source_id, $lead_id, $source_type, $metadata);
        $duplicates = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$journey_table} WHERE source_event_id = %d", $source_id));
        journey_wp_check("{$catalog_event}: idempotent without duplicate", $retry_id === absint($journey['id']) && $duplicates === 1);
    }

    $ordered = $journey_ids;
    sort($ordered, SORT_NUMERIC);
    journey_wp_check('Journey is append-only and chronologically ordered', $journey_ids === $ordered && count($journey_ids) === count($scenarios));
    journey_wp_check('one Journey row per mapped source event', count(array_unique($source_ids)) === count($source_ids) && count(array_unique($journey_ids)) === count($journey_ids));
} finally {
    $wpdb->query('ROLLBACK');
}

$source_remaining = empty($source_ids) ? 0 : (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . $source_table . ' WHERE id IN (' . implode(',', array_map('absint', $source_ids)) . ')'
);
$journey_remaining = empty($journey_ids) ? 0 : (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . $journey_table . ' WHERE id IN (' . implode(',', array_map('absint', $journey_ids)) . ')'
);
journey_wp_check('transaction rollback removes source and shadow fixtures', $source_remaining === 0 && $journey_remaining === 0);
if ($fixture_created) {
    journey_wp_check('transaction rollback removes lead fixture',
        (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}es_leads WHERE id = %d", $lead_id)) === 0
    );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
