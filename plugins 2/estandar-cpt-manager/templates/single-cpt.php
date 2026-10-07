<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

while ( have_posts() ) : the_post();
    $categories = get_the_term_list( get_the_ID(), 'category', '', ', ' );
    $tags       = get_the_term_list( get_the_ID(), 'post_tag', '', ', ' );
?>
<main id="primary" class="site-main egm-cpt-single">

    <article <?php post_class(); ?>>

        <header class="entry-header" style="margin-bottom:20px;">
            <h1 class="entry-title" style="margin:0 0 12px;"><?php the_title(); ?></h1>
            <?php if ( $categories || $tags ) : ?>
                <div class="entry-meta" style="font-size:14px; color:rgba(0,0,0,0.7);">
                    <?php if ( $categories ) : ?>
                        <div><strong>Categorias:</strong> <?php echo wp_kses_post( $categories ); ?></div>
                    <?php endif; ?>
                    <?php if ( $tags ) : ?>
                        <div><strong>Etiquetas:</strong> <?php echo wp_kses_post( $tags ); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </header>

        <div class="egm-cpt-thumbnail" style="margin-bottom:20px; background:#f2f2f2; border-radius:14px; overflow:hidden;">
            <?php if ( has_post_thumbnail() ) : ?>
                <?php the_post_thumbnail( 'producto-large', [ 'style' => 'width:100%; height:auto; display:block;' ] ); ?>
            <?php else : ?>
                <div aria-hidden="true" style="min-height:220px; display:flex; align-items:center; justify-content:center; color:rgba(0,0,0,0.38);">
                    Sin imagen destacada
                </div>
            <?php endif; ?>
        </div>

        <div class="entry-content">
            <?php the_content(); ?>
        </div>

    </article>

</main>
<?php
endwhile;

get_footer();
