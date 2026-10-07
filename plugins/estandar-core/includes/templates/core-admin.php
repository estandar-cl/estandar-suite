<?php
/**
 * Estandar Suite — Core admin page. 5 tabs (Inicio · Estado · Configuración ·
 * Datos · Avanzado) que agrupan los 7 conjuntos internos de funciones. El tab
 * Inicio agrupa la intro (Bienvenida); la marca/emoji vive en Configuración. El
 * tab Avanzado muestra el inventario completo del plugin (cobertura 100%, sin
 * funciones fuera).
 * Variables: $health, $legacy, $tab, $tabs,
 * $sets, $fn_settings, $telemetry.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( empty( $fn_settings ) ) { $fn_settings = array(); }
$salud_block = '<h2 style="display:none;">Salud</h2>'
	. '<button type="button" class="button button-secondary" id="estandar-suite-copy-health" title="Copiar diagnóstico de salud al portapapeles">Salud</button>'
	. '<textarea id="estandar-suite-health-text" readonly aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;">' . esc_textarea( "Salud\n" . \Estandar\Core\Health::render_text( $health ) . "\nInstalación legada detectada: " . ( $legacy ? 'SÍ (tablas originales presentes)' : 'NO (instalación limpia)' ) . "\nNota: infraestructura técnica de la suite — REST autenticado y WP-CLI disponibles; esta UI es complementaria." ) . '</textarea>'
	. '<script>(function(){var b=document.getElementById("estandar-suite-copy-health");var t=document.getElementById("estandar-suite-health-text");if(!b||!t)return;b.addEventListener("click",function(){t.focus();t.select();t.setSelectionRange(0,99999);var ok=false;try{ok=document.execCommand("copy")}catch(e){}if(!ok&&navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t.value).catch(function(){})}})})();</script>';
?>
<div class="wrap">

	<?php \Estandar\Core\Admin::render_module_tabs( 'estandar-suite', $tab, $sub, array_merge( array( 'configuracion' => 'Configuración', 'proyectos' => 'Proyectos', 'operadores' => 'Operadores', 'leads' => 'Leads' ), \Estandar\Core\Admin::reviewed_core_modules() ) ); ?>


	<?php if ( \Estandar\Core\Admin::native_blank() ) : ?>
		<!-- ══ Backend Ordenado: modo "solo estructura" (sin contenido) ══ -->
		<div class="notice notice-info inline" style="margin:12px 0;max-width:760px;"><p>Backend Ordenado: esta pantalla está en modo <strong>solo estructura</strong>. Los módulos se están reordenando; nada se eliminó y todo sigue funcionando (el acceso directo por URL y las acciones siguen operativas).</p></div>
	<?php elseif ( $tab === 'inicio' ) : ?>
		<!-- ══ INICIO: Bienvenida · Emoji ══ -->
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite', 'inicio', $sub, $subs['inicio'] ); ?>

		<?php if ( $sub === 'bienvenida' ) : ?>
		<div class="notice notice-info inline" style="margin:12px 0;max-width:760px;">
			<p><strong>Operación por terminal (WP-CLI):</strong> toda la suite se puede operar y configurar desde la terminal con <code>wp estandar-suite &lt;producto&gt; &lt;feature&gt; …</code>. Explora todos los comandos con <code>wp help estandar-suite</code>. Esto también aplica a agentes de IA (Claude, Codex, Dragon u otros): pueden configurar la suite vía CLI.</p>
		</div>

		<?php endif; ?>

	<?php elseif ( $tab === 'estado' ) : ?>
		<!-- ══ ESTADO: Resumen · Datos · Esquema ══ -->
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite', 'estado', $sub, $subs['estado'] ); ?>

		<?php if ( $sub === 'resumen' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'resumen', '<strong>¿Para qué sirve?</strong> Es la tarjeta de presentación del Core: de un vistazo sabes qué es la suite, si hay una instalación anterior (legada) y cuántos eventos técnicos existen. <br><br>Piensa en el <em>tablero de mandos</em> de un avión: no pilotas desde aquí, pero confirmas que todo está en su sitio y desde aquí saltas al lugar exacto (ver eventos en Datos, ver adopción…).' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Rol</strong></td><td>Infraestructura técnica de la suite: contratos, telemetría, CLI y REST. Prerrequisito de los otros 4 productos.</td></tr>
				<tr><td><strong>Instalación legada</strong></td><td><?php echo $legacy ? 'SÍ — tablas originales presentes. La adopción de datos históricos está disponible.' : 'NO — instalación limpia.'; ?></td></tr>
				<tr><td><strong>Eventos técnicos</strong></td><td><?php echo count( $telemetry ); ?> recientes en telemetría</td></tr>
			</tbody>
		</table>
		<p style="margin-top:10px;">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite&tab=datos&sub=adopcion' ) ); ?>">Ver adopción</a>
			<span class="description" style="margin-left:8px;">Telemetría configurada con valores óptimos · se opera por CLI/REST · sus eventos se ven en <strong>Datos</strong>.</span>
		</p>

		<?php elseif ( $sub === 'autodiagnostico' ) : ?>
		<?php
		$auto_overall = isset( $auto['overall'] ) ? $auto['overall'] : array( 'level' => 'unknown', 'state' => 'UNKNOWN' );
		$auto_color   = array( 'ok' => '#00a32a', 'warn' => '#dba617', 'bad' => '#d63638', 'unknown' => '#787c82' );
		$auto_lvl     = isset( $auto_color[ $auto_overall['level'] ] ) ? $auto_overall['level'] : 'unknown';
		$auto_checks  = isset( $auto['checks'] ) ? $auto['checks'] : array();
		?>
		<?php echo \Estandar\Core\Admin::help_html( 'autodiagnostico', '<strong>¿Para qué sirve?</strong> Es el <em>estado honesto</em> de esta instalación: cada módulo que la Suite espera (tracker de visitas, captura de leads, esquemas, integraciones, refresco diario) se comprueba solo y se muestra tal cual está. <br><br>Regla de oro: <strong>no se inventa éxito</strong>. Si no hay evidencia (tabla vacía, producto inactivo, sin datos aún), el estado es ATENCION/UNKNOWN, no OK. Una falla silenciosa de semanas (p.ej. el tracker apagado y el Mapa en 0) ahora se ve aquí al instante. <em>Ejemplo:</em> desactivas el tracker en Mapa del sitio y este panel pasa de OK a ATENCION con el motivo exacto.' ); ?>
		<?php if ( isset( $_GET['selfcheck_done'] ) ) : ?>
		<div class="notice notice-success inline" style="margin:12px 0;max-width:860px;"><p>Diagnóstico regenerado: se ejecutó el chequeo completo y se actualizó el snapshot.</p></div>
		<?php endif; ?>
		<div style="max-width:860px;">
			<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin:14px 0 6px;">
				<span style="font-size:22px;font-weight:700;color:#fff;background:<?php echo esc_attr( $auto_color[ $auto_lvl ] ); ?>;padding:8px 18px;border-radius:6px;"><?php echo esc_html( $auto_overall['state'] ); ?></span>
				<div>
					<div><strong>Autodiagnóstico</strong> · chequeado <code><?php echo esc_html( $auto['checked_at'] ?? '' ); ?></code> · ventana <?php echo (int) ( $auto['window_days'] ?? 7 ); ?> días</div>
					<div style="color:#646970;">Legible por REST <code>GET /estandar-suite/core/v1/selfcheck</code> y CLI <code>wp estandar-suite core selfcheck</code> (el radar/IA puede leer este estado).</div>
				</div>
				<a class="button button-primary" style="margin-left:auto;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=estandar-suite&tab=estado&sub=autodiagnostico&selfcheck=1' ), 'estandar_suite_core_selfcheck' ) ); ?>">Ejecutar ahora</a>
			</div>
			<?php if ( empty( $auto_checks ) ) : ?>
				<p class="description">Sin checks todavía. Ejecuta el diagnóstico con «Ejecutar ahora».</p>
			<?php else : ?>
			<table class="widefat striped" style="margin-top:10px;">
				<thead><tr><th style="width:40px;"></th><th style="width:230px;">Comprobación</th><th style="width:200px;">Estado</th><th>Detalle</th></tr></thead>
				<tbody>
				<?php foreach ( $auto_checks as $c ) : ?>
					<?php $lvl = isset( $auto_color[ $c['level'] ] ) ? $c['level'] : 'unknown'; ?>
					<tr>
						<td><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo esc_attr( $auto_color[ $lvl ] ); ?>;" title="<?php echo esc_attr( strtoupper( (string) $c['level'] ) ); ?>"></span></td>
						<td><strong><?php echo esc_html( $c['label'] ); ?></strong></td>
						<td><code><?php echo esc_html( $c['status'] ); ?></code></td>
						<td><?php echo esc_html( $c['desc'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

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
		<?php elseif ( $sub === 'mapa' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'mapa', '<strong>¿Para qué sirve?</strong> Es el <em>diagrama de flujo</em> del sitio completo: desde la portada, sus despliegues (páginas hijas, menú, landings) y cómo se enlazan entre sí. Cada tarjeta muestra <strong>estadísticas de visitas</strong> (rango, total, última vista), autor, fecha de publicación y una <strong>captura de pantalla</strong> de la página. <br><br>Sirve para <strong>identificar de un vistazo qué está realmente activo</strong> (publicada + con tráfico) y qué no (publicada sin tráfico, borradores, papelera). Cada página es clickeable (ir a verla en producción), editable y puede <strong>enviarse a la papelera</strong> para desactivarla de las publicadas.' ); ?>
		<?php \Estandar\Core\SiteMap::render_admin(); ?>

		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'esquema', '<strong>¿Para qué sirve?</strong> Muestra las <em>tablas propias</em> que crea el Core en la base de datos: telemetría (eventos técnicos), secretos (compartidos de un solo uso), destinos y entregas. <br><br>Es la <em>carta de navegación</em> de dónde vive cada dato. No se edita nada aquí: sirve para entender el modelo y poder hacer consultas técnicas si hace falta.' ); ?>
		<h3>Schema (tablas propias)</h3>
		<p><code>estandar_core_telemetry</code></p>
		<?php endif; ?>

	<?php elseif ( $tab === 'config' ) : ?>
		<!-- ══ CONFIGURACIÓN: Dominio + UI ══ -->

		<?php
		// Configuración del Core. Nivel superior: Correo · Claves IA ·
		// Ajustes avanzados (no existe ya el envoltorio "Ajustes básicos").
		// Correo y Claves IA viven en este nivel. "Ajustes avanzados",
		// "Telemetría" y "Emoji" quedan OCULTOS del nav (soporte/CLI): solo
		// alcanzables por URL directa, para no exponer plomería al usuario.
		$sub_eff   = $sub;
		$cfg_view  = '';
		$md_active = '';
		$cfg_tabs = array(
			'correo' => 'Correo',
			'claves' => 'Claves IA',
			'acceso' => 'Acceso',
		);
		// Sub-pestañas ocultas del nav pero alcanzables por URL (cfg=…).
		$cfg_hidden = array( 'avanzado', 'emoji' );
		$md_avanzado = array(
			'mapa'          => 'Mapa del sitio',
		);
		$md_sub = array( 'correo', 'claves' );
		if ( $sub === 'configuracion' ) {
			$cfg_view = sanitize_key( (string) ( $_GET['cfg'] ?? 'correo' ) );
			// 'emoji' está OCULTO del nav pero sigue alcanzable por URL directa.
			if ( ! isset( $cfg_tabs[ $cfg_view ] ) && ! in_array( $cfg_view, $cfg_hidden, true ) ) {
				$cfg_view = 'correo';
			}
			if ( $cfg_view === 'avanzado' ) {
				$md_active = sanitize_key( (string) ( $_GET['md'] ?? 'mapa' ) );
				if ( ! isset( $md_avanzado[ $md_active ] ) ) {
					$md_active = 'mapa';
				}
				if ( in_array( $md_active, $md_sub, true ) ) {
					$sub_eff = $md_active;
				}
			} elseif ( in_array( $cfg_view, $md_sub, true ) ) {
				// Correo y Claves IA viven en este nivel (sin envolver en básicos).
				$sub_eff = $cfg_view;
			}
			$cfg_base = admin_url( 'admin.php?page=estandar-suite&tab=config&sub=configuracion' );
			$cfg_items = array();
			foreach ( $cfg_tabs as $cfk => $cfl ) {
				$cfg_items[ $cfk ] = array( 'label' => $cfl, 'url' => add_query_arg( 'cfg', $cfk, $cfg_base ) );
			}
			echo \Estandar\Core\Admin::subtab_nav_html( $cfg_items, $cfg_view );
			if ( $cfg_view === 'avanzado' ) {
				$md_items = array();
				foreach ( $md_avanzado as $mdk => $mdl ) {
					$md_items[ $mdk ] = array( 'label' => $mdl, 'url' => add_query_arg( array( 'cfg' => 'avanzado', 'md' => $mdk ), $cfg_base ) );
				}
				echo \Estandar\Core\Admin::subtab_nav_html( $md_items, $md_active );
			}
		}
		?>

		<?php if ( $sub_eff === 'configuracion' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'configuracion', '<strong>Ajustes del Core.</strong> Correo y Claves IA viven en este nivel; el <em>Mapa del sitio</em> en <em>Ajustes avanzados</em>. Proyectos y Operadores siguen como pestañas propias de la fila.' ); ?>

		<?php if ( $cfg_view === 'acceso' ) : ?>
		<?php \Estandar\Core\Access::render_page(); ?>

		<?php elseif ( $cfg_view === 'emoji' ) : ?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:820px;margin:12px 0;">
			<h3 style="margin:0 0 4px;">Marca de la suite (emoji)</h3>
			<p class="description" style="margin:0 0 12px;">Emoji que identifica a Estandar Suite en el menú lateral y en la barra superior. La <em>identidad de la marca en los correos</em> (logo y color) vive en Capture → Panel de Leads → Email.</p>
			<?php include ESTANDAR_SUITE_CORE_PATH . 'includes/templates/feature-emoji.php'; ?>
		</div>

		<?php elseif ( $cfg_view === 'avanzado' && $md_active === 'mapa' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'configuracion_av', '<strong>Ajustes avanzados del Core.</strong> El Mapa del sitio se administra aquí. La Telemetría permanece oculta hasta revisarse (acceso directo por URL: <code>sub=telemetria</code>).' ); ?>
		<div style="margin-top:10px;">
			<?php \Estandar\Core\SiteMap::render_admin(); ?>
		</div>
		<?php endif; ?>

		<?php elseif ( $sub_eff === 'emoji' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'emoji', '<strong>¿Para qué sirve?</strong> Personaliza el <em>icono</em> (emoji) de la suite: se usa en el menú lateral de WordPress y en la barra superior. <br><br>Piensa en el <em>logotipo</em> de la marca: un detalle pequeño que hace reconocible a la suite entre decenas de plugins. <em>Ejemplo:</em> cambias el 🐲 por 🚀 y toda la suite (menú + barra superior) lo refleja al instante, desde una sola configuración.' ); ?>
		<?php include ESTANDAR_SUITE_CORE_PATH . 'includes/templates/feature-emoji.php'; ?>

		<?php elseif ( $sub_eff === 'telemetria' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'telemetria', 'Imagina que la suite es una casa donde trabajan varias personas (Capture capta, Brain piensa, Flow vende, Insights analiza). La telemetría es la <strong>caja negra</strong> de esa casa: un cuaderno donde, cada vez que algo importante pasa, alguien lo anota con la fecha y una etiqueta — <em>info</em> = pasó algo normal, <em>warning</em> = ojo, esto podría ser problema, <em>error</em> = algo se rompió. <br><br>Lo importante: el cuaderno <strong>no escribe tus datos</strong> (ni emails, ni teléfonos, ni claves). Solo anota <em>qué le pasó al sistema</em>, no quién ni qué datos. <br><br><strong>Ejemplo real:</strong> anoche un webhook de n8n no respondió. Sin telemetría, hoy solo verías el síntoma: el lead nuevo no llegó a la hoja de Google, y tendrías que adivinar por qué. Con la caja negra, ves la anotación exacta: «error · HTTP 500 · envío a n8n · 02:14». Sabes qué falló, cuándo y dónde, sin investigar. <br><br>Y como un cuaderno puede llenarse, aquí decides dos cosas: <strong>cuánto tiempo se conserva</strong> (lo viejo se borra solo cada día) y <strong>a partir de qué nivel se escribe</strong> (si eliges «warning», lo normal ni se anota y solo queda lo importante). La suite vuela, pero nunca pierdes la caja negra.' ); ?>
		<?php include ESTANDAR_SUITE_CORE_PATH . 'includes/templates/feature-telemetria.php'; ?>

 		<?php elseif ( $sub_eff === 'leads' ) : ?>
 		<?php
 		$ssubs_leads = \Estandar\Core\Admin::filter_ssubs( 'core', 'config', 'leads', array(
 			'vista' => 'Vista Leads', 'metricas' => 'Métricas de correo', 'panel' => 'Panel de Leads', 'operadores' => 'Operadores',
 		) );
		// Operadores siempre visible (gestión de es_operators).
		$ssubs_leads['operadores'] = 'Operadores';
 		$ss = \Estandar\Core\Admin::resolve_ssub( $ssubs_leads, 'panel' );
 		echo \Estandar\Core\Admin::render_ssubtabs( 'estandar-suite', 'config', 'leads', $ss, $ssubs_leads );
 		?>
 		<?php if ( $ss === 'vista' ) : ?>
 		<?php
 		// Vista admin de los leads (lista + ficha de pipeline), antes en el
 		// sidebar como item "Leads". Ahora centralizada en Core → Config → Leads.
 		echo \Estandar\Core\Leads\AdminPage::render_embedded();
 		?>
 		<?php elseif ( $ss === 'metricas' ) : ?>
 		<?php echo \Estandar\Core\Leads\MailTracking::render_report(); ?>
 		<?php elseif ( $ss === 'panel' ) : ?>
 		<h3>Panel de Leads (vista + export + API)</h3>
		<?php if ( $token_rot !== '' ) : ?><div class="notice notice-success is-dismissible"><p><?php echo $token_rot === 'key' ? 'API key regenerada. Actualiza tus integraciones.' : 'Enlace del viewer regenerado. Comparte el nuevo.'; ?></p></div><?php endif; ?>
		<p class="description" style="max-width:860px;">Vista unificada de los leads (CRM + formularios) para operadores o el dueño del sitio, sin login de WordPress, con descarga CSV/Excel/PDF y una API simple para terceros (Zapier, n8n, etc.) que se conectan por su cuenta.</p>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:900px;margin:10px 0;">
			<strong>Panel de Leads (para operadores / cliente, sin login de WordPress)</strong>
			<div style="display:flex;gap:8px;margin:6px 0;">
				<input type="text" readonly value="<?php echo esc_attr( $leads_panel_url ); ?>" style="flex:1;padding:6px 8px;border:1px solid #dcdcde;border-radius:6px;" onclick="this.select()">
				<a class="button" target="_blank" href="<?php echo esc_url( $leads_panel_url ); ?>">Abrir panel</a>
			</div>
			<p class="description">El operador entra con su <strong>nombre + PIN de 4 letras</strong> (una vez; la web lo recuerda).</p>
		</div>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:900px;margin:10px 0;">
			<strong>Notificación al operador (email v2 enriquecido)</strong>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 0;">
				<?php wp_nonce_field( 'estandar_suite_leads_notifier' ); ?>
				<input type="hidden" name="action" value="estandar_suite_leads_notifier">
				<label style="font-size:13px;">
					<input type="checkbox" name="enabled" value="1" <?php checked( $leads_notifier_enabled ); ?>>
					Habilitar notificador v2 (toma el control del correo al operador con el email enriquecido; si algo falla, cae al notifier legacy).
				</label>
				<button class="button" style="margin-left:10px;">Guardar</button>
			</form>
		</div>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:900px;margin:10px 0;">
			<strong>Operadores del panel (nombre + PIN de 4 letras)</strong>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;margin:8px 0;flex-wrap:wrap;align-items:flex-end;">
				<?php wp_nonce_field( 'estandar_suite_leads_operator' ); ?>
				<input type="hidden" name="action" value="estandar_suite_leads_operator">
				<input type="hidden" name="op_action" value="add">
				<div style="display:flex;flex-direction:column;"><label style="font-size:11px;color:#646970;">Nombre</label><input name="name" required placeholder="Ej. Valentina"></div>
				<div style="display:flex;flex-direction:column;"><label style="font-size:11px;color:#646970;">PIN (4 letras)</label><input name="pin" required maxlength="4" pattern="[A-Za-z]{4}" placeholder="abcd"></div>
				<button class="button button-primary">Agregar operador</button>
			</form>
			<?php if ( empty( $leads_operators ) ) : ?>
				<p class="description">Sin operadores. Agrega el primero arriba.</p>
		<?php else : ?>
				<table class="widefat striped" style="max-width:560px;">
					<thead><tr><th>Operador</th><th>Creado</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $leads_operators as $op_name => $op_row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $op_name ); ?></td>
							<td><?php echo esc_html( (string) ( $op_row['created'] ?? '-' ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'estandar_suite_leads_operator' ); ?>
									<input type="hidden" name="action" value="estandar_suite_leads_operator">
									<input type="hidden" name="op_action" value="remove">
									<input type="hidden" name="name" value="<?php echo esc_attr( (string) $op_name ); ?>">
									<button class="button-link-delete">Quitar</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:900px;margin:10px 0;">
			<?php if ( isset( $_GET['gate_saved'] ) ) : ?><div class="notice notice-success inline" style="margin:0 0 10px;"><p>Método de acceso del gate guardado.</p></div><?php endif; ?>
			<strong>Acceso del gate (protección de formularios)</strong>
			<?php
			$gate_mode = \Estandar\Core\LeadsPanel::gate_methods_mode();
			$gate_eff  = \Estandar\Core\LeadsPanel::effective_gate_methods();
			$gate_labels = array( 'pin' => 'PIN', 'magic' => 'correo (magic link)' );
			$gate_eff_txt = array();
			foreach ( $gate_eff as $gm ) { $gate_eff_txt[] = $gate_labels[ $gm ] ?? $gm; }
			?>
			<p class="description" style="max-width:780px;margin:6px 0 10px;">Método de acceso que muestran los formularios protegidos con <code>[estandar_form_gate]</code> y el visor <code>[estandar_camara_registros]</code>. Por defecto: <strong>PIN + correo</strong> (igual que siempre). Afecta <em>solo al gate</em>; el panel de leads y los operadores existentes no se tocan.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
				<?php wp_nonce_field( 'estandar_suite_leads_gate_methods' ); ?>
				<input type="hidden" name="action" value="estandar_suite_leads_gate_methods">
				<label style="display:block;font-size:13px;margin:0 0 6px;"><input type="radio" name="method" value="both" <?php checked( $gate_mode, 'both' ); ?>> PIN + correo <span class="description">(recomendado)</span></label>
				<label style="display:block;font-size:13px;margin:0 0 6px;"><input type="radio" name="method" value="pin" <?php checked( $gate_mode, 'pin' ); ?>> Solo PIN <span class="description">(requiere operadores con PIN)</span></label>
				<label style="display:block;font-size:13px;margin:0 0 6px;"><input type="radio" name="method" value="magic" <?php checked( $gate_mode, 'magic' ); ?>> Solo correo <span class="description">(magic link)</span></label>
				<button class="button button-primary" style="margin-top:6px;">Guardar método</button>
			</form>
			<p class="description" style="margin:10px 0 0;">Efectivo ahora: <strong><?php echo esc_html( implode( ' + ', $gate_eff_txt ) ); ?></strong><?php echo empty( $leads_operators ) ? ' · sin operadores con PIN, el PIN se omite automáticamente.' : ''; ?></p>
		</div>

<details style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:0 16px;max-width:900px;margin:10px 0;">
  <summary style="cursor:pointer;font-weight:600;padding:12px 0;">Acceso por correo (sin contraseña) · registro</summary>
  <div style="padding-bottom:14px;">
    <p class="description" style="margin:0 0 8px;">En <code>/panel-leads/</code> cada persona ingresa su correo de <code><?php echo esc_html( \Estandar\Core\LeadsPanel::magic_domain() ); ?></code> y recibe un enlace de acceso (un solo uso, caduca en 30 min). La sesión dura 30 días. Anti-bombardeo: 1 enlace por correo cada 5 min.</p>
    <table class="widefat striped" style="margin-top:6px;max-width:640px;">
      <thead><tr><th>Evento</th><th>Correo</th><th>IP</th><th>Fecha (web)</th></tr></thead>
      <tbody>
      <?php foreach ( \Estandar\Core\LeadsPanel::magic_log( 20 ) as $ml ) : ?>
        <tr><td><?php echo esc_html( (string) ( $ml['event'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $ml['email'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $ml['ip'] ?? '' ) ); ?></td><td><?php echo esc_html( (string) ( $ml['ts'] ?? '' ) ); ?></td></tr>
      <?php endforeach; ?>
      <?php if ( empty( \Estandar\Core\LeadsPanel::magic_log( 20 ) ) ) : ?><tr><td colspan="4">Sin accesos por correo registrados todavía.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</details>
<details style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:0 16px;max-width:900px;margin:10px 0;">
  <summary style="cursor:pointer;font-weight:600;padding:12px 0;">Actividad del panel · quién abrió la lista o un lead</summary>
  <div style="padding-bottom:14px;">
    <p class="description" style="margin:0 0 8px;">Cada apertura de la lista de leads o de la ficha de un lead queda registrada con quién (operador PIN, correo mágico o usuario WP), a qué hora (zona de la web) y desde qué IP. El acceso por enlace compartido <code>?access=</code> no deja identidad de persona y no se registra.</p>
    <?php
    $activity = \Estandar\Core\LeadsPanel::activity_log( 50 );
    $lead_ids = array();
    foreach ( $activity as $a ) { if ( (string) ( $a['lead_id'] ?? 0 ) !== '0' ) { $lead_ids[] = (int) $a['lead_id']; } }
    $lead_names = array();
    if ( $lead_ids && isset( $wpdb ) ) {
      $lead_names = (array) $wpdb->get_col( 'SELECT id, name FROM ' . $wpdb->prefix . 'es_leads WHERE id IN (' . implode( ',', array_map( 'absint', $lead_ids ) ) . ')' );
      $lead_names = array_combine( array_map( 'absint', $lead_names ), $lead_names );
    }
    ?>
    <table class="widefat striped" style="margin-top:6px;max-width:760px;">
      <thead><tr><th>Quién</th><th>Tipo</th><th>Lead</th><th>IP</th><th>Fecha (web)</th></tr></thead>
      <tbody>
      <?php foreach ( $activity as $a ) : ?>
        <?php $lid = (int) ( $a['lead_id'] ?? 0 ); ?>
        <tr>
          <td><?php echo esc_html( (string) ( $a['who'] ?? '' ) ); ?></td>
          <td><?php echo $lid > 0 ? 'Ficha del lead' : 'Lista de leads'; ?></td>
          <td><?php echo $lid > 0 ? '#' . $lid . ' ' . esc_html( (string) ( $lead_names[ $lid ] ?? '' ) ) : '—'; ?></td>
          <td><?php echo esc_html( (string) ( $a['ip'] ?? '' ) ); ?></td>
          <td><?php echo esc_html( (string) ( $a['ts'] ?? '' ) ); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ( empty( $activity ) ) : ?><tr><td colspan="5">Sin actividad registrada todavía.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

		<?php if ( $ss === 'operadores' ) : ?>
		<?php include ESTANDAR_SUITE_CORE_PATH . 'includes/templates/feature-operadores-manager.php'; ?>
		<?php endif; ?>

		<?php if ( $ss === 'api' ) : ?>
		<?php $leads_api_export = rest_url( 'estandar-suite/core/v1/leads/export' ); ?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:980px;margin:10px 0;">
			<strong>API para integraciones (Zapier / n8n / terceros)</strong>
			<div style="display:flex;gap:8px;margin:6px 0;">
				<input type="text" readonly id="es-api-key" value="<?php echo esc_attr( (string) $leads_tokens['api_key'] ); ?>" style="flex:1;padding:6px 8px;border:1px solid #dcdcde;border-radius:6px;font-family:monospace;" onclick="this.select()">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'estandar_suite_leads_regenerate' ); ?>
					<input type="hidden" name="action" value="estandar_suite_leads_regenerate">
					<input type="hidden" name="which" value="api_key">
					<button class="button">Regenerar API key</button>
				</form>
			</div>
			<p class="description" style="margin:6px 0;"><strong>Qué hace:</strong> API de <strong>lectura (pull)</strong> de leads en JSON. <code>GET <?php echo esc_html( $leads_api_url ); ?></code> devuelve <code>{ columns, count, leads }</code>. Autenticación: header <code>Authorization: Bearer &lt;tu API key&gt;</code>.</p>
			<p class="description" style="margin:6px 0;"><strong>Casos de uso:</strong></p>
			<ul class="description" style="margin:2px 0 6px;padding-left:22px;">
				<li><strong>n8n / Zapier</strong>: un flujo programado (cada 5-60 min) consulta los leads nuevos con <code>from</code>/<code>to</code> y los sincroniza a tu CRM, hoja, Slack u otro destino.</li>
				<li><strong>CRM o sistema propio</strong>: lee los leads capturados por la suite desde tu aplicación.</li>
				<li><strong>Reportes / BI</strong>: exporta a <code>CSV</code>/<code>XLSX</code>/<code>PDF</code> con <code>GET <?php echo esc_html( $leads_api_export ); ?>?format=csv</code> (y los mismos filtros).</li>
			</ul>
			<p class="description" style="margin:6px 0;"><strong>Filtros opcionales:</strong> <code>from</code>, <code>to</code> (YYYY-MM-DD), <code>project</code>, <code>source</code>, <code>limit</code> (máx. 200).</p>
			<div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0;">
				<button type="button" class="button" id="es-api-probe">Probar ahora (últimos 5)</button>
				<button type="button" class="button" id="es-api-copy">Copiar ejemplo curl</button>
			</div>
			<pre id="es-api-out" style="display:none;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px;max-height:340px;overflow:auto;font-size:11px;white-space:pre-wrap;"></pre>
		</div>
		<script>
		(function () {
			var base  = <?php echo wp_json_encode( rest_url( 'estandar-suite/core/v1' ) ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			var out   = document.getElementById('es-api-out');
			var btn   = document.getElementById('es-api-probe');
			var cp    = document.getElementById('es-api-copy');
			function key() { var k = document.getElementById('es-api-key'); return k ? k.value.trim() : ''; }
			if (btn) btn.addEventListener('click', function () {
				btn.disabled = true; out.style.display = 'block'; out.textContent = 'Consultando…';
				fetch(base + '/leads?limit=5', { headers: { 'X-WP-Nonce': nonce } })
					.then(function (r) { return r.json(); })
					.then(function (d) { out.textContent = JSON.stringify(d, null, 2); })
					.catch(function (e) { out.textContent = 'Error: ' + e; })
					.finally(function () { btn.disabled = false; });
			});
			if (cp) cp.addEventListener('click', function () {
				var ex = 'curl -H "Authorization: Bearer ' + key() + '" "' + base + '/leads?from=' + new Date().toISOString().slice(0, 7) + '-01&limit=50"';
				if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(ex).then(function () { cp.textContent = '¡Copiado!'; setTimeout(function () { cp.textContent = 'Copiar ejemplo curl'; }, 1800); }); }
				else { out.style.display = 'block'; out.textContent = ex; }
			});
		})();
		</script>
		<?php endif; ?>

		<?php elseif ( $sub_eff === 'claves' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'claves', '<strong>¿Para qué sirve?</strong> Las claves de IA compartidas de la Suite v2: una sola clave por proveedor (DeepSeek / OpenAI) que consumen los 5 productos (Core, Capture, Brain, Flow, Insights). <br><br>La clave se guarda <strong>solo aquí</strong> (opción propia v2) y se comparte a toda la instalación. Si en el sitio ya había una clave previa en los plugins anteriores (Primer, Chatbot, Scanner), esta pantalla la <em>adopta</em> automáticamente como punto de partida, sin tocar las opciones originales: nada se rompe y ambas conviven.' ); ?>

		<?php if ( isset( $_GET['ai_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Claves API guardadas.</p></div><?php endif; ?>

		<?php
		\Estandar\Core\AiKeys::adopt_legacy_once();
		$ai_settings = \Estandar\Core\AiKeys::get_settings();
		$ai_providers = \Estandar\Core\AiKeys::providers();
		$ai_active    = \Estandar\Core\AiKeys::active_provider();
		$ai_model     = \Estandar\Core\AiKeys::default_model();
		$ai_adopted   = get_option( \Estandar\Core\AiKeys::ADOPTED_FLAG, array() );
		$ai_adopted   = is_array( $ai_adopted ) ? $ai_adopted : array();
		?>

		<?php if ( ! empty( $ai_adopted ) ) : ?>
			<div class="notice notice-info is-dismissible">
				<p><strong>Claves adoptadas de la instalación anterior.</strong> Se importaron automáticamente (solo lectura, las opciones originales no se tocaron):
				<?php foreach ( $ai_adopted as $_p => $_src ) : ?>
					<code><?php echo esc_html( $_p ); ?></code> ← <code><?php echo esc_html( $_src ); ?></code>
				<?php endforeach; ?>
				— puedes conservarlas o reemplazarlas abajo.</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;max-width:640px;">
			<?php wp_nonce_field( 'estandar_suite_core_save_ai_keys' ); ?>
			<input type="hidden" name="action" value="estandar_suite_core_save_ai_keys">
			<table class="form-table" role="presentation">
				<?php foreach ( $ai_providers as $_pid => $_plabel ) : ?>
					<?php $_pval = (string) ( $ai_settings[ $_pid ] ?? '' ); ?>
					<tr>
						<th scope="row"><label for="es-ai-<?php echo esc_attr( $_pid ); ?>"><?php echo esc_html( $_plabel ); ?></label></th>
						<td>
							<div style="display:flex;align-items:center;gap:6px;max-width:420px;">
								<div style="position:relative;flex:1;">
									<input id="es-ai-<?php echo esc_attr( $_pid ); ?>" type="password" name="ai_keys[<?php echo esc_attr( $_pid ); ?>]" class="regular-text" style="width:100%;padding-right:34px;" value="" autocomplete="new-password" placeholder="<?php echo $_pval !== '' ? '' : ''; ?>">
									<?php if ( $_pval !== '' ) : ?>
										<span class="es-ai-dots" id="es-ai-dots-<?php echo esc_attr( $_pid ); ?>" aria-hidden="true" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);pointer-events:none;color:#1d2327;letter-spacing:3px;font-size:13px;">● ● ● ● ● ● ● ●</span>
										<button type="button" class="es-ai-eye" data-provider="<?php echo esc_attr( $_pid ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'estandar_suite_reveal_ai_key' ) ); ?>" aria-label="Ver clave <?php echo esc_attr( $_pid ); ?>" title="Ver clave" style="position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:none;cursor:pointer;padding:2px;color:#50575e;line-height:1;">
											<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
										</button>
									<?php endif; ?>
								</div>
								<?php if ( $_pval !== '' ) : ?>
									<label style="white-space:nowrap;"><input type="checkbox" name="ai_clear[<?php echo esc_attr( $_pid ); ?>]" value="1"> Eliminar</label>
								<?php endif; ?>
							</div>
							<p class="description" style="margin-top:4px;"><?php echo $_pval !== '' ? 'Clave configurada (oculta). Usa el ojo para verla · deja el campo vacío para conservarla.' : 'Sin clave guardada en la Suite v2.'; ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="es-ai-active">Proveedor activo</label></th>
					<td>
						<select id="es-ai-active" name="ai_active_provider">
							<?php foreach ( $ai_providers as $_pid => $_plabel ) : ?>
								<option value="<?php echo esc_attr( $_pid ); ?>" <?php selected( $ai_active, $_pid ); ?>><?php echo esc_html( $_plabel ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description" style="margin-top:4px;">Proveedor que usan los productos de la Suite por defecto.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="es-ai-model">Modelo por defecto</label></th>
					<td>
						<input id="es-ai-model" type="text" name="ai_default_model" class="regular-text" value="<?php echo esc_attr( $ai_model ); ?>" placeholder="<?php echo $ai_active === 'openai' ? 'gpt-4o-mini' : 'deepseek-chat'; ?>">
						<p class="description" style="margin-top:4px;">Modelo por defecto para el proveedor activo (ej. <code>deepseek-chat</code>, <code>deepseek-reasoner</code>, <code>gpt-4o-mini</code>).</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Guardar claves API' ); ?>
		</form>

		<script>
		(function () {
			document.querySelectorAll( '.es-ai-eye' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var provider = btn.getAttribute( 'data-provider' );
					var nonce = btn.getAttribute( 'data-nonce' );
					var input = document.getElementById( 'es-ai-' + provider );
					var dots = document.getElementById( 'es-ai-dots-' + provider );
					var isVisible = input.getAttribute( 'data-visible' ) === '1';
					if ( isVisible ) {
						input.value = '';
						input.type = 'password';
						input.removeAttribute( 'data-visible' );
						if ( dots ) { dots.style.display = ''; }
						btn.querySelector( '.dashicons' ).className = 'dashicons dashicons-visibility';
						return;
					}
					var body = new URLSearchParams();
					body.set( 'action', 'estandar_suite_reveal_ai_key' );
					body.set( 'nonce', nonce );
					body.set( 'provider', provider );
					fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( d ) {
							if ( ! d || ! d.success || ! d.data || ! d.data.key ) {
								alert( ( d && d.data && d.data.message ) || 'No se pudo ver la clave.' );
								return;
							}
							input.value = d.data.key;
							input.type = 'text';
							input.setAttribute( 'data-visible', '1' );
							if ( dots ) { dots.style.display = 'none'; }
							btn.querySelector( '.dashicons' ).className = 'dashicons dashicons-hidden';
						} )
						.catch( function () { alert( 'No se pudo ver la clave.' ); } );
				} );
			} );
		})();
		</script>

		<?php elseif ( $sub_eff === 'correo' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'correo', '<strong>¿Para qué sirve?</strong> Configura el transporte de correo de TODA la Suite (y del sitio): con el SMTP nativo configurado, todos los envíos — notificaciones de formularios (incluidos los plugins legacy), leads, cotizaciones y pruebas — salen por un único motor autenticado. Sin SMTP, el hosting intenta entregar por PHP mail() y los correos suelen perderse silenciosamente. <em>Ejemplo:</em> pones el SMTP de SiteGround (smtp.tu-host.com, puerto 465, SSL) con las credenciales de una cuenta de correo del dominio, y todo empieza a llegar.' ); ?>

		<?php if ( isset( $_GET['mail_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Configuración avanzada de correo guardada.</p></div><?php endif; ?>
		<?php if ( isset( $_GET['monitor_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Copias de monitoreo guardadas.</p></div><?php endif; ?>
		<?php if ( isset( $_GET['mail_test'] ) ) : ?><div class="notice notice-<?php echo $_GET['mail_test'] === '1' ? 'success' : 'error'; ?> is-dismissible"><p><?php echo $_GET['mail_test'] === '1' ? 'Correo de prueba ENVIADO (transporte: ' . esc_html( (string) ( $_GET['mail_test_transport'] ?? '?' ) ) . '). Revisa la bandeja del destinatario.' : 'El correo de prueba falló: ' . esc_html( (string) ( $_GET['mail_test_err'] ?? '' ) ); ?></p></div><?php endif; ?>

		<?php
		// MAIL_IDENTITY_CANONICAL_V1: se retiró la vista "Identidad" (el branding
		// se configura en Capture → Panel de Leads → Email). La vista por defecto
		// del bloque Correo pasa a ser "Monitoreo".
		$corr_view = sanitize_key( (string) ( $_GET['cc'] ?? 'basica' ) );
		if ( ! in_array( $corr_view, array( 'basica', 'avanzado', 'plantillas' ), true ) ) {
			$corr_view = 'basica';
		}
		$corr_base = ( $sub === 'configuracion' && ( $cfg_view ?? '' ) === 'correo' )
			? admin_url( 'admin.php?page=estandar-suite&tab=config&sub=configuracion&cfg=correo' )
			: admin_url( 'admin.php?page=estandar-suite&tab=config&sub=correo' );
		echo \Estandar\Core\Admin::subtab_nav_html( array(
			'basica'     => array( 'label' => 'Monitoreo', 'url' => add_query_arg( 'cc', 'basica', $corr_base ) ),
			'avanzado'   => array( 'label' => 'Avanzado', 'url' => add_query_arg( 'cc', 'avanzado', $corr_base ) ),
			'plantillas' => array( 'label' => 'Plantillas', 'url' => add_query_arg( 'cc', 'plantillas', $corr_base ) ),
		), $corr_view );
		?>

		<?php if ( $corr_view === 'basica' ) : ?>
			<?php $monitor_bcc = (string) get_option( 'estandar_suite_core_mail_monitor_bcc', '' ); ?>
			<?php
			$monitor_flag = (string) get_option( \Estandar\Core\Mail::MONITOR_ENABLED_OPTION, '' );
			if ( $monitor_flag === '1' ) {
				$monitor_on = true;
			} elseif ( $monitor_flag === '0' ) {
				$monitor_on = false;
			} else {
				$monitor_on = \Estandar\Core\Mail::monitor_bcc() !== array();
			}
			?>
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;max-width:640px;margin:12px 0;">
				<h3 style="margin:0 0 4px;">Copias de monitoreo (check maestro)</h3>
				<p class="description" style="margin:0 0 12px;">Recibe en <strong>copia oculta</strong> (BCC) TODOS los correos que envía la Suite (confirmaciones/autorespuestas, notificaciones a operadores, encuestas…). Así verificas si llegan o no los correos de cada formulario.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'estandar_suite_core_save_mail_monitor' ); ?>
					<input type="hidden" name="action" value="estandar_suite_core_save_mail_monitor">
					<table class="form-table" role="presentation" style="margin:0;">
						<tr><th scope="row">Activar copia</th><td><label style="display:inline-flex;gap:8px;align-items:center;">
							<input type="hidden" name="monitor_enabled" value="0">
							<input type="checkbox" name="monitor_enabled" value="1" <?php checked( $monitor_on ); ?>>
							<span><?php echo $monitor_on ? '<span style="color:#007017;font-weight:600;">Activado</span> — todos los correos de la Suite llevarán BCC.' : '<span style="color:#8c8f94;">Desactivado</span>'; ?></span>
						</label></td></tr>
						<tr><th scope="row"><label for="es-monitor-bcc">Dirección de destino</label></th><td><input id="es-monitor-bcc" type="text" name="monitor_bcc" class="regular-text" value="<?php echo esc_attr( $monitor_bcc ); ?>" style="width:80%;" placeholder="gestion@example.com, copia@dominio.cl">
							<span class="description">Correos (separados por coma) que reciben la copia oculta. Si no se ha definido, se hereda la copia que existía en la configuración anterior de notificaciones.</span></td></tr>
					</table>
					<p style="margin:10px 0 0;"><?php submit_button( 'Guardar copias de monitoreo', 'primary', 'save_monitor', false ); ?></p>
				</form>
			</div>

		<?php elseif ( $corr_view === 'plantillas' ) : ?>
		<?php include ESTANDAR_SUITE_CORE_PATH . 'includes/templates/feature-mail-templates.php'; ?>

		<?php else : ?>
			<?php $smtp_host   = (string) get_option( 'estandar_suite_core_smtp_host', '' ); ?>
			<?php $smtp_port   = (int) get_option( 'estandar_suite_core_smtp_port', 587 ); ?>
			<?php $smtp_user   = (string) get_option( 'estandar_suite_core_smtp_user', '' ); ?>
			<?php $smtp_secure = (string) get_option( 'estandar_suite_core_smtp_secure', 'tls' ); ?>
			<?php $smtp_auth   = (int) get_option( 'estandar_suite_core_smtp_auth', 1 ); ?>
			<?php $smtp_pass   = (string) get_option( 'estandar_suite_core_smtp_pass', '' ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;max-width:640px;">
				<?php wp_nonce_field( 'estandar_suite_core_save_mail' ); ?>
				<input type="hidden" name="action" value="estandar_suite_core_save_mail">
				<h4 style="margin:8px 0 4px;">Transporte</h4>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="es-smtp-host">Host SMTP</label></th><td><input id="es-smtp-host" name="smtp_host" class="regular-text" value="<?php echo esc_attr( $smtp_host ); ?>" placeholder="smtp.tu-host.com"></td></tr>
					<tr><th scope="row"><label for="es-smtp-port">Puerto</label></th><td><input id="es-smtp-port" type="number" name="smtp_port" value="<?php echo esc_attr( (string) $smtp_port ); ?>" style="width:120px;"></td></tr>
					<tr><th scope="row"><label for="es-smtp-secure">Seguridad</label></th><td><select id="es-smtp-secure" name="smtp_secure"><option value="tls" <?php selected( $smtp_secure, 'tls' ); ?>>TLS</option><option value="ssl" <?php selected( $smtp_secure, 'ssl' ); ?>>SSL</option><option value="" <?php selected( $smtp_secure, '' ); ?>>Ninguna</option></select></td></tr>
					<tr><th scope="row"><label for="es-smtp-user">Usuario</label></th><td><input id="es-smtp-user" name="smtp_user" class="regular-text" value="<?php echo esc_attr( $smtp_user ); ?>" placeholder="usuario@example.com"></td></tr>
					<tr><th scope="row"><label for="es-smtp-pass">Contraseña</label></th><td><input id="es-smtp-pass" type="password" name="smtp_pass" class="regular-text" value="<?php echo esc_attr( $smtp_pass ); ?>" autocomplete="new-password"></td></tr>
					<tr><th scope="row">Autenticación</th><td><label><input type="checkbox" name="smtp_auth" value="1" <?php checked( $smtp_auth, 1 ); ?>> Requerir autenticación SMTP</label></td></tr>
				</table>
				<?php submit_button( 'Guardar configuración avanzada de correo' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;max-width:640px;">
				<?php wp_nonce_field( 'estandar_suite_core_test_mail' ); ?>
				<input type="hidden" name="action" value="estandar_suite_core_test_mail">
				<p><label for="es-test-to">Enviar correo de prueba a:</label>
				<input id="es-test-to" type="email" name="test_to" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'admin_email', '' ) ); ?>" required>
				<?php submit_button( 'Enviar prueba', 'secondary', 'test_mail', false ); ?></p>
			</form>

			<?php $deliveries = class_exists( 'Estandar\Core\Communications\DeliveryLog' ) ? \Estandar\Core\Communications\DeliveryLog::recent( 10 ) : array(); ?>
			<h4>Últimas entregas (diagnóstico)</h4>
			<?php if ( empty( $deliveries ) ) : ?><p class="description">Sin entregas registradas todavía.</p><?php endif; ?>
			<table class="widefat striped" style="max-width:820px;">
				<thead><tr><th>Fecha</th><th>Transporte</th><th>OK</th><th>Para</th><th>Asunto</th><th>Error</th></tr></thead>
				<tbody>
				<?php foreach ( $deliveries as $d ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $d['ts'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $d['transport'] ?? '' ) ); ?></td>
						<td><?php echo ! empty( $d['ok'] ) ? '✓' : '✗'; ?></td>
						<td><?php echo esc_html( implode( ', ', (array) ( $d['to'] ?? array() ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $d['subject'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $d['error'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php elseif ( $sub_eff === 'operadores' ) : ?>
		<?php include ESTANDAR_SUITE_CORE_PATH . 'includes/templates/feature-operadores-manager.php'; ?>
		<?php elseif ( $sub_eff === 'proyectos' ) : ?>
		<?php include ESTANDAR_SUITE_CORE_PATH . 'includes/templates/feature-projects-manager.php'; ?>
		<?php elseif ( $sub_eff === 'digest' ) : ?>
		<?php include ESTANDAR_SUITE_CORE_PATH . 'includes/templates/feature-leads-digest.php'; ?>
 		<?php elseif ( $sub_eff === 'qualification' ) : ?>
 		<?php echo \Estandar\Core\Admin::help_html( 'qualification', '<strong>Calificación (Frío/Tibio/Caliente).</strong> Pesos y umbrales que usa el correo al operador. La suma = plazo de compra + financiamiento. <em>Contado/ahorro</em> cuenta como señal fuerte y <em>“solo averiguando”</em> es neutro; sube el umbral para exigir más.' ); ?>
 		<?php if ( isset( $_GET['feature_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Settings guardados.</p></div><?php endif; ?>
 		<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_core_save_feature', 'estandar_suite_core_qualification_settings', $feature_fields['qualification'], 'qualification' ); ?>

 		<?php elseif ( $sub_eff === 'visibilidad' ) : ?>
 		<?php echo \Estandar\Core\Admin::help_html( 'visibilidad', '<strong>Visibilidad centralizada.</strong> Administra aquí las pestañas y sub-pestañas visibles de <strong>todos los plugins de la suite</strong> (Core, Capture, Brain, Flow e Insights). Es <strong>solo visual</strong>: las funciones y los datos siguen funcionando aunque ocultes una pestaña.' ); ?>
 		<?php if ( isset( $_GET['visibility_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Visibilidad guardada.</p></div><?php endif; ?>
 		<?php \Estandar\Core\Admin::render_visibility_central( 'estandar-suite', 'core' ); ?>
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
		<?php echo \Estandar\Core\Admin::help_html( 'cli', '<strong>¿Para qué sirve?</strong> Muestra los <em>comandos de WP-CLI</em> del Core para operar la suite desde la terminal: diagnosticar, ver telemetría o detectar la instalación legada. <br><br>Es el <em>panel de control por voz</em>: lo que haces con clicks en la UI, lo haces con una línea de texto. Útil para tareas automatizadas o cuando no hay navegador.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>wp estandar-suite doctor</code></td><td>Diagnóstico de la suite</td></tr>
				<tr><td><code>wp estandar-suite telemetry</code></td><td>Ver eventos técnicos</td></tr>
				<tr><td><code>wp estandar-suite adoption probe</code></td><td>Detectar instalación legada</td></tr>
			</tbody>
		</table>
		<?php elseif ( $sub === 'actualizaciones' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'actualizaciones', '<strong>¿Para qué sirve?</strong> Es el <em>update-core.php de la Suite</em>: revisa si hay versiones nuevas de los 6 plugins v2 en <code>updates.estandar.cl</code>, muestra cuál está desactualizado y permite <strong>actualizar aquí mismo</strong>, sin salir al plugin de WordPress. <br><br>Es el <em>mantenimiento del taller</em>: de un vistazo sabes si algún producto quedó atrás y lo pones al día. El paquete se descarga e instala con el instalador nativo de WordPress.' ); ?>
		<?php
		$upd  = \Estandar\Core\Updates::manifest();
		$last = (int) $upd['last_checked'];
		?>
		<?php if ( isset( $_GET['checked'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Comprobación completada contra updates.estandar.cl.</p></div><?php endif; ?>
		<?php if ( isset( $_GET['policy'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Política de actualización guardada.</p></div><?php endif; ?>
		<?php if ( ! empty( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Actualizados: <?php echo esc_html( implode( ', ', array_map( 'trim', explode( ',', (string) $_GET['updated'] ) ) ) ); ?> ✓</p></div><?php endif; ?>
		<?php if ( ! empty( $_GET['error'] ) ) : ?><div class="notice notice-error is-dismissible"><p>Error al actualizar: <?php echo esc_html( implode( '<br>', array_map( 'trim', explode( ',', (string) $_GET['error'] ) ) ) ); ?></p></div><?php endif; ?>

		<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 0;">
			<span class="description">Última comprobación: <strong><?php echo $last > 0 ? esc_html( gmdate( 'd-m-Y H:i', $last ) . ' UTC' ) : 'nunca'; ?></strong> · canal <code>updates.estandar.cl/staging/v2/</code></span>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="estandar_suite_updates">
				<input type="hidden" name="es_upd_action" value="check">
				<?php wp_nonce_field( 'estandar_suite_updates' ); ?>
				<button type="submit" class="button">⟳ Comprobar ahora</button>
			</form>
		</div>

		<?php
		$pol = \Estandar\Core\Updates::policy();
		$pol_label = $pol['mode'] === 'off' ? 'Actualizaciones de la Suite DESACTIVADAS en este sitio' : ( $pol['mode'] === 'pin' ? 'Este sitio congelado en la versión ' . $pol['pin'] : 'Actualizaciones de la Suite ACTIVAS (auto)' );
		?>
		<div style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px 12px;border-radius:4px;max-width:980px;margin:6px 0 12px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="es-upd-policy-form" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
				<input type="hidden" name="action" value="estandar_suite_updates">
				<input type="hidden" name="es_upd_action" value="policy">
				<input type="hidden" name="es_upd_policy" id="es-upd-policy-value" value="<?php echo esc_attr( $pol['mode'] === 'pin' ? 'pin:' . $pol['pin'] : $pol['mode'] ); ?>">
				<?php wp_nonce_field( 'estandar_suite_updates' ); ?>
				<strong>Política de este sitio:</strong>
				<select id="es-upd-mode">
					<option value="auto" <?php selected( $pol['mode'], 'auto' ); ?>>Auto (recibe updates)</option>
					<option value="off" <?php selected( $pol['mode'], 'off' ); ?>>Off (no actualizar)</option>
					<option value="pin" <?php selected( $pol['mode'], 'pin' ); ?>>Congelar en versión…</option>
				</select>
				<input type="text" id="es-upd-pin" placeholder="2.2.8" value="<?php echo esc_attr( $pol['mode'] === 'pin' ? $pol['pin'] : '' ); ?>" style="width:90px;<?php echo $pol['mode'] === 'pin' ? '' : 'display:none;'; ?>">
				<button type="submit" class="button">Guardar política</button>
				<span class="description"><?php echo esc_html( $pol_label ); ?></span>
			</form>
			<script>
			(function () {
				var f = document.getElementById('es-upd-policy-form');
				if (!f) { return; }
				var m = document.getElementById('es-upd-mode');
				var pin = document.getElementById('es-upd-pin');
				var out = document.getElementById('es-upd-policy-value');
				function sync() {
					pin.style.display = m.value === 'pin' ? '' : 'none';
				}
				m.addEventListener('change', sync);
				f.addEventListener('submit', function () {
					if (m.value === 'pin') {
						var v = (pin.value || '').trim();
						if (!v) { alert('Indica la versión a congelar (p. ej. 2.2.8).'); m.focus(); return false; }
						out.value = 'pin:' + v;
					} else {
						out.value = m.value;
					}
					return true;
				});
			})();
			</script>
			<p class="description" style="margin:6px 0 0;">Control por instalación: <code>auto</code> ofrece updates automáticos (apágalo por plugin en <em>Plugins → Actualizaciones automáticas</em>), <code>off</code> los bloquea del todo y <code>pin:X.Y.Z</code> congela en una versión. Útil para decidir qué sitios se actualizan (rollout) y cuáles no, sin tocar el canal.</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="estandar_suite_updates">
			<input type="hidden" name="es_upd_action" value="update">
			<?php wp_nonce_field( 'estandar_suite_updates' ); ?>
			<table class="widefat striped" style="max-width:980px;">
				<thead>
					<tr><th style="width:34px;"><input type="checkbox" id="es-upd-all"></th><th>Plugin</th><th>Versión actual</th><th>Versión disponible</th><th>Estado</th><th>Paquete</th></tr>
				</thead>
				<tbody>
				<?php foreach ( $upd['plugins'] as $u ) : ?>
					<?php
					$badge = '';
					if ( ! empty( $u['blocked'] ) ) {
						$badge = '<span style="background:#b26e00;color:#fff;border-radius:99px;padding:2px 9px;font-size:11px;font-weight:700;">Pausado por política de este sitio</span>';
					} elseif ( $u['update'] ) {
						$badge = '<span style="background:#b32d2e;color:#fff;border-radius:99px;padding:2px 9px;font-size:11px;font-weight:700;">Actualización disponible</span>';
					} elseif ( $u['local'] !== '' && $u['remote'] !== '' ) {
						$badge = '<span style="background:#00a32a;color:#fff;border-radius:99px;padding:2px 9px;font-size:11px;font-weight:700;">Al día</span>';
					} else {
						$badge = '<span class="description">Sin telemetría / no instalado</span>';
					}
					?>
					<tr>
						<td><input type="checkbox" name="slugs[]" value="<?php echo esc_attr( $u['slug'] ); ?>" <?php echo $u['update'] ? 'checked' : ''; ?>></td>
						<td><strong><?php echo esc_html( str_replace( 'estandar-', 'Estandar ', $u['slug'] ) ); ?></strong><br><code><?php echo esc_html( $u['file'] ); ?></code></td>
						<td><?php echo $u['local'] !== '' ? esc_html( $u['local'] ) : '<span class="description">—</span>'; ?></td>
						<td><?php echo $u['remote'] !== '' ? esc_html( $u['remote'] ) : '<span class="description">—</span>'; ?></td>
						<td><?php echo $badge; ?></td>
						<td><?php echo $u['package'] !== '' ? '<code>' . esc_html( $u['package'] ) . '</code>' : '<span class="description">—</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="max-width:980px;">
				<button type="submit" class="button button-primary" onclick="return confirm('¿Actualizar los plugins seleccionados desde updates.estandar.cl?');">⬇ Actualizar seleccionados</button>
			</p>
		</form>
		<script>(function(){var a=document.getElementById('es-upd-all');if(!a){return;}a.addEventListener('change',function(){document.querySelectorAll('input[name="slugs[]"]').forEach(function(c){c.checked=a.checked;});});})();</script>
		<?php elseif ( $sub === 'herramientas' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'herramientas', '<strong>¿Para qué sirve?</strong> Reúne dos utilidades de administración: <em>subir SVG</em> a la biblioteca (validados con lista blanca) y <em>generar un child theme</em> desde un tema padre. Ambas se pueden <strong>activar o desactivar por sitio</strong> aquí mismo.' ); ?>
		<?php \Estandar\Core\Tools::render(); ?>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'inventario', '<strong>¿Para qué sirve?</strong> El <em>catálogo completo</em> del Core: todas las funciones del plugin agrupadas por conjuntos funcionales, para que ninguna quede fuera de la interfaz. <br><br>Es el <em>inventario del almacén</em>: confirma que todo lo que el código puede hacer tiene su lugar visible. Sirve para auditar cobertura y detectar funciones escondidas.' ); ?>
		<?php \Estandar\Core\Admin::render_inventory( $sets, $fn_settings ); ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php echo \Estandar\Core\Admin::help_script(); ?>
