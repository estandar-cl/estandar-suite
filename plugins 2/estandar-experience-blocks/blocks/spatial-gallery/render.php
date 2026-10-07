<?php
/**
 * Spatial Gallery — server render.
 *
 * @package Estandar\FX
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\FX\Blocks::enqueue_for( 'estandar/spatial-gallery' );

$efx_slides = is_array( $attributes['slides'] ?? null ) ? $attributes['slides'] : array();
$efx_slides = array_values(
	array_filter(
		$efx_slides,
		function ( $slide ) {
			return is_array( $slide ) && ! empty( $slide['url'] ) && is_string( $slide['url'] );
		}
	)
);

$efx_height        = max( 420, min( 1200, (int) ( $attributes['height'] ?? 720 ) ) );
$efx_image_width   = max( 160, min( 520, (int) ( $attributes['imageWidth'] ?? 280 ) ) );
$efx_spread        = max( 0.5, min( 1.8, (float) ( $attributes['spread'] ?? 1 ) ) );
$efx_focus_scale   = max( 1.0, min( 1.7, (float) ( $attributes['focusScale'] ?? 1.18 ) ) );
$efx_show_captions = ! array_key_exists( 'showCaptions', $attributes ) || ! empty( $attributes['showCaptions'] );
$efx_show_index    = ! array_key_exists( 'showIndex', $attributes ) || ! empty( $attributes['showIndex'] );

$efx_positions = array(
	array( 9, 15, -7 ),
	array( 63, 10, 5 ),
	array( 35, 39, -3 ),
	array( 72, 51, -9 ),
	array( 15, 59, 7 ),
	array( 48, 70, 3 ),
);

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-spatial-gallery',
		'style' => sprintf(
			'--efx-sg-height:%dpx;--efx-sg-width:%dpx;--efx-sg-spread:%s;--efx-sg-focus:%s;',
			$efx_height,
			$efx_image_width,
			esc_attr( (string) $efx_spread ),
			esc_attr( (string) $efx_focus_scale )
		),
	)
);

if ( empty( $efx_slides ) ) :
	?>
	<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?>>
		<div class="efx-sg-empty"><?php esc_html_e( 'Agrega imágenes para construir la galería espacial.', 'estandar-experience-blocks' ); ?></div>
	</div>
	<?php
	return;
endif;
?>
<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?> data-efx-spatial-gallery>
	<?php if ( $efx_show_index ) : ?>
		<div class="efx-sg-index" aria-hidden="true">01 / <?php echo esc_html( str_pad( (string) count( $efx_slides ), 2, '0', STR_PAD_LEFT ) ); ?></div>
	<?php endif; ?>

	<div class="efx-sg-stage" role="list" aria-label="<?php esc_attr_e( 'Galería espacial', 'estandar-experience-blocks' ); ?>">
		<?php foreach ( $efx_slides as $efx_index => $efx_slide ) : ?>
			<?php
			$efx_title = isset( $efx_slide['title'] ) && is_string( $efx_slide['title'] ) ? trim( $efx_slide['title'] ) : '';
			$efx_alt   = isset( $efx_slide['alt'] ) && is_string( $efx_slide['alt'] ) ? $efx_slide['alt'] : $efx_title;
			$efx_pos   = $efx_positions[ $efx_index % count( $efx_positions ) ];
			?>
			<figure class="efx-sg-item" role="listitem" tabindex="0" data-index="<?php echo esc_attr( (string) $efx_index ); ?>" style="--efx-sg-x:<?php echo esc_attr( (string) $efx_pos[0] ); ?>%;--efx-sg-y:<?php echo esc_attr( (string) $efx_pos[1] ); ?>%;--efx-sg-r:<?php echo esc_attr( (string) $efx_pos[2] ); ?>deg;">
				<img src="<?php echo esc_url( $efx_slide['url'] ); ?>" alt="<?php echo esc_attr( $efx_alt ); ?>" loading="lazy" decoding="async" />
				<?php if ( $efx_show_captions && '' !== $efx_title ) : ?>
					<figcaption><?php echo esc_html( $efx_title ); ?></figcaption>
				<?php endif; ?>
			</figure>
		<?php endforeach; ?>
	</div>
</div>
