<?php
/** Estandar Suite — Capture admin. 4 tabs (Estado · Configuración · Datos ·
 * Avanzado) con sub-tabs por grupo funcional.
 * Variables: $tab, $tabs, $subs, $sub, $sets, $fn_settings, $legacy, $history, $imported, $rolled, $recent.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( empty( $fn_settings ) ) { $fn_settings = array(); }
?>
<div class="wrap">

		<?php \Estandar\Core\Admin::render_module_tabs( 'estandar-suite-capture', $tab, $sub, array( 'forms' => 'Formularios', 'surveys' => 'Encuestas', 'popups' => 'Popups', 'chatbot' => 'Chatbot', 'whatsapp' => 'WhatsApp' ) ); ?>


	<?php if ( \Estandar\Core\Admin::native_blank() ) : ?>
		<!-- Backend Ordenado: solo estructura (sin contenido). -->
		<div class="notice notice-info inline" style="margin:12px 0;max-width:760px;"><p>Backend Ordenado: esta pantalla está en modo <strong>solo estructura</strong>; nada se eliminó.</p></div>
	<?php elseif ( $tab === 'estado' ) : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-capture', 'estado', $sub, $subs['estado'] ); ?>

		<?php if ( $sub === 'resumen' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'resumen', '<strong>¿Para qué sirve?</strong> Es la tarjeta de presentación de Capture: de un vistazo ves el rol (captar leads por cualquier canal), cuánta ingesta ha entrado y si hay instalación legada con submissions históricas. <br><br>Es el <em>mostrador de entrada</em> del edificio: aquí confirmas que la recepción está operativa y saltas a la adopción de leads con un click.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Rol</strong></td><td>Captación omnicanal: ingesta normalizada de capturas (form/chat/landing/popup), atribución first-touch y adopción de leads históricos.</td></tr>
				<tr><td><strong>Ingesta</strong></td><td>Las capturas llegan vía formularios, chat, landing, popup y omnicanal; se normalizan y se emite <code>estandar_suite_capture_event</code>.</td></tr>
				<tr><td><strong>Instalación legada</strong></td><td><?php echo $legacy ? 'SÍ — submissions históricas disponibles para adopción (tab <strong>Datos</strong>).' : 'NO — instalación limpia.'; ?></td></tr>
			</tbody>
		</table>
		<p style="margin-top:10px;">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=datos&sub=adopcion' ) ); ?>">Ver adopción de leads</a>
		</p>
		<?php elseif ( $sub === 'datos' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'datos', '<strong>¿Para qué sirve?</strong> Explica cómo se atribuye cada captura: el <em>primer canal</em> y los click IDs (UTM) que la originaron, con respaldo para capturas sin evidencia. <br><br>Es la <em>caja de origen</em>: saber de dónde vino cada lead para no perder la trazabilidad de las campañas.' ); ?>
		<h3>Canales y atribución</h3>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Atribución first-touch</strong></td><td>La captura conserva el primer canal y los click IDs (UTM/click IDs), con respaldos para capturas sin evidencia.</td></tr>
				<tr><td><strong>Canales</strong></td><td>Las primitivas de canal viven en Core (sin dependencia dura de Capture).</td></tr>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'esquema', '<strong>¿Para qué sirve?</strong> Muestra la tabla donde vive la captación: <code>estandar_capture_submissions</code> (20 columnas), con el campo <code>origin_lead_id</code> como marcador de adopción. <br><br>Es la <em>ficha técnica</em> de dónde se guardan los leads. No se edita aquí; solo para entender el modelo de datos.' ); ?>
		<p><code>estandar_capture_submissions</code> (20 columnas, <code>origin_lead_id</code> como marcador de adopción).</p>
		<?php endif; ?>

	<?php elseif ( $tab === 'config' ) : ?>

		<?php if ( $sub === 'atribucion' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'atribucion', '<strong>¿Para qué sirve?</strong> Define la atribución omnicanal: cómo Capture conserva el <em>primer canal</em> y los click IDs de cada lead, incluso cuando la captura llega sin evidencia de click. <br><br>Es el <em>sistema de etiquetas del paquete</em>: cada lead llega con su «de dónde vino» bien marcado. También confirma que Capture solo lee las tablas legadas, nunca las escribe.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td style="width:220px;"><strong>Atribución first-touch</strong></td><td>La captura conserva el primer canal y los click IDs (UTM/click IDs), con respaldos para capturas sin evidencia.</td></tr>
				<tr><td><strong>Canales</strong></td><td>Las primitivas de canal viven en Core (sin dependencia dura de Capture).</td></tr>
				<tr><td><strong>Datos históricos</strong></td><td>Capture solo lee las tablas legadas <code>es_lead_submissions</code>/<code>es_leads</code>; nunca escribe en ellas.</td></tr>
			</tbody>
		</table>
		<?php elseif ( $sub === 'forms' ) : ?>
		<?php
		// Línea 03 — módulos internos del sistema de formularios.
		$fm_modules = array(
			'configuracion' => 'Configuración',
			'formularios'   => 'Formularios',
			'entradas'      => 'Entradas',
			'importar'      => 'Importar',
		);
		$fm = sanitize_key( (string) ( $_GET['fm'] ?? 'formularios' ) );
		if ( ! isset( $fm_modules[ $fm ] ) ) { $fm = 'formularios'; }
		$fm_items = array();
		foreach ( $fm_modules as $mk => $ml ) {
			$fm_items[] = array(
				'href'   => admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&fm=' . $mk ),
				'label'  => $ml,
				'active' => ( $fm === $mk ),
			);
		}
		\Estandar\Core\Admin::render_nav_tabs( $fm_items );

		if ( $fm === 'formularios' || $fm === 'papelera' ) {
			if ( $fm === 'papelera' ) { $_GET['papelera'] = 1; }
			$feature = 'forms'; $feature_fields = $feature_fields['forms']; $feature_option = 'estandar_suite_capture_forms_settings'; $fp_type = 'lead'; $fp_title = 'Formularios'; $fp_singular = 'formulario'; $fp_use_responses = false; $fp_create_label = 'Crear formulario'; $fp_help = '<strong>¿Para qué sirve?</strong> Administra los <em>formularios</em> de captura de la suite v2.'; include ESTANDAR_SUITE_CAPTURE_PATH . 'includes/templates/feature-forms-product.php';
			if ( isset( $_GET['papelera'] ) ) { unset( $_GET['papelera'] ); }
			if ( \Estandar\Capture\Admin::show_legacy_config() ) { \Estandar\Capture\Admin::render_legacy_forms_config(); }
		} elseif ( $fm === 'configuracion' ) {
			echo \Estandar\Core\Admin::help_html( 'configuracion', '<strong>Configuración del módulo Formularios</strong> (habilitado, email de notificación y almacenamiento de entradas).' );
			\Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_feature', 'estandar_suite_capture_forms_settings', $feature_fields['forms'], 'forms' );
				} elseif ( $fm === 'entradas' ) {
			echo \Estandar\Core\Admin::help_html( 'entradas', '<strong>Entradas de formularios</strong> — cada entrada de los formularios (tipo lead), filtrable por formulario. Surveys / postventa / chatbot no se listan aquí.' );
			if ( ! class_exists( '\\WP_List_Table' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'; }
			require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Admin/EntriesListTable.php';
			$lead_names = array();
			foreach ( \Estandar\Capture\Forms::list() as $lf ) {
				if ( (string) $lf['form_type'] === 'lead' && (string) $lf['status'] !== 'trashed' ) {
					$lead_names[ (int) $lf['id'] ] = (string) $lf['name'];
				}
			}
			$cur_f = isset( $_GET['fform'] ) ? (int) $_GET['fform'] : 0;
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin:10px 0;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">';
			foreach ( array( 'page' => 'estandar-suite-capture', 'tab' => 'config', 'sub' => 'forms', 'fm' => 'entradas' ) as $fk => $fv ) {
				echo '<input type="hidden" name="' . esc_attr( $fk ) . '" value="' . esc_attr( $fv ) . '">';
			}
			echo '<select name="fform"><option value="0">Todos los formularios</option>';
			foreach ( $lead_names as $lid => $ln ) {
				echo '<option value="' . (int) $lid . '"' . selected( $cur_f, $lid, false ) . '>' . esc_html( $ln ) . '</option>';
			}
			echo '</select>';
			echo '<input type="text" name="s" value="' . esc_attr( (string) ( $_GET['s'] ?? '' ) ) . '" placeholder="Buscar nombre/email">';
			echo '<button class="button">Filtrar</button></form>';
			$et = new \Estandar\Capture\Admin\EntriesListTable();
			$et->process_row_actions();
			$et->process_bulk_action();
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&fm=entradas' ) ) . '">';
			$et->prepare_items();
			$et->display();
			echo '</form>';
		} elseif ( $fm === 'importar' ) {
			echo \Estandar\Core\Admin::help_html( 'importar', '<strong>Importar formularios</strong> desde JSON (envelope v1) o Gravity Forms.' );
			echo '<div style="background:#f9f9f6;border:1px solid #c3c4c7;border-radius:8px;padding:14px;max-width:820px;">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" style="margin:0 0 10px;">';
			wp_nonce_field( 'estandar_suite_capture_form_json' );
			echo '<input type="hidden" name="action" value="estandar_suite_capture_form_json_import">';
			echo '<label>JSON de formulario (envelope v1):</label> ';
			echo '<input type="file" name="form_json" accept=".json"> ';
			echo '<button class="button" type="submit">Importar JSON</button></form>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" style="margin:0;">';
			wp_nonce_field( 'estandar_suite_capture_gf_import' );
			echo '<input type="hidden" name="action" value="estandar_suite_capture_gf_import">';
			echo '<label>Export JSON de Gravity Forms:</label> ';
			echo '<input type="file" name="gf_json" accept=".json"> ';
			echo '<button class="button" type="submit">Importar desde Gravity Forms</button></form>';
			echo '</div>';
		}
		?>

		<?php elseif ( $sub === 'surveys' ) : ?>
		<?php $m = array(
			'feature' => 'surveys', 'type' => 'survey', 'title' => 'Encuestas', 'singular' => 'encuesta',
			'use_responses' => true, 'create_label' => 'Crear encuesta',
			'help' => '<strong>¿Para qué sirve?</strong> Administra las <em>encuestas</em> de la suite v2 (tipo Typeform): crearlas con su estructura de preguntas, editarlas y ver sus respuestas.',
			'fields' => $feature_fields['surveys'], 'settings_opt' => 'estandar_suite_capture_surveys_settings',
			'import' => false, 'bridge' => false,
			'modules' => array( 'list' => 'Encuestas', 'entradas' => 'Respuestas', 'configuracion' => 'Configuración' ),
		); include ESTANDAR_SUITE_CAPTURE_PATH . 'includes/templates/feature-capture-module.php'; ?>

		<?php elseif ( $sub === 'postventa' ) : ?>
		<?php $m = array(
			'feature' => 'postventa', 'type' => 'incident', 'title' => 'Postventa', 'singular' => 'caso',
			'use_responses' => false, 'create_label' => 'Crear caso',
			'help' => '<strong>¿Para qué sirve?</strong> Administra los <em>casos / incidentes</em> de postventa de la suite v2, con sus entradas y seguimiento.',
			'fields' => $feature_fields['postventa'], 'settings_opt' => 'estandar_suite_capture_postventa_settings',
			'import' => false, 'bridge' => true,
			'modules' => array( 'list' => 'Casos', 'entradas' => 'Entradas', 'configuracion' => 'Configuración' ),
		); include ESTANDAR_SUITE_CAPTURE_PATH . 'includes/templates/feature-capture-module.php'; ?>

		<?php elseif ( $sub === 'chatbot' ) : ?>
		<?php $feature = 'chatbot'; $feature_option = 'estandar_suite_capture_chatbot_settings'; include ESTANDAR_SUITE_CAPTURE_PATH . 'includes/templates/feature-chatbot.php'; ?>

		<?php elseif ( $sub === 'whatsapp' ) : ?>
		<?php $feature = 'whatsapp'; $feature_option = 'estandar_suite_capture_whatsapp_settings'; include ESTANDAR_SUITE_CAPTURE_PATH . 'includes/templates/feature-whatsapp.php'; ?>

		<?php elseif ( $sub === 'popups' ) : ?>
		<?php $feature = 'popups'; $feature_fields = $feature_fields['popups']; $feature_option = 'estandar_suite_capture_popups_settings'; include ESTANDAR_SUITE_CAPTURE_PATH . 'includes/templates/feature-popups.php'; ?>

		<?php elseif ( $sub === 'leads' ) : ?>
		<?php $feature = 'leads'; $feature_fields = $feature_fields['leads']; $feature_option = 'estandar_suite_capture_leads_settings'; include ESTANDAR_SUITE_CAPTURE_PATH . 'includes/templates/feature-leads.php'; ?>

		<?php elseif ( $sub === 'settings' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'settings', '<strong>¿Para qué sirve?</strong> Ajustes operativos de Capture agrupados por área (infraestructura, operación, esquema, UI) con valores recomendados de fábrica. <br><br>Es el <em>panel de mandos</em>: aquí se decide cómo se comporta la captación por defecto. La sección «Settings heredados» muestra en solo lectura lo que dejó el plugin v1.' ); ?>
		<?php \Estandar\Core\Admin::render_settings_panel( \Estandar\Capture\Admin::get_settings() ); ?>
		<?php else : ?>
 		<?php echo \Estandar\Core\Admin::help_html( 'visibilidad', '<strong>Visibilidad consolidada en Core.</strong> La visibilidad de pestañas de todos los plugins de la suite (incluido Capture) se administra desde <strong>Estandar Suite → Core → Config → Visibilidad</strong>. Este cambio solo afecta la visualización; ninguna función, setting o dato se pierde.' ); ?>
 		<?php endif; ?>

	<?php elseif ( $tab === 'settings' ) : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-capture', 'settings', $sub, $subs['settings'] ); ?>

		<?php if ( $sub === 'capacidades' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'capacidades', '<strong>¿Para qué sirve?</strong> Todos los catchers (Formularios, Encuestas, Postventa) comparten el mismo motor. Aquí defines, en una sola matriz, qué capacidades tiene cada tipo de catcher. <br><br>Es como <em>asignar herramientas por puesto</em>: una fila por capacidad, una columna por producto. Marca la casilla donde el tipo debe tener esa capacidad (p.ej. activar «Paso a paso» solo en Encuestas, o también en Postventa).' ); ?>
		<?php if ( isset( $_GET['matrix_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Matriz de capacidades guardada.</p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'estandar_suite_capture_save_caps_matrix' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_save_caps_matrix">
			<table class="widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<th style="width:28%;">Capacidad</th>
						<?php foreach ( $settings_types as $tk => $tl ) : ?>
							<th style="text-align:center;"><?php echo esc_html( $tl ); ?> (<?php echo esc_html( $tk ); ?>)</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $settings_matrix as $cap => $cfg ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $cfg['label'] ); ?></strong><br><span class="description"><?php echo esc_html( $cfg['desc'] ); ?></span></td>
						<?php foreach ( $settings_types as $tk => $tl ) : $available = ! empty( $cfg['types'][ $tk ] ); $on = $available && \Estandar\Capture\Forms::is_capability_enabled( $tk, $cap ); ?>
							<td style="text-align:center;">
								<?php if ( $available ) : ?>
									<input type="checkbox" name="matrix[<?php echo esc_attr( $tk ); ?>][<?php echo esc_attr( $cap ); ?>]" value="1" <?php checked( $on, true ); ?>>
								<?php else : ?>
									<span class="description">—</span>
								<?php endif; ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top:12px;"><?php submit_button( 'Guardar matriz de capacidades', 'primary', 'save_matrix', false ); ?></p>
		</form>

		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'modulos', '<strong>¿Para qué sirve?</strong> Configuración general de cada módulo de captación (Forms, Surveys, Postventa, Chatbot, Popups, Leads). <br><br>Cada módulo tiene sus propias opciones; aquí se agrupan todas para el administrador.' ); ?>
		<?php foreach ( $settings_features as $sf ) : ?>
			<h4><?php echo esc_html( ucfirst( $sf ) ); ?></h4>
			<?php \Estandar\Core\Admin::render_feature_settings( 'estandar_suite_capture_save_feature', 'estandar_suite_capture_' . $sf . '_settings', $feature_fields_all[ $sf ], $sf ); ?>
		<?php endforeach; ?>
		<?php endif; ?>

	<?php elseif ( $tab === 'datos' ) : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-capture', 'datos', $sub, $subs['datos'] ); ?>

		<?php if ( $sub === 'adopcion' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'adopcion', '<strong>¿Para qué sirve?</strong> Trae los <em>leads históricos</em> de la instalación anterior (v1) al formato nuevo de Capture, sin tocar las tablas originales. <br><br>Es una <em>mudanza cuidadosa</em>: previsualizas (dry-run) cuántos se importarán, luego confirmas (commit) y, si algo no cuadra, haces rollback y los adoptados se eliminan. Los originales quedan intactos siempre.' ); ?>
		<p>Instalación legada: <strong><?php echo $legacy ? 'SÍ — ' . count( $history ) . ' submissions históricas visibles' : 'NO — sin tablas legadas de submissions'; ?></strong></p>

		<?php if ( $imported !== null ) : ?><div class="notice notice-success is-dismissible"><p>Adopción procesada: <strong><?php echo (int) $imported; ?></strong> registros nuevos (0 = ya estaban o no había legados).</p></div><?php endif; ?>
		<?php if ( $rolled !== null ) : ?><div class="notice notice-success is-dismissible"><p>Rollback: <strong><?php echo (int) $rolled; ?></strong> registros adoptados eliminados.</p></div><?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
			<?php wp_nonce_field( 'estandar_suite_capture_adoption_import' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_adoption_import">
			<label>Límite <input type="number" name="limit" value="100" min="1" max="500"></label>
			<?php submit_button( 'Previsualizar import (dry-run)', 'secondary', 'preview', false ); ?>
			<?php submit_button( 'Importar (commit)', 'primary', 'commit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Eliminar los registros adoptados? Los originales no se tocan.')">
			<?php wp_nonce_field( 'estandar_suite_capture_adoption_rollback' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_adoption_rollback">
			<?php submit_button( 'Rollback (solo adoptados)', 'delete', 'rollback', false ); ?>
		</form>

		<hr style="margin:22px 0;">

		<?php $fa = \Estandar\Capture\Admin::forms_adoption_summary(); ?>
		<h3>Adopción de Forms (entries / surveys) — solo lectura del legacy</h3>
		<p class="description">Lee las tablas originales de Estandar Forms (<code>estandar_forms*</code>) y las presenta como capturas v2. No copia, no migra y jamás escribe en legacy.</p>
		<table class="widefat striped" style="max-width:760px;">
			<thead><tr><th>Recurso legacy</th><th>Estado</th></tr></thead>
			<tbody>
				<tr><td>Formularios (<code>estandar_forms</code>)</td><td><strong><?php echo (int) $fa['estandar_forms']; ?></strong> (v2 adoptados: <?php echo count( \Estandar\Capture\Forms::list() ); ?>)</td></tr>
				<tr><td>Entries (<code>estandar_form_entries</code>)</td><td><strong><?php echo (int) $fa['estandar_form_entries']; ?></strong></td></tr>
				<tr><td>Survey responses (<code>estandar_survey_responses</code>)</td><td><strong><?php echo (int) $fa['estandar_survey_responses']; ?></strong></td></tr>
				<tr><td>Incidents (<code>estandar_incidents</code>) · Logs · Requests · Attachments · Tokens</td><td><?php echo (int) $fa['estandar_incidents']; ?> · <?php echo (int) $fa['estandar_form_logs']; ?> · <?php echo (int) $fa['estandar_requests']; ?> · <?php echo (int) $fa['estandar_attachments']; ?> · <?php echo (int) $fa['estandar_tokens']; ?> <span class="description">(solo conteo read-only)</span></td></tr>
			</tbody>
		</table>

		<?php if ( isset( $_GET['forms_imported'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Forms entries adoptadas: <strong><?php echo (int) $_GET['forms_imported']; ?></strong></p></div><?php endif; ?>
		<?php if ( isset( $_GET['forms_rolled'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Rollback entries: <strong><?php echo (int) $_GET['forms_rolled']; ?></strong></p></div><?php endif; ?>
		<?php if ( isset( $_GET['forms_responses_imported'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Survey responses adoptadas: <strong><?php echo (int) $_GET['forms_responses_imported']; ?></strong></p></div><?php endif; ?>
		<?php if ( isset( $_GET['forms_responses_rolled'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Rollback responses: <strong><?php echo (int) $_GET['forms_responses_rolled']; ?></strong></p></div><?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
			<?php wp_nonce_field( 'estandar_suite_capture_forms_entries_import' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_forms_entries_import">
			<label>Límite entries <input type="number" name="limit" value="100" min="1" max="500"></label>
			<?php submit_button( 'Previsualizar entries (dry-run)', 'secondary', 'preview', false ); ?>
			<?php submit_button( 'Importar entries (commit)', 'primary', 'commit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Eliminar solo las entries adoptadas (source=form)?')">
			<?php wp_nonce_field( 'estandar_suite_capture_forms_entries_rollback' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_forms_entries_rollback">
			<?php submit_button( 'Rollback entries (solo adoptadas)', 'delete', 'rollback', false ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
			<?php wp_nonce_field( 'estandar_suite_capture_forms_responses_import' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_forms_responses_import">
			<label>Límite responses <input type="number" name="limit" value="100" min="1" max="500"></label>
			<?php submit_button( 'Previsualizar responses (dry-run)', 'secondary', 'preview', false ); ?>
			<?php submit_button( 'Importar responses (commit)', 'primary', 'commit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Eliminar solo las responses adoptadas (source=survey)?')">
			<?php wp_nonce_field( 'estandar_suite_capture_forms_responses_rollback' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_forms_responses_rollback">
			<?php submit_button( 'Rollback responses (solo adoptadas)', 'delete', 'rollback', false ); ?>
		</form>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'historial', '<strong>¿Para qué sirve?</strong> Vista previa de las <em>submissions históricas</em> legadas antes de adoptarlas: lead, fuente, nombre, email, fecha y click IDs. <br><br>Es el <em>álbum de fotos del pasado</em>: revisas qué hay antes de decidir qué traer al presente. Solo lectura.' ); ?>
		<?php if ( $history ) : ?>
			<h3>Vista previa (histórico)</h3>
			<table class="widefat striped">
				<thead><tr><th>Lead</th><th>Fuente</th><th>Nombre</th><th>Email</th><th>Fecha</th><th>Click IDs</th></tr></thead>
				<tbody>
				<?php foreach ( $history as $h ) : ?>
					<tr>
						<td><?php echo (int) $h['origin_lead_id']; ?></td>
						<td><?php echo esc_html( $h['source'] ); ?></td>
						<td><?php echo esc_html( $h['name'] ); ?></td>
						<td><?php echo esc_html( $h['email_normalized'] ); ?></td>
						<td><?php echo esc_html( $h['created_at'] ); ?></td>
						<td><code><?php echo esc_html( $h['click_ids_json'] ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?><p class="description">Sin histórico disponible.</p><?php endif; ?>
		<?php endif; ?>

	<?php else : ?>
		<?php \Estandar\Core\Admin::render_subtabs( 'estandar-suite-capture', 'avanzado', $sub, $subs['avanzado'] ); ?>

		<?php if ( $sub === 'rest' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'rest', '<strong>¿Para qué sirve?</strong> Lista los <em>endpoints REST</em> de Capture para que otros sistemas ingesten capturas o consulten la adopción histórica vía HTTP. Todos requieren autenticación y permisos de administrador. <br><br>Es la <em>puerta de servicio</em>: lo que la UI hace con clicks, los sistemas lo hacen por aquí. Útil para conectar formularios externos o n8n.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>POST /estandar-suite/capture/v1/captures</code></td><td>Ingestar una captura</td></tr>
				<tr><td><code>GET /estandar-suite/capture/v1/adoption/historical</code></td><td>Previsualizar submissions históricas</td></tr>
			</tbody>
		</table>
		<?php elseif ( $sub === 'cli' ) : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'cli', '<strong>¿Para qué sirve?</strong> Muestra los <em>comandos de WP-CLI</em> de Capture para operar la captación desde la terminal: ingestar leads, ver el estado de la adopción, importar o hacer rollback. <br><br>Es el <em>panel de control por voz</em>: lo que haces con clicks en la UI, lo haces con una línea de texto. Útil para tareas automatizadas o sin navegador.' ); ?>
		<table class="widefat striped" style="max-width:820px;">
			<tbody>
				<tr><td><code>wp estandar-suite capture ingest</code></td><td>Ingestar una captura</td></tr>
				<tr><td><code>wp estandar-suite capture adoption status</code></td><td>Estado de la adopción</td></tr>
				<tr><td><code>wp estandar-suite capture adoption import [--commit]</code></td><td>Importar leads históricos</td></tr>
				<tr><td><code>wp estandar-suite capture adoption rollback --confirm=yes</code></td><td>Rollback de adoptados</td></tr>
			</tbody>
		</table>
		<?php else : ?>
		<?php echo \Estandar\Core\Admin::help_html( 'inventario', '<strong>¿Para qué sirve?</strong> El <em>catálogo completo</em> de Capture: todas las funciones del plugin agrupadas por conjuntos funcionales, para que ninguna quede fuera de la interfaz. <br><br>Es el <em>inventario del almacén</em>: confirma que todo lo que el código puede hacer tiene su lugar visible. Sirve para auditar cobertura y detectar funciones escondidas.' ); ?>
		<?php \Estandar\Core\Admin::render_inventory( $sets, $fn_settings ); ?>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php echo \Estandar\Core\Admin::help_script(); ?>
