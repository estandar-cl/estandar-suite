<?php
/**
 * LANDING_PRECEDENCE_V1 — standalone suite.
 * Run: php tests/run-landing-precedence.php
 */

define('ABSPATH', __DIR__ . '/');

function sanitize_text_field($v) { return is_scalar($v) ? trim((string) $v) : ''; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $v)); }
function do_action(...$a) {}

require dirname(__DIR__) . '/includes/class-estandar-leads-attribution.php';

$failed = 0;
function check($label, $ok) {
    global $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

$C = 'Estandar_Leads_Attribution';
$home = 'https://example.test/';
$precise = 'https://example.test/proyectos/altacruz/';
$session = 'https://example.test/proyectos/parronales/';
$referrer = 'https://google.com/search?q=casas';

// 1. Explicit precise landing wins over everything.
$r = $C::canonical_landing(array('explicit' => $precise, 'submission' => $session, 'session' => $session, 'referrer' => $referrer, 'home' => $home));
check('explicit precise wins', $r['url'] === $precise && $r['source'] === 'explicit');

// 2. Generic home as explicit NEVER replaces a more precise lower-precedence landing.
$r = $C::canonical_landing(array('explicit' => $home, 'submission' => '', 'session' => $session, 'referrer' => '', 'home' => $home));
check('generic home never replaces precise session landing', $r['url'] === $session && $r['source'] === 'session');

// 3. Submission landing beats session landing.
$r = $C::canonical_landing(array('explicit' => '', 'submission' => $precise, 'session' => $session, 'referrer' => '', 'home' => $home));
check('submission beats session', $r['url'] === $precise && $r['source'] === 'submission');

// 4. Referrer fallback when nothing more precise exists.
$r = $C::canonical_landing(array('explicit' => '', 'submission' => '', 'session' => '', 'referrer' => $referrer, 'home' => $home));
check('referrer fallback', $r['url'] === $referrer && $r['source'] === 'referrer');

// 5. Home fallback last.
$r = $C::canonical_landing(array('explicit' => '', 'submission' => '', 'session' => '', 'referrer' => '', 'home' => $home));
check('home fallback last', $r['url'] === $home && $r['source'] === 'home');

// 6. All candidates generic home → highest precedence generic accepted.
$r = $C::canonical_landing(array('explicit' => $home, 'submission' => $home, 'session' => '', 'referrer' => '', 'home' => $home));
check('all generic: explicit generic accepted', $r['url'] === $home && $r['source'] === 'explicit');

// 7. Home with query string counts as precise (campaign landing on /?utm=...).
$home_utm = 'https://example.test/?utm_source=google';
$r = $C::canonical_landing(array('explicit' => $home_utm, 'submission' => '', 'session' => $session, 'referrer' => '', 'home' => $home));
check('home + query is precise', $r['url'] === $home_utm && $r['source'] === 'explicit');

// 8. Nothing at all.
$r = $C::canonical_landing(array());
check('empty candidates → none', $r['url'] === '' && $r['source'] === 'none');

// 9. is_generic_home guards.
check('is_generic_home: bare domain', $C::is_generic_home('https://example.test/'));
check('is_generic_home: no slash', $C::is_generic_home('https://example.test'));
check('is_generic_home: path is precise', !$C::is_generic_home($precise));
check('is_generic_home: query is precise', !$C::is_generic_home($home_utm));
check('is_generic_home: empty string false', !$C::is_generic_home(''));

echo "\n" . ($failed === 0 ? "ALL PASS\n" : $failed . " FAILED\n");
exit($failed === 0 ? 0 : 1);
