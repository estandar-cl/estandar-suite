<?php
/**
 * Configuration Status Widget — helpers for the Diagnóstico page.
 *
 * Reads from: chatbot project registry, wp_es_operators.
 * Fase 1 (2026-05-29): el widget global de admin_notices fue eliminado.
 * La información se muestra ahora exclusivamente en Diagnóstico.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Hook global eliminado en Fase 1 (2026-05-29).
// Contenido movido a Diagnóstico via estandar_chatbot_render_config_status_section().

function estandar_chatbot_render_config_status_widget() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Only show on Estandar admin pages.
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}
	$base = (string) ( $screen->base ?? '' );
	$id   = (string) ( $screen->id ?? '' );
	if ( strpos( $id, 'estandar' ) === false && strpos( $base, 'estandar' ) === false ) {
		return;
	}

	$status = estandar_chatbot_get_config_status();

	$has_warnings = ! empty( $status['warnings'] );
	$border_color = $has_warnings ? '#dba617' : '#00a32a';
	$bg_color     = $has_warnings ? '#fffbe6' : '#f0faf0';

	echo '<div class="notice" style="border-left-color:' . esc_attr( $border_color ) . ';background:' . esc_attr( $bg_color ) . ';padding:10px 16px;">';
	echo '<strong style="display:block;margin-bottom:6px;">Estado del sistema Estandar</strong>';
	echo '<ul style="margin:0;padding:0 0 0 4px;list-style:none;">';

	foreach ( $status['lines'] as $line ) {
		$icon  = $line['ok'] ? '✓' : '⚠';
		$color = $line['ok'] ? '#1a7f37' : '#996600';
		$link  = ! empty( $line['url'] ) ? ' &nbsp;<a href="' . esc_url( $line['url'] ) . '">[Ver →]</a>' : '';
		echo '<li style="color:' . esc_attr( $color ) . ';margin:2px 0;">' . esc_html( $icon . '  ' . $line['label'] ) . wp_kses_post( $link ) . '</li>';
	}

	echo '</ul></div>';
}

/**
 * Build the status data array.
 *
 * @return array{ lines: array<array{ok:bool,label:string,url?:string}>, warnings: int }
 */
function estandar_chatbot_get_config_status() {
	$cache_key = 'ecb_config_status_v1';
	$cached    = wp_cache_get( $cache_key, 'estandar_chatbot' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;

	$registry = function_exists( 'estandar_chatbot_get_project_registry' )
		? estandar_chatbot_get_project_registry()
		: (array) get_option( 'chatbot_project_registry', [] );

	$active_projects = array_filter( $registry, fn( $p ) => (bool) ( $p['active'] ?? true ) );
	$project_count   = count( $active_projects );
	$active_slugs    = array_column( $active_projects, 'project_slug' );

	$operators_table = $wpdb->prefix . 'es_operators';
	$table_exists    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $operators_table ) );

	$operators_without_number = 0;
	$slugs_with_operator      = [];
	$op_rows                  = [];

	if ( $table_exists ) {
		$op_rows = $wpdb->get_results( "SELECT project_slug, whatsapp_number FROM {$operators_table} WHERE active = 1", ARRAY_A );
		if ( is_array( $op_rows ) ) {
			foreach ( $op_rows as $op ) {
				$slug = (string) ( $op['project_slug'] ?? '' );
				if ( $slug !== '' ) {
					$slugs_with_operator[] = $slug;
				}
				if ( empty( $op['whatsapp_number'] ) ) {
					$operators_without_number++;
				}
			}
		}
	}

	$slugs_without_operator = array_diff( $active_slugs, $slugs_with_operator );

	// Collision detection (uses transient so it's cached longer).
	$collisions = estandar_chatbot_detect_pattern_collisions( $registry );

	$lines    = [];
	$warnings = 0;

	// Active projects.
	$lines[] = [
		'ok'    => $project_count > 0,
		'label' => $project_count > 0
			? sprintf( '%d %s', $project_count, _n( 'proyecto activo', 'proyectos activos', $project_count, 'estandar-chatbot' ) )
			: 'Sin proyectos activos configurados',
		'url'   => $project_count === 0 ? admin_url( 'admin.php?page=estandar-chatbot&tab=leads-captacion' ) : '',
	];
	if ( $project_count === 0 ) {
		$warnings++;
	}

	// Projects without operator.
	if ( ! empty( $slugs_without_operator ) ) {
		$warnings++;
		$lines[] = [
			'ok'    => false,
			'label' => count( $slugs_without_operator ) . ' proyecto(s) sin operador de WhatsApp asignado',
			'url'   => admin_url( 'admin.php?page=estandar-leads' ),
		];
	} else {
		$lines[] = [
			'ok'    => true,
			'label' => 'Todos los proyectos tienen operador asignado',
		];
	}

	// Operators without WhatsApp number.
	if ( $operators_without_number > 0 ) {
		$warnings++;
		$lines[] = [
			'ok'    => false,
			'label' => $operators_without_number . ' operador(es) sin número de WhatsApp',
			'url'   => admin_url( 'admin.php?page=estandar-leads-settings' ),
		];
	}

	// Projects missing sid_prefix.
	$missing_sid = array_values( array_filter( $active_projects, fn( $p ) => empty( $p['sid_prefix'] ) ) );
	if ( ! empty( $missing_sid ) ) {
		$warnings++;
		$lines[] = [
			'ok'    => false,
			'label' => count( $missing_sid ) . ' proyecto(s) sin sid_prefix: ' . implode( ', ', array_column( $missing_sid, 'project_slug' ) ),
			'url'   => admin_url( 'admin.php?page=estandar-chatbot&tab=leads-captacion' ),
		];
	}

	// Projects missing url_patterns.
	$missing_patterns = array_values( array_filter( $active_projects, fn( $p ) => empty( $p['url_patterns'] ) ) );
	if ( ! empty( $missing_patterns ) ) {
		$warnings++;
		$lines[] = [
			'ok'    => false,
			'label' => count( $missing_patterns ) . ' proyecto(s) sin url_patterns: ' . implode( ', ', array_column( $missing_patterns, 'project_slug' ) ),
			'url'   => admin_url( 'admin.php?page=estandar-chatbot&tab=leads-captacion' ),
		];
	} else if ( $project_count > 0 ) {
		$lines[] = [
			'ok'    => true,
			'label' => 'Todos los proyectos tienen url_patterns',
		];
	}

	// Routing: count how many active projects would fall through to fallback.
	if ( $table_exists && $project_count > 0 ) {
		$resolved_slugs = [];
		if ( is_array( $op_rows ) ) {
			foreach ( $op_rows as $op ) {
				$slug = (string) ( $op['project_slug'] ?? '' );
				if ( $slug !== '' && ! empty( $op['whatsapp_number'] ) ) {
					$resolved_slugs[] = $slug;
				}
			}
		}
		$fallback_projects = array_diff( $active_slugs, $resolved_slugs );
		if ( ! empty( $fallback_projects ) ) {
			$warnings++;
			$lines[] = [
				'ok'    => false,
				'label' => count( $fallback_projects ) . ' proyecto(s) enrutarán a fallback (sin operador+WA resolvible): ' . implode( ', ', $fallback_projects ),
				'url'   => admin_url( 'admin.php?page=estandar-leads-settings' ),
			];
		} else {
			$lines[] = [
				'ok'    => true,
				'label' => 'Todos los proyectos tienen routing resolved',
			];
		}
	}

	// Pattern collisions.
	if ( ! empty( $collisions ) ) {
		$warnings++;
		$lines[] = [
			'ok'    => false,
			'label' => count( $collisions ) . ' colisión(es) de URL patterns detectada(s)',
			'url'   => admin_url( 'admin.php?page=estandar-analytics&tab=routing-health' ),
		];
	} else {
		$lines[] = [
			'ok'    => true,
			'label' => '0 colisiones de URL patterns',
		];
	}

	$result = [ 'lines' => $lines, 'warnings' => $warnings ];
	wp_cache_set( $cache_key, $result, 'estandar_chatbot' );
	return $result;
}

/**
 * Detect overlapping url_patterns across different projects.
 * Uses a transient (15 min) invalidated on registry saves.
 *
 * @param array $registry
 * @return array List of collision pairs.
 */
function estandar_chatbot_detect_pattern_collisions( array $registry ) {
	$cached = get_transient( 'ea_routing_health_collisions' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$collisions = [];
	$count      = count( $registry );

	for ( $i = 0; $i < $count; $i++ ) {
		$pa = $registry[ $i ];
		$patterns_a = (array) ( $pa['url_patterns'] ?? [] );
		if ( empty( $patterns_a ) ) {
			continue;
		}
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$pb = $registry[ $j ];
			$patterns_b = (array) ( $pb['url_patterns'] ?? [] );
			if ( empty( $patterns_b ) ) {
				continue;
			}
			foreach ( $patterns_a as $pat_a ) {
				foreach ( $patterns_b as $pat_b ) {
					if ( estandar_chatbot_pattern_matches_collision( $pat_a, $pat_b )
						|| estandar_chatbot_pattern_matches_collision( $pat_b, $pat_a ) ) {
						$collisions[] = [
							'pattern_a' => $pat_a,
							'project_a' => $pa['project_slug'] ?? '',
							'pattern_b' => $pat_b,
							'project_b' => $pb['project_slug'] ?? '',
							'risk'      => 'HIGH',
						];
					}
				}
			}
		}
	}

	set_transient( 'ea_routing_health_collisions', $collisions, 15 * MINUTE_IN_SECONDS );
	return $collisions;
}

/**
 * Same strict matching logic as EA_Attribution, used for collision detection.
 * Pattern A "collides" with pattern B if A matches any URL that starts with B.
 */
function estandar_chatbot_pattern_matches_collision( $path, $pattern ) {
	$path    = '/' . ltrim( rtrim( (string) $path, '/' ), '/' );
	$pattern = '/' . ltrim( rtrim( (string) $pattern, '/' ), '/' );
	if ( $path === $pattern ) {
		return true;
	}
	if ( strpos( $path, $pattern . '/' ) === 0 ) {
		return true;
	}
	return false;
}

// Invalidate collision transient whenever the project registry is saved.
add_action( 'update_option_chatbot_project_registry', function() {
	delete_transient( 'ea_routing_health_collisions' );
	wp_cache_delete( 'ecb_config_status_v1', 'estandar_chatbot' );
} );

/**
 * Render the project/operator/routing status as an embedded section.
 * Called from estandar_core_render_diagnostics_page() — NOT as admin_notice.
 */
if ( ! function_exists( 'estandar_chatbot_render_config_status_section' ) ) {
	function estandar_chatbot_render_config_status_section(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status       = estandar_chatbot_get_config_status();
		$has_warnings = ! empty( $status['warnings'] );

		echo '<h2>Estado del sistema Estandar</h2>';
		echo '<div class="estandar-card">';
		echo '<p class="description" style="margin:0 0 10px;">Proyectos activos, operadores, WhatsApp, url_patterns, routing y colisiones de URL.</p>';
		echo '<ul style="margin:0;padding:0 0 0 4px;list-style:none;">';
		foreach ( (array) $status['lines'] as $line ) {
			$ok    = ! empty( $line['ok'] );
			$icon  = $ok ? '✓' : '⚠';
			$color = $ok ? '#1a7f37' : '#996600';
			$link  = ! empty( $line['url'] ) ? ' &nbsp;<a href="' . esc_url( (string) $line['url'] ) . '">[Ver →]</a>' : '';
			echo '<li style="color:' . esc_attr( $color ) . ';margin:4px 0;">'
				. esc_html( $icon . '  ' . (string) ( $line['label'] ?? '' ) )
				. wp_kses_post( $link )
				. '</li>';
		}
		echo '</ul>';
		if ( $has_warnings ) {
			echo '<p class="description" style="margin:10px 0 0;">'
				. esc_html( (string) $status['warnings'] ) . ' advertencia(s) detectada(s).</p>';
		}
		echo '</div>';
	}
}

/**
 * Render the 6 chatbot instance configuration checks as an embedded section.
 * Called from estandar_core_render_diagnostics_page() — NOT as admin_notice.
 */
/**
 * Devuelve el array de checks de configuración de instancia.
 * Cada check: [ label, status ('ok'|'warning'|'critical'), text, desc, button, url ]
 * Usado por estandar_chatbot_render_instance_config_section() y por inicio.php.
 */
if ( ! function_exists( 'estandar_chatbot_get_instance_checks' ) ) {
	function estandar_chatbot_get_instance_checks(): array {
		$chatbot_active    = (int) get_option( 'chatbot_activar', 0 ) === 1;
		$solo_loggeados    = (int) get_option( 'chatbot_solo_loggeados', 0 ) === 1;
		$visible_pages     = function_exists( 'estandar_chatbot_get_option_array' )
			? (array) estandar_chatbot_get_option_array( 'chatbot_paginas_visibles', [] )
			: (array) get_option( 'chatbot_paginas_visibles', [] );
		$local_openai_key  = trim( (string) get_option( 'chatbot_openai_api_key', '' ) );
		$openai_key        = function_exists( 'estandar_chatbot_get_openai_api_key' ) ? estandar_chatbot_get_openai_api_key() : $local_openai_key;
		$openai_ok         = $openai_key !== '';
		$openai_shared     = $openai_ok && $local_openai_key === '' && function_exists( 'estandar_core_get_secret' ) && estandar_core_get_secret( 'openai' ) !== '';
		$context_path      = (string) get_option( 'chatbot_context_txt_path', '' );
		$contexto_ok       = $context_path !== '' && file_exists( $context_path );
		$prompt_custom_ok  = trim( (string) get_option( 'chatbot_system_prompt', '' ) ) !== '';

		$readiness_evidence = [];
		if ( function_exists( 'estandar_chatbot_get_operational_readiness' ) ) {
			$or = estandar_chatbot_get_operational_readiness();
			$readiness_evidence = (array) ( $or['evidence'] ?? [] );
		}
		$latest_export_ok     = (string) ( $readiness_evidence['latest_export_at'] ?? '' ) !== '';
		$latest_email_status  = (string) ( $readiness_evidence['latest_email_effective_status'] ?? '' );
		if ( $latest_email_status === '' ) {
			$latest_email_status = (string) ( $readiness_evidence['latest_email_status'] ?? '' );
		}
		$notification_mode = (string) ( $readiness_evidence['notification_mode'] ?? '' );
		$is_leads_mode_check = $notification_mode === 'leads' && function_exists( 'estandar_leads_version' );
		$email_configured = ! empty( $readiness_evidence['email_enabled'] )
			&& (int) ( $readiness_evidence['recipients_count'] ?? 0 ) > 0;
		$email_ready      = $is_leads_mode_check || ( $email_configured && $latest_email_status === 'success' );
		$email_status     = $is_leads_mode_check ? 'ok' : ( ! $email_configured ? 'critical' : ( $email_ready ? 'ok' : 'warning' ) );
		$email_text       = $is_leads_mode_check ? 'Delegado a Leads' : ( ! $email_configured ? 'Corregir' : ( $email_ready ? 'Validado' : 'Configurado, falta validar' ) );
		$email_action_url = ! $email_configured && ! $is_leads_mode_check
			? admin_url( 'admin.php?page=estandar-chatbot&tab=integraciones#ecb-email-delivery' )
			: ( $email_ready
				? admin_url( 'admin.php?page=estandar-chatbot&tab=integraciones#ecb-email-delivery' )
				: admin_url( 'admin.php?page=estandar-chatbot&tab=herramientas' ) );

		$vis_status = ! $chatbot_active ? 'critical' : ( $solo_loggeados ? 'warning' : 'ok' );
		$vis_text   = ! $chatbot_active
			? 'Desactivado'
			: ( $solo_loggeados ? 'Solo logueados' : ( ! empty( $visible_pages ) ? 'Páginas seleccionadas' : 'Visible en todo el sitio' ) );
		$vis_desc   = ! $chatbot_active
			? 'El widget no se renderiza porque el chatbot está apagado.'
			: ( $solo_loggeados
				? 'Los visitantes no autenticados no verán la burbuja.'
				: ( ! empty( $visible_pages )
					? 'La burbuja aparece en las páginas configuradas. Esto es una decisión de configuración, no un error.'
					: 'La burbuja se carga en el frontend público.' ) );

		return [
			[
				'label'  => 'Visibilidad frontend',
				'status' => $vis_status,
				'text'   => $vis_text,
				'desc'   => $vis_desc,
				'button' => $vis_status === 'ok' ? 'Revisar' : 'Corregir visibilidad',
				'url'    => admin_url( 'admin.php?page=estandar-chatbot&tab=configuracion#ecb-frontend-visibility' ),
			],
			[
				'label'  => 'Entrega de leads por email',
				'status' => $email_status,
				'text'   => $email_text,
				'desc'   => $is_leads_mode_check
					? 'Estandar Leads envía los emails de notificación. El chatbot no envía email propio.'
					: ( ! $email_configured
						? 'Falta activar email o agregar un destinatario válido.'
						: ( $email_ready
							? 'Hay destinatario y evidencia reciente de validación correcta.'
							: 'El destinatario está guardado; falta validar el envío con una prueba o un nuevo lead.' ) ),
				'button' => $is_leads_mode_check ? 'Ver integración' : ( ! $email_configured ? 'Agregar email' : ( $email_ready ? 'Revisar' : 'Validar email' ) ),
				'url'    => $email_action_url,
			],
			[
				'label'  => 'Clave API OpenAI',
				'status' => $openai_ok ? 'ok' : 'critical',
				'text'   => $openai_ok ? ( $openai_shared ? 'Compartida' : 'Configurada' ) : 'Falta clave',
				'desc'   => $openai_ok
					? ( $openai_shared ? 'El chatbot usa una clave compartida del ecosistema.' : 'La clave está guardada en configuración.' )
					: 'Sin clave válida el chatbot no puede usar IA de forma confiable.',
				'button' => $openai_ok ? 'Revisar' : 'Agregar clave',
				'url'    => admin_url( 'admin.php?page=estandar-chatbot&tab=ia-contexto#ecb-openai-api-key' ),
			],
			[
				'label'  => 'Prompt del asistente',
				'status' => $prompt_custom_ok ? 'ok' : 'warning',
				'text'   => $prompt_custom_ok ? 'Personalizado' : 'Usando default',
				'desc'   => $prompt_custom_ok
					? 'Existe prompt personalizado guardado.'
					: 'El plugin usará el prompt por defecto; conviene revisarlo para cada cliente.',
				'button' => $prompt_custom_ok ? 'Revisar' : 'Desarrollar prompt',
				'url'    => admin_url( 'admin.php?page=estandar-chatbot&tab=ia-contexto#ecb-system-prompt' ),
			],
			[
				'label'  => 'Contexto del cliente',
				'status' => $contexto_ok ? 'ok' : 'warning',
				'text'   => $contexto_ok ? 'Cargado' : 'Pendiente',
				'desc'   => $contexto_ok
					? 'Hay archivo TXT usado como contexto oficial.'
					: 'Falta cargar el contexto específico del cliente.',
				'button' => $contexto_ok ? 'Revisar' : 'Cargar contexto',
				'url'    => admin_url( 'admin.php?page=estandar-chatbot&tab=ia-contexto#ecb-client-context' ),
			],
			[
				'label'  => 'Export CSV',
				'status' => $latest_export_ok ? 'ok' : 'warning',
				'text'   => $latest_export_ok ? 'Con evidencia' : 'Sin validar',
				'desc'   => $latest_export_ok
					? 'Existe evidencia de export reciente o histórico.'
					: 'Todavía no hay evidencia operacional de export CSV.',
				'button' => $latest_export_ok ? 'Ver leads' : 'Probar export',
				'url'    => admin_url( 'admin.php?page=estandar-chatbot&tab=leads' ),
			],
		];

		// Perfil de configuración.
		$current_profile   = sanitize_key( (string) get_option( 'chatbot_profile', '' ) );
		$profile_labels    = function_exists( 'estandar_chatbot_get_profile_labels' ) ? estandar_chatbot_get_profile_labels() : [];
		$profile_label_str = isset( $profile_labels[ $current_profile ] ) && $current_profile !== ''
			? (string) $profile_labels[ $current_profile ]
			: '';
		$suggested_profile = function_exists( 'estandar_chatbot_suggest_profile' ) ? estandar_chatbot_suggest_profile() : '';
		$suggested_label   = ( $suggested_profile !== '' && isset( $profile_labels[ $suggested_profile ] ) )
			? (string) $profile_labels[ $suggested_profile ]
			: 'Lead servicios';

		$checks[] = [
			'label'            => 'Perfil del chatbot',
			'status'           => $current_profile !== '' ? 'ok' : 'warning',
			'text'             => $current_profile !== '' ? $profile_label_str : 'Sin configurar',
			'desc'             => $current_profile !== ''
				? 'Comportamiento y botones configurados para este tipo de instalación.'
				: 'Sin perfil el chatbot usa defaults genéricos. Configura "' . $suggested_label . '" para optimizar la captura.',
			'button'           => $current_profile !== '' ? 'Revisar perfil' : 'Configurar perfil',
			'url'              => admin_url( 'admin.php?page=estandar-chatbot&tab=configuracion' ),
			'suggested_profile' => $suggested_profile,
		];

		return $checks;
	}
}

if ( ! function_exists( 'estandar_chatbot_render_instance_config_section' ) ) {
	function estandar_chatbot_render_instance_config_section(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// --- Data: delegado a estandar_chatbot_get_instance_checks() ---
		$checks = function_exists( 'estandar_chatbot_get_instance_checks' )
			? estandar_chatbot_get_instance_checks()
			: [];

		// --- Render ---
		echo '<h2>Configuración de instancia</h2>';
		echo '<div class="estandar-card">';
		echo '<p class="description" style="margin:0 0 12px;">Checks de configuración operativa del chatbot en esta instalación.</p>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;">';

		foreach ( $checks as $check ) {
			$s      = (string) ( $check['status'] ?? 'warning' );
			$color  = $s === 'critical' ? '#991b1b' : ( $s === 'ok' ? '#166534' : '#92400e' );
			$border = $s === 'critical' ? '#fecaca' : ( $s === 'ok' ? '#bbf7d0' : '#fde68a' );
			echo '<div style="padding:12px 14px;border:1px solid ' . esc_attr( $border ) . ';border-radius:8px;background:#fff;">';
			echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">';
			echo '<div>';
			echo '<div style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;">' . esc_html( (string) $check['label'] ) . '</div>';
			echo '<div style="margin-top:5px;font-weight:700;color:' . esc_attr( $color ) . ';">' . esc_html( (string) $check['text'] ) . '</div>';
			echo '</div>';
			echo '<a class="button button-small" href="' . esc_url( (string) $check['url'] ) . '">' . esc_html( (string) $check['button'] ) . '</a>';
			echo '</div>';
			echo '<p class="description" style="margin:8px 0 0;">' . esc_html( (string) $check['desc'] ) . '</p>';
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
	}
}
