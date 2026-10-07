<?php
/** Estandar Suite — Insights admin. Módulos de análisis como primera fila de
 * pestañas (Analytics · Funnel · Adopción · SEO · Configuración), cada uno
 * enlazando a su contenido ya existente; la infraestructura (Estado/Datos/
 * Avanzado) queda en segundo nivel, accesible por URL.
 * Variables: $tab, $tabs, $subs, $sub, $sets, $fn_settings, $funnel, $events,
 * $legacy, $imp, $failed, $rol.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( empty( $fn_settings ) ) { $fn_settings = array(); }
?>
<div class="wrap">

	<?php
	// ── Línea de módulos analíticos (patrón capture): cada pestaña enlaza a su
	// contenido canónico real (no duplica vistas). La infra queda por URL.
	$insight_mod_active = static function ( string $t, string $s ) use ( $tab, $sub ): bool {
		return $tab === $t && $sub === $s;
	};
	$insight_mod_items = array(
		array(
			'href'   => admin_url( 'admin.php?page=estandar-suite-insights&tab=config&sub=analytics' ),
			'label'  => 'Analytics',
			'active' => $insight_mod_active( 'config', 'analytics' ),
		),
		array(
			'href'   => admin_url( 'admin.php?page=estandar-suite-insights&tab=estado&sub=datos' ),
			'label'  => 'Funnel',
			'active' => $insight_mod_active( 'estado', 'datos' ),
		),
	);
	// Adopción: solo si hay datos legados que adoptar (evita mostrar ceros).
	if ( ! empty( $adopt_available ) ) {
		$insight_mod_items[] = array(
			'href'   => admin_url( 'admin.php?page=estandar-suite-insights&tab=datos&sub=adopcion' ),
			'label'  => 'Adopción',
			'active' => $insight_mod_active( 'datos', 'adopcion' ),
		);
	}
	$insight_mod_items = array_merge( $insight_mod_items, array(
		array(
			'href'   => admin_url( 'admin.php?page=estandar-suite-insights&tab=config&sub=seo' ),
			'label'  => 'SEO',
			'active' => $insight_mod_active( 'config', 'seo' ),
		),
		array(
			'href'   => admin_url( 'admin.php?page=estandar-suite-insights&tab=config&sub=settings' ),
			'label'  => 'Configuración',
			'active' => $insight_mod_active( 'config', 'settings' ),
		),
	) );
	\Estandar\Core\Admin::render_nav_tabs( $insight_mod_items );
	?>


	<?php if ( \Estandar\Core\Admin::native_blank() ) : ?>
		<!-- Backend Ordenado: solo estructura (sin contenido). -->
		<div class="notice notice-info inline" style="margin:12px 0;max-width:760px;"><p>Backend Ordenado: esta pantalla está en modo <strong>solo estructura</strong>; nada se eliminó.</p></div>
	<?php elseif ( $tab === 'estado' ) : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-insights', 'estado', $sub, $subs['estado'] ); ?>

		<?php if ( $sub === 'resumen' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'resumen', '<strong>¿Para qué sirve?</strong> Es la tarjeta de presentación de Insights: de un vistazo ves el rol (analizar sin inventar éxito), cuántos eventos hay y si existe analytics legado. <br><br>Es el <em>cuadro de mando del analista</em>: aquí sabes si la suite tiene datos con qué hablar. Si no hay datos, Insights lo dice en claro, nunca inventa.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Rol</strong></td><td>Análisis: eventos analíticos, funnel comercial, retención y tráfico web.</td></tr>
				<tr><td><strong>Eventos</strong></td><td><?php echo count( $events ); ?> recientes</td></tr>
				<tr><td><strong>Instalación legada</strong></td><td><?php echo $legacy ? 'SÍ — analytics histórico de ea_events/ea_sessions disponible.' : 'NO — sin analytics legado.'; ?></td></tr>
			</tbody>
		</table>
		<p style="margin-top:10px;">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-insights&tab=estado&sub=datos' ) ); ?>">Ver análisis</a>
			<?php if ( ! empty( $adopt_available ) ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-insights&tab=datos&sub=adopcion' ) ); ?>">Ver adopción</a><?php endif; ?>
		</p>
		<?php elseif ( $sub === 'datos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'datos', '<strong>¿Para qué sirve?</strong> Es la <em>lectura en vivo</em> del negocio: el <strong>funnel comercial</strong> (cuántos registros hay en cada etapa y cómo caen) y los <strong>eventos recientes</strong> con su canal y proyecto. <br><br>Es el <em>dashboard de resultados</em>: todo se calcula de datos reales. Si no hay actividad, el funnel se ve vacío.' ); ?>
		<h3>Funnel comercial</h3>
		<p class="description" style="max-width:820px;">Etapas del pipeline comercial (Flow). La barra es proporcional al volumen y se marca <span style="color:#b32d2e;">⚠ cuello</span> cuando la conversión respecto al paso anterior es menor al 30%.</p>
		<?php echo \Estandar\Insights\Visual::funnel_svg( \Estandar\Insights\Visual::rows_from_stages( (array) ( $funnel['stages'] ?? array() ) ), 'registros' ); ?>

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
	
		<?php if ( $sub === 'analytics' ) : ?>
		<?php $feature = 'analytics'; $feature_fields = array(); $feature_option = ''; include ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/templates/feature-analytics.php'; ?>

		<?php elseif ( $sub === 'seo' ) : ?>
		<?php $feature = 'seo'; $feature_fields = $feature_fields['seo']; $feature_option = 'estandar_suite_insights_seo_settings'; include ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/templates/feature-seo.php'; ?>


		<?php elseif ( $sub === 'settings' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'settings', '<strong>¿Para qué sirve?</strong> Es el <em>panel de mandos</em> de Insights: aquí se activan o desactivan los módulos y se ajustan sus parámetros. Cada ajuste se guarda por separado; abajo quedan, en solo lectura, las <em>reglas del análisis</em> y las opciones internas.' ); ?>
		<?php if ( isset( $_GET['feature_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Ajustes guardados.</p></div><?php endif; ?>

		<h3 style="margin-bottom:4px;">SEO</h3>
		<p class="description" style="max-width:760px;">Módulo de posicionamiento (landings y métricas de búsqueda).</p>
		<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_insights_save_feature', 'estandar_suite_insights_seo_settings', $feature_fields['seo'], 'seo' ); ?>

		<h3>Reglas del análisis <span class="description" style="font-weight:400;">(información)</span></h3>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Tipos cerrados</strong></td><td>Los eventos solo se aceptan si pertenecen al conjunto definido por <code>sanitize_type</code> (no hay tipos libres).</td></tr>
				<tr><td><strong>Canales</strong></td><td>Usa <code>Core\Channels</code> para canales; sin dependencia dura con Capture.</td></tr>
				<tr><td><strong>Funnel</strong></td><td>Se alimenta de Flow cuando está activo (guard <code>class_exists</code>); sin Flow, muestra ausencia honesta (UNKNOWN).</td></tr>
			</tbody>
		</table>

		<h3>Opciones internas <span class="description" style="font-weight:400;">(solo lectura)</span></h3>
		<?php \Estandar\Core\Admin::render_settings_panel( \Estandar\Insights\Admin::get_settings() ); ?>
 		<?php else : ?>
 		<?php echo \Estandar\Core\Admin::help_html( 'visibilidad', '<strong>Visibilidad consolidada en Core.</strong> La visibilidad de pestañas de todos los plugins de la suite (incluido Insights) se administra desde <strong>Estandar Suite → Core → Config → Visibilidad</strong>. Este cambio solo afecta la visualización; ninguna función, setting o dato se pierde.' ); ?>
 		<?php endif; ?>


	<?php elseif ( $tab === 'datos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'adopcion', '<strong>¿Para qué sirve?</strong> Importa el <em>analytics histórico</em> de la instalación anterior (v1, tablas <code>ea_*</code>) a tablas propias del v2 (<code>estandar_insights_analytics_*</code>): sesiones, pageviews, eventos, conversiones y agregados diarios. <br><br>Es una <em>mudanza cuidadosa</em>: previsualizas (dry-run) cuánto se importará, luego confirmas (commit) y, si algo no cuadra, haces rollback y lo adoptado se elimina. Los originales quedan intactos siempre.' ); ?>
		<p>Instalación legada: <strong><?php echo $legacy ? 'SÍ — analytics histórico de ea_* disponible' : 'NO — sin analytics legado'; ?></strong></p>

		<?php if ( $imp !== null ) : ?><div class="notice <?php echo ( ! empty( $failed ) ) ? 'notice-warning' : 'notice-success'; ?> is-dismissible"><p>Adopción analytics: <strong><?php echo (int) $imp; ?></strong> nuevos<?php if ( ! empty( $failed ) ) : ?>, <strong><?php echo (int) $failed; ?></strong> omitidos (conflicto de clave única)<?php endif; ?>.</p></div><?php endif; ?>
		<?php if ( $rol !== null ) : ?><div class="notice notice-success is-dismissible"><p>Rollback analytics: <strong><?php echo (int) $rol; ?></strong>.</p></div><?php endif; ?>

		<h3>Estado de la herencia</h3>
		<table class="widefat striped" style="max-width:640px;">
			<thead><tr><th>Superficie</th><th>Legacy (ea_*)</th><th>Adoptado (v2)</th></tr></thead>
			<tbody>
			<?php foreach ( $analytics_status as $surface => $st ) : ?>
				<tr>
					<td><code><?php echo esc_html( $surface ); ?></code></td>
					<td><?php echo $st['legacy_exists'] ? (int) $st['legacy'] : '—'; ?></td>
					<td><?php echo (int) $st['adopted']; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $analytics_status ) ) : ?><tr><td colspan="3">Sin superficies.</td></tr><?php endif; ?>
			</tbody>
		</table>

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
		<?php echo \Estandar\Core\Admin::help_html( 'rest', '<strong>¿Para qué sirve?</strong> Lista los <em>endpoints REST</em> de Insights para que otros sistemas consulten el funnel, los eventos, el embudo web y la adopción vía HTTP. Todos requieren autenticación y permisos de administrador. <br><br>Es la <em>puerta de servicio</em>: lo que la UI hace con clicks, los sistemas lo hacen por aquí.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>GET /estandar-suite/insights/v1/funnel</code></td><td>Ver funnel comercial</td></tr>
				<tr><td><code>GET /estandar-suite/insights/v1/events</code></td><td>Ver eventos</td></tr>
				<tr><td><code>GET /estandar-suite/insights/v1/funnel/web</code></td><td>Ver embudo web (sesiones→leads)</td></tr>
				<tr><td><code>GET /estandar-suite/insights/v1/analytics/visits</code></td><td>Serie diaria de visitas</td></tr>
				<tr><td><code>GET /estandar-suite/insights/v1/adoption</code></td><td>Estado de herencia (analytics + SEO)</td></tr>
				<tr><td><code>POST /estandar-suite/insights/v1/adoption/import</code></td><td>Importar adoptado (commit)</td></tr>
				<tr><td><code>POST /estandar-suite/insights/v1/adoption/rollback</code></td><td>Rollback de adoptado</td></tr>
			</tbody>
		</table>
		<?php elseif ( $sub === 'cli' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'cli', '<strong>¿Para qué sirve?</strong> Muestra los <em>comandos de WP-CLI</em> de Insights para operar el análisis desde la terminal: ver el funnel comercial y web, las visitas, la auditoría SEO o importar/hacer rollback del analytics histórico. <br><br>Es el <em>panel de control por voz</em>: lo que haces con clicks en la UI, lo haces con una línea de texto.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>wp estandar-suite insights funnel</code></td><td>Ver funnel comercial</td></tr>
				<tr><td><code>wp estandar-suite insights funnel-web [--from=] [--to=]</code></td><td>Ver embudo web (sesiones→leads)</td></tr>
				<tr><td><code>wp estandar-suite insights visits [--from=] [--to=]</code></td><td>Serie diaria de visitas</td></tr>
				<tr><td><code>wp estandar-suite insights events backfill-channel [--limit=N]</code></td><td>Completar el canal de señales antiguas</td></tr>
				<tr><td><code>wp estandar-suite insights adoption import [--commit]</code></td><td>Migrar analytics histórico (5 superficies) a tablas v2</td></tr>
				<tr><td><code>wp estandar-suite insights adoption status</code></td><td>Estado de herencia (legacy vs adoptado)</td></tr>
				<tr><td><code>wp estandar-suite insights adoption rollback --confirm=yes</code></td><td>Rollback de adoptado</td></tr>
				<tr><td><code>wp estandar-suite insights seo scan [--limit=N]</code></td><td>Auditoría SEO técnica del sitio (Tier 1)</td></tr>
				<tr><td><code>wp estandar-suite insights external import|status|purge</code></td><td>Relleno externo de días sin dato</td></tr>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'inventario', '<strong>¿Para qué sirve?</strong> El <em>catálogo completo</em> de Insights: todas las funciones del plugin agrupadas por conjuntos funcionales, para que ninguna quede fuera de la interfaz. <br><br>Es el <em>inventario del almacén</em>: confirma que todo lo que el código puede hacer tiene su lugar visible. Sirve para auditar cobertura y detectar funciones escondidas.' ); ?>
		<?php \Estandar\Core\Admin::render_inventory( $sets, $fn_settings ); ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php echo \Estandar\Core\Admin::help_script(); ?>
