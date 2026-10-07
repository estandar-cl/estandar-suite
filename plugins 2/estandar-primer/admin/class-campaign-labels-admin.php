<?php
/**
 * Minimal admin for the shared campaign label dictionary.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Campaign_Labels_Admin' ) ) :

class Estandar_Campaign_Labels_Admin {

	const PAGE_SLUG = 'estandar_campaigns';
	const CAP       = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_estandar_campaign_label_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_estandar_campaign_label_delete', array( __CLASS__, 'handle_delete' ) );
	}

	public static function register_menu(): void {
		if ( ! defined( 'ESTANDAR_CORE_MENU_SLUG' ) ) {
			return;
		}
		if ( function_exists( 'estandar_register_navigation_page' ) ) {
			estandar_register_navigation_page( [ 'slug' => self::PAGE_SLUG, 'capability' => self::CAP, 'callback' => array( __CLASS__, 'render_page' ) ] );
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'No autorizado.' );
		}

		$view = sanitize_key( (string) ( $_GET['view'] ?? 'discovery' ) );

		if ( function_exists( 'estandar_core_render_admin_shell' ) ) {
			estandar_core_render_admin_shell( array(
				'title'    => 'Proyectos',
				'subtitle' => 'Nombres operacionales para campañas, conjuntos y anuncios.',
				'width'    => 'fluid', // ESTANDAR_ADMIN_UI_V0.6 P1 — unificar ancho fluido.
				'header_slot' => static function (): void {
					self::render_local_tabs();
				},
				'callback' => static function () use ( $view ) {
					Estandar_Campaign_Labels_Admin::render_content( $view );
				},
			) );
			return;
		}

		echo '<div class="wrap"><h1>Proyectos</h1>';
		self::render_content( $view );
		echo '</div>';
	}

	public static function render_content( string $view = 'registry' ): void {
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$prefill = array(
			'type'     => sanitize_key( (string) ( $_GET['type'] ?? '' ) ),
			'id'       => sanitize_text_field( (string) ( $_GET['id'] ?? '' ) ),
			'label'    => sanitize_text_field( (string) ( $_GET['label'] ?? '' ) ),
			'project'  => sanitize_key( (string) ( $_GET['project'] ?? '' ) ),
			'platform' => sanitize_key( (string) ( $_GET['platform'] ?? '' ) ),
		);

		if ( $view === 'discovery' ) {
			echo '<p style="text-align:right;margin:0 0 12px;"><a class="button button-secondary" href="' . esc_url( add_query_arg( 'view', 'registry', $base ) ) . '">Registry</a></p>';
			self::render_discovery();
			return;
		}

		$store = Estandar_Campaign_Label_Resolver::get_store();
		$rows  = array();
		foreach ( $store as $type => $entries ) {
			foreach ( $entries as $id => $entry ) {
				$entry  = is_array( $entry ) ? $entry : array( 'label' => (string) $entry );
				$rows[] = array(
					'type'     => $type,
					'id'       => (string) $id,
					'label'    => (string) ( $entry['label'] ?? '' ),
					'platform' => (string) ( $entry['platform'] ?? '' ),
					'project'  => (string) ( $entry['project'] ?? '' ),
					'active'   => ! array_key_exists( 'active', $entry ) || ! empty( $entry['active'] ),
				);
			}
		}

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Etiqueta guardada.</p></div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Etiqueta eliminada.</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;border:1px solid #dcdcde;padding:18px;margin-bottom:20px;">
			<input type="hidden" name="action" value="estandar_campaign_label_save">
			<?php wp_nonce_field( 'estandar_campaign_label_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="eclr-type">Tipo</label></th>
					<td><select id="eclr-type" name="type"><option value="campaigns" <?php echo $prefill['type'] === 'campaigns' ? 'selected' : ''; ?>>Campaña</option><option value="adsets" <?php echo $prefill['type'] === 'adsets' ? 'selected' : ''; ?>>Conjunto</option><option value="ads" <?php echo $prefill['type'] === 'ads' ? 'selected' : ''; ?>>Anuncio</option></select></td>
				</tr>
				<tr><th><label for="eclr-id">ID / valor UTM</label></th><td><input id="eclr-id" name="id" class="regular-text" required value="<?php echo esc_attr( $prefill['id'] ); ?>"></td></tr>
				<tr><th><label for="eclr-label">Nombre</label></th><td><input id="eclr-label" name="label" class="regular-text" required value="<?php echo esc_attr( $prefill['label'] ); ?>"></td></tr>
				<tr><th><label for="eclr-project">Proyecto</label></th><td><input id="eclr-project" name="project" class="regular-text" value="<?php echo esc_attr( $prefill['project'] ); ?>"></td></tr>
				<tr><th><label for="eclr-platform">Plataforma</label></th><td><select id="eclr-platform" name="platform"><option value="" <?php echo $prefill['platform'] === '' ? 'selected' : ''; ?>>—</option><option value="meta" <?php echo $prefill['platform'] === 'meta' ? 'selected' : ''; ?>>Meta</option><option value="google" <?php echo $prefill['platform'] === 'google' ? 'selected' : ''; ?>>Google</option><option value="other" <?php echo $prefill['platform'] === 'other' ? 'selected' : ''; ?>>Otra</option></select></td></tr>
				<tr><th>Estado</th><td><label><input type="checkbox" name="active" value="1" checked> Activa</label></td></tr>
			</table>
			<?php submit_button( 'Guardar etiqueta' ); ?>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead><tr><th>Tipo</th><th>ID / UTM</th><th>Nombre</th><th>Proyecto</th><th>Plataforma</th><th>Estado</th><th></th></tr></thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="7">No hay etiquetas configuradas.</td></tr>
			<?php else : foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( array( 'campaigns' => 'Campaña', 'adsets' => 'Conjunto', 'ads' => 'Anuncio' )[ $row['type'] ] ); ?></td>
					<td><code><?php echo esc_html( $row['id'] ); ?></code></td>
					<td><strong><?php echo esc_html( $row['label'] ); ?></strong></td>
					<td><?php echo esc_html( $row['project'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $row['platform'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $row['active'] ? 'Activa' : 'Inactiva' ); ?></td>
					<td><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=estandar_campaign_label_delete&type=' . $row['type'] . '&id=' . rawurlencode( $row['id'] ) ), 'estandar_campaign_label_delete_' . $row['type'] . '_' . $row['id'] ) ); ?>" onclick="return confirm('¿Eliminar esta etiqueta?');">Eliminar</a></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_local_tabs(): void {
		// Grupo compartido del schema de navegación — nunca duplicar la lista
		// de subtabs aquí (Integraciones y futuras tabs aparecen solas).
		if ( function_exists( 'estandar_core_render_subtabs' ) ) {
			estandar_core_render_subtabs( 'estandar_projects', self::PAGE_SLUG );
			return;
		}
		if ( ! function_exists( 'estandar_render_tabs' ) ) {
			return;
		}
		estandar_render_tabs(
			array(
				'estandar_campaigns' => array( 'label' => 'Campaign Discovery', 'url' => admin_url( 'admin.php?page=estandar_campaigns' ) ),
			),
			'estandar_campaigns'
		);
	}

	private static function render_discovery(): void {
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$sections = array(
			'campaigns' => 'Campaigns',
			'adsets'    => 'Adsets',
			'ads'       => 'Ads',
		);

		foreach ( $sections as $type => $title ) {
			$rows = class_exists( 'Estandar_Campaign_Discovery' )
				? Estandar_Campaign_Discovery::get_by_type( $type )
				: array();
			?>
			<h2><?php echo esc_html( $title ); ?></h2>
			<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">
				<thead>
					<tr><th>ID</th><th>Proyecto</th><th>Leads</th><th>Primera vez</th><th>Última vez</th><th></th></tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6">Sin IDs descubiertos.</td></tr>
				<?php else : foreach ( $rows as $row ) : ?>
					<?php
					$registry_url = add_query_arg( array(
						'view'     => 'registry',
						'type'     => $type,
						'id'       => $row['external_id'],
						'project'  => $row['project_slug'],
						'platform' => '',
						'label'    => '',
					), $base );
					?>
					<tr>
						<td><code><?php echo esc_html( (string) $row['external_id'] ); ?></code></td>
						<td><?php echo esc_html( (string) ( $row['project_slug'] ?: '—' ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $row['lead_count'] ) ); ?></td>
						<td><?php echo esc_html( (string) $row['first_seen'] ); ?></td>
						<td><?php echo esc_html( (string) $row['last_seen'] ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( $registry_url ); ?>">Nombrar</a></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			<?php
		}
	}

	public static function handle_save(): void {
		self::authorize( 'estandar_campaign_label_save' );

		$type = sanitize_key( (string) ( $_POST['type'] ?? '' ) );
		$id   = sanitize_text_field( (string) ( $_POST['id'] ?? '' ) );
		if ( ! in_array( $type, Estandar_Campaign_Label_Resolver::TYPES, true ) || $id === '' ) {
			wp_die( 'Tipo o ID inválido.' );
		}

		$store = Estandar_Campaign_Label_Resolver::get_store();
		$store[ $type ][ $id ] = array(
			'label'    => sanitize_text_field( (string) ( $_POST['label'] ?? '' ) ),
			'platform' => sanitize_key( (string) ( $_POST['platform'] ?? '' ) ),
			'project'  => sanitize_key( (string) ( $_POST['project'] ?? '' ) ),
			'active'   => ! empty( $_POST['active'] ),
		);
		Estandar_Campaign_Label_Resolver::save_store( $store );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&saved=1' ) );
		exit;
	}

	public static function handle_delete(): void {
		$type = sanitize_key( (string) ( $_GET['type'] ?? '' ) );
		$id   = sanitize_text_field( (string) ( $_GET['id'] ?? '' ) );
		self::authorize( 'estandar_campaign_label_delete_' . $type . '_' . $id );

		$store = Estandar_Campaign_Label_Resolver::get_store();
		if ( isset( $store[ $type ][ $id ] ) ) {
			unset( $store[ $type ][ $id ] );
			Estandar_Campaign_Label_Resolver::save_store( $store );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&deleted=1' ) );
		exit;
	}

	private static function authorize( string $nonce ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( $nonce );
	}
}

endif;
