<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CamaraRegistros — visor de registros diarios de cámara (REGISTROS_V1, 2026-09-01).
 *
 * Shortcode: [estandar_camara_registros form="ID"]
 *   Muestra todos los envíos (submissions) del form como tabla "tipo Excel" con
 *   filtros (Local, Cámara, Lote, rango de fechas), export CSV/Excel y, con un
 *   click en una fila, un gráfico Chart.js de la evolución en el tiempo del
 *   objeto auditado (temperaturas + cantidades de productos por lote/cámara).
 *
 * Acceso: protegido con el gate de operadores (PIN/magic link) — mismo motor
 * que LeadsPanel. Un operador autenticado entra sin fricción.
 */
class CamaraRegistros
{
	const SHORTCODE = 'estandar_camara_registros';

	/** Campos del registro diario (name → label) para tabla/export/gráfico. */
	const COLUMNS = array(
		'fecha'               => 'Fecha',
		'local'               => 'Local',
		'otro_local'          => 'Otro local',
		'camara'              => 'Cámara',
		'lote'                => 'Lote',
		'temp_deseada'        => 'Temp. deseada (°C)',
		'temp_real'           => 'Temp. real (°C)',
		'uso_s_cobre'         => 'S. Cobre',
		'cant_s_cobre'        => 'S. Cobre (g)',
		'uso_agua_rapida'     => 'Agua rápida',
		'cant_agua_rapida'    => 'Agua rápida (g)',
		'uso_agua_carbon'     => 'Agua carbón',
		'cant_agua_carbon'    => 'Agua carbón (g)',
		'uso_acido_sudel'     => 'Ácido súdel',
		'cant_acido_sudel'    => 'Ácido súdel (g)',
		'op_p'                => 'P',
		'op_m'                => 'M',
		'op_v'                => 'V',
		'op_s'                => 'S',
		'observaciones'       => 'Observaciones',
		'fecha_inicio_camara' => 'Inicio cámara',
		'operator_name'       => 'Operador',
	);

	/**
	 * ADAPT_V1 (2026-09-21): columnas del visor derivadas de los campos REALES
	 * del form. Si el form cambia (agregar/quitar/renombrar un campo — p.ej. un
	 * campo "Hora"), el visor se adapta solo, sin tocar código. Excluye
	 * heading/html/hidden. Añade `operator_name` al final (columna DB).
	 *
	 * @return array name → array( label, type, required, options )
	 */
	public static function form_fields( int $form_id ): array
	{
		$row = Forms::get( $form_id );
		if ( ! is_array( $row ) ) {
			return array();
		}
		$config = json_decode( (string) ( $row['config_json'] ?? '' ), true );
		if ( ! is_array( $config ) ) {
			$config = array();
		}
		$out = array();
		foreach ( (array) Forms::renderable_fields( $config ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$name = sanitize_key( (string) ( $f['name'] ?? '' ) );
			$type = strtolower( (string) ( $f['type'] ?? 'text' ) );
			if ( $name === '' || in_array( $type, array( 'heading', 'html', 'hidden' ), true ) ) {
				continue;
			}
			$out[ $name ] = array(
				'label'    => (string) ( $f['label'] ?? $name ),
				'type'     => $type,
				'required' => ! empty( $f['required'] ),
				'options'  => isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array(),
			);
		}
		if ( $out ) {
			$out['operator_name'] = array( 'label' => 'Operador', 'type' => 'text', 'required' => false, 'options' => array() );
		}
		return $out;
	}

	/** Nombres editables por el modal: campos del form + legado (retrocompatibilidad). */
	public static function editable_names( int $form_id ): array
	{
		$names = array_keys( self::form_fields( $form_id ) );
		foreach ( array_keys( self::COLUMNS ) as $k ) {
			$names[] = $k;
		}
		$names = array_values( array_unique( $names ) );
		return array_values( array_diff( $names, array( 'operator_name', 'operator_email' ) ) );
	}

	public static function init(): void
	{
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_stream_export' ) );
	}

	/**
	 * EXPORT_EARLY_V1 (2026-09-21): sirve el export CSV/XLSX ANTES de que el
	 * tema emita HTML. El shortcode corre a mitad de página; si el export se
	 * hacía allí, la respuesta quedaba envuelta en el HTML del tema (cabeceras
	 * de descarga correctas pero cuerpo HTML) → archivo corrupto.
	 * Requiere sesión de operador (misma política que el visor).
	 */
	public static function maybe_stream_export(): void
	{
		if ( empty( $_GET['cr_export'] ) ) {
			return;
		}
		$format = (string) $_GET['cr_export'];
		if ( ! in_array( $format, array( 'csv', 'xlsx' ), true ) ) {
			return;
		}
		// MULTI_FORM_V1: export en modo multi-form (planilla plana).
		$forms_attr = trim( (string) ( $_GET['cr_forms'] ?? '' ) );
		$detail_id  = absint( (int) ( $_GET['cr_detail'] ?? 0 ) );
		if ( $forms_attr !== '' && $detail_id > 0 ) {
			$ids    = array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $forms_attr ) ) ) );
			$base_id = absint( (int) ( $_GET['cr_base'] ?? 0 ) );
			if ( $base_id <= 0 && $ids ) {
				$base_id = (int) $ids[0];
			}
			if ( ! $ids || $base_id <= 0 || ! is_array( Forms::get( $base_id ) ) || ! is_array( Forms::get( $detail_id ) ) ) {
				return;
			}
			if ( self::gate_required( $base_id )
				&& class_exists( '\Estandar\Core\LeadsPanel' )
				&& ! \Estandar\Core\LeadsPanel::authorized() ) {
				return;
			}
			$join_fields = self::parse_join( (string) ( $_GET['cr_join'] ?? '' ) );
			self::stream_export_multi( $ids, $base_id, $detail_id, $join_fields, $format, $_GET );
			exit;
		}
		if ( empty( $_GET['cr_form'] ) ) {
			return;
		}
		$form_id = absint( (int) $_GET['cr_form'] );
		if ( $form_id <= 0 || ! is_array( Forms::get( $form_id ) ) ) {
			return;
		}
		// GATE_FROM_SETTINGS_V1: export libre si el form no exige protección.
		if ( self::gate_required( $form_id )
			&& class_exists( '\Estandar\Core\LeadsPanel' )
			&& ! \Estandar\Core\LeadsPanel::authorized() ) {
			return; // sin sesión: que la página muestre el gate de operador.
		}
		self::stream_export( $form_id, $format, $_GET );
		exit;
	}

	/**
	 * GATE_FROM_SETTINGS_V1: ¿el visor/export exige acceso de operador?
	 * Sigue el ajuste "Acceso protegido" (gate_enabled) del form.
	 */
	public static function gate_required( int $form_id ): bool
	{
		$form = $form_id > 0 ? Forms::get( $form_id ) : null;
		if ( ! is_array( $form ) ) {
			return false;
		}
		$cfg = json_decode( (string) ( $form['config_json'] ?? '' ), true );
		$cfg = is_array( $cfg ) ? $cfg : array();
		return ! empty( $cfg['gate_enabled'] ) && (string) $cfg['gate_enabled'] !== '0';
	}

	public static function render( array $atts = array() ): string
	{
		if ( ! class_exists( '\Estandar\Core\LeadsPanel' ) || ! class_exists( '\Estandar\Core\LeadsPanel' ) ) {
			return '<div style="color:#b32d2e;">El visor requiere estandar-core.</div>';
		}
		$LP = '\Estandar\Core\LeadsPanel';

		$atts = shortcode_atts( array(
			'form'   => 0,
			'title'  => 'Registros de cámara',
			'mail_label' => 'Registros de Cámara',
			// GATE_FROM_SETTINGS_V1: auto (sigue el ajuste del form) | on | off.
			'gate'   => 'auto',
			// MULTI_FORM_V1 (2026-09-22): visor multi-form genérico. Ej.:
			// [estandar_camara_registros forms="A,B" base="A" detail="B"
			//  join="fecha,camara" group="1"]
			// Sin `detail` → modo actual de un solo form (retrocompatible).
			'forms'  => '',
			'base'   => 0,
			'detail' => 0,
			'join'   => '',
			'group'  => '',
		), (array) $atts, self::SHORTCODE );

		$form_id    = absint( (int) $atts['form'] );
		$base_id    = absint( (int) $atts['base'] );
		$detail_id  = absint( (int) $atts['detail'] );
		$forms_attr = trim( (string) $atts['forms'] );
		$is_multi   = ( $forms_attr !== '' && $detail_id > 0 );

		if ( $is_multi ) {
			$form_ids = array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', $forms_attr ) ) ) );
			if ( $base_id <= 0 && $form_ids ) {
				$base_id = (int) $form_ids[0];
			}
			if ( ! $form_ids ) {
				$form_ids = array_values( array_filter( array( $base_id, $detail_id ) ) );
			}
			if ( $base_id <= 0 || ! is_array( Forms::get( $base_id ) ) || ! is_array( Forms::get( $detail_id ) ) ) {
				return '<div style="color:#b32d2e;">CamaraRegistros: base/detail inválidos.</div>';
			}
			// El gate se rige por el form "base" (cabecera).
			$form_id = $base_id;
		} else {
			if ( $form_id <= 0 ) {
				return '<div style="color:#b32d2e;">CamaraRegistros: falta el atributo form="ID".</div>';
			}
			$form = Forms::get( $form_id );
			if ( ! is_array( $form ) ) {
				return '<div style="color:#b32d2e;">El form de registros no existe.</div>';
			}
		}

		// GATE_FROM_SETTINGS_V1 (2026-09-21): la protección del visor sigue el
		// ajuste "Acceso protegido" del form (antes era fija). El atributo
		// `gate="on|off"` permite forzarla puntualmente.
		$gate_attr = strtolower( trim( (string) $atts['gate'] ) );
		if ( $gate_attr === 'on' ) {
			$gate_on = true;
		} elseif ( $gate_attr === 'off' ) {
			$gate_on = false;
		} else {
			$gate_on = self::gate_required( $form_id );
		}

		// ── Export CSV/Excel: lo sirve ANTES del render → maybe_stream_export()
		// (template_redirect, EXPORT_EARLY_V1). Aquí ya NO se hace: si el export
		// se emitiera a mitad de página quedaría envuelto en el HTML del tema
		// (archivo corrupto) y además sin exigir sesión de operador.

		// ── Gate de operador: login si no autorizado (solo si la protección
		// está activa en el ajuste del form / atributo). ──────────────────
		if ( $gate_on ) {
			$return_url = self::current_page_url();
			$result = $LP::process_gate_post( $return_url, $return_url, 'es_og', array(
				'label'  => (string) $atts['mail_label'],
				'subject' => 'Acceso al ' . (string) $atts['mail_label'] . ' — ' . get_bloginfo( 'name' ),
				'button'  => 'Abrir ' . (string) $atts['mail_label'],
			) );
			if ( ! empty( $result['redirect'] ) ) {
				nocache_headers();
				wp_safe_redirect( $result['redirect'] );
				exit;
			}
			if ( ! $LP::authorized() ) {
				$notice      = (string) ( $result['notice'] ?? '' );
				$notice_type = (string) ( $result['notice_type'] ?? 'info' );
				return $LP::render_gate_login( $return_url, $return_url, (string) $atts['title'], $notice, $notice_type, 'es_og' );
			}
		}

		// ── MULTI_FORM_V1: modo multi-form (cabecera `base` + filas `detail`). ──
		if ( $is_multi ) {
			$join_fields = self::parse_join( (string) $atts['join'] );
			return self::render_multi( $form_ids, $base_id, $detail_id, $join_fields, (string) $atts['title'] );
		}

		// ── EDIT_V1: alta manual / edición de registros (POST con nonce). ──
		$edit_notice = self::handle_edit_post( $form_id, $_POST );

		// ── Datos: submissions + filtros. ─────────────────────────────────
		$rows   = self::submission_rows( $form_id );
		$filters = self::filter_rows( $rows, $_GET );

		// Opciones de filtro (dinámicas desde los datos).
		$locals  = self::distinct( $rows, 'local' );
		$camaras = self::distinct( $rows, 'camara' );
		$lotes   = self::distinct( $rows, 'lote' );

		return self::render_view( $form_id, $filters, $rows, $locals, $camaras, $lotes, (string) $atts['title'], $edit_notice );
	}

	/**
	 * EDIT_V1: procesa el POST del visor — "nuevo" (alta manual) o "editar"
	 * (actualiza fields_json + operador). Devuelve array [type, message] o ''.
	 */
	public static function handle_edit_post( int $form_id, array $post ): array
	{
		if ( empty( $post['cr_action'] ) ) {
			return array();
		}
		$action = sanitize_key( (string) $post['cr_action'] );
		$ok     = wp_verify_nonce( (string) ( $post['cr_nonce'] ?? '' ), 'cr_edit_' . $form_id );
		if ( ! $ok ) {
			return array( 'type' => 'error', 'message' => 'Sesión expirada. Intenta de nuevo.' );
		}

		$fields = array();
		$defs   = self::form_fields( $form_id );
		foreach ( self::editable_names( $form_id ) as $k ) {
			$fields[ $k ] = isset( $post[ 'cr_' . $k ] ) ? sanitize_text_field( (string) $post[ 'cr_' . $k ] ) : '';
		}
		// Normalizar checkboxes: "1" si vino marcado, "0" si no (según el tipo
		// real del campo, con respaldo del set legado).
		$legacy_cb = array( 'uso_s_cobre', 'uso_agua_rapida', 'uso_agua_carbon', 'uso_acido_sudel', 'op_p', 'op_m', 'op_v', 'op_s' );
		foreach ( $fields as $k => $v ) {
			$is_cb = ( isset( $defs[ $k ]['type'] ) && $defs[ $k ]['type'] === 'checkbox' ) || in_array( $k, $legacy_cb, true );
			if ( $is_cb ) {
				$fields[ $k ] = (string) ( $v === '1' ? '1' : '0' );
			}
		}
		if ( (string) ( $fields['fecha'] ?? '' ) === '' ) {
			return array( 'type' => 'error', 'message' => 'La fecha es obligatoria.' );
		}

		// Operador que hace el cambio (sesión actual).
		$LP = '\Estandar\Core\LeadsPanel';
		$op_name  = (string) $LP::current_operator();
		$op_email = '';
		if ( $op_name === '' && method_exists( $LP, 'magic_session_email' ) ) {
			$op_email = (string) $LP::magic_session_email();
			$op_name  = $op_email !== '' ? $op_email : '';
		}

		$sub_id = (int) ( $post['cr_id'] ?? 0 );

		if ( $action === 'new' ) {
			$raw = array(
				'source' => 'form',
				'content' => array( 'source_form_id' => (string) $form_id, 'message' => '' ),
				'contact' => array(),
				'fields'  => $fields,
				'attribution' => array(),
				'ip'      => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
				'session_id' => '', 'time_on_page' => 0,
				'spam'    => array( 'honeypot_filled' => false ),
				'operator_name'  => $op_name,
				'operator_email' => $op_email,
			);
			$r = Ingestion::ingest( $raw );
			return ! empty( $r['id'] )
				? array( 'type' => 'ok', 'message' => 'Registro agregado (#' . (int) $r['id'] . ').' )
				: array( 'type' => 'error', 'message' => 'No se pudo agregar el registro.' );
		}

		if ( $action === 'edit' && $sub_id > 0 ) {
			$done = Forms::update_submission_fields( $sub_id, $fields, $op_name, $op_email );
			return $done
				? array( 'type' => 'ok', 'message' => 'Registro #' . $sub_id . ' actualizado.' )
				: array( 'type' => 'error', 'message' => 'No se pudo actualizar el registro.' );
		}

		return array();
	}

	/** Lee las submissions del form y las transforma a filas planas. */
	public static function submission_rows( int $form_id ): array
	{
		$subs = Forms::submissions( $form_id, 10000, 0 );
		$out  = array();
		// ADAPT_V1: columnas derivadas de los campos del form (fallback legado).
		$names = array_keys( self::form_fields( $form_id ) );
		if ( ! $names ) {
			$names = array_keys( self::COLUMNS );
		}
		// Columnas que NO son campos del form (vienen de la fila, no de fields_json).
		$row_cols = array( 'operator_name', 'operator_email' );
		foreach ( (array) $subs as $sub ) {
			$fields = json_decode( (string) ( $sub['fields_json'] ?? '{}' ), true );
			$fields = is_array( $fields ) ? $fields : array();
			$row = array(
				'id'             => (int) ( $sub['id'] ?? 0 ),
				'created_at'     => (string) ( $sub['created_at'] ?? '' ),
			);
			foreach ( $names as $k ) {
				$row[ $k ] = (string) ( $fields[ $k ] ?? '' );
			}
			// OPERATOR_V1: operador desde la columna DB (no del fields_json).
			foreach ( $row_cols as $rc ) {
				$row[ $rc ] = (string) ( $sub[ $rc ] ?? '' );
			}
			$out[] = $row;
		}
		// Orden: por fecha de registro (campo fecha) ASC para el gráfico temporal.
		usort( $out, static function ( $a, $b ) {
			return strcmp( (string) ( $a['fecha'] ?? '' ), (string) ( $b['fecha'] ?? '' ) );
		} );
		return $out;
	}

	/** Aplica filtros (GET) sobre las filas. */
	public static function filter_rows( array $rows, array $get ): array
	{
		$local    = sanitize_text_field( (string) ( $get['local'] ?? '' ) );
		$camara   = sanitize_text_field( (string) ( $get['camara'] ?? '' ) );
		$lote     = sanitize_text_field( (string) ( $get['lote'] ?? '' ) );
		$from     = sanitize_text_field( (string) ( $get['from'] ?? '' ) );
		$to       = sanitize_text_field( (string) ( $get['to'] ?? '' ) );
		$from_ts  = $from !== '' ? strtotime( $from ) : 0;
		$to_ts    = $to !== '' ? strtotime( $to ) : 0;

		return array_values( array_filter( $rows, static function ( $r ) use ( $local, $camara, $lote, $from_ts, $to_ts ) {
			if ( $local !== '' && (string) ( $r['local'] ?? '' ) !== $local ) {
				return false;
			}
			if ( $camara !== '' && (string) ( $r['camara'] ?? '' ) !== $camara ) {
				return false;
			}
			if ( $lote !== '' && stripos( (string) ( $r['lote'] ?? '' ), $lote ) === false ) {
				return false;
			}
			$ts = strtotime( (string) ( $r['fecha'] ?? '' ) );
			if ( $from_ts && $ts && $ts < $from_ts ) {
				return false;
			}
			if ( $to_ts && $ts && $ts > $to_ts ) {
				return false;
			}
			return true;
		} ) );
	}

	/** Valores únicos de una columna (para selects de filtro). */
	public static function distinct( array $rows, string $col ): array
	{
		$set = array();
		foreach ( $rows as $r ) {
			$v = trim( (string) ( $r[ $col ] ?? '' ) );
			if ( $v !== '' ) {
				$set[ $v ] = $v;
			}
		}
		ksort( $set );
		return array_values( $set );
	}

	/** Descarga directa CSV/Excel de las filas (todas o filtradas). */
	public static function stream_export( int $form_id, string $format, array $get ): void
	{
		$rows = self::filter_rows( self::submission_rows( $form_id ), $get );
		$e    = self::export_bytes( $format, $rows, self::export_columns( $form_id ) );
		$filename = 'registro-camara-' . gmdate( 'Ymd-His' ) . '.' . $e['ext'];
		nocache_headers();
		header( 'Content-Type: ' . $e['mime'] );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( (string) $e['bytes'] ) );
		echo $e['bytes']; // phpcs:ignore
		exit;
	}

	/**
	 * Mapa name→label para export (ADAPT_V1): derivado de los campos del form.
	 * Fallback al mapa legado si el form no tiene secciones.
	 */
	public static function export_columns( int $form_id ): array
	{
		$columns = array();
		foreach ( self::form_fields( $form_id ) as $name => $d ) {
			$columns[ $name ] = (string) ( $d['label'] ?? $name );
		}
		return $columns ? $columns : self::COLUMNS;
	}

	/**
	 * Genera bytes de export. `$columns` es un mapa name→label; las filas llevan
	 * claves internas (name) y se convierten a filas con claves=label para el
	 * motor de export (LeadsPanel usa el label como header y clave de celda).
	 */
	public static function export_bytes( string $format, array $rows, array $columns ): array
	{
		$labels = array_values( $columns );
		$export = array();
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $columns as $name => $label ) {
				$line[ $label ] = (string) ( $r[ $name ] ?? '' );
			}
			$export[] = $line;
		}
		if ( $format === 'xlsx' ) {
			return array( 'bytes' => \Estandar\Core\LeadsPanel::export_xlsx( $export, $labels ), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ext' => 'xlsx' );
		}
		return array( 'bytes' => \Estandar\Core\LeadsPanel::export_csv( $export, $labels ), 'mime' => 'text/csv', 'ext' => 'csv' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// MULTI_FORM_V1 (2026-09-22) — visor multi-form genérico.
	//
	// Une los envíos de DOS forms (cabecera `base` + filas `detail`) por un
	// conjunto de campos con valores idénticos (`join`, p.ej. fecha+camara) y
	// los presenta agrupados. Es genérico: cualquier sitio puede reutilizarlo
	// con su propio shortcode; Guillaume es solo configuración.
	// ─────────────────────────────────────────────────────────────────────

	/** Campos de join desde el atributo `join` (default fecha,camara). */
	public static function parse_join( string $raw ): array
	{
		$parts = array_values( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', $raw ) ) ) );
		if ( ! $parts ) {
			$parts = array( 'fecha', 'camara' );
		}
		return $parts;
	}

	/**
	 * Une las submissions de varios forms en una lista plana. Cada fila lleva
	 * `_form` con el id de origen (para separar base/detail).
	 */
	public static function submission_rows_multi( array $form_ids ): array
	{
		$out = array();
		foreach ( $form_ids as $fid ) {
			foreach ( self::submission_rows( (int) $fid ) as $r ) {
				$r['_form'] = (int) $fid;
				$out[]      = $r;
			}
		}
		return $out;
	}

	/** Clave de join normalizada (minúsculas + sin espacios extremos). */
	public static function join_key( array $row, array $fields ): string
	{
		$parts = array();
		foreach ( $fields as $f ) {
			$parts[] = strtolower( trim( (string) ( $row[ $f ] ?? '' ) ) );
		}
		return implode( '||', $parts );
	}

	/** Definiciones de campos base+detail (dedup por nombre; base manda). */
	public static function form_fields_multi( int $base_id, int $detail_id ): array
	{
		$out = self::form_fields( $base_id );
		foreach ( self::form_fields( $detail_id ) as $n => $d ) {
			if ( ! isset( $out[ $n ] ) ) {
				$out[ $n ] = $d;
			}
		}
		return $out;
	}

	/**
	 * Construye los grupos: cada base-row (cabecera) con sus detail-rows hijas
	 * (mismo join key). Las detail huérfanas (sin base) forman grupo propio.
	 */
	public static function group_rows( array $base_rows, array $detail_rows, array $join_fields ): array
	{
		$groups = array();
		$index  = array();
		foreach ( $base_rows as $br ) {
			$k = self::join_key( $br, $join_fields );
			if ( ! isset( $index[ $k ] ) ) {
				$index[ $k ] = count( $groups );
				$groups[]    = array( 'base' => $br, 'details' => array() );
			} else {
				$groups[ $index[ $k ] ]['base'] = $br;
			}
		}
		foreach ( $detail_rows as $dr ) {
			$k = self::join_key( $dr, $join_fields );
			if ( ! isset( $index[ $k ] ) ) {
				$index[ $k ] = count( $groups );
				$groups[]    = array( 'base' => array(), 'details' => array() );
			}
			$groups[ $index[ $k ] ]['details'][] = $dr;
		}
		return $groups;
	}

	/** Grupos filtrados (base filtra sin lote; detail sin local). */
	public static function grouped_filtered( array $form_ids, int $base_id, int $detail_id, array $join_fields, array $get ): array
	{
		$all         = self::submission_rows_multi( $form_ids );
		$base_rows   = array_values( array_filter( $all, static function ( $r ) use ( $base_id ) { return (int) ( $r['_form'] ?? 0 ) === $base_id; } ) );
		$detail_rows = array_values( array_filter( $all, static function ( $r ) use ( $detail_id ) { return (int) ( $r['_form'] ?? 0 ) === $detail_id; } ) );
		$get_base    = $get;
		unset( $get_base['lote'] );
		$get_detail  = $get;
		unset( $get_detail['local'] );
		return self::group_rows(
			self::filter_rows( $base_rows, $get_base ),
			self::filter_rows( $detail_rows, $get_detail ),
			$join_fields
		);
	}

	/** ¿El campo `$name` es un compañero "otro_<campo>" con padre existente? */
	public static function is_other_companion( string $name, array $defs ): bool
	{
		return ( strpos( $name, 'otro_' ) === 0 && isset( $defs[ substr( $name, 5 ) ] ) );
	}

	/** ¿El valor seleccionado corresponde a la opción "Otro"/"Otra" del campo? */
	public static function value_is_other( array $def, string $val ): bool
	{
		if ( $val === '' ) { return false; }
		$label = Forms::field_option_label( $def, $val );
		return stripos( (string) $label, 'otro' ) === 0;
	}

	/** Aplana los grupos a filas (una por lote) para export tipo Excel. */
	public static function flat_rows_multi( array $groups, int $base_id, int $detail_id ): array
	{
		$base_defs   = self::form_fields( $base_id );
		$detail_defs = self::form_fields( $detail_id );

		// Unión completa de campos (para construir las filas).
		$all_defs = $base_defs;
		foreach ( $detail_defs as $n => $d ) {
			if ( ! isset( $all_defs[ $n ] ) ) { $all_defs[ $n ] = $d; }
		}
		$all_names = array_keys( $all_defs );

		// Columnas visibles: sin los "otro_<campo>" (se fusionan en su campo).
		$names = array();
		foreach ( $all_names as $n ) {
			if ( self::is_other_companion( $n, $all_defs ) ) { continue; }
			$names[] = $n;
		}

		$rows = array();
		foreach ( $groups as $g ) {
			$base    = (array) ( $g['base'] ?? array() );
			$details = (array) ( $g['details'] ?? array() );
			if ( ! $details ) {
				$details = array( array() );
			}
			foreach ( $details as $d ) {
				$r = array();
				foreach ( $all_names as $n ) {
					$r[ $n ] = (string) ( $d[ $n ] ?? ( $base[ $n ] ?? '' ) );
				}
				// Fusionar "otro_<campo>": si el select eligió "Otro", usar el texto libre.
				foreach ( $all_names as $n ) {
					if ( ! self::is_other_companion( $n, $all_defs ) ) { continue; }
					$parent = substr( $n, 5 );
					if ( self::value_is_other( $all_defs[ $parent ] ?? array(), (string) $r[ $parent ] ) && trim( (string) $r[ $n ] ) !== '' ) {
						$r[ $parent ] = (string) $r[ $n ];
					}
				}
				$rows[] = $r;
			}
		}

		$defs = array();
		foreach ( $names as $n ) { $defs[ $n ] = $all_defs[ $n ]; }
		return array( 'names' => $names, 'defs' => $defs, 'rows' => $rows );
	}

	/** Export CSV/XLSX en modo multi-form (planilla plana: fila por lote). */
	public static function stream_export_multi( array $form_ids, int $base_id, int $detail_id, array $join_fields, string $format, array $get ): void
	{
		$groups = self::grouped_filtered( $form_ids, $base_id, $detail_id, $join_fields, $get );
		$flat   = self::flat_rows_multi( $groups, $base_id, $detail_id );
		$columns = array();
		foreach ( $flat['names'] as $n ) {
			$columns[ $n ] = (string) ( $flat['defs'][ $n ]['label'] ?? $n );
		}
		$e = self::export_bytes( $format, $flat['rows'], $columns );
		$filename = 'registro-camara-' . gmdate( 'Ymd-His' ) . '.' . $e['ext'];
		nocache_headers();
		header( 'Content-Type: ' . $e['mime'] );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( (string) $e['bytes'] ) );
		echo $e['bytes']; // phpcs:ignore
		exit;
	}

	/** Vista multi-form: filtros + grupos (cabecera base + lotes detail) + gráfico. */
	public static function render_multi( array $form_ids, int $base_id, int $detail_id, array $join_fields, string $title ): string
	{
		$all         = self::submission_rows_multi( $form_ids );
		$base_rows   = array_values( array_filter( $all, static function ( $r ) use ( $base_id ) { return (int) ( $r['_form'] ?? 0 ) === $base_id; } ) );
		$detail_rows = array_values( array_filter( $all, static function ( $r ) use ( $detail_id ) { return (int) ( $r['_form'] ?? 0 ) === $detail_id; } ) );

		$get_base   = $_GET;
		unset( $get_base['lote'] );
		$get_detail = $_GET;
		unset( $get_detail['local'] );
		$base_f   = self::filter_rows( $base_rows, $get_base );
		$detail_f = self::filter_rows( $detail_rows, $get_detail );
		$groups   = self::group_rows( $base_f, $detail_f, $join_fields );

		$base_defs   = self::form_fields( $base_id );
		$detail_defs = self::form_fields( $detail_id );

		$locals  = self::distinct( $base_rows, 'local' );
		$camaras = self::distinct( array_merge( $base_rows, $detail_rows ), 'camara' );
		$lotes   = self::distinct( $detail_rows, 'lote' );

		// ── Filtros ──
		$opt_local  = '<option value="">Todos</option>';
		foreach ( $locals as $v ) { $opt_local .= '<option value="' . esc_attr( $v ) . '"' . selected( (string) ( $_GET['local'] ?? '' ), $v, false ) . '>' . esc_html( $v ) . '</option>'; }
		$opt_camara = '<option value="">Todas</option>';
		foreach ( $camaras as $v ) { $opt_camara .= '<option value="' . esc_attr( $v ) . '"' . selected( (string) ( $_GET['camara'] ?? '' ), $v, false ) . '>' . esc_html( $v ) . '</option>'; }
		$opt_lote   = '<option value="">Todos</option>';
		foreach ( $lotes as $v ) { $opt_lote .= '<option value="' . esc_attr( $v ) . '"' . selected( (string) ( $_GET['lote'] ?? '' ), $v, false ) . '>' . esc_html( $v ) . '</option>'; }

		// Base de export (URL actual + filtros + params multi).
		$export_base = self::current_page_url() . '?';
		foreach ( array( 'local', 'camara', 'lote', 'from', 'to' ) as $k ) {
			if ( ! empty( $_GET[ $k ] ) ) {
				$export_base .= $k . '=' . rawurlencode( (string) $_GET[ $k ] ) . '&';
			}
		}
		$export_base .= 'cr_forms=' . rawurlencode( implode( ',', $form_ids ) )
			. '&cr_base=' . (int) $base_id . '&cr_detail=' . (int) $detail_id
			. '&cr_join=' . rawurlencode( implode( ',', $join_fields ) );

		// ── Cuerpo: grupos ──
		$th = 'border:1px solid #d0d3d7;background:#f0f1f3;padding:6px 8px;text-align:left;';
		$td = 'border:1px solid #e2e4e7;padding:5px 8px;';
		// MULTI_FORM_V1: tabla UNIFICADA — una fila por registro (lote), con las
		// columnas de cámara repetidas. Antes: una tarjeta/tabla por grupo.
		$flat        = self::flat_rows_multi( $groups, $base_id, $detail_id );
		$total_lotes = count( $flat['rows'] );

		$thead = '<tr>';
		foreach ( $flat['names'] as $name ) {
			if ( $name === 'operator_name' ) { continue; }
			$thead .= '<th style="' . $th . '">' . esc_html( (string) ( $flat['defs'][ $name ]['label'] ?? $name ) ) . '</th>';
		}
		$thead .= '</tr>';

		$tbody = '';
		if ( ! $flat['rows'] ) {
			$tbody = '<tr><td colspan="' . max( 1, count( $flat['names'] ) ) . '" style="' . $td . 'color:#8c8f94;text-align:center;">Sin registros para los filtros seleccionados.</td></tr>';
		}
		foreach ( $flat['rows'] as $r ) {
			$tbody .= '<tr>';
			foreach ( $flat['names'] as $name ) {
				if ( $name === 'operator_name' ) { continue; }
				$type   = (string) ( $flat['defs'][ $name ]['type'] ?? 'text' );
				$tbody .= self::cell_html( (string) $name, $type, $r, $flat['defs'] );
			}
			$tbody .= '</tr>';
		}

		$body = '<div style="overflow-x:auto;border:1px solid #d0d3d7;border-radius:10px;">'
			. '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;">'
			. '<thead>' . $thead . '</thead><tbody>' . $tbody . '</tbody></table></div>';

		$chart_data = wp_json_encode( self::chart_series_multi( $base_f, 'lote' ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<div class="estandar-camara-registros" style="max-width:1180px;margin:0 auto;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1d2327;">'
			. '<h2 style="margin:0 0 12px;font-size:20px;">' . esc_html( $title ) . '</h2>'
			. '<form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;background:#f6f7f7;border:1px solid #dcdcde;border-radius:10px;padding:12px;margin-bottom:14px;">'
			. '<label style="font-size:12px;color:#50575e;">Local <select name="local" style="display:block;margin-top:2px;padding:7px;border:1px solid #c3c4c7;border-radius:6px;">' . $opt_local . '</select></label>'
			. '<label style="font-size:12px;color:#50575e;">Cámara <select name="camara" style="display:block;margin-top:2px;padding:7px;border:1px solid #c3c4c7;border-radius:6px;">' . $opt_camara . '</select></label>'
			. '<label style="font-size:12px;color:#50575e;">Lote <select name="lote" style="display:block;margin-top:2px;padding:7px;border:1px solid #c3c4c7;border-radius:6px;">' . $opt_lote . '</select></label>'
			. '<label style="font-size:12px;color:#50575e;">Desde <input type="date" name="from" value="' . esc_attr( (string) ( $_GET['from'] ?? '' ) ) . '" style="display:block;margin-top:2px;padding:7px;border:1px solid #c3c4c7;border-radius:6px;"></label>'
			. '<label style="font-size:12px;color:#50575e;">Hasta <input type="date" name="to" value="' . esc_attr( (string) ( $_GET['to'] ?? '' ) ) . '" style="display:block;margin-top:2px;padding:7px;border:1px solid #c3c4c7;border-radius:6px;"></label>'
			. '<button type="submit" style="padding:8px 16px;background:#f95b1f;color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer;">Filtrar</button>'
			. '<button type="button" class="cr-graph-btn" style="padding:8px 16px;background:#fff;border:1px solid #c5d9ed;color:#2271b1;border-radius:6px;cursor:pointer;font-weight:600;">📈 Ver gráfico</button>'
			. '<a href="' . esc_url( $export_base . '&cr_export=csv' ) . '" style="padding:8px 14px;background:#fff;border:1px solid #c5d9ed;color:#2271b1;border-radius:6px;text-decoration:none;font-weight:600;">⬇ CSV</a>'
			. '<a href="' . esc_url( $export_base . '&cr_export=xlsx' ) . '" style="padding:8px 14px;background:#fff;border:1px solid #c5d9ed;color:#2271b1;border-radius:6px;text-decoration:none;font-weight:600;">⬇ Excel</a>'
			. '</form>'
			. '<p style="font-size:13px;color:#50575e;margin:0 0 10px;">' . count( $groups ) . ' cámaras-día · ' . $total_lotes . ' lotes.</p>'
			// Gráfico arriba (temperatura del form base).
			. '<div id="cr-chart-wrap" style="display:none;margin-bottom:18px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;box-shadow:0 4px 16px rgba(0,0,0,.06);">'
			. '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">'
			. '<h3 id="cr-chart-title" style="margin:0;font-size:16px;">Evolución de la temperatura por lote</h3>'
			. '<button type="button" id="cr-chart-close" style="background:#fff;border:1px solid #d0d3d7;border-radius:6px;padding:5px 10px;cursor:pointer;font-size:12px;">✕ Cerrar</button>'
			. '</div>'
			. '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:10px;font-size:12px;" id="cr-series-toggle"></div>'
			. '<canvas id="cr-chart" height="320" style="width:100%;max-height:360px;"></canvas>'
			. '</div>'
			. $body
			. '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>'
			. '<script>var ES_CR_DATA=' . $chart_data . ';var ES_CR_TITLE=' . wp_json_encode( 'Evolución de la temperatura por lote' ) . ';' . self::chart_script() . self::sort_script() . '</script>'
			. '</div>';
	}

	/** Vista principal: filtros + tabla + gráfico (Chart.js CDN) + edición. */
	public static function render_view( int $form_id, array $rows, array $all_rows, array $locals, array $camaras, array $lotes, string $title, array $edit_notice = array() ): string
	{
		$esc = static function ( string $v ): string { return esc_html( $v ); };
		$q   = '';
		foreach ( array( 'local', 'camara', 'lote', 'from', 'to' ) as $k ) {
			if ( ! empty( $_GET[ $k ] ) ) {
				$q .= '&' . $k . '=' . rawurlencode( (string) $_GET[ $k ] );
			}
		}
		$export_base = (string) wp_parse_url( home_url( add_query_arg( array( 'page' => 'x' ), add_query_arg( array() ) ) ), PHP_URL_PATH );
		$export_base = home_url( '/' );
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
			if ( $path !== '' ) {
				$export_base = home_url( $path );
			}
		}
		// Base de export: URL actual sin query + filtros activos.
		$export_base = $export_base . '?';
		foreach ( array( 'local', 'camara', 'lote', 'from', 'to' ) as $k ) {
			if ( ! empty( $_GET[ $k ] ) ) {
				$export_base .= $k . '=' . rawurlencode( (string) $_GET[ $k ] ) . '&';
			}
		}

		// ── Opciones de filtro ──
		$opt_local  = '<option value="">Todos</option>';
		foreach ( $locals as $v ) { $opt_local .= '<option value="' . esc_attr( $v ) . '"' . selected( (string) ( $_GET['local'] ?? '' ), $v, false ) . '>' . $esc( $v ) . '</option>'; }
		$opt_camara = '<option value="">Todas</option>';
		foreach ( $camaras as $v ) { $opt_camara .= '<option value="' . esc_attr( $v ) . '"' . selected( (string) ( $_GET['camara'] ?? '' ), $v, false ) . '>' . $esc( $v ) . '</option>'; }
		$opt_lote   = '<option value="">Todos</option>';
		foreach ( $lotes as $v ) { $opt_lote .= '<option value="' . esc_attr( $v ) . '"' . selected( (string) ( $_GET['lote'] ?? '' ), $v, false ) . '>' . $esc( $v ) . '</option>'; }

		// ── Tabla (ADAPT_V1: columnas dinámicas desde los campos del form) ──
		$defs = self::form_fields( $form_id );
		if ( ! $defs ) {
			$defs = array();
			foreach ( self::COLUMNS as $n => $l ) {
				$defs[ $n ] = array( 'label' => $l, 'type' => 'text', 'required' => false, 'options' => array() );
			}
		}
		$th_style = 'border:1px solid #d0d3d7;background:#f0f1f3;padding:6px 8px;text-align:left;';
		$thead = '<tr>';
		foreach ( $defs as $name => $d ) {
			$thead .= '<th style="' . $th_style . '">' . esc_html( (string) $d['label'] ) . '</th>';
			if ( $name === 'fecha' ) {
				$thead .= '<th style="' . $th_style . '">Día</th>';
			}
		}
		$thead .= '<th style="' . $th_style . '"></th></tr>';

		$tbody = '';
		$colspan = count( $defs ) + ( isset( $defs['fecha'] ) ? 1 : 0 ) + 1;
		if ( ! $rows ) {
			$tbody = '<tr><td colspan="' . $colspan . '" style="border:1px solid #e2e4e7;padding:12px;text-align:center;color:#8c8f94;">Sin registros para los filtros seleccionados.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$tbody .= '<tr data-id="' . (int) ( $r['id'] ?? 0 ) . '">';
			foreach ( $defs as $name => $d ) {
				$tbody .= self::cell_html( (string) $name, (string) $d['type'], $r, $defs );
				if ( $name === 'fecha' ) {
					$tbody .= '<td style="border:1px solid #e2e4e7;padding:5px 8px;">' . esc_html( Forms::weekday_name( (string) ( $r['fecha'] ?? '' ) ) ) . '</td>';
				}
			}
			$tbody .= '<td style="border:1px solid #e2e4e7;padding:5px 8px;text-align:center;"><button type="button" class="cr-edit-btn" data-id="' . (int) ( $r['id'] ?? 0 ) . '" data-row="' . esc_attr( wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) ) . '" style="background:#fff;border:1px solid #c5d9ed;color:#2271b1;border-radius:6px;padding:5px 10px;cursor:pointer;font-size:12px;">✏️ Editar</button></td>';
			$tbody .= '</tr>';
		}

		$chart_data = wp_json_encode( self::chart_series( $rows ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return '<div class="estandar-camara-registros" style="max-width:1180px;margin:0 auto;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1d2327;">'
			. '<h2 style="margin:0 0 12px;font-size:20px;">' . $esc( $title ) . '</h2>'

			// ── Filtros ──
			. '<form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;background:#f6f7f7;border:1px solid #dcdcde;border-radius:10px;padding:12px;margin-bottom:14px;">'
			. '<label style="font-size:12px;color:#50575e;">Local <select name="local" style="display:block;margin-top:2px;padding:7px;border:1px solid #c3c4c7;border-radius:6px;">' . $opt_local . '</select></label>'
			. '<label style="font-size:12px;color:#50575e;">Cámara <select name="camara" style="display:block;margin-top:2px;padding:7px;border:1px solid #c3c4c7;border-radius:6px;">' . $opt_camara . '</select></label>'
			. '<label style="font-size:12px;color:#50575e;">Lote <select name="lote" style="display:block;margin-top:2px;padding:7px;border:1px solid #c3c4c7;border-radius:6px;">' . $opt_lote . '</select></label>'
			. '<label style="font-size:12px;color:#50575e;">Desde <input type="date" name="from" value="' . esc_attr( (string) ( $_GET['from'] ?? '' ) ) . '" style="display:block;margin-top:2px;padding:7px;border:1px solid #c3c4c7;border-radius:6px;"></label>'
			. '<label style="font-size:12px;color:#50575e;">Hasta <input type="date" name="to" value="' . esc_attr( (string) ( $_GET['to'] ?? '' ) ) . '" style="display:block;margin-top:2px;padding:7px;border:1px solid #c3c4c7;border-radius:6px;"></label>'
			. '<button type="submit" style="padding:8px 16px;background:#f95b1f;color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer;">Filtrar</button>'
			. '<button type="button" class="cr-graph-btn" style="padding:8px 16px;background:#fff;border:1px solid #c5d9ed;color:#2271b1;border-radius:6px;cursor:pointer;font-weight:600;">📈 Ver gráfico</button>'
			. '<button type="button" class="cr-new-btn" style="padding:8px 16px;background:#01651f;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:700;">＋ Nuevo registro</button>'
			. '<a href="' . esc_url( $export_base . 'cr_form=' . (int) $form_id . '&cr_export=csv' ) . '" style="padding:8px 14px;background:#fff;border:1px solid #c5d9ed;color:#2271b1;border-radius:6px;text-decoration:none;font-weight:600;">⬇ CSV</a>'
			. '<a href="' . esc_url( $export_base . 'cr_form=' . (int) $form_id . '&cr_export=xlsx' ) . '" style="padding:8px 14px;background:#fff;border:1px solid #c5d9ed;color:#2271b1;border-radius:6px;text-decoration:none;font-weight:600;">⬇ Excel</a>'
			. '</form>'

			// ── Resumen ──
			. '<p style="font-size:13px;color:#50575e;margin:0 0 10px;">' . count( $rows ) . ' registros.</p>'

			// ── Aviso de resultado de alta/edición ──
			. ( ! empty( $edit_notice['message'] )
				? '<div style="padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:13px;'
					. ( ( $edit_notice['type'] ?? 'ok' ) === 'ok' ? 'background:#e7f6ec;color:#01651f;border:1px solid #b8e6c4;' : 'background:#fcf0f1;color:#b32d2e;border:1px solid #f0c9c9;' )
					. '">' . esc_html( (string) $edit_notice['message'] ) . '</div>'
				: '' )

			// ── Gráfico (Chart.js CDN) — ARRIBA de la tabla ──
			. '<div id="cr-chart-wrap" style="display:none;margin-bottom:18px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;box-shadow:0 4px 16px rgba(0,0,0,.06);">'
			. '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">'
			. '<h3 id="cr-chart-title" style="margin:0;font-size:16px;">Evolución en el tiempo</h3>'
			. '<button type="button" id="cr-chart-close" style="background:#fff;border:1px solid #d0d3d7;border-radius:6px;padding:5px 10px;cursor:pointer;font-size:12px;">✕ Cerrar</button>'
			. '</div>'
			. '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:10px;font-size:12px;" id="cr-series-toggle"></div>'
			. '<canvas id="cr-chart" height="320" style="width:100%;max-height:360px;"></canvas>'
			. '</div>'

			// ── Tabla (ADAPT_V1: columnas dinámicas) ──
			. '<div style="overflow-x:auto;border:1px solid #d0d3d7;border-radius:10px;">'
			. '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;">'
			. '<thead>' . $thead . '</thead><tbody>' . $tbody . '</tbody></table></div>'

			// ── Modal de alta / edición (EDIT_V1) ──
			. self::edit_modal( $form_id, $locals, $camaras )

			. '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>'
			. '<script>var ES_CR_DATA=' . $chart_data . ';' . self::chart_script() . ';' . self::edit_script() . self::sort_script() . '</script>'

			// ── Branding discreto Estandar (logo → estandar.cl), como el back ──
			. '<footer style="text-align:center;margin:26px 0 8px;padding-top:14px;border-top:1px solid #ececec;">'
			. '<a href="' . esc_url( 'https://estandar.cl' ) . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:7px;text-decoration:none;color:#a7aaad;font-size:12px;line-height:1.5;" title="Estandar Studio">'
			. '<img src="' . esc_url( \Estandar\Core\LeadsPanel::BRAND_LOGO ) . '" alt="Estandar" style="max-height:18px;width:auto;display:inline-block;opacity:.9;">'
			. '<span>Impulsado por <strong style="color:#7d8388;font-weight:600;">Estandar Studio</strong> — soluciones digitales de IA</span>'
			. '</a></footer>'
			. '</div>';
	}

	/** Etiqueta legible del operador: nombre, o email si vino por magic link. */
	public static function operator_display( string $name, string $email ): string
	{
		$n = trim( $name );
		if ( $n !== '' && strpos( $n, '@' ) !== false ) {
			return substr( $n, 0, strpos( $n, '@' ) );
		}
		return $n !== '' ? $n : '—';
	}

	/** Etiqueta legible de un producto (para tabla/gráfico). */
	public static function product_label( string $p ): string
	{
		$map = array(
			's_cobre' => 'S. Cobre', 'agua_rapida' => 'Agua rápida',
			'agua_carbon' => 'Agua carbón', 'acido_sudel' => 'Ácido súdel',
		);
		return isset( $map[ $p ] ) ? $map[ $p ] : $p;
	}

	/**
	 * Celda de tabla para un campo del form (ADAPT_V1). Formatea por tipo:
	 * checkbox→✓, date→d/m/Y, time→HH:MM, temp_real→color por desvío,
	 * operator_name→operador legible. El resto, valor crudo.
	 */
	public static function cell_html( string $name, string $type, array $r, array $defs = array() ): string
	{
		$val   = (string) ( $r[ $name ] ?? '' );
		$style = 'border:1px solid #e2e4e7;padding:5px 8px;';
		// VALUE_LABEL_V1: selects/radios muestran la etiqueta legible (no la key).
		if ( in_array( $type, array( 'select', 'radio', 'routing' ), true ) && isset( $defs[ $name ] ) ) {
			$val = Forms::field_option_label( $defs[ $name ], $val );
		}
		if ( $name === 'temp_real' ) {
			$style .= 'text-align:center;' . self::temp_color( $val, (string) ( $r['temp_deseada'] ?? '' ) );
		} elseif ( $type === 'checkbox' ) {
			$style .= 'text-align:center;';
			$val = ( (string) $val === '1' ) ? '✓' : '';
		} elseif ( $type === 'date' && $val !== '' ) {
			$ts = strtotime( $val );
			if ( $ts !== false ) {
				$val = gmdate( 'd/m/Y', $ts );
			}
		} elseif ( $type === 'time' ) {
			$val = Forms::time_display( $val );
		} elseif ( $name === 'operator_name' ) {
			$val = self::operator_display( $val, (string) ( $r['operator_email'] ?? '' ) );
		} elseif ( $type === 'number' ) {
			$style .= 'text-align:center;';
		}
		return '<td style="' . $style . '">' . esc_html( $val ) . '</td>';
	}

	/** Color de la celda de temperatura real según se aleje de la deseada. */
	public static function temp_color( string $real, string $deseada ): string
	{
		if ( $real === '' || $deseada === '' || ! is_numeric( $real ) || ! is_numeric( $deseada ) ) {
			return '';
		}
		$diff = abs( (float) $real - (float) $deseada );
		if ( $diff <= 1.5 ) {
			return 'background:#e7f6ec;color:#01651f;font-weight:600;';
		}
		if ( $diff <= 3 ) {
			return 'background:#fdf6e0;color:#7a5c00;font-weight:600;';
		}
		return 'background:#fcf0f1;color:#b32d2e;font-weight:700;';
	}

	/** Series del gráfico (etiquetas por fecha + datasets). */
	public static function chart_series( array $rows ): array
	{
		$labels = array();
		$deseada = array();
		$real    = array();
		$cobre   = array();
		$rapida  = array();
		$carbon  = array();
		$sudel   = array();
		foreach ( $rows as $r ) {
			$labels[] = (string) ( $r['fecha'] ?? '' );
			$deseada[] = (string) ( $r['temp_deseada'] ?? '' ) !== '' ? (float) $r['temp_deseada'] : null;
			$real[]    = (string) ( $r['temp_real'] ?? '' ) !== '' ? (float) $r['temp_real'] : null;
			$cobre[]   = (string) ( $r['cant_s_cobre'] ?? '' ) !== '' ? (float) $r['cant_s_cobre'] : null;
			$rapida[]  = (string) ( $r['cant_agua_rapida'] ?? '' ) !== '' ? (float) $r['cant_agua_rapida'] : null;
			$carbon[]  = (string) ( $r['cant_agua_carbon'] ?? '' ) !== '' ? (float) $r['cant_agua_carbon'] : null;
			$sudel[]   = (string) ( $r['cant_acido_sudel'] ?? '' ) !== '' ? (float) $r['cant_acido_sudel'] : null;
		}
		return array(
			'labels' => $labels,
			'datasets' => array(
				array( 'key' => 'deseada', 'label' => 'Temp. deseada (°C)', 'data' => $deseada, 'borderColor' => '#2271b1', 'backgroundColor' => 'rgba(34,113,177,.12)', 'unit' => '°C' ),
				array( 'key' => 'real', 'label' => 'Temp. real (°C)', 'data' => $real, 'borderColor' => '#f95b1f', 'backgroundColor' => 'rgba(249,91,31,.14)', 'unit' => '°C' ),
				array( 'key' => 'cobre', 'label' => 'S. Cobre (g)', 'data' => $cobre, 'borderColor' => '#8a6d1a', 'backgroundColor' => 'rgba(138,109,26,.10)', 'unit' => 'g' ),
				array( 'key' => 'rapida', 'label' => 'Agua rápida (g)', 'data' => $rapida, 'borderColor' => '#2e7d32', 'backgroundColor' => 'rgba(46,125,50,.10)', 'unit' => 'g' ),
				array( 'key' => 'carbon', 'label' => 'Agua carbón (g)', 'data' => $carbon, 'borderColor' => '#6a4fa3', 'backgroundColor' => 'rgba(106,79,163,.10)', 'unit' => 'g' ),
				array( 'key' => 'sudel', 'label' => 'Ácido súdel (g)', 'data' => $sudel, 'borderColor' => '#9e1f1f', 'backgroundColor' => 'rgba(158,31,31,.10)', 'unit' => 'g' ),
			),
		);
	}

	/**
	 * Series del gráfico en modo multi-form: UNA línea por grupo (`$by`, p.ej.
	 * `lote`). Eje X = fechas ordenadas; cada dataset = temperatura real de ese
	 * grupo. Genérico: sirve a cualquier campo compartido.
	 */
	public static function chart_series_multi( array $rows, string $by = 'lote' ): array
	{
		$palette = array( '#f95b1f', '#2271b1', '#2e7d32', '#6a4fa3', '#9e1f1f', '#8a6d1a', '#00838f', '#c2185b', '#5d4037', '#455a64' );
		$dates   = array();
		foreach ( $rows as $r ) {
			$f = (string) ( $r['fecha'] ?? '' );
			if ( $f !== '' ) { $dates[ $f ] = true; }
		}
		$labels = array_keys( $dates );
		sort( $labels );

		$groups = array();
		foreach ( $rows as $r ) {
			$key = trim( (string) ( $r[ $by ] ?? '' ) );
			if ( $key === '' ) { $key = '—'; }
			$groups[ $key ][] = $r;
		}
		ksort( $groups );

		$datasets = array();
		$i        = 0;
		foreach ( $groups as $key => $grp ) {
			$by_date = array();
			foreach ( $grp as $r ) {
				$f             = (string) ( $r['fecha'] ?? '' );
				$by_date[ $f ] = ( (string) ( $r['temp_real'] ?? '' ) !== '' ) ? (float) $r['temp_real'] : null;
			}
			$data = array();
			foreach ( $labels as $f ) {
				$data[] = array_key_exists( $f, $by_date ) ? $by_date[ $f ] : null;
			}
			$color      = $palette[ $i % count( $palette ) ];
			$datasets[] = array(
				'key'            => $by . '_' . $key,
				'label'          => (string) $key,
				'data'           => $data,
				'borderColor'    => $color,
				'backgroundColor' => $color . '22',
				'unit'           => '°C',
			);
			$i++;
		}

		return array( 'labels' => $labels, 'datasets' => $datasets );
	}

	/** Modal de alta/edición (form POST con nonce, campos del registro diario). */
	public static function edit_modal( int $form_id, array $locals, array $camaras ): string
	{
		$defs  = self::form_fields( $form_id );
		$nonce = wp_create_nonce( 'cr_edit_' . $form_id );

		$field = static function ( string $name, string $label, string $type = 'text', string $extra = '', bool $req = false ) {
			return '<label style="display:block;font-size:12px;color:#50575e;margin:8px 0 3px;">' . esc_html( $label ) . ( $req ? ' *' : '' ) . '</label>'
				. '<input type="' . esc_attr( $type ) . '" name="cr_' . esc_attr( $name ) . '" class="cr-f" data-f="' . esc_attr( $name ) . '"' . ( $req ? ' required' : '' ) . ' ' . $extra . ' style="width:100%;box-sizing:border-box;padding:8px;border:1px solid #c3c4c7;border-radius:6px;font-size:14px;">';
		};
		$sel = static function ( string $name, string $label, string $options ) {
			return '<label style="display:block;font-size:12px;color:#50575e;margin:8px 0 3px;">' . esc_html( $label ) . '</label>'
				. '<select name="cr_' . esc_attr( $name ) . '" class="cr-f" data-f="' . esc_attr( $name ) . '" style="width:100%;box-sizing:border-box;padding:8px;border:1px solid #c3c4c7;border-radius:6px;font-size:14px;">' . $options . '</select>';
		};
		$cb = static function ( string $name, string $label ) {
			return '<label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;margin:6px 14px 0 0;"><input type="checkbox" name="cr_' . esc_attr( $name ) . '" value="1" class="cr-f" data-f="' . esc_attr( $name ) . '"> ' . esc_html( $label ) . '</label>';
		};
		$ta = static function ( string $name, string $label ) {
			return '<label style="display:block;font-size:12px;color:#50575e;margin:8px 0 3px;">' . esc_html( $label ) . '</label>'
				. '<textarea name="cr_' . esc_attr( $name ) . '" class="cr-f" data-f="' . esc_attr( $name ) . '" rows="2" style="width:100%;box-sizing:border-box;padding:8px;border:1px solid #c3c4c7;border-radius:6px;font-size:14px;"></textarea>';
		};

		// ADAPT_V1: el modal se genera desde los campos del form (se adapta solo).
		$grid = '';
		$inline = '';
		foreach ( $defs as $name => $d ) {
			if ( $name === 'operator_name' ) {
				continue;
			}
			$type  = (string) $d['type'];
			$label = (string) $d['label'];
			$req   = ! empty( $d['required'] );
			if ( $type === 'checkbox' ) {
				$inline .= $cb( $name, $label );
				continue;
			}
			if ( in_array( $type, array( 'select', 'radio', 'routing' ), true ) ) {
				$opts = '<option value="">Selecciona</option>';
				foreach ( (array) $d['options'] as $o ) {
					$v = is_array( $o ) ? (string) ( $o['value'] ?? ( $o['label'] ?? '' ) ) : (string) $o;
					$l = is_array( $o ) ? (string) ( $o['label'] ?? $v ) : (string) $o;
					$opts .= '<option value="' . esc_attr( $v ) . '">' . esc_html( $l ) . '</option>';
				}
				$grid .= $sel( $name, $label, $opts );
				continue;
			}
			if ( $type === 'textarea' ) {
				$grid .= $ta( $name, $label );
				continue;
			}
			$html_type = in_array( $type, array( 'date', 'time', 'number', 'email', 'tel', 'url' ), true ) ? $type : 'text';
			$extra     = ( $type === 'number' ) ? 'step="0.1"' : ( ( $type === 'time' ) ? 'step="60"' : '' );
			$grid .= $field( $name, $label, $html_type, $extra, $req );
		}
		$inner = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 14px;">' . $grid . '</div>';
		if ( $inline !== '' ) {
			$inner .= '<div style="margin-top:10px;">' . $inline . '</div>';
		}

		return '<div id="cr-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;align-items:center;justify-content:center;padding:16px;">'
			. '<div style="background:#fff;border-radius:12px;max-width:640px;width:100%;max-height:90vh;overflow:auto;padding:20px;box-shadow:0 12px 40px rgba(0,0,0,.25);">'
			. '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">'
			. '<h3 id="cr-modal-title" style="margin:0;font-size:17px;">Nuevo registro</h3>'
			. '<button type="button" id="cr-modal-close" style="background:#fff;border:1px solid #d0d3d7;border-radius:6px;padding:5px 12px;cursor:pointer;font-size:14px;">✕</button>'
			. '</div>'
			. '<form method="post" action="' . esc_url( self::current_page_url() ) . '">'
			. '<input type="hidden" name="cr_action" id="cr-action" value="new">'
			. '<input type="hidden" name="cr_id" id="cr-id" value="">'
			. '<input type="hidden" name="cr_nonce" value="' . esc_attr( $nonce ) . '">'
			. $inner
			. '<div style="margin-top:14px;display:flex;gap:10px;justify-content:flex-end;">'
			. '<button type="button" id="cr-modal-cancel" style="padding:9px 18px;background:#fff;border:1px solid #d0d3d7;border-radius:6px;cursor:pointer;">Cancelar</button>'
			. '<button type="submit" style="padding:9px 20px;background:#f95b1f;color:#fff;border:none;border-radius:6px;font-weight:700;cursor:pointer;">Guardar</button>'
			. '</div>'
			. '</form></div></div>';
	}

	/** JS del modal de alta/edición (rellenar desde la fila, abrir/cerrar). */
	private static function edit_script(): string
	{
		return <<<'JS'
(function(){
	var modal=document.getElementById('cr-modal');
	if(!modal){return;}
	var title=document.getElementById('cr-modal-title');
	var action=document.getElementById('cr-action');
	var idf=document.getElementById('cr-id');
	function setv(f,v){var el=modal.querySelector('.cr-f[data-f="'+f+'"]');if(el){if(el.type==='checkbox'){el.checked=String(v)==='1';}else{el.value=v||'';}}}
	function open(data){
		action.value=data.id?'edit':'new';
		idf.value=data.id||'';
		title.textContent=data.id?('Editar registro #'+data.id):'Nuevo registro';
		modal.querySelectorAll('.cr-f').forEach(function(el){
			var f=el.getAttribute('data-f');var v=(data||{})[f];
			if(el.type==='checkbox'){el.checked=String(v)==='1';}else{el.value=v||'';}
		});
		modal.style.display='flex';
	}
	function close(){modal.style.display='none';}
	document.addEventListener('click',function(e){
		var n=e.target.closest('.cr-new-btn');
		if(n){open({});return;}
		var ed=e.target.closest('.cr-edit-btn');
		if(ed){try{open(JSON.parse(ed.getAttribute('data-row')));}catch(err){open({id:ed.getAttribute('data-id')});}return;}
		if(e.target.closest('#cr-modal-close')||e.target.closest('#cr-modal-cancel')){close();}
		if(e.target===modal){close();}
	});
})();
JS;
	}

	/** JS del gráfico (Chart.js, con toggle de series). */
	private static function chart_script(): string
	{
		return <<<'JS'
(function(){
	function el(id){return document.getElementById(id);}
	function init(){
		var data=window.ES_CR_DATA||{labels:[],datasets:[]};
		var wrap=el('cr-chart-wrap');
		if(!wrap||!data.datasets.length){return;}
		var canvas=el('cr-chart');
		var chart=null;
		function mk(){
			var active=data.datasets.filter(function(d){return d._on!==false;});
			if(!active.length){active=data.datasets.slice(0,1);}
			if(chart){chart.destroy();}
			chart=new Chart(canvas,{type:'line',data:{labels:data.labels,datasets:active.map(function(d,i){
				return {label:d.label,data:d.data,borderColor:d.borderColor,backgroundColor:d.backgroundColor,
					borderWidth:2,pointRadius:3,pointHoverRadius:5,tension:.3,spanGaps:true,
					yAxisID: d.unit==='°C'?'y':'y1'};
			})},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
				scales:{y:{title:{display:true,text:'Temperatura (°C)'},position:'left'},
					y1:{title:{display:true,text:'Productos (g)'},position:'right',grid:{drawOnChartArea:false}}},
				plugins:{legend:{position:'bottom'}}}});
		}
		var toggle=el('cr-series-toggle');
		data.datasets.forEach(function(d){
			d._on=true;
			var lab=document.createElement('label');
			lab.style.cssText='display:flex;align-items:center;gap:4px;cursor:pointer;';
			var cb=document.createElement('input');cb.type='checkbox';cb.checked=true;
			cb.addEventListener('change',function(){d._on=cb.checked;mk();});
			lab.appendChild(cb);
			var sp=document.createElement('span');sp.style.cssText='width:10px;height:10px;border-radius:2px;background:'+d.borderColor+';display:inline-block;';
			lab.appendChild(sp);
			lab.appendChild(document.createTextNode(d.label));
			toggle.appendChild(lab);
		});
		mk();
	}
	function openChart(){
		var wrap=el('cr-chart-wrap');
		if(wrap){wrap.style.display='block';}
		if(el('cr-chart-title')){el('cr-chart-title').textContent=window.ES_CR_TITLE||'Evolución en el tiempo — registros filtrados';}
		if(!window.__crChartReady){init();window.__crChartReady=true;}
	}
	document.addEventListener('click',function(e){
		var b=e.target.closest('.cr-graph-btn');
		if(b){openChart();}
	});
	if(el('cr-chart-close')){
		el('cr-chart-close').addEventListener('click',function(){var w=el('cr-chart-wrap');if(w){w.style.display='none';}});
	}
	if(typeof Chart==='undefined'){document.addEventListener('chartjs:ready',init);}
	else{init();window.__crChartReady=true;}
})();
JS;
	}

	/** JS de orden de columnas por clic en el encabezado (SORT_V1). */
	private static function sort_script(): string
	{
		return <<<'JS'
(function(){
	function cellText(td){return (td?td.textContent:'').trim();}
	function toNum(s){if(s===''){return null;}var c=s.replace(/[^\d.,\-]/g,'');if(c===''){return null;}c=c.replace(/\./g,'').replace(',', '.');var n=parseFloat(c);return isNaN(n)?null:n;}
	function cmp(a,b){var na=toNum(a),nb=toNum(b);if(na!==null&&nb!==null){return na-nb;}return a.localeCompare(b,'es',{numeric:true,sensitivity:'base'});}
	document.querySelectorAll('.estandar-camara-registros table').forEach(function(tbl){
		var ths=tbl.querySelectorAll('thead th');
		var tb=tbl.querySelector('tbody');
		if(!tb){return;}
		ths.forEach(function(th,idx){
			if((th.textContent||'').trim()===''){return;}
			th.style.cursor='pointer';
			th.title='Ordenar';
			var mark=document.createElement('span');
			mark.className='cr-sort-mark';
			mark.style.cssText='margin-left:5px;opacity:.45;font-size:10px;';
			mark.textContent='\u2195';
			th.appendChild(mark);
			var dir=0;
			th.addEventListener('click',function(){
				dir=(dir===1)?-1:1;
				var rows=Array.prototype.slice.call(tb.querySelectorAll('tr'));
				rows.sort(function(r1,r2){return dir*cmp(cellText(r1.children[idx]),cellText(r2.children[idx]));});
				rows.forEach(function(r){tb.appendChild(r);});
				tbl.querySelectorAll('thead .cr-sort-mark').forEach(function(m){m.textContent='\u2195';m.style.opacity='.45';});
				mark.textContent=(dir===1)?'\u25B2':'\u25BC';
				mark.style.opacity='1';
			});
		});
	});
})();
JS;
	}

	/** URL canónica de la página actual (home + path), sin query. */
	public static function current_page_url(): string
	{
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( $path === '' ) {
			$path = '/';
		}
		return esc_url_raw( home_url( $path ) );
	}
}
