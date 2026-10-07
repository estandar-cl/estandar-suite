<?php
/**
 * Before/After — server render.
 *
 * @package Estandar\FX
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$efx_before_id  = isset( $attributes['beforeId'] ) ? (int) $attributes['beforeId'] : 0;
$efx_after_id   = isset( $attributes['afterId'] ) ? (int) $attributes['afterId'] : 0;
$efx_before_url = isset( $attributes['beforeUrl'] ) ? esc_url( $attributes['beforeUrl'] ) : '';
$efx_after_url  = isset( $attributes['afterUrl'] ) ? esc_url( $attributes['afterUrl'] ) : '';

if ( ( ! $efx_before_id && '' === $efx_before_url ) || ( ! $efx_after_id && '' === $efx_after_url ) ) {
	return;
}

\Estandar\FX\Blocks::enqueue_for( 'estandar/before-after' );

$efx_before_label = isset( $attributes['beforeLabel'] ) ? sanitize_text_field( $attributes['beforeLabel'] ) : __( 'Antes', 'estandar-experience-blocks' );
$efx_after_label  = isset( $attributes['afterLabel'] ) ? sanitize_text_field( $attributes['afterLabel'] ) : __( 'Después', 'estandar-experience-blocks' );
$efx_start        = max( 0, min( 100, (int) ( $attributes['startPosition'] ?? 50 ) ) );

$efx_img = function ( $id, $url, $alt ) {
	if ( $id ) {
		$html = wp_get_attachment_image( $id, 'large', false, array( 'alt' => $alt ) );
		if ( $html ) {
			return $html;
		}
	}
	return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" />';
};

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-ba',
		'style' => '--efx-ba-pos:' . $efx_start . '%;',
	)
);
?>
<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?>>
	<?php echo $efx_img( $efx_before_id, $efx_before_url, $efx_before_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image / escaped fallback. ?>
	<div class="efx-ba-after">
		<?php echo $efx_img( $efx_after_id, $efx_after_url, $efx_after_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image / escaped fallback. ?>
	</div>
	<span class="efx-ba-label efx-ba-label-before"><?php echo esc_html( $efx_before_label ); ?></span>
	<span class="efx-ba-label efx-ba-label-after"><?php echo esc_html( $efx_after_label ); ?></span>
	<input
		type="range"
		class="efx-ba-range"
		min="0"
		max="100"
		step="1"
		value="<?php echo esc_attr( (string) $efx_start ); ?>"
		aria-label="<?php esc_attr_e( 'Posición del comparador', 'estandar-experience-blocks' ); ?>"
	/>
	<div class="efx-ba-handle" aria-hidden="true"></div>
</div>
