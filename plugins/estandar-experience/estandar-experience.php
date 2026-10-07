<?php
/**
 * Plugin Name: Estandar Experience
 * Description: Motion system v2 del ecosistema Estandar. Motor de animación en
 * capas (visibility, scroll, pointer, text, particles) acoplado a bloques
 * Gutenberg nativos, más bloques estructurales de movimiento. Portado desde el
 * legacy estandar-experience-blocks (Estandar FX V3) bajo la regla de dos.
 * Version: 2.9.4
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * Text Domain: estandar-experience
 * Update URI: https://updates.estandar.cl
 *
 * @package Estandar\Experience
 *
 * F2 — KERNEL + MÓDULOS. Se integra a la suite v2 como bounded context
 * hermano de quotes. El manifiesto declara los módulos/bloques portados; la
 * guarda de coexistencia (flag `estandar_experience_frontend_enabled`, default
 * OFF) mantiene al legacy como dueño en producción hasta la paridad. El
 * frontend v2 (pipeline render_block) se activa solo cuando la guarda lo
 * autoriza. El editor (panel + bloques estructurales) llega en F3.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

define( 'ESTANDAR_EXPERIENCE_VERSION', '2.9.4');
define( 'ESTANDAR_EXPERIENCE_FILE', __FILE__ );
define( 'ESTANDAR_EXPERIENCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_EXPERIENCE_URL', plugin_dir_url( __FILE__ ) );

require_once ESTANDAR_EXPERIENCE_PATH . 'includes/class-experience-manifest.php';
require_once ESTANDAR_EXPERIENCE_PATH . 'includes/class-experience-guard.php';
require_once ESTANDAR_EXPERIENCE_PATH . 'includes/class-experience-assets.php';
require_once ESTANDAR_EXPERIENCE_PATH . 'includes/class-experience-attributes.php';
require_once ESTANDAR_EXPERIENCE_PATH . 'includes/class-experience-frontend.php';
require_once ESTANDAR_EXPERIENCE_PATH . 'includes/class-experience-blocks.php';
require_once ESTANDAR_EXPERIENCE_PATH . 'includes/class-experience-preview.php';

use Estandar\Experience\Assets;
use Estandar\Experience\Attributes;
use Estandar\Experience\Blocks;
use Estandar\Experience\Frontend;
use Estandar\Experience\Guard;
use Estandar\Experience\Preview;

/**
 * Punto de entrada del plugin.
 *
 * Assets y Attributes se registran siempre (inofensivos). El pipeline de
 * frontend SOLO se activa cuando la guarda lo autoriza (flag enabled), para
 * no duplicar el render con el legacy mientras no haya paridad.
 */
function estandar_experience_boot(): void {
	Assets::instance()->init();
	Attributes::instance()->init();

	if ( ! Guard::should_activate_v2_frontend() ) {
		return;
	}

	// Patrón owner: si el legacy está presente, suprimimos su frontend para
	// que no duplique el estampado de los mismos bloques.
	if ( defined( 'ESTANDAR_EFX_VERSION' ) ) {
		remove_filter( 'render_block', array( '\Estandar\FX\Frontend', 'instance' ), 20 );
	}

	Frontend::instance()->init();
	Blocks::instance()->init();
	Preview::init();
	do_action( 'estandar_experience_ready' );
}
add_action( 'plugins_loaded', 'estandar_experience_boot', 20 );
