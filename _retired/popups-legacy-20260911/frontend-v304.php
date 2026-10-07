<?php
defined('ABSPATH') || exit;

// ─── QUERY & CACHE ───────────────────────────────────────────────────────────

// ─── NORMALIZACIÓN (legacy CPT + v2 tabla) ────────────────────────────────────
// POPUP_MIGRATE_V2: el frontend sirve popups desde dos fuentes — el CPT legacy
// (estandar_pop, mientras dure la transición) y la tabla v2 (estandar_capture_popups).
// Todo se normaliza a un item común para que el motor y el render no distingan.

function ep_item_from_post(WP_Post $p): array
{
    $jm = function (string $key, array $def) use ($p): array {
        $raw = get_post_meta($p->ID, $key, true);
        $val = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        return array_merge($def, is_array($val) ? $val : []);
    };

    return [
        'id'                => (int) $p->ID,
        'source'            => 'legacy',
        'row_id'            => null,
        'legacy_post_id'    => (int) $p->ID,
        'title'             => (string) $p->post_title,
        'content'           => (string) $p->post_content,
        'triggers'          => $jm('_ep_triggers', ['time' => 0, 'scroll' => 0, 'inactivity' => 0, 'click_selector' => '']),
        'segmentation'      => $jm('_ep_segmentation', ['post_ids' => [], 'url_contains' => [], 'date_start' => '', 'date_end' => '']),
        'frequency'         => $jm('_ep_frequency', ['mode' => '7days', 'days' => 0]),
        'design'            => $jm('_ep_design', ['size' => 'medium', 'custom_width' => '', 'css_mode' => 'inherit', 'overlay' => true, 'overlay_color' => '#000000', 'overlay_opacity' => 0.6, 'animation' => 'fade', 'z_index' => 99999]),
        'hide_if_submitted' => get_post_meta($p->ID, '_ep_hide_if_submitted', true) === '1',
        'custom_css'        => (string) get_post_meta($p->ID, '_ep_custom_css', true),
        'custom_css_scoped' => (string) get_post_meta($p->ID, '_ep_custom_css_scoped', true),
    ];
}

/** Popups legacy ACTIVOS (CPT) no migrados aún a v2. */
function ep_legacy_items(): array
{
    $all = get_posts([
        'post_type'      => 'estandar_pop',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => '_ep_active', 'value' => '1']],
        'no_found_rows'  => true,
        'fields'         => 'all',
    ]);
    return array_map('ep_item_from_post', $all);
}

/** Popups v2 activos (tabla estandar_capture_popups). */
function ep_v2_items(): array
{
    if (!class_exists('\Estandar\Capture\Popups')) {
        return [];
    }
    return \Estandar\Capture\Popups::frontend_items();
}

/** IDs de post legacy que ya viven en v2 (para no duplicar). */
function ep_migrated_legacy_ids(): array
{
    $ids = [];
    foreach (ep_v2_items() as $it) {
        if (!empty($it['legacy_post_id'])) {
            $ids[(int) $it['legacy_post_id']] = true;
        }
    }
    return $ids;
}

/** Todos los items (v2 + legacy no migrado). */
function ep_all_items(): array
{
    $items    = ep_v2_items();
    $migrated = ep_migrated_legacy_ids();
    foreach (ep_legacy_items() as $it) {
        if (isset($migrated[(int) $it['legacy_post_id']])) {
            continue;
        }
        $items[] = $it;
    }
    return $items;
}

function ep_get_applicable_popups(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $by_id = [];
    foreach (ep_all_items() as $it) {
        if (ep_item_applies($it)) {
            $by_id[$it['id']] = $it;
        }
    }

    // POPUP_BLOCK_V1: popups referenciados por el bloque Gutenberg de una página
    // (legacy picker). Se muestran sin filtro de segmentación. Se omiten los ya
    // migrados a v2 (los cubre el item v2).
    $migrated = ep_migrated_legacy_ids();
    foreach (ep_get_block_popups() as $p) {
        if (isset($migrated[(int) $p->ID])) {
            continue;
        }
        $it = ep_item_from_post($p);
        $by_id[$it['id']] = $it;
    }

    $cache = array_values($by_id);
    return $cache;
}

function ep_get_block_popup_ids_from_blocks(array $blocks): array
{
    $ids = [];

    foreach ($blocks as $block) {
        if (($block['blockName'] ?? '') === 'estandar-pop/popup-picker') {
            $id = intval($block['attrs']['selectedPopupId'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if (!empty($block['innerBlocks'])) {
            $ids = array_merge($ids, ep_get_block_popup_ids_from_blocks($block['innerBlocks']));
        }
    }

    return array_values(array_unique($ids));
}

function ep_get_block_popups(): array
{
    $post = get_post();
    if (!$post || empty($post->post_content) || !has_blocks($post->post_content)) {
        return [];
    }

    $ids = ep_get_block_popup_ids_from_blocks(parse_blocks($post->post_content));
    if (empty($ids)) {
        return [];
    }

    return get_posts([
        'post_type'      => 'estandar_pop',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'post__in'       => $ids,
        'orderby'        => 'post__in',
        'no_found_rows'  => true,
        'fields'         => 'all',
    ]);
}

function ep_get_frontend_popups(): array
{
    // COOKIE_DONE_V1 (server-side): si este navegador ya envió un form de
    // captura v2 (cookie estandar_fv2_done_*), NO se renderizan los popups:
    // no hay popup que abrir aunque el JS esté cacheado viejo. Así la regla
    // "no mostrar a quienes ya dejaron sus datos" no depende del motor JS.
    if ( class_exists( '\Estandar\Capture\FormRenderer' )
        && \Estandar\Capture\FormRenderer::submitted_restriction_enabled()
        && \Estandar\Capture\FormRenderer::done_cookie_set() ) {
        return array();
    }

    return ep_get_applicable_popups();
}

/** Segmentación aplicable a la página actual. Puro sobre el item normalizado. */
function ep_item_applies(array $item): bool
{
    $seg = $item['segmentation'] ?? [];
    $now = time();

    // Dates
    if (!empty($seg['date_start']) && $now < strtotime($seg['date_start'])) {
        return false;
    }
    if (!empty($seg['date_end']) && $now > strtotime($seg['date_end'])) {
        return false;
    }

    // Location: if both empty → all site
    $has_ids      = !empty($seg['post_ids']);
    $has_patterns = !empty($seg['url_contains']);

    if (!$has_ids && !$has_patterns) {
        return true;
    }

    // post_ids match
    if ($has_ids) {
        $current = get_queried_object_id();
        if (in_array($current, array_map('intval', $seg['post_ids']), true)) {
            return true;
        }
    }

    // url_contains match
    if ($has_patterns) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        foreach ($seg['url_contains'] as $pattern) {
            if (!empty($pattern) && str_contains($uri, $pattern)) {
                return true;
            }
        }
    }

    return false;
}

/** Compat: evaluación por post_id legacy (envuelve el item normalizado). */
function ep_popup_applies(int $post_id): bool
{
    $p = get_post($post_id);
    return $p ? ep_item_applies(ep_item_from_post($p)) : false;
}

// ─── ENQUEUE ─────────────────────────────────────────────────────────────────

add_action('wp_enqueue_scripts', function () {
    $popups = ep_get_frontend_popups();
    if (is_admin() || empty($popups)) {
        return;
    }

    wp_enqueue_script('ep-engine', EP_URL . 'assets/js/engine.v2.js', [], EP_VERSION, true);
    wp_enqueue_style('ep-popup',   EP_URL . 'assets/css/popup.css', [], EP_VERSION);

    $payload = array_map(function ($it) {
        return [
            'id'        => (int) $it['id'],
            'name'      => (string) $it['title'],
            'triggers'  => $it['triggers'],
            'frequency' => $it['frequency'],
            'design'    => $it['design'],
            // POPUP_SKIP_SUBMITTED_V1: si el popup contiene un form de captura,
            // no abrir en navegadores que ya enviaron datos (es_fv2_done_*).
            'hideIfSubmitted' => !empty($it['hide_if_submitted']),
        ];
    }, $popups);

    wp_localize_script('ep-engine', 'epConfig', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('ep_nonce'),
        // SUBMITTED_TTL_V1: duración configurable de la restricción "ya envió"
        // (0 = sin restricción). El motor JS la usa para caducar el localStorage.
        'submittedTtlDays' => class_exists('\Estandar\Capture\FormRenderer') ? \Estandar\Capture\FormRenderer::submitted_cookie_days() : 365,
        'popups'  => array_values($payload),
    ]);
}, 20);

// ─── RENDER ──────────────────────────────────────────────────────────────────

add_action('wp_footer', function () {
    $popups = ep_get_frontend_popups();
    if (empty($popups)) {
        return;
    }

    foreach ($popups as $it) {
        $design     = $it['design'];
        $scoped_css = $it['custom_css_scoped'];
        $content    = apply_filters('the_content', $it['content']);

        ep_render_shell((int) $it['id'], $content, $design, $scoped_css, (string) $it['title']);
    }
});

function ep_render_shell(int $id, string $content, array $design, string $scoped_css, string $title = ''): void
{
    $size      = esc_attr($design['size']      ?? 'medium');
    $css_mode  = esc_attr($design['css_mode']  ?? 'inherit');
    $animation = esc_attr($design['animation'] ?? 'fade');
    $z_index   = intval($design['z_index']     ?? 99999);

    $container_style = '';
    if ($size === 'custom' && !empty($design['custom_width'])) {
        $container_style = 'width:' . esc_attr($design['custom_width']) . ';';
    }

    $overlay_style = '';
    if (!empty($design['overlay'])) {
        $color   = esc_attr($design['overlay_color']   ?? '#000000');
        $opacity = floatval($design['overlay_opacity'] ?? 0.6);
        $overlay_style = "background:{$color};opacity:{$opacity};";
    }

    $classes = implode(' ', [
        'ep-popup',
        "ep-popup--{$size}",
        "ep-popup--{$animation}",
        "ep-popup--{$css_mode}",
    ]);

    $title_id = "ep-popup-{$id}-title";
    $body_id  = "ep-popup-{$id}-body";
    ?>
    <div id="ep-popup-<?php echo $id; ?>"
         class="<?php echo $classes; ?>"
         style="z-index:<?php echo $z_index; ?>;"
         role="dialog"
         aria-modal="true"
         aria-labelledby="<?php echo esc_attr($title_id); ?>"
         aria-describedby="<?php echo esc_attr($body_id); ?>">

        <?php if (!empty($design['overlay'])): ?>
        <div class="ep-popup__overlay"
             style="<?php echo $overlay_style; ?>"
             data-ep-close="<?php echo $id; ?>"
             tabindex="-1"
             aria-hidden="true"></div>
        <?php endif; ?>

        <div class="ep-popup__container"
             style="<?php echo $container_style; ?>"
             tabindex="-1">

            <h2 id="<?php echo esc_attr($title_id); ?>" class="ep-sr-only">
                <?php echo esc_html($title !== '' ? $title : get_the_title($id)); ?>
            </h2>

            <button class="ep-popup__close"
                    data-ep-close="<?php echo $id; ?>"
                    aria-label="<?php esc_attr_e('Cerrar', 'estandar-pop'); ?>">&#x2715;</button>

            <div id="<?php echo esc_attr($body_id); ?>" class="ep-popup__body">
                <?php echo $content; ?>
            </div>
        </div>

        <?php if (!empty($scoped_css)): ?>
        <style><?php echo wp_strip_all_tags($scoped_css); ?></style>
        <?php endif; ?>

    </div>
    <?php
    // POPUP_SKIP_SUBMITTED_V1 (inline, NO va en el combined de SG): elimina el
    // popup del DOM si este navegador ya envió un form de captura (localStorage
    // es_fv2_done_* o cookie estandar_fv2_done_*). Como es un script inline en el
    // HTML fresco (no cacheado), funciona incluso si el motor JS viejo quedó en
    // caché del navegador: openPopup no encuentra el elemento y no abre nada.
    // SUBMITTED_TTL_V1: respeta la duración configurable; 0 = sin restricción.
    $ep_ttl_days = class_exists( '\Estandar\Capture\FormRenderer' ) ? \Estandar\Capture\FormRenderer::submitted_cookie_days() : 365;
    if ( $ep_ttl_days > 0 ) : ?>
    <script>(function(){try{
      var cutoff=Date.now()-<?php echo (int) $ep_ttl_days; ?>*86400000;var done=false;
      try{for(var i=0;i<localStorage.length;i++){var k=localStorage.key(i);if(!k||k.indexOf('es_fv2_done_')!==0)continue;if(/_t$/.test(k))continue;var ts=parseInt(localStorage.getItem(k+'_t')||'0',10);if(!ts||ts>=cutoff){done=true;break;}}}catch(e){}
      if(!done && document.cookie.indexOf('estandar_fv2_done_')!==-1) done=true;
      if(done){var el=document.getElementById('ep-popup-<?php echo (int) $id; ?>');if(el&&el.parentNode){el.parentNode.removeChild(el);}}
    }catch(e){}})();</script>
    <?php endif;
}
