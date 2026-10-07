<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST helpers for the integrations wizard. Thin adapters over the engine:
 * resolve an adapter + connection + config from request params and delegate to
 * the transport. All routes require manage_options (enforced by Rest.php).
 */
class IntegrationsRest
{
	private static function connection( \WP_REST_Request $request ): array
	{
		$conn = Connections::get( (int) $request->get_param( 'connection_id' ) );
		if ( ! $conn ) {
			return array( 'ok' => false, 'error' => 'conexión no encontrada' );
		}
		return $conn;
	}

	private static function adapter( \WP_REST_Request $request ): ?\Estandar\Core\Adapters\AdapterInterface
	{
		$key = sanitize_key( (string) $request->get_param( 'adapter' ) );
		return Adapters::get( $key );
	}

	private static function config( \WP_REST_Request $request ): array
	{
		$raw = $request->get_param( 'config' );
		return is_array( $raw ) ? $raw : array();
	}

	public static function discover( \WP_REST_Request $request ): array
	{
		$adapter = self::adapter( $request );
		$conn    = self::connection( $request );
		if ( ! $adapter ) {
			return array( 'ok' => false, 'items' => array(), 'error' => 'adapter inválido' );
		}
		if ( isset( $conn['error'] ) ) {
			return array( 'ok' => false, 'items' => array(), 'error' => $conn['error'] );
		}
		return $adapter->discover( $conn, self::config( $request ), array( 'search' => (string) $request->get_param( 'search' ) ) );
	}

	public static function columns( \WP_REST_Request $request ): array
	{
		$adapter = self::adapter( $request );
		$conn    = self::connection( $request );
		if ( ! $adapter ) {
			return array( 'ok' => false, 'columns' => array(), 'error' => 'adapter inválido' );
		}
		if ( isset( $conn['error'] ) ) {
			return array( 'ok' => false, 'columns' => array(), 'error' => $conn['error'] );
		}
		return $adapter->columns( $conn, self::config( $request ) );
	}

	/** Build the row that WOULD be written (no transport call). */
	public static function preview( \WP_REST_Request $request ): array
	{
		$config   = self::config( $request );
		$mapping  = (array) $request->get_param( 'mapping' );
		$entry    = (array) $request->get_param( 'entry' );
		$headers  = array_values( array_filter( (array) ( $config['columns'] ?? array() ) ) );
		$system   = array_values( array_filter( (array) ( $config['system_fields'] ?? array() ) ) );
		$meta     = array(
			'entry_id'           => (string) ( $entry['id'] ?? '' ),
			'form_id'            => (string) ( $entry['source_form_id'] ?? '' ),
			'created_at'         => (string) ( $entry['created_at'] ?? '' ),
			'source_url'         => (string) ( $entry['landing_url'] ?? $entry['referrer'] ?? '' ),
			'user_ip'            => FieldMap::anonymize_ip( (string) ( $entry['ip_address'] ?? '' ) ),
			'integration_status' => 'ok',
		);
		$row = FieldMap::build_row( $entry, $headers, $mapping, $system, $meta );
		return array( 'ok' => true, 'headers' => $headers, 'row' => $row );
	}

	/** Register an adapter destination (create a new workbook/table too). */
	public static function register_destination( \WP_REST_Request $request ): array
	{
		$slug  = sanitize_key( (string) $request->get_param( 'slug' ) );
		$type  = sanitize_key( (string) $request->get_param( 'adapter' ) );
		$cid   = (int) $request->get_param( 'connection_id' );
		if ( $slug === '' || $type === '' || $cid <= 0 ) {
			return array( 'ok' => false, 'error' => 'faltan slug/adapter/connection_id' );
		}
		$config = self::config( $request );
		// Optionally materialize the destination (create file/table first).
		if ( ! empty( $request->get_param( 'create' ) ) ) {
			$adapter = Adapters::get( $type );
			$conn    = Connections::get( $cid );
			if ( ! $adapter || ! $conn ) {
				return array( 'ok' => false, 'error' => 'adapter o conexión inválidos' );
			}
			$created = $adapter->create( $conn, $config );
			if ( empty( $created['ok'] ) ) {
				return $created;
			}
			$config = array_merge( $config, $created['config'] );
		}
		$id = Destinations::register_adapter( $slug, $type, $cid, $config );
		return $id > 0 ? array( 'ok' => true, 'id' => $id, 'config' => $config ) : array( 'ok' => false, 'error' => 'no se pudo registrar el destino' );
	}

	/** Bind a form to a destination with a field mapping. */
	public static function bind( \WP_REST_Request $request ): array
	{
		$form_source = sanitize_key( (string) $request->get_param( 'form_source' ) );
		$form_id     = (int) $request->get_param( 'form_id' );
		$dest_id     = (int) $request->get_param( 'destination_id' );
		$mapping     = (array) $request->get_param( 'mapping' );
		$enabled     = (int) $request->get_param( 'enabled' ) !== 0;
		$opts        = array(
			'mode'         => sanitize_key( (string) $request->get_param( 'mode' ) ),
			'priority'     => (int) $request->get_param( 'priority' ),
			'error_policy' => sanitize_key( (string) $request->get_param( 'error_policy' ) ),
			'condition'    => (array) $request->get_param( 'condition' ),
		);
		$id = Integrations::bind( $form_source, $form_id, $dest_id, $mapping, $enabled, $opts );
		return $id > 0 ? array( 'ok' => true, 'id' => $id ) : array( 'ok' => false, 'error' => 'no se pudo vincular el formulario' );
	}

	/** Register an active sandbox connection (no OAuth — validation mode). */
	public static function sandbox_connect(): array
	{
		$id = Connections::register( 'sandbox', 'Simulación', array( 'access_token' => 'sandbox' ), array(), array() );
		return $id > 0 ? array( 'ok' => true, 'id' => $id ) : array( 'ok' => false, 'error' => 'no se pudo crear la conexión sandbox' );
	}

	/** Destination row + decoded config for the wizard. */
	public static function destination( \WP_REST_Request $request ): array
	{		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return array( 'ok' => false, 'error' => 'destino inválido' );
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, slug, type, connection_id, config_json, enabled FROM {$wpdb->prefix}estandar_core_destinations WHERE id = %d LIMIT 1",
			$id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array( 'ok' => false, 'error' => 'destino no encontrado' );
		}
		$config = json_decode( (string) ( $row['config_json'] ?? '{}' ), true );
		$row['config'] = is_array( $config ) ? $config : array();
		$row['columns'] = array_values( array_filter( (array) ( $row['config']['columns'] ?? array() ) ) );
		return $row;
	}

	/**
	 * Light connection assistant: deploy the Apps Script web app and register
	 * a google_sheets_light destination. Returns the deployment URL + editor
	 * URL + the single Google click instructions.
	 */
	public static function light_deploy( \WP_REST_Request $request ): array
	{
		$slug      = sanitize_key( (string) $request->get_param( 'slug' ) );
		$conn_id   = (int) $request->get_param( 'connection_id' );
		$sheet_id  = (string) $request->get_param( 'spreadsheet_id' );
		$sheet_tit = sanitize_text_field( (string) $request->get_param( 'sheet_title' ) );
		$columns   = (array) $request->get_param( 'columns' );
		if ( $slug === '' || $conn_id <= 0 || $sheet_id === '' ) {
			return array( 'ok' => false, 'error' => 'faltan slug/connection_id/spreadsheet_id' );
		}
		$conn = Connections::get( $conn_id );
		if ( ! $conn ) {
			return array( 'ok' => false, 'error' => 'conexión no encontrada' );
		}
		$tok = Connections::access_token( $conn_id );
		if ( empty( $tok['ok'] ) || $tok['token'] === '' ) {
			return array( 'ok' => false, 'error' => 'sin token vigente para esta cuenta' );
		}
		$title  = 'Estandar Suite — ' . $slug;
		$deploy = AppScriptDeploy::deploy( $tok['token'], $title, $sheet_id, $sheet_tit );
		if ( empty( $deploy['ok'] ) ) {
			return array( 'ok' => false, 'error' => $deploy['error'] ?? 'deploy fallido' );
		}
		$config = array(
			'webapp_url'     => $deploy['url'],
			'spreadsheet_id' => $sheet_id,
			'sheet_title'    => $sheet_tit,
			'columns'        => array_values( array_filter( array_map( 'sanitize_text_field', $columns ) ) ),
			'script_id'      => $deploy['script_id'],
		);
		$id = Destinations::register_adapter( $slug, 'google_sheets_light', $conn_id, $config );
		if ( $id <= 0 ) {
			return array( 'ok' => false, 'error' => 'no se pudo registrar el destino liviano' );
		}
		return array(
			'ok'           => true,
			'id'           => $id,
			'webapp_url'   => $deploy['url'],
			'editor_url'   => $deploy['editor_url'],
			'script_id'    => $deploy['script_id'],
			'deployment_id'=> $deploy['deployment_id'],
			'steps'        => array(
				'1. Abre el editor de Apps Script (botón de abajo).',
				'2. Implementar → Nueva implementación → Aplicación web.',
				'3. "Ejecutar como" = Yo · "Quién tiene acceso" = Cualquier persona → Implementar.',
				'4. Vuelve aquí y pulsa Probar para validar la escritura en la hoja.',
			),
		);
	}

	/** Update config of an existing light destination (e.g. columns). */
	public static function light_update( \WP_REST_Request $request ): array
	{
		$id       = (int) $request->get_param( 'id' );
		$columns  = (array) $request->get_param( 'columns' );
		$sheet_id = sanitize_text_field( (string) $request->get_param( 'spreadsheet_id' ) );
		$row      = Destinations::list();
		$found    = null;
		foreach ( $row as $d ) {
			if ( (int) $d['id'] === $id ) { $found = $d; break; }
		}
		if ( ! $found ) {
			return array( 'ok' => false, 'error' => 'destino no encontrado' );
		}
		$config = json_decode( (string) ( $found['config_json'] ?? '{}' ), true );
		$config = is_array( $config ) ? $config : array();
		if ( $sheet_id !== '' ) { $config['spreadsheet_id'] = $sheet_id; }
		if ( $columns ) { $config['columns'] = array_values( array_filter( array_map( 'sanitize_text_field', $columns ) ) ); }
		$n = Destinations::update_adapter_config( $id, $config );
		return $n > 0 ? array( 'ok' => true, 'id' => $id, 'config' => $config ) : array( 'ok' => false, 'error' => 'no se pudo actualizar' );
	}

	/**
	 * Assistant — inspect the current columns of a spreadsheet vs the form's
	 * fields. Returns {ok, headers, missing, extra, aligned}. No writes.
	 */
	public static function sheet_check( \WP_REST_Request $request ): array
	{
		$conn_id = (int) $request->get_param( 'connection_id' );
		$sheet_id = sanitize_text_field( (string) $request->get_param( 'spreadsheet_id' ) );
		$sheet_tit = sanitize_text_field( (string) $request->get_param( 'sheet_title' ) );
		$fields    = (array) $request->get_param( 'fields' ); // [key => label] del form
		if ( $conn_id <= 0 || $sheet_id === '' ) {
			return array( 'ok' => false, 'error' => 'faltan connection_id/spreadsheet_id' );
		}
		$conn = Connections::get( $conn_id );
		if ( ! $conn ) {
			return array( 'ok' => false, 'error' => 'conexión no encontrada' );
		}
		$adapter = Adapters::get( 'google_sheets' );
		$res = $adapter->columns( $conn, array( 'spreadsheet_id' => $sheet_id, 'sheet_title' => $sheet_tit ) );
		if ( empty( $res['ok'] ) ) {
			return array( 'ok' => false, 'error' => $res['error'] ?? 'no se pudo leer la hoja' );
		}
		$headers = array_values( array_filter( (array) ( $res['columns'] ?? array() ) ) );
		$desired = array_values( array_filter( array_map( 'sanitize_text_field', $fields ) ) );
		$missing = array_values( array_diff( $desired, $headers ) );
		$extra   = array_values( array_diff( $headers, $desired ) );
		return array(
			'ok'      => true,
			'headers' => $headers,
			'missing' => $missing,
			'extra'   => $extra,
			'aligned' => empty( $missing ) && empty( $extra ),
		);
	}

	/** Assistant — rename the header row to match the form's fields (only titles
	 *  are written; existing data rows are untouched). */
	public static function sheet_force( \WP_REST_Request $request ): array
	{
		$conn_id = (int) $request->get_param( 'connection_id' );
		$sheet_id = sanitize_text_field( (string) $request->get_param( 'spreadsheet_id' ) );
		$sheet_tit = sanitize_text_field( (string) $request->get_param( 'sheet_title' ) );
		$fields    = (array) $request->get_param( 'fields' );
		if ( $conn_id <= 0 || $sheet_id === '' ) {
			return array( 'ok' => false, 'error' => 'faltan connection_id/spreadsheet_id' );
		}
		$conn = Connections::get( $conn_id );
		if ( ! $conn ) {
			return array( 'ok' => false, 'error' => 'conexión no encontrada' );
		}
		$adapter = Adapters::get( 'google_sheets' );
		$headers = array_values( array_filter( array_map( 'sanitize_text_field', $fields ) ) );
		if ( empty( $headers ) ) {
			return array( 'ok' => false, 'error' => 'sin campos para escribir como columnas' );
		}
		$res = $adapter->set_headers( $conn, array( 'spreadsheet_id' => $sheet_id, 'sheet_title' => $sheet_tit ), $headers );
		return array( 'ok' => $res['ok'], 'error' => $res['error'], 'summary' => $res['summary'], 'headers' => $headers );
	}

	/** Assistant — create a NEW spreadsheet with the form's fields as columns. */
	public static function sheet_create( \WP_REST_Request $request ): array
	{
		$conn_id = (int) $request->get_param( 'connection_id' );
		$title   = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$sheet_tit = sanitize_text_field( (string) $request->get_param( 'sheet_title' ) );
		$fields    = (array) $request->get_param( 'fields' );
		if ( $conn_id <= 0 || $title === '' ) {
			return array( 'ok' => false, 'error' => 'faltan connection_id/title' );
		}
		$conn = Connections::get( $conn_id );
		if ( ! $conn ) {
			return array( 'ok' => false, 'error' => 'conexión no encontrada' );
		}
		$adapter = Adapters::get( 'google_sheets' );
		$headers = array_values( array_filter( array_map( 'sanitize_text_field', $fields ) ) );
		$config  = array( 'title' => $title, 'sheet_title' => $sheet_tit, 'columns' => $headers );
		$res = $adapter->create( $conn, $config );
		if ( empty( $res['ok'] ) ) {
			return array( 'ok' => false, 'error' => $res['error'] ?? 'no se pudo crear la hoja' );
		}
		return array( 'ok' => true, 'error' => '', 'config' => $res['config'] ?? $config );
	}

	/** Assistant — create the destination + bind the form with an auto mapping,
	 *  so every entry of that form writes a row into the sheet. */
	public static function connect( \WP_REST_Request $request ): array
	{
		$slug       = sanitize_key( (string) $request->get_param( 'slug' ) );
		$conn_id    = (int) $request->get_param( 'connection_id' );
		$form_source= sanitize_key( (string) $request->get_param( 'form_source' ) );
		$form_id    = (int) $request->get_param( 'form_id' );
		$config     = (array) $request->get_param( 'config' ); // spreadsheet_id/sheet_title/columns
		$mapping    = (array) $request->get_param( 'mapping' ); // field key => column
		if ( $slug === '' || $conn_id <= 0 || $form_source === '' || $form_id <= 0 ) {
			return array( 'ok' => false, 'error' => 'faltan slug/connection_id/form_source/form_id' );
		}
		$config = array_merge( array( 'spreadsheet_id' => '', 'sheet_title' => '', 'columns' => array() ), $config );
		$columns = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $config['columns'] ?? array() ) ) ) );
		$config['columns'] = $columns;
		if ( (string) $config['spreadsheet_id'] === '' ) {
			return array( 'ok' => false, 'error' => 'falta spreadsheet_id (crea o elige una hoja primero)' );
		}
		$dest_id = Destinations::register_adapter( $slug, 'google_sheets', $conn_id, $config );
		if ( $dest_id <= 0 ) {
			return array( 'ok' => false, 'error' => 'no se pudo registrar el destino' );
		}
		$clean_map = array();
		foreach ( $mapping as $fk => $col ) {
			if ( is_string( $col ) && $col !== '' ) {
				$clean_map[ sanitize_key( (string) $fk ) ] = $col;
			}
		}
		$bind_id = Integrations::bind( $form_source, $form_id, $dest_id, $clean_map, true );
		if ( $bind_id <= 0 ) {
			return array( 'ok' => false, 'id' => $dest_id, 'error' => 'destino creado (# ' . $dest_id . ') pero no se pudo vincular el form' );
		}
		return array( 'ok' => true, 'id' => $dest_id, 'binding_id' => $bind_id, 'form_source' => $form_source, 'form_id' => $form_id );
	}
}
