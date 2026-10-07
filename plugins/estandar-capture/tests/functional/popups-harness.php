<?php
/**
 * ESTANDAR SUITE v2 — Capture · Harness de Popups v2 (POPUPS_V2).
 *
 * Verifica el sistema de popups sobre su tabla (sin CPT legacy):
 *   1. Clases Popups + PopupsFrontend disponibles; CPT estandar_pop retirado.
 *   2. CRUD v2 + frontend_items normaliza config (contenido/triggers/design).
 *   3. Supresión POR POPUP (no global): un envío en otra landing no oculta.
 *   4. render_shell (overlay/container/close/body) + script de supresión inline.
 *   5. scope_css prefija con #ep-popup-<frontend id>.
 *
 * Uso:
 *   php tests/functional/popups-harness.php <wp-path>
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$wp_path = rtrim( $argv[1] ?? '', '/' );
if ( $wp_path === '' ) {
	$wp_path = dirname( __DIR__, 4 ) . '/Playground';
}
if ( ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress no encontrado en $wp_path (pasa el path o levanta el Playground)\n" );
	exit( 2 );
}

define( 'WP_USE_THEMES', false );
require_once $wp_path . '/wp-load.php';

$passed = 0; $failed = 0;
function pcheck( $label, $cond ) {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n"; }
}

pcheck( 'clase Popups disponible', class_exists( 'Estandar\\Capture\\Popups' ) );
pcheck( 'clase PopupsFrontend disponible', class_exists( 'Estandar\\Capture\\PopupsFrontend' ) );
pcheck( 'CPT de contenido estandar_pop registrado (editor de bloques)', post_type_exists( 'estandar_pop' ) && post_type_supports( 'estandar_pop', 'editor' ) && post_type_supports( 'estandar_pop', 'custom-fields' ) );
pcheck( 'shortcode ep_open registrado', shortcode_exists( 'ep_open' ) );
$render = do_shortcode( '[ep_open id="1000007"]Abrir[/ep_open]' );
pcheck( 'ep_open renderiza disparador data-ep-open', strpos( $render, 'data-ep-open="1000007"' ) !== false );

// Fixture v2.
$content = '<p>contenido</p><p>[estandar_form_v2 id="999"]</p>';
$row = \Estandar\Capture\Popups::create( array(
	'name'   => 'Fixture Popup V2 Harness',
	'slug'   => 'fixture-popup-v2-harness',
	'status' => 'active',
	'config_json' => wp_json_encode( array(
		'active'            => true,
		'content'           => $content,
		'triggers'          => array( 'time' => 3, 'scroll' => 0, 'inactivity' => 0, 'click_selector' => '' ),
		'segmentation'      => array( 'post_ids' => array(), 'url_contains' => array(), 'date_start' => '', 'date_end' => '' ),
		'frequency'         => array( 'mode' => 'always', 'days' => 0 ),
		'design'            => array( 'size' => 'medium', 'overlay' => true, 'overlay_color' => '#000000', 'overlay_opacity' => 0.6, 'animation' => 'fade', 'z_index' => 99999, 'custom_width' => '', 'css_mode' => 'inherit' ),
		'hide_if_submitted' => true,
		'project_slug'      => '',
	) ),
) );
pcheck( 'Popups::create devuelve row_id', $row > 0 );
$front_id = \Estandar\Capture\Popups::encode_frontend_id( $row );

$items = \Estandar\Capture\Popups::frontend_items();
$item  = null;
foreach ( $items as $it ) { if ( (int) $it['row_id'] === (int) $row ) { $item = $it; break; } }
pcheck( 'frontend_items incluye el popup v2', is_array( $item ) );
pcheck( 'frontend_items normaliza id de frontend', is_array( $item ) && (int) $item['id'] === (int) $front_id );
pcheck( 'frontend_items trae hide_if_submitted + design', is_array( $item ) && $item['hide_if_submitted'] === true && (int) $item['design']['z_index'] === 99999 );

pcheck( 'embedded_form_ids detecta el form embebido', \Estandar\Capture\PopupsFrontend::embedded_form_ids( $content ) === array( 999 ) );

// POPUP_BODY_CONTENT_V1: el cuerpo corre bloques + shortcodes, sin the_content.
$body = \Estandar\Capture\PopupsFrontend::render_body_content( '<!-- wp:paragraph --><p>Hola bloques</p><!-- /wp:paragraph -->[estandar_form_v2 id="999"]' );
pcheck( 'render_body_content renderiza bloques', strpos( $body, 'Hola bloques' ) !== false );
pcheck( 'render_body_content procesa shortcodes (no deja el tag crudo)', strpos( $body, '[estandar_form_v2' ) === false );

// POPUP_BLOCKS_V1: el contenido vive en un post CPT estandar_pop (Gutenberg).
$cpost = wp_insert_post( array( 'post_type' => 'estandar_pop', 'post_status' => 'publish', 'post_title' => 'Contenido fixture', 'post_content' => '<!-- wp:paragraph --><p>bloques</p><!-- /wp:paragraph -->' ) );
pcheck( 'post de contenido estandar_pop creado', $cpost > 0 && ! is_wp_error( $cpost ) );
pcheck( 'resolve_content usa el post (editor de bloques)', strpos( (string) \Estandar\Capture\Popups::resolve_content( array( 'content' => 'fallback', 'content_post_id' => (int) $cpost ) ), 'bloques' ) !== false );
pcheck( 'resolve_content reutiliza el post legacy migrado', \Estandar\Capture\Popups::resolve_content( array( 'content' => 'fallback', 'legacy_post_id' => (int) $cpost ) ) === (string) get_post_field( 'post_content', $cpost ) );
pcheck( 'resolve_content usa fallback config si no hay post', \Estandar\Capture\Popups::resolve_content( array( 'content' => 'solo-config' ) ) === 'solo-config' );
if ( $cpost && ! is_wp_error( $cpost ) ) { wp_delete_post( (int) $cpost, true ); }

// Supresión POR POPUP.
unset( $_COOKIE['estandar_fv2_done_999'], $_COOKIE['estandar_fv2_done_111'] );
$pf_item = array( 'content' => $content, 'hide_if_submitted' => true, 'project_slug' => '' );
pcheck( 'sin envíos → no suprime', \Estandar\Capture\PopupsFrontend::suppressed( $pf_item ) === false );
$_COOKIE['estandar_fv2_done_111'] = '1';
pcheck( 'envío en OTRA landing → NO suprime', \Estandar\Capture\PopupsFrontend::suppressed( $pf_item ) === false );
$_COOKIE['estandar_fv2_done_999'] = '1';
pcheck( 'envío del form del popup → SÍ suprime', \Estandar\Capture\PopupsFrontend::suppressed( $pf_item ) === true );
unset( $_COOKIE['estandar_fv2_done_999'], $_COOKIE['estandar_fv2_done_111'] );
pcheck( 'hide_if_submitted=0 → nunca suprime', \Estandar\Capture\PopupsFrontend::suppressed( array( 'content' => $content, 'hide_if_submitted' => false ) ) === false );

// Render shell.
$design = array( 'size' => 'medium', 'overlay' => true, 'overlay_color' => '#000000', 'overlay_opacity' => 0.6, 'animation' => 'fade', 'z_index' => 99999 );
ob_start();
\Estandar\Capture\PopupsFrontend::render_shell( (int) $front_id, '<p>v2</p>', $design, '', 'Fixture Popup V2', true, array( 999 ), array() );
$shell = ob_get_clean();
pcheck( 'shell usa id de frontend ep-popup-{offset+row}', strpos( $shell, "ep-popup-{$front_id}" ) !== false );
pcheck( 'shell renderiza overlay', strpos( $shell, 'ep-popup__overlay' ) !== false );
pcheck( 'shell renderiza botón cerrar', strpos( $shell, 'ep-popup__close' ) !== false );
pcheck( 'shell renderiza cuerpo', strpos( $shell, 'ep-popup__body' ) !== false );
pcheck( 'shell incluye supresión inline por popup', strpos( $shell, 'removeChild(el)' ) !== false && strpos( $shell, 'es_fv2_done_' ) !== false );

// scope_css.
$scoped = \Estandar\Capture\Popups::scope_css( 'h2{color:red}', (int) $front_id );
pcheck( 'scope_css prefija con #ep-popup-{id}', strpos( $scoped, "#ep-popup-{$front_id} h2" ) === 0 );

\Estandar\Capture\Popups::delete( $row );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
