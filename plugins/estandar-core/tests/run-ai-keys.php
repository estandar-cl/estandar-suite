<?php
/**
 * ESTANDAR SUITE v2 — Core AiKeys tests (pure logic + guarded WP paths).
 *
 *   php tests/run-ai-keys.php   → expect "0 failed"
 *
 * Verifies the v2 shared API-key vault: one-time read-only adoption from
 * legacy options, getter with legacy fallback, and no writes to legacy.
 */

define('ABSPATH', __DIR__);
$GLOBALS['__test_options'] = array();
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) $t); } }
if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }

require dirname(__DIR__) . '/includes/AiKeys.php';

use Estandar\Core\AiKeys;

$fail = 0;
function check($label, $cond) {
    global $fail;
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$cond) { $fail++; }
}

// ── 1. Providers + defaults ──────────────────────────────────────────────
$p = AiKeys::providers();
check('1a proveedores deepseek+openai', isset($p['deepseek']) && isset($p['openai']));
check('1b proveedor activo default deepseek', AiKeys::active_provider() === 'deepseek');
check('1c modelo default deepseek-chat', AiKeys::default_model() === 'deepseek-chat');

// ── 2. Getter sin nada guardado → vacío ───────────────────────────────────
check('2a get openai vacío', AiKeys::get('openai') === '');
check('2b get deepseek vacío', AiKeys::get('deepseek') === '');

// ── 3. Save v2 → getter devuelve el valor ────────────────────────────────
AiKeys::update_settings(array('deepseek' => 'sk-deep-1', 'active_provider' => 'deepseek', 'default_model' => 'deepseek-chat', 'adopted' => 1));
check('3a get deepseek tras save', AiKeys::get('deepseek') === 'sk-deep-1');
check('3b proveedor activo guardado', AiKeys::active_provider() === 'deepseek');

// ── 4. Fallback read-only a legacy (sin valor v2) ────────────────────────
$GLOBALS['__test_options'] = array('estandar_shared_openai_key' => 'sk-legacy-openai');
$GLOBALS['__test_options'][AiKeys::OPTION] = array();
check('4a fallback lee legacy openai', AiKeys::get('openai') === 'sk-legacy-openai');
check('4b legacy NO se sobrescribe', $GLOBALS['__test_options']['estandar_shared_openai_key'] === 'sk-legacy-openai');

// ── 5. Adopción one-time: seeds v2 desde legacy, marca flag persistente ──
$GLOBALS['__test_options'] = array(
    'estandar_shared_openai_key' => 'sk-legacy-openai',
    'chatbot_openai_api_key'     => 'sk-chatbot',
    'estandar_openai_api_key'    => 'sk-scanner',
);
$settings = AiKeys::adopt_legacy_once();
check('5a adopción toma openai de primer', ($settings['openai'] ?? '') === 'sk-legacy-openai');
check('5b adopción marca adopted=1', !empty($settings['adopted']));
$flag = get_option(AiKeys::ADOPTED_FLAG, array());
check('5c flag persistente registra fuente', is_array($flag) && ($flag['openai'] ?? '') === 'estandar_shared_openai_key');
check('5d is_adopted true tras adopción', AiKeys::is_adopted() === true);

// ── 6. Tras adopción, borrar clave NO la resucita desde legacy ────────────
// El usuario borra openai en el vault; legacy aún la tiene.
$GLOBALS['__test_options'] = array(
    'estandar_shared_openai_key' => 'sk-legacy-openai',
    AiKeys::OPTION => array('openai' => '', 'active_provider' => 'deepseek', 'default_model' => '', 'adopted' => 1),
    AiKeys::ADOPTED_FLAG => array('openai' => 'estandar_shared_openai_key'),
);
check('6a get openai vacío tras borrado (no resucita)', AiKeys::get('openai') === '');

// ── 6b. Adopción idempotente: flag presente no re-siembra ni pisa v2 ──────
$GLOBALS['__test_options'] = array(
    'estandar_shared_openai_key' => 'sk-legacy-openai',
    AiKeys::OPTION => array('openai' => 'sk-v2-manual', 'adopted' => 1),
    AiKeys::ADOPTED_FLAG => array('openai' => 'estandar_shared_openai_key'),
);
$settings2 = AiKeys::adopt_legacy_once();
check('6b adopción no pisa valor v2', ($settings2['openai'] ?? '') === 'sk-v2-manual');
check('6c flag sigue presente tras segundo guardado', AiKeys::is_adopted() === true);

// ── 7. Sanitización de escritura (sin write a legacy) ────────────────────
$GLOBALS['__test_options'][AiKeys::ADOPTED_FLAG] = array();
AiKeys::update_settings(array('deepseek' => "sk-deep\n  X", 'active_provider' => 'gpt', 'default_model' => ''));
check('7a normaliza saltos de línea', AiKeys::get('deepseek') === 'sk-deep  X');
check('7b proveedor inválido cae a deepseek', AiKeys::active_provider() === 'deepseek');
check('7c get openai sin adoptar y sin vault → vacío', AiKeys::get('openai') === '');

// ── 8. Handler respeta borrado: update_settings con '' persiste '' ───────
AiKeys::update_settings(array('openai' => 'sk-temp', 'active_provider' => 'openai', 'default_model' => ''));
check('8a guarda openai temporal', AiKeys::get('openai') === 'sk-temp');
AiKeys::update_settings(array('openai' => '', 'active_provider' => 'deepseek', 'default_model' => ''));
check('8b borrado persiste como vacío', AiKeys::get('openai') === '');

echo PHP_EOL . $fail . ' failed' . PHP_EOL;
exit($fail > 0 ? 1 : 0);
