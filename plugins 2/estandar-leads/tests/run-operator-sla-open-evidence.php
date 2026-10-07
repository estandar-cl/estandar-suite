<?php

require_once dirname(__DIR__, 4) . '/wp-load.php';

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

global $wpdb;

// ─── Setup: create test project and autoresponse settings ──────────────────
$project_slug = 'demo-project-a';
$area = 'Demo City';
$test_email = 'sla-test-' . substr(wp_generate_uuid4(), 0, 8) . '@example.com';
$lead_id = 0;
$submission_id = 0;

$autoresponse_backup = get_option('estandar_leads_autoresponse_settings', '__missing__');
$hours_backup = get_option('estandar_operational_hours_v1', '__missing__');

try {
    // Set operational hours to closed (only 23:00-23:01 Mon)
    update_option('estandar_operational_hours_v1', array(
        'enabled' => true,
        'timezone' => 'America/Santiago',
        'schedule' => array(
            'monday' => array(array('open' => '23:00', 'close' => '23:01')),
        ),
    ), false);

    // Enable autoresponse for test project
    update_option('estandar_leads_autoresponse_settings', array(
        'default' => array(
            'enabled' => 1,
            'subject' => 'Gracias por contactarnos, {{nombre}}',
            'body' => "Hola {{nombre}},\n\nGracias por tu inter\u00E9s en {{proyecto}}.\n\n{{horario_mensaje}}\n\nSaludos,\n{{operador}}",
            'throttle_hours' => 24,
        ),
    ), false);

    // ─── TEST 1: Lead captured outside business hours produces autoresponse ──
    do_action('estandar_lead_captured', array(
        'schema_version' => '1.0',
        'source_plugin' => 'sla-test',
        'submitted_at' => gmdate('c'),
        'contact' => array('name' => 'SLA Lead OOH', 'email' => $test_email, 'phone' => '+569' . random_int(10000000, 99999999)),
        'content' => array('message' => 'Quiero cotizar', 'source_form_id' => 'sla-test-form'),
        'attribution' => array('utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'sla-test'),
        'request' => array('ip_address' => '127.0.0.1', 'user_agent' => 'sla-test'),
        'spam' => array('honeypot_filled' => false, 'seconds_to_submit' => 9),
        'context' => array('project_slug' => $project_slug, 'project_area' => $area),
    ));

    $lead_id = absint($wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}es_leads WHERE email = %s ORDER BY id DESC LIMIT 1",
        $test_email
    )));
    $assert($lead_id > 0, 'Test 1: Lead must be created');

    $submission_id = absint($wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d ORDER BY id DESC LIMIT 1",
        $lead_id
    )));
    $assert($submission_id > 0, 'Test 1: Submission must be created');

    // ─── TEST 2: Autoresponse sent with out-of-hours variant ─────────────────
    $ar_sent = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d AND event_type = 'autoresponse_email_sent'
         ORDER BY id DESC LIMIT 1",
        $lead_id
    ));
    $assert($ar_sent !== null, 'Test 2: Autoresponse must be sent even outside hours');

    if ($ar_sent) {
        $ar_meta = json_decode((string) ($ar_sent->metadata ?? ''), true);
        $variant = (string) ($ar_meta['autoresponse_variant'] ?? '');
        $assert($variant === 'out_of_hours', 'Test 2: Variant must be out_of_hours (got: ' . $variant . ')');
        $assert(!empty($ar_meta['out_of_hours']), 'Test 2: out_of_hours flag must be truthy');
    }

    // ─── TEST 3: Notifier tries to deliver but hours are closed ──────────────
    // The lead has no assigned operator yet, but the notifier should record
    // either a routing event or a deferred event based on the global hours.
    $def_or_route = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d
           AND event_type IN ('operator_notification_deferred', 'routing_unresolved', 'operator_notified')
         LIMIT 1",
        $lead_id
    ));
    $assert($def_or_route > 0, 'Test 3: Notifier must produce a routing/notification event');

    // ─── TEST 4: Open operational hours, verify SLA starts ───────────────────
    update_option('estandar_operational_hours_v1', array(
        'enabled' => false,
        'timezone' => 'America/Santiago',
        'schedule' => array(),
    ), false);

    // Re-enter ingestion to trigger immediate notification
    do_action('estandar_lead_captured', array(
        'schema_version' => '1.0',
        'source_plugin' => 'sla-test-reopen',
        'submitted_at' => gmdate('c'),
        'contact' => array('name' => 'SLA Lead OOH v2', 'email' => $test_email, 'phone' => '+569' . random_int(10000000, 99999999)),
        'content' => array('message' => 'Re-entry', 'source_form_id' => 'sla-test-form'),
        'request' => array('ip_address' => '127.0.0.1', 'user_agent' => 'sla-test'),
        'spam' => array('honeypot_filled' => false, 'seconds_to_submit' => 9),
        'context' => array('project_slug' => $project_slug, 'project_area' => $area),
    ));

    // Verify operator_notified exists (SLA clock started)
    $notified = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d AND event_type = 'operator_notified'
         ORDER BY id DESC LIMIT 1",
        $lead_id
    ));
    $assert($notified !== null, 'Test 4: operator_notified must exist when hours are open');

    // Verify AI context shows both Lead Age and Operator SLA
    if (class_exists('Estandar_Leads_AI_Context')) {
        $context = Estandar_Leads_AI_Context::build_for_lead($lead_id, 'technical');
        $assert(strpos($context, 'Lead Age') !== false, 'Test 4: AI context must show Lead Age');
        $assert(strpos($context, 'Operator SLA') !== false, 'Test 4: AI context must show Operator SLA');
        $assert(strpos($context, 'Operator SLA Timeline') !== false, 'Test 4: AI context must show SLA Timeline section');
    }

    // ─── TEST 5: Open at <5 seconds without click = technical_fetch ──────────
    // Manipulate the autoresponse_email_sent timestamp to appear as "just now"
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}es_lead_events
         SET created_at = %s
         WHERE lead_id = %d AND event_type = 'autoresponse_email_sent'
         ORDER BY id DESC LIMIT 1",
        gmdate('Y-m-d H:i:s'),
        $lead_id
    ));

    if (class_exists('Estandar_Leads_Autoresponse')) {
        Estandar_Leads_Autoresponse::record_open_signal($lead_id, $submission_id, $project_slug);

        $classified = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND event_type = 'autoresponse_open_classified'
             ORDER BY id DESC LIMIT 1",
            $lead_id
        ));
        $assert($classified !== null, 'Test 5: Open signal must produce classified event');

        if ($classified) {
            $cl_meta = json_decode((string) ($classified->metadata ?? ''), true);
            $class = (string) ($cl_meta['open_classification'] ?? '');
            $assert(in_array($class, array('technical_fetch', 'probable_machine_open'), true),
                'Test 5: Immediate open must classify as technical/machine (got: ' . $class . ')');

            if (in_array($class, array('technical_fetch', 'probable_machine_open'), true)) {
                $assert(empty($cl_meta['score_eligible']),
                    'Test 5: technical_fetch/probable_machine_open must NOT be score_eligible');
            }
        }
    }

    // ─── TEST 6: Open + click = confirmed_human_engagement ───────────────────
    if (class_exists('Estandar_Leads_Autoresponse') && class_exists('Estandar_Leads_Tokens')) {
        $token = Estandar_Leads_Tokens::sign_link($lead_id, $submission_id, 'landing', home_url('/'), 30, $project_slug);
        if ($token !== '') {
            Estandar_Leads_Autoresponse::record_link_click($lead_id, $submission_id, 'landing', $project_slug);

            // Now record another open — it should be confirmed_human_engagement
            Estandar_Leads_Autoresponse::record_open_signal($lead_id, $submission_id, $project_slug);

            $confirmed_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
                 WHERE lead_id = %d AND event_type = 'autoresponse_open_classified'
                 AND metadata LIKE %s",
                $lead_id,
                '%confirmed_human_engagement%'
            ));
            $assert($confirmed_count >= 1, 'Test 6: Click must produce confirmed_human_engagement classification (found ' . $confirmed_count . ')');

            // Check the last classified event has score_eligible=true
            $last_classified = $wpdb->get_row($wpdb->prepare(
                "SELECT metadata FROM {$wpdb->prefix}es_lead_events
                 WHERE lead_id = %d AND event_type = 'autoresponse_open_classified'
                 AND metadata LIKE %s
                 ORDER BY id DESC LIMIT 1",
                $lead_id,
                '%confirmed_human_engagement%'
            ));
            if ($last_classified) {
                $cl_meta = json_decode((string) ($last_classified->metadata ?? ''), true);
                $assert(!empty($cl_meta['score_eligible']),
                    'Test 6: confirmed_human_engagement must be score_eligible');
            }
        }
    }

    // ─── TEST 7: Idempotency — no duplicate events ──────────────────────────
    $before_ar = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d AND event_type = 'autoresponse_email_sent'",
        $lead_id
    ));

    // Re-enter autoresponse (should be throttled)
    if (class_exists('Estandar_Leads_Autoresponse')) {
        Estandar_Leads_Autoresponse::on_lead_ingested($lead_id, $submission_id, false, array(
            'contact' => array('name' => 'Dup', 'email' => $test_email),
            'content' => array('message' => 'Dup'),
            'context' => array('project_slug' => $project_slug, 'project_area' => $area),
            'request' => array('ip_address' => '127.0.0.1', 'user_agent' => 'dup'),
            'spam' => array('honeypot_filled' => false, 'seconds_to_submit' => 9),
        ));
    }

    $after_ar = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d AND event_type = 'autoresponse_email_sent'",
        $lead_id
    ));
    $assert($after_ar === $before_ar,
        'Test 7: autoresponse_email_sent must not be duplicated (' . $before_ar . ' → ' . $after_ar . ')');

    // Verify no duplicate open_signal events from the same pixel load
    $before_open = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d AND event_type = 'autoresponse_email_open_signal'",
        $lead_id
    ));
    if (class_exists('Estandar_Leads_Autoresponse')) {
        Estandar_Leads_Autoresponse::record_open_signal($lead_id, $submission_id, $project_slug);
        Estandar_Leads_Autoresponse::record_open_signal($lead_id, $submission_id, $project_slug);
    }
    $after_open = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d AND event_type = 'autoresponse_email_open_signal'",
        $lead_id
    ));
    $assert($after_open >= $before_open + 1, 'Test 7: Multiple open signals must be recorded (raw events are not deduplicated)');

} finally {
    // Cleanup
    if ($lead_id > 0) {
        $wpdb->delete($wpdb->prefix . 'es_lead_events', array('lead_id' => $lead_id));
        $wpdb->delete($wpdb->prefix . 'es_lead_submissions', array('lead_id' => $lead_id));
        $wpdb->delete($wpdb->prefix . 'es_leads', array('id' => $lead_id));
    }
    if ($hours_backup === '__missing__') {
        delete_option('estandar_operational_hours_v1');
    } else {
        update_option('estandar_operational_hours_v1', $hours_backup, false);
    }
}

if ($failures) {
    fwrite(STDERR, "FAILURES:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "PASS: OPERATOR_SLA_OPEN_EVIDENCE_V1\n";
