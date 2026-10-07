<?php
defined('ABSPATH') || exit;

// ─── COLUMNS ─────────────────────────────────────────────────────────────────

add_filter('manage_estandar_pop_posts_columns', function (array $cols): array {
    $new = [];
    foreach ($cols as $key => $label) {
        $new[$key] = $label;
        if ($key === 'title') {
            $new['ep_status']    = 'Estado';
            $new['ep_method']    = 'Método';
            $new['ep_triggers']  = 'Triggers';
            $new['ep_frequency'] = 'Frecuencia';
            $new['ep_views']     = 'Vistas';
            $new['ep_opens']     = 'Aperturas';
        }
    }
    unset($new['date']);
    return $new;
});

add_action('manage_estandar_pop_posts_custom_column', function (string $col, int $post_id): void {
    switch ($col) {
        case 'ep_status':
            $active = get_post_meta($post_id, '_ep_active', true);
            $dot    = $active ? '#46b450' : '#999';
            $label  = $active ? 'Activo' : 'Inactivo';
            echo "<span style='color:{$dot};font-size:18px;line-height:1;vertical-align:middle;'>●</span> {$label}";
            break;

        case 'ep_method':
            $method = get_post_meta($post_id, '_ep_open_method', true) ?: 'click';
            $labels = [
                'click'  => '👆 Click',
                'auto'   => '⏱️ Automático',
                'manual' => '⚙️ Manual',
            ];
            echo esc_html($labels[$method] ?? 'Desconocido');
            break;

        case 'ep_triggers':
            $t      = json_decode(get_post_meta($post_id, '_ep_triggers', true), true) ?: [];
            $labels = [];
            if (!empty($t['time']))           $labels[] = 'Tiempo';
            if (!empty($t['scroll']))         $labels[] = 'Scroll';
            if (!empty($t['inactivity']))     $labels[] = 'Inactividad';
            if (!empty($t['click_selector'])) $labels[] = 'Click';
            echo $labels ? esc_html(implode(', ', $labels)) : '<span style="color:#999;">—</span>';
            break;

        case 'ep_frequency':
            $f   = json_decode(get_post_meta($post_id, '_ep_frequency', true), true) ?: [];
            $map = [
                'always'   => 'Siempre',
                'session'  => 'Sesión',
                '1day'     => '1 día',
                '7days'    => '7 días',
                '30days'   => '30 días',
                'once_ever' => 'Una vez',
                'custom'   => (intval($f['days'] ?? 0)) . ' días',
            ];
            $mode = $f['mode'] ?? '7days';
            echo esc_html($map[$mode] ?? '—');
            break;

        case 'ep_views':
            echo intval(get_post_meta($post_id, '_ep_views', true));
            break;

        case 'ep_opens':
            echo intval(get_post_meta($post_id, '_ep_opens', true));
            break;
    }
}, 10, 2);

// ─── DUPLICATE ───────────────────────────────────────────────────────────────

add_filter('post_row_actions', function (array $actions, WP_Post $post): array {
    if ($post->post_type !== 'estandar_pop') {
        return $actions;
    }
    $url = wp_nonce_url(
        admin_url("admin-post.php?action=ep_duplicate&post={$post->ID}"),
        "ep_duplicate_{$post->ID}"
    );
    $actions['ep_duplicate'] = '<a href="' . esc_url($url) . '">Duplicar</a>';
    return $actions;
}, 10, 2);

add_action('admin_post_ep_duplicate', function (): void {
    $post_id = intval($_GET['post'] ?? 0);

    check_admin_referer("ep_duplicate_{$post_id}");

    if (!$post_id || !current_user_can('edit_posts')) {
        wp_die('Sin permiso.');
    }

    $original = get_post($post_id);
    if (!$original || $original->post_type !== 'estandar_pop') {
        wp_die('Popup no encontrado.');
    }

    $new_id = wp_insert_post([
        'post_title'   => $original->post_title . ' (copia)',
        'post_content' => $original->post_content,
        'post_status'  => 'draft',
        'post_type'    => 'estandar_pop',
    ]);

    if (is_wp_error($new_id)) {
        wp_die($new_id->get_error_message());
    }

    $meta_keys = [
        '_ep_triggers', '_ep_segmentation', '_ep_frequency',
        '_ep_design', '_ep_custom_css', '_ep_custom_css_scoped',
        '_ep_hide_if_submitted',
    ];

    foreach ($meta_keys as $key) {
        $value = get_post_meta($post_id, $key, true);
        if ($value !== '') {
            update_post_meta($new_id, $key, $value);
        }
    }

    // Duplicate starts inactive regardless of original
    update_post_meta($new_id, '_ep_active', false);

    wp_redirect(admin_url("post.php?post={$new_id}&action=edit"));
    exit;
});

// ─── POPUP_SKIP_SUBMITTED_V1: metabox "no abrir a quienes ya enviaron" ───────

add_action('add_meta_boxes', function (): void {
    add_meta_box(
        'ep_skip_submitted_box',
        'Estandar Popup — Captura',
        function (WP_Post $post): void {
            $checked = get_post_meta($post->ID, '_ep_hide_if_submitted', true) === '1';
            wp_nonce_field('ep_skip_submitted_' . $post->ID, '_ep_skip_nonce');
            echo '<label style="display:block;margin:6px 0;">'
                . '<input type="checkbox" name="_ep_hide_if_submitted" value="1" ' . checked($checked, true, false) . '> '
                . 'No abrir en navegadores que ya enviaron sus datos (el popup contiene un formulario de captura).'
                . '</label>'
                . '<p class="description">Si este popup embebe <code>[estandar_form_v2 id="N"]</code>, la persona que ya envió ese form no volverá a verlo.</p>';
        },
        'estandar_pop',
        'side',
        'default'
    );
});

add_action('save_post_estandar_pop', function (int $post_id): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!isset($_POST['_ep_skip_nonce']) || !wp_verify_nonce(sanitize_key((string) $_POST['_ep_skip_nonce']), 'ep_skip_submitted_' . $post_id)) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    $value = isset($_POST['_ep_hide_if_submitted']) && (int) $_POST['_ep_hide_if_submitted'] === 1 ? '1' : '0';
    update_post_meta($post_id, '_ep_hide_if_submitted', $value);
});
