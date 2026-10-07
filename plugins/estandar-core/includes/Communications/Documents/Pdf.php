<?php

namespace Estandar\Core\Communications\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pdf — infraestructura base de generación de PDF de la Suite.
 *
 * Motor único del ecosistema: usa la copia única de dompdf embebida en
 * estandar-core (`includes/lib/dompdf`, vendida en S16). Si dompdf no está
 * disponible o falla (host PHP < 8.1, extensión ausente, error de render),
 * produce un PDF de texto mínimo válido con un aviso EXPLÍCITO (nunca un
 * fallo silencioso). Es la base común para los documentos de la Suite
 * (exports del panel, futuras cotizaciones, propuestas, comprobantes).
 */
class Pdf implements DocumentInterface
{
	/** Autoload canónico de dompdf (UNA única copia, vendida en estandar-core). */
	const DOMPDF_AUTOLOAD = 'includes/lib/dompdf/autoload.php';

	/** Rutas candidatas al autoload de dompdf (host canónico único del ecosistema). */
	public static function dompdf_paths(): array
	{
		$base  = '';
		if ( defined( 'ESTANDAR_SUITE_CORE_PATH' ) ) {
			$base = ESTANDAR_SUITE_CORE_PATH;
		} elseif ( defined( 'WP_PLUGIN_DIR' ) ) {
			$base = WP_PLUGIN_DIR . '/estandar-core/';
		}
		$paths = $base !== '' ? array( $base . self::DOMPDF_AUTOLOAD ) : array();
		if ( function_exists( 'apply_filters' ) ) {
			$paths = (array) apply_filters( 'estandar_suite_core_dompdf_autoload', $paths );
		}
		return array_values( array_filter( $paths, 'is_string' ) );
	}

	public static function dompdf_available(): bool
	{
		foreach ( self::dompdf_paths() as $p ) {
			if ( $p !== '' && is_readable( $p ) ) {
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
					if ( $p !== '' && is_readable( $p ) ) {
						require_once $p;
						break;
					}
				}
				// Postura de seguridad por defecto: sin PHP remoto, sin recursos
				// remotos, fuente por defecto Helvetica (core PDF, sin ttf extra).
				$dompdf = new \Dompdf\Dompdf( array_merge( array(
					'isRemoteEnabled' => false,
					'isPhpEnabled'    => false,
					'defaultFont'     => 'Helvetica',
				), $opts ) );
				$dompdf->loadHtml( $html );
				$dompdf->setPaper( (string) ( $opts['paper'] ?? 'letter' ), (string) ( $opts['orientation'] ?? 'portrait' ) );
				$dompdf->render();
				return (string) $dompdf->output();
			} catch ( \Throwable $e ) {
				// Fallthrough al fallback mínimo (con aviso explícito).
			}
		}
		self::note_fallback();
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

	/** Aviso explícito cuando se cae al fallback mínimo (nunca silencioso). */
	private static function note_fallback(): void
	{
		if ( function_exists( 'do_action' ) ) {
			do_action( 'estandar_suite_core_pdf_fallback' );
		}
	}

	/** PDF mínimo válido (fallback explícito, sin dompdf). Texto legible multi-línea. */
	private static function minimal_pdf( string $html ): string
	{
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		$text = (string) mb_substr( $text, 0, 4000 );

		// Trocea en líneas de ~96 chars (corta en espacios; no rompe palabras).
		$lines = array();
		foreach ( explode( "\n", wordwrap( $text, 96, "\n", true ) ) as $line ) {
			if ( $line !== '' ) {
				$lines[] = $line;
			}
		}
		$lines = array_slice( $lines, 0, 42 );

		$stream = '';
		$y      = 760;
		foreach ( $lines as $line ) {
			$safe = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $line );
			$stream .= "BT /F1 10 Tf 50 {$y} Td ({$safe}) Tj ET\n";
			$y -= 14;
		}

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
