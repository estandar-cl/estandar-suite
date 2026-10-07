<?php
/**
 * Report i18n helper.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads report language files.
 */
class EWS_Report_I18n {

	/**
	 * Cache.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $cache = array();

	/**
	 * Get translations for language.
	 *
	 * @param string $lang Language code.
	 * @return array<string, string>
	 */
	public static function get( string $lang ): array {
		$lang = in_array( $lang, array( 'en', 'es' ), true ) ? $lang : 'en';
		if ( isset( self::$cache[ $lang ] ) ) {
			return self::$cache[ $lang ];
		}

		$default = self::load_file( 'en' );
		$current = ( 'en' === $lang ) ? $default : array_merge( $default, self::load_file( $lang ) );
		self::$cache[ $lang ] = $current;
		return $current;
	}

	/**
	 * Translate a key.
	 *
	 * @param array<string, string> $dict Dictionary.
	 * @param string                $key Key.
	 * @return string
	 */
	public static function t( array $dict, string $key ): string {
		return isset( $dict[ $key ] ) ? (string) $dict[ $key ] : $key;
	}

	/**
	 * Return default CTA text by report language.
	 *
	 * @param string $lang Language.
	 * @return string
	 */
	public static function default_cta( string $lang ): string {
		if ( 'es' === $lang ) {
			return "Monitorear un sitio web de forma continua permite mejorar resultados.\n\nEste informe refleja el estado del sitio en el momento del analisis.\nSin embargo, los sitios web evolucionan constantemente a medida que los\nequipos tecnicos realizan cambios, optimizaciones y actualizaciones.\n\nEstandar Studio ofrece un servicio de monitoreo continuo mediante\nscanners semanales o mensuales que permiten evaluar si las mejoras\nimplementadas realmente estan impactando en el rendimiento, el SEO\ny la experiencia de usuario.\n\nSi deseas implementar un sistema de monitoreo continuo para tu sitio web,\npuedes contactarnos en:\n\nhttps://estandar.cl";
		}

		return "Continuous monitoring improves results.\n\nThis report reflects the current state of the website at the time of the scan.\nHowever, websites evolve constantly as developers introduce changes, updates\nand optimizations.\n\nEstandar Studio offers continuous monitoring services through weekly or\nmonthly scans that allow you to track improvements, detect regressions\nand ensure that technical adjustments are producing measurable results.\n\nIf you would like to implement continuous website monitoring for your\norganization, please contact:\n\nhttps://estandar.cl";
	}

	/**
	 * Return short CTA text by report language.
	 *
	 * @param string $lang Language.
	 * @return string
	 */
	public static function default_cta_short( string $lang ): string {
		if ( 'es' === $lang ) {
			return "El rendimiento de un sitio web evoluciona con cada cambio técnico o editorial.\n\nEstandar Studio ofrece servicios de monitoreo continuo mediante scanners periódicos que permiten evaluar la evolución del rendimiento, el SEO y la experiencia de usuario.\n\nMás información:\nhttps://estandar.cl";
		}

		return "Website performance evolves with each technical or editorial change.\n\nEstandar Studio provides continuous monitoring services through periodic scans to evaluate performance, SEO and user experience evolution.\n\nMore information:\nhttps://estandar.cl";
	}

	/**
	 * Load json file.
	 *
	 * @param string $lang Lang code.
	 * @return array<string, string>
	 */
	private static function load_file( string $lang ): array {
		$file = EWS_PLUGIN_DIR . 'languages/report-' . $lang . '.json';
		if ( ! file_exists( $file ) ) {
			return array();
		}
		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $content ) || '' === $content ) {
			return array();
		}
		$data = json_decode( $content, true );
		return is_array( $data ) ? $data : array();
	}
}
