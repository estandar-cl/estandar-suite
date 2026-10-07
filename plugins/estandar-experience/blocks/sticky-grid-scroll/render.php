<?php
/**
 * Sticky Grid Scroll — server render.
 *
 * @package Estandar\Experience
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\Experience\Blocks::enqueue_for( 'estandar/sticky-grid-scroll' );

$efx_slides = is_array( $attributes['slides'] ?? null ) ? $attributes['slides'] : array();
$efx_slides = array_values(
	array_filter(
		$efx_slides,
		function ( $slide ) {
			return is_array( $slide ) && ! empty( $slide['url'] ) && is_string( $slide['url'] );
		}
	)
);

$efx_columns = max( 2, min( 6, (int) ( $attributes['columns'] ?? 4 ) ) );
$efx_height = max( 120, min( 320, (int) ( $attributes['height'] ?? 180 ) ) );
$efx_gap = max( 0, min( 60, (int) ( $attributes['gap'] ?? 18 ) ) );
$efx_radius = max( 0, min( 80, (int) ( $attributes['radius'] ?? 22 ) ) );
$efx_travel = max( 40, min( 420, (int) ( $attributes['travel'] ?? 180 ) ) );
$efx_show_captions = ! empty( $attributes['showCaptions'] );

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-sticky-grid-scroll',
		'style' => sprintf(
			'--efx-sgs-columns:%d;--efx-sgs-height:%dpx;--efx-sgs-gap:%dpx;--efx-sgs-radius:%dpx;--efx-sgs-travel:%dpx;',
			$efx_columns,
			$efx_height,
			$efx_gap,
			$efx_radius,
			$efx_travel
		),
	)
);

if ( empty( $efx_slides ) ) :
	?>
	<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?>>
		<div class="efx-sgs-empty"><?php esc_html_e( 'Agrega imágenes para construir la grilla sticky.', 'estandar-experience-blocks' ); ?></div>
	</div>
	<?php
	return;
endif;
?>
<section <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?> data-efx-sticky-grid-scroll>
	<div class="efx-sgs-sticky">
		<div class="efx-sgs-grid" role="list" aria-label="<?php esc_attr_e( 'Grilla sticky de imágenes', 'estandar-experience-blocks' ); ?>">
			<?php foreach ( $efx_slides as $efx_index => $efx_slide ) : ?>
				<?php
				$efx_title = isset( $efx_slide['title'] ) && is_string( $efx_slide['title'] ) ? trim( $efx_slide['title'] ) : '';
				$efx_alt = isset( $efx_slide['alt'] ) && is_string( $efx_slide['alt'] ) ? $efx_slide['alt'] : $efx_title;
				$efx_direction = 0 === $efx_index % 2 ? 1 : -1;
				?>
				<figure class="efx-sgs-item" role="listitem" style="--efx-sgs-dir:<?php echo esc_attr( (string) $efx_direction ); ?>;">
					<img src="<?php echo esc_url( $efx_slide['url'] ); ?>" alt="<?php echo esc_attr( $efx_alt ); ?>" loading="lazy" decoding="async" />
					<?php if ( $efx_show_captions && '' !== $efx_title ) : ?><figcaption><?php echo esc_html( $efx_title ); ?></figcaption><?php endif; ?>
				</figure>
			<?php endforeach; ?>
		</div>
	</div>
</section>
