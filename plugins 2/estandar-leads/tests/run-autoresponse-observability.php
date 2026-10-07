<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$lead_a = 980000000 + random_int(1000, 9999);
$lead_b = $lead_a + 1;
$submission = $lead_a + 10000;
$events_table = $wpdb->prefix . 'es_lead_events';
$baseline = Estandar_Leads_Portal::get_autoresponse_stats(7);
$failures = array();

$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    Estandar_Leads_Repository::insert_event($lead_a, 'autoresponse_email_sent', array(
        'submission_id' => $submission,
    ));
    Estandar_Leads_Repository::insert_event($lead_a, 'autoresponse_link_click', array(
        'submission_id' => $submission,
        'metadata' => array('resource_type' => 'pdf'),
    ));
    Estandar_Leads_Repository::insert_event($lead_a, 'whatsapp_click', array(
        'submission_id' => $submission,
        'metadata' => array('source' => 'email_autoresponse'),
    ));
    Estandar_Leads_Repository::insert_event($lead_a, 'autoresponse_engaged', array(
        'submission_id' => $submission,
        'metadata' => array('channel' => 'whatsapp'),
    ));
    Estandar_Leads_Repository::insert_event($lead_a, 'autoresponse_email_failed', array(
        'submission_id' => $submission + 1,
        'reason' => 'throttled',
        'metadata' => array('reason' => 'throttled'),
    ));
    Estandar_Leads_Repository::insert_event($lead_b, 'autoresponse_email_failed', array(
        'submission_id' => $submission + 2,
        'reason' => 'send_error',
        'metadata' => array('reason' => 'send_error'),
    ));
    Estandar_Leads_Repository::insert_event($lead_b, 'autoresponse_email_failed', array(
        'submission_id' => $submission + 3,
        'reason' => 'suspect',
        'metadata' => array('reason' => 'suspect'),
    ));

    $signals = Estandar_Leads_Portal::get_autoresponse_observability(array($lead_a, $lead_b));
    $stats = Estandar_Leads_Portal::get_autoresponse_stats(7);

    $assert(!empty($signals[$lead_a]['sent']), 'Sent signal missing.');
    $assert(!empty($signals[$lead_a]['pdf']), 'PDF signal missing.');
    $assert(!empty($signals[$lead_a]['whatsapp']), 'WhatsApp signal missing.');
    $assert(!empty($signals[$lead_a]['engaged']), 'Engaged signal missing.');
    $assert(($stats['sent'] - $baseline['sent']) === 1, 'Sent counter mismatch.');
    $assert(($stats['pdf_clicks'] - $baseline['pdf_clicks']) === 1, 'PDF counter mismatch.');
    $assert(($stats['whatsapp_clicks'] - $baseline['whatsapp_clicks']) === 1, 'WhatsApp must not double count derived engagement.');
    $assert(($stats['engaged_leads'] - $baseline['engaged_leads']) === 1, 'Unique engaged leads counter mismatch.');
    $assert(($stats['operational_failures'] - $baseline['operational_failures']) === 1, 'Operational failure counter mismatch.');
    $assert(($stats['throttled'] - $baseline['throttled']) === 1, 'Throttle counter mismatch.');
} finally {
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$events_table} WHERE lead_id IN (%d, %d)",
        $lead_a,
        $lead_b
    ));
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "PASS: LEAD_RESPONSE_OBSERVABILITY_V1\n";
