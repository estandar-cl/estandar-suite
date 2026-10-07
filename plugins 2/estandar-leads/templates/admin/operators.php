<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php
$client_slug = function_exists( 'estandar_core_get_client_slug' ) ? estandar_core_get_client_slug() : '';
?>

<div style="border-left:4px solid <?php echo esc_attr( $bc['border'] ); ?>;background:<?php echo esc_attr( $bc['bg'] ); ?>;padding:14px 18px;margin-bottom:24px;border-radius:0 4px 4px 0;">
	<div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:10px;">
		<div style="flex:1;">
			<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
				<strong style="font-size:13px;">
					<?php
					if ( $registry_ok ) {
						echo esc_html( $registry_count ) . ' proyecto' . ( $registry_count !== 1 ? 's' : '' ) . ' configurado' . ( $registry_count !== 1 ? 's' : '' );
					} else {
						echo 'Sin proyectos configurados';
					}
					?>
				</strong>
				<span style="background:<?php echo esc_attr( $bc['badge_bg'] ); ?>;color:<?php echo esc_attr( $bc['badge_text'] ); ?>;font-size:11px;font-weight:700;padding:2px 8px;border-radius:3px;letter-spacing:.5px;">
					<?php echo esc_html( $audit_state ); ?>
				</span>
			</div>
			<p style="margin:0;font-size:11px;color:#9ca3af;">
				Origen: <strong><?php echo esc_html( $origen_label ); ?></strong>
				<?php if ( ! $chatbot_active && ! $core_projects_admin_active ) : ?>
					<span style="color:#d63638;"> · Chatbot inactivo</span>
				<?php endif; ?>
			</p>
		</div>
		<div style="flex-shrink:0;padding-top:2px;">
			<?php if ( $core_projects_admin_active || $chatbot_active ) : ?>
				<a href="<?php echo esc_url( $manage_url ); ?>" class="button button-small"><?php echo esc_html( $manage_label ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( $chatbot_page_url ); ?>" class="button button-small">Activar Chatbot →</a>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $registry_ok ) : ?>
		<ul style="margin:0 0 10px;padding-left:0;list-style:none;">
			<?php foreach ( $project_registry as $proj ) :
				$proj_name   = ! empty( $proj['name'] ) ? (string) $proj['name'] : (string) $proj['project_slug'];
				$proj_slug   = (string) $proj['project_slug'];
				$proj_active = isset( $proj['active'] ) ? (bool) $proj['active'] : true;
			?>
				<li style="padding:5px 0;border-bottom:1px solid rgba(0,0,0,.06);display:flex;align-items:center;gap:8px;font-size:13px;">
					<span style="color:#00a32a;font-weight:700;font-size:11px;">✓</span>
					<span style="font-weight:600;"><?php echo esc_html( $proj_name ); ?></span>
					<code style="font-size:11px;color:#6b7280;"><?php echo esc_html( $proj_slug ); ?></code>
					<?php if ( ! $proj_active ) : ?>
						<span style="font-size:11px;color:#9ca3af;font-style:italic;">inactivo</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $ops_warnings > 0 ) : ?>
			<p style="margin:0;font-size:12px;color:#d63638;">
				⚠ <?php echo esc_html( $ops_warnings ); ?> operador<?php echo $ops_warnings !== 1 ? 'es' : ''; ?> con slug no encontrado en el registry.
				Edita el operador para corregir el slug.
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $projects_without_operator ) ) : ?>
			<p style="margin:8px 0 0;font-size:12px;color:#8a6100;">
				⚠ Proyecto<?php echo count( $projects_without_operator ) !== 1 ? 's' : ''; ?> sin operador asignado:
				<?php foreach ( $projects_without_operator as $missing_slug ) : ?>
					<code><?php echo esc_html( $missing_slug ); ?></code>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $lead_projects_without_operator ) ) : ?>
			<p style="margin:8px 0 0;font-size:12px;color:#8a6100;">
				⚠ Leads recibidos con proyecto sin operador:
				<?php foreach ( $lead_projects_without_operator as $missing_slug ) : ?>
					<code><?php echo esc_html( $missing_slug ); ?></code>
					<?php if ( $core_projects_admin_active && $missing_slug !== '' ) :
						$create_url = admin_url( 'admin.php?page=estandar_projects&action=edit&project_id=' . urlencode( $missing_slug ) );
					?>
						<a href="<?php echo esc_url( $create_url ); ?>" style="font-size:11px;margin-left:4px;">→ Crear proyecto</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</p>
			<?php if ( $client_slug !== '' && in_array( $client_slug, $lead_projects_without_operator, true ) && $core_projects_admin_active ) : ?>
				<p style="margin:4px 0 0;font-size:11px;color:#6b7280;">
					El slug <code><?php echo esc_html( $client_slug ); ?></code> coincide con la identidad de esta instalación (<strong>estandar_client_slug</strong>).
					Crear el proyecto con ese slug resuelve el routing automáticamente.
				</p>
			<?php endif; ?>
		<?php endif; ?>
	<?php else : ?>
		<p style="margin:0 0 6px;font-size:13px;">
			<strong>El routing por proyecto está desactivado.</strong>
			Los leads no pueden resolverse a un operador específico.
		</p>
		<?php if ( ! $chatbot_active ) : ?>
			<p style="margin:0 0 4px;font-size:12px;color:#374151;">Chatbot está inactivo. Active Chatbot para acceder a la administración de proyectos.</p>
		<?php else : ?>
			<p style="margin:0 0 4px;font-size:12px;color:#374151;">Chatbot está activo pero no tiene proyectos configurados. Ve a <strong>Chatbot → Captura y salida</strong> para agregar proyectos.</p>
		<?php endif; ?>
		<p style="margin:0;font-size:11px;color:#9ca3af;">Si esta instalación opera sin Chatbot, esto refleja una dependencia arquitectónica pendiente de resolución.</p>
	<?php endif; ?>
</div>

<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p>Operador guardado.</p></div><?php endif; ?>
<?php if ( isset( $_GET['deleted'] ) ) : ?><div class="notice notice-success inline" style="margin-bottom:16px;"><p>Operador eliminado.</p></div><?php endif; ?>
<?php if ( isset( $_GET['historical_operator'] ) ) : ?><div class="notice notice-warning inline" style="margin-bottom:16px;"><p>Operador convertido a histórico. Conserva <?php echo esc_html( absint( $_GET['operator_leads'] ?? 0 ) ); ?> leads asociados.</p></div><?php endif; ?>
<?php if ( isset( $_GET['orphan_recovered'] ) ) : ?><div class="notice notice-success inline" style="margin-bottom:16px;"><p>Leads huérfanos recuperados: <?php echo esc_html( absint( $_GET['orphan_recovered'] ) ); ?>.</p></div><?php endif; ?>

<?php if ( $diagnostic && $focus_project_slug ) : ?>
	<?php if ( 'missing_operator' === $diagnostic ) : ?>
		<div style="border:1px solid #dba617;border-left:4px solid #dba617;background:#fffbeb;padding:16px 18px;margin:0 0 20px;border-radius:0 4px 4px 0;">
			<h2 style="margin:0 0 8px;font-size:18px;">Proyecto sin operador detectado</h2>
			<p style="margin:0 0 10px;">
				Proyecto: <strong><?php echo esc_html( $focus_project_label ); ?></strong>
				<code style="margin-left:6px;"><?php echo esc_html( $focus_project_slug ); ?></code>
			</p>
			<?php if ( ! empty( $active_operators ) ) : ?>
				<p style="margin:0 0 10px;"><strong>Acción requerida:</strong> seleccione un operador para este proyecto.</p>
				<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
					<?php foreach ( $active_operators as $operator ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar_projects_operators&edit_operator=' . absint( $operator->id ) . '&project_slug=' . rawurlencode( $focus_project_slug ) . '&diagnostic=missing_operator#operator-form' ) ); ?>">
							<?php echo esc_html( $operator->name ); ?>
						</a>
					<?php endforeach; ?>
				</div>
				<p class="description" style="margin:0;">Al seleccionar un operador, el formulario se abrirá con este proyecto marcado. Revise y guarde los cambios.</p>
			<?php else : ?>
				<p style="margin:0 0 10px;"><strong>No existen operadores configurados.</strong></p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar_projects_operators#operator-form' ) ); ?>">Crear operador</a>
				<p class="description" style="margin:8px 0 0;">El formulario de creación ya tiene este proyecto preseleccionado.</p>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="notice notice-warning">
			<p><strong>Está corrigiendo una incidencia detectada por Estado.</strong><br>
			Proyecto: <code><?php echo esc_html( $focus_project_slug ); ?></code></p>
			<p class="description">Complete o revise operador y WhatsApp para este proyecto.</p>
		</div>
	<?php endif; ?>
<?php endif; ?>

<div style="border:1px solid #dcdcde;background:#fff;padding:14px 16px;margin:18px 0 24px;border-radius:4px;">
	<h2 style="margin-top:0;">Operadores huérfanos</h2>
	<p style="margin:0 0 8px;">
		Total leads: <strong><?php echo esc_html( (int) $orphan_operator_report['total'] ); ?></strong> ·
		Huérfanos: <strong><?php echo esc_html( (int) $orphan_operator_report['orphan_count'] ); ?></strong> ·
		Recuperables automáticamente: <strong><?php echo esc_html( (int) $orphan_operator_report['recoverable_count'] ); ?></strong>
	</p>
	<?php if ( ! empty( $orphan_operator_report['by_orphan_operator'] ) ) : ?>
		<table class="widefat striped" style="margin:8px 0 10px;">
			<thead><tr><th>Operator ID histórico</th><th>Nombre</th><th>Email</th><th>Leads</th></tr></thead>
			<tbody>
			<?php foreach ( $orphan_operator_report['by_orphan_operator'] as $item ) : ?>
				<tr>
					<td><?php echo esc_html( (int) $item['operator_id'] ); ?></td>
					<td><?php echo esc_html( $item['name'] ?: 'Operador histórico eliminado' ); ?></td>
					<td><?php echo esc_html( $item['email'] ); ?></td>
					<td><?php echo esc_html( (int) $item['count'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p style="margin:8px 0 4px;"><strong>Por proyecto:</strong>
			<?php foreach ( $orphan_operator_report['by_project'] as $project => $count ) : ?>
				<code><?php echo esc_html( $project ); ?>: <?php echo esc_html( (int) $count ); ?></code>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>
	<?php if ( (int) $orphan_operator_report['recoverable_count'] > 0 ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;" onsubmit="return confirm('Recuperar ownership de leads huérfanos sin emails, eventos ni cambios de estado?');">
			<?php wp_nonce_field( 'estandar_leads_recover_orphan_operators' ); ?>
			<input type="hidden" name="action" value="estandar_leads_recover_orphan_operators">
			<button type="submit" class="button button-primary">Recuperar huérfanos recuperables</button>
		</form>
	<?php endif; ?>
</div>

<h2 id="operator-form"><?php echo $editing ? 'Editar operador: ' . esc_html( $editing->name ) : 'Crear operador'; ?></h2>
<?php if ( $editing ) : ?>
<script>document.addEventListener('DOMContentLoaded',function(){document.getElementById('operator-form')?.scrollIntoView({behavior:'smooth',block:'start'});});</script>
<?php endif; ?>
<?php if ( $return_to === 'project' && $return_project !== '' ) : ?>
<p class="description" style="margin:4px 0 10px;padding:8px 12px;background:#f0f6fc;border:1px solid #c3d9ed;border-radius:4px;">
	Creando operador para el proyecto <code><?php echo esc_html( $focus_project_label !== '' ? $focus_project_label : $return_project ); ?></code>.
	Al guardar volverás a la ficha del proyecto.
</p>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px;">
	<?php wp_nonce_field( 'estandar_leads_save_operator' ); ?>
	<input type="hidden" name="action" value="estandar_leads_save_operator">
	<input type="hidden" name="operator_id" value="<?php echo esc_attr( $editing ? $editing->id : 0 ); ?>">
	<?php if ( $return_to !== '' ) : ?>
	<input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>">
	<input type="hidden" name="return_project" value="<?php echo esc_attr( $return_project ); ?>">
	<?php endif; ?>
	<table class="form-table">
		<tr><th><label for="op_name">Nombre</label></th><td><input class="regular-text" id="op_name" name="name" value="<?php echo esc_attr( $editing ? $editing->name : '' ); ?>" required></td></tr>
		<tr><th><label for="op_email">Email</label></th><td><input class="regular-text" id="op_email" name="email" type="email" value="<?php echo esc_attr( $editing ? $editing->email : '' ); ?>"></td></tr>
		<tr><th><label for="op_project_slug">Proyectos workspace</label></th><td>
			<?php
			$selected_projects = $editing ? Estandar_Leads_Operators_Admin::allowed_projects( $editing ) : Estandar_Leads_Operator_Workspace::sanitize_projects( array( $focus_project_slug ) );
			if ( $editing && 'missing_operator' === $diagnostic && $focus_project_slug !== '' ) {
				$selected_projects = Estandar_Leads_Operator_Workspace::sanitize_projects( array_merge( $selected_projects, array( $focus_project_slug ) ) );
			}
			?>
			<?php if ( ! empty( $project_registry ) ) : ?>
				<select id="op_project_slug" name="allowed_projects[]" multiple size="6" style="min-width:260px;">
					<?php foreach ( $project_registry as $project ) : ?>
						<?php
						$slug = sanitize_title( remove_accents( (string) ( $project['project_slug'] ?? '' ) ) );
						$label = sanitize_text_field( (string) ( $project['name'] ?? $slug ) );
						?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( in_array( $slug, $selected_projects, true ) ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">Estos son los únicos proyectos visibles en el workspace público.</p>
			<?php else : ?>
				<input class="regular-text" id="op_project_slug" name="allowed_projects[]" value="<?php echo esc_attr( $editing ? $editing->project_slug : '' ); ?>">
				<p class="description" style="color:#d63638;">⚠ Registry vacío. El valor ingresado no puede validarse.</p>
			<?php endif; ?>
			<input type="hidden" name="area" value="<?php echo esc_attr( $editing ? $editing->area : '' ); ?>">
		</td></tr>
		<tr><th><label for="op_whatsapp">WhatsApp</label></th><td>
			<input class="regular-text" id="op_whatsapp" name="whatsapp_number" inputmode="numeric" placeholder="56912345678"
				value="<?php echo esc_attr( $editing ? ( $editing->whatsapp_number ?? '' ) : '' ); ?>">
			<p class="description">Formato internacional sin + ni espacios. Ej: 56912345678</p>
		</td></tr>
		<tr><th>Activo</th><td><label><input type="checkbox" name="active" value="1" <?php checked( $editing ? (int) $editing->active : 1, 1 ); ?>> Marcar como activo</label></td></tr>
		<tr>
			<th><label for="op_hours_enabled">Horario operacional</label></th>
			<td>
				<?php
				// Use the effective operator hours config (with global fallback, if available).
				$op_hours = null;
				if ( class_exists( 'Estandar_Leads_Operator_Hours' ) && $editing ) {
					$op_hours = Estandar_Leads_Operator_Hours::config_for( $editing );
				}
				if ( ! is_array( $op_hours ) ) {
					$op_hours = array( 'enabled' => false, 'timezone' => 'America/Santiago', 'schedule' => array() );
				}
				$hours_enabled = ! empty( $op_hours['enabled'] );
				$hours_tz      = $op_hours['timezone'] ?? 'America/Santiago';
				$hours_sched   = $op_hours['schedule'] ?? array();
				$weekdays      = array( 'mon' => 'Lunes', 'tue' => 'Martes', 'wed' => 'Miércoles', 'thu' => 'Jueves', 'fri' => 'Viernes', 'sat' => 'Sábado', 'sun' => 'Domingo' );
				$has_own_config = $editing && ! empty( $editing->operational_hours_json ) && json_decode( $editing->operational_hours_json, true );
				?>
				<label style="display:block;margin-bottom:6px;">
					<input type="checkbox" name="operational_hours_enabled" id="op_hours_enabled" value="1" <?php checked( $hours_enabled ); ?> onchange="document.getElementById('op-hours-detail').style.display=this.checked?'block':'none'">
					Habilitar horario operacional para este operador
				</label>
				<div id="op-hours-detail" style="display:<?php echo $hours_enabled ? 'block' : 'none'; ?>;margin-left:20px;padding:8px;background:#f9f9f9;border-radius:4px;">
					<p style="margin:0 0 6px;">
						<label>Zona horaria:
							<select name="operational_hours_tz" style="max-width:280px;">
								<?php foreach ( timezone_identifiers_list() as $_tz ) : ?>
									<option value="<?php echo esc_attr( $_tz ); ?>" <?php selected( $hours_tz, $_tz ); ?>><?php echo esc_html( $_tz ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<?php foreach ( $weekdays as $day => $label ) :
						$day_data = $hours_sched[ $day ] ?? null;
					?>
						<p style="margin:2px 0;">
							<label style="display:inline-block;min-width:80px;"><?php echo esc_html( $label ); ?></label>
							<label><input type="checkbox" name="operational_hours_days[<?php echo esc_attr( $day ); ?>][active]" value="1" <?php checked( is_array( $day_data ) ); ?> onchange="this.closest('p').querySelectorAll('input[type=time]').forEach(function(e){e.disabled=!this.checked;}.bind(this));"> Abierto</label>
							<input type="time" name="operational_hours_days[<?php echo esc_attr( $day ); ?>][start]" value="<?php echo esc_attr( is_array( $day_data ) ? ( $day_data['start'] ?? '09:00' ) : '09:00' ); ?>" <?php disabled( ! is_array( $day_data ) ); ?> style="width:100px;">
							<span>a</span>
							<input type="time" name="operational_hours_days[<?php echo esc_attr( $day ); ?>][end]" value="<?php echo esc_attr( is_array( $day_data ) ? ( $day_data['end'] ?? '18:00' ) : '18:00' ); ?>" <?php disabled( ! is_array( $day_data ) ); ?> style="width:100px;">
						</p>
					<?php endforeach; ?>
					<p class="description" style="margin:6px 0 0;">Si se desactiva, los correos pendientes retenidos por horario se enviarán inmediatamente.</p>
					<?php if ( $editing && ! $has_own_config && $hours_enabled ) : ?>
						<p class="description" style="color:#d63638;">Estos valores provienen del horario operacional global. Al guardar, se copiarán como configuración propia del operador.</p>
					<?php endif; ?>
				</div>
			</td>
		</tr>
	</table>
	<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px;">
		<button type="submit" class="button button-primary"><?php echo $editing ? 'Actualizar operador' : 'Guardar operador'; ?></button>
		<?php if ( $editing ) : ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar_projects_operators' ) ); ?>">Cancelar</a>
		<?php endif; ?>
	</div>
</form>
<?php if ( $editing ) : ?>
	<?php $editing_leads = (int) ( $operator_lead_counts[ (int) $editing->id ] ?? 0 ); ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;" onsubmit="return confirm('<?php echo $editing_leads > 0 ? 'Este operador posee ' . esc_js( (string) $editing_leads ) . ' leads. Se convertirá a histórico.' : '¿Eliminar al operador ' . esc_js( $editing->name ) . '? Esta acción no se puede deshacer.'; ?>');">
		<?php wp_nonce_field( 'estandar_leads_delete_operator' ); ?>
		<input type="hidden" name="action" value="estandar_leads_delete_operator">
		<input type="hidden" name="operator_id" value="<?php echo esc_attr( $editing->id ); ?>">
		<button type="submit" class="button" style="color:#d63638;border-color:#d63638;"><?php echo $editing_leads > 0 ? 'Convertir a operador histórico' : 'Eliminar operador'; ?></button>
	</form>
<?php endif; ?>

<h2 style="margin-top:32px;">Operadores</h2>
<table class="widefat striped">
	<thead><tr><th>Nombre</th><th>Email</th><th>Proyectos</th><th>Workspace</th><th>WhatsApp</th><th>Activo</th><th>Acciones</th></tr></thead>
	<tbody>
	<?php foreach ( $operators as $operator ) : ?>
		<?php
		$op_projects = Estandar_Leads_Operators_Admin::allowed_projects( $operator );
		$op_slug    = (string) ( $op_projects[0] ?? ( $operator->project_slug ?? '' ) );
		$op_display = $op_slug ?: ( (string) ( $operator->area ?? '' ) );
		$fresh_token = get_transient( 'estandar_leads_workspace_token_' . get_current_user_id() . '_' . absint( $operator->id ) );
		if ( $fresh_token ) {
			delete_transient( 'estandar_leads_workspace_token_' . get_current_user_id() . '_' . absint( $operator->id ) );
		}

		if ( $op_slug === '' ) {
			$slug_badge = '<span style="color:#6b7280;font-size:11px;">— fallback</span>';
			$slug_issue = '';
		} elseif ( in_array( $op_slug, $registry_slugs, true ) ) {
			$slug_badge = '<span style="color:#00a32a;font-weight:700;" title="Slug encontrado en registry">✓</span>';
			$slug_issue = '';
		} else {
			$slug_badge = '<span style="color:#d63638;font-weight:700;" title="Slug no encontrado en registry. Routing inactivo.">⚠</span>';
			$suggestion = $registry_ok ? Estandar_Leads_Projects::suggest_slug( $op_slug, $registry_slugs ) : '';
			if ( $suggestion !== '' ) {
				$slug_issue = '<br><span style="font-size:11px;color:#6b7280;">¿Quisiste decir <code>' . esc_html( $suggestion ) . '</code>? '
					. '<a href="' . esc_url( admin_url( 'admin.php?page=estandar_projects_operators&edit_operator=' . absint( $operator->id ) ) ) . '#operator-form">Corregir</a>'
					. '</span>';
			} else {
				$slug_issue = '<br><span style="font-size:11px;color:#6b7280;">No coincide con ningún proyecto del registry.</span>';
			}
		}
		?>
		<tr>
			<td><?php echo esc_html( $operator->name ); ?></td>
			<td><?php echo esc_html( $operator->email ); ?></td>
			<td>
				<?php echo $slug_badge; // phpcs:ignore ?>
				<?php foreach ( $op_projects as $project_slug ) : ?>
					<code style="font-size:11px;margin-right:3px;"><?php echo esc_html( $project_slug ); ?></code>
				<?php endforeach; ?>
				<?php echo empty( $op_projects ) && $op_display !== '' ? ' <code style="font-size:11px;">' . esc_html( $op_display ) . '</code>' : ''; ?>
				<?php echo $slug_issue; // phpcs:ignore -- already escaped ?>
			</td>
			<td style="max-width:280px;">
				<?php if ( $fresh_token ) : ?>
					<input class="regular-text" readonly value="<?php echo esc_attr( Estandar_Leads_Operator_Workspace::workspace_url( $fresh_token ) ); ?>" style="max-width:100%;">
				<?php elseif ( ! empty( $operator->access_token_hash ) ) : ?>
					<span style="color:#00a32a;font-weight:600;">Token activo</span>
					<?php if ( ! empty( $operator->access_token_expires_at ) ) : ?><br><span style="font-size:11px;color:#646970;">Expira: <?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $operator->access_token_expires_at ) ); ?></span><?php endif; ?>
				<?php else : ?>
					<span style="color:#d63638;">Sin token</span>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:4px;">
					<?php wp_nonce_field( 'estandar_leads_rotate_operator_token' ); ?>
					<input type="hidden" name="action" value="estandar_leads_rotate_operator_token">
					<input type="hidden" name="operator_id" value="<?php echo esc_attr( $operator->id ); ?>">
					<button class="button button-small" type="submit">Rotar link</button>
				</form>
				<?php if ( ! empty( $operator->active ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" style="margin-top:4px;">
						<?php wp_nonce_field( 'estandar_leads_view_operator_workspace' ); ?>
						<input type="hidden" name="action" value="estandar_leads_view_operator_workspace">
						<input type="hidden" name="operator_id" value="<?php echo esc_attr( $operator->id ); ?>">
						<button class="button button-small button-primary" type="submit">Ver panel en vivo</button>
					</form>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $operator->whatsapp_number ?? '' ); ?></td>
			<td><?php echo $operator->active ? '<span style="color:#00a32a;font-weight:600;">Activo</span>' : '<span style="color:#6b7280;">Inactivo</span>'; ?></td>
			<td style="white-space:nowrap;">
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar_projects_operators&edit_operator=' . absint( $operator->id ) . '#operator-form' ) ); ?>">Editar</a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:4px;">
					<?php wp_nonce_field( 'estandar_leads_toggle_operator' ); ?>
					<input type="hidden" name="action" value="estandar_leads_toggle_operator">
					<input type="hidden" name="operator_id" value="<?php echo esc_attr( $operator->id ); ?>">
					<input type="hidden" name="active" value="<?php echo $operator->active ? '0' : '1'; ?>">
					<button class="button button-small" type="submit"><?php echo $operator->active ? 'Desactivar' : 'Activar'; ?></button>
				</form>
				<?php $op_leads = (int) ( $operator_lead_counts[ (int) $operator->id ] ?? 0 ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:4px;" onsubmit="return confirm('<?php echo $op_leads > 0 ? 'Este operador posee ' . esc_js( (string) $op_leads ) . ' leads. Se convertirá a histórico.' : '¿Eliminar a ' . esc_js( $operator->name ) . '?'; ?>');">
					<?php wp_nonce_field( 'estandar_leads_delete_operator' ); ?>
					<input type="hidden" name="action" value="estandar_leads_delete_operator">
					<input type="hidden" name="operator_id" value="<?php echo esc_attr( $operator->id ); ?>">
					<button class="button button-small" type="submit" style="color:#d63638;border-color:#d63638;"><?php echo $op_leads > 0 ? 'Histórico' : 'Eliminar'; ?></button>
				</form>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
