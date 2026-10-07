<?php
/**
 * Temporary QA shadow copies for explicitly identified Compliance emails.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ESTANDAR_COMPLIANCE_SHADOW_COPY_OPTION = 'estandar_compliance_shadow_copy_v1';

function estandar_core_compliance_shadow_copy_defaults(): array {
	$defaults = [
		'enabled'       => 0,
		'destination'   => defined( 'ESTANDAR_COMPLIANCE_SHADOW_COPY_DESTINATION' )
			? ESTANDAR_COMPLIANCE_SHADOW_COPY_DESTINATION
			: 'gestion@estandar.cl',
		'duration_days' => 7,
		'activated_at'  => '',
		'expires_at'    => '',
		'history'       => [],
	];
	if ( function_exists( 'apply_filters' ) ) {
		$filtered = apply_filters( 'estandar_compliance_shadow_copy_defaults', $defaults );
		if ( is_array( $filtered ) ) {
			$defaults = $filtered;
		}
	}
	return $defaults;
}

function estandar_core_compliance_shadow_copy_settings(): array {
	$settings = wp_parse_args(
		(array) get_option( ESTANDAR_COMPLIANCE_SHADOW_COPY_OPTION, [] ),
		estandar_core_compliance_shadow_copy_defaults()
	);

	if ( ! empty( $settings['enabled'] ) && ! empty( $settings['expires_at'] ) && time() >= strtotime( (string) $settings['expires_at'] . ' UTC' ) ) {
		$settings['enabled'] = 0;
		$history             = is_array( $settings['history'] ) ? $settings['history'] : [];
		$history[]           = [
			'event' => 'expired',
			'at'    => gmdate( 'Y-m-d H:i:s' ),
		];
		$settings['history'] = array_slice( $history, -20 );
		update_option( ESTANDAR_COMPLIANCE_SHADOW_COPY_OPTION, $settings, false );
	}

	return $settings;
}

function estandar_core_compliance_shadow_copy_allowed_types(): array {
	return [
		'daily_digest',
		'reminder_soft',
		'reminder_operational',
		'reminder_insistent',
		'escalation',
		'weekly_digest',
	];
}

function estandar_core_compliance_shadow_copy_is_active( array $context = [] ): bool {
	$settings = estandar_core_compliance_shadow_copy_settings();
	$type     = sanitize_key( (string) ( $context['type'] ?? '' ) );

	return ! empty( $settings['enabled'] )
		&& is_email( (string) $settings['destination'] )
		&& in_array( $type, estandar_core_compliance_shadow_copy_allowed_types(), true );
}

function estandar_core_compliance_shadow_copy_marked( array $context, bool $mark = false ): bool {
	static $marked = [];
	$key = md5( wp_json_encode( [
		'type'  => sanitize_key( (string) ( $context['type'] ?? '' ) ),
		'to'    => sanitize_email( (string) ( $context['to'] ?? '' ) ),
		'level' => sanitize_key( (string) ( $context['level'] ?? '' ) ),
	] ) );

	if ( $mark ) {
		$marked[ $key ] = true;
		return true;
	}

	$was_marked = ! empty( $marked[ $key ] );
	unset( $marked[ $key ] );
	return $was_marked;
}

function estandar_core_compliance_shadow_copy_headers( array $headers, array $context ): array {
	if ( ! estandar_core_compliance_shadow_copy_is_active( $context ) ) {
		return $headers;
	}

	$destination = sanitize_email( (string) estandar_core_compliance_shadow_copy_settings()['destination'] );
	foreach ( $headers as $header ) {
		if ( 0 === stripos( trim( (string) $header ), 'bcc:' ) && false !== stripos( (string) $header, $destination ) ) {
			estandar_core_compliance_shadow_copy_marked( $context, true );
			return $headers;
		}
	}

	$headers[] = 'Bcc: ' . $destination;
	estandar_core_compliance_shadow_copy_marked( $context, true );
	return $headers;
}
add_filter( 'estandar_compliance_email_headers', 'estandar_core_compliance_shadow_copy_headers', 10, 2 );

function estandar_core_compliance_shadow_copy_record_send( array $context ): void {
	if ( ! estandar_core_compliance_shadow_copy_marked( $context ) ) {
		return;
	}

	do_action( 'estandar_telemetry_event', [
		'source_plugin' => 'estandar-core-mu',
		'type'          => 'compliance_shadow_copy',
		'severity'      => ! empty( $context['result'] ) ? 'info' : 'error',
		'code'          => ! empty( $context['result'] ) ? 'COMPLIANCE_SHADOW_COPY_SENT' : 'COMPLIANCE_SHADOW_COPY_FAILED',
		'message'       => 'Compliance Shadow Copy delivery result.',
		'context'       => [
			'email_type'        => sanitize_key( (string) ( $context['type'] ?? '' ) ),
			'sent_at'           => gmdate( 'Y-m-d H:i:s' ),
			'primary_recipient' => sanitize_email( (string) ( $context['to'] ?? '' ) ),
			'result'            => ! empty( $context['result'] ),
		],
	] );
}
add_action( 'estandar_compliance_email_sent', 'estandar_core_compliance_shadow_copy_record_send', 10, 1 );

function estandar_core_save_compliance_shadow_copy(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'No autorizado.' );
	}
	check_admin_referer( 'estandar_compliance_shadow_copy_save' );

	$settings = estandar_core_compliance_shadow_copy_settings();
	$enabled  = isset( $_POST['enabled'] ) && '1' === (string) wp_unslash( $_POST['enabled'] );
	$duration = absint( $_POST['duration_days'] ?? 7 );
	$duration = in_array( $duration, [ 7, 14, 30 ], true ) ? $duration : 7;
	$history  = is_array( $settings['history'] ) ? $settings['history'] : [];

	$settings['destination']   = sanitize_email( wp_unslash( (string) ( $_POST['destination'] ?? '' ) ) );
	$settings['duration_days'] = $duration;

	if ( $enabled ) {
		$settings['enabled']      = 1;
		$settings['activated_at'] = gmdate( 'Y-m-d H:i:s' );
		$settings['expires_at']   = gmdate( 'Y-m-d H:i:s', time() + ( DAY_IN_SECONDS * $duration ) );
		$history[]                = [ 'event' => 'activated', 'at' => $settings['activated_at'] ];
	} else {
		$settings['enabled'] = 0;
		$history[]           = [ 'event' => 'disabled', 'at' => gmdate( 'Y-m-d H:i:s' ) ];
	}

	$settings['history'] = array_slice( $history, -20 );
	update_option( ESTANDAR_COMPLIANCE_SHADOW_COPY_OPTION, $settings, false );

	wp_safe_redirect( admin_url( 'admin.php?page=estandar_core_diagnostics&tab=configuracion#compliance-shadow-copy' ) );
	exit;
}
add_action( 'admin_post_estandar_core_save_compliance_shadow_copy', 'estandar_core_save_compliance_shadow_copy' );

function estandar_core_render_compliance_shadow_copy_card(): void {
	$settings   = estandar_core_compliance_shadow_copy_settings();
	$active     = ! empty( $settings['enabled'] );
	$expired    = ! $active && ! empty( $settings['expires_at'] ) && time() >= strtotime( (string) $settings['expires_at'] . ' UTC' );
	$days_left  = $active ? max( 0, (int) ceil( ( strtotime( (string) $settings['expires_at'] . ' UTC' ) - time() ) / DAY_IN_SECONDS ) ) : 0;
	$status     = $active ? 'Activo' : ( $expired ? 'Expirado' : 'Desactivado' );
	$status_dot = $active ? '#00a32a' : '#9ca3af';
	?>
	<div class="es-diag-section" id="compliance-shadow-copy">
		<div class="es-diag-section-header">
			Compliance Shadow Copy
			<span style="display:flex;align-items:center;gap:6px;font-size:12px;color:#4b5563;">
				<span style="width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $status_dot ); ?>;"></span>
				<?php echo esc_html( $status ); ?>
			</span>
		</div>
		<div class="es-diag-section-body">
			<?php if ( $active ) : ?>
				<div style="background:#fffbeb;border-left:4px solid #dba617;padding:10px 12px;margin-bottom:14px;">
					<strong>Compliance Shadow Copy activo</strong><br>
					<span style="font-size:12px;color:#4b5563;">Los correos Compliance enviados a operadores están siendo copiados a <?php echo esc_html( $settings['destination'] ); ?>. Expira en <?php echo esc_html( (string) $days_left ); ?> día(s).</span>
				</div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid;gap:12px;max-width:560px;">
				<input type="hidden" name="action" value="estandar_core_save_compliance_shadow_copy">
				<?php wp_nonce_field( 'estandar_compliance_shadow_copy_save' ); ?>
				<label><input type="checkbox" name="enabled" value="1" <?php checked( $active ); ?>> Activar Compliance Shadow Copy</label>
				<label>Destino<br><input type="email" class="regular-text" name="destination" value="<?php echo esc_attr( (string) $settings['destination'] ); ?>"></label>
				<div>
					<strong style="font-size:12px;">Duración</strong><br>
					<?php foreach ( [ 7, 14, 30 ] as $days ) : ?>
						<label style="margin-right:14px;"><input type="radio" name="duration_days" value="<?php echo esc_attr( (string) $days ); ?>" <?php checked( (int) $settings['duration_days'], $days ); ?>> <?php echo esc_html( (string) $days ); ?> días</label>
					<?php endforeach; ?>
				</div>
				<?php if ( ! empty( $settings['activated_at'] ) ) : ?>
					<div style="font-size:12px;color:#6b7280;">
						Activación: <?php echo esc_html( get_date_from_gmt( (string) $settings['activated_at'], 'd/m/Y H:i' ) ); ?>
						· Expiración: <?php echo esc_html( get_date_from_gmt( (string) $settings['expires_at'], 'd/m/Y H:i' ) ); ?>
						<?php if ( $active ) : ?>· <?php echo esc_html( (string) $days_left ); ?> día(s) restantes<?php endif; ?>
					</div>
				<?php endif; ?>
				<div><button type="submit" class="button button-primary"><?php echo $expired ? 'Reactivar' : 'Guardar'; ?></button></div>
			</form>
		</div>
	</div>
	<?php
}
