<?php
/**
 * Estandar Suite — Insights · SEO (sub-tab con sub-sub-tabs).
 *
 * Dos capas:
 *  - **Tier 1 · Auditoría técnica propia** (universal, sin credenciales): analiza el HTML
 *    renderizado de las páginas publicadas del sitio y las puntúa (Resumen · Auditoría).
 *  - **Tier 2 · Search Console** (opcional): landings/métricas/queries heredadas de `es_seo_*`.
 *
 * Sub-sub-tabs: Resumen · Auditoría · Landings (Search Console) · Settings.
 * Variables: $feature, $feature_fields, $feature_option, $feature_saved, $feature_view.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$landings = isset( $feature_view['landings'] ) ? $feature_view['landings'] : array();
$metrics  = isset( $feature_view['metrics'] ) ? (int) $feature_view['metrics'] : 0;
$queries  = isset( $feature_view['queries'] ) ? (int) $feature_view['queries'] : 0;

$audit       = isset( $feature_view['audit'] ) ? $feature_view['audit'] : array();
$audit_count = isset( $feature_view['audit_count'] ) ? (int) $feature_view['audit_count'] : 0;
$audit_last  = isset( $feature_view['audit_last'] ) ? $feature_view['audit_last'] : array( 'at' => '', 'pages' => 0, 'avg' => 0.0 );
$audit_top   = isset( $feature_view['audit_top'] ) ? $feature_view['audit_top'] : array();
$audit_site  = isset( $feature_view['audit_site'] ) ? $feature_view['audit_site'] : array();
$audit_runs  = isset( $feature_view['audit_runs'] ) ? $feature_view['audit_runs'] : array();

$ssubs = \Estandar\Core\Admin::filter_ssubs( 'insights', 'config', 'seo', \Estandar\Insights\Admin::seo_ssubs() );
$ss    = \Estandar\Core\Admin::resolve_ssub( $ssubs, 'resumen' );

/** Color del score (semáforo simple). */
$es_seo_color = static function ( $score ): string {
	$s = (int) $score;
	if ( $s >= 80 ) { return '#1a7f37'; }
	if ( $s >= 60 ) { return '#9a6700'; }
	return '#b32d2e';
};
?>
<?php echo \Estandar\Core\Admin::help_html( 'seo', '<strong>¿Para qué sirve?</strong> Audita el <em>SEO técnico</em> del propio sitio: recorre las páginas publicadas, analiza el HTML que ve un buscador (título, meta description, canonical, encabezados, alt de imágenes, datos estructurados…) y las <strong>puntúa de 0 a 100</strong> con la lista de incidencias. No necesita credenciales: funciona en cualquier sitio. <br><br>La pestaña <em>Landings</em> corresponde a la capa opcional de <strong>Search Console</strong> — datos reales de búsqueda, que requiere credenciales.' ); ?>
<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-insights', 'config', $feature, $ss, $ssubs ); ?>

<?php if ( $feature_saved ) : ?><div class="notice notice-success is-dismissible"><p>Settings guardados.</p></div><?php endif; ?>
<?php if ( isset( $_GET['audited'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Auditoría completada: <strong><?php echo (int) $_GET['audited']; ?></strong> páginas · score medio <strong><?php echo esc_html( (string) ( $_GET['avg'] ?? '0' ) ); ?></strong>.</p></div><?php endif; ?>

<?php if ( $ss === 'resumen' ) : ?>
	<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;max-width:980px;margin:12px 0;">
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;">
			<div style="font-size:24px;font-weight:600;color:<?php echo esc_attr( $es_seo_color( $audit_last['avg'] ) ); ?>"><?php echo esc_html( (string) $audit_last['avg'] ); ?></div>
			<div class="description">Score medio</div>
		</div>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:24px;font-weight:600;"><?php echo (int) $audit_last['pages']; ?></div><div class="description">Páginas auditadas</div></div>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:24px;font-weight:600;"><?php echo (int) $audit_count; ?></div><div class="description">Filas en la última corrida</div></div>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;"><div style="font-size:15px;font-weight:600;"><?php echo esc_html( $audit_last['at'] !== '' ? $audit_last['at'] : 'nunca' ); ?></div><div class="description">Última auditoría</div></div>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:8px;margin:0 0 14px;">
		<?php wp_nonce_field( 'estandar_suite_insights_seo_scan' ); ?>
		<input type="hidden" name="action" value="estandar_suite_insights_seo_scan">
		<label class="description">Páginas:</label>
		<input type="number" name="limit" value="<?php echo (int) ( $feature_fields['max_pages'] ?? 50 ); ?>" min="1" max="500" style="width:80px;">
		<button type="submit" class="button button-primary">⟳ Auditar ahora</button>
		<span class="description">Descarga y analiza el HTML de cada página; puede tardar unos segundos.</span>
	</form>

	<h3>Chequeos del sitio</h3>
	<table class="widefat striped" style="max-width:560px;">
		<tbody>
		<?php foreach ( array( 'sitemap.xml', 'robots.txt' ) as $f ) : $chk = isset( $audit_site[ $f ] ) ? $audit_site[ $f ] : array( 'ok' => false, 'code' => 0 ); ?>
			<tr>
				<td style="width:220px;"><code><?php echo esc_html( $f ); ?></code></td>
				<td><?php if ( ! empty( $chk['ok'] ) ) : ?><span style="color:#1a7f37;font-weight:600;">OK</span><?php else : ?><span style="color:#b32d2e;font-weight:600;">No disponible</span> <span class="description">(HTTP <?php echo (int) $chk['code']; ?>)</span><?php endif; ?></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $audit_site ) : ?><tr><td colspan="2" class="description">Aún sin chequeos: corre una auditoría.</td></tr><?php endif; ?>
		</tbody>
	</table>

	<h3>Incidencias más frecuentes</h3>
	<?php if ( $audit_top ) : ?>
		<table class="widefat striped" style="max-width:820px;">
			<thead><tr><th>Incidencia</th><th style="width:120px;text-align:right;">Páginas</th></tr></thead>
			<tbody>
			<?php foreach ( $audit_top as $label => $n ) : ?>
				<tr><td><?php echo esc_html( (string) $label ); ?></td><td style="text-align:right;"><strong><?php echo (int) $n; ?></strong></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="description">Sin incidencias registradas. Corre una auditoría para verlas.</p>
	<?php endif; ?>

	<?php if ( count( (array) $audit_runs ) > 1 ) : ?>
		<h3>Tendencia: score medio por corrida</h3>
		<table class="widefat striped" style="max-width:560px;">
			<thead><tr><th>Fecha</th><th>Páginas</th><th style="text-align:right;">Score medio</th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( (array) $audit_runs, 0, 8 ) as $run ) : ?>
				<tr><td><?php echo esc_html( (string) $run['scanned_at'] ); ?></td><td><?php echo (int) $run['pages']; ?></td><td style="text-align:right;color:<?php echo esc_attr( $es_seo_color( $run['avg_score'] ) ); ?>"><strong><?php echo esc_html( (string) $run['avg_score'] ); ?></strong></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

<?php elseif ( $ss === 'auditoria' ) : ?>
	<?php if ( ! $audit ) : ?>
		<p class="description">Aún no hay auditoría. Ve a <strong>Resumen</strong> y pulsa «Auditar ahora».</p>
	<?php else : ?>
		<p class="description">Peor score primero. Un score bajo suele deberse a falta de meta description, título mal largo, sin H1 o sin datos estructurados.</p>
		<table class="widefat striped">
			<thead><tr><th style="width:70px;">Score</th><th>Título</th><th>URL</th><th>Incidencias</th></tr></thead>
			<tbody>
			<?php foreach ( $audit as $row ) : $issues = json_decode( (string) ( $row['issues_json'] ?? '' ), true ); ?>
				<tr>
					<td><strong style="color:<?php echo esc_attr( $es_seo_color( $row['score'] ) ); ?>"><?php echo (int) $row['score']; ?></strong></td>
					<td><?php echo esc_html( (string) $row['title'] ); ?></td>
					<td><a href="<?php echo esc_url( (string) $row['url'] ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( wp_parse_url( (string) $row['url'], PHP_URL_PATH ) ?: '/' ); ?></code></a></td>
					<td><?php echo esc_html( implode( ' · ', array_slice( (array) $issues, 0, 4 ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

<?php elseif ( $ss === 'landings' ) : ?>
	<p class="description">Capa <strong>Tier 2</strong>: landings monitoreadas por la instalación anterior y sincronización de Search Console. Requiere credenciales.</p>
	<table class="widefat striped">
		<thead><tr><th>ID</th><th>URL</th><th>Última sincronización</th><th>Creada</th></tr></thead>
		<tbody>
		<?php foreach ( $landings as $l ) : ?>
			<tr>
				<td><?php echo (int) $l['id']; ?></td>
				<td><code><?php echo esc_html( $l['normalized_url'] ? $l['normalized_url'] : $l['url'] ); ?></code></td>
				<td><?php echo esc_html( $l['last_sync'] ); ?></td>
				<td><?php echo esc_html( $l['created_at'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( empty( $landings ) ) : ?><tr><td colspan="4">Sin landings legadas (Search Console no configurado).</td></tr><?php endif; ?>
		</tbody>
	</table>

<?php else : ?>
	<h4>Settings del módulo</h4>
	<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_insights_save_feature', $feature_option, $feature_fields ); ?>
<?php endif; ?>
