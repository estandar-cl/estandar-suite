<?php
/**
 * ESTANDAR SUITE v2 — Core AIDiscovery tests (pure logic + guarded WP paths).
 *
 *   php tests/run-ai-discovery.php   → expect "0 failed"
 *
 * Verifica el motor de descubrimiento para motores IA/SEO: default OFF (nada
 * cambia hasta habilitarse), generación de llms.txt, reglas de robots aditivas,
 * JSON-LD (Organization/Person/FAQ), bloques de respuesta AEO y el log de
 * medición de visibilidad. Ningún test toca producción.
 */

define( 'ABSPATH', __DIR__ );
$GLOBALS['__test_options'] = array();
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $t ) { return trim( (string) $t ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return preg_replace( '/<[^>]+>/', '', (string) $s ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__test_options'][ $k ] = $v; return true; } }

require dirname( __DIR__ ) . '/includes/AIDiscovery.php';

use Estandar\Core\AIDiscovery;

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $cond ) { $fail++; }
}

// ── 1. Defaults: todo OFF (no destructivo por defecto) ──────────────────────
$d = AIDiscovery::defaults();
check( '1a defaults: flags OFF', ! $d['enable_llms'] && ! $d['allow_ai_bots'] && ! $d['enable_jsonld'] && ! $d['enable_answers'] && ! $d['enable_sitemap_lastmod'] && ! $d['enable_probe'] );
check( '1b defaults: claves esperadas', isset( $d['site_title'], $d['org_name'], $d['person_name'], $d['bots_policy'] ) );

// ── 2. Sanitización de settings ─────────────────────────────────────────────
$clean = AIDiscovery::sanitize_settings( array(
	'enable_llms'    => '1',
	'allow_ai_bots'  => 1,
	'site_title'     => '  Estandar  ',
	'org_name'       => 'Estandar Studios',
	'bots_policy'    => array( 'GPTBot' => 'deny', 'DesconocidoBot' => 'allow', 'PerplexityBot' => 'cache', 'ClaudeBot' => 'malo' ),
) );
check( '2a flags booleanos', $clean['enable_llms'] === true && $clean['allow_ai_bots'] === true );
check( '2b strings recortados', $clean['site_title'] === 'Estandar' );
check( '2c solo bots conocidos con política válida', isset( $clean['bots_policy']['GPTBot'], $clean['bots_policy']['PerplexityBot'] ) && ! isset( $clean['bots_policy']['DesconocidoBot'], $clean['bots_policy']['ClaudeBot'] ) );

// ── 3. llms.txt ─────────────────────────────────────────────────────────────
$site = array( 'title' => 'Estandar', 'description' => 'Asesoría real de IA.', 'home' => 'https://estandar.cl/' );
$links = array(
	array( 'title' => 'Asesoría IA', 'url' => 'https://estandar.cl/asesoria-ia/', 'description' => '' ),
	array( 'title' => 'Contacto', 'url' => '', 'description' => 'x' ), // sin url → se salta
);
$llms = AIDiscovery::build_llms( $site, $links, 10 );
check( '3a llms: título y descripción', strpos( $llms, '# Estandar' ) === 0 && strpos( $llms, '> Asesoría real de IA.' ) !== false );
check( '3b llms: home presente', strpos( $llms, '- [Estandar](https://estandar.cl/)' ) !== false );
check( '3c llms: link válido con sección', strpos( $llms, '## Asesoría IA' ) !== false && strpos( $llms, '- [Asesoría IA](https://estandar.cl/asesoria-ia/)' ) !== false );
check( '3d llms: sin url se omite', strpos( $llms, 'Contacto' ) === false );
$llms2 = AIDiscovery::build_llms( $site, $links, 0 );
check( '3e llms: límite 0', strpos( $llms2, '## Asesoría IA' ) === false );

// ── 4. llms-full.txt ────────────────────────────────────────────────────────
$full = AIDiscovery::build_llms_full( $site, array( array( 'title' => 'WP Suite', 'url' => 'https://estandar.cl/wp-suite/', 'description' => 'La máquina de captura.' ) ) );
check( '4a llms-full: incluye descripción', strpos( $full, '- [WP Suite](https://estandar.cl/wp-suite/): La máquina de captura.' ) !== false );

// ── 5. robots rules (aditivo) ───────────────────────────────────────────────
$rules = AIDiscovery::robots_rules( array( 'GPTBot' => 'deny', 'PerplexityBot' => 'cache' ) );
check( '5a deny → Disallow: /', in_array( 'Disallow: /', $rules, true ) );
check( '5b cache → Crawl-delay: 10', in_array( 'Crawl-delay: 10', $rules, true ) );
$rules_allow = AIDiscovery::robots_rules( array( 'GPTBot' => 'allow' ) );
check( '5c allow → Disallow vacío', in_array( 'Disallow:', $rules_allow, true ) && ! in_array( 'Disallow: /', $rules_allow, true ) );

$GLOBALS['__test_options'][ AIDiscovery::OPTION ] = array( 'allow_ai_bots' => false );
check( '5d robots_txt sin flag → intacto', AIDiscovery::robots_txt( 'User-agent: *' ) === 'User-agent: *' );
$GLOBALS['__test_options'][ AIDiscovery::OPTION ] = array( 'allow_ai_bots' => true, 'bots_policy' => array( 'GPTBot' => 'deny' ) );
$rt = AIDiscovery::robots_txt( 'User-agent: *' );
check( '5e robots_txt con flag → anexa bloque', strpos( $rt, '# Estandar AI Discovery' ) !== false && strpos( $rt, 'User-agent: GPTBot' ) !== false );
check( '5f robots_txt: preserva contenido original', strpos( $rt, 'User-agent: *' ) === 0 );

// ── 6. JSON-LD ──────────────────────────────────────────────────────────────
check( '6a org vacío → null', AIDiscovery::jsonld_org( array() ) === null );
$org = AIDiscovery::jsonld_org( array( 'org_name' => 'Estandar Studios', 'org_url' => 'https://estandar.cl' ) );
check( '6b org válido', null !== $org && strpos( (string) $org, '"@type":"Organization"' ) !== false && strpos( (string) $org, 'Estandar Studios' ) !== false );
check( '6c person vacío → null', AIDiscovery::jsonld_person( array() ) === null );
$per = AIDiscovery::jsonld_person( array( 'person_name' => 'Persona Demo', 'person_url' => 'https://example.com' ) );
check( '6d person válido con url', null !== $per && strpos( (string) $per, '"@type":"Person"' ) !== false && strpos( (string) $per, 'example.com' ) !== false );
check( '6e faq vacío → null', AIDiscovery::faq_jsonld( array() ) === null );
$faq = AIDiscovery::faq_jsonld( array( array( 'question' => '¿Qué es?', 'answer' => 'Respuesta.' ), array( 'question' => ' ', 'answer' => 'x' ) ) );
check( '6f faq válido ignora entradas vacías', null !== $faq && strpos( (string) $faq, '"@type":"FAQPage"' ) !== false && strpos( (string) $faq, '¿Qué es?' ) !== false && substr_count( (string) $faq, '"@type":"Question"' ) === 1 );

// ── 7. Bloques de respuesta AEO ─────────────────────────────────────────────
$markup = AIDiscovery::answer_markup( '¿Cuánto cuesta?', 'No será barato.' );
check( '7a markup semántico', strpos( $markup, 'class="es-answer"' ) !== false && strpos( $markup, '¿Cuánto cuesta?' ) !== false && strpos( $markup, 'No será barato.' ) !== false );
check( '7b acumula FAQ para footer', count( AIDiscovery::faq_collection() ) >= 1 );

// ── 8. Medición de visibilidad (append-only) ────────────────────────────────
$log1 = AIDiscovery::record_probe( 'perplexity', 'estandar.cl ia', true, array( 'rank' => 1 ) );
$log2 = AIDiscovery::record_probe( 'chatgpt', 'asesoría ia chile', false );
check( '8a probe append-only', count( $log2 ) === count( $log1 ) + 1 && $log2[ count( $log2 ) - 1 ]['found'] === false );
check( '8b probe persiste en opción', is_array( get_option( AIDiscovery::OPTION . '_probes' ) ) );
check( '8c sin proveedor IA → false', AIDiscovery::has_ai_provider() === false );

echo PHP_EOL;
echo ( $fail > 0 ) ? ( $fail . ' FAILED' . PHP_EOL ) : '0 failed' . PHP_EOL;
exit( $fail > 0 ? 1 : 0 );
