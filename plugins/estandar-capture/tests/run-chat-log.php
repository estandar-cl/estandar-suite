<?php
/**
 * ESTANDAR SUITE v2 — Capture ChatLog tests.
 *
 *   php tests/run-chat-log.php   → expect "0 failed"
 *
 * Covers: result classification, log persistence, recent list, friction KPIs.
 */

define('ABSPATH', __DIR__);
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_rows'] = array();
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) $t); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return trim((string) $t); } }
if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $l) { return substr($s, $a, $l); } }
if (!function_exists('gmdate')) { function gmdate($f, $t = null) { return date($f, $t ?: time()); } }
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public $insert_id = 1;
    public function get_charset_collate() { return ''; }
    public function insert($t, $d, $f) {
        $GLOBALS['__test_rows'][$t][] = $d;
        $this->insert_id++;
        return true;
    }
    public function get_results($sql, $type = ARRAY_A) {
        $rows = $GLOBALS['__test_rows']['wp_estandar_capture_chat_logs'] ?? array();
        return array_values($rows);
    }
    public function get_var($sql) {
        $rows = $GLOBALS['__test_rows']['wp_estandar_capture_chat_logs'] ?? array();
        if (strpos($sql, "result = 'empty'") !== false) {
            $n = 0; foreach ($rows as $r) { if (($r['result'] ?? '') === 'empty') $n++; } return $n;
        }
        if (strpos($sql, "result = 'fallback'") !== false) {
            $n = 0; foreach ($rows as $r) { if (($r['result'] ?? '') === 'fallback') $n++; } return $n;
        }
        if (strpos($sql, "result = 'error'") !== false) {
            $n = 0; foreach ($rows as $r) { if (($r['result'] ?? '') === 'error') $n++; } return $n;
        }
        if (strpos($sql, 'GROUP BY user_message') !== false) {
            $counts = array();
            foreach ($rows as $r) { $m = $r['user_message'] ?? ''; $counts[$m] = ($counts[$m] ?? 0) + 1; }
            $out = array();
            foreach ($counts as $m => $n) { if ($n > 1) $out[] = array('user_message' => $m, 'n' => $n); }
            usort($out, function ($a, $b) { return $b['n'] <=> $a['n']; });
            return $out;
        }
        return count($rows);
    }
    public function prepare($sql, ...$args) {
        $s = $sql;
        foreach ($args as $a) { $s = preg_replace('/%[sd]/', "'" . addslashes((string) $a) . "'", $s, 1); }
        return $s;
    }
};

require dirname(__DIR__) . '/includes/ChatLog.php';

use Estandar\Capture\ChatLog;

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

// ── 1. Clasificación de resultados ───────────────────────────────────────
check('1a ok normal', ChatLog::classify('Claro, te ayudo con eso ahora.') === ChatLog::RESULT_OK);
check('1b empty vacío', ChatLog::classify('') === ChatLog::RESULT_EMPTY);
check('1c empty corto', ChatLog::classify('ok') === ChatLog::RESULT_EMPTY);
check('1d fallback genérico', ChatLog::classify('Lo siento, no pude procesar tu mensaje.') === ChatLog::RESULT_FALLBACK);

// ── 2. Log persistencia ──────────────────────────────────────────────────
$id = ChatLog::log('web_1', 'web', '¿Cuánto cuesta?', 'Claro, te ayudo con eso.');
check('2a log devuelve id', $id > 0);
ChatLog::log('web_1', 'web', 'hola', 'ok');
$recent = ChatLog::recent(50);
check('2b recent devuelve filas', count($recent) >= 2);

// ── 3. Friction KPIs ─────────────────────────────────────────────────────
$f = ChatLog::friction(7);
check('3a total', ($f['total'] ?? 0) >= 2);
check('3b empty', ($f['empty'] ?? 0) >= 1);
check('3c fallbacks', ($f['fallbacks'] ?? 0) >= 0);

// ── 4. Preguntas repetidas ───────────────────────────────────────────────
ChatLog::log('web_2', 'web', 'precio', 'El precio es...');
ChatLog::log('web_3', 'web', 'precio', 'El precio es...');
$f2 = ChatLog::friction(7);
check('4a repeated detecta duplicado', !empty($f2['repeated']));
$found = false;
foreach ($f2['repeated'] as $r) { if (($r['user_message'] ?? '') === 'precio') $found = true; }
check('4b repeated incluye "precio"', $found);

echo PHP_EOL . $failed . ' failed · ' . $passed . ' passed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
