<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! estandar_chatbot_user_can_view_tab( 'inicio' ) ) {
	wp_die( esc_html__( 'No autorizado.', 'estandar-chatbot' ) );
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$inline_page_id       = (int) get_option( 'estandar_chatbot_inline_page_id', 0 );
$inline_url           = $inline_page_id > 0 ? get_permalink( $inline_page_id ) : '';

$leads_total          = function_exists( 'estandar_chatbot_count_leads' ) ? (int) estandar_chatbot_count_leads() : 0;
$leads_today          = function_exists( 'estandar_chatbot_count_leads_today' ) ? (int) estandar_chatbot_count_leads_today() : 0;
$open_conversations   = function_exists( 'estandar_chatbot_get_conversations' ) ? (array) estandar_chatbot_get_conversations( 5, 0, [ 'conversation_status' => 'open' ] ) : [];
$summary_7d           = function_exists( 'estandar_chatbot_get_analytics_summary' ) ? (array) estandar_chatbot_get_analytics_summary( 7 ) : [];
$geo_7d               = [ 'points' => [], 'unique_places' => 0, 'mapped_interactions' => 0 ]; // map promoted to Primer; collection intact
$operational_readiness = function_exists( 'estandar_chatbot_get_operational_readiness' ) ? estandar_chatbot_get_operational_readiness() : [];
$readiness_evidence   = (array) ( $operational_readiness['evidence'] ?? [] );
$readiness_action     = (array) ( $operational_readiness['action'] ?? [] );
$readiness_status     = (string) ( $operational_readiness['readiness'] ?? 'WARNING' );
$chatbot_status       = (string) ( $operational_readiness['chatbot_status'] ?? 'INACTIVE' );
$latest_email_at      = (string) ( $readiness_evidence['latest_email_effective_at'] ?? $readiness_evidence['latest_email_at'] ?? '' );
$latest_export_at     = (string) ( $readiness_evidence['latest_export_at'] ?? '' );
$leads_export_url     = wp_nonce_url(
	admin_url( 'admin-post.php?action=estandar_chatbot_export_leads_csv' ),
	'ecb_download_leads'
);

// Checks de configuración (desde project-status-widget.php)
$all_checks     = function_exists( 'estandar_chatbot_get_instance_checks' ) ? estandar_chatbot_get_instance_checks() : [];
$pending_checks = array_values( array_filter( $all_checks, fn( $c ) => ( $c['status'] ?? 'ok' ) !== 'ok' ) );
$has_critical   = ! empty( array_filter( $pending_checks, fn( $c ) => ( $c['status'] ?? '' ) === 'critical' ) );

// Colores por estado readiness
$status_colors = [
	'READY'    => [ 'bg' => '#ecfdf5', 'border' => '#6ee7b7', 'text' => '#166534', 'dot' => '#16a34a' ],
	'WARNING'  => [ 'bg' => '#fffbeb', 'border' => '#fde68a', 'text' => '#92400e', 'dot' => '#d97706' ],
	'CRITICAL' => [ 'bg' => '#fef2f2', 'border' => '#fecaca', 'text' => '#991b1b', 'dot' => '#dc2626' ],
];
$sc = $status_colors[ $readiness_status ] ?? $status_colors['WARNING'];

$conv_rate           = (int) ( $summary_7d['conversion_rate'] ?? 0 );
?>
<div class="ecb-inicio-tab">

	<?php // ===================================================================
	// ZONA 1: ESTADO GLOBAL
	// =================================================================== ?>
	<div style="
		background:<?php echo esc_attr( $sc['bg'] ); ?>;
		border:1px solid <?php echo esc_attr( $sc['border'] ); ?>;
		border-radius:10px;
		padding:20px 24px;
		margin-bottom:20px;
		display:flex;
		flex-wrap:wrap;
		gap:16px;
		align-items:center;
		justify-content:space-between;
	">
		<div style="display:flex;align-items:center;gap:14px;">
			<span style="
				width:14px;height:14px;border-radius:50%;
				background:<?php echo esc_attr( $sc['dot'] ); ?>;
				flex-shrink:0;display:inline-block;
				box-shadow:0 0 0 4px <?php echo esc_attr( $sc['border'] ); ?>;
			"></span>
			<div>
				<div style="font-size:20px;font-weight:700;color:<?php echo esc_attr( $sc['text'] ); ?>;line-height:1.2;">
					<?php echo esc_html( $chatbot_status ); ?>
					<span style="font-size:14px;font-weight:400;margin-left:8px;opacity:.75;"><?php echo esc_html( $readiness_status ); ?></span>
				</div>
				<?php if ( $readiness_status === 'READY' ) : ?>
					<?php
					$notification_mode_label = '';
					$r_notification_mode = (string) ( $readiness_evidence['notification_mode'] ?? '' );
					if ( $r_notification_mode === 'leads' ) {
						$notification_mode_label = 'Email vía Leads';
					} elseif ( $latest_email_at ) {
						$notification_mode_label = 'Email ' . $latest_email_at;
					} else {
						$notification_mode_label = 'sin email reciente';
					}
					?>
					<div style="font-size:13px;color:<?php echo esc_attr( $sc['text'] ); ?>;margin-top:4px;opacity:.8;">
						<?php echo esc_html( 'Chatbot operativo · ' . $notification_mode_label ); ?>
						<?php if ( $latest_export_at ) : ?>
							&nbsp;·&nbsp;<?php echo esc_html( 'Export ' . $latest_export_at ); ?>
						<?php endif; ?>
					</div>
				<?php elseif ( ! empty( $operational_readiness['reasons'] ) ) : ?>
					<div style="font-size:13px;color:<?php echo esc_attr( $sc['text'] ); ?>;margin-top:4px;">
						<?php
						$reason_labels = [];
						foreach ( (array) $operational_readiness['reasons'] as $r ) {
							$reason_labels[] = function_exists( 'estandar_chatbot_readiness_reason_label' )
								? estandar_chatbot_readiness_reason_label( $r )
								: (string) $r;
						}
						echo esc_html( implode( ' · ', $reason_labels ) );
						?>
					</div>
					<?php if ( ! empty( $readiness_action['url'] ) && ! empty( $readiness_action['label'] ) ) : ?>
						<a href="<?php echo esc_url( (string) $readiness_action['url'] ); ?>" class="button button-small" style="margin-top:8px;">
							<?php echo esc_html( (string) $readiness_action['label'] ); ?>
						</a>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<div style="display:flex;flex-wrap:wrap;gap:8px;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=leads' ) ); ?>" class="button">Ver leads</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=conversaciones' ) ); ?>" class="button">Pendientes (<?php echo esc_html( (string) count( $open_conversations ) ); ?>)</a>
			<?php if ( $inline_url ) : ?>
				<a href="<?php echo esc_url( $inline_url ); ?>" class="button" target="_blank" rel="noopener">Probar chat →</a>
			<?php endif; ?>
		</div>
	</div>

	<?php // ===================================================================
	// ZONA 2: ACCIONES PENDIENTES (solo si hay checks != ok)
	// =================================================================== ?>
	<?php if ( ! empty( $pending_checks ) ) : ?>
		<div style="margin-bottom:20px;">
			<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
				<span style="font-weight:700;font-size:14px;color:<?php echo $has_critical ? '#991b1b' : '#92400e'; ?>;">
					<?php echo $has_critical ? '⚠ Requiere atención' : '· Pendiente de revisión'; ?>
				</span>
				<span style="font-size:12px;color:#6b7280;">(<?php echo count( $pending_checks ); ?> de <?php echo count( $all_checks ); ?> checks)</span>
			</div>
			<div style="display:flex;flex-direction:column;gap:8px;">
				<?php foreach ( $pending_checks as $check ) :
					$s      = (string) ( $check['status'] ?? 'warning' );
					$c_text = $s === 'critical' ? '#991b1b' : '#92400e';
					$c_bg   = $s === 'critical' ? '#fef2f2' : '#fffbeb';
					$c_brd  = $s === 'critical' ? '#fecaca' : '#fde68a';
				?>
					<div style="
						background:<?php echo esc_attr( $c_bg ); ?>;
						border:1px solid <?php echo esc_attr( $c_brd ); ?>;
						border-left:4px solid <?php echo esc_attr( $c_text ); ?>;
						border-radius:8px;
						padding:12px 16px;
						display:flex;
						align-items:center;
						justify-content:space-between;
						gap:16px;
						flex-wrap:wrap;
					">
						<div>
							<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:<?php echo esc_attr( $c_text ); ?>;opacity:.7;margin-bottom:2px;"><?php echo esc_html( (string) $check['label'] ); ?></div>
							<div style="font-weight:600;color:<?php echo esc_attr( $c_text ); ?>;"><?php echo esc_html( (string) $check['text'] ); ?></div>
							<div style="font-size:12px;color:#6b7280;margin-top:2px;"><?php echo esc_html( (string) $check['desc'] ); ?></div>
						</div>
						<a class="button <?php echo $s === 'critical' ? 'button-primary' : ''; ?>" href="<?php echo esc_url( (string) $check['url'] ); ?>" style="flex-shrink:0;"><?php echo esc_html( (string) $check['button'] ); ?></a>
					</div>
				<?php endforeach; ?>
			</div>
			<p style="margin:8px 0 0;font-size:12px;color:#6b7280;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar_core_diagnostics' ) ); ?>">Ver diagnóstico completo →</a>
			</p>
		</div>
	<?php else : ?>
		<p style="font-size:13px;color:#166534;margin-bottom:20px;">
			✓ <strong>Configuración completa.</strong>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar_core_diagnostics' ) ); ?>" style="color:#166534;margin-left:8px;">Ver diagnóstico →</a>
		</p>
	<?php endif; ?>

	<?php // ===================================================================
	// ZONA 3: MÉTRICAS
	// =================================================================== ?>
	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px;">

		<div class="ecb-card" style="padding:16px 18px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:6px;">Leads hoy</div>
			<div style="font-size:32px;font-weight:700;line-height:1;color:#111827;"><?php echo esc_html( (string) $leads_today ); ?></div>
			<div style="font-size:12px;color:#9ca3af;margin-top:4px;">Capturas del día actual</div>
		</div>

		<div class="ecb-card" style="padding:16px 18px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:6px;">Leads totales</div>
			<div style="font-size:32px;font-weight:700;line-height:1;color:#111827;"><?php echo esc_html( (string) $leads_total ); ?></div>
			<div style="font-size:12px;color:#9ca3af;margin-top:4px;margin-bottom:10px;">Base completa</div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=leads' ) ); ?>" class="button button-small">Abrir leads</a>
		</div>

		<div class="ecb-card" style="padding:16px 18px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:6px;">Conversión 7 días</div>
			<div style="font-size:32px;font-weight:700;line-height:1;color:<?php echo $conv_rate >= 15 ? '#166534' : ( $conv_rate >= 5 ? '#92400e' : '#6b7280' ); ?>;">
				<?php echo esc_html( (string) $conv_rate ); ?><span style="font-size:18px;">%</span>
			</div>
			<div style="font-size:12px;color:#9ca3af;margin-top:4px;">
				<?php echo $conv_rate >= 15 ? 'Buen ritmo' : ( $conv_rate >= 5 ? 'Ritmo moderado' : 'Sin conversiones aún' ); ?>
			</div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=analitica' ) ); ?>" class="button button-small" style="margin-top:10px;">Ver embudo</a>
		</div>

		<div class="ecb-card" style="padding:16px 18px;">
			<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:6px;">Conversaciones abiertas</div>
			<div style="font-size:32px;font-weight:700;line-height:1;color:#111827;"><?php echo esc_html( (string) count( $open_conversations ) ); ?></div>
			<div style="font-size:12px;color:#9ca3af;margin-top:4px;margin-bottom:10px;"><?php echo empty( $open_conversations ) ? 'Sin conversaciones pendientes' : 'Pendientes de respuesta'; ?></div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=conversaciones' ) ); ?>" class="button button-small">Abrir conversaciones</a>
		</div>

	</div>


	<?php // ===================================================================
	// ZONA 5: ACCIONES SECUNDARIAS
	// =================================================================== ?>
	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;padding-top:16px;border-top:1px solid #e5e7eb;">
		<div>
			<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:8px;">Análisis</div>
			<div style="display:flex;flex-wrap:wrap;gap:8px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=analitica' ) ); ?>" class="button">Embudo</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=logs' ) ); ?>" class="button">Problemas detectados</a>
			</div>
		</div>
		<div>
			<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:8px;">Administración</div>
			<div style="display:flex;flex-wrap:wrap;gap:8px;">
				<a href="<?php echo esc_url( $leads_export_url ); ?>" class="button">Exportar CSV</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-chatbot&tab=herramientas' ) ); ?>" class="button">Sistema</a>
			</div>
		</div>
	</div>

</div>
