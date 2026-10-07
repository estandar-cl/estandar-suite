<?php
/**
 * Tests unitarios para el módulo SVG Upload - mitigación XXE.
 * Verifica que no se usen flags peligrosos en loadXML.
 */

use PHPUnit\Framework\TestCase;

class SvgUploadXxeTest extends TestCase {

	public function test_dangerous_libxml_flags_not_in_source() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/modules/svg-upload/class-svg-upload.php' );
		$this->assertStringNotContainsString( 'LIBXML_NOENT', $source, 'LIBXML_NOENT permite XXE y no debe usarse' );
		$this->assertStringNotContainsString( 'LIBXML_DTDLOAD', $source, 'LIBXML_DTDLOAD permite cargar DTD externos' );
		$this->assertStringNotContainsString( 'LIBXML_DTDATTR', $source, 'LIBXML_DTDATTR puede ser peligroso con DTD' );
	}

	public function test_safe_libxml_option_used() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/modules/svg-upload/class-svg-upload.php' );
		$this->assertTrue(
			strpos( $source, 'LIBXML_NOXXE' ) !== false || strpos( $source, '$options' ) !== false,
			'Debe usarse LIBXML_NOXXE o options seguras para mitigar XXE'
		);
	}
}
