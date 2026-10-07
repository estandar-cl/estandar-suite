<?php
/**
 * Admin principal de Estandar KickOff.
 * Escanea modules/ y carga cada subcarpeta como tab (si está habilitado).
 *
 * @package Estandar_KickOff
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Admin {

	const PAGE_SLUG       = 'estandar-kickoff';
	const CAPABILITY      = 'manage_options';
	const OPTION_MODULES  = 'estandar_kickoff_modules_enabled';

	private static $instance = null;
	private $tabs = [];
	private $all_modules_config = [];
	private $modules_loaded = false;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'load_modules' ], 5 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		$this->load_modules();
	}

	/**
	 * Registra las opciones y handlers de guardado.
	 */
	public function register_settings() {
		register_setting( self::OPTION_MODULES, self::OPTION_MODULES, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_modules_settings' ],
		] );
	}

	/**
	 * Sanitiza el array de módulos habilitados.
	 */
	public function sanitize_modules_settings( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$available = array_keys( $this->discover_all_modules() );
		$result   = [];
		foreach ( $available as $slug ) {
			if ( ! $this->is_module_available( $slug ) ) {
				$result[ $slug ] = 0;
				continue;
			}
			$result[ $slug ] = ! empty( $value[ $slug ] ) ? 1 : 0;
		}
		return $result;
	}

	private function is_plugin_active_file( $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( $plugin_file ) ) {
			return true;
		}

		return function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file );
	}

	private function get_module_conflict_reason( $slug ) {
		$conflicts = apply_filters( 'estandar_kickoff_module_conflicts', [] );

		if ( empty( $conflicts[ $slug ]['plugin'] ) ) {
			return '';
		}

		$plugin = (string) $conflicts[ $slug ]['plugin'];
		if ( ! $this->is_plugin_active_file( $plugin ) ) {
			return '';
		}

		$label = isset( $conflicts[ $slug ]['label'] ) ? (string) $conflicts[ $slug ]['label'] : $plugin;
		return sprintf(
			/* translators: %s: active plugin name. */
			__( 'Bloqueado porque %s ya cubre esta función.', 'estandar-kickoff' ),
			$label
		);
	}

	private function is_module_available( $slug ) {
		if ( in_array( $slug, $this->get_out_of_scope_modules(), true ) ) {
			return false;
		}

		return '' === $this->get_module_conflict_reason( $slug );
	}

	private function get_out_of_scope_modules() {
		return [
			'taxonomy-manager',
		];
	}

	/**
	 * Descubre todos los módulos disponibles (config.php) sin cargar lógica.
	 */
	public function discover_all_modules() {
		if ( ! empty( $this->all_modules_config ) ) {
			return $this->all_modules_config;
		}

		if ( ! is_dir( ESTANDAR_KICKOFF_MODULES_PATH ) ) {
			return [];
		}

		$dirs   = glob( ESTANDAR_KICKOFF_MODULES_PATH . '*', GLOB_ONLYDIR );
		$configs = [];

		foreach ( $dirs as $dir ) {
			$slug = basename( $dir );
			if ( in_array( $slug, $this->get_out_of_scope_modules(), true ) ) {
				continue;
			}

			$config_file = $dir . '/config.php';
			$module_file  = $dir . '/module.php';

			// Preferir config.php (sin efectos secundarios)
			if ( file_exists( $config_file ) ) {
				$config = include $config_file;
			} elseif ( file_exists( $module_file ) ) {
				// Fallback: module.php debe retornar array (sin require previo)
				$config = include $module_file;
			} else {
				continue;
			}

			if ( ! is_array( $config ) || empty( $config['slug'] ) ) {
				continue;
			}

			$configs[ $slug ] = wp_parse_args( $config, [
				'slug'        => $slug,
				'label'       => $slug,
				'description' => '',
				'icon'        => '⚙️',
				'order'       => 100,
			] );
		}

		uasort( $configs, function ( $a, $b ) {
			return ( $a['order'] ?? 100 ) <=> ( $b['order'] ?? 100 );
		} );

		$this->all_modules_config = apply_filters( 'estandar_kickoff_discovered_modules', $configs );
		return $this->all_modules_config;
	}

	/**
	 * Obtiene los módulos habilitados desde la opción.
	 * Por defecto todos están habilitados (nuevos módulos se activan automáticamente).
	 */
	public function get_enabled_modules() {
		$saved = get_option( self::OPTION_MODULES, [] );
		$available = array_keys( $this->discover_all_modules() );
		$enabled = [];
		$defaults = [
			'script-optimization' => false,
			'maintenance-mode'    => false,
			'upload-limits'       => false,
			'redirects'           => false,
		];

		foreach ( $available as $slug ) {
			if ( ! $this->is_module_available( $slug ) ) {
				$enabled[ $slug ] = false;
				continue;
			}

			$default_enabled = $defaults[ $slug ] ?? true;
			$enabled[ $slug ] = isset( $saved[ $slug ] ) ? ! empty( $saved[ $slug ] ) : $default_enabled;
		}

		return $enabled;
	}

	/**
	 * Comprueba si un módulo está habilitado.
	 */
	public function is_module_enabled( $slug ) {
		$enabled = $this->get_enabled_modules();
		return ! empty( $enabled[ $slug ] );
	}

	/**
	 * Escanea modules/ y carga solo los habilitados.
	 */
	public function load_modules() {
		if ( $this->modules_loaded ) {
			return;
		}

		$all = $this->discover_all_modules();
		$enabled = $this->get_enabled_modules();

		$loaded = [];

		foreach ( $all as $slug => $config ) {
			if ( empty( $enabled[ $slug ] ) ) {
				continue;
			}

			$module_file = ESTANDAR_KICKOFF_MODULES_PATH . $slug . '/module.php';
			if ( ! file_exists( $module_file ) ) {
				continue;
			}

			$full_config = include $module_file;
			if ( ! is_array( $full_config ) || empty( $full_config['slug'] ) ) {
				continue;
			}

			$tab = wp_parse_args( $full_config, $config );
			$loaded[ $slug ] = $tab;
		}

		$this->tabs = apply_filters( 'estandar_kickoff_tabs', $loaded );
		$this->modules_loaded = true;
	}

	/**
	 * Obtiene los tabs (Settings + módulos habilitados).
	 */
	public function get_tabs() {
		$settings_tab = [
			'settings' => [
				'slug'   => 'settings',
				'label'  => __( 'Ajustes', 'estandar-kickoff' ),
				'icon'   => '⚙️',
				'order'  => 0,
				'render' => [ $this, 'render_settings_tab' ],
			],
		];

		return $settings_tab + $this->tabs;
	}

	/**
	 * Renderiza el tab de Ajustes.
	 */
	public function render_settings_tab() {
			$all_modules = $this->discover_all_modules();
			$enabled     = $this->get_enabled_modules();
			$blocked     = [];

			foreach ( $all_modules as $slug => $config ) {
			$reason = $this->get_module_conflict_reason( $slug );
			if ( '' !== $reason ) {
				$blocked[ $slug ] = $reason;
			}
		}

		if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ajustes guardados.', 'estandar-kickoff' ) . '</p></div>';
		}
		?>

			<!-- Módulos -->
		<h2><?php esc_html_e( 'Módulos', 'estandar-kickoff' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION_MODULES ); ?>

			<p class="description" style="margin-bottom: 20px;">
				<?php esc_html_e( 'Activa o desactiva las funciones del plugin. Los módulos desactivados no cargarán su código ni aparecerán en el menú.', 'estandar-kickoff' ); ?>
			</p>

			<table class="widefat striped kickoff-modules-table">
				<thead>
					<tr>
						<th style="width: 40px;"><?php esc_html_e( 'Estado', 'estandar-kickoff' ); ?></th>
						<th><?php esc_html_e( 'Módulo', 'estandar-kickoff' ); ?></th>
						<th><?php esc_html_e( 'Descripción', 'estandar-kickoff' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $all_modules as $slug => $config ) : ?>
						<?php $blocked_reason = $blocked[ $slug ] ?? ''; ?>
						<tr class="estandar-module-row" data-module="<?php echo esc_attr( $slug ); ?>">
							<td>
								<input type="hidden" name="<?php echo esc_attr( self::OPTION_MODULES ); ?>[<?php echo esc_attr( $slug ); ?>]" value="0" />
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( self::OPTION_MODULES ); ?>[<?php echo esc_attr( $slug ); ?>]"
										value="1"
										<?php checked( ! empty( $enabled[ $slug ] ) ); ?>
										<?php disabled( '' !== $blocked_reason ); ?>
									/>
								</label>
							</td>
							<td>
								<span class="kickoff-module-icon"><?php echo esc_html( $config['icon'] ?? '' ); ?></span>
								<strong><?php echo esc_html( $config['label'] ); ?></strong>
								<span class="estandar-module-risk-mount"></span>
							</td>
							<td>
								<?php echo esc_html( $config['description'] ?? '' ); ?>
								<?php if ( '' !== $blocked_reason ) : ?>
									<br><em><?php echo esc_html( $blocked_reason ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( empty( $all_modules ) ) : ?>
				<p><em><?php esc_html_e( 'No hay módulos disponibles.', 'estandar-kickoff' ); ?></em></p>
			<?php endif; ?>

			<p class="submit">
				<?php submit_button( __( 'Guardar cambios', 'estandar-kickoff' ) ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Registra el ítem en el menú lateral del admin.
	 */
	public function register_menu() {
		if ( function_exists( 'primer_is_active' ) && primer_is_active() && function_exists( 'estandar_register_submenu' ) ) {
			estandar_register_submenu(
				[
					'slug'       => self::PAGE_SLUG,
					'title'      => __( 'Estandar KickOff', 'estandar-kickoff' ),
					'menu_title' => __( 'KickOff', 'estandar-kickoff' ),
					'capability' => self::CAPABILITY,
					'callback'   => [ $this, 'render_page' ],
				]
			);
			return;
		}

		add_menu_page(
			__( 'Estandar KickOff', 'estandar-kickoff' ),
			__( 'KickOff', 'estandar-kickoff' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
			'dashicons-admin-tools',
			80
		);

	}

	/**
	 * Assets del admin.
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		$css = ESTANDAR_KICKOFF_PATH . 'assets/admin.css';
		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'estandar-kickoff-admin-dashboard-style',
				ESTANDAR_KICKOFF_URL . 'assets/admin.css',
				[],
				ESTANDAR_KICKOFF_VERSION
			);
		}

		$js = ESTANDAR_KICKOFF_PATH . 'assets/admin.js';
		if ( file_exists( $js ) ) {
			wp_enqueue_script(
				'estandar-kickoff-admin-dashboard-script',
				ESTANDAR_KICKOFF_URL . 'assets/admin.js',
				[],
				ESTANDAR_KICKOFF_VERSION,
				true
			);
		}

		if ( function_exists( 'estandar_core_enqueue_admin_ui' ) ) {
			estandar_core_enqueue_admin_ui();
		}
	}

	/**
	 * Renderiza la página con tabs.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder.', 'estandar-kickoff' ) );
		}

		$tabs = $this->get_tabs();
		if ( empty( $tabs ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'No hay módulos disponibles.', 'estandar-kickoff' ) . '</p></div>';
			return;
		}

		$current = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = array_key_first( $tabs );
		}

		$tab_config      = $tabs[ $current ];
		$content_callback = $tab_config['render'] ?? null;
		$content_file     = $tab_config['file'] ?? null;
		$module_path      = ESTANDAR_KICKOFF_MODULES_PATH . $current . '/';
		?>
		<div class="wrap estandar-kickoff-admin estandar-admin">
			<h1 class="kickoff-admin-title">
				<span class="dashicons dashicons-admin-tools"></span>
				<?php echo esc_html__( 'Estandar KickOff', 'estandar-kickoff' ); ?>
			</h1>

			<p class="kickoff-admin-subtitle">
				<?php echo esc_html__( 'Herramientas comunes para desarrollar y mantener sitios WordPress.', 'estandar-kickoff' ); ?>
			</p>

			<?php
			$render_tabs = [];
			foreach ( $tabs as $slug => $t ) {
				$icon  = isset( $t['icon'] ) ? trim( (string) $t['icon'] ) : '';
				$label = isset( $t['label'] ) ? (string) $t['label'] : (string) $slug;

				$render_tabs[ $slug ] = [
					'label' => trim( $icon . ' ' . $label ),
					'url'   => admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $slug ),
				];
			}

			// CORE_OPTIONALITY_V1: KickOff tiene menú standalone; esta ruta corre sin core.
			if ( function_exists( 'estandar_render_tabs' ) ) {
				estandar_render_tabs( $render_tabs, $current );
			} else {
				echo '<h2 class="nav-tab-wrapper">';
				foreach ( $render_tabs as $slug => $tab ) {
					printf(
						'<a href="%s" class="nav-tab%s">%s</a>',
						esc_url( $tab['url'] ?? '' ),
						$slug === $current ? ' nav-tab-active' : '',
						esc_html( $tab['label'] ?? '' )
					);
				}
				echo '</h2>';
			}
			?>

			<div class="kickoff-tab-content">
				<?php if ( 'settings' !== $current ) : ?>
					<div class="estandar-module" data-module="<?php echo esc_attr( $current ); ?>">
						<div class="estandar-module-info-mount"></div>
				<?php endif; ?>
				<?php
				if ( is_callable( $content_callback ) ) {
					call_user_func( $content_callback );
				} elseif ( ! empty( $content_file ) && file_exists( $module_path . $content_file ) ) {
					include $module_path . $content_file;
				} else {
					echo '<p>' . esc_html__( 'Contenido no disponible.', 'estandar-kickoff' ) . '</p>';
				}
				?>
				<?php if ( 'settings' !== $current ) : ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="kickoff-admin-footer">
				<?php echo esc_html__( 'Versión', 'estandar-kickoff' ); ?>: <?php echo esc_html( ESTANDAR_KICKOFF_VERSION ); ?>
			</div>
		</div>
		<?php
	}
}
