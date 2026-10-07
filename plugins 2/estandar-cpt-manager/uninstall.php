<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'estandar_cpt_manager_defs' );
delete_option( 'estandar_producto_template_settings' );
delete_option( 'estandar_producto_template_post_id' );
delete_metadata( 'term', 0, '_estandar_category_featured_image_id', '', true );

$templates = get_posts(
    [
        // Current template singleton post type (<= 20 chars).
        'post_type'      => 'egm_prod_tpl',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]
);

foreach ( $templates as $template_id ) {
    wp_delete_post( (int) $template_id, true );
}

// Back-compat cleanup (older versions tried to use a too-long post_type and likely failed to insert,
// but keep this just in case the site's schema was customized).
$legacy_templates = get_posts(
    [
        'post_type'      => 'estandar_producto_template',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]
);

foreach ( $legacy_templates as $template_id ) {
    wp_delete_post( (int) $template_id, true );
}
