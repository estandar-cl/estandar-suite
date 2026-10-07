<?php
/**
 * Magnetic Carousel — server render.
 *
 * @package Estandar\FX
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\FX\Blocks::enqueue_for( 'estandar/magnetic-carousel' );

$efx_slides = is_array( $attributes['slides'] ?? null ) ? $attributes['slides'] : array();
$efx_slides = array_values(
	array_filter(
		$efx_slides,
		function ( $slide ) {
			return is_array( $slide ) && ! empty( $slide['url'] ) && is_string( $slide['url'] );
		}
	)
);

$efx_collapsed = max( 36, min( 220, (int) ( $attributes['collapsedWidth'] ?? 86 ) ) );
$efx_hover     = max( $efx_collapsed, min( 420, (int) ( $attributes['hoverWidth'] ?? 210 ) ) );
$efx_height    = max( 160, min( 720, (int) ( $attributes['height'] ?? 360 ) ) );
$efx_gap       = max( 0, min( 60, (int) ( $attributes['gap'] ?? 14 ) ) );
$efx_influence = max( 60, min( 520, (int) ( $attributes['influence'] ?? 220 ) ) );
$efx_radius    = max( 0, min( 80, (int) ( $attributes['radius'] ?? 24 ) ) );
$efx_blur      = max( 0, min( 10, (int) ( $attributes['blur'] ?? 2 ) ) );
$efx_show_titles = ! array_key_exists( 'showTitles', $attributes ) || ! empty( $attributes['showTitles'] );

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-magnetic-carousel',
		'style' => sprintf(
			'--efx-mc-collapsed:%dpx;--efx-mc-hover:%dpx;--efx-mc-height:%dpx;--efx-mc-gap:%dpx;--efx-mc-influence:%dpx;--efx-mc-radius:%dpx;--efx-mc-blur:%dpx;',
			$efx_collapsed,
			$efx_hover,
			$efx_height,
			$efx_gap,
			$efx_influence,
			$efx_radius,
			$efx_blur
		),
	)
);

if ( empty( $efx_slides ) ) :
	?>
	<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?>>
		<div class="efx-mc-empty"><?php esc_html_e( 'Agrega imágenes para construir el carrusel magnético.', 'estandar-experience-blocks' ); ?></div>
	</div>
	<?php
	return;
endif;
?>
<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?> data-efx-magnetic-carousel>
	<div class="efx-mc-track" role="list" aria-label="<?php esc_attr_e( 'Carrusel magnético', 'estandar-experience-blocks' ); ?>">
		<?php foreach ( $efx_slides as $efx_index => $efx_slide ) : ?>
			<?php
			$efx_title = isset( $efx_slide['title'] ) && is_string( $efx_slide['title'] ) ? trim( $efx_slide['title'] ) : '';
			$efx_alt   = isset( $efx_slide['alt'] ) && is_string( $efx_slide['alt'] ) ? $efx_slide['alt'] : $efx_title;
			?>
			<button class="efx-mc-item" type="button" role="listitem" data-index="<?php echo esc_attr( (string) $efx_index ); ?>" aria-label="<?php echo esc_attr( '' !== $efx_title ? $efx_title : sprintf( __( 'Imagen %d', 'estandar-experience-blocks' ), $efx_index + 1 ) ); ?>">
				<img src="<?php echo esc_url( $efx_slide['url'] ); ?>" alt="<?php echo esc_attr( $efx_alt ); ?>" loading="lazy" decoding="async" />
				<?php if ( $efx_show_titles && '' !== $efx_title ) : ?>
					<span class="efx-mc-title"><?php echo esc_html( $efx_title ); ?></span>
				<?php endif; ?>
			</button>
		<?php endforeach; ?>
	</div>
</div>
