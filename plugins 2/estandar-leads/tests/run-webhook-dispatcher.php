<?php

require_once dirname(__DIR__, 4) . '/wp-load.php';

if (!class_exists('Estandar_Leads_Webhook_Dispatcher')) {
    fwrite(STDERR, "Dispatcher unavailable.\n");
    exit(1);
}

$option_names = array(
    Estandar_Leads_Webhook_Dispatcher::ROUTES_OPTION,
    Estandar_Leads_Webhook_Dispatcher::MIGRATION_OPTION,
    Estandar_Leads_Webhook_Dispatcher::MIGRATION_REPORT_OPTION,
    Estandar_Leads_Webhook_Dispatcher::OWNER_OPTION,
);
$backup = array();
foreach ($option_names as $option_name) {
    $backup[$option_name] = array(
        'exists' => get_option($option_name, '__missing__') !== '__missing__',
        'value' => get_option($option_name),
    );
}

global $wpdb;
$forms_table = $wpdb->prefix . 'estandar_forms';
$test_form_id = 0;
$test_submission_id = 0;
$dedupe_key = '';

$calls = array();
$filter = function ($pre, $args, $url) use (&$calls) {
    $calls[] = array('url' => $url, 'args' => $args);
    return array(
        'headers' => array(),
        'body' => '',
        'response' => array('code' => 200, 'message' => 'OK'),
        'cookies' => array(),
        'filename' => null,
    );
};
add_filter('pre_http_request', $filter, 10, 3);

$exit_code = 1;
try {
    $results = array();
    $results['default_owner_legacy'] = !Estandar_Leads_Webhook_Dispatcher::owns_webhooks();

    $wpdb->insert($forms_table, array(
        'name' => 'Webhook Dispatcher Test',
        'slug' => 'webhook-dispatcher-test-' . wp_generate_uuid4(),
        'form_type' => 'lead',
        'send_webhook' => 1,
        'webhook_url' => 'https://example.com/saladepruebas',
        'webhook_payload_mode' => 'estandar',
        'context_schema' => wp_json_encode(array(
            'routing' => array(
                'mode' => 'fixed_project',
                'project_slug' => 'altacruz',
                'project_name' => 'AltaCruz',
            ),
        )),
        'created_at' => gmdate('Y-m-d H:i:s'),
    ));
    $test_form_id = absint($wpdb->insert_id);
    delete_option(Estandar_Leads_Webhook_Dispatcher::ROUTES_OPTION);
    delete_option(Estandar_Leads_Webhook_Dispatcher::MIGRATION_OPTION);
    delete_option(Estandar_Leads_Webhook_Dispatcher::MIGRATION_REPORT_OPTION);
    Estandar_Leads_Webhook_Dispatcher::maybe_migrate_routes();
    $migrated_routes = get_option(Estandar_Leads_Webhook_Dispatcher::ROUTES_OPTION, array());
    $results['forms_migration'] =
        ($migrated_routes['altacruz']['url'] ?? '') === 'https://example.com/saladepruebas'
        && ($migrated_routes['altacruz']['payload_mode'] ?? '') === 'estandar'
        && ($migrated_routes['altacruz']['validation']['status'] ?? '') === 'valid';

    update_option(Estandar_Leads_Webhook_Dispatcher::OWNER_OPTION, 'leads', false);
    update_option(Estandar_Leads_Webhook_Dispatcher::ROUTES_OPTION, array(
        'altacruz' => array(
            'enabled' => true,
            'url' => 'https://n8n.example.test/webhook/altacruz',
            'payload_mode' => 'cf7_compat',
            'validation' => array('status' => 'valid', 'url_valid' => true, 'dns_valid' => true),
        ),
    ), false);

    $row = $wpdb->get_row(
        "SELECT lead_id, id AS submission_id
         FROM {$wpdb->prefix}es_lead_submissions
         ORDER BY id DESC LIMIT 1",
        ARRAY_A
    );
    $lead_id = absint($row['lead_id'] ?? 1);
    $submission_id = absint($row['submission_id'] ?? 1) + 1000000;
    $test_submission_id = $submission_id;
    $payload = array(
        'schema_version' => '1.0',
        'source_plugin' => 'estandar-chatbot',
        'contact' => array(
            'name' => 'Acceptance',
            'email' => 'acceptance@example.com',
            'phone' => '+56911111111',
        ),
        'content' => array('source_form_id' => 'chatbot', 'message' => 'Cotizacion'),
        'context' => array('project_slug' => 'altacruz'),
        'meta' => array(),
    );

    $dedupe_hash = hash('sha256', $submission_id . '|altacruz|https://n8n.example.test/webhook/altacruz|cf7_compat');
    $dedupe_key = 'estandar_leads_wh_' . substr($dedupe_hash, 0, 40);
    delete_option($dedupe_key);
    Estandar_Leads_Webhook_Dispatcher::dispatch($lead_id, $submission_id, true, $payload);
    Estandar_Leads_Webhook_Dispatcher::dispatch($lead_id, $submission_id, true, $payload);
    $results['single_dispatch'] = count($calls) === 1;

    $payload['context']['project_slug'] = 'missing-project';
    Estandar_Leads_Webhook_Dispatcher::dispatch($lead_id, $submission_id + 1, true, $payload);
    $results['fail_closed'] = count($calls) === 1;

    if (class_exists('Estandar_Forms_Webhook')) {
        Estandar_Forms_Webhook::maybe_dispatch(array(
            'source_plugin' => 'estandar-forms',
            'content' => array('source_form_id' => $test_form_id),
        ));
    }
    $results['forms_guard'] = count($calls) === 1;

    $chatbot_guard = function_exists('estandar_chatbot_dispatch_lead_webhook')
        ? estandar_chatbot_dispatch_lead_webhook(array('event' => 'lead.captured'))
        : array('message' => 'owned_by_estandar_leads');
    $results['chatbot_guard'] = ($chatbot_guard['message'] ?? '') === 'owned_by_estandar_leads';

    update_option(Estandar_Leads_Webhook_Dispatcher::OWNER_OPTION, 'legacy_forms', false);
    $results['rollback'] = !Estandar_Leads_Webhook_Dispatcher::owns_webhooks();

    $failed = array_keys(array_filter($results, function ($passed) {
        return !$passed;
    }));
    echo wp_json_encode(array(
        'status' => empty($failed) ? 'PASS' : 'FAIL',
        'checks' => $results,
        'http_calls' => count($calls),
        'failed' => $failed,
    ), JSON_PRETTY_PRINT) . PHP_EOL;
    $exit_code = empty($failed) ? 0 : 1;
} finally {
    remove_filter('pre_http_request', $filter, 10);
    if ($test_form_id > 0) {
        $wpdb->delete($forms_table, array('id' => $test_form_id), array('%d'));
    }
    if ($test_submission_id > 0) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}es_lead_events WHERE submission_id IN (%d, %d)",
            $test_submission_id,
            $test_submission_id + 1
        ));
    }
    if ($dedupe_key !== '') {
        delete_option($dedupe_key);
    }
    foreach ($backup as $option_name => $state) {
        if ($state['exists']) {
            update_option($option_name, $state['value'], false);
        } else {
            delete_option($option_name);
        }
    }
}

exit($exit_code);
