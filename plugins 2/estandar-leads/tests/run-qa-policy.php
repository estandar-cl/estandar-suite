<?php
define('ABSPATH', __DIR__ . '/');
$GLOBALS['__logged_in'] = false;
function is_user_logged_in() { return !empty($GLOBALS['__logged_in']); }
require dirname(__DIR__) . '/includes/class-estandar-leads-qa-policy.php';

$config = array(
    'enabled' => true,
    'internal_actors' => array('admin', 'system'),
    'explicit_flags' => array('meta.is_qa'),
    'email_domains' => array('internal.test'),
    'phone_patterns' => array('/^5690000/'),
    'tokens' => array('qa-only'),
);
$base = array(
    'contact' => array('name' => 'Persona Real', 'email' => 'real@example.com', 'phone' => '56912345678'),
    'content' => array('message' => 'Cotización real'),
    'context' => array('project_slug' => 'project'),
    'meta' => array(),
);
$cases = array(
    'real lead eligible' => array($base, false),
    'internal actor' => array(array_replace_recursive($base, array('actor_type' => 'admin')), true),
    'explicit flag' => array(array_replace_recursive($base, array('meta' => array('is_qa' => true))), true),
    'configured email domain' => array(array_replace_recursive($base, array('contact' => array('email' => 'qa@internal.test'))), true),
    'configured phone pattern' => array(array_replace_recursive($base, array('contact' => array('phone' => '56900001234'))), true),
    'configured token' => array(array_replace_recursive($base, array('content' => array('message' => 'QA-ONLY'))), true),
);
$failed = 0;
foreach ($cases as $label => $case) {
    $result = Estandar_Leads_QA_Policy::evaluate($case[0], $config);
    $ok = $result['is_test_lead'] === $case[1];
    if ($case[1]) {
        $ok = $ok && $result['score_eligible'] === false && $result['qualification'] === 'test'
            && $result['routing'] === 'skip' && $result['notification'] === 'skip'
            && $result['ads_feedback'] === 'skip' && $result['sla'] === 'skip' && $result['escalation'] === 'skip';
    }
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

$GLOBALS['__logged_in'] = true;
$authenticated = Estandar_Leads_QA_Policy::evaluate($base, array('enabled' => true, 'detect_authenticated_users' => true));
$ok = $authenticated['is_test_lead'] && strpos($authenticated['test_reason'], 'authenticated_user') !== false;
echo ($ok ? 'PASS  ' : 'FAIL  ') . "authenticated user\n";
$failed += $ok ? 0 : 1;
$GLOBALS['__logged_in'] = false;

$project_config = array(
    'enabled' => false,
    'projects' => array('project' => array('enabled' => true, 'tokens' => array('project-qa'), 'autoresponse' => 'allow')),
);
$project_payload = array_replace_recursive($base, array('content' => array('message' => 'project-qa')));
$project = Estandar_Leads_QA_Policy::evaluate($project_payload, $project_config);
$ok = $project['is_test_lead'] && $project['autoresponse'] === 'allow';
echo ($ok ? 'PASS  ' : 'FAIL  ') . "project override and configurable autoresponse\n";
$failed += $ok ? 0 : 1;
exit($failed === 0 ? 0 : 1);
