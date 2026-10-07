<?php
/**
 * Acquisition Intelligence — executive commercial funnel by utm_campaign.
 *
 * Variables in scope (via shell() extract):
 *   $filters  — active filters (from/to/project)
 *   $options  — available filter options
 *   $mode     — 'list' | 'detail'
 *   $rows     — aggregated campaign rows (list) or per-lead rows (detail)
 *   $campaign — selected campaign (detail mode)
 *   $global   — global executive summary (list mode)
 *   $top      — top campaigns by metric (list mode)
 *   $summary  — single-campaign summary card (detail mode)
 *
 * Read-only. Simple cards/tables, no charts, no CSS framework.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$page_slug = 'ea-acquisition-intel';

// Period range shortcuts (reused Core/Analytics helper, guarded).
if ( class_exists( 'EA_Admin_UI' ) && method_exists( 'EA_Admin_UI', 'render_range_shortcuts' ) ) {
	EA_Admin_UI::render_range_shortcuts( $filters, $page_slug );
}

$campaign_label = static function ( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return __( '(Sin campaña)', 'estandar-analytics' );
	}
	// Campaign Labels (core): solo IDs numéricos de plataforma pasan por el
	// resolver (un utm_campaign humano como "parronales" se muestra tal cual).
	if ( class_exists( 'Estandar_Campaign_Label_Resolver' ) && ctype_digit( $value ) && strlen( $value ) >= 8 ) {
		$resolved = (string) Estandar_Campaign_Label_Resolver::campaign( $value );
		if ( '' !== $resolved ) {
			return $resolved;
		}
	}
	return $value;
};
$pct = static function ( $value ) {
	return $value === null ? '—' : ( (string) $value . '%' );
};
$money = static function ( $value ) {
	return $value === null ? '—' : esc_html( $value );
};

if ( ( $mode ?? 'list' ) === 'detail' ) :

	$s = is_array( $summary ?? null ) ? $summary : array();
	$back_url = add_query_arg(
		array_filter( array(
			'page'    => $page_slug,
			'from'    => $filters['from'] ?? '',
			'to'      => $filters['to'] ?? '',
			'project' => $filters['project'] ?? '',
		) ),
		admin_url( 'admin.php' )
	);
	?>
	<p style="margin:8px 0 16px;">
		<a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Volver a campañas', 'estandar-analytics' ); ?></a>
	</p>

	<?php /* ── CAMPAIGN SUMMARY CARD ─────────────────────────────────────── */ ?>
	<div class="ea-panel" style="border:1px solid #dcdcde;border-radius:6px;padding:16px 18px;margin:0 0 18px;background:#fff;">
		<h2 style="font-size:16px;margin:0 0 4px;"><?php echo esc_html( $campaign_label( $campaign ?? '' ) ); ?></h2>
		<p style="margin:0 0 14px;color:#646970;font-size:12px;">
			<?php esc_html_e( 'Período', 'estandar-analytics' ); ?>: <?php echo esc_html( $s['period'] !== '' ? $s['period'] : '—' ); ?>
		</p>
		<table class="widefat striped" style="max-width:760px;">
			<tbody>
				<tr><td><strong><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $s['leads'] ?? 0 ); ?></td>
					<td><strong><?php esc_html_e( 'Contacted', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $s['contacted'] ?? 0 ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Qualified', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $s['qualified'] ?? 0 ); ?></td>
					<td><strong><?php esc_html_e( 'Proposal Sent', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $s['proposal_sent'] ?? 0 ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Proposal Accepted', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $s['proposal_accepted'] ?? 0 ); ?></td>
					<td><strong><?php esc_html_e( 'Won', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $s['won'] ?? 0 ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Lost', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $s['lost'] ?? 0 ); ?></td>
					<td><strong><?php esc_html_e( 'Revenue', 'estandar-analytics' ); ?></strong></td><td><?php echo $money( $s['revenue'] ?? null ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Google IDs captured', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $s['google_ids'] ?? 0 ); ?></td>
					<td><strong><?php esc_html_e( 'Meta IDs captured', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $s['meta_ids'] ?? 0 ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Conversion %', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $pct( $s['conversion_pct'] ?? null ) ); ?></td>
					<td><strong><?php esc_html_e( 'Qualification %', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $pct( $s['qualification_pct'] ?? null ) ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Acceptance %', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $pct( $s['acceptance_pct'] ?? null ) ); ?></td>
					<td><strong><?php esc_html_e( 'Win %', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $pct( $s['win_pct'] ?? null ) ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<?php /* ── EXISTING LEAD TABLE (unchanged) ───────────────────────────── */ ?>
	<h3 style="font-size:14px;margin:0 0 8px;"><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></h3>
	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'Sin leads para esta campaña en el período seleccionado.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Lead', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Fecha (timeline)', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Estado actual', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Operador', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Landing page', 'estandar-analytics' ); ?></th>
				<th>utm_source</th>
				<th>utm_medium</th>
				<th>utm_campaign</th>
				<th>gclid</th>
				<th>fbclid</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $r ) : ?>
			<tr>
				<td>#<?php echo esc_html( $r['id'] ); ?></td>
				<td><?php echo esc_html( $r['created_at'] ); ?></td>
				<td><?php echo esc_html( $r['status'] !== '' ? $r['status'] : '—' ); ?></td>
				<td><?php echo esc_html( $r['operator'] !== '' ? $r['operator'] : '—' ); ?></td>
				<td style="max-width:280px;word-break:break-all;"><?php echo esc_html( $r['landing_url'] !== '' ? $r['landing_url'] : '—' ); ?></td>
				<td><?php echo esc_html( $r['utm_source'] !== '' ? $r['utm_source'] : '—' ); ?></td>
				<td><?php echo esc_html( $r['utm_medium'] !== '' ? $r['utm_medium'] : '—' ); ?></td>
				<td><?php echo esc_html( $campaign_label( $r['utm_campaign'] ) ); ?></td>
				<td><?php echo $r['gclid'] ? esc_html__( 'Yes', 'estandar-analytics' ) : esc_html__( 'No', 'estandar-analytics' ); ?></td>
				<td><?php echo $r['fbclid'] ? esc_html__( 'Yes', 'estandar-analytics' ) : esc_html__( 'No', 'estandar-analytics' ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

<?php else : // ── LIST MODE ─────────────────────────────────────────────────

	$g = is_array( $global ?? null ) ? $global : array();
	$t = is_array( $top ?? null ) ? $top : array();

	$top_card = static function ( $label, $row, $metric ) use ( $campaign_label ) {
		echo '<div style="border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;background:#fff;min-width:180px;">';
		echo '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;">' . esc_html( $label ) . '</div>';
		if ( $row === null ) {
			echo '<div style="font-size:14px;color:#646970;margin-top:6px;">—</div>';
		} else {
			echo '<div style="font-size:15px;font-weight:600;margin-top:6px;">' . esc_html( $campaign_label( $row['campaign'] ) ) . '</div>';
			echo '<div style="font-size:13px;color:#1d2327;">' . esc_html( (int) $row[ $metric ] ) . '</div>';
		}
		echo '</div>';
	};
	?>

	<?php /* ── GLOBAL SUMMARY ─────────────────────────────────────────────── */ ?>
	<h2 style="font-size:15px;margin:0 0 10px;"><?php esc_html_e( 'GLOBAL SUMMARY', 'estandar-analytics' ); ?></h2>
	<table class="widefat striped" style="max-width:900px;margin:0 0 20px;">
		<tbody>
			<tr>
				<td><strong><?php esc_html_e( 'Total Campaigns', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $g['total_campaigns'] ?? 0 ); ?></td>
				<td><strong><?php esc_html_e( 'Total Leads', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $g['total_leads'] ?? 0 ); ?></td>
				<td><strong><?php esc_html_e( 'Qualified', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $g['qualified'] ?? 0 ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Won', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $g['won'] ?? 0 ); ?></td>
				<td><strong><?php esc_html_e( 'Lost', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $g['lost'] ?? 0 ); ?></td>
				<td><strong><?php esc_html_e( 'Revenue', 'estandar-analytics' ); ?></strong></td><td><?php echo $money( $g['revenue'] ?? null ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Google IDs', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $g['google_ids'] ?? 0 ); ?></td>
				<td><strong><?php esc_html_e( 'Meta IDs', 'estandar-analytics' ); ?></strong></td><td><?php echo esc_html( $g['meta_ids'] ?? 0 ); ?></td>
				<td></td><td></td>
			</tr>
		</tbody>
	</table>

	<?php /* ── TOP CAMPAIGNS ──────────────────────────────────────────────── */ ?>
	<h2 style="font-size:15px;margin:0 0 10px;"><?php esc_html_e( 'TOP CAMPAIGNS', 'estandar-analytics' ); ?></h2>
	<div style="display:flex;flex-wrap:wrap;gap:12px;margin:0 0 22px;">
		<?php
		$top_card( __( 'Top by Leads', 'estandar-analytics' ), $t['leads'] ?? null, 'leads' );
		$top_card( __( 'Top by Qualified', 'estandar-analytics' ), $t['qualified'] ?? null, 'qualified' );
		$top_card( __( 'Top by Won', 'estandar-analytics' ), $t['won'] ?? null, 'won' );
		// Top by Revenue: no revenue source → always "—".
		$top_card( __( 'Top by Revenue', 'estandar-analytics' ), null, 'revenue' );
		?>
	</div>

	<?php /* ── CAMPAIGN FUNNEL TABLE ──────────────────────────────────────── */ ?>
	<h2 style="font-size:15px;margin:0 0 10px;"><?php esc_html_e( 'Campañas', 'estandar-analytics' ); ?></h2>
	<?php if ( empty( $rows ) ) : ?>
		<p><?php esc_html_e( 'Sin leads con campaña en el período seleccionado.', 'estandar-analytics' ); ?></p>
	<?php else : ?>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Campaign', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Leads', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Contacted', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Qualified', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Proposal Sent', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Proposal Accepted', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Won', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Lost', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Revenue', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Assigned Operators', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Google Click IDs', 'estandar-analytics' ); ?></th>
				<th><?php esc_html_e( 'Meta Click IDs', 'estandar-analytics' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $r ) :
				$detail_url = add_query_arg(
					array_filter( array(
						'page'     => $page_slug,
						'drill'    => 1,
						'campaign' => $r['campaign'],
						'from'     => $filters['from'] ?? '',
						'to'       => $filters['to'] ?? '',
						'project'  => $filters['project'] ?? '',
					), static function ( $v ) { return $v !== '' && $v !== null; } )
				);
			?>
			<tr>
				<td><a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $campaign_label( $r['campaign'] ) ); ?></a></td>
				<td><?php echo esc_html( $r['leads'] ); ?></td>
				<td><?php echo esc_html( $r['contacted'] ); ?></td>
				<td><?php echo esc_html( $r['qualified'] ); ?></td>
				<td><?php echo esc_html( $r['proposal_sent'] ); ?></td>
				<td><?php echo esc_html( $r['proposal_accepted'] ); ?></td>
				<td><?php echo esc_html( $r['won'] ); ?></td>
				<td><?php echo esc_html( $r['lost'] ); ?></td>
				<td><?php echo $money( $r['revenue'] ); ?></td>
				<td><?php echo esc_html( $r['operators'] ); ?></td>
				<td><?php echo esc_html( $r['google_ids'] ); ?></td>
				<td><?php echo esc_html( $r['meta_ids'] ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

<?php endif; ?>
