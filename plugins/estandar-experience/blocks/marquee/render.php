<?php
/**
 * Marquee — server render.
 *
 * @package Estandar\Experience
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\Experience\Blocks::enqueue_for( 'estandar/marquee' );

$efx_speed       = max( 10, min( 400, (int) ( $attributes['speed'] ?? 60 ) ) );
$efx_direction   = ( isset( $attributes['direction'] ) && 'right' === $attributes['direction'] ) ? 'right' : 'left';
$efx_hover_pause = ! isset( $attributes['hoverPause'] ) || (bool) $attributes['hoverPause'];
$efx_fade        = ! isset( $attributes['fade'] ) || (bool) $attributes['fade'];
$efx_gap         = max( 0, min( 400, (int) ( $attributes['gap'] ?? 48 ) ) );

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class'            => 'efx-marquee',
		'style'            => '--efx-marquee-gap:' . $efx_gap . 'px;',
		'data-speed'       => (string) $efx_speed,
		'data-direction'   => $efx_direction,
		'data-hover-pause' => $efx_hover_pause ? 'true' : 'false',
		'data-fade'        => $efx_fade ? 'true' : 'false',
	)
);
?>
<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?>>
	<div class="efx-marquee-track">
		<div class="efx-marquee-group"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner blocks HTML. ?></div>
	</div>
</div>
