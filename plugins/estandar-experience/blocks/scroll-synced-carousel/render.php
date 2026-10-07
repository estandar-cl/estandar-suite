<?php
/**
 * Scroll Synced Carousel — server render.
 *
 * @package Estandar\Experience
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\Experience\Blocks::enqueue_for( 'estandar/scroll-synced-carousel' );

$efx_slides = is_array( $attributes['slides'] ?? null ) ? $attributes['slides'] : array();
$efx_slides = array_values( array_filter( $efx_slides, function ( $slide ) { return is_array( $slide ) && ! empty( $slide['url'] ) && is_string( $slide['url'] ); } ) );
$efx_height = max( 60, min( 120, (int) ( $attributes['height'] ?? 92 ) ) );
$efx_width = max( 220, min( 720, (int) ( $attributes['cardWidth'] ?? 420 ) ) );
$efx_gap = max( 0, min( 80, (int) ( $attributes['gap'] ?? 26 ) ) );
$efx_accent = is_string( $attributes['accentColor'] ?? null ) ? $attributes['accentColor'] : '#f97316';
$efx_show_progress = ! array_key_exists( 'showProgress', $attributes ) || ! empty( $attributes['showProgress'] );
$efx_snap = ! empty( $attributes['snap'] );

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-scroll-synced-carousel' . ( $efx_snap ? ' has-snap' : '' ),
		'style' => sprintf( '--efx-ssc-height:%dvh;--efx-ssc-card:%dpx;--efx-ssc-gap:%dpx;--efx-ssc-accent:%s;', $efx_height, $efx_width, $efx_gap, esc_attr( $efx_accent ) ),
	)
);

if ( empty( $efx_slides ) ) : ?>
	<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><div class="efx-ssc-empty"><?php esc_html_e( 'Agrega imágenes para construir el carrusel sincronizado.', 'estandar-experience-blocks' ); ?></div></div>
	<?php return; endif; ?>
<section <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-efx-scroll-synced-carousel>
	<div class="efx-ssc-sticky">
		<?php if ( $efx_show_progress ) : ?><div class="efx-ssc-progress" aria-hidden="true"><span></span></div><?php endif; ?>
		<div class="efx-ssc-copy"><span class="efx-ssc-count">01 / <?php echo esc_html( str_pad( (string) count( $efx_slides ), 2, '0', STR_PAD_LEFT ) ); ?></span><h2><?php esc_html_e( 'Scroll synced carousel', 'estandar-experience-blocks' ); ?></h2></div>
		<div class="efx-ssc-track" role="list" aria-label="<?php esc_attr_e( 'Carrusel sincronizado con scroll', 'estandar-experience-blocks' ); ?>">
			<?php foreach ( $efx_slides as $efx_index => $efx_slide ) : ?>
				<?php $efx_title = isset( $efx_slide['title'] ) && is_string( $efx_slide['title'] ) ? trim( $efx_slide['title'] ) : ''; $efx_alt = isset( $efx_slide['alt'] ) && is_string( $efx_slide['alt'] ) ? $efx_slide['alt'] : $efx_title; ?>
				<figure class="efx-ssc-slide" role="listitem" data-index="<?php echo esc_attr( (string) $efx_index ); ?>"><img src="<?php echo esc_url( $efx_slide['url'] ); ?>" alt="<?php echo esc_attr( $efx_alt ); ?>" loading="lazy" decoding="async" /><?php if ( '' !== $efx_title ) : ?><figcaption><?php echo esc_html( $efx_title ); ?></figcaption><?php endif; ?></figure>
			<?php endforeach; ?>
		</div>
	</div>
</section>
