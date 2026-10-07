<?php
/** Estandar Suite — Brain admin. 4 tabs (Estado · Configuración · Datos ·
 * Avanzado) con sub-tabs por grupo funcional.
 * Variables: $tab, $tabs, $subs, $sub, $sets, $fn_settings, $intents, $rules,
 * $signals, $legacy, $intent_saved, $rule_saved.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( empty( $fn_settings ) ) { $fn_settings = array(); }
// Blindaje: el template corre dentro del admin; una variable no definida no debe
// provocar un fatal a nivel de sitio (ya pasó con sub=exportadores/agente).
$subs       = isset( $subs ) && is_array( $subs ) ? $subs : array();
$ss         = isset( $ss ) ? (string) $ss : '';
$ssubs      = isset( $ssubs ) && is_array( $ssubs ) ? $ssubs : array();
$history    = isset( $history ) && is_array( $history ) ? $history : array();
$exp_groups = isset( $exp_groups ) && is_array( $exp_groups ) ? $exp_groups : array();
$exp_ssubs  = isset( $exp_ssubs ) && is_array( $exp_ssubs ) ? $exp_ssubs : array();
$exp_ss     = isset( $exp_ss ) ? (string) $exp_ss : '';
?>
<div class="wrap">

		<?php \Estandar\Core\Admin::render_module_tabs( 'estandar-suite-brain', $tab, $sub, array( 'exportadores' => 'Exportadores', 'agente' => 'Agente', 'settings' => 'Settings' ) ); ?>


	<?php if ( \Estandar\Core\Admin::native_blank() ) : ?>
		<!-- Backend Ordenado: solo estructura (sin contenido). -->
		<div class="notice notice-info inline" style="margin:12px 0;max-width:760px;"><p>Backend Ordenado: esta pantalla está en modo <strong>solo estructura</strong>; nada se eliminó.</p></div>
	<?php elseif ( $tab === 'estado' ) : ?>
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

		<?php if ( $sub === 'exportadores' ) : $exp_groups = \Estandar\Brain\Admin::exporters_settings_groups(); $exp_ssubs = \Estandar\Core\Admin::filter_ssubs( 'brain', 'config', 'exportadores', \Estandar\Brain\Admin::exporters_ssubs() ); $exp_ss = \Estandar\Core\Admin::resolve_ssub( $exp_ssubs, 'meta', 'ss' ); ?>
		<?php echo \Estandar\Core\Admin::help_html( 'exportadores', '<strong>¿Para qué sirve?</strong> Conecta las señales con <em>Meta Conversions API</em> y <em>Google Ads (OCI)</em> para exportar eventos de negocio (lead_created, meeting_booked, sale_closed). <br><br><strong>Meta</strong> envía el evento con email/teléfono hasheados + fbc/fbp. <strong>Google</strong> sube la conversión con el <em>click id</em> (gclid/wbraid/gbraid) a la Google Ads API; si la señal no trae click id, se omite (no ensucia la cuenta). Cada exportador usa sus propias credenciales y se procesa por la cola con reintentos.' ); ?>
		<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-brain', 'config', $sub, $exp_ss, $exp_ssubs ); ?>
		<?php if ( isset( $_GET['exporters_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Exportadores guardados.</p></div><?php endif; ?>
		<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_brain_save_exporters', 'estandar_suite_brain_exporters_settings', $exp_groups[ $exp_ss ], '', array( 'group' => $exp_ss ) ); ?>
		<?php elseif ( $sub === 'agente' ) : $history = \Estandar\Brain\Agent::history( 20 ); ?>
		<?php echo \Estandar\Core\Admin::help_html( 'agente', '<strong>¿Para qué sirve?</strong> El <em>agente interno</em> ejecuta peticiones en lenguaje natural dentro de la instalación: crea formularios, configura notificaciones por email, lista plugins/leads/usuarios y pasa comandos WP-CLI nativos. <br><br>Las lecturas corren directo; las escrituras piden confirmación y las destructivas confirmación explícita. Todo queda auditado. <br><br><strong>Importante:</strong> esto es un <em>agente de acciones</em> del backend, NO el chatbot conversacional del sitio. El chat con IA que ven los visitantes vive en <strong>Capture → Chatbot</strong> (otra configuración y otro motor).' ); ?>
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
		<?php if ( isset( $_GET['knowledge_reindexed'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Índice reconstruido: <?php echo (int) $_GET['knowledge_reindexed']; ?> fragmentos.</p></div><?php endif; ?>
		<table class="widefat striped" style="max-width:520px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Fragmentos indexados</strong></td><td><?php echo (int) \Estandar\Brain\Knowledge::count(); ?></td></tr>
				<tr><td><strong>Último indexado</strong></td><td><?php echo esc_html( get_option( \Estandar\Brain\Knowledge::LAST_INDEXED, 'nunca' ) ); ?></td></tr>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 0;">
			<?php wp_nonce_field( 'estandar_suite_brain_reindex_knowledge' ); ?>
			<input type="hidden" name="action" value="estandar_suite_brain_reindex_knowledge">
			<button type="submit" class="button">⟳ Reindexar conocimiento ahora</button>
		</form>
		<p class="description">El agente público responde con el contenido publicado (páginas/posts) citando la URL. <strong>El índice se reconstruye solo, una vez al día</strong>; este botón lo fuerza. Sin índice, el agente público no tiene de dónde responder. También por terminal: <code>wp estandar-suite brain knowledge index</code>.</p>
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
		<?php echo \Estandar\Core\Admin::help_html( 'settings', '<strong>Motor de decisión.</strong> Aquí se configura cómo Brain decide. Los tres son parte del mismo motor: <em>Ajustes de score</em> (pesos y umbrales), <em>Intenciones</em> (vocabulario según el rubro) y <em>Reglas</em> (condiciones → acción).' ); ?>
		<?php \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite-brain', 'config', 'settings', $ss, $ssubs['settings'] ); ?>
		<?php if ( $ss === 'intents' ) : ?>
		<?php if ( $intent_saved ) : ?><div class="notice notice-success is-dismissible"><p>Vocabulario de intents guardado.<?php if ( isset( $_GET['preset_loaded'] ) ) : ?> Preset del área cargado.<?php endif; ?></p></div><?php endif; ?>
		<?php echo \Estandar\Core\Admin::help_html( 'intents', '<strong>¿Para qué sirve?</strong> Define cuántos <em>puntos</em> (0-100) vale cada intención: cuando una conversación muestra «quiero precio», «necesito agendar» u otra intención, Brain suma sus puntos para calcular el <em>score</em> del lead. <br><br>El vocabulario depende del <strong>área de negocio</strong>: elige el rubro y carga su preset, luego ajusta puntos, quita o agrega intents propios.' ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'estandar_suite_brain_save_intents' ); ?>
			<input type="hidden" name="action" value="estandar_suite_brain_save_intents">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="brain_area">Área de negocio</label></th>
					<td>
						<select name="area" id="brain_area">
							<?php foreach ( \Estandar\Brain\IntentPresets::areas() as $area_slug => $area_label ) : ?>
								<option value="<?php echo esc_attr( $area_slug ); ?>" <?php selected( $area, $area_slug ); ?>><?php echo esc_html( $area_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button" name="load_preset" value="1" onclick="return confirm('Esto reemplaza el vocabulario actual por el preset del área elegida. ¿Continuar?');">Cargar preset del área</button>
						<p class="description">Área actual: <strong><?php echo esc_html( \Estandar\Brain\IntentPresets::areas()[ $area ] ?? $area ); ?></strong>. El preset precarga el vocabulario típico del rubro; después lo editas abajo.</p>
					</td>
				</tr>
			</table>
			<table class="widefat striped" style="max-width:820px;">
				<thead><tr><th style="width:32%;">Intent</th><th style="width:20%;">Puntos (0-100)</th><th>Quitar</th></tr></thead>
				<tbody>
				<?php $preset = \Estandar\Brain\IntentPresets::preset( $area ); ?>
				<?php foreach ( $intents as $key => $points ) : $optimal = array_key_exists( $key, $preset ) ? (int) $preset[ $key ] : null; ?>
					<tr>
						<td><code><?php echo esc_html( $key ); ?></code><?php if ( $optimal === null ) : ?> <span class="description">(propio)</span><?php endif; ?></td>
						<td><input type="number" name="intent[<?php echo esc_attr( $key ); ?>]" value="<?php echo (int) $points; ?>" min="0" max="100"><?php if ( $optimal !== null ) : echo \Estandar\Core\Optimal::note( $points, $optimal, (string) $optimal ); endif; ?></td>
						<td><label><input type="checkbox" name="remove[<?php echo esc_attr( $key ); ?>]" value="1"> quitar</label></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $intents ) ) : ?><tr><td colspan="3" class="description">Sin intents. Elige un área y carga su preset, o agrega intents abajo.</td></tr><?php endif; ?>
				<?php for ( $i = 0; $i < 4; $i++ ) : ?>
					<tr>
						<td><input type="text" name="new_intent_key[]" placeholder="nuevo_intent" class="regular-text"></td>
						<td><input type="number" name="new_intent_points[]" value="10" min="0" max="100"></td>
						<td class="description">agregar</td>
					</tr>
				<?php endfor; ?>
				</tbody>
			</table>
			<?php submit_button( 'Guardar intents' ); ?>
		</form>
		<?php elseif ( $ss === 'reglas' ) : ?>

		<?php if ( $rule_saved ) : ?><div class="notice notice-success is-dismissible"><p>Reglas guardadas.</p></div><?php endif; ?>
		<?php echo \Estandar\Core\Admin::help_html( 'reglas', '<strong>¿Para qué sirve?</strong> Son los <em>reflejos</em> del motor: si una captura cumple una condición, se le aplica una acción (clasificar el fit, fijar integridad, detener). Ej.: si el mensaje contiene «trabajo» → <code>FUERA_DE_PERFIL</code>. <br><br>Se evalúan por <em>prioridad</em> y gana la primera coincidencia. Aquí se editan como filas; el JSON queda para casos avanzados.' ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'estandar_suite_brain_save_rules' ); ?>
			<input type="hidden" name="action" value="estandar_suite_brain_save_rules">
			<?php
			$ops_list = array( 'contains', 'not_contains', 'equals', 'not_equals', 'starts_with', 'field_exists', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte', 'regex', 'in' );
			$fit_list = array( 'MATCH', 'MATCH_PARCIAL', 'DUDOSO', 'FUERA_DE_PERFIL' );
			$ui_rows  = array_merge( array_values( (array) $rules ), array( null, null, null ) );
			?>
			<table class="widefat striped" style="max-width:1080px;">
				<thead><tr><th style="width:24%;">Campo</th><th style="width:14%;">Operador</th><th style="width:22%;">Valor</th><th style="width:20%;">Acción</th><th style="width:9%;">Prior.</th><th>Quitar</th></tr></thead>
				<tbody>
				<?php foreach ( $ui_rows as $i => $r ) :
					$r    = is_array( $r ) ? $r : array();
					$cond = (array) ( $r['conditions'][0] ?? array() );
					$act  = (array) ( $r['action'] ?? array() );
					$new  = empty( $r ); ?>
					<tr>
						<td><input type="text" name="cond_field[]" value="<?php echo esc_attr( (string) ( $cond['field'] ?? '' ) ); ?>" class="regular-text" placeholder="content.message"></td>
						<td><select name="cond_op[]"><?php foreach ( $ops_list as $op ) : ?><option value="<?php echo esc_attr( $op ); ?>" <?php selected( (string) ( $cond['op'] ?? '' ), $op ); ?>><?php echo esc_html( $op ); ?></option><?php endforeach; ?></select></td>
						<td><input type="text" name="cond_value[]" value="<?php echo esc_attr( (string) ( $cond['value'] ?? '' ) ); ?>" class="regular-text"></td>
						<td>
							<select name="act_fit[]"><option value="">— fit —</option><?php foreach ( $fit_list as $fit ) : ?><option value="<?php echo esc_attr( $fit ); ?>" <?php selected( (string) ( $act['commercial_fit'] ?? '' ), $fit ); ?>><?php echo esc_html( $fit ); ?></option><?php endforeach; ?></select>
							<select name="act_integrity[]" style="margin-top:4px;"><option value="">— integridad —</option><?php foreach ( array( 'TEST', 'SPAM', 'FRAUD' ) as $ig ) : ?><option value="<?php echo esc_attr( $ig ); ?>" <?php selected( (string) ( $act['integrity'] ?? '' ), $ig ); ?>><?php echo esc_html( $ig ); ?></option><?php endforeach; ?></select>
							<label style="display:block;margin-top:4px;"><input type="checkbox" name="act_stop[<?php echo (int) $i; ?>]" value="1" <?php checked( ! empty( $act['stop'] ) ); ?>> detener</label>
						</td>
						<td><input type="number" name="priority[]" value="<?php echo (int) ( $r['priority'] ?? 10 ); ?>" min="0" max="999" style="width:64px;"></td>
						<td><?php if ( ! $new ) : ?><label><input type="checkbox" name="remove_rule[<?php echo (int) $i; ?>]" value="1"> quitar</label><?php else : ?><span class="description">agregar</span><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Una condición por regla. Si el campo no existe, la condición no coincide. Usa el modo avanzado para reglas con varias condiciones.</p>
			<?php submit_button( 'Guardar reglas' ); ?>
		</form>
		<details style="margin-top:12px;max-width:1080px;">
			<summary style="cursor:pointer;font-weight:600;">Modo avanzado (JSON)</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
				<?php wp_nonce_field( 'estandar_suite_brain_save_rules' ); ?>
				<input type="hidden" name="action" value="estandar_suite_brain_save_rules">
				<input type="hidden" name="use_json" value="1">
				<textarea name="rules_json" class="large-text code" rows="12" placeholder='[{"id":"r1","conditions":[{"field":"content.message","op":"contains","value":"trabajo"}],"action":{"commercial_fit":"FUERA_DE_PERFIL"}}]'><?php echo esc_textarea( wp_json_encode( array_values( (array) $rules ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea>
				<p class="description">Reglas completas (varias condiciones, operadores, stop). Reemplaza todo el set.</p>
				<?php submit_button( 'Guardar JSON', 'secondary' ); ?>
			</form>
		</details>
		<?php else : ?>
 		<?php if ( isset( $_GET['scoring_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Scoring guardado.</p></div><?php endif; ?>
		<?php $scor = \Estandar\Brain\Scoring::config(); $def = \Estandar\Brain\Scoring::defaults(); ?>
		<h3>Pesos y umbrales del score</h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'estandar_suite_brain_save_scoring' ); ?>
			<input type="hidden" name="action" value="estandar_suite_brain_save_scoring">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Pesos del promedio</th>
					<td>
						<label>Captura <input type="number" step="0.05" min="0" max="1" name="w_capture" value="<?php echo esc_attr( $scor['w_capture'] ); ?>" style="width:80px;"></label>
						<label style="margin-left:12px;">Calificación <input type="number" step="0.05" min="0" max="1" name="w_qualification" value="<?php echo esc_attr( $scor['w_qualification'] ); ?>" style="width:80px;"></label>
						<label style="margin-left:12px;">Comportamiento <input type="number" step="0.05" min="0" max="1" name="w_behavior" value="<?php echo esc_attr( $scor['w_behavior'] ); ?>" style="width:80px;"></label>
						<p class="description">Óptimos de fábrica: <?php echo esc_html( $def['w_capture'] . ' / ' . $def['w_qualification'] . ' / ' . $def['w_behavior'] ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Boost aditivo (perfil / intención)</th>
					<td>
						<label>Perfil <input type="number" step="0.05" min="0" max="1" name="w_profile" value="<?php echo esc_attr( $scor['w_profile'] ); ?>" style="width:80px;"></label>
						<label style="margin-left:12px;">Intención <input type="number" step="0.05" min="0" max="1" name="w_intent" value="<?php echo esc_attr( $scor['w_intent'] ); ?>" style="width:80px;"></label>
						<p class="description">Nunca bajan el total (aditivo monotónico). Óptimos: <?php echo esc_html( $def['w_profile'] . ' / ' . $def['w_intent'] ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Umbrales de temperatura</th>
					<td>
						<label>Caliente ≥ <input type="number" min="0" max="100" name="hot" value="<?php echo esc_attr( $scor['hot'] ); ?>" style="width:70px;"></label>
						<label style="margin-left:12px;">Tibio ≥ <input type="number" min="0" max="100" name="warm" value="<?php echo esc_attr( $scor['warm'] ); ?>" style="width:70px;"></label>
						<p class="description">Óptimos: caliente <?php echo (int) $def['hot']; ?> · tibio <?php echo (int) $def['warm']; ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Decaimiento del comportamiento</th>
					<td>
						<label>Mitad a los <input type="number" min="1" max="365" name="decay_half_days" value="<?php echo esc_attr( $scor['decay_half_days'] ); ?>" style="width:70px;"> días</label>
						<label style="margin-left:12px;">Cero a los <input type="number" min="1" max="365" name="decay_zero_days" value="<?php echo esc_attr( $scor['decay_zero_days'] ); ?>" style="width:70px;"> días</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Peso por señal de comportamiento</th>
					<td>
						<?php foreach ( $def['behavior_weights'] as $bw_key => $bw_def ) : ?>
							<label style="display:inline-block;margin:0 12px 6px 0;"><?php echo esc_html( $bw_key ); ?> <input type="number" min="0" max="100" name="behavior[<?php echo esc_attr( $bw_key ); ?>]" value="<?php echo esc_attr( $scor['behavior_weights'][ $bw_key ] ?? $bw_def ); ?>" style="width:70px;"></label>
						<?php endforeach; ?>
						<p class="description">Óptimos de fábrica: <?php echo esc_html( wp_json_encode( $def['behavior_weights'] ) ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Calificación automática</th>
					<td>
						<label><input type="checkbox" name="assessment_enabled" value="1" <?php checked( \Estandar\Brain\AssessmentEngine::enabled() ); ?>> Evaluar cada captura y fijar <code>commercial_fit</code> / <code>temperature</code> en Flow</label>
						<p class="description">Solo escribe cuando <strong>una regla clasifica</strong> o el contacto es inválido (no marca todo como «dudoso»). Requiere reglas en <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-brain&tab=config&sub=reglas' ) ); ?>">Reglas</a>. Habilita la invariante <code>QUALIFIED</code> de Flow.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Guardar scoring' ); ?>
		</form>
		<h3>Opciones de Brain (solo lectura)</h3>
		<?php \Estandar\Core\Admin::render_settings_panel( \Estandar\Brain\Admin::get_settings() ); ?>
		<?php endif; ?>
		<?php else : ?>
 		<?php echo \Estandar\Core\Admin::help_html( 'visibilidad', '<strong>Visibilidad consolidada en Core.</strong> La visibilidad de pestañas de todos los plugins de la suite (incluido Brain) se administra desde <strong>Estandar Suite → Core → Config → Visibilidad</strong>. Este cambio solo afecta la visualización; ninguna función, setting o dato se pierde.' ); ?>
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
