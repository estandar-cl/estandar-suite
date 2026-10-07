<?php
/**
 * Página única del hub con tabs anidados (plugins → ítems → subitems).
 *
 * @package Estandar\Suite
 */

namespace Estandar\Suite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render de la página única `Estandar Suite`.
 *
 * Jerarquía de tabs:
 *   Nivel 1: plugins.
 *   Nivel 2: ítems de menú del plugin.
 *   Nivel 3+: subitems (recursivo).
 *
 * La ruta activa viaja en `?path=plugin/item/subitem`.
 */
class Settings {

	/**
	 * Render de la página del hub.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$plugins = Registry::plugins();

		$segments  = self::current_path();
		$plugin_id = $segments[0] ?? '';
		if ( '' === $plugin_id || ! isset( $plugins[ $plugin_id ] ) ) {
			$plugin_id = array_key_first( $plugins );
		}
		?>
		<div class="wrap es-wrap">
			<?php Branding::header(); ?>

			<?php if ( empty( $plugins ) ) : ?>
				<div class="es-panel es-empty">
					<p><?php esc_html_e( 'Aún no hay plugins registrados en Estandar Suite.', 'estandar-suite' ); ?></p>
					<p class="es-muted">
						<?php esc_html_e( 'Los plugins se registran con la API estandar_suite_register_plugin().', 'estandar-suite' ); ?>
					</p>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<?php
			// Nivel 1: plugins como tabs.
			self::render_tab_bar( self::level_plugins( $plugins ), array(), (string) $plugin_id, 'es-tabs--primary' );

			$plugin = $plugins[ $plugin_id ];
			$rest   = array_slice( $segments, 1 );
			self::render_tree( $plugin, (array) $plugin['tabs'], $rest, array( (string) $plugin_id ) );
			?>
		</div>
		<?php
	}

	/**
	 * Render recursivo del árbol de tabs de un plugin.
	 *
	 * @param array $plugin Plugin.
	 * @param array $tabs   Tabs del nivel actual.
	 * @param array $rest   Segmentos de ruta pendientes.
	 * @param array $prefix Segmentos de ruta ya usados.
	 * @return void
	 */
	private static function render_tree( array $plugin, array $tabs, array $rest, array $prefix ): void {
		$visible = self::visible_tabs( $tabs );

		if ( empty( $visible ) ) {
			self::render_content( $plugin, null );
			return;
		}

		$active = isset( $rest[0] ) ? (string) $rest[0] : '';
		if ( '' === $active || ! isset( $visible[ $active ] ) ) {
			$active = (string) array_key_first( $visible );
		}

		self::render_tab_bar( $visible, $prefix, $active, 'es-tabs--sub' );

		$tab = $visible[ $active ];

		if ( ! empty( $tab['children'] ) ) {
			self::render_tree(
				$plugin,
				(array) $tab['children'],
				array_slice( $rest, 1 ),
				array_merge( $prefix, array( $active ) )
			);
			return;
		}

		self::render_content( $plugin, $tab );
	}

	/**
	 * Contenido (hoja): callback del tab, o del plugin, o aviso.
	 *
	 * @param array      $plugin Plugin.
	 * @param array|null $tab    Tab activo (o null).
	 * @return void
	 */
	private static function render_content( array $plugin, ?array $tab ): void {
		?>
		<div class="es-panel" role="tabpanel">
			<?php
			$rendered = false;

			if ( null !== $tab && ! empty( $tab['callback'] ) && is_callable( $tab['callback'] ) ) {
				call_user_func( $tab['callback'], $plugin );
				$rendered = true;
			}

			if ( ! $rendered && ! empty( $plugin['callback'] ) && is_callable( $plugin['callback'] ) ) {
				call_user_func( $plugin['callback'], $plugin );
				$rendered = true;
			}

			if ( ! $rendered ) {
				echo '<p class="es-muted">' . esc_html__( 'Este plugin no ha definido contenido todavía.', 'estandar-suite' ) . '</p>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Convierte los plugins en items de tab (nivel 1).
	 *
	 * @param array $plugins Plugins registrados.
	 * @return array
	 */
	private static function level_plugins( array $plugins ): array {
		$items = array();
		foreach ( $plugins as $id => $plugin ) {
			$items[ $id ] = array(
				'id'         => $id,
				'label'      => (string) $plugin['name'],
				'capability' => (string) ( $plugin['capability'] ?? 'manage_options' ),
			);
		}
		return $items;
	}

	/**
	 * Filtra por capability y ordena.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	private static function visible_tabs( array $tabs ): array {
		$tabs = Registry::sort_tabs( $tabs );

		$visible = array();
		foreach ( $tabs as $id => $tab ) {
			if ( current_user_can( (string) ( $tab['capability'] ?? 'manage_options' ) ) ) {
				$visible[ $id ] = $tab;
			}
		}
		return $visible;
	}

	/**
	 * Barra de tabs.
	 *
	 * @param array  $tabs   Items del nivel.
	 * @param array  $prefix Segmentos de ruta ya usados.
	 * @param string $active Id activo.
	 * @param string $class  Clase extra.
	 * @return void
	 */
	private static function render_tab_bar( array $tabs, array $prefix, string $active, string $class ): void {
		if ( empty( $tabs ) ) {
			return;
		}
		?>
		<nav class="es-tabs <?php echo esc_attr( $class ); ?>" role="tablist">
			<?php foreach ( $tabs as $id => $tab ) : ?>
				<?php
				$path = implode( '/', array_merge( $prefix, array( (string) $id ) ) );
				$url  = add_query_arg(
					array(
						'page' => ESTANDAR_SUITE_SLUG,
						'path' => $path,
					),
					admin_url( 'admin.php' )
				);
				$is_active = ( (string) $id === $active );
				?>
				<a
					class="es-tab<?php echo $is_active ? ' is-active' : ''; ?>"
					role="tab"
					href="<?php echo esc_url( $url ); ?>"
					<?php echo $is_active ? ' aria-current="page"' : ''; ?>
				><?php echo esc_html( (string) $tab['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Segmentos de la ruta activa (`?path=a/b/c`).
	 *
	 * @return array
	 */
	private static function current_path(): array {
		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $path ) {
			return array();
		}

		$segments = array_filter( array_map( 'sanitize_key', explode( '/', $path ) ) );

		return array_values( $segments );
	}
}
