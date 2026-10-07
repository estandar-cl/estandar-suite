<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'leads-captacion' ) ) {
	wp_die( esc_html__( 'No autorizado.', 'estandar-chatbot' ) );
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$lead_capture_enabled     = (int) get_option( 'chatbot_lead_capture_enabled', 1 );
$auto_open_enabled        = (int) get_option( 'chatbot_auto_open_enabled', 0 );
$auto_open_type           = get_option( 'chatbot_auto_open_type', 'time' );
$auto_open_value          = (int) get_option( 'chatbot_auto_open_value', 6 );
$hide_menu_on_click       = (int) get_option( 'chatbot_hide_menu_on_click', 1 );
$human_escalation_enabled = (int) get_option( 'chatbot_human_escalation_enabled', 1 );
$human_escalation_text    = get_option( 'chatbot_human_escalation_text', __( 'Si prefieres, te puedo derivar ahora mismo con una persona del equipo.', 'estandar-chatbot' ) );
$human_escalation_mode    = get_option( 'chatbot_human_escalation_mode', 'lead_form' );
$human_whatsapp_number    = get_option( 'chatbot_human_whatsapp_number', '' );
$human_whatsapp_message   = get_option( 'chatbot_human_whatsapp_message', __( 'Hola, quiero hablar con una persona del equipo. Dejé mis datos en la web.', 'estandar-chatbot' ) );
$post_lead_message        = get_option( 'chatbot_post_lead_message', __( "Perfecto 👌 ya tengo tus datos.\n\nSi quieres avanzar ahora mismo, podemos verlo por WhatsApp.", 'estandar-chatbot' ) );
// Business profile fields moved to IA tab (ia-contexto.php) — 2026-06 Fase A1
$business_industry        = (string) get_option( 'chatbot_business_industry', 'servicios' );
$business_goal            = (string) get_option( 'chatbot_business_goal', 'captar contactos' );
$business_primary_cta     = (string) get_option( 'chatbot_business_primary_cta', 'Quiero avanzar' );
$business_sensitive_topics= (string) get_option( 'chatbot_business_sensitive_topics', 'precio, plazos, disponibilidad' );
$business_tone            = (string) get_option( 'chatbot_business_tone', 'claro y profesional' );
$enable_website_demo      = (int) get_option( 'chatbot_enable_website_demo', 0 );
$requires_disambiguation  = (int) get_option( 'chatbot_requires_disambiguation', 0 );
$quick_actions            = estandar_chatbot_get_option_array( 'chatbot_botones_iniciales', [] );
$quick_actions_total      = is_array( $quick_actions ) ? count( $quick_actions ) : 0;
$post_lead_actions        = get_option( 'chatbot_post_lead_actions', [
	[ 'action' => 'whatsapp', 'label' => __( 'Hablar por WhatsApp', 'estandar-chatbot' ), 'enabled' => 1 ],
	[ 'action' => 'deferred', 'label' => __( 'Prefiero que me contacten', 'estandar-chatbot' ), 'enabled' => 1 ],
	[ 'action' => 'reset', 'label' => __( 'Volver al inicio', 'estandar-chatbot' ), 'enabled' => 1 ],
] );
$cta_labels              = get_option( 'chatbot_cta_labels', [
	'default'   => __( 'Ver más', 'estandar-chatbot' ),
	'pagar'     => __( 'Pagar ahora', 'estandar-chatbot' ),
	'precio'    => __( 'Ver precios', 'estandar-chatbot' ),
	'cotizar'   => __( 'Cotizar ahora', 'estandar-chatbot' ),
	'contratar' => __( 'Contratar ahora', 'estandar-chatbot' ),
] );
$responsables            = get_option( 'chatbot_responsables', [] );
$project_registry        = function_exists( 'estandar_chatbot_get_project_registry' ) ? estandar_chatbot_get_project_registry() : (array) get_option( 'chatbot_project_registry', [] );
$source_intent_rules      = get_option( 'chatbot_source_intent_rules', [] );
$text_intent_rules        = get_option( 'chatbot_text_intent_rules', [
	[ 'keyword' => 'cotizar', 'intent' => 'ventas', 'area' => '' ],
	[ 'keyword' => 'precio', 'intent' => 'ventas', 'area' => '' ],
	[ 'keyword' => 'informacion', 'intent' => 'info', 'area' => '' ],
	[ 'keyword' => 'contacto', 'intent' => 'humano', 'area' => '' ],
] );
$lead_intro_text          = get_option( 'chatbot_lead_intro_text', __( 'Te ayudo rápidamente. Primero dime qué necesitas para derivarte con el equipo correcto.', 'estandar-chatbot' ) );
$lead_intents             = get_option( 'chatbot_lead_intents', [
	[ 'id' => 'ventas', 'label' => __( 'Quiero cotizar', 'estandar-chatbot' ) ],
	[ 'id' => 'soporte', 'label' => __( 'Necesito soporte', 'estandar-chatbot' ) ],
	[ 'id' => 'info', 'label' => __( 'Quiero más información', 'estandar-chatbot' ) ],
] );
$lead_hot_threshold       = (int) get_option( 'chatbot_lead_hot_threshold', 80 );
$lead_warm_threshold      = (int) get_option( 'chatbot_lead_warm_threshold', 50 );
$lead_fields              = get_option( 'chatbot_lead_fields', [] );
$lead_registered_message  = (string) get_option( 'chatbot_mensaje_ya_registrado', '' );
$behavior_copy            = get_option( 'chatbot_behavior_copy', estandar_chatbot_get_behavior_copy_defaults() );
$lead_intro_fallback      = __( 'Hola 👋 ¿Qué necesitas hoy? Elige una opción y te ayudo a avanzar rápido.', 'estandar-chatbot' );

if ( '' === trim( $lead_intro_text ) ) {
	$lead_intro_text = $lead_intro_fallback;
}

if ( empty( $lead_fields ) ) {
	$lead_fields = [
		[ 'key' => 'name', 'label' => 'Nombre', 'required' => true ],
		[ 'key' => 'phone', 'label' => 'Teléfono', 'required' => true ],
	];
}

$has_enabled_post_action = false;
foreach ( (array) $post_lead_actions as $action ) {
	if ( ! empty( $action['enabled'] ) && '' !== trim( (string) ( $action['label'] ?? '' ) ) ) {
		$has_enabled_post_action = true;
		break;
	}
}
if ( ! $has_enabled_post_action && ! empty( $post_lead_actions ) ) {
	foreach ( $post_lead_actions as &$action ) {
		if ( ( $action['action'] ?? '' ) === 'whatsapp' ) {
			$action['enabled'] = 1;
			$has_enabled_post_action = true;
			break;
		}
	}
	unset( $action );
}

$preview_intents = array_values(
	array_filter(
		array_map(
			function( $item ) {
				return trim( (string) ( $item['label'] ?? '' ) );
			},
			is_array( $lead_intents ) ? $lead_intents : []
		)
	)
);

$preview_fields = array_values(
	array_filter(
		array_map(
			function( $item ) {
				return trim( (string) ( $item['label'] ?? '' ) );
			},
			is_array( $lead_fields ) ? $lead_fields : []
		)
	)
);
$field_count = count( $preview_fields );
$friction_label = $field_count <= 2
	? __( 'Fricción baja', 'estandar-chatbot' )
	: ( $field_count <= 4 ? __( 'Fricción media', 'estandar-chatbot' ) : __( 'Fricción alta', 'estandar-chatbot' ) );
$advanced_rules_count = count( (array) $source_intent_rules ) + count( (array) $text_intent_rules );

// Summary cards
$summary_items = [
	[
		'label' => __( 'Opciones iniciales', 'estandar-chatbot' ),
		'value' => count( $preview_intents ) >= 2
			? sprintf( _n( '%d opción', '%d opciones', count( $preview_intents ), 'estandar-chatbot' ), count( $preview_intents ) )
			: __( 'Sin definir', 'estandar-chatbot' ),
	],
	[
		'label' => __( 'Campos de lead', 'estandar-chatbot' ),
		'value' => ! empty( $preview_fields )
			? implode( ', ', $preview_fields ) . ' · ' . $friction_label
			: __( 'Sin definir', 'estandar-chatbot' ),
	],
	[
		'label' => __( 'Canal de cierre', 'estandar-chatbot' ),
		'value' => 'whatsapp' === $human_escalation_mode
			? __( 'WhatsApp directo', 'estandar-chatbot' )
			: __( 'Formulario dentro del chat', 'estandar-chatbot' ),
	],
	[
		'label' => __( 'Rubro', 'estandar-chatbot' ),
		'value' => trim( $business_industry ) ?: __( 'No definido', 'estandar-chatbot' ),
	],
];
?>
<form method="post" action="options.php">
	<?php settings_fields( 'estandar_chatbot_settings' ); ?>

	<?php
	$diagnostic    = isset( $_GET['diagnostic'] ) ? sanitize_key( wp_unslash( (string) $_GET['diagnostic'] ) ) : '';
	$focus_project = isset( $_GET['focus_project'] ) ? sanitize_key( wp_unslash( (string) $_GET['focus_project'] ) ) : '';
	if ( 'missing_url_patterns' === $diagnostic ) :
		$raw_projects = isset( $_GET['projects'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['projects'] ) ) : '';
		$projects     = array_values( array_unique( array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', $raw_projects ) ) ) ) ) );
		?>
			<div class="notice notice-warning">
				<p><strong><?php esc_html_e( 'Está corrigiendo una incidencia detectada por Estado.', 'estandar-chatbot' ); ?></strong><br>
				<?php esc_html_e( 'Proyecto sin rutas web configuradas.', 'estandar-chatbot' ); ?></p>
			<?php if ( ! empty( $projects ) ) : ?>
				<p><strong><?php esc_html_e( 'Proyecto:', 'estandar-chatbot' ); ?></strong></p>
				<ul style="margin-left:18px;list-style:disc;">
					<?php foreach ( $projects as $project ) : ?>
						<li><?php echo esc_html( $project ); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<p><strong><?php esc_html_e( 'WARNING condicional:', 'estandar-chatbot' ); ?></strong><br>
				<?php esc_html_e( 'Configure rutas web solo si este proyecto requiere atribución o resolución de proyecto basada en URL. Forms y Leads no dependen de rutas web cuando reciben project_slug explícito.', 'estandar-chatbot' ); ?></p>
			</div>
		<?php endif; ?>

	<div class="ecb-leads-toolbar">
		<div>
			<h2 style="margin:0 0 6px;"><?php esc_html_e( 'Captura de datos', 'estandar-chatbot' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Define la conversación inicial, los datos que pides y el cierre. Configura solo lo que necesitas hoy.', 'estandar-chatbot' ); ?>
			</p>
		</div>
		<button type="button" class="button button-secondary" id="ecb-apply-recommended">
			<?php echo esc_html( sprintf( __( 'Optimizar para captación %s', 'estandar-chatbot' ), trim( $business_industry ) ?: __( 'de leads', 'estandar-chatbot' ) ) ); ?>
		</button>
	</div>

	<?php // ── Confirmación de configuración recomendada (oculta hasta el click) ── ?>
	<div id="ecb-recommended-confirm-panel" style="display:none;margin-bottom:16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:16px 18px;">
		<p style="font-weight:600;margin:0 0 12px;"><?php esc_html_e( 'La configuración recomendada cambiará lo siguiente:', 'estandar-chatbot' ); ?></p>
		<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
			<div style="flex:1 1 220px;padding:10px 14px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;">
				<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#1d4ed8;margin-bottom:6px;"><?php esc_html_e( 'Aplicará', 'estandar-chatbot' ); ?></div>
				<div style="font-size:13px;color:#1e3a8a;line-height:1.7;">
					✓ <?php esc_html_e( 'Mensaje de bienvenida', 'estandar-chatbot' ); ?><br>
					✓ <?php esc_html_e( 'Opciones iniciales (3 recomendadas)', 'estandar-chatbot' ); ?><br>
					✓ <?php esc_html_e( 'Campos capturados (Nombre + Teléfono)', 'estandar-chatbot' ); ?><br>
					✓ <?php esc_html_e( 'Mensaje de cierre y derivación', 'estandar-chatbot' ); ?><br>
					✓ <?php esc_html_e( 'Umbrales internos de prioridad', 'estandar-chatbot' ); ?>
				</div>
			</div>
			<div style="flex:1 1 220px;padding:10px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
				<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:6px;"><?php esc_html_e( 'No modificará', 'estandar-chatbot' ); ?></div>
				<div style="font-size:13px;color:#374151;line-height:1.7;">
					✓ <?php esc_html_e( 'Leads ya capturados', 'estandar-chatbot' ); ?><br>
					✓ <?php esc_html_e( 'Conversaciones e historial', 'estandar-chatbot' ); ?><br>
					✓ <?php esc_html_e( 'Estadísticas y analítica', 'estandar-chatbot' ); ?><br>
					✓ <?php esc_html_e( 'Responsables y proyectos', 'estandar-chatbot' ); ?>
				</div>
			</div>
		</div>
		<p class="description" style="margin:0 0 12px;"><?php esc_html_e( 'Los valores se cargan en el formulario para que los revises. Nada se guarda hasta presionar "Guardar captación".', 'estandar-chatbot' ); ?></p>
		<button type="button" class="button button-primary" id="ecb-recommended-confirm"><?php esc_html_e( 'Cargar configuración recomendada', 'estandar-chatbot' ); ?></button>
		<button type="button" class="button" id="ecb-recommended-cancel"><?php esc_html_e( 'Cancelar', 'estandar-chatbot' ); ?></button>
	</div>

	<div class="ecb-leads-layout">
		<div class="ecb-leads-main">

			<?php // ── Resumen rápido ──────────────────────────────────────────── ?>
			<section class="ecb-admin-surface ecb-leads-section">
				<h3><?php esc_html_e( 'Resumen rápido', 'estandar-chatbot' ); ?></h3>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
					<?php foreach ( $summary_items as $item ) : ?>
						<div style="padding:14px 16px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;">
							<div style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><?php echo esc_html( $item['label'] ); ?></div>
							<div style="margin-top:6px;font-weight:600;color:#111827;"><?php echo esc_html( $item['value'] ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

			<?php // ── SECCIÓN A: Conversación ──────────────────────────────────── ?>
			<section class="ecb-admin-surface ecb-leads-section">
				<h3><?php esc_html_e( 'Conversación', 'estandar-chatbot' ); ?></h3>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e( 'Define qué ve el usuario al abrir el chat, las opciones que puede elegir y los datos básicos que pedirás.', 'estandar-chatbot' ); ?>
				</p>
				<table class="form-table" role="presentation">
				<tbody>
				<tr>
					<th scope="row">
						<span class="ecb-field-label">
							<?php esc_html_e( 'Captación de leads', 'estandar-chatbot' ); ?>
							<span class="ecb-help-tip" title="<?php esc_attr_e( 'Define si el usuario pasa por un flujo guiado o entra directo al chat libre.', 'estandar-chatbot' ); ?>">?</span>
						</span>
					</th>
					<td>
						<label>
							<input type="hidden" name="chatbot_lead_capture_enabled" value="0" />
							<input type="checkbox" name="chatbot_lead_capture_enabled" value="1" <?php checked( $lead_capture_enabled, 1 ); ?> />
							<?php esc_html_e( 'Activar flujo guiado de captación antes de conversación abierta', 'estandar-chatbot' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Recomendado: Activado. Muestra opciones al usuario y aumenta conversiones.', 'estandar-chatbot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="ecb-field-label">
							<?php esc_html_e( 'Mensaje de bienvenida', 'estandar-chatbot' ); ?>
							<span class="ecb-help-tip" title="<?php esc_attr_e( 'Primer mensaje que ve el usuario. Debe ser claro e invitar a elegir.', 'estandar-chatbot' ); ?>">?</span>
						</span>
					</th>
					<td>
						<input type="text" name="chatbot_lead_intro_text" value="<?php echo esc_attr( $lead_intro_text ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $lead_intro_fallback ); ?>" />
						<p class="description"><strong><?php esc_html_e( 'Ejemplo:', 'estandar-chatbot' ); ?></strong> <?php esc_html_e( 'Hola 👋 ¿Qué necesitas hoy? Elige una opción y te ayudo.', 'estandar-chatbot' ); ?></p>
					</td>
				</tr>
				</tbody>
				</table>

				<?php // ── Opciones iniciales ────────────────────────────────────── ?>
				<h4 style="margin:20px 0 4px;"><?php esc_html_e( 'Opciones iniciales', 'estandar-chatbot' ); ?></h4>
				<p class="description" style="margin:0 0 10px;"><?php esc_html_e( 'Usa 2 o 3 opciones claras. Menos opciones genera más interacción. Pon primero la más frecuente.', 'estandar-chatbot' ); ?></p>
				<style>
					#ecb-intents-builder:not(.ecb-show-tech) .ecb-intent-advanced { display:none; }
				</style>
				<input type="hidden" name="chatbot_lead_intents__present" value="1" />
				<div id="ecb-intents-builder" class="ecb-intents-builder">
					<?php foreach ( $lead_intents as $idx => $intent ) : ?>
						<div class="ecb-intent-row">
							<input type="text" name="chatbot_lead_intents[<?php echo esc_attr( (string) $idx ); ?>][label]" value="<?php echo esc_attr( $intent['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Texto visible del botón', 'estandar-chatbot' ); ?>" class="regular-text ecb-intent-label" />
							<div class="ecb-intent-controls">
								<button type="button" class="button ecb-move-intent-up" title="<?php esc_attr_e( 'Subir opción', 'estandar-chatbot' ); ?>" aria-label="<?php esc_attr_e( 'Subir opción', 'estandar-chatbot' ); ?>">↑</button>
								<button type="button" class="button ecb-move-intent-down" title="<?php esc_attr_e( 'Bajar opción', 'estandar-chatbot' ); ?>" aria-label="<?php esc_attr_e( 'Bajar opción', 'estandar-chatbot' ); ?>">↓</button>
								<button type="button" class="button-link-delete ecb-remove-intent" title="<?php esc_attr_e( 'Eliminar opción', 'estandar-chatbot' ); ?>" aria-label="<?php esc_attr_e( 'Eliminar opción', 'estandar-chatbot' ); ?>">×</button>
							</div>
							<details class="ecb-intent-advanced">
								<summary><?php esc_html_e( 'Opciones avanzadas (para desarrolladores)', 'estandar-chatbot' ); ?></summary>
								<div class="ecb-intent-id-wrap">
									<input type="text" name="chatbot_lead_intents[<?php echo esc_attr( (string) $idx ); ?>][id]" value="<?php echo esc_attr( $intent['id'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'id interno', 'estandar-chatbot' ); ?>" class="regular-text ecb-intent-id" style="max-width:220px;" />
									<p class="description"><?php esc_html_e( 'Solo cambia este ID si integras automatizaciones o scoring personalizado.', 'estandar-chatbot' ); ?></p>
								</div>
							</details>
						</div>
					<?php endforeach; ?>
				</div>
				<p style="margin-top:8px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
					<button type="button" class="button" id="ecb-add-intent">+ <?php esc_html_e( 'Agregar opción', 'estandar-chatbot' ); ?></button>
					<label style="font-size:12px;color:#6b7280;display:flex;align-items:center;gap:5px;cursor:pointer;">
						<input type="checkbox" id="ecb-show-tech-toggle" />
						<?php esc_html_e( 'Mostrar configuración técnica (IDs internos)', 'estandar-chatbot' ); ?>
					</label>
				</p>
				<script>
				(function(){
					var toggle = document.getElementById('ecb-show-tech-toggle');
					var target = document.getElementById('ecb-intents-builder');
					if (!toggle || !target) return;
					toggle.addEventListener('change', function(){
						target.classList.toggle('ecb-show-tech', toggle.checked);
					});
				})();
				</script>

				<?php // ── Datos a capturar — visual builder ────────────────────── ?>
				<h4 style="margin:20px 0 4px;"><?php esc_html_e( 'Datos a capturar', 'estandar-chatbot' ); ?></h4>
				<p class="description" style="margin:0 0 10px;"><?php esc_html_e( 'Menos datos = más completaciones. Recomendado: Nombre + un dato de contacto.', 'estandar-chatbot' ); ?></p>
				<div id="ecb-fields-builder" class="ecb-intents-builder">
					<?php foreach ( $lead_fields as $field ) :
						$fkey = sanitize_key( (string) ( $field['key'] ?? '' ) );
						$flbl = (string) ( $field['label'] ?? '' );
						$freq = ! empty( $field['required'] );
					?>
					<div class="ecb-field-row" data-fkey="<?php echo esc_attr( $fkey ); ?>" data-fkey-locked="1" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
						<input type="text" class="ecb-field-label regular-text" value="<?php echo esc_attr( $flbl ); ?>" placeholder="<?php esc_attr_e( 'Nombre del campo', 'estandar-chatbot' ); ?>" />
						<label style="white-space:nowrap;display:flex;align-items:center;gap:4px;">
							<input type="checkbox" class="ecb-field-required" <?php checked( $freq ); ?> />
							<?php esc_html_e( 'Requerido', 'estandar-chatbot' ); ?>
						</label>
						<code class="ecb-field-key-badge" style="font-size:11px;background:#f0f0f0;border-radius:3px;padding:3px 7px;color:#555;"><?php echo esc_html( $fkey ); ?></code>
						<button type="button" class="button-link-delete ecb-remove-field" title="<?php esc_attr_e( 'Quitar campo', 'estandar-chatbot' ); ?>" aria-label="<?php esc_attr_e( 'Quitar campo', 'estandar-chatbot' ); ?>">×</button>
					</div>
					<?php endforeach; ?>
				</div>
				<p style="margin-top:8px;">
					<button type="button" class="button" id="ecb-add-field">+ <?php esc_html_e( 'Agregar campo', 'estandar-chatbot' ); ?></button>
				</p>
				<textarea id="chatbot_lead_fields" name="chatbot_lead_fields" style="display:none;" class="large-text code"><?php
					echo esc_textarea( wp_json_encode( $lead_fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
				?></textarea>
			</section>

			<?php // ── SECCIÓN B: Conversión ────────────────────────────────────── ?>
			<section class="ecb-admin-surface ecb-leads-section">
				<h3><?php esc_html_e( 'Conversión', 'estandar-chatbot' ); ?></h3>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e( 'Define cómo el usuario puede contactar al equipo y qué mensaje ve después de dejar sus datos.', 'estandar-chatbot' ); ?>
				</p>
				<table class="form-table" role="presentation">
				<tbody>
				<tr>
					<th scope="row">
						<span class="ecb-field-label">
							<?php esc_html_e( 'Derivación a persona', 'estandar-chatbot' ); ?>
							<span class="ecb-help-tip" title="<?php esc_attr_e( 'Permite que el usuario pida atención humana.', 'estandar-chatbot' ); ?>">?</span>
						</span>
					</th>
					<td>
						<label>
							<input type="hidden" name="chatbot_human_escalation_enabled" value="0" />
							<input type="checkbox" id="chatbot_human_escalation_enabled" name="chatbot_human_escalation_enabled" value="1" <?php checked( $human_escalation_enabled, 1 ); ?> />
							<?php esc_html_e( 'Permitir que el usuario solicite atención de una persona', 'estandar-chatbot' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Actívalo para ofrecer cierre asistido cuando el usuario lo pida.', 'estandar-chatbot' ); ?></p>
					</td>
				</tr>
				<tr id="ecb-row-human-text">
					<th scope="row">
						<span class="ecb-field-label"><?php esc_html_e( 'Mensaje al derivar', 'estandar-chatbot' ); ?></span>
					</th>
					<td>
						<input type="text" name="chatbot_human_escalation_text" value="<?php echo esc_attr( $human_escalation_text ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Si prefieres, te puedo derivar ahora mismo con una persona del equipo.', 'estandar-chatbot' ); ?>" />
					</td>
				</tr>
				<tr id="ecb-row-human-mode">
					<th scope="row">
						<span class="ecb-field-label">
							<?php esc_html_e( 'Canal de cierre', 'estandar-chatbot' ); ?>
							<span class="ecb-help-tip" title="<?php esc_attr_e( 'Define si al hacer click se abre WhatsApp o el formulario de contacto dentro del chat.', 'estandar-chatbot' ); ?>">?</span>
						</span>
					</th>
					<td>
						<select id="chatbot_human_escalation_mode" name="chatbot_human_escalation_mode">
							<option value="lead_form" <?php selected( $human_escalation_mode, 'lead_form' ); ?>><?php esc_html_e( 'Formulario dentro del chat', 'estandar-chatbot' ); ?></option>
							<option value="whatsapp" <?php selected( $human_escalation_mode, 'whatsapp' ); ?>><?php esc_html_e( 'WhatsApp directo', 'estandar-chatbot' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Recomendado: WhatsApp directo para leads calientes.', 'estandar-chatbot' ); ?></p>
					</td>
				</tr>
				<tr id="ecb-row-human-whatsapp-number">
					<th scope="row">
						<span class="ecb-field-label">
							<?php esc_html_e( 'Número de WhatsApp', 'estandar-chatbot' ); ?>
							<span class="ecb-help-tip" title="<?php esc_attr_e( 'Formato internacional sin + ni espacios. Ejemplo: 56912345678.', 'estandar-chatbot' ); ?>">?</span>
						</span>
					</th>
					<td>
						<input type="text" name="chatbot_human_whatsapp_number" value="<?php echo esc_attr( $human_whatsapp_number ); ?>" class="regular-text" placeholder="56912345678" inputmode="numeric" />
						<p class="description"><?php esc_html_e( 'Sin +, sin espacios. Ej: 56912345678', 'estandar-chatbot' ); ?></p>
					</td>
				</tr>
				<tr id="ecb-row-human-whatsapp-message">
					<th scope="row">
						<span class="ecb-field-label">
							<?php esc_html_e( 'Mensaje inicial de WhatsApp', 'estandar-chatbot' ); ?>
							<span class="ecb-help-tip" title="<?php esc_attr_e( 'Puedes usar {{name}} y {{phone}} para completar con datos del lead.', 'estandar-chatbot' ); ?>">?</span>
						</span>
					</th>
					<td>
						<textarea name="chatbot_human_whatsapp_message" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Hola, quiero hablar con una persona del equipo. Dejé mis datos en la web.', 'estandar-chatbot' ); ?>"><?php echo esc_textarea( $human_whatsapp_message ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Puedes usar {{name}} para personalizar con el nombre del usuario.', 'estandar-chatbot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="ecb-field-label">
							<?php esc_html_e( 'Mensaje después de capturar lead', 'estandar-chatbot' ); ?>
							<span class="ecb-help-tip" title="<?php esc_attr_e( 'Mensaje que ve el usuario justo después de dejar sus datos.', 'estandar-chatbot' ); ?>">?</span>
						</span>
					</th>
					<td>
						<textarea name="chatbot_post_lead_message" rows="3" class="large-text"><?php echo esc_textarea( $post_lead_message ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Recomendado: confirmar la captura y dejar claro cuál es el siguiente paso.', 'estandar-chatbot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<span class="ecb-field-label">
							<?php esc_html_e( 'Acciones después de capturar lead', 'estandar-chatbot' ); ?>
							<span class="ecb-help-tip" title="<?php esc_attr_e( 'Opciones visibles para el usuario después de dejar sus datos.', 'estandar-chatbot' ); ?>">?</span>
						</span>
					</th>
					<td>
						<p class="description"><?php esc_html_e( 'Activa las acciones que quieres ofrecer. Debe haber al menos una activa.', 'estandar-chatbot' ); ?></p>
						<?php foreach ( $post_lead_actions as $idx => $action ) :
							$action_key = (string) ( $action['action'] ?? '' );
						?>
							<div style="display:flex;align-items:center;gap:10px;margin:8px 0;">
								<input type="hidden" name="chatbot_post_lead_actions[<?php echo esc_attr( (string) $idx ); ?>][action]" value="<?php echo esc_attr( $action_key ); ?>" />
								<label style="display:flex;align-items:center;gap:6px;min-width:170px;">
									<input type="hidden" name="chatbot_post_lead_actions[<?php echo esc_attr( (string) $idx ); ?>][enabled]" value="0" />
									<input type="checkbox" name="chatbot_post_lead_actions[<?php echo esc_attr( (string) $idx ); ?>][enabled]" value="1" <?php checked( ! empty( $action['enabled'] ), true ); ?> />
									<span>
										<?php
										if ( 'whatsapp' === $action_key ) {
											esc_html_e( 'WhatsApp', 'estandar-chatbot' );
										} elseif ( 'deferred' === $action_key ) {
											esc_html_e( 'Contacto diferido', 'estandar-chatbot' );
										} else {
											esc_html_e( 'Volver al inicio', 'estandar-chatbot' );
										}
										?>
									</span>
								</label>
								<input type="text" name="chatbot_post_lead_actions[<?php echo esc_attr( (string) $idx ); ?>][label]" value="<?php echo esc_attr( (string) ( $action['label'] ?? '' ) ); ?>" class="regular-text" />
							</div>
						<?php endforeach; ?>
					</td>
				</tr>
				</tbody>
				</table>
			</section>

			<?php // ── SECCIÓN C: Avanzado ──────────────────────────────────────── ?>
			<details class="ecb-admin-surface ecb-leads-section ecb-advanced-details">
				<summary style="cursor:pointer;font-weight:600;display:flex;align-items:center;gap:10px;padding:4px 0;">
					<?php esc_html_e( 'Motor conversacional (avanzado)', 'estandar-chatbot' ); ?>
					<span class="ecb-stage-status__pill"><?php echo esc_html( sprintf( _n( '%d regla', '%d reglas', $advanced_rules_count, 'estandar-chatbot' ), $advanced_rules_count ) ); ?></span>
					<span style="font-size:11px;color:#9ca3af;font-weight:400;"><?php esc_html_e( 'comportamiento, reglas, scoring, mensajes técnicos — no se necesita para la operación diaria', 'estandar-chatbot' ); ?></span>
				</summary>
				<p class="description" style="margin-top:12px;"><?php esc_html_e( 'Opciones para ajustes técnicos y de comportamiento. La configuración principal arriba es suficiente para la mayoría de los casos.', 'estandar-chatbot' ); ?></p>

				<?php // ── Capacidades del flujo ─────────────────────────────────── ?>
				<section style="margin-top:16px;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Capacidades del flujo', 'estandar-chatbot' ); ?></h4>
					<table class="form-table" role="presentation">
					<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Comportamiento', 'estandar-chatbot' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:8px;">
								<input type="hidden" name="chatbot_requires_disambiguation" value="0" />
								<input type="checkbox" name="chatbot_requires_disambiguation" value="1" <?php checked( $requires_disambiguation, 1 ); ?> />
								<?php esc_html_e( 'Requiere aclarar proyecto, producto o servicio antes de cerrar', 'estandar-chatbot' ); ?>
							</label>
							<label style="display:block;">
								<input type="hidden" name="chatbot_enable_website_demo" value="0" />
								<input type="checkbox" name="chatbot_enable_website_demo" value="1" <?php checked( $enable_website_demo, 1 ); ?> />
								<?php esc_html_e( 'Activar demo cuando el usuario comparte una web', 'estandar-chatbot' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Activa "aclarar proyecto" en negocios con varias ofertas. Activa la demo solo en landings de demostración.', 'estandar-chatbot' ); ?></p>
						</td>
					</tr>
					</tbody>
					</table>
				</section>

				<?php // ── Comportamiento visual ─────────────────────────────────── ?>
				<section style="margin-top:16px;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Comportamiento visual', 'estandar-chatbot' ); ?></h4>
					<table class="form-table" role="presentation">
					<tbody>
					<tr>
						<th scope="row">
							<span class="ecb-field-label"><?php esc_html_e( 'Ocultar menú al hacer click', 'estandar-chatbot' ); ?></span>
						</th>
						<td>
							<label>
								<input type="hidden" name="chatbot_hide_menu_on_click" value="0" />
								<input type="checkbox" name="chatbot_hide_menu_on_click" value="1" <?php checked( $hide_menu_on_click, 1 ); ?> />
								<?php esc_html_e( 'Ocultar las opciones iniciales después de elegir una', 'estandar-chatbot' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Recomendado: Activado.', 'estandar-chatbot' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<span class="ecb-field-label">
								<?php esc_html_e( 'Apertura automática', 'estandar-chatbot' ); ?>
								<span class="ecb-help-tip" title="<?php esc_attr_e( 'Abre el chatbot automáticamente según tiempo en página o scroll.', 'estandar-chatbot' ); ?>">?</span>
							</span>
						</th>
						<td>
							<label>
								<input type="hidden" name="chatbot_auto_open_enabled" value="0" />
								<input type="checkbox" id="chatbot_auto_open_enabled" name="chatbot_auto_open_enabled" value="1" <?php checked( $auto_open_enabled, 1 ); ?> />
								<?php esc_html_e( 'Activar apertura automática', 'estandar-chatbot' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Recomendado: desactivado hasta probar timing real en producción.', 'estandar-chatbot' ); ?></p>
							<div id="ecb-auto-open-settings" class="ecb-inline-stack" style="margin-top:10px;">
								<div class="ecb-inline-stack">
									<strong><?php esc_html_e( 'Tipo de trigger', 'estandar-chatbot' ); ?></strong>
									<label><input type="radio" name="chatbot_auto_open_type" value="time" <?php checked( $auto_open_type, 'time' ); ?> /> <?php esc_html_e( 'Tiempo en página', 'estandar-chatbot' ); ?></label>
									<label><input type="radio" name="chatbot_auto_open_type" value="scroll" <?php checked( $auto_open_type, 'scroll' ); ?> /> <?php esc_html_e( 'Scroll', 'estandar-chatbot' ); ?></label>
								</div>
								<div class="ecb-inline-stack">
									<label for="chatbot_auto_open_value"><strong id="ecb-auto-open-value-label"><?php echo 'scroll' === $auto_open_type ? esc_html__( 'Porcentaje de scroll', 'estandar-chatbot' ) : esc_html__( 'Segundos en página', 'estandar-chatbot' ); ?></strong></label>
									<input type="number" id="chatbot_auto_open_value" name="chatbot_auto_open_value" value="<?php echo esc_attr( (string) $auto_open_value ); ?>" class="small-text" min="1" max="100" step="1" />
									<p class="description" id="ecb-auto-open-description">
										<?php echo 'scroll' === $auto_open_type
											? esc_html__( 'Recomendado: 30% a 40%.', 'estandar-chatbot' )
											: esc_html__( 'Recomendado: entre 5 y 8 segundos.', 'estandar-chatbot' );
										?>
									</p>
								</div>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<span class="ecb-field-label"><?php esc_html_e( 'Mensaje lead ya registrado', 'estandar-chatbot' ); ?></span>
						</th>
						<td>
							<textarea name="chatbot_mensaje_ya_registrado" rows="3" class="large-text"><?php echo esc_textarea( $lead_registered_message ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Se muestra cuando el sistema detecta que este usuario ya dejó sus datos antes. Si está vacío, usa el mensaje por defecto.', 'estandar-chatbot' ); ?></p>
						</td>
					</tr>
					</tbody>
					</table>
				</section>

				<?php // ── Señales internas del negocio ─────────────────────────── ?>
				<section style="margin-top:16px;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Señales internas del negocio', 'estandar-chatbot' ); ?></h4>
					<p class="description" style="margin-bottom:10px;"><?php esc_html_e( 'Palabras clave y textos internos para afinar el comportamiento según el tipo de negocio.', 'estandar-chatbot' ); ?></p>
					<table class="form-table" role="presentation">
					<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Señal principal del negocio', 'estandar-chatbot' ); ?></th>
						<td>
							<input type="text" name="chatbot_behavior_copy[special_service_keyword]" value="<?php echo esc_attr( (string) ( $behavior_copy['special_service_keyword'] ?? '' ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Referencia interna del servicio principal. Ejemplo: servicio, producto, proyecto, visita.', 'estandar-chatbot' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'CTA ejecutable de cierre', 'estandar-chatbot' ); ?></th>
						<td>
							<input type="text" name="chatbot_behavior_copy[special_service_cta_label]" value="<?php echo esc_attr( (string) ( $behavior_copy['special_service_cta_label'] ?? '' ) ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Texto de acción visible para cerrar. Ejemplo: Solicitar cotización, Agendar visita.', 'estandar-chatbot' ); ?></p>
						</td>
					</tr>
					</tbody>
					</table>

					<details class="ecb-advanced-details" style="margin-top:12px;">
						<summary style="cursor:pointer;font-size:13px;color:#6b7280;"><?php esc_html_e( 'Mensajes de transición (para desarrolladores)', 'estandar-chatbot' ); ?></summary>
						<p class="description" style="margin:10px 0 6px;"><?php esc_html_e( 'Si están vacíos el chatbot usa defaults del perfil configurado.', 'estandar-chatbot' ); ?></p>
						<table class="form-table" role="presentation" style="margin-top:10px;">
							<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Keywords de alta intención', 'estandar-chatbot' ); ?></th>
								<td><textarea name="chatbot_behavior_copy[high_intent_keywords]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['high_intent_keywords'] ?? '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Keywords de tema sensible', 'estandar-chatbot' ); ?></th>
								<td><textarea name="chatbot_behavior_copy[tax_question_keywords]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['tax_question_keywords'] ?? '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Transición alta intención', 'estandar-chatbot' ); ?></th>
								<td><textarea name="chatbot_behavior_copy[high_intent_transition_message]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['high_intent_transition_message'] ?? '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Transición servicio principal', 'estandar-chatbot' ); ?></th>
								<td><textarea name="chatbot_behavior_copy[special_service_transition_message]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['special_service_transition_message'] ?? '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Transición para precio', 'estandar-chatbot' ); ?></th>
								<td><textarea name="chatbot_behavior_copy[price_transition_message]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['price_transition_message'] ?? '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'CTA de precio', 'estandar-chatbot' ); ?></th>
								<td><textarea name="chatbot_behavior_copy[price_cta_message]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['price_cta_message'] ?? '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Precio hacia WhatsApp', 'estandar-chatbot' ); ?></th>
								<td><textarea name="chatbot_behavior_copy[price_whatsapp_message]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['price_whatsapp_message'] ?? '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Mensaje para objeciones', 'estandar-chatbot' ); ?></th>
								<td><textarea name="chatbot_behavior_copy[objection_message]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['objection_message'] ?? '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Objeciones repetidas', 'estandar-chatbot' ); ?></th>
								<td><textarea name="chatbot_behavior_copy[objection_repeat_message]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['objection_repeat_message'] ?? '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Tema sensible', 'estandar-chatbot' ); ?></th>
								<td><textarea name="chatbot_behavior_copy[tax_cta_message]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['tax_cta_message'] ?? '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Post-lead HOT', 'estandar-chatbot' ); ?></th>
								<td><textarea name="chatbot_behavior_copy[post_lead_hot_message]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['post_lead_hot_message'] ?? '' ) ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Usuario comparte su web', 'estandar-chatbot' ); ?></th>
								<td>
									<textarea name="chatbot_behavior_copy[website_input_message]" rows="3" class="large-text"><?php echo esc_textarea( (string) ( $behavior_copy['website_input_message'] ?? '' ) ); ?></textarea>
									<p class="description"><?php esc_html_e( 'Usa {{website}} para mostrar el dominio detectado.', 'estandar-chatbot' ); ?></p>
								</td>
							</tr>
							</tbody>
						</table>
					</details>
				</section>

				<?php // ── CTA labels ───────────────────────────────────────────── ?>
				<section style="margin-top:16px;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Textos de acción (CTAs)', 'estandar-chatbot' ); ?></h4>
					<p class="description" style="margin-bottom:10px;"><?php esc_html_e( 'El chatbot muestra automáticamente un botón de acción según lo que el usuario está pidiendo. Aquí defines el texto de cada botón.', 'estandar-chatbot' ); ?></p>
					<?php
					$cta_contexts = [
						'precio'    => __( 'Cuando pregunta por precio', 'estandar-chatbot' ),
						'cotizar'   => __( 'Cuando pide una cotización', 'estandar-chatbot' ),
						'contratar' => __( 'Cuando quiere contratar', 'estandar-chatbot' ),
						'pagar'     => __( 'Cuando quiere pagar', 'estandar-chatbot' ),
						'default'   => __( 'En cualquier otro caso (acción general)', 'estandar-chatbot' ),
					];
					?>
					<div style="display:flex;flex-direction:column;gap:8px;max-width:620px;">
						<?php foreach ( $cta_contexts as $cta_key => $cta_context ) : ?>
							<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
								<span style="font-size:13px;color:#374151;width:240px;flex-shrink:0;"><?php echo esc_html( $cta_context ); ?>:</span>
								<input id="chatbot_cta_<?php echo esc_attr( $cta_key ); ?>" type="text"
									name="chatbot_cta_labels[<?php echo esc_attr( $cta_key ); ?>]"
									value="<?php echo esc_attr( (string) ( $cta_labels[ $cta_key ] ?? '' ) ); ?>"
									class="regular-text ecb-cta-input" data-cta-key="<?php echo esc_attr( $cta_key ); ?>" style="max-width:200px;" />
								<span class="ecb-cta-chip" data-cta-chip="<?php echo esc_attr( $cta_key ); ?>" style="
									font-size:12px;padding:5px 12px;border-radius:16px;
									background:#2563eb;color:#fff;font-weight:600;white-space:nowrap;
								"><?php echo esc_html( (string) ( $cta_labels[ $cta_key ] ?? '' ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="description" style="margin-top:8px;"><?php esc_html_e( 'El botón azul muestra cómo lo verá el usuario dentro del chat.', 'estandar-chatbot' ); ?></p>
					<script>
					(function(){
						document.querySelectorAll('.ecb-cta-input').forEach(function(input){
							var chip = document.querySelector('.ecb-cta-chip[data-cta-chip="' + input.dataset.ctaKey + '"]');
							if (!chip) return;
							input.addEventListener('input', function(){
								chip.textContent = input.value.trim() || '—';
							});
						});
					})();
					</script>
				</section>

				<?php // ── Clasificación + proyectos + reglas ──────────────────── ?>
				<section style="margin-top:16px;">
					<h4 style="margin:0 0 4px;"><?php esc_html_e( 'Clasificación y proyectos', 'estandar-chatbot' ); ?></h4>
					<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Umbrales de prioridad, registro de proyectos y reglas de enrutamiento. Su administración será centralizada en Estandar Leads en una futura actualización.', 'estandar-chatbot' ); ?></p>
					<table class="form-table" role="presentation">
					<tbody>
					<tr>
						<th scope="row"><span class="ecb-field-label"><?php esc_html_e( 'Usuario listo para contratar (HOT)', 'estandar-chatbot' ); ?></span></th>
						<td>
							<input type="number" min="0" step="1" name="chatbot_lead_hot_threshold" value="<?php echo esc_attr( (string) $lead_hot_threshold ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Puntaje mínimo para clasificar como HOT. Valor recomendado: 70.', 'estandar-chatbot' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><span class="ecb-field-label"><?php esc_html_e( 'Usuario interesado (WARM)', 'estandar-chatbot' ); ?></span></th>
						<td>
							<input type="number" min="0" step="1" name="chatbot_lead_warm_threshold" value="<?php echo esc_attr( (string) $lead_warm_threshold ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Puntaje mínimo para clasificar como WARM. Valor recomendado: 40.', 'estandar-chatbot' ); ?></p>
						</td>
					</tr>
					</tbody>
					</table>

						<h5 style="margin:16px 0 4px;"><?php esc_html_e( 'Registro de proyectos', 'estandar-chatbot' ); ?></h5>
						<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Readonly. Fuente canónica: Core → Proyectos. Prefijo SID derivado desde el slug.', 'estandar-chatbot' ); ?></p>
						<div id="ecb-project-registry-builder" class="ecb-intents-builder">
						<?php foreach ( (array) $project_registry as $idx => $project ) :
							$has_slug     = isset( $project['project_slug'] ) && '' !== $project['project_slug'];
							$project_slug = (string) ( $project['project_slug'] ?? '' );
							$is_focus     = $focus_project !== '' && $project_slug === $focus_project;
					?>
						<div id="ecb-project-row-<?php echo esc_attr( sanitize_key( $project_slug ) ); ?>" class="ecb-intent-row ecb-project-row" style="flex-wrap:wrap;gap:6px 10px;align-items:flex-start;padding:<?php echo $is_focus ? '10px' : '0 0 10px'; ?>;border:<?php echo $is_focus ? '2px solid #dba617' : '0'; ?>;border-bottom:1px solid #e0e0e0;margin-bottom:6px;background:<?php echo $is_focus ? '#fffbeb' : 'transparent'; ?>;">
							<?php if ( $has_slug ) : ?>
									<span style="display:inline-flex;flex-direction:column;gap:2px;">
										<label style="font-size:11px;color:#666;"><?php esc_html_e( 'Slug (inmutable)', 'estandar-chatbot' ); ?></label>
										<code style="padding:4px 8px;background:#f0f0f0;border-radius:3px;"><?php echo esc_html( $project_slug ); ?></code>
									</span>
								<?php else : ?>
									<span style="display:inline-flex;flex-direction:column;gap:2px;">
										<label style="font-size:11px;color:#666;"><?php esc_html_e( 'Slug', 'estandar-chatbot' ); ?></label>
										<input type="text" value="" placeholder="project-slug" class="regular-text" style="width:130px;" readonly />
									</span>
								<?php endif; ?>
								<span style="display:inline-flex;flex-direction:column;gap:2px;">
									<label style="font-size:11px;color:#666;"><?php esc_html_e( 'Nombre', 'estandar-chatbot' ); ?></label>
									<input type="text" value="<?php echo esc_attr( (string) ( $project['name'] ?? '' ) ); ?>" placeholder="Nombre proyecto" class="regular-text" style="width:160px;" readonly />
								</span>
								<span style="display:inline-flex;flex-direction:column;gap:2px;">
									<label style="font-size:11px;color:#666;"><?php esc_html_e( 'Ciudad', 'estandar-chatbot' ); ?></label>
									<input type="text" value="<?php echo esc_attr( (string) ( $project['city'] ?? '' ) ); ?>" placeholder="Ciudad" class="regular-text" style="width:120px;" readonly />
								</span>
								<span style="display:inline-flex;flex-direction:column;gap:2px;">
									<label style="font-size:11px;color:#666;"><?php esc_html_e( 'SID', 'estandar-chatbot' ); ?></label>
									<input type="text" value="<?php echo esc_attr( strtoupper( (string) ( $project['sid_prefix'] ?? '' ) ) ); ?>" placeholder="AC" class="regular-text" style="width:50px;text-transform:uppercase;" maxlength="2" readonly />
								</span>
								<span style="display:inline-flex;flex-direction:column;gap:2px;">
									<label style="font-size:11px;color:#666;"><?php esc_html_e( 'URL patterns (coma)', 'estandar-chatbot' ); ?></label>
									<input type="text" value="<?php echo esc_attr( implode( ', ', (array) ( $project['url_patterns'] ?? [] ) ) ); ?>" placeholder="/proyecto, /proyecto/contacto" class="regular-text" style="width:220px;" readonly />
								</span>
								<span style="display:inline-flex;flex-direction:column;gap:2px;justify-content:flex-end;">
									<label style="font-size:11px;color:#666;"><?php esc_html_e( 'Activo', 'estandar-chatbot' ); ?></label>
									<input type="checkbox" value="1" <?php checked( (bool) ( $project['active'] ?? true ) ); ?> style="margin-top:4px;" disabled />
								</span>
							</div>
						<?php endforeach; ?>
						</div>
						<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Para crear o modificar proyectos, usa Estandar Primer → Proyectos.', 'estandar-chatbot' ); ?></p>

					<h5 style="margin:16px 0 4px;"><?php esc_html_e( 'Reglas URL → intención', 'estandar-chatbot' ); ?></h5>
					<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Si la URL de origen coincide, tiene prioridad sobre el texto del usuario. Usa el slug sin parámetros.', 'estandar-chatbot' ); ?></p>
					<input type="hidden" name="chatbot_source_intent_rules__present" value="1" />
					<div id="ecb-source-rules-builder" class="ecb-intents-builder">
						<?php foreach ( (array) $source_intent_rules as $idx => $rule ) : ?>
							<div class="ecb-intent-row ecb-source-rule-row">
								<input type="text" name="chatbot_source_intent_rules[<?php echo esc_attr( (string) $idx ); ?>][slug]" value="<?php echo esc_attr( (string) ( $rule['slug'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'slug URL', 'estandar-chatbot' ); ?>" class="regular-text ecb-source-rule-slug" />
								<input type="text" name="chatbot_source_intent_rules[<?php echo esc_attr( (string) $idx ); ?>][intent]" value="<?php echo esc_attr( (string) ( $rule['intent'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'intent interno', 'estandar-chatbot' ); ?>" class="regular-text ecb-source-rule-intent" />
								<input type="text" name="chatbot_source_intent_rules[<?php echo esc_attr( (string) $idx ); ?>][project_slug]" value="<?php echo esc_attr( (string) ( $rule['project_slug'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'project_slug', 'estandar-chatbot' ); ?>" class="regular-text" />
								<input type="text" name="chatbot_source_intent_rules[<?php echo esc_attr( (string) $idx ); ?>][area]" value="<?php echo esc_attr( (string) ( $rule['area'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Área / Proyecto', 'estandar-chatbot' ); ?>" class="regular-text" />
								<button type="button" class="button ecb-remove-source-rule"><?php esc_html_e( 'Eliminar', 'estandar-chatbot' ); ?></button>
							</div>
						<?php endforeach; ?>
					</div>
					<p style="margin-top:8px;">
						<button type="button" class="button" id="ecb-add-source-rule">+ <?php esc_html_e( 'Agregar regla URL', 'estandar-chatbot' ); ?></button>
					</p>

					<h5 style="margin:16px 0 4px;"><?php esc_html_e( 'Reglas palabras clave → intención', 'estandar-chatbot' ); ?></h5>
					<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Fallback cuando la URL no entrega intención clara. Ejemplo: cotizar → ventas.', 'estandar-chatbot' ); ?></p>
					<input type="hidden" name="chatbot_text_intent_rules__present" value="1" />
					<div id="ecb-text-rules-builder" class="ecb-intents-builder">
						<?php foreach ( (array) $text_intent_rules as $idx => $rule ) : ?>
							<div class="ecb-intent-row ecb-text-rule-row">
								<input type="text" name="chatbot_text_intent_rules[<?php echo esc_attr( (string) $idx ); ?>][keyword]" value="<?php echo esc_attr( (string) ( $rule['keyword'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'palabra clave', 'estandar-chatbot' ); ?>" class="regular-text ecb-text-rule-keyword" />
								<input type="text" name="chatbot_text_intent_rules[<?php echo esc_attr( (string) $idx ); ?>][intent]" value="<?php echo esc_attr( (string) ( $rule['intent'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'intent interno', 'estandar-chatbot' ); ?>" class="regular-text ecb-text-rule-intent" />
								<input type="text" name="chatbot_text_intent_rules[<?php echo esc_attr( (string) $idx ); ?>][project_slug]" value="<?php echo esc_attr( (string) ( $rule['project_slug'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'project_slug', 'estandar-chatbot' ); ?>" class="regular-text" />
								<input type="text" name="chatbot_text_intent_rules[<?php echo esc_attr( (string) $idx ); ?>][area]" value="<?php echo esc_attr( (string) ( $rule['area'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Área / Categoría', 'estandar-chatbot' ); ?>" class="regular-text" />
								<button type="button" class="button ecb-remove-text-rule"><?php esc_html_e( 'Eliminar', 'estandar-chatbot' ); ?></button>
							</div>
						<?php endforeach; ?>
					</div>
					<p style="margin-top:8px;">
						<button type="button" class="button" id="ecb-add-text-rule">+ <?php esc_html_e( 'Agregar regla de texto', 'estandar-chatbot' ); ?></button>
					</p>
				</section>

				<?php // ── Responsables ─────────────────────────────────────────── ?>
				<section style="margin-top:16px;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Responsables de leads', 'estandar-chatbot' ); ?></h4>
					<div style="background:#f0f9ff;border-left:3px solid #0ea5e9;padding:12px 16px;border-radius:0 4px 4px 0;font-size:13px;color:#0c4a6e;">
						<strong><?php esc_html_e( 'La asignación de responsables se administra desde Estandar Leads.', 'estandar-chatbot' ); ?></strong><br>
						<span style="color:#374151;"><?php esc_html_e( 'Los operadores configurados en Leads son la fuente de verdad para routing y asignación.', 'estandar-chatbot' ); ?></span>
					</div>
					<?php
					$leads_settings_url = admin_url( 'admin.php?page=estandar-leads-settings' );
					$has_leads          = function_exists( 'ESTANDAR_LEADS_VERSION' ) || defined( 'ESTANDAR_LEADS_VERSION' );
					$responsables_count = count( (array) $responsables );
					if ( $responsables_count > 0 ) : ?>
					<p style="font-size:12px;color:#6b7280;margin:10px 0 10px;">
						<?php printf(
							esc_html( _n( '%d responsable en el registro de Chatbot.', '%d responsables en el registro de Chatbot.', $responsables_count, 'estandar-chatbot' ) ),
							$responsables_count
						); ?>
						<?php esc_html_e( 'Los datos permanecen intactos.', 'estandar-chatbot' ); ?>
					</p>
					<?php endif; ?>
					<?php if ( $has_leads ) : ?>
						<a href="<?php echo esc_url( $leads_settings_url ); ?>" class="button" style="margin-top:8px;"><?php esc_html_e( 'Ir a Leads → Configuración →', 'estandar-chatbot' ); ?></a>
					<?php else : ?>
						<p style="font-size:12px;color:#9ca3af;margin:8px 0 0;"><?php esc_html_e( 'Activa Estandar Leads para administrar operadores.', 'estandar-chatbot' ); ?></p>
					<?php endif; ?>
				</section>

				<?php // ── Acciones rápidas del chat ─────────────────────────────── ?>
				<section id="ecb-quick-actions-section" style="margin-top:16px;">
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Acciones rápidas del chat', 'estandar-chatbot' ); ?></h4>
					<p class="description"><?php esc_html_e( 'Acciones disponibles durante la conversación. Máximo recomendado: 3 botones.', 'estandar-chatbot' ); ?></p>
					<input type="hidden" name="chatbot_botones_iniciales__present" value="1" />
					<div class="ecb-card-grid" style="margin:12px 0;">
						<div class="ecb-card">
							<p class="ecb-metric"><strong><?php esc_html_e( 'Botones configurados:', 'estandar-chatbot' ); ?></strong> <span id="ecb-botones-count"><?php echo esc_html( (string) $quick_actions_total ); ?></span></p>
							<p class="description" id="ecb-botones-warning" style="margin-top:6px;"></p>
						</div>
					</div>
					<div class="ecb-botones-layout">
						<div class="ecb-botones-main">
							<div class="ecb-card" style="margin-bottom:12px;">
								<h4 style="margin-top:0;"><?php esc_html_e( 'Crear acción rápida', 'estandar-chatbot' ); ?></h4>
								<div class="ecb-card-grid" style="grid-template-columns:minmax(220px,1fr) minmax(240px,260px);align-items:end;">
									<div>
										<label style="display:block;font-size:12px;color:#6e6e73;margin-bottom:4px;"><?php esc_html_e( 'Texto de la acción', 'estandar-chatbot' ); ?></label>
										<input type="text" id="ecb-new-button-text" placeholder="<?php esc_attr_e( 'Ej: Hablar por WhatsApp', 'estandar-chatbot' ); ?>" style="width:100%;" />
									</div>
									<div>
										<label style="display:block;font-size:12px;color:#6e6e73;margin-bottom:4px;"><?php esc_html_e( 'Tipo de acción', 'estandar-chatbot' ); ?></label>
										<select id="ecb-new-button-type" style="width:100%;">
											<option value="ia"><?php esc_html_e( 'Enviar mensaje al chat', 'estandar-chatbot' ); ?></option>
											<option value="info"><?php esc_html_e( 'Mostrar información fija', 'estandar-chatbot' ); ?></option>
											<option value="link"><?php esc_html_e( 'Abrir enlace', 'estandar-chatbot' ); ?></option>
											<option value="human_escalation"><?php esc_html_e( 'Hablar con humano', 'estandar-chatbot' ); ?></option>
											<option value="reset_flow"><?php esc_html_e( 'Volver al inicio', 'estandar-chatbot' ); ?></option>
										</select>
									</div>
								</div>
								<p class="description" id="ecb-new-button-help" style="margin:10px 0 0;"></p>
								<p style="margin:10px 0 0;">
									<button type="button" class="button" id="add-boton">+ <?php esc_html_e( 'Agregar acción rápida', 'estandar-chatbot' ); ?></button>
								</p>
							</div>
							<div class="ecb-card" style="margin-bottom:12px;">
								<p style="margin-top:0;"><strong><?php esc_html_e( 'Arrastra para ordenar según prioridad.', 'estandar-chatbot' ); ?></strong></p>
								<div id="chatbot-botones-builder" class="ecb-botones-builder">
									<?php if ( ! empty( $quick_actions ) ) : ?>
										<?php foreach ( $quick_actions as $i => $btn ) : ?>
											<?php include __DIR__ . '/partials/boton-item.php'; ?>
										<?php endforeach; ?>
									<?php else : ?>
										<div class="notice notice-info inline ecb-botones-empty-state"><p><?php esc_html_e( 'Aún no hay acciones rápidas creadas.', 'estandar-chatbot' ); ?></p></div>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<aside class="ecb-card ecb-botones-preview">
							<h4 style="margin-top:0;"><?php esc_html_e( 'Vista previa', 'estandar-chatbot' ); ?></h4>
							<div id="ecb-botones-preview-list" class="ecb-botones-preview-list"></div>
						</aside>
					</div>
				</section>

			</details>

		</div>

		<aside class="ecb-admin-surface ecb-leads-preview">
			<h3><?php esc_html_e( 'Así verá el usuario tu chatbot', 'estandar-chatbot' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Simulación del flujo antes de guardar.', 'estandar-chatbot' ); ?></p>
			<p><button type="button" class="button" id="ecb-start-simulation"><?php esc_html_e( 'Simular conversación', 'estandar-chatbot' ); ?></button></p>
			<div class="ecb-flow-preview">
				<div class="ecb-flow-preview__intro" id="ecb-preview-intro"><?php echo esc_html( $lead_intro_text ); ?></div>
				<div class="ecb-flow-preview__buttons" id="ecb-preview-intents">
					<?php foreach ( $preview_intents as $intent_label ) : ?>
						<span class="ecb-flow-chip"><?php echo esc_html( $intent_label ); ?></span>
					<?php endforeach; ?>
				</div>
				<div class="ecb-flow-preview__user" id="ecb-preview-user" hidden></div>
				<div class="ecb-flow-preview__reply" id="ecb-preview-reply"><?php esc_html_e( 'Perfecto. Cuéntame un poco más para ayudarte mejor.', 'estandar-chatbot' ); ?></div>
				<div class="ecb-flow-preview__capture">
					<strong><?php esc_html_e( 'Datos que pedirá el chatbot', 'estandar-chatbot' ); ?></strong>
					<ul id="ecb-preview-fields">
						<?php foreach ( $preview_fields as $field_label ) : ?>
							<li><?php echo esc_html( $field_label ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
			<?php submit_button( __( 'Guardar captación', 'estandar-chatbot' ), 'primary', 'submit', false ); ?>
		</aside>
	</div>
</form>

<script type="text/html" id="tpl-boton-item">
	<?php
	$btn = [
		'id'       => '',
		'icono'    => '',
		'texto'    => '',
		'tipo'     => 'info',
		'payload'  => '',
		'children' => [],
	];
	include __DIR__ . '/partials/boton-item.php';
	?>
</script>

<script>
(function(){
	var builder        = document.getElementById('ecb-intents-builder');
	var addBtn         = document.getElementById('ecb-add-intent');
	var recommendedBtn = document.getElementById('ecb-apply-recommended');
	var autoOpenEnabled     = document.getElementById('chatbot_auto_open_enabled');
	var autoOpenSettings    = document.getElementById('ecb-auto-open-settings');
	var autoOpenValueInput  = document.getElementById('chatbot_auto_open_value');
	var autoOpenValueLabel  = document.getElementById('ecb-auto-open-value-label');
	var autoOpenDescription = document.getElementById('ecb-auto-open-description');
	var humanCheck          = document.getElementById('chatbot_human_escalation_enabled');
	var humanRow            = document.getElementById('ecb-row-human-text');
	var humanModeRow        = document.getElementById('ecb-row-human-mode');
	var humanModeSelect     = document.getElementById('chatbot_human_escalation_mode');
	var humanWANumberRow    = document.getElementById('ecb-row-human-whatsapp-number');
	var humanWAMessageRow   = document.getElementById('ecb-row-human-whatsapp-message');
	var introInput          = document.querySelector('input[name="chatbot_lead_intro_text"]');
	var previewIntro        = document.getElementById('ecb-preview-intro');
	var previewIntents      = document.getElementById('ecb-preview-intents');
	var previewFields       = document.getElementById('ecb-preview-fields');
	var simulationBtn       = document.getElementById('ecb-start-simulation');
	var previewUser         = document.getElementById('ecb-preview-user');
	var previewReply        = document.getElementById('ecb-preview-reply');
	var leadFieldsTextarea  = document.getElementById('chatbot_lead_fields');
	var projectBuilder      = document.getElementById('ecb-project-registry-builder');
	var addProjectBtn       = document.getElementById('ecb-add-project');
	var sourceBuilder       = document.getElementById('ecb-source-rules-builder');
	var addSourceBtn        = document.getElementById('ecb-add-source-rule');
	var textBuilder         = document.getElementById('ecb-text-rules-builder');
	var addTextBtn          = document.getElementById('ecb-add-text-rule');

	if (!builder || !addBtn || !recommendedBtn || !humanCheck || !humanRow || !previewIntents || !previewFields) return;

	var recommendedIntents = [
		{ id: 'ventas', label: 'Quiero cotizar' },
		{ id: 'soporte', label: 'Necesito soporte' },
		{ id: 'info', label: 'Quiero más información' }
	];
	var recommendedFields = [
		{ key: 'name', label: 'Nombre', required: true },
		{ key: 'phone', label: 'Teléfono', required: true }
	];

	function slugify(text){
		return String(text||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'');
	}

	function nextIntentIndex(){ return builder.querySelectorAll('.ecb-intent-row').length; }

	function reindexIntentRows(){
		Array.from(builder.querySelectorAll('.ecb-intent-row')).forEach(function(row, idx){
			var label = row.querySelector('.ecb-intent-label');
			var id = row.querySelector('.ecb-intent-id');
			if (label) label.name = 'chatbot_lead_intents[' + idx + '][label]';
			if (id) id.name = 'chatbot_lead_intents[' + idx + '][id]';
		});
	}

	function refreshPreviewIntents(){
		var labels = Array.from(builder.querySelectorAll('.ecb-intent-label')).map(function(i){ return i.value.trim(); }).filter(Boolean);
		previewIntents.innerHTML = '';
		labels.forEach(function(label){
			var chip = document.createElement('span');
			chip.className = 'ecb-flow-chip';
			chip.textContent = label;
			previewIntents.appendChild(chip);
		});
	}

	function refreshPreviewFields(){
		if (!previewFields) return;
		var fields = [];
		if (leadFieldsTextarea) { try { fields = JSON.parse(leadFieldsTextarea.value||'[]'); } catch(_){} }
		var labels = Array.isArray(fields) ? fields.map(function(f){ return (f&&f.label)?String(f.label).trim():''; }).filter(Boolean) : [];
		previewFields.innerHTML = '';
		labels.forEach(function(label){
			var li = document.createElement('li');
			li.textContent = label;
			previewFields.appendChild(li);
		});
	}

	function bindIntentRow(row){
		var label  = row.querySelector('.ecb-intent-label');
		var id     = row.querySelector('.ecb-intent-id');
		var remove = row.querySelector('.ecb-remove-intent');
		var moveUp = row.querySelector('.ecb-move-intent-up');
		var moveDown = row.querySelector('.ecb-move-intent-down');
		if (label && id){
			var sync = function(){
				if (!id.value.trim()) id.value = slugify(label.value);
				refreshPreviewIntents();
			};
			label.addEventListener('input', sync);
			label.addEventListener('blur', sync);
			sync();
		}
		if (id) id.addEventListener('input', function(){ id.value = slugify(id.value); });
		if (remove) remove.addEventListener('click', function(){ row.remove(); reindexIntentRows(); refreshPreviewIntents(); });
		if (moveUp) moveUp.addEventListener('click', function(){
			var previous = row.previousElementSibling;
			if (previous) builder.insertBefore(row, previous);
			reindexIntentRows();
			refreshPreviewIntents();
		});
		if (moveDown) moveDown.addEventListener('click', function(){
			var next = row.nextElementSibling;
			if (next) builder.insertBefore(next, row);
			reindexIntentRows();
			refreshPreviewIntents();
		});
	}

	function makeIntentRow(idx, intent){
		var row = document.createElement('div');
		row.className = 'ecb-intent-row';
		row.innerHTML =
			'<input type="text" name="chatbot_lead_intents['+idx+'][label]" value="'+String(intent.label||'').replace(/"/g,'&quot;')+'" placeholder="Texto visible del botón" class="regular-text ecb-intent-label" />' +
			'<div class="ecb-intent-controls"><button type="button" class="button ecb-move-intent-up" title="Subir opción" aria-label="Subir opción">↑</button><button type="button" class="button ecb-move-intent-down" title="Bajar opción" aria-label="Bajar opción">↓</button><button type="button" class="button-link-delete ecb-remove-intent" title="Eliminar opción" aria-label="Eliminar opción">×</button></div>' +
			'<details class="ecb-intent-advanced"><summary>Opciones avanzadas (para desarrolladores)</summary><div class="ecb-intent-id-wrap">' +
			'<input type="text" name="chatbot_lead_intents['+idx+'][id]" value="'+String(intent.id||'').replace(/"/g,'&quot;')+'" placeholder="id interno" class="regular-text ecb-intent-id" style="max-width:220px;" />' +
			'<p class="description">Solo cambia este ID si integras automatizaciones o scoring personalizado.</p></div></details>';
		return row;
	}

	// ── Human escalation (fixed: WA rows only when mode=whatsapp) ──
	function paintHumanRow(){
		var on = humanCheck.checked;
		if (humanRow) humanRow.style.display = on ? '' : 'none';
		if (humanModeRow) humanModeRow.style.display = on ? '' : 'none';
		var isWA = on && humanModeSelect && humanModeSelect.value === 'whatsapp';
		if (humanWANumberRow)  humanWANumberRow.style.display  = isWA ? '' : 'none';
		if (humanWAMessageRow) humanWAMessageRow.style.display = isWA ? '' : 'none';
	}

	// ── Auto-open ──
	function getAutoOpenType(){
		var c = document.querySelector('input[name="chatbot_auto_open_type"]:checked');
		return c ? c.value : 'time';
	}
	function paintAutoOpenSettings(){
		if (!autoOpenEnabled||!autoOpenSettings||!autoOpenValueInput||!autoOpenValueLabel||!autoOpenDescription) return;
		var on = autoOpenEnabled.checked, type = getAutoOpenType();
		autoOpenSettings.style.display = on ? '' : 'none';
		if (type === 'scroll'){
			autoOpenValueLabel.textContent = 'Porcentaje de scroll';
			autoOpenDescription.textContent = 'Recomendado: 30% a 40% de scroll.';
		} else {
			autoOpenValueLabel.textContent = 'Segundos en página';
			autoOpenDescription.textContent = 'Recomendado: entre 5 y 8 segundos.';
		}
	}

	// ── Project registry ──
	function nextProjectIndex(){ return projectBuilder ? projectBuilder.querySelectorAll('.ecb-project-row').length : 0; }
		function makeProjectRow(idx, project){
			var row = document.createElement('div');
			row.className = 'ecb-intent-row ecb-project-row';
			var slug = String(project.project_slug||'').replace(/"/g,'&quot;');
			var patterns = Array.isArray(project.url_patterns) ? project.url_patterns.join(', ') : String(project.url_patterns||'');
			var activeChecked = (project.active===false||project.active==='0'||project.active===0) ? '' : 'checked';
			row.style.cssText = 'flex-wrap:wrap;gap:6px 10px;align-items:flex-start;padding-bottom:10px;border-bottom:1px solid #e0e0e0;margin-bottom:6px;';
			var slugField = slug
				? '<code style="padding:4px 8px;background:#f0f0f0;border-radius:3px;font-size:12px;">'+slug+'</code>'
				: '<input type="text" value="" placeholder="project-slug" class="regular-text" style="width:130px;" readonly />';
			row.innerHTML =
				'<span style="display:inline-flex;flex-direction:column;gap:2px;"><label style="font-size:11px;color:#666;">Slug</label>'+slugField+'</span>'+
				'<span style="display:inline-flex;flex-direction:column;gap:2px;"><label style="font-size:11px;color:#666;">Nombre</label><input type="text" value="'+String(project.name||'').replace(/"/g,'&quot;')+'" placeholder="Nombre proyecto" class="regular-text" style="width:160px;" readonly /></span>'+
				'<span style="display:inline-flex;flex-direction:column;gap:2px;"><label style="font-size:11px;color:#666;">SID</label><input type="text" value="" placeholder="AC" class="regular-text" style="width:50px;text-transform:uppercase;" maxlength="2" readonly /></span>'+
				'<span style="display:inline-flex;flex-direction:column;gap:2px;"><label style="font-size:11px;color:#666;">URL patterns (coma)</label><input type="text" value="'+patterns.replace(/"/g,'&quot;')+'" placeholder="/proyecto, /proyecto/contacto" class="regular-text" style="width:220px;" readonly /></span>'+
				'<span style="display:inline-flex;flex-direction:column;gap:2px;justify-content:flex-end;"><label style="font-size:11px;color:#666;">Activo</label><input type="checkbox" value="1" '+activeChecked+' style="margin-top:4px;" disabled /></span>';
			return row;
		}
	function bindProjectRow(row){
		var remove = row.querySelector('.ecb-remove-project');
		if (remove) remove.addEventListener('click', function(){ row.remove(); });
	}

	// ── Source rules ──
	function nextSourceIdx(){ return sourceBuilder ? sourceBuilder.querySelectorAll('.ecb-source-rule-row').length : 0; }
	function makeSourceRuleRow(idx, rule){
		var row = document.createElement('div');
		row.className = 'ecb-intent-row ecb-source-rule-row';
		row.innerHTML =
			'<input type="text" name="chatbot_source_intent_rules['+idx+'][slug]" value="'+String(rule.slug||'').replace(/"/g,'&quot;')+'" placeholder="slug URL" class="regular-text ecb-source-rule-slug" />' +
			'<input type="text" name="chatbot_source_intent_rules['+idx+'][intent]" value="'+String(rule.intent||'').replace(/"/g,'&quot;')+'" placeholder="intent interno" class="regular-text ecb-source-rule-intent" />' +
			'<input type="text" name="chatbot_source_intent_rules['+idx+'][project_slug]" value="" placeholder="project_slug" class="regular-text" />' +
			'<input type="text" name="chatbot_source_intent_rules['+idx+'][area]" value="" placeholder="Área / Proyecto" class="regular-text" />' +
			'<button type="button" class="button ecb-remove-source-rule">Eliminar</button>';
		return row;
	}
	function bindSourceRuleRow(row){
		var slugInput = row.querySelector('.ecb-source-rule-slug');
		var intentInput = row.querySelector('.ecb-source-rule-intent');
		var remove = row.querySelector('.ecb-remove-source-rule');
		if (slugInput) slugInput.addEventListener('input', function(){ slugInput.value = slugify(slugInput.value).replace(/_/g,'-'); });
		if (intentInput) intentInput.addEventListener('input', function(){ intentInput.value = slugify(intentInput.value); });
		if (remove) remove.addEventListener('click', function(){ row.remove(); });
	}

	// ── Text rules ──
	function nextTextIdx(){ return textBuilder ? textBuilder.querySelectorAll('.ecb-text-rule-row').length : 0; }
	function makeTextRuleRow(idx, rule){
		var row = document.createElement('div');
		row.className = 'ecb-intent-row ecb-text-rule-row';
		row.innerHTML =
			'<input type="text" name="chatbot_text_intent_rules['+idx+'][keyword]" value="'+String(rule.keyword||'').replace(/"/g,'&quot;')+'" placeholder="palabra clave" class="regular-text ecb-text-rule-keyword" />' +
			'<input type="text" name="chatbot_text_intent_rules['+idx+'][intent]" value="'+String(rule.intent||'').replace(/"/g,'&quot;')+'" placeholder="intent interno" class="regular-text ecb-text-rule-intent" />' +
			'<input type="text" name="chatbot_text_intent_rules['+idx+'][project_slug]" value="" placeholder="project_slug" class="regular-text" />' +
			'<input type="text" name="chatbot_text_intent_rules['+idx+'][area]" value="" placeholder="Área / Categoría" class="regular-text" />' +
			'<button type="button" class="button ecb-remove-text-rule">Eliminar</button>';
		return row;
	}
	function bindTextRuleRow(row){
		var intentInput = row.querySelector('.ecb-text-rule-intent');
		var remove = row.querySelector('.ecb-remove-text-rule');
		if (intentInput) intentInput.addEventListener('input', function(){ intentInput.value = slugify(intentInput.value); });
		if (remove) remove.addEventListener('click', function(){ row.remove(); });
	}

	// ── Recommended config (con confirmación previa) ──
	var recommendedPanel   = document.getElementById('ecb-recommended-confirm-panel');
	var recommendedConfirm = document.getElementById('ecb-recommended-confirm');
	var recommendedCancel  = document.getElementById('ecb-recommended-cancel');

	recommendedBtn.addEventListener('click', function(){
		if (recommendedPanel) {
			recommendedPanel.style.display = '';
			recommendedPanel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
		}
	});
	if (recommendedCancel) recommendedCancel.addEventListener('click', function(){
		if (recommendedPanel) recommendedPanel.style.display = 'none';
	});

	function applyRecommendedConfig(){
		builder.innerHTML = '';
		recommendedIntents.forEach(function(intent, idx){
			var row = makeIntentRow(idx, intent);
			builder.appendChild(row);
			bindIntentRow(row);
		});
		var warmInput = document.querySelector('input[name="chatbot_lead_warm_threshold"]');
		var hotInput  = document.querySelector('input[name="chatbot_lead_hot_threshold"]');
		var introField = document.querySelector('input[name="chatbot_lead_intro_text"]');
		var humanTextField = document.querySelector('input[name="chatbot_human_escalation_text"]');
		var autoOpenTypeTime = document.querySelector('input[name="chatbot_auto_open_type"][value="time"]');
		if (warmInput) warmInput.value = '40';
		if (hotInput)  hotInput.value  = '70';
		if (introField) introField.value = 'Te ayudo a encontrar la mejor opción. Elige lo que necesitas para comenzar.';
		if (humanTextField) humanTextField.value = 'Si prefieres, te puedo derivar ahora mismo con una persona del equipo.';
		if (autoOpenEnabled) autoOpenEnabled.checked = false;
		if (autoOpenTypeTime) autoOpenTypeTime.checked = true;
		if (autoOpenValueInput) autoOpenValueInput.value = '6';
		if (typeof window.applyRecommendedFields === 'function') window.applyRecommendedFields(recommendedFields);
		paintAutoOpenSettings();
		if (previewIntro && introField) previewIntro.textContent = introField.value;
		refreshPreviewIntents();
		refreshPreviewFields();
	}

	if (recommendedConfirm) recommendedConfirm.addEventListener('click', function(){
		applyRecommendedConfig();
		if (recommendedPanel) recommendedPanel.style.display = 'none';
	});

	if (introInput && previewIntro) {
		introInput.addEventListener('input', function(){ previewIntro.textContent = introInput.value.trim() || 'Te ayudo rápidamente.'; });
	}

	if (simulationBtn && previewIntents && previewUser && previewReply) {
		simulationBtn.addEventListener('click', function(){
			previewUser.hidden = true;
			previewUser.textContent = '';
			previewReply.hidden = true;
			simulationBtn.textContent = 'Elige una opción para continuar';
			previewIntents.classList.add('is-simulating');
		});
		previewIntents.addEventListener('click', function(event){
			var chip = event.target.closest('.ecb-flow-chip');
			if (!chip || !previewIntents.classList.contains('is-simulating')) return;
			previewUser.textContent = chip.textContent;
			previewUser.hidden = false;
			previewReply.hidden = false;
			previewIntents.classList.remove('is-simulating');
			simulationBtn.textContent = 'Simular nuevamente';
		});
	}

	addBtn.addEventListener('click', function(){
		var row = makeIntentRow(nextIntentIndex(), {id:'',label:''});
		builder.appendChild(row);
		bindIntentRow(row);
	});

	if (addProjectBtn && projectBuilder) addProjectBtn.addEventListener('click', function(){
		var row = makeProjectRow(nextProjectIndex(), {project_slug:'',name:'',city:''});
		projectBuilder.appendChild(row);
		bindProjectRow(row);
	});
	if (addSourceBtn && sourceBuilder) addSourceBtn.addEventListener('click', function(){
		var row = makeSourceRuleRow(nextSourceIdx(), {slug:'',intent:'',project_slug:'',area:''});
		sourceBuilder.appendChild(row);
		bindSourceRuleRow(row);
	});
	if (addTextBtn && textBuilder) addTextBtn.addEventListener('click', function(){
		var row = makeTextRuleRow(nextTextIdx(), {keyword:'',intent:'',project_slug:'',area:''});
		textBuilder.appendChild(row);
		bindTextRuleRow(row);
	});

	if (autoOpenEnabled) autoOpenEnabled.addEventListener('change', paintAutoOpenSettings);
	document.querySelectorAll('input[name="chatbot_auto_open_type"]').forEach(function(radio){ radio.addEventListener('change', paintAutoOpenSettings); });
	humanCheck.addEventListener('change', paintHumanRow);
	if (humanModeSelect) humanModeSelect.addEventListener('change', paintHumanRow);

	// Init
	paintAutoOpenSettings();
	paintHumanRow();
	builder.querySelectorAll('.ecb-intent-row').forEach(bindIntentRow);
	reindexIntentRows();
	if (projectBuilder) projectBuilder.querySelectorAll('.ecb-project-row').forEach(bindProjectRow);
	if (sourceBuilder) sourceBuilder.querySelectorAll('.ecb-source-rule-row').forEach(bindSourceRuleRow);
	if (textBuilder) textBuilder.querySelectorAll('.ecb-text-rule-row').forEach(bindTextRuleRow);
	refreshPreviewIntents();
	refreshPreviewFields();
})();
</script>

<script>
// ── Visual field builder ─────────────────────────────────────────────────────
(function(){
	var builder  = document.getElementById('ecb-fields-builder');
	var addBtn   = document.getElementById('ecb-add-field');
	var textarea = document.getElementById('chatbot_lead_fields');
	if (!builder || !addBtn || !textarea) return;

	function slugifyKey(text){
		return String(text||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'');
	}

	function syncJSON(){
		var rows   = Array.from(builder.querySelectorAll('.ecb-field-row'));
		var fields = rows.map(function(row){
			var lbl = row.querySelector('.ecb-field-label');
			var req = row.querySelector('.ecb-field-required');
			var label = lbl ? lbl.value.trim() : '';
			var key   = row.dataset.fkey || slugifyKey(label);
			if (!key) key = slugifyKey(label);
			return { key: key, label: label, required: req ? req.checked : false };
		}).filter(function(f){ return f.label !== ''; });
		textarea.value = JSON.stringify(fields, null, 2);
		if (typeof refreshPreviewFields === 'function') refreshPreviewFields();
	}

	function bindFieldRow(row){
		var lbl    = row.querySelector('.ecb-field-label');
		var req    = row.querySelector('.ecb-field-required');
		var badge  = row.querySelector('.ecb-field-key-badge');
		var remove = row.querySelector('.ecb-remove-field');
		if (lbl){
			lbl.addEventListener('input', function(){
				if (!row.dataset.fkeyLocked){
					var k = slugifyKey(lbl.value);
					row.dataset.fkey = k;
					if (badge) badge.textContent = k || '—';
				}
				syncJSON();
			});
		}
		if (req) req.addEventListener('change', syncJSON);
		if (remove) remove.addEventListener('click', function(){ row.remove(); syncJSON(); });
	}

	// Bind existing rows (locked keys)
	builder.querySelectorAll('.ecb-field-row').forEach(function(row){
		row.dataset.fkeyLocked = '1';
		bindFieldRow(row);
	});

	addBtn.addEventListener('click', function(){
		var row = document.createElement('div');
		row.className = 'ecb-field-row';
		row.dataset.fkey = '';
		row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px;';
		row.innerHTML =
			'<input type="text" class="ecb-field-label regular-text" placeholder="Ej: Correo electrónico" />' +
			'<label style="white-space:nowrap;display:flex;align-items:center;gap:4px;"><input type="checkbox" class="ecb-field-required" checked /> Requerido</label>' +
			'<code class="ecb-field-key-badge" style="font-size:11px;background:#f0f0f0;border-radius:3px;padding:3px 7px;color:#555;">—</code>' +
			'<button type="button" class="button-link-delete ecb-remove-field" title="Quitar campo" aria-label="Quitar campo">×</button>';
		builder.appendChild(row);
		bindFieldRow(row);
		var input = row.querySelector('.ecb-field-label');
		if (input) input.focus();
	});

	// Exposed for "Aplicar configuración recomendada"
	window.applyRecommendedFields = function(fields){
		builder.innerHTML = '';
		fields.forEach(function(f){
			var row = document.createElement('div');
			row.className = 'ecb-field-row';
			row.dataset.fkey = f.key;
			row.dataset.fkeyLocked = '1';
			row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:6px;';
			row.innerHTML =
				'<input type="text" class="ecb-field-label regular-text" value="'+String(f.label||'').replace(/"/g,'&quot;')+'" placeholder="Nombre del campo" />' +
				'<label style="white-space:nowrap;display:flex;align-items:center;gap:4px;"><input type="checkbox" class="ecb-field-required" '+(f.required?'checked':'')+' /> Requerido</label>' +
				'<code class="ecb-field-key-badge" style="font-size:11px;background:#f0f0f0;border-radius:3px;padding:3px 7px;color:#555;">'+String(f.key||'')+'</code>' +
				'<button type="button" class="button-link-delete ecb-remove-field" title="Quitar campo" aria-label="Quitar campo">×</button>';
			builder.appendChild(row);
			bindFieldRow(row);
		});
		syncJSON();
	};

	syncJSON();
})();
</script>

<script>
(function(){
	const container  = document.getElementById('chatbot-botones-builder');
	const tplEl      = document.getElementById('tpl-boton-item');
	const addBtn     = document.getElementById('add-boton');
	const countEl    = document.getElementById('ecb-botones-count');
	const warningEl  = document.getElementById('ecb-botones-warning');
	const previewList= document.getElementById('ecb-botones-preview-list');
	const newText    = document.getElementById('ecb-new-button-text');
	const newType    = document.getElementById('ecb-new-button-type');
	const newHelp    = document.getElementById('ecb-new-button-help');
	if (!container||!tplEl||!addBtn||!newText||!newType||!newHelp||!previewList) return;

	const HELP_BY_TYPE = {
		ia: 'Define la acción al hacer clic: enviar mensaje, abrir WhatsApp o navegar.',
		info: 'Muestra información fija o un mensaje preparado dentro del chat.',
		link: 'Abre un enlace externo. Usa una URL completa como https://...',
		human_escalation: 'Activará el flujo de contacto para derivar a una persona.',
		reset_flow: 'Reinicia el chatbot y vuelve a las opciones iniciales.'
	};
	const TEMPLATE_BY_TYPE = {
		ia: { icono: '💬', tipo: 'ia', payload: '' },
		info: { icono: '❓', tipo: 'info', payload: '' },
		link: { icono: '🔗', tipo: 'link', payload: 'https://' },
		human_escalation: { icono: '👤', tipo: 'human_escalation', payload: 'Hablar con una persona' },
		reset_flow: { icono: '🔄', tipo: 'info', payload: '/reset' }
	};

	function slugify(text){ return String(text||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,''); }
	function paintCreatorHelp(){ newHelp.textContent = HELP_BY_TYPE[newType.value]||''; }

	function updateCount(){
		const count = container.querySelectorAll('[data-boton-item="1"]').length;
		if (countEl) countEl.textContent = String(count);
		if (!warningEl) return;
		if (count===0){ warningEl.textContent='Aún no hay acciones rápidas. Máximo recomendado: 3 botones.'; }
		else if (count>3){ warningEl.textContent='Tienes más de 3 botones. Máximo recomendado: 3.'; }
		else { warningEl.textContent='Máximo recomendado: 3 botones.'; }
	}

	function updatePreview(){
		previewList.innerHTML='';
		const items=Array.from(container.querySelectorAll('[data-boton-item="1"]'));
		if(!items.length){ const c=document.createElement('div'); c.className='ecb-preview-chip is-empty'; c.textContent='Sin acciones rápidas.'; previewList.appendChild(c); return; }
		items.forEach(function(card){
			const text=card.querySelector('.ecb-boton-texto');
			const icon=card.querySelector('.ecb-boton-icono');
			const label=((icon?icon.value.trim()+' ':'')+(text?text.value.trim():'')).trim();
			if(!label) return;
			const c=document.createElement('div'); c.className='ecb-preview-chip'; c.textContent=label; previewList.appendChild(c);
		});
	}

	function renderEmptyState(){
		const items=container.querySelectorAll('[data-boton-item="1"]');
		const empty=container.querySelector('.ecb-botones-empty-state');
		if(items.length===0){ if(!empty) container.innerHTML='<div class="notice notice-info inline ecb-botones-empty-state"><p>Aún no hay acciones rápidas creadas.</p></div>'; return; }
		if(empty) empty.remove();
	}

	function paintCard(card){
		const typeEl=card.querySelector('.ecb-boton-tipo');
		const helpEl=card.querySelector('.ecb-boton-help');
		const payloadEl=card.querySelector('.ecb-boton-payload');
		const textEl=card.querySelector('.ecb-boton-texto');
		const iconEl=card.querySelector('.ecb-boton-icono');
		if(!typeEl||!helpEl||!payloadEl) return;
		const render=()=>{
			const t=typeEl.value||'info';
			helpEl.textContent=HELP_BY_TYPE[t]||'';
			if(t==='human_escalation'&&!payloadEl.value.trim()) payloadEl.value='Hablar con una persona';
			if(t==='info'&&payloadEl.value.trim()==='/reset'&&textEl&&!textEl.value.trim()) textEl.value='Volver al inicio';
			updatePreview();
		};
		typeEl.addEventListener('change',render);
		[textEl,iconEl,payloadEl].forEach(function(el){ if(el) el.addEventListener('input',updatePreview); });
		card.setAttribute('draggable','true');
		render();
	}

	function appendButton(config){
		const idx=Date.now().toString()+'_'+Math.floor(Math.random()*1000);
		const empty=container.querySelector('.ecb-botones-empty-state');
		if(empty) empty.remove();
		container.insertAdjacentHTML('beforeend',tplEl.innerHTML.replace(/__INDEX__/g,idx));
		const all=container.querySelectorAll('[data-boton-item="1"]');
		const last=all.length?all[all.length-1]:null;
		if(last){
			const ii=last.querySelector('.ecb-boton-icono');
			const ti=last.querySelector('.ecb-boton-texto');
			const ty=last.querySelector('.ecb-boton-tipo');
			const pi=last.querySelector('.ecb-boton-payload');
			const di=last.querySelector('.ecb-boton-id');
			if(ii) ii.value=config.icono||'';
			if(ti) ti.value=config.texto||'';
			if(ty) ty.value=config.tipo||'info';
			if(pi) pi.value=config.payload||'';
			if(di) di.value=slugify(config.texto||('btn_'+idx));
			paintCard(last);
		}
		updateCount(); updatePreview();
	}

	let dragEl=null;
	container.addEventListener('dragstart',function(e){ const c=e.target.closest('[data-boton-item="1"]'); if(!c) return; dragEl=c; c.classList.add('is-dragging'); });
	container.addEventListener('dragend',function(e){ const c=e.target.closest('[data-boton-item="1"]'); if(!c) return; c.classList.remove('is-dragging'); dragEl=null; updatePreview(); });
	container.addEventListener('dragover',function(e){ e.preventDefault(); const t=e.target.closest('[data-boton-item="1"]'); if(!dragEl||!t||dragEl===t) return; const r=t.getBoundingClientRect(); if((e.clientY-r.top)>(r.height/2)) t.after(dragEl); else t.before(dragEl); });
	container.addEventListener('click',function(e){ const remove=e.target.closest('.ecb-remove-boton'); if(!remove) return; const c=remove.closest('[data-boton-item="1"]'); if(!c) return; c.remove(); renderEmptyState(); updateCount(); updatePreview(); });
	addBtn.addEventListener('click',function(){
		const text=newText.value.trim(); if(!text){ newText.focus(); return; }
		const type=newType.value;
		const base=TEMPLATE_BY_TYPE[type]||TEMPLATE_BY_TYPE.info;
		appendButton({icono:base.icono,texto:text,tipo:base.tipo,payload:type==='ia'?text:base.payload});
		newText.value=''; newText.focus();
	});
	newType.addEventListener('change',paintCreatorHelp);
	container.querySelectorAll('[data-boton-item="1"]').forEach(paintCard);
	renderEmptyState(); paintCreatorHelp(); updateCount(); updatePreview();
})();
</script>
