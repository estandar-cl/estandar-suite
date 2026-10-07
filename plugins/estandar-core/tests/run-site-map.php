<?php
/**
 * ESTANDAR SUITE v2 — Site Map tests (pure logic, SITE_MAP_V1).
 *
 *   php tests/run-site-map.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
$GLOBALS['__test_options'] = array();
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) $t); } }
if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
if (!function_exists('has_filter')) { function has_filter($tag, $cb = false) { return false; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value) { return $value; } }
if (!function_exists('post_type_exists')) { function post_type_exists($t) { return in_array((string) $t, array('page', 'post', 'project'), true); } }

require dirname(__DIR__) . '/includes/SiteMap.php';

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Core as C;

// ── 1. normalize_url ──────────────────────────────────────────────────────
check('1a normaliza barra final', C\SiteMap::normalize_url('https://Example.COM/demo') === 'https://example.com/demo');
check('1b quita query', C\SiteMap::normalize_url('https://cliente1.example.com/proyectos/?utm_source=x') === 'https://cliente1.example.com/proyectos/');
check('1c quita fragmento', C\SiteMap::normalize_url('https://cliente1.example.com/parronales/#seccion') === 'https://cliente1.example.com/parronales/');
check('1d mantiene barra al final', C\SiteMap::normalize_url('https://cliente1.example.com/proyectos/') === 'https://cliente1.example.com/proyectos/');
check('1e colapsa dobles barras', C\SiteMap::normalize_url('https://cliente1.example.com//a//b/') === 'https://cliente1.example.com/a/b/');
check('1f resuelve relativo con base', C\SiteMap::normalize_url('/demo/', 'https://cliente1.example.com/') === 'https://cliente1.example.com/demo/');
check('1g host a minúsculas', C\SiteMap::normalize_url('HTTPS://Example.COM/Pagina') === 'https://example.com/Pagina');

// ── 2. url_variants ───────────────────────────────────────────────────────
$vars = C\SiteMap::url_variants('https://cliente1.example.com/proyectos/');
check('2a dos variantes con barra', count($vars) === 2 && in_array('https://cliente1.example.com/proyectos/', $vars, true) && in_array('https://cliente1.example.com/proyectos', $vars, true));
$vars2 = C\SiteMap::url_variants('https://cliente1.example.com/raiz');
check('2b una variante sin barra', count($vars2) === 1);

// ── 3. internal_links ─────────────────────────────────────────────────────
$html = '<p><a href="https://cliente1.example.com/proyectos/">Proyectos</a> <a href="/demo/">demo</a> <a href="https://externo.cl/x">Fuera</a> <a href="#ancla">Ancla</a> <a href="mailto:a@b.cl">mail</a> <a href="tel:123">tel</a> <a href="https://cliente1.example.com/proyectos/">dup</a></p>';
$links = C\SiteMap::internal_links($html, 'https://cliente1.example.com/');
check('3a extrae enlaces internos', count($links) === 2);
check('3b normaliza y deduplica', in_array('https://cliente1.example.com/proyectos/', $links, true) && in_array('https://cliente1.example.com/demo/', $links, true));

// ── 4. classify_node ──────────────────────────────────────────────────────
check('4a publicada con tráfico → active', C\SiteMap::classify_node(array('status' => 'publish', 'views_total' => 10, 'views_30' => 2)) === 'active');
check('4b publicada con visitas solo 30d → active', C\SiteMap::classify_node(array('status' => 'publish', 'views_total' => 0, 'views_30' => 1)) === 'active');
check('4c publicada sin tráfico → visible', C\SiteMap::classify_node(array('status' => 'publish', 'views_total' => 0, 'views_30' => 0)) === 'visible');
check('4d borrador → draft', C\SiteMap::classify_node(array('status' => 'draft', 'views_total' => 0)) === 'draft');
check('4e papelera → trash', C\SiteMap::classify_node(array('status' => 'trash', 'views_total' => 0)) === 'trash');
check('4f portada → home', C\SiteMap::classify_node(array('status' => 'publish', 'is_front' => true, 'views_total' => 0)) === 'home');

// ── 5. Fixture de nodos y edges ───────────────────────────────────────────
$home_url = 'https://cliente1.example.com/';
$nodes = array(
    array('key' => 'p1', 'id' => 1, 'status' => 'publish', 'url' => 'https://cliente1.example.com/',          'parent' => 0, 'is_front' => true,  'links' => array()),
    array('key' => 'p2', 'id' => 2, 'status' => 'publish', 'url' => 'https://cliente1.example.com/proyectos/', 'parent' => 1, 'is_front' => false, 'links' => array('https://cliente1.example.com/proyectos/parronales/')),
    array('key' => 'p3', 'id' => 3, 'status' => 'publish', 'url' => 'https://cliente1.example.com/proyectos/parronales/', 'parent' => 2, 'is_front' => false, 'links' => array()),
    array('key' => 'p4', 'id' => 4, 'status' => 'publish', 'url' => 'https://cliente1.example.com/demo/',  'parent' => 0, 'is_front' => false, 'links' => array('https://cliente1.example.com/')),
    array('key' => 'p5', 'id' => 5, 'status' => 'draft',   'url' => 'https://cliente1.example.com/borrador/', 'parent' => 0, 'is_front' => false, 'links' => array()),
    array('key' => 'p6', 'id' => 6, 'status' => 'trash',   'url' => 'https://cliente1.example.com/vieja/',    'parent' => 0, 'is_front' => false, 'links' => array()),
);

$built = C\SiteMap::build_edges($nodes);
$edges = $built['edges'];
$unres = $built['unresolved'];
$sig = function ($es) {
    $out = array();
    foreach ($es as $e) { $out[] = $e['from'] . '>' . $e['to'] . '>' . $e['kind']; }
    sort($out);
    return $out;
};
$e = $sig($edges);
check('5a jerarquía p1→p2', in_array('p1>p2>hierarchy', $e, true));
check('5b jerarquía p2→p3', in_array('p2>p3>hierarchy', $e, true));
check('5c contenido p2→p3', in_array('p2>p3>content', $e, true));
check('5d contenido p4→p1', in_array('p4>p1>content', $e, true));
check('5e sin edges de borradores', ! in_array('p1>p5>hierarchy', $e, true) && ! in_array('p5>p1>content', $e, true));

// Menú de navegación: p1 (item 10) → p4 (item 11).
$mi10 = new stdClass(); $mi10->ID = 10; $mi10->object_id = 1; $mi10->menu_item_parent = 0;
$mi11 = new stdClass(); $mi11->ID = 11; $mi11->object_id = 4; $mi11->menu_item_parent = 10;
$built_menu = C\SiteMap::build_edges($nodes, array($mi10, $mi11));
$em = $sig($built_menu['edges']);
check('5f edge de menú p1→p4', in_array('p1>p4>menu', $em, true));

// ── 5bis. Divi shortcodes + redirect_map + unresolved (PURE) ──────────────
$divi_html = '<p><a href="https://cliente1.example.com/demo/empresa/">HTML</a></p>'
    . '[et_pb_button button_url="https://cliente1.example.com/ods/"]ODS[/et_pb_button]'
    . '[et_pb_slider slide_image="https://cliente1.example.com/demo/inmobiliaria/"]</p>'
    . '[et_pb_button button_url="https://externo.cl/x"]ext[/et_pb_button]';
$dl = C\SiteMap::divi_links($divi_html, 'https://cliente1.example.com/');
check('5g Divi extrae button_url', in_array('https://cliente1.example.com/ods/', $dl, true));
check('5h Divi extrae image_url', in_array('https://cliente1.example.com/demo/inmobiliaria/', $dl, true));
check('5i Divi ignora externos', ! in_array('https://externo.cl/x', $dl, true));

// redirect_map: p2 enlaza a /empresa/ que resuelve a p1 (redirect).
$nodes_r = $nodes;
$nodes_r[1]['links'] = array('https://cliente1.example.com/empresa/');
$rmap = array('https://cliente1.example.com/empresa/' => 'p1');
$built_r = C\SiteMap::build_edges($nodes_r, array(), $rmap);
$er = $sig($built_r['edges']);
check('5j redirect resuelto → edge content', in_array('p2>p1>content', $er, true));
check('5k unresolved vacío con redirect', count($built_r['unresolved']) === 0);

// sin redirect: /empresa/ sin nodo → unresolved.
$built_u = C\SiteMap::build_edges($nodes_r, array());
check('5l /empresa/ sin nodo → unresolved', count($built_u['unresolved']) === 1 && $built_u['unresolved'][0]['target'] === 'https://cliente1.example.com/empresa/' && $built_u['unresolved'][0]['kind'] === 'content');

// menú custom link sin destino → unresolved.
$mi20 = new stdClass(); $mi20->ID = 20; $mi20->object_id = 0; $mi20->type = 'custom'; $mi20->url = 'https://cliente1.example.com/sin-destino/'; $mi20->menu_item_parent = 0;
$built_cu = C\SiteMap::build_edges($nodes, array($mi20));
check('5m menú custom sin destino → unresolved menu', count($built_cu['unresolved']) === 1 && $built_cu['unresolved'][0]['kind'] === 'menu');

// El origen de un ítem de menú raíz debe ser la portada (is_front), no el
// primer nodo del array.
$nodes_rot = array_merge(array_slice($nodes, 2), array_slice($nodes, 0, 2));
$built_rot = C\SiteMap::build_edges($nodes_rot, array($mi11));
$erot = $sig($built_rot['edges']);
check('5n menú raíz parte de la portada', in_array('p1>p4>menu', $erot, true));

// ── 6. layout (BFS por capas) ─────────────────────────────────────────────
$graph = array('nodes' => $nodes, 'edges' => $edges);
$lay = C\SiteMap::layout($graph);
$pos = $lay['pos'];
check('6a raíz colocada en capa 0', isset($pos['p1']) && $pos['p1']['x'] === C\SiteMap::PAD);
check('6b hijos p2/p4 en capa 1', isset($pos['p2']) && isset($pos['p4']) && $pos['p2']['x'] === C\SiteMap::PAD + C\SiteMap::CARD_W + C\SiteMap::GAP_X && $pos['p4']['x'] === $pos['p2']['x']);
check('6c nieto p3 en capa 2', isset($pos['p3']) && $pos['p3']['x'] === C\SiteMap::PAD + 2 * (C\SiteMap::CARD_W + C\SiteMap::GAP_X));
check('6d huérfanos no colocados', in_array('p5', $lay['unplaced'], true) && in_array('p6', $lay['unplaced'], true) && ! isset($pos['p5']) && ! isset($pos['p6']));
check('6e colocados = alcanzables', count($lay['placed']) === 4);
check('6f edges con ambos extremos', count($lay['edges']) === count($edges));

// ── 7. sanitize_settings ──────────────────────────────────────────────────
$s = C\SiteMap::sanitize_settings(array('days' => 7, 'track_enabled' => 1, 'screenshots_enabled' => 0, 'screenshot_service' => 'mshots'));
check('7a días válidos', $s['days'] === 7 && $s['track_enabled'] === 1 && $s['screenshots_enabled'] === 0);
$s2 = C\SiteMap::sanitize_settings(array('days' => 999, 'track_enabled' => 0, 'screenshots_enabled' => 'x', 'screenshot_service' => 'malo'));
check('7b días inválidos → default', $s2['days'] === C\SiteMap::default_days());
check('7c bools normalizados', $s2['track_enabled'] === 0 && $s2['screenshots_enabled'] === 1);
check('7d servicio inválido → mshots', $s2['screenshot_service'] === 'mshots');

// ── 8. defaults ───────────────────────────────────────────────────────────
$def = C\SiteMap::default_settings();
check('8a default days 30', $def['days'] === 30 && $def['track_enabled'] === 1);
check('8b default GA4 apagado', $def['ga4_enabled'] === 0 && $def['ga4_property_id'] === '' && $def['ga4_connection_id'] === 0);
check('8c default post_types solo page', $def['post_types'] === array('page'));

// ── 8bis. post_types: sanitize + map_post_types ───────────────────────────
$spt = C\SiteMap::sanitize_post_types(array('post', 'project', 'basura', ''));
check('8d sanitize mantiene válidos + page', $spt === array('post', 'project', 'page'));
$spt2 = C\SiteMap::sanitize_post_types('project');
check('8e sanitize acepta string', $spt2 === array('project', 'page'));
$spt3 = C\SiteMap::sanitize_post_types(array());
check('8f vacío → solo page', $spt3 === array('page'));
check('8g map_post_types default = page', C\SiteMap::map_post_types() === array('page'));
$GLOBALS['__test_options'][C\SiteMap::OPTION_SETTINGS] = array('post_types' => array('page', 'post'));
check('8h map_post_types respeta settings', C\SiteMap::map_post_types() === array('page', 'post'));
unset($GLOBALS['__test_options'][C\SiteMap::OPTION_SETTINGS]);

// ── 9. GA4 helpers (PURE) ─────────────────────────────────────────────────
check('9a ga4_path_of barra final', C\SiteMap::ga4_path_of('https://cliente1.example.com/demo') === '/demo/');
check('9b ga4_path_of raíz', C\SiteMap::ga4_path_of('https://cliente1.example.com/') === '/');
check('9c ga4_path_of anidado', C\SiteMap::ga4_path_of('https://cliente1.example.com/proyectos/parronales/') === '/proyectos/parronales/');
check('9d ga4_path_of minúsculas', C\SiteMap::ga4_path_of('https://cliente1.example.com/Proyectos/PARRONALES/') === '/proyectos/parronales/');

$s3 = C\SiteMap::sanitize_settings(array('days' => 30, 'ga4_enabled' => 1, 'ga4_connection_id' => 7, 'ga4_property_id' => '  123456789  '));
check('9e settings GA4 sanitizados', $s3['ga4_enabled'] === 1 && $s3['ga4_connection_id'] === 7 && $s3['ga4_property_id'] === '123456789');

// ── 10. page_stats: nuestra analytics es primaria; GA4 solo extra ─────────
$ga4 = array('ok' => true, 'paths' => array('/demo/' => array(12, 340), '/proyectos/' => array(5, 90)));
$st = C\SiteMap::page_stats('https://cliente1.example.com/demo/', 30, $ga4);
check('10a primaria = nuestra (tracker), GA4 como extra', $st['source'] === 'tracker' && $st['views_total'] === 0 && $st['ga4_views_30'] === 12 && $st['ga4_views_total'] === 340);
$st2 = C\SiteMap::page_stats('https://cliente1.example.com/otra/', 30, $ga4);
check('10b path sin datos → sin extra GA4', ! isset( $st2['ga4_views_total'] ));
$st3 = C\SiteMap::page_stats('https://cliente1.example.com/demo/', 30, null);
check('10c sin GA4 → sin campos extra', ! isset( $st3['ga4_views_total'] ));

// ── 11. classify_node con tráfico extra (GA4 cuando nuestra cifra es 0) ───
check('11a extra_traffic sube a active', C\SiteMap::classify_node(array('status' => 'publish', 'views_total' => 0, 'views_30' => 0), true) === 'active');
check('11b sin extra sigue visible', C\SiteMap::classify_node(array('status' => 'publish', 'views_total' => 0, 'views_30' => 0), false) === 'visible');

echo "\n==> {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
