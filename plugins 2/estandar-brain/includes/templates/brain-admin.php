<?php
/** Estandar Suite — Brain admin. 4 tabs (Estado · Configuración · Datos ·
 * Avanzado) con sub-tabs por grupo funcional.
 * Variables: $tab, $tabs, $subs, $sub, $sets, $fn_settings, $intents, $rules,
 * $signals, $legacy, $intent_saved, $rule_saved.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( empty( $fn_settings ) ) { $fn_settings = array(); }
?>
<div class="wrap">
	<h1>Estandar Suite — Brain</h1>

	<?php \Estandar\Core\Admin::render_tabs( 'estandar-suite-brain', $tab, $tabs ); ?>

	<?php if ( $tab === 'estado' ) : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-brain', 'estado', $sub, $subs['estado'] ); ?>

		<?php if ( $sub === 'resumen' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'resumen', '<strong>¿Para qué sirve?</strong> Es la tarjeta de presentación de Brain: de un vistazo ves el rol (dar inteligencia a la suite), cuántas señales hay registradas y si existe instalación legada con señales históricas. <br><br>Es el <em>tablero de estado del cerebro</em>: confirmas que está activo y saltas a configurar intents o reglas con un click.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Rol</strong></td><td>Inteligencia transversal: intents, reglas deterministas, scoring (wC/wQ/wB) y ledger de señales canónicas.</td></tr>
				<tr><td><strong>Señales</strong></td><td><?php echo count( $signals ); ?> recientes en el ledger</td></tr>
				<tr><td><strong>Instalación legada</strong></td><td><?php echo $legacy ? 'SÍ — señales de es_lead_events/es_leads disponibles.' : 'NO — sin señales legadas.'; ?></td></tr>
			</tbody>
		</table>
		<p style="margin-top:10px;">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-brain&tab=config&sub=intents' ) ); ?>">Configurar intents</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-brain&tab=config&sub=reglas' ) ); ?>">Configurar reglas</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-brain&tab=datos&sub=adopcion' ) ); ?>">Ver adopción</a>
		</p>
		<?php elseif ( $sub === 'datos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'datos', '<strong>¿Para qué sirve?</strong> Muestra el <em>ledger de señales</em>: el registro canónico de todo lo que la suite ha detectado (un lead abrió, calificó, compró…), con entidad, proyecto, valor y fecha. <br><br>Es la <em>bitácora del comportamiento</em>: aquí ves lo que Brain sabe, en crudo y sin interpretar. De esta bitácora se alimentan los análisis de Insights.' ); ?>
		<h3>Señales recientes (ledger)</h3>
		<table class="widefat striped">
			<thead><tr><th>Evento</th><th>Entidad</th><th>Proyecto</th><th>Valor</th><th>Fecha</th></tr></thead>
			<tbody>
			<?php foreach ( $signals as $s ) : ?>
				<tr>
					<td><code><?php echo esc_html( $s['event_name'] ); ?></code></td>
					<td><?php echo esc_html( $s['entity_type'] . '#' . $s['entity_id'] ); ?></td>
					<td><?php echo esc_html( $s['project_slug'] ); ?></td>
					<td><?php echo $s['value_decimal'] !== null ? esc_html( $s['value_decimal'] . ' ' . $s['currency'] ) : '—'; ?></td>
					<td><?php echo esc_html( $s['event_time'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $signals ) ) : ?><tr><td colspan="5">Sin señales todavía.</td></tr><?php endif; ?>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'esquema', '<strong>¿Para qué sirve?</strong> Muestra las <em>tablas propias</em> de Brain: <code>estandar_brain_signals</code> (el ledger) y <code>estandar_brain_signal_queue</code> (cola de señales por procesar). <br><br>Es la <em>ficha técnica</em> de dónde vive la inteligencia. No se edita aquí; sirve para entender el modelo de datos.' ); ?>
		<p><code>estandar_brain_signals</code> · <code>estandar_brain_signal_queue</code></p>
		<?php endif; ?>

	<?php elseif ( $tab === 'config' ) : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-brain', 'config', $sub, $subs['config'] ); ?>

		<?php if ( $sub === 'intents' ) : ?>
		<?php if ( $intent_saved ) : ?><div class="notice notice-success is-dismissible"><p>Mapa de intents guardado.</p></div><?php endif; ?>
		<?php echo \Estandar\Core\Admin::help_html( 'intents', '<strong>¿Para qué sirve?</strong> Define cuántos <em>puntos</em> (0-100) vale cada intención: cuando una conversación muestra «quiero trabajo», «necesito precio» u otra intención, Brain suma sus puntos para calcular el <em>score</em> del lead. <br><br>Es como <em>asignar valor a cada señal de interés</em>: pedir presupuesto pesa más que solo navegar. Los valores de fábrica se marcan como óptimos; puedes ajustarlos a tu negocio.' ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'estandar_suite_brain_save_intents' ); ?>
			<input type="hidden" name="action" value="estandar_suite_brain_save_intents">
			<table class="widefat striped">
				<thead><tr><th>Intent</th><th>Puntos (0-100)</th></tr></thead>
				<tbody>
				<?php $defaults = \Estandar\Brain\IntentMap::defaults(); ?>
				<?php foreach ( $intents as $key => $points ) : $optimal = array_key_exists( $key, $defaults ) ? (int) $defaults[ $key ] : null; ?>
					<tr>
						<td><code><?php echo esc_html( $key ); ?></code><?php if ( $optimal === null ) : ?> <span class="description">(personalizado — sin óptimo de fábrica)</span><?php endif; ?></td>
						<td><input type="number" name="intent[<?php echo esc_attr( $key ); ?>]" value="<?php echo (int) $points; ?>" min="0" max="100"><?php if ( $optimal !== null ) : echo \Estandar\Core\Optimal::note( $points, $optimal, (string) $optimal ); endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( 'Guardar intents' ); ?>
		</form>
		<?php elseif ( $sub === 'reglas' ) : ?>
		<?php if ( $rule_saved ) : ?><div class="notice notice-success is-dismissible"><p>Reglas guardadas.</p></div><?php endif; ?>
		<?php echo \Estandar\Core\Admin::help_html( 'reglas', '<strong>¿Para qué sirve?</strong> Permite escribir <em>reglas automáticas</em> (JSON) que reaccionan a hechos concretos: si un mensaje contiene «trabajo», marcar el lead como <em>fuera de perfil</em>, detenerlo o fijar su integridad. <br><br>Son como los <em>reflejos condicionados</em> del cerebro: condiciones fijas que se disparan siempre igual. Operadores disponibles: <code>contains</code>, <code>equals</code>, <code>gt</code>, <code>in</code>, etc.' ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'estandar_suite_brain_save_rules' ); ?>
			<input type="hidden" name="action" value="estandar_suite_brain_save_rules">
			<textarea name="rules_json" class="large-text code" rows="10" placeholder='[{"id":"r1","conditions":[{"field":"content.message","op":"contains","value":"trabajo"}],"action":{"commercial_fit":"FUERA_DE_PERFIL"}}]'><?php echo esc_textarea( wp_json_encode( array_values( $rules ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea>
			<p class="description">Reglas en buckets: se guardan como "global". Operadores: equals, contains, starts_with, gt, gte, lt, lte, in, field_exists, empty, not_empty. Acciones: <code>commercial_fit</code>, <code>integrity</code>, <code>stop</code>.</p>
			<?php submit_button( 'Guardar reglas' ); ?>
		</form>
		<?php elseif ( $sub === 'senales' ) : $signal = \Estandar\Brain\Admin::template_signal_data(); ?>
		<?php echo \Estandar\Core\Admin::help_html( 'senales', '<strong>¿Para qué sirve?</strong> Muestra el <em>ledger de señales</em>: el registro canónico de eventos comerciales (captura, contacto, reunión, venta…), idempotente, con su estado de exportación a Meta/Google. <br><br>Es la <em>bitácora auditada</em> de la inteligencia: lo que Brain sabe, con su historial.' ); ?>
		<h4>Señales (<?php echo (int) $signal['total']; ?> total · <?php echo (int) $signal['queue']; ?> en cola)</h4>
		<table class="widefat striped">
			<thead><tr><th>ID</th><th>Evento</th><th>Lead</th><th>Proyecto</th><th>Estado</th><th>Valor</th><th>Fecha</th></tr></thead>
			<tbody>
			<?php foreach ( $signal['rows'] as $s ) : ?>
				<tr>
					<td><?php echo (int) $s['id']; ?></td>
					<td><code><?php echo esc_html( $s['event_name'] ); ?></code></td>
					<td><?php echo $s['lead_id'] ? (int) $s['lead_id'] : '—'; ?></td>
					<td><?php echo esc_html( $s['project_slug'] ); ?></td>
					<td><?php echo esc_html( $s['export_status'] ); ?></td>
					<td><?php echo $s['value_decimal'] !== null ? esc_html( $s['value_decimal'] . ' ' . $s['currency'] ) : '—'; ?></td>
					<td><?php echo esc_html( $s['event_time'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $signal['rows'] ) ) : ?><tr><td colspan="7">Sin señales.</td></tr><?php endif; ?>
			</tbody>
		</table>
		<?php elseif ( $sub === 'exportadores' ) : $exp_groups = \Estandar\Brain\Admin::exporters_settings_groups(); $exp_ssubs = array( 'meta' => 'Meta CAPI', 'google' => 'Google Ads OCI' ); $exp_ss = \Estandar\Core\Admin::resolve_ssub( $exp_ssubs, 'meta', 'ss' ); ?>
		<?php echo \Estandar\Core\Admin::help_html( 'exportadores', '<strong>¿Para qué sirve?</strong> Conecta las señales con <em>Meta Conversions API</em> y <em>Google Ads OCI</em> para exportar eventos de negocio (lead_created, meeting_booked, sale_closed). <br><br>Cada exportador usa sus propias credenciales.' ); ?>
		<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-brain', 'config', $sub, $exp_ss, $exp_ssubs ); ?>
		<?php if ( isset( $_GET['exporters_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Exportadores guardados.</p></div><?php endif; ?>
		<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_brain_save_exporters', 'estandar_suite_brain_exporters_settings', $exp_groups[ $exp_ss ], '', array( 'group' => $exp_ss ) ); ?>
		<?php elseif ( $sub === 'agente' ) : $history = \Estandar\Brain\Agent::history( 20 ); ?>
		<?php echo \Estandar\Core\Admin::help_html( 'agente', '<strong>¿Para qué sirve?</strong> El <em>agente interno</em> (chatbot del backend) ejecuta peticiones en lenguaje natural dentro de la instalación: crea formularios, configura notificaciones por email, lista plugins/leads/usuarios y pasa comandos WP-CLI nativos. <br><br>Las lecturas corren directo; las escrituras piden confirmación y las destructivas piden confirmación explícita. Todo queda auditado.' ); ?>
		<?php if ( isset( $_GET['agent_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Activación guardada.</p></div><?php endif; ?>
		<h4>Activación</h4>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="estandar_suite_brain_save_agent_activation" />
			<?php wp_nonce_field( 'estandar_suite_brain_save_agent_activation' ); ?>
			<?php $agent_act = \Estandar\Brain\Agent::activation(); ?>
			<table class="widefat striped" style="max-width:620px;">
				<tbody>
					<tr><td><label><input type="checkbox" name="agent_internal" value="1" <?php checked( $agent_act['agent_internal'] ); ?> /> <strong>Agente interno</strong></label><p class="description">Habilita el endpoint interno y la burbuja del backend (no afecta a WP-CLI).</p></td></tr>
					<tr><td><label><input type="checkbox" name="bubble_admin" value="1" <?php checked( $agent_act['bubble_admin'] ); ?> /> <strong>Burbuja interna en el backend</strong></label><p class="description">Muestra la burbuja 🤖 flotante en todo el admin (solo admins). Requiere «Agente interno».</p></td></tr>
					<tr><td><label><input type="checkbox" name="agent_public" value="1" <?php checked( $agent_act['agent_public'] ); ?> /> <strong>Agente público</strong></label><p class="description">Habilita el endpoint público y el shortcode <code>[estandar_agent]</code> (burbuja del frontend).</p></td></tr>
				</tbody>
			</table>
			<?php submit_button( 'Guardar activación', 'primary', 'submit', false ); ?>
		</form>
		<h4>Chat del agente interno</h4>
		<?php echo \Estandar\Brain\PublicChat::bubble( array(
			'mode'        => 'inline',
			'internal'    => true,
			'endpoint'    => rest_url( 'estandar-suite/brain/v1/agent' ),
			'welcome'     => 'Pídeme en lenguaje natural, p. ej. «haz un formulario para la página de contactos que mande notificaciones al correo del admin». Las escrituras piden confirmación.',
			'placeholder' => 'Petición…',
			'id'          => 'estandar-agent-inline-brain',
		) ); ?>
		<p class="description">La misma burbuja flota en todo el backend (🤖 abajo a la derecha). También por terminal: <code>wp estandar-suite agent run "petición"</code> y <code>wp estandar-suite agent chat</code>.</p>
		<h4>Herramientas</h4>
		<table class="widefat striped" style="max-width:760px;">
			<thead><tr><th>Tool</th><th>Nivel</th><th>Descripción</th></tr></thead>
			<tbody>
			<?php foreach ( \Estandar\Brain\Agent::tools() as $tid => $t ) : ?>
				<tr><td><code><?php echo esc_html( $tid ); ?></code></td><td><?php echo esc_html( $t['level'] ); ?></td><td><?php echo esc_html( $t['help'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<h4>Conocimiento del sitio (fuente del agente público)</h4>
		<table class="widefat striped" style="max-width:520px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Fragmentos indexados</strong></td><td><?php echo (int) \Estandar\Brain\Knowledge::count(); ?></td></tr>
				<tr><td><strong>Último indexado</strong></td><td><?php echo esc_html( get_option( \Estandar\Brain\Knowledge::LAST_INDEXED, 'nunca' ) ); ?></td></tr>
			</tbody>
		</table>
		<p class="description">Índice construido por terminal: <code>wp estandar-suite brain knowledge index</code> · lista: <code>knowledge list</code> · vaciar: <code>knowledge clear</code>. El chatbot público responde con el contenido publicado (páginas/posts) citando la URL.</p>
		<h4>Widget público</h4>
		<p class="description">Coloca el shortcode <code>[estandar_agent]</code> en cualquier página para mostrar el chat del agente público (usa el mismo motor con perfil público: solo lectura del sitio + acciones de UI). El perfil interno (este panel + WP-CLI) sigue siendo solo admin.</p>
		<h4>Historial (<?php echo count( $history ); ?>)</h4>
		<table class="widefat striped">
			<thead><tr><th>ID</th><th>Fecha</th><th>Usuario</th><th>Estado</th><th>Petición</th><th>Plan</th></tr></thead>
			<tbody>
			<?php foreach ( $history as $h ) : $hplan = json_decode( (string) $h['plan'], true ); $hsum = array(); foreach ( (array) $hplan as $a ) { $hsum[] = \Estandar\Brain\Agent::summarize( $a['tool'] ?? '', $a['params'] ?? array() ); } ?>
				<tr>
					<td><?php echo (int) $h['id']; ?></td>
					<td><?php echo esc_html( $h['created_at'] ); ?></td>
					<td><?php echo esc_html( $h['user_email'] ); ?></td>
					<td><?php echo esc_html( $h['status'] ); ?></td>
					<td><?php echo esc_html( $h['request'] ); ?></td>
					<td><?php echo esc_html( implode( ' | ', $hsum ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $history ) ) : ?><tr><td colspan="6">Sin ejecuciones del agente.</td></tr><?php endif; ?>
			</tbody>
		</table>
		<?php elseif ( $sub === 'settings' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'settings', '<strong>¿Para qué sirve?</strong> Ajustes operativos de Brain agrupados por área (infraestructura, operación, esquema, UI) con valores recomendados de fábrica. <br><br>Es el <em>panel de mandos</em>: aquí se decide cómo se comporta la inteligencia por defecto. La sección «Settings heredados» muestra en solo lectura lo que dejó el plugin v1.' ); ?>
		<?php \Estandar\Core\Admin::render_settings_panel( \Estandar\Brain\Admin::get_settings() ); ?>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'visibilidad', '<strong>¿Para qué sirve?</strong> Decide qué pestañas y sub-pestañas ves en Brain. Es <strong>solo visual</strong>: las funciones y los datos siguen funcionando aunque ocultes una pestaña. <br><br>Es como <em>ordenar el escritorio</em>: si no usas «Settings», lo ocultas para tener una interfaz más limpia. La pestaña de Visibilidad siempre queda accesible, para que nunca te quedes sin acceso.' ); ?>
		<?php if ( isset( $_GET['visibility_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Visibilidad guardada.</p></div><?php endif; ?>
		<?php
		$visibility_subs = array(
			'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'config'   => array( 'intents' => 'Intents', 'reglas' => 'Reglas', 'senales' => 'Señales', 'exportadores' => 'Exportadores', 'agente' => 'Agente', 'settings' => 'Settings', 'visibilidad' => 'Visibilidad' ),
			'datos'    => array( 'adopcion' => 'Adopción' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);
		\Estandar\Core\Admin::render_visibility_manager( 'brain', 'estandar-suite-brain', $visibility_subs );
		?>
		<?php endif; ?>

	<?php elseif ( $tab === 'datos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'adopcion', '<strong>¿Para qué sirve?</strong> Importa <em>señales de comportamiento históricas</em> de la instalación anterior (v1, tablas <code>es_lead_events</code>/<code>es_leads</code>) al ledger nuevo de Brain. <br><br>Es una <em>mudanza cuidadosa</em>: por defecto hace dry-run (no escribe nada), es idempotente y nunca toca las tablas <code>es_signal_*</code>. El rollback elimina solo lo adoptado; los originales quedan intactos.' ); ?>
		<p>Instalación legada: <strong><?php echo $legacy ? 'SÍ — señales de es_lead_events/es_leads disponibles' : 'NO — sin señales legadas'; ?></strong></p>
		<p class="description">Comando: <code>wp estandar-suite brain adoption import --lead_id=N [--commit]</code> · Rollback: <code>adoption rollback --confirm=yes</code>.</p>

	<?php else : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-brain', 'avanzado', $sub, $subs['avanzado'] ); ?>

		<?php if ( $sub === 'rest' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'rest', '<strong>¿Para qué sirve?</strong> Lista los <em>endpoints REST</em> de Brain para que otros sistemas calculen scores, consulten señales o guarden reglas vía HTTP. Todos requieren autenticación y permisos de administrador. <br><br>Es la <em>puerta de servicio</em>: lo que la UI hace con clicks, los sistemas lo hacen por aquí.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>POST /estandar-suite/brain/v1/score</code></td><td>Calcular score</td></tr>
				<tr><td><code>GET /estandar-suite/brain/v1/signals</code></td><td>Ver señales</td></tr>
				<tr><td><code>POST /estandar-suite/brain/v1/rules</code></td><td>Guardar reglas</td></tr>
			</tbody>
		</table>
		<?php elseif ( $sub === 'cli' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'cli', '<strong>¿Para qué sirve?</strong> Muestra los <em>comandos de WP-CLI</em> de Brain para operar la inteligencia desde la terminal: calcular scores, ver señales, guardar intents/reglas o importar señales históricas. <br><br>Es el <em>panel de control por voz</em>: lo que haces con clicks en la UI, lo haces con una línea de texto.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>wp estandar-suite brain score</code></td><td>Calcular score</td></tr>
				<tr><td><code>wp estandar-suite brain signals</code></td><td>Ver señales</td></tr>
				<tr><td><code>wp estandar-suite brain intents|intents set</code></td><td>Ver/guardar intents</td></tr>
				<tr><td><code>wp estandar-suite brain rules|rules set</code></td><td>Ver/guardar reglas</td></tr>
				<tr><td><code>wp estandar-suite brain adoption import --lead_id=N [--commit]</code></td><td>Importar señales históricas</td></tr>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'inventario', '<strong>¿Para qué sirve?</strong> El <em>catálogo completo</em> de Brain: todas las funciones del plugin agrupadas por conjuntos funcionales, para que ninguna quede fuera de la interfaz. <br><br>Es el <em>inventario del almacén</em>: confirma que todo lo que el código puede hacer tiene su lugar visible. Sirve para auditar cobertura y detectar funciones escondidas.' ); ?>
		<?php \Estandar\Core\Admin::render_inventory( $sets, $fn_settings ); ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php echo \Estandar\Core\Admin::help_script(); ?>
