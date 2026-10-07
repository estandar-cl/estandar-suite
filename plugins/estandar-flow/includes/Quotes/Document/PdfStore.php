<?php

namespace Estandar\Flow\Quotes\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PdfStore — almacenamiento en disco de los artefactos PDF (M4).
 *
 * Usa `wp-content/uploads/estandar-quotes/` y, si WP no está cargado, cae a un
 * directorio temporal (`sys_get_temp_dir()`), de modo que la clase es usable en
 * tests puros sin fatalizar. Todos los nombres se toman con `basename()`: ninguna
 * ruta puede escapar del directorio base (anti path traversal).
 */
class PdfStore
{
	/** Subdirectorio de uploads para las cotizaciones. */
	const SUBDIR = 'estandar-quotes';

	/** Directorio base de almacenamiento (lo crea si falta). */
	public static function dir(): string
	{
		$base = '';
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = (array) wp_upload_dir();
			if ( ! empty( $uploads['basedir'] ) ) {
				$base = rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . self::SUBDIR;
			}
		}
		if ( '' === $base ) {
			$base = rtrim( sys_get_temp_dir(), '/\\' ) . '/' . self::SUBDIR;
		}
		if ( ! is_dir( $base ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				@wp_mkdir_p( $base );
			} else {
				@mkdir( $base, 0777, true );
			}
		}
		return $base;
	}

	/** Ruta absoluta del artefacto (basename: sin traversal). */
	public static function path( string $filename ): string
	{
		return self::dir() . '/' . self::safe_name( $filename );
	}

	/** URL pública del artefacto (vacía si no hay uploads/URL base). */
	public static function url( string $filename ): string
	{
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$uploads = (array) wp_upload_dir();
		if ( empty( $uploads['baseurl'] ) ) {
			return '';
		}
		return rtrim( (string) $uploads['baseurl'], '/\\' ) . '/' . self::SUBDIR . '/' . rawurlencode( self::safe_name( $filename ) );
	}

	/** Nombre seguro: solo basename, sin separadores ni `..`. */
	private static function safe_name( string $filename ): string
	{
		$name = basename( str_replace( array( '\\', "\0" ), '/', $filename ) );
		$name = trim( $name );
		return '' !== $name && '.' !== $name && '..' !== $name ? $name : 'Cotizacion.pdf';
	}
}
