<?php

namespace Estandar\Core\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Estandar\Core\Connections;
use Estandar\Core\Http;

/**
 * Excel Online destination adapter (Microsoft Graph). Multi-tenant by design:
 * the drive path comes from config/provider_config ('me/drive' for OneDrive,
 * 'sites/{site}/drive' or '/drives/{driveId}' for SharePoint), so one install
 * can connect to several orgs/tenants. Append requires a structured table
 * (Graph has no worksheet-append), which matches the wizard's "tabla
 * estructurada" step.
 */
class ExcelOnline implements AdapterInterface
{
	const GRAPH = 'https://graph.microsoft.com/v1.0';

	public function key(): string
	{
		return 'excel_online';
	}

	public function label(): string
	{
		return 'Excel Online';
	}

	public function provider(): string
	{
		return 'microsoft';
	}

	public function scopes(): array
	{
		return array( 'Files.ReadWrite.All', 'Sites.ReadWrite.All', 'offline_access' );
	}

	private function token( array $connection ): array
	{
		return Connections::access_token( (int) ( $connection['id'] ?? 0 ) );
	}

	/** Drive path: config override → connection provider_config → 'me/drive'. */
	private function drive( array $connection, array $config ): string
	{
		$from_config = trim( (string) ( $config['drive_path'] ?? '' ) );
		if ( $from_config !== '' ) {
			return ltrim( $from_config, '/' );
		}
		$from_conn = trim( (string) ( $connection['provider_config']['drive_path'] ?? '' ) );
		return $from_conn === '' ? 'me/drive' : ltrim( $from_conn, '/' );
	}

	private function table_url( array $connection, array $config, string $suffix = '' ): string
	{
		$item  = rawurlencode( (string) ( $config['item_id'] ?? '' ) );
		$table = rawurlencode( (string) ( $config['table_id'] ?? '' ) );
		return self::GRAPH . '/' . $this->drive( $connection, $config ) . '/items/' . $item . '/workbook/tables/' . $table . $suffix;
	}

	public function test( array $connection, array $config ): array
	{
		$tok = $this->token( $connection );
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => $tok['error'] ?? 'sin token' );
		}
		$item = (string) ( $config['item_id'] ?? '' );
		if ( $item === '' ) {
			// No file yet → identity check proves the account works.
			$r = Http::get( self::GRAPH . '/me', $tok['token'] );
			return array( 'ok' => $r['ok'], 'http' => $r['http'], 'error' => $r['error'] );
		}
		$r = Http::get( self::GRAPH . '/' . $this->drive( $connection, $config ) . '/items/' . rawurlencode( $item ) . '?$select=id,name,file', $tok['token'] );
		return array( 'ok' => $r['ok'], 'http' => $r['http'], 'error' => $r['error'] );
	}

	public function discover( array $connection, array $config, array $query = array() ): array
	{
		$tok = $this->token( $connection );
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'items' => array(), 'error' => $tok['error'] ?? 'sin token' );
		}
		$url = self::GRAPH . '/' . $this->drive( $connection, $config ) . '/root/children?$select=id,name,file,folder,webUrl&$top=100';
		$r   = Http::get( $url, $tok['token'] );
		$items = array();
		foreach ( (array) ( $r['body']['value'] ?? array() ) as $f ) {
			$mime = (string) ( $f['file']['mimeType'] ?? '' );
			$name = (string) ( $f['name'] ?? '' );
			if ( strpos( $mime, 'spreadsheet' ) !== false || preg_match( '/\.xlsx?$/i', $name ) ) {
				$items[] = array( 'id' => (string) ( $f['id'] ?? '' ), 'name' => $name );
			}
		}
		if ( ! empty( $query['search'] ) ) {
			$needle = strtolower( (string) $query['search'] );
			$items  = array_values( array_filter( $items, static function ( $i ) use ( $needle ) {
				return $needle === '' || strpos( strtolower( $i['name'] ), $needle ) !== false;
			} ) );
		}
		return array( 'ok' => $r['ok'], 'items' => $items, 'error' => $r['error'] );
	}

	public function create( array $connection, array $config ): array
	{
		$tok = $this->token( $connection );
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'config' => array(), 'error' => $tok['error'] ?? 'sin token' );
		}
		$item_id = (string) ( $config['item_id'] ?? '' );

		// Copy a template workbook (creates a new .xlsx from an existing one).
		if ( ! empty( $config['template_item_id'] ) ) {
			$tpl = rawurlencode( (string) $config['template_item_id'] );
			$r = Http::post( self::GRAPH . '/' . $this->drive( $connection, $config ) . '/items/' . $tpl . ':copy', $tok['token'], array(
				'json' => array( 'name' => (string) ( $config['title'] ?? 'Nueva planilla' ) ),
			) );
			if ( ! empty( $r['ok'] ) && ! empty( $r['body']['id'] ) ) {
				return array( 'ok' => true, 'config' => array_merge( $config, array( 'item_id' => (string) $r['body']['id'] ) ), 'error' => '' );
			}
			return array( 'ok' => false, 'config' => array(), 'error' => $r['error'] );
		}

		// Add a structured table on an existing workbook + optional header write.
		if ( $item_id !== '' && ! empty( $config['create_table'] ) ) {
			$columns = array_values( array_filter( (array) ( $config['columns'] ?? array() ) ) );
			if ( $columns ) {
				$this->write_headers( $connection, $config, $columns, $tok['token'] );
			}
			$name = trim( (string) ( $config['table_name'] ?? 'Datos' ) );
			$r = Http::post( self::GRAPH . '/' . $this->drive( $connection, $config ) . '/items/' . rawurlencode( $item_id ) . '/workbook/tables/add', $tok['token'], array(
				'json' => array( 'name' => $name, 'hasHeaders' => true ),
			) );
			if ( empty( $r['ok'] ) || empty( $r['body']['id'] ) ) {
				return array( 'ok' => false, 'config' => array(), 'error' => $r['error'] );
			}
			$out = array_merge( $config, array( 'table_id' => (string) $r['body']['id'], 'table_name' => $name ) );
			// Learn the actual headers from the created table.
			$cols = $this->columns( $connection, $out );
			if ( $cols['ok'] && $cols['columns'] ) {
				$out['columns'] = $cols['columns'];
			}
			return array( 'ok' => true, 'config' => $out, 'error' => '' );
		}

		return array(
			'ok'     => false,
			'config' => array(),
			'error'  => 'Excel requiere un archivo .xlsx existente (item_id) o una plantilla (template_item_id).',
		);
	}

	/** PURE-ish: write a header row into the first worksheet (best-effort). */
	private function write_headers( array $connection, array $config, array $columns, string $token ): void
	{
		$item = rawurlencode( (string) ( $config['item_id'] ?? '' ) );
		$sheets = Http::get( self::GRAPH . '/' . $this->drive( $connection, $config ) . '/items/' . $item . '/workbook/worksheets?$select=id,name', $token );
		$sheet = '';
		foreach ( (array) ( $sheets['body']['value'] ?? array() ) as $s ) {
			$sheet = (string) ( $s['name'] ?? '' );
			break;
		}
		if ( $sheet === '' ) {
			return;
		}
		$seg = rawurlencode( "range(address='A1:Z1')" );
		Http::request( 'PATCH', self::GRAPH . '/' . $this->drive( $connection, $config ) . '/items/' . $item . '/workbook/worksheets/' . rawurlencode( $sheet ) . '/' . $seg, $token, array(
			'json' => array( 'values' => array( array_values( $columns ) ) ),
		) );
	}

	public function columns( array $connection, array $config ): array
	{
		$tok = $this->token( $connection );
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'columns' => array(), 'error' => $tok['error'] ?? 'sin token' );
		}
		if ( ! empty( $config['table_id'] ) ) {
			$r = Http::get( $this->table_url( $connection, $config, '/columns?$select=name,index' ), $tok['token'] );
			$cols = array();
			foreach ( (array) ( $r['body']['value'] ?? array() ) as $c ) {
				$cols[] = (string) ( $c['name'] ?? '' );
			}
			return array( 'ok' => $r['ok'], 'columns' => array_values( array_filter( $cols ) ), 'error' => $r['error'] );
		}
		// Fallback: headers of the first worksheet.
		$item = rawurlencode( (string) ( $config['item_id'] ?? '' ) );
		$seg  = rawurlencode( "range(address='A1:Z1')" );
		$r = Http::get( self::GRAPH . '/' . $this->drive( $connection, $config ) . '/items/' . $item . '/workbook/worksheets/0/' . $seg, $tok['token'] );
		$cols = array();
		if ( ! empty( $r['body']['text'][0] ) && is_array( $r['body']['text'][0] ) ) {
			$cols = array_values( array_filter( array_map( 'strval', $r['body']['text'][0] ) ) );
		}
		return array( 'ok' => $r['ok'], 'columns' => $cols, 'error' => $r['error'] );
	}

	public function write( array $connection, array $config, array $row ): array
	{
		if ( empty( $config['table_id'] ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'requiere tabla estructurada (config.table_id)', 'summary' => '' );
		}
		$tok = $this->token( $connection );
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => $tok['error'] ?? 'sin token', 'summary' => '' );
		}
		$r = Http::post( $this->table_url( $connection, $config, '/rows/add' ), $tok['token'], array(
			'json' => array( 'values' => array( array_values( $row ) ) ),
		) );
		if ( empty( $r['ok'] ) ) {
			return array( 'ok' => false, 'http' => $r['http'], 'error' => $r['error'], 'summary' => '' );
		}
		return array( 'ok' => true, 'http' => $r['http'], 'error' => '', 'summary' => '1 fila(s)' );
	}
}
