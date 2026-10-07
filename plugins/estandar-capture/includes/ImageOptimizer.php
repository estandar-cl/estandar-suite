<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ImageOptimizer — optimización de imágenes referenciales (postventa).
 *
 * Las fotos que sube el usuario son referenciales: no se necesita la calidad
 * cruda del teléfono. Se redimensionan a resolución de pantalla (máx 1600px en
 * su lado mayor) y se re-comprimen (JPEG calidad ~80), reduciendo mucho el peso.
 *
 * Usa WP_Image_Editor (GD/Imagick) de WordPress. Si no hay editor disponible,
 * devuelve la imagen sin tocar (no rompe la subida). Idempotente: la versión
 * optimizada se guarda una sola vez junto a la original.
 */
class ImageOptimizer
{
	const MAX_DIMENSION = 1600;
	const QUALITY       = 80;

	/** ¿Es una URL o ruta de imagen que este optimizador maneja? */
	public static function is_supported( string $path, string $mime = '' ): bool
	{
		if ( $mime !== '' ) {
			return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true );
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true );
	}

	/**
	 * Optimiza una imagen ya guardada en disco (in-place reemplaza por la
	 * versión optimizada en un archivo adjunto `-opt`).
	 *
	 * @return array{path:string,name:string,type:string,size:int,optimized:bool}
	 */
	public static function optimize_file( string $path, string $name, string $mime ): array
	{
		if ( ! self::is_supported( $path, $mime ) || ! file_exists( $path ) ) {
			return array( 'path' => $path, 'name' => $name, 'type' => $mime, 'size' => (int) @filesize( $path ), 'optimized' => false );
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return array( 'path' => $path, 'name' => $name, 'type' => $mime, 'size' => (int) @filesize( $path ), 'optimized' => false );
		}

		$size = $editor->get_size();
		if ( ! is_array( $size ) ) {
			return array( 'path' => $path, 'name' => $name, 'type' => $mime, 'size' => (int) @filesize( $path ), 'optimized' => false );
		}

		// Solo redimensiona si excede el máximo (no agranda).
		$w = (int) ( $size['width'] ?? 0 );
		$h = (int) ( $size['height'] ?? 0 );
		if ( $w > self::MAX_DIMENSION || $h > self::MAX_DIMENSION ) {
			$editor->resize( min( self::MAX_DIMENSION, $w ), min( self::MAX_DIMENSION, $h ), false );
		}

		$editor->set_quality( self::QUALITY );

		$target = preg_replace( '/\.(jpe?g|png|webp)$/i', '-opt.' . ( $mime === 'image/png' ? 'png' : 'jpg' ), $path );
		$saved  = $editor->save( $target, $mime === 'image/png' ? 'image/png' : 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return array( 'path' => $path, 'name' => $name, 'type' => $mime, 'size' => (int) @filesize( $path ), 'optimized' => false );
		}

		$opt_path = (string) $saved['path'];
		// Solo reemplaza la original si la optimizada pesa menos (protección).
		if ( (int) @filesize( $opt_path ) < (int) @filesize( $path ) ) {
			@unlink( $path );
			@rename( $opt_path, $path );
			return array( 'path' => $path, 'name' => $name, 'type' => $mime, 'size' => (int) @filesize( $path ), 'optimized' => true );
		}

		@unlink( $opt_path );
		return array( 'path' => $path, 'name' => $name, 'type' => $mime, 'size' => (int) @filesize( $path ), 'optimized' => false );
	}

	/**
	 * Optimiza una imagen referida por URL (ya subida, p.ej. en uploads de GF):
	 * localiza el archivo, genera una versión `-opt` si no existe, y devuelve la
	 * URL de la versión optimizada. Best-effort: si no se puede, devuelve la
	 * URL original.
	 */
	public static function optimize_url( string $url ): string
	{
		$url = trim( (string) $url );
		if ( $url === '' ) {
			return '';
		}
		$dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => '', 'baseurl' => '' );
		$base_dir = (string) ( $dir['basedir'] ?? '' );
		$base_url = (string) ( $dir['baseurl'] ?? '' );
		if ( $base_dir === '' || $base_url === '' ) {
			return $url;
		}

		// Solo procesa URLs dentro de uploads.
		if ( strpos( $url, $base_url ) !== 0 ) {
			return $url;
		}
		$rel   = substr( $url, strlen( $base_url ) );
		$path  = $base_dir . $rel;
		if ( ! file_exists( $path ) || ! self::is_supported( $path ) ) {
			return $url;
		}

		$mime = (string) wp_check_filetype( $path )['type'];
		if ( ! self::is_supported( $path, $mime ) ) {
			return $url;
		}

		$opt_path = preg_replace( '/\.(jpe?g|png|webp)$/i', '-opt.' . ( $mime === 'image/png' ? 'png' : 'jpg' ), $path );
		if ( file_exists( $opt_path ) ) {
			$opt_url = str_replace( $base_dir, $base_url, $opt_path );
			return esc_url_raw( $opt_url );
		}

		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return $url;
		}
		$size = $editor->get_size();
		$w = (int) ( is_array( $size ) ? ( $size['width'] ?? 0 ) : 0 );
		$h = (int) ( is_array( $size ) ? ( $size['height'] ?? 0 ) : 0 );
		if ( $w > self::MAX_DIMENSION || $h > self::MAX_DIMENSION ) {
			$editor->resize( min( self::MAX_DIMENSION, $w ), min( self::MAX_DIMENSION, $h ), false );
		}
		$editor->set_quality( self::QUALITY );
		$saved = $editor->save( $opt_path, $mime === 'image/png' ? 'image/png' : 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( (string) $saved['path'] ) ) {
			return $url;
		}
		// Si no redujo el peso, usa la original (la optimizada se descarta).
		if ( (int) @filesize( (string) $saved['path'] ) >= (int) @filesize( $path ) ) {
			@unlink( (string) $saved['path'] );
			return $url;
		}
		return esc_url_raw( str_replace( $base_dir, $base_url, (string) $saved['path'] ) );
	}
}
