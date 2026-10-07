<?php
/**
 * Category archive template for productos (only when visiting category with ?post_type=producto).
 *
 * This bypasses Astra's archive limitations and renders a block-based template stored in the plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Add a body class so we can safely override Astra's container only on this template.
add_filter(
    'body_class',
    static function ( $classes ) {
        $classes[] = 'egm-cat-producto-template';
        return $classes;
    }
);

get_header();

echo '<main id="primary" class="site-main">';

if ( class_exists( 'Estandar_CPT_Manager' ) && method_exists( 'Estandar_CPT_Manager', 'render_category_archive_page' ) ) {
    Estandar_CPT_Manager::render_category_archive_page();
} else {
    // Fallback: default loop.
    if ( have_posts() ) {
        while ( have_posts() ) {
            the_post();
            the_title( '<h2>', '</h2>' );
        }
    }
}

echo '</main>';

get_footer();
