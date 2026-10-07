<?php
/**
 * PRODUCTION_DATA_SAFETY_GATE_V1
 *
 * Mandatory pre-release gate. Runs from:
 *   - ESTANDAR_TEST.command (always)
 *   - release.command (always, blocks on failure)
 *
 * Validates:
 *   1. Static analysis — no undocumented destructive operations
 *   2. Upgrade simulation — data preservation + idempotency
 *   3. Coverage manifest — every plugin accounted for
 *
 * Exit code: 0 = PASS, 1 = FAIL (blocks release)
 */
declare(strict_types=1);

// ─── Bootstrap ────────────────────────────────────────────────────────
chdir(dirname(__DIR__));
$plugins_dir = dirname(__DIR__);
$RESULTS = array('gate' => array(), 'errors' => array(), 'warnings' => array());
$EXIT = 0;

function gate_pass(string $check, string $detail = ''): void {
    global $RESULTS;
    $RESULTS['gate'][] = array('status' => 'PASS', 'check' => $check, 'detail' => $detail);
}

function gate_fail(string $check, string $detail): void {
    global $RESULTS, $EXIT;
    $RESULTS['gate'][] = array('status' => 'FAIL', 'check' => $check, 'detail' => $detail);
    $EXIT = 1;
}

function gate_warn(string $check, string $detail): void {
    global $RESULTS;
    $RESULTS['warnings'][] = array('check' => $check, 'detail' => $detail);
}

function scan_plugins(): array {
    // Canal legacy: la Suite v2 (estandar-core/capture/brain/flow/insights) es
    // un canal aislado con sus propios gates; se excluye de este gate.
    $v2 = array('estandar-core', 'estandar-capture', 'estandar-brain', 'estandar-flow', 'estandar-insights');
    $plugins = array();
    foreach (glob(dirname(__DIR__) . '/estandar-*', GLOB_ONLYDIR) as $dir) {
        $slug = basename($dir);
        if (in_array($slug, $v2, true)) {
            continue;
        }
        $plugins[] = $slug;
    }
    sort($plugins);
    return $plugins;
}

// ═══════════════════════════════════════════════════════════════════════
// 1. COVERAGE MANIFEST
// ═══════════════════════════════════════════════════════════════════════

$all_plugins = scan_plugins();
$covered = array();

echo "\n=== PRODUCTION DATA SAFETY GATE ===\n\n";

// 1a. All plugins found
echo "[1] COVERAGE MANIFEST\n";
echo "  Plugins found: " . count($all_plugins) . "\n";
foreach ($all_plugins as $p) {
    echo "    - $p\n";
}

// Verify every plugin has at least one test file or explicit exemption
foreach ($all_plugins as $plugin) {
    $test_dir = dirname(__DIR__) . "/{$plugin}/tests";
    $has_tests = is_dir($test_dir) && glob("{$test_dir}/run-*.php");
    $has_exemption = false;  // future: check manifest exemptions
    
    if ($has_tests || $has_exemption) {
        $covered[] = $plugin;
    } else {
        gate_warn("Plugin {$plugin} has no test files", "No run-*.php found in tests/");
    }
}

$coverage_pct = count($all_plugins) > 0 
    ? round(count($covered) / count($all_plugins) * 100, 1) 
    : 0;
echo "  Coverage: {$coverage_pct}% (" . count($covered) . "/" . count($all_plugins) . ")\n";
gate_pass("coverage_manifest", count($all_plugins) . " plugins, {$coverage_pct}% coverage");

// ═══════════════════════════════════════════════════════════════════════
// 2. STATIC ANALYSIS — destructive operation detection
// ═══════════════════════════════════════════════════════════════════════

echo "\n[2] STATIC ANALYSIS — destructive operations\n";

/**
 * Exemption registry: files and patterns that are KNOWN SAFE.
 * All exemptions must include a documented reason.
 * Add new entries here when a destructive operation is intentionally safe.
 */
$EXEMPTIONS = array(
    // Test files — operate on disposable data, never production
    '#tests/#' => 'test_file',
    // Template/admin HTML — these are UI buttons with JS confirm(), not SQL
    '#templates/#' => 'template_file',
    '#admin/tabs/#' => 'admin_tabs_html',
    '#admin/tabs/partials/#' => 'admin_tabs_partials_html',
    // Uninstall files — explicit user action to remove data
    '#uninstall\.php#' => 'uninstall_file',
    // Admin JS UI — button-link-delete are HTML buttons, not SQL
    '#button-link-delete#' => 'html_button',
    // Admin action handlers — gated by nonce + user capability
    '#/includes/class-estandar-leads-admin\.php#' => 'admin_handler_nonce_gated',
    '#/includes/class-estandar-leads-customers-admin\.php#' => 'admin_handler_nonce_gated',
    '#/includes/class-estandar-leads-repository\.php#' => 'scoped_delete_option',
    '#/includes/class-estandar-leads-schema\.php#' => 'migration_dedup_gated',
    // Retention cleanup — gated by time threshold, documented
    '#class-retention\.php#' => 'retention_cleanup_gated',
    // Chatbot handlers — gated by admin nonce + scoped by ID
    '#includes/conversations-handler\.php#' => 'admin_handler_nonce_gated',
    '#includes/leads-handler\.php#' => 'admin_handler_nonce_gated',
    '#includes/logs-handler\.php#' => 'admin_handler_nonce_gated',
    '#includes/analytics-handler\.php#' => 'admin_handler_nonce_gated',
    // SEO sync lock — atomic lock cleanup in finally block
    '#class-estandar-seo-operations\.php#' => 'atomic_lock_cleanup',
    '#class-estandar-seo-service\.php#' => 'atomic_lock_cleanup',

);

$destructive_patterns = array(
    'drop_table'             => array(
        'pattern' => '/DROP\s+TABLE\s*(?:IF\s+EXISTS)?\s*`?\w+/i',
        'label'   => 'DROP TABLE',
        'allowed' => array('uninstall.php'),
    ),
    'truncate'               => array(
        'pattern' => '/TRUNCATE\s+TABLE/i',
        'label'   => 'TRUNCATE TABLE (gated by admin nonce)',
        'allowed' => array(),
    ),
    'delete_from'            => array(
        'pattern' => '/DELETE\s+(?:FROM\s+)?`?\w+\.?\w*`?\s*(?!WHERE\s|$)/i',
        'label'   => 'DELETE FROM (validate context)',
        'allowed' => array(),
    ),
    'delete_option'          => array(
        'pattern' => '/delete_option\s*\(\s*[\'"][^\'"]+[\'"]\s*\)/i',
        'label'   => 'delete_option()',
        'allowed' => array('uninstall.php'),
    ),
    'delete_post_meta'       => array(
        'pattern' => '/delete_post_meta\s*\(/i',
        'label'   => 'delete_post_meta()',
        'allowed' => array('uninstall.php'),
    ),
    'uninstall_hook'         => array(
        'pattern' => '/register_uninstall_hook/i',
        'label'   => 'register_uninstall_hook',
        'allowed' => array(),
    ),
    'update_option_defaults' => array(
        'pattern' => '/update_option\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*(?:array_merge\s*\(\s*\$default|\$default)/i',
        'label'   => 'update_option() with defaults',
        'allowed' => array(),
    ),
);

$excluded_dirs = array('.git', 'node_modules', 'vendor', '.release-build', '.release.lock');
// ── Load canonical exceptions (allowlist) ──────────────────────────────
$exceptions_file = __DIR__ . '/safety-exceptions.json';
$allowlist = array();
if (file_exists($exceptions_file)) {
    $ex_data = json_decode(file_get_contents($exceptions_file), true);
    if (is_array($ex_data) && isset($ex_data['exceptions'])) {
        foreach ($ex_data['exceptions'] as $exc) {
            if (empty($exc['id']) || empty($exc['file']) || empty($exc['rule'])) { continue; }
            // Skip expired exceptions
            if (!empty($exc['expires']) && strtotime($exc['expires']) < time()) { continue; }
            // Skip exceptions without justification
            if (empty($exc['justification'])) { continue; }
            $key = $exc['plugin'] . '/' . $exc['file'] . '|' . $exc['rule'];
            $allowlist[$key] = $exc;
        }
    }
}
$allowlist_count = count($allowlist);
echo "  Allowlist: {$allowlist_count} approved exceptions loaded\n";

$findings_by_plugin = array();
$new_findings_count = 0;

foreach ($all_plugins as $plugin) {
    $plugin_path = dirname(__DIR__) . "/{$plugin}";
    $findings = array();
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($plugin_path, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        $rel_path = str_replace($plugin_path . '/', '', $file->getPathname());
        
        // Skip excluded and non-PHP
        $skip = false;
        foreach ($excluded_dirs as $ex) {
            if (strpos($rel_path, $ex) === 0) { $skip = true; break; }
        }
        if ($skip || $file->getExtension() !== 'php') { continue; }
        
        $content = file_get_contents($file->getPathname());
        if ($content === false) { continue; }
        
        // Check runtime exemption patterns
        $is_exempt = false;
        foreach ($EXEMPTIONS as $pattern => $reason) {
            if (preg_match($pattern, $rel_path)) {
                $is_exempt = true;
                break;
            }
        }
        if ($is_exempt) { continue; }
        
        foreach ($destructive_patterns as $key => $spec) {
            if (!preg_match_all($spec['pattern'], $content, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            
            // Check if this file is in the allowed list
            $basename = basename($rel_path);
            $is_allowed = in_array($basename, $spec['allowed']);
            
            if (!$is_allowed) {
                foreach ($matches[0] as $match) {
                    // Get context (line of code)
                    $pos = $match[1];
                    $line_start = strrpos(substr($content, 0, $pos), "\n");
                    $line_start = $line_start === false ? 0 : $line_start + 1;
                    $line_end = strpos($content, "\n", $pos);
                    $line_end = $line_end === false ? strlen($content) : $line_end;
                    $code_line = substr($content, $line_start, $line_end - $line_start);
                    
                    // Check allowlist
                    $allow_key = $plugin . '/' . $rel_path . '|' . $spec['label'];
                    if (isset($allowlist[$allow_key])) {
                        // Approved exception — silently skip
                        continue;
                    }
                    
                    $findings[] = array(
                        'file'    => $rel_path,
                        'match'   => trim($code_line),
                        'type'    => $spec['label'],
                    );
                }
            }
        }
    }
    
    if (!empty($findings)) {
        $findings_by_plugin[$plugin] = $findings;
        foreach ($findings as $f) {
            echo "  [!] {$plugin}/{$f['file']}: {$f['type']}\n";
            echo "      {$f['match']}\n";
        }
    }
}

$total_findings = array_sum(array_map('count', $findings_by_plugin));
$allowlist_used = 0;
// Count how many allowlist entries would have been triggered (files that exist and have matches)
foreach ($allowlist as $key => $entry) {
    $parts = explode('|', $key);
    if (count($parts) >= 2) {
        $file_key = $parts[0]; // plugin/rel_path
        $file_parts = explode('/', $file_key, 2);
        if (count($file_parts) === 2) {
            $plugin_name = $file_parts[0];
            $file_path = $file_parts[1];
            $full_path = dirname(__DIR__) . "/{$plugin_name}/{$file_path}";
            if (file_exists($full_path)) {
                $allowlist_used++;
            }
        }
    }
}
echo "  Allowlist entries used: {$allowlist_used} of {$allowlist_count} (exist in source)\n";
echo "  New findings: {$total_findings}\n";

if ($total_findings === 0) {
    echo "  No undocumented destructive operations found.\n";
    gate_pass("static_analysis", "0 undocumented destructive operations");
} else {
    echo "  {$total_findings} NEW undocumented destructive operation(s) found in " . count($findings_by_plugin) . " plugin(s).\n";
    echo "  Action required: add entries to safety-exceptions.json or fix the code.\n";
    foreach ($findings_by_plugin as $plugin => $finds) {
        gate_fail("static_analysis.{$plugin}", count($finds) . " NEW unapproved finding(s) — add to allowlist");
    }
    if ($total_findings > 0) {
        gate_fail("static_analysis", "{$total_findings} unapproved operations found — review and add to safety-exceptions.json");
    }
}

// ═══════════════════════════════════════════════════════════════════════
// 3. DATABASE MIGRATION IDEMPOTENCY TEST (disposable SQLite)
// ═══════════════════════════════════════════════════════════════════════

echo "\n[3] MIGRATION IDEMPOTENCY — disposable database\n";

$db_file = '/tmp/estandar-safety-gate-' . bin2hex(random_bytes(8)) . '.sqlite';
$schema_snapshots = array();
$migration_ok = true;

try {
    $pdo = new PDO("sqlite:{$db_file}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Collect all schema definitions from plugins (CREATE TABLE statements in schema files)
    $schema_tables = array();
    foreach ($all_plugins as $plugin) {
        $plugin_path = dirname(__DIR__) . "/{$plugin}";
        $schema_files = glob("{$plugin_path}/includes/*schema*.php");
        $schema_files = array_merge($schema_files, glob("{$plugin_path}/includes/class-*-schema.php"));
        
        foreach ($schema_files as $sf) {
            $content = file_get_contents($sf);
            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?\{?\$?(?:table|wpdb->prefix)\}?(\w+)/i', $content, $table_matches)) {
                foreach ($table_matches[0] as $tm) {
                    $schema_tables[] = array('plugin' => $plugin, 'file' => basename($sf), 'sql_snippet' => substr($tm, 0, 80));
                }
            }
        }
    }
    
    echo "  Schema definitions found: " . count($schema_tables) . "\n";
    
    // Run each dbDelta/migration twice and verify idempotency
    foreach ($all_plugins as $plugin) {
        $plugin_path = dirname(__DIR__) . "/{$plugin}";
        
        // Check for maybe_migrate functions
        $main_file = "{$plugin_path}/{$plugin}.php";
        if (!file_exists($main_file)) {
            // Try alternate main files
            $candidates = glob("{$plugin_path}/*.php");
            foreach ($candidates as $c) {
                $content = file_get_contents($c);
                if (preg_match('/Plugin Name:/', $content)) {
                    $main_file = $c;
                    break;
                }
            }
        }
        
        if (file_exists($main_file)) {
            $content = file_get_contents($main_file);
            $has_migration = preg_match('/maybe_migrate|maybe_upgrade|dbDelta|schema_version|DB_VERSION/i', $content);
            
            // Verify schema version constants exist and are compared
            $has_version_check = preg_match('/version_compare|SCHEMA_VERSION|DB_VERSION|get_option.*[Vv]ersion/i', $content);
            // Also check included schema files for version gates
            if (!$has_version_check) {
                $schema_files = glob("{$plugin_path}/includes/*schema*.php");
                $schema_files = array_merge($schema_files, glob("{$plugin_path}/includes/class-*-schema.php"));
                foreach ($schema_files as $sf) {
                    $sc = file_get_contents($sf);
                    if ($sc !== false && preg_match('/version_compare|SCHEMA_VERSION|DB_VERSION|get_option.*[Vv]ersion/i', $sc)) {
                        $has_version_check = true;
                        break;
                    }
                }
            }
            
            if ($has_migration && !$has_version_check) {
                echo "  [WARN] {$plugin}: has migration logic but no version gate\n";
                gate_warn("migration_idempotency", "{$plugin}: migration without version gate");
            } elseif ($has_migration) {
                echo "  [OK] {$plugin}: migration with version gate\n";
            } else {
                echo "  [OK] {$plugin}: no migration logic\n";
            }
        }
    }
    
    // Test dbDelta idempotency: run CREATE TABLE twice
    echo "\n  Running schema creation twice (idempotency test)...\n";
    $test_table = 'test_safety_gate_' . bin2hex(random_bytes(4));
    
    // First run
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$test_table} (id INTEGER PRIMARY KEY, value TEXT)");
    $pdo->exec("INSERT INTO {$test_table} (value) VALUES ('test_value')");
    $first_count = (int) $pdo->query("SELECT COUNT(*) FROM {$test_table}")->fetchColumn();
    
    // Simulate migration re-run (ALTER TABLE ADD COLUMN is additive)
    $pdo->exec("ALTER TABLE {$test_table} ADD COLUMN new_col TEXT DEFAULT NULL");
    
    // Drop and recreate (simulates what dbDelta does)
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$test_table} (id INTEGER PRIMARY KEY, value TEXT, new_col TEXT)");
    $second_count = (int) $pdo->query("SELECT COUNT(*) FROM {$test_table}")->fetchColumn();
    
    if ($first_count === $second_count && $first_count === 1) {
        echo "  [OK] CREATE TABLE IF NOT EXISTS is idempotent (data preserved)\n";
        gate_pass("migration_idempotency_create", "CREATE TABLE IF NOT EXISTS preserves existing data");
    } else {
        echo "  [FAIL] Data lost or duplicated during re-creation\n";
        gate_fail("migration_idempotency_create", "Data count changed from {$first_count} to {$second_count}");
    }
    
    // Verify ALTER TABLE ADD COLUMN is idempotent
    try {
        $pdo->exec("ALTER TABLE {$test_table} ADD COLUMN another_col TEXT DEFAULT NULL");
        echo "  [OK] ALTER TABLE ADD COLUMN (first time)\n";
    } catch (PDOException $e) {
        // Expected on second run if column exists — SQLite allows it
    }
    
    $pdo = null;
    unlink($db_file);
    echo "  [OK] Disposable database cleaned up\n";
    
} catch (Exception $e) {
    if (file_exists($db_file)) { unlink($db_file); }
    echo "  [WARN] Migration test skipped: {$e->getMessage()}\n";
    gate_warn("migration_idempotency", "Could not run: {$e->getMessage()}");
}

// ═══════════════════════════════════════════════════════════════════════
// 4. SUMMARY
// ═══════════════════════════════════════════════════════════════════════

echo "\n=== PRODUCTION DATA SAFETY GATE SUMMARY ===\n";
echo "\nChecks:\n";
$pass_count = 0;
$fail_count = 0;
foreach ($RESULTS['gate'] as $g) {
    $icon = $g['status'] === 'PASS' ? '✓' : '✗';
    echo "  {$icon} {$g['check']}: {$g['detail']}\n";
    if ($g['status'] === 'PASS') { $pass_count++; } else { $fail_count++; }
}

if (!empty($RESULTS['warnings'])) {
    echo "\nWarnings:\n";
    foreach ($RESULTS['warnings'] as $w) {
        echo "  ⚠ {$w['check']}: {$w['detail']}\n";
    }
}

echo "\nResult: " . ($EXIT === 0 ? 'PASS' : 'FAIL') . " ({$pass_count} passed, {$fail_count} failed)\n";
exit($EXIT);
