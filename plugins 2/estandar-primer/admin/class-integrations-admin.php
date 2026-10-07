<?php
/**
 * Integraciones · subtab del módulo Proyectos.
 *
 *   GET  ?page=estandar_projects_integrations                 → dashboard + proveedor por defecto
 *   GET  ?page=estandar_projects_integrations&provider=meta   → proveedor específico
 *   POST admin-post.php action=estandar_integrations_save     → guardar (nonce: estandar_integrations_save)
 *
 * Capa 100% genérica: renderiza exclusivamente desde el contrato
 * Estandar_Integration_Provider (settings_fields/status/render_extra).
 * Prohibido código específico de un proveedor en este archivo.
 *
 * @package EstandarCoreMU
 * @since   13.0.47
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Integrations_Admin {

	const PAGE_SLUG = 'estandar_projects_integrations';
	const CAP       = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_estandar_integrations_save', array( __CLASS__, 'handle_save' ) );
	}

	public static function register_menu(): void {
		if ( ! defined( 'ESTANDAR_CORE_MENU_SLUG' ) ) {
			return;
		}
		if ( function_exists( 'estandar_register_navigation_page' ) ) {
			estandar_register_navigation_page( array(
				'slug'       => self::PAGE_SLUG,
				'capability' => self::CAP,
				'callback'   => array( __CLASS__, 'render_page' ),
			) );
		}
	}

	// ── Render ───────────────────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'No autorizado.' );
		}

		if ( function_exists( 'estandar_core_render_admin_shell' ) ) {
			estandar_core_render_admin_shell( array(
				'title'       => 'Proyectos',
				'subtitle'    => 'Integraciones con plataformas publicitarias.',
				'width'       => 'fluid',
				'header_slot' => static function (): void {
					if ( function_exists( 'estandar_core_render_subtabs' ) ) {
						estandar_core_render_subtabs( 'estandar_projects', self::PAGE_SLUG );
					}
				},
				'callback'    => static function (): void {
					Estandar_Integrations_Admin::render_content();
				},
			) );
			return;
		}

		echo '<div class="wrap"><h1>Integraciones</h1>';
		self::render_content();
		echo '</div>';
	}

	public static function render_content(): void {
		$providers = Estandar_Integration_Registry::all();
		if ( empty( $providers ) ) {
			echo '<p>No hay proveedores de integración registrados.</p>';
			return;
		}

		$current_id = sanitize_key( (string) ( $_GET['provider'] ?? '' ) );
		if ( $current_id === '' || ! isset( $providers[ $current_id ] ) ) {
			$current_id = (string) array_key_first( $providers );
		}
		$current = $providers[ $current_id ];

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Configuración de '
				. esc_html( $current->label() ) . ' guardada.</p></div>';
		}

		self::render_dashboard( $providers, $current_id );
		self::render_provider_nav( $providers, $current_id );
		self::render_provider( $current );
	}

	/** Dashboard de estado: una fila por proveedor, todo desde status(). */
	private static function render_dashboard( array $providers, string $current_id ): void {
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<table class="widefat striped" style="max-width:860px;margin-bottom:24px;">
			<thead>
				<tr><th>Proveedor</th><th>Estado</th><th>Resumen</th><th></th></tr>
			</thead>
			<tbody>
			<?php foreach ( $providers as $id => $provider ) :
				$status = $provider->status();
				if ( ! $provider->is_implemented() ) {
					$badge = '<span style="color:#6b7280;">— Preparado (sin implementación)</span>';
				} elseif ( ! empty( $status['active'] ) ) {
					$badge = '<span style="color:#166534;font-weight:600;">✓ Activo</span>';
				} elseif ( ! empty( $status['configured'] ) ) {
					$badge = '<span style="color:#92400e;font-weight:600;">Configurado · inactivo</span>';
				} else {
					$badge = '<span style="color:#991b1b;">Sin configurar</span>';
				}
				?>
				<tr>
					<td style="font-weight:600;"><?php echo esc_html( $provider->label() ); ?></td>
					<td><?php echo $badge; // phpcs:ignore WordPress.Security.EscapeOutput -- construido arriba con literales. ?></td>
					<td><?php echo esc_html( $status['summary'] !== '' ? $status['summary'] : $provider->description() ); ?></td>
					<td>
						<?php if ( $provider->is_implemented() ) : ?>
							<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'provider', $id, $base ) ); ?>">
								<?php echo $id === $current_id ? 'Viendo' : 'Configurar'; ?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** Navegación secundaria por proveedor. */
	private static function render_provider_nav( array $providers, string $current_id ): void {
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		echo '<p style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px;">';
		foreach ( $providers as $id => $provider ) {
			$class = $id === $current_id ? 'button button-primary' : 'button button-secondary';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( add_query_arg( 'provider', $id, $base ) ) . '">'
				. esc_html( $provider->label() ) . '</a>';
		}
		echo '</p>';
	}

	/** Detalle del proveedor activo: formulario genérico + panel propio. */
	private static function render_provider( Estandar_Integration_Provider $provider ): void {
		echo '<h2 style="margin-top:8px;">' . esc_html( $provider->label() ) . '</h2>';

		if ( ! $provider->is_implemented() ) {
			echo '<div style="padding:16px 20px;border:1px dashed #d1d5db;border-radius:8px;max-width:640px;color:#6b7280;">'
				. esc_html( $provider->description() !== '' ? $provider->description() : 'Proveedor planificado — sin implementación todavía.' )
				. '</div>';
			return;
		}

		$fields = $provider->settings_fields();
		if ( ! empty( $fields ) ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'estandar_integrations_save' ); ?>
				<input type="hidden" name="action" value="estandar_integrations_save">
				<input type="hidden" name="integration_provider" value="<?php echo esc_attr( $provider->id() ); ?>">
				<table class="form-table" role="presentation">
					<?php foreach ( $fields as $field ) : self::render_field( $field ); endforeach; ?>
				</table>
				<?php submit_button( 'Guardar configuración de ' . $provider->label() ); ?>
			</form>
			<?php
		}

		$provider->render_extra();
	}

	/** Render genérico de un campo declarativo (text|password|checkbox). */
	private static function render_field( array $field ): void {
		$name        = (string) ( $field['name'] ?? '' );
		$label       = (string) ( $field['label'] ?? $name );
		$type        = (string) ( $field['type'] ?? 'text' );
		$description = (string) ( $field['description'] ?? '' );
		if ( $name === '' ) {
			return;
		}
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( $type === 'checkbox' ) : ?>
					<label>
						<input type="checkbox" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"
							value="1" <?php checked( ! empty( $field['checked'] ) ); ?>>
						<?php echo esc_html( (string) ( $field['checkbox_label'] ?? '' ) ); ?>
					</label>
				<?php elseif ( $type === 'select' ) : ?>
					<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
						<?php foreach ( (array) ( $field['options'] ?? array() ) as $option_value => $option_label ) : ?>
							<option value="<?php echo esc_attr( (string) $option_value ); ?>" <?php selected( (string) ( $field['value'] ?? '' ), (string) $option_value ); ?>><?php echo esc_html( (string) $option_label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input class="<?php echo $type === 'password' ? 'large-text' : 'regular-text'; ?>"
						type="<?php echo esc_attr( $type === 'password' ? 'password' : 'text' ); ?>"
						id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>"
						autocomplete="off"
						placeholder="<?php echo esc_attr( (string) ( $field['placeholder'] ?? '' ) ); ?>"
						value="<?php echo esc_attr( (string) ( $field['value'] ?? '' ) ); ?>">
				<?php endif; ?>
				<?php if ( $description !== '' ) : ?>
					<p class="description"><?php echo wp_kses( $description, array( 'code' => array(), 'strong' => array() ) ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	// ── Guardado ─────────────────────────────────────────────────────────────────

	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_integrations_save' );

		$provider_id = sanitize_key( (string) ( $_POST['integration_provider'] ?? '' ) );
		$provider    = Estandar_Integration_Registry::get( $provider_id );

		if ( $provider && $provider->is_implemented() ) {
			$provider->save_settings( $_POST );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&provider=' . rawurlencode( $provider_id ) . '&saved=1' ) );
		exit;
	}
}
