<?php
/**
 * Plugin Name: Estandar Duplicate Post
 * Plugin URI: https://www.estandar.cl
 * Description: Duplicar entradas, páginas y cualquier CPT con opciones configurables.
 * Version: 13.0.173
 * Author: Luis Valdés de Estandar Studios CL
 * Author URI: https://www.estandar.cl
 * License: GPLv2 or later
 * Update URI: https://updates.estandar.cl
 */

if ( ! defined('ABSPATH') ) exit;

class Estandar_Duplicate_Post {

	const OPTION_KEY   = 'estandar_duplicate_post_settings';
	const NONCE_ACTION = 'estandar_duplicate_post_action';
	const CAPABILITY   = 'edit_posts';

	private $defaults = [
		'enabled_post_types' => ['post','page'],
		'duplicate_status'   => 'draft', // draft | same
		'author_mode'        => 'current', // current | original
		'title_suffix'       => ' (Copia)',
	];

	public function __construct() {
		add_action('admin_init',  [$this, 'register_settings']);
		add_action('admin_menu',  [$this, 'register_settings_page']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

		add_filter('post_row_actions', [$this, 'add_row_actions'], 10, 2);
		add_filter('page_row_actions', [$this, 'add_row_actions'], 10, 2);

		add_action('admin_action_estandar_duplicate_post', [$this, 'handle_duplicate']);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( strpos( (string) $hook, 'estandar-duplicate-post' ) === false ) {
			return;
		}

		if ( function_exists( 'estandar_core_enqueue_admin_ui' ) ) {
			estandar_core_enqueue_admin_ui();
		}
	}

	/* ======================================================
	 * SETTINGS
	 * ====================================================== */

	public function get_settings() {
		$opts = get_option(self::OPTION_KEY, []);
		return wp_parse_args($opts, $this->defaults);
	}

	public function register_settings() {
		register_setting(self::OPTION_KEY, self::OPTION_KEY);
	}

	public function register_settings_page() {
		$core_active = function_exists( 'primer_is_active' ) && primer_is_active();
		if ( $core_active && function_exists( 'estandar_register_submenu' ) ) {
			estandar_register_submenu(
				[
					'slug'       => 'estandar-duplicate-post',
					'title'      => 'Estandar Duplicate Post',
					'menu_title' => 'Duplicate Post',
					'capability' => 'manage_options',
					'callback'   => [ $this, 'render_settings_page' ],
				]
			);
			return;
		}

		// Fallback (sin Core): mantener accesible bajo Ajustes.
		add_options_page(
			'Estandar Duplicate Post',
			'Estandar Duplicate Post',
			'manage_options',
			'estandar-duplicate-post',
			[ $this, 'render_settings_page' ]
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can('manage_options') ) return;

		$settings   = $this->get_settings();
		$post_types = get_post_types(['show_ui' => true], 'objects');
		?>
		<?php if ( function_exists( 'estandar_render_layout_start' ) ) : ?>
			<?php estandar_render_layout_start(); ?>
		<?php else : ?>
			<div class="wrap estandar-admin">
		<?php endif; ?>

		<?php if ( function_exists( 'estandar_render_header' ) ) : ?>
			<?php
			estandar_render_header(
				[
					'title'    => 'Duplicate Post',
					'subtitle' => 'Duplica entradas, páginas y CPTs con opciones configurables.',
				]
			);
			?>
		<?php else : ?>
			<h1>Estandar Duplicate Post</h1>
		<?php endif; ?>

		<?php if ( function_exists( 'estandar_render_surface_start' ) ) : ?>
			<?php estandar_render_surface_start(); ?>
		<?php elseif ( function_exists( 'primer_is_active' ) && primer_is_active() ) : ?>
			<div class="estandar-surface">
		<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields(self::OPTION_KEY); ?>

				<table class="form-table" role="presentation">

					<tr>
						<th scope="row">Tipos de contenido habilitados</th>
						<td>
							<?php foreach ($post_types as $pt): ?>
								<label style="display:block;">
									<input type="checkbox"
										name="<?php echo self::OPTION_KEY; ?>[enabled_post_types][]"
										value="<?php echo esc_attr($pt->name); ?>"
										<?php checked(in_array($pt->name, $settings['enabled_post_types'], true)); ?>
									/>
									<?php echo esc_html($pt->labels->singular_name); ?> (<?php echo esc_html($pt->name); ?>)
								</label>
							<?php endforeach; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">Estado del duplicado</th>
						<td>
							<select name="<?php echo self::OPTION_KEY; ?>[duplicate_status]">
								<option value="draft" <?php selected($settings['duplicate_status'],'draft'); ?>>
									Siempre borrador
								</option>
								<option value="same" <?php selected($settings['duplicate_status'],'same'); ?>>
									Mismo estado del original
								</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">Autor del duplicado</th>
						<td>
							<select name="<?php echo self::OPTION_KEY; ?>[author_mode]">
								<option value="current" <?php selected($settings['author_mode'],'current'); ?>>
									Usuario actual
								</option>
								<option value="original" <?php selected($settings['author_mode'],'original'); ?>>
									Mismo autor del original
								</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">Sufijo del título</th>
						<td>
							<input type="text"
								name="<?php echo self::OPTION_KEY; ?>[title_suffix]"
								value="<?php echo esc_attr($settings['title_suffix']); ?>"
								class="regular-text"
							/>
						</td>
					</tr>

				</table>

				<?php submit_button(); ?>
			</form>

		<?php if ( function_exists( 'estandar_render_surface_end' ) ) : ?>
			<?php estandar_render_surface_end(); ?>
		<?php elseif ( function_exists( 'primer_is_active' ) && primer_is_active() ) : ?>
			</div>
		<?php endif; ?>

		<?php if ( function_exists( 'estandar_render_layout_end' ) ) : ?>
			<?php estandar_render_layout_end(); ?>
		<?php else : ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/* ======================================================
	 * UI — ROW ACTIONS
	 * ====================================================== */

	public function add_row_actions($actions, $post) {
		if ( ! is_admin() || ! $post ) return $actions;

		$settings = $this->get_settings();

		if ( ! in_array($post->post_type, $settings['enabled_post_types'], true) ) {
			return $actions;
		}

		if ( ! current_user_can(self::CAPABILITY, $post->ID) ) return $actions;

		$url = wp_nonce_url(
			add_query_arg([
				'action' => 'estandar_duplicate_post',
				'post'   => $post->ID,
			], admin_url('admin.php')),
			self::NONCE_ACTION
		);

		$new = [];
		foreach ($actions as $key => $label) {
			$new[$key] = $label;
			if ($key === 'edit') {
				$new['estandar_duplicate'] = '<a href="'.esc_url($url).'">Duplicar</a>';
			}
		}

		if ( ! isset($new['estandar_duplicate']) ) {
			$new['estandar_duplicate'] = '<a href="'.esc_url($url).'">Duplicar</a>';
		}

		return $new;
	}

	/* ======================================================
	 * DUPLICATION CORE
	 * ====================================================== */

	public function handle_duplicate() {
		if ( ! is_admin() ) wp_die('Acceso no permitido.');

		check_admin_referer(self::NONCE_ACTION);

		$post_id  = absint($_GET['post'] ?? 0);
		$post     = get_post($post_id);
		$settings = $this->get_settings();

		if ( ! $post ) wp_die('Contenido no encontrado.');

		if ( ! in_array($post->post_type, $settings['enabled_post_types'], true) ) {
			wp_die('Este tipo de contenido no está habilitado.');
		}

		if ( ! current_user_can(self::CAPABILITY, $post_id) ) {
			wp_die('Sin permisos.');
		}

		$new_status = ($settings['duplicate_status'] === 'same')
			? $post->post_status
			: 'draft';

		$new_author = ($settings['author_mode'] === 'original')
			? $post->post_author
			: get_current_user_id();

		$new_post_id = wp_insert_post([
			'post_type'    => $post->post_type,
			'post_status'  => $new_status,
			'post_title'   => $post->post_title . $settings['title_suffix'],
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_author'  => $new_author,
			'post_parent'  => $post->post_parent,
			'menu_order'   => $post->menu_order,
		], true);

		if ( is_wp_error($new_post_id) ) {
			wp_die($new_post_id->get_error_message());
		}

		// Taxonomías
		foreach (get_object_taxonomies($post->post_type) as $tax) {
			$terms = wp_get_object_terms($post_id, $tax, ['fields'=>'ids']);
			if ( ! is_wp_error($terms) ) {
				wp_set_object_terms($new_post_id, $terms, $tax);
			}
		}

		// Meta
		$this->duplicate_meta($post_id, $new_post_id);

		// Thumbnail
		if ($thumb = get_post_thumbnail_id($post_id)) {
			set_post_thumbnail($new_post_id, $thumb);
		}

		wp_safe_redirect(
			($new_status === 'draft')
				? admin_url('post.php?action=edit&post='.$new_post_id)
				: admin_url('edit.php?post_type='.$post->post_type)
		);
		exit;
	}

	private function duplicate_meta($from, $to) {
		global $wpdb;

		$exclude = ['_edit_lock','_edit_last','_wp_old_slug','_thumbnail_id'];

		$metas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
				$from
			)
		);

		foreach ($metas as $m) {
			if ( in_array($m->meta_key, $exclude, true) ) continue;
			add_post_meta($to, $m->meta_key, maybe_unserialize($m->meta_value));
		}
	}
}

new Estandar_Duplicate_Post();
