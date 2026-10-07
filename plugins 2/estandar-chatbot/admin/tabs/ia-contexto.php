<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'ia-contexto' ) ) {
	wp_die( esc_html__( 'No autorizado.', 'estandar-chatbot' ) );
}

$status = isset( $_GET['ecb_status'] ) ? sanitize_key( (string) $_GET['ecb_status'] ) : '';
$msg    = isset( $_GET['ecb_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ecb_msg'] ) ) : '';

$context_upload_success = false;
$context_delete_success = false;

// ── DELETE context file ─────────────────────────────────────────────────────
if ( isset( $_POST['estandar_chatbot_delete_context'] ) ) {
	check_admin_referer( 'estandar_chatbot_save_ia_nonce' );

	$existing_context_path = (string) get_option( 'chatbot_context_txt_path', '' );
	if ( $existing_context_path !== '' ) {
		$normalized_path = wp_normalize_path( $existing_context_path );
		$uploads         = wp_get_upload_dir();
		$allowed_paths   = [
			wp_normalize_path( $uploads['basedir'] ),
			wp_normalize_path( ESTANDAR_CHATBOT_STORAGE ),
		];
		$is_allowed = false;
		foreach ( $allowed_paths as $base_path ) {
			if ( strpos( $normalized_path, $base_path ) === 0 ) {
				$is_allowed = true;
				break;
			}
		}
		if ( $is_allowed && file_exists( $normalized_path ) ) {
			wp_delete_file( $normalized_path );
		}
	}
	update_option( 'chatbot_context_txt_path', '' );
	$context_delete_success = true;
	add_settings_error( 'chatbot_context_txt', 'delete_ok', __( 'Archivo eliminado correctamente.', 'estandar-chatbot' ), 'updated' );
}

// ── SAVE all IA settings (single form) ─────────────────────────────────────
if ( isset( $_POST['estandar_chatbot_save_ia'] ) ) {
	check_admin_referer( 'estandar_chatbot_save_ia_nonce' );

	$allowed_models = class_exists( 'Estandar_AI_Gateway' )
		? Estandar_AI_Gateway::available_models()
		: [ 'gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo' ];
	$openai_key        = (string) wp_unslash( $_POST['chatbot_openai_api_key'] ?? '' );
	$openai_key        = trim( str_replace( [ "\r", "\n", "\t" ], '', $openai_key ) );
	$delete_openai_key = ! empty( $_POST['chatbot_delete_openai_api_key'] );
	$openai_model      = sanitize_text_field( wp_unslash( $_POST['chatbot_openai_model'] ?? 'gpt-4o' ) );
	$max_tokens        = max( 100, min( 8000, (int) ( $_POST['chatbot_openai_max_tokens'] ?? 500 ) ) );
	$temperature       = max( 0.0, min( 2.0, round( (float) ( $_POST['chatbot_openai_temperature'] ?? 0.4 ), 1 ) ) );
	$system_prompt     = estandar_chatbot_sanitize_system_prompt_option( $_POST['chatbot_system_prompt'] ?? '' );

	if ( ! in_array( $openai_model, $allowed_models, true ) ) {
		$openai_model = class_exists( 'Estandar_AI_Gateway' ) ? Estandar_AI_Gateway::default_model() : 'gpt-4o-mini';
	}

	if ( $delete_openai_key ) {
		delete_option( 'chatbot_openai_api_key' );
		add_settings_error( 'chatbot_openai_api_key', 'deleted', __( 'Credencial OpenAI eliminada.', 'estandar-chatbot' ), 'updated' );
	} elseif ( '' !== $openai_key ) {
		update_option( 'chatbot_openai_api_key', $openai_key );
		add_settings_error( 'chatbot_openai_api_key', 'updated', __( 'Credencial OpenAI actualizada.', 'estandar-chatbot' ), 'updated' );
	}
	update_option( 'chatbot_openai_model', $openai_model );
	update_option( 'chatbot_openai_max_tokens', $max_tokens );
	update_option( 'chatbot_openai_temperature', $temperature );
	update_option( 'chatbot_system_prompt', $system_prompt );

	// Business profile fields (formerly separate form via options.php)
	update_option( 'chatbot_business_industry',         sanitize_text_field( wp_unslash( $_POST['chatbot_business_industry'] ?? '' ) ) );
	update_option( 'chatbot_business_goal',             sanitize_text_field( wp_unslash( $_POST['chatbot_business_goal'] ?? '' ) ) );
	update_option( 'chatbot_business_primary_cta',      sanitize_text_field( wp_unslash( $_POST['chatbot_business_primary_cta'] ?? '' ) ) );
	update_option( 'chatbot_business_tone',             sanitize_text_field( wp_unslash( $_POST['chatbot_business_tone'] ?? '' ) ) );
	update_option( 'chatbot_business_sensitive_topics', sanitize_textarea_field( wp_unslash( $_POST['chatbot_business_sensitive_topics'] ?? '' ) ) );

	// Context file upload
	if ( ! empty( $_FILES['chatbot_context_txt']['name'] ) ) {
		$file = $_FILES['chatbot_context_txt'];
		$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( $ext !== 'txt' ) {
			add_settings_error( 'chatbot_context_txt', 'invalid_ext', __( 'Solo se permiten archivos .txt', 'estandar-chatbot' ) );
		} elseif ( (int) $file['size'] > 512 * 1024 ) {
			add_settings_error( 'chatbot_context_txt', 'size_limit', __( 'El archivo excede el tamaño máximo (512 KB).', 'estandar-chatbot' ) );
		} else {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$upload = wp_handle_upload( $file, [
				'test_form' => false,
				'mimes'     => [ 'txt' => 'text/plain' ],
			] );
			if ( isset( $upload['error'] ) ) {
				add_settings_error( 'chatbot_context_txt', 'upload_error', $upload['error'] );
			} else {
				update_option( 'chatbot_context_txt_path', sanitize_text_field( $upload['file'] ) );
				$context_upload_success = true;
			}
		}
	}

	add_settings_error( 'estandar_chatbot_ia', 'settings_saved', __( 'Configuración guardada correctamente.', 'estandar-chatbot' ), 'updated' );

	if ( $context_upload_success ) {
		add_settings_error( 'chatbot_context_txt', 'upload_ok', __( 'Archivo de contexto cargado correctamente.', 'estandar-chatbot' ), 'updated' );
	}
}

// ── Read current values ─────────────────────────────────────────────────────
$openai_key            = function_exists( 'estandar_chatbot_get_openai_api_key' ) ? estandar_chatbot_get_openai_api_key() : (string) get_option( 'chatbot_openai_api_key', '' );
$openai_key_configured = '' !== trim( $openai_key );
$openai_local_key      = (string) get_option( 'chatbot_openai_api_key', '' );
$openai_shared         = $openai_key_configured && $openai_local_key === '' && function_exists( 'estandar_core_get_secret' ) && estandar_core_get_secret( 'openai' ) !== '';
$openai_model          = (string) get_option( 'chatbot_openai_model', 'gpt-4o' );
$openai_max_tokens     = max( 100, min( 8000, (int) get_option( 'chatbot_openai_max_tokens', 500 ) ) );
$openai_temperature    = max( 0.0, min( 2.0, round( (float) get_option( 'chatbot_openai_temperature', 0.4 ), 1 ) ) );
$lang_base             = (string) get_option( 'chatbot_idioma', 'es' );
$system_prompt_custom  = (string) get_option( 'chatbot_system_prompt', '' );
$system_prompt_source  = trim( $system_prompt_custom ) === '' ? 'default' : 'custom';
$context_path          = get_option( 'chatbot_context_txt_path', '' );
$file_ok               = $context_path && file_exists( $context_path );
$file_size             = $file_ok ? filesize( $context_path ) : 0;
$file_mtime            = $file_ok ? filemtime( $context_path ) : 0;
$current_file_name     = $file_ok ? basename( $context_path ) : '';

$default_system_prompt = function_exists( 'estandar_chatbot_default_system_prompt_lines' )
	? estandar_chatbot_default_system_prompt_lines( $lang_base )
	: '';

$preview = '';
if ( $file_ok ) {
	$raw = @file_get_contents( $context_path );
	if ( is_string( $raw ) && $raw !== '' ) {
		$raw     = trim( preg_replace( '/\s+/', ' ', $raw ) );
		$preview = function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 280 ) : substr( $raw, 0, 280 );
	}
}

$effective_system_prompt = function_exists( 'estandar_chatbot_build_system_prompt' )
	? estandar_chatbot_build_system_prompt( $lang_base, function_exists( 'estandar_chatbot_get_context' ) ? estandar_chatbot_get_context() : '' )
	: $system_prompt_custom;

$prompt_history = function_exists( 'estandar_chatbot_get_prompt_history' )
	? estandar_chatbot_get_prompt_history()
	: [];

// Business profile
$bp_industry  = (string) get_option( 'chatbot_business_industry', 'servicios' );
$bp_goal      = (string) get_option( 'chatbot_business_goal', 'captar contactos' );
$bp_cta       = (string) get_option( 'chatbot_business_primary_cta', 'Quiero avanzar' );
$bp_sensitive = (string) get_option( 'chatbot_business_sensitive_topics', 'precio, plazos, disponibilidad' );
$bp_tone      = (string) get_option( 'chatbot_business_tone', 'claro y profesional' );

// Perfil activo (configurado en la pestaña Conversación)
$active_profile_key   = sanitize_key( (string) get_option( 'chatbot_profile', '' ) );
$profile_labels       = function_exists( 'estandar_chatbot_get_profile_labels' ) ? estandar_chatbot_get_profile_labels() : [];
$active_profile_label = $active_profile_key !== '' && isset( $profile_labels[ $active_profile_key ] )
	? (string) $profile_labels[ $active_profile_key ]
	: '';
?>
<div>
	<?php if ( $status && $msg ) : ?>
		<div class="notice <?php echo $status === 'ok' ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html( $msg ); ?></p></div>
	<?php endif; ?>
	<?php settings_errors(); ?>

	<?php // ═══════════════════════════════════════════════════════════════════
	// 0. RESUMEN — cómo piensa el chatbot, de un vistazo
	// ═══════════════════════════════════════════════════════════════════ ?>
	<div class="ecb-card" style="margin-bottom:16px;">
		<h3 style="margin-top:0;"><?php esc_html_e( 'Cómo piensa tu chatbot', 'estandar-chatbot' ); ?></h3>
		<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'El chatbot combina cuatro piezas: la IA conectada, el conocimiento de tu empresa, el perfil aplicado y tus instrucciones propias.', 'estandar-chatbot' ); ?></p>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;">
			<?php if ( $openai_key_configured && $openai_shared ) : ?>
			<div style="padding:12px 14px;border:1px solid #bde8bd;border-radius:10px;background:#edfaed;">
				<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'IA centralizada', 'estandar-chatbot' ); ?></div>
				<div style="font-weight:600;color:#008a20;">
					<?php esc_html_e( 'Operativa', 'estandar-chatbot' ); ?> · <?php echo esc_html( $openai_model ); ?>
				</div>
				<div style="font-size:11px;color:#4b5563;margin-top:4px;">
					<?php esc_html_e( 'Usa la configuración global de IA de Estandar Primer.', 'estandar-chatbot' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar_core_diagnostics&tab=configuracion' ) ); ?>" style="font-weight:600;"><?php esc_html_e( 'Ver configuración global', 'estandar-chatbot' ); ?></a>
				</div>
			</div>
			<?php elseif ( $openai_key_configured ) : ?>
			<div style="padding:12px 14px;border:1px solid #bde8bd;border-radius:10px;background:#edfaed;">
				<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'IA', 'estandar-chatbot' ); ?></div>
				<div style="font-weight:600;color:#008a20;">
					✓ <?php esc_html_e( 'Conectada', 'estandar-chatbot' ); ?> · <?php echo esc_html( $openai_model ); ?>
				</div>
			</div>
			<?php else : ?>
			<div style="padding:12px 14px;border:1px solid #f5bcbc;border-radius:10px;background:#fdeaea;">
				<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'IA', 'estandar-chatbot' ); ?></div>
				<div style="font-weight:600;color:#cc1818;">
					<?php esc_html_e( 'Sin conexión', 'estandar-chatbot' ); ?>
				</div>
				<div style="font-size:11px;color:#991b1b;margin-top:4px;">
					<?php esc_html_e( 'No hay clave API configurada. El chatbot no podrá usar IA.', 'estandar-chatbot' ); ?>
				</div>
			</div>
			<?php endif; ?>
			<div style="padding:12px 14px;border:1px solid <?php echo $file_ok ? '#bde8bd' : '#dcdcde'; ?>;border-radius:10px;background:<?php echo $file_ok ? '#edfaed' : '#f6f7f7'; ?>;">
				<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Conocimiento', 'estandar-chatbot' ); ?></div>
				<div style="font-weight:600;color:<?php echo $file_ok ? '#008a20' : '#646970'; ?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
					<?php echo $file_ok ? '✓ ' . esc_html( $current_file_name ) : esc_html__( 'Sin archivo', 'estandar-chatbot' ); ?>
				</div>
			</div>
			<div style="padding:12px 14px;border:1px solid <?php echo $active_profile_label !== '' ? '#bde8bd' : '#fde68a'; ?>;border-radius:10px;background:<?php echo $active_profile_label !== '' ? '#edfaed' : '#fffbeb'; ?>;">
				<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Perfil', 'estandar-chatbot' ); ?></div>
				<div style="font-weight:600;color:<?php echo $active_profile_label !== '' ? '#008a20' : '#92400e'; ?>;">
					<?php echo $active_profile_label !== '' ? '✓ ' . esc_html( $active_profile_label ) : esc_html__( 'Sin perfil asignado', 'estandar-chatbot' ); ?>
				</div>
			</div>
			<div style="padding:12px 14px;border:1px solid #dcdcde;border-radius:10px;background:#f6f7f7;">
				<div style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Personalización', 'estandar-chatbot' ); ?></div>
				<div style="font-weight:600;color:#374151;">
					<?php echo 'custom' === $system_prompt_source ? esc_html__( 'Instrucciones propias', 'estandar-chatbot' ) : esc_html__( 'Prompt por defecto', 'estandar-chatbot' ); ?>
				</div>
			</div>
		</div>
	</div>

	<form method="post" enctype="multipart/form-data">
		<?php wp_nonce_field( 'estandar_chatbot_save_ia_nonce' ); ?>

		<!-- ═══════════════════════════════════════════════════════════════════
		     1. Perfil del negocio — lo más importante para el comportamiento
		     ═══════════════════════════════════════════════════════════════════ -->
		<div class="ecb-card" style="margin-bottom:16px;">
			<h3 style="margin-top:0;">🏢 <?php esc_html_e( 'Perfil del negocio', 'estandar-chatbot' ); ?></h3>
			<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Define quién eres y qué hace tu chatbot. Estos valores se inyectan automáticamente en el prompt.', 'estandar-chatbot' ); ?></p>
			<table class="form-table" role="presentation" style="margin-bottom:0;">
			<tbody>
			<tr>
				<th scope="row" style="width:180px;"><?php esc_html_e( 'Rubro', 'estandar-chatbot' ); ?></th>
				<td>
					<input type="text" name="chatbot_business_industry" value="<?php echo esc_attr( $bp_industry ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Ej: contabilidad, inmobiliaria, salud, ecommerce.', 'estandar-chatbot' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Objetivo principal', 'estandar-chatbot' ); ?></th>
				<td>
					<input type="text" name="chatbot_business_goal" value="<?php echo esc_attr( $bp_goal ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Qué acción impulsar: captar contactos, agendar reunión, cotizar, vender.', 'estandar-chatbot' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'CTA principal', 'estandar-chatbot' ); ?></th>
				<td>
					<input type="text" name="chatbot_business_primary_cta" value="<?php echo esc_attr( $bp_cta ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Frase de cierre que usará el bot. Ej: Quiero avanzar, Cotizar, Reservar hora.', 'estandar-chatbot' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Tono de comunicación', 'estandar-chatbot' ); ?></th>
				<td>
					<input type="text" name="chatbot_business_tone" value="<?php echo esc_attr( $bp_tone ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Ej: claro y profesional, cercano y directo, técnico pero simple.', 'estandar-chatbot' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Temas sensibles', 'estandar-chatbot' ); ?></th>
				<td>
					<input type="text" name="chatbot_business_sensitive_topics" value="<?php echo esc_attr( $bp_sensitive ); ?>" class="large-text" />
					<p class="description"><?php esc_html_e( 'Temas que el bot debe manejar con cuidado. Ej: precio, plazos, disponibilidad.', 'estandar-chatbot' ); ?></p>
				</td>
			</tr>
			</tbody>
			</table>
		</div>

		<!-- ═══════════════════════════════════════════════════════════════════
		     2. Conocimiento de la empresa
		     ═══════════════════════════════════════════════════════════════════ -->
		<div class="ecb-card" style="margin-bottom:16px;">
			<h3 style="margin-top:0;display:flex;align-items:center;gap:8px;">
				📄 <?php esc_html_e( 'Conocimiento de la empresa', 'estandar-chatbot' ); ?>
				<?php if ( ! $file_ok ) : ?>
					<span style="font-size:12px;font-weight:400;color:#646970;background:#f6f7f7;border:1px solid #dcdcde;border-radius:12px;padding:2px 10px;"><?php esc_html_e( 'Sin archivo', 'estandar-chatbot' ); ?></span>
				<?php endif; ?>
			</h3>
			<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Sube un archivo .txt con información sobre tu empresa, servicios o preguntas frecuentes. El chatbot lo usará como contexto oficial en cada conversación.', 'estandar-chatbot' ); ?></p>

			<?php if ( $file_ok ) : ?>
				<div style="display:flex;gap:14px;align-items:flex-start;background:#fff;border:1px solid #bde8bd;border-radius:10px;padding:14px 16px;margin-bottom:12px;">
					<div style="width:44px;height:44px;border-radius:10px;background:#edfaed;border:1px solid #bde8bd;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">📄</div>
					<div style="min-width:0;">
						<div style="font-weight:600;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $current_file_name ); ?></div>
						<div style="font-size:12px;color:#6b7280;margin-top:2px;">
							<?php echo esc_html( (string) size_format( $file_size ) ); ?>
							&nbsp;·&nbsp;<?php echo esc_html( $file_mtime ? wp_date( 'Y-m-d H:i', $file_mtime ) : '-' ); ?>
							&nbsp;·&nbsp;<span style="color:#008a20;font-weight:600;">✓ <?php esc_html_e( 'Activo', 'estandar-chatbot' ); ?></span>
						</div>
						<?php if ( $preview !== '' ) : ?>
							<p style="margin:8px 0 0;color:#646970;font-size:12px;line-height:1.5;">"<?php echo esc_html( $preview ); ?>…"</p>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
				<input type="file" name="chatbot_context_txt" accept=".txt" />
				<?php if ( $file_ok ) : ?>
					<button type="submit" name="estandar_chatbot_delete_context" class="button button-secondary"
						onclick="return window.confirm('<?php echo esc_js( __( '¿Eliminar el archivo de contexto actual?', 'estandar-chatbot' ) ); ?>');"
					><?php esc_html_e( 'Eliminar archivo', 'estandar-chatbot' ); ?></button>
				<?php endif; ?>
			</div>
			<p class="description" style="margin-top:6px;"><?php esc_html_e( 'Solo .txt · Máximo 512 KB. Subir uno nuevo reemplaza el anterior.', 'estandar-chatbot' ); ?></p>
		</div>

		<!-- ═══════════════════════════════════════════════════════════════════
		     3. Instrucciones del asistente (solo comportamiento, colapsada)
		     ═══════════════════════════════════════════════════════════════════ -->
		<details style="margin-bottom:16px;">
			<summary style="cursor:pointer;padding:12px 16px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:6px;">
				📝 <?php esc_html_e( 'Instrucciones del asistente', 'estandar-chatbot' ); ?>
				<span style="font-size:12px;font-weight:400;color:#646970;">
					<?php echo 'custom' === $system_prompt_source ? esc_html__( '(instrucciones propias activas)', 'estandar-chatbot' ) : esc_html__( '(usando instrucciones por defecto)', 'estandar-chatbot' ); ?>
				</span>
			</summary>
			<div class="ecb-card" style="border-top:none;border-top-left-radius:0;border-top-right-radius:0;margin-top:0;">
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Define aquí solo reglas de comportamiento: cómo responder, cuándo pedir datos, cuándo derivar, restricciones y prioridades. No repitas información del negocio — la identidad (empresa, rubro, CTA, tono) vive en Perfil del negocio y los datos (proyectos, servicios, precios) en el archivo de Conocimiento; ambos se inyectan automáticamente en cada conversación.', 'estandar-chatbot' ); ?>
				</p>
				<p class="description" style="margin-bottom:12px;">
					<?php if ( 'custom' === $system_prompt_source ) : ?>
						<?php esc_html_e( 'Estás usando instrucciones propias. Si lo vacías, el plugin usará las instrucciones por defecto.', 'estandar-chatbot' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Sin instrucciones propias: el chatbot usa las instrucciones por defecto del plugin, orientadas a conversión y derivación humana.', 'estandar-chatbot' ); ?>
					<?php endif; ?>
				</p>
				<textarea name="chatbot_system_prompt" id="chatbot_system_prompt" rows="10" class="large-text code"
					placeholder="<?php esc_attr_e( 'Vacío = usar instrucciones por defecto del plugin.', 'estandar-chatbot' ); ?>"
					style="font-family:monospace;font-size:13px;"
				><?php echo esc_textarea( $system_prompt_custom ); ?></textarea>

				<details style="margin-top:12px;">
					<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'Ver prompt efectivo que se enviará a OpenAI', 'estandar-chatbot' ); ?></summary>
					<textarea rows="12" class="large-text code" readonly style="margin-top:8px;font-size:12px;opacity:.8;"><?php echo esc_textarea( $effective_system_prompt ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Composición completa: System Base + instrucciones + Perfil del negocio + Conocimiento + horario. Solo lectura.', 'estandar-chatbot' ); ?></p>
				</details>
			</div>
		</details>

		<!-- ═══════════════════════════════════════════════════════════════════
		     4. Motor IA (avanzado) — abierto solo si falta la credencial
		     ═══════════════════════════════════════════════════════════════════ -->
		<details style="margin-bottom:16px;" <?php echo $openai_key_configured ? '' : 'open'; ?>>
			<summary style="cursor:pointer;padding:12px 16px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:6px;">
				⚙️ <?php esc_html_e( 'Motor IA (avanzado)', 'estandar-chatbot' ); ?>
				<span style="font-size:12px;font-weight:400;color:#646970;"><?php esc_html_e( '(API Key, modelo, tokens, creatividad, historial)', 'estandar-chatbot' ); ?></span>
				<?php if ( ! $openai_key_configured ) : ?>
					<span style="font-size:12px;font-weight:400;color:#cc1818;background:#fdeaea;border:1px solid #f5bcbc;border-radius:12px;padding:2px 10px;"><?php esc_html_e( 'Falta credencial', 'estandar-chatbot' ); ?></span>
				<?php endif; ?>
			</summary>
			<div class="ecb-card" style="border-top:none;border-top-left-radius:0;border-top-right-radius:0;margin-top:0;">
				<table class="form-table" role="presentation" style="margin-bottom:0;">
				<tbody>

				<tr id="ecb-openai-api-key">
					<th scope="row" style="width:220px;"><?php esc_html_e( 'API Key', 'estandar-chatbot' ); ?></th>
					<td>
						<div style="display:flex;align-items:center;gap:8px;max-width:520px;">
							<input type="password" id="chatbot_openai_api_key" name="chatbot_openai_api_key" value="" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $openai_key_configured ? __( 'Configurada. Dejar vacío para conservar.', 'estandar-chatbot' ) : __( 'sk-…', 'estandar-chatbot' ) ); ?>" />
							<button type="button" class="button" id="ecb-toggle-openai-key" aria-label="<?php esc_attr_e( 'Mostrar / ocultar clave', 'estandar-chatbot' ); ?>">👁</button>
						</div>
						<p class="description"><?php esc_html_e( 'Dejar vacío conserva la clave actual. No se expone al navegador.', 'estandar-chatbot' ); ?></p>
						<?php if ( $openai_key_configured ) : ?>
							<label style="display:block;margin-top:8px;">
								<input type="checkbox" name="chatbot_delete_openai_api_key" value="1" onclick="return window.confirm('<?php echo esc_js( __( '¿Eliminar la credencial OpenAI local almacenada?', 'estandar-chatbot' ) ); ?>');" />
								<?php esc_html_e( 'Eliminar credencial local almacenada', 'estandar-chatbot' ); ?>
							</label>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Modelo de IA', 'estandar-chatbot' ); ?></th>
					<td>
						<select name="chatbot_openai_model" id="chatbot_openai_model">
							<option value="gpt-4o" <?php selected( $openai_model, 'gpt-4o' ); ?>>gpt-4o — <?php esc_html_e( 'recomendado, más explicativo', 'estandar-chatbot' ); ?></option>
							<option value="gpt-4o-mini" <?php selected( $openai_model, 'gpt-4o-mini' ); ?>>gpt-4o-mini — <?php esc_html_e( 'rápido y económico', 'estandar-chatbot' ); ?></option>
							<option value="gpt-4-turbo" <?php selected( $openai_model, 'gpt-4-turbo' ); ?>>gpt-4-turbo</option>
							<option value="gpt-4" <?php selected( $openai_model, 'gpt-4' ); ?>>gpt-4</option>
							<option value="gpt-3.5-turbo" <?php selected( $openai_model, 'gpt-3.5-turbo' ); ?>>gpt-3.5-turbo</option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Longitud de respuesta', 'estandar-chatbot' ); ?></th>
					<td>
						<input type="number" name="chatbot_openai_max_tokens" value="<?php echo esc_attr( (string) $openai_max_tokens ); ?>" min="100" max="8000" step="50" class="small-text" />
						<p class="description"><?php esc_html_e( 'Límite en tokens (100–8000). Valor recomendado: 500. Aumentar para respuestas más largas.', 'estandar-chatbot' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Creatividad del bot', 'estandar-chatbot' ); ?></th>
					<td>
						<input type="number" name="chatbot_openai_temperature" value="<?php echo esc_attr( (string) $openai_temperature ); ?>" min="0" max="2" step="0.1" class="small-text" />
						<p class="description"><?php esc_html_e( '0 = respuestas predecibles y consistentes · 1 = variadas · 2 = muy creativas. Recomendado: 0.4.', 'estandar-chatbot' ); ?></p>
					</td>
				</tr>

				</tbody>
				</table>

				<?php if ( ! empty( $prompt_history ) ) : ?>
					<hr style="margin:16px 0;" />
					<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Historial automático del prompt', 'estandar-chatbot' ); ?></h4>
					<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Cambios automáticos del sistema. Puedes revertir el último.', 'estandar-chatbot' ); ?></p>
					<p style="margin-bottom:12px;">
						<?php // El form real vive fuera del form principal (forms anidados son HTML inválido). ?>
						<button type="submit" class="button" form="ecb-rollback-prompt-form"><?php esc_html_e( 'Revertir último cambio automático', 'estandar-chatbot' ); ?></button>
					</p>
					<div style="display:flex;flex-direction:column;gap:8px;">
						<?php foreach ( array_slice( $prompt_history, 0, 5 ) as $entry ) :
							$entry_meta  = is_array( $entry['meta'] ?? null ) ? $entry['meta'] : [];
							$entry_title = (string) ( $entry_meta['title'] ?? ( $entry_meta['suggestion_id'] ?? 'auto' ) );
						?>
							<div style="padding:10px 12px;border:1px solid #dcdcde;border-radius:6px;background:#fff;">
								<strong style="font-size:13px;"><?php echo esc_html( $entry_title ); ?></strong>
								<span style="color:#646970;font-size:12px;margin-left:8px;"><?php echo esc_html( (string) ( $entry['at'] ?? '' ) ); ?> · <?php echo esc_html( (string) ( $entry['source'] ?? 'auto' ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</details>

		<?php submit_button( __( 'Guardar configuración de IA', 'estandar-chatbot' ), 'primary large', 'estandar_chatbot_save_ia' ); ?>

	</form>

	<?php if ( ! empty( $prompt_history ) ) : ?>
		<?php // Form del rollback — fuera del form principal para evitar anidamiento inválido. ?>
		<form id="ecb-rollback-prompt-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="estandar_chatbot_rollback_prompt_change" />
			<?php wp_nonce_field( 'estandar_chatbot_rollback_prompt_change' ); ?>
		</form>
	<?php endif; ?>
</div>

<script>
(function(){
	var btn   = document.getElementById('ecb-toggle-openai-key');
	var input = document.getElementById('chatbot_openai_api_key');
	if (!btn || !input) return;
	btn.addEventListener('click', function(){
		input.type = input.type === 'password' ? 'text' : 'password';
	});
})();
</script>
