<?php
/**
 * Horizontal Parallax Gallery — server render.
 *
 * @package Estandar\FX
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\FX\Blocks::enqueue_for( 'estandar/horizontal-parallax-gallery' );

$efx_slides = is_array( $attributes['slides'] ?? null ) ? $attributes['slides'] : array();
$efx_slides = array_values( array_filter( $efx_slides, function ( $slide ) { return is_array( $slide ) && ! empty( $slide['url'] ) && is_string( $slide['url'] ); } ) );
$efx_height = max( 45, min( 100, (int) ( $attributes['height'] ?? 78 ) ) );
$efx_width = max( 180, min( 720, (int) ( $attributes['imageWidth'] ?? 360 ) ) );
$efx_gap = max( 0, min( 90, (int) ( $attributes['gap'] ?? 28 ) ) );
$efx_speed = max( 0.2, min( 2.5, (float) ( $attributes['speed'] ?? 1 ) ) );
$efx_depth = max( 0, min( 2.5, (float) ( $attributes['depth'] ?? 1 ) ) );
$efx_hero = ! array_key_exists( 'heroMode', $attributes ) || ! empty( $attributes['heroMode'] );
$efx_show_captions = ! array_key_exists( 'showCaptions', $attributes ) || ! empty( $attributes['showCaptions'] );

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-horizontal-parallax-gallery' . ( $efx_hero ? ' is-hero' : '' ),
		'style' => sprintf( '--efx-hpg-height:%dvh;--efx-hpg-width:%dpx;--efx-hpg-gap:%dpx;--efx-hpg-speed:%s;--efx-hpg-depth:%s;', $efx_height, $efx_width, $efx_gap, esc_attr( (string) $efx_speed ), esc_attr( (string) $efx_depth ) ),
	)
);

if ( empty( $efx_slides ) ) : ?>
	<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><div class="efx-hpg-empty"><?php esc_html_e( 'Agrega imágenes para construir la galería horizontal.', 'estandar-experience-blocks' ); ?></div></div>
	<?php return; endif; ?>
<section <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-efx-horizontal-parallax-gallery>
	<div class="efx-hpg-stage">
		<div class="efx-hpg-track" role="list" aria-label="<?php esc_attr_e( 'Galería horizontal parallax', 'estandar-experience-blocks' ); ?>">
			<?php foreach ( $efx_slides as $efx_index => $efx_slide ) : ?>
				<?php $efx_title = isset( $efx_slide['title'] ) && is_string( $efx_slide['title'] ) ? trim( $efx_slide['title'] ) : ''; $efx_alt = isset( $efx_slide['alt'] ) && is_string( $efx_slide['alt'] ) ? $efx_slide['alt'] : $efx_title; ?>
				<figure class="efx-hpg-item" role="listitem" style="--efx-hpg-i:<?php echo esc_attr( (string) $efx_index ); ?>;">
					<img src="<?php echo esc_url( $efx_slide['url'] ); ?>" alt="<?php echo esc_attr( $efx_alt ); ?>" loading="lazy" decoding="async" />
					<?php if ( $efx_show_captions && '' !== $efx_title ) : ?><figcaption><?php echo esc_html( $efx_title ); ?></figcaption><?php endif; ?>
				</figure>
			<?php endforeach; ?>
		</div>
	</div>
</section>
