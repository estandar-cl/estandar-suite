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
}
