<?php

namespace Estandar\Core\Communications\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DocumentInterface — contrato de generación de documentos de la Suite.
 *
 * Infraestructura común para cotizaciones, propuestas, comprobantes,
 * formularios, certificados y reportes. Los productos aportan contexto y
 * plantilla; la generación del documento es responsabilidad de Core.
 */
interface DocumentInterface
{
	/** MIME del documento generado (ej. application/pdf). */
	public function mime(): string;

	/** Extensión del archivo (ej. pdf). */
	public function extension(): string;

	/** Genera el documento y devuelve el contenido binario (string). */
	public function render( string $html, array $opts = array() ): string;

	/** Guarda el documento en una ruta y devuelve la ruta (o null). */
	public function save( string $html, string $path, array $opts = array() ): ?string;
}
