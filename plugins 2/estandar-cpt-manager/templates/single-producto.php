<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

while ( have_posts() ) :
    the_post();
    $template_post_id = 0;
    if ( class_exists( 'Estandar_CPT_Manager' ) && method_exists( 'Estandar_CPT_Manager', 'get_selected_product_template_id' ) ) {
        $template_post_id = (int) Estandar_CPT_Manager::get_selected_product_template_id( (int) get_the_ID() );
    }

    $template_post = $template_post_id ? get_post( (int) $template_post_id ) : null;
    $content       = ( $template_post && isset( $template_post->post_content ) ) ? (string) $template_post->post_content : '';
    ?>
    <main id="primary" class="site-main egm-product-page-wrapper">
        <?php
        if ( $content ) {
            // Render template blocks in the context of the current producto post.
            echo apply_filters( 'the_content', $content );
        } else {
            the_content();
        }
        ?>
    </main>
    <?php
endwhile;

get_footer();
