<?php
/**
 * Stack Cards — server render.
 *
 * @package Estandar\FX
 *
 * @var array  $attributes Block attributes.
 * @var string $content    Inner blocks HTML (each direct child = one card).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Estandar\FX\Blocks::enqueue_for( 'estandar/stack-cards' );

$efx_top    = max( 0, min( 400, (int) ( $attributes['topOffset'] ?? 96 ) ) );
$efx_gap    = max( 0, min( 200, (int) ( $attributes['gap'] ?? 32 ) ) );
$efx_radius = max( 0, min( 80, (int) ( $attributes['radius'] ?? 20 ) ) );

$efx_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'efx-stack',
		'style' => sprintf(
			'--efx-stack-top:%dpx;--efx-stack-top-mobile:%dpx;--efx-stack-gap:%dpx;--efx-stack-radius:%dpx;',
			$efx_top,
			max( 0, (int) round( $efx_top * 0.6 ) ),
			$efx_gap,
			$efx_radius
		),
	)
);
?>
<div <?php echo $efx_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by get_block_wrapper_attributes(). ?>>
	<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner blocks HTML. ?>
</div>
