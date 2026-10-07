<?php
/**
 * Estandar Suite — Core admin page. 4 tabs (Estado · Configuración · Datos ·
 * Avanzado) que agrupan los 7 conjuntos internos de funciones. El tab Avanzado
 * muestra el inventario completo del plugin (cobertura 100%, sin funciones fuera).
 * Variables: $health, $dest, $event_map, $events, $secrets, $legacy, $tab, $tabs,
 * $sets, $fn_settings, $dest_saved, $dest_del, $secret_ok, $test_id, $test_ok, $test_http,
 * $test_err, $map_saved.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( empty( $fn_settings ) ) { $fn_settings = array(); }
$last_secret = get_transient( 'estandar_suite_last_secret_' . get_current_user_id() );
$salud_block = '<h2 style="display:none;">Salud</h2>'
	. '<button type="button" class="button button-secondary" id="estandar-suite-copy-health" title="Copiar diagnóstico de salud al portapapeles">Salud</button>'
	. '<textarea id="estandar-suite-health-text" readonly aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;">' . esc_textarea( "Salud\n" . \Estandar\Core\Health::render_text( $health ) . "\nInstalación legada detectada: " . ( $legacy ? 'SÍ (tablas originales presentes)' : 'NO (instalación limpia)' ) . "\nNota: infraestructura técnica de la suite — REST autenticado y WP-CLI disponibles; esta UI es complementaria." ) . '</textarea>'
	. '<script>(function(){var b=document.getElementById("estandar-suite-copy-health");var t=document.getElementById("estandar-suite-health-text");if(!b||!t)return;b.addEventListener("click",function(){t.focus();t.select();t.setSelectionRange(0,99999);var ok=false;try{ok=document.execCommand("copy")}catch(e){}if(!ok&&navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t.value).catch(function(){})}})})();</script>';
?>
<div class="wrap">
	<h1>Estandar Suite — Core</h1>

	<?php \Estandar\Core\Admin::render_tabs( 'estandar-suite', $tab, $tabs ); ?>

	<?php if ( $tab === 'inicio' ) : ?>
		<!-- ══ INICIO: intro de toda la suite ══ -->
		<div class="notice notice-info inline" style="margin:12px 0;max-width:760px;">
			<p><strong>Operación por terminal (WP-CLI):</strong> toda la suite se puede operar y configurar desde la terminal con <code>wp estandar-suite &lt;producto&gt; &lt;feature&gt; …</code>. Explora todos los comandos con <code>wp help estandar-suite</code>. Esto también aplica a agentes de IA (Claude, Codex, Dragon u otros): pueden configurar la suite vía CLI.</p>
		</div>

	<?php elseif ( $tab === 'estado' ) : ?>
		<!-- ══ ESTADO: Resumen · Datos · Esquema ══ -->
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite', 'estado', $sub, $subs['estado'] ); ?>

		<?php if ( $sub === 'resumen' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'resumen', '<strong>¿Para qué sirve?</strong> Es la tarjeta de presentación del Core: de un vistazo sabes qué es la suite, si hay una instalación anterior (legada) y cuántos destinos, secretos y eventos técnicos existen. <br><br>Piensa en el <em>tablero de mandos</em> de un avión: no pilotas desde aquí, pero confirmas que todo está en su sitio y desde aquí saltas al lugar exacto (configurar telemetría, ver adopción…). <em>Ejemplo:</em> entras y ves «3 destinos configurados» — sabes que los avisos a n8n y a la hoja de Google están operativos sin abrir cada pantalla.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Rol</strong></td><td>Infraestructura técnica de la suite: contratos, telemetría, destinos, secretos, CLI y REST. Prerrequisito de los otros 4 productos.</td></tr>
				<tr><td><strong>Instalación legada</strong></td><td><?php echo $legacy ? 'SÍ — tablas originales presentes. La adopción de datos históricos está disponible.' : 'NO — instalación limpia.'; ?></td></tr>
				<tr><td><strong>Destinos</strong></td><td><?php echo count( $dest ); ?> configurados</td></tr>
				<tr><td><strong>Secretos</strong></td><td><?php echo count( $secrets ); ?> recientes</td></tr>
				<tr><td><strong>Eventos técnicos</strong></td><td><?php echo count( $telemetry ); ?> recientes en telemetría</td></tr>
			</tbody>
		</table>
		<p style="margin-top:10px;">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=telemetria' ) ); ?>">Configurar telemetría</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=destinos' ) ); ?>">Configurar destinos</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite&tab=datos&sub=adopcion' ) ); ?>">Ver adopción</a>
		</p>

		<?php elseif ( $sub === 'datos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'datos', '<strong>¿Para qué sirve?</strong> Es la <em>sala de máquinas</em> del Core: aquí ves la infraestructura compartida que usan los otros 4 productos (contratos, canales, telemetría), el diagnóstico de salud de toda la suite y los últimos eventos técnicos registrados. <br><br>No es para operar, es para <strong>verificar que la casa está bien construida</strong> antes de que Capture, Brain, Flow e Insights trabajen sobre ella. <em>Ejemplo:</em> si un destino falla, aquí verás la anotación «error · HTTP 500 · envío a n8n» sin tener que adivinar.' ); ?>
		<h3>Infraestructura compartida</h3>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Contratos entre productos</strong></td><td>Interfaces <code>TelemetryBus</code>, <code>CaptureSink</code>, <code>SignalLedger</code>, <code>CommercialStageMap</code>, <code>AdoptionProbe</code> — los productos dependen de estas interfaces, nunca de clases de otros productos.</td></tr>
				<tr><td><strong>Channels</strong></td><td>Primitivas compartidas de canal/atribución (click IDs, UTM→canal, evidencia de click), usadas por Capture e Insights sin dependencias cruzadas.</td></tr>
				<tr><td><strong>Telemetry</strong></td><td>Bus de eventos técnicos <strong>sin PII</strong> (severidad, código, mensaje, contexto sanitizado).</td></tr>
				<tr><td><strong>Protección de prefijos legados</strong></td><td>Lista <code>es_</code>/<code>ea_</code>/<code>ecb_</code>/<code>estandar_leads_</code> que la suite nunca escribe (safety gate).</td></tr>
				<tr><td><strong>AdoptionProbe</strong></td><td>Detecta instalación legada (tablas originales presentes): <strong><?php echo $legacy ? 'SÍ' : 'NO'; ?></strong> — habilita la adopción de datos históricos.</td></tr>
			</tbody>
		</table>

		<h3>Salud</h3>
		<?php echo $salud_block; ?>
		<p class="description">Diagnóstico estructurado de la suite (también vía <code>GET /estandar-suite/core/v1/health</code> y <code>wp estandar-suite doctor</code>).</p>

		<h3>Eventos técnicos recientes</h3>
		<table class="widefat striped">
			<thead><tr><th>Fecha</th><th>Nivel</th><th>Código</th><th>Mensaje</th></tr></thead>
			<tbody>
			<?php foreach ( $telemetry as $t ) : ?>
				<tr><td><?php echo esc_html( $t['created_at'] ); ?></td><td><code><?php echo esc_html( $t['severity'] ); ?></code></td><td><code><?php echo esc_html( $t['code'] ); ?></code></td><td><?php echo esc_html( $t['message'] ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( empty( $telemetry ) ) : ?><tr><td colspan="4">Sin eventos técnicos todavía.</td></tr><?php endif; ?>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'esquema', '<strong>¿Para qué sirve?</strong> Muestra las <em>tablas propias</em> que crea el Core en la base de datos: telemetría (eventos técnicos), secretos (compartidos de un solo uso), destinos y entregas. <br><br>Es la <em>carta de navegación</em> de dónde vive cada dato. No se edita nada aquí: sirve para entender el modelo y poder hacer consultas técnicas si hace falta.' ); ?>
		<h3>Schema (tablas propias)</h3>
		<p><code>estandar_core_telemetry</code> · <code>estandar_core_secrets</code> · <code>estandar_core_destinations</code> · <code>estandar_core_deliveries</code></p>
		<?php endif; ?>

	<?php elseif ( $tab === 'config' ) : ?>
		<!-- ══ CONFIGURACIÓN: Dominio + UI ══ -->
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite', 'config', $sub, $subs['config'] ); ?>

		<?php if ( $sub === 'telemetria' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'telemetria', 'Imagina que la suite es una casa donde trabajan varias personas (Capture capta, Brain piensa, Flow vende, Insights analiza). La telemetría es la <strong>caja negra</strong> de esa casa: un cuaderno donde, cada vez que algo importante pasa, alguien lo anota con la fecha y una etiqueta — <em>info</em> = pasó algo normal, <em>warning</em> = ojo, esto podría ser problema, <em>error</em> = algo se rompió. <br><br>Lo importante: el cuaderno <strong>no escribe tus datos</strong> (ni emails, ni teléfonos, ni claves). Solo anota <em>qué le pasó al sistema</em>, no quién ni qué datos. <br><br><strong>Ejemplo real:</strong> anoche un webhook de n8n no respondió. Sin telemetría, hoy solo verías el síntoma: el lead nuevo no llegó a la hoja de Google, y tendrías que adivinar por qué. Con la caja negra, ves la anotación exacta: «error · HTTP 500 · envío a n8n · 02:14». Sabes qué falló, cuándo y dónde, sin investigar. <br><br>Y como un cuaderno puede llenarse, aquí decides dos cosas: <strong>cuánto tiempo se conserva</strong> (lo viejo se borra solo cada día) y <strong>a partir de qué nivel se escribe</strong> (si eliges «warning», lo normal ni se anota y solo queda lo importante). La suite vuela, pero nunca pierdes la caja negra.' ); ?>
		<?php if ( $telemetry_saved ) : ?><div class="notice notice-success is-dismissible"><p>Configuración de telemetría guardada.</p></div><?php endif; ?>
		<?php $tel = \Estandar\Core\Telemetry::settings(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px;">
			<?php wp_nonce_field( 'estandar_suite_save_telemetry_settings' ); ?>
			<input type="hidden" name="action" value="estandar_suite_save_telemetry_settings">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Registrar telemetría</th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $tel['enabled'] ) ); ?>> Activa el registro de eventos técnicos</label><?php echo \Estandar\Core\Optimal::note( $tel['enabled'], 1, 'activado' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="es-tel-ret">Retención (días)</label></th>
					<td><input id="es-tel-ret" type="number" name="retention_days" value="<?php echo (int) $tel['retention_days']; ?>" min="1" max="365" style="width:120px;"> <span class="description">los eventos más antiguos se eliminan automáticamente cada día</span><?php echo \Estandar\Core\Optimal::note( $tel['retention_days'], 30, '30' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="es-tel-min">Nivel mínimo a guardar</label></th>
					<td>
						<select id="es-tel-min" name="min_level">
							<?php foreach ( array( 'debug', 'info', 'warning', 'error' ) as $lvl ) : ?>
								<option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $tel['min_level'], $lvl ); ?>><?php echo esc_html( $lvl ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="description">debug registra todo; error solo fallos</span><?php echo \Estandar\Core\Optimal::note( $tel['min_level'], 'info', 'info' ); ?>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Guardar telemetría', 'primary', 'save', false ); ?>
		</form>

		<?php elseif ( $sub === 'destinos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'destinos', '<strong>¿Para qué sirve?</strong> Cuando la suite detecta un evento (lead nuevo, calificado, venta…), puedes avisar automáticamente a OTROS sistemas: una hoja de Google, un flujo de n8n, un CRM. Aquí registras <em>a dónde</em> enviar y <em>qué eventos</em> avisan a cada destino. El motor encola y entrega con reintentos, sin duplicar. <em>Ejemplo:</em> registras un webhook de n8n que escribe en una hoja "Leads_2026" y marcas el evento "capture" → cada lead nuevo llega solo a la hoja. Usa <strong>Probar</strong> para validar la conexión.' ); ?>

		<?php if ( $dest_saved ) : ?><div class="notice notice-success is-dismissible"><p>Destino registrado.</p></div><?php endif; ?>
		<?php if ( $dest_del ) : ?><div class="notice notice-success is-dismissible"><p>Destino eliminado.</p></div><?php endif; ?>
		<?php if ( $map_saved ) : ?><div class="notice notice-success is-dismissible"><p>Configuración de eventos guardada.</p></div><?php endif; ?>
		<?php if ( $test_id && $test_ok ) : ?><div class="notice notice-success is-dismissible"><p>Prueba OK — el destino respondió HTTP <?php echo (int) $test_http; ?>.</p></div><?php endif; ?>
		<?php if ( $test_id && ! $test_ok ) : ?><div class="notice notice-error is-dismissible"><p>Prueba FALLÓ (HTTP <?php echo (int) $test_http; ?>): <?php echo esc_html( $test_err ); ?></p></div><?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
			<?php wp_nonce_field( 'estandar_suite_add_destination' ); ?>
			<input type="hidden" name="action" value="estandar_suite_add_destination">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="es-slug">Slug (nombre único)</label></th><td><input id="es-slug" name="slug" class="regular-text" required placeholder="n8n-hoja-leads"></td></tr>
				<tr><th scope="row"><label for="es-tipo">Tipo</label></th><td><select id="es-tipo" name="type">
					<option value="webhook">webhook</option><option value="sheets">sheets</option><option value="crm">crm</option>
				</select></td></tr>
				<tr><th scope="row"><label for="es-url">URL destino</label></th><td><input id="es-url" type="url" name="target_url" class="regular-text" required placeholder="https://hook.n8n.app/abc123"></td></tr>
			</table>
			<?php submit_button( 'Registrar destino', 'primary', 'add', false ); ?>
		</form>

		<table class="widefat striped">
			<thead><tr><th>ID</th><th>Slug</th><th>Tipo</th><th>URL</th><th>Eventos que avisan</th><th>Acciones</th></tr></thead>
			<tbody>
			<?php foreach ( $dest as $row ) : $slug = $row['slug']; $mapped = (array) ( $event_map[ $slug ] ?? array() ); ?>
				<tr>
					<td><?php echo (int) $row['id']; ?></td>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
					<td><?php echo esc_html( $row['type'] ); ?></td>
					<td style="word-break:break-all;"><?php echo esc_html( $row['target_url'] ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'estandar_suite_save_event_map' ); ?>
							<input type="hidden" name="action" value="estandar_suite_save_event_map">
							<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>">
							<?php foreach ( $events as $ev ) : ?>
								<label style="white-space:nowrap;display:inline-block;margin-right:8px;">
									<input type="checkbox" name="events[]" value="<?php echo esc_attr( $ev ); ?>" <?php checked( in_array( $ev, $mapped, true ) ); ?>><?php echo esc_html( $ev ); ?>
								</label>
							<?php endforeach; ?>
							<button class="button">Guardar</button>
						</form>
					</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'estandar_suite_test_destination' ); ?>
							<input type="hidden" name="action" value="estandar_suite_test_destination">
							<input type="hidden" name="destination_id" value="<?php echo (int) $row['id']; ?>">
							<button class="button">Probar</button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'estandar_suite_delete_destination' ); ?>
							<input type="hidden" name="action" value="estandar_suite_delete_destination">
							<input type="hidden" name="destination_id" value="<?php echo (int) $row['id']; ?>">
							<button class="button-link-delete">Eliminar</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $dest ) ) : ?><tr><td colspan="6">Sin destinos configurados. Registra el primero arriba.</td></tr><?php endif; ?>
			</tbody>
		</table>

		<?php elseif ( $sub === 'integraciones' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'integraciones', '<strong>¿Para qué sirve?</strong> Conecta Estandar Suite con servicios externos (Google Sheets, Excel Online) para que cualquier formulario pueda escribir sus respuestas en una planilla o libro sin Zapier ni n8n. <strong>Conectar cuenta</strong> inicia el asistente: el proveedor te autoriza, eliges o creas el archivo y la hoja/tabla, se mapean las columnas del formulario y la sincronización queda activa. Los tokens se guardan cifrados y el envío nunca bloquea el formulario.' ); ?>

		<?php if ( ! empty( $_GET['connected'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Conexión registrada. Crea un destino para empezar a escribir.</p></div><?php endif; ?>
		<?php if ( ! empty( $_GET['dest_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Destino creado y activo.</p></div><?php endif; ?>

		<?php $wz_source = sanitize_key( (string) ( $_GET['form_source'] ?? '' ) );
		      $wz_form   = (int) ( $_GET['form_id'] ?? 0 ); ?>
		<?php if ( $wz_source !== '' && $wz_form > 0 ) : ?>
		<div class="notice notice-info"><p>Vincular formulario <code><?php echo esc_html( $wz_source ); ?>:<?php echo (int) $wz_form; ?></code> a un destino de integración. Elige el destino, revisa el mapeo automático y activa.</p></div>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;max-width:900px;margin-bottom:16px;">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="es-bind-dest">Destino</label></th><td>
					<select id="es-bind-dest">
						<option value="">— elige un destino de integración —</option>
						<?php foreach ( $adapter_dests as $ad ) : ?>
							<option value="<?php echo (int) $ad['id']; ?>" data-adapter="<?php echo esc_attr( $ad['type'] ); ?>" data-conn="<?php echo (int) $ad['connection_id']; ?>">#<?php echo (int) $ad['id']; ?> · <?php echo esc_html( $ad['slug'] ); ?> (<?php echo esc_html( $ad['type'] ); ?>)</option>
						<?php endforeach; ?>
					</select>
				</td></tr>
			</table>
			<div id="es-bind-map" style="margin-top:8px;"></div>
			<button class="button button-primary es-bind-activate" disabled>Activar</button>
			<div id="es-bind-result" style="margin-top:8px;"></div>
		</div>
		<?php endif; ?>

		<h3>Cuentas conectadas</h3>
		<table class="widefat striped" style="max-width:900px;">
			<thead><tr><th>ID</th><th>Proveedor</th><th>Etiqueta</th><th>Estado</th><th>Expira</th><th>Acciones</th></tr></thead>
			<tbody>
			<?php if ( empty( $integrations_connections ) ) : ?>
				<tr><td colspan="6">Sin cuentas conectadas. Usa los botones de abajo.</td></tr>
			<?php else : foreach ( $integrations_connections as $c ) : ?>
				<tr>
					<td><?php echo (int) $c['id']; ?></td>
					<td><?php echo esc_html( $c['provider'] ); ?></td>
					<td><?php echo esc_html( $c['label'] ); ?></td>
					<td><?php echo esc_html( $c['status'] ); ?></td>
					<td><?php echo esc_html( (string) ( $c['expires_at'] ?? '-' ) ); ?></td>
					<td>
						<button class="button es-conn-revoke" data-id="<?php echo (int) $c['id']; ?>">Desconectar</button>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>

		<p style="margin-top:10px;">
			<button class="button button-primary es-connect" data-provider="google" data-adapter="google_sheets">Conectar Google Sheets</button>
			<button class="button es-connect" data-provider="microsoft" data-adapter="excel_online">Conectar Excel Online</button>
			<button class="button es-sandbox-connect">Conectar Simulación (sin OAuth)</button>
		</p>

		<h3>Destinos de integración</h3>
		<table class="widefat striped" style="max-width:900px;">
			<thead><tr><th>ID</th><th>Slug</th><th>Adapter</th><th>Conexión</th><th>Acciones</th></tr></thead>
			<tbody>
			<?php $adapter_dests = array_values( array_filter( $dest, static function ( $d ) {
				return \Estandar\Core\Destinations::is_adapter_type( (string) $d['type'] );
			} ) ); ?>
			<?php if ( empty( $adapter_dests ) ) : ?>
				<tr><td colspan="5">Sin destinos de integración. Crea uno abajo (elige cuenta, archivo, hoja/tabla y columnas).</td></tr>
			<?php else : foreach ( $adapter_dests as $ad ) : ?>
				<tr>
					<td><?php echo (int) $ad['id']; ?></td>
					<td><code><?php echo esc_html( $ad['slug'] ); ?></code></td>
					<td><?php echo esc_html( $ad['type'] ); ?></td>
					<td><?php echo (int) $ad['connection_id']; ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'estandar_suite_test_destination' ); ?>
							<input type="hidden" name="action" value="estandar_suite_test_destination">
							<input type="hidden" name="destination_id" value="<?php echo (int) $ad['id']; ?>">
							<button class="button">Probar</button>
						</form>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>

		<h3>Asistente rápido</h3>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;max-width:900px;margin-top:8px;">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="es-int-slug">Slug del destino</label></th><td><input id="es-int-slug" class="regular-text" placeholder="leads-altacruz-sheets"></td></tr>
				<tr><th scope="row"><label for="es-int-adapter">Adapter</label></th><td>
					<select id="es-int-adapter"><?php foreach ( $integration_adapters as $k => $a ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $a['label'] ); ?></option>
					<?php endforeach; ?></select>
				</td></tr>
				<tr><th scope="row"><label for="es-int-conn">Conexión</label></th><td>
					<select id="es-int-conn"><?php foreach ( $integrations_connections as $c ) : ?>
						<option value="<?php echo (int) $c['id']; ?>">#<?php echo (int) $c['id']; ?> · <?php echo esc_html( $c['label'] ); ?> (<?php echo esc_html( $c['provider'] ); ?>)</option>
					<?php endforeach; ?></select>
				</td></tr>
				<tr><th scope="row"><label for="es-int-config">Config (JSON)</label></th><td><textarea id="es-int-config" rows="5" class="large-text code" placeholder='{"columns":["Nombre","Email"]}'></textarea></td></tr>
				<tr><th scope="row"></th><td><button class="button button-primary es-create-dest">Crear destino</button></td></tr>
			</table>
			<div id="es-int-result" style="margin-top:8px;"></div>
		</div>

		<h3>Formularios vinculados</h3>
		<table class="widefat striped" style="max-width:900px;">
			<thead><tr><th>ID</th><th>Fuente</th><th>Form</th><th>Destino</th><th>Estado</th></tr></thead>
			<tbody>
			<?php if ( empty( $integration_bindings ) ) : ?>
				<tr><td colspan="5">Sin formularios vinculados. Desde el plugin de Formularios (Capture) usa «Conectar a…».</td></tr>
			<?php else : foreach ( $integration_bindings as $b ) : ?>
				<tr>
					<td><?php echo (int) $b['id']; ?></td>
					<td><?php echo esc_html( $b['form_source'] ); ?></td>
					<td><?php echo (int) $b['form_id']; ?></td>
					<td><?php echo esc_html( (string) ( $b['dest_slug'] ?? $b['destination_id'] ) ); ?></td>
					<td><?php echo (int) $b['enabled'] === 1 ? 'Activo' : 'Inactivo'; ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>

		<script>
		(function () {
			const nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			const base  = <?php echo wp_json_encode( rest_url( 'estandar-suite/core/v1' ) ); ?>;
			const capBase = <?php echo wp_json_encode( rest_url( 'estandar-suite/capture/v1' ) ); ?>;
			async function api( path, opts ) {
				opts = opts || {};
				opts.headers = Object.assign( { 'X-WP-Nonce': nonce }, opts.headers || {} );
				const r = await fetch( path, opts );
				return r.json();
			}
			document.querySelectorAll( '.es-connect' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', async function () {
					btn.disabled = true;
					const d = await api( base + '/oauth/begin?provider=' + encodeURIComponent( btn.dataset.provider ) + '&adapter=' + encodeURIComponent( btn.dataset.adapter ) );
					if ( d && d.url ) { window.location = d.url; } else { alert( 'No se pudo iniciar la autorización.' ); btn.disabled = false; }
				} );
			} );
			document.querySelectorAll( '.es-conn-revoke' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', async function () {
					const d = await api( base + '/integrations/connections/revoke', { method: 'POST', body: JSON.stringify( { id: Number( btn.dataset.id ) } ), headers: { 'Content-Type': 'application/json' } } );
					if ( d.ok ) { location.reload(); } else { alert( 'No se pudo desconectar.' ); }
				} );
			} );
			const sandboxBtn = document.querySelector( '.es-sandbox-connect' );
			if ( sandboxBtn ) {
				sandboxBtn.addEventListener( 'click', async function () {
					const d = await api( base + '/integrations/sandbox-connect', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' } );
					if ( d.ok ) { location.reload(); } else { alert( 'No se pudo crear la conexión de simulación: ' + ( d.error || 'desconocido' ) ); }
				} );
			}
			document.querySelector( '.es-create-dest' ).addEventListener( 'click', async function () {
				const cfg = document.getElementById( 'es-int-config' ).value;
				let config = {};
				try { config = cfg.trim() ? JSON.parse( cfg ) : {}; } catch ( e ) { alert( 'Config JSON inválido' ); return; }
				const d = await api( base + '/integrations/destinations', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { slug: document.getElementById( 'es-int-slug' ).value, adapter: document.getElementById( 'es-int-adapter' ).value, connection_id: Number( document.getElementById( 'es-int-conn' ).value ), config: config } )
				} );
				document.getElementById( 'es-int-result' ).textContent = d.ok ? 'Destino #' + d.id + ' creado.' : 'Error: ' + ( d.error || 'desconocido' );
				if ( d.ok ) { setTimeout( function () { location.reload(); }, 600 ); }
			} );

			const bindSelect = document.getElementById( 'es-bind-dest' );
			if ( bindSelect ) {
				const fields = await api( capBase + '/forms/fields?source=' + encodeURIComponent( <?php echo wp_json_encode( $wz_source ); ?> ) + '&form_id=' + Number( <?php echo (int) $wz_form; ?> ) );
				const formFields = ( fields && fields.fields ) ? fields.fields : {};
				bindSelect.addEventListener( 'change', async function () {
					const opt = bindSelect.options[ bindSelect.selectedIndex ];
					const box = document.getElementById( 'es-bind-map' );
					const btn = document.querySelector( '.es-bind-activate' );
					box.innerHTML = 'Cargando columnas…'; btn.disabled = true;
					if ( ! opt || ! opt.value ) { box.innerHTML = ''; return; }
					const dest = await api( base + '/integrations/destination?id=' + Number( opt.value ) );
					if ( ! dest || ! dest.ok && ! dest.id ) { box.innerHTML = 'No se pudo leer el destino.'; return; }
					const cols = await api( base + '/integrations/columns?adapter=' + encodeURIComponent( dest.type ) + '&connection_id=' + Number( dest.connection_id ) + '&config=' + encodeURIComponent( JSON.stringify( dest.config ) ) );
					const headers = ( cols && cols.columns && cols.columns.length ) ? cols.columns : ( dest.columns || [] );
					const am = await api( base + '/integrations/auto-map', {
						method: 'POST', headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { fields: formFields, headers: headers } )
					} );
					let html = '<p class="description">Mapeo automático de campos del formulario → columnas de la hoja/tabla:</p>'
						+ '<table class="widefat striped"><thead><tr><th>Campo del formulario</th><th>Columna destino</th></tr></thead><tbody>';
					Object.keys( am.mapping ).forEach( function ( f ) {
						html += '<tr><td>' + f + '</td><td>' + ( am.mapping[f] || '—' ) + '</td></tr>';
					} );
					html += '</tbody></table>';
					box.innerHTML = html;
					btn.disabled = false;
					btn.dataset.mapping = JSON.stringify( am.mapping );
				} );
				document.querySelector( '.es-bind-activate' ).addEventListener( 'click', async function () {
					const btn = this;
					const d = await api( base + '/integrations/bind', {
						method: 'POST', headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( {
							form_source: <?php echo wp_json_encode( $wz_source ); ?>,
							form_id: Number( <?php echo (int) $wz_form; ?> ),
							destination_id: Number( bindSelect.value ),
							mapping: JSON.parse( btn.dataset.mapping || '{}' ),
							enabled: 1
						} )
					} );
					document.getElementById( 'es-bind-result' ).textContent = d.ok ? 'Formulario vinculado (# ' + d.id + '). La sincronización está activa.' : 'Error: ' + ( d.error || 'desconocido' );
					if ( d.ok ) { setTimeout( function () { location.reload(); }, 900 ); }
				} );
			}
		})();
		</script>

		<?php elseif ( $sub === 'secretos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'secretos', '<strong>¿Para qué sirve?</strong> Compartir un dato sensible (contraseña, API key, credenciales) de forma segura: el dato se guarda cifrado y, al abrirlo con el enlace, se muestra <strong>una sola vez</strong> y se elimina para siempre. <em>Ejemplo:</em> le mandas a tu contador el enlace con la clave del portal del banco; al abrirlo la ve y nadie más podrá verla jamás, ni siquiera el sistema. El dato expira por tiempo si no se abre. <strong>Guarda el enlace cuando lo crees</strong> — solo se muestra una vez.' ); ?>

		<?php if ( $secret_ok && is_array( $last_secret ) && ! empty( $last_secret['token'] ) ) : ?>
			<div class="notice notice-info is-dismissible">
				<p><strong>Secreto creado (se muestra una sola vez).</strong> Envía este enlace al destinatario:</p>
				<p style="margin:8px 0;">
					<input type="text" id="es-secret-link" readonly value="<?php echo esc_attr( $last_secret['link'] ); ?>" style="width:100%;max-width:640px;" onclick="this.select();">
				</p>
				<p><button type="button" class="button button-secondary" id="es-copy-secret-link">Copiar enlace</button>
				<span class="description">Expira: <?php echo esc_html( $last_secret['expires_at'] ); ?> · Token: <code><?php echo esc_html( $last_secret['token'] ); ?></code></span></p>
			</div>
			<script>(function(){var b=document.getElementById("es-copy-secret-link");var i=document.getElementById("es-secret-link");if(!b||!i)return;b.addEventListener("click",function(){i.select();var ok=false;try{ok=document.execCommand("copy")}catch(e){}if(!ok&&navigator.clipboard){navigator.clipboard.writeText(i.value)}})})();</script>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
			<?php wp_nonce_field( 'estandar_suite_create_secret' ); ?>
			<input type="hidden" name="action" value="estandar_suite_create_secret">
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="es-payload">Contenido (dato a compartir)</label></th><td><textarea id="es-payload" name="payload" class="large-text" rows="3" required placeholder="Clave portal: AbC123..."></textarea></td></tr>
				<tr><th scope="row"><label for="es-purpose">Propósito</label></th><td><input id="es-purpose" name="purpose" class="regular-text" value="share" placeholder="ej. banco-portal"></td></tr>
				<tr><th scope="row"><label for="es-ttl">Válido por (horas)</label></th><td><input id="es-ttl" type="number" name="ttl" value="24" min="1" max="720" style="width:120px;"> <span class="description">el enlace expira si no se abre en este plazo</span></td></tr>
			</table>
			<?php submit_button( 'Crear secreto' ); ?>
		</form>

		<table class="widefat striped">
			<thead><tr><th>ID</th><th>Propósito</th><th>Expira</th><th>Usado</th><th>Creado</th></tr></thead>
			<tbody>
			<?php foreach ( $secrets as $row ) : ?>
				<tr>
					<td><?php echo (int) $row['id']; ?></td>
					<td><?php echo esc_html( $row['purpose'] ); ?></td>
					<td><?php echo esc_html( $row['expires_at'] ); ?></td>
					<td><?php echo ! empty( $row['used_at'] ) ? esc_html( $row['used_at'] ) : '—'; ?></td>
					<td><?php echo esc_html( $row['created_at'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $secrets ) ) : ?><tr><td colspan="5">Sin secretos.</td></tr><?php endif; ?>
			</tbody>
		</table>

		<?php \Estandar\Core\Admin::render_settings_panel( \Estandar\Core\Admin::get_settings() ); ?>

		<?php elseif ( $sub === 'secure' ) : ?>
		<?php $feature = 'secure'; $feature_fields = $secure_fields; $feature_option = 'estandar_suite_core_secure_settings'; $feature_saved = isset( $_GET['feature_saved'] ); $feature_data = $secure_deliveries; include ESTANDAR_SUITE_CORE_PATH . 'includes/templates/feature-secure.php'; ?>

		<?php elseif ( $sub === 'emoji' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'emoji', '<strong>¿Para qué sirve?</strong> Personaliza el <em>icono</em> (emoji) de la suite: se usa en el menú lateral de WordPress y en la barra superior. <br><br>Piensa en el <em>logotipo</em> de la marca: un detalle pequeño que hace reconocible a la suite entre decenas de plugins. <em>Ejemplo:</em> cambias el 🐲 por 🚀 y toda la suite (menú + barra superior) lo refleja al instante, desde una sola configuración.' ); ?>
		<?php if ( isset( $_GET['icon_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Emoji guardado.</p></div><?php endif; ?>
		<?php $current_icon = (string) get_option( 'estandar_suite_core_menu_icon', '🐲' ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
			<?php wp_nonce_field( 'estandar_suite_save_menu_icon' ); ?>
			<input type="hidden" name="action" value="estandar_suite_save_menu_icon">
			<div style="margin-top:6px;">
				<div style="position:relative;display:inline-block;">
					<input type="text" id="es-custom-emoji" name="menu_icon_custom" value="<?php echo esc_attr( $current_icon ); ?>" placeholder="Buscar o elegir…" style="width:220px;padding-right:34px;" readonly>
					<button type="button" id="es-emoji-picker-toggle" class="button" style="position:absolute;right:4px;top:1px;border:none;background:transparent;cursor:pointer;font-size:18px;line-height:1;" aria-label="Abrir selector de emojis" title="Abrir selector de emojis">😀</button>
				</div>
				<span id="es-emoji-preview" style="font-size:26px;margin-left:10px;vertical-align:middle;"><?php echo esc_html( $current_icon ); ?></span>
			</div>
			<div id="es-emoji-picker" style="display:none;position:absolute;z-index:99999;margin-top:8px;background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,0.18);max-width:340px;width:340px;">
				<div style="padding:8px 10px;border-bottom:1px solid #f0f0f1;">
					<input type="text" id="es-emoji-search" placeholder="Buscar emoji…" style="width:100%;box-sizing:border-box;">
				</div>
				<div style="padding:10px;border-bottom:1px solid #f0f0f1;display:flex;gap:4px;flex-wrap:wrap;" id="es-emoji-tabs"></div>
				<div id="es-emoji-grid" style="max-height:260px;overflow-y:auto;padding:8px;display:grid;grid-template-columns:repeat(8,1fr);gap:2px;"></div>
			</div>
			<?php submit_button( 'Guardar emoji', 'primary', 'save_icon', false ); ?>
		</form>
		<script>
		(function () {
			var EMOJI = <?php echo wp_json_encode( \Estandar\Core\Admin::emoji_catalog(), JSON_UNESCAPED_UNICODE ); ?>;
			var grid = document.getElementById('es-emoji-grid');
			var tabs = document.getElementById('es-emoji-tabs');
			var picker = document.getElementById('es-emoji-picker');
			var search = document.getElementById('es-emoji-search');
			var input = document.getElementById('es-custom-emoji');
			var preview = document.getElementById('es-emoji-preview');
			var toggle = document.getElementById('es-emoji-picker-toggle');
			var current = <?php echo wp_json_encode( $current_icon ); ?>;

			function close() { picker.style.display = 'none'; }
			function renderTab(name) {
				var b = document.createElement('button');
				b.type = 'button';
				b.textContent = name;
				b.style.cssText = 'border:1px solid #dcdcde;background:#fff;border-radius:14px;padding:2px 10px;font-size:13px;cursor:pointer;';
				b.addEventListener('click', function () { renderGrid(name); });
				return b;
			}
			function renderGrid(cat) {
				grid.innerHTML = '';
				var list = cat ? EMOJI[cat] : Object.values(EMOJI).flat();
				if (search.value.trim()) {
					var q = search.value.trim().toLowerCase();
					list = Object.values(EMOJI).flat().filter(function (e) { return e.label.toLowerCase().indexOf(q) !== -1; });
				}
				list.forEach(function (e) {
					var b = document.createElement('button');
					b.type = 'button';
					b.textContent = e.emoji;
					b.title = e.label;
					b.style.cssText = 'font-size:22px;line-height:1;padding:4px;cursor:pointer;background:none;border:none;border-radius:6px;';
					b.addEventListener('click', function () {
						input.value = e.emoji;
						preview.textContent = e.emoji;
						close();
					});
					grid.appendChild(b);
				});
				if (!list.length) { grid.innerHTML = '<span class="description" style="grid-column:1/-1;padding:8px;">Sin resultados.</span>'; }
			}
			toggle.addEventListener('click', function (ev) {
				ev.stopPropagation();
				var hidden = picker.style.display === 'none';
				picker.style.display = hidden ? 'block' : 'none';
				if (hidden) { renderGrid(''); search.value = ''; }
			});
			search.addEventListener('input', function () { renderGrid(''); });
			document.addEventListener('click', function (ev) {
				if (!picker.contains(ev.target) && ev.target !== toggle) { close(); }
			});
			document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { close(); } });
			// Tabs
			Object.keys(EMOJI).forEach(function (k) { tabs.appendChild(renderTab(k)); });
			preview.textContent = current;
		})();
		</script>

		<?php elseif ( $sub === 'visibilidad' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'visibilidad', '<strong>¿Para qué sirve?</strong> Decide qué pestañas y sub-pestañas ves en el menú del Core. Es <strong>solo visual</strong>: las funciones y los datos siguen funcionando aunque ocultes una pestaña. <br><br>Es como <em>ordenar el escritorio</em>: si no usas «Emoji», lo ocultas para tener una interfaz más limpia. La pestaña de Visibilidad siempre queda accesible, para que nunca te quedes sin acceso.' ); ?>
		<?php if ( isset( $_GET['visibility_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Visibilidad guardada.</p></div><?php endif; ?>
		<?php
		$visibility_subs = array(
			'inicio'   => array( 'bienvenida' => 'Bienvenida' ),
			'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'config'   => array( 'telemetria' => 'Telemetría', 'destinos' => 'Destinos', 'secretos' => 'Secretos', 'secure' => 'Secure Share', 'emoji' => 'Emoji', 'visibilidad' => 'Visibilidad' ),
			'datos'    => array( 'adopcion' => 'Adopción', 'heredados' => 'Settings heredados' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);
		\Estandar\Core\Admin::render_visibility_manager( 'core', 'estandar-suite', $visibility_subs );
		?>
		<?php endif; ?>

	<?php elseif ( $tab === 'datos' ) : ?>
		<!-- ══ DATOS: Adopción ══ -->
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite', 'datos', $sub, $subs['datos'] ); ?>

		<?php if ( $sub === 'adopcion' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'adopcion', '<strong>¿Para qué sirve?</strong> Detecta y protege la <em>instalación anterior</em> (legada): si el sitio ya tenía tablas <code>es_*</code>/<code>ea_*</code> de los plugins v1, el Core lo detecta para que los productos nuevos puedan adoptar esos datos históricos. <br><br>Es como <em>comprar un local ya equipado</em>: no tiras lo que hay, lo pones a trabajar. El Core nunca escribe en las tablas legadas; solo las lee para abrir la puerta a la adopción.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Instalación legada</strong></td><td><?php echo $legacy ? 'SÍ — tablas originales (<code>es_*</code>/<code>ea_*</code>) presentes. La adopción de datos históricos está disponible.' : 'NO — instalación limpia, sin tablas legadas.'; ?></td></tr>
				<tr><td><strong>Prefijos protegidos</strong></td><td><code>es_</code> · <code>ea_</code> · <code>ecb_</code> · <code>estandar_leads_</code> — la suite nunca escribe en ellos.</td></tr>
				<tr><td><strong>Adopción por producto</strong></td><td>Capture (leads) · Brain (señales) · Flow (pipeline) · Insights (analytics). Cada uno desde su tab <strong>Datos</strong>.</td></tr>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'heredados', '<strong>¿Para qué sirve?</strong> Muestra en <em>solo lectura</em> las opciones (settings) que dejaron los plugins anteriores (v1). <br><br>Es la <em>vitrina del museo</em>: puedes ver qué configuró el plugin viejo, pero no lo modificas desde aquí. Así, si una integración legada depende de un valor, lo encuentras documentado sin romper nada.' ); ?>
		<?php \Estandar\Core\Admin::render_settings_panel( \Estandar\Core\Admin::get_legacy_settings() ); ?>
		<?php endif; ?>

	<?php else : ?>
		<!-- ══ AVANZADO: REST + CLI + Inventario completo ══ -->
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite', 'avanzado', $sub, $subs['avanzado'] ); ?>

		<?php if ( $sub === 'rest' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'rest', '<strong>¿Para qué sirve?</strong> Lista los <em>endpoints REST</em> que expone el Core para que otros sistemas (n8n, scripts, integraciones) hablen con la suite vía HTTP. Todos requieren autenticación y permisos de administrador. <br><br>Es la <em>puerta de servicio</em> de la suite: mientras la UI es para personas, estos endpoints son para máquinas. <em>Ejemplo:</em> un script externo consulta <code>/health</code> para saber si la suite está sana sin abrir WordPress.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>GET /estandar-suite/core/v1/health</code></td><td>Diagnóstico estructurado (JSON)</td></tr>
				<tr><td><code>GET /estandar-suite/core/v1/telemetry</code></td><td>Eventos técnicos recientes (sin PII)</td></tr>
				<tr><td><code>POST /estandar-suite/core/v1/secrets</code></td><td>Crear secreto (payload, purpose) → token</td></tr>
			</tbody>
		</table>
		<?php elseif ( $sub === 'cli' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'cli', '<strong>¿Para qué sirve?</strong> Muestra los <em>comandos de WP-CLI</em> del Core para operar la suite desde la terminal: diagnosticar, ver telemetría, purgar secretos, drenar destinos o detectar la instalación legada. <br><br>Es el <em>panel de control por voz</em>: lo que haces con clicks en la UI, lo haces con una línea de texto. Útil para tareas automatizadas o cuando no hay navegador.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>wp estandar-suite doctor</code></td><td>Diagnóstico de la suite</td></tr>
				<tr><td><code>wp estandar-suite telemetry</code></td><td>Ver eventos técnicos</td></tr>
				<tr><td><code>wp estandar-suite secrets purge --confirm=yes</code></td><td>Eliminar secretos usados/expirados</td></tr>
				<tr><td><code>wp estandar-suite destinations drain</code></td><td>Enviar entregas pendientes</td></tr>
				<tr><td><code>wp estandar-suite adoption probe</code></td><td>Detectar instalación legada</td></tr>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'inventario', '<strong>¿Para qué sirve?</strong> El <em>catálogo completo</em> del Core: todas las funciones del plugin agrupadas por conjuntos funcionales, para que ninguna quede fuera de la interfaz. <br><br>Es el <em>inventario del almacén</em>: confirma que todo lo que el código puede hacer tiene su lugar visible. Sirve para auditar cobertura y detectar funciones escondidas.' ); ?>
		<?php \Estandar\Core\Admin::render_inventory( $sets, $fn_settings ); ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php echo \Estandar\Core\Admin::help_script(); ?>
