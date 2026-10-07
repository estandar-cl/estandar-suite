<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Publish {

	public static function post_type(): string {
		return (string) Settings::get( 'general', 'post_type', 'noticia' );
	}

	public static function taxonomy(): string {
		return (string) Settings::get( 'general', 'taxonomia', 'tema' );
	}

	public static function register(): void {
		$pt = self::post_type();
		$tx = self::taxonomy();

		if ( $pt !== '' && ! post_type_exists( $pt ) ) {
			register_post_type(
				$pt,
				array(
					'labels'        => array( 'name' => 'Noticias', 'singular_name' => 'Noticia', 'menu_name' => 'Noticias', 'add_new_item' => 'Añadir noticia', 'edit_item' => 'Editar noticia' ),
					'public'        => true,
					'has_archive'   => 'noticias',
					'rewrite'       => array( 'slug' => 'noticias', 'with_front' => false ),
					'menu_icon'     => 'dashicons-media-document',
					'menu_position' => 5,
					'show_in_rest'  => true,
					'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'custom-fields' ),
					'taxonomies'    => array( $tx ),
				)
			);
		}

		if ( $tx !== '' && ! taxonomy_exists( $tx ) ) {
			register_taxonomy(
				$tx,
				array( $pt ),
				array(
					'labels'       => array( 'name' => 'Temas', 'singular_name' => 'Tema' ),
					'public'       => true,
					'hierarchical' => false,
					'show_in_rest' => true,
					'rewrite'      => array( 'slug' => $tx ),
				)
			);
		}
	}

	public static function publish( array $data ): array {
		self::register();
		$pt   = self::post_type();
		$tx   = self::taxonomy();
		$slug = Render::slug( $data );
		$args = array(
			'post_title'   => Render::titulo( $data ),
			'post_name'    => $slug,
			'post_content' => Render::html( $data ),
			'post_excerpt' => Render::excerpt( $data ),
			'post_status'  => 'publish',
			'post_type'    => $pt,
			'post_author'  => get_current_user_id() ?: 1,
		);

		$existing = get_page_by_path( $slug, OBJECT, $pt );
		if ( $existing ) {
			$args['ID'] = $existing->ID;
			$id         = wp_update_post( $args, true );
		} else {
			$id = wp_insert_post( $args, true );
		}
		if ( is_wp_error( $id ) ) {
			return array( 'ok' => false, 'error' => $id->get_error_message() );
		}

		$terms = array();
		foreach ( (array) ( $data['temas'] ?? array() ) as $tslug => $t ) {
			if ( ! empty( $t['items'] ) ) {
				$terms[] = (string) ( $t['nombre'] ?? $tslug );
			}
		}
		if ( $terms && $tx !== '' ) {
			wp_set_object_terms( (int) $id, $terms, $tx, false );
		}

		$cat = trim( (string) Settings::get( 'general', 'categoria', '' ) );
		if ( $cat !== '' ) {
			$term = get_term_by( 'name', $cat, 'category' );
			if ( $term ) {
				wp_set_post_categories( (int) $id, array( (int) $term->term_id ) );
			}
		}

		return array( 'ok' => true, 'id' => (int) $id, 'url' => get_permalink( (int) $id ) );
	}

	public static function find( string $slug ): int {
		$post = get_page_by_path( $slug, OBJECT, self::post_type() );
		return $post ? (int) $post->ID : 0;
	}
}
