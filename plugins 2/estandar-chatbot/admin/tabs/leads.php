<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'leads' ) ) {
	wp_die( 'No autorizado.' );
}

$status = isset( $_GET['ecb_status'] ) ? sanitize_key( $_GET['ecb_status'] ) : '';
$msg    = isset( $_GET['ecb_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ecb_msg'] ) ) : '';

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 200;
if ( ! in_array( $limit, [50, 200, 500], true ) ) $limit = 200;
$paged = isset($_GET['paged']) ? max( 1, intval($_GET['paged']) ) : 1;

$total = function_exists('estandar_chatbot_count_leads') ? estandar_chatbot_count_leads() : 0;
$total_pages = max( 1, (int) ceil( $total / $limit ) );
if ( $paged > $total_pages ) {
	$paged = $total_pages;
}
$offset = ( $paged - 1 ) * $limit;
$leads = function_exists('estandar_chatbot_get_leads') ? estandar_chatbot_get_leads($limit, $offset) : [];

$download_url = wp_nonce_url(
	add_query_arg(
		'action',
		'estandar_chatbot_export_leads_csv',
		admin_url( 'admin-post.php' )
	),
	'ecb_download_leads'
);
$base_page_url = admin_url( 'admin.php?page=estandar-chatbot&tab=leads&limit=' . $limit );
$responsables = function_exists( 'estandar_chatbot_get_responsables' ) ? estandar_chatbot_get_responsables() : [];

$hot = 0;
$warm = 0;
$cold = 0;
foreach ( $leads as $r ) {
	$t = strtolower( (string) ( $r['lead_tier'] ?? '' ) );
	if ( $t === 'hot' ) $hot++;
	elseif ( $t === 'warm' ) $warm++;
	else $cold++;
}

function estandar_chatbot_admin_lead_tier_class( $tier ) {
	$tier = strtolower( (string) $tier );
	if ( $tier === 'hot' ) return 'ecb-badge ecb-badge--hot';
	if ( $tier === 'warm' ) return 'ecb-badge ecb-badge--warm';
	return 'ecb-badge ecb-badge--cold';
}

function estandar_chatbot_admin_lead_quality_class( $quality ) {
	$quality = strtoupper( (string) $quality );
	if ( $quality === 'HIGH' ) return 'ecb-badge ecb-badge--hot';
	if ( $quality === 'MEDIUM' ) return 'ecb-badge ecb-badge--warm';
	return 'ecb-badge ecb-badge--cold';
}

function estandar_chatbot_admin_commercial_priority_class( $priority ) {
	$priority = strtoupper( (string) $priority );
	if ( in_array( $priority, [ 'URGENTE', 'ALTA' ], true ) ) return 'ecb-badge ecb-badge--hot';
	if ( $priority === 'MEDIA' ) return 'ecb-badge ecb-badge--warm';
	return 'ecb-badge ecb-badge--cold';
}
?>
<div>
	<h3 class="ecb-section-title">Leads</h3>
	<p class="ecb-section-copy">Revisa rápido a quién contactar, qué tan caliente está cada lead y entra al detalle solo cuando lo necesites.</p>

	<?php if ( $status && $msg ) : ?>
		<div class="notice <?php echo $status === 'ok' ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html( $msg ); ?></p></div>
	<?php endif; ?>

	<div class="ecb-card-grid" style="margin:12px 0;">
		<div class="ecb-card ecb-stat-card ecb-stat-card--hot">
			<p class="ecb-metric"><strong>HOT</strong></p>
			<span class="ecb-stat-card__number"><?php echo esc_html( (string) $hot ); ?></span>
		</div>
		<div class="ecb-card ecb-stat-card ecb-stat-card--warm">
			<p class="ecb-metric"><strong>WARM</strong></p>
			<span class="ecb-stat-card__number"><?php echo esc_html( (string) $warm ); ?></span>
		</div>
		<div class="ecb-card ecb-stat-card ecb-stat-card--cold">
			<p class="ecb-metric"><strong>COLD</strong></p>
			<span class="ecb-stat-card__number"><?php echo esc_html( (string) $cold ); ?></span>
		</div>
		<div class="ecb-card ecb-stat-card ecb-stat-card--total">
			<p class="ecb-metric"><strong>TOTAL</strong></p>
			<span class="ecb-stat-card__number"><?php echo esc_html( (string) $total ); ?></span>
		</div>
	</div>

	<div class="ecb-toolbar">
		<a href="<?php echo esc_url($download_url); ?>" class="button">Descargar CSV completo</a>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
			<input type="hidden" name="action" value="estandar_chatbot_reprocess_lead_intents" />
			<?php wp_nonce_field( 'estandar_chatbot_reprocess_lead_intents' ); ?>
			<button class="button button-secondary" type="submit" onclick="return confirm('¿Recalcular intención y área usando URL de origen y reglas de texto en todos los leads?');">Reprocesar intenciones</button>
		</form>
		<form method="get" style="margin:0;display:flex;gap:8px;align-items:center;">
			<input type="hidden" name="page" value="estandar-chatbot" />
			<input type="hidden" name="tab" value="leads" />
			<label>
				Mostrar:
				<select name="limit">
					<option value="50"  <?php selected($limit, 50); ?>>50</option>
					<option value="200" <?php selected($limit, 200); ?>>200</option>
					<option value="500" <?php selected($limit, 500); ?>>500</option>
				</select>
			</label>
			<button class="button">Aplicar</button>
		</form>
		<input id="ecb-leads-search" type="search" placeholder="Buscar nombre, teléfono, intención o área..." style="min-width:260px;" />
	</div>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="ecb-toolbar" style="margin-top:0;">
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( $base_page_url . '&paged=' . ( $paged - 1 ) ); ?>">Anterior</a>
			<?php endif; ?>
			<span>Página <?php echo esc_html( (string) $paged ); ?> de <?php echo esc_html( (string) $total_pages ); ?></span>
			<?php if ( $paged < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( $base_page_url . '&paged=' . ( $paged + 1 ) ); ?>">Siguiente</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( empty($leads) ) : ?>
		<div class="notice notice-warning inline"><p>Aún no hay leads registrados.</p></div>
	<?php else : ?>
		<form id="ecb-leads-bulk-delete" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Eliminar los leads seleccionados?');" style="margin-bottom:8px;">
			<input type="hidden" name="action" value="estandar_chatbot_leads_bulk_delete" />
			<?php wp_nonce_field( 'estandar_chatbot_leads_bulk_delete' ); ?>
			<button class="button button-secondary" type="submit">Eliminar seleccionados</button>
		</form>

		<div class="ecb-table-wrap" style="margin-top:8px;">
			<table id="ecb-leads-table" class="widefat striped ecb-leads-table" style="min-width:1080px;">
				<thead>
					<tr>
						<th style="width:36px;"><input type="checkbox" id="ecb-leads-select-all" /></th>
						<th>Nombre</th>
						<th>Teléfono</th>
						<th>Estado</th>
						<th>Score</th>
						<th>Quality</th>
						<th>Commercial Priority</th>
						<th>Intención</th>
						<th>Mismatch</th>
						<th>Área / Proyecto</th>
						<th>Última interacción</th>
						<th style="width:250px;">Acción</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $leads as $lead ) :
						$lead_id = (int) ( $lead['_db_id'] ?? 0 );
						if ( $lead_id <= 0 ) continue;
						$name = (string) ( $lead['name'] ?? '' );
						$phone = (string) ( $lead['phone'] ?? '' );
						$contact_quality = (string) ( $lead['contact_quality'] ?? CONTACT_VALID );
						$contact_warning = (string) ( $lead['contact_warning'] ?? CONTACT_WARNING_NONE );
						$status_label = (string) ( $lead['lead_status'] ?? 'new' );
						$tier = (string) ( $lead['lead_tier'] ?? 'cold' );
						$score = (string) ( $lead['lead_score'] ?? '0' );
						$quality = function_exists( 'estandar_chatbot_lead_quality_label' ) ? estandar_chatbot_lead_quality_label( (int) $score ) : 'LOW';
						$commercial_priority = function_exists( 'estandar_chatbot_commercial_priority_label' ) ? estandar_chatbot_commercial_priority_label( (int) $score ) : 'BAJA';
						$intent = (string) ( $lead['intent'] ?? '' );
						$intent_label = function_exists( 'estandar_chatbot_humanize_lead_intent' ) ? estandar_chatbot_humanize_lead_intent( $intent, $lead ) : $intent;
						$intent_category = (string) ( $lead['intent_category'] ?? '' );
						$intent_category_label = function_exists( 'estandar_chatbot_humanize_intent_category' ) ? estandar_chatbot_humanize_intent_category( $intent_category ) : $intent_category;
						$intent_mismatch = ! empty( $lead['intent_mismatch'] );
						$project_area = (string) ( $lead['project_area'] ?? '' );
						$project_display = resolve_project_display_name( $lead['project_slug'] ?? '', $project_area );
						$last_interaction = (string) ( $lead['last_interaction_at'] ?? $lead['created_at'] ?? '' );
						$phone_link = preg_replace( '/\D+/', '', $phone );
						$suggested_responsable = function_exists( 'estandar_chatbot_find_suggested_responsable_index' ) ? estandar_chatbot_find_suggested_responsable_index( $lead, $responsables ) : -1;
						?>
						<tr data-row-kind="lead">
							<td><input type="checkbox" name="lead_ids[]" form="ecb-leads-bulk-delete" value="<?php echo esc_attr( (string) $lead_id ); ?>" class="ecb-lead-checkbox" /></td>
							<td>
								<div class="ecb-lead-main">
									<span class="ecb-lead-name"><?php echo esc_html( $name !== '' ? $name : 'Sin nombre' ); ?></span>
									<span class="ecb-lead-meta"><?php echo esc_html( (string) ( $lead['email'] ?? 'Sin email' ) ); ?></span>
									<?php if ( $contact_quality === CONTACT_SUSPECT ) : ?>
										<span class="ecb-badge ecb-badge--warm">⚠ Datos de contacto requieren revisión</span>
									<?php endif; ?>
								</div>
							</td>
							<td><?php echo esc_html( $phone !== '' ? $phone : 'Sin teléfono' ); ?></td>
							<td>
								<div style="display:flex;flex-direction:column;gap:6px;">
									<span class="<?php echo esc_attr( estandar_chatbot_admin_lead_tier_class( $tier ) ); ?>"><?php echo esc_html( strtoupper( $tier ) ); ?></span>
									<span class="ecb-badge ecb-badge--status"><?php echo esc_html( $status_label ); ?></span>
								</div>
							</td>
							<td><?php echo esc_html( $score ); ?>/100</td>
							<td><span class="<?php echo esc_attr( estandar_chatbot_admin_lead_quality_class( $quality ) ); ?>"><?php echo esc_html( $quality ); ?></span></td>
							<td><span class="<?php echo esc_attr( estandar_chatbot_admin_commercial_priority_class( $commercial_priority ) ); ?>"><?php echo esc_html( $commercial_priority ); ?></span></td>
							<td>
								<?php echo esc_html( $intent_label !== '' ? $intent_label : '-' ); ?>
								<div style="font-size:12px;color:#64748b;"><?php echo esc_html( $intent_category_label !== '' ? $intent_category_label : '-' ); ?></div>
							</td>
							<td><?php echo esc_html( $intent_mismatch ? 'Sí' : 'No' ); ?></td>
							<td><?php echo esc_html( $project_display !== '' ? $project_display : '-' ); ?></td>
							<td><?php echo esc_html( $last_interaction !== '' ? $last_interaction : '-' ); ?></td>
							<td>
								<div class=”ecb-action-stack”>
									<?php if ( $phone_link !== '' ) : ?>
										<a class=”button button-primary button-small” href=”tel:<?php echo esc_attr( $phone_link ); ?>”>Contactar</a>
									<?php endif; ?>
									<button type=”button” class=”button button-small ecb-toggle-lead-edit” data-target=”ecb-lead-edit-<?php echo esc_attr( (string) $lead_id ); ?>”>Ver detalle</button>
									<form method=”post” action=”<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>” style=”display:inline;” onsubmit=”return confirm('¿Eliminar este lead?');”>
										<input type=”hidden” name=”action” value=”estandar_chatbot_lead_delete” />
										<input type=”hidden” name=”lead_id” value=”<?php echo esc_attr( (string) $lead_id ); ?>” />
										<?php wp_nonce_field( 'estandar_chatbot_lead_delete' ); ?>
										<button class=”button button-small” type=”submit”>Borrar</button>
									</form>
								</div>
								<?php if ( ! empty( $responsables ) ) : ?>
								<form method=”post” action=”<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>”
									  style=”margin-top:8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;”
									  onsubmit=”return confirm('¿Enviar este lead a responsable?');”>
									<input type=”hidden” name=”action” value=”estandar_chatbot_lead_assign_responsable” />
									<input type=”hidden” name=”lead_id” value=”<?php echo esc_attr( (string) $lead_id ); ?>” />
									<input type=”hidden” name=”redirect_to” value=”<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=leads&limit=' . $limit . '&paged=' . $paged ) ); ?>” />
									<?php wp_nonce_field( 'estandar_chatbot_lead_assign_responsable' ); ?>
									<select name=”responsable_index” required style=”font-size:12px;max-width:160px;”>
										<option value=””>— Responsable —</option>
										<?php foreach ( $responsables as $responsable_index => $responsable ) :
											$nombre = (string) ( $responsable['nombre'] ?? '' );
											$area   = (string) ( $responsable['area'] ?? '' );
											if ( $nombre === '' ) continue;
											$rlabel = $nombre . ( $area !== '' ? ' (' . $area . ')' : '' );
											?>
											<option value=”<?php echo esc_attr( (string) $responsable_index ); ?>”
												<?php selected( $suggested_responsable, (int) $responsable_index ); ?>>
												<?php echo esc_html( $rlabel ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<button class=”button button-small” type=”submit”>Enviar →</button>
								</form>
								<?php elseif ( ! empty( $lead['assigned_to'] ) ) : ?>
									<div style=”margin-top:6px;font-size:12px;color:#475569;”>Asignado a: <?php echo esc_html( (string) $lead['assigned_to'] ); ?></div>
								<?php endif; ?>
								<?php if ( ! empty( $lead['assigned_to'] ) && ! empty( $responsables ) ) : ?>
									<div style=”margin-top:4px;font-size:11px;color:#64748b;”>✓ Enviado a: <?php echo esc_html( (string) $lead['assigned_to'] ); ?></div>
								<?php endif; ?>
								</td>
							</tr>
							<tr id=”ecb-lead-edit-<?php echo esc_attr( (string) $lead_id ); ?>” style=”display:none;background:#fafafa;” data-row-kind=”lead-edit”>
							<td colspan="12">
								<?php if ( $contact_quality === CONTACT_SUSPECT ) : ?>
									<p><span class="ecb-badge ecb-badge--warm">⚠ Datos de contacto requieren revisión</span> <?php echo esc_html( $contact_warning ); ?></p>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecb-card">
									<input type="hidden" name="action" value="estandar_chatbot_lead_update" />
									<input type="hidden" name="lead_id" value="<?php echo esc_attr( (string) $lead_id ); ?>" />
									<?php wp_nonce_field( 'estandar_chatbot_lead_update' ); ?>
									<div class="ecb-card-grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">
										<div><label>Nombre</label><input type="text" name="name" value="<?php echo esc_attr( $name ); ?>" style="width:100%;" /></div>
										<div><label>Email</label><input type="email" name="email" value="<?php echo esc_attr( (string) ($lead['email'] ?? '') ); ?>" style="width:100%;" /></div>
										<div><label>Teléfono</label><input type="text" name="phone" value="<?php echo esc_attr( $phone ); ?>" style="width:100%;" /></div>
										<?php if ( ! empty( $lead['phone_raw'] ) ) : ?><div><label>Teléfono original</label><input type="text" value="<?php echo esc_attr( (string) $lead['phone_raw'] ); ?>" readonly style="width:100%;" /></div><?php endif; ?>
										<?php if ( ! empty( $lead['email_raw'] ) ) : ?><div><label>Email original</label><input type="text" value="<?php echo esc_attr( (string) $lead['email_raw'] ); ?>" readonly style="width:100%;" /></div><?php endif; ?>
										<div><label>Intent</label><input type="text" name="intent" value="<?php echo esc_attr( $intent ); ?>" style="width:100%;" /></div>
										<div><label>Intent Category</label><input type="text" name="intent_category" value="<?php echo esc_attr( $intent_category ); ?>" style="width:100%;" /></div>
										<div>
											<label>Intent Mismatch</label>
											<select name="intent_mismatch" style="width:100%;">
												<option value="0" <?php selected( $intent_mismatch, false ); ?>>No</option>
												<option value="1" <?php selected( $intent_mismatch, true ); ?>>Sí</option>
											</select>
										</div>
										<div><label>Área / Proyecto</label><input type="text" name="project_area" value="<?php echo esc_attr( $project_area ); ?>" style="width:100%;" /></div>
										<div>
											<label>Estado</label>
											<select name="lead_status" style="width:100%;">
												<?php foreach ( ['new','contacted','qualified','proposal','won','lost','spam'] as $lead_status_opt ) : ?>
													<option value="<?php echo esc_attr( $lead_status_opt ); ?>" <?php selected( $status_label, $lead_status_opt ); ?>><?php echo esc_html( $lead_status_opt ); ?></option>
												<?php endforeach; ?>
											</select>
										</div>
										<div><label>Tier</label><input type="text" name="lead_tier" value="<?php echo esc_attr( $tier ); ?>" style="width:100%;" /></div>
										<div><label>Score</label><input type="number" name="lead_score" value="<?php echo esc_attr( $score ); ?>" style="width:100%;" /></div>
										<div><label>Quality</label><input type="text" value="<?php echo esc_attr( $quality ); ?>" style="width:100%;" readonly /></div>
										<div><label>Commercial Priority</label><input type="text" value="<?php echo esc_attr( $commercial_priority ); ?>" style="width:100%;" readonly /></div>
										<div><label>Route</label><input type="text" name="lead_route" value="<?php echo esc_attr( (string) ($lead['lead_route'] ?? '') ); ?>" style="width:100%;" /></div>
										<div style="grid-column:1/-1;"><label>Página de origen</label><input type="url" name="source_page_url" value="<?php echo esc_attr( (string) ($lead['source_page_url'] ?? '') ); ?>" style="width:100%;" placeholder="https://…" /></div>
										<div><label>Asignado a</label><input type="text" name="assigned_to" value="<?php echo esc_attr( (string) ($lead['assigned_to'] ?? '') ); ?>" style="width:100%;" /></div>
										<div><label>Canal</label><input type="text" value="<?php echo esc_attr( (string) ($lead['source_channel'] ?? '') ); ?>" style="width:100%;" readonly /></div>
									</div>
									<p style="margin-top:8px;"><label>Requirement</label><textarea name="requirement" rows="2" style="width:100%;"><?php echo esc_textarea( (string) ($lead['requirement'] ?? '') ); ?></textarea></p>
									<p style="margin-top:8px;"><label>Notas internas</label><textarea name="notes" rows="2" style="width:100%;"><?php echo esc_textarea( (string) ($lead['notes'] ?? '') ); ?></textarea></p>
									<p style="margin-top:8px;"><label>Mensaje CRM</label><textarea rows="2" style="width:100%;" readonly><?php echo esc_textarea( (string) ($lead['crm_sync_message'] ?? '') ); ?></textarea></p>
									<p style="margin:8px 0 0;"><button class="button button-primary" type="submit">Guardar cambios</button></p>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</div>

<script>
(function(){
	const input = document.getElementById('ecb-leads-search');
	const table = document.getElementById('ecb-leads-table');
	if (input && table) {
		const rows = Array.from(table.querySelectorAll('tbody tr[data-row-kind="lead"]'));
		input.addEventListener('input', function(){
			const q = input.value.trim().toLowerCase();
			rows.forEach(function(row){
				const txt = row.textContent.toLowerCase();
				const visible = !q || txt.indexOf(q) !== -1;
				row.style.display = visible ? '' : 'none';
				let next = row.nextElementSibling;
				while (next && next.getAttribute('data-row-kind') !== 'lead') {
					if (!visible) next.style.display = 'none';
					next = next.nextElementSibling;
				}
			});
		});
	}

	const selectAll = document.getElementById('ecb-leads-select-all');
	if (selectAll) {
		selectAll.addEventListener('change', function(){
			document.querySelectorAll('.ecb-lead-checkbox').forEach(function(ch){ ch.checked = selectAll.checked; });
		});
	}

	document.querySelectorAll('.ecb-toggle-lead-edit').forEach(function(btn){
		btn.addEventListener('click', function(){
			const id = btn.getAttribute('data-target');
			const row = document.getElementById(id);
			if (!row) return;
			row.style.display = row.style.display === 'none' ? '' : 'none';
		});
	});

	const openWhatsapp = new URLSearchParams(window.location.search).get('ecb_open_whatsapp');
	if (openWhatsapp) {
		try {
			window.open(decodeURIComponent(openWhatsapp), '_blank', 'noopener');
		} catch (_) {}
	}
})();
</script>
