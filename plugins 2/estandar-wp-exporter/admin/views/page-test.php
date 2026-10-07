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
					       value="200"
					       min="50"
					       max="800">
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
				Valores conservadores reducen el riesgo de timeout en hostings compartidos.
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

</div>
