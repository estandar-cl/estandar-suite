<?php
/** MySQL-only atomicity failure gate. */

require __DIR__ . '/bootstrap-wordpress-mysql.php';
global $wpdb;
$source_table = $wpdb->prefix . 'es_lead_events';
$journey_table = $wpdb->prefix . 'es_commercial_journey_events';
$now = gmdate('Y-m-d H:i:s');

$wpdb->query('DROP TRIGGER IF EXISTS journey_gate_reject_insert');
$wpdb->query("CREATE TRIGGER journey_gate_reject_insert BEFORE INSERT ON {$journey_table}
              FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journey gate forced failure'");
$wpdb->query('START TRANSACTION');
$wpdb->insert($wpdb->prefix . 'es_leads', array(
    'lead_uuid' => wp_generate_uuid4(), 'status' => 'NEW',
    'first_source_plugin' => 'estandar-forms', 'latest_source_plugin' => 'estandar-forms',
    'project_slug' => 'journey-gate', 'first_seen_at' => $now, 'last_seen_at' => $now,
    'created_at' => $now, 'updated_at' => $now,
));
$lead_id = absint($wpdb->insert_id);

$wpdb->suppress_errors(true);
$source_id = Estandar_Leads_Repository::insert_event($lead_id, 'lead_created');
$wpdb->suppress_errors(false);

$source_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$source_table} WHERE lead_id = %d AND event_type = 'lead_created'",
    $lead_id
));
$journey_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$journey_table} WHERE lead_id = %d",
    $lead_id
));
$ok = $source_id === 0 && $source_count === 0 && $journey_count === 0;
echo ($ok ? 'PASS' : 'FAIL') . "  failed Journey write leaves no partial ledger rows\n";
$wpdb->query('ROLLBACK');
$wpdb->query('DROP TRIGGER IF EXISTS journey_gate_reject_insert');
$remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}es_leads WHERE id = %d", $lead_id));
$rollback_ok = $remaining === 0;
echo ($rollback_ok ? 'PASS' : 'FAIL') . "  failure fixture rolled back\n";
exit($ok && $rollback_ok ? 0 : 1);
