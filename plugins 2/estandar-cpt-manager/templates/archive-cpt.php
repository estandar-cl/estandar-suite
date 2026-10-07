<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

$post_type = get_query_var( 'post_type' );
if ( is_array( $post_type ) ) {
    $post_type = reset( $post_type );
}
$post_type = is_string($post_type) ? $post_type : '';
$pt_obj    = $post_type ? get_post_type_object( $post_type ) : null;

$title = $pt_obj && ! empty( $pt_obj->labels->name ) ? $pt_obj->labels->name : 'Archivo';

// Optional: show a per-post "subtitle" under the title (e.g. marketing name).
// This reads from EGM template fields meta (`_egm_tpl_fields`) using a key like `hero_title`.
// You can override which key is shown (or disable it) from theme or another plugin:
// add_filter('egm_archive_card_subtitle_key', function($key, $post_type){ return 'hero_title'; }, 10, 2);
// add_filter('egm_archive_card_subtitle_key', '__return_empty_string', 10, 2);
$egm_archive_card_subtitle_key = apply_filters( 'egm_archive_card_subtitle_key', 'hero_title', $post_type );
$egm_archive_card_subtitle_key = sanitize_key( (string) $egm_archive_card_subtitle_key );

function egm_archive_resolve_subtitle_value( int $post_id, string $key ): string {
    $key = sanitize_key( $key );
    if ( '' === $key ) {
        return '';
    }

    // Primary source: EGM dynamic template fields store.
    $fields = get_post_meta( $post_id, '_egm_tpl_fields', true );
    $fields = is_array( $fields ) ? $fields : [];

    if ( array_key_exists( $key, $fields ) ) {
        $v = $fields[ $key ];

        if ( is_array( $v ) ) {
            foreach ( [ 'text', 'content', 'value', 'label', 'title' ] as $k ) {
                if ( isset( $v[ $k ] ) && is_scalar( $v[ $k ] ) ) {
                    return trim( (string) $v[ $k ] );
                }
            }
            return '';
        }

        if ( is_scalar( $v ) ) {
            return trim( (string) $v );
        }

        return '';
    }

    // Fallback: allow direct post meta key (useful for CPTs not using the EGM template-fields UI).
    $direct = get_post_meta( $post_id, $key, true );
    if ( is_scalar( $direct ) ) {
        return trim( (string) $direct );
    }

    return '';
}

?>
<?php if ( '' !== $post_type ) : ?>
	    <style id="egm-archive-header-logo-fix">
	        /* Archive-only fix: correct logo vertical alignment without changing header grid/menu layout. */
	        body.post-type-archive-<?php echo esc_html( sanitize_html_class( $post_type ) ); ?> .site-header .custom-logo-link,
	        body.post-type-archive-<?php echo esc_html( sanitize_html_class( $post_type ) ); ?> .site-header .site-logo a {
	            display: inline-flex !important;
	            align-items: center !important;
	            line-height: 0 !important;
	            margin-top: 0 !important;
	            margin-bottom: 0 !important;
	            top: auto !important;
	            transform: none !important;
	        }
        body.post-type-archive-<?php echo esc_html( sanitize_html_class( $post_type ) ); ?> .site-header .custom-logo,
        body.post-type-archive-<?php echo esc_html( sanitize_html_class( $post_type ) ); ?> .site-header .custom-logo-link img {
            display: block;
        }
    </style>
<?php endif; ?>
<style id="egm-archive-cpt-css">
    .egm-cpt-archive .egm-archive-header { margin-bottom: 26px; }
    .egm-cpt-archive .egm-archive-header h1 { margin: 0; }
    .egm-cpt-archive .egm-archive-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; }
    .egm-cpt-archive .egm-archive-card { display: block; padding: 16px; border: 1px solid rgba(0,0,0,0.08); border-radius: 14px; text-decoration: none; }
    .egm-cpt-archive .egm-archive-card-title { margin: 0 0 10px; font-size: 18px; }
    .egm-cpt-archive .egm-archive-card-subtitle { margin: 0 0 10px; font-size: 13px; color: rgba(0,0,0,0.62); }
    .egm-cpt-archive .egm-archive-card-subtitle-img { max-width: 100%; height: auto; display: block; border-radius: 10px; }
    .egm-cpt-archive .egm-archive-card-media { margin: 0 0 10px; aspect-ratio: 3/2; background: #f2f2f2; border-radius: 10px; overflow: hidden; }
    .egm-cpt-archive .egm-archive-card-media .egm-zoom-image { width: 100%; height: 100%; object-fit: cover; display: block; }
    .egm-cpt-archive .egm-archive-card-media-empty { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: rgba(0,0,0,0.38); font-size: 13px; }
    .egm-cpt-archive .egm-archive-card-tax { margin: 0 0 10px; font-size: 13px; color: rgba(0,0,0,0.6); }
    .egm-cpt-archive .egm-archive-card-excerpt { color: rgba(0,0,0,0.72); }
    .egm-cpt-archive .egm-archive-pagination { margin-top: 28px; }
</style>
<main id="primary" class="site-main egm-cpt-archive egm-zoom-scope" data-egm-hover-zoom="8" data-egm-scroll-zoom="1" data-egm-scroll-zoom-percent="10">
    <header class="egm-archive-header">
        <h1><?php echo esc_html( $title ); ?></h1>
    </header>

    <?php if ( have_posts() ) : ?>
        <div class="egm-archive-grid">
            <?php while ( have_posts() ) : the_post(); ?>
                <?php
                $categories = get_the_term_list( get_the_ID(), 'category', '', ', ' );
                $tags       = get_the_term_list( get_the_ID(), 'post_tag', '', ', ' );
                ?>
                <a href="<?php the_permalink(); ?>" class="egm-zoom-card egm-archive-card">
                    <h2 class="egm-archive-card-title"><?php the_title(); ?></h2>
                    <?php
                    $egm_subtitle_html = '';
                    if ( '' !== $egm_archive_card_subtitle_key ) {
                        $egm_val = egm_archive_resolve_subtitle_value( (int) get_the_ID(), $egm_archive_card_subtitle_key );
                        $egm_title_cmp = trim( wp_strip_all_tags( get_the_title() ) );

                        $egm_val_str = trim( (string) $egm_val );
                        $egm_img_id  = absint( $egm_val_str );
                        if ( $egm_img_id && wp_attachment_is_image( $egm_img_id ) ) {
                            $egm_subtitle_html = wp_get_attachment_image(
                                $egm_img_id,
                                'thumbnail',
                                false,
                                [
                                    'class'    => 'egm-archive-card-subtitle-img',
                                    'alt'      => '',
                                    'loading'  => 'lazy',
                                    'decoding' => 'async',
                                ]
                            );
                        } elseif ( '' !== $egm_val_str ) {
                            // Never show the literal key name, and avoid duplicating the title.
                            if ( 0 !== strcasecmp( $egm_val_str, $egm_archive_card_subtitle_key ) && 0 !== strcasecmp( $egm_val_str, $egm_title_cmp ) ) {
                                $egm_subtitle_html = esc_html( $egm_val_str );
                            }
                        }

                        $egm_subtitle_html = apply_filters(
                            'egm_archive_card_subtitle_html',
                            $egm_subtitle_html,
                            $egm_val_str,
                            $egm_archive_card_subtitle_key,
                            (int) get_the_ID(),
                            $post_type
                        );
                    }
                    ?>
                    <?php if ( '' !== $egm_subtitle_html ) : ?>
                        <div class="egm-archive-card-subtitle">
                            <?php echo wp_kses_post( $egm_subtitle_html ); ?>
                        </div>
                    <?php endif; ?>
                    <div class="egm-archive-card-media egm-zoom-hover">
                        <?php if ( has_post_thumbnail() ) : ?>
                            <?php the_post_thumbnail( 'producto-thumb', [ 'class' => 'egm-zoom-image', 'loading' => 'lazy', 'decoding' => 'async' ] ); ?>
                        <?php else : ?>
                            <div aria-hidden="true" class="egm-archive-card-media-empty">
                                Sin imagen
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ( $categories || $tags ) : ?>
                        <div class="egm-archive-card-tax">
                            <?php if ( $categories ) : ?>
                                <div><strong>Categorias:</strong> <?php echo wp_kses_post( $categories ); ?></div>
                            <?php endif; ?>
                            <?php if ( $tags ) : ?>
                                <div><strong>Etiquetas:</strong> <?php echo wp_kses_post( $tags ); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="egm-archive-card-excerpt">
                        <?php echo wp_kses_post( wpautop( wp_trim_words( get_the_excerpt() ?: wp_strip_all_tags( get_the_content() ), 22 ) ) ); ?>
                    </div>
                </a>
            <?php endwhile; ?>
        </div>

        <div class="egm-archive-pagination">
            <?php the_posts_pagination(); ?>
        </div>

    <?php else : ?>
        <p>No hay contenidos aún.</p>
    <?php endif; ?>
</main>
<?php
get_footer();
