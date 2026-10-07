<?php
/**
 * Estandar Studio — WP-CLI: publicar/actualizar bundles code-first desde la IA.
 *
 *   wp estandar-studio create --title="..." --slug=... [--status=publish]
 *        [--html-file=... --css-file=... --js-file=...] [--html="..."]
 *   wp estandar-studio set-bundle <slug> [--html-file=... --css-file=... --js-file=...]
 *   wp estandar-studio list
 *   wp estandar-studio dir <slug>
 *
 * @package Estandar\Studio
 */

namespace Estandar\Studio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cli {

	private function read_arg( $assoc, string $file_key, string $inline_key ): ?string {
		if ( ! empty( $assoc[ $file_key ] ) ) {
			$path = (string) $assoc[ $file_key ];
			if ( ! is_file( $path ) ) {
				\WP_CLI::error( "No existe el archivo: {$path}" );
			}
			return (string) file_get_contents( $path ); // phpcs:ignore
		}
		if ( isset( $assoc[ $inline_key ] ) ) {
			return (string) $assoc[ $inline_key ];
		}
		return null;
	}

	/**
	 * Crea (o actualiza) una página Studio.
	 *
	 * ## OPTIONS
	 * [--title=<texto>]
	 * [--slug=<slug>]
	 * [--status=<publish|draft|private>]
	 * [--html-file=<ruta>] [--html=<html>]
	 * [--css-file=<ruta>]  [--css=<css>]
	 * [--js-file=<ruta>]   [--js=<js>]
	 */
	public function create( $args, $assoc ): void {
		$slug = Pages::sanitize_slug( (string) ( $assoc['slug'] ?? ( $args[0] ?? '' ) ) );
		$payload = array(
			'title'  => (string) ( $assoc['title'] ?? ucfirst( str_replace( '-', ' ', $slug ) ) ),
			'status' => (string) ( $assoc['status'] ?? 'publish' ),
		);
		foreach ( array( array( 'html-file', 'html' ), array( 'css-file', 'css' ), array( 'js-file', 'js' ) ) as $pair ) {
			$val = $this->read_arg( $assoc, $pair[0], $pair[1] );
			if ( $val !== null ) {
				$payload[ $pair[1] ] = $val;
			}
		}
		$res = Pages::upsert( $slug, $payload );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_message() );
		}
		$post = get_post( (int) $res );
		\WP_CLI::success( "Página '{$slug}' lista (ID {$res}). URL: " . get_permalink( $post ) );
	}

	/**
	 * Actualiza el bundle de una página existente.
	 *
	 * ## OPTIONS
	 * <slug>
	 * [--html-file=<ruta>] [--html=<html>]
	 * [--css-file=<ruta>]  [--css=<css>]
	 * [--js-file=<ruta>]   [--js=<js>]
	 */
	public function set_bundle( $args, $assoc ): void {
		$slug = Pages::sanitize_slug( (string) ( $args[0] ?? '' ) );
		if ( $slug === '' || ! Pages::post_for_slug( $slug ) ) {
			\WP_CLI::error( "No existe la página '{$slug}'." );
		}
		$parts = array();
		foreach ( array( array( 'html-file', 'html' ), array( 'css-file', 'css' ), array( 'js-file', 'js' ) ) as $pair ) {
			$val = $this->read_arg( $assoc, $pair[0], $pair[1] );
			if ( $val !== null ) {
				$parts[ $pair[1] ] = $val;
			}
		}
		if ( ! $parts ) {
			\WP_CLI::error( 'Nada que actualizar (usa --html-file/--css-file/--js-file).' );
		}
		$res = Pages::write( $slug, $parts );
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_message() );
		}
		\WP_CLI::success( "Bundle de '{$slug}' actualizado." );
	}

	/**
	 * Lista las páginas Studio.
	 */
	public function list( $args, $assoc ): void {
		$rows = array();
		foreach ( Pages::all() as $p ) {
			$rows[] = array(
				'ID'     => $p->ID,
				'slug'   => $p->post_name,
				'title'  => $p->post_title,
				'status' => $p->post_status,
				'url'    => get_permalink( $p ),
			);
		}
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin páginas Studio.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'slug', 'title', 'status', 'url' ) );
	}

	/**
	 * Muestra la carpeta del bundle de una página.
	 *
	 * ## OPTIONS
	 * <slug>
	 */
	public function dir( $args, $assoc ): void {
		$slug = Pages::sanitize_slug( (string) ( $args[0] ?? '' ) );
		\WP_CLI::log( Pages::bundle_dir( $slug ) );
	}
}
