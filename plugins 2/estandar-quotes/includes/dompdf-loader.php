<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Loads DOMPDF from the Estandar ecosystem.
 *
 * Source order:
 *  1. Bundled in includes/lib/dompdf/vendor (shipped with plugin, survives hosting vendor strip)
 *  2. estandar-website-scanner (canonical DOMPDF host in the ecosystem)
 *  3. This plugin's own vendor/ if installed via Composer
 *
 * Each source is tried via autoloader first, then direct require fallback.
 */
function estandar_quotes_load_dompdf(): bool {
	if ( class_exists( '\Dompdf\Dompdf' ) ) { return true; }

	$sources = array();

	// 1. Bundled in includes/lib/dompdf/vendor (shipped with plugin, survives hosting vendor strip)
	$sources[] = __DIR__ . '/lib/dompdf/vendor';

	// 2. Primary: estandar-website-scanner (canonical DOMPDF source in ecosystem)
	if ( defined( 'WP_PLUGIN_DIR' ) ) {
		$sources[] = WP_PLUGIN_DIR . '/estandar-website-scanner/vendor';
	}

	// 3. Fallback: this plugin's own vendor (if composer install was run)
	$sources[] = dirname( __DIR__ ) . '/vendor';

	foreach ( $sources as $base ) {
		$autoload = $base . '/autoload.php';
		if ( file_exists( $autoload ) ) {
			require_once $autoload;
			if ( class_exists( '\Dompdf\Dompdf' ) ) { return true; }
		}
		// Direct require fallback (no autoloader available)
		$dompdf_src = $base . '/dompdf/dompdf/src/Dompdf.php';
		if ( file_exists( $dompdf_src ) ) {
			$deps = array(
				$base . '/phenx/php-font-lib/src/FontLib/Font.php',
				$base . '/phenx/php-svg-lib/src/Svg/Style.php',
				$base . '/masterminds/html5/src/HTML5.php',
				$base . '/sabberworm/php-css-parser/src/CSSParser.php',
			);
			foreach ( $deps as $dep ) {
				if ( file_exists( $dep ) ) { require_once $dep; }
			}
			require_once $dompdf_src;
			if ( class_exists( '\Dompdf\Dompdf' ) ) { return true; }
		}
	}

	return false;
}
