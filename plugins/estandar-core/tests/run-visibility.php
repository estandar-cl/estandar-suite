<?php
/**
 * ESTANDAR SUITE v2 — Visibility engine tests (3 levels: tab → sub → ss).
 *
 *   php tests/run-visibility.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
$GLOBALS['__test_options'] = array();
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
if (!function_exists('delete_option')) { function delete_option($k) { unset($GLOBALS['__test_options'][$k]); return true; } }
if (!function_exists('esc_html')) { function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($t) { return esc_html((string) $t); } }
if (!function_exists('esc_url')) { function esc_url($u) { return (string) $u; } }
if (!function_exists('admin_url')) { function admin_url($p = '') { return '/wp-admin/' . ltrim((string) $p, '/'); } }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($a = '') { echo ''; } }
if (!function_exists('wp_nonce_url')) { function wp_nonce_url($u, $a = '') { return $u . '&_wpnonce=x'; } }
if (!function_exists('checked')) { function checked($v, $c, $echo = false) { $r = $v == $c ? " checked='checked'" : ''; if ($echo) { echo $r; } return $r; } }
if (!function_exists('submit_button')) { function submit_button($t = '', $ty = '', $n = '', $w = false, $a = '') { echo ''; } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }

require dirname(__DIR__) . '/includes/Admin.php';

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Core\Admin;

// ── 1. normalize_visibility: legacy flat → 3-level nested ────────────────
$raw = array( 'config' => array( 'destinos', 'integraciones' ), 'estado' => array() );
$norm = Admin::normalize_visibility( $raw );
check('1a legacy flat config → destinos visible con ss vacío', isset( $norm['config']['destinos'] ) && $norm['config']['destinos'] === array() );
check('1b legacy flat integraciones visible', isset( $norm['config']['integraciones'] ) );
check('1c estado vacío → tab oculto (lista vacía)', $norm['estado'] === array() );

// ── 2. normalize_visibility: nested (sub => ss list) se conserva ─────────
$nested = array( 'config' => array( 'destinos' => array( 'destinos', 'entregas' ), 'integraciones' => array() ) );
$norm2 = Admin::normalize_visibility( $nested );
check('2a nested destinos → ss list conservado', $norm2['config']['destinos'] === array( 'destinos', 'entregas' ) );
check('2b nested integraciones → ss vacío', $norm2['config']['integraciones'] === array() );

// ── 3. get_visibility normaliza al leer ──────────────────────────────────
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array( 'config' => array( 'destinos' => array( 'destinos' => array() ), 'integraciones' => array() ) );
$map = Admin::get_visibility( 'core' );
check('3a get_visibility normaliza + migra legacy destinos → api-connect', isset( $map['api-connect']['destinos'] ) && $map['api-connect']['destinos'] === array() && isset( $map['config']['integraciones'] ) );

// ── 4. filter_subs: solo subs visibles (con nivel ss anidado) ────────────
// VISIBILITY_NEW_V1: sin __catalog (mapa legacy), un sub ausente del mapa se
// trata como NUEVO → visible. Para ocultar de verdad hace falta __catalog.
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array( 'config' => array( 'api-connect' => array( 'conexion', 'destinos' ), 'integraciones' => array() ) );
$subs = Admin::filter_subs( 'core', 'config', array( 'api-connect' => 'API-Connect', 'integraciones' => 'Integraciones', 'secretos' => 'Secretos' ) );
check('4a api-connect visible', isset( $subs['api-connect'] ) );
check('4b integraciones visible', isset( $subs['integraciones'] ) );
check('4c secretos nuevo sin catálogo → visible (no se oculta lo agregado)', isset( $subs['secretos'] ) );
// Con __catalog: secretos existía al guardar y quedó desmarcado → sí se oculta.
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array(
	'config' => array( 'api-connect' => array( 'conexion', 'destinos' ), 'integraciones' => array() ),
	'__catalog' => array( 'subs' => array( 'config' => array( 'api-connect', 'integraciones', 'secretos' ) ) ),
);
$subs3 = Admin::filter_subs( 'core', 'config', array( 'api-connect' => 'API-Connect', 'integraciones' => 'Integraciones', 'secretos' => 'Secretos' ) );
check('4d secretos en catálogo sin marcar → oculto', ! isset( $subs3['secretos'] ) );

// ── 5. filter_ssubs: filtra por nivel ss (hub api-connect) ───────────────
$ss_all = array( 'conexion' => 'Conexión', 'destinos' => 'Destinos', 'entregas' => 'Entregas', 'integraciones' => 'Integraciones' );
// sub sin restricción → todos visibles
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array( 'config' => array( 'api-connect' => array() ) );
check('5a ss vacío → todos visibles', count( Admin::filter_ssubs( 'core', 'config', 'api-connect', $ss_all ) ) === 4 );
// sub con lista parcial → solo esos (los demás existían y quedaron desmarcados)
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array(
	'config' => array( 'api-connect' => array( 'conexion', 'destinos' ) ),
	'__catalog' => array( 'ss' => array( 'config' => array( 'api-connect' => array( 'conexion', 'destinos', 'entregas', 'integraciones' ) ) ) ),
);
$filt = Admin::filter_ssubs( 'core', 'config', 'api-connect', $ss_all );
check('5b ss parcial con catálogo → solo conexion y destinos', array_keys( $filt ) === array( 'conexion', 'destinos' ) );
// sin mapa → todos visibles
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array();
check('5c sin mapa → todos ss visibles', count( Admin::filter_ssubs( 'core', 'config', 'api-connect', $ss_all ) ) === 4 );
// nunca ocultar todos los ss
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array( 'config' => array( 'api-connect' => array( 'noexiste' ) ) );
check('5d ss no coincidentes → fallback todos', count( Admin::filter_ssubs( 'core', 'config', 'api-connect', $ss_all ) ) === 4 );
// ss NUEVO (ausente del catálogo guardado) sin marcar → visible por defecto;
// los que existían y quedaron desmarcados (entregas/integraciones) → ocultos.
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array(
	'config' => array( 'api-connect' => array( 'conexion', 'destinos' ) ),
	'__catalog' => array( 'ss' => array( 'config' => array( 'api-connect' => array( 'conexion', 'destinos', 'entregas', 'integraciones' ) ) ) ),
);
$ss5 = array( 'conexion' => 'Conexión', 'destinos' => 'Destinos', 'entregas' => 'Entregas', 'integraciones' => 'Integraciones', 'nuevo' => 'Nuevo' );
$filt5 = Admin::filter_ssubs( 'core', 'config', 'api-connect', $ss5 );
check('5e ss nuevo sin catálogo → visible', isset( $filt5['nuevo'] ) && ! isset( $filt5['entregas'] ) && ! isset( $filt5['integraciones'] ) );

// ── 6. filter_tabs: tab con subs ocultos → oculto ────────────────────────
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array( 'config' => array( 'api-connect' => array() ), 'estado' => array() );
$tabs = Admin::filter_tabs( 'core', array( 'config' => 'Configuración', 'estado' => 'Estado', 'datos' => 'Datos' ), array() );
check('6a config visible (subs presentes)', isset( $tabs['config'] ) );
check('6b estado oculto (lista vacía)', ! isset( $tabs['estado'] ) );
check('6c datos no configurado → visible', isset( $tabs['datos'] ) );
// Todas ocultas → respetado (sin fallback "nunca ocultar todas"): el render
// de cada producto muestra el aviso render_visibility_none.
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array( 'config' => array(), 'estado' => array(), 'inicio' => array() );
$tabs_all = Admin::filter_tabs( 'core', array( 'config' => 'Configuración', 'estado' => 'Estado', 'inicio' => 'Inicio' ), array() );
check('6d todas las tabs ocultas → array vacío (respetado)', $tabs_all === array() );
ob_start();
Admin::render_visibility_none( 'core' );
$vis_none = ob_get_clean();
check('6e aviso "todas ocultas" emite enlace al manager', strpos( $vis_none, 'Administrar visibilidad' ) !== false );

// ── 7. save_visibility: persiste formato anidado (3 niveles) ─────────────
Admin::save_visibility( 'core', array( 'config' => array( 'api-connect' => array( 'conexion', 'destinos' ), 'integraciones' => array() ) ) );
$saved = $GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ];
check('7a save anidado → api-connect con ss', isset( $saved['config']['api-connect'] ) && $saved['config']['api-connect'] === array( 'conexion', 'destinos' ) );
check('7b save anidado → integraciones ss vacío', $saved['config']['integraciones'] === array() );
// legacy flat payload → se normaliza a anidado
Admin::save_visibility( 'core', array( 'config' => array( 'destinos', 'integraciones' ) ) );
$saved2 = $GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ];
check('7c save flat legacy → destinos con ss vacío', isset( $saved2['config']['destinos'] ) && $saved2['config']['destinos'] === array() );

// ── 8. is_tab_visible: respeta mapa anidado ──────────────────────────────
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array( 'config' => array( 'api-connect' => array() ), 'estado' => array() );
check('8a is_tab_visible config → true', Admin::is_tab_visible( 'core', 'config', array() ) === true );
check('8b is_tab_visible estado → false', Admin::is_tab_visible( 'core', 'estado', array() ) === false );

// ── 9. Migración legacy: destinos (con ss) → api-connect + leads ────────
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array(
	'config' => array(
		'destinos' => array( 'destinos' => array(), 'entregas' => array(), 'panel' => array(), 'acceso' => array() ),
	),
);
$mig = Admin::get_visibility( 'core' );
check('9a legacy destinos.webhooks → api-connect.destinos', isset( $mig['api-connect']['destinos'] ) );
check('9b legacy destinos.entregas → api-connect.entregas', isset( $mig['api-connect']['entregas'] ) );
check('9c legacy destinos.panel/acceso → leads sub-tab', isset( $mig['leads']['panel'] ) && isset( $mig['leads']['acceso'] ) );
check('9d legacy config sin destinos viejo', ! isset( $mig['config']['destinos'] ) );

// ── 10. Migración demo REAL (flat sub-tabs, sin ss): NO romper visibilidad ──
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array(
	'config' => array( 'destinos', 'integraciones', 'secretos', 'claves', 'correo', 'secure', 'visibilidad' ),
	'datos'  => array( 'adopcion', 'heredados' ),
);
$mig2 = Admin::get_visibility( 'core' );
$subs2 = Admin::filter_subs( 'core', 'config', array( 'telemetria' => 'T', 'api-connect' => 'AC', 'leads' => 'L', 'integraciones' => 'I', 'secretos' => 'S', 'claves' => 'C', 'correo' => 'Co', 'proyectos' => 'P', 'secure' => 'Se', 'visibilidad' => 'V' ) );
check('10a api-connect visible (no se rompe)', isset( $subs2['api-connect'] ) );
check('10b leads visible', isset( $subs2['leads'] ) );
check('10c integraciones + resto visibles', isset( $subs2['integraciones'] ) && isset( $subs2['secretos'] ) && isset( $subs2['visibilidad'] ) );
check('10d ss del hub visibles', count( Admin::filter_ssubs( 'core', 'config', 'api-connect', array( 'conexion' => 'C', 'destinos' => 'D', 'entregas' => 'E', 'integraciones' => 'I' ) ) ) === 4 );
check('10e ss de leads visibles', count( Admin::filter_ssubs( 'core', 'config', 'leads', array( 'panel' => 'P', 'acceso' => 'A', 'actividad' => 'Ac', 'api' => 'Ap' ) ) ) === 4 );

// ── 11. render_visibility_manager: el estado marcado = visibilidad EFECTIVA ─
// (VIS_STATE_V1) Un mapa vacío (nada personalizado) = todo visible → todos los
// sub-tabs salen marcados, coherente con filter_tabs/filter_subs. Antes salían
// sin marcar y un guardado reescribía el mapa ocultando lo no marcado.
function vis_parse( string $html ): array {
	$out = array();
	preg_match_all( '/<input type="checkbox" name="([^"]+)" value="([^"]+)"([^>]*)/', $html, $m, PREG_SET_ORDER );
	foreach ( $m as $x ) { $out[] = array( 'name' => $x[1], 'value' => $x[2], 'checked' => strpos( $x[3], 'checked' ) !== false, 'disabled' => strpos( $x[3], 'disabled' ) !== false ); }
	return $out;
}
function vis_state( array $cbs, string $name, string $value ): array {
	foreach ( $cbs as $cb ) { if ( $cb['name'] === $name && $cb['value'] === $value ) { return $cb; } }
	return array( 'checked' => false, 'disabled' => false );
}
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array();
ob_start();
Admin::render_visibility_manager( 'core', 'estandar-suite', array(
	'config' => array( 'telemetria' => 'Telemetría', 'api-connect' => 'API-Connect', 'leads' => 'Panel de Leads', 'visibilidad' => 'Visibilidad' ),
), array( 'config' => array( 'api-connect' => array( 'conexion' => 'Conexión', 'destinos' => 'Destinos' ) ) ) );
$html_v = ob_get_clean();
$cbs_v  = vis_parse( $html_v );
check('11a mapa vacío → sub-tabs marcados (visibles por defecto)', vis_state( $cbs_v, 'subs[core][config][]', 'telemetria' )['checked'] && vis_state( $cbs_v, 'subs[core][config][]', 'api-connect' )['checked'] );
check('11b mapa vacío → ss marcados', vis_state( $cbs_v, 'ss[core][config][api-connect][]', 'conexion' )['checked'] && vis_state( $cbs_v, 'ss[core][config][api-connect][]', 'destinos' )['checked'] );
check('11c toggle "seleccionar/deseleccionar todas" presente', strpos( $html_v, 'es-vis-all' ) !== false );
$vis_st = vis_state( $cbs_v, 'subs[core][config][]', 'visibilidad' );
check('11d visibilidad (fija) no se puede desmarcar', $vis_st['checked'] && $vis_st['disabled'] );

// Sub-tab oculto a propósito: existía al guardar (en __catalog) y quedó sin
// marcar → sin marcar; sus ss sin marcar + deshabilitados (no quedan
// "encendidos"). Sin __catalog un ausente sería "nuevo" (visible).
$GLOBALS['__test_options'][ Admin::visibility_option( 'core' ) ] = array(
	'config' => array( 'api-connect' => array() ),
	'__catalog' => array(
		'subs' => array( 'config' => array( 'telemetria', 'api-connect', 'leads' ) ),
		'ss'   => array(
			'config' => array(
				'api-connect' => array( 'conexion', 'destinos' ),
				'leads'       => array( 'panel', 'acceso' ),
			),
		),
	),
);
ob_start();
Admin::render_visibility_manager( 'core', 'estandar-suite', array(
	'config' => array( 'telemetria' => 'Telemetría', 'api-connect' => 'API-Connect', 'leads' => 'Panel de Leads' ),
), array(
	'config' => array(
		'api-connect' => array( 'conexion' => 'Conexión', 'destinos' => 'Destinos' ),
		'leads'       => array( 'panel' => 'Panel', 'acceso' => 'Acceso' ),
	),
) );
$html_v2 = ob_get_clean();
$cbs_v2  = vis_parse( $html_v2 );
check('11e sub ausente del mapa (oculto) → sin marcar', ! vis_state( $cbs_v2, 'subs[core][config][]', 'telemetria' )['checked'] );
check('11f sub presente en el mapa → marcado', vis_state( $cbs_v2, 'subs[core][config][]', 'api-connect' )['checked'] );
$ss_cx = vis_state( $cbs_v2, 'ss[core][config][api-connect][]', 'conexion' );
check('11g ss de un padre visible → marcados y habilitados', $ss_cx['checked'] && ! $ss_cx['disabled'] );
$ss_lead = vis_state( $cbs_v2, 'ss[core][config][leads][]', 'panel' );
check('11h ss de un padre oculto → sin marcar', ! $ss_lead['checked'] );
check('11i ss de un padre oculto → deshabilitados', $ss_lead['disabled'] );

echo "\n{$passed} passed, {$failed} failed\n"; exit($failed > 0 ? 1 : 0);
