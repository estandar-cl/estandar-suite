<?php
/**
 * LEAD_ASSESSMENT_V1 — decoupled renderer tests (MS-A04).
 * Pure logic: no WP/DB required (function_exists guards).
 *
 *   php tests/run-fit-indicator.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);

// WP-function polyfills for standalone (non-WP) execution. Guarded so they
// never collide when this file runs under a real WordPress bootstrap.
if (!function_exists('esc_html')) {
    function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url($u) { return filter_var((string) $u, FILTER_VALIDATE_URL) ? (string) $u : ''; }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($u) { return (string) $u; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($t) { return trim((string) strip_tags((string) $t)); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class($c) { return (string) preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $c); }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($h) { return strip_tags((string) $h, '<a><strong><em><br><span>'); }
}
if (!function_exists('update_option')) {
    function update_option() { return true; }
}

require dirname(__DIR__) . '/includes/class-estandar-leads-fit.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-fit-indicator.php';

$passed = 0;
$failed = 0;
function check($label, $cond) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS  $label\n"; }
    else       { $failed++; echo "FAIL  $label\n"; }
}

$I = 'Estandar_Leads_Fit_Indicator';

// ── 1. Default OFF (byte-identical email) ─────────────────────────────────
check('1a defaults enabled=0', $I::defaults()['enabled'] === 0);
check('1b default mode text', $I::defaults()['mode'] === 'text');
check('1c render disabled → empty', $I::render('FUERA_DE_PERFIL', 'JOB_SEEKER') === '');
check('1d render empty fit → empty even enabled', $I::render('', 'JOB_SEEKER', array(), array('enabled' => 1, 'mode' => 'text')) === '');

// ── 2. mode text ──────────────────────────────────────────────────────────
$s = array('enabled' => 1, 'mode' => 'text', 'state_map' => array());
$h = $I::render('FUERA_DE_PERFIL', 'JOB_SEEKER', array(), $s);
check('2a text renders label', stripos($h, 'Fuera de perfil') !== false);
check('2b text escapes (no raw)', stripos($h, '<script>') === false);

// ── 3. mode emoji ─────────────────────────────────────────────────────────
$s = array('enabled' => 1, 'mode' => 'emoji', 'state_map' => array('FUERA_DE_PERFIL' => array('emoji' => '🚫')));
check('3a emoji renders', stripos($I::render('FUERA_DE_PERFIL', '', array(), $s), '🚫') !== false);

// ── 4. mode badge ─────────────────────────────────────────────────────────
$s = array('enabled' => 1, 'mode' => 'badge', 'state_map' => array('MATCH' => array('color' => '#188038')));
$h = $I::render('MATCH', '', array(), $s);
check('4a badge renders', stripos($h, 'Match') !== false);
check('4b badge uses configured color', stripos($h, '#188038') !== false);

// ── 5. mode icon ──────────────────────────────────────────────────────────
$s = array('enabled' => 1, 'mode' => 'icon', 'state_map' => array('DUDOSO' => array('icon' => 'dashicons-flag')));
check('5a icon renders', stripos($I::render('DUDOSO', '', array(), $s), 'dashicons-flag') !== false);

// ── 6. mode image ─────────────────────────────────────────────────────────
$s = array('enabled' => 1, 'mode' => 'image', 'state_map' => array('FUERA_DE_PERFIL' => array('image_url' => 'https://cdn.example.com/fuera.png')));
$h = $I::render('FUERA_DE_PERFIL', '', array(), $s);
check('6a image renders', stripos($h, '<img') !== false && stripos($h, 'fuera.png') !== false);

// ── 7. mode html ──────────────────────────────────────────────────────────
$s = array('enabled' => 1, 'mode' => 'html', 'state_map' => array('FUERA_DE_PERFIL' => array('html' => '<span data-fit="out">Fuera</span>')));
check('7a html renders verbatim', stripos($I::render('FUERA_DE_PERFIL', '', array(), $s), 'data-fit="out"') !== false);

// ── 8. mode template → falls back to text when no template renderer ───────
$s = array('enabled' => 1, 'mode' => 'template', 'state_map' => array());
check('8a template falls back to text', stripos($I::render('MATCH', '', array('template' => 'email'), $s), 'Match') !== false);

// ── 9. unknown mode → text fallback ───────────────────────────────────────
$s = array('enabled' => 1, 'mode' => 'hologram', 'state_map' => array());
check('9a unknown mode → text', stripos($I::render('MATCH', '', array(), $s), 'Match') !== false);

// ── 10. state_map override beats canonical label ──────────────────────────
$s = array('enabled' => 1, 'mode' => 'text', 'state_map' => array('MATCH' => array('label' => 'Cliente ideal')));
check('10a state_map label override', stripos($I::render('MATCH', '', array(), $s), 'Cliente ideal') !== false);
check('10b canonical label used when no override', stripos($I::render('FUERA_DE_PERFIL', '', array(), $s), 'Fuera de perfil') !== false);

// ── 11. reason label propagation ──────────────────────────────────────────
$s = array('enabled' => 1, 'mode' => 'text', 'state_map' => array());
check('11a reason label not in text by default (label only)', stripos($I::render('FUERA_DE_PERFIL', 'SUPPLIER', array(), $s), 'proveedor') === false);
$def = $I::state_defaults('FUERA_DE_PERFIL', 'SUPPLIER');
check('11b state_defaults carries reason label', $def['reason_label'] === 'Es proveedor, no cliente');
check('11c state_defaults canonical label', $def['label'] === 'Fuera de perfil — no es cliente objetivo');

// ── 12. registry exposes 7 builtin modes ──────────────────────────────────
$modes = array_keys($I::renderers());
foreach (array('text', 'badge', 'emoji', 'icon', 'image', 'html', 'template') as $m) {
    check("12a builtin mode $m", in_array($m, $modes, true));
}

// ── 13. save_settings sanitization (pure with function guards) ────────────
$saved = $I::save_settings(array('enabled' => '1', 'mode' => 'EMOJI', 'state_map' => array(
    'MATCH' => array('label' => ' ok <script>x</script>', 'emoji' => '✅'),
    'bogus-state' => array('label' => 'x'),
)));
check('13a mode normalized', $saved['mode'] === 'emoji');
check('13b enabled kept', $saved['enabled'] === 1);
check('13c state_map only canonical state key kept uppercase', isset($saved['state_map']['MATCH']) && !isset($saved['state_map']['bogus-state']));
check('13d label stripped', $saved['state_map']['MATCH']['label'] === 'ok x');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
