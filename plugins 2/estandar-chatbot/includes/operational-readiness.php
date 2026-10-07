<?php
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ESTANDAR_CHATBOT_READINESS_RECENT_DAYS', 14 );

function estandar_chatbot_readiness_table_exists( $table ) {
	global $wpdb;
	$table = (string) $table;
	if ( $table === '' ) {
		return false;
	}
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return (string) $found === $table;
}

function estandar_chatbot_readiness_is_recent( $datetime ) {
	$timestamp = strtotime( (string) $datetime );
	if ( ! $timestamp ) {
		return false;
	}
	return $timestamp >= ( time() - ( ESTANDAR_CHATBOT_READINESS_RECENT_DAYS * DAY_IN_SECONDS ) );
}

function estandar_chatbot_readiness_valid_recipients() {
	$raw_to = (string) get_option( 'chatbot_notify_email_to', '' );
	if ( trim( $raw_to ) === '' ) {
		$raw_to = (string) get_option( 'admin_email', '' );
	}

	$recipients = [];
	foreach ( explode( ',', $raw_to ) as $mail ) {
		$san = sanitize_email( trim( $mail ) );
		if ( is_email( $san ) ) {
			$recipients[] = $san;
		}
	}

	return array_values( array_unique( $recipients ) );
}

function estandar_chatbot_readiness_latest_event_by_meta( $event_name, array $meta_filters = [], $limit = 50 ) {
	global $wpdb;
	$table = function_exists( 'estandar_chatbot_get_events_table' ) ? estandar_chatbot_get_events_table() : $wpdb->prefix . 'ecb_events';
	if ( ! estandar_chatbot_readiness_table_exists( $table ) ) {
		return null;
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, created_at, event_name, meta FROM {$table} WHERE event_name = %s ORDER BY id DESC LIMIT %d",
			(string) $event_name,
			max( 1, min( 100, (int) $limit ) )
		),
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		$meta = json_decode( (string) ( $row['meta'] ?? '{}' ), true );
		$meta = is_array( $meta ) ? $meta : [];
		$matches = true;
		foreach ( $meta_filters as $key => $expected ) {
			if ( (string) ( $meta[ $key ] ?? '' ) !== (string) $expected ) {
				$matches = false;
				break;
			}
		}
		if ( $matches ) {
			$row['meta'] = $meta;
			return $row;
		}
	}

	return null;
}

function estandar_chatbot_readiness_latest_lead() {
	global $wpdb;
	$table = function_exists( 'estandar_chatbot_get_leads_table' ) ? estandar_chatbot_get_leads_table() : $wpdb->prefix . 'ecb_leads';
	if ( ! estandar_chatbot_readiness_table_exists( $table ) ) {
		return null;
	}
	$row = $wpdb->get_row( "SELECT id, created_at, last_interaction_at FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );
	return is_array( $row ) ? $row : null;
}

function estandar_chatbot_readiness_newest_event( $event_a, $event_b ) {
	if ( ! is_array( $event_a ) ) {
		return is_array( $event_b ) ? $event_b : null;
	}
	if ( ! is_array( $event_b ) ) {
		return $event_a;
	}

	$time_a = strtotime( (string) ( $event_a['created_at'] ?? '' ) );
	$time_b = strtotime( (string) ( $event_b['created_at'] ?? '' ) );

	return $time_b > $time_a ? $event_b : $event_a;
}

function estandar_chatbot_get_operational_readiness() {
	$email_enabled = (int) get_option( 'chatbot_notify_email_enabled', 1 ) === 1;
	$recipients = estandar_chatbot_readiness_valid_recipients();
	$has_recipient = ! empty( $recipients );
	$notification_mode = sanitize_key( (string) get_option( 'chatbot_notification_mode', 'legacy' ) );
	$is_leads_mode = $notification_mode === 'leads';

	$latest_email = estandar_chatbot_readiness_latest_event_by_meta( 'lead.delivery_status', [ 'delivery_channel' => 'email' ] );
	$latest_email_meta = is_array( $latest_email ) ? (array) ( $latest_email['meta'] ?? [] ) : [];
	$latest_email_status = sanitize_key( (string) ( $latest_email_meta['status'] ?? '' ) );
	$latest_email_message = sanitize_key( (string) ( $latest_email_meta['message'] ?? '' ) );
	$latest_email_recent = is_array( $latest_email ) && estandar_chatbot_readiness_is_recent( $latest_email['created_at'] ?? '' );
	$latest_email_skipped_is_leads_mode = $latest_email_recent
		&& $latest_email_status === 'skipped'
		&& in_array( $latest_email_message, [ 'leads_notification_mode', 'leads_mode' ], true );
	$latest_email_skipped_is_stale_config = $latest_email_recent
		&& $latest_email_status === 'skipped'
		&& $email_enabled
		&& $has_recipient
		&& in_array( $latest_email_message, [ 'missing_recipients', 'disabled', 'email_disabled' ], true );

	$latest_email_validation = estandar_chatbot_readiness_latest_event_by_meta( 'email.delivery_validation', [ 'delivery_channel' => 'email' ] );
	$latest_email_validation_meta = is_array( $latest_email_validation ) ? (array) ( $latest_email_validation['meta'] ?? [] ) : [];
	$latest_email_validation_status = sanitize_key( (string) ( $latest_email_validation_meta['status'] ?? '' ) );
	$latest_email_validation_message = sanitize_key( (string) ( $latest_email_validation_meta['message'] ?? '' ) );
	$latest_email_validation_recent = is_array( $latest_email_validation ) && estandar_chatbot_readiness_is_recent( $latest_email_validation['created_at'] ?? '' );
	$latest_email_validation_success = $latest_email_validation_recent && $latest_email_validation_status === 'success';
	$latest_email_delivery_success = $latest_email_recent && $latest_email_status === 'success';

	$leads_plugin_delivers_email = $is_leads_mode && function_exists( 'estandar_leads_version' );
	$email_delivery_validated = $latest_email_delivery_success || $latest_email_validation_success || $leads_plugin_delivers_email;
	$latest_email_evidence = estandar_chatbot_readiness_newest_event(
		$latest_email_delivery_success ? $latest_email : null,
		$latest_email_validation_success ? $latest_email_validation : null
	);
	$latest_email_evidence_meta = is_array( $latest_email_evidence ) ? (array) ( $latest_email_evidence['meta'] ?? [] ) : [];
	$latest_email_evidence_status = sanitize_key( (string) ( $latest_email_evidence_meta['status'] ?? '' ) );
	$latest_email_evidence_source = is_array( $latest_email_evidence ) && (string) ( $latest_email_evidence['event_name'] ?? '' ) === 'email.delivery_validation' ? 'validation' : 'lead';

	$latest_export = estandar_chatbot_readiness_latest_event_by_meta( 'csv.export' );
	$latest_export_recent = is_array( $latest_export ) && estandar_chatbot_readiness_is_recent( $latest_export['created_at'] ?? '' );

	$latest_lead = estandar_chatbot_readiness_latest_lead();
	$latest_lead_recent = is_array( $latest_lead ) && estandar_chatbot_readiness_is_recent( $latest_lead['created_at'] ?? '' );

	$active_checks = [
		'plugin_loaded'    => defined( 'ESTANDAR_CHATBOT_VERSION' ),
		'config_enabled'   => (int) get_option( 'chatbot_activar', 0 ) === 1,
		'render_available' => function_exists( 'estandar_chatbot_shortcode' ) && function_exists( 'estandar_chatbot_frontend_assets' ),
	];
	$active = ! in_array( false, $active_checks, true );

	$readiness = 'WARNING';
	$reasons = [];

	if ( ! $active ) {
		$readiness = 'CRITICAL';
		if ( ! $active_checks['config_enabled'] ) {
			$reasons[] = 'Chatbot disabled.';
		} else {
			$reasons[] = 'Frontend render capability unavailable.';
		}
	} elseif ( ! $email_enabled && ! $is_leads_mode ) {
		$readiness = 'CRITICAL';
		$reasons[] = 'Email delivery disabled.';
	} elseif ( ! $has_recipient && ! $is_leads_mode ) {
		$readiness = 'CRITICAL';
		$reasons[] = 'No valid lead recipient configured.';
	} elseif ( ! $latest_email_validation_success && $latest_email_recent && $latest_email_status === 'failed' && ! $latest_email_skipped_is_leads_mode ) {
		$readiness = 'CRITICAL';
		$reasons[] = 'Recent email delivery failed.';
	} elseif ( ! $latest_email_validation_success && $latest_email_recent && $latest_email_status === 'skipped' && ! $latest_email_skipped_is_stale_config && ! $latest_email_skipped_is_leads_mode ) {
		$readiness = 'CRITICAL';
		$reasons[] = 'Recent email delivery skipped.';
	} elseif ( $latest_email_validation_recent && $latest_email_validation_status === 'failed' ) {
		$readiness = 'CRITICAL';
		$reasons[] = 'Recent email validation failed.';
	} elseif ( $latest_lead_recent && ! is_array( $latest_email ) && ! $latest_email_validation_success && ! $leads_plugin_delivers_email ) {
		$readiness = 'CRITICAL';
		$reasons[] = 'Recent leads captured without email delivery evidence.';
	} elseif ( $leads_plugin_delivers_email && $latest_lead_recent ) {
		$readiness = 'READY';
		$reasons[] = 'Leads plugin handles email delivery.';
	} elseif ( $email_delivery_validated && $latest_export_recent ) {
		$readiness = 'READY';
		$reasons[] = $latest_email_validation_success && ! $latest_email_delivery_success
			? 'Recent email validation success and CSV export evidence found.'
			: 'Recent email delivery success and CSV export evidence found.';
	} elseif ( $leads_plugin_delivers_email ) {
		$readiness = 'READY';
		$reasons[] = 'Leads plugin handles email delivery.';
	} else {
		$readiness = 'WARNING';
		if ( $email_delivery_validated && ! $latest_email_delivery_success ) {
			$reasons[] = 'Recent email validation success found.';
		} elseif ( $latest_email_skipped_is_stale_config ) {
			$reasons[] = 'Email configured; pending new delivery validation.';
		} elseif ( ! $latest_email_recent && ! $latest_email_validation_recent ) {
			$reasons[] = is_array( $latest_email ) ? 'No recent email delivery validation.' : 'No email delivery evidence yet.';
		}
		if ( ! $latest_export_recent ) {
			$reasons[] = is_array( $latest_export ) ? 'No recent CSV export validation.' : 'No CSV export evidence yet.';
		}
		if ( ! $latest_lead_recent ) {
			$reasons[] = 'No recent leads captured.';
		}
	}

	$action = estandar_chatbot_get_operational_readiness_action( $reasons, $readiness );

	return [
		'chatbot_status' => $active ? 'ACTIVE' : 'INACTIVE',
		'readiness'      => $readiness,
		'reasons'        => array_values( array_unique( $reasons ) ),
		'action'         => $action,
		'evidence'       => [
			'notification_mode'   => $notification_mode,
			'email_enabled'       => $email_enabled,
			'recipients_count'    => count( $recipients ),
			'latest_email_status' => $latest_email_status,
			'latest_email_message'=> $latest_email_message,
			'latest_email_at'     => is_array( $latest_email ) ? (string) ( $latest_email['created_at'] ?? '' ) : '',
			'latest_email_validation_status' => $latest_email_validation_status,
			'latest_email_validation_message'=> $latest_email_validation_message,
			'latest_email_validation_at'     => is_array( $latest_email_validation ) ? (string) ( $latest_email_validation['created_at'] ?? '' ) : '',
			'latest_email_effective_status'  => $latest_email_evidence_status,
			'latest_email_effective_source'  => $latest_email_evidence_source,
			'latest_email_effective_at'      => is_array( $latest_email_evidence ) ? (string) ( $latest_email_evidence['created_at'] ?? '' ) : '',
			'latest_export_at'    => is_array( $latest_export ) ? (string) ( $latest_export['created_at'] ?? '' ) : '',
			'latest_export_source'=> is_array( $latest_export ) ? (string) ( $latest_export['meta']['source'] ?? '' ) : '',
			'latest_lead_at'      => is_array( $latest_lead ) ? (string) ( $latest_lead['created_at'] ?? '' ) : '',
		],
	];
}

function estandar_chatbot_get_operational_readiness_action( array $reasons, $readiness ) {
	$first_reason = (string) ( $reasons[0] ?? '' );
	$reason_text = implode( ' ', array_map( 'strval', $reasons ) );
	$base_url = admin_url( 'admin.php?page=estandar-chatbot' );

	if ( stripos( $first_reason, 'Chatbot disabled' ) !== false ) {
		return [
			'label' => __( 'Activar chatbot', 'estandar-chatbot' ),
			'url'   => $base_url . '&tab=configuracion',
		];
	}

	if (
		stripos( $reason_text, 'Email delivery' ) !== false ||
		stripos( $reason_text, 'email validation failed' ) !== false ||
		stripos( $reason_text, 'recipient' ) !== false ||
		stripos( $reason_text, 'delivery evidence' ) !== false ||
		stripos( $reason_text, 'pending new delivery validation' ) !== false
	) {
		return [
			'label' => stripos( $reason_text, 'pending new delivery validation' ) !== false || stripos( $reason_text, 'email validation failed' ) !== false ? __( 'Validar email', 'estandar-chatbot' ) : __( 'Resolver email de leads', 'estandar-chatbot' ),
			'url'   => stripos( $reason_text, 'pending new delivery validation' ) !== false || stripos( $reason_text, 'email validation failed' ) !== false ? $base_url . '&tab=herramientas' : $base_url . '&tab=integraciones#ecb-email-delivery',
		];
	}

	if ( stripos( $reason_text, 'CSV export' ) !== false ) {
		return [
			'label' => __( 'Validar export CSV', 'estandar-chatbot' ),
			'url'   => $base_url . '&tab=leads',
		];
	}

	if ( stripos( $reason_text, 'No recent leads' ) !== false ) {
		return [
			'label' => __( 'Revisar captura', 'estandar-chatbot' ),
			'url'   => $base_url . '&tab=leads-captacion',
		];
	}

	if ( 'READY' === (string) $readiness ) {
		return [
			'label' => __( 'Ver estado operacional', 'estandar-chatbot' ),
			'url'   => $base_url . '&tab=estado-operacional',
		];
	}

	return [
		'label' => __( 'Revisar configuración', 'estandar-chatbot' ),
		'url'   => $base_url . '&tab=integraciones',
	];
}

function estandar_chatbot_readiness_reason_label( $reason ) {
	$reason = (string) $reason;
	$labels = [
		'Chatbot disabled.' => __( 'El chatbot está desactivado.', 'estandar-chatbot' ),
		'Frontend render capability unavailable.' => __( 'La capacidad de render frontend no está disponible.', 'estandar-chatbot' ),
		'Email delivery disabled.' => __( 'La entrega de leads por email está desactivada.', 'estandar-chatbot' ),
		'No valid lead recipient configured.' => __( 'No hay destinatario válido para recibir leads.', 'estandar-chatbot' ),
		'Recent email delivery failed.' => __( 'El envío reciente de email falló.', 'estandar-chatbot' ),
		'Recent email delivery skipped.' => __( 'El envío reciente de email fue omitido.', 'estandar-chatbot' ),
		'Recent email validation failed.' => __( 'La validación reciente de email falló.', 'estandar-chatbot' ),
		'Recent leads captured without email delivery evidence.' => __( 'Hay leads recientes sin evidencia de entrega por email.', 'estandar-chatbot' ),
		'Recent email delivery success and CSV export evidence found.' => __( 'Hay entrega de email correcta y evidencia de export CSV.', 'estandar-chatbot' ),
		'Recent email validation success and CSV export evidence found.' => __( 'Hay validación de email correcta y evidencia de export CSV.', 'estandar-chatbot' ),
		'Recent email validation success found.' => __( 'La validación de email fue correcta.', 'estandar-chatbot' ),
		'Email configured; pending new delivery validation.' => __( 'Email configurado; falta validar un nuevo envío.', 'estandar-chatbot' ),
		'No recent email delivery validation.' => __( 'No hay validación reciente de entrega por email.', 'estandar-chatbot' ),
		'No email delivery evidence yet.' => __( 'Todavía no hay evidencia de entrega por email.', 'estandar-chatbot' ),
		'No recent CSV export validation.' => __( 'No hay validación reciente de export CSV.', 'estandar-chatbot' ),
		'No CSV export evidence yet.' => __( 'Todavía no hay evidencia de export CSV.', 'estandar-chatbot' ),
		'No recent leads captured.' => __( 'No hay leads recientes capturados.', 'estandar-chatbot' ),
		'Leads plugin handles email delivery.' => __( 'La entrega de email la gestiona Estandar Leads.', 'estandar-chatbot' ),
	];

	return $labels[ $reason ] ?? $reason;
}

function estandar_chatbot_render_operational_readiness_card( array $readiness ) {
	$status = (string) ( $readiness['readiness'] ?? 'WARNING' );
	$colors = [
		'READY'    => [ 'bg' => '#ecfdf5', 'border' => '#a7f3d0', 'text' => '#166534' ],
		'WARNING'  => [ 'bg' => '#fffbeb', 'border' => '#fde68a', 'text' => '#92400e' ],
		'CRITICAL' => [ 'bg' => '#fef2f2', 'border' => '#fecaca', 'text' => '#991b1b' ],
	];
	$color = $colors[ $status ] ?? $colors['WARNING'];
	$evidence = (array) ( $readiness['evidence'] ?? [] );
	$reasons = (array) ( $readiness['reasons'] ?? [] );
	$action = (array) ( $readiness['action'] ?? [] );
	?>
	<div class="ecb-card" style="border-color:<?php echo esc_attr( $color['border'] ); ?>;background:<?php echo esc_attr( $color['bg'] ); ?>;">
		<p class="ecb-metric" style="margin:0 0 8px;"><strong><?php esc_html_e( 'Readiness operacional', 'estandar-chatbot' ); ?></strong></p>
		<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px;">
			<span class="ecb-badge ecb-badge--status"><?php echo esc_html( (string) ( $readiness['chatbot_status'] ?? 'INACTIVE' ) ); ?></span>
			<strong style="color:<?php echo esc_attr( $color['text'] ); ?>;font-size:18px;"><?php echo esc_html( $status ); ?></strong>
		</div>
		<?php if ( ! empty( $reasons ) ) : ?>
			<ul style="margin:0 0 10px 18px;">
				<?php foreach ( $reasons as $reason ) : ?>
					<li><?php echo esc_html( estandar_chatbot_readiness_reason_label( $reason ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( ! empty( $action['url'] ) && ! empty( $action['label'] ) ) : ?>
			<p style="margin:0 0 12px;">
				<a class="button button-primary" href="<?php echo esc_url( (string) $action['url'] ); ?>">
					<?php echo esc_html( (string) $action['label'] ); ?>
				</a>
			</p>
		<?php endif; ?>
		<p class="description" style="margin:0;">
			<?php
			$latest_email = (string) ( $evidence['latest_email_effective_at'] ?? '' );
			if ( $latest_email === '' ) {
				$latest_email = (string) ( $evidence['latest_email_at'] ?? '' );
			}
			$latest_export = (string) ( $evidence['latest_export_at'] ?? '' );
			$email_label = (string) ( $evidence['latest_email_effective_source'] ?? '' ) === 'validation' ? 'Última validación email: ' : 'Último email: ';
			echo esc_html( $email_label . ( $latest_email !== '' ? $latest_email : 'sin evidencia' ) );
			echo '<br>';
			echo esc_html( 'Último export: ' . ( $latest_export !== '' ? $latest_export : 'sin evidencia' ) );
			?>
		</p>
	</div>
	<?php
}
