<?php

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../includes/class-estandar-leads-normalizer.php';
require_once __DIR__ . '/../includes/class-estandar-leads-feedback.php';

$failed = 0;
function check($label, $ok) {
    global $failed;
    echo ($ok ? "PASS  " : "FAIL  ") . $label . "\n";
    if (!$ok) { $failed++; }
}

$selectable = array(
    'QUALIFIED', 'MEETING_BOOKED', 'VISIT_SCHEDULED', 'VISITED',
    'RESERVED', 'CLIENT', 'CLIENT_SIGNED', 'DEED_SIGNED', 'DELIVERED',
);
$compatible = array_merge($selectable, array('CONVERTED'));

$statuses = Estandar_Leads_Normalizer::statuses();
$display_statuses = Estandar_Leads_Normalizer::display_statuses();
$display_stages = Estandar_Leads_Normalizer::display_commercial_stages();
$stage_labels = Estandar_Leads_Normalizer::commercial_stage_labels();

$r = new ReflectionClass('Estandar_Leads_Feedback');
$feedback_labels = $r->getMethod('sales_stage_labels');
$feedback_map = $r->getMethod('status_from_sales_stage');
$feedback_options = $feedback_labels->invoke(null);

foreach ($compatible as $status) {
    check("$status in statuses()", in_array($status, $statuses, true));
    check("$status in commercial_stage_labels()", isset($stage_labels[$status]));
    check("$status maps to status", Estandar_Leads_Normalizer::status_from_commercial_stage($status) === $status);
    check("$status feedback maps to status", $feedback_map->invoke(null, $status, 1) === $status);
}

foreach ($selectable as $status) {
    check("$status in display_statuses()", isset($display_statuses[$status]));
    check("$status in display_commercial_stages()", isset($display_stages[$status]));
    check("$status in feedback options", isset($feedback_options[$status]));
}

check('CONVERTED hidden from display_statuses()', !isset($display_statuses['CONVERTED']));
check('CONVERTED hidden from display_commercial_stages()', !isset($display_stages['CONVERTED']));
check('CONVERTED hidden from feedback options', !isset($feedback_options['CONVERTED']));
check('SALE remains backward-compatible', Estandar_Leads_Normalizer::status_from_commercial_stage('SALE') === 'DEED_SIGNED');
check('CUSTOMER remains backward-compatible', $feedback_map->invoke(null, 'CUSTOMER', 1) === 'CONVERTED');

echo "\n" . (count($compatible) * 4 + count($selectable) * 3 + 5 - $failed) . " passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
