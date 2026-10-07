<?php

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['__options'] = array();

function get_option( $k, $d = false ) {
	return $GLOBALS['__options'][ $k ] ?? $d;
}
function update_option( $k, $v ) {
	$GLOBALS['__options'][ $k ] = $v;
	return true;
}
function sanitize_key( $k ) {
	$k = strtolower( (string) $k );
	$k = preg_replace( '/[^a-z0-9_\- ]/', '', $k );
	return str_replace( ' ', '-', $k );
}
function sanitize_text_field( $t ) {
	return trim( preg_replace( '/\s+/', ' ', (string) $t ) );
}
function sanitize_textarea_field( $t ) {
	return trim( (string) $t );
}
function sanitize_email( $e ) {
	return trim( (string) $e );
}
function is_email( $e ) {
	return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL );
}
function wp_strip_all_tags( $s ) {
	return preg_replace( '/<[^>]*>/', '', (string) $s );
}
function wp_parse_url( $u, $c = -1 ) {
	return parse_url( (string) $u, $c );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $s ) {
	return (string) $s;
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_url_raw( $s ) {
	return trim( (string) $s );
}
function sanitize_title( $s ) {
	$s = strtolower( (string) $s );
	$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
	return trim( $s, '-' );
}
function wp_trim_words( $s, $n = 55, $more = '…' ) {
	$w = preg_split( '/\s+/', trim( (string) $s ) );
	return implode( ' ', array_slice( $w, 0, $n ) ) . ( count( $w ) > $n ? $more : '' );
}
function home_url( $p = '' ) {
	return 'https://example.test' . $p;
}
function wp_date( $f, $ts = null ) {
	return date( $f, $ts ?: time() );
}

require_once __DIR__ . '/../includes/Settings.php';
require_once __DIR__ . '/../includes/Feeds.php';
require_once __DIR__ . '/../includes/Collect.php';
require_once __DIR__ . '/../includes/Curate.php';
require_once __DIR__ . '/../includes/Render.php';
require_once __DIR__ . '/../includes/Ratings.php';

use Estandar\Noticias\Settings;
use Estandar\Noticias\Feeds;
use Estandar\Noticias\Collect;
use Estandar\Noticias\Curate;
use Estandar\Noticias\Render;
use Estandar\Noticias\Ratings;

$passed = 0;
$failed = 0;
function check( $cond, $label ) {
	global $passed, $failed;
	if ( $cond ) {
		$passed++;
		echo "PASS  {$label}\n";
	} else {
		$failed++;
		echo "FAIL  {$label}\n";
	}
}

$d = Settings::defaults();
check( isset( $d['general']['hora'], $d['general']['salida'], $d['ai']['provider'] ), 'defaults: general + ai presentes' );
check( count( $d['temas'] ) >= 10, 'defaults: catalogo de temas' );
check( isset( $d['temas']['ia-tecnologia']['feeds'][0] ), 'defaults: feeds por tema' );
check( $d['ediciones'] === array(), 'defaults: sin ediciones (deben configurarse)' );

$clean = Settings::sanitize(
	array(
		'general' => array(
			'hora'           => '7:5',
			'ventana_horas'  => '999',
			'max_por_tema'   => '5',
			'salida'         => 'raro',
			'activo'         => 1,
		),
		'ai'      => array( 'provider' => 'openai', 'modelo' => 'gpt-4o' ),
		'temas'   => array(
			array( 'nombre' => 'Un tema', 'activo' => 1, 'horas' => 24, 'max' => 3, 'feeds' => array( 'https://a.test/feed', 'https://a.test/feed', 'https://b.test/rss' ), 'feeds_new' => 'https://c.test/feed, https://a.test/feed', 'incluir' => 'ia, modelos' ),
		),
		'ediciones' => array(
			array( 'titulo' => 'Principal', 'activo' => 1, 'temas' => array( 'un-tema', 'no-existe' ), 'destinatarios' => "x@y.cl\nmalo\nz@w.cl", 'max_por_tema' => 7 ),
		),
		'ruido' => array( 'uno', 'uno', 'dos' ),
	),
	$d
);

check( $clean['general']['hora'] === '07:30', 'sanitize: hora invalida -> fallback 07:30' );
check( $clean['general']['ventana_horas'] === 24, 'sanitize: ventana fuera de rango -> fallback' );
check( $clean['general']['max_por_tema'] === 5, 'sanitize: max por tema valido' );
check( $clean['general']['salida'] === 'ambos', 'sanitize: salida invalida -> ambos' );
check( $clean['ai']['provider'] === 'openai' && $clean['ai']['modelo'] === 'gpt-4o', 'sanitize: provider/model validos' );
check( isset( $clean['temas']['un-tema'] ), 'sanitize: slug derivado del nombre' );
check( count( $clean['temas']['un-tema']['feeds'] ) === 3, 'sanitize: feeds checkbox + nuevos, dedup' );
check( (int) $clean['temas']['un-tema']['max'] === 3, 'sanitize: noticias por tema' );
check( $clean['temas']['un-tema']['incluir'] === array( 'ia', 'modelos' ), 'sanitize: incluir por comas' );
check( $clean['ediciones']['principal']['temas'] === array( 'un-tema' ), 'sanitize: solo temas existentes' );
check( (int) $clean['ediciones']['principal']['max_por_tema'] === 7, 'sanitize: noticias por tema de la edicion' );
check( $clean['ediciones']['principal']['destinatarios'] === array( 'x@y.cl', 'z@w.cl' ), 'sanitize: emails validos' );
check( $clean['ruido'] === array( 'uno', 'dos' ), 'sanitize: ruido dedup' );

check( Settings::sanitize( array( 'general' => array( 'hora' => '23:59' ) ), $d )['general']['hora'] === '23:59', 'sanitize: hora valida 23:59' );

// Regresion del footgun: guardar una pestana distinta de "General" NO debe
// apagar `general.activo` ni vaciar `ruido`/`ai` (bug que corto el boletin 27-sep).
$base_active = $d;
$base_active['general']['activo'] = 1;
$base_active['ruido'] = array( 'farándula' );
$solo_temas = Settings::sanitize(
	array( 'temas' => array( array( 'nombre' => 'Otro', 'activo' => 1, 'feeds' => array( 'https://x.test/rss' ) ) ) ),
	$base_active
);
check( (int) $solo_temas['general']['activo'] === 1, 'sanitize: guardar otra pestana NO apaga general.activo' );
check( $solo_temas['ruido'] === array( 'farándula' ), 'sanitize: guardar otra pestana NO vacia ruido' );
check( $solo_temas['ai']['provider'] === $base_active['ai']['provider'], 'sanitize: guardar otra pestana NO resetea ai' );
check( (int) Settings::sanitize( array( 'general' => array( 'hora' => '08:00' ) ), $base_active )['general']['activo'] === 0, 'sanitize: guardar General sin casilla SI desactiva (accion explicita)' );

$j = Curate::extract_json( "```json\n{\"items\":[{\"i\":0,\"tema\":\"x\",\"resumen\":\"y\"}]}\n```" );
check( is_array( $j ) && $j['items'][0]['i'] === 0, 'curate: extract_json con cerca' );
check( Curate::extract_json( 'sin json aqui' ) === null, 'curate: extract_json sin objeto -> null' );

$rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>Canal</title>'
	. '<item><title>Noticia IA</title><link>https://openai.com/news/a</link><pubDate>' . gmdate( 'D, d M Y H:i:s', time() - 3600 ) . ' GMT</pubDate><description>Detalle <b>relevante</b></description></item>'
	. '<item><title>Farándula: teleserie</title><link>https://ejemplo.cl/farandula</link><pubDate>' . gmdate( 'D, d M Y H:i:s', time() - 3600 ) . ' GMT</pubDate><description>x</description></item>'
	. '<item><title>Vieja</title><link>https://ejemplo.cl/vieja</link><pubDate>' . gmdate( 'D, d M Y H:i:s', time() - 100000 ) . ' GMT</pubDate><description>x</description></item>'
	. '</channel></rss>';

$atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Blog</title>'
	. '<entry><title>Entrada Atom</title><link rel="alternate" href="https://googleblog.test/x"/><updated>' . gmdate( 'c', time() - 7200 ) . '</updated><summary>Resumen atom</summary></entry>'
	. '</feed>';

$ri = Feeds::parse( $rss );
check( count( $ri ) === 3, 'feeds: parse RSS 3 items' );
check( $ri[0]['titulo'] === 'Noticia IA' && $ri[0]['fuente'] === 'Canal', 'feeds: titulo + fuente del canal' );
check( str_contains( $ri[0]['texto'], 'relevante' ), 'feeds: texto limpio sin HTML' );
check( strpos( Feeds::clean( '&lt;img src="x"&gt;Hola &lt;p&gt;mundo&lt;/p&gt;' ), '<' ) === false, 'feeds: limpia HTML doble-codificado' );

$ai = Feeds::parse( $atom );
check( count( $ai ) === 1 && $ai[0]['link'] === 'https://googleblog.test/x', 'feeds: parse Atom + link href' );

$fresh = array( 'titulo' => 'Noticia IA', 'link' => 'https://openai.com/news/a', 'fuente' => 'Canal', 'fecha' => gmdate( 'c', time() - 3600 ), 'texto' => 'x' );
$old   = array( 'titulo' => 'Vieja', 'link' => 'https://ejemplo.cl/v', 'fuente' => 'Canal', 'fecha' => gmdate( 'c', time() - 100000 ), 'texto' => 'x' );
$far   = array( 'titulo' => 'Farándula: teleserie', 'link' => 'https://ejemplo.cl/f', 'fuente' => 'Canal', 'fecha' => gmdate( 'c', time() - 3600 ), 'texto' => 'x' );

check( Collect::pass_filters( $fresh, 24, array( 'teleserie' ), array(), array() ) === true, 'filtros: fresca sin ruido pasa' );
check( Collect::pass_filters( $old, 24, array(), array(), array() ) === false, 'filtros: fuera de ventana se descarta' );
check( Collect::pass_filters( $far, 24, array( 'teleserie' ), array(), array() ) === false, 'filtros: ruido se descarta' );
check( Collect::pass_filters( $fresh, 24, array(), array( 'ejemplo.cl' ), array() ) === true, 'filtros: blacklist otro dominio no afecta' );
check( Collect::pass_filters( array_merge( $fresh, array( 'link' => 'https://www.ejemplo.cl/x' ) ), 24, array(), array( 'ejemplo.cl' ), array() ) === false, 'filtros: blacklist con subdominio' );
check( Collect::pass_filters( $fresh, 24, array(), array(), array( 'ia' ) ) === true, 'filtros: incluir presente' );
check( Collect::pass_filters( $fresh, 24, array(), array(), array( 'noexiste' ) ) === false, 'filtros: incluir ausente descarta' );

$temas = array( 't' => array( 'activo' => 1, 'horas' => 24, 'feeds' => array( 'https://a.test/rss', 'https://b.test/rss' ), 'incluir' => array() ) );
$res   = Collect::collect( $temas, array( 'ventana_horas' => 24, 'max_por_tema' => 10, 'max_por_fuente' => 3, 'ruido' => array(), 'blacklist_dominios' => array(), 'fetch' => static function () use ( $rss ) { return $rss; } ) );
check( count( $res['temas']['t'] ) === 2, 'collect: dedup entre feeds (2 frescas)' );
check( count( $res['errors'] ) === 0, 'collect: sin errores' );

$res2 = Collect::collect( $temas, array( 'ventana_horas' => 24, 'max_por_tema' => 10, 'max_por_fuente' => 1, 'ruido' => array(), 'blacklist_dominios' => array(), 'fetch' => static function () use ( $rss ) { return $rss; } ) );
check( count( $res2['temas']['t'] ) === 1, 'collect: tope por fuente' );

$res3 = Collect::collect( $temas, array( 'ventana_horas' => 24, 'max_por_tema' => 10, 'max_por_fuente' => 3, 'ruido' => array(), 'blacklist_dominios' => array(), 'fetch' => static function () { return null; } ) );
check( count( $res3['errors'] ) === 2, 'collect: errores de fetch registrados' );

$rss_old = '<?xml version="1.0"?><rss version="2.0"><channel><title>Canal</title><item><title>Antigua</title><link>https://ejemplo.cl/old</link><pubDate>' . gmdate( 'D, d M Y H:i:s', time() - 200000 ) . ' GMT</pubDate><description>x</description></item></channel></rss>';
$temas_old = array( 't' => array( 'activo' => 1, 'horas' => 24, 'feeds' => array( 'https://a.test/rss' ), 'incluir' => array() ) );
$res_fb = Collect::collect( $temas_old, array( 'ventana_horas' => 24, 'max_por_tema' => 3, 'max_por_fuente' => 3, 'ruido' => array(), 'blacklist_dominios' => array(), 'fetch' => static function () use ( $rss_old ) { return $rss_old; } ) );
check( count( $res_fb['temas']['t'] ) === 1, 'collect: fallback a la mas reciente fuera de ventana' );

$data = array(
	'fecha'   => '2026-09-18',
	'edicion' => 'personal',
	'titulo'  => 'Lo que importa hoy',
	'temas'   => array(
		'ia' => array(
			'nombre' => 'IA y tecnología',
			'items'  => array(
				array( 'titulo' => 'Modelo nuevo', 'link' => 'https://openai.com/news/x', 'fuente' => 'OpenAI', 'texto' => 'Resumen de prueba.', 'fecha' => gmdate( 'c' ) ),
				array( 'titulo' => 'Paper', 'link' => 'https://arxiv.org/abs/1', 'fuente' => 'arXiv', 'texto' => 'Otro resumen.', 'fecha' => gmdate( 'c' ) ),
			),
		),
		'vacio' => array( 'nombre' => 'Vacío', 'items' => array() ),
	),
);

check( Render::total( $data ) === 2, 'render: total de items' );
check( Render::slug( $data ) === 'boletin-personal-2026-09-18', 'render: slug por edicion+fecha' );
$txt = Render::texto( $data );
check( str_contains( $txt, '## IA y tecnología' ) && str_contains( $txt, 'Modelo nuevo' ) && str_contains( $txt, 'https://openai.com/news/x' ), 'render: texto con tema e item' );
check( ! str_contains( $txt, 'Vacío' ), 'render: omite temas sin items' );
$html = Render::html( $data );
check( str_contains( $html, '<a href="https://openai.com/news/x"' ) && str_contains( $html, 'Leer más' ), 'render: html con enlaces' );
check( str_contains( Render::titulo( $data ), 'Lo que importa hoy' ), 'render: titulo compuesto' );
check( Render::tags( $data ) === array( 'IA y tecnología' ), 'render: tags solo con items' );

$k1 = Ratings::item_key( array( 'link' => 'https://openai.com/news/x' ) );
$k2 = Ratings::item_key( array( 'link' => 'https://openai.com/news/x' ) );
$k3 = Ratings::item_key( array( 'titulo' => 'Modelo nuevo' ) );
check( $k1 === $k2 && strlen( $k1 ) === 32, 'ratings: item_key estable por link' );
check( $k1 !== $k3, 'ratings: item_key distinto por item' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
