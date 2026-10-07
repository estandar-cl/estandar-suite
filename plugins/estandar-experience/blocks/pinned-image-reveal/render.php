<?php
/**
 * Pinned Image Reveal — server render.
 *
 * @package Estandar\Experience
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\Experience\Blocks::enqueue_for( 'estandar/pinned-image-reveal' );

$efx_image = is_string( $attributes['imageUrl'] ?? null ) ? $attributes['imageUrl'] : '';
$efx_title = is_string( $attributes['title'] ?? null ) ? $attributes['title'] : '';
$efx_body = is_string( $attributes['body'] ?? null ) ? $attributes['body'] : '';
$efx_height = max( 110, min( 320, (int) ( $attributes['height'] ?? 180 ) ) );
$efx_mode = in_array( $attributes['mode'] ?? 'mask-zoom', array( 'mask-zoom', 'circle', 'zoom', 'cinematic' ), true ) ? $attributes['mode'] : 'mask-zoom';
$efx_start = max( 1, min( 2, (float) ( $attributes['startScale'] ?? 1.18 ) ) );
$efx_end = max( 0.75, min( 1.4, (float) ( $attributes['endScale'] ?? 1 ) ) );
$efx_radius = max( 0, min( 80, (int) ( $attributes['radius'] ?? 28 ) ) );
$efx_overlay = max( 0, min( 0.85, (float) ( $attributes['overlayOpacity'] ?? 0.35 ) ) );

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-pinned-image-reveal efx-pinned-image-reveal--' . $efx_mode,
		'style' => sprintf( '--efx-pir-height:%dvh;--efx-pir-start:%s;--efx-pir-end:%s;--efx-pir-radius:%dpx;--efx-pir-overlay:%s;', $efx_height, esc_attr( (string) $efx_start ), esc_attr( (string) $efx_end ), $efx_radius, esc_attr( (string) $efx_overlay ) ),
	)
);
?>
<section <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-efx-pinned-image-reveal>
	<div class="efx-pir-sticky">
		<?php if ( '' !== $efx_image ) : ?><img src="<?php echo esc_url( $efx_image ); ?>" alt="<?php echo esc_attr( $efx_title ); ?>" loading="lazy" decoding="async" /><?php endif; ?>
		<div class="efx-pir-copy">
			<?php if ( '' !== $efx_title ) : ?><h2><?php echo esc_html( $efx_title ); ?></h2><?php endif; ?>
			<?php if ( '' !== $efx_body ) : ?><p><?php echo esc_html( $efx_body ); ?></p><?php endif; ?>
		</div>
	</div>
</section>
