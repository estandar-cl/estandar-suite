<?php
namespace {
/**
 * INSIGHTS ADMIN — guardarraíl de render.
 *
 * Renderiza `includes/templates/insights-admin.php` (+ sus features) en TODAS sus
 * ramas (tab/sub/ss) con stubs y falla con CUALQUIER warning, notice o error.
 *
 * Motivo: el 2026-09-16 un `null` pasado a un renderer por una variable no
 * definida provocó un E_ERROR site-wide en el admin de Brain. Este test aplica el
 * mismo blindaje a Insights.
 *
 *   php tests/run-insights-admin.php   → expect "0 failed"
 */
error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ );
define( 'ESTANDAR_SUITE_INSIGHTS_PATH', dirname( __DIR__ ) . '/' );
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
	if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
	if ( ! defined( 'WEEK_IN_SECONDS' ) ) { define( 'WEEK_IN_SECONDS', 604800 ); }

	// Visual es PURE: se carga el archivo real (así el test valida el código que corre).
	require dirname( __DIR__ ) . '/includes/Visual.php';

$GLOBALS['__errs']    = array();
$GLOBALS['__options'] = array();
set_error_handler( function ( $no, $str, $file, $line ) {
	$GLOBALS['__errs'][] = $str . ' @ ' . basename( (string) $file ) . ':' . $line;
	return true;
} );

	// ── Stubs WordPress ───────────────────────────────────────────────────
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_url( $s ) { return (string) $s; }
	function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function admin_url( $p = '' ) { return 'https://x/wp-admin/' . $p; }
	function wp_nonce_field( $a = '', $b = '' ) { echo '<input type="hidden" name="_wpnonce" value="n">'; }
	function submit_button( $t = '', $a = 'primary', $b = 'submit', $c = true, $d = null ) { echo '<button>' . esc_html( $t ) . '</button>'; }
	function checked( $a, $b = true, $echo = true ) { $s = ( (string) $a === (string) $b ) ? ' checked' : ''; if ( $echo ) { echo $s; } return $s; }
	function selected( $a, $b = true, $echo = true ) { $s = ( (string) $a === (string) $b ) ? ' selected' : ''; if ( $echo ) { echo $s; } return $s; }
	function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
	function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
	function current_user_can( $c ) { return true; }
	function sanitize_key( $k ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); }
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
	function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d, ',', '.' ); }
	function wp_trim_words( $t, $n = 20, $more = '…' ) { $w = preg_split( '/\s+/', trim( (string) $t ) ); return implode( ' ', array_slice( $w, 0, $n ) ) . ( count( $w ) > $n ? $more : '' ); }
	function wp_list_pluck( $list, $field ) { $o = array(); foreach ( (array) $list as $i => $r ) { if ( is_array( $r ) && isset( $r[ $field ] ) ) { $o[ $i ] = $r[ $field ]; } } return $o; }
	function is_wp_error( $t ) { return false; }
	function number_format_i18n_es( $n ) { return (string) $n; }
	function rest_url( $p = '' ) { return 'https://x/wp-json/' . $p; }
	function home_url( $p = '' ) { return 'https://x/' . $p; }
}

namespace Estandar\Core {
	class Optimal { public static function note( $v, $o, $l = '' ) { return ''; } }
	class Admin {
		public static function render_nav_tabs( $items ) { echo '<nav></nav>'; }
		public static function render_module_tabs( $p, $t, $s, $m ) { echo '<nav></nav>'; }
		public static function render_subtabs( $p, $t, $a, $s = array() ) { echo '<nav></nav>'; }
		public static function render_ssubtabs( $p, $t, $sub, $a, $s = array() ) { echo '<nav></nav>'; }
		public static function help_html( $id, $text ) { return ''; }
		public static function help_script() { return ''; }
		public static function native_blank() { return false; }
		public static function render_feature_settings( $action, $option, $fields, $feature = '', $extra = array() ) { echo '<form></form>'; }
		public static function render_settings_panel( $s ) { echo '<table></table>'; }
		public static function render_inventory( $sets, $fn = array() ) { echo '<table></table>'; }
		public static function render_product_header() { echo ''; }
		public static function render_visibility_none( $p ) { echo ''; }
		public static function resolve_ssub( array $s, $d = '', $p = 'ss' ) { $k = array_keys( $s ); return $k ? $k[0] : ''; }
		public static function resolve_sub( array $s, $d = '' ) { $k = array_keys( $s ); return $k ? $k[0] : $d; }
		public static function resolve_tab( array $t, $d = '' ) { $k = array_keys( $t ); return $k ? $k[0] : $d; }
		public static function filter_ssubs( $p, $t, $s, array $ss ) { return $ss; }
		public static function filter_subs( $p, $t, array $s ) { return $s; }
		public static function filter_tabs( $p, $t, $s ) { return $t; }
		public static function sanitize_feature_fields( $fields, $raw ) { return (array) $raw; }
		public static function legacy_count( $t ) { return 0; }
		public static function legacy_rows( $t, $s = '' ) { return array(); }
		public static function legacy_table( $t ) { return false; }
	}
}

namespace Estandar\Insights {
	class Events { public static function recent( $n = 50 ) { return array(); } }
	class Funnel { public static function compute( array $c ) { return array( 'stages' => array( 'NEW' => array( 'count' => 0, 'rate_from_prev' => null ) ) ); } }

	class Adoption { public static function legacy_present() { return false; } public static function status() { return array(); } }
	class Analytics { public static function status() { return array(); } public static function rows( $s, $q = '' ) { return array(); } public static function count( $s ) { return 0; } public static function group( $s, $c ) { return array(); } }
	class SeoAdoption { public static function rows( $s, $q = '' ) { return array(); } public static function count( $s ) { return 0; } }
	class WebFunnel { public static function snapshot( $f = '', $t = '' ) { return array( 'stages' => array() ); } }
	class Admin {
		public static function analytics_ssubs() { return array( 'panorama' => 'Panorama', 'embudo' => 'Embudo web', 'resumen' => 'Resumen', 'trafico' => 'Tráfico', 'paginas' => 'Páginas', 'eventos' => 'Eventos', 'chatbot' => 'Chatbot', 'tendencia' => 'Tendencia', 'configuracion' => 'Configuración', 'settings' => 'Settings' ); }
		public static function seo_ssubs() { return array( 'resumen' => 'Resumen', 'auditoria' => 'Auditoría', 'landings' => 'Landings (Search Console)', 'settings' => 'Settings' ); }
		public static function get_settings() { return array(); }
		public static function panorama_presets() { return array( '7' => '1 semana' ); }
	}
}

namespace {
	$passed = 0; $failed = 0;
	function check( $label, $ok ) { global $passed, $failed; if ( $ok ) { $passed++; echo "PASS  {$label}\n"; } else { $failed++; echo "FAIL  {$label}\n"; } }

	$TEMPLATE = dirname( __DIR__ ) . '/includes/templates/insights-admin.php';

	/** Fixture completo de `$feature_view` (todas las claves usadas). */
	function fixture_view() {
		return array(
			'sessions' => array(), 'pageviews' => array(), 'events' => array(), 'by_source' => array(),
			'by_medium' => array(), 'top_pages' => array(), 'trend' => array(), 'conv' => 0,
			'total_pv' => 0, 'total_ev' => 0, 'total_s' => 0,
			'panorama_range' => array( 'preset' => '7', 'from' => '2026-09-01', 'to' => '2026-09-07', 'label' => '1 semana', 'is_custom' => false ),
			'panorama' => array( 'sessions' => array(), 'pageviews' => array(), 'events' => array(), 'labels' => array() ),
			'panorama_conv' => 0, 'panorama_pv' => 0, 'panorama_ev' => 0, 'panorama_s' => 0,
			'panorama_conv_total' => 0,
			'web_funnel' => array( 'stages' => array( array( 'key' => 'sessions', 'label' => 'Sesiones', 'count' => 0, 'rate' => null, 'bottleneck' => false ) ), 'from' => '', 'to' => '' ),
			'chatbot_events' => array(), 'chatbot_conversations' => 0, 'chatbot_leads' => 0,
			'landings' => array(), 'metrics' => 0, 'queries' => 0,
			'audit' => array(), 'audit_count' => 0, 'audit_last' => array( 'at' => '', 'pages' => 0, 'avg' => 0.0 ), 'audit_top' => array(), 'audit_site' => array(), 'audit_runs' => array(),
		);
	}

	/** Renderiza el template con un estado dado. */
	function render_insights( array $state ) {
		global $TEMPLATE;
		$base = array(
			'tab' => 'config',
			'tabs' => array( 'estado' => 'Estado', 'config' => 'Configuración', 'datos' => 'Datos', 'avanzado' => 'Avanzado' ),
			'subs' => array(
				'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
				'config'   => array( 'analytics' => 'Analytics', 'seo' => 'SEO', 'settings' => 'Configuración' ),
				'datos'    => array( 'adopcion' => 'Adopción' ),
				'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
			),
			'sub' => 'settings', 'ss' => '',
			'fn_settings' => array(), 'sets' => array(),
			'funnel' => array( 'stages' => array( 'NEW' => array( 'count' => 3, 'rate_from_prev' => null ) ) ),
			'events' => array(), 'obs' => array(), 'legacy' => false, 'imp' => null, 'rol' => null,
			'stage' => '', 'surface' => '',
			'analytics_status' => array(), 'seo_status' => array(),
			'adopt_available' => true,
			'feature_fields' => array(
				'analytics' => array( 'enabled' => array( 'type' => 'bool', 'label' => 'Analytics', 'default' => 1 ) ),
				'seo'       => array( 'enabled' => array( 'type' => 'bool', 'label' => 'SEO', 'default' => 1 ) ),
			),
			'feature_view' => fixture_view(), 'feature_saved' => false,
		);
		$vars = array_merge( $base, $state );
		$GLOBALS['__errs'] = array();
		extract( $vars );
		ob_start();
		include $TEMPLATE;
		$html = ob_get_clean();
		return array( $html, $GLOBALS['__errs'] );
	}

	$cases = array(
		'estado/resumen'          => array( 'tab' => 'estado', 'sub' => 'resumen' ),
		'estado/datos'            => array( 'tab' => 'estado', 'sub' => 'datos' ),
		'estado/esquema'          => array( 'tab' => 'estado', 'sub' => 'esquema' ),
		'config/settings'         => array( 'sub' => 'settings' ),
		'config/analytics+panorama'  => array( 'sub' => 'analytics', 'ss' => 'panorama' ),
		'config/analytics+embudo'    => array( 'sub' => 'analytics', 'ss' => 'embudo' ),
		'config/analytics+resumen'   => array( 'sub' => 'analytics', 'ss' => 'resumen' ),
		'config/analytics+trafico'   => array( 'sub' => 'analytics', 'ss' => 'trafico' ),
		'config/analytics+paginas'   => array( 'sub' => 'analytics', 'ss' => 'paginas' ),
		'config/analytics+eventos'   => array( 'sub' => 'analytics', 'ss' => 'eventos' ),
		'config/analytics+chatbot'   => array( 'sub' => 'analytics', 'ss' => 'chatbot' ),
		'config/analytics+tendencia' => array( 'sub' => 'analytics', 'ss' => 'tendencia' ),
		'config/analytics+config'    => array( 'sub' => 'analytics', 'ss' => 'configuracion' ),
		'config/analytics+settings'  => array( 'sub' => 'analytics', 'ss' => 'settings' ),
		'config/seo+resumen'      => array( 'sub' => 'seo', 'ss' => 'resumen' ),
		'config/seo+landings'     => array( 'sub' => 'seo', 'ss' => 'landings' ),
		'config/seo+settings'     => array( 'sub' => 'seo', 'ss' => 'settings' ),
		'config/seo+auditoria'    => array( 'sub' => 'seo', 'ss' => 'auditoria' ),
		'datos/adopcion'          => array( 'tab' => 'datos', 'sub' => 'adopcion' ),
		'avanzado/rest'           => array( 'tab' => 'avanzado', 'sub' => 'rest' ),
		'avanzado/cli'            => array( 'tab' => 'avanzado', 'sub' => 'cli' ),
		'avanzado/inventario'     => array( 'tab' => 'avanzado', 'sub' => 'inventario' ),
	);

	foreach ( $cases as $label => $state ) {
		list( $html, $errs ) = render_insights( $state );
		check( "render {$label}: sin warnings/errores", empty( $errs ) );
		if ( ! empty( $errs ) ) { echo '      → ' . implode( ' | ', array_slice( $errs, 0, 3 ) ) . "\n"; }
		check( "render {$label}: produce salida", trim( (string) $html ) !== '' );
	}

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
