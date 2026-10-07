<?php
defined('ABSPATH') || exit;

// ─── QUERY & CACHE ───────────────────────────────────────────────────────────

function ep_get_applicable_popups(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $all = get_posts([
        'post_type'      => 'estandar_pop',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => '_ep_active', 'value' => '1']],
        'no_found_rows'  => true,
        'fields'         => 'all',
    ]);

    $cache = array_values(array_filter($all, fn($p) => ep_popup_applies($p->ID)));
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
    $by_id = [];

    foreach (array_merge(ep_get_applicable_popups(), ep_get_block_popups()) as $popup) {
        $by_id[$popup->ID] = $popup;
    }

    return array_values($by_id);
}

function ep_popup_applies(int $post_id): bool
{
    $seg = json_decode(get_post_meta($post_id, '_ep_segmentation', true), true) ?: [];
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
        $uri = $_SERVER['REQUEST_URI'];
        foreach ($seg['url_contains'] as $pattern) {
            if (!empty($pattern) && str_contains($uri, $pattern)) {
                return true;
            }
        }
    }

    return false;
}

// ─── ENQUEUE ─────────────────────────────────────────────────────────────────

add_action('wp_enqueue_scripts', function () {
    $popups = ep_get_frontend_popups();
    if (is_admin() || empty($popups)) {
        return;
    }

    wp_enqueue_script('ep-engine', EP_URL . 'assets/js/engine.js', [], EP_VERSION, true);
    wp_enqueue_style('ep-popup',   EP_URL . 'assets/css/popup.css', [], EP_VERSION);

    $payload = array_map(function ($popup) {
        return [
            'id'        => $popup->ID,
            'name'      => $popup->post_title,
            'triggers'  => json_decode(get_post_meta($popup->ID, '_ep_triggers',   true), true) ?: [],
            'frequency' => json_decode(get_post_meta($popup->ID, '_ep_frequency',  true), true) ?: [],
            'design'    => json_decode(get_post_meta($popup->ID, '_ep_design',     true), true) ?: [],
        ];
    }, $popups);

    wp_localize_script('ep-engine', 'epConfig', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('ep_nonce'),
        'popups'  => array_values($payload),
    ]);
}, 20);

// ─── RENDER ──────────────────────────────────────────────────────────────────

add_action('wp_footer', function () {
    $popups = ep_get_frontend_popups();
    if (empty($popups)) {
        return;
    }

    foreach ($popups as $popup) {
        $design     = json_decode(get_post_meta($popup->ID, '_ep_design', true), true) ?: [];
        $scoped_css = get_post_meta($popup->ID, '_ep_custom_css_scoped', true);
        $content    = apply_filters('the_content', $popup->post_content);

        ep_render_shell($popup->ID, $content, $design, $scoped_css);
    }
});

function ep_render_shell(int $id, string $content, array $design, string $scoped_css): void
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
                <?php echo esc_html(get_the_title($id)); ?>
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
}
