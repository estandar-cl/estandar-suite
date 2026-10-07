<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Estandar_Quotes_PDF_Dompdf_Adapter implements Estandar_Quotes_PDF_Adapter {

	/** Canonical renderer resolver. Loads DOMPDF and returns an adapter, or throws. */
	public static function resolve(): self {
		require_once __DIR__ . '/dompdf-loader.php';
		if ( ! estandar_quotes_load_dompdf() ) {
			throw new RuntimeException( 'PDF renderer unavailable.' );
		}
		return new self();
	}

	public function id(): string { return 'dompdf'; }

	public function version(): string {
		require_once __DIR__ . '/dompdf-loader.php';
		estandar_quotes_load_dompdf();
		return defined( '\Dompdf\Dompdf::VERSION' ) ? constant( '\Dompdf\Dompdf::VERSION' ) : 'runtime';
	}

	public function render( string $print_html, array $options = array() ): string {
		require_once __DIR__ . '/dompdf-loader.php';
		if ( ! estandar_quotes_load_dompdf() ) {
			throw new RuntimeException( 'PDF renderer unavailable. DOMPDF not found. Install dompdf/dompdf via Composer or activate estandar-website-scanner.' );
		}
		$dompdf = new \Dompdf\Dompdf( array(
			'isRemoteEnabled'    => false,
			'isHtml5ParserEnabled' => true,
			'isFontSubsettingEnabled' => false,
			'isJavascriptEnabled' => false,
		) );
		if ( stripos( $print_html, '<html' ) === false ) {
			$print_html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $print_html . '</body></html>';
		}
		$dompdf->loadHtml( $print_html, 'UTF-8' );
		$dompdf->setPaper( 'letter', 'portrait' );
		$dompdf->render();
		$pdf = $dompdf->output();
		if ( substr( $pdf, 0, 4 ) !== '%PDF' ) {
			throw new RuntimeException( 'PDF renderer returned invalid output.' );
		}
		$page_count = 0;
		if ( preg_match_all( '/\/Type\s*\/Page\b(?!\s*\/Pages)/i', $pdf, $matches ) ) {
			$page_count = count( $matches[0] );
		}
		if ( $page_count > 0 ) {
			$pdf = preg_replace( '/\/Count\s+0\b/', '/Count ' . $page_count, $pdf );
		}
		return $pdf;
	}
}
