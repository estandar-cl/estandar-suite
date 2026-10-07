<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$event_filter = $event_filter ?? 'all';
$journey_context = is_array( $journey_context ?? null ) ? $journey_context : array();
$base_url = admin_url( 'admin.php?page=ea-events' );
$session_id = (string) ( $filters['session_id'] ?? '' );
$tabs_base_url = $session_id !== ''
	? add_query_arg( 'session_id', rawurlencode( $session_id ), $base_url )
	: $base_url;
$tabs = array(
	'all'         => __( 'Todos', 'estandar-analytics' ),
	'pageview'    => __( 'Pageviews', 'estandar-analytics' ),
	'form_start'  => __( 'Form Starts', 'estandar-analytics' ),
	'form_submit' => __( 'Form Submits', 'estandar-analytics' ),
	'chatbot'     => __( 'Chatbot', 'estandar-analytics' ),
);
	?>
	<?php
	if ( $session_id !== '' && ! empty( $journey_context['project'] ) ) {
		EA_Admin_UI::render_project_trust_badge( 'exact', __( 'Fuente: contexto de sesión (lead + submission)', 'estandar-analytics' ) );
	} else {
		EA_Admin_UI::render_project_trust_badge( 'unsupported' );
	}
	?>

	<section class="ea-panel">
	<header class="ea-panel__head">
		<h2><?php esc_html_e( 'Eventos recientes', 'estandar-analytics' ); ?></h2>
	</header>

	<div style="display:flex;gap:6px;flex-wrap:wrap;padding:14px 18px 0;">
		<?php if ( $session_id !== '' ) : ?>
			<span class="button disabled" style="pointer-events:none;"><?php echo esc_html( 'Session: ' . $session_id ); ?></span>
		<?php endif; ?>
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a class="button<?php echo $event_filter === $key ? ' button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'ea_event', $key, $tabs_base_url ) ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</div>

	<?php if ( $session_id !== '' && ! empty( $journey_context ) ) : ?>
		<div style="margin:14px 18px 0;padding:12px 14px;border:1px solid #dcdcde;background:#fff;">
			<div style="display:flex;gap:12px 18px;align-items:center;flex-wrap:wrap;">
				<strong><?php esc_html_e( 'Contexto de sesión', 'estandar-analytics' ); ?></strong>
				<span><?php esc_html_e( 'Lead', 'estandar-analytics' ); ?>: <?php echo ! empty( $journey_context['lead_id'] ) ? '#' . esc_html( (string) $journey_context['lead_id'] ) : '-'; ?></span>
				<span><?php esc_html_e( 'Submission', 'estandar-analytics' ); ?>: <?php echo ! empty( $journey_context['submission_id'] ) ? '#' . esc_html( (string) $journey_context['submission_id'] ) : '-'; ?></span>
				<span><?php esc_html_e( 'Proyecto', 'estandar-analytics' ); ?>: <?php echo esc_html( resolve_project_display_name( $journey_context['project'] ?? '', '' ) ?: '-' ); ?></span>
				<span><?php esc_html_e( 'Canal', 'estandar-analytics' ); ?>: <?php echo esc_html( (string) ( $journey_context['channel'] ?: '-' ) ); ?></span>
				<span><?php esc_html_e( 'Operador', 'estandar-analytics' ); ?>: <?php echo esc_html( (string) ( $journey_context['operator'] ?: '-' ) ); ?></span>
				<?php if ( ! empty( $journey_context['lead_url'] ) ) : ?>
					<a class="button button-small" href="<?php echo esc_url( (string) $journey_context['lead_url'] ); ?>"><?php esc_html_e( 'Ver Lead', 'estandar-analytics' ); ?></a>
				<?php endif; ?>
				<?php if ( ! empty( $journey_context['submission_url'] ) ) : ?>
					<a class="button button-small" href="<?php echo esc_url( (string) $journey_context['submission_url'] ); ?>"><?php esc_html_e( 'Ver Submission', 'estandar-analytics' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( empty( $events ) ) : ?>
		<?php EA_Admin_UI::render_empty( __( 'No hay eventos visibles en este rango.', 'estandar-analytics' ) ); ?>
	<?php else : ?>
		<div class="ea-table-scroll" style="margin-top:14px;">
			<table class="ea-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Fecha', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Evento', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Session ID', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Landing', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Form ID', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Project', 'estandar-analytics' ); ?></th>
						<th><?php esc_html_e( 'Source', 'estandar-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $events as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['occurred_at'] ?? '' ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $row['event_type'] ?? '' ) ); ?></code></td>
						<td><code><?php echo esc_html( $session_id !== '' ? (string) ( $row['session_id'] ?? '' ) : substr( (string) ( $row['session_id'] ?? '' ), 0, 12 ) . '...' ); ?></code></td>
						<td><?php echo esc_html( wp_html_excerpt( (string) ( $row['landing'] ?? '' ), 64, '...' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['form_id'] ?: '-' ) ); ?></td>
							<td><?php echo esc_html( resolve_project_display_name( $row['project'] ?? '', '' ) ?: '-' ); ?></td>
							<td><?php echo esc_html( (string) ( $row['source'] ?: '-' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
