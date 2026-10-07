<?php
/**
 * Scroll Panels — server render.
 *
 * @package Estandar\FX
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\FX\Blocks::enqueue_for( 'estandar/scroll-panels' );

$efx_panels = is_array( $attributes['panels'] ?? null ) ? $attributes['panels'] : array();
$efx_panels = array_values(
	array_filter(
		$efx_panels,
		function ( $panel ) {
			return is_array( $panel ) && ( ! empty( $panel['title'] ) || ! empty( $panel['body'] ) || ! empty( $panel['imageUrl'] ) );
		}
	)
);

$efx_height = max( 55, min( 120, (int) ( $attributes['height'] ?? 88 ) ) );
$efx_effect = in_array( $attributes['effect'] ?? 'slide', array( 'slide', 'scale', 'fade' ), true ) ? $attributes['effect'] : 'slide';
$efx_accent = is_string( $attributes['accentColor'] ?? null ) ? $attributes['accentColor'] : '#111111';
$efx_show_progress = ! array_key_exists( 'showProgress', $attributes ) || ! empty( $attributes['showProgress'] );
$efx_sticky_media = ! array_key_exists( 'stickyMedia', $attributes ) || ! empty( $attributes['stickyMedia'] );

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-scroll-panels efx-scroll-panels--' . $efx_effect . ( $efx_sticky_media ? ' has-sticky-media' : '' ),
		'style' => sprintf( '--efx-sp-height:%dvh;--efx-sp-accent:%s;', $efx_height, esc_attr( $efx_accent ) ),
	)
);

if ( empty( $efx_panels ) ) :
	?>
	<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?>>
		<div class="efx-sp-empty"><?php esc_html_e( 'Agrega paneles para construir la narrativa por scroll.', 'estandar-experience-blocks' ); ?></div>
	</div>
	<?php
	return;
endif;
?>
<section <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?> data-efx-scroll-panels>
	<?php if ( $efx_show_progress ) : ?>
		<div class="efx-sp-progress" aria-hidden="true"><span></span></div>
	<?php endif; ?>
	<?php foreach ( $efx_panels as $efx_index => $efx_panel ) : ?>
		<?php
		$efx_kicker = isset( $efx_panel['kicker'] ) && is_string( $efx_panel['kicker'] ) ? trim( $efx_panel['kicker'] ) : str_pad( (string) ( $efx_index + 1 ), 2, '0', STR_PAD_LEFT );
		$efx_title = isset( $efx_panel['title'] ) && is_string( $efx_panel['title'] ) ? trim( $efx_panel['title'] ) : '';
		$efx_body = isset( $efx_panel['body'] ) && is_string( $efx_panel['body'] ) ? trim( $efx_panel['body'] ) : '';
		$efx_image = isset( $efx_panel['imageUrl'] ) && is_string( $efx_panel['imageUrl'] ) ? $efx_panel['imageUrl'] : '';
		?>
		<article class="efx-sp-panel" data-index="<?php echo esc_attr( (string) $efx_index ); ?>">
			<div class="efx-sp-copy">
				<span class="efx-sp-kicker"><?php echo esc_html( $efx_kicker ); ?></span>
				<?php if ( '' !== $efx_title ) : ?><h2><?php echo esc_html( $efx_title ); ?></h2><?php endif; ?>
				<?php if ( '' !== $efx_body ) : ?><p><?php echo esc_html( $efx_body ); ?></p><?php endif; ?>
			</div>
			<?php if ( '' !== $efx_image ) : ?>
				<figure class="efx-sp-media"><img src="<?php echo esc_url( $efx_image ); ?>" alt="<?php echo esc_attr( $efx_title ); ?>" loading="lazy" decoding="async" /></figure>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
</section>
