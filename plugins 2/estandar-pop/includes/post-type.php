<?php
defined('ABSPATH') || exit;

// ─── CPT ────────────────────────────────────────────────────────────────────

add_action('init', function () {
    register_post_type('estandar_pop', [
        'labels' => [
            'name'          => 'Popups',
            'singular_name' => 'Popup',
            'add_new_item'  => 'Nuevo Popup',
            'edit_item'     => 'Editar Popup',
            'not_found'     => 'No hay popups.',
        ],
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'show_in_rest'  => true,
        'has_archive'   => false,
        'supports'      => ['title', 'editor', 'revisions', 'custom-fields'],
        'menu_icon'     => 'dashicons-layout',
        'menu_position' => 30,
    ]);
});

// ─── META ────────────────────────────────────────────────────────────────────

add_action('init', function () {
    $defaults = [
        '_ep_active' => [
            'type'    => 'boolean',
            'default' => false,
        ],
        '_ep_open_method' => [
            'type'    => 'string',
            'default' => 'click',
            'enum'    => ['click', 'auto', 'manual'],
        ],
        '_ep_triggers' => [
            'type'    => 'string',
            'default' => '{"time":0,"scroll":0,"inactivity":0,"click_selector":""}',
        ],
        '_ep_segmentation' => [
            'type'    => 'string',
            'default' => '{"post_ids":[],"url_contains":[],"date_start":"","date_end":""}',
        ],
        '_ep_frequency' => [
            'type'    => 'string',
            'default' => '{"mode":"7days","days":0}',
        ],
        '_ep_design' => [
            'type'    => 'string',
            'default' => '{"size":"medium","custom_width":"","css_mode":"inherit","overlay":true,"overlay_color":"#000000","overlay_opacity":0.6,"animation":"fade","z_index":99999}',
        ],
        '_ep_custom_css'        => ['type' => 'string',  'default' => ''],
        '_ep_custom_css_scoped' => ['type' => 'string',  'default' => ''],
        '_ep_views'             => ['type' => 'integer', 'default' => 0],
        '_ep_opens'             => ['type' => 'integer', 'default' => 0],
        '_ep_clicks'            => ['type' => 'integer', 'default' => 0],
        '_ep_submits'           => ['type' => 'integer', 'default' => 0],
    ];

    foreach ($defaults as $key => $args) {
        register_post_meta('estandar_pop', $key, array_merge([
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => fn() => current_user_can('edit_posts'),
        ], $args));
    }
});

// ─── CSS SCOPER ──────────────────────────────────────────────────────────────

// Fires after REST API writes all meta — correct hook for Gutenberg saves
add_action('rest_after_insert_estandar_pop', function (WP_Post $post) {
    $raw = get_post_meta($post->ID, '_ep_custom_css', true);
    update_post_meta(
        $post->ID,
        '_ep_custom_css_scoped',
        $raw ? ep_scope_css($raw, $post->ID) : ''
    );
});

// Classic editor / programmatic saves (save_post fires before meta on REST, but covers non-REST)
add_action('save_post_estandar_pop', function (int $post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }
    $raw = get_post_meta($post_id, '_ep_custom_css', true);
    update_post_meta(
        $post_id,
        '_ep_custom_css_scoped',
        $raw ? ep_scope_css($raw, $post_id) : ''
    );
});

function ep_scope_css(string $css, int $id): string
{
    $prefix = "#ep-popup-{$id}";

    // Strip unsupported at-rules
    $css = preg_replace('/@(keyframes|font-face)[^{]*\{(?:[^{}]*\{[^}]*\})*\s*\}/s', '', $css);

    // Prefix a single selector string that may contain comma-separated parts
    $scope_selector = function (string $selector) use ($prefix): string {
        $parts = array_map('trim', explode(',', $selector));
        $scoped = array_map(fn($s) => $prefix . ' ' . $s, $parts);
        return implode(', ', $scoped);
    };

    // Step 1: extract @media blocks, process selectors inside them, replace with placeholder
    $placeholders = [];
    $css = preg_replace_callback(
        '/@media\s([^{]+)\{((?:[^{}]*\{[^}]*\}\s*)*)\}/s',
        function ($m) use ($prefix, $scope_selector, &$placeholders): string {
            $condition = trim($m[1]);
            $inner = preg_replace_callback(
                '/([^{}]+)\{([^}]*)\}/s',
                fn($r) => $scope_selector(trim($r[1])) . ' {' . $r[2] . '}',
                $m[2]
            );
            $key = '__EP_MEDIA_' . count($placeholders) . '__';
            $placeholders[$key] = "@media {$condition} {{$inner}}";
            return $key;
        },
        $css
    );

    // Step 2: prefix remaining non-@-rule selectors
    $css = preg_replace_callback(
        '/([^@{}\s][^{}]*)\{([^}]*)\}/s',
        fn($m) => $scope_selector(trim($m[1])) . ' {' . $m[2] . '}',
        $css
    );

    // Step 3: restore @media blocks
    $css = strtr($css, $placeholders);

    return trim($css);
}

// ─── SHORTCODE ───────────────────────────────────────────────────────────────

add_shortcode('ep_open', function (array $atts, string $content = ''): string {
    $atts = shortcode_atts(['id' => 0], $atts);
    $id   = intval($atts['id']);
    if (!$id) {
        return esc_html($content);
    }
    return '<a href="#" data-ep-open="' . esc_attr($id) . '">' . esc_html($content) . '</a>';
});

// ─── ANALYTICS ───────────────────────────────────────────────────────────────

add_action('wp_ajax_ep_track',        'ep_track_event');
add_action('wp_ajax_nopriv_ep_track', 'ep_track_event');

function ep_track_event(): void
{
    check_ajax_referer('ep_nonce', 'nonce');

    $id  = intval($_POST['popup_id'] ?? 0);
    $key = sanitize_key($_POST['counter'] ?? '');

    if (!$id || !in_array($key, ['views', 'opens', 'clicks', 'submits'], true)) {
        wp_die();
    }

    $meta = "_ep_{$key}";
    update_post_meta($id, $meta, intval(get_post_meta($id, $meta, true)) + 1);
    wp_die();
}

// ─── SIDEBAR (admin only) ─────────────────────────────────────────────────────

add_action('enqueue_block_editor_assets', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'estandar_pop') {
        return;
    }

    wp_enqueue_style(
        'ep-sidebar-v2',
        EP_URL . 'assets/css/sidebar-v2.css',
        [],
        EP_VERSION
    );

    wp_enqueue_script(
        'ep-sidebar',
        EP_URL . 'assets/js/sidebar.js',
        ['wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n'],
        EP_VERSION,
        true
    );
});
