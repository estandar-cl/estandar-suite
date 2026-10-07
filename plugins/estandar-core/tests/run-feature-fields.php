<?php
/**
 * ESTANDAR SUITE v2 — Core sanitize_feature_fields tests (new field types).
 *
 *   php tests/run-feature-fields.php   → expect "0 failed"
 *
 * Covers textarea/number/color rendering-safe sanitization introduced for
 * the shared settings renderer (system_prompt, context_txt, temperature…).
 */

define('ABSPATH', __DIR__);
$GLOBALS['__test_options'] = array();
if (!function_exists('get_option')) { function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) $t); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return trim((string) $t); } }
if (!function_exists('sanitize_hex_color')) { function sanitize_hex_color($c) { return preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', (string) $c) ? (string) $c : ''; } }

require dirname(__DIR__) . '/includes/Admin.php';

use Estandar\Core\Admin;

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

// ── 1. textarea preserva saltos de línea ─────────────────────────────────
$fields = array( 'system_prompt' => array( 'type' => 'textarea', 'rows' => 6, 'default' => '' ) );
$clean = Admin::sanitize_feature_fields( $fields, array( 'system_prompt' => "Hola\nmundo\nlínea 3" ) );
check('1a textarea conserva saltos', ($clean['system_prompt'] ?? '') === "Hola\nmundo\nlínea 3");

// ── 2. number entero clamp min/max ───────────────────────────────────────
$fields = array( 'tokens' => array( 'type' => 'number', 'min' => 100, 'max' => 8000, 'default' => 500 ) );
check('2a number dentro de rango', Admin::sanitize_feature_fields( $fields, array( 'tokens' => 1200 ) )['tokens'] === 1200);
check('2b number clamp bajo', Admin::sanitize_feature_fields( $fields, array( 'tokens' => 5 ) )['tokens'] === 100);
check('2c number clamp alto', Admin::sanitize_feature_fields( $fields, array( 'tokens' => 99999 ) )['tokens'] === 8000);
check('2d number default si vacío', Admin::sanitize_feature_fields( $fields, array() )['tokens'] === 500);

// ── 3. number decimal (temperature) ──────────────────────────────────────
$fields = array( 'temp' => array( 'type' => 'number', 'min' => 0, 'max' => 2, 'step' => '0.1', 'default' => 0.4 ) );
$v = Admin::sanitize_feature_fields( $fields, array( 'temp' => 1.2 ) )['temp'];
check('3a float preservado', is_float($v) && $v === 1.2);
$v2 = Admin::sanitize_feature_fields( $fields, array( 'temp' => 3 ) )['temp'];
check('3b float clamp alto', is_float($v2) && $v2 === 2.0);
$v3 = Admin::sanitize_feature_fields( $fields, array() )['temp'];
check('3c float default', is_float($v3) && $v3 === 0.4);

// ── 4. color valida hex ──────────────────────────────────────────────────
$fields = array( 'accent' => array( 'type' => 'color', 'default' => '#111827' ) );
check('4a color válido', Admin::sanitize_feature_fields( $fields, array( 'accent' => '#2563eb' ) )['accent'] === '#2563eb');
check('4b color inválido cae a default', Admin::sanitize_feature_fields( $fields, array( 'accent' => 'rojo' ) )['accent'] === '#111827');

// ── 5. select sigue validando (regresión) ────────────────────────────────
$fields = array( 'm' => array( 'type' => 'select', 'options' => array( 'a' => 'A', 'b' => 'B' ), 'default' => 'a' ) );
check('5a select válido', Admin::sanitize_feature_fields( $fields, array( 'm' => 'b' ) )['m'] === 'b');
check('5b select inválido cae a default', Admin::sanitize_feature_fields( $fields, array( 'm' => 'zzz' ) )['m'] === 'a');

// ── 6. pages (multi-select de IDs de página) ─────────────────────────────
$fields = array( 'paginas_visibles' => array( 'type' => 'pages', 'default' => array() ) );
$clean = Admin::sanitize_feature_fields( $fields, array( 'paginas_visibles' => array( '12', '7', '12', 'abc', '-3' ) ) );
check('6a pages filtra ids válidos y dedupe', ($clean['paginas_visibles'] ?? array()) === array( 12, 7 ));
check('6b pages vacío → array vacío', Admin::sanitize_feature_fields( $fields, array() )['paginas_visibles'] === array());
check('6c pages sin campo → array vacío', Admin::sanitize_feature_fields( $fields, array( 'otro' => 'x' ) )['paginas_visibles'] === array());

// ── 7. save_feature_group: anidado + idempotencia + migración legacy ─────
$opt = 'test_group_option';
unset($GLOBALS['__test_options'][$opt]);
$groups = array(
    'meta'   => array(
        'enabled'      => array('type' => 'bool', 'default' => 0),
        'pixel_id'     => array('type' => 'text', 'default' => ''),
        'access_token' => array('type' => 'text', 'default' => ''),
    ),
    'google' => array(
        'enabled'     => array('type' => 'bool', 'default' => 0),
        'customer_id' => array('type' => 'text', 'default' => ''),
    ),
);
Admin::save_feature_group($opt, $groups['meta'], 'meta', array('enabled' => '1', 'pixel_id' => '111', 'access_token' => 'aaa'));
$stored = $GLOBALS['__test_options'][$opt] ?? array();
check('7a meta escribe anidado', ($stored['meta']['pixel_id'] ?? '') === '111');
check('7b meta enabled anidado = 1', ($stored['meta']['enabled'] ?? 0) === 1);
check('7c raíz sin claves planas', !array_key_exists('pixel_id', $stored));

Admin::save_feature_group($opt, $groups['google'], 'google', array('enabled' => '1', 'customer_id' => 'C1'));
$stored = $GLOBALS['__test_options'][$opt] ?? array();
check('7d google enabled no pisa meta.enabled', ($stored['google']['enabled'] ?? 0) === 1 && ($stored['meta']['enabled'] ?? 0) === 1);
check('7e google anidado', ($stored['google']['customer_id'] ?? '') === 'C1');

Admin::save_feature_group($opt, $groups['meta'], 'meta', array('enabled' => '1', 'pixel_id' => '111', 'access_token' => 'aaa'));
$stored2 = $GLOBALS['__test_options'][$opt] ?? array();
check('7f idempotente (segundo save = mismo estado)', $stored2 === $stored);

$GLOBALS['__test_options'][$opt] = array('pixel_id' => '999', 'access_token' => 'old', 'meta' => array('enabled' => 1));
Admin::save_feature_group($opt, $groups['meta'], 'meta', array('enabled' => '1', 'pixel_id' => '222'));
$stored3 = $GLOBALS['__test_options'][$opt] ?? array();
check('7g migra plano→anidado y limpia raíz', ($stored3['meta']['pixel_id'] ?? '') === '222' && !array_key_exists('pixel_id', $stored3) && !array_key_exists('access_token', $stored3));

echo PHP_EOL . $failed . ' failed · ' . $passed . ' passed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
