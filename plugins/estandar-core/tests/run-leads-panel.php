<?php
/**
 * LeadsPanel (estandar-core) — functional tests against the local Playground.
 *
 *   php tests/run-leads-panel.php   → expect "0 failed"
 *
 * Covers:
 *   - recontact_counts(): misma semántica que el 🔥 del notifier (ventana 3h).
 *   - status_label(): Calificado / Contactado / Sin contacto.
 *   - operator_leads(): recontacts + status por fila.
 *   - render_viewer(): badges de estado presentes.
 * Rollbacks all fixtures.
 */

if ( getenv( 'ESTANDAR_MYSQL_GATE_DB' ) ) {
	require dirname( __DIR__, 2 ) . '/estandar-leads/tests/bootstrap-wordpress-mysql.php';
} else {
	require dirname( __DIR__, 3 ) . '/Playground/wp-load.php';
}

if ( ! class_exists( '\Estandar\Core\LeadsPanel' ) ) {
	fwrite( STDERR, "run-leads-panel: clase LeadsPanel no cargada — ¿estandar-core activo en Playground?\n" );
	exit( 1 );
}

use Estandar\Core\LeadsPanel as LP;

global $wpdb;
$passed = 0;
$failed = 0;

function panel_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

$leads_table = $wpdb->prefix . 'es_leads';
$subs_table  = $wpdb->prefix . 'es_lead_submissions';
if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $leads_table ) ) !== $leads_table
	|| (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $subs_table ) ) !== $subs_table ) {
	fwrite( STDERR, "run-leads-panel: tablas es_leads / es_lead_submissions no existen — ¿estandar-leads activo en Playground?\n" );
	exit( 1 );
}

$wpdb->query( 'START TRANSACTION' );

$now = gmdate( 'Y-m-d H:i:s' );
$ids = array();

function panel_lead( array $data, string $now ): int {
	global $wpdb, $leads_table;
	$defaults = array(
		'lead_uuid'              => wp_generate_uuid4(),
		'status'                 => 'NEW',
		'first_source_plugin'    => 'estandar-forms',
		'latest_source_plugin'   => 'estandar-forms',
		'project_slug'           => 'panel-test',
		'first_seen_at'          => $now,
		'last_seen_at'           => $now,
		'created_at'             => $now,
		'updated_at'             => $now,
	);
	$wpdb->insert( $leads_table, array_merge( $defaults, $data ) );
	return absint( $wpdb->insert_id );
}

function panel_sub( int $lead_id, string $submitted_at ): void {
	global $wpdb, $subs_table;
	$wpdb->insert( $subs_table, array(
		'lead_id'        => $lead_id,
		'lead_uuid'      => (string) $wpdb->get_var( $wpdb->prepare( "SELECT lead_uuid FROM {$GLOBALS['leads_table']} WHERE id = %d", $lead_id ) ),
		'source_plugin'  => 'estandar-forms',
		'schema_version' => '1.0',
		'submitted_at'   => $submitted_at,
		'created_at'     => $submitted_at,
	) );
}

$t0 = strtotime( $now );

// Lead A: 3 submissions, gaps 4h y 6h (ambos > 3h) → 2 recontactos.
$id_a = panel_lead( array( 'name' => 'PANELTEST Recontact 2' ), $now );
panel_sub( $id_a, gmdate( 'Y-m-d H:i:s', $t0 ) );
panel_sub( $id_a, gmdate( 'Y-m-d H:i:s', $t0 + 4 * HOUR_IN_SECONDS ) );
panel_sub( $id_a, gmdate( 'Y-m-d H:i:s', $t0 + 10 * HOUR_IN_SECONDS ) );

// Lead B: 2 submissions, gap 1h (misma sesión) → 0 recontactos.
$id_b = panel_lead( array( 'name' => 'PANELTEST Same session' ), $now );
panel_sub( $id_b, gmdate( 'Y-m-d H:i:s', $t0 ) );
panel_sub( $id_b, gmdate( 'Y-m-d H:i:s', $t0 + 1 * HOUR_IN_SECONDS ) );

// Lead C: 2 submissions, gap 4h + calificado → 1 recontacto, Calificado.
$id_c = panel_lead( array( 'name' => 'PANELTEST Calificado', 'feedback_completed' => 1, 'last_classified_at' => $now, 'status' => 'QUALIFIED' ), $now );
panel_sub( $id_c, gmdate( 'Y-m-d H:i:s', $t0 ) );
panel_sub( $id_c, gmdate( 'Y-m-d H:i:s', $t0 + 4 * HOUR_IN_SECONDS ) );

// Lead D: 1 submission + contacto registrado → 0 recontactos, Contactado.
$id_d = panel_lead( array( 'name' => 'PANELTEST Contactado', 'first_contact_at' => $now, 'contact_attempts' => 1 ), $now );
panel_sub( $id_d, gmdate( 'Y-m-d H:i:s', $t0 ) );

// Lead E: 1 submission, sin gestión → 0 recontactos, Sin contacto.
$id_e = panel_lead( array( 'name' => 'PANELTEST Sin gestion' ), $now );
panel_sub( $id_e, gmdate( 'Y-m-d H:i:s', $t0 ) );

// Lead F: SIN proyecto (routing sin resolver) → NO debe verse en el panel.
$id_f = panel_lead( array( 'name' => 'PANELTEST Unrouted', 'project_slug' => '' ), $now );
panel_sub( $id_f, gmdate( 'Y-m-d H:i:s', $t0 ) );

// ── recontact_counts ─────────────────────────────────────────────────────────
$counts = \Estandar\Core\LeadsPanel::recontact_counts( array( $id_a, $id_b, $id_c, $id_d, $id_e ) );
panel_check( 'recontact_counts: gap>3h suma recontactos (A=2)', (int) ( $counts[ $id_a ] ?? -1 ) === 2 );
panel_check( 'recontact_counts: misma sesión no suma (B=0)', (int) ( $counts[ $id_b ] ?? -1 ) === 0 );
panel_check( 'recontact_counts: lead calificado con reformulario (C=1)', (int) ( $counts[ $id_c ] ?? -1 ) === 1 );
panel_check( 'recontact_counts: sin reformulario (D=0)', (int) ( $counts[ $id_d ] ?? -1 ) === 0 );
panel_check( 'recontact_counts: sin reformulario (E=0)', (int) ( $counts[ $id_e ] ?? -1 ) === 0 );
panel_check( 'recontact_counts: lista vacía → vacío', \Estandar\Core\LeadsPanel::recontact_counts( array() ) === array() );

// ── status_label ─────────────────────────────────────────────────────────────
panel_check( 'status_label: calificado (feedback_completed)', \Estandar\Core\LeadsPanel::status_label( 1, '', '', 0 ) === 'Calificado' );
panel_check( 'status_label: calificado (last_classified_at)', \Estandar\Core\LeadsPanel::status_label( 0, $now, '', 0 ) === 'Calificado' );
panel_check( 'status_label: contactado (first_contact_at)', \Estandar\Core\LeadsPanel::status_label( 0, '', $now, 0 ) === 'Contactado' );
panel_check( 'status_label: contactado (contact_attempts)', \Estandar\Core\LeadsPanel::status_label( 0, '', '', 2 ) === 'Contactado' );
panel_check( 'status_label: nunca contactado', \Estandar\Core\LeadsPanel::status_label( 0, '', '', 0 ) === 'Nunca contactado' );

// ── operator_leads ───────────────────────────────────────────────────────────
$rows = \Estandar\Core\LeadsPanel::operator_leads( array( 'name' => 'PANELTEST' ), 50, 0 );
$by_name = array();
foreach ( $rows as $r ) {
	$by_name[ (string) $r['name'] ] = $r;
}
panel_check( 'operator_leads: incluye los 5 leads de fixture', count( $rows ) >= 5 );
panel_check( 'operator_leads: recontacts del lead reincidente = 2', isset( $by_name['PANELTEST Recontact 2'] ) && (int) $by_name['PANELTEST Recontact 2']['recontacts'] === 2 );
panel_check( 'operator_leads: recontacts misma sesión = 0', isset( $by_name['PANELTEST Same session'] ) && (int) $by_name['PANELTEST Same session']['recontacts'] === 0 );
panel_check( 'operator_leads: status Calificado', isset( $by_name['PANELTEST Calificado'] ) && $by_name['PANELTEST Calificado']['status'] === 'Calificado' );
panel_check( 'operator_leads: status Contactado', isset( $by_name['PANELTEST Contactado'] ) && $by_name['PANELTEST Contactado']['status'] === 'Contactado' );
panel_check( 'operator_leads: status Nunca contactado', isset( $by_name['PANELTEST Sin gestion'] ) && $by_name['PANELTEST Sin gestion']['status'] === 'Nunca contactado' );

// HIDE_UNROUTED_V1: los leads sin routing a proyecto no aparecen por defecto.
panel_check( 'operator_leads: oculta lead sin proyecto', ! isset( $by_name['PANELTEST Unrouted'] ) );
$rows_unr = \Estandar\Core\LeadsPanel::operator_leads( array( 'name' => 'PANELTEST', 'show_unrouted' => 1 ), 50, 0 );
$by_unr = array();
foreach ( $rows_unr as $r ) { $by_unr[ (string) $r['name'] ] = $r; }
panel_check( 'operator_leads: show_unrouted sí lo incluye', isset( $by_unr['PANELTEST Unrouted'] ) );
$rows_sinp = \Estandar\Core\LeadsPanel::operator_leads( array( 'project' => '__unrouted__' ), 50, 0 );
$by_sinp = array();
foreach ( $rows_sinp as $r ) { $by_sinp[ (string) $r['name'] ] = $r; }
panel_check( 'operator_leads: filtro Sin proyecto solo muestra unrouted', count( $rows_sinp ) >= 1 && isset( $by_sinp['PANELTEST Unrouted'] ) && ! isset( $by_sinp['PANELTEST Sin gestion'] ) );
$cnt_default = \Estandar\Core\LeadsPanel::count_operator_leads( array( 'name' => 'PANELTEST' ) );
panel_check( 'count_operator_leads: sin proyecto no cuenta en total', (int) $cnt_default === 5 );
$cnt_sinp = \Estandar\Core\LeadsPanel::count_operator_leads( array( 'project' => '__unrouted__', 'name' => 'PANELTEST' ) );
panel_check( 'count_operator_leads: filtro Sin proyecto cuenta el unrouted', (int) $cnt_sinp === 1 );

// ── Orden por columnas computadas (recontacts / status) ─────────────────────
$by_rc = \Estandar\Core\LeadsPanel::operator_leads( array( 'name' => 'PANELTEST', 'orderby' => 'recontacts', 'order' => 'desc' ), 50, 0 );
panel_check( 'sort recontacts desc: lead con 2 recontactos primero', (int) $by_rc[0]['recontacts'] >= 2 && $by_rc[0]['name'] === 'PANELTEST Recontact 2' );
$rc_sorted = array_map( static function ( $r ) { return (int) $r['recontacts']; }, $by_rc );
$rc_ok = true; $prev = PHP_INT_MAX;
foreach ( $rc_sorted as $v ) { if ( $v > $prev ) { $rc_ok = false; break; } $prev = $v; }
panel_check( 'sort recontacts desc: monótono no-creciente', $rc_ok );

$by_st = \Estandar\Core\LeadsPanel::operator_leads( array( 'name' => 'PANELTEST', 'orderby' => 'status', 'order' => 'asc' ), 50, 0 );
$prio = array( 'Nunca contactado' => 0, 'Contactado' => 1, 'Calificado' => 2 );
$st_sorted = array_column( array_map( static function ( $r ) use ( $prio ) { return $prio[ $r['status'] ] ?? 3; }, $by_st ), 0 );
panel_check( 'sort status asc: nunca contactado primero', isset( $by_st[0] ) && $by_st[0]['status'] === 'Nunca contactado' );
$st_ok = true; $prev = -1;
foreach ( $st_sorted as $v ) { if ( $v < $prev ) { $st_ok = false; break; } $prev = $v; }
panel_check( 'sort status asc: monótono no-decreciente', $st_ok );

// ── render_viewer (badges) ───────────────────────────────────────────────────
$html = \Estandar\Core\LeadsPanel::render_viewer( $rows, array( 'name' => 'PANELTEST' ), '', 1, 1, count( $rows ) );
panel_check( 'render_viewer: badge Calificado presente', strpos( $html, '✔ Calificado' ) !== false );
panel_check( 'render_viewer: badge Contactado presente', strpos( $html, '✆ Contactado' ) !== false );
panel_check( 'render_viewer: badge rojo Nunca contactado presente', strpos( $html, '⚠ Nunca contactado' ) !== false );
panel_check( 'render_viewer: columna Estado presente', strpos( $html, 'data-label="Estado"' ) !== false );
panel_check( 'render_viewer: opción "Sin proyecto" en el filtro', strpos( $html, '>— Sin proyecto —<' ) !== false );
// PANEL_PAGINATION_V1
panel_check( 'render_viewer: paginador presente', strpos( $html, 'class="pager"' ) !== false );
panel_check( 'render_viewer: contador "Mostrando X–Y de N"', strpos( $html, 'Mostrando' ) !== false );
panel_check( 'render_viewer: selector de por-página', strpos( $html, 'name="per_page"' ) !== false );
$html_multi = \Estandar\Core\LeadsPanel::render_viewer( array(), array( 'name' => 'PANELTEST' ), '', 2, 5, 120, 30 );
panel_check( 'render_viewer: enlace "Siguiente" en página 2', strpos( $html_multi, 'Siguiente' ) !== false );
panel_check( 'render_viewer: enlace "Anterior" en página 2', strpos( $html_multi, 'Anterior' ) !== false );

// ── recontact_badge ──────────────────────────────────────────────────────────
panel_check( 'recontact_badge: sin recontactos → —', strpos( \Estandar\Core\LeadsPanel::recontact_badge( 0 ), '—' ) !== false );
panel_check( 'recontact_badge: con recontactos → 🔥', strpos( \Estandar\Core\LeadsPanel::recontact_badge( 2 ), '🔥' ) !== false );

// ── lead_pipeline: lead con todo el flujo ──────────────────────────────────
function panel_event( int $lead_id, string $type, string $at, array $meta = array() ): void {
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'es_lead_events', array(
		'lead_id'    => $lead_id,
		'event_type' => $type,
		'metadata'   => wp_json_encode( $meta ),
		'created_at' => $at,
	), array( '%d', '%s', '%s', '%s' ) );
}

$p_now = gmdate( 'Y-m-d H:i:s' );
$p_t0  = strtotime( $p_now );

$pf_id = panel_lead( array(
	'name' => 'PANELTEST Pipeline Full', 'feedback_completed' => 1,
	'last_classified_at' => $p_now, 'contact_attempts' => 2, 'first_contact_at' => $p_now,
	'status' => 'QUALIFIED', 'quality' => 'ALTO', 'assigned_operator_name' => 'Operadora A',
	'first_session_id' => 'pipe-sess-full', 'first_source_plugin' => 'estandar-forms',
	'first_utm_source' => 'fb', 'first_utm_medium' => 'paid', 'first_referrer' => 'https://m.facebook.com/',
	'email' => 'contacto@paneltest.cl', 'phone' => '+56911112222',
), $p_now );
$wpdb->insert( $subs_table, array(
	'lead_id' => $pf_id, 'lead_uuid' => (string) $wpdb->get_var( $wpdb->prepare( "SELECT lead_uuid FROM {$leads_table} WHERE id = %d", $pf_id ) ),
	'source_plugin' => 'estandar-forms', 'schema_version' => '1.0',
	'submitted_at' => $p_now, 'created_at' => $p_now,
	'session_id' => 'pipe-sess-full',
	'utm_source' => 'fb', 'utm_medium' => 'paid', 'utm_campaign' => 'ACQ|Alto|Lead',
	'referrer' => 'https://m.facebook.com/', 'landing_url' => 'https://cliente1.example.com/demo/',
) );
$chat_tbl = $wpdb->prefix . 'estandar_capture_chat_messages';
if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $chat_tbl ) ) === $chat_tbl ) {
	$wpdb->insert( $chat_tbl, array( 'session_key' => 'pipe-sess-full', 'channel' => 'web', 'role' => 'user', 'content' => 'Hola', 'created_at' => $p_now ) );
	$wpdb->insert( $chat_tbl, array( 'session_key' => 'pipe-sess-full', 'channel' => 'web', 'role' => 'assistant', 'content' => 'Hola!', 'created_at' => $p_now ) );
}
$wpdb->insert( $wpdb->prefix . 'es_lead_contacts', array(
	'lead_id' => $pf_id, 'operator_id' => 7, 'channel' => 'whatsapp', 'outcome' => 'CONTACTADO', 'notes' => 'Conversó', 'contacted_at' => $p_now,
) );
panel_event( $pf_id, 'lead_created', $p_now );
panel_event( $pf_id, 'operator_assigned', $p_now, array( 'operator_id' => 7 ) );
panel_event( $pf_id, 'operator_notified', $p_now );
panel_event( $pf_id, 'autoresponse_email_sent', $p_now );
panel_event( $pf_id, 'autoresponse_email_opened', gmdate( 'Y-m-d H:i:s', $p_t0 + 60 ) );
panel_event( $pf_id, 'autoresponse_link_click', gmdate( 'Y-m-d H:i:s', $p_t0 + 120 ) );
panel_event( $pf_id, 'lead_viewed', gmdate( 'Y-m-d H:i:s', $p_t0 + 180 ) );
panel_event( $pf_id, 'whatsapp_clicked', gmdate( 'Y-m-d H:i:s', $p_t0 + 200 ) );
panel_event( $pf_id, 'contact_logged', gmdate( 'Y-m-d H:i:s', $p_t0 + 220 ) );

$pipe = \Estandar\Core\LeadsPanel::lead_pipeline( $pf_id );
$steps_by_id = array();
foreach ( $pipe['steps'] as $st ) { $steps_by_id[ $st['id'] ] = $st; }
panel_check( 'pipeline: lead encontrado', (int) $pipe['id'] === $pf_id );
panel_check( 'pipeline: origen done (fb)', $steps_by_id['origen']['status'] === 'done' );
panel_check( 'pipeline: chatbot done', $steps_by_id['chatbot']['status'] === 'done' );
panel_check( 'pipeline: form done', $steps_by_id['form']['status'] === 'done' );
panel_check( 'pipeline: autoresponse done', $steps_by_id['autoresponse']['status'] === 'done' );
panel_check( 'pipeline: routing done', $steps_by_id['routing']['status'] === 'done' );
panel_check( 'pipeline: notify done', $steps_by_id['notify']['status'] === 'done' );
panel_check( 'pipeline: seen done', $steps_by_id['seen']['status'] === 'done' );
panel_check( 'pipeline: act done', $steps_by_id['act']['status'] === 'done' );
panel_check( 'pipeline: contact done', $steps_by_id['contact']['status'] === 'done' );
panel_check( 'pipeline: classify done', $steps_by_id['classify']['status'] === 'done' );

// Lead sin gestión → todo marcado como falta.
$pz_id = panel_lead( array( 'name' => 'PANELTEST Pipeline Empty' ), $p_now );
panel_sub( $pz_id, $p_now );
panel_event( $pz_id, 'lead_created', $p_now );
panel_event( $pz_id, 'lead_submitted', $p_now );
$pipe_z = \Estandar\Core\LeadsPanel::lead_pipeline( $pz_id );
$z_by_id = array();
foreach ( $pipe_z['steps'] as $st ) { $z_by_id[ $st['id'] ] = $st; }
panel_check( 'pipeline empty: chatbot no usado (gris)', $z_by_id['chatbot']['status'] === 'skip' );
panel_check( 'pipeline empty: autoresponse sin telemetría (gris)', $z_by_id['autoresponse']['status'] === 'skip' );
panel_check( 'pipeline empty: routing falta', $z_by_id['routing']['status'] === 'missing' );
panel_check( 'pipeline empty: seen pendiente (operador)', $z_by_id['seen']['status'] === 'pending' );
panel_check( 'pipeline empty: contact pendiente (operador)', $z_by_id['contact']['status'] === 'pending' );
panel_check( 'pipeline empty: classify pendiente (operador)', $z_by_id['classify']['status'] === 'pending' );

// Render de la ficha.
$ficha = \Estandar\Core\LeadsPanel::render_lead_ficha( $pipe );
panel_check( 'ficha: título del lead bajo el botón volver', strpos( $ficha, '← Volver al panel</a></p><h2 class="lead-title">#' . $pf_id ) !== false );
panel_check( 'ficha: branding sin el nombre (título simple)', strpos( $ficha, '<strong style="font-size:16px;letter-spacing:.02em;">Ficha del lead</strong>' ) !== false );
panel_check( 'ficha full: sin sección Lo que falta', strpos( $ficha, 'Lo que falta' ) === false );
$ficha_z = \Estandar\Core\LeadsPanel::render_lead_ficha( $pipe_z );
panel_check( 'ficha empty: sección Lo que falta presente', strpos( $ficha_z, 'Lo que falta' ) !== false );
panel_check( 'ficha empty: chip Falta presente', strpos( $ficha_z, '>Falta<' ) !== false );
panel_check( 'ficha empty: chip Pendiente presente', strpos( $ficha_z, '>Pendiente<' ) !== false );
panel_check( 'ficha empty: chip No usado presente (chatbot gris)', strpos( $ficha_z, '>No usado<' ) !== false );

// ── Editor de lead: dropdown Estado en español (values canónicos) ────────────
$editor_html = \Estandar\Core\LeadsPanel::render_lead_editor( 0 );
panel_check( 'editor: Estado en español (Nuevo/Calificado)', strpos( $editor_html, '>Nuevo</option>' ) !== false && strpos( $editor_html, '>Calificado</option>' ) !== false );
panel_check( 'editor: sin códigos crudos en el dropdown', strpos( $editor_html, '>NEW</option>' ) === false && strpos( $editor_html, '>LOST</option>' ) === false );

// ── Ficha: contacto accionable (email link + botón Comunicarse) ──────────────
panel_check( 'ficha: email como mailto', strpos( $ficha, 'href="mailto:contacto@paneltest.cl"' ) !== false );
panel_check( 'ficha: link Editar lleva el lead id', preg_match( '/lead=' . $pf_id . '&(?:amp;|#038;)?edit=1/', $ficha ) === 1 );
panel_check( 'ficha: link Cotizar lleva el lead id', preg_match( '/lead=' . $pf_id . '&(?:amp;|#038;)?quote=new/', $ficha ) === 1 );
panel_check( 'ficha: botón Comunicarse presente', strpos( $ficha, '📞 Comunicarse' ) !== false );
panel_check( 'ficha: menú Comunicarse con WhatsApp', strpos( $ficha, '💬 Mensaje de WhatsApp' ) !== false );
panel_check( 'ficha: menú Comunicarse con Llamada', strpos( $ficha, '📞 Llamada' ) !== false );
panel_check( 'ficha: menú Comunicarse con Correo', strpos( $ficha, '✉️ Correo' ) !== false );

// ── Recontactos declarados (REPEAT_REASON_V1) → visible en ficha ─────────────
$cap_tbl = $wpdb->prefix . 'estandar_capture_submissions';
if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cap_tbl ) ) === $cap_tbl ) {
	$pr_id = panel_lead( array( 'name' => 'PANELTEST Repeat Reason', 'email' => 'repeat@paneltest.cl' ), $now );
	panel_sub( $pr_id, $now );
	$wpdb->insert( $cap_tbl, array(
		'capture_uuid'    => wp_generate_uuid4(),
		'email_normalized'=> 'repeat@paneltest.cl',
		'name'            => 'PANELTEST Repeat Reason',
		'source'          => 'form',
		'source_form_id'  => 1,
		'created_at'      => $now,
		'repeat_reason'   => 'urgent',
		'repeat_note'     => 'Necesito avanzar hoy',
	) );
	$pipe_pr = \Estandar\Core\LeadsPanel::lead_pipeline( $pr_id );
	panel_check( 'repeat: pipeline expone motivo de recontacto', ( (string) ( $pipe_pr['repeats'][0]['code'] ?? '' ) === 'urgent' ) );
	$ficha_pr = \Estandar\Core\LeadsPanel::render_lead_ficha( $pipe_pr );
	panel_check( 'repeat: ficha muestra "Recontactos declarados"', strpos( $ficha_pr, 'Recontactos declarados' ) !== false );
	panel_check( 'repeat: ficha muestra etiqueta del motivo', strpos( $ficha_pr, 'Necesito atención urgente' ) !== false );
	panel_check( 'repeat: ficha muestra la nota', strpos( $ficha_pr, 'Necesito avanzar hoy' ) !== false );
}

// ── Diagnóstico de fallo de autorespuesta (email FALLÓ) ────────────────────
$paf_id = panel_lead( array( 'name' => 'PANELTEST Autoresponse Fail' ), $p_now );
panel_sub( $paf_id, $p_now );
panel_event( $paf_id, 'lead_created', $p_now );
panel_event( $paf_id, 'autoresponse_email_failed', $p_now, array(
	'reason' => 'send_error',
	'send_error_message' => 'SMTP connect() failed',
	'send_error_code'    => 'smtp_connect',
) );
$pipe_af = \Estandar\Core\LeadsPanel::lead_pipeline( $paf_id );
$af_step = null;
foreach ( $pipe_af['steps'] as $st ) { if ( $st['id'] === 'autoresponse' ) { $af_step = $st; break; } }
panel_check( 'autoresponse fail: step en Falta (rojo)', $af_step['status'] === 'missing' );
panel_check( 'autoresponse fail: detalle incluye razón send_error', strpos( $af_step['detail'], 'send_error' ) !== false );
panel_check( 'autoresponse fail: detalle incluye el error SMTP', strpos( $af_step['detail'], 'SMTP connect() failed' ) !== false );
panel_check( 'autoresponse fail: detalle incluye pista de solución', strpos( $af_step['detail'], 'Configuración → Correo' ) !== false );
panel_check( 'autoresponse fail: reason corto = mensaje', LP::autoresponse_failure_reason( $pipe_af['events'] ) === 'SMTP connect() failed' );
$ficha_af = LP::render_lead_ficha( $pipe_af );
panel_check( 'ficha: timeline muestra la razón del fallo', strpos( $ficha_af, 'SMTP connect() failed' ) !== false );

// Fallback v2: error SMTP desde la opción del sistema (sin metadata en el evento).
$ev_no_msg = array( array(
	'event_type' => 'autoresponse_email_failed',
	'metadata'   => wp_json_encode( array( 'reason' => 'send_error' ) ),
	'created_at' => $p_now, 'operator_id' => 0, 'to_status' => 'x',
) );
update_option( 'estandar_leads_smtp_last_error', array( 'ts' => $p_now, 'message' => 'SMTP auth failed', 'code' => 'smtp_auth' ), false );
panel_check( 'autoresponse fail: fallback opción SMTP en reason', LP::autoresponse_failure_reason( $ev_no_msg ) === 'SMTP auth failed' );
panel_check( 'autoresponse fail: fallback opción SMTP en detail', strpos( LP::autoresponse_failure_detail( $ev_no_msg ), 'SMTP auth failed' ) !== false );
delete_option( 'estandar_leads_smtp_last_error' );

// Acciones nativas v2 (sin dependencia de estandar-leads).
$pact_id = panel_lead( array( 'name' => 'PANELTEST Acciones' ), $p_now );
panel_sub( $pact_id, $p_now );
panel_check( 'acciones: log_contact_event inserta whatsapp_clicked', LP::log_contact_event( $pact_id, 'whatsapp', 0 ) === true );
panel_check( 'acciones: evento insertado en es_lead_events', (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d AND event_type = 'whatsapp_clicked'", $pact_id ) ) >= 1 );
$fback_url = LP::create_feedback_url( $pact_id, 0 );
panel_check( 'acciones: create_feedback_url genera URL', strpos( $fback_url, 'estandar_feedback=1' ) !== false && strpos( $fback_url, 'token=' ) !== false );
panel_check( 'acciones: token persistido en es_feedback_tokens', (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}es_feedback_tokens WHERE lead_id = %d", $pact_id ) ) >= 1 );
panel_check( 'acciones: contact_target whatsapp', strpos( LP::contact_target( array( 'name' => 'X', 'phone' => '+56911112222', 'email' => '' ), 'whatsapp' ), 'wa.me/56911112222' ) !== false );
panel_check( 'acciones: contact_target call', strpos( LP::contact_target( array( 'name' => 'X', 'phone' => '+56911112222', 'email' => '' ), 'call' ), 'tel:+56911112222' ) !== false );

// ── Acceso por correo (magic link) ─────────────────────────────────────────
panel_check( 'magic: dominio del sitio no vacío', LP::magic_domain() !== '' );
add_filter( 'estandar_suite_leads_panel_magic_domain', static function () { return 'test.cl'; } );
panel_check( 'magic: resolve prefijo → correo del dominio', LP::magic_resolve_email( 'valentina' ) === 'valentina@test.cl' );
panel_check( 'magic: resolve correo completo válido', LP::magic_resolve_email( 'valentina@test.cl' ) === 'valentina@test.cl' );
panel_check( 'magic: resolve correo de otro dominio → vacío', LP::magic_resolve_email( 'x@otro.cl' ) === '' );
panel_check( 'magic: resolve vacío → vacío', LP::magic_resolve_email( '' ) === '' );

// Resolución contra operadores conocidos del registry (VALENTINA_FIX).
add_filter( 'estandar_suite_leads_panel_known_operators', static function () {
	return array(
		'valentina'         => 'e.desarrolladora@test.cl',
		'e.desarrolladora'  => 'e.desarrolladora@test.cl',
		'rodrigo'           => 'rodrigo@fuera.cl', // fuera del dominio → se descarta
	);
} );
panel_check( 'magic-target: nombre de operador → correo registrado', LP::resolve_magic_target( 'valentina' ) === 'e.desarrolladora@test.cl' );
panel_check( 'magic-target: parte local → correo registrado', LP::resolve_magic_target( 'e.desarrolladora' ) === 'e.desarrolladora@test.cl' );
panel_check( 'magic-target: caso mixto (VaLeNtInA) → correo registrado', LP::resolve_magic_target( 'VaLENtina' ) === 'e.desarrolladora@test.cl' );
panel_check( 'magic-target: operador con correo de otro dominio → fallback', LP::resolve_magic_target( 'rodrigo' ) === 'rodrigo@test.cl' );
panel_check( 'magic-target: desconocido → fallback prefijo@dominio', LP::resolve_magic_target( 'nadie' ) === 'nadie@test.cl' );
panel_check( 'magic-target: correo completo del dominio → igual', LP::resolve_magic_target( 'e.desarrolladora@test.cl' ) === 'e.desarrolladora@test.cl' );
panel_check( 'magic-target: vacío → vacío', LP::resolve_magic_target( '' ) === '' );
remove_all_filters( 'estandar_suite_leads_panel_known_operators' );

$r_bad = LP::request_magic_link( 'x@otro.cl' );
panel_check( 'magic: request correo de otro dominio rechazado', $r_bad['ok'] === false && strpos( $r_bad['message'], 'test.cl' ) !== false );

// Flujo completo (si el envío funciona en el entorno; si no, validar solo el rate-limit).
$r_ok = LP::request_magic_link( 'valentina@test.cl' );
panel_check( 'magic: request válido devuelve array ok/message', is_array( $r_ok ) && isset( $r_ok['ok'], $r_ok['message'] ) );
update_option( LP::MAGIC_RATE_OPT, array( md5( 'valentina@test.cl' ) => time() ), false );
$r_rl = LP::request_magic_link( 'valentina@test.cl' );
panel_check( 'magic: cooldown anti-bombardeo (2ª petición rechazada)', $r_rl['ok'] === false && strpos( $r_rl['message'], 'Ya enviamos' ) !== false );
delete_option( LP::MAGIC_RATE_OPT );

// Consumo single-use.
$raw  = 'f2e9a1c4d5b6a7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c';
$toks = get_option( LP::MAGIC_TOKENS_OPT, array() );
$toks[ LP::magic_hash( $raw ) ] = array( 'email' => 'valentina@test.cl', 'expires' => time() + 600, 'created' => gmdate( 'Y-m-d H:i:s' ) );
update_option( LP::MAGIC_TOKENS_OPT, $toks, false );
panel_check( 'magic: consume token válido → correo', LP::consume_magic_token( $raw ) === 'valentina@test.cl' );
panel_check( 'magic: consume mismo token de nuevo → vacío (single-use)', LP::consume_magic_token( $raw ) === '' );

$expired = 'aaaa' . substr( $raw, 4 );
$toks = get_option( LP::MAGIC_TOKENS_OPT, array() );
$toks[ LP::magic_hash( $expired ) ] = array( 'email' => 'v@test.cl', 'expires' => time() - 60, 'created' => gmdate( 'Y-m-d H:i:s' ) );
update_option( LP::MAGIC_TOKENS_OPT, $toks, false );
panel_check( 'magic: consume token vencido → vacío', LP::consume_magic_token( $expired ) === '' );
delete_option( LP::MAGIC_TOKENS_OPT );
delete_option( LP::MAGIC_RATE_OPT );
remove_all_filters( 'estandar_suite_leads_panel_magic_domain' );

// ── Login: página con formulario de acceso por correo ──────────────────────
// ── Login: éxito (form oculto + contacto admin) y formulario normal ───────
$login_ok = LP::render_login_page( false, 'Revisa tu correo cm@test.cl: te enviamos un enlace de acceso.', 'ok' );
panel_check( 'login ok: sin formulario de acceso', strpos( $login_ok, 'magic_request' ) === false && strpos( $login_ok, 'id="lp-magic"' ) === false );
panel_check( 'login ok: título Revisa tu correo', strpos( $login_ok, 'Revisa tu correo' ) !== false );
panel_check( 'login ok: aviso del envío', strpos( $login_ok, 'te enviamos un enlace de acceso' ) !== false );
panel_check( 'login ok: contacto al administrador', strpos( $login_ok, 'administrador de la web' ) !== false && strpos( $login_ok, 'mailto:' ) !== false );
panel_check( 'login ok: aviso revisar No deseado', strpos( $login_ok, 'No deseado' ) !== false );
panel_check( 'login ok: volver a intentar', strpos( $login_ok, 'Volver a intentar' ) !== false );

$login_form = LP::render_login_page( false, '', 'info' );
panel_check( 'login form: formulario magic link presente', strpos( $login_form, 'Acceso por correo' ) !== false && strpos( $login_form, 'magic_request' ) !== false );
panel_check( 'login form: dominio fijo renderizado', strpos( $login_form, 'email-suffix">@' ) !== false );
panel_check( 'login form: sin formulario PIN visible', strpos( $login_form, 'Ingresar con PIN' ) === false && strpos( $login_form, 'lp_action" value="login"' ) === false );

// ── Auditoría de actividad (quiénes abren la lista / un lead) ──────────────
$pact2 = panel_lead( array( 'name' => 'PANELTEST Activity' ), $p_now );
panel_sub( $pact2, $p_now );
delete_option( LP::VIEWS_LOG_OPT );

// Sin identidad (acceso por token compartido): no se registra.
panel_check( 'actividad: sin identidad no registra', LP::log_activity( 'lead', $pact2 ) === null
	&& LP::log_activity( 'list' ) === null && LP::activity_log( 10 ) === array() );

// Simular operador autenticado vía cookie de PIN (auth_sig usa wp_salt del sitio).
$op_name  = 'TestOp';
$expiry   = time() + 3600;
$token    = $op_name . '|' . $expiry . '|' . hash_hmac( 'sha256', $op_name . '|' . $expiry, wp_salt( 'auth' ) );
$_COOKIE[ LP::AUTH_COOKIE ] = $token;
$saved_ops = LP::operators();
LP::save_operator( $op_name, 'abcd' );
panel_check( 'actividad: operador reconocido por cookie', LP::current_operator() === $op_name );
panel_check( 'actividad: identity del operador PIN', LP::activity_identity() === $op_name );

LP::log_activity( 'lead', $pact2 );
LP::log_activity( 'list' );
$act = LP::activity_log( 10 );
$act_lead = null; $act_list = null;
foreach ( $act as $a ) {
	if ( $a['kind'] === 'lead' && $act_lead === null ) { $act_lead = $a; }
	if ( $a['kind'] === 'list' && $act_list === null ) { $act_list = $a; }
}
panel_check( 'actividad: log registra 2 entradas', count( $act ) === 2 );
panel_check( 'actividad: entrada lead con lead_id', $act_lead !== null && (int) $act_lead['lead_id'] === $pact2 && $act_lead['who'] === $op_name );
panel_check( 'actividad: entrada list sin lead_id', $act_list !== null && (int) $act_list['lead_id'] === 0 );
panel_check( 'actividad: entradas con ip y fecha', $act_lead !== null && $act_lead['ts'] !== '' && isset( $act_lead['ip'] ) );

// log_lead_viewed: inserta evento lead_viewed en es_lead_events.
$before_v = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d AND event_type = 'lead_viewed'", $pact2 ) );
LP::log_lead_viewed( $pact2 );
$after_v = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d AND event_type = 'lead_viewed'", $pact2 ) );
panel_check( 'lead_viewed: evento insertado en es_lead_events', $after_v === $before_v + 1 );

// Restaurar operadores previos y limpiar cookie/log.
unset( $_COOKIE[ LP::AUTH_COOKIE ] );
update_option( LP::OPERATORS_OPTION, $saved_ops, false );
delete_option( LP::VIEWS_LOG_OPT );

// ── CRM_LEAD_EDIT_V1: crear / editar / notas por el operador ───────────────
$created = LP::operator_create_lead( array( 'name' => 'CRM Test', 'email' => 'crmtest@x.cl', 'phone' => '56911110000', 'project_slug' => 'panel-test', 'status' => 'NEW' ) );
panel_check( 'crm: create devuelve ok+lead_id', ! empty( $created['ok'] ) && (int) ( $created['lead_id'] ?? 0 ) > 0 );
$crm_id = (int) ( $created['lead_id'] ?? 0 );
$row_crm = $wpdb->get_row( $wpdb->prepare( "SELECT name,email,phone,project_slug,status FROM {$wpdb->prefix}es_leads WHERE id = %d", $crm_id ), ARRAY_A );
panel_check( 'crm: lead creado con campos', (string) ( $row_crm['name'] ?? '' ) === 'CRM Test' && (string) ( $row_crm['project_slug'] ?? '' ) === 'panel-test' );
$created2 = LP::operator_create_lead( array( 'name' => 'CRM Test 2', 'email' => 'crmtest@x.cl', 'phone' => '', 'project_slug' => '' ) );
panel_check( 'crm: dedupe por email → mismo lead', ! empty( $created2['ok'] ) && (int) ( $created2['lead_id'] ?? 0 ) === $crm_id );

$edited = LP::operator_edit_lead( $crm_id, array( 'name' => 'CRM Test Editado', 'email' => 'crmtest2@x.cl', 'phone' => '56922220000', 'project_slug' => 'panel-test', 'status' => 'CONTACTED' ) );
panel_check( 'crm: edit ok', ! empty( $edited['ok'] ) );
$row_crm2 = $wpdb->get_row( $wpdb->prepare( "SELECT name,email,phone,status FROM {$wpdb->prefix}es_leads WHERE id = %d", $crm_id ), ARRAY_A );
panel_check( 'crm: edit cambió nombre/estado', (string) ( $row_crm2['name'] ?? '' ) === 'CRM Test Editado' && (string) ( $row_crm2['status'] ?? '' ) === 'CONTACTED' );
$ev_edit = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d AND event_type = 'operator_edited'", $crm_id ) );
panel_check( 'crm: edit registra evento operator_edited', (int) $ev_edit >= 1 );

panel_check( 'crm: add_note ok', LP::operator_add_note( $crm_id, 'Llamar mañana' ) === true );
$ev_note = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d AND event_type = 'operator_note' AND reason = 'Llamar mañana'", $crm_id ) );
panel_check( 'crm: nota persistida en timeline', (int) $ev_note >= 1 );
panel_check( 'crm: add_note vacío → false', LP::operator_add_note( $crm_id, '  ' ) === false );

$editor_new = LP::render_lead_editor( 0 );
panel_check( 'crm: editor nuevo tiene formulario+nonce', strpos( $editor_new, 'lp_action" value="lead_save"' ) !== false && strpos( $editor_new, '_wpnonce' ) !== false && strpos( $editor_new, 'Nuevo lead' ) !== false );
$editor_edit = LP::render_lead_editor( $crm_id );
panel_check( 'crm: editor edición precarga nombre', strpos( $editor_edit, 'CRM Test Editado' ) !== false && strpos( $editor_edit, 'value="CONTACTED"' ) !== false );

$wpdb->query( 'ROLLBACK' );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
