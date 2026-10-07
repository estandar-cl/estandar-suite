<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function estandar_chatbot_mark_environment_success_notice_pending() {
	update_option( 'estandar_chatbot_env_show_success_notice', 1, false );
}

function estandar_chatbot_should_render_environment_success_notice() {
	$pending = (int) get_option( 'estandar_chatbot_env_show_success_notice', 0 );
	$seen    = (int) get_option( 'estandar_chatbot_env_success_seen', 0 );

	return $pending === 1 || $seen === 0;
}

function estandar_chatbot_check_environment() {
	global $wp_version;

	$issues = [];

	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		$issues[] = [
			'severity' => 'error',
			'message'  => 'PHP 7.4 o superior es requerido para usar este plugin con seguridad.',
		];
	}

	if ( version_compare( (string) $wp_version, '5.8', '<' ) ) {
		$issues[] = [
			'severity' => 'error',
			'message'  => 'WordPress 5.8 o superior es requerido para una instalación compatible.',
		];
	}

	if ( ! function_exists( 'rest_url' ) || ! function_exists( 'rest_get_server' ) ) {
		$issues[] = [
			'severity' => 'error',
			'message'  => 'La REST API de WordPress no está disponible en esta instalación.',
		];
	}

	if ( ! function_exists( 'wp_remote_post' ) ) {
		$issues[] = [
			'severity' => 'error',
			'message'  => 'Las solicitudes HTTP salientes de WordPress no están disponibles (wp_remote_post).',
		];
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$issues[] = [
			'severity' => 'warning',
			'message'  => 'WP_DEBUG está activo. El plugin funcionará, pero podrías ver avisos o mensajes técnicos durante las pruebas.',
		];
	}

	if ( ! extension_loaded( 'curl' ) ) {
		$issues[] = [
			'severity' => 'warning',
			'message'  => 'La extensión cURL no está activa. WordPress puede seguir funcionando, pero algunas conexiones externas podrían ser menos estables.',
		];
	}

	$socket_timeout = (int) ini_get( 'default_socket_timeout' );
	if ( $socket_timeout > 0 && $socket_timeout < 10 ) {
		$issues[] = [
			'severity' => 'warning',
			'message'  => 'El timeout HTTP del servidor es bajo. Esto puede afectar llamadas externas o respuestas lentas.',
		];
	}

	$status = 'ok';
	foreach ( $issues as $issue ) {
		if ( ( $issue['severity'] ?? '' ) === 'error' ) {
			$status = 'error';
			break;
		}
		if ( ( $issue['severity'] ?? '' ) === 'warning' ) {
			$status = 'warning';
		}
	}

	$result = [
		'status'       => $status,
		'issues'       => $issues,
		'checked_at'   => current_time( 'mysql' ),
		'php_version'  => PHP_VERSION,
		'wp_version'   => (string) $wp_version,
		'has_rest_api' => function_exists( 'rest_url' ) && function_exists( 'rest_get_server' ),
	];

	update_option( 'estandar_chatbot_env_status', $result, false );

	return $result;
}

function estandar_chatbot_get_environment_status() {
	$status = get_option( 'estandar_chatbot_env_status', [] );
	if ( ! is_array( $status ) || empty( $status['status'] ) ) {
		return estandar_chatbot_check_environment();
	}
	return $status;
}

function estandar_chatbot_maybe_check_environment_on_admin() {
	if ( ! is_admin() ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	estandar_chatbot_check_environment();
}
add_action( 'admin_init', 'estandar_chatbot_maybe_check_environment_on_admin', 5 );

function estandar_chatbot_render_environment_notice() {
	if ( ! is_admin() ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
	if ( $page !== 'estandar-chatbot' ) {
		return;
	}

	$status = estandar_chatbot_get_environment_status();
	$level  = (string) ( $status['status'] ?? 'ok' );
	$issues = isset( $status['issues'] ) && is_array( $status['issues'] ) ? $status['issues'] : [];

	if ( $level === 'ok' ) {
		if ( ! estandar_chatbot_should_render_environment_success_notice() ) {
			return;
		}
		?>
		<div class="notice notice-success" style="padding:20px 24px;border-left-width:6px;margin-top:16px;">
			<h2 style="margin:0 0 10px;font-size:20px;">✅ <?php esc_html_e( 'Tu web es compatible con Estandar Chatbot', 'estandar-chatbot' ); ?></h2>
			<p style="margin:0 0 12px;font-size:14px;"><?php esc_html_e( 'Al parecer tu sitio puede utilizar el chatbot correctamente sin conflictos relevantes.', 'estandar-chatbot' ); ?></p>
			<p style="margin:0 0 8px;font-weight:600;"><?php esc_html_e( 'Recomendación preventiva', 'estandar-chatbot' ); ?></p>
			<p style="margin:0 0 8px;"><?php esc_html_e( 'Antes de continuar, realiza una copia de seguridad completa del sitio y de la base de datos para evitar cualquier pérdida en caso de cambios futuros.', 'estandar-chatbot' ); ?></p>
			<p style="margin:0;"><?php esc_html_e( 'Puedes continuar con la configuración del chatbot con tranquilidad.', 'estandar-chatbot' ); ?></p>
		</div>
		<?php
		update_option( 'estandar_chatbot_env_success_seen', 1, false );
		update_option( 'estandar_chatbot_env_show_success_notice', 0, false );
		return;
	}

	if ( empty( $issues ) ) {
		return;
	}

	$is_error = $level === 'error';
	$heading  = $is_error ? 'Esta instalación no es apta todavía' : 'Conviene revisar esta instalación';
	$intro    = $is_error
		? 'Se detectaron condiciones que pueden impedir que el plugin funcione correctamente o poner en riesgo la estabilidad de esta web.'
		: 'El plugin puede funcionar, pero se detectaron condiciones que podrían limitar su rendimiento o hacer más sensible la instalación.';
	?>
	<div class="notice <?php echo $is_error ? 'notice-error' : 'notice-warning'; ?>" style="padding:20px 24px;border-left-width:6px;margin-top:16px;">
		<h2 style="margin:0 0 10px;font-size:20px;"><?php echo $is_error ? '❌ ' : '⚠️ '; ?><?php echo esc_html( $heading ); ?></h2>
		<p style="margin:0 0 12px;font-size:14px;"><?php echo esc_html( $intro ); ?></p>
		<ul style="margin:0 0 12px 18px;list-style:disc;">
			<?php foreach ( $issues as $issue ) : ?>
				<li style="margin:0 0 6px;">
					<?php echo esc_html( (string) ( $issue['message'] ?? '' ) ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( $is_error ) : ?>
			<p style="margin:12px 0 0;font-weight:600;"><?php esc_html_e( 'Antes de continuar, corrige estos puntos.', 'estandar-chatbot' ); ?></p>
			<p style="margin:6px 0 0;"><?php esc_html_e( 'Si aun así decides seguir, realiza una copia de seguridad completa del sitio y de la base de datos antes de usar el plugin.', 'estandar-chatbot' ); ?></p>
		<?php else : ?>
			<p style="margin:12px 0 0;"><?php esc_html_e( 'Recomendación: revisa estos puntos para asegurar una instalación más estable.', 'estandar-chatbot' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}
add_action( 'admin_notices', 'estandar_chatbot_render_environment_notice' );
