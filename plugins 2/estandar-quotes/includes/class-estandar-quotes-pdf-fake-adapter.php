<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Deterministic test-only renderer; it deliberately is not a real PDF implementation. */
class Estandar_Quotes_PDF_Fake_Adapter implements Estandar_Quotes_PDF_Adapter {
	public function id(): string { return 'fake'; }
	public function version(): string { return '1'; }
	public function render( string $print_html, array $options = array() ): string { if ( trim( $print_html ) === '' || stripos( $print_html, '<') === false ) { throw new InvalidArgumentException( 'Invalid print HTML.' ); } return "%PDF-FAKE-1\n" . hash( 'sha256', $print_html ) . "\n" . $print_html; }
}
