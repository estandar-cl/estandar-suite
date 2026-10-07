<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PostventaPanel — página web dedicada «tipo planilla» para el módulo de
 * postventa de la suite v2.
 *
 * URL: /panel-postventa/ (rewrite rule → query_var → template_redirect).
 *
 * - Lista las submissions de postventa (source='gf_postventa' durante el puente
 *   provisional GF→Capture; y source='form' con form_type incident cuando los
 *   forms nativos de postventa entren en producción).
 * - Reutiliza el login de operadores del Panel de Leads (misma cookie/PIN):
 *   un operador autenticado ve ambos paneles. Adicionalmente acepta ?access=.
 * - Vista tipo planilla: fecha, proyecto, nombre, teléfono, email, tipo,
 *   requerimiento, nº vivienda + export CSV/XLSX/PDF por correo (reutiliza los
 *   generadores de LeadsPanel + el motor Core\Mail).
 * - REST endpoints (bajo el namespace de capture) para listar/exportar/email.
 */
class PostventaPanel
{
	const PANEL_SLUG  = 'panel-postventa';
	const QUERY_VAR   = 'estandar_suite_postventa_panel';
	const NS          = 'estandar-suite/capture/v1';

	public static function columns(): array
	{
		return array( 'gf_id', 'id', 'created_at', 'project', 'name', 'email', 'phone', 'type', 'requirement', 'unit', 'origin' );
	}

	/** Columnas del export de UNA solicitud (detalle completo con problemas). */
	public static function detail_columns(): array
	{
		return array(
			'id', 'created_at', 'project', 'name', 'rut', 'email', 'phone',
			'type', 'calle', 'unit', 'requirement', 'solicitud',
			'problema_1_lugar', 'problema_1_descripcion',
			'problema_2_lugar', 'problema_2_descripcion',
			'problema_3_lugar', 'problema_3_descripcion',
			'problema_4_lugar', 'problema_4_descripcion',
		);
	}

	/** Fila de export de una solicitud con todos sus campos (RUT, calle, problemas). */
	public static function detail_export_row( int $submission_id ): array
	{
		$d = self::detail( $submission_id );
		if ( ! $d ) {
			return array();
		}
		$f = $d['fields'];
		$row = array(
			'id'        => (string) ( $d['id'] ?? '' ),
			'created_at'=> (string) ( $d['created_at'] ?? '' ),
			'project'   => (string) ( $f['proyecto'] ?? '' ),
			'name'      => (string) ( $d['name'] ?? '' ),
			'rut'       => (string) ( ( $f['rut'] ?? '' ) . ( $f['dv'] ?? '' ) ),
			'email'     => (string) ( $d['email'] ?? '' ),
			'phone'     => (string) ( $d['phone'] ?? '' ),
			'type'      => (string) ( $f['tipo_postventa'] ?? '' ),
			'calle'     => (string) ( $f['calle'] ?? '' ),
			'unit'      => (string) ( $f['numero'] ?? '' ),
			'requirement' => (string) ( $f['requerimiento'] ?? '' ),
			'solicitud' => (string) ( $f['solicitud'] ?? '' ),
		);
		foreach ( array( 1, 2, 3, 4 ) as $i ) {
			$p = isset( $d['problemas'][ $i - 1 ] ) ? $d['problemas'][ $i - 1 ] : array();
			$row[ 'problema_' . $i . '_lugar' ]        = (string) ( $p['lugar'] ?? '' );
			$row[ 'problema_' . $i . '_descripcion' ]  = (string) ( $p['descripcion'] ?? '' );
		}
		return $row;
	}

	// ── Wiring (se llama desde estandar-capture.php) ───────────────────────

	public static function init(): void
	{
		add_action( 'init', static function () {
			add_rewrite_rule( '^' . preg_quote( self::PANEL_SLUG, '/' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		}, 5 );
		add_filter( 'query_vars', static function ( array $qv ) {
			$qv[] = self::QUERY_VAR;
			return $qv;
		} );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_panel_page' ), 6 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );

		// Edición y borrado de submissions (solo administradores del sitio).
		add_action( 'admin_post_estandar_suite_capture_postventa_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_estandar_suite_capture_postventa_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_estandar_suite_capture_postventa_bulk', array( __CLASS__, 'handle_bulk' ) );
	}

	// ── Edición / borrado (admin) ─────────────────────────────────────────

	/** Guarda la edición de una submission (solo manage_options). */
	public static function handle_update(): void
	{
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado', 403 );
		}
		check_admin_referer( 'estandar_suite_capture_postventa_update' );

		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 );
		if ( $id <= 0 ) {
			wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) );
			exit;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}estandar_capture_submissions WHERE id = %d LIMIT 1", $id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/?pv=' . $id ) );
			exit;
		}

		$fj = json_decode( (string) ( $row['fields_json'] ?? '{}' ), true );
		$fj = is_array( $fj ) ? $fj : array();

		$name    = sanitize_text_field( (string) ( $_POST['name'] ?? '' ) );
		$email   = sanitize_email( (string) ( $_POST['email'] ?? '' ) );
		$phone   = preg_replace( '/\D+/', '', (string) ( $_POST['phone'] ?? '' ) );
		$proyecto = sanitize_text_field( (string) ( $_POST['proyecto'] ?? '' ) );
		$calle    = sanitize_text_field( (string) ( $_POST['calle'] ?? '' ) );
		$numero   = sanitize_text_field( (string) ( $_POST['numero'] ?? '' ) );
		$tipo     = sanitize_text_field( (string) ( $_POST['tipo_postventa'] ?? '' ) );
		$requerim = sanitize_text_field( (string) ( $_POST['requerimiento'] ?? '' ) );
		$solicitud = sanitize_textarea_field( (string) ( $_POST['solicitud'] ?? '' ) );

		// Conserva claves del survey (nombre_propietario) y de GF (nombre/apellido).
		$fj['proyecto']        = $proyecto;
		$fj['calle']           = $calle;
		$fj['numero']          = $numero;
		$fj['tipo_postventa']  = $tipo;
		$fj['requerimiento']   = $requerim;
		$fj['solicitud']       = $solicitud;
		if ( isset( $fj['nombre_propietario'] ) ) {
			$fj['nombre_propietario'] = $name;
		}
		if ( isset( $fj['nombre'] ) ) {
			$fj['nombre'] = $name;
		}

		$wpdb->update(
			$wpdb->prefix . 'estandar_capture_submissions',
			array(
				'name'             => $name,
				'email_normalized' => is_email( $email ) ? strtolower( $email ) : $row['email_normalized'],
				'phone_normalized' => $phone,
				'fields_json'      => wp_json_encode( $fj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/?pv=' . $id ) );
		exit;
	}

	/** Elimina una submission (solo manage_options). */
	public static function handle_delete(): void
	{
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado', 403 );
		}
		check_admin_referer( 'estandar_suite_capture_postventa_delete' );

		global $wpdb;
		$id = absint( $_GET['id'] ?? $_POST['id'] ?? 0 );
		if ( $id > 0 ) {
			$wpdb->delete( $wpdb->prefix . 'estandar_capture_submissions', array( 'id' => $id ), array( '%d' ) );
		}
		wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) );
		exit;
	}

	/** Acciones masivas: eliminar varias submissions seleccionadas (solo manage_options). */
	public static function handle_bulk(): void
	{
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado', 403 );
		}
		check_admin_referer( 'estandar_suite_capture_postventa_bulk' );

		$action = sanitize_key( (string) ( $_POST['bulk_action'] ?? '' ) );
		$ids = isset( $_POST['submission_ids'] ) && is_array( $_POST['submission_ids'] )
			? array_values( array_filter( array_map( 'absint', $_POST['submission_ids'] ) ) )
			: array();

		if ( $action === 'delete' && $ids !== array() ) {
			global $wpdb;
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}estandar_capture_submissions WHERE id IN ($placeholders)",
				$ids
			) );
		}
		wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) );
		exit;
	}

	// ── Datos ──────────────────────────────────────────────────────────────

	/**
	 * Cláusula SQL de fuentes del panel postventa:
	 *  - Puente GF provisional: source='gf_postventa' o source_form_id 'gf-%'.
	 *  - Forms nativos de postventa (form_type='incident'): source='form' con
	 *    source_form_id = id del form. El form 16 (Postventa, incident) ingesta
	 *    así, y debe listarse en el panel igual que las entries GF.
	 * Devuelve ['sql' => ..., 'args' => []] lista para implode(' AND ').
	 */
	private static function source_clause(): array
	{
		global $wpdb;
		$native = array();
		if ( isset( $wpdb ) ) {
			$native = (array) $wpdb->get_col(
				"SELECT id FROM {$wpdb->prefix}estandar_capture_forms WHERE form_type = 'incident' AND status = 'active'"
			);
		}
		$native = array_values( array_filter( array_map( 'intval', $native ) ) );
		if ( $native === array() ) {
			return array( 'sql' => "(source_form_id LIKE 'gf-%' OR source = 'gf_postventa')", 'args' => array() );
		}
		$placeholders = implode( ', ', array_fill( 0, count( $native ), '%d' ) );
		return array(
			'sql'  => "(source_form_id LIKE 'gf-%' OR source = 'gf_postventa' OR (source = 'form' AND source_form_id IN ($placeholders)))",
			'args' => $native,
		);
	}

	/**
	 * Submissions de postventa con orden por columna (click en el header).
	 * Fuentes: puente GF (source='gf_postventa' o source_form_id 'gf-%') +
	 * forms nativos incident (source='form', source_form_id = id).
	 * Default: created_at DESC (más reciente primero). orderby whitelist:
	 * id|created_at|name|email|phone|project|type|unit.
	 */
	public static function rows( array $filters = array(), int $limit = 50, int $offset = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$src  = self::source_clause();
		$where = array( $src['sql'] );
		$args  = $src['args'];

		$from = trim( (string) ( $filters['from'] ?? '' ) );
		$to   = trim( (string) ( $filters['to'] ?? '' ) );
		$proj = trim( (string) ( $filters['project'] ?? '' ) );
		$name = trim( (string) ( $filters['name'] ?? '' ) );
		$rid  = (int) ( $filters['id'] ?? 0 );

		if ( $rid > 0 ) {
			$where[] = 'id = %d';
			$args[]  = $rid;
		}
		if ( $from !== '' ) {
			$where[] = 'created_at >= %s';
			$args[]  = $from . ' 00:00:00';
		}
		if ( $to !== '' ) {
			$where[] = 'created_at <= %s';
			$args[]  = $to . ' 23:59:59';
		}
		if ( $proj !== '' ) {
			$where[] = 'fields_json LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $proj ) . '%';
		}
		if ( $name !== '' ) {
			$where[] = '(name LIKE %s OR email_normalized LIKE %s OR phone_normalized LIKE %s)';
			$args[]  = '%' . $wpdb->esc_like( $name ) . '%';
			$args[]  = '%' . $wpdb->esc_like( $name ) . '%';
			$args[]  = '%' . $wpdb->esc_like( $name ) . '%';
		}

		$args[] = 10000; // cap para ordenar en PHP (volúmenes de postventa pequeños).
		$sql = "SELECT id, name, email_normalized AS email, phone_normalized AS phone,
		               message, fields_json, source_form_id, created_at
		        FROM {$wpdb->prefix}estandar_capture_submissions
		        WHERE " . implode( ' AND ', $where ) . "
		        ORDER BY id DESC LIMIT %d";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		// Orden por columna (default fecha DESC = más reciente primero).
		$orderby = sanitize_key( (string) ( $filters['orderby'] ?? 'created_at' ) );
		$order   = strtolower( (string) ( $filters['order'] ?? 'desc' ) ) === 'asc' ? 'asc' : 'desc';
		$allowed = array( 'id', 'gf_id', 'created_at', 'name', 'email', 'phone', 'project', 'type', 'unit' );
		if ( ! in_array( $orderby, $allowed, true ) ) {
			$orderby = 'created_at';
		}

		// Ordena sobre los datos CRUDOS (created_at original, JSON decodificado).
		usort( $rows, static function ( $a, $b ) use ( $orderby, $order ) {
			$fa = json_decode( (string) ( $a['fields_json'] ?? '' ), true );
			$fb = json_decode( (string) ( $b['fields_json'] ?? '' ), true );
			$fa = is_array( $fa ) ? $fa : array();
			$fb = is_array( $fb ) ? $fb : array();
			$va = (string) ( $orderby === 'project' ? ( $fa['proyecto'] ?? '' )
				: ( $orderby === 'type' ? ( $fa['tipo_postventa'] ?? '' )
				: ( $orderby === 'unit' ? ( $fa['numero'] ?? '' )
				: ( $orderby === 'gf_id' ? ( $fa['gf_entry_id'] ?? '' )
				: ( $a[ $orderby ] ?? '' ) ) ) ) );
			$vb = (string) ( $orderby === 'project' ? ( $fb['proyecto'] ?? '' )
				: ( $orderby === 'type' ? ( $fb['tipo_postventa'] ?? '' )
				: ( $orderby === 'unit' ? ( $fb['numero'] ?? '' )
				: ( $orderby === 'gf_id' ? ( $fb['gf_entry_id'] ?? '' )
				: ( $b[ $orderby ] ?? '' ) ) ) ) );
			$cmp = ( $orderby === 'id' || $orderby === 'created_at' )
				? strcmp( $va, $vb )
				: ( function_exists( 'mb_strtolower' ) ? strcasecmp( $va, $vb ) : strcmp( strtolower( $va ), strtolower( $vb ) ) );
			return $order === 'asc' ? $cmp : -$cmp;
		} );

		$out = array();
		foreach ( array_slice( $rows, $offset, $limit ) as $r ) {
			$fj  = json_decode( (string) ( $r['fields_json'] ?? '' ), true );
			$fj  = is_array( $fj ) ? $fj : array();
			$out[] = array(
				'gf_id'       => (string) ( ( $fj['gf_entry_id'] ?? '' ) !== '' ? $fj['gf_entry_id'] : ( $r['id'] ?? '' ) ),
				'id'          => (int) $r['id'],
				'created_at'  => self::display_date( (string) ( $r['created_at'] ?? '' ) ),
				'project'     => (string) ( $fj['proyecto'] ?? '' ),
				'name'        => (string) ( $r['name'] ?? '' ),
				'email'       => (string) ( $r['email'] ?? '' ),
				'phone'       => (string) ( $r['phone'] ?? '' ),
				'type'        => (string) ( $fj['tipo_postventa'] ?? '' ),
				'requirement' => (string) ( $fj['requerimiento'] ?? '' ),
				'unit'        => (string) ( $fj['numero'] ?? '' ),
				'origin'      => (string) ( $r['source_form_id'] ?? 'gf' ),
			);
		}
		return $out;
	}

	public static function count_rows( array $filters = array() ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$src  = self::source_clause();
		$where = array( $src['sql'] );
		$args  = $src['args'];
		$from = trim( (string) ( $filters['from'] ?? '' ) );
		$to   = trim( (string) ( $filters['to'] ?? '' ) );
		$proj = trim( (string) ( $filters['project'] ?? '' ) );
		$name = trim( (string) ( $filters['name'] ?? '' ) );
		$rid  = (int) ( $filters['id'] ?? 0 );
		if ( $rid > 0 ) { $where[] = 'id = %d'; $args[] = $rid; }
		if ( $from !== '' ) { $where[] = 'created_at >= %s'; $args[] = $from . ' 00:00:00'; }
		if ( $to !== '' ) { $where[] = 'created_at <= %s'; $args[] = $to . ' 23:59:59'; }
		if ( $proj !== '' ) { $where[] = 'fields_json LIKE %s'; $args[] = '%' . $wpdb->esc_like( $proj ) . '%'; }
		if ( $name !== '' ) {
			$where[] = '(name LIKE %s OR email_normalized LIKE %s OR phone_normalized LIKE %s)';
			$args[] = '%' . $wpdb->esc_like( $name ) . '%';
			$args[] = '%' . $wpdb->esc_like( $name ) . '%';
			$args[] = '%' . $wpdb->esc_like( $name ) . '%';
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'estandar_capture_submissions WHERE ' . implode( ' AND ', $where ),
			$args
		) );
	}

	public static function display_date( string $dt ): string
	{
		$ts = strtotime( $dt );
		if ( ! $ts ) {
			return $dt;
		}
		return gmdate( 'd-m-Y H:i', $ts );
	}

	public static function request_filters( $request ): array
	{
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			return array(
				'from'    => sanitize_text_field( (string) $request->get_param( 'from' ) ),
				'to'      => sanitize_text_field( (string) $request->get_param( 'to' ) ),
				'project' => sanitize_text_field( (string) $request->get_param( 'project' ) ),
				'name'    => sanitize_text_field( (string) $request->get_param( 'name' ) ),
				'id'      => (int) $request->get_param( 'id' ),
				'orderby' => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'order'   => strtolower( (string) $request->get_param( 'order' ) ) === 'asc' ? 'asc' : 'desc',
			);
		}
		return array();
	}

	public static function project_options(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$src = self::source_clause();
		$rows = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT fields_json FROM {$wpdb->prefix}estandar_capture_submissions
				 WHERE " . $src['sql'] . " AND fields_json LIKE '%proyecto%'
				 ORDER BY id DESC LIMIT 400",
				$src['args']
			)
		);
		$seen = array();
		foreach ( $rows as $fj ) {
			$data = json_decode( (string) $fj, true );
			$p = (string) ( is_array( $data ) ? ( $data['proyecto'] ?? '' ) : '' );
			if ( $p !== '' ) {
				$seen[ $p ] = $p;
			}
		}
		$list = array_values( $seen );
		sort( $list, SORT_STRING );
		return $list;
	}

	// ── Exports (reutiliza generadores de LeadsPanel) ─────────────────────

	public static function export_bytes( string $format, array $rows, array $columns ): array
	{
		if ( $format === 'xlsx' ) {
			return array( 'bytes' => \Estandar\Core\LeadsPanel::export_xlsx( $rows, $columns ), 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ext' => 'xlsx' );
		}
		if ( $format === 'pdf' ) {
			return array( 'bytes' => \Estandar\Core\LeadsPanel::export_pdf( $rows, $columns ), 'mime' => 'application/pdf', 'ext' => 'pdf' );
		}
		return array( 'bytes' => \Estandar\Core\LeadsPanel::export_csv( $rows, $columns ), 'mime' => 'text/csv', 'ext' => 'csv' );
	}

	// ── REST ───────────────────────────────────────────────────────────────

	public static function routes(): void
	{
		register_rest_route( self::NS, '/postventa', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return self::authorized();
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$filters = self::request_filters( $request );
				$limit   = (int) ( $request->get_param( 'limit' ) ?: 50 );
				return rest_ensure_response( array(
					'columns' => self::columns(),
					'total'   => self::count_rows( $filters ),
					'rows'    => self::rows( $filters, $limit, 0 ),
				) );
			},
		) );

		register_rest_route( self::NS, '/postventa/export', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return self::authorized();
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$format = sanitize_key( (string) $request->get_param( 'format' ) );
				if ( ! in_array( $format, array( 'csv', 'xlsx', 'pdf' ), true ) ) {
					$format = 'csv';
				}
				$filters = self::request_filters( $request );
				$single  = (int) ( $filters['id'] ?? 0 );
				if ( $single > 0 ) {
					// Export de UNA solicitud: detalle completo (RUT, calle, problemas).
					$rows     = array( self::detail_export_row( $single ) );
					$columns  = self::detail_columns();
				} else {
					$rows     = self::rows( $filters, 10000, 0 );
					$columns  = self::columns();
				}
				$e       = self::export_bytes( $format, $rows, $columns );
				$filename = 'postventa-' . gmdate( 'Ymd-His' ) . '.' . $e['ext'];
				return new \WP_REST_Response( (string) $e['bytes'], 200, array(
					'Content-Type'        => $e['mime'],
					'Content-Disposition' => 'attachment; filename="' . $filename . '"',
					'Content-Length'      => (string) strlen( (string) $e['bytes'] ),
					'Cache-Control'       => 'no-store',
				) );
			},
		) );

		register_rest_route( self::NS, '/postventa/email', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return self::authorized();
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$to     = sanitize_email( (string) ( $request->get_param( 'email' ) ?: $request->get_param( 'to' ) ) );
				$format = sanitize_key( (string) $request->get_param( 'format' ) );
				if ( ! in_array( $format, array( 'csv', 'xlsx', 'pdf' ), true ) ) {
					$format = 'csv';
				}
				if ( ! is_email( $to ) ) {
					return new \WP_Error( 'estandar_suite_postventa_email_invalid', 'Correo destino inválido.', array( 'status' => 400 ) );
				}
				$filters = self::request_filters( $request );
				$single  = (int) ( $filters['id'] ?? 0 );
				if ( $single > 0 ) {
					$rows    = array( self::detail_export_row( $single ) );
					$columns = self::detail_columns();
				} else {
					$rows    = self::rows( $filters, 10000, 0 );
					$columns = self::columns();
				}
				$e       = self::export_bytes( $format, $rows, $columns );
				if ( $e['bytes'] === '' ) {
					return new \WP_Error( 'estandar_suite_postventa_email_generate', 'No se pudo generar el archivo.', array( 'status' => 500 ) );
				}
				$tmp = tempnam( sys_get_temp_dir(), 'pv' );
				file_put_contents( $tmp, (string) $e['bytes'] );
				$filename = 'postventa-' . gmdate( 'Ymd-His' ) . '.' . $e['ext'];
				$subject  = 'Estandar Suite — Postventa (' . strtoupper( $e['ext'] ) . ')';
				$body     = 'Adjunto el archivo de postventa (' . count( $rows ) . ' registros, formato ' . strtoupper( $e['ext'] ) . ').';
				$result   = \Estandar\Core\Mail::send_raw( array(
					'to'          => $to,
					'subject'     => $subject,
					'html'        => '<p>' . esc_html( $body ) . '</p>',
					'alt'         => $body,
					'attachments' => array( $tmp ),
				) );
				\Estandar\Core\Mail::log( $result );
				@unlink( $tmp );
				if ( empty( $result['ok'] ) ) {
					return new \WP_Error( 'estandar_suite_postventa_email_failed', 'No se pudo enviar el correo: ' . (string) ( $result['error'] ?? 'revisa el transporte SMTP' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'to' => $to, 'format' => $format, 'rows' => count( $rows ) ) );
			},
		) );
	}

	// ── Página amigable /panel-postventa/ ─────────────────────────────────

	/** Reutiliza la autorización/operadores del Panel de Leads (una cookie). */
	public static function authorized(): bool
	{
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return true;
		}
		return \Estandar\Core\LeadsPanel::authorized();
	}

	public static function maybe_render_panel_page(): void
	{
		if ( (int) get_query_var( self::QUERY_VAR ) !== 1 ) {
			return;
		}
		nocache_headers();

		if ( isset( $_GET['logout'] ) ) {
			\Estandar\Core\LeadsPanel::logout();
			wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) );
			exit;
		}

		if ( ! empty( $_POST['lp_action'] ) && $_POST['lp_action'] === 'login' ) {
			$name = sanitize_text_field( (string) ( $_POST['name'] ?? '' ) );
			$pin  = (string) ( $_POST['pin'] ?? '' );
			if ( \Estandar\Core\LeadsPanel::authenticate( $name, $pin ) ) {
				wp_safe_redirect( home_url( '/' . self::PANEL_SLUG . '/' ) );
				exit;
			}
			status_header( 200 );
			echo self::render_login_page( true );
			exit;
		}

		if ( self::authorized() ) {
			$operator = \Estandar\Core\LeadsPanel::current_operator();
			if ( $operator !== '' ) {
				\Estandar\Core\LeadsPanel::log_view( $operator );
			}

			// Detalle de una solicitud (enlace desde el listado).
			$detail_id = (int) ( $_GET['pv'] ?? 0 );
			if ( $detail_id > 0 ) {
				$detail = self::detail( $detail_id );
				$back = home_url( '/' . self::PANEL_SLUG . '/' )
					. ( isset( $_GET['access'] ) ? '?access=' . rawurlencode( (string) $_GET['access'] ) : '' );
				status_header( 200 );
				if ( isset( $_GET['pv_edit'] ) && $detail && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
					echo self::render_edit( $detail, $back );
				} else {
					echo self::render_detail( $detail, $back );
				}
				exit;
			}

			$filters = array(
				'from'    => sanitize_text_field( (string) ( $_GET['from'] ?? '' ) ),
				'to'      => sanitize_text_field( (string) ( $_GET['to'] ?? '' ) ),
				'project' => sanitize_text_field( (string) ( $_GET['project'] ?? '' ) ),
				'name'    => sanitize_text_field( (string) ( $_GET['name'] ?? '' ) ),
				'access'  => sanitize_text_field( (string) ( $_GET['access'] ?? $_GET['pass'] ?? '' ) ),
				'orderby' => sanitize_key( (string) ( $_GET['orderby'] ?? 'created_at' ) ),
				'order'   => strtolower( (string) ( $_GET['order'] ?? 'desc' ) ) === 'asc' ? 'asc' : 'desc',
			);
			$total = self::count_rows( $filters );
			$rows  = self::rows( $filters, 30, 0 );
			$export_base = rest_url( self::NS . '/postventa/export' );
			status_header( 200 );
			echo self::render_viewer( $rows, $filters, $export_base, $total );
			exit;
		}

		status_header( 200 );
		echo self::render_login_page( false );
		exit;
	}

	public static function render_login_page( bool $error = false ): string
	{
		$action = esc_url( home_url( '/' . self::PANEL_SLUG . '/' ) );
		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Panel Postventa — Acceso</title>'
			. '<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f6f7f7;color:#1d2327;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:16px;}
			.card{background:#fff;border:1px solid #dcdcde;border-radius:12px;max-width:400px;width:100%;padding:24px;box-shadow:0 4px 16px rgba(0,0,0,.08);}
			label{display:block;font-size:12px;color:#646970;margin:12px 0 4px;}
			input{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #dcdcde;border-radius:6px;font-size:15px;}
			.btn{width:100%;margin-top:18px;background:#f95b1f;color:#fff;border:none;padding:11px;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;}
			.btn:hover{filter:brightness(.94);}
			.err{background:#fcf0f1;color:#b32d2e;border:1px solid #ff8085;border-radius:6px;padding:8px 10px;font-size:13px;margin-bottom:8px;}</style></head><body>'
			. '<div class="card">'
			. \Estandar\Core\LeadsPanel::brand_top( 'Panel Postventa' )
			. ( $error ? '<div class="err">Nombre o PIN incorrecto.</div>' : '' )
			. '<form method="post" action="' . $action . '">'
			. '<input type="hidden" name="lp_action" value="login">'
			. '<label for="lp-name">Tu nombre</label><input id="lp-name" name="name" required autocomplete="name" placeholder="Ej. Diego">'
			. '<label for="lp-pin">PIN (4 letras)</label><input id="lp-pin" name="pin" required maxlength="4" pattern="[A-Za-z]{4}" autocomplete="off" placeholder="abcd" title="4 letras">'
			. '<button class="btn" type="submit">Ingresar</button>'
			. '</form></div></body></html>';
	}

	/** Detalle completo de una solicitud de postventa (submission). */
	public static function detail( int $submission_id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $submission_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}estandar_capture_submissions WHERE id = %d LIMIT 1", $submission_id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$fj = json_decode( (string) ( $row['fields_json'] ?? '' ), true );
		$fj = is_array( $fj ) ? $fj : array();

		$problemas = array();
		$raw = json_decode( (string) ( $fj['problemas'] ?? '[]' ), true );
		if ( ! is_array( $raw ) || $raw === array() ) {
			// Form nativo / survey: problemas van en `incidents` (location/description/fotos).
			$inc = json_decode( (string) ( $fj['incidents'] ?? '[]' ), true );
			if ( is_array( $inc ) ) {
				$raw = array_map( static function ( $p ) {
					if ( ! is_array( $p ) ) {
						return array();
					}
					return array(
						'numero'      => (int) ( $p['numero'] ?? 0 ),
						'lugar'       => (string) ( $p['location'] ?? $p['lugar'] ?? '' ),
						'descripcion' => (string) ( $p['description'] ?? $p['descripcion'] ?? '' ),
						'fotos'       => isset( $p['fotos'] ) ? (array) $p['fotos'] : array(),
					);
				}, $inc );
			}
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $p ) {
				if ( is_array( $p ) && ( ! empty( $p['lugar'] ) || ! empty( $p['descripcion'] ) || ! empty( $p['fotos'] ) ) ) {
					$p['fotos'] = isset( $p['fotos'] ) && is_array( $p['fotos'] ) ? array_values( array_filter( array_map( 'strval', $p['fotos'] ) ) ) : array();
					$problemas[] = $p;
				}
			}
		}

		return array(
			'id'          => (int) $row['id'],
			'gf_id'       => (string) ( $fj['gf_entry_id'] ?? '' ),
			'created_at'  => self::display_date( (string) ( $row['created_at'] ?? '' ) ),
			'created_at_raw' => (string) ( $row['created_at'] ?? '' ),
			'name'        => (string) ( $row['name'] ?? '' ),
			'email'       => (string) ( $row['email_normalized'] ?? '' ),
			'phone'       => (string) ( $row['phone_normalized'] ?? '' ),
			'fields'      => array(
				'nombre'         => (string) ( $fj['nombre'] ?? $fj['nombre_propietario'] ?? '' ),
				'apellido'       => (string) ( $fj['apellido'] ?? '' ),
				'rut'            => (string) ( $fj['rut'] ?? '' ),
				'dv'             => (string) ( $fj['dv'] ?? '' ),
				'proyecto'       => (string) ( $fj['proyecto'] ?? '' ),
				'calle'          => (string) ( $fj['calle'] ?? '' ),
				'numero'         => (string) ( $fj['numero'] ?? '' ),
				'tipo_postventa' => (string) ( $fj['tipo_postventa'] ?? '' ),
				'requerimiento'  => (string) ( $fj['requerimiento'] ?? '' ),
				'solicitud'      => (string) ( $fj['solicitud'] ?? '' ),
			),
			'problemas'   => $problemas,
		);
	}

	/** Vista de edición de una solicitud (solo administradores). */
	public static function render_edit( array $detail, string $back_url ): string
	{
		$esc = static function ( string $v ): string {
			return esc_html( $v );
		};
		$f = $detail['fields'];
		$sub_id = (int) $detail['id'];
		$action = admin_url( 'admin-post.php' );
		$proyectos = array( 'ALTACRUZ', 'TERRACRUZ', 'VILLA EL ESTERO CASAS', 'VILLA EL ESTERO EDIFICIOS', 'LOS VIÑEDOS- NANCAGUA', 'LOS PARRONALES-NANCAGUA', 'CASAS DE QUINTA', 'EL ESFUERZO - LICANTEN' );
		$tipos = array( 'DOCUMENTOS Y ESCRITURACION', 'SERVICO TECNICO DE POST VENTA' );
		$reqs = array( 'SOLICITUD DE ESCRITURA', 'GARANTIA CALEFONT', 'OTRA' );

		$opt = static function ( string $sel, array $opts ) use ( $esc ) {
			$o = '';
			foreach ( $opts as $v ) {
				$o .= '<option value="' . $esc( $v ) . '"' . selected( $sel, $v, false ) . '>' . $esc( $v ) . '</option>';
			}
			return $o;
		};

		$body = '<div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">'
			. '<a class="btn" href="' . esc_url( $back_url ) . '" style="margin:0;">← Volver al panel</a>'
			. '<a class="btn" href="' . esc_url( home_url( '/' . self::PANEL_SLUG . '/?pv=' . $sub_id ) ) . '" style="margin:0;">Ver solicitud</a>'
			. '</div>';
		$body .= '<div class="card"><h3>Editar solicitud #' . $sub_id . '</h3>'
			. '<form method="post" action="' . esc_url( $action ) . '">'
			. wp_nonce_field( 'estandar_suite_capture_postventa_update', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="estandar_suite_capture_postventa_update">'
			. '<input type="hidden" name="id" value="' . $sub_id . '">'
			. '<table class="form-table" role="presentation" style="max-width:640px;">'
			. '<tr><th scope="row">Nombre</th><td><input type="text" name="name" class="regular-text" style="width:100%;" value="' . $esc( (string) $detail['name'] ) . '"></td></tr>'
			. '<tr><th scope="row">Email</th><td><input type="email" name="email" class="regular-text" style="width:100%;" value="' . $esc( (string) $detail['email'] ) . '"></td></tr>'
			. '<tr><th scope="row">Teléfono</th><td><input type="tel" name="phone" class="regular-text" style="width:100%;" value="' . $esc( (string) $detail['phone'] ) . '"></td></tr>'
			. '<tr><th scope="row">Proyecto</th><td><select name="proyecto" style="width:100%;">' . $opt( (string) $f['proyecto'], $proyectos ) . '</select></td></tr>'
			. '<tr><th scope="row">Calle</th><td><input type="text" name="calle" class="regular-text" style="width:100%;" value="' . $esc( (string) $f['calle'] ) . '"></td></tr>'
			. '<tr><th scope="row">Número de vivienda</th><td><input type="text" name="numero" class="regular-text" style="width:100%;" value="' . $esc( (string) $f['numero'] ) . '"></td></tr>'
			. '<tr><th scope="row">Tipo de postventa</th><td><select name="tipo_postventa" style="width:100%;">' . $opt( (string) $f['tipo_postventa'], $tipos ) . '</select></td></tr>'
			. '<tr><th scope="row">Requerimiento</th><td><select name="requerimiento" style="width:100%;">' . $opt( (string) $f['requerimiento'], $reqs ) . '</select></td></tr>'
			. '<tr><th scope="row">Solicitud de documento</th><td><textarea name="solicitud" rows="3" style="width:100%;">' . $esc( (string) $f['solicitud'] ) . '</textarea></td></tr>'
			. '</table>'
			. '<p style="margin-top:12px;"><button type="submit" class="btn btn-green" style="margin:0;">Guardar cambios</button></p>'
			. '</form></div>';
		return self::page_shell( 'Editar solicitud de Postventa', $body, $back_url );
	}

	/** Vista detallada de una solicitud (todos los datos que envió la persona). */
	public static function render_detail( ?array $detail, string $back_url ): string
	{
		$esc = static function ( string $v ): string {
			return esc_html( $v );
		};
		$title = 'Solicitud de Postventa';
		if ( ! $detail ) {
			$body = '<div class="card"><p>Solicitud no encontrada.</p><p><a class="btn" href="' . esc_url( $back_url ) . '">← Volver al panel</a></p></div>';
			return self::page_shell( $title, $body, $back_url );
		}

		$f = $detail['fields'];
		$rows  = '<tr><td class="k">Fecha de la solicitud</td><td>' . $esc( (string) $detail['created_at'] ) . '</td></tr>';
		$rows .= '<tr><td class="k">ID (entrada original)</td><td>' . $esc( (string) $detail['gf_id'] ) . '</td></tr>';
		$rows .= '<tr><td class="k">Nombre</td><td>' . $esc( (string) ( $f['nombre'] . ' ' . $f['apellido'] ) ) . '</td></tr>';
		$rows .= '<tr><td class="k">RUT</td><td>' . $esc( (string) ( $f['rut'] . $f['dv'] ) ) . '</td></tr>';
		$rows .= '<tr><td class="k">Email</td><td><a href="mailto:' . esc_attr( $detail['email'] ) . '">' . $esc( (string) $detail['email'] ) . '</a></td></tr>';
		$rows .= '<tr><td class="k">Teléfono</td><td>' . $esc( (string) $detail['phone'] ) . '</td></tr>';
		$rows .= '<tr><td class="k">Proyecto</td><td>' . $esc( (string) $f['proyecto'] ) . '</td></tr>';
		$rows .= '<tr><td class="k">Calle</td><td>' . $esc( (string) $f['calle'] ) . '</td></tr>';
		$rows .= '<tr><td class="k">Número de vivienda</td><td>' . $esc( (string) $f['numero'] ) . '</td></tr>';
		$rows .= '<tr><td class="k">Tipo de postventa</td><td>' . $esc( (string) $f['tipo_postventa'] ) . '</td></tr>';
		$rows .= '<tr><td class="k">Requerimiento</td><td>' . $esc( (string) $f['requerimiento'] ) . '</td></tr>';
		if ( (string) $f['solicitud'] !== '' ) {
			$rows .= '<tr><td class="k">Solicitud de documento</td><td>' . $esc( (string) $f['solicitud'] ) . '</td></tr>';
		}

		$prob = '';
		$lightbox_items = array(); // colección global para el lightbox del detalle
		foreach ( (array) $detail['problemas'] as $i => $p ) {
			$prob .= '<div class="problema"><h4>Problema #' . ( (int) $i + 1 ) . '</h4>';
			if ( ! empty( $p['lugar'] ) ) {
				$prob .= '<p><strong>Lugar:</strong> ' . $esc( (string) $p['lugar'] ) . '</p>';
			}
			if ( ! empty( $p['descripcion'] ) ) {
				$prob .= '<p><strong>Descripción:</strong> ' . $esc( (string) $p['descripcion'] ) . '</p>';
			}
			if ( ! empty( $p['fotos'] ) ) {
				$gal = '';
				foreach ( (array) $p['fotos'] as $u ) {
					$big = \Estandar\Capture\ImageOptimizer::optimize_url( $u );
					$lightbox_items[] = $big !== '' ? $big : $u;
					$idx = count( $lightbox_items ) - 1;
					$gal .= '<a href="javascript:void(0)" class="pv-lightbox-open" data-index="' . $idx . '" style="display:inline-block;margin:4px 6px 0 0;"><img src="' . esc_url( $big !== '' ? $big : $u ) . '" alt="Foto del problema #' . ( (int) $i + 1 ) . '" loading="lazy" style="max-width:140px;max-height:100px;object-fit:cover;border:1px solid #dcdcde;border-radius:6px;vertical-align:top;"></a>';
				}
				$prob .= '<p><strong>Fotografías:</strong> <span class="muted">(clic para agrandar)</span><br>' . $gal . '</p>';
			}
			$prob .= '</div>';
		}

		// Lightbox interno (no saca al usuario de /panel-postventa/).
		$lightbox = '';
		if ( $lightbox_items ) {
			$lb_images = '';
			foreach ( $lightbox_items as $u ) {
				$lb_images .= '<div class="pv-lb-slide"><img src="' . esc_url( $u ) . '" alt="Foto de la solicitud" loading="lazy"></div>';
			}
			$lightbox = '<div id="pv-lightbox" class="pv-lightbox" role="dialog" aria-modal="true" aria-label="Fotografías de la solicitud">'
				. '<button type="button" class="pv-lb-close" aria-label="Cerrar">✕</button>'
				. '<button type="button" class="pv-lb-prev" aria-label="Anterior">‹</button>'
				. '<div class="pv-lb-stage">' . $lb_images . '</div>'
				. '<button type="button" class="pv-lb-next" aria-label="Siguiente">›</button>'
				. '<div class="pv-lb-counter"></div>'
				. '</div>';
		}

		$body  = '<div style="display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">'
			. '<a class="btn" href="' . esc_url( $back_url ) . '" style="margin:0;">← Volver al panel</a>'
			. '<button type="button" class="btn btn-gray" id="pv-share-open" style="margin:0;">Compartir</button>';
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			$edit_url = home_url( '/' . self::PANEL_SLUG . '/?pv=' . (int) $detail['id'] . '&pv_edit=1' );
			$del_url  = wp_nonce_url(
				admin_url( 'admin-post.php?action=estandar_suite_capture_postventa_delete&id=' . (int) $detail['id'] ),
				'estandar_suite_capture_postventa_delete'
			);
			$body .= '<a class="btn" href="' . esc_url( $edit_url ) . '" style="margin:0;">✏️ Editar</a>'
				. '<a class="btn btn-danger" href="' . esc_url( $del_url ) . '" style="margin:0;background:#b32d2e;color:#fff;" onclick="return confirm(\'¿Eliminar esta solicitud de postventa? Esta acción no se puede deshacer.\');">🗑️ Eliminar</a>';
		}
		$body .= '</div>';
		$body .= '<div class="card"><h3>' . $esc( $title ) . '</h3><table class="detail"><tbody>' . $rows . '</tbody></table></div>';
		if ( $prob !== '' ) {
			$body .= '<div class="card"><h3>Problemas reportados</h3>' . $prob . '</div>';
		}

		// Modal "Compartir": enviar por correo o descargar CSV/Excel/PDF de ESTA solicitud.
		$rest_email = rest_url( self::NS . '/postventa/email' );
		$rest_export = rest_url( self::NS . '/postventa/export' );
		$nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '';
		$sub_id = (int) ( $detail['id'] ?? 0 );
		$body .= '<div id="pv-share-modal" class="pv-modal" aria-hidden="true">'
			. '<div class="pv-modal-card">'
			. '<div class="pv-modal-head"><strong>Compartir solicitud #' . $sub_id . '</strong><button type="button" id="pv-share-close" class="pv-modal-close" aria-label="Cerrar">✕</button></div>'
			. '<div class="pv-modal-body">'
			. '<p class="sub">Elige cómo quieres compartir esta solicitud de postventa.</p>'
			. '<form class="share-row" id="pv-share-download-form" action="' . esc_url( $rest_export ) . '">'
			. '<label>Formato <select name="format" id="pv-share-fmt-dl"><option value="csv">CSV</option><option value="xlsx">Excel</option><option value="pdf">PDF</option></select></label>'
			. '<input type="hidden" name="id" value="' . $sub_id . '">'
			. '<button class="btn" type="button" id="pv-share-download" style="margin:0;">⬇ Descargar</button>'
			. '</form>'
			. '<hr style="border:none;border-top:1px solid #f0f0f1;margin:14px 0;">'
			. '<div class="emailbox"><input type="email" id="pv-share-email" placeholder="destino@correo.cl" required>'
			. '<select id="pv-share-fmt"><option value="csv">CSV</option><option value="xlsx">Excel</option><option value="pdf">PDF</option></select>'
			. '<button type="button" id="pv-share-send" class="btn btn-green" style="margin:0;">Enviar por correo</button></div>'
			. '<div id="pv-share-msg" class="meta"></div>'
			. '</div></div></div>'
			. '<script>(function(){
var m=document.getElementById("pv-share-modal");var o=document.getElementById("pv-share-open");var c=document.getElementById("pv-share-close");
if(!m||!o){return;}
o.addEventListener("click",function(){m.classList.add("open");m.setAttribute("aria-hidden","false");});
c.addEventListener("click",function(){m.classList.remove("open");m.setAttribute("aria-hidden","true");});
m.addEventListener("click",function(e){if(e.target===m){m.classList.remove("open");m.setAttribute("aria-hidden","true");}});
var em=document.getElementById("pv-share-email");var fmt=document.getElementById("pv-share-fmt");var btn=document.getElementById("pv-share-send");var msg=document.getElementById("pv-share-msg");
var dlBtn=document.getElementById("pv-share-download");var dlFmt=document.getElementById("pv-share-fmt-dl");
if(dlBtn&&dlFmt){dlBtn.addEventListener("click",function(){
  dlBtn.disabled=true;
  fetch(' . wp_json_encode( $rest_export ) . '+"?format="+dlFmt.value+"&id=' . $sub_id . '",{
    method:"GET",credentials:"same-origin",headers:{"X-WP-Nonce":' . wp_json_encode( $nonce ) . '}})
    .then(function(r){if(!r.ok){return r.json().then(function(d){throw new Error((d&&d.message)||"Error "+r.status);});}
      var cd=r.headers.get("Content-Disposition")||"";var m=cd.match(/filename="?([^";]+)"?/);var fn=m?m[1]:("postventa-' . $sub_id . '."+dlFmt.value);var ct=r.headers.get("Content-Type")||"application/octet-stream";
      return r.blob().then(function(b){var u=URL.createObjectURL(new Blob([b],{type:ct}));var a=document.createElement("a");a.href=u;a.download=fn;document.body.appendChild(a);a.click();a.remove();URL.revokeObjectURL(u);});})
    .then(function(){dlBtn.disabled=false;})
    .catch(function(e){dlBtn.disabled=false;msg.textContent="Error: "+e.message;});});}
if(em&&btn){btn.addEventListener("click",function(){
  var v=em.value.trim();if(!v){msg.textContent="Ingresa el correo destino.";return;}
  btn.disabled=true;msg.textContent="Enviando…";
  fetch(' . wp_json_encode( $rest_email ) . '+"?email="+encodeURIComponent(v)+"&format="+fmt.value+"&id=' . $sub_id . '",{
    method:"POST",credentials:"same-origin",headers:{"X-WP-Nonce":' . wp_json_encode( $nonce ) . ',"Content-Type":"application/json"}})
    .then(function(r){return r.json();}).then(function(d){btn.disabled=false;
      msg.textContent=d&&d.ok?("Enviado a "+d.to+" ("+d.format+")."):(d&&d.message?"Error: "+d.message:"Error al enviar.");})
    .catch(function(){btn.disabled=false;msg.textContent="Error de conexión.";});});}
})();</script>';

		$body .= $lightbox . self::lightbox_script( count( $lightbox_items ) );
		return self::page_shell( $title, $body, $back_url );
	}

	/** Script del lightbox interno del detalle de postventa. */
	private static function lightbox_script( int $total ): string
	{
		return '<script>(function(){
var lb=document.getElementById("pv-lightbox");if(!lb){return;}
var slides=lb.querySelectorAll(".pv-lb-slide");var cur=0;
var show=function(i){cur=((i%slides.length)+slides.length)%slides.length;
  slides.forEach(function(s,k){s.style.display=(k===cur)?"flex":"none";});
  var c=lb.querySelector(".pv-lb-counter");if(c){c.textContent=(cur+1)+" / "+slides.length;}};
document.querySelectorAll(".pv-lightbox-open").forEach(function(a){
  a.addEventListener("click",function(e){e.preventDefault();show(parseInt(a.getAttribute("data-index")||"0",10));lb.classList.add("open");document.body.style.overflow="hidden";});});
lb.querySelector(".pv-lb-close").addEventListener("click",function(){lb.classList.remove("open");document.body.style.overflow="";});
lb.querySelector(".pv-lb-prev").addEventListener("click",function(){show(cur-1);});
lb.querySelector(".pv-lb-next").addEventListener("click",function(){show(cur+1);});
lb.addEventListener("click",function(e){if(e.target===lb){lb.classList.remove("open");document.body.style.overflow="";}});
document.addEventListener("keydown",function(e){if(!lb.classList.contains("open")){return;}
  if(e.key==="Escape"){lb.classList.remove("open");document.body.style.overflow="";}
  if(e.key==="ArrowLeft"){show(cur-1);}if(e.key==="ArrowRight"){show(cur+1);}});
})();</script>';
	}

	/** Cascarón HTML compartido por las vistas del panel. */
	private static function page_shell( string $title, string $body, string $back_url = '' ): string
	{
		$operator = \Estandar\Core\LeadsPanel::current_operator();
		$right = $operator !== ''
			? '<span>👤 ' . esc_html( $operator ) . '</span><a href="' . esc_url( home_url( '/' . self::PANEL_SLUG . '/?logout=1' ) ) . '" style="color:#fff;">Salir</a>'
			: '';
		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $title ) . '</title>'
			. '<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f6f7f7;color:#1d2327;}
.wrap{max-width:900px;margin:0 auto;padding:24px 16px;}
.card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px;margin-bottom:16px;}
table.detail{width:100%;border-collapse:collapse;font-size:14px;}
table.detail td{border-bottom:1px solid #f0f0f1;padding:9px 10px;vertical-align:top;}
table.detail td.k{width:230px;font-weight:600;color:#50575e;background:#f9f9f6;white-space:nowrap;}
.problema{background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;margin:10px 0;}
.problema h4{margin:0 0 6px;}
.btn{display:inline-block;background:#32373c;color:#fff;text-decoration:none;padding:9px 16px;border-radius:6px;font-size:13px;font-weight:600;margin-bottom:14px;}
.btn:hover{background:#1d2327;}
.btn-gray{background:#646970;}
.btn-gray:hover{background:#50575e;}
.btn-green{background:#00a32a;}
.btn-green:hover{background:#007017;}
.pv-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1001;align-items:flex-start;justify-content:center;padding:24px 12px;overflow-y:auto;}
.pv-modal.open{display:flex;}
.pv-modal-card{background:#fff;border-radius:12px;max-width:480px;width:100%;box-shadow:0 12px 40px rgba(0,0,0,.25);}
.pv-modal-head{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f0f0f1;font-size:15px;}
.pv-modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#646970;line-height:1;}
.pv-modal-body{padding:16px 18px;}
.share-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;}
.share-row label{font-size:12px;color:#646970;display:flex;flex-direction:column;gap:3px;}
.share-row select,.share-row input{padding:6px 8px;border:1px solid #dcdcde;border-radius:6px;background:#fff;}
.emailbox{display:flex;gap:8px;flex-wrap:wrap;}
.emailbox input{flex:1 1 200px;padding:8px 10px;border:1px solid #dcdcde;border-radius:6px;}
.emailbox select{padding:8px 10px;border:1px solid #dcdcde;border-radius:6px;background:#fff;}
.pv-lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;align-items:center;justify-content:center;}
.pv-lightbox.open{display:flex;}
.pv-lb-stage{max-width:90vw;max-height:86vh;display:flex;align-items:center;justify-content:center;}
.pv-lb-slide{display:none;max-width:90vw;max-height:86vh;}
.pv-lb-slide img{max-width:90vw;max-height:86vh;object-fit:contain;border-radius:6px;}
.pv-lb-close,.pv-lb-prev,.pv-lb-next{position:absolute;background:rgba(255,255,255,.12);border:none;color:#fff;cursor:pointer;font-size:26px;line-height:1;border-radius:50%;width:44px;height:44px;}
.pv-lb-close{top:14px;right:14px;}
.pv-lb-prev{left:14px;top:50%;transform:translateY(-50%);}
.pv-lb-next{right:14px;top:50%;transform:translateY(-50%);}
.pv-lb-close:hover,.pv-lb-prev:hover,.pv-lb-next:hover{background:rgba(255,255,255,.28);}
.pv-lb-counter{position:absolute;bottom:16px;left:50%;transform:translateX(-50%);color:#fff;font-size:13px;background:rgba(0,0,0,.5);padding:3px 12px;border-radius:12px;}
@media (max-width:720px){table.detail td.k{width:130px;}.pv-lb-prev{left:6px;}.pv-lb-next{right:6px;}}
</style></head><body><div class="wrap">'
			. \Estandar\Core\LeadsPanel::brand_top( $title, $right )
			. $body
			. '</div></body></html>';
	}

	public static function render_viewer( array $rows, array $filters, string $export_base, int $total = 0 ): string
	{
		$esc = static function ( string $v ): string {
			return esc_html( $v );
		};
		$q = '';
		foreach ( array( 'from', 'to', 'project', 'name' ) as $k ) {
			if ( ! empty( $filters[ $k ] ) ) {
				$q .= '&' . $k . '=' . rawurlencode( (string) $filters[ $k ] );
			}
		}
		// Estado del orden (default: fecha DESC = más reciente primero).
		$orderby = sanitize_key( (string) ( $filters['orderby'] ?? 'created_at' ) );
		$order   = strtolower( (string) ( $filters['order'] ?? 'desc' ) ) === 'asc' ? 'asc' : 'desc';
		$th = static function ( string $label, string $key ) use ( $filters, $orderby, $order ) {
			$next = ( $orderby === $key && $order === 'desc' ) ? 'asc' : 'desc';
			$arrow = $orderby === $key ? ( $order === 'desc' ? ' ↓' : ' ↑' ) : '';
			$qs = array();
			foreach ( array( 'from', 'to', 'project', 'name', 'access' ) as $k ) {
				if ( ! empty( $filters[ $k ] ) ) {
					$qs[ $k ] = (string) $filters[ $k ];
				}
			}
			$qs['orderby'] = $key;
			$qs['order']   = $next;
			$href = '?' . http_build_query( $qs );
			return '<a href="' . esc_attr( $href ) . '" style="color:inherit;text-decoration:none;" title="Ordenar por ' . esc_attr( $label ) . '">'
				. esc_html( $label ) . '<span style="color:#f95b1f;">' . $arrow . '</span></a>';
		};
		$project_opts = '';
		foreach ( self::project_options() as $po ) {
			$project_opts .= '<option value="' . esc_attr( $po ) . '"' . selected( (string) ( $filters['project'] ?? '' ), $po, false ) . '>' . $esc( $po ) . '</option>';
		}

		$rows_html = '';
		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
		$detail_base = home_url( '/' . self::PANEL_SLUG . '/' )
			. ( ! empty( $filters['access'] ) ? '?access=' . rawurlencode( (string) $filters['access'] ) : '' );
		$detail_sep = strpos( $detail_base, '?' ) === false ? '?' : '&';
		foreach ( $rows as $r ) {
			$row_actions = '<a class="btn small" href="' . esc_url( $detail_base . $detail_sep . 'pv=' . (int) $r['id'] ) . '">Ver solicitud</a>';
			if ( $is_admin ) {
				$del_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=estandar_suite_capture_postventa_delete&id=' . (int) $r['id'] ),
					'estandar_suite_capture_postventa_delete'
				);
				$row_actions .= ' <a class="btn small" style="background:#b32d2e;color:#fff;" href="' . esc_url( $del_url ) . '" onclick="return confirm(\'¿Eliminar esta solicitud?\');">Eliminar</a>';
			}
			$rows_html .= '<tr>'
				. ( $is_admin ? '<td class="nowrap" data-label="Sel"><input type="checkbox" name="submission_ids[]" value="' . (int) $r['id'] . '" style="width:auto;"></td>' : '' )
				. '<td class="nowrap" data-label="ID">' . esc_html( (string) $r['gf_id'] ) . '</td>'
				. '<td class="nowrap" data-label="Fecha">' . $esc( (string) $r['created_at'] ) . '</td>'
				. '<td data-label="Proyecto">' . ( ! empty( $r['project'] ) ? '<span class="tag">' . $esc( (string) $r['project'] ) . '</span>' : '<span class="muted">—</span>' ) . '</td>'
				. '<td data-label="Nombre"><strong>' . $esc( (string) $r['name'] ) . '</strong></td>'
				. '<td class="nowrap" data-label="Teléfono">' . $esc( (string) $r['phone'] ) . '</td>'
				. '<td data-label="Email"><a href="mailto:' . esc_attr( $r['email'] ) . '">' . $esc( (string) $r['email'] ) . '</a></td>'
				. '<td data-label="Tipo">' . $esc( (string) $r['type'] ) . '</td>'
				. '<td data-label="N° vivienda">' . $esc( (string) $r['unit'] ) . '</td>'
				. '<td class="nowrap" data-label="Solicitud">' . $row_actions . '</td>'
				. '</tr>';
		}
		if ( empty( $rows ) ) {
			$rows_html = '<tr><td colspan="' . ( $is_admin ? 11 : 10 ) . '">Sin casos de postventa para los filtros.</td></tr>';
		}

		$operator = \Estandar\Core\LeadsPanel::current_operator();
		$right = $operator !== ''
			? '<span>👤 ' . $esc( $operator ) . '</span><a href="' . esc_url( home_url( '/' . self::PANEL_SLUG . '/?logout=1' ) ) . '" style="color:#fff;">Salir</a>'
			: '';

		return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panel Postventa</title>
<style>body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f6f7f7;color:#1d2327;}
.wrap{max-width:1280px;margin:0 auto;padding:24px 16px;}
.sub{color:#646970;font-size:13px;margin-bottom:18px;}
.card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin-bottom:16px;}
form.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;}
form.filters label{font-size:12px;color:#646970;display:flex;flex-direction:column;gap:3px;}
form.filters input,form.filters select{padding:6px 8px;border:1px solid #dcdcde;border-radius:6px;background:#fff;}
.btn{display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:8px 14px;border-radius:6px;font-size:13px;font-weight:600;margin-right:8px;}
.btn.green{background:#00a32a;}
.btn.small{padding:4px 10px;font-size:12px;background:#7e57c2;}
.muted{color:#8c8f94;font-size:12px;}
.tag{display:inline-block;background:#f0f6fc;color:#2271b1;border-radius:10px;padding:1px 8px;font-size:11px;margin-top:3px;}
table{width:100%;border-collapse:collapse;background:#fff;font-size:13px;}
th,td{border:1px solid #dcdcde;padding:7px 9px;text-align:left;vertical-align:middle;}
th{background:#f0f6fc;font-weight:600;white-space:nowrap;}
.nowrap{white-space:nowrap;}
.meta{color:#646970;font-size:12px;margin-top:10px;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:flex-start;justify-content:center;padding:24px 12px;overflow-y:auto;}
.modal.open{display:flex;}
.modal-card{background:#fff;border-radius:12px;max-width:560px;width:100%;box-shadow:0 12px 40px rgba(0,0,0,.25);}
.modal-head{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #f0f0f1;font-size:15px;}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#646970;line-height:1;}
.modal-body{padding:16px 18px;}
.emailbox{display:flex;gap:8px;margin:8px 0 6px;flex-wrap:wrap;}
.emailbox input{flex:1 1 220px;padding:8px 10px;border:1px solid #dcdcde;border-radius:6px;}
.emailbox select{padding:8px 10px;border:1px solid #dcdcde;border-radius:6px;background:#fff;}
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
@media (max-width:720px){
.wrap{padding:12px 10px;}
table thead{display:none;}
table,table tbody,table tr,table td{display:block;width:100%;box-sizing:border-box;}
table tr{background:#fff;border:1px solid #dcdcde;border-radius:10px;margin-bottom:10px;padding:6px 12px;}
table td{display:flex;justify-content:space-between;align-items:center;gap:12px;border:none;border-bottom:1px solid #f0f0f1;padding:8px 0;}
td::before{content:attr(data-label);font-weight:600;color:#646970;font-size:12px;white-space:nowrap;flex-shrink:0;}
}
</style></head><body><div class="wrap">'
			. \Estandar\Core\LeadsPanel::brand_top( 'Panel Postventa', $right )
			. '<div class="sub">Casos de postventa (puente Gravity Forms → Suite v2). Los datos quedan registrados y publicados aquí para seguimiento del equipo.</div>'

			. '<div class="toolbar">
<button type="button" id="pv-open-modal" class="btn">⚙ Filtros y descargas</button>
<span class="meta">Mostrando los últimos 30 · ' . (int) $total . ' en total</span>
</div>

<div id="pv-modal" class="modal" aria-hidden="true">
<div class="modal-card">
<div class="modal-head"><strong>Filtros y descargas</strong><button type="button" id="pv-close-modal" class="modal-close" aria-label="Cerrar">✕</button></div>
<div class="modal-body">
<form class="filters" method="get">
<input type="hidden" name="access" value="' . esc_attr( (string) ( $filters['access'] ?? '' ) ) . '">
<label>Desde <input type="date" name="from" value="' . esc_attr( (string) ( $filters['from'] ?? '' ) ) . '"></label>
<label>Hasta <input type="date" name="to" value="' . esc_attr( (string) ( $filters['to'] ?? '' ) ) . '"></label>
<label>Proyecto <select name="project"><option value="">Todos</option>' . $project_opts . '</select></label>
<label>Nombre / email / tel <input type="text" name="name" value="' . esc_attr( (string) ( $filters['name'] ?? '' ) ) . '" placeholder="Buscar…"></label>
<button class="btn" type="submit">Filtrar</button>
</form>
<hr style="border:none;border-top:1px solid #f0f0f1;margin:14px 0;">
<strong>Descargar (por correo)</strong>
<div class="emailbox">
<input type="email" id="pv-send-to" placeholder="destino@correo.cl" required>
<select id="pv-send-fmt"><option value="csv">CSV</option><option value="xlsx">Excel</option><option value="pdf">PDF</option></select>
<button type="button" id="pv-send-btn" class="btn green">Enviar</button>
</div>
<div id="pv-send-msg" class="meta"></div>
</div>
</div>
</div>

<div class="card">
' . ( $is_admin
	? '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'¿Aplicar la acción a las solicitudes seleccionadas?\');">'
		. wp_nonce_field( 'estandar_suite_capture_postventa_bulk', '_wpnonce', true, false )
		. '<input type="hidden" name="action" value="estandar_suite_capture_postventa_bulk">
<div class="bulkbar" style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
<select name="bulk_action" style="padding:6px 8px;border:1px solid #dcdcde;border-radius:6px;background:#fff;">
<option value="">Acciones masivas</option>
<option value="delete">Eliminar seleccionadas</option>
</select>
<button type="submit" class="btn" style="margin:0;">Aplicar</button>
<span class="meta" id="pv-sel-count">0 seleccionadas</span>
</div>' : '' )
. '<table><thead><tr>'
. ( $is_admin ? '<th style="width:32px;"><input type="checkbox" id="pv-check-all" style="width:auto;"></th>' : '' )
. '<th>' . $th( 'ID', 'gf_id' ) . '</th><th>' . $th( 'Fecha', 'created_at' ) . '</th><th>' . $th( 'Proyecto', 'project' ) . '</th><th>' . $th( 'Nombre', 'name' ) . '</th><th>' . $th( 'Teléfono', 'phone' ) . '</th><th>' . $th( 'Email', 'email' ) . '</th><th>' . $th( 'Tipo', 'type' ) . '</th><th>' . $th( 'N° vivienda', 'unit' ) . '</th><th>Solicitud</th>
</tr></thead><tbody>' . $rows_html . '</tbody></table>'
. ( $is_admin ? '</form>' : '' )
. '</div>
<script>
(function(){
  var selAll = document.getElementById("pv-check-all");
  var selCount = document.getElementById("pv-sel-count");
  var selBoxes = function(){ return document.querySelectorAll(\'input[name="submission_ids[]"]\'); };
  if (selAll) {
    selAll.addEventListener("change", function(){
      var checked = selAll.checked;
      selBoxes().forEach(function(cb){ cb.checked = checked; });
      if (selCount) { selCount.textContent = (checked ? selBoxes().length : 0) + " seleccionadas"; }
    });
  }
  if (selCount) {
    selBoxes().forEach(function(cb){
      cb.addEventListener("change", function(){
        var n = document.querySelectorAll(\'input[name="submission_ids[]"]:checked\').length;
        selCount.textContent = n + " seleccionadas";
        if (selAll) { selAll.checked = n > 0 && n === selBoxes().length; }
      });
    });
  }
  var modal = document.getElementById("pv-modal");
  var openBtn = document.getElementById("pv-open-modal");
  var closeBtn = document.getElementById("pv-close-modal");
  function openModal(){ if(modal){ modal.classList.add("open"); modal.setAttribute("aria-hidden","false"); } }
  function closeModal(){ if(modal){ modal.classList.remove("open"); modal.setAttribute("aria-hidden","true"); } }
  if (openBtn) { openBtn.addEventListener("click", openModal); }
  if (closeBtn) { closeBtn.addEventListener("click", closeModal); }
  if (modal) { modal.addEventListener("click", function (e) { if (e.target === modal) { closeModal(); } }); }
})();
(function(){
  var to = document.getElementById("pv-send-to");
  var fmt = document.getElementById("pv-send-fmt");
  var btn = document.getElementById("pv-send-btn");
  var msg = document.getElementById("pv-send-msg");
  if (!to || !fmt || !btn || !msg) { return; }
  var url = ' . wp_json_encode( rest_url( self::NS . '/postventa/email' ) ) . ';
  var qs = ' . wp_json_encode( $q ) . ';
  var nonce = ' . wp_json_encode( function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '' ) . ';
  btn.addEventListener("click", function () {
    var email = to.value.trim();
    if (!email) { msg.textContent = "Ingresa el correo destino."; return; }
    btn.disabled = true; msg.textContent = "Enviando…";
    fetch(url + "?email=" + encodeURIComponent(email) + "&format=" + fmt.value + qs, {
      method: "POST", credentials: "same-origin",
      headers: { "X-WP-Nonce": nonce, "Content-Type": "application/json" }
    }).then(function (r) { return r.json(); }).then(function (d) {
      btn.disabled = false;
      msg.textContent = d && d.ok ? ("Enviado a " + d.to + " (" + d.format + " · " + d.rows + " registros).") : (d && d.message ? "Error: " + d.message : "Error al enviar.");
    }).catch(function () { btn.disabled = false; msg.textContent = "Error de conexión."; });
  });
})();
</script>
</div></body></html>';
	}
}
