<?php
/** Estandar Suite — Flow admin. 4 tabs (Estado · Configuración · Datos ·
 * Avanzado) con sub-tabs por grupo funcional.
 * Variables: $tab, $tabs, $subs, $sub, $sets, $fn_settings, $stage, $pipeline,
 * $customers, $quotes, $legacy, $trans, $imp, $rol.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( empty( $fn_settings ) ) { $fn_settings = array(); }
?>
<div class="wrap">

		<?php \Estandar\Core\Admin::render_tabs( 'estandar-suite-flow', $tab, $tabs ); ?>

		<?php \Estandar\Core\Admin::render_module_tabs( 'estandar-suite-flow', $tab, $sub, array( 'etapas' => 'Etapas', 'quotes' => 'Cotizaciones', 'settings' => 'Settings' ) ); ?>


	<?php if ( \Estandar\Core\Admin::native_blank() ) : ?>
		<!-- Backend Ordenado: solo estructura (sin contenido). -->
		<div class="notice notice-info inline" style="margin:12px 0;max-width:760px;"><p>Backend Ordenado: esta pantalla está en modo <strong>solo estructura</strong>; nada se eliminó.</p></div>
	<?php elseif ( $tab === 'estado' ) : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-flow', 'estado', $sub, $subs['estado'] ); ?>

		<?php if ( $sub === 'resumen' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'resumen', '<strong>¿Para qué sirve?</strong> Es la tarjeta de presentación de Flow: de un vistazo ves el rol (operación comercial) y los contadores del negocio — cuántos registros hay en pipeline, clientes y cotizaciones, y si existe instalación legada con estado histórico. <br><br>Es el <em>tablero de la sala de ventas</em>: confirmas cómo va la operación y saltas al pipeline o a la adopción con un click.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Rol</strong></td><td>Operación comercial: pipeline con etapas canónicas, transiciones auditadas, clientes y cotizaciones.</td></tr>
				<tr><td><strong>Pipeline</strong></td><td><?php echo count( $pipeline ); ?> registros</td></tr>
				<tr><td><strong>Clientes</strong></td><td><?php echo count( $customers ); ?> registros</td></tr>
				<tr><td><strong>Cotizaciones</strong></td><td><?php echo count( $quotes ); ?> registros</td></tr>
				<tr><td><strong>Instalación legada</strong></td><td><?php echo $legacy ? 'SÍ — estado comercial histórico de es_leads disponible.' : 'NO — sin estado histórico.'; ?></td></tr>
			</tbody>
		</table>
		<p style="margin-top:10px;">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-flow&tab=estado&sub=datos' ) ); ?>">Ver pipeline</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-flow&tab=datos&sub=adopcion' ) ); ?>">Ver adopción</a>
		</p>
		<?php elseif ( $sub === 'datos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'datos', '<strong>¿Para qué sirve?</strong> Es la <em>operación en vivo</em>: aquí ves el <strong>pipeline</strong> (qué lead está en qué etapa y puedes moverlo), los <strong>clientes</strong> y las <strong>cotizaciones</strong>. <br><br>Es la <em>pizarra del vendedor</em>: cada movimiento de etapa queda auditado en <code>estandar_flow_stage_events</code>. Si ves un lead estancado, aquí lo avanzas con un click.' ); ?>
		<h3>Pipeline</h3>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="estandar-suite-flow">
			<label>Filtrar etapa
				<select name="stage">
					<option value="">Todas</option>
					<?php foreach ( \Estandar\Flow\Pipeline::STAGES as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $stage, $s ); ?>><?php echo esc_html( $s ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php submit_button( 'Filtrar', 'secondary', '', false ); ?>
		</form>
		<?php
		// Origen/rama del lead (landing diferenciada, p. ej. Temuco): se lee de
		// fields_json de la captura (estandar_capture) sin tocar el schema.
		global $wpdb;
		$branch_by_lead = array();
		$lead_ids = array_values( array_unique( array_filter( array_map( 'intval', wp_list_pluck( $pipeline, 'origin_lead_id' ) ) ) ) );
		$capture_tbl = $wpdb->prefix . 'estandar_capture';
		if ( $lead_ids && (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $capture_tbl ) ) === $capture_tbl ) {
			$ids = implode( ',', array_map( 'intval', $lead_ids ) );
			$cap_rows = $wpdb->get_results( "SELECT id, fields_json FROM {$capture_tbl} WHERE id IN ({$ids})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $cap_rows as $cr ) {
				$fj = json_decode( (string) ( $cr['fields_json'] ?? '' ), true );
				$br = is_array( $fj ) ? (string) ( $fj['_branch'] ?? '' ) : '';
				if ( $br !== '' ) {
					$branch_by_lead[ (int) $cr['id'] ] = $br;
				}
			}
		}
		?>
		<table class="widefat striped">
			<thead><tr><th>ID</th><th>Entidad</th><th>Etapa</th><th>Proyecto</th><th>Monto</th><th>Origen</th><th>Acción</th></tr></thead>
			<tbody>
			<?php foreach ( $pipeline as $row ) : ?>
				<tr>
					<td><?php echo (int) $row['id']; ?></td>
					<td><?php echo esc_html( $row['entity_type'] . '#' . $row['entity_id'] ); ?></td>
					<td><strong><?php echo esc_html( $row['stage'] ); ?></strong></td>
					<td><?php echo esc_html( $row['project_slug'] ); ?></td>
					<td><?php echo $row['amount'] !== null ? esc_html( $row['amount'] . ' ' . $row['currency'] ) : '—'; ?></td>
					<td>
						<?php if ( $row['origin_lead_id'] ) : ?>
							lead#<?php echo (int) $row['origin_lead_id']; ?>
							<?php $br = (string) ( $branch_by_lead[ (int) $row['origin_lead_id'] ] ?? '' ); ?>
							<?php if ( $br !== '' ) : ?>
								<span style="display:inline-block;margin-left:4px;padding:1px 8px;border-radius:10px;font-size:11px;background:#ecfdf5;color:#14532d;border:1px solid #14532d;">Landing <?php echo esc_html( ucfirst( $br ) ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'estandar_suite_flow_transition' ); ?>
							<input type="hidden" name="action" value="estandar_suite_flow_transition">
							<input type="hidden" name="pipeline_id" value="<?php echo (int) $row['id']; ?>">
							<select name="stage">
								<?php foreach ( \Estandar\Flow\Pipeline::STAGES as $s ) : ?>
									<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $row['stage'], $s ); ?>><?php echo esc_html( $s ); ?></option>
								<?php endforeach; ?>
							</select>
							<button class="button">Mover</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $pipeline ) ) : ?><tr><td colspan="7">Sin registros de pipeline.</td></tr><?php endif; ?>
			</tbody>
		</table>

		<h3>Clientes</h3>
		<table class="widefat striped">
			<thead><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Teléfono</th><th>Origen</th></tr></thead>
			<tbody>
			<?php foreach ( $customers as $c ) : ?>
				<tr><td><?php echo (int) $c['id']; ?></td><td><?php echo esc_html( $c['name'] ); ?></td><td><?php echo esc_html( $c['email'] ); ?></td><td><?php echo esc_html( $c['phone'] ); ?></td><td><?php echo $c['origin_customer_id'] ? 'customer#' . (int) $c['origin_customer_id'] : '—'; ?></td></tr>
			<?php endforeach; ?>
			<?php if ( empty( $customers ) ) : ?><tr><td colspan="5">Sin clientes.</td></tr><?php endif; ?>
			</tbody>
		</table>

		<h3>Cotizaciones</h3>
		<table class="widefat striped">
			<thead><tr><th>ID</th><th>UUID</th><th>Pipeline</th><th>Monto</th><th>Estado</th></tr></thead>
			<tbody>
			<?php foreach ( $quotes as $q ) : ?>
				<tr><td><?php echo (int) $q['id']; ?></td><td><code><?php echo esc_html( $q['quote_uuid'] ); ?></code></td><td><?php echo (int) $q['pipeline_id']; ?></td><td><?php echo $q['amount'] !== null ? esc_html( $q['amount'] . ' ' . $q['currency'] ) : '—'; ?></td><td><?php echo esc_html( $q['status'] ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( empty( $quotes ) ) : ?><tr><td colspan="5">Sin cotizaciones.</td></tr><?php endif; ?>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'esquema', '<strong>¿Para qué sirve?</strong> Muestra las <em>tablas propias</em> de Flow: <code>estandar_flow_pipeline</code>, <code>estandar_flow_stage_events</code> (auditoría de movimientos), <code>estandar_flow_customers</code>, <code>estandar_flow_quotes</code> y <code>estandar_flow_tasks</code>. <br><br>Es la <em>ficha técnica</em> de dónde vive la operación comercial. No se edita aquí; sirve para entender el modelo de datos.' ); ?>
		<p><code>estandar_flow_pipeline</code> · <code>estandar_flow_stage_events</code> · <code>estandar_flow_customers</code> · <code>estandar_flow_quotes</code> · <code>estandar_flow_tasks</code></p>
		<?php endif; ?>

	<?php elseif ( $tab === 'config' ) : ?>
	
		<?php if ( $sub === 'etapas' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'etapas', '<strong>¿Para qué sirve?</strong> Define el <em>camino comercial</em>: la lista fija de etapas por las que avanza cada lead (primera, calificado, propuesta, ganado…). Es el vocabulario común de toda la suite. <br><br>Es como el <em>tablero del juego</em>: todas las piezas se mueven por las mismas casillas. Cada movimiento entre etapas queda auditado en <code>estandar_flow_stage_events</code> para que nada se mueva «en silencio».' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<thead><tr><th>#</th><th>Etapa</th></tr></thead>
			<tbody>
			<?php foreach ( \Estandar\Flow\Pipeline::STAGES as $i => $s ) : ?>
				<tr><td><?php echo (int) $i + 1; ?></td><td><strong><?php echo esc_html( $s ); ?></strong></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">Las transiciones se auditan en <code>estandar_flow_stage_events</code>.</p>
		<?php elseif ( $sub === 'quotes' ) : ?>
		<?php $feature = 'quotes'; $feature_fields = $feature_fields['quotes']; $feature_option = 'estandar_suite_flow_quotes_settings'; include ESTANDAR_SUITE_FLOW_PATH . 'includes/templates/feature-quotes.php'; ?>
		<?php elseif ( $sub === 'settings' ) : ?>
 		<?php echo \Estandar\Core\Admin::help_html( 'settings', '<strong>¿Para qué sirve?</strong> Ajustes operativos de Flow agrupados por área (infraestructura, operación, esquema, UI) con valores recomendados de fábrica. <br><br>Es el <em>panel de mandos</em>: aquí se decide cómo se comporta la operación comercial por defecto. La sección «Settings heredados» muestra en solo lectura lo que dejó el plugin v1.' ); ?>
 		<?php \Estandar\Core\Admin::render_settings_panel( \Estandar\Flow\Admin::get_settings() ); ?>
 		<?php else : ?>
 		<?php echo \Estandar\Core\Admin::help_html( 'visibilidad', '<strong>Visibilidad consolidada en Core.</strong> La visibilidad de pestañas de todos los plugins de la suite (incluido Flow) se administra desde <strong>Estandar Suite → Core → Config → Visibilidad</strong>. Este cambio solo afecta la visualización; ninguna función, setting o dato se pierde.' ); ?>
 		<?php endif; ?>


	<?php elseif ( $tab === 'datos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'adopcion', '<strong>¿Para qué sirve?</strong> Trae el <em>estado comercial histórico</em> de la instalación anterior (v1, <code>es_leads</code>) al pipeline nuevo de Flow. <br><br>Es una <em>mudanza cuidadosa</em>: previsualizas (dry-run) cuánto se importará, luego confirmas (commit) y, si algo no cuadra, haces rollback y lo adoptado se elimina. Los originales quedan intactos siempre.' ); ?>
		<p>Instalación legada: <strong><?php echo $legacy ? 'SÍ — estado comercial histórico de es_leads disponible' : 'NO — sin estado histórico'; ?></strong></p>

		<?php if ( $trans ) : ?><div class="notice notice-success is-dismissible"><p>Transición registrada.</p></div><?php endif; ?>
		<?php if ( $imp !== null ) : ?><div class="notice notice-success is-dismissible"><p>Adopción pipeline: <strong><?php echo (int) $imp; ?></strong> nuevos.</p></div><?php endif; ?>
		<?php if ( $rol !== null ) : ?><div class="notice notice-success is-dismissible"><p>Rollback pipeline: <strong><?php echo (int) $rol; ?></strong>.</p></div><?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php wp_nonce_field( 'estandar_suite_flow_adoption_import' ); ?>
			<input type="hidden" name="action" value="estandar_suite_flow_adoption_import">
			<?php submit_button( 'Previsualizar import (dry-run)', 'secondary', 'dryrun', false ); ?>
			<?php submit_button( 'Importar (commit)', 'primary', 'commit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('¿Rollback de pipeline adoptado?')">
			<?php wp_nonce_field( 'estandar_suite_flow_adoption_rollback' ); ?>
			<input type="hidden" name="action" value="estandar_suite_flow_adoption_rollback">
			<?php submit_button( 'Rollback', 'delete', 'rollback', false ); ?>
		</form>

	<?php else : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-flow', 'avanzado', $sub, $subs['avanzado'] ); ?>

		<?php if ( $sub === 'rest' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'rest', '<strong>¿Para qué sirve?</strong> Lista los <em>endpoints REST</em> de Flow para que otros sistemas consulten el pipeline, muevan etapas o vean clientes y cotizaciones vía HTTP. Todos requieren autenticación y permisos de administrador. <br><br>Es la <em>puerta de servicio</em>: lo que la UI hace con clicks, los sistemas lo hacen por aquí.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>GET /estandar-suite/flow/v1/pipeline</code></td><td>Ver pipeline</td></tr>
				<tr><td><code>POST /estandar-suite/flow/v1/pipeline/{id}/transition</code></td><td>Transición de etapa</td></tr>
				<tr><td><code>GET /estandar-suite/flow/v1/customers</code></td><td>Ver clientes</td></tr>
				<tr><td><code>GET /estandar-suite/flow/v1/quotes</code></td><td>Ver cotizaciones</td></tr>
			</tbody>
		</table>
		<?php elseif ( $sub === 'cli' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'cli', '<strong>¿Para qué sirve?</strong> Muestra los <em>comandos de WP-CLI</em> de Flow para operar la venta desde la terminal: ver el pipeline, mover de etapa o importar/hacer rollback del pipeline histórico. <br><br>Es el <em>panel de control por voz</em>: lo que haces con clicks en la UI, lo haces con una línea de texto.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>wp estandar-suite flow pipeline</code></td><td>Ver pipeline</td></tr>
				<tr><td><code>wp estandar-suite flow transition</code></td><td>Mover de etapa</td></tr>
				<tr><td><code>wp estandar-suite flow adoption import [--commit]</code></td><td>Importar pipeline histórico</td></tr>
				<tr><td><code>wp estandar-suite flow adoption rollback --confirm=yes</code></td><td>Rollback de adoptado</td></tr>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'inventario', '<strong>¿Para qué sirve?</strong> El <em>catálogo completo</em> de Flow: todas las funciones del plugin agrupadas por conjuntos funcionales, para que ninguna quede fuera de la interfaz. <br><br>Es el <em>inventario del almacén</em>: confirma que todo lo que el código puede hacer tiene su lugar visible. Sirve para auditar cobertura y detectar funciones escondidas.' ); ?>
		<?php \Estandar\Core\Admin::render_inventory( $sets, $fn_settings ); ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php echo \Estandar\Core\Admin::help_script(); ?>
