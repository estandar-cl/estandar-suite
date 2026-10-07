<?php
/**
 * Container SplitText — server render.
 *
 * @package Estandar\Experience
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\Experience\Blocks::enqueue_for( 'estandar/container-split-text' );

$efx_panels = is_array( $attributes['panels'] ?? null ) ? $attributes['panels'] : array();
$efx_panels = array_values(
	array_filter(
		$efx_panels,
		function ( $panel ) {
			return is_array( $panel ) && ( ! empty( $panel['title'] ) || ! empty( $panel['body'] ) || ! empty( $panel['imageUrl'] ) );
		}
	)
);

$efx_height = max( 55, min( 100, (int) ( $attributes['height'] ?? 82 ) ) );
$efx_effect = in_array( $attributes['textEffect'] ?? 'cascade', array( 'cascade', 'wipe', 'scrub' ), true ) ? $attributes['textEffect'] : 'cascade';
$efx_bg = is_string( $attributes['backgroundColor'] ?? null ) ? $attributes['backgroundColor'] : '#0b0b0f';
$efx_color = is_string( $attributes['textColor'] ?? null ) ? $attributes['textColor'] : '#f8fafc';
$efx_accent = is_string( $attributes['accentColor'] ?? null ) ? $attributes['accentColor'] : '#84cc16';
$efx_show_progress = ! array_key_exists( 'showProgress', $attributes ) || ! empty( $attributes['showProgress'] );

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-container-split-text efx-container-split-text--' . $efx_effect,
		'style' => sprintf( '--efx-cst-height:%dvh;--efx-cst-bg:%s;--efx-cst-color:%s;--efx-cst-accent:%s;', $efx_height, esc_attr( $efx_bg ), esc_attr( $efx_color ), esc_attr( $efx_accent ) ),
	)
);

if ( empty( $efx_panels ) ) : ?>
	<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><div class="efx-cst-empty"><?php esc_html_e( 'Agrega paneles para construir Container SplitText.', 'estandar-experience-blocks' ); ?></div></div>
	<?php return; endif; ?>
<section <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-efx-container-split-text>
	<div class="efx-cst-sticky">
		<?php if ( $efx_show_progress ) : ?><div class="efx-cst-progress" aria-hidden="true"><span></span></div><?php endif; ?>
		<div class="efx-cst-track" role="list" aria-label="<?php esc_attr_e( 'Paneles Container SplitText', 'estandar-experience-blocks' ); ?>">
			<?php foreach ( $efx_panels as $efx_index => $efx_panel ) : ?>
				<?php
				$efx_kicker = isset( $efx_panel['kicker'] ) && is_string( $efx_panel['kicker'] ) ? trim( $efx_panel['kicker'] ) : str_pad( (string) ( $efx_index + 1 ), 2, '0', STR_PAD_LEFT );
				$efx_title = isset( $efx_panel['title'] ) && is_string( $efx_panel['title'] ) ? trim( $efx_panel['title'] ) : '';
				$efx_body = isset( $efx_panel['body'] ) && is_string( $efx_panel['body'] ) ? trim( $efx_panel['body'] ) : '';
				$efx_image = isset( $efx_panel['imageUrl'] ) && is_string( $efx_panel['imageUrl'] ) ? $efx_panel['imageUrl'] : '';
				?>
				<article class="efx-cst-panel" role="listitem" data-index="<?php echo esc_attr( (string) $efx_index ); ?>">
					<div class="efx-cst-copy">
						<span class="efx-cst-kicker"><?php echo esc_html( $efx_kicker ); ?></span>
						<?php if ( '' !== $efx_title ) : ?><h2 data-efx-cst-split><?php echo esc_html( $efx_title ); ?></h2><?php endif; ?>
						<?php if ( '' !== $efx_body ) : ?><p><?php echo esc_html( $efx_body ); ?></p><?php endif; ?>
					</div>
					<?php if ( '' !== $efx_image ) : ?><figure class="efx-cst-media"><img src="<?php echo esc_url( $efx_image ); ?>" alt="<?php echo esc_attr( $efx_title ); ?>" loading="lazy" decoding="async" /></figure><?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>
