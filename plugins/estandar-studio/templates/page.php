<?php
/**
 * Plantilla de página del CPT `estandar_page` (Estandar Studio).
 * Sirve el bundle code-first dentro de WordPress (head/footer de WP).
 *
 * @package Estandar\Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo \Estandar\Studio\Render::document(); // phpcs:ignore WordPress.Security.EscapeOutput -- documento compuesto; el bundle es contenido de confianza del admin.
