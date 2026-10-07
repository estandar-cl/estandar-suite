<?php
define('ABSPATH', __DIR__ . '/');
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string) $value)); }
// No emitter capability registered here → default false → legacy path stays active.
function apply_filters($tag, $value) { return $value; }

final class QAAdsWpdb {
    public $prefix = 'wp_';
    public $is_test = true;
    public function prepare($query, ...$args) { return array($query, $args); }
    public function get_var($prepared) {
        return json_encode(array('qa_protection' => array('is_test_lead' => $this->is_test)));
    }
}
$GLOBALS['wpdb'] = new QAAdsWpdb();

final class Estandar_Signal_Core_Repository {
    public static $records = 0;
    public static function record_from_lead(int $lead_id, string $event_name, array $args = array()): int {
        self::$records++;
        return self::$records;
    }
}

$root = dirname(__DIR__, 2);
require $root . '/estandar-leads/includes/class-estandar-leads-qa-policy.php';
require dirname(__DIR__) . '/includes/interface-estandar-signal-core-exporter.php';
require dirname(__DIR__) . '/includes/class-estandar-signal-core-hooks.php';
require dirname(__DIR__) . '/includes/class-estandar-signal-core-google-oci.php';
require dirname(__DIR__) . '/includes/class-estandar-signal-core-meta-capi.php';

$failed = 0;
$check = static function ($label, $condition) use (&$failed) {
    echo ($condition ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    $failed += $condition ? 0 : 1;
};

Estandar_Signal_Core_Hooks::on_lead_ingested(7, 10, true, array('qa' => array('is_test_lead' => true)));
Estandar_Signal_Core_Hooks::on_status_changed(7, 'NEW', 'QUALIFIED', '2026-07-13 12:00:00');
$check('QA does not create Signal Core records', Estandar_Signal_Core_Repository::$records === 0);

// Phase 2: Google OCI no longer self-gates QA — that decision is upstream (a QA
// lead never persists a signal, asserted above). The exporter now skips only on
// the technical contract (e.g. an event_name with no configured conversion action).
$google = new Estandar_Signal_Core_Google_OCI('dev', 'client', 'secret', 'refresh', '123', array('lead_created' => 'customers/123/conversionActions/1'));
$check('Google OCI skips unmapped event (contract-only, no HTTP)', $google->export(array('event_name' => 'sale_closed', 'gclid' => 'g')) === true);
// Phase 3: Meta CAPI no longer self-gates QA either — decided upstream (a QA lead
// never persists a signal, asserted above). The exporter now skips only on the
// technical contract (e.g. an event_name with no Meta standard-event mapping).
$meta = new Estandar_Signal_Core_Meta_Capi('pixel', 'token');
$check('Meta CAPI skips unmapped event (contract-only, no HTTP)', $meta->export(array('event_name' => 'lead_qualified')) === true);

$GLOBALS['wpdb']->is_test = false;
Estandar_Signal_Core_Hooks::on_lead_ingested(8, 11, true, array());
$check('real lead still creates Signal Core record', Estandar_Signal_Core_Repository::$records === 1);

exit($failed === 0 ? 0 : 1);
