<?php
/**
 * CamaraRegistros (estandar-capture) — REGISTROS_V1 functional tests (Playground).
 *
 *   php tests/run-camara-registros.php   → expect "0 failed"
 *
 * Cubre:
 *   - Forms::weekday_label(): "Viernes 05/09/2026" (día de la semana + fecha).
 *   - submission_vars(): checkbox marcado → "✓"; fecha → día + fecha.
 *   - CamaraRegistros::submission_rows(): parsea fields_json → filas planas.
 *   - CamaraRegistros::filter_rows(): filtros por local/cámara/lote/fechas.
 *   - CamaraRegistros::chart_series(): series con temp deseada/real + productos.
 *   - CamaraRegistros::temp_color(): verde/ámbar/rojo según desvío.
 *   - render() sin form → mensaje; autorizado → tabla + gráfico.
 * Rollback de fixtures.
 */

if ( getenv( 'ESTANDAR_MYSQL_GATE_DB' ) ) {
	require dirname( __DIR__, 2 ) . '/estandar-leads/tests/bootstrap-wordpress-mysql.php';
} else {
	require dirname( __DIR__, 3 ) . '/Playground/wp-load.php';
}

if ( ! class_exists( '\Estandar\Capture\CamaraRegistros' ) ) {
	fwrite( STDERR, "run-camara-registros: clase CamaraRegistros no cargada — ¿estandar-capture activo en Playground?\n" );
	exit( 1 );
}

use Estandar\Capture\CamaraRegistros as CR;
use Estandar\Capture\Forms;

global $wpdb;
$passed = 0;
$failed = 0;

function cr_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

$wpdb->query( 'START TRANSACTION' );

// ── weekday_label / weekday_name ─────────────────────────────────────────
cr_check( 'weekday: viernes', Forms::weekday_label( '2026-09-04' ) === 'Viernes 04/09/2026' );
cr_check( 'weekday: martes', Forms::weekday_label( '2026-09-01' ) === 'Martes 01/09/2026' );
cr_check( 'weekday: inválido devuelve igual', Forms::weekday_label( 'no-fecha' ) === 'no-fecha' );
cr_check( 'weekday: vacío devuelve igual', Forms::weekday_label( '' ) === '' );
cr_check( 'weekday_name: solo día', Forms::weekday_name( '2026-09-04' ) === 'Viernes' );
cr_check( 'weekday_name: lunes', Forms::weekday_name( '2026-08-03' ) === 'Lunes' );
cr_check( 'weekday_name: inválido devuelve igual', Forms::weekday_name( 'no-fecha' ) === 'no-fecha' );

// ── submission_vars: checkbox → ✓, fecha → día + fecha ───────────────────
$cfg = array(
	'name' => 'Registro Diario de Cámara',
	'sections' => array(
		array( 'title' => 'Día', 'fields' => array(
			array( 'type' => 'date', 'name' => 'fecha', 'label' => 'Fecha' ),
			array( 'type' => 'checkbox', 'name' => 'uso_s_cobre', 'label' => 'S. Cobre' ),
			array( 'type' => 'checkbox', 'name' => 'op_p', 'label' => 'P — Sacada de plástico' ),
			array( 'type' => 'checkbox', 'name' => 'op_v', 'label' => 'V — Vaciado de agua' ),
			array( 'type' => 'number', 'name' => 'temp_real', 'label' => 'Temperatura real' ),
		) ),
	),
);
$capture = array(
	'fields' => array(
		'fecha' => '2026-09-04',
		'uso_s_cobre' => '1',
		'op_p' => '1',
		'op_v' => '', // no marcado
		'temp_real' => '25',
	),
	'contact' => array(),
	'content' => array(),
);
$vars = Forms::submission_vars( 0, $cfg, $capture );
cr_check( 'vars: fecha con día de la semana', strpos( $vars['fields'], 'Viernes 04/09/2026' ) !== false );
cr_check( 'vars: checkbox marcado → ✓', strpos( $vars['fields'], 'S. Cobre' ) !== false && strpos( $vars['fields'], '✓' ) !== false );
cr_check( 'vars: checkbox no marcado → ausente', strpos( $vars['fields'], 'Vaciado' ) === false );
cr_check( 'vars: fecha no es cruda (2026-09-04)', strpos( $vars['fields'], '>2026-09-04<' ) === false );

// ── submission_rows: parseo de fields_json ──────────────────────────────
$form_cfg = wp_json_encode( array(
	'sections' => array( array( 'title' => 'Día', 'fields' => array(
		array( 'type' => 'date', 'name' => 'fecha', 'label' => 'Fecha' ),
		array( 'type' => 'select', 'name' => 'camara', 'label' => 'Cámara' ),
		array( 'type' => 'text', 'name' => 'lote', 'label' => 'Lote' ),
		array( 'type' => 'number', 'name' => 'temp_real', 'label' => 'Temp real' ),
		array( 'type' => 'number', 'name' => 'temp_deseada', 'label' => 'Temp deseada' ),
		array( 'type' => 'checkbox', 'name' => 'uso_s_cobre', 'label' => 'S. Cobre' ),
		array( 'type' => 'number', 'name' => 'cant_s_cobre', 'label' => 'Cant S. Cobre' ),
		array( 'type' => 'checkbox', 'name' => 'op_m', 'label' => 'M' ),
		array( 'type' => 'textarea', 'name' => 'observaciones', 'label' => 'Obs' ),
	) ) ),
	'store_entries' => '1',
) );
$form_id = Forms::create( array(
	'name' => 'Registro Test', 'slug' => 'registro-test', 'form_type' => 'lead',
	'form_context' => 'generic', 'config_json' => $form_cfg, 'status' => 'active',
) );
cr_check( 'setup: form creado', $form_id > 0 );

if ( $form_id > 0 ) {
	$ingest = \Estandar\Capture\Ingestion::ingest( array(
		'source' => 'form',
		'content' => array( 'source_form_id' => (string) $form_id, 'message' => '' ),
		'contact' => array(),
		'fields' => array(
			'fecha' => '2026-09-01', 'camara' => 'Camara 1', 'lote' => 'F5',
			'temp_real' => '24', 'temp_deseada' => '26',
			'uso_s_cobre' => '1', 'cant_s_cobre' => '8.5', 'op_m' => '1',
		),
		'attribution' => array(), 'ip' => '', 'session_id' => '', 'time_on_page' => 0,
		'spam' => array(),
	) );
	cr_check( 'setup: ingesta 1 ok', ! empty( $ingest['id'] ) );
	$ingest2 = \Estandar\Capture\Ingestion::ingest( array(
		'source' => 'form',
		'content' => array( 'source_form_id' => (string) $form_id, 'message' => '' ),
		'contact' => array(),
		'fields' => array(
			'fecha' => '2026-09-04', 'camara' => 'Camara 1', 'lote' => 'F5',
			'temp_real' => '26', 'temp_deseada' => '26',
			'uso_agua_carbon' => '1', 'cant_agua_carbon' => '12', 'op_v' => '1',
		),
		'attribution' => array(), 'ip' => '', 'session_id' => '', 'time_on_page' => 0,
		'spam' => array(),
	) );
	cr_check( 'setup: ingesta 2 ok', ! empty( $ingest2['id'] ) );

	$rows = CR::submission_rows( $form_id );
	cr_check( 'rows: 2 filas', count( $rows ) === 2 );
	cr_check( 'rows: parsea campos', isset( $rows[0]['lote'] ) && $rows[0]['lote'] === 'F5' );
	cr_check( 'rows: orden por fecha ASC', (string) $rows[0]['fecha'] === '2026-09-01' && (string) $rows[1]['fecha'] === '2026-09-04' );

	// ── filter_rows ──
	$f_all = CR::filter_rows( $rows, array() );
	cr_check( 'filter: sin filtros = todas', count( $f_all ) === 2 );
	$f_cam = CR::filter_rows( $rows, array( 'camara' => 'Camara 1' ) );
	cr_check( 'filter: por cámara', count( $f_cam ) === 2 );
	$f_none = CR::filter_rows( $rows, array( 'camara' => 'Camara 9' ) );
	cr_check( 'filter: cámara inexistente → 0', count( $f_none ) === 0 );
	$f_from = CR::filter_rows( $rows, array( 'from' => '2026-09-03' ) );
	cr_check( 'filter: desde fecha', count( $f_from ) === 1 && (string) $f_from[0]['fecha'] === '2026-09-04' );
	$f_to = CR::filter_rows( $rows, array( 'to' => '2026-09-02' ) );
	cr_check( 'filter: hasta fecha', count( $f_to ) === 1 && (string) $f_to[0]['fecha'] === '2026-09-01' );
	$f_lote = CR::filter_rows( $rows, array( 'lote' => 'F5' ) );
	cr_check( 'filter: por lote', count( $f_lote ) === 2 );

	// ── chart_series ──
	$series = CR::chart_series( $rows );
	cr_check( 'chart: 2 labels', count( $series['labels'] ) === 2 );
	cr_check( 'chart: 6 datasets', count( $series['datasets'] ) === 6 );
	cr_check( 'chart: temp real ok', (float) $series['datasets'][1]['data'][1] === 26.0 );
	cr_check( 'chart: s_cobre ok', (float) $series['datasets'][2]['data'][0] === 8.5 );

	// ── temp_color ──
	cr_check( 'temp: verde en rango', CR::temp_color( '25', '26' ) !== '' && strpos( CR::temp_color( '25', '26' ), '#01651f' ) !== false );
	cr_check( 'temp: ámbar', strpos( CR::temp_color( '23', '26' ), '#7a5c00' ) !== false );
	cr_check( 'temp: rojo', strpos( CR::temp_color( '20', '26' ), '#b32d2e' ) !== false );
	cr_check( 'temp: vacío → sin color', CR::temp_color( '', '26' ) === '' );

	// ── render sin form / con form ──────────────────────────────────────
	$no_form = CR::render( array( 'form' => 0 ) );
	cr_check( 'render: sin form → mensaje', strpos( $no_form, 'falta el atributo form' ) !== false );

	// Simular operador autorizado (cookie firmada).
	$ops = \Estandar\Core\LeadsPanel::operators();
	if ( ! isset( $ops['camara_test'] ) ) {
		\Estandar\Core\LeadsPanel::save_operator( 'camara_test', 'cmar' );
	}
	$exp = time() + 600;
	$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'leads-panel';
	$sig = hash_hmac( 'sha256', 'camara_test|' . $exp, (string) $secret );
	$_COOKIE[ \Estandar\Core\LeadsPanel::AUTH_COOKIE ] = 'camara_test|' . $exp . '|' . $sig;
	$_SERVER['REQUEST_URI'] = '/registro-camara/registros/';

	$html = CR::render( array( 'form' => $form_id, 'title' => 'Registros de cámara' ) );
	cr_check( 'render: autorizado muestra tabla', strpos( $html, '<table' ) !== false && strpos( $html, 'registros.' ) !== false );
	cr_check( 'render: fila con solo día', strpos( $html, '>Viernes<' ) !== false && strpos( $html, 'Viernes 04/09/2026' ) === false );
	cr_check( 'render: botón único ver gráfico', substr_count( $html, 'class="cr-graph-btn"' ) === 1 && strpos( $html, 'Ver gráfico' ) !== false );
	cr_check( 'render: chart.js incluido', strpos( $html, 'chart.umd.min.js' ) !== false );
	cr_check( 'render: export CSV', strpos( $html, 'cr_export=csv' ) !== false );
	cr_check( 'render: export Excel', strpos( $html, 'cr_export=xlsx' ) !== false );
	cr_check( 'render: branding Estandar con logo', strpos( $html, 'estandar_logo_color.png' ) !== false && strpos( $html, 'Estandar Studio' ) !== false );

	// ── EDIT_V1: modal + botones + columna operador ──────────────────────
	cr_check( 'render: botón nuevo registro', strpos( $html, 'cr-new-btn' ) !== false && strpos( $html, 'Nuevo registro' ) !== false );
	cr_check( 'render: botones editar por fila', substr_count( $html, 'cr-edit-btn' ) >= 2 );
	cr_check( 'render: modal de edición presente', strpos( $html, 'cr-modal' ) !== false && strpos( $html, 'cr_nonce' ) !== false );
	cr_check( 'render: columna Operador', strpos( $html, '>Operador</th>' ) !== false );

	// Alta manual (POST nuevo) con operador de sesión.
	$nonce = wp_create_nonce( 'cr_edit_' . $form_id );
	$_POST = array(
		'cr_action' => 'new', 'cr_nonce' => $nonce,
		'cr_fecha' => '2026-09-06', 'cr_local' => 'Chepica', 'cr_camara' => 'Camara 1',
		'cr_lote' => 'F5', 'cr_temp_deseada' => '26', 'cr_temp_real' => '25.5',
		'cr_uso_s_cobre' => '1', 'cr_cant_s_cobre' => '9', 'cr_op_m' => '1',
		'cr_observaciones' => 'alta manual',
	);
	$res = CR::handle_edit_post( $form_id, $_POST );
	cr_check( 'edit: alta manual ok', ( $res['type'] ?? '' ) === 'ok' );
	$_POST = array();
	$rows3 = CR::submission_rows( $form_id );
	cr_check( 'edit: alta agrega fila', count( $rows3 ) === 3 );
	$op_row = null;
	foreach ( $rows3 as $r ) { if ( (string) $r['fecha'] === '2026-09-06' ) { $op_row = $r; } }
	cr_check( 'edit: operador capturado en alta', ! is_null( $op_row ) && (string) $op_row['operator_name'] === 'camara_test' );

	// Edición de un registro existente (update fields_json + operador).
	$target = null;
	foreach ( $rows3 as $r ) { if ( (string) $r['fecha'] === '2026-09-01' ) { $target = $r; } }
	if ( $target ) {
		$_POST = array(
			'cr_action' => 'edit', 'cr_id' => (int) $target['id'], 'cr_nonce' => $nonce,
			'cr_fecha' => '2026-09-01', 'cr_local' => 'San Fernando', 'cr_camara' => 'Camara 1',
			'cr_lote' => 'F5', 'cr_temp_deseada' => '26', 'cr_temp_real' => '24.8',
			'cr_uso_agua_carbon' => '1', 'cr_cant_agua_carbon' => '11', 'cr_op_v' => '1',
			'cr_observaciones' => 'editada',
		);
		$res2 = CR::handle_edit_post( $form_id, $_POST );
		$_POST = array();
		cr_check( 'edit: editar ok', ( $res2['type'] ?? '' ) === 'ok' );
		$check = Forms::get_submission( (int) $target['id'] );
		$cf = json_decode( (string) ( $check['fields_json'] ?? '{}' ), true );
		cr_check( 'edit: campos actualizados', (string) ( $cf['local'] ?? '' ) === 'San Fernando' && (string) ( $cf['temp_real'] ?? '' ) === '24.8' );
		cr_check( 'edit: operador actualizado', (string) ( $check['operator_name'] ?? '' ) === 'camara_test' );
	}

	// ── MULTI_FORM_V1: visor multi-form (join fecha+camara, agrupación) ─────
	cr_check( 'multi: parse_join default', CR::parse_join( '' ) === array( 'fecha', 'camara' ) );
	cr_check( 'multi: parse_join explícito', CR::parse_join( 'fecha, camara' ) === array( 'fecha', 'camara' ) );
	cr_check( 'multi: join_key normaliza', CR::join_key( array( 'fecha' => '2026-09-01 ', 'camara' => 'Camara 1' ), array( 'fecha', 'camara' ) ) === '2026-09-01||camara 1' );

	// MULTI_FORM_V1: fusión de "otro_<campo>" en su campo padre.
	cr_check( 'merge: companion con padre', CR::is_other_companion( 'otro_local', array( 'local' => array(), 'otro_local' => array() ) ) );
	cr_check( 'merge: sin padre no es companion', ! CR::is_other_companion( 'otro_zzz', array( 'local' => array() ) ) );
	$sdef = array( 'type' => 'select', 'options' => array( array( 'value' => 'otro', 'label' => 'Otro' ), array( 'value' => 'chepica', 'label' => 'Chepica' ) ) );
	cr_check( 'merge: value_is_other true', CR::value_is_other( $sdef, 'otro' ) );
	cr_check( 'merge: value_is_other false', ! CR::value_is_other( $sdef, 'chepica' ) );
	cr_check( 'merge: value_is_other vacío', ! CR::value_is_other( $sdef, '' ) );

	$detail_cfg = wp_json_encode( array(
		'sections' => array( array( 'title' => 'Lote', 'fields' => array(
			array( 'type' => 'date', 'name' => 'fecha', 'label' => 'Fecha' ),
			array( 'type' => 'select', 'name' => 'camara', 'label' => 'Cámara' ),
			array( 'type' => 'text', 'name' => 'lote', 'label' => 'Lote' ),
			array( 'type' => 'checkbox', 'name' => 'uso_s_cobre', 'label' => 'S. Cobre' ),
			array( 'type' => 'number', 'name' => 'cant_s_cobre', 'label' => 'Cant S. Cobre' ),
			array( 'type' => 'checkbox', 'name' => 'op_p', 'label' => 'P' ),
		) ) ),
		'store_entries' => '1',
	) );
	$detail_id = Forms::create( array(
		'name' => 'Registro Lotes Test', 'slug' => 'registro-lotes-test', 'form_type' => 'lead',
		'form_context' => 'generic', 'config_json' => $detail_cfg, 'status' => 'active',
	) );
	cr_check( 'multi: form detail creado', $detail_id > 0 );

	if ( $detail_id > 0 ) {
		foreach ( array(
			array( 'fecha' => '2026-09-01', 'camara' => 'Camara 1', 'lote' => 'F5', 'uso_s_cobre' => '1', 'cant_s_cobre' => '8', 'op_p' => '1' ),
			array( 'fecha' => '2026-09-04', 'camara' => 'Camara 1', 'lote' => 'F6', 'uso_s_cobre' => '0', 'cant_s_cobre' => '', 'op_p' => '1' ),
		) as $dfields ) {
			\Estandar\Capture\Ingestion::ingest( array(
				'source' => 'form',
				'content' => array( 'source_form_id' => (string) $detail_id, 'message' => '' ),
				'contact' => array(), 'fields' => $dfields,
				'attribution' => array(), 'ip' => '', 'session_id' => '', 'time_on_page' => 0, 'spam' => array(),
			) );
		}
		$all = CR::submission_rows_multi( array( $form_id, $detail_id ) );
		cr_check( 'multi: submission_rows_multi marca _form', count( array_filter( $all, static function ( $r ) use ( $detail_id ) { return (int) ( $r['_form'] ?? 0 ) === $detail_id; } ) ) === 2 );

		$_GET = array( 'from' => '2026-09-01', 'to' => '2026-09-04' );
		$groups = CR::grouped_filtered( array( $form_id, $detail_id ), $form_id, $detail_id, array( 'fecha', 'camara' ), $_GET );
		cr_check( 'multi: agrupa por fecha+camara (2 grupos)', count( $groups ) === 2 );
		cr_check( 'multi: cada grupo con su lote', count( $groups[0]['details'] ) === 1 && count( $groups[1]['details'] ) === 1 );

		$flat = CR::flat_rows_multi( $groups, $form_id, $detail_id );
		cr_check( 'multi: flat rows = nº lotes', count( $flat['rows'] ) === 2 );
		cr_check( 'multi: flat combina base+detail', (string) $flat['rows'][0]['lote'] === 'F5' && (string) $flat['rows'][0]['temp_real'] === '24.8' );

		$mhtml = CR::render( array(
			'forms' => $form_id . ',' . $detail_id, 'base' => $form_id, 'detail' => $detail_id,
			'join' => 'fecha,camara', 'group' => '1', 'title' => 'Resumen cámara',
		) );
		cr_check( 'multi: render agrupado', strpos( $mhtml, '2 cámaras-día' ) !== false && substr_count( $mhtml, 'Sin lotes registrados' ) === 0 );
		cr_check( 'multi: render muestra lotes', strpos( $mhtml, 'F5' ) !== false && strpos( $mhtml, 'F6' ) !== false );
		cr_check( 'multi: render export multi', strpos( $mhtml, 'cr_forms=' ) !== false && strpos( $mhtml, 'cr_export=xlsx' ) !== false );
		cr_check( 'multi: render gráfico temperatura', strpos( $mhtml, 'chart.umd.min.js' ) !== false && strpos( $mhtml, 'Evolución de la temperatura' ) !== false );

		// tabla UNIFICADA: una sola tabla (antes: una por grupo).
		cr_check( 'multi: tabla unificada (1 table)', substr_count( $mhtml, '<table' ) === 1 );
		cr_check( 'multi: orden por columnas (SORT_V1)', strpos( $mhtml, 'cr-sort-mark' ) !== false );

		// gráfico por lote: un dataset por lote.
		$cm = CR::chart_series_multi(
			array(
				array( 'fecha' => '2026-09-04', 'lote' => 'F6', 'temp_real' => '26.1' ),
				array( 'fecha' => '2026-09-01', 'lote' => 'F5', 'temp_real' => '24.8' ),
				array( 'fecha' => '2026-09-04', 'lote' => 'F5', 'temp_real' => '25.5' ),
			),
			'lote'
		);
		cr_check( 'multi: chart por lote = 2 datasets', count( $cm['datasets'] ) === 2 );
		cr_check( 'multi: chart labels ordenados', $cm['labels'] === array( '2026-09-01', '2026-09-04' ) );
		cr_check( 'multi: chart lote alineado por fecha', (float) $cm['datasets'][0]['data'][1] === 25.5 && $cm['datasets'][0]['data'][0] === 24.8 );
		cr_check( 'multi: chart sin dato → null', $cm['datasets'][1]['data'][0] === null );

		Forms::trash( $detail_id );
	}

	// ── NOTIFY_TO_OPERATOR_V1: notificación al operador + CC configurables ──
	if ( $target ) {
		// Interceptar wp_mail para capturar destinatarios sin enviar.
		$GLOBALS['__cr_mail_captured'] = array();
		add_filter( 'pre_wp_mail', static function ( $null, $atts ) {
			$GLOBALS['__cr_mail_captured'][] = $atts;
			return true;
		}, 10, 2 );

		$cfg_n = array(
			'name' => 'Registro Diario de Cámara',
			'send_email' => '1',
			'notify_emails' => 'gestion@cliente2.example.com',
			'notify_cc_admin' => '0',
			'autoresponse' => array( 'enabled' => '0' ),
			'form_funnel_mode' => 'simple',
		);
		$cap_n = array(
			'fields' => array( 'fecha' => '2026-09-04', 'camara' => 'Camara 1', 'lote' => 'F5' ),
			'contact' => array(), 'content' => array( 'source_form_id' => (string) $form_id, 'message' => '' ),
			'email_normalized' => '',
			'operator_email' => 'camara@cliente2.example.com',
			'operator_name' => 'camara',
			'id' => (int) $target['id'],
		);
		$GLOBALS['__test_transients'] = array();
		$r_n = Forms::notify_submission( $form_id, $cfg_n, $cap_n );
		cr_check( 'notify: con operador → ok', ! empty( $r_n['ok'] ) );
		$sent = $GLOBALS['__cr_mail_captured'];
		$to0 = (array) ( $sent[0]['to'] ?? array() );
		cr_check( 'notify: To es el operador', in_array( 'camara@cliente2.example.com', $to0, true ) );
		// gestión y admin como copia en el body/headers (cc se mete en headers).
		$all_headers = is_array( $sent[0]['headers'] ?? null ) ? implode( ' ', $sent[0]['headers'] ) : (string) ( $sent[0]['headers'] ?? '' );
		cr_check( 'notify: gestión en CC', strpos( $all_headers, 'gestion@cliente2.example.com' ) !== false );
		remove_all_filters( 'pre_wp_mail' );
		$GLOBALS['__cr_mail_captured'] = array();
	}

	// ── export bytes CSV ──
	$csv = CR::export_bytes( 'csv', array( array( 'fecha' => '2026-09-04', 'camara' => 'Camara 1' ) ), array( 'fecha' => 'Fecha', 'camara' => 'Cámara' ) );
	cr_check( 'export: csv contiene datos', strpos( $csv['bytes'], '2026-09-04' ) !== false && $csv['ext'] === 'csv' );

	// Limpiar.
	Forms::trash( $form_id );
	unset( $_COOKIE[ \Estandar\Core\LeadsPanel::AUTH_COOKIE ] );
}

$wpdb->query( 'ROLLBACK' );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
