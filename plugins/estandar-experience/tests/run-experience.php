<?php
/**
 * ESTANDAR SUITE v2 — Estandar Experience tests (F1 cimiento).
 *
 *   php tests/run-experience.php   → expect "0 failed"
 *
 * Verifica la integridad del manifiesto portado (módulos, efectos CSS,
 * bloques estructurales), la guarda de coexistencia con el legacy, y que la
 * fuente del port (assets del legacy FX V3) exista completa en disco.
 */

define( 'ABSPATH', __DIR__ );
$GLOBALS['__test_options'] = array();
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) ); }
}
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $k ] : $d; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

$BASE = dirname( __DIR__ );
require $BASE . '/includes/class-experience-manifest.php';
require $BASE . '/includes/class-experience-guard.php';
require $BASE . '/includes/class-experience-assets.php';
require $BASE . '/includes/class-experience-frontend.php';
require $BASE . '/includes/class-experience-blocks.php';

use Estandar\Experience\Manifest;
use Estandar\Experience\Guard;
use Estandar\Experience\Frontend;
use Estandar\Experience\Blocks;
use Estandar\Experience\Assets;

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $fail++; }
}

// ── 1. Manifiesto: módulos portados ────────────────────────────────────────
$modules = Manifest::MODULES;
$required = array( 'reveal', 'text', 'scroll', 'pointer', 'hover', 'ambient', 'particles', 'marquee', 'beforeafter', 'stack', 'coverflow', 'magnetic-carousel', 'spatial-gallery', 'cover-transition', 'scroll-panels', 'sticky-grid-scroll', 'horizontal-parallax-gallery', 'container-split-text', 'pinned-image-reveal', 'scroll-synced-carousel' );
check( '1a todos los módulos requeridos presentes', count( array_diff( $required, array_keys( $modules ) ) ) === 0 );
check( '1b total módulos = 20 (espejo legacy)', Manifest::module_count() === 20 );
$kinds = array_unique( array_column( $modules, 'kind' ) );
check( '1c kinds válidos (js/css/both)', array_diff( $kinds, array( 'js', 'css', 'both' ) ) === array() );
$no_assets = array();
foreach ( $modules as $id => $mod ) {
	if ( empty( $mod['js'] ) && empty( $mod['css'] ) ) { $no_assets[] = $id; }
}
check( '1d todo módulo declara asset js o css', $no_assets === array() );

// ── 2. Efectos CSS por módulo ───────────────────────────────────────────────
check( '2a hover: 10 efectos', count( Manifest::CSS_EFFECTS['hover'] ) === 10 );
check( '2b ambient: 7 efectos', count( Manifest::CSS_EFFECTS['ambient'] ) === 7 );

// ── 3. Bloques estructurales ────────────────────────────────────────────────
check( '3a 13 bloques estructurales', Manifest::structural_block_count() === 13 );
$blocks = Manifest::BLOCKS;
$core = Manifest::supported_blocks();
$slugs = array_column( $blocks, 'slug' );
check( '3b bloques nativos soportados', in_array( 'core/group', $core, true ) && in_array( 'core/heading', $core, true ) && in_array( 'core/button', $core, true ) );
check( '3c bloques estructurales en supported_blocks', array_diff( $slugs, $core ) === array() );
check( '3d todo bloque mapea a módulo existente', array_diff( array_keys( $blocks ), array_keys( $modules ) ) === array() );
check( '3e slugs únicos', count( $slugs ) === count( array_unique( $slugs ) ) );

// ── 4. Guarda de coexistencia (patrón owner + flag enabled) ────────────────
check( '4a solo legacy → owner legacy', Guard::frontend_owner( array( 'v2_active' => false, 'legacy_active' => true, 'override' => '', 'enabled' => false ) ) === 'legacy' );
check( '4b solo v2 (sin legacy) sin enabled → v2 (dueño por defecto; instalación limpia/FSE)', Guard::frontend_owner( array( 'v2_active' => true, 'legacy_active' => false, 'override' => '', 'enabled' => false ) ) === 'v2' );
check( '4c solo v2 + enabled → owner v2', Guard::frontend_owner( array( 'v2_active' => true, 'legacy_active' => false, 'override' => '', 'enabled' => true ) ) === 'v2' );
check( '4d ambos SIN enabled → owner legacy (seguro, regla de dos)', Guard::frontend_owner( array( 'v2_active' => true, 'legacy_active' => true, 'override' => '', 'enabled' => false ) ) === 'legacy' );
check( '4e ambos + enabled → owner v2', Guard::frontend_owner( array( 'v2_active' => true, 'legacy_active' => true, 'override' => '', 'enabled' => true ) ) === 'v2' );
check( '4f ambos + enabled + override legacy → owner legacy', Guard::frontend_owner( array( 'v2_active' => true, 'legacy_active' => true, 'override' => 'legacy', 'enabled' => true ) ) === 'legacy' );
check( '4g ninguno → none', Guard::frontend_owner( array( 'v2_active' => false, 'legacy_active' => false, 'override' => '', 'enabled' => false ) ) === 'none' );
check( '4h activar v2 frontend requiere enabled', Guard::should_activate_v2_frontend( array( 'v2_active' => true, 'legacy_active' => true, 'override' => '', 'enabled' => true ) ) === true && Guard::should_activate_v2_frontend( array( 'v2_active' => true, 'legacy_active' => true, 'override' => '', 'enabled' => false ) ) === false );
check( '4i suprime legacy solo con enabled + legacy', Guard::should_suppress_legacy_frontend( array( 'v2_active' => true, 'legacy_active' => true, 'override' => '', 'enabled' => true ) ) === true && Guard::should_suppress_legacy_frontend( array( 'v2_active' => true, 'legacy_active' => true, 'override' => '', 'enabled' => false ) ) === false );
check( '4j override off → none (desactivar v2)', Guard::frontend_owner( array( 'v2_active' => true, 'legacy_active' => false, 'override' => 'off', 'enabled' => true ) ) === 'none' );

// ── 5. Fuente del port: assets del legacy FX V3 presentes en disco ──────────
$legacy_dir = dirname( $BASE ) . '/estandar-experience-blocks';
$missing = array();
if ( is_dir( $legacy_dir ) ) {
	foreach ( $modules as $id => $mod ) {
		foreach ( array( 'js', 'css' ) as $kind ) {
			if ( empty( $mod[ $kind ] ) ) { continue; }
			if ( ! file_exists( $legacy_dir . '/' . $mod[ $kind ] ) ) { $missing[] = $mod[ $kind ]; }
		}
	}
	foreach ( array( 'assets/core/core.js', 'assets/core/core.css', 'assets/editor/catalog.js', 'assets/editor/panel.js', 'assets/editor/blocks.js' ) as $core_asset ) {
		if ( ! file_exists( $legacy_dir . '/' . $core_asset ) ) { $missing[] = $core_asset; }
	}
	foreach ( $blocks as $dir ) {
		foreach ( array( 'block.json', 'render.php' ) as $part ) {
			if ( ! file_exists( $legacy_dir . '/blocks/' . $dir['dir'] . '/' . $part ) ) { $missing[] = 'blocks/' . $dir['dir'] . '/' . $part; }
		}
	}
	check( '5a assets legacy fuente completos (kernel+módulos+editor+bloques)', $missing === array() );
} else {
	check( '5a assets legacy fuente completos (SKIP: legacy no en disco)', true );
}

// ── 6. CSS_VARS + sanitizador de valores CSS ────────────────────────────────
$css_vars = Manifest::CSS_VARS;
check( '6a CSS_VARS define hover.color → --efx-accent', ( $css_vars['hover.color']['var'] ?? '' ) === '--efx-accent' );
check( '6b CSS_VARS define ambient.speed type seconds', ( $css_vars['ambient.speed']['type'] ?? '' ) === 'seconds' );
check( '6c color válido pasa', Manifest::sanitize_css_value( '#ff8800', 'color' ) === '#ff8800' );
check( '6d color inválido rechazado', Manifest::sanitize_css_value( 'red;x', 'color' ) === '' );
check( '6e unit_float clamp 0..3', Manifest::sanitize_css_value( 99, 'unit_float' ) === '3' && Manifest::sanitize_css_value( -5, 'unit_float' ) === '0' );
check( '6f seconds formatea con s y clamp', Manifest::sanitize_css_value( 5, 'seconds' ) === '5s' && Manifest::sanitize_css_value( 0.05, 'seconds' ) === '0.1s' );

// ── 7. Frontend: sanitize_config (lógica pura) ──────────────────────────────
$frontend = Frontend::instance();
$empty = $frontend->sanitize_config( array() );
check( '7a config vacío → sin módulos', $empty['modules'] === array() );
check( '7b advanced por defecto once+mobile true', $empty['advanced']['once'] === true && $empty['advanced']['mobile'] === true );

$cfg = $frontend->sanitize_config( array(
	'reveal' => array( 'effect' => 'rise', 'distance' => 36 ),
	'text'   => array( 'effect' => 'cascade', 'split' => 'words' ),
	'timing' => array( 'duration' => 99999, 'stagger' => -5, 'easing' => 'bogus', 'trigger' => 'visible' ),
	'advanced' => array( 'mobile' => false ),
) );
check( '7c módulos reveal+text presentes', isset( $cfg['modules']['reveal'] ) && isset( $cfg['modules']['text'] ) );
check( '7d effect correcto', $cfg['modules']['reveal']['effect'] === 'rise' );
check( '7e timing clampeado (duration→5000, stagger→0)', $cfg['timing']['duration'] === 5000 && $cfg['timing']['stagger'] === 0 );
check( '7f easing inválido filtrado', empty( $cfg['timing']['easing'] ) );
check( '7g trigger visible conservado', $cfg['timing']['trigger'] === 'visible' );
check( '7h advanced mobile false respetado', $cfg['advanced']['mobile'] === false );

$bad = $frontend->sanitize_config( array( 'reveal' => array( 'effect' => '' ), 'nope' => array( 'effect' => 'x' ) ) );
check( '7i effect vacío o módulo no declarado → filtrado', $bad['modules'] === array() );

$opts = $frontend->sanitize_options( array( 'color' => '#22d3ee', 'intensity' => 99, 'flag' => true, 'bad key!' => 'x', 'script' => '<script>' ) );
check( '7j opciones: color conservado', ( $opts['color'] ?? '' ) === '#22d3ee' );
check( '7k opciones: numérico clampeado', ( $opts['intensity'] ?? 0 ) === 99.0 );
check( '7l opciones: bool conservado', ( $opts['flag'] ?? null ) === true );
check( '7m opciones: clave inválida filtrada', ! isset( $opts['bad key!'] ) );
check( '7n opciones: string malicioso filtrado', ! isset( $opts['script'] ) );

// ── 8. Assets del plugin v2 presentes en disco (kernel + módulos base F2) ───
$v2_missing = array();
foreach ( array( 'assets/core/core.js', 'assets/core/core.css', 'assets/core/mobile.css' ) as $a ) {
	if ( ! file_exists( $BASE . '/' . $a ) ) { $v2_missing[] = $a; }
}
$base_modules = array( 'reveal', 'text', 'scroll', 'pointer', 'hover', 'ambient', 'particles' );
foreach ( $base_modules as $id ) {
	foreach ( array( 'js', 'css' ) as $kind ) {
		if ( empty( $modules[ $id ][ $kind ] ) ) { continue; }
		if ( ! file_exists( $BASE . '/' . $modules[ $id ][ $kind ] ) ) { $v2_missing[] = $modules[ $id ][ $kind ]; }
	}
}
check( '8a assets v2 (kernel + 7 módulos base + mobile boost) presentes', $v2_missing === array() );

// ── 9. F3: bloques estructurales portados al plugin v2 ─────────────────────
$block_dirs = glob( $BASE . '/blocks/*', GLOB_ONLYDIR ) ?: array();
check( '9a 13 carpetas de bloques en plugin v2', count( $block_dirs ) === 13 );
$json_bad = array();
$render_missing = array();
$legacy_ref = array();
foreach ( $block_dirs as $dir ) {
	$bj = $dir . '/block.json';
	if ( ! file_exists( $bj ) ) { $render_missing[] = $dir . '/block.json'; continue; }
	$j = json_decode( (string) file_get_contents( $bj ), true );
	if ( ! is_array( $j ) ) { $json_bad[] = $bj; continue; }
	if ( ( $j['textdomain'] ?? '' ) !== 'estandar-experience' ) { $json_bad[] = $bj . ' (textdomain)'; }
	if ( ( $j['editorScript'] ?? '' ) !== 'estandar-experience-editor' || ( $j['editorStyle'] ?? '' ) !== 'estandar-experience-editor-css' ) { $json_bad[] = $bj . ' (editor handles)'; }
	if ( ! isset( $j['render'] ) ) { $json_bad[] = $bj . ' (sin render)'; }
	if ( ! file_exists( $dir . '/render.php' ) ) { $render_missing[] = $dir . '/render.php'; }
	$src = (string) file_get_contents( $dir . '/render.php' );
	if ( strpos( $src, 'Estandar\FX' ) !== false || strpos( $src, 'estandar-efx-' ) !== false ) { $legacy_ref[] = $dir; }
}
check( '9b block.json válidos + textdomain + editorScript/editorStyle', $json_bad === array() );
check( '9c render.php presentes en los 13', $render_missing === array() );
check( '9d sin referencias legacy en bloques portados', $legacy_ref === array() );
check( '9e BLOCKS_MAP = 13 y módulos mapean a manifest', count( Blocks::BLOCKS_MAP ) === 13 && array_diff( array_keys( Blocks::BLOCKS_MAP ), array_column( $blocks, 'slug' ) ) === array() );
$dir_to_module = array();
foreach ( $blocks as $mod_id => $meta ) { $dir_to_module[ $meta['dir'] ] = $mod_id; }
$fx_missing = array();
foreach ( $block_dirs as $dir ) {
	$mod = $dir_to_module[ basename( $dir ) ] ?? '';
	if ( '' === $mod ) { $fx_missing[] = basename( $dir ) . ' (sin módulo)'; continue; }
	foreach ( array( 'js', 'css' ) as $kind ) {
		if ( empty( $modules[ $mod ][ $kind ] ) ) { continue; }
		if ( ! file_exists( $BASE . '/' . $modules[ $mod ][ $kind ] ) ) { $fx_missing[] = $modules[ $mod ][ $kind ]; }
	}
}
check( '9f todo bloque estructural tiene assets fx en plugin v2', $fx_missing === array() );

// ── 10. Categoría de bloque: sin duplicar con el legacy ────────────────────
$cats = array( array( 'slug' => 'core', 'title' => 'Core' ) );
$with = Assets::category_list( $cats );
check( '10a categoría nueva se antepone', count( $with ) === 2 && $with[0]['slug'] === 'estandar-experience' );
$with_legacy = Assets::category_list( array_merge( $cats, array( array( 'slug' => 'estandar-experience', 'title' => 'Legacy' ) ) ) );
check( '10b categoría existente (legacy) NO se duplica', count( $with_legacy ) === 2 && substr_count( json_encode( $with_legacy ), '"estandar-experience"' ) === 1 );

echo PHP_EOL;
echo ( $fail > 0 ) ? ( $fail . ' FAILED' . PHP_EOL ) : '0 failed' . PHP_EOL;
exit( $fail > 0 ? 1 : 0 );
