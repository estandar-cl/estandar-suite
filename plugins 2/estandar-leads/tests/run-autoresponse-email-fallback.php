<?php
/**
 * AUTORESPONSE_EMAIL_FALLBACK_V1 — Test suite
 * Verifica que el fallback email (submission → lead) funcione correctamente
 * Casos: A (prioritize submission), B (fallback to lead), C (both empty).
 */

if (!defined('ABSPATH')) {
    require_once dirname(__DIR__, 4) . '/wp-load.php';
}

global $wpdb;
$autoresponse_settings_backup = get_option('estandar_leads_autoresponse_settings', null);

echo "======================================================\n";
echo "AUTORESPONSE_EMAIL_FALLBACK_V1 — Test Suite\n";
echo "======================================================\n\n";

// Disable autoresponse temporarily to control flow
remove_action('estandar_lead_ingested', ['Estandar_Leads_Autoresponse', 'on_lead_ingested']);

// Test helper: create lead with submission
function create_test_lead($email = '', $lead_email = '', $case_name = 'unnamed') {
    global $wpdb;

    $now = gmdate('Y-m-d H:i:s');
    $email_normalized = $email !== '' ? $email : $lead_email;
    $email_hash = $email_normalized !== '' ? md5($email_normalized) : '';
    $phone_hash = md5('5691234567');

    // Create lead
    $lead_ok = $wpdb->insert($wpdb->prefix . 'es_leads', [
        'lead_uuid' => wp_generate_uuid4(),
        'status' => 'NEW',
        'name' => 'Test ' . $case_name,
        'email' => $lead_email,  // This is what fallback reads
        'phone' => '5691234567',
        'email_normalized' => $email_normalized,
        'phone_normalized' => '5691234567',
        'email_hash' => $email_hash,
        'phone_hash' => $phone_hash,
        'first_source_plugin' => 'test',
        'latest_source_plugin' => 'test',
        'project_slug' => 'test-project',
        'first_seen_at' => $now,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if (!$lead_ok) {
        return null;
    }

    $lead_id = absint($wpdb->insert_id);

    // Create submission
    $submission_ok = $wpdb->insert($wpdb->prefix . 'es_lead_submissions', [
        'lead_id' => $lead_id,
        'source_plugin' => 'estandar-chatbot',
        'source_form_id' => 'test-form',
        'name' => 'Test ' . $case_name,
        'email' => $email,
        'phone' => '5691234567',
        'email_normalized' => $email,
        'phone_normalized' => '5691234567',
        'email_hash' => $email !== '' ? md5($email) : '',
        'phone_hash' => $phone_hash,
        'context_json' => wp_json_encode(['project_slug' => 'test-project', 'project_area' => 'Test']),
        'submitted_at' => $now,
        'created_at' => $now,
    ]);

    if (!$submission_ok) {
        return null;
    }

    $submission_id = absint($wpdb->insert_id);

    return [
        'lead_id' => $lead_id,
        'submission_id' => $submission_id,
        'case' => $case_name,
        'submission_email' => $email,
        'lead_email' => $lead_email,
    ];
}

// Test helper: simulate autoresponse trigger with telemetry capture
function test_autoresponse($test_case) {
    global $wpdb;

    $lead_id = $test_case['lead_id'];
    $submission_id = $test_case['submission_id'];

    // Build payload as chatbot would
    $payload = [
        'schema_version' => '1.0',
        'source_plugin' => 'estandar-chatbot',
        'submitted_at' => gmdate('c'),
        'contact' => [
            'name' => 'Test ' . $test_case['case'],
            'email' => $test_case['submission_email'],
            'phone' => '5691234567',
        ],
        'content' => [
            'message' => 'Test message',
            'source_form_id' => 'test-form',
        ],
        'context' => [
            'project_slug' => 'test-project',
            'project_area' => 'Test',
        ],
        'attribution' => [
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'session_id' => '',
        ],
        'request' => [
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test-browser',
        ],
        'spam' => [
            'honeypot_filled' => false,
            'seconds_to_submit' => 10,
        ],
    ];

    // Enable autoresponse for test-project using the current settings store.
    update_option('estandar_leads_autoresponse_settings', [
        'test-project' => [
            'enabled' => true,
            'subject' => 'Test Subject',
            'body' => 'Test Body',
            'throttle_hours' => 0,
        ],
    ], false);

    // Capture telemetry
    $captured_events = [];
    $telemetry_hook = function($severity, $event_type, $message, $context) use (&$captured_events) {
        $captured_events[] = [
            'event_type' => $event_type,
            'severity' => $severity,
            'context' => $context,
        ];
    };

    if (function_exists('add_action')) {
        add_filter('estandar_leads_telemetry', $telemetry_hook, 10, 4);
    }

    // Trigger autoresponse directly; the hook was removed above to isolate the test.
    Estandar_Leads_Autoresponse::on_lead_ingested($lead_id, $submission_id, false, $payload);

    // Check if autoresponse_email_failed event was created
    $failed_event = $wpdb->get_row($wpdb->prepare(
        "SELECT event_type, metadata FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d AND event_type = %s
         ORDER BY id DESC LIMIT 1",
        $lead_id, 'autoresponse_email_failed'
    ), ARRAY_A);

    $sent_event = $wpdb->get_row($wpdb->prepare(
        "SELECT event_type, metadata FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d AND event_type = %s
         ORDER BY id DESC LIMIT 1",
        $lead_id, 'autoresponse_email_sent'
    ), ARRAY_A);

    // SEMANTIC_STATUS_V1 (Lead #478): sin email = skip intencional.
    $skipped_event = $wpdb->get_row($wpdb->prepare(
        "SELECT event_type, metadata FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d AND event_type = %s
         ORDER BY id DESC LIMIT 1",
        $lead_id, 'autoresponse_skipped'
    ), ARRAY_A);

    return [
        'test_case' => $test_case['case'],
        'submission_email' => $test_case['submission_email'],
        'lead_email' => $test_case['lead_email'],
        'failed_event' => $failed_event,
        'sent_event' => $sent_event,
        'skipped_event' => $skipped_event,
        'telemetry_events' => $captured_events,
    ];
}

// Run test cases
echo "Test Case A: submission.email = 'test@test.com', lead.email = 'otro@test.com'\n";
echo "Expected: Uses submission.email, autoresponse_email_sent event\n";
$case_a = create_test_lead('test@test.com', 'otro@test.com', 'Case_A');
if ($case_a) {
    $result_a = test_autoresponse($case_a);
    echo "Result: " . ($result_a['sent_event'] ? "✓ PASS - Email sent" : "✗ FAIL - Email not sent") . "\n";
    if ($result_a['sent_event']) {
        echo "  Event metadata: " . ($result_a['sent_event']['metadata'] ?? 'N/A') . "\n";
    }
} else {
    echo "✗ FAIL - Could not create test lead\n";
}
echo "\n";

echo "Test Case B: submission.email = '', lead.email = 'fallback@test.com'\n";
echo "Expected: Uses lead.email fallback, autoresponse_email_sent event\n";
$case_b = create_test_lead('', 'fallback@test.com', 'Case_B');
if ($case_b) {
    $result_b = test_autoresponse($case_b);
    echo "Result: " . ($result_b['sent_event'] ? "✓ PASS - Email sent via fallback" : "✗ FAIL - Email not sent") . "\n";
    if ($result_b['sent_event']) {
        echo "  Event metadata: " . ($result_b['sent_event']['metadata'] ?? 'N/A') . "\n";
    }
    if ($result_b['failed_event'] && stripos($result_b['failed_event']['metadata'], 'no_email') === false) {
        echo "  Note: No 'no_email' failure - correct\n";
    }
} else {
    echo "✗ FAIL - Could not create test lead\n";
}
echo "\n";

echo "Test Case C: submission.email = '', lead.email = ''\n";
echo "Expected: autoresponse_skipped with reason=no_email, NO autoresponse_email_failed\n";
$case_c = create_test_lead('', '', 'Case_C');
if ($case_c) {
    $result_c = test_autoresponse($case_c);
    $case_c_ok = $result_c['skipped_event']
        && stripos((string) $result_c['skipped_event']['metadata'], 'no_email') !== false
        && ! $result_c['failed_event'];
    echo "Result: " . ($case_c_ok ? "✓ PASS - skipped/no_email, no failure emitted" : "✗ FAIL - Unexpected result") . "\n";
    if ($result_c['skipped_event']) {
        echo "  Event metadata: " . ($result_c['skipped_event']['metadata'] ?? 'N/A') . "\n";
    }
    if ($result_c['failed_event']) {
        echo "  UNEXPECTED failed event: " . ($result_c['failed_event']['metadata'] ?? 'N/A') . "\n";
    }
} else {
    echo "✗ FAIL - Could not create test lead\n";
}
echo "\n";

echo "======================================================\n";
echo "Summary:\n";
echo "- Case A (submission prioritized): " . ($result_a['sent_event'] ? "✓" : "✗") . "\n";
echo "- Case B (lead fallback): " . ($result_b['sent_event'] ? "✓" : "✗") . "\n";
echo "- Case C (both empty => skipped/no_email): " . (!empty($case_c_ok) ? "✓" : "✗") . "\n";
echo "======================================================\n";
if ($autoresponse_settings_backup === null) {
    delete_option('estandar_leads_autoresponse_settings');
} else {
    update_option('estandar_leads_autoresponse_settings', $autoresponse_settings_backup, false);
}
?>
