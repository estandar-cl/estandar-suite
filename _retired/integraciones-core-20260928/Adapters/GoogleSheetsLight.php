<?php

namespace Estandar\Core\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Estandar\Core\Http;

/**
 * Google Sheets "liviano" destination — NO OAuth at runtime.
 *
 * The user publishes an Apps Script Web App with "Anyone can access" (the
 * Suite generates and deploys it via the Apps Script API; the user only does
 * the single Google "Implementar" click). At delivery time we just POST the
 * mapped row to the web-app URL; the script (running as its owner) appends it
 * to the spreadsheet. Works with a sheet shared as "viewer" for reading and
 * editable by the script owner.
 *
 * Config keys:
 *   - webapp_url      the Apps Script deployment URL (.../exec)
 *   - spreadsheet_id  the target spreadsheet (for the script's openById)
 *   - sheet_title     optional sheet name (default: first sheet)
 *   - columns         header row, in order (mapping target)
 */
class GoogleSheetsLight implements AdapterInterface, NoOAuth
{
	const API = 'https://script.google.com/macros/s';

	public function key(): string
	{
		return 'google_sheets_light';
	}

	public function label(): string
	{
		return 'Google Sheets liviano (sin OAuth)';
	}

	public function provider(): string
	{
		return 'google';
	}

	/**
	 * Scopes required at AUTHORIZATION time (used by the assistant to deploy
	 * the Apps Script web app and to pick the spreadsheet). At RUNTIME the
	 * adapter is NoOAuth: it just POSTs to the public web-app URL.
	 */
	public function scopes(): array
	{
		return array(
			'https://www.googleapis.com/auth/script.projects',
			'https://www.googleapis.com/auth/script.deployments',
			'https://www.googleapis.com/auth/spreadsheets',
			'https://www.googleapis.com/auth/drive.file',
			'https://www.googleapis.com/auth/drive.readonly',
		);
	}

	private function webapp_url( array $config ): string
	{
		return (string) ( $config['webapp_url'] ?? '' );
	}

	/** POST a payload to the web app. Body shape matches the generated script. */
	private function post( array $config, array $payload ): array
	{
		$url = $this->webapp_url( $config );
		if ( $url === '' ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'Sin webapp_url (publica el Apps Script como web app primero).' );
		}
		$r = Http::post( $url, '', array( 'json' => $payload, 'timeout' => 30 ) );
		if ( empty( $r['ok'] ) ) {
			return array( 'ok' => false, 'http' => $r['http'], 'error' => $r['error'], 'summary' => '' );
		}
		// The script echoes {ok:true, row:...} or {ok:false, error:...}.
		$body = is_array( $r['body'] ) ? $r['body'] : array();
		if ( ! empty( $body['ok'] ) ) {
			return array( 'ok' => true, 'http' => $r['http'], 'error' => '', 'summary' => (string) ( $body['row'] ?? 'fila escrita' ) );
		}
		return array( 'ok' => false, 'http' => $r['http'], 'error' => (string) ( $body['error'] ?? $r['error'] ), 'summary' => '' );
	}

	public function test( array $connection, array $config ): array
	{
		return $this->post( $config, array(
			'estandar_test' => true,
			'spreadsheet_id' => (string) ( $config['spreadsheet_id'] ?? '' ),
			'sheet_title'   => (string) ( $config['sheet_title'] ?? '' ),
			'columns'       => array_values( array_filter( (array) ( $config['columns'] ?? array() ) ) ),
			'values'        => array_values( array_filter( (array) ( $config['columns'] ?? array() ) ) ),
		) );
	}

	/** No OAuth: nothing to discover via Drive here. */
	public function discover( array $connection, array $config, array $query = array() ): array
	{
		return array( 'ok' => false, 'items' => array(), 'error' => 'google_sheets_light no descubre hojas; usa el asistente para crearla/deployar el script.' );
	}

	/** No OAuth: creation is done by the assistant (Apps Script deploy). */
	public function create( array $connection, array $config ): array
	{
		return array( 'ok' => false, 'config' => array(), 'error' => 'google_sheets_light no crea hojas; usa el asistente de conexión liviana.' );
	}

	/** No OAuth: column discovery is done by the assistant from the sheet. */
	public function columns( array $connection, array $config ): array
	{
		return array( 'ok' => false, 'columns' => array(), 'error' => 'google_sheets_light no lee columnas sin OAuth; defínelas en el asistente.' );
	}

	public function write( array $connection, array $config, array $row ): array
	{
		return $this->post( $config, array(
			'estandar_suite' => true,
			'spreadsheet_id' => (string) ( $config['spreadsheet_id'] ?? '' ),
			'sheet_title'   => (string) ( $config['sheet_title'] ?? '' ),
			'columns'       => array_values( array_filter( (array) ( $config['columns'] ?? array() ) ) ),
			'values'        => array_values( array_map( 'strval', $row ) ),
		) );
	}
}
