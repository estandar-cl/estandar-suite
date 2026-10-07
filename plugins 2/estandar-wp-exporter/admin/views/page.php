<?php
if ( ! defined('ABSPATH') ) exit;
?>

<div class="wrap estandar-wpe-wrap estandar-admin">

	<!-- =========================
	     Header
	========================= -->
	<div class="estandar-wpe-header">
		<h1>Estandar WP Exporter</h1>
		<p class="subtitle">
			Exporta plugins y themes instalados en este sitio en un archivo ZIP,
			de forma segura y por lotes.
		</p>
		<p class="meta">
			Pensado para hostings restrictivos · Sin timeouts · Sin colapsar el servidor
		</p>
	</div>

	<div class="estandar-wpe-card estandar-wpe-summary">
		<h2>Presets útiles</h2>
		<div class="estandar-wpe-preset-actions">
			<button type="button" class="button" data-wpe-preset="active-plugins">Plugins activos</button>
			<button type="button" class="button" data-wpe-preset="theme-stack">Theme activo</button>
			<button type="button" class="button" data-wpe-preset="visual-stack">Stack visual</button>
			<button type="button" class="button" data-wpe-preset="plugin-only">Solo este plugin</button>
			<button type="button" class="button button-link-delete" data-wpe-preset="clear">Limpiar</button>
		</div>
		<p class="estandar-wpe-help">
			Theme activo: <?php echo esc_html($current_theme->get('Name')); ?> ·
			Versión WP: <?php echo esc_html(get_bloginfo('version')); ?> ·
			El ZIP incluirá un <code>export-report.json</code> con el contexto técnico de la exportación.
		</p>
	</div>

	<form id="estandar-wpe-form" autocomplete="off">

		<!-- =========================
		     Selección
		========================= -->
		<div class="estandar-wpe-grid">

			<!-- PLUGINS -->
			<div class="estandar-wpe-card">
				<h2>Plugins</h2>

				<div class="estandar-wpe-list">
					<?php foreach ( $plugins as $file => $data ) : ?>
						<label class="wpe-item">
							<input type="checkbox" name="plugins[]" value="<?php echo esc_attr($file); ?>">
							<span class="wpe-title">
								<?php echo esc_html( $data['Name'] ?: $file ); ?>
							</span>
							<span class="wpe-meta">
								<?php echo esc_html($file); ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- THEMES -->
			<div class="estandar-wpe-card">
				<h2>Themes</h2>

				<div class="estandar-wpe-list">
					<?php foreach ( $themes as $stylesheet => $theme ) : ?>
						<label class="wpe-item">
							<input type="checkbox" name="themes[]" value="<?php echo esc_attr($stylesheet); ?>">
							<span class="wpe-title">
								<?php echo esc_html( $theme->get('Name') ); ?>
							</span>
							<span class="wpe-meta">
								<?php echo esc_html($stylesheet); ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

		</div>

		<!-- =========================
		     Configuración
		========================= -->
		<div class="estandar-wpe-card" style="margin-top:24px;">
			<h2>Configuración</h2>

			<div class="estandar-wpe-fields">
				<label>
					<span>Archivos por lote</span>
					<input type="number"
					       id="batch_files"
					       name="batch_files"
					       value="25"
					       min="5"
					       max="200">
				</label>

				<label>
					<span>Tamaño máximo por archivo (MB)</span>
					<input type="number"
					       id="max_file_mb"
					       name="max_file_mb"
					       value="25"
					       min="1"
					       max="200">
				</label>
			</div>

			<p class="estandar-wpe-help">
				Valores conservadores reducen el riesgo de timeout en hostings compartidos como GoDaddy y siguen siendo compatibles con servidores más potentes.
			</p>
		</div>

		<!-- =========================
		     Acción
		========================= -->
		<div class="estandar-wpe-actions">
			<button type="button"
			        class="button button-primary"
			        id="estandar-wpe-start">
				Iniciar exportación
			</button>
		</div>

		<!-- =========================
		     Progreso
		========================= -->
		<div id="estandar-wpe-progress"
		     class="estandar-wpe-progress"
		     style="display:none;">

			<div class="wpe-progress-bar">
				<div class="wpe-progress-fill"></div>
			</div>

			<div class="wpe-progress-meta">
				<span id="estandar-wpe-status">Preparando…</span>
				<span id="estandar-wpe-counts"></span>
			</div>

		</div>

	</form>

	<div class="estandar-wpe-card estandar-wpe-history">
		<h2>Historial reciente</h2>
		<?php if ( empty($jobs) ) : ?>
			<p class="estandar-wpe-help">Todavía no hay exportaciones registradas.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Fecha</th>
						<th>Estado</th>
						<th>Contenido</th>
						<th>Tamaño</th>
						<th>Motor</th>
						<th>Acción</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $jobs as $job ) : ?>
						<?php
						$plugins_count = count((array) ($job['selection']['plugins'] ?? []));
						$themes_count  = count((array) ($job['selection']['themes'] ?? []));
						$download_url  = $job['result']['download_url'] ?? '';
						$status        = (string) ($job['status'] ?? 'pending');
						$size_label    = size_format((int) ($job['progress']['bytes_total'] ?? 0), 2);
						?>
						<tr>
							<td><?php echo esc_html(wp_date('Y-m-d H:i', (int) ($job['created'] ?? time()))); ?></td>
							<td><span class="wpe-status wpe-status-<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></span></td>
							<td><?php echo esc_html(sprintf('%d plugin(s) · %d theme(s)', $plugins_count, $themes_count)); ?></td>
							<td><?php echo esc_html($size_label); ?></td>
							<td><?php echo esc_html((string) ($job['result']['engine'] ?? '')); ?></td>
							<td>
								<?php if ( $status === 'done' && is_string($download_url) && $download_url !== '' ) : ?>
									<a class="button button-small" href="<?php echo esc_url($download_url); ?>">Descargar</a>
								<?php else : ?>
									<span class="description"><?php echo esc_html((string) ($job['progress']['last_message'] ?? 'Sin descarga')); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
