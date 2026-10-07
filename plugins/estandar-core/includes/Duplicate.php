<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Duplicate — duplicar entradas, páginas y cualquier CPT (port del legacy
 * estandar-duplicate-post).
 *
 * Capacidad nativa v2 que reemplaza al plugin legacy: añade la acción
 * "Duplicar" en las filas de posts/páginas/CPTs habilitados y duplica el
 * contenido con opciones configurables (tipos habilitados, estado del
 * duplicado, autor y sufijo del título).
 *
 * Configuración en `estandar_suite_core_duplicate_config` (antes
 * `estandar_duplicate_post_settings`). Migración automática en `init()`.
 */
class Duplicate
{
	const OPTION       = 'estandar_suite_core_duplicate_config';
	const LEGACY_OPTION = 'estandar_duplicate_post_settings';
	const NONCE_ACTION  = 'estandar_core_duplicate_post_action';
	const CAPABILITY    = 'edit_posts';

	const DEFAULTS = array(
		'enabled_post_types' => array( 'post', 'page' ),
		'duplicate_status'   => 'draft', // draft | same
		'author_mode'        => 'current', // current | original
		'title_suffix'       => ' (Copia)',
	);

	public static function init(): void
	{
		self::maybe_migrate_legacy();

		add_filter( 'post_row_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );
		add_action( 'admin_action_estandar_core_duplicate_post', array( __CLASS__, 'handle_duplicate' ) );

		// Submenú en Estandar Suite (settings).
		add_action( 'estandar_suite_admin_register', array( __CLASS__, 'register_submenu' ) );
	}

	/** Migra la configuración del legacy a la v2 (una sola vez, no destructivo). */
	public static function maybe_migrate_legacy(): void
	{
		if ( get_option( self::OPTION, null ) !== null ) {
			return;
		}
		$legacy = get_option( self::LEGACY_OPTION, array() );
		if ( is_array( $legacy ) && $legacy ) {
			update_option( self::OPTION, wp_parse_args( $legacy, self::DEFAULTS ), false );
		}
	}

	/** PURE: settings con defaults (funciona sin WP en tests). */
	public static function get_settings(): array
	{
		$opts = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$opts = is_array( $opts ) ? $opts : array();
		if ( function_exists( 'wp_parse_args' ) ) {
			return wp_parse_args( $opts, self::DEFAULTS );
		}
		return array_merge( self::DEFAULTS, $opts );
	}

	public static function register_submenu(): void
	{
		\add_submenu_page(
			'estandar-suite',
			'Duplicate Post',
			'Duplicate Post',
			'manage_options',
			'estandar-suite-duplicate',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function render_settings_page(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings   = self::get_settings();
		$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
		?>
		<div class="wrap estandar-admin">
			<?php if ( function_exists( 'estandar_render_header' ) ) : ?>
				<?php estandar_render_header( array(
					'title'    => 'Duplicate Post',
					'subtitle' => 'Duplica entradas, páginas y CPTs con opciones configurables.',
				) ); ?>
			<?php else : ?>
				<h1>Duplicate Post</h1>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Tipos de contenido habilitados</th>
						<td>
							<?php foreach ( $post_types as $pt ) : ?>
								<label style="display:block;">
									<input type="checkbox"
										name="<?php echo esc_attr( self::OPTION ); ?>[enabled_post_types][]"
										value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, $settings['enabled_post_types'], true ) ); ?>
									/>
									<?php echo esc_html( $pt->labels->singular_name ); ?> (<?php echo esc_html( $pt->name ); ?>)
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Estado del duplicado</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[duplicate_status]">
								<option value="draft" <?php selected( $settings['duplicate_status'], 'draft' ); ?>>Siempre borrador</option>
								<option value="same" <?php selected( $settings['duplicate_status'], 'same' ); ?>>Mismo estado del original</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Autor del duplicado</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>[author_mode]">
								<option value="current" <?php selected( $settings['author_mode'], 'current' ); ?>>Usuario actual</option>
								<option value="original" <?php selected( $settings['author_mode'], 'original' ); ?>>Mismo autor del original</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Sufijo del título</th>
						<td>
							<input type="text"
								name="<?php echo esc_attr( self::OPTION ); ?>[title_suffix]"
								value="<?php echo esc_attr( $settings['title_suffix'] ); ?>"
								class="regular-text"
							/>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/** PURE: ¿el post type está habilitado? */
	public static function is_enabled_type( string $post_type ): bool
	{
		$s = self::get_settings();
		return in_array( $post_type, $s['enabled_post_types'], true );
	}

	/** Filtro de filas: inserta la acción "Duplicar" tras "Editar". */
	public static function add_row_actions( $actions, $post )
	{
		// Guarda de contexto admin (frontend no toca), testeable vía filtro.
		$is_admin = apply_filters( 'estandar_core_duplicate_is_admin', is_admin() );
		if ( ! $is_admin || ! $post ) {
			return $actions;
		}
		if ( ! self::is_enabled_type( (string) $post->post_type ) ) {
			return $actions;
		}
		if ( ! current_user_can( self::CAPABILITY, $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg( array(
				'action' => 'estandar_core_duplicate_post',
				'post'   => $post->ID,
			), admin_url( 'admin.php' ) ),
			self::NONCE_ACTION
		);

		$new = array();
		foreach ( (array) $actions as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'edit' ) {
				$new['estandar_core_duplicate'] = '<a href="' . esc_url( $url ) . '">Duplicar</a>';
			}
		}
		if ( ! isset( $new['estandar_core_duplicate'] ) ) {
			$new['estandar_core_duplicate'] = '<a href="' . esc_url( $url ) . '">Duplicar</a>';
		}
		return $new;
	}

	/** Handler admin_action: duplica el post. */
	public static function handle_duplicate(): void
	{
		if ( ! is_admin() ) {
			wp_die( 'Acceso no permitido.' );
		}
		check_admin_referer( self::NONCE_ACTION );

		$post_id = absint( (int) ( $_GET['post'] ?? 0 ) );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			wp_die( 'Contenido no encontrado.' );
		}
		if ( ! self::is_enabled_type( (string) $post->post_type ) ) {
			wp_die( 'Este tipo de contenido no está habilitado.' );
		}
		if ( ! current_user_can( self::CAPABILITY, $post_id ) ) {
			wp_die( 'Sin permisos.' );
		}

		$settings   = self::get_settings();
		$new_status = ( $settings['duplicate_status'] === 'same' )
			? $post->post_status
			: 'draft';
		$new_author = ( $settings['author_mode'] === 'original' )
			? (int) $post->post_author
			: get_current_user_id();

		$new_post_id = wp_insert_post( array(
			'post_type'    => $post->post_type,
			'post_status'  => $new_status,
			'post_title'   => (string) $post->post_title . (string) $settings['title_suffix'],
			'post_content' => (string) $post->post_content,
			'post_excerpt' => (string) $post->post_excerpt,
			'post_author'  => $new_author,
			'post_parent'  => (int) $post->post_parent,
			'menu_order'   => (int) $post->menu_order,
		), true );

		if ( is_wp_error( $new_post_id ) ) {
			wp_die( $new_post_id->get_error_message() );
		}

		// Taxonomías.
		foreach ( get_object_taxonomies( (string) $post->post_type ) as $tax ) {
			$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $new_post_id, $terms, $tax );
			}
		}

		// Meta (sin _thumbnail_id; el thumbnail se setea aparte).
		self::duplicate_meta( $post_id, $new_post_id );

		// Thumbnail.
		if ( $thumb = get_post_thumbnail_id( $post_id ) ) {
			set_post_thumbnail( $new_post_id, $thumb );
		}

		wp_safe_redirect(
			( $new_status === 'draft' )
				? admin_url( 'post.php?action=edit&post=' . $new_post_id )
				: admin_url( 'edit.php?post_type=' . $post->post_type )
		);
		exit;
	}

	/** Copia el postmeta (excluyendo llaves internas y _thumbnail_id). */
	public static function duplicate_meta( int $from, int $to ): void
	{
		global $wpdb;
		$exclude = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_thumbnail_id' );

		$metas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
				$from
			)
		);

		foreach ( $metas as $m ) {
			if ( in_array( $m->meta_key, $exclude, true ) ) {
				continue;
			}
			add_post_meta( $to, $m->meta_key, maybe_unserialize( $m->meta_value ) );
		}
	}
}
