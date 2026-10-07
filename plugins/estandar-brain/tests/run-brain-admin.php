<?php
namespace {
/**
 * BRAIN ADMIN — guardarraíl de render.
 *
 * Renderiza `includes/templates/brain-admin.php` en TODAS sus ramas (tabs/subs/
 * ss) con stubs, y falla con CUALQUIER warning, notice o error de PHP.
 *
 * Motivo: el 2026-09-16 un `null` pasado a un renderer (por una variable no
 * definida en una rama del template) provocó un E_ERROR site-wide en producción
 * ("Tu sitio tiene problemas técnicos"). Este test caza esa clase de regresión.
 *
 *   php tests/run-brain-admin.php   → expect "0 failed"
 */
error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ );

$GLOBALS['__errs']  = array();
$GLOBALS['__options'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__errs'][] = $str . ' @ ' . basename( (string) $file ) . ':' . $line;
	return true;
} );

// ── Stubs de WordPress ────────────────────────────────────────────────────
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_js( $s ) { return (string) $s; }
function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; }
function wp_nonce_field( $a = '', $b = '' ) { echo '<input type="hidden" name="_wpnonce" value="n">'; }
function submit_button( $t = '', $type = 'primary', $name = 'submit', $wrap = true, $other = null ) { echo '<button>' . esc_html( $t ) . '</button>'; }
function checked( $a, $b = true, $echo = true ) { $s = ( (string) $a === (string) $b ) ? ' checked' : ''; if ( $echo ) { echo $s; } return $s; }
function selected( $a, $b = true, $echo = true ) { $s = ( (string) $a === (string) $b ) ? ' selected' : ''; if ( $echo ) { echo $s; } return $s; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function wp_rand( $a = 0, $b = 0 ) { return 1234; }
function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
function current_user_can( $c ) { return true; }
function sanitize_key( $k ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function number_format_i18n( $n ) { return (string) $n; }
function rest_url( $p = '' ) { return 'https://x/wp-json/' . $p; }
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function sanitize_title( $t ) { return strtolower( (string) preg_replace( '/[^a-z0-9\-_]/', '-', (string) $t ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_kses_post( $s ) { return (string) $s; }
function wp_unslash( $s ) { return $s; }
function current_time( $t = 'mysql' ) { return date( 'Y-m-d H:i:s' ); }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $ttl = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function add_query_arg( $a, $b = null ) { return 'https://x/wp-admin/admin.php'; }
function home_url( $p = '' ) { return 'https://x/' . $p; }

// ── Stubs de Core e Brain no cargables sin WP ─────────────────────────────
}

namespace Estandar\Core {
	class Optimal {
		public static function note( $v, $opt, $label = '' ) { return ''; }
	}
	class Admin {
		public static function render_module_tabs( $page, $tab, $sub, $modules ) { echo '<nav></nav>'; }
		public static function render_subtabs( $page, $tab, $active, $subs = array() ) { echo '<nav></nav>'; }
		public static function render_ssubtabs( $page, $tab, $sub, $active, $ssubs = array() ) { echo '<nav></nav>'; }
		public static function help_html( $id, $text ) { return ''; }
		public static function help_script() { return ''; }
		public static function render_feature_settings( $action, $option, $fields, $x = '', $extra = array() ) { echo '<form></form>'; }
		public static function render_settings_panel( $settings ) { echo '<table></table>'; }
		public static function render_inventory( $sets, $fn = array() ) { echo '<table></table>'; }
		public static function resolve_ssub( array $ssubs, $default = '', $param = 'ss' ) { $k = array_keys( $ssubs ); return $k ? $k[0] : ''; }
		public static function filter_ssubs( $product, $tab, $sub, array $ssubs ) { return $ssubs; }
		public static function render_visibility_none( $p ) { echo ''; }
		public static function native_blank() { return false; }
		public static function filter_tabs( $product, $tabs, $subs ) { return $tabs; }
		public static function resolve_tab( array $tabs, $default = '' ) { $k = array_keys( $tabs ); return $k ? $k[0] : $default; }
		public static function resolve_sub( array $subs, $default = '' ) { $k = array_keys( $subs ); return $k ? $k[0] : $default; }
		public static function render_product_header() { echo ''; }
	}
}

namespace Estandar\Brain {
	class Agent {
		const LOG_TABLE = 'estandar_agent_logs';
		public static function history( $limit = 30 ) { return array(); }
		public static function activation( $key = '' ) { $a = array( 'bubble_admin' => 1, 'agent_internal' => 1, 'agent_public' => 1 ); return $key === '' ? $a : (int) ( $a[ $key ] ?? 1 ); }
		public static function tools() { return array( 'listar_plugins' => array( 'level' => 'read', 'help' => 'x' ) ); }
		public static function summarize( $tool, $params ) { return ''; }
	}
	class Knowledge {
		const LAST_INDEXED = 'estandar_suite_knowledge_last_indexed';
		public static function count() { return 0; }
	}
	class PublicChat {
		public static function bubble( $opts = array() ) { return '<div></div>'; }
	}
	class Admin {
		public static function exporters_settings_groups() { return array( 'meta' => array(), 'google' => array() ); }
		public static function exporters_ssubs() { return array( 'meta' => 'Meta CAPI', 'google' => 'Google Ads OCI' ); }
		public static function template_signal_data() { return array( 'rows' => array(), 'queue' => 0, 'total' => 0 ); }
		public static function get_settings() { return array(); }
	}
}

namespace {
	require dirname( __DIR__ ) . '/includes/IntentPresets.php';
	require dirname( __DIR__ ) . '/includes/IntentMap.php';
	require dirname( __DIR__ ) . '/includes/Scoring.php';
	require dirname( __DIR__ ) . '/includes/AssessmentEngine.php';

	$passed = 0; $failed = 0;
	function check( $label, $ok ) { global $passed, $failed; if ( $ok ) { $passed++; echo "PASS  {$label}\n"; } else { $failed++; echo "FAIL  {$label}\n"; } }

	$TEMPLATE = dirname( __DIR__ ) . '/includes/templates/brain-admin.php';

	/** Renderiza el template con un estado dado y devuelve [html, errores]. */
	function render_brain( array $state ) {
		global $TEMPLATE;
		$base = array(
			'tab' => 'config', 'tabs' => array( 'config' => 'Configuración' ),
			'subs' => array( 'estado' => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ), 'config' => array( 'exportadores' => 'Exportadores', 'agente' => 'Agente', 'settings' => 'Settings' ), 'datos' => array( 'adopcion' => 'Adopción' ), 'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ) ),
			'ssubs' => array( 'settings' => array( 'scoring' => 'Ajustes de score', 'intents' => 'Intenciones', 'reglas' => 'Reglas' ) ),
			'sub' => 'exportadores', 'ss' => '',
			'fn_settings' => array(), 'sets' => array(),
			'intents' => \Estandar\Brain\IntentMap::intents(), 'area' => 'generico',
			'rules' => array(), 'signals' => array(), 'legacy' => false,
			'intent_saved' => false, 'rule_saved' => false, 'health' => array(),
		);
		$vars = array_merge( $base, $state );
		$GLOBALS['__errs'] = array();
		extract( $vars );
		ob_start();
		include $TEMPLATE;
		$html = ob_get_clean();
		return array( $html, $GLOBALS['__errs'] );
	}

	// --- Escenarios: todas las ramas del template ---
	$cases = array(
		'estado/resumen'              => array( 'tab' => 'estado', 'sub' => 'resumen' ),
		'estado/datos'                => array( 'tab' => 'estado', 'sub' => 'datos' ),
		'estado/esquema'              => array( 'tab' => 'estado', 'sub' => 'esquema' ),
		'config/exportadores'         => array( 'sub' => 'exportadores', 'ss' => 'meta' ),
		'config/agente'               => array( 'sub' => 'agente' ),
		'config/settings+scoring'     => array( 'sub' => 'settings', 'ss' => 'scoring' ),
		'config/settings+intents'     => array( 'sub' => 'settings', 'ss' => 'intents' ),
		'config/settings+reglas'      => array( 'sub' => 'settings', 'ss' => 'reglas' ),
		'datos/adopcion'              => array( 'tab' => 'datos', 'sub' => 'adopcion' ),
		'avanzado/rest'               => array( 'tab' => 'avanzado', 'sub' => 'rest' ),
		'avanzado/inventario'         => array( 'tab' => 'avanzado', 'sub' => 'inventario' ),
	);

	foreach ( $cases as $label => $state ) {
		list( $html, $errs ) = render_brain( $state );
		check( "render {$label}: sin warnings/errores", empty( $errs ) );
		if ( ! empty( $errs ) ) {
			echo '      → ' . implode( ' | ', array_slice( $errs, 0, 3 ) ) . "\n";
		}
		check( "render {$label}: produce salida", trim( (string) $html ) !== '' );
	}

	// --- Ramas con variables faltantes (regresión del 16-sep) ---
	// Antes: sub=exportadores sin $exp_ssubs ni $exp_ss → fatal.
	$GLOBALS['__errs'] = array();
	extract( array(
		'tab' => 'config', 'tabs' => array( 'config' => 'Configuración' ),
		'subs' => array( 'config' => array( 'exportadores' => 'Exportadores' ) ),
		'sub' => 'exportadores', 'ss' => '',
		'fn_settings' => array(), 'sets' => array(), 'intents' => array(), 'area' => 'generico',
		'rules' => array(), 'signals' => array(), 'legacy' => false,
		'intent_saved' => false, 'rule_saved' => false,
	) );
	ob_start(); include $TEMPLATE; $out_missing = ob_get_clean();
	check( 'exportadores SIN $exp_* definidos: no rompe (blindaje)', empty( $GLOBALS['__errs'] ) );

	$GLOBALS['__errs'] = array();
	extract( array(
		'tab' => 'config', 'tabs' => array( 'config' => 'Configuración' ),
		'subs' => array( 'config' => array( 'agente' => 'Agente' ) ),
		'sub' => 'agente', 'ss' => '',
		'fn_settings' => array(), 'sets' => array(), 'intents' => array(), 'area' => 'generico',
		'rules' => array(), 'signals' => array(), 'legacy' => false,
		'intent_saved' => false, 'rule_saved' => false,
	) );
	ob_start(); include $TEMPLATE; $out_missing2 = ob_get_clean();
	check( 'agente SIN $history definido: no rompe (blindaje)', empty( $GLOBALS['__errs'] ) );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
