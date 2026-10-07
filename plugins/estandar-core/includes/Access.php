<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Acceso limitado + auditoría (ACCESS_V1).
 *
 * Un único lugar del core que resuelve tres cosas, con código mínimo:
 *  1. Rol mínimo `editor_web` (solo Páginas + Medios, sin tocar nada más).
 *  2. Gate real del backend por lista blanca de pantallas (`current_screen`),
 *     más limpieza de menú. No depende de la capability de cada plugin: si la
 *     pantalla no está permitida, se corta con 403.
 *  3. Auditoría en tabla propia `estandar_core_access_log` (login, logout, vista
 *     de pantalla y cambio de página), con visor y ajustes en Core →
 *     Configuración → Acceso.
 *
 * Diseño deliberadamente conservador: solo se aplica a usuarios con el rol
 * limitado (nunca a administradores ni a otros roles del sitio).
 */
class Access
{
	const OPTION          = 'estandar_core_access_settings';
	const SCHEMA_OPTION   = 'estandar_core_access_schema_version';
	const SCHEMA_VERSION  = '1.0.0';
	const TABLE           = 'estandar_core_access_log';
	const ROLE            = 'editor_web';

	/** Pantallas de backend reconocidas (id de pantalla => etiqueta humana). */
	public static function screens_catalog(): array
	{
		return array(
			'dashboard' => 'Escritorio',
			'edit-page' => 'Páginas (listado)',
			'page'      => 'Páginas (editor)',
			'upload'    => 'Medios (biblioteca)',
			'media'     => 'Medios (ficha)',
			'profile'   => 'Perfil propio',
		);
	}

	/** Valor por defecto de los ajustes. */
	public static function defaults(): array
	{
		return array(
			'enabled'        => 1,
			'log'            => 1,
			'screens'        => array( 'dashboard', 'edit-page', 'page', 'upload', 'media', 'profile' ),
			'retention_days' => 90,
			'role_label'     => 'Editor Web',
		);
	}

	/** Ajustes normalizados. */
	public static function settings(): array
	{
		$raw = get_option( self::OPTION, array() );
		return self::sanitize_settings( is_array( $raw ) ? $raw : array() );
	}

	/** Sanitiza los ajustes (puro: sin WP salvo helpers de saneo ya presentes). */
	public static function sanitize_settings( array $raw ): array
	{
		$d       = self::defaults();
		$catalog = self::screens_catalog();

		$screens = array();
		foreach ( (array) ( $raw['screens'] ?? $d['screens'] ) as $s ) {
			$s = sanitize_key( (string) $s );
			if ( isset( $catalog[ $s ] ) ) {
				$screens[] = $s;
			}
		}
		$screens = array_values( array_unique( $screens ) );
		if ( ! $screens ) {
			$screens = $d['screens'];
		}

		$retention = (int) ( $raw['retention_days'] ?? $d['retention_days'] );
		$retention = max( 1, min( 3650, $retention ) );

		$label = trim( (string) ( $raw['role_label'] ?? $d['role_label'] ) );
		if ( $label === '' ) {
			$label = $d['role_label'];
		}

		return array(
			'enabled'        => array_key_exists( 'enabled', $raw ) ? ( ! empty( $raw['enabled'] ) ? 1 : 0 ) : $d['enabled'],
			'log'            => array_key_exists( 'log', $raw ) ? ( ! empty( $raw['log'] ) ? 1 : 0 ) : $d['log'],
			'screens'        => $screens,
			'retention_days' => $retention,
			'role_label'     => mb_substr( $label, 0, 60 ),
		);
	}

	/** Capacidades del rol limitado (puro: sirve para tests y para add_role). */
	public static function role_caps(): array
	{
		$caps = array(
			'read'                  => true,
			'edit_pages'            => true,
			'edit_published_pages'  => true,
			'publish_pages'         => true,
			'delete_pages'          => true,
			'upload_files'          => true,
		);
		return apply_filters( 'estandar_suite_access_role_caps', $caps );
	}

	/** Slug de menú de WordPress que corresponde a cada pantalla permitida (puro). */
	public static function screen_menu_map(): array
	{
		return array(
			'dashboard' => 'index.php',
			'edit-page' => 'edit.php?post_type=page',
			'page'      => 'edit.php?post_type=page',
			'upload'    => 'upload.php',
			'media'     => 'upload.php',
			'profile'   => 'profile.php',
		);
	}

	/** Slugs top-level de menú que se conservan para una lista de pantallas (puro). */
	public static function menu_keep_slugs( array $screens ): array
	{
		$map  = self::screen_menu_map();
		$keep = array();
		foreach ( $screens as $s ) {
			if ( isset( $map[ $s ] ) ) {
				$keep[] = $map[ $s ];
			}
		}
		return array_values( array_unique( $keep ) );
	}

	/** ¿La pantalla está permitida? (puro) */
	public static function screen_allowed( string $screen_id, array $screens ): bool
	{
		if ( $screen_id === '' ) {
			return false;
		}
		// El dashboard siempre es accesible (pantalla de aterrizaje).
		if ( $screen_id === 'dashboard' ) {
			return true;
		}
		return in_array( $screen_id, $screens, true );
	}

	// ── Ciclo de vida ───────────────────────────────────────────────────────

	public static function activate(): void
	{
		self::create_table();
		self::ensure_role();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	public static function maybe_migrate(): void
	{
		if ( get_option( self::SCHEMA_OPTION, '' ) !== self::SCHEMA_VERSION ) {
			self::create_table();
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		}
		self::ensure_role();
	}

	public static function create_table(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			event varchar(24) NOT NULL DEFAULT '',
			object_type varchar(40) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			screen varchar(80) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			message varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY event (event),
			KEY created_at (created_at)
		) $charset;" );
	}

	/** Crea/actualiza el rol limitado si no existe. */
	public static function ensure_role(): void
	{
		$label = self::settings()['role_label'];
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, $label, self::role_caps() );
		}
	}

	// ── Hooks ─────────────────────────────────────────────────────────────

	public static function init(): void
	{
		add_action( 'admin_menu', array( __CLASS__, 'filter_menu' ), 9999 );
		add_action( 'current_screen', array( __CLASS__, 'guard_screen' ) );
		add_action( 'admin_init', array( __CLASS__, 'on_admin_view' ) );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'guard_rest' ) );

		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_logout', array( __CLASS__, 'on_logout' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 3 );

		add_action( 'admin_post_estandar_suite_access_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_estandar_suite_access_create_user', array( __CLASS__, 'handle_create_user' ) );
		add_action( 'admin_post_estandar_suite_access_purge', array( __CLASS__, 'handle_purge' ) );
	}

	/** ¿Es un usuario limitado (rol editor_web, sin manage_options)? (puro sobre roles) */
	public static function user_is_restricted( $user ): bool
	{
		if ( ! $user || empty( $user->ID ) || empty( $user->roles ) ) {
			return false;
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			return false;
		}
		return in_array( self::ROLE, (array) $user->roles, true );
	}

	/** Lo que aplica a un usuario actual limitado. */
	private static function restricted_here(): bool
	{
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		return self::user_is_restricted( wp_get_current_user() );
	}

	/** Quita del menú todo lo que no esté permitido (solo usuarios limitados). */
	public static function filter_menu(): void
	{
		if ( ! self::restricted_here() ) {
			return;
		}
		$keep = self::menu_keep_slugs( self::settings()['screens'] );
		if ( empty( $GLOBALS['menu'] ) || ! is_array( $GLOBALS['menu'] ) ) {
			return;
		}
		foreach ( $GLOBALS['menu'] as $i => $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( $slug !== '' && ! in_array( $slug, $keep, true ) ) {
				unset( $GLOBALS['menu'][ $i ] );
			}
		}
		$GLOBALS['menu'] = array_values( $GLOBALS['menu'] );
	}

	/** Corte real: cualquier pantalla de admin fuera de la lista blanca → 403. */
	public static function guard_screen( $screen ): void
	{
		if ( ! self::restricted_here() || ! $screen || empty( $screen->id ) ) {
			return;
		}
		if ( ! self::screen_allowed( (string) $screen->id, self::settings()['screens'] ) ) {
			wp_die(
				esc_html__( 'No tienes acceso a esta sección.', 'estandar-suite-core' ),
				esc_html__( 'Acceso denegado', 'estandar-suite-core' ),
				array( 'response' => 403 )
			);
		}
	}

	/** Bloquea a usuarios limitados rutas REST sensibles (usuarios, ajustes, plugins…). */
	public static function guard_rest( $result )
	{
		if ( is_wp_error( $result ) || ! self::restricted_here() ) {
			return $result;
		}
		$deny = array( 'settings', 'plugins', 'themes', 'templates' );
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		if ( $route === '' && isset( $_SERVER['REQUEST_URI'] ) ) {
			$route = (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
		}
		foreach ( $deny as $piece ) {
			if ( strpos( $route, '/' . $piece ) !== false ) {
				return new \WP_Error( 'estandar_access_denied', __( 'No tienes acceso a este recurso.', 'estandar-suite-core' ), array( 'status' => 403 ) );
			}
		}
		return $result;
	}

	// ── Auditoría ─────────────────────────────────────────────────────────

	public static function on_login( $user_login, $user ): void
	{
		if ( ! self::user_is_restricted( $user ) ) {
			return;
		}
		self::insert_log( (int) $user->ID, (string) $user_login, 'login', array( 'message' => 'Inicio de sesión' ) );
	}

	public static function on_logout( $user_id = 0 ): void
	{
		$user = wp_get_current_user();
		if ( ! self::user_is_restricted( $user ) ) {
			return;
		}
		self::insert_log( (int) $user->ID, (string) $user->user_login, 'logout', array( 'message' => 'Cierre de sesión' ) );
	}

	/** Cambios de contenido por usuarios limitados (solo páginas). */
	public static function on_save_post( $post_id, $post, $update ): void
	{
		if ( ! self::restricted_here() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post || $post->post_type !== 'page' ) {
			return;
		}
		self::insert_log( get_current_user_id(), wp_get_current_user()->user_login, $update ? 'update' : 'create', array(
			'object_type' => 'page',
			'object_id'   => (int) $post_id,
			'message'     => (string) get_the_title( $post_id ),
		) );
	}

	/** Inserta una fila de auditoría (con saneo). */
	public static function insert_log( int $user_id, string $user_login, string $event, array $args = array() ): void
	{
		if ( empty( self::settings()['log'] ) ) {
			return;
		}
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'user_id'     => $user_id,
			'user_login'  => mb_substr( sanitize_text_field( $user_login ), 0, 60 ),
			'event'       => mb_substr( sanitize_key( $event ), 0, 24 ),
			'object_type' => mb_substr( sanitize_key( (string) ( $args['object_type'] ?? '' ) ), 0, 40 ),
			'object_id'   => (int) ( $args['object_id'] ?? 0 ),
			'screen'      => mb_substr( sanitize_key( (string) ( $args['screen'] ?? '' ) ), 0, 80 ),
			'ip'          => mb_substr( (string) ( self::client_ip() ), 0, 45 ),
			'message'     => mb_substr( sanitize_text_field( (string) ( $args['message'] ?? '' ) ), 0, 255 ),
			'created_at'  => current_time( 'mysql' ),
		) );
	}

	private static function client_ip(): string
	{
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Registra la vista de pantalla (una por request de admin, sin ajax). */
	public static function on_admin_view(): void
	{
		if ( wp_doing_ajax() || ! self::restricted_here() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		self::insert_log( get_current_user_id(), wp_get_current_user()->user_login, 'view', array(
			'screen'  => $screen ? (string) $screen->id : '',
			'message' => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) $_SERVER['REQUEST_URI'] ) : '',
		) );
	}

	/** Últimas entradas del log. */
	public static function recent_logs( int $limit = 100, string $user_filter = '' ): array
	{
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$limit = max( 1, min( 500, $limit ) );
		if ( $user_filter !== '' ) {
			$like = '%' . $wpdb->esc_like( $user_filter ) . '%';
			$sql  = $wpdb->prepare( "SELECT * FROM $table WHERE user_login LIKE %s ORDER BY id DESC LIMIT %d", $like, $limit );
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit );
		}
		return (array) $wpdb->get_results( $sql, ARRAY_A );
	}

	/** Borra entradas viejas según retención. */
	public static function prune_logs(): int
	{
		global $wpdb;
		$days = (int) self::settings()['retention_days'];
		$table = $wpdb->prefix . self::TABLE;
		$cut  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", $cut ) );
	}

	// ── Handlers admin ────────────────────────────────────────────────────

	private static function guard_post(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
	}

	private static function redirect( string $extra = '' ): void
	{
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=configuracion&cfg=acceso' . $extra ) );
		exit;
	}

	public static function handle_save(): void
	{
		self::guard_post();
		check_admin_referer( 'estandar_suite_access_save' );
		$raw = array(
			'enabled'        => ! empty( $_POST['enabled'] ),
			'log'            => ! empty( $_POST['log'] ),
			'screens'        => ( isset( $_POST['screens'] ) && is_array( $_POST['screens'] ) ) ? (array) $_POST['screens'] : array(),
			'retention_days' => (int) ( $_POST['retention_days'] ?? 90 ),
			'role_label'     => (string) ( $_POST['role_label'] ?? '' ),
		);
		update_option( self::OPTION, self::sanitize_settings( $raw ), false );
		self::ensure_role();
		self::redirect( '&access_saved=1' );
	}

	public static function handle_create_user(): void
	{
		self::guard_post();
		check_admin_referer( 'estandar_suite_access_create_user' );
		$email = sanitize_email( (string) ( $_POST['email'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $_POST['name'] ?? '' ) );
		$pass  = (string) ( $_POST['pass'] ?? '' );
		if ( ! is_email( $email ) ) {
			self::redirect( '&access_err=email' );
		}
		if ( email_exists( $email ) ) {
			self::redirect( '&access_err=exists' );
		}
		if ( $pass === '' ) {
			$pass = wp_generate_password( 16, true, false );
		}
		$uid = wp_insert_user( array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => $pass,
			'display_name' => $name !== '' ? $name : $email,
			'first_name'   => $name,
			'role'         => self::ROLE,
		) );
		if ( is_wp_error( $uid ) ) {
			self::redirect( '&access_err=create' );
		}
		self::insert_log( (int) $uid, $email, 'user_created', array( 'message' => 'Usuario limitado creado por ' . wp_get_current_user()->user_login ) );
		set_transient( 'estandar_suite_access_pass_' . get_current_user_id(), array( 'email' => $email, 'pass' => $pass ), 600 );
		self::redirect( '&access_user=1' );
	}

	public static function handle_purge(): void
	{
		self::guard_post();
		check_admin_referer( 'estandar_suite_access_purge' );
		self::prune_logs();
		self::redirect( '&access_purged=1' );
	}

	// ── UI ─────────────────────────────────────────────────────────────────

	/** Renderiza la sección Configuración → Acceso (ajustes + alta + log). */
	public static function render_page(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		$s        = self::settings();
		$catalog  = self::screens_catalog();
		$filter   = isset( $_GET['au'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['au'] ) ) : '';
		$logs     = self::recent_logs( 100, $filter );
		$lastpass = get_transient( 'estandar_suite_access_pass_' . get_current_user_id() );
		if ( $lastpass ) {
			delete_transient( 'estandar_suite_access_pass_' . get_current_user_id() );
		}
		echo \Estandar\Core\Admin::help_html( 'configuracion', '<strong>Acceso limitado.</strong> Crea usuarios que solo ven <em>Páginas</em> y <em>Medios</em> (rol <code>' . esc_html( self::ROLE ) . '</code>), sin acceso a plugins, temas, usuarios ni ajustes. Cada acceso (inicio de sesión, pantalla visitada y cambios de página) queda registrado abajo.' );
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;max-width:960px;margin:12px 0;">
			<h3 style="margin-top:0;">Ajustes de acceso limitado</h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="estandar_suite_access_save" />
				<?php wp_nonce_field( 'estandar_suite_access_save' ); ?>
				<p><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?> /> Activar el control de acceso para usuarios con el rol limitado</label></p>
				<p><label><input type="checkbox" name="log" value="1" <?php checked( $s['log'] ); ?> /> Registrar accesos y cambios (auditoría)</label></p>
				<p><strong>Pantallas permitidas</strong><br />
				<?php foreach ( $catalog as $id => $label ) : ?>
					<label style="display:inline-block;min-width:220px;margin:4px 12px 4px 0;">
						<input type="checkbox" name="screens[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $s['screens'], true ) ); ?> />
						<?php echo esc_html( $label ); ?> <code style="opacity:.6;"><?php echo esc_html( $id ); ?></code>
					</label>
				<?php endforeach; ?>
				</p>
				<p>Retención del registro: <input type="number" name="retention_days" min="1" max="3650" value="<?php echo (int) $s['retention_days']; ?>" /> días</p>
				<p>Nombre del rol: <input type="text" name="role_label" value="<?php echo esc_attr( $s['role_label'] ); ?>" class="regular-text" /></p>
				<p><button class="button button-primary">Guardar</button>
				<?php if ( isset( $_GET['access_saved'] ) ) : ?><span style="color:#2271b1;margin-left:10px;">Guardado.</span><?php endif; ?></p>
			</form>
		</div>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;max-width:960px;margin:12px 0;">
			<h3 style="margin-top:0;">Crear usuario limitado</h3>
			<?php if ( isset( $_GET['access_err'] ) ) : ?>
				<p style="color:#b32d2e;">No se pudo crear el usuario (<?php echo esc_html( sanitize_key( (string) $_GET['access_err'] ) ); ?>).</p>
			<?php endif; ?>
			<?php if ( $lastpass ) : ?>
				<p style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;">
					Usuario <strong><?php echo esc_html( $lastpass['email'] ); ?></strong> creado. Contraseña temporal:
					<code><?php echo esc_html( $lastpass['pass'] ); ?></code> (cópiala ahora; no se vuelve a mostrar).
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="estandar_suite_access_create_user" />
				<?php wp_nonce_field( 'estandar_suite_access_create_user' ); ?>
				<p>Nombre: <input type="text" name="name" class="regular-text" /></p>
				<p>Correo (usuario): <input type="email" name="email" class="regular-text" required /></p>
				<p>Contraseña (dejar vacío = temporal automática): <input type="text" name="pass" class="regular-text" /></p>
				<p><button class="button button-primary">Crear usuario</button></p>
			</form>
		</div>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;max-width:1100px;margin:12px 0;">
			<h3 style="margin-top:0;">Registro de actividad (últimas 100)</h3>
			<form method="get" style="margin-bottom:10px;">
				<input type="hidden" name="page" value="estandar-suite" />
				<input type="hidden" name="tab" value="config" />
				<input type="hidden" name="sub" value="configuracion" />
				<input type="hidden" name="cfg" value="acceso" />
				<input type="text" name="au" value="<?php echo esc_attr( $filter ); ?>" placeholder="Filtrar por usuario…" />
				<button class="button">Filtrar</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:10px;">
				<input type="hidden" name="action" value="estandar_suite_access_purge" />
				<?php wp_nonce_field( 'estandar_suite_access_purge' ); ?>
				<button class="button" onclick="return confirm('¿Borrar registros anteriores a la retención?');">Purgar antiguos</button>
				<?php if ( isset( $_GET['access_purged'] ) ) : ?><span style="color:#2271b1;margin-left:8px;">Purgado.</span><?php endif; ?>
			</form>
			<table class="widefat striped">
				<thead><tr><th>Fecha</th><th>Usuario</th><th>Evento</th><th>Pantalla / Detalle</th><th>IP</th></tr></thead>
				<tbody>
				<?php if ( ! $logs ) : ?>
					<tr><td colspan="5">Sin registros.</td></tr>
				<?php else : foreach ( $logs as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
						<td><?php echo esc_html( (string) $row['user_login'] ); ?></td>
						<td><?php echo esc_html( (string) $row['event'] ); ?></td>
						<td><?php echo esc_html( trim( (string) $row['screen'] . ' ' . (string) $row['message'] ) ); ?></td>
						<td><?php echo esc_html( (string) $row['ip'] ); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
