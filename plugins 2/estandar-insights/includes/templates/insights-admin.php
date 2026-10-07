<?php
/** Estandar Suite — Insights admin. 4 tabs (Estado · Configuración · Datos ·
 * Avanzado) con sub-tabs por grupo funcional.
 * Variables: $tab, $tabs, $subs, $sub, $sets, $fn_settings, $funnel, $events,
 * $obs, $legacy, $imp, $rol.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( empty( $fn_settings ) ) { $fn_settings = array(); }
?>
<div class="wrap">
	<h1>Estandar Suite — Insights</h1>

	<?php \Estandar\Core\Admin::render_tabs( 'estandar-suite-insights', $tab, $tabs ); ?>

	<?php if ( $tab === 'estado' ) : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-insights', 'estado', $sub, $subs['estado'] ); ?>

		<?php if ( $sub === 'resumen' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'resumen', '<strong>¿Para qué sirve?</strong> Es la tarjeta de presentación de Insights: de un vistazo ves el rol (analizar sin inventar éxito), cuántos eventos hay, el estado de observabilidad y si existe analytics legado. <br><br>Es el <em>cuadro de mando del analista</em>: aquí sabes si la suite tiene datos con qué hablar. Recuerda: si no hay datos, Insights lo dice en claro (UNKNOWN), nunca inventa.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Rol</strong></td><td>Análisis y observabilidad: eventos analíticos, funnel comercial, retención y estado honesto (ausencia → UNKNOWN).</td></tr>
				<tr><td><strong>Eventos</strong></td><td><?php echo count( $events ); ?> recientes</td></tr>
				<tr><td><strong>Observabilidad</strong></td><td><code><?php echo esc_html( \Estandar\Insights\Observability::render_line( $obs ) ); ?></code></td></tr>
				<tr><td><strong>Instalación legada</strong></td><td><?php echo $legacy ? 'SÍ — analytics histórico de ea_events/ea_sessions disponible.' : 'NO — sin analytics legado.'; ?></td></tr>
			</tbody>
		</table>
		<p style="margin-top:10px;">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-insights&tab=estado&sub=datos' ) ); ?>">Ver análisis</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-insights&tab=datos&sub=adopcion' ) ); ?>">Ver adopción</a>
		</p>
		<?php elseif ( $sub === 'datos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'datos', '<strong>¿Para qué sirve?</strong> Es la <em>lectura en vivo</em> del negocio: el <strong>funnel comercial</strong> (cuántos registros hay en cada etapa y cómo caen), el estado de <strong>observabilidad</strong> y los <strong>eventos recientes</strong> con su canal y proyecto. <br><br>Es el <em>dashboard de resultados</em>: todo se calcula de datos reales. Si no hay actividad, el funnel se ve vacío y la observabilidad lo dice honestamente.' ); ?>
		<h3>Funnel comercial</h3>
		<table class="widefat striped" style="max-width:480px;">
			<thead><tr><th>Etapa</th><th>Registros</th><th>vs anterior</th></tr></thead>
			<tbody>
			<?php foreach ( $funnel['stages'] as $stage => $info ) : ?>
				<tr><td><?php echo esc_html( $stage ); ?></td><td><?php echo (int) $info['count']; ?></td><td><?php echo $info['rate_from_prev'] !== null ? esc_html( $info['rate_from_prev'] . '%' ) : '—'; ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h3>Observabilidad</h3>
		<p><code><?php echo esc_html( \Estandar\Insights\Observability::render_line( $obs ) ); ?></code></p>

		<h3>Eventos recientes</h3>
		<table class="widefat striped">
			<thead><tr><th>Fecha</th><th>Tipo</th><th>Entidad</th><th>Canal</th><th>Proyecto</th></tr></thead>
			<tbody>
			<?php foreach ( $events as $e ) : ?>
				<tr>
					<td><?php echo esc_html( $e['occurred_at'] ); ?></td>
					<td><code><?php echo esc_html( $e['event_type'] ); ?></code></td>
					<td><?php echo esc_html( $e['entity_type'] . '#' . $e['entity_id'] ); ?></td>
					<td><?php echo esc_html( $e['channel'] ); ?></td>
					<td><?php echo esc_html( $e['project_slug'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $events ) ) : ?><tr><td colspan="5">Sin eventos todavía.</td></tr><?php endif; ?>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'esquema', '<strong>¿Para qué sirve?</strong> Muestra la <em>tabla propia</em> de Insights: <code>estandar_insights_events</code>, donde viven los eventos analíticos. <br><br>Es la <em>ficha técnica</em> de dónde se guarda el análisis. No se edita aquí; sirve para entender el modelo de datos.' ); ?>
		<p><code>estandar_insights_events</code></p>
		<?php endif; ?>

	<?php elseif ( $tab === 'config' ) : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-insights', 'config', $sub, $subs['config'] ); ?>

		<?php if ( $sub === 'config' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'config', '<strong>¿Para qué sirve?</strong> Explica las <em>reglas del análisis</em>: los tipos de evento son cerrados (no hay tipos libres, <code>sanitize_type</code>), los canales vienen de <code>Core\Channels</code> y el funnel se alimenta de Flow cuando está activo. <br><br>Es el <em>reglamento del laboratorio</em>: para que las mediciones sean comparables, todo se mide con las mismas unidades y, si falta el dato, se declara ausencia honesta (UNKNOWN).' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Tipos cerrados</strong></td><td>Los eventos solo se aceptan si pertenecen al conjunto definido por <code>sanitize_type</code> (no hay tipos libres).</td></tr>
				<tr><td><strong>Canales</strong></td><td>Usa <code>Core\Channels</code> para canales; sin dependencia dura con Capture.</td></tr>
				<tr><td><strong>Funnel</strong></td><td>Se alimenta de Flow cuando está activo (guard <code>class_exists</code>); sin Flow, muestra ausencia honesta (UNKNOWN).</td></tr>
			</tbody>
		</table>
		<?php elseif ( $sub === 'analytics' ) : ?>
		<?php $feature = 'analytics'; $feature_fields = $feature_fields['analytics']; $feature_option = 'estandar_suite_insights_analytics_settings'; include ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/templates/feature-analytics.php'; ?>

		<?php elseif ( $sub === 'seo' ) : ?>
		<?php $feature = 'seo'; $feature_fields = $feature_fields['seo']; $feature_option = 'estandar_suite_insights_seo_settings'; include ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/templates/feature-seo.php'; ?>

		<?php elseif ( $sub === 'scanner' ) : ?>
		<?php $feature = 'scanner'; $feature_fields = $feature_fields['scanner']; $feature_option = 'estandar_suite_insights_scanner_settings'; include ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/templates/feature-scanner.php'; ?>

		<?php elseif ( $sub === 'settings' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'settings', '<strong>¿Para qué sirve?</strong> Ajustes operativos de Insights agrupados por área (infraestructura, operación, esquema, UI) con valores recomendados de fábrica. <br><br>Es el <em>panel de mandos</em>: aquí se decide cómo se comporta el análisis por defecto. La sección «Settings heredados» muestra en solo lectura lo que dejó el plugin v1.' ); ?>
		<?php \Estandar\Core\Admin::render_settings_panel( \Estandar\Insights\Admin::get_settings() ); ?>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'visibilidad', '<strong>¿Para qué sirve?</strong> Decide qué pestañas y sub-pestañas ves en Insights. Es <strong>solo visual</strong>: las funciones y los datos siguen funcionando aunque ocultes una pestaña. <br><br>Es como <em>ordenar el escritorio</em>: si no usas «Settings», lo ocultas para tener una interfaz más limpia. La pestaña de Visibilidad siempre queda accesible, para que nunca te quedes sin acceso.' ); ?>
		<?php if ( isset( $_GET['visibility_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Visibilidad guardada.</p></div><?php endif; ?>
		<?php
		$visibility_subs = array(
			'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'config'   => array( 'config' => 'Configuración', 'analytics' => 'Analytics', 'seo' => 'SEO', 'scanner' => 'Scanner', 'settings' => 'Settings', 'visibilidad' => 'Visibilidad' ),
			'datos'    => array( 'adopcion' => 'Adopción' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);
		\Estandar\Core\Admin::render_visibility_manager( 'insights', 'estandar-suite-insights', $visibility_subs );
		?>
		<?php endif; ?>

	<?php elseif ( $tab === 'datos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'adopcion', '<strong>¿Para qué sirve?</strong> Importa el <em>analytics histórico</em> de la instalación anterior (v1, tablas <code>ea_events</code>/<code>ea_sessions</code>) al modelo nuevo de Insights. <br><br>Es una <em>mudanza cuidadosa</em>: previsualizas (dry-run) cuánto se importará, luego confirmas (commit) y, si algo no cuadra, haces rollback y lo adoptado se elimina. Los originales quedan intactos siempre.' ); ?>
		<p>Instalación legada: <strong><?php echo $legacy ? 'SÍ — eventos de ea_events/ea_sessions disponibles' : 'NO — sin analytics legado'; ?></strong></p>

		<?php if ( $imp !== null ) : ?><div class="notice notice-success is-dismissible"><p>Adopción analytics: <strong><?php echo (int) $imp; ?></strong> nuevos.</p></div><?php endif; ?>
		<?php if ( $rol !== null ) : ?><div class="notice notice-success is-dismissible"><p>Rollback analytics: <strong><?php echo (int) $rol; ?></strong>.</p></div><?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php wp_nonce_field( 'estandar_suite_insights_adoption_import' ); ?>
			<input type="hidden" name="action" value="estandar_suite_insights_adoption_import">
			<?php submit_button( 'Previsualizar import (dry-run)', 'secondary', 'dryrun', false ); ?>
			<?php submit_button( 'Importar (commit)', 'primary', 'commit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('¿Rollback de eventos adoptados?')">
			<?php wp_nonce_field( 'estandar_suite_insights_adoption_rollback' ); ?>
			<input type="hidden" name="action" value="estandar_suite_insights_adoption_rollback">
			<?php submit_button( 'Rollback', 'delete', 'rollback', false ); ?>
		</form>

	<?php else : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-insights', 'avanzado', $sub, $subs['avanzado'] ); ?>

		<?php if ( $sub === 'rest' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'rest', '<strong>¿Para qué sirve?</strong> Lista los <em>endpoints REST</em> de Insights para que otros sistemas consulten el funnel, los eventos o el estado de observabilidad vía HTTP. Todos requieren autenticación y permisos de administrador. <br><br>Es la <em>puerta de servicio</em>: lo que la UI hace con clicks, los sistemas lo hacen por aquí.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>GET /estandar-suite/insights/v1/funnel</code></td><td>Ver funnel</td></tr>
				<tr><td><code>GET /estandar-suite/insights/v1/events</code></td><td>Ver eventos</td></tr>
				<tr><td><code>GET /estandar-suite/insights/v1/observability</code></td><td>Ver estado de observabilidad</td></tr>
			</tbody>
		</table>
		<?php elseif ( $sub === 'cli' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'cli', '<strong>¿Para qué sirve?</strong> Muestra los <em>comandos de WP-CLI</em> de Insights para operar el análisis desde la terminal: ver el funnel, la retención o importar/hacer rollback del analytics histórico. <br><br>Es el <em>panel de control por voz</em>: lo que haces con clicks en la UI, lo haces con una línea de texto.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>wp estandar-suite insights funnel</code></td><td>Ver funnel</td></tr>
				<tr><td><code>wp estandar-suite insights retention</code></td><td>Ver retención</td></tr>
				<tr><td><code>wp estandar-suite insights adoption import [--commit]</code></td><td>Importar analytics histórico</td></tr>
				<tr><td><code>wp estandar-suite insights adoption rollback --confirm=yes</code></td><td>Rollback de adoptado</td></tr>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'inventario', '<strong>¿Para qué sirve?</strong> El <em>catálogo completo</em> de Insights: todas las funciones del plugin agrupadas por conjuntos funcionales, para que ninguna quede fuera de la interfaz. <br><br>Es el <em>inventario del almacén</em>: confirma que todo lo que el código puede hacer tiene su lugar visible. Sirve para auditar cobertura y detectar funciones escondidas.' ); ?>
		<?php \Estandar\Core\Admin::render_inventory( $sets, $fn_settings ); ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php echo \Estandar\Core\Admin::help_script(); ?>
