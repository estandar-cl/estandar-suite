<?php

require_once dirname(__DIR__, 4) . '/wp-load.php';

if (($argv[1] ?? '') === '--reservation-worker') {
    global $wpdb;
    $wpdb->hide_errors();
    $wpdb->suppress_errors(true);
    $email_hash = sanitize_text_field((string) ($argv[2] ?? ''));
    $project_slug = sanitize_key((string) ($argv[3] ?? ''));
    $start_at = (float) ($argv[4] ?? 0);
    while (microtime(true) < $start_at) {
        usleep(1000);
    }
    $reservation = Estandar_Leads_Repository::reserve_autoresponse($email_hash, $project_slug, 24);
    echo $reservation === false ? 'LOSE' : 'WIN';
    if (is_string($reservation)) {
        sleep(1);
        Estandar_Leads_Repository::release_autoresponse_reservation($email_hash, $project_slug, $reservation);
    }
    exit(0);
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$lead_id = 910001;
$submission_id = 920001;
$project = 'altacruz';
$route = rest_url('estandar-leads/v1/whatsapp-redirect');
$token = Estandar_Leads_Tokens::sign_link($lead_id, $submission_id, 'whatsapp', $route, 30, $project);
$verified = Estandar_Leads_Tokens::verify_link($token);

$assert(is_array($verified), 'Valid WhatsApp token must verify.');
$assert(($verified['lead_id'] ?? 0) === $lead_id, 'Token must bind lead_id.');
$assert(($verified['submission_id'] ?? 0) === $submission_id, 'Token must bind submission_id.');
$assert(($verified['project_slug'] ?? '') === $project, 'Token must bind project_slug.');
$assert(($verified['resource_type'] ?? '') === 'whatsapp', 'Token must bind source context.');
$assert(Estandar_Leads_Tokens::verify_whatsapp_tracking($token, $lead_id, $submission_id, $project, 'email_autoresponse'), 'Exact tracking context must verify.');
$assert(!Estandar_Leads_Tokens::verify_whatsapp_tracking($token, $lead_id + 1, $submission_id, $project, 'email_autoresponse'), 'Modified lead_id must fail.');
$assert(!Estandar_Leads_Tokens::verify_whatsapp_tracking($token, $lead_id, $submission_id + 1, $project, 'email_autoresponse'), 'Modified submission_id must fail.');
$assert(!Estandar_Leads_Tokens::verify_whatsapp_tracking($token, $lead_id, $submission_id, 'parronales', 'email_autoresponse'), 'Modified project_slug must fail.');
$assert(!Estandar_Leads_Tokens::verify_whatsapp_tracking($token, $lead_id, $submission_id, $project, 'button'), 'Modified source must fail.');

$parts = explode('.', $token);
$parts[0] = substr($parts[0], 0, -1) . ($parts[0][strlen($parts[0]) - 1] === 'A' ? 'B' : 'A');
$assert(Estandar_Leads_Tokens::verify_link(implode('.', $parts)) === false, 'Tampered token must fail.');

$run_id = wp_generate_uuid4();
$email_a = Estandar_Leads_Normalizer::email('person-a-' . $run_id . '@example.com');
$email_b = Estandar_Leads_Normalizer::email('person-b-' . $run_id . '@example.com');
$hash_a = Estandar_Leads_Normalizer::hash($email_a);
$hash_b = Estandar_Leads_Normalizer::hash($email_b);
$project_b = 'parronales';

$reservation_a = Estandar_Leads_Repository::reserve_autoresponse($hash_a, $project, 24);
$reservation_same = Estandar_Leads_Repository::reserve_autoresponse($hash_a, $project, 24);
$reservation_other_project = Estandar_Leads_Repository::reserve_autoresponse($hash_a, $project_b, 24);
$reservation_other_email = Estandar_Leads_Repository::reserve_autoresponse($hash_b, $project, 24);

$assert(is_string($reservation_a) && $reservation_a !== '', 'First reservation must succeed.');
$assert($reservation_same === false, 'Concurrent same email/project reservation must fail.');
$assert(is_string($reservation_other_project) && $reservation_other_project !== '', 'Same email/different project must succeed.');
$assert(is_string($reservation_other_email) && $reservation_other_email !== '', 'Different email/same lead context must succeed.');
$assert(Estandar_Leads_Normalizer::email('') === '', 'Missing email must remain empty.');
$assert(!is_email(Estandar_Leads_Normalizer::email('invalid-email')), 'Invalid email must fail validation.');

global $wpdb;
$fallback_backup = get_option('estandar_leads_whatsapp_fallback', '__missing__');
$operator_id = 0;
$routing_lead_id = 930001;
$routing_submission_id = 930002;
try {
    update_option('estandar_leads_whatsapp_fallback', '56999999999', false);
    $wpdb->insert($wpdb->prefix . 'es_operators', array(
        'operator_uuid' => wp_generate_uuid4(),
        'name' => 'Valentina Routing QA',
        'email' => 'valentina-routing-qa@example.com',
        'project_slug' => $project_b,
        'area' => '',
        'whatsapp_number' => '56911112222',
        'allowed_projects_json' => wp_json_encode(array($project_b)),
        'active' => 1,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ));
    $operator_id = absint($wpdb->insert_id);
    Estandar_Leads_Repository::insert_event($routing_lead_id, 'operator_assigned', array(
        'submission_id' => $routing_submission_id,
        'operator_id' => $operator_id,
        'to_status' => $project_b,
    ));

    $route = Estandar_Leads_Whatsapp_Redirect::resolve_route('email_autoresponse', $routing_lead_id, $routing_submission_id, $project_b);
    $assert(($route['routing_mode'] ?? '') === 'assigned_operator', 'Autoresponse WhatsApp must route by assigned operator.');
    $assert(($route['number'] ?? '') === '56911112222', 'Autoresponse WhatsApp must use assigned operator phone.');

    $wpdb->update($wpdb->prefix . 'es_operators', array('active' => 0), array('id' => $operator_id), array('%d'), array('%d'));
    $fallback_route = Estandar_Leads_Whatsapp_Redirect::resolve_route('email_autoresponse', $routing_lead_id, $routing_submission_id, $project_b);
    $assert(($fallback_route['routing_mode'] ?? '') === 'fallback', 'Inactive assigned operator must use explicit fallback.');
    $assert(($fallback_route['number'] ?? '') === '56999999999', 'Fallback must use estandar_leads_whatsapp_fallback.');
} finally {
    $wpdb->delete($wpdb->prefix . 'es_lead_events', array('lead_id' => $routing_lead_id));
    if ($operator_id > 0) {
        $wpdb->delete($wpdb->prefix . 'es_operators', array('id' => $operator_id));
    }
    if ($fallback_backup === '__missing__') {
        delete_option('estandar_leads_whatsapp_fallback');
    } else {
        update_option('estandar_leads_whatsapp_fallback', $fallback_backup, false);
    }
}

if (is_string($reservation_a)) {
    Estandar_Leads_Repository::release_autoresponse_reservation($hash_a, $project, $reservation_a);
}
if (is_string($reservation_other_project)) {
    Estandar_Leads_Repository::release_autoresponse_reservation($hash_a, $project_b, $reservation_other_project);
}
if (is_string($reservation_other_email)) {
    Estandar_Leads_Repository::release_autoresponse_reservation($hash_b, $project, $reservation_other_email);
}

$race_hash = Estandar_Leads_Normalizer::hash('race-' . wp_generate_uuid4() . '@example.com');
$race_project = 'race-project';
$start_at = microtime(true) + 2;
$command = array(
    PHP_BINARY,
    __FILE__,
    '--reservation-worker',
    $race_hash,
    $race_project,
    (string) $start_at,
);
$processes = array();
for ($i = 0; $i < 2; $i++) {
    $processes[] = proc_open($command, array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    ), $pipes);
    $processes[$i] = array($processes[$i], $pipes);
}

$race_results = array();
foreach ($processes as $process_entry) {
    $process = $process_entry[0];
    $pipes = $process_entry[1];
    $race_results[] = trim(stream_get_contents($pipes[1]));
    $stderr = trim(stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);
    $assert(
        $exit_code === 0 && $stderr === '',
        'Concurrent reservation worker must exit cleanly. exit=' . $exit_code . ' stderr=' . $stderr
    );
}
sort($race_results);
$assert($race_results === array('LOSE', 'WIN'), 'Exactly one concurrent reservation may succeed.');

$settings_backup = get_option('estandar_leads_autoresponse_settings', '__missing__');
$qa_email = 'missing-project-' . wp_generate_uuid4() . '@example.com';
$qa_lead_id = 0;
try {
    update_option('estandar_leads_autoresponse_settings', array(
        'default' => array_merge(Estandar_Leads_Email::autoresponse_defaults(), array('enabled' => 1)),
    ), false);

    do_action('estandar_lead_captured', array(
        'schema_version' => '1.0',
        'source_plugin' => 'autoresponse-hardening-test',
        'submitted_at' => gmdate('c'),
        'contact' => array(
            'name' => 'Missing Project QA',
            'email' => $qa_email,
            'phone' => '+569' . random_int(10000000, 99999999),
        ),
        'content' => array('source_form_id' => 'autoresponse-hardening-test'),
        'context' => array(),
        'request' => array('ip_address' => '127.0.0.1', 'user_agent' => 'autoresponse-hardening-test'),
        'spam' => array('honeypot_filled' => false, 'seconds_to_submit' => 9),
    ));

    global $wpdb;
    $qa_lead_id = absint($wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}es_leads WHERE email = %s ORDER BY id DESC LIMIT 1",
        $qa_email
    )));
    $reason = $wpdb->get_var($wpdb->prepare(
        "SELECT reason FROM {$wpdb->prefix}es_lead_events
         WHERE lead_id = %d AND event_type = 'autoresponse_email_failed'
         ORDER BY id DESC LIMIT 1",
        $qa_lead_id
    ));
    $assert($reason === 'missing_project', 'Missing project must emit reason=missing_project.');
} finally {
    if ($qa_lead_id > 0) {
        $wpdb->delete($wpdb->prefix . 'es_lead_events', array('lead_id' => $qa_lead_id));
        $wpdb->delete($wpdb->prefix . 'es_lead_submissions', array('lead_id' => $qa_lead_id));
        $wpdb->delete($wpdb->prefix . 'es_leads', array('id' => $qa_lead_id));
    }
    if ($settings_backup === '__missing__') {
        delete_option('estandar_leads_autoresponse_settings');
    } else {
        update_option('estandar_leads_autoresponse_settings', $settings_backup, false);
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "PASS: LEAD_RESPONSE_ACCELERATION_HARDENING\n";
