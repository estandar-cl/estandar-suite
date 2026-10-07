<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'conversaciones' ) ) {
	wp_die( 'No autorizado.' );
}

$status = isset( $_GET['ecb_status'] ) ? sanitize_key( $_GET['ecb_status'] ) : '';
$msg    = isset( $_GET['ecb_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ecb_msg'] ) ) : '';
$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$channel_filter = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';
$status_filter = isset( $_GET['conversation_status'] ) ? sanitize_key( wp_unslash( $_GET['conversation_status'] ) ) : '';

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
if ( ! in_array( $limit, [50, 100, 200], true ) ) $limit = 100;
$paged = isset($_GET['paged']) ? max( 1, intval($_GET['paged']) ) : 1;

$filters = [
	'search' => $search,
	'channel' => $channel_filter,
	'conversation_status' => $status_filter,
];

$total = function_exists('estandar_chatbot_count_conversations_filtered')
	? estandar_chatbot_count_conversations_filtered( $filters )
	: ( function_exists('estandar_chatbot_count_conversations') ? estandar_chatbot_count_conversations() : 0 );
$total_pages = max( 1, (int) ceil( $total / $limit ) );
if ( $paged > $total_pages ) $paged = $total_pages;
$offset = ( $paged - 1 ) * $limit;

$conversations = function_exists('estandar_chatbot_get_conversations') ? estandar_chatbot_get_conversations( $limit, $offset, $filters ) : [];
$selected_id = isset($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : 0;
$selected = $selected_id > 0 && function_exists('estandar_chatbot_get_conversation_by_id')
	? estandar_chatbot_get_conversation_by_id( $selected_id )
	: null;

$ecb_channel_options = function_exists( 'estandar_chatbot_get_allowed_channels' )
	? estandar_chatbot_get_allowed_channels()
	: [ 'web', 'whatsapp', 'api' ];

$base_page_url = add_query_arg(
	[
		'page' => 'estandar-chatbot',
		'tab' => 'conversaciones',
		'limit' => $limit,
		's' => $search,
		'channel' => $channel_filter,
		'conversation_status' => $status_filter,
	],
	admin_url( 'admin.php' )
);

$download_url = wp_nonce_url(
	add_query_arg(
		[
			'action'              => 'estandar_chatbot_conversations_export',
			's'                   => $search,
			'channel'             => $channel_filter,
			'conversation_status' => $status_filter,
		],
		admin_url( 'admin-post.php' )
	),
	'estandar_chatbot_conversations_export'
);

function estandar_chatbot_admin_conversation_badge_class( $status ) {
	$status = sanitize_key( (string) $status );
	if ( in_array( $status, [ 'qualified', 'human_handoff' ], true ) ) {
		return 'ecb-badge ecb-badge--warm';
	}
	if ( $status === 'closed' || $status === 'archived' ) {
		return 'ecb-badge ecb-badge--cold';
	}
	return 'ecb-badge ecb-badge--status';
}
?>
<div>
	<h3 class="ecb-section-title">Conversaciones</h3>
	<p class="ecb-section-copy">Inbox operativo para detectar rápido quién escribió, qué necesita y a cuál conversación entrar ahora.</p>

	<?php if ( $status && $msg ) : ?>
		<div class="notice <?php echo $status === 'ok' ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html( $msg ); ?></p></div>
	<?php endif; ?>

	<div class="ecb-card-grid" style="margin:12px 0;">
		<div class="ecb-card"><p class="ecb-metric"><strong>Total filtrado:</strong> <?php echo esc_html( (string) $total ); ?></p></div>
		<div class="ecb-card"><p class="ecb-metric"><strong>Mostrando:</strong> <?php echo esc_html( (string) count( $conversations ) ); ?></p></div>
		<div class="ecb-card"><p class="ecb-metric"><strong>Canal activo:</strong> <?php echo esc_html( $channel_filter ?: 'todos' ); ?></p></div>
		<div class="ecb-card"><p class="ecb-metric"><strong>Estado activo:</strong> <?php echo esc_html( $status_filter ?: 'todos' ); ?></p></div>
	</div>

	<div class="ecb-toolbar">
		<form method="get" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
			<input type="hidden" name="page" value="estandar-chatbot" />
			<input type="hidden" name="tab" value="conversaciones" />
			<label>
				Canal:
				<select name="channel">
					<option value="">Todos</option>
					<?php foreach ( $ecb_channel_options as $ch ) : ?>
						<option value="<?php echo esc_attr( $ch ); ?>" <?php selected( $channel_filter, $ch ); ?>>
							<?php echo esc_html( function_exists( 'estandar_chatbot_get_channel_label' ) ? estandar_chatbot_get_channel_label( $ch ) : $ch ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				Estado:
				<select name="conversation_status">
					<option value="">Todos</option>
					<?php foreach ( ['open','qualified','human_handoff','closed','archived'] as $conv_status_opt ) : ?>
						<option value="<?php echo esc_attr( $conv_status_opt ); ?>" <?php selected( $status_filter, $conv_status_opt ); ?>><?php echo esc_html( $conv_status_opt ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				Mostrar:
				<select name="limit">
					<option value="50" <?php selected( $limit, 50 ); ?>>50</option>
					<option value="100" <?php selected( $limit, 100 ); ?>>100</option>
					<option value="200" <?php selected( $limit, 200 ); ?>>200</option>
				</select>
			</label>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Buscar sesión, lead o resumen..." style="min-width:240px;" />
			<button class="button">Aplicar</button>
		</form>
		<a class="button button-secondary" href="<?php echo esc_url( $download_url ); ?>">Descargar conversaciones (CSV)</a>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;" onsubmit="return confirm('¿Seguro? Esta acción es irreversible y eliminará todas las conversaciones y sus eventos asociados.');">
			<input type="hidden" name="action" value="estandar_chatbot_conversations_wipe" />
			<?php wp_nonce_field( 'estandar_chatbot_conversations_wipe' ); ?>
			<button class="button button-link-delete" type="submit">🔥 Limpiar todas las conversaciones</button>
		</form>
	</div>

	<div class="ecb-card-grid" style="grid-template-columns:minmax(380px,460px) minmax(0,1fr);align-items:start;">
		<div class="ecb-card">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ecb-conversations-bulk-form">
				<input type="hidden" name="action" value="estandar_chatbot_conversations_bulk_update" />
				<?php wp_nonce_field( 'estandar_chatbot_conversations_bulk_update' ); ?>
				<div class="ecb-toolbar" style="margin-top:0;">
					<label style="display:flex;align-items:center;gap:6px;">
						<input type="checkbox" id="ecb-conversations-select-all" />
						<span>Seleccionar todas</span>
					</label>
					<select name="bulk_conversation_status">
						<?php foreach ( ['open','qualified','human_handoff','closed','archived'] as $bulk_status ) : ?>
							<option value="<?php echo esc_attr( $bulk_status ); ?>"><?php echo esc_html( $bulk_status ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button button-secondary" type="submit" onclick="return confirm('¿Actualizar el estado de las conversaciones seleccionadas?');">Aplicar a seleccionadas</button>
					<button
						class="button button-link-delete"
						type="submit"
						formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						name="action"
						value="estandar_chatbot_conversations_bulk_delete"
						formnovalidate
						onclick="return confirm('¿Eliminar las conversaciones seleccionadas? Esta acción es irreversible.');"
					>Eliminar seleccionadas</button>
					<?php wp_nonce_field( 'estandar_chatbot_conversations_bulk_delete', 'ecb_bulk_delete_nonce' ); ?>
				</div>

				<div class="ecb-inbox-list">
					<?php if ( empty( $conversations ) ) : ?>
						<div class="notice notice-warning inline"><p>No hay conversaciones para este filtro.</p></div>
					<?php else : ?>
						<?php foreach ( $conversations as $item ) :
							$url = add_query_arg(
								[
									'page' => 'estandar-chatbot',
									'tab'  => 'conversaciones',
									'limit'=> $limit,
									's' => $search,
									'channel' => $channel_filter,
									'conversation_status' => $status_filter,
									'conversation_id' => (int) $item['id'],
								],
								admin_url( 'admin.php' )
							);
							$delete_url = wp_nonce_url(
								add_query_arg(
									[
										'action' => 'estandar_chatbot_conversation_delete',
										'conversation_id' => (int) $item['id'],
									],
									admin_url( 'admin-post.php' )
								),
								'estandar_chatbot_conversation_delete'
							);
							$is_selected = $selected && (int) $selected['id'] === (int) $item['id'];
							$title = function_exists( 'estandar_chatbot_get_conversation_display_name' )
								? estandar_chatbot_get_conversation_display_name( $item, (int) $item['id'] )
								: (string) ( $item['lead_email'] ?: $item['lead_phone'] ?: $item['session_key'] );
							$contact_state = function_exists( 'estandar_chatbot_get_conversation_contact_state' )
								? estandar_chatbot_get_conversation_contact_state( $item )
								: 'Sin datos';
							$preview = trim( (string) ( $item['summary'] ?? '' ) );
							if ( $preview === '' ) {
								$preview = 'Sin resumen aún.';
							}
							?>
							<div class="ecb-inbox-item<?php echo $is_selected ? ' is-selected' : ''; ?>">
								<div class="ecb-inbox-check">
									<input type="checkbox" name="conversation_ids[]" value="<?php echo esc_attr( (string) $item['id'] ); ?>" class="ecb-conversation-checkbox" />
								</div>
								<a href="<?php echo esc_url( $url ); ?>" style="text-decoration:none;color:inherit;">
									<div class="ecb-inbox-title">
										<strong><?php echo esc_html( $title ); ?></strong>
										<span class="<?php echo esc_attr( estandar_chatbot_admin_conversation_badge_class( $item['conversation_status'] ?? '' ) ); ?>"><?php echo esc_html( (string) $item['conversation_status'] ); ?></span>
									</div>
									<div class="ecb-inbox-subtitle"><?php echo esc_html( $preview ); ?></div>
									<div class="ecb-inbox-meta">
										<?php echo esc_html( function_exists( 'estandar_chatbot_get_channel_label' ) ? estandar_chatbot_get_channel_label( (string) $item['channel'] ) : (string) $item['channel'] ); ?>
										· <?php echo esc_html( (string) $item['message_count'] ); ?> mensajes
										· <?php echo esc_html( $contact_state ); ?>
									</div>
								</a>
								<div class="ecb-inbox-side">
									<div><?php echo esc_html( (string) $item['last_message_at'] ); ?></div>
									<div style="display:flex;gap:6px;justify-content:flex-end;margin-top:8px;">
										<a class="button button-small" href="<?php echo esc_url( $url ); ?>">Ver</a>
										<a class="button button-small button-link-delete" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('¿Eliminar esta conversación? Esta acción es irreversible.');">Borrar</a>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</form>
		</div>

		<div class="ecb-card">
			<?php if ( ! $selected ) : ?>
				<p class="description" style="margin:0;">Selecciona una conversación para revisar mensajes, resumen y estado operativo.</p>
			<?php else : ?>
				<?php
				$selected_title = function_exists( 'estandar_chatbot_get_conversation_display_name' )
					? estandar_chatbot_get_conversation_display_name( $selected, (int) $selected['id'] )
					: (string) ( $selected['lead_email'] ?: $selected['lead_phone'] ?: $selected['session_key'] );
				$selected_contact_state = function_exists( 'estandar_chatbot_get_conversation_contact_state' )
					? estandar_chatbot_get_conversation_contact_state( $selected )
					: 'Sin datos';
				?>
				<h4 style="margin-top:0;"><?php echo esc_html( $selected_title ); ?></h4>
				<p class="ecb-metric"><strong>Estado de contacto:</strong> <?php echo esc_html( $selected_contact_state ); ?></p>
				<p class="ecb-metric"><strong>Sesión:</strong> <?php echo esc_html( (string) $selected['session_key'] ); ?></p>
				<p class="ecb-metric"><strong>Canal:</strong> <?php echo esc_html( (string) $selected['channel'] ); ?></p>
				<p class="ecb-metric"><strong>Lead:</strong> <?php echo esc_html( (string) ( $selected['lead_email'] ?: $selected['lead_phone'] ?: '-' ) ); ?></p>
				<p class="ecb-metric"><strong>Mensajes:</strong> <?php echo esc_html( (string) $selected['message_count'] ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ecb-card" style="margin:12px 0 0;">
					<input type="hidden" name="action" value="estandar_chatbot_conversation_update" />
					<input type="hidden" name="conversation_id" value="<?php echo esc_attr( (string) $selected['id'] ); ?>" />
					<?php wp_nonce_field( 'estandar_chatbot_conversation_update' ); ?>
					<p><label>Estado</label>
						<select name="conversation_status" style="width:100%;">
							<?php foreach ( ['open','qualified','human_handoff','closed','archived'] as $conv_status ) : ?>
								<option value="<?php echo esc_attr( $conv_status ); ?>" <?php selected( (string) $selected['conversation_status'], $conv_status ); ?>><?php echo esc_html( $conv_status ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p><label>Resumen operador</label>
						<textarea name="summary" rows="3" style="width:100%;"><?php echo esc_textarea( (string) $selected['summary'] ); ?></textarea>
					</p>
					<p style="margin-bottom:0;display:flex;gap:8px;align-items:center;">
						<button class="button button-primary" type="submit">Guardar</button>
					</p>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;" onsubmit="return confirm('¿Eliminar esta conversación? Esta acción es irreversible.');">
					<input type="hidden" name="action" value="estandar_chatbot_conversation_delete" />
					<input type="hidden" name="conversation_id" value="<?php echo esc_attr( (string) $selected['id'] ); ?>" />
					<?php wp_nonce_field( 'estandar_chatbot_conversation_delete' ); ?>
					<button class="button button-link-delete" type="submit">🗑 Borrar conversación</button>
				</form>

				<div class="ecb-card" style="margin-top:12px;background:#f8f9fb;">
					<h4 style="margin-top:0;">Mensajes</h4>
					<div style="display:flex;flex-direction:column;gap:10px;max-height:560px;overflow:auto;">
						<?php foreach ( (array) $selected['messages'] as $message ) :
							$role = sanitize_key( (string) ( $message['role'] ?? '' ) );
							$bg = $role === 'assistant' ? '#ffffff' : '#111827';
							$color = $role === 'assistant' ? '#111827' : '#ffffff';
						?>
							<div style="align-self:<?php echo $role === 'assistant' ? 'flex-start' : 'flex-end'; ?>;max-width:86%;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $color ); ?>;border-radius:14px;padding:10px 12px;border:1px solid rgba(15,23,42,.08);">
								<div style="font-size:11px;opacity:.7;margin-bottom:4px;"><?php echo esc_html( strtoupper( $role ) ); ?> · <?php echo esc_html( (string) ( $message['at'] ?? '' ) ); ?></div>
								<div><?php echo nl2br( esc_html( (string) ( $message['content'] ?? '' ) ) ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="ecb-toolbar">
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( $base_page_url . '&paged=' . ( $paged - 1 ) ); ?>">Anterior</a>
			<?php endif; ?>
			<span>Página <?php echo esc_html( (string) $paged ); ?> de <?php echo esc_html( (string) $total_pages ); ?></span>
			<?php if ( $paged < $total_pages ) : ?>
				<a class="button" href="<?php echo esc_url( $base_page_url . '&paged=' . ( $paged + 1 ) ); ?>">Siguiente</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
<script>
(function(){
	var selectAll = document.getElementById('ecb-conversations-select-all');
	if (!selectAll) return;
	selectAll.addEventListener('change', function(){
		document.querySelectorAll('.ecb-conversation-checkbox').forEach(function(checkbox){
			checkbox.checked = !!selectAll.checked;
		});
	});
})();
</script>
