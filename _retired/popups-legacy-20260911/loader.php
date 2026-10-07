<?php
/**
 * Popups Legacy — port íntegro de estandar-pop a estandar-capture v2.
 *
 * Rescata la versión original del sistema de popups (CPT `estandar_pop`)
 * SIN rehacer estilos, opciones ni características: se mantienen el editor
 * Gutenberg (sidebar + bloque "Popup Picker"), los triggers (tiempo / scroll /
 * inactividad / click), la segmentación (posts / URL / fechas), la frecuencia
 * (always / session / 1-7-30 días / custom), el diseño (size / custom width /
 * overlay / animation / z-index), el CSS scoped, el tracking
 * (views / opens / clicks / submits) y el shortcode [ep_open].
 *
 * Los archivos se copiaron tal cual del plugin legacy (13.0.173 / v10.5.x);
 * solo cambia el bootstrap: EP_* apunta a esta carpeta y todo se carga
 * guardado por `function_exists`, de modo que si el plugin legacy volviera a
 * activarse no habría colisión.
 *
 * @package Estandar\Capture
 */

namespace Estandar\Capture\PopupsLegacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'EP_VERSION' ) ) {
	define( 'EP_VERSION', defined( 'ESTANDAR_SUITE_CAPTURE_VERSION' ) ? ESTANDAR_SUITE_CAPTURE_VERSION : '13.0.290' );
}
if ( ! defined( 'EP_PATH' ) ) {
	define( 'EP_PATH', trailingslashit( dirname( __FILE__ ) ) );
}
if ( ! defined( 'EP_URL' ) ) {
	define( 'EP_URL', plugin_dir_url( __FILE__ ) );
}

// Guard: si el plugin legacy estandar-pop está cargado, no duplicamos nada.
if ( ! function_exists( 'ep_track_event' ) ) {
	require EP_PATH . 'post-type.php';
	require EP_PATH . 'frontend-v304.php';
	require EP_PATH . 'admin.php';
	require EP_PATH . 'gutenberg-popup-block.php';
}
