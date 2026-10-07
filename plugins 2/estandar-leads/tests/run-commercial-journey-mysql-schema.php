<?php
/** MySQL-only schema/install/upgrade gate. */

require __DIR__ . '/bootstrap-wordpress-mysql.php';
global $wpdb;

$table = $wpdb->prefix . 'es_commercial_journey_events';
$from = (string) getenv('ESTANDAR_MYSQL_GATE_FROM_VERSION');
if (in_array($from, array('3.6.0', '3.6.1'), true)) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
    update_option('estandar_leads_schema_version', $from, false);
    Estandar_Leads_Schema::maybe_migrate();
}

$passed = 0;
$failed = 0;
function journey_mysql_schema_check(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS  {$label}\n"; }
    else { $failed++; echo "FAIL  {$label}\n"; }
}

journey_mysql_schema_check('schema version matches live SCHEMA_VERSION', get_option('estandar_leads_schema_version') === Estandar_Leads_Schema::SCHEMA_VERSION);
journey_mysql_schema_check('Journey table exists', (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);

$columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
$actual_columns = array();
foreach ($columns as $column) {
    $actual_columns[$column['Field']] = array(
        'Type' => strtolower($column['Type']),
        'Null' => $column['Null'],
        'Default' => $column['Default'],
        'Extra' => strtolower($column['Extra']),
    );
}
$expected_columns = array(
    'id' => array('Type' => 'bigint unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'),
    'event_id' => array('Type' => 'char(36)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
    'event_name' => array('Type' => 'varchar(64)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
    'event_version' => array('Type' => 'smallint unsigned', 'Null' => 'NO', 'Default' => '1', 'Extra' => ''),
    'lead_id' => array('Type' => 'bigint unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
    'lead_uuid' => array('Type' => 'char(36)', 'Null' => 'NO', 'Default' => '', 'Extra' => ''),
    'project_slug' => array('Type' => 'varchar(190)', 'Null' => 'NO', 'Default' => '', 'Extra' => ''),
    'source_plugin' => array('Type' => 'varchar(64)', 'Null' => 'NO', 'Default' => 'estandar-leads', 'Extra' => ''),
    'source_event_id' => array('Type' => 'bigint unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
    'correlation_id' => array('Type' => 'varchar(190)', 'Null' => 'NO', 'Default' => '', 'Extra' => ''),
    'sequence_no' => array('Type' => 'bigint unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
    'idempotency_key' => array('Type' => 'char(64)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
    'actor_json' => array('Type' => 'longtext', 'Null' => 'YES', 'Default' => null, 'Extra' => ''),
    'data_json' => array('Type' => 'longtext', 'Null' => 'YES', 'Default' => null, 'Extra' => ''),
    'envelope_json' => array('Type' => 'longtext', 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
    'occurred_at' => array('Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
    'recorded_at' => array('Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''),
);
journey_mysql_schema_check('types nullability and defaults match contract', $actual_columns === $expected_columns);

$index_rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
$indexes = array();
foreach ($index_rows as $row) {
    $name = $row['Key_name'];
    $indexes[$name]['unique'] = ((int) $row['Non_unique'] === 0);
    $indexes[$name]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
}
foreach ($indexes as &$index) { ksort($index['columns']); $index['columns'] = array_values($index['columns']); }
unset($index);
$expected_indexes = array(
    'PRIMARY' => array('unique' => true, 'columns' => array('id')),
    'event_id' => array('unique' => true, 'columns' => array('event_id')),
    'idempotency_key' => array('unique' => true, 'columns' => array('idempotency_key')),
    'source_event' => array('unique' => true, 'columns' => array('source_event_id', 'event_name')),
    'lead_sequence' => array('unique' => false, 'columns' => array('lead_id', 'sequence_no')),
    'event_name' => array('unique' => false, 'columns' => array('event_name')),
    'occurred_at' => array('unique' => false, 'columns' => array('occurred_at')),
);
journey_mysql_schema_check('all expected indexes exist exactly once', $indexes === $expected_indexes);

$before = $wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_N)[1] ?? '';
Estandar_Leads_Schema::create_tables();
$after = $wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_N)[1] ?? '';
journey_mysql_schema_check('repeating migration is idempotent', $before === $after);

$sentinel_id = wp_generate_uuid4();
$wpdb->insert($table, array(
    'event_id' => $sentinel_id, 'event_name' => 'lead.created', 'event_version' => 1,
    'lead_id' => 999999, 'source_event_id' => 999999, 'sequence_no' => 999999,
    'idempotency_key' => hash('sha256', $sentinel_id), 'envelope_json' => '{}',
    'occurred_at' => gmdate('Y-m-d H:i:s'), 'recorded_at' => gmdate('Y-m-d H:i:s'),
));
activate_plugin('estandar-leads/estandar-leads.php', '', false, true);
deactivate_plugins('estandar-leads/estandar-leads.php', true, false);
journey_mysql_schema_check('deactivation preserves existing data', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_id = %s", $sentinel_id)) === 1);
Estandar_Leads_Schema::activate();
journey_mysql_schema_check('reactivation preserves existing data', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE event_id = %s", $sentinel_id)) === 1);
$wpdb->delete($table, array('event_id' => $sentinel_id));

$normalized_schema = preg_replace('/ AUTO_INCREMENT=\d+/', '', $after);
echo "SCHEMA_HASH " . hash('sha256', $normalized_schema) . "\n";
echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
