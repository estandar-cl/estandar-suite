<?php
/**
 * Estandar Projects Admin
 *
 * CRUD visual para Estandar_Core_Project_Registry v2.
 * Registrado como submenú bajo ESTANDAR_CORE_MENU_SLUG.
 *
 * Acciones:
 *   GET  ?page=estandar_projects              → lista
 *   GET  ?page=estandar_projects&action=edit  → formulario (nuevo o editar)
 *   POST ?page=estandar_projects&action=save  → guardar (nonce: estandar_project_save)
 *   GET  ?page=estandar_projects&action=delete&project_id=X&_wpnonce=Y → eliminar
 *
 * @package Estandar_Core_MU
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Project_Registry_Admin' ) ) :

class Estandar_Project_Registry_Admin {

	const PAGE_SLUG     = 'estandar_projects';
	const NONCE_SAVE    = 'estandar_project_save';
	const NONCE_DELETE  = 'estandar_project_delete';
	const CAP           = 'manage_options';

	// ---------------------------------------------------------------------------
	// Boot
	// ---------------------------------------------------------------------------

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_post_estandar_project_save',         [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_post_estandar_project_quick_create', [ __CLASS__, 'handle_quick_create' ] );
		add_action( 'admin_post_estandar_project_delete',       [ __CLASS__, 'handle_delete' ] );
	}

	public static function register_menu(): void {
		if ( ! function_exists( 'estandar_admin_menu_register_parent' ) ) {
			return;
		}
		estandar_admin_menu_register_parent();

		if ( function_exists( 'estandar_register_navigation_page' ) ) {
			estandar_register_navigation_page( [ 'slug' => self::PAGE_SLUG, 'capability' => self::CAP, 'callback' => [ __CLASS__, 'render_page' ] ] );
			estandar_register_navigation_page( [ 'slug' => 'estandar_projects_operators', 'capability' => self::CAP, 'callback' => [ __CLASS__, 'render_operators_page' ] ] );
		}
	}

	// ---------------------------------------------------------------------------
	// Router
	// ---------------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'No autorizado.' );
		}

		$action = sanitize_key( (string) ( $_GET['action'] ?? 'list' ) );

		if ( function_exists( 'estandar_core_render_admin_shell' ) ) {
			estandar_core_render_admin_shell( [
				'title'    => 'Proyectos',
				'subtitle' => 'Fuente de verdad comercial del ecosistema Estandar.',
				'width'    => 'fluid', // ESTANDAR_ADMIN_UI_V0.6 P1 — unificar ancho fluido.
				'header_slot' => static function (): void {
					Estandar_Project_Registry_Admin::render_local_tabs( self::PAGE_SLUG );
				},
				'callback' => static function () use ( $action ) {
					Estandar_Project_Registry_Admin::render_content( $action );
				},
			] );
		} else {
			echo '<div class="wrap">';
			self::render_content( $action );
			echo '</div>';
		}
	}

	public static function render_operators_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'No autorizado.' );
		}

		if ( function_exists( 'estandar_core_render_admin_shell' ) && class_exists( 'Estandar_Leads_Operators_Admin' ) ) {
			estandar_core_render_admin_shell( [
				'title'    => 'Operadores',
				'subtitle' => 'Gestión de operadores del ecosistema Estandar.',
				'width'    => 'fluid',
				'header_slot' => static function (): void {
					Estandar_Project_Registry_Admin::render_local_tabs( 'estandar_projects_operators' );
				},
				'callback' => static function (): void {
					if ( class_exists( 'Estandar_Leads_Operators_Admin' ) ) {
						Estandar_Leads_Operators_Admin::render();
					}
				},
			] );
			return;
		}

		if ( class_exists( 'Estandar_Leads_Operators_Admin' ) ) {
			Estandar_Leads_Operators_Admin::render();
		}
	}

	public static function render_content( string $action ): void {
		$notice = self::get_transient_notice();
		if ( $notice ) {
			$type = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		// Detectar proyectos sin client_id (posiblemente heredados de otra instalación).
		if ( 'list' === $action ) {
			global $wpdb;
			$project_table = $wpdb->prefix . 'estandar_project_registry';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $project_table ) ) ) === $project_table ) {
				$foreign_projects = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$project_table} WHERE (client_id = '' OR client_id IS NULL) AND project_id != ''" );
				if ( $foreign_projects > 0 ) {
					echo '<div class="notice notice-warning is-dismissible"><p>';
					echo esc_html( "⚠ {$foreign_projects} proyecto(s) no tienen cliente asignado. Pueden haberse heredado de otra instalación. Asigna un cliente o elimínalos si no corresponden." );
					echo ' <a href="' . esc_url( admin_url( 'admin.php?page=estandar_projects&action=edit' ) ) . '" style="font-weight:600;">Revisar proyectos →</a>';
					echo '</p></div>';
				}
			}
		}

		if ( $action === 'edit' ) {
			self::render_edit_form();
		} else {
			self::render_list();
		}
	}

	private static function render_local_tabs( string $active ): void {
		if ( ! function_exists( 'estandar_core_render_subtabs' ) ) {
			return;
		}

		estandar_core_render_subtabs( 'estandar_projects', $active );
	}

	// ---------------------------------------------------------------------------
	// List view
	// ---------------------------------------------------------------------------

	private static function render_list(): void {
		$projects = class_exists( 'Estandar_Core_Project_Registry' )
			? Estandar_Core_Project_Registry::get_all_admin()
			: [];

		$edit_url   = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=edit' );

		// Si el registry está vacío, pre-poblar project_id con estandar_client_slug.
		$client_slug = function_exists( 'estandar_core_get_client_slug' ) ? estandar_core_get_client_slug() : '';
		$new_url     = ( empty( $projects ) && $client_slug !== '' )
			? add_query_arg( 'project_id', $client_slug, $edit_url )
			: $edit_url;
		$active_count   = count( array_filter( $projects, static fn( array $p ): bool => ! empty( $p['active'] ) ) );
		$inactive_count = max( 0, count( $projects ) - $active_count );

		?>
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
			<p style="margin:0;color:#666;">
				<?php echo esc_html( count( $projects ) ); ?> proyecto(s) registrado(s).
			</p>
			<a href="<?php echo esc_url( $new_url ); ?>" class="button button-primary">+ Nuevo Proyecto</a>
		</div>

		<?php /* P2 Quick Create — additive. Canonical write path: handle_quick_create() → Registry::upsert(). */ ?>
		<details class="estandar-card" style="margin-bottom:16px;padding:12px 16px;">
			<summary style="cursor:pointer;font-weight:600;">+ Creación rápida</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;max-width:520px;">
				<?php wp_nonce_field( self::NONCE_SAVE, '_wpnonce' ); ?>
				<input type="hidden" name="action" value="estandar_project_quick_create">
				<input type="hidden" name="project_type" value="other">
				<input type="hidden" name="company_id" value="<?php echo esc_attr( $client_slug ); ?>">
				<p style="margin:0 0 10px;">
					<label for="es-qc-slug" style="display:block;font-weight:600;margin-bottom:2px;">ID / Slug <span style="color:#b32d2e;">*</span></label>
					<input type="text" id="es-qc-slug" name="project_id" required pattern="[a-z0-9\-]+" placeholder="mi-proyecto" class="regular-text">
					<span style="display:block;font-size:11px;color:#6b7280;">Solo minúsculas, números y guiones. No se puede cambiar después.</span>
				</p>
				<p style="margin:0 0 10px;">
					<label for="es-qc-label" style="display:block;font-weight:600;margin-bottom:2px;">Nombre visible</label>
					<input type="text" id="es-qc-label" name="label" placeholder="Mi Proyecto" class="regular-text">
					<span style="display:block;font-size:11px;color:#6b7280;">Se autogenera desde el slug; puedes editarlo.</span>
				</p>
				<p style="margin:0 0 10px;color:#444;">
					<strong>Tipo inicial:</strong> Genérico · <strong>Empresa:</strong> <code><?php echo esc_html( $client_slug !== '' ? $client_slug : '—' ); ?></code>
					<span style="display:block;font-size:11px;color:#6b7280;">Editables después en la ficha completa.</span>
				</p>
				<button type="submit" class="button button-primary">Crear y completar</button>
			</form>
			<script>
			( function () {
				var s = document.getElementById( 'es-qc-slug' ), l = document.getElementById( 'es-qc-label' );
				if ( ! s || ! l ) { return; }
				l.addEventListener( 'input', function () { l.dataset.touched = '1'; } );
				s.addEventListener( 'input', function () {
					if ( l.dataset.touched === '1' ) { return; }
					l.value = s.value.split( /[-_]+/ ).filter( Boolean ).map( function ( w ) {
						return w.charAt( 0 ).toUpperCase() + w.slice( 1 );
					} ).join( ' ' );
				} );
			} )();
			</script>
		</details>

		<?php if ( empty( $projects ) ) : ?>
			<div class="estandar-card" style="text-align:center;padding:40px;color:#666;">
				<p>No hay proyectos registrados.</p>
				<?php if ( $client_slug !== '' ) : ?>
					<p style="font-size:12px;color:#6b7280;margin-bottom:12px;">
						Se usará <code><?php echo esc_html( $client_slug ); ?></code> como slug del primer proyecto
						(coincide con la identidad de esta instalación).
					</p>
				<?php endif; ?>
				<a href="<?php echo esc_url( $new_url ); ?>" class="button button-primary">Crear primer proyecto</a>
			</div>
		<?php else : ?>
			<div class="estandar-card" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;padding:12px;">
				<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
					<button type="button" class="button es-project-filter is-active" data-filter="all">Todos (<?php echo esc_html( (string) count( $projects ) ); ?>)</button>
					<button type="button" class="button es-project-filter" data-filter="active">Activos (<?php echo esc_html( (string) $active_count ); ?>)</button>
					<button type="button" class="button es-project-filter" data-filter="inactive">Inactivos (<?php echo esc_html( (string) $inactive_count ); ?>)</button>
					<input type="search" id="es-project-search" class="regular-text" placeholder="Buscar proyecto..." style="min-width:260px;">
				</div>
				<div id="es-project-bulk-count" style="font-size:12px;color:#6b7280;">0 seleccionados</div>
			</div>
			<table class="wp-list-table widefat fixed striped" style="border-radius:6px;overflow:hidden;">
				<thead>
					<tr>
						<th style="width:36px;"><input type="checkbox" id="es-project-select-all" aria-label="Seleccionar todos"></th>
						<th style="width:22%">Proyecto</th>
						<th style="width:12%">Ciudad</th>
						<th style="width:14%">Empresa</th>
						<th style="width:14%">Funnel</th>
						<th style="width:12%">Precio Desde</th>
						<th style="width:14%">Operador</th>
						<th style="width:6%">Estado</th>
						<th style="width:6%">Orden</th>
						<th style="width:12%">Acciones</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $projects as $p ) :
						$project_edit_url = add_query_arg( 'project_id', $p['project_id'], $edit_url );
						$delete_url = wp_nonce_url(
							admin_url( 'admin-post.php?action=estandar_project_delete&project_id=' . urlencode( $p['project_id'] ) ),
							self::NONCE_DELETE . '_' . $p['project_id']
						);
						$funnel_label = Estandar_Core_Project_Registry::FUNNEL_TEMPLATES[ $p['funnel_template'] ] ?? '—';
						$sale_label   = Estandar_Core_Project_Registry::SALE_TYPES[ $p['sale_type'] ] ?? '—';
						$row_search   = strtolower( implode( ' ', array_filter( [
							(string) $p['label'],
							(string) $p['project_id'],
							(string) $p['city'],
							(string) $p['company'],
							(string) $p['operator_name'],
							(string) $p['operator_email'],
						] ) ) );
					?>
					<tr class="es-project-row" data-active="<?php echo ! empty( $p['active'] ) ? '1' : '0'; ?>" data-search="<?php echo esc_attr( $row_search ); ?>">
						<td><input type="checkbox" class="es-project-check" value="<?php echo esc_attr( (string) $p['project_id'] ); ?>" aria-label="Seleccionar <?php echo esc_attr( (string) $p['label'] ); ?>"></td>
						<td>
							<strong><?php echo esc_html( $p['label'] ); ?></strong>
							<br><code style="font-size:11px;color:#888;"><?php echo esc_html( $p['project_id'] ); ?></code>
						</td>
						<td><?php echo esc_html( $p['city'] ?: '—' ); ?></td>
						<td><?php echo esc_html( $p['company'] ?: '—' ); ?></td>
						<td>
							<?php if ( $p['funnel_template'] ) : ?>
								<span style="font-size:11px;background:#f0f6fc;color:#0969da;padding:2px 6px;border-radius:4px;white-space:nowrap;">
									<?php echo esc_html( $funnel_label ); ?>
								</span>
							<?php else : echo '—'; endif; ?>
						</td>
						<td>
							<?php if ( $p['price_from'] ) :
								echo 'UF ' . esc_html( $p['price_from'] );
							else : echo '—'; endif; ?>
						</td>
						<td>
							<?php echo esc_html( $p['operator_name'] ?: '—' ); ?>
							<?php if ( $p['operator_email'] ) : ?>
								<br><span style="font-size:11px;color:#888;"><?php echo esc_html( $p['operator_email'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $p['active'] ) : ?>
								<span style="color:#1e7e34;">●</span>
							<?php else : ?>
								<span style="color:#999;">○</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $p['sort_order'] ); ?></td>
						<td>
							<a href="<?php echo esc_url( $project_edit_url ); ?>">Editar</a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( $delete_url ); ?>"
							   onclick="return confirm('¿Eliminar <?php echo esc_js( $p['label'] ); ?>? Esta acción no se puede deshacer.');"
							   style="color:#b32d2e;">Eliminar</a>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<script>
			(function(){
				var filter='all';
				var search=document.getElementById('es-project-search');
				var rows=[].slice.call(document.querySelectorAll('.es-project-row'));
				var checks=[].slice.call(document.querySelectorAll('.es-project-check'));
				var all=document.getElementById('es-project-select-all');
				var count=document.getElementById('es-project-bulk-count');
				function visible(row){
					var statusOk=filter==='all'||(filter==='active'&&row.dataset.active==='1')||(filter==='inactive'&&row.dataset.active!=='1');
					var q=(search.value||'').toLowerCase().trim();
					return statusOk&&(!q||(row.dataset.search||'').indexOf(q)!==-1);
				}
				function render(){
					rows.forEach(function(row){row.style.display=visible(row)?'':'none';});
					updateCount();
				}
				function updateCount(){
					var selected=checks.filter(function(c){return c.checked;}).length;
					count.textContent=selected+' seleccionado'+(selected===1?'':'s');
					if(all){
						var visibleChecks=checks.filter(function(c){return c.closest('tr').style.display!=='none';});
						all.checked=visibleChecks.length>0&&visibleChecks.every(function(c){return c.checked;});
					}
				}
				document.querySelectorAll('.es-project-filter').forEach(function(btn){
					btn.addEventListener('click',function(){
						filter=btn.dataset.filter||'all';
						document.querySelectorAll('.es-project-filter').forEach(function(b){b.classList.remove('is-active');});
						btn.classList.add('is-active');
						render();
					});
				});
				if(search){search.addEventListener('input',render);}
				checks.forEach(function(c){c.addEventListener('change',updateCount);});
				if(all){all.addEventListener('change',function(){
					checks.forEach(function(c){if(c.closest('tr').style.display!=='none'){c.checked=all.checked;}});
					updateCount();
				});}
				render();
			})();
			</script>
			<style>
			.es-project-filter.is-active{background:#1d2327;border-color:#1d2327;color:#fff;}
			</style>
		<?php endif;
	}

	// ---------------------------------------------------------------------------
	// Edit / New form
	// ---------------------------------------------------------------------------

	private static function render_edit_form(): void {
		$project_id = sanitize_key( (string) ( $_GET['project_id'] ?? '' ) );
		$project    = ( $project_id && class_exists( 'Estandar_Core_Project_Registry' ) )
			? Estandar_Core_Project_Registry::get( $project_id )
			: null;

		// Si project_id está en la URL pero no existe en DB, empezamos uno nuevo con ese slug.
		$is_new = empty( $project );
		$p      = $project ?? [
			'project_id'      => $project_id,
			'project_uuid'    => '',
			'label'           => '',
			'city'            => '',
			'active'          => true,
			'sort_order'      => 0,
			'url_patterns'    => [],
			'streets'         => [],
			'company_id'      => '',
			'company'         => '',
			'project_type'    => '',
			'region'          => '',
			'commune'         => '',
			'price_from'      => 0,
			'price_to'        => 0,
			'sale_type'       => '',
			'operator_name'   => '',
			'operator_email'  => '',
			'operator_phone'  => '',
			'form_id'         => '',
			'landing_url'     => '',
			'cta_primary'     => '',
			'cta_secondary'   => '',
			'whatsapp_msg'    => '',
			'funnel_template' => '',
			'hero_image_id'   => 0,
		];

		$back_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$form_action = admin_url( 'admin-post.php' );

		$funnel_templates = class_exists( 'Estandar_Core_Project_Registry' )
			? Estandar_Core_Project_Registry::FUNNEL_TEMPLATES
			: [];
		$sale_types = class_exists( 'Estandar_Core_Project_Registry' )
			? Estandar_Core_Project_Registry::SALE_TYPES
			: [];
			$project_types = class_exists( 'Estandar_Core_Project_Registry' )
				? Estandar_Core_Project_Registry::PROJECT_TYPES
				: [];
			$company_id_value = (string) ( $p['company_id'] ?: ( function_exists( 'estandar_core_get_client_slug' ) ? estandar_core_get_client_slug() : '' ) );
			$company_value    = (string) ( $p['company'] ?: get_bloginfo( 'name' ) );
			$operators        = self::get_operator_options();
			$selected_operator_id = self::find_selected_operator_id( $operators, $p );
			$forms            = self::get_form_options();

			?>
		<p style="margin-bottom:16px;">
			<a href="<?php echo esc_url( $back_url ); ?>">← Volver a Proyectos</a>
		</p>

		<?php if ( $is_new && '' !== $project_id ) : ?>
			<div class="notice notice-warning">
				<p>Este proyecto se está creando para resolver leads o formularios asociados a un slug no registrado.</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $form_action ); ?>">
			<?php wp_nonce_field( self::NONCE_SAVE, '_wpnonce' ); ?>
			<input type="hidden" name="action" value="estandar_project_save">
			<input type="hidden" name="_original_project_id" value="<?php echo esc_attr( $p['project_id'] ); ?>">

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

				<!-- Columna izquierda -->
				<div>

					<div class="estandar-card" style="margin-bottom:16px;">
						<h3 style="margin-top:0;">Identidad</h3>
						<?php self::field_text( 'project_id', 'ID / Slug', $p['project_id'], [
							'required'    => true,
							'pattern'     => '[a-z0-9\-]+',
							'placeholder' => 'mi-proyecto',
							'help'        => 'Solo minúsculas, números y guiones. No se puede cambiar después.',
							'readonly'    => ! $is_new,
						] ); ?>
						<?php if ( ! $is_new && ! empty( $p['project_uuid'] ) ) : ?>
							<?php self::field_text( 'project_uuid_display', 'UUID permanente', $p['project_uuid'], [
								'readonly' => true,
								'help'     => 'Identificador estable para integraciones externas. Solo lectura.',
							] ); ?>
						<?php endif; ?>
						<?php self::field_text( 'label', 'Nombre visible', $p['label'], [
							'required'    => true,
							'placeholder' => 'Mi Proyecto',
						] ); ?>
							<?php self::field_select( 'project_type', 'Tipo de proyecto', $p['project_type'], $project_types, '— Seleccionar —', [
								'help' => 'Qué ES el proyecto. Independiente del funnel.',
							] ); ?>
							<?php self::field_text( 'company_id', 'ID Empresa (slug)', $company_id_value, [
								'readonly' => true,
								'help'     => 'Derivado de la identidad de esta instalación.',
							] ); ?>
							<?php self::field_text( 'company', 'Nombre Empresa', $company_value, [
								'readonly' => true,
								'help'     => 'Solo lectura en esta pantalla.',
							] ); ?>
						<?php self::field_text( 'city', 'Ciudad', $p['city'], [
							'placeholder' => 'Ciudad',
						] ); ?>
						<?php self::field_text( 'commune', 'Comuna', $p['commune'], [
							'placeholder' => 'Comuna',
						] ); ?>
						<?php self::field_text( 'region', 'Región', $p['region'], [
							'placeholder' => 'Región',
						] ); ?>
						<?php self::field_number( 'sort_order', 'Orden en menú', $p['sort_order'] ); ?>
						<?php self::field_checkbox( 'active', 'Proyecto activo', $p['active'] ); ?>
					</div>

						<div class="estandar-card" style="margin-bottom:16px;">
							<h3 style="margin-top:0;">Operación Comercial</h3>
							<?php self::field_operator_select( $operators, $selected_operator_id, $p ); ?>
							<?php self::field_textarea( 'whatsapp_msg', 'Mensaje WhatsApp pre-llenado', $p['whatsapp_msg'], [
								'rows'        => 2,
								'placeholder' => 'Hola, quiero información del proyecto.',
						] ); ?>
					</div>

				</div>

				<!-- Columna derecha -->
				<div>

						<div class="estandar-card" data-project-type-section="real_estate" style="margin-bottom:16px;">
							<h3 style="margin-top:0;">Datos Comerciales</h3>
							<?php self::field_select( 'sale_type', 'Tipo de venta', $p['sale_type'], $sale_types, '— Seleccionar —' ); ?>
							<?php self::field_price( 'price_from', 'Precio desde (UF)', (int) $p['price_from'] ); ?>
						<?php self::field_price( 'price_to', 'Precio hasta (UF)', (int) $p['price_to'] ); ?>
					</div>

					<div class="estandar-card" style="margin-bottom:16px;">
						<h3 style="margin-top:0;">Funnel</h3>
						<?php self::field_select( 'funnel_template', 'Template de funnel', $p['funnel_template'], $funnel_templates, '— Seleccionar —', [
							'help' => 'Define el eje comunicacional: hero, CTA, chatbot y emails.',
						] ); ?>
						<?php self::field_text( 'cta_primary', 'CTA principal', $p['cta_primary'], [
							'placeholder' => 'Agendar Visita',
						] ); ?>
						<?php self::field_text( 'cta_secondary', 'CTA secundario', $p['cta_secondary'], [
							'placeholder' => 'WhatsApp',
						] ); ?>
					</div>

						<div class="estandar-card" style="margin-bottom:16px;">
							<h3 style="margin-top:0;">Integración</h3>
							<?php self::field_form_select( $forms, (string) $p['form_id'] ); ?>
							<?php self::field_text( 'landing_url', 'URL Landing', $p['landing_url'], [
								'placeholder' => 'https://cliente.cl/proyecto/',
							] ); ?>
							<?php self::field_textarea( 'url_patterns', 'URL Patterns (chatbot)', implode( "\n", $p['url_patterns'] ), [
								'rows' => 3,
								'id' => 'estandar-project-url-patterns',
								'help' => 'Una URL por línea. Ej: /proyecto o /proyecto/contacto',
								'placeholder' => "/proyecto\n/proyecto/contacto",
							] ); ?>
						<?php self::field_textarea( 'streets', 'Calles del proyecto', implode( "\n", $p['streets'] ), [
							'rows' => 4,
							'help' => 'Una calle por línea. Usadas por Estandar Forms.',
							'placeholder' => 'CALLE VIÑAS DE COLCHAGUA',
						] ); ?>
					</div>

				</div>

			</div>

			<p style="margin-top:8px;">
				<button type="submit" class="button button-primary button-large">
					<?php echo $is_new ? 'Crear Proyecto' : 'Guardar Cambios'; ?>
				</button>
					<a href="<?php echo esc_url( $back_url ); ?>" class="button" style="margin-left:8px;">Cancelar</a>
				</p>
			</form>
			<script>
			document.addEventListener('DOMContentLoaded', function () {
				var projectType = document.querySelector('select[name="project_type"]');
				var realEstateSections = document.querySelectorAll('[data-project-type-section="real_estate"]');
				var operatorSelect = document.getElementById('estandar-project-operator-select');
				function syncProjectTypeSections() {
					var showRealEstate = !projectType || projectType.value === '' || projectType.value === 'real_estate';
					realEstateSections.forEach(function (section) {
						section.style.display = showRealEstate ? '' : 'none';
					});
				}
				function syncOperatorFields() {
					if (!operatorSelect) {
						return;
					}
					var option = operatorSelect.options[operatorSelect.selectedIndex];
					document.getElementById('operator_name').value = option ? (option.dataset.name || '') : '';
					document.getElementById('operator_email').value = option ? (option.dataset.email || '') : '';
					document.getElementById('operator_phone').value = option ? (option.dataset.phone || '') : '';
				}
				if (projectType) {
					projectType.addEventListener('change', syncProjectTypeSections);
					syncProjectTypeSections();
				}
				if (operatorSelect) {
					operatorSelect.addEventListener('change', syncOperatorFields);
				}
			});
			</script>
			<?php
		}

	// ---------------------------------------------------------------------------
	// Save handler
	// ---------------------------------------------------------------------------

	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'No autorizado.' );
		}

		check_admin_referer( self::NONCE_SAVE );

		if ( ! class_exists( 'Estandar_Core_Project_Registry' ) ) {
			self::redirect_with_notice( 'error', 'Estandar_Core_Project_Registry no disponible.' );
			return;
		}

		$project_id = sanitize_key( (string) ( $_POST['project_id'] ?? '' ) );
			if ( $project_id === '' ) {
				self::redirect_with_notice( 'error', 'El campo ID / Slug es obligatorio.' );
				return;
			}

			$operator_data = [
				'operator_name'  => sanitize_text_field( (string) ( $_POST['operator_name'] ?? '' ) ),
				'operator_email' => sanitize_email( (string) ( $_POST['operator_email'] ?? '' ) ),
				'operator_phone' => sanitize_text_field( (string) ( $_POST['operator_phone'] ?? '' ) ),
			];
			$operator_matches = self::operator_matches( self::get_operator_options(), $operator_data );
			$has_operator_data = $operator_data['operator_name'] !== ''
				|| $operator_data['operator_email'] !== ''
				|| $operator_data['operator_phone'] !== '';
			if ( $has_operator_data && count( $operator_matches ) !== 1 ) {
				self::redirect_with_notice( 'error', 'No se guardó el proyecto: el operador seleccionado no resuelve de forma única en Leads.' );
				return;
			}

			// url_patterns y streets llegan como texto multilínea
			$url_patterns = self::sanitize_url_patterns( (string) ( $_POST['url_patterns'] ?? '' ) );
			$streets      = array_filter( array_map( 'trim', explode( "\n", (string) ( $_POST['streets'] ?? '' ) ) ) );

		$data = [
			'project_id'      => $project_id,
			'label'           => sanitize_text_field( (string) ( $_POST['label'] ?? '' ) ),
			'company_id'      => sanitize_key( (string) ( $_POST['company_id'] ?? '' ) ),
			'company'         => sanitize_text_field( (string) ( $_POST['company'] ?? '' ) ),
			'project_type'    => sanitize_key( (string) ( $_POST['project_type'] ?? '' ) ),
			'city'            => sanitize_text_field( (string) ( $_POST['city'] ?? '' ) ),
			'commune'         => sanitize_text_field( (string) ( $_POST['commune'] ?? '' ) ),
			'region'          => sanitize_text_field( (string) ( $_POST['region'] ?? '' ) ),
			'active'          => isset( $_POST['active'] ) && $_POST['active'] === '1',
			'sort_order'      => absint( $_POST['sort_order'] ?? 0 ),
			'url_patterns'    => array_values( $url_patterns ),
			'streets'         => array_values( $streets ),
			'price_from'      => absint( $_POST['price_from'] ?? 0 ),
			'price_to'        => absint( $_POST['price_to'] ?? 0 ),
			'sale_type'       => sanitize_key( (string) ( $_POST['sale_type'] ?? '' ) ),
				'operator_name'   => $operator_data['operator_name'],
				'operator_email'  => $operator_data['operator_email'],
				'operator_phone'  => $operator_data['operator_phone'],
			'whatsapp_msg'    => sanitize_textarea_field( (string) ( $_POST['whatsapp_msg'] ?? '' ) ),
			'form_id'         => sanitize_text_field( (string) ( $_POST['form_id'] ?? '' ) ),
			'landing_url'     => esc_url_raw( (string) ( $_POST['landing_url'] ?? '' ) ),
			'cta_primary'     => sanitize_text_field( (string) ( $_POST['cta_primary'] ?? '' ) ),
			'cta_secondary'   => sanitize_text_field( (string) ( $_POST['cta_secondary'] ?? '' ) ),
			'funnel_template' => sanitize_key( (string) ( $_POST['funnel_template'] ?? '' ) ),
			'hero_image_id'   => absint( $_POST['hero_image_id'] ?? 0 ),
		];

		// Detect create vs update BEFORE writing (for the created event).
		$was_new = empty( Estandar_Core_Project_Registry::get( $project_id ) );

		$ok = Estandar_Core_Project_Registry::upsert( $data );

		if ( $ok ) {
			if ( $was_new ) {
				self::emit_project_created( $project_id, array(
					'source'         => 'form',
					'company_id'     => (string) ( $data['company_id'] ?? '' ),
					'operator_name'  => (string) ( $data['operator_name'] ?? '' ),
					'operator_email' => (string) ( $data['operator_email'] ?? '' ),
					'operator_phone' => (string) ( $data['operator_phone'] ?? '' ),
				) );
			}
			self::redirect_with_notice( 'success', 'Proyecto "' . $data['label'] . '" guardado.' );
		} else {
			self::redirect_with_notice( 'error', 'No se pudo guardar el proyecto. Verifica el log de errores.' );
		}
	}

	/**
	 * ESTANDAR_PROJECT_PROVISIONING_V1 — emit the canonical create event.
	 * Fires 'estandar_project_created' with an immutable Estandar_Project + intent.
	 * Guarded (CORE_OPTIONALITY): if provisioning classes are absent, no-op.
	 */
	private static function emit_project_created( string $project_id, array $intent ): void {
		if ( ! class_exists( 'Estandar_Project' ) || ! class_exists( 'Estandar_Core_Project_Registry' ) ) {
			return;
		}
		$row = Estandar_Core_Project_Registry::get( $project_id );
		if ( ! is_array( $row ) || empty( $row ) ) {
			return;
		}
		do_action( 'estandar_project_created', Estandar_Project::from_registry_row( $row ), $intent );
	}

	// ---------------------------------------------------------------------------
	// Quick Create handler (P2) — create-only; reuses the canonical upsert() path.
	// ---------------------------------------------------------------------------

	public static function handle_quick_create(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'No autorizado.' );
		}

		check_admin_referer( self::NONCE_SAVE );

		if ( ! class_exists( 'Estandar_Core_Project_Registry' ) ) {
			self::redirect_with_notice( 'error', 'Estandar_Core_Project_Registry no disponible.' );
			return;
		}

		$project_id = sanitize_key( (string) ( $_POST['project_id'] ?? '' ) );
		if ( $project_id === '' || ! preg_match( '/^[a-z0-9\-]{2,64}$/', $project_id ) ) {
			self::redirect_with_notice( 'error', 'Slug inválido. Usa solo minúsculas, números y guiones (2–64 caracteres).' );
			return;
		}

		// Create-only: Quick Create never overwrites an existing project.
		if ( Estandar_Core_Project_Registry::get( $project_id ) !== null ) {
			self::redirect_with_notice( 'error', 'Ya existe un proyecto con el slug "' . $project_id . '".' );
			return;
		}

		$label = sanitize_text_field( (string) ( $_POST['label'] ?? '' ) );
		if ( $label === '' ) {
			$label = self::label_from_slug( $project_id );
		}

		$project_type = sanitize_key( (string) ( $_POST['project_type'] ?? 'other' ) );
		if ( ! array_key_exists( $project_type, Estandar_Core_Project_Registry::PROJECT_TYPES ) ) {
			$project_type = 'other';
		}

		$company_id = sanitize_key( (string) ( $_POST['company_id'] ?? '' ) );
		if ( $company_id === '' && function_exists( 'estandar_core_get_client_slug' ) ) {
			$company_id = sanitize_key( (string) estandar_core_get_client_slug() );
		}

		// Minimal payload mirroring handle_save()'s shape so upsert() behaves
		// identically to a full-form save with blank optional fields.
		$data = [
			'project_id'      => $project_id,
			'label'           => $label,
			'company_id'      => $company_id,
			'company'         => '',
			'project_type'    => $project_type,
			'city'            => '',
			'commune'         => '',
			'region'          => '',
			'active'          => true,
			'sort_order'      => 0,
			'url_patterns'    => [],
			'streets'         => [],
			'price_from'      => 0,
			'price_to'        => 0,
			'sale_type'       => '',
			'operator_name'   => '',
			'operator_email'  => '',
			'operator_phone'  => '',
			'whatsapp_msg'    => '',
			'form_id'         => '',
			'landing_url'     => '',
			'cta_primary'     => '',
			'cta_secondary'   => '',
			'funnel_template' => '',
			'hero_image_id'   => 0,
		];

		$ok = Estandar_Core_Project_Registry::upsert( $data );

		if ( ! $ok ) {
			self::redirect_with_notice( 'error', 'No se pudo crear el proyecto. Verifica el log de errores.' );
			return;
		}

		self::emit_project_created( $project_id, array( 'source' => 'quick', 'company_id' => $company_id ) );

		// Success → open the full edit form so the user can complete the rest.
		set_transient( 'estandar_projects_notice_' . get_current_user_id(), [
			'type'    => 'success',
			'message' => 'Proyecto "' . $label . '" creado. Completa los datos que necesites.',
		], 30 );
		wp_safe_redirect( add_query_arg(
			[ 'page' => self::PAGE_SLUG, 'action' => 'edit', 'project_id' => $project_id ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/** Titlecase a slug into a human label: 'mi-proyecto' → 'Mi Proyecto'. */
	private static function label_from_slug( string $slug ): string {
		$parts = array_filter( preg_split( '/[-_]+/', $slug ) );
		$parts = array_map( static fn( string $w ): string => ucfirst( $w ), $parts );
		return trim( implode( ' ', $parts ) );
	}

	// ---------------------------------------------------------------------------
	// Delete handler
	// ---------------------------------------------------------------------------

		public static function handle_delete(): void {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( 'No autorizado.' );
			}

		$project_id = sanitize_key( (string) ( $_GET['project_id'] ?? '' ) );
		check_admin_referer( self::NONCE_DELETE . '_' . $project_id );

		if ( ! class_exists( 'Estandar_Core_Project_Registry' ) ) {
			self::redirect_with_notice( 'error', 'Estandar_Core_Project_Registry no disponible.' );
			return;
		}

		$ok = Estandar_Core_Project_Registry::delete( $project_id );

		if ( $ok ) {
			self::redirect_with_notice( 'success', 'Proyecto eliminado.' );
		} else {
			self::redirect_with_notice( 'error', 'No se pudo eliminar el proyecto.' );
			}
		}

		private static function get_operator_options(): array {
			global $wpdb;

			if ( ! isset( $wpdb ) ) {
				return [];
			}

			$table = $wpdb->prefix . 'es_operators';
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				return [];
			}

			$rows = $wpdb->get_results(
				"SELECT id, name, email, whatsapp_number, active FROM {$table} WHERE active = 1 ORDER BY name ASC",
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : [];
		}

			private static function find_selected_operator_id( array $operators, array $project ): int {
				$matches = self::operator_matches( $operators, [
					'operator_name'  => (string) ( $project['operator_name'] ?? '' ),
					'operator_email' => (string) ( $project['operator_email'] ?? '' ),
					'operator_phone' => (string) ( $project['operator_phone'] ?? '' ),
				] );
				return count( $matches ) === 1 ? (int) ( $matches[0]['id'] ?? 0 ) : 0;
			}

			private static function operator_matches( array $operators, array $project ): array {
				$email = sanitize_email( (string) ( $project['operator_email'] ?? '' ) );
				$phone = preg_replace( '/\D+/', '', (string) ( $project['operator_phone'] ?? '' ) );
				$name  = sanitize_text_field( (string) ( $project['operator_name'] ?? '' ) );
				$has_operator_data = $email !== '' || $phone !== '' || $name !== '';
				if ( ! $has_operator_data ) {
					return [];
				}

				$matches = [];
				foreach ( $operators as $operator ) {
					$operator_email = sanitize_email( (string) ( $operator['email'] ?? '' ) );
					$operator_phone = preg_replace( '/\D+/', '', (string) ( $operator['whatsapp_number'] ?? '' ) );
					$operator_name  = sanitize_text_field( (string) ( $operator['name'] ?? '' ) );
					if ( $email !== '' && $operator_email !== $email ) {
						continue;
					}
					if ( $phone !== '' && $operator_phone !== $phone ) {
						continue;
					}
					if ( $name !== '' && $operator_name !== $name ) {
						continue;
					}
					$matches[] = $operator;
				}

				return $matches;
			}

		private static function get_form_options(): array {
			global $wpdb;

			if ( ! isset( $wpdb ) ) {
				return [];
			}

			$table = $wpdb->prefix . 'estandar_forms';
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				return [];
			}

			$rows = $wpdb->get_results(
				"SELECT id, name, slug, form_type FROM {$table} ORDER BY name ASC",
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : [];
		}

		// ---------------------------------------------------------------------------
		// Field helpers
		// ---------------------------------------------------------------------------

		private static function field_operator_select( array $operators, int $selected_operator_id, array $project ): void {
			?>
			<p>
				<label style="display:block;font-weight:600;margin-bottom:4px;">Operador principal</label>
				<?php if ( ! empty( $operators ) ) : ?>
					<select id="estandar-project-operator-select" style="width:100%;max-width:360px;">
						<option value="">— Seleccionar operador —</option>
						<?php foreach ( $operators as $operator ) :
							$id    = (int) ( $operator['id'] ?? 0 );
							$name  = sanitize_text_field( (string) ( $operator['name'] ?? '' ) );
							$email = sanitize_email( (string) ( $operator['email'] ?? '' ) );
							$phone = preg_replace( '/\D+/', '', (string) ( $operator['whatsapp_number'] ?? '' ) );
							if ( $id <= 0 || $name === '' ) {
								continue;
							}
						?>
							<option
								value="<?php echo esc_attr( (string) $id ); ?>"
								data-name="<?php echo esc_attr( $name ); ?>"
								data-email="<?php echo esc_attr( $email ); ?>"
								data-phone="<?php echo esc_attr( $phone ); ?>"
								<?php selected( $selected_operator_id, $id ); ?>
							>
								<?php echo esc_html( $name . ( $email !== '' ? ' · ' . $email : '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span style="display:block;color:#666;font-size:12px;margin-top:2px;">La ficha guarda una copia de contacto del operador seleccionado. La administración de operadores vive en Leads.</span>
				<?php else : ?>
					<span style="display:block;color:#666;">No hay operadores activos disponibles.</span>
				<?php endif; ?>
				<?php
				// Contextual CTA — create an operator without leaving the project flow.
				// Reuses the ONLY operators admin (Leads); passes the current project +
				// return params so the user comes back here after saving.
				$op_proj_slug = sanitize_key( (string) ( $project['project_id'] ?? '' ) );
				if ( $op_proj_slug !== '' ) {
					$op_new_url = add_query_arg( [
						'page'           => 'estandar_projects_operators',
						'project_slug'   => $op_proj_slug,
						'return_to'      => 'project',
						'return_project' => $op_proj_slug,
					], admin_url( 'admin.php' ) ) . '#operator-form';
					$op_cta_label = '+ Crear operador para este proyecto';
				} else {
					// Fallback: no persisted project yet → general operators screen.
					$op_new_url   = admin_url( 'admin.php?page=estandar_projects_operators#operator-form' );
					$op_cta_label = 'Administrar operadores';
				}
				?>
				<a href="<?php echo esc_url( $op_new_url ); ?>" class="button button-secondary" style="display:inline-block;margin-top:8px;"><?php echo esc_html( $op_cta_label ); ?></a>
				<input type="hidden" id="operator_name" name="operator_name" value="<?php echo esc_attr( (string) ( $project['operator_name'] ?? '' ) ); ?>">
				<input type="hidden" id="operator_email" name="operator_email" value="<?php echo esc_attr( (string) ( $project['operator_email'] ?? '' ) ); ?>">
				<input type="hidden" id="operator_phone" name="operator_phone" value="<?php echo esc_attr( (string) ( $project['operator_phone'] ?? '' ) ); ?>">
			</p>
			<?php
		}

		private static function field_form_select( array $forms, string $selected_form_slug ): void {
			$known = false;
			?>
			<p>
				<label style="display:block;font-weight:600;margin-bottom:4px;">Formulario principal</label>
				<select name="form_id" style="width:100%;max-width:360px;">
					<option value="">— Seleccionar formulario —</option>
					<?php foreach ( $forms as $form ) :
						$slug = sanitize_title( remove_accents( (string) ( $form['slug'] ?? '' ) ) );
						$name = sanitize_text_field( (string) ( $form['name'] ?? $slug ) );
						if ( $slug === '' ) {
							continue;
						}
						if ( $slug === $selected_form_slug ) {
							$known = true;
						}
					?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected_form_slug, $slug ); ?>>
							<?php echo esc_html( $name . ' (' . $slug . ')' ); ?>
						</option>
					<?php endforeach; ?>
					<?php if ( $selected_form_slug !== '' && ! $known ) : ?>
						<option value="<?php echo esc_attr( $selected_form_slug ); ?>" selected>
							<?php echo esc_html( 'Legacy: ' . $selected_form_slug ); ?>
						</option>
					<?php endif; ?>
				</select>
				<span style="display:block;color:#666;font-size:12px;margin-top:2px;">Referencia al formulario principal. El routing operativo sigue viviendo en Forms.</span>
			</p>
			<?php
		}

		private static function field_text( string $name, string $label, string $value, array $args = [] ): void {
		$type        = $args['type']        ?? 'text';
		$required    = ! empty( $args['required'] );
		$placeholder = $args['placeholder'] ?? '';
		$help        = $args['help']        ?? '';
		$readonly    = ! empty( $args['readonly'] );
		$pattern     = $args['pattern']     ?? '';
		?>
		<p>
			<label style="display:block;font-weight:600;margin-bottom:4px;">
				<?php echo esc_html( $label ); ?>
				<?php if ( $required ) : ?><span style="color:#b32d2e;">*</span><?php endif; ?>
			</label>
			<input
				type="<?php echo esc_attr( $type ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				class="regular-text"
				style="width:100%;"
				<?php if ( $placeholder ) echo 'placeholder="' . esc_attr( $placeholder ) . '"'; ?>
				<?php if ( $required )    echo 'required'; ?>
				<?php if ( $readonly )    echo 'readonly style="background:#f6f6f6;width:100%;"'; ?>
				<?php if ( $pattern )     echo 'pattern="' . esc_attr( $pattern ) . '"'; ?>
			>
			<?php if ( $help ) : ?>
				<span style="display:block;color:#666;font-size:12px;margin-top:2px;"><?php echo esc_html( $help ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function field_number( string $name, string $label, int $value ): void {
		?>
		<p>
			<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_html( $label ); ?></label>
			<input type="number" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>"
			       class="small-text" min="0" step="10">
		</p>
		<?php
	}

	private static function field_price( string $name, string $label, int $value ): void {
		?>
		<p>
			<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_html( $label ); ?></label>
			<div style="display:flex;align-items:center;gap:6px;">
				<span style="color:#666;">UF</span>
				<input type="number" name="<?php echo esc_attr( $name ); ?>"
				       value="<?php echo esc_attr( $value > 0 ? (string) $value : '' ); ?>"
				       class="regular-text" min="0" step="1" placeholder="0"
				       style="width:140px;">
			</div>
			<span style="display:block;color:#666;font-size:12px;margin-top:2px;">
				Entero sin decimales. La UI formatea el separador de miles.
			</span>
		</p>
		<?php
	}

	private static function field_checkbox( string $name, string $label, bool $checked ): void {
		?>
		<p>
			<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1"
				       <?php checked( $checked ); ?>>
				<?php echo esc_html( $label ); ?>
			</label>
		</p>
		<?php
	}

	private static function field_select( string $name, string $label, string $value, array $options, string $placeholder = '', array $args = [] ): void {
		$help = $args['help'] ?? '';
		?>
		<p>
			<label style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_html( $label ); ?></label>
			<select name="<?php echo esc_attr( $name ); ?>" style="width:100%;max-width:300px;">
				<?php if ( $placeholder ) : ?>
					<option value=""><?php echo esc_html( $placeholder ); ?></option>
				<?php endif; ?>
				<?php foreach ( $options as $key => $display ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
						<?php echo esc_html( $display ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $help ) : ?>
				<span style="display:block;color:#666;font-size:12px;margin-top:2px;"><?php echo esc_html( $help ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function field_textarea( string $name, string $label, string $value, array $args = [] ): void {
		$rows        = $args['rows']        ?? 3;
		$help        = $args['help']        ?? '';
		$placeholder = $args['placeholder'] ?? '';
		$id          = $args['id']          ?? '';
		?>
		<p>
			<label <?php if ( $id ) echo 'for="' . esc_attr( $id ) . '"'; ?> style="display:block;font-weight:600;margin-bottom:4px;"><?php echo esc_html( $label ); ?></label>
			<textarea
				<?php if ( $id ) echo 'id="' . esc_attr( $id ) . '"'; ?>
				name="<?php echo esc_attr( $name ); ?>"
				rows="<?php echo esc_attr( (string) $rows ); ?>"
				style="width:100%;font-family:monospace;font-size:12px;"
				<?php if ( $placeholder ) echo 'placeholder="' . esc_attr( $placeholder ) . '"'; ?>
			><?php echo esc_textarea( $value ); ?></textarea>
			<?php if ( $help ) : ?>
				<span style="display:block;color:#666;font-size:12px;margin-top:2px;"><?php echo esc_html( $help ); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	private static function sanitize_url_patterns( string $raw ): array {
		$patterns = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ?: [] ) );
		$legacy_placeholders = [
			'/proyecto/proyecto/contacto',
		];

		return array_values( array_filter( $patterns, static function ( string $pattern ) use ( $legacy_placeholders ): bool {
			return ! in_array( $pattern, $legacy_placeholders, true );
		} ) );
	}

	// ---------------------------------------------------------------------------
	// Notice helpers
	// ---------------------------------------------------------------------------

	private static function redirect_with_notice( string $type, string $message ): void {
		$redirect_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		set_transient( 'estandar_projects_notice_' . get_current_user_id(), [
			'type'    => $type,
			'message' => $message,
		], 30 );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	private static function get_transient_notice(): ?array {
		$key    = 'estandar_projects_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			return $notice;
		}
		return null;
	}
}

endif; // class_exists
