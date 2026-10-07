<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'configuracion' ) ) {
	wp_die( esc_html__( 'No autorizado.', 'estandar-chatbot' ) );
}

$activar      = (int) get_option( 'chatbot_activar', 0 );
$solo_loggeados = (int) get_option( 'chatbot_solo_loggeados', 0 );
$nombre       = get_option( 'chatbot_nombre', 'Asistente' );
$mostrar_nombre = (int) get_option( 'chatbot_mostrar_nombre', 1 );
$idioma       = get_option( 'chatbot_idioma', 'es' );
$visibles     = estandar_chatbot_get_option_array( 'chatbot_paginas_visibles', [] );
$all_pages    = get_pages([
	'sort_column' => 'post_title',
	'sort_order'  => 'ASC',
	'post_status' => [ 'publish' ],
]);
$inline_page_id = (int) get_option( 'estandar_chatbot_inline_page_id', 0 );
$inline_page_url = $inline_page_id > 0 ? get_permalink( $inline_page_id ) : '';
$profile_labels    = function_exists( 'estandar_chatbot_get_profile_labels' ) ? estandar_chatbot_get_profile_labels() : [ '' => __( 'Sin perfil / Manual legacy', 'estandar-chatbot' ) ];
$profile_registry  = function_exists( 'estandar_chatbot_get_profile_registry' ) ? estandar_chatbot_get_profile_registry() : [];
$current_profile   = sanitize_key( (string) get_option( 'chatbot_profile', '' ) );
if ( ! isset( $profile_labels[ $current_profile ] ) ) {
	$current_profile = '';
}
$suggested_profile = function_exists( 'estandar_chatbot_suggest_profile' ) ? estandar_chatbot_suggest_profile() : '';
$preview_profile   = isset( $_GET['ecb_profile_preview'] ) ? sanitize_key( wp_unslash( $_GET['ecb_profile_preview'] ) ) : $current_profile;
if ( ! isset( $profile_labels[ $preview_profile ] ) ) {
	$preview_profile = '';
}
$preview_overwrite = ! empty( $_GET['ecb_profile_overwrite'] );
$profile_preview   = ( $preview_profile !== '' && function_exists( 'estandar_chatbot_preview_profile_defaults' ) )
	? estandar_chatbot_preview_profile_defaults( $preview_profile, $preview_overwrite )
	: null;

// Función para mostrar valores del preview de forma human-readable.
$format_profile_value = function( $key, $value ) use ( $preview_profile, $profile_registry ) {
	// Botones iniciales → mostrar solo los textos
	if ( $key === 'chatbot_botones_iniciales' && is_array( $value ) ) {
		$labels = array_filter( array_map( fn( $b ) => (string) ( $b['texto'] ?? '' ), $value ) );
		return implode( ' · ', $labels ) ?: '(sin botones)';
	}
	// Campos de lead → mostrar nombres
	if ( $key === 'chatbot_lead_fields' && is_array( $value ) ) {
		$fields = array_filter( array_map( fn( $f ) => (string) ( $f['label'] ?? $f['key'] ?? '' ), $value ) );
		return implode( ', ', $fields ) ?: '(ninguno)';
	}
	// Intents → mostrar labels
	if ( $key === 'chatbot_lead_intents' && is_array( $value ) ) {
		$labels = array_filter( array_map( fn( $i ) => (string) ( $i['label'] ?? '' ), $value ) );
		return implode( ' · ', $labels ) ?: '(ninguno)';
	}
	// Arrays genéricos → JSON compacto
	if ( is_array( $value ) ) {
		$json = wp_json_encode( $value );
		return strlen( $json ) > 80 ? substr( $json, 0, 80 ) . '…' : $json;
	}
	if ( is_bool( $value ) ) { return $value ? 'Sí' : 'No'; }
	if ( $value === null )    { return '(vacío)'; }
	if ( $value === 1 || $value === '1' ) { return 'Sí'; }
	if ( $value === 0 || $value === '0' ) { return 'No'; }
	$str = (string) $value;
	return strlen( $str ) > 100 ? substr( $str, 0, 100 ) . '…' : $str;
};

// Etiquetas legibles para las claves de opciones.
$field_labels = [
	'chatbot_lead_capture_enabled'      => 'Captura de leads activa',
	'chatbot_lead_intro_text'           => 'Mensaje de bienvenida del chatbot',
	'chatbot_lead_fields'               => 'Campos que se capturan',
	'chatbot_lead_intents'              => 'Intenciones disponibles',
	'chatbot_botones_iniciales'         => 'Botones de inicio',
	'chatbot_human_escalation_enabled'  => 'Derivación a humano',
	'chatbot_human_escalation_mode'     => 'Modo de derivación',
	'chatbot_requires_disambiguation'   => 'Requiere elección de proyecto',
	'chatbot_business_industry'         => 'Industria',
	'chatbot_business_goal'             => 'Objetivo del chatbot',
	'chatbot_business_primary_cta'      => 'CTA principal',
	'chatbot_business_tone'             => 'Tono de comunicación',
	'chatbot_business_sensitive_topics' => 'Temas sensibles',
	'chatbot_auto_open_enabled'         => 'Apertura automática',
	'chatbot_hide_menu_on_click'        => 'Ocultar menú al hacer clic',
	'chatbot_cta_labels'                => 'Etiquetas de CTAs',
	'chatbot_behavior_copy'             => 'Textos de comportamiento',
	'chatbot_post_lead_actions'         => 'Acciones post-lead',
];

// Claves que se muestran en el preview simplificado (las más relevantes para el usuario).
$preview_visible_keys = [
	'chatbot_botones_iniciales',
	'chatbot_lead_fields',
	'chatbot_lead_intro_text',
	'chatbot_business_tone',
	'chatbot_business_goal',
	'chatbot_lead_capture_enabled',
	'chatbot_human_escalation_enabled',
];
$summary_items = [
	[
		'label' => __( 'Estado del chatbot', 'estandar-chatbot' ),
		'value' => $activar ? __( 'Activo', 'estandar-chatbot' ) : __( 'Inactivo', 'estandar-chatbot' ),
	],
	[
		'label' => __( 'Nombre visible', 'estandar-chatbot' ),
		'value' => $mostrar_nombre ? ( $nombre ?: __( 'Asistente', 'estandar-chatbot' ) ) : __( 'Oculto', 'estandar-chatbot' ),
	],
	[
		'label' => __( 'Idioma base', 'estandar-chatbot' ),
		'value' => 'en' === $idioma ? __( 'Inglés', 'estandar-chatbot' ) : ( 'pt' === $idioma ? __( 'Portugués', 'estandar-chatbot' ) : __( 'Español', 'estandar-chatbot' ) ),
	],
	[
		'label' => __( 'Cobertura', 'estandar-chatbot' ),
		'value' => empty( $visibles ) ? __( 'Todo el sitio', 'estandar-chatbot' ) : sprintf( _n( '%d página', '%d páginas', count( $visibles ), 'estandar-chatbot' ), count( $visibles ) ),
	],
];
?>
<?php if ( isset( $_GET['ecb_profile_error'] ) && in_array( $_GET['ecb_profile_error'], [ 'confirm_required', 'preview_required' ], true ) ) : ?>
	<div class="notice notice-error is-dismissible">
		<p>
			<?php
			echo $_GET['ecb_profile_error'] === 'preview_required'
				? esc_html__( 'Debes previsualizar el profile antes de aplicarlo.', 'estandar-chatbot' )
				: esc_html__( 'Debes confirmar explícitamente antes de aplicar un profile.', 'estandar-chatbot' );
			?>
		</p>
	</div>
<?php endif; ?>

<?php if ( isset( $_GET['ecb_profile_applied'], $_GET['ecb_profile_skipped'] ) ) : ?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php
			printf(
				esc_html__( 'Perfil aplicado: %1$d campo(s) actualizado(s), %2$d conservado(s).', 'estandar-chatbot' ),
				(int) $_GET['ecb_profile_applied'],
				(int) $_GET['ecb_profile_skipped']
			);
			if ( ! empty( $_GET['ecb_profile_overwrite'] ) ) {
				echo ' ' . esc_html__( 'Se permitió sobrescribir campos existentes.', 'estandar-chatbot' );
			}
			?>
		</p>
	</div>
<?php endif; ?>

<div class="ecb-admin-surface" style="margin-bottom:16px;">

	<?php // Cabecera de la sección ?>
	<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
		<div>
			<h2 style="margin:0 0 4px;"><?php esc_html_e( 'Configuración por perfil', 'estandar-chatbot' ); ?></h2>
			<p class="description" style="margin:0;">
				<?php esc_html_e( 'Aplica una configuración pre-armada según tu tipo de negocio. No modifica leads ni conversaciones existentes.', 'estandar-chatbot' ); ?>
				<?php if ( $current_profile !== '' && isset( $profile_labels[ $current_profile ] ) ) : ?>
					&nbsp;·&nbsp;<strong><?php echo esc_html( 'Perfil activo: ' . $profile_labels[ $current_profile ] ); ?></strong>
				<?php else : ?>
					&nbsp;·&nbsp;<strong style="color:#92400e;"><?php esc_html_e( 'Sin perfil asignado.', 'estandar-chatbot' ); ?></strong>
				<?php endif; ?>
			</p>
		</div>
	</div>

	<?php // Tarjetas de perfil + selector GET ?>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="estandar-chatbot" />
		<input type="hidden" name="tab" value="configuracion" />

		<div id="ecb-profile-list" style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px;max-width:560px;">
			<?php foreach ( $profile_registry as $pkey => $pdata ) :
				$is_active   = ( $current_profile === $pkey );
				$is_selected = ( $preview_profile === $pkey );
				$is_suggested = ( $suggested_profile === $pkey );
				$border = $is_active ? '#6ee7b7' : ( $is_selected ? '#93c5fd' : '#e5e7eb' );
				$bg     = $is_active ? '#ecfdf5' : ( $is_selected ? '#eff6ff' : '#fff' );
			?>
				<label style="
					display:flex;align-items:baseline;gap:10px;cursor:pointer;
					padding:9px 12px;border:2px solid <?php echo esc_attr( $border ); ?>;
					border-radius:8px;background:<?php echo esc_attr( $bg ); ?>;
				">
					<input type="radio" name="ecb_profile_preview" value="<?php echo esc_attr( $pkey ); ?>"
						<?php checked( $preview_profile, $pkey ); ?>
						style="flex-shrink:0;position:relative;top:2px;"
					/>
					<span style="font-weight:600;font-size:13px;white-space:nowrap;"><?php echo esc_html( (string) ( $pdata['label'] ?? $pkey ) ); ?></span>
					<span style="font-size:12px;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;"><?php echo esc_html( (string) ( $pdata['description'] ?? '' ) ); ?></span>
					<?php if ( $is_active ) : ?>
						<span style="font-size:10px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.04em;flex-shrink:0;">✓ Activo</span>
					<?php elseif ( $is_suggested ) : ?>
						<span style="font-size:10px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.04em;flex-shrink:0;">Recomendado</span>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>

		<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
			<label style="display:flex;align-items:center;gap:6px;font-size:13px;">
				<input type="checkbox" name="ecb_profile_overwrite" value="1" <?php checked( $preview_overwrite ); ?> />
				<?php esc_html_e( 'Reemplazar campos ya configurados con los valores del perfil', 'estandar-chatbot' ); ?>
			</label>
			<button type="submit" id="ecb-profile-preview-btn" class="button"><?php esc_html_e( 'Ver comportamiento y cambios →', 'estandar-chatbot' ); ?></button>
			<span id="ecb-profile-preview-hint" class="description" style="display:none;"><?php esc_html_e( 'Selecciona un perfil para ver el detalle.', 'estandar-chatbot' ); ?></span>
		</div>
	</form>
	<script>
	(function(){
		var form = document.getElementById('ecb-profile-list');
		if (!form) return;
		var radios = form.querySelectorAll('input[name="ecb_profile_preview"]');
		var btn = document.getElementById('ecb-profile-preview-btn');
		var hint = document.getElementById('ecb-profile-preview-hint');
		function anyChecked(){
			return Array.prototype.some.call(radios, function(r){ return r.checked; });
		}
		function sync(){
			var ok = anyChecked();
			if (btn) btn.disabled = !ok;
			if (hint) hint.style.display = ok ? 'none' : 'inline';
		}
		Array.prototype.forEach.call(radios, function(r){
			r.addEventListener('change', function(){
				sync();
				// Al elegir perfil, cargar el detalle de inmediato (mismo GET del botón).
				if (r.checked && r.form) r.form.submit();
			});
		});
		sync();
	})();
	</script>

	<?php // Preview human-readable ?>
	<?php if ( is_array( $profile_preview ) && $preview_profile !== '' ) :
		$to_apply = (array) ( $profile_preview['fields_to_apply'] ?? [] );
		$to_skip  = (array) ( $profile_preview['fields_to_skip'] ?? [] );
		$human    = function_exists( 'estandar_chatbot_get_profile_human_summary' ) ? estandar_chatbot_get_profile_human_summary( $preview_profile ) : [];
		$pmeta    = function_exists( 'estandar_chatbot_get_profile_meta' ) ? estandar_chatbot_get_profile_meta( $preview_profile ) : [];
	?>
		<div id="ecb-profile-preview" style="margin-top:18px;border-top:1px solid #e5e7eb;padding-top:16px;">

			<h3 style="margin:0 0 4px;"><?php echo esc_html( 'Perfil: ' . ( $profile_labels[ $preview_profile ] ?? $preview_profile ) ); ?></h3>
			<?php if ( ! empty( $pmeta['description'] ) ) : ?>
				<p class="description" style="margin:0 0 14px;"><?php echo esc_html( $pmeta['description'] ); ?></p>
			<?php endif; ?>

			<?php // Qué modifica / qué no — elimina la incertidumbre antes de aplicar ?>
			<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
				<div style="flex:1 1 240px;padding:12px 14px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;">
					<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#1d4ed8;margin-bottom:6px;"><?php esc_html_e( 'Este perfil modificará', 'estandar-chatbot' ); ?></div>
					<div style="font-size:13px;color:#1e3a8a;line-height:1.7;">
						✓ <?php esc_html_e( 'Botones iniciales del chat', 'estandar-chatbot' ); ?><br>
						✓ <?php esc_html_e( 'Campos que se solicitan al visitante', 'estandar-chatbot' ); ?><br>
						✓ <?php esc_html_e( 'Objetivo comercial y tono', 'estandar-chatbot' ); ?><br>
						✓ <?php esc_html_e( 'Comportamiento de captación de leads', 'estandar-chatbot' ); ?>
					</div>
				</div>
				<div style="flex:1 1 240px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
					<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:6px;"><?php esc_html_e( 'No modifica', 'estandar-chatbot' ); ?></div>
					<div style="font-size:13px;color:#374151;line-height:1.7;">
						✓ <?php esc_html_e( 'Leads ya capturados', 'estandar-chatbot' ); ?><br>
						✓ <?php esc_html_e( 'Conversaciones históricas', 'estandar-chatbot' ); ?><br>
						✓ <?php esc_html_e( 'Estadísticas y analítica', 'estandar-chatbot' ); ?>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $human ) ) : ?>
				<h3 style="margin:0 0 10px;"><?php esc_html_e( 'Vista previa del comportamiento', 'estandar-chatbot' ); ?></h3>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:16px;">
					<?php if ( ! empty( $human['buttons'] ) ) : ?>
						<div style="padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
							<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:6px;">Botones del chatbot</div>
							<div style="display:flex;flex-wrap:wrap;gap:4px;">
								<?php foreach ( (array) $human['buttons'] as $b ) : ?>
									<span style="font-size:12px;padding:3px 8px;background:#fff;border:1px solid #d1d5db;border-radius:12px;"><?php echo esc_html( $b ); ?></span>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $human['captures'] ) ) : ?>
						<div style="padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
							<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:6px;">Datos que captura</div>
							<div style="font-size:13px;color:#374151;"><?php echo esc_html( implode( ', ', (array) $human['captures'] ) ); ?></div>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $human['tone'] ) ) : ?>
						<div style="padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
							<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:6px;">Tono</div>
							<div style="font-size:13px;color:#374151;"><?php echo esc_html( $human['tone'] ); ?></div>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $human['goal'] ) ) : ?>
						<div style="padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
							<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:6px;">Objetivo</div>
							<div style="font-size:13px;color:#374151;"><?php echo esc_html( $human['goal'] ); ?></div>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php // Detalle técnico de campos (colapsable) ?>
			<details style="margin-bottom:14px;">
				<summary style="cursor:pointer;font-size:13px;color:#6b7280;">
					<?php printf( esc_html__( 'Detalle técnico: %1$d campo(s) se aplicarían · %2$d se conservarían', 'estandar-chatbot' ), count( $to_apply ), count( $to_skip ) ); ?>
				</summary>
				<div style="margin-top:10px;">
					<?php if ( ! empty( $to_apply ) ) : ?>
						<p style="font-weight:600;margin:0 0 6px;">Se aplicarían:</p>
						<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:12px;">
							<?php foreach ( $to_apply as $item ) :
								$k = (string) ( $item['key'] ?? '' );
								if ( ! in_array( $k, $preview_visible_keys, true ) ) continue;
								$label = isset( $field_labels[ $k ] ) ? $field_labels[ $k ] : $k;
							?>
								<tr style="border-bottom:1px solid #f3f4f6;">
									<td style="padding:5px 8px;color:#374151;font-weight:500;width:40%;"><?php echo esc_html( $label ); ?></td>
									<td style="padding:5px 8px;color:#9ca3af;width:25%;"><?php echo esc_html( $format_profile_value( $k, $item['current_value'] ) ); ?></td>
									<td style="padding:5px 8px;color:#111827;width:35%;">→ <?php echo esc_html( $format_profile_value( $k, $item['proposed_value'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							<?php
							$hidden = count( array_filter( $to_apply, fn( $i ) => ! in_array( $i['key'] ?? '', $preview_visible_keys, true ) ) );
							if ( $hidden > 0 ) : ?>
								<tr><td colspan="3" style="padding:5px 8px;color:#9ca3af;font-style:italic;"><?php echo esc_html( "+ {$hidden} campos internos (botones, textos, comportamiento)" ); ?></td></tr>
							<?php endif; ?>
						</table>
					<?php endif; ?>
					<?php if ( ! empty( $to_skip ) ) : ?>
						<p style="font-weight:600;margin:0 0 4px;color:#6b7280;">Se conservarían (ya tienen valor):</p>
						<p style="font-size:12px;color:#9ca3af;margin:0;"><?php echo esc_html( implode( ', ', array_filter( array_map( fn( $i ) => (string) ( $i['key'] ?? '' ), $to_skip ) ) ) ); ?></p>
					<?php endif; ?>
				</div>
			</details>

			<?php // Formulario de aplicación con campo chatbot_nombre ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:16px 18px;">
				<input type="hidden" name="action" value="estandar_chatbot_apply_profile" />
				<input type="hidden" name="chatbot_profile" value="<?php echo esc_attr( $preview_profile ); ?>" />
				<input type="hidden" name="chatbot_profile_previewed" value="1" />
				<?php if ( $preview_overwrite ) : ?>
					<input type="hidden" name="chatbot_profile_overwrite" value="1" />
				<?php endif; ?>
				<?php wp_nonce_field( 'estandar_chatbot_apply_profile' ); ?>

				<p style="font-weight:600;margin:0 0 12px;">¿Cómo se llamará el asistente?</p>
				<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
					<input type="text" name="chatbot_nombre_from_profile"
						value="<?php echo esc_attr( (string) get_option( 'chatbot_nombre', 'Asistente' ) ); ?>"
						placeholder="Ej: Asistente AK"
						class="regular-text"
						style="max-width:260px;"
					/>
					<span class="description"><?php esc_html_e( 'Solo cambia el nombre visible en la cabecera y el saludo del chat. No afecta el comportamiento de la IA.', 'estandar-chatbot' ); ?></span>
				</div>

				<label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:14px;cursor:pointer;">
					<input type="checkbox" name="chatbot_profile_confirm_apply" value="1" style="margin-top:3px;" />
					<span><?php esc_html_e( 'Confirmo aplicar el perfil seleccionado y actualizar la configuración recomendada.', 'estandar-chatbot' ); ?></span>
				</label>
				<button type="submit" class="button button-primary">
					<?php echo esc_html( 'Aplicar perfil "' . ( $profile_labels[ $preview_profile ] ?? $preview_profile ) . '"' ); ?>
				</button>
			</form>

		</div>
		<script>
		(function(){
			// El preview se genera tras un reload: llevar al usuario directo al panel.
			var panel = document.getElementById('ecb-profile-preview');
			if (panel && window.location.search.indexOf('ecb_profile_preview=') !== -1) {
				panel.scrollIntoView({ block: 'start', behavior: 'smooth' });
			}
		})();
		</script>
	<?php endif; ?>

</div>

<form method="post" action="options.php">
	<?php settings_fields( 'estandar_chatbot_settings' ); ?>

	<div class="ecb-admin-surface" style="margin-bottom:16px;">
		<h2 style="margin:0 0 8px;"><?php esc_html_e( 'Configuración base', 'estandar-chatbot' ); ?></h2>
		<p class="description" style="margin:0 0 16px;"><?php esc_html_e( 'Usa esta pantalla para encender el chatbot, definir su identidad básica y decidir dónde se muestra.', 'estandar-chatbot' ); ?></p>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
			<?php foreach ( $summary_items as $item ) : ?>
				<div style="padding:14px 16px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;">
					<div style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;"><?php echo esc_html( $item['label'] ); ?></div>
					<div style="margin-top:6px;font-weight:600;color:#111827;"><?php echo esc_html( $item['value'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<details class="ecb-base-settings-editor" style="margin-bottom:16px;">
	<summary style="cursor:pointer;padding:12px 16px;border:1px solid #d9d9de;border-radius:8px;background:#fff;font-weight:600;color:#2563eb;">
		<?php esc_html_e( 'Editar configuración base', 'estandar-chatbot' ); ?>
	</summary>

	<table class="form-table" role="presentation">
	<tbody>

	<tr id="ecb-frontend-visibility">
		<th scope="row"><?php esc_html_e( 'Activar chatbot', 'estandar-chatbot' ); ?></th>
		<td>
			<label style="display:block;margin-bottom:8px;">
				<input type="hidden" name="chatbot_activar" value="0" />
				<input type="checkbox" name="chatbot_activar" value="1" <?php checked( $activar, 1 ); ?> />
				<?php esc_html_e( 'Mostrar el chatbot en el sitio', 'estandar-chatbot' ); ?>
			</label>
			<label style="display:block;">
				<input type="hidden" name="chatbot_solo_loggeados" value="0" />
				<input type="checkbox" name="chatbot_solo_loggeados" value="1" <?php checked( $solo_loggeados, 1 ); ?> />
				<?php esc_html_e( 'Mostrar solo para usuarios autenticados', 'estandar-chatbot' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Úsalo para pruebas privadas, sitios cerrados o implementaciones internas.', 'estandar-chatbot' ); ?></p>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'Nombre del bot', 'estandar-chatbot' ); ?></th>
		<td>
			<input type="text" name="chatbot_nombre" value="<?php echo esc_attr( $nombre ); ?>" class="regular-text" />
			<br><br>
			<label>
				<input type="hidden" name="chatbot_mostrar_nombre" value="0" />
				<input type="checkbox" name="chatbot_mostrar_nombre" value="1" <?php checked( $mostrar_nombre, 1 ); ?> />
				<?php esc_html_e( 'Mostrar nombre del chatbot', 'estandar-chatbot' ); ?>
			</label>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'Idioma base', 'estandar-chatbot' ); ?></th>
		<td>
			<select name="chatbot_idioma">
				<option value="es" <?php selected( $idioma, 'es' ); ?>><?php esc_html_e( 'Español', 'estandar-chatbot' ); ?></option>
				<option value="en" <?php selected( $idioma, 'en' ); ?>><?php esc_html_e( 'Inglés', 'estandar-chatbot' ); ?></option>
				<option value="pt" <?php selected( $idioma, 'pt' ); ?>><?php esc_html_e( 'Portugués', 'estandar-chatbot' ); ?></option>
			</select>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'Dónde aparece', 'estandar-chatbot' ); ?></th>
		<td>
			<input type="hidden" name="chatbot_paginas_visibles__present" value="1" />
			<?php
			// Resumen de cobertura legible: nombres de páginas, no un multi-select crudo.
			$visible_titles = [];
			if ( ! empty( $visibles ) ) {
				foreach ( $all_pages as $p ) {
					if ( in_array( (int) $p->ID, $visibles, true ) ) {
						$visible_titles[] = (string) $p->post_title;
					}
				}
			}
			$shown_titles  = array_slice( $visible_titles, 0, 6 );
			$hidden_titles = max( 0, count( $visible_titles ) - count( $shown_titles ) );
			?>
			<?php if ( empty( $visibles ) ) : ?>
				<div style="
					margin-bottom:8px;padding:8px 12px;
					background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;
					font-size:13px;color:#166534;display:inline-block;
				">✓ <?php esc_html_e( 'Todo el sitio — el widget aparece en todas las páginas.', 'estandar-chatbot' ); ?></div>
			<?php else : ?>
				<div style="margin-bottom:8px;">
					<div style="
						margin-bottom:8px;padding:8px 12px;
						background:#fffbeb;border:1px solid #fde68a;border-radius:8px;
						font-size:13px;color:#92400e;display:inline-block;
					">
						⚠ <?php printf( esc_html__( 'Cobertura restringida a %d página(s):', 'estandar-chatbot' ), count( $visibles ) ); ?>
						&nbsp;<a href="#" style="color:#92400e;font-weight:600;"
							onclick="Array.from(document.querySelectorAll('[name=\'chatbot_paginas_visibles[]\']')).forEach(o=>o.selected=false);return false;">
							<?php esc_html_e( '× Limpiar selección (todo el sitio)', 'estandar-chatbot' ); ?>
						</a>
					</div>
					<div style="display:flex;flex-wrap:wrap;gap:5px;">
						<?php foreach ( $shown_titles as $t ) : ?>
							<span style="font-size:12px;padding:3px 9px;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:12px;color:#374151;">✓ <?php echo esc_html( $t ); ?></span>
						<?php endforeach; ?>
						<?php if ( $hidden_titles > 0 ) : ?>
							<span style="font-size:12px;padding:3px 9px;color:#6b7280;">(+<?php echo esc_html( (string) $hidden_titles ); ?> páginas)</span>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
			<details>
				<summary style="cursor:pointer;font-size:13px;color:#2563eb;margin:6px 0;"><?php esc_html_e( 'Editar visibilidad', 'estandar-chatbot' ); ?></summary>
				<select name="chatbot_paginas_visibles[]" multiple size="8" style="min-width:320px;display:block;margin-top:6px;">
					<?php foreach ( $all_pages as $p ) : ?>
						<option value="<?php echo esc_attr( (string) $p->ID ); ?>" <?php selected( in_array( (int) $p->ID, $visibles, true ) ); ?>>
							<?php echo esc_html( $p->post_title . ' (#' . $p->ID . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description" style="margin-top:6px;">
					<?php esc_html_e( 'Sin selección = todo el sitio. Para quitar una página seleccionada haz Cmd/Ctrl + clic sobre ella.', 'estandar-chatbot' ); ?>
					<?php esc_html_e( 'El shortcode [estandar_chatbot] siempre carga el chatbot sin importar esta lista.', 'estandar-chatbot' ); ?>
				</p>
			</details>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'Página de prueba (auto)', 'estandar-chatbot' ); ?></th>
		<td>
			<?php if ( $inline_page_id > 0 && $inline_page_url ) : ?>
				<p style="margin:0 0 8px;"><?php esc_html_e( 'Se creó automáticamente una página con el chatbot embebido para pruebas rápidas:', 'estandar-chatbot' ); ?></p>
				<p style="margin:0;">
					<a class="button button-secondary" href="<?php echo esc_url( $inline_page_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Abrir página', 'estandar-chatbot' ); ?></a>
					<a class="button" href="<?php echo esc_url( get_edit_post_link( $inline_page_id ) ?: '#' ); ?>"><?php esc_html_e( 'Editar página', 'estandar-chatbot' ); ?></a>
					<button type="button" class="button" id="ecb-copy-test-url" data-url="<?php echo esc_attr( $inline_page_url ); ?>"><?php esc_html_e( 'Copiar URL', 'estandar-chatbot' ); ?></button>
					<span id="ecb-copy-test-url-status" class="description" aria-live="polite"></span>
				</p>
				<script>
				(function(){
					var btn = document.getElementById('ecb-copy-test-url');
					var status = document.getElementById('ecb-copy-test-url-status');
					if (!btn) return;
					function done(){
						if (status) status.textContent = 'URL copiada.';
					}
					btn.addEventListener('click', function(){
						var url = btn.getAttribute('data-url') || '';
						if (navigator.clipboard && navigator.clipboard.writeText) {
							navigator.clipboard.writeText(url).then(done);
							return;
						}
						var input = document.createElement('textarea');
						input.value = url;
						document.body.appendChild(input);
						input.select();
						document.execCommand('copy');
						input.remove();
						done();
					});
				})();
				</script>
				<p class="description"><?php esc_html_e( 'Contenido base: [estandar_chatbot mode="inline" height="860"]', 'estandar-chatbot' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'La página automática se creará al activar el plugin o en la primera carga posterior a la actualización.', 'estandar-chatbot' ); ?></p>
			<?php endif; ?>
		</td>
	</tr>

	<tr>
		<th colspan="2" style="padding-top:18px;">
			<h2 style="margin:0;"><?php esc_html_e( 'Opciones avanzadas', 'estandar-chatbot' ); ?></h2>
		</th>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Modo rendimiento', 'estandar-chatbot' ); ?></th>
		<td>
			<label>
				<input type="hidden" name="chatbot_log_defer" value="0" />
				<input type="checkbox" name="chatbot_log_defer" value="1" <?php checked( (int) get_option( 'chatbot_log_defer', 1 ), 1 ); ?> />
				<?php esc_html_e( 'Activa esta opción para que el chatbot responda más rápido. Los registros se guardan en segundo plano.', 'estandar-chatbot' ); ?>
			</label>
		</td>
	</tr>
	<tr>
		<th scope="row"><?php esc_html_e( 'Mensajes de bienvenida', 'estandar-chatbot' ); ?></th>
		<td>
			<?php
			$flow_enabled  = (int) get_option( 'chatbot_flow_enabled', 0 );
			$flow_json_raw = (string) get_option( 'chatbot_flow_json', '' );
			// Detectar si el JSON tiene contenido de demo/prueba sin estar activo.
			$flow_json_has_demo = ! $flow_enabled && $flow_json_raw !== '' && (
				str_contains( $flow_json_raw, '"demo"' ) ||
				str_contains( $flow_json_raw, 'demo' ) ||
				str_contains( $flow_json_raw, '"Hola"' )
			);
			?>
			<label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
				<input type="hidden" name="chatbot_flow_enabled" value="0" />
				<input type="checkbox" name="chatbot_flow_enabled" value="1" <?php checked( $flow_enabled, 1 ); ?> />
				<span><?php esc_html_e( 'Mostrar varios mensajes de bienvenida al iniciar el chat', 'estandar-chatbot' ); ?></span>
			</label>
			<?php if ( $flow_json_has_demo ) : ?>
				<div style="
					margin-bottom:8px;padding:8px 12px;
					background:#fef2f2;border:1px solid #fecaca;border-radius:8px;
					font-size:13px;color:#991b1b;
				">
					⚠ <?php esc_html_e( 'El JSON contiene contenido de prueba. Si activas esta opción sin revisar, el chatbot saludará con ese texto.', 'estandar-chatbot' ); ?>
				</div>
			<?php endif; ?>
			<div id="ecb-flow-visual" style="display:none;max-width:480px;">
				<div id="ecb-flow-rows" style="display:flex;flex-direction:column;gap:8px;margin-bottom:8px;"></div>
				<button type="button" class="button" id="ecb-flow-add"><?php esc_html_e( '+ Agregar mensaje', 'estandar-chatbot' ); ?></button>
				<p class="description" style="margin-top:6px;"><?php esc_html_e( 'Cada mensaje se muestra como una burbuja separada al iniciar el chat, en este orden.', 'estandar-chatbot' ); ?></p>
			</div>
			<p id="ecb-flow-advanced-notice" class="description" style="display:none;margin:0 0 6px;color:#92400e;">
				<?php esc_html_e( 'Este JSON usa un formato avanzado (claves adicionales). Edítalo directamente abajo.', 'estandar-chatbot' ); ?>
			</p>
			<details id="ecb-flow-advanced">
				<summary style="cursor:pointer;font-size:13px;color:#6b7280;margin:8px 0 4px;"><?php esc_html_e( 'Modo avanzado (JSON)', 'estandar-chatbot' ); ?></summary>
				<textarea name="chatbot_flow_json" id="ecb-flow-json" rows="5" class="large-text code"
					placeholder='{"intro":["Hola 👋","Soy el asistente","¿En qué puedo ayudarte?"]}'
				><?php echo esc_textarea( $flow_json_raw ); ?></textarea>
				<p class="description" style="margin-top:6px;">
					<?php esc_html_e( 'Formato JSON. Ejemplo:', 'estandar-chatbot' ); ?>
					<code>{"intro":["Hola 👋","¿En qué puedo ayudarte?"]}</code>
				</p>
			</details>
			<script>
			(function(){
				var ta      = document.getElementById('ecb-flow-json');
				var visual  = document.getElementById('ecb-flow-visual');
				var rowsEl  = document.getElementById('ecb-flow-rows');
				var addBtn  = document.getElementById('ecb-flow-add');
				var notice  = document.getElementById('ecb-flow-advanced-notice');
				if (!ta || !visual || !rowsEl || !addBtn) return;

				// Solo activamos el editor visual si el JSON es simple: vacío o {"intro":[strings]}.
				function parseSimple(raw) {
					raw = (raw || '').trim();
					if (raw === '') return [];
					try {
						var obj = JSON.parse(raw);
						if (!obj || typeof obj !== 'object' || Array.isArray(obj)) return null;
						var keys = Object.keys(obj);
						if (keys.length === 0) return [];
						if (keys.length !== 1 || keys[0] !== 'intro' || !Array.isArray(obj.intro)) return null;
						if (!obj.intro.every(function(m){ return typeof m === 'string'; })) return null;
						return obj.intro;
					} catch (e) { return null; }
				}

				function writeBack() {
					var msgs = Array.prototype.map.call(rowsEl.querySelectorAll('input'), function(i){ return i.value; })
						.filter(function(v){ return v.trim() !== ''; });
					ta.value = msgs.length ? JSON.stringify({ intro: msgs }) : '';
				}

				function makeRow(value, index) {
					var wrap = document.createElement('div');
					wrap.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #dbeafe;border-radius:14px 14px 14px 4px;background:#eff6ff;';
					var label = document.createElement('span');
					label.style.cssText = 'font-size:11px;color:#1d4ed8;width:74px;flex-shrink:0;font-weight:600;';
					label.textContent = 'Mensaje ' + index;
					var input = document.createElement('input');
					input.type = 'text';
					input.className = 'regular-text';
					input.style.cssText = 'flex:1;';
					input.value = value;
					input.addEventListener('input', writeBack);
					var del = document.createElement('button');
					del.type = 'button';
					del.className = 'button-link';
					del.style.cssText = 'color:#b91c1c;text-decoration:none;flex-shrink:0;';
					del.textContent = '×';
					del.title = 'Quitar mensaje';
					del.addEventListener('click', function(){
						wrap.remove();
						renumber();
						writeBack();
					});
					wrap.appendChild(label);
					wrap.appendChild(input);
					wrap.appendChild(del);
					return wrap;
				}

				function renumber() {
					Array.prototype.forEach.call(rowsEl.children, function(row, i){
						var lbl = row.querySelector('span');
						if (lbl) lbl.textContent = 'Mensaje ' + (i + 1);
					});
				}

				function render(messages) {
					rowsEl.innerHTML = '';
					(messages.length ? messages : ['']).forEach(function(m, i){
						rowsEl.appendChild(makeRow(m, i + 1));
					});
				}

				addBtn.addEventListener('click', function(){
					rowsEl.appendChild(makeRow('', rowsEl.children.length + 1));
					var last = rowsEl.lastChild.querySelector('input');
					if (last) last.focus();
				});

				// Si el usuario edita el JSON a mano y sigue siendo simple, refrescar el editor visual.
				ta.addEventListener('change', function(){
					var msgs = parseSimple(ta.value);
					if (msgs !== null) {
						visual.style.display = '';
						notice.style.display = 'none';
						render(msgs);
					} else {
						visual.style.display = 'none';
						notice.style.display = 'block';
					}
				});

				var initial = parseSimple(ta.value);
				if (initial !== null) {
					visual.style.display = '';
					render(initial);
				} else {
					notice.style.display = 'block';
					document.getElementById('ecb-flow-advanced').setAttribute('open', '');
				}
			})();
			</script>
		</td>
	</tr>

	</tbody>
	</table>

	<?php submit_button( __( 'Guardar configuración', 'estandar-chatbot' ) ); ?>
	</details>
</form>

<?php
/* ======================================================
 * CONTEXTOS V1.1
 * ====================================================== */
$ecb_contexts = function_exists( 'estandar_chatbot_contexts_get_all' ) ? estandar_chatbot_contexts_get_all() : [];
$ecb_ctx_slots = [];
$i = 1;
foreach ( $ecb_contexts as $slug => $data ) {
	$ecb_ctx_slots[] = [ 'slug' => $slug, 'name' => $data['name'] ?? '', 'prompt' => $data['prompt'] ?? '' ];
	$i++;
	if ( $i > 4 ) break;
}
while ( count( $ecb_ctx_slots ) < 4 ) {
	$ecb_ctx_slots[] = [ 'slug' => '', 'name' => '', 'prompt' => '' ];
}
if ( isset( $_GET['ecb_contexts_saved'] ) ) {
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Contextos guardados.', 'estandar-chatbot' ) . '</p></div>';
}
?>
<details id="ecb-contextos-panel" style="margin-top:24px;" <?php echo isset( $_GET['_section'] ) && $_GET['_section'] === 'contextos' ? 'open' : ''; ?>>
	<summary style="font-size:14px;font-weight:600;cursor:pointer;padding:10px 0;border-top:1px solid #e2e8f0;">
		<?php esc_html_e( 'Contextos especializados', 'estandar-chatbot' ); ?>
	</summary>
	<div style="margin-top:12px;">
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'Define hasta 4 contextos especializados. Usa el shortcode ', 'estandar-chatbot' ); ?>
			<code>[estandar_chatbot context="slug"]</code>
			<?php esc_html_e( 'para activar uno. El chatbot usará el sistema global + el contexto especializado.', 'estandar-chatbot' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'estandar_chatbot_save_contexts_nonce' ); ?>
			<input type="hidden" name="action" value="estandar_chatbot_save_contexts" />
			<?php foreach ( $ecb_ctx_slots as $idx => $slot ) :
				$n = $idx + 1;
			?>
			<div style="background:#f9fafb;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:12px;">
				<div style="display:flex;align-items:center;gap:6px;margin-bottom:10px;">
					<span style="background:#1e293b;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;">
						<?php echo esc_html( 'Contexto ' . $n ); ?>
					</span>
					<?php if ( $slot['slug'] !== '' ) : ?>
						<code style="font-size:12px;">[estandar_chatbot context="<?php echo esc_attr( $slot['slug'] ); ?>"]</code>
					<?php endif; ?>
				</div>
				<div style="display:grid;grid-template-columns:1fr 2fr;gap:10px;margin-bottom:10px;">
					<div>
						<label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;" for="ctx_slug_<?php echo $n; ?>">
							<?php esc_html_e( 'Slug (identificador)', 'estandar-chatbot' ); ?>
						</label>
						<input type="text" id="ctx_slug_<?php echo $n; ?>" name="ctx_slug_<?php echo $n; ?>"
							value="<?php echo esc_attr( $slot['slug'] ); ?>"
							placeholder="ventas, soporte, postventa…"
							pattern="[a-z0-9\-]{1,40}" maxlength="40"
							style="width:100%;font-family:monospace;font-size:12px;" />
						<p class="description" style="font-size:11px;margin-top:4px;"><?php esc_html_e( 'Solo minúsculas, números y guiones. Sin espacios.', 'estandar-chatbot' ); ?></p>
					</div>
					<div>
						<label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;" for="ctx_name_<?php echo $n; ?>">
							<?php esc_html_e( 'Nombre visible', 'estandar-chatbot' ); ?>
						</label>
						<input type="text" id="ctx_name_<?php echo $n; ?>" name="ctx_name_<?php echo $n; ?>"
							value="<?php echo esc_attr( $slot['name'] ); ?>"
							placeholder="Ventas, Soporte técnico…"
							maxlength="80"
							style="width:100%;font-size:13px;" />
					</div>
				</div>
				<div>
					<label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;" for="ctx_prompt_<?php echo $n; ?>">
						<?php esc_html_e( 'Prompt especializado', 'estandar-chatbot' ); ?>
					</label>
					<textarea id="ctx_prompt_<?php echo $n; ?>" name="ctx_prompt_<?php echo $n; ?>"
						rows="4" style="width:100%;font-size:13px;"
						placeholder="<?php esc_attr_e( 'Instrucciones adicionales para este contexto. Se añaden al prompt global.', 'estandar-chatbot' ); ?>"><?php echo esc_textarea( $slot['prompt'] ); ?></textarea>
				</div>
			</div>
			<?php endforeach; ?>
			<?php submit_button( __( 'Guardar contextos', 'estandar-chatbot' ), 'secondary', 'submit_contexts', false ); ?>
		</form>
	</div>
</details>
