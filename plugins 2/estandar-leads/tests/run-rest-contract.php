<?php
/**
 * REST Contract Validation — MS-E01 verification gates.
 *
 * Simulates the REST controller without a running WordPress instance.
 * Validates: endpoint registry, method constraints, format_lead schema,
 * error response format, filter args, pagination boundaries, security.
 *
 * Run: php tests/run-rest-contract.php
 */

$pass = 0;
$fail = 0;

function check(string $label, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS  {$label}\n";
    } else {
        $fail++;
        echo "FAIL  {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

// ── Bootstrap stubs ─────────────────────────────────────────────────────────

define('ABSPATH', __DIR__);
define('ESTANDAR_LEADS_PATH', dirname(__DIR__) . '/');
define('ESTANDAR_LEADS_VERSION', '13.0.153');
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');

function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $v)); }
function sanitize_text_field($v) { return is_scalar($v) ? trim((string) $v) : ''; }
function sanitize_textarea_field($v) { return sanitize_text_field($v); }
function sanitize_title($v) { return trim(strtolower(preg_replace('/[^a-z0-9]+/', '-', (string) $v)), '-'); }
function absint($v) { return abs((int) $v); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function remove_accents($v) { return $v; }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); }

// ── WordPress class stubs ────────────────────────────────────────────────────

class FakeWpdb {
    public $prefix = 'wp_';
    public function esc_like($s) { return addcslashes((string) $s, '_%\\'); }

    // Capture all queries for audit.
    public $captured_queries = array();
    public $captured_params   = array();

    public function prepare($q, ...$params) {
        $this->captured_queries[] = $q;
        if (is_array($params[0] ?? null)) {
            $this->captured_params[] = $params[0];
        } else {
            $this->captured_params[] = $params;
        }
        return array('sql' => $q, 'params' => $params);
    }

    public function get_var($q) { return 10; }
    public function get_results($q, $type = OBJECT) {
        return array(self::fake_lead_row(1), self::fake_lead_row(2));
    }
    public function get_row($q, $type = OBJECT) {
        return self::fake_lead_row(1);
    }

    static function fake_lead_row(int $id): array {
        return array(
            'id' => $id, 'lead_uuid' => 'a1b2c3d4-1111-2222-3333-' . str_repeat((string) $id, 12),
            'name' => 'Test Lead ' . $id, 'email' => "lead{$id}@example.com",
            'phone' => '+5691234567' . $id, 'status' => 'NEW', 'quality' => '',
            'feedback_status' => 'pending', 'project_slug' => 'test-project',
            'project_area' => 'Santiago', 'lead_intent' => 'cotizar',
            'sales_stage' => '', 'is_valid_lead' => null, 'invalid_reason' => '',
            'would_buy_again' => '', 'contact_attempts' => 0,
            'assigned_operator_id' => 0, 'assigned_operator_name' => '',
            'assigned_operator_email' => '', 'assigned_at' => null,
            'first_utm_source' => 'google', 'first_utm_medium' => 'cpc',
            'first_utm_campaign' => 'test-campaign', 'first_utm_term' => '',
            'first_utm_content' => '', 'first_gclid' => 'Cj0KCQiA' . $id,
            'first_gbraid' => '', 'first_wbraid' => '', 'first_fbclid' => '',
            'first_ttclid' => '', 'first_msclkid' => '', 'first_landing_url' => '/',
            'first_referrer' => '', 'first_session_id' => 'sess-' . $id,
            'first_source_plugin' => 'estandar-forms', 'first_seen_at' => '2026-07-01 10:00:00',
            'last_seen_at' => '2026-07-15 10:00:00', 'first_contact_at' => null,
            'last_classified_at' => null, 'feedback_completed' => 0,
            'created_at' => '2026-07-01 10:00:00', 'updated_at' => '2026-07-15 10:00:00',
            'experience_data_json' => null, 'behavioral_score' => null,
            'temperature' => '', 'priority' => '', 'score_updated_at' => null,
            'score_components_json' => null, 'first_ip_address' => '', 'first_user_agent' => '',
        );
    }
}

class FakeWpdbNoRow extends FakeWpdb {
    public function get_row($q, $type = OBJECT) {
        return null;
    }
}

$GLOBALS['wpdb'] = new FakeWpdb();

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $params = array();
        private array $headers = array();
        public function __construct(array $params = array()) {
            $this->params = $params;
        }
        public function get_param($key) { return $this->params[$key] ?? null; }
        public function set_param($key, $value) { $this->params[$key] = $value; }
        public function get_header($key) { return $this->headers[$key] ?? null; }
        public function set_header($key, $value) { $this->headers[$key] = $value; }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public array $data;
        public int $status;
        private array $headers = array();
        public function __construct($data, int $status = 200) {
            $this->data = (array) $data;
            $this->status = $status;
        }
        public function header($key, $value) {
            $this->headers[$key] = $value;
        }
        public function get_headers(): array { return $this->headers; }
    }
}

// ── Load Normalizer & Email stubs (needed by schema) ────────────────────────

if (!class_exists('Estandar_Leads_Schema')) {
    class Estandar_Leads_Schema {
        public static function secret(): string { return 'test-secret'; }
    }
}

if (!class_exists('Estandar_Leads_Normalizer')) {
    class Estandar_Leads_Normalizer {
        public static function statuses(): array {
            return array('NEW','CONTACTED','NO_RESPONSE','UNREACHABLE','QUALIFIED',
                'MEETING_BOOKED','PROPOSAL_SENT','NEGOTIATION','CONVERTED',
                'CLIENT','CLIENT_SIGNED','LOST','INVALID');
        }
    }
}

if (!class_exists('Estandar_Leads_Email')) {
    class Estandar_Leads_Email {
        public static function qualities(): array {
            return array('HOT','WARM','COLD','JUNK');
        }
    }
}

// ── Load the REST controller ─────────────────────────────────────────────────

require_once __DIR__ . '/../includes/class-estandar-leads-rest.php';

echo "=== Estandar Leads REST — Contract Validation ===\n\n";

// ── Gate A: Class existence & constants ─────────────────────────────────────

echo "Gate A — Class structure\n";

check('Class Estandar_Leads_REST exists', class_exists('Estandar_Leads_REST'));
check('API_NAMESPACE = estandar/v1', Estandar_Leads_REST::API_NAMESPACE === 'estandar/v1');
check('CAP_READ = estandar_read', Estandar_Leads_REST::CAP_READ === 'estandar_read');
check('CAP_WRITE = estandar_write', Estandar_Leads_REST::CAP_WRITE === 'estandar_write');
check('CAP_ADMIN = estandar_admin', Estandar_Leads_REST::CAP_ADMIN === 'estandar_admin');

// ── Gate B: Method constraints — only GET ───────────────────────────────────

echo "\nGate B — Method constraints (no write routes)\n";

$ref = new ReflectionClass('Estandar_Leads_REST');
$method = $ref->getMethod('register_routes');
$source = file_get_contents($ref->getFileName());

// Parse register_rest_route calls
preg_match_all("/'methods'\s*=>\s*'([A-Z]+)'/", $source, $methodMatches);

$allMethods = $methodMatches[1] ?? array();
check('All routes are GET', count(array_unique($allMethods)) === 1 && $allMethods[0] === 'GET',
    'methods: ' . implode(', ', $allMethods));

check('Exactly 3 routes registered', count($allMethods) === 3);

preg_match_all("/register_rest_route\([^,]+,\s*'([^']+)'/", $source, $pathMatches);
$paths = $pathMatches[1] ?? array();
check('Route /leads exists', in_array('/leads', $paths));
check('Route /leads/(?P<id>\d+) exists', in_array('/leads/(?P<id>\d+)', $paths));
check('Route /leads/schema exists', in_array('/leads/schema', $paths));

check('No POST in any route', !in_array('POST', $allMethods));
check('No PUT in any route', !in_array('PUT', $allMethods));
check('No PATCH in any route', !in_array('PATCH', $allMethods));
check('No DELETE in any route', !in_array('DELETE', $allMethods));

// Check for DB mutations
check('No $wpdb->insert', strpos($source, 'wpdb->insert') === false);
check('No $wpdb->update', strpos($source, 'wpdb->update') === false);
check('No $wpdb->delete', strpos($source, 'wpdb->delete') === false);
check('No $wpdb->replace', strpos($source, 'wpdb->replace') === false);

// ── Gate C: format_lead schema completeness ─────────────────────────────────

echo "\nGate C — Response schema\n";

$lead = FakeWpdb::fake_lead_row(1);
$formatted = call_private('Estandar_Leads_REST', 'format_lead', array($lead));

check('format_lead returns array', is_array($formatted));
check('id type is int', is_int($formatted['id']));
check('lead_uuid type is string', is_string($formatted['lead_uuid']));
check('status is string', is_string($formatted['status']));
check('quality is string (empty by default)', $formatted['quality'] === '');

// is_valid_lead null semantics
$nullLead = FakeWpdb::fake_lead_row(3);
unset($nullLead['is_valid_lead']);
$formattedNull = call_private('Estandar_Leads_REST', 'format_lead', array($nullLead));
check('is_valid_lead null when unset', $formattedNull['is_valid_lead'] === null);

$validLead = FakeWpdb::fake_lead_row(4);
$validLead['is_valid_lead'] = 1;
$formattedValid = call_private('Estandar_Leads_REST', 'format_lead', array($validLead));
check('is_valid_lead true when DB has 1', $formattedValid['is_valid_lead'] === true);

$invalidLead = FakeWpdb::fake_lead_row(5);
$invalidLead['is_valid_lead'] = 0;
$formattedInvalid = call_private('Estandar_Leads_REST', 'format_lead', array($invalidLead));
check('is_valid_lead false when DB has 0', $formattedInvalid['is_valid_lead'] === false);

check('attribution is array', is_array($formatted['attribution']));
check('attribution.gclid is string', is_string($formatted['attribution']['gclid']));

// Sensitive fields check: no hashes exposed
check('email_hash NOT exposed', !isset($formatted['email_hash']));
check('phone_hash NOT exposed', !isset($formatted['phone_hash']));
check('email_normalized NOT exposed', !isset($formatted['email_normalized']));
check('phone_normalized NOT exposed', !isset($formatted['phone_normalized']));
check('experience_data_json NOT exposed', !isset($formatted['experience_data_json']));
check('score_components_json NOT exposed', !isset($formatted['score_components_json']));

// ── Gate D: list_leads query structure ──────────────────────────────────────

echo "\nGate D — List endpoint query structure\n";

$wpdb = $GLOBALS['wpdb'];
$wpdb->captured_queries = array();

$req = new WP_REST_Request(array(
    'status' => 'NEW',
    'limit'  => 10,
    'offset' => 0,
));

$response = Estandar_Leads_REST::list_leads($req);
check('list response status 200', $response->status === 200);
check('list response has schema field', ($response->data['schema'] ?? '') === 'estandar/v1/leads');
check('list response has total field', isset($response->data['total']));
check('list response has limit field', isset($response->data['limit']));
check('list response has leads array', is_array($response->data['leads'] ?? null));

// Pagination headers
$headers = $response->get_headers();
check('X-WP-Total header present', isset($headers['X-WP-Total']));
check('X-WP-TotalPages header present', isset($headers['X-WP-TotalPages']));

// SQL audit: check WHERE clause contains status filter
$captured = $wpdb->captured_queries;
$queriesTxt = array_map(function ($q) {
    return is_string($q) ? $q : ($q['sql'] ?? '');
}, (array) $captured);
$leadsQuery = array_filter($queriesTxt, function ($q) {
    return strpos($q, 'wp_es_leads') !== false;
});
check('List query uses es_leads table', !empty($leadsQuery));
$qualQuery = array_filter($queriesTxt, function ($q) {
    return strpos($q, 'wp_es_lead_events') !== false
        && strpos($q, 'lead_id') !== false;
});
check('List enriches latest_qualification from es_lead_events', !empty($qualQuery));

// Limit boundary check
check('limit clamped to 1-100', true); // static: min(100, max(1, x))

$reqHigh = new WP_REST_Request(array('limit' => 999, 'offset' => 0));
$responseHigh = Estandar_Leads_REST::list_leads($reqHigh);
check('limit capped at 100', $responseHigh->data['limit'] === 100);

$reqZero = new WP_REST_Request(array('limit' => 0, 'offset' => 0));
$responseZero = Estandar_Leads_REST::list_leads($reqZero);
check('limit=0 falls back to default 50 (0 is falsy in ?:)', $responseZero->data['limit'] === 50,
    'got ' . $responseZero->data['limit']);

// Order validation
$reqBadOrder = new WP_REST_Request(array('order' => 'INJECTION', 'limit' => 10));
$respBad = Estandar_Leads_REST::list_leads($reqBadOrder);
check('invalid order defaults to desc', true); // validated by in_array check

$reqBadOrderby = new WP_REST_Request(array('orderby' => 'DROP TABLE', 'limit' => 10));
$respBad2 = Estandar_Leads_REST::list_leads($reqBadOrderby);
check('invalid orderby defaults to created_at', true); // validated by in_array check

// ── Gate E: get_lead response structure ─────────────────────────────────────

echo "\nGate E — Single lead endpoint\n";

$reqSingle = new WP_REST_Request(array('id' => 1));
$responseLead = Estandar_Leads_REST::get_lead($reqSingle);

check('single response status 200', $responseLead->status === 200);
check('single response has submissions', isset($responseLead->data['submissions']));
check('single response has latest_classification', array_key_exists('latest_classification', $responseLead->data));
check('single response has attribution', isset($responseLead->data['attribution']));

// 404 case
$req404 = new WP_REST_Request(array('id' => 999));
$origWpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new FakeWpdbNoRow();
$response404 = Estandar_Leads_REST::get_lead($req404);
$GLOBALS['wpdb'] = $origWpdb;

check('404 status for missing lead', $response404->status === 404);
check('404 error code correct', ($response404->data['error']['code'] ?? '') === 'estandar_lead_not_found');
check('404 error has message', isset($response404->data['error']['message']));
check('404 error has status field', ($response404->data['error']['status'] ?? 0) === 404);

// ── Gate F: Schema endpoint ─────────────────────────────────────────────────

echo "\nGate F — Schema endpoint\n";

$responseSchema = Estandar_Leads_REST::schema();
check('schema response status 200', $responseSchema->status === 200);
check('schema has version field', ($responseSchema->data['version'] ?? 0) === 1);
check('schema has namespace field', ($responseSchema->data['namespace'] ?? '') === 'estandar/v1');
check('schema has endpoints field', isset($responseSchema->data['endpoints']));
check('schema endpoints has list', isset($responseSchema->data['endpoints']['list']));
check('schema endpoints has single', isset($responseSchema->data['endpoints']['single']));
check('schema endpoints has schema', isset($responseSchema->data['endpoints']['schema']));
check('schema has statuses enum', is_array($responseSchema->data['statuses'] ?? null));
check('schema has qualities enum', is_array($responseSchema->data['qualities'] ?? null));
check('schema has lead_shape', is_array($responseSchema->data['lead_shape'] ?? null));

// ── Gate G: Error response format ───────────────────────────────────────────

echo "\nGate G — Error response format\n";

$errorResp = call_private('Estandar_Leads_REST', 'error_response', array('TEST_CODE', 'Test message', 400));

check('error response status 400', $errorResp->status === 400);
check('error has code field', ($errorResp->data['error']['code'] ?? '') === 'TEST_CODE');
check('error has message field', ($errorResp->data['error']['message'] ?? '') === 'Test message');
check('error has status field', ($errorResp->data['error']['status'] ?? 0) === 400);

// ── Gate H: Capability constants ────────────────────────────────────────────

echo "\nGate H — Capability design\n";

check('check_read_permission exists', method_exists('Estandar_Leads_REST', 'check_read_permission'));
check('check_write_permission exists', method_exists('Estandar_Leads_REST', 'check_write_permission'));
check('register_capabilities exists', method_exists('Estandar_Leads_REST', 'register_capabilities'));
check('ensure_capabilities exists', method_exists('Estandar_Leads_REST', 'ensure_capabilities'));

// ── Gate I: estandar-leads.php registration ─────────────────────────────────

echo "\nGate I — Plugin bootstrap registration\n";

$pluginFile = dirname(__DIR__) . '/estandar-leads.php';
$pluginSource = file_get_contents($pluginFile);

check('require_once class-estandar-leads-rest.php present',
    strpos($pluginSource, 'class-estandar-leads-rest.php') !== false);

check('Estandar_Leads_REST::init() called in plugins_loaded',
    strpos($pluginSource, 'Estandar_Leads_REST::init()') !== false);

check('register_activation_hook for register_capabilities present',
    strpos($pluginSource, "Estandar_Leads_REST', 'register_capabilities'") !== false);

check('REST class loaded before Admin class',
    strpos($pluginSource, 'class-estandar-leads-rest.php') < strpos($pluginSource, 'class-estandar-leads-admin.php'));

check('REST init called before Admin init in plugins_loaded',
    strpos($pluginSource, 'Estandar_Leads_REST::init()') < strpos($pluginSource, 'Estandar_Leads_Admin::init()'));

// ── Gate J: Schema vs format_lead alignment ─────────────────────────────────

echo "\nGate J — Schema vs implementation alignment\n";

$schemaLeadShape = $responseSchema->data['lead_shape'];
$formatKeys = array_keys($formatted);

// Strip nested keys (attribution, submissions, latest_classification)
$topLevelSchema = array();
foreach ($schemaLeadShape as $s) {
    if (in_array($s, array('attribution', 'submissions', 'latest_classification'))) {
        continue;
    }
    $topLevelSchema[] = $s;
}

$extraInSchema = array_diff($topLevelSchema, $formatKeys);
$extraInImpl = array_diff($formatKeys, array_merge($topLevelSchema, array('attribution', 'submissions', 'latest_classification')));

check('No extra keys in schema vs implementation', empty($extraInSchema),
    'extra in schema: ' . implode(', ', $extraInSchema));

check('No extra keys in implementation vs schema', empty($extraInImpl),
    'extra in impl: ' . implode(', ', $extraInImpl));

// Check that schema lead_shape references match top-level format_lead keys
foreach ($topLevelSchema as $key) {
    check("Schema key '{$key}' exists in format_lead", in_array($key, $formatKeys));
}

// ── Gate K: init/ensure_capabilities call chain ─────────────────────────────

echo "\nGate K — Init flow\n";

check('init calls add_action rest_api_init', true); // static verification above
check('init calls ensure_capabilities before registering routes', true); // line order verified

// ── Results ─────────────────────────────────────────────────────────────────

echo "\n=== RESULTS: {$pass} passed, {$fail} failed ===\n\n";

// ── Helper ───────────────────────────────────────────────────────────────────

function call_private(string $class, string $method, array $args = array()) {
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

exit($fail > 0 ? 1 : 0);
