<?php

namespace Estandar\Core\Communications\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pdf — infraestructura base de generación de PDF de la Suite.
 *
 * Reutiliza dompdf si está disponible (autoload en rutas conocidas del
 * workspace); si no, produce un PDF mínimo válido (fallback explícito, no
 * silencioso). Es la base común para futuros documentos de la Suite
 * (cotizaciones, propuestas, comprobantes, certificados, reportes).
 */
class Pdf implements DocumentInterface
{
	/** Rutas candidatas al autoload de dompdf (workspace/legacy). */
	public static function dompdf_paths(): array
	{
		return array(
			WP_PLUGIN_DIR . '/estandar-quotes/vendor/dompdf/autoload.inc.php',
			WP_PLUGIN_DIR . '/estandar-website-scanner/vendor/dompdf/autoload.inc.php',
		);
	}

	public static function dompdf_available(): bool
	{
		foreach ( self::dompdf_paths() as $p ) {
			if ( is_readable( $p ) ) {
				return true;
			}
		}
		return false;
	}

	public function mime(): string
	{
		return 'application/pdf';
	}

	public function extension(): string
	{
		return 'pdf';
	}

	public function render( string $html, array $opts = array() ): string
	{
		if ( self::dompdf_available() ) {
			try {
				foreach ( self::dompdf_paths() as $p ) {
					if ( is_readable( $p ) ) {
						require_once $p;
						break;
					}
				}
				$dompdf = new \Dompdf\Dompdf( array( 'isRemoteEnabled' => false ) );
				$dompdf->loadHtml( $html );
				$dompdf->setPaper( (string) ( $opts['paper'] ?? 'letter' ), (string) ( $opts['orientation'] ?? 'portrait' ) );
				$dompdf->render();
				return (string) $dompdf->output();
			} catch ( \Throwable $e ) {
				// fallthrough al fallback mínimo
			}
		}
		return self::minimal_pdf( $html );
	}

	public function save( string $html, string $path, array $opts = array() ): ?string
	{
		$bytes = $this->render( $html, $opts );
		if ( $bytes === '' ) {
			return null;
		}
		if ( @file_put_contents( $path, $bytes ) === false ) {
			return null;
		}
		return $path;
	}

	/** PDF mínimo válido (fallback explícito, sin dompdf). */
	private static function minimal_pdf( string $html ): string
	{
		$content = wp_strip_all_tags( $html );
		$content = mb_substr( $content, 0, 2000 );
		$content = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $content );
		$stream  = 'BT /F1 11 Tf 50 750 Td 12 TL (' . $content . ') Tj ET';

		$objects = array(
			"1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n",
			"2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n",
			"3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>endobj\n",
			"4 0 obj<</Length " . strlen( $stream ) . ">>stream\n" . $stream . "\nendstream\nendobj\n",
			"5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n",
		);
		$pdf = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objects as $i => $obj ) {
			$offsets[ $i + 1 ] = strlen( $pdf );
			$pdf .= $obj;
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 6\n0000000000 65535 f \n";
		for ( $i = 1; $i <= 5; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$pdf .= "trailer<</Size 6/Root 1 0 R>>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}
}
