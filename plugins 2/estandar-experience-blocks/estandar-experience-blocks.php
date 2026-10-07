<?php
/**
 * Plugin Name: Estandar Experience Blocks
 * Description: Motion system for the Estandar ecosystem. A layered animation engine (visibility, scroll, pointer, text, particles) that attaches composable experiences to native Gutenberg blocks, plus structural motion blocks (Marquee, Before/After, Stack Cards, Coverflow Gallery, Magnetic Carousel, Spatial Gallery, Cover Transition, Scroll Panels, Sticky Grid Scroll, Horizontal Parallax Gallery, Container SplitText, Pinned Image Reveal, Scroll Synced Carousel).
 * Version: 13.0.173
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: Estandar Studios CL
 * Author URI: https://estandar.cl
 * Text Domain: estandar-experience-blocks
 * Update URI: https://updates.estandar.cl
 *
 * @package Estandar\FX
 *
 * Architecture (V3 — "Estandar FX"): one kernel (ticker + visibility + pointer +
 * scroll engines), independent FX modules registered into a runtime registry, an
 * editor enhancement panel for native blocks, and per-page asset loading — a page
 * only ships the modules it actually uses. See docs/ESTANDAR_FX_V3.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ESTANDAR_EFX_VERSION', '13.0.173' );
define( 'ESTANDAR_EFX_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_EFX_URL', plugin_dir_url( __FILE__ ) );

/*
 * CORE_OPTIONALITY_V1.1 — dynamic polyfill. Core MU is optional for every
 * consumer plugin; detection must happen at call time (never `return false`).
 */
if ( ! function_exists( 'primer_is_active' ) ) {
	function primer_is_active(): bool {
		return defined( 'PRIMER_VERSION' );
	}
}

require_once ESTANDAR_EFX_PATH . 'includes/class-efx-manifest.php';
require_once ESTANDAR_EFX_PATH . 'includes/class-efx-assets.php';
require_once ESTANDAR_EFX_PATH . 'includes/class-efx-attributes.php';
require_once ESTANDAR_EFX_PATH . 'includes/class-efx-frontend.php';
require_once ESTANDAR_EFX_PATH . 'includes/class-efx-blocks.php';
require_once ESTANDAR_EFX_PATH . 'includes/class-efx-presets.php';

/**
 * Boot once all plugins are loaded.
 */
function estandar_efx_boot() {
	\Estandar\FX\Assets::instance()->init();
	\Estandar\FX\Attributes::instance()->init();
	\Estandar\FX\Frontend::instance()->init();
	\Estandar\FX\Blocks::instance()->init();
	\Estandar\FX\Presets::instance()->init();
}
add_action( 'plugins_loaded', 'estandar_efx_boot' );
