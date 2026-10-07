<?php
/**
 * Estandar Studio — Pages: CPT `estandar_page` + almacenamiento del bundle.
 *
 * Un "bundle" es la pieza code-first de una página: index.html (con marcadores de
 * features), style.css y app.js. Vive en uploads/estandar-studio/<slug>/ para que la
 * IA pueda escribirlo/actualizarlo sin tocar el repo ni la BD (salvo el post que lo
 * registra). El render lo sirve WP (ver Render).
 *
 * @package Estandar\Studio
 */

namespace Estandar\Studio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pages {

	const POST_TYPE = 'estandar_page';
	const DIR       = 'estandar-studio';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'           => 'Páginas Studio',
				'public'          => true,
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-layout',
				'supports'        => array( 'title', 'editor', 'thumbnail', 'revisions' ),
				'has_archive'     => false,
				'rewrite'         => array( 'slug' => self::base_slug() ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
	}

	public static function base_slug(): string {
		$base = (string) get_option( 'estandar_studio_base', 'estudio' );
		$base = trim( $base, '/' );
		return $base !== '' ? $base : 'estudio';
	}

	/**
	 * Carpeta del bundle para un slug. No crea nada.
	 */
	public static function bundle_dir( string $slug ): string {
		$slug = self::sanitize_slug( $slug );
		$up   = wp_upload_dir();
		return rtrim( $up['basedir'], '/' ) . '/' . self::DIR . '/' . $slug;
	}

	public static function sanitize_slug( string $slug ): string {
		$slug = trim( $slug );
		if ( function_exists( 'sanitize_title' ) ) {
			$slug = sanitize_title( $slug ); // maneja acentos/ñ y mayúsculas.
		} else {
			$slug = strtolower( $slug );
			$slug = preg_replace( '/[^a-z0-9\-]+/', '-', $slug );
		}
		$slug = trim( (string) $slug, '-' );
		return $slug !== '' ? $slug : 'pagina';
	}

	/**
	 * Lee el bundle. Devuelve ['html'=>,'css'=>,'js'=>] ('' si falta cada pieza).
	 */
	public static function read( string $slug ): array {
		$dir = self::bundle_dir( $slug );
		$out = array( 'html' => '', 'css' => '', 'js' => '' );
		foreach ( array( 'html' => 'index.html', 'css' => 'style.css', 'js' => 'app.js' ) as $k => $file ) {
			$path = $dir . '/' . $file;
			if ( is_file( $path ) ) {
				$out[ $k ] = (string) file_get_contents( $path );
			}
		}
		return $out;
	}

	/**
	 * Escribe el bundle (crea la carpeta). Devuelve true|WP_Error.
	 */
	public static function write( string $slug, array $parts ) {
		$dir = self::bundle_dir( $slug );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'studio_dir', 'No se pudo crear la carpeta del bundle: ' . $dir );
		}
		$map = array( 'html' => 'index.html', 'css' => 'style.css', 'js' => 'app.js' );
		foreach ( $map as $k => $file ) {
			if ( array_key_exists( $k, $parts ) ) {
				$ok = file_put_contents( $dir . '/' . $file, (string) $parts[ $k ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false === $ok ) {
					return new \WP_Error( 'studio_write', 'No se pudo escribir ' . $file );
				}
			}
		}
		return true;
	}

	public static function exists( string $slug ): bool {
		return is_dir( self::bundle_dir( $slug ) );
	}

	public static function post_for_slug( string $slug ): ?\WP_Post {
		$q = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'name'           => self::sanitize_slug( $slug ),
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
			)
		);
		return $q ? $q[0] : null;
	}

	/**
	 * Crea o actualiza el post del CPT y su bundle.
	 */
	public static function upsert( string $slug, array $args = array() ) {
		$slug  = self::sanitize_slug( $slug );
		$title = (string) ( $args['title'] ?? ucfirst( str_replace( '-', ' ', $slug ) ) );
		$post  = self::post_for_slug( $slug );

		$postarr = array(
			'post_type'   => self::POST_TYPE,
			'post_name'   => $slug,
			'post_title'  => $title,
			'post_status' => (string) ( $args['status'] ?? ( $post ? $post->post_status : 'publish' ) ),
		);
		if ( $post ) {
			$postarr['ID'] = $post->ID;
			$id = wp_update_post( $postarr, true );
		} else {
			$id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( array_key_exists( 'html', $args ) || array_key_exists( 'css', $args ) || array_key_exists( 'js', $args ) ) {
			$current = self::read( $slug );
			$parts   = array(
				'html' => array_key_exists( 'html', $args ) ? $args['html'] : $current['html'],
				'css'  => array_key_exists( 'css', $args ) ? $args['css'] : $current['css'],
				'js'   => array_key_exists( 'js', $args ) ? $args['js'] : $current['js'],
			);
			$w = self::write( $slug, $parts );
			if ( is_wp_error( $w ) ) {
				return $w;
			}
		}
		return (int) $id;
	}

	public static function all(): array {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'private' ),
			)
		);
	}
}
