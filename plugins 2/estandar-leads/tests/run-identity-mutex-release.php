<?php
/**
 * MUTEX_LEAK_FIX_V1 — focused regression suite for the Identity Mutex balance.
 * Run: php tests/run-identity-mutex-release.php
 *      (or ESTANDAR_MYSQL_GATE_DB=1 … for the transactional MySQL gate)
 *
 * Proves that Estandar_Leads_Ingestion::capture() releases the canonical
 * identity mutex on EVERY exit after acquisition — success, each error return,
 * the idempotent-duplicate return, and an uncaught Throwable in the critical
 * section — and that a Throwable rolls the transaction back and re-propagates.
 * The decisive check: an immediate re-submit of the SAME identity can acquire
 * the lock again (i.e. it was not left held until its 30s expiry).
 *
 * Fault injection is done with a transparent $wpdb proxy that fails or throws
 * on the es_lead_submissions INSERT (mid critical section, inside the tx).
 */

if (getenv('ESTANDAR_MYSQL_GATE_DB')) {
    require __DIR__ . '/bootstrap-wordpress-mysql.php';
} else {
    require dirname(__DIR__, 4) . '/wp-load.php';
}

if (!class_exists('Estandar_Leads_Ingestion') || !method_exists('Estandar_Leads_Ingestion', 'capture')) {
    fwrite(STDERR, "Estandar_Leads_Ingestion::capture() unavailable.\n");
    exit(1);
}

global $wpdb;
$real_wpdb = $wpdb;

$passed = 0;
$failed = 0;
function mx_check(string $label, bool $ok): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS  {$label}\n"; }
    else { $failed++; echo "FAIL  {$label}\n"; }
}

// Silence downstream side effects (notifier / autoresponse / webhook / analytics)
// so the suite only exercises ingestion + the mutex. Process-local only.
remove_all_actions('estandar_lead_ingested');
add_filter('pre_http_request', static function () {
    return array('response' => array('code' => 200, 'message' => 'OK'), 'body' => '', 'headers' => array(), 'cookies' => array());
}, 10, 3);

// Capture telemetry codes + recovery stages emitted during ingestion.
$telemetry_codes = array();
$telemetry_stages = array();
add_action('estandar_telemetry_event', static function ($event) use (&$telemetry_codes, &$telemetry_stages) {
    if (!is_array($event)) { return; }
    if (isset($event['code'])) { $telemetry_codes[] = (string) $event['code']; }
    if (isset($event['context']['stage'])) { $telemetry_stages[] = (string) $event['context']['stage']; }
});

// Reflection access to the private mutex primitives (recovery is tested in
// isolation — capture() cannot deterministically stage an expired lock).
$ACQ = new ReflectionMethod('Estandar_Leads_Ingestion', 'acquire_identity_mutex');
$ACQ->setAccessible(true);
$REL = new ReflectionMethod('Estandar_Leads_Ingestion', 'release_identity_mutex');
$REL->setAccessible(true);
function mx_acquire($eh, $ph) { global $ACQ; return $ACQ->invoke(null, $eh, $ph); }
function mx_release($mutex) { global $REL; $REL->invoke(null, $mutex); }

function mx_email_hash(string $email): string {
    return Estandar_Leads_Normalizer::hash(Estandar_Leads_Normalizer::email($email));
}
function mx_seed_expired_lock(string $key, int $ago = 1902): string {
    $vk = '_transient_estandar_leads_identity_' . md5($key);
    delete_option($vk);
    add_option($vk, wp_json_encode(array('token' => 'stale-' . uniqid('', true), 'expires' => time() - $ago)), '', 'no');
    return $vk;
}
function mx_seed_future_lock(string $key, int $ahead = 25): string {
    $vk = '_transient_estandar_leads_identity_' . md5($key);
    delete_option($vk);
    add_option($vk, wp_json_encode(array('token' => 'live-' . uniqid('', true), 'expires' => time() + $ahead)), '', 'no');
    return $vk;
}

/** Scoped proxy to fail options delete / re-insert ONLY for identity locks. */
class Mutex_QA_Options_Proxy {
    private $inner; private $mode; private $prefix = '_transient_estandar_leads_identity_';
    public function __construct($inner, string $mode) { $this->inner = $inner; $this->mode = $mode; }
    public function delete($table, $where, $where_format = null) {
        if ($this->mode === 'delete_fail' && $table === $this->inner->options
            && isset($where['option_name']) && strpos((string) $where['option_name'], $this->prefix) === 0) {
            return false; // simulate a failed delete; row remains
        }
        return $this->inner->delete($table, $where, $where_format);
    }
    public function query($q) {
        // Both fault modes must block the re-add INSERT so the recovery reaches
        // its fail-closed branch; the stage is derived from delete_option()'s
        // own result (delete_fail → row remained; reacquire_fail → row deleted).
        if (($this->mode === 'reacquire_fail' || $this->mode === 'delete_fail') && is_string($q)
            && stripos($q, 'insert') !== false && strpos($q, $this->prefix) !== false) {
            return false; // simulate a failed re-INSERT of the lock option
        }
        return $this->inner->query($q);
    }
    public function __call($n, $a) { return $this->inner->$n(...$a); }
    public function __get($n) { return $this->inner->$n; }
    public function __set($n, $v) { $this->inner->$n = $v; }
    public function __isset($n) { return isset($this->inner->$n); }
}

/** Counts real deletes of identity-lock options (forwards them normally). */
class Mutex_QA_Delete_Counter {
    public $inner; public $lock_deletes = 0; private $prefix = '_transient_estandar_leads_identity_';
    public function __construct($inner) { $this->inner = $inner; }
    public function delete($table, $where, $where_format = null) {
        if ($table === $this->inner->options && isset($where['option_name'])
            && strpos((string) $where['option_name'], $this->prefix) === 0) {
            $this->lock_deletes++;
        }
        return $this->inner->delete($table, $where, $where_format);
    }
    public function __call($n, $a) { return $this->inner->$n(...$a); }
    public function __get($n) { return $this->inner->$n; }
    public function __set($n, $v) { $this->inner->$n = $v; }
    public function __isset($n) { return isset($this->inner->$n); }
}

// ── helpers ──────────────────────────────────────────────────────────────────
$run_token = 'mutexqa_' . substr(md5(uniqid('', true)), 0, 10);
$lock_names_all = array();

function mx_lock_names(string $email, string $phone): array {
    $email_n = Estandar_Leads_Normalizer::email($email);
    $phone_n = Estandar_Leads_Normalizer::phone_storage($phone);
    $email_h = Estandar_Leads_Normalizer::hash($email_n);
    $phone_h = Estandar_Leads_Normalizer::hash($phone_n);
    $names = array();
    if ($email_h !== '') { $names[] = '_transient_estandar_leads_identity_' . md5('email:' . $email_h); }
    if ($phone_h !== '') { $names[] = '_transient_estandar_leads_identity_' . md5('phone:' . $phone_h); }
    return $names;
}

function mx_locks_free(array $names): bool {
    foreach ($names as $n) {
        if (get_option($n, '__missing__') !== '__missing__') { return false; }
    }
    return true;
}

function mx_count_identity_locks(): int {
    global $wpdb;
    return (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_estandar_leads_identity_%'"
    );
}

function mx_lead_id_for(string $email): int {
    global $wpdb;
    $hash = Estandar_Leads_Normalizer::hash(Estandar_Leads_Normalizer::email($email));
    if ($hash === '') { return 0; }
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}es_leads WHERE email_hash = %s LIMIT 1", $hash
    ));
}

function mx_submission_count(int $lead_id): int {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d", $lead_id
    ));
}

$entry_seq = 1000;
function mx_payload(string $email, string $phone, int &$entry_seq, ?string $uuid = null): array {
    $entry_seq++;
    return array(
        'schema_version' => '1.0',
        'source_plugin'  => 'estandar-forms',
        'submitted_at'   => gmdate('c'),
        'contact'        => array('name' => 'QA Mutex', 'email' => $email, 'phone' => $phone),
        'content'        => array('message' => 'qa mutex', 'source_form_id' => 'mutex-qa-form'),
        'attribution'    => array(),
        'context'        => array('project_slug' => '', 'intent' => 'lead_capture'),
        'meta'           => array('source_entry_id' => $entry_seq, 'lead_uuid' => $uuid ?: wp_generate_uuid4()),
    );
}

/** Transparent proxy that fails/throws on the submissions INSERT. */
class Mutex_QA_WPDB_Proxy {
    private $inner; private $mode; private $target = 'es_lead_submissions';
    public function __construct($inner, string $mode) { $this->inner = $inner; $this->mode = $mode; }
    public function insert($table, $data, $format = null) {
        if (strpos((string) $table, $this->target) !== false) {
            if ($this->mode === 'throw') { throw new RuntimeException('INJECTED_THROWABLE_SUBMISSION'); }
            if ($this->mode === 'fail')  { $this->inner->last_error = 'INJECTED_FAILURE'; return false; }
        }
        return $this->inner->insert($table, $data, $format);
    }
    public function __call($name, $args) { return $this->inner->$name(...$args); }
    public function __get($n) { return $this->inner->$n; }
    public function __set($n, $v) { $this->inner->$n = $v; }
    public function __isset($n) { return isset($this->inner->$n); }
}

$baseline_locks = mx_count_identity_locks();

// Identities (unique per run → no collision with real data).
$idA_email = $run_token . '-a@example.test'; $idA_phone = '+56990000001';
$idB_email = $run_token . '-b@example.test'; $idB_phone = '+56990000002';
$idC_email = $run_token . '-c@example.test'; $idC_phone = '+56990000003';
$idD_email = $run_token . '-d@example.test'; // recovery cases (acquire-only)
$idE_email = $run_token . '-e@example.test';
$idF_email = $run_token . '-f@example.test';
$idG_email = $run_token . '-g@example.test';
$idH_email = $run_token . '-h@example.test'; $idH_phone = '+56990000008'; // order + reentrancy
$idJ_email = $run_token . '-j@example.test';                              // single-release (email-only)
$idK_email = $run_token . '-k@example.test'; $idK_phone = '+56990000011'; // post-COMMIT throwable
$lock_names_all = array_merge(
    mx_lock_names($idA_email, $idA_phone),
    mx_lock_names($idB_email, $idB_phone),
    mx_lock_names($idC_email, $idC_phone),
    mx_lock_names($idD_email, ''),
    mx_lock_names($idE_email, ''),
    mx_lock_names($idF_email, ''),
    mx_lock_names($idG_email, ''),
    mx_lock_names($idH_email, $idH_phone),
    mx_lock_names($idJ_email, ''),
    mx_lock_names($idK_email, $idK_phone)
);

try {
    // ── CASE 1 — NEW lead success releases the lock ──────────────────────────
    $pA1 = mx_payload($idA_email, $idA_phone, $entry_seq);
    $r1 = Estandar_Leads_Ingestion::capture($pA1);
    $snap1 = Estandar_Leads_Ingestion::last_ingested();
    $leadA = mx_lead_id_for($idA_email);
    mx_check('1 new: lead persisted', $r1 > 0 && $leadA === (int) $r1);
    mx_check('1 new: last_ingested set, not duplicate', is_array($snap1) && !empty($snap1['lead_id']) && empty($snap1['duplicate']));
    mx_check('1 new: exactly one submission', mx_submission_count($leadA) === 1);
    mx_check('1 new: identity lock released', mx_locks_free(mx_lock_names($idA_email, $idA_phone)));

    // ── CASE 2 — RECONTACT (same identity, new submission) releases the lock ──
    $pA2 = mx_payload($idA_email, $idA_phone, $entry_seq); // distinct uuid+entry
    $r2 = Estandar_Leads_Ingestion::capture($pA2);
    $snap2 = Estandar_Leads_Ingestion::last_ingested();
    mx_check('2 recontact: same lead id', $r2 === $leadA);
    mx_check('2 recontact: last_ingested not duplicate', is_array($snap2) && empty($snap2['duplicate']));
    mx_check('2 recontact: two submissions', mx_submission_count($leadA) === 2);
    mx_check('2 recontact: identity lock released', mx_locks_free(mx_lock_names($idA_email, $idA_phone)));

    // ── CASE 3 — DUPLICATE idempotent (replay same payload) releases the lock ─
    $r3 = Estandar_Leads_Ingestion::capture($pA2); // identical array → same payload_hash
    $snap3 = Estandar_Leads_Ingestion::last_ingested();
    mx_check('3 duplicate: same lead id returned', $r3 === $leadA);
    mx_check('3 duplicate: last_ingested duplicate=true', is_array($snap3) && !empty($snap3['duplicate']));
    mx_check('3 duplicate: submission count unchanged (2)', mx_submission_count($leadA) === 2);
    mx_check('3 duplicate: identity lock released', mx_locks_free(mx_lock_names($idA_email, $idA_phone)));

    // ── CASE 4 — ERROR return (submission insert fails) rolls back + releases ─
    $wpdb = new Mutex_QA_WPDB_Proxy($real_wpdb, 'fail');
    $GLOBALS['wpdb'] = $wpdb;
    $pB = mx_payload($idB_email, $idB_phone, $entry_seq);
    $r4 = Estandar_Leads_Ingestion::capture($pB);
    $snap4 = Estandar_Leads_Ingestion::last_ingested();
    $GLOBALS['wpdb'] = $wpdb = $real_wpdb;
    mx_check('4 error: capture returned 0', $r4 === 0);
    mx_check('4 error: last_ingested null (no persistence)', $snap4 === null);
    mx_check('4 error: lead rolled back (absent)', mx_lead_id_for($idB_email) === 0);
    mx_check('4 error: identity lock released', mx_locks_free(mx_lock_names($idB_email, $idB_phone)));

    // ── CASE 5 — THROWABLE in critical section rolls back, releases, rethrows ─
    $telemetry_codes = array();
    $wpdb = new Mutex_QA_WPDB_Proxy($real_wpdb, 'throw');
    $GLOBALS['wpdb'] = $wpdb;
    $threw = false; $threw_msg = '';
    $pC1 = mx_payload($idC_email, $idC_phone, $entry_seq);
    try {
        Estandar_Leads_Ingestion::capture($pC1);
    } catch (Throwable $e) {
        $threw = true; $threw_msg = $e->getMessage();
    } finally {
        $GLOBALS['wpdb'] = $wpdb = $real_wpdb;
    }
    mx_check('5 throwable: exception re-propagated to caller', $threw && strpos($threw_msg, 'INJECTED_THROWABLE_SUBMISSION') !== false);
    mx_check('5 throwable: LEADS_INGESTION_EXCEPTION telemetry emitted', in_array('LEADS_INGESTION_EXCEPTION', $telemetry_codes, true));
    mx_check('5 throwable: lead rolled back (absent)', mx_lead_id_for($idC_email) === 0);
    mx_check('5 throwable: identity lock released', mx_locks_free(mx_lock_names($idC_email, $idC_phone)));

    // ── CASE 6 — Re-acquire proof: same identity submits fine right after ─────
    $pC2 = mx_payload($idC_email, $idC_phone, $entry_seq);
    $r6 = Estandar_Leads_Ingestion::capture($pC2);
    $snap6 = Estandar_Leads_Ingestion::last_ingested();
    mx_check('6 re-acquire: same identity persists after throwable', $r6 > 0 && is_array($snap6) && !empty($snap6['lead_id']));
    mx_check('6 re-acquire: identity lock released', mx_locks_free(mx_lock_names($idC_email, $idC_phone)));

    // ── CASE 7 — EXPIRED lock is deleted and re-acquired ─────────────────────
    $ehD = mx_email_hash($idD_email); $keyD = 'email:' . $ehD; $vkD = mx_seed_expired_lock($keyD);
    $telemetry_codes = array(); $telemetry_stages = array();
    $mD = mx_acquire($ehD, '');
    mx_check('7 expired: re-acquired after removing expired lock', is_array($mD) && !empty($mD['locks']));
    mx_check('7 expired: no recovery_failed telemetry', !in_array('LEADS_IDENTITY_MUTEX_RECOVERY_FAILED', $telemetry_codes, true));
    mx_check('7 expired: fresh lock present after acquire', get_option($vkD, '__gone__') !== '__gone__');
    if (is_array($mD)) { mx_release($mD); }
    mx_check('7 expired: lock released', get_option($vkD, '__gone__') === '__gone__');

    // ── CASE 8 — delete failure → staged telemetry (delete_failed) ───────────
    $ehE = mx_email_hash($idE_email); $keyE = 'email:' . $ehE; $vkE = mx_seed_expired_lock($keyE);
    $telemetry_codes = array(); $telemetry_stages = array();
    $wpdb = new Mutex_QA_Options_Proxy($real_wpdb, 'delete_fail');
    $GLOBALS['wpdb'] = $wpdb;
    $mE = mx_acquire($ehE, '');
    $GLOBALS['wpdb'] = $wpdb = $real_wpdb;
    mx_check('8 delete_failed: acquire returned false (fail-closed)', $mE === false);
    mx_check('8 delete_failed: RECOVERY_FAILED telemetry', in_array('LEADS_IDENTITY_MUTEX_RECOVERY_FAILED', $telemetry_codes, true));
    mx_check('8 delete_failed: exact stage=delete_failed', in_array('delete_failed', $telemetry_stages, true));
    delete_option($vkE);

    // ── CASE 9 — re-acquire failure → staged telemetry (reacquire_failed) ────
    $ehF = mx_email_hash($idF_email); $keyF = 'email:' . $ehF; $vkF = mx_seed_expired_lock($keyF);
    $telemetry_codes = array(); $telemetry_stages = array();
    $wpdb = new Mutex_QA_Options_Proxy($real_wpdb, 'reacquire_fail');
    $GLOBALS['wpdb'] = $wpdb;
    $mF = mx_acquire($ehF, '');
    $GLOBALS['wpdb'] = $wpdb = $real_wpdb;
    mx_check('9 reacquire_failed: acquire returned false (fail-closed)', $mF === false);
    mx_check('9 reacquire_failed: RECOVERY_FAILED telemetry', in_array('LEADS_IDENTITY_MUTEX_RECOVERY_FAILED', $telemetry_codes, true));
    mx_check('9 reacquire_failed: exact stage=reacquire_failed', in_array('reacquire_failed', $telemetry_stages, true));
    delete_option($vkF);

    // ── CASE 10 — ACTIVE (non-expired) lock → contention, not recovery ───────
    $ehG = mx_email_hash($idG_email); $keyG = 'email:' . $ehG; $vkG = mx_seed_future_lock($keyG);
    $telemetry_codes = array(); $telemetry_stages = array();
    $mG = mx_acquire($ehG, '');
    mx_check('10 active: acquire returned false (fail-closed)', $mG === false);
    mx_check('10 active: CONTENDED telemetry (not recovery)', in_array('LEADS_IDENTITY_MUTEX_CONTENDED', $telemetry_codes, true) && !in_array('LEADS_IDENTITY_MUTEX_RECOVERY_FAILED', $telemetry_codes, true));
    mx_check('10 active: stage=active_lock', in_array('active_lock', $telemetry_stages, true));
    mx_check('10 active: live lock untouched', get_option($vkG, '__gone__') !== '__gone__');
    delete_option($vkG);

    // ── CASE 11 — COMMIT → release → listeners, and reentrancy ───────────────
    // The estandar_lead_ingested listener must observe the identity lock ALREADY
    // released, and must be able to re-acquire the SAME identity (reentrancy).
    $vkH_email = mx_lock_names($idH_email, '')[0];
    $observed = array('lock_present' => null, 'reenter' => null);
    $listener11 = function () use (&$observed, $idH_email, $idH_phone, $vkH_email) {
        $observed['lock_present'] = get_option($vkH_email, '__gone__') !== '__gone__';
        $eh = mx_email_hash($idH_email);
        $ph = Estandar_Leads_Normalizer::hash(Estandar_Leads_Normalizer::phone_storage($idH_phone));
        $m = mx_acquire($eh, $ph);
        $observed['reenter'] = is_array($m) && !empty($m['locks']);
        if (is_array($m)) { mx_release($m); }
    };
    add_action('estandar_lead_ingested', $listener11, 10, 4);
    $pH = mx_payload($idH_email, $idH_phone, $entry_seq);
    $rH = Estandar_Leads_Ingestion::capture($pH);
    remove_action('estandar_lead_ingested', $listener11, 10);
    mx_check('11 order: lead persisted', $rH > 0);
    mx_check('11 order: listener observed lock ALREADY released (COMMIT→release→listener)', $observed['lock_present'] === false);
    mx_check('11 reentrancy: same identity re-acquired inside listener', $observed['reenter'] === true);
    mx_check('11 order: lock gone after capture', get_option($vkH_email, '__gone__') === '__gone__');

    // ── CASE 12 — single logical release (finally makes no duplicate delete) ──
    // Email-only identity → exactly one lock key → exactly one lock delete.
    $vkJ = mx_lock_names($idJ_email, '')[0];
    $counter = new Mutex_QA_Delete_Counter($real_wpdb);
    $GLOBALS['wpdb'] = $wpdb = $counter;
    $pJ = mx_payload($idJ_email, '', $entry_seq);
    $rJ = Estandar_Leads_Ingestion::capture($pJ);
    $GLOBALS['wpdb'] = $wpdb = $real_wpdb;
    mx_check('12 single-release: lead persisted', $rJ > 0);
    mx_check('12 single-release: lock deleted exactly once (no second release in finally)', $counter->lock_deletes === 1);
    mx_check('12 single-release: lock gone after capture', get_option($vkJ, '__gone__') === '__gone__');

    // ── CASE 13 — Throwable POST-COMMIT (listener): data preserved, lock freed ─
    $telemetry_codes = array();
    $listener13 = function () { throw new RuntimeException('LISTENER_POST_COMMIT_THROW'); };
    add_action('estandar_lead_ingested', $listener13, 10, 4);
    $threw13 = false; $msg13 = '';
    $pK = mx_payload($idK_email, $idK_phone, $entry_seq);
    try {
        Estandar_Leads_Ingestion::capture($pK);
    } catch (Throwable $e) {
        $threw13 = true; $msg13 = $e->getMessage();
    }
    remove_action('estandar_lead_ingested', $listener13, 10);
    $leadK = mx_lead_id_for($idK_email);
    mx_check('13 post-commit throwable: re-propagated to caller', $threw13 && strpos($msg13, 'LISTENER_POST_COMMIT_THROW') !== false);
    mx_check('13 post-commit throwable: data PRESERVED (lead committed)', $leadK > 0);
    mx_check('13 post-commit throwable: identity lock already released before listener', mx_locks_free(mx_lock_names($idK_email, $idK_phone)));
    mx_check('13 post-commit throwable: LEADS_INGESTION_EXCEPTION telemetry emitted', in_array('LEADS_INGESTION_EXCEPTION', $telemetry_codes, true));

    // ── Global balance: no identity lock leaked across the whole suite ────────
    mx_check('balance: identity lock count back to baseline', mx_count_identity_locks() === $baseline_locks);

} finally {
    // Teardown — remove any persisted fixtures and stray locks (best effort).
    $GLOBALS['wpdb'] = $wpdb = $real_wpdb;
    $like = $wpdb->esc_like($run_token) . '%';
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}es_leads WHERE email LIKE %s", $like
    ));
    if (!empty($ids)) {
        $in = implode(',', array_map('intval', $ids));
        $wpdb->query("DELETE FROM {$wpdb->prefix}es_lead_events WHERE lead_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id IN ({$in})");
        $wpdb->query("DELETE FROM {$wpdb->prefix}es_leads WHERE id IN ({$in})");
    }
    foreach (array_unique($lock_names_all) as $n) { delete_option($n); }
}

echo "\n" . ($failed === 0 ? "ALL PASS ({$passed})\n" : "{$failed} FAILED / {$passed} passed\n");
exit($failed === 0 ? 0 : 1);
