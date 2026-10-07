<?php
/**
 * ESTANDAR SUITE v2 — Capture ChatbotHealth tests.
 *
 *   php tests/run-chatbot-health.php   → expect "0 failed"
 *
 * Covers: health snapshot (config/ai/persist/identity levels) and appearance.
 */

define('ABSPATH', __DIR__);
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
$GLOBALS['__test_options'] = array();
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) $t); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return trim((string) $t); } }
if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public $last_error = '';
    public function get_charset_collate() { return ''; }
    public function get_results($sql) { return array(); }
};

require dirname(__DIR__) . '/../estandar-core/includes/AiKeys.php';
require dirname(__DIR__) . '/includes/ChatConversation.php';
require dirname(__DIR__) . '/includes/AiChat.php';
require dirname(__DIR__) . '/includes/AiChatPrompt.php';
require dirname(__DIR__) . '/includes/ChatbotHealth.php';

use Estandar\Capture\ChatbotHealth;

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

// ── 1. Snapshot con clave y config activa ────────────────────────────────
$GLOBALS['__test_options']['estandar_suite_core_ai_settings'] = array(
    'deepseek' => 'sk-deep', 'openai' => '', 'active_provider' => 'deepseek', 'default_model' => 'deepseek-chat',
);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings'] = array(
    'activar' => 1, 'nombre' => 'Asistente Test',
    'theme_accent' => '#2563eb', 'theme_position' => 'left', 'bubble_size' => 60,
);
$h = ChatbotHealth::snapshot();
$checks = $h['checks'] ?? array();
check('1a check config presente', isset($checks['config']));
check('1b check ai presente', isset($checks['ai']));
check('1c check persist presente', isset($checks['persist']));
check('1d check identity presente', isset($checks['identity']));
check('1e config level ok', ($checks['config']['level'] ?? '') === 'ok');
check('1f ai level ok (con clave)', ($checks['ai']['level'] ?? '') === 'ok');

// ── 2. Sin clave → bad ───────────────────────────────────────────────────
$GLOBALS['__test_options']['estandar_suite_core_ai_settings'] = array(
    'deepseek' => '', 'openai' => '', 'active_provider' => 'deepseek', 'default_model' => 'deepseek-chat', 'adopted' => 1,
);
$GLOBALS['__test_options']['estandar_suite_core_ai_adopted_from'] = array();
$h2 = ChatbotHealth::snapshot();
check('2a ai level bad sin clave', ($h2['checks']['ai']['level'] ?? '') === 'bad');

// ── 3. Desactivado → warn ────────────────────────────────────────────────
$GLOBALS['__test_options']['estandar_suite_core_ai_settings']['deepseek'] = 'sk-deep';
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['activar'] = 0;
$h3 = ChatbotHealth::snapshot();
check('3a config level warn si desactivado', ($h3['checks']['config']['level'] ?? '') === 'warn');

// ── 4. Appearance ────────────────────────────────────────────────────────
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['activar'] = 1;
$ap = ChatbotHealth::appearance();
check('4a accent', ($ap['accent'] ?? '') === '#2563eb');
check('4b bot_name', ($ap['bot_name'] ?? '') === 'Asistente Test');
check('4c position', ($ap['position'] ?? '') === 'left');
check('4d size clamp', ($ap['size'] ?? 0) === 60);

echo PHP_EOL . $failed . ' failed · ' . $passed . ' passed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
