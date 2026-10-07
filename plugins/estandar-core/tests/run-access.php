<?php
/**
 * ESTANDAR SUITE v2 — Access (ACCESS_V1) tests.
 *
 *   php tests/run-access.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
$GLOBALS['__test_options'] = array();

if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) $t); } }
if (!function_exists('sanitize_email')) { function sanitize_email($e) { return filter_var((string) $e, FILTER_VALIDATE_EMAIL) ?: ''; } }
if (!function_exists('is_email')) { function is_email($e) { return filter_var((string) $e, FILTER_VALIDATE_EMAIL) !== false; } }
if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }

require dirname(__DIR__) . '/includes/Access.php';

use Estandar\Core\Access;

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

// ── defaults / sanitize ────────────────────────────────────────────────────
$d = Access::defaults();
check('1a defaults enabled=1 y log=1', $d['enabled'] === 1 && $d['log'] === 1);
check('1b default screens incluye Páginas y Medios', in_array('edit-page', $d['screens'], true) && in_array('upload', $d['screens'], true));

$s = Access::sanitize_settings(array('enabled' => 1, 'log' => 0, 'screens' => array('edit-page', 'upload', 'no_existe'), 'retention_days' => 99999, 'role_label' => '  Editor Web  '));
check('2a screens filtra desconocidos', $s['screens'] === array('edit-page', 'upload'));
check('2b retention clamp 3650', $s['retention_days'] === 3650);
check('2c log=0 se respeta', $s['log'] === 0);
check('2d label trim', $s['role_label'] === 'Editor Web');

$s2 = Access::sanitize_settings(array('screens' => array('bogus')));
check('2e screens vacío → default', $s2['screens'] === $d['screens']);
$s3 = Access::sanitize_settings(array('retention_days' => -5));
check('2f retention clamp >=1', $s3['retention_days'] === 1);
check('2g vacío → enabled default 1', Access::sanitize_settings(array())['enabled'] === 1);
check('2h vacío → log default 1', Access::sanitize_settings(array())['log'] === 1);
check('2i enabled=0 presente se respeta', Access::sanitize_settings(array('enabled' => 0))['enabled'] === 0);

// ── rol ────────────────────────────────────────────────────────────────────
$caps = Access::role_caps();
check('3a incluye edit_pages', !empty($caps['edit_pages']));
check('3b incluye upload_files', !empty($caps['upload_files']));
check('3c NO incluye manage_options', empty($caps['manage_options']));
check('3d NO incluye edit_plugins', empty($caps['edit_plugins']));
check('3e NO incluye edit_users', empty($caps['edit_users']));

// ── mapa pantalla→menú ─────────────────────────────────────────────────────
$keep = Access::menu_keep_slugs(array('dashboard', 'edit-page', 'upload', 'profile'));
check('4a keep incluye index.php', in_array('index.php', $keep, true));
check('4b keep incluye edit.php?post_type=page', in_array('edit.php?post_type=page', $keep, true));
check('4c keep deduplica upload/media', in_array('upload.php', $keep, true));
check('4d keep NO incluye plugins.php', !in_array('plugins.php', $keep, true));

// ── screen_allowed ─────────────────────────────────────────────────────────
$screens = array('edit-page', 'upload');
check('5a dashboard siempre permitido', Access::screen_allowed('dashboard', $screens));
check('5b pantalla en allowlist', Access::screen_allowed('edit-page', $screens));
check('5c pantalla fuera → false', !Access::screen_allowed('plugins', $screens));
check('5d vacío → false', !Access::screen_allowed('', $screens));

// ── user_is_restricted ─────────────────────────────────────────────────────
function fake_user($id, $roles) { return (object) array('ID' => $id, 'roles' => $roles, 'user_login' => 'x'); }
check('6a editor_web → restringido', Access::user_is_restricted(fake_user(2, array('editor_web'))));
check('6b administrator → NO', !Access::user_is_restricted(fake_user(1, array('administrator'))));
check('6c otro rol → NO', !Access::user_is_restricted(fake_user(3, array('editor'))));
check('6d sin rol → NO', !Access::user_is_restricted(fake_user(0, array())));
check('6e admin+editor_web → NO (admin prevalece)', !Access::user_is_restricted(fake_user(1, array('administrator', 'editor_web'))));

// ── settings round-trip ────────────────────────────────────────────────────
$GLOBALS['__test_options'][ Access::OPTION ] = array('enabled' => 0, 'screens' => array('page'));
$got = Access::settings();
check('7a settings lee opción', $got['enabled'] === 0 && $got['screens'] === array('page'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
