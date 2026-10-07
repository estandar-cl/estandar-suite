<?php
/**
 * Cover Transition — server render.
 *
 * @package Estandar\FX
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\FX\Blocks::enqueue_for( 'estandar/cover-transition' );

$efx_items = is_array( $attributes['items'] ?? null ) ? $attributes['items'] : array();
$efx_items = array_values(
	array_filter(
		$efx_items,
		function ( $item ) {
			return is_array( $item ) && ( ! empty( $item['title'] ) || ! empty( $item['imageUrl'] ) );
		}
	)
);

$efx_height = max( 420, min( 1100, (int) ( $attributes['height'] ?? 640 ) ) );
$efx_duration = max( 260, min( 1800, (int) ( $attributes['duration'] ?? 760 ) ) );
$efx_overlay_color = is_string( $attributes['overlayColor'] ?? null ) ? $attributes['overlayColor'] : '#111111';
$efx_text_color = is_string( $attributes['textColor'] ?? null ) ? $attributes['textColor'] : '#f8fafc';
$efx_style = in_array( $attributes['transitionStyle'] ?? 'split', array( 'split', 'wipe', 'lift' ), true ) ? $attributes['transitionStyle'] : 'split';
$efx_show_meta = ! array_key_exists( 'showMeta', $attributes ) || ! empty( $attributes['showMeta'] );

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-cover-transition efx-cover-transition--' . $efx_style,
		'style' => sprintf(
			'--efx-ct-height:%dpx;--efx-ct-duration:%dms;--efx-ct-bg:%s;--efx-ct-color:%s;',
			$efx_height,
			$efx_duration,
			esc_attr( $efx_overlay_color ),
			esc_attr( $efx_text_color )
		),
	)
);

if ( empty( $efx_items ) ) :
	?>
	<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?>>
		<div class="efx-ct-empty"><?php esc_html_e( 'Agrega elementos para construir la transición de cubierta.', 'estandar-experience-blocks' ); ?></div>
	</div>
	<?php
	return;
endif;
?>
<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?> data-efx-cover-transition>
	<div class="efx-ct-list" role="list">
		<?php foreach ( $efx_items as $efx_index => $efx_item ) : ?>
			<?php
			$efx_title = isset( $efx_item['title'] ) && is_string( $efx_item['title'] ) ? trim( $efx_item['title'] ) : sprintf( __( 'Elemento %d', 'estandar-experience-blocks' ), $efx_index + 1 );
			$efx_meta = isset( $efx_item['meta'] ) && is_string( $efx_item['meta'] ) ? trim( $efx_item['meta'] ) : '';
			$efx_description = isset( $efx_item['description'] ) && is_string( $efx_item['description'] ) ? trim( $efx_item['description'] ) : '';
			$efx_image = isset( $efx_item['imageUrl'] ) && is_string( $efx_item['imageUrl'] ) ? $efx_item['imageUrl'] : '';
			?>
			<article class="efx-ct-card" role="listitem">
				<?php if ( $efx_show_meta && '' !== $efx_meta ) : ?><span class="efx-ct-meta"><?php echo esc_html( $efx_meta ); ?></span><?php endif; ?>
				<h3 class="efx-ct-title"><?php echo esc_html( $efx_title ); ?></h3>
				<?php if ( '' !== $efx_image ) : ?><img src="<?php echo esc_url( $efx_image ); ?>" alt="<?php echo esc_attr( $efx_title ); ?>" loading="lazy" decoding="async" /><?php endif; ?>
				<?php if ( '' !== $efx_description ) : ?><p><?php echo esc_html( $efx_description ); ?></p><?php endif; ?>
				<button type="button" class="efx-ct-open" data-index="<?php echo esc_attr( (string) $efx_index ); ?>"><?php esc_html_e( 'Ver', 'estandar-experience-blocks' ); ?></button>
			</article>
		<?php endforeach; ?>
	</div>

	<div class="efx-ct-overlay" aria-hidden="true"><span></span><span></span></div>
	<div class="efx-ct-previews" aria-hidden="true">
		<?php foreach ( $efx_items as $efx_index => $efx_item ) : ?>
			<?php
			$efx_title = isset( $efx_item['title'] ) && is_string( $efx_item['title'] ) ? trim( $efx_item['title'] ) : '';
			$efx_meta = isset( $efx_item['meta'] ) && is_string( $efx_item['meta'] ) ? trim( $efx_item['meta'] ) : '';
			$efx_description = isset( $efx_item['description'] ) && is_string( $efx_item['description'] ) ? trim( $efx_item['description'] ) : '';
			$efx_image = isset( $efx_item['imageUrl'] ) && is_string( $efx_item['imageUrl'] ) ? $efx_item['imageUrl'] : '';
			$efx_detail_a = isset( $efx_item['detailA'] ) && is_string( $efx_item['detailA'] ) ? trim( $efx_item['detailA'] ) : __( 'Detalle editorial para contexto, ubicación o alcance.', 'estandar-experience-blocks' );
			$efx_detail_b = isset( $efx_item['detailB'] ) && is_string( $efx_item['detailB'] ) ? trim( $efx_item['detailB'] ) : __( 'Segundo bloque para material, resultado o promesa comercial.', 'estandar-experience-blocks' );
			?>
			<section class="efx-ct-preview" data-index="<?php echo esc_attr( (string) $efx_index ); ?>" aria-label="<?php echo esc_attr( $efx_title ); ?>">
				<?php if ( '' !== $efx_image ) : ?><img src="<?php echo esc_url( $efx_image ); ?>" alt="<?php echo esc_attr( $efx_title ); ?>" loading="lazy" decoding="async" /><?php endif; ?>
				<div class="efx-ct-preview-main">
					<span><?php echo esc_html( $efx_meta ); ?></span>
					<h2><?php echo esc_html( $efx_title ); ?></h2>
					<?php if ( '' !== $efx_description ) : ?><p><?php echo esc_html( $efx_description ); ?></p><?php endif; ?>
				</div>
				<div class="efx-ct-preview-grid"><p><?php echo esc_html( $efx_detail_a ); ?></p><p><?php echo esc_html( $efx_detail_b ); ?></p></div>
				<button type="button" class="efx-ct-back"><?php esc_html_e( 'Volver', 'estandar-experience-blocks' ); ?></button>
			</section>
		<?php endforeach; ?>
	</div>
</div>
