<?php
/**
 * Estandar Studio — Admin: gestión mínima de las páginas code-first.
 *
 * @package Estandar\Studio
 */

namespace Estandar\Studio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const SLUG = 'estandar-studio';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_estandar_studio_save', array( __CLASS__, 'handle_save' ) );
	}

	public static function menu(): void {
		add_menu_page(
			'Estandar Studio',
			'Estandar Studio',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-layout',
			58
		);
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permiso.' );
		}
		check_admin_referer( 'estandar_studio_save' );

		$slug = Pages::sanitize_slug( (string) ( $_POST['slug'] ?? '' ) );
		if ( $slug === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&error=slug' ) );
			exit;
		}
		$res = Pages::upsert(
			$slug,
			array(
				'title'  => sanitize_text_field( (string) ( $_POST['title'] ?? '' ) ),
				'status' => in_array( ( $_POST['status'] ?? '' ), array( 'publish', 'draft', 'private' ), true ) ? (string) $_POST['status'] : 'publish',
				'html'   => (string) wp_unslash( $_POST['html'] ?? '' ),
				'css'    => (string) wp_unslash( $_POST['css'] ?? '' ),
				'js'     => (string) wp_unslash( $_POST['js'] ?? '' ),
			)
		);
		$args = is_wp_error( $res ) ? array( 'error' => 'save' ) : array( 'slug' => $slug, 'saved' => 1 );
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = isset( $_GET['slug'] ) ? Pages::sanitize_slug( (string) $_GET['slug'] ) : '';
		$bundle  = $current !== '' ? Pages::read( $current ) : array( 'html' => '', 'css' => '', 'js' => '' );
		$post    = $current !== '' ? Pages::post_for_slug( $current ) : null;
		$title    = $post ? $post->post_title : '';
		$status   = $post ? $post->post_status : 'publish';
		$all      = Pages::all();
		$saved    = isset( $_GET['saved'] );

		echo '<div class="wrap"><h1>Estandar Studio — Páginas</h1>';
		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>Guardado.</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>Error al guardar.</p></div>';
		}

		echo '<p>Páginas de alto contenido visual (HTML/CSS/JS) servidas por WordPress con las features del Suite inyectadas en los puntos de conversión.</p>';

		echo '<h2>Existentes</h2><ul style="margin-left:16px;list-style:disc">';
		if ( ! $all ) {
			echo '<li><em>ninguna todavía</em></li>';
		}
		foreach ( $all as $p ) {
			$url = get_permalink( $p );
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&slug=' . $p->post_name ) ) . '">'
				. esc_html( $p->post_title ) . '</a> <code>' . esc_html( $p->post_name ) . '</code> [' . esc_html( $p->post_status ) . '] '
				. '· <a href="' . esc_url( $url ) . '" target="_blank">ver</a></li>';
		}
		echo '</ul>';

		echo '<h2>' . ( $current !== '' ? 'Editar: ' . esc_html( $current ) : 'Nueva página' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'estandar_studio_save' );
		echo '<input type="hidden" name="action" value="estandar_studio_save">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th><label for="es-title">Título</label></th><td><input id="es-title" name="title" type="text" class="regular-text" value="' . esc_attr( $title ) . '"></td></tr>';
		echo '<tr><th><label for="es-slug">Slug</label></th><td><input id="es-slug" name="slug" type="text" class="regular-text" value="' . esc_attr( $current ) . '"' . ( $current !== '' ? ' readonly' : '' ) . '> <p class="description">URL: /' . esc_html( Pages::base_slug() ) . '/&lt;slug&gt;/</p></td></tr>';
		echo '<tr><th><label for="es-status">Estado</label></th><td><select id="es-status" name="status">';
		foreach ( array( 'publish' => 'Publicada', 'draft' => 'Borrador', 'private' => 'Privada' ) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $status, $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="es-html">index.html</label></th><td><textarea id="es-html" name="html" rows="14" class="large-text code" spellcheck="false">' . esc_textarea( $bundle['html'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="es-css">style.css</label></th><td><textarea id="es-css" name="css" rows="8" class="large-text code" spellcheck="false">' . esc_textarea( $bundle['css'] ) . '</textarea></td></tr>';
		echo '<tr><th><label for="es-js">app.js</label></th><td><textarea id="es-js" name="js" rows="8" class="large-text code" spellcheck="false">' . esc_textarea( $bundle['js'] ) . '</textarea></td></tr>';
		echo '</table>';
		submit_button( 'Guardar' );
		echo '</form>';

		echo '<h2>Marcadores</h2><ul style="margin-left:16px;list-style:disc">'
			. '<li><code>{{form:ID}}</code> — formulario del Suite (id del form de capture)</li>'
			. '<li><code>{{chatbot}}</code> — burbuja de chatbot</li>'
			. '<li><code>{{shortcode:[ ... ]}}</code> — cualquier shortcode</li>'
			. '<li><code>{{wa:56912345678}}</code> — enlace de WhatsApp</li>'
			. '<li><code>{{home}}</code> — URL del sitio</li>'
			. '</ul>';
		echo '</div>';
	}
}
