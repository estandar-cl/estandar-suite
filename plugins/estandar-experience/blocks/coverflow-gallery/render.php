<?php
/**
 * Coverflow Gallery — server render.
 *
 * @package Estandar\Experience
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\Experience\Blocks::enqueue_for( 'estandar/coverflow-gallery' );

$efx_slides = is_array( $attributes['slides'] ?? null ) ? $attributes['slides'] : array();
$efx_slides = array_values(
	array_filter(
		$efx_slides,
		function ( $slide ) {
			return is_array( $slide ) && ! empty( $slide['url'] ) && is_string( $slide['url'] );
		}
	)
);

$efx_card_width = max( 180, min( 720, (int) ( $attributes['cardWidth'] ?? 320 ) ) );
$efx_gap        = max( 24, min( 220, (int) ( $attributes['gap'] ?? 96 ) ) );
$efx_tilt       = max( 0, min( 80, (int) ( $attributes['tilt'] ?? 52 ) ) );
$efx_radius     = max( 0, min( 80, (int) ( $attributes['radius'] ?? 24 ) ) );
$efx_interval   = max( 1600, min( 15000, (int) ( $attributes['interval'] ?? 4200 ) ) );
$efx_autoplay   = ! empty( $attributes['autoplay'] );
$efx_show_titles = ! array_key_exists( 'showTitles', $attributes ) || ! empty( $attributes['showTitles'] );

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-coverflow',
		'style' => sprintf(
			'--efx-coverflow-card:%dpx;--efx-coverflow-gap:%dpx;--efx-coverflow-tilt:%ddeg;--efx-coverflow-radius:%dpx;',
			$efx_card_width,
			$efx_gap,
			$efx_tilt,
			$efx_radius
		),
	)
);

if ( empty( $efx_slides ) ) :
	?>
	<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?>>
		<div class="efx-coverflow-empty"><?php esc_html_e( 'Agrega imágenes para construir la galería coverflow.', 'estandar-experience-blocks' ); ?></div>
	</div>
	<?php
	return;
endif;

$efx_count = count( $efx_slides );
?>
<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?> data-efx-coverflow data-autoplay="<?php echo $efx_autoplay ? '1' : '0'; ?>" data-interval="<?php echo esc_attr( (string) $efx_interval ); ?>">
	<div class="efx-coverflow-stage" tabindex="0" role="region" aria-roledescription="carousel" aria-label="<?php esc_attr_e( 'Galería coverflow', 'estandar-experience-blocks' ); ?>">
		<div class="efx-coverflow-track" style="--efx-coverflow-count:<?php echo esc_attr( (string) $efx_count ); ?>;">
			<?php foreach ( $efx_slides as $efx_index => $efx_slide ) : ?>
				<?php
				$efx_title = isset( $efx_slide['title'] ) && is_string( $efx_slide['title'] ) ? trim( $efx_slide['title'] ) : '';
				$efx_alt   = isset( $efx_slide['alt'] ) && is_string( $efx_slide['alt'] ) ? $efx_slide['alt'] : $efx_title;
				?>
				<figure class="efx-coverflow-slide" data-index="<?php echo esc_attr( (string) $efx_index ); ?>" aria-hidden="<?php echo 0 === $efx_index ? 'false' : 'true'; ?>">
					<img src="<?php echo esc_url( $efx_slide['url'] ); ?>" alt="<?php echo esc_attr( $efx_alt ); ?>" loading="lazy" decoding="async" />
					<?php if ( $efx_show_titles && '' !== $efx_title ) : ?>
						<figcaption><?php echo esc_html( $efx_title ); ?></figcaption>
					<?php endif; ?>
				</figure>
			<?php endforeach; ?>
		</div>
	</div>

	<?php if ( $efx_count > 1 ) : ?>
		<div class="efx-coverflow-controls" aria-label="<?php esc_attr_e( 'Controles de galería', 'estandar-experience-blocks' ); ?>">
			<button class="efx-coverflow-prev" type="button" aria-label="<?php esc_attr_e( 'Imagen anterior', 'estandar-experience-blocks' ); ?>">‹</button>
			<div class="efx-coverflow-dots" role="tablist" aria-label="<?php esc_attr_e( 'Elegir imagen', 'estandar-experience-blocks' ); ?>">
				<?php foreach ( $efx_slides as $efx_index => $efx_slide ) : ?>
					<button type="button" role="tab" class="efx-coverflow-dot" data-index="<?php echo esc_attr( (string) $efx_index ); ?>" aria-selected="<?php echo 0 === $efx_index ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Ver imagen %d', 'estandar-experience-blocks' ), $efx_index + 1 ) ); ?>"></button>
				<?php endforeach; ?>
			</div>
			<button class="efx-coverflow-next" type="button" aria-label="<?php esc_attr_e( 'Imagen siguiente', 'estandar-experience-blocks' ); ?>">›</button>
		</div>
	<?php endif; ?>
</div>
