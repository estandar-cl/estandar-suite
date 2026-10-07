<?php

namespace Estandar\Core\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Estandar\Core\Connections;
use Estandar\Core\Http;

/**
 * Google Sheets destination adapter (Sheets API v4 + Drive API v3 for picking).
 * Scopes are adapter-owned so the OAuth core stays generic. Every operation is
 * guarded (returns error when HTTP is unavailable) so tests can use mocks.
 */
class GoogleSheets implements AdapterInterface
{
	const API   = 'https://sheets.googleapis.com/v4';
	const DRIVE = 'https://www.googleapis.com/drive/v3';

	public function key(): string
	{
		return 'google_sheets';
	}

	public function label(): string
	{
		return 'Google Sheets';
	}

	public function provider(): string
	{
		return 'google';
	}

	public function scopes(): array
	{
		return array(
			'https://www.googleapis.com/auth/spreadsheets',
			'https://www.googleapis.com/auth/drive.file',
			'https://www.googleapis.com/auth/drive.readonly',
		);
	}

	private function token( array $connection ): array
	{
		return Connections::access_token( (int) ( $connection['id'] ?? 0 ) );
	}

	private function sheet( array $config ): string
	{
		$title = trim( (string) ( $config['sheet_title'] ?? '' ) );
		return $title === '' ? 'Sheet1' : $title;
	}

	private function range( array $config, string $cell ): string
	{
		return rawurlencode( $this->sheet( $config ) ) . '!' . $cell;
	}

	public function test( array $connection, array $config ): array
	{
		$id = (string) ( $config['spreadsheet_id'] ?? '' );
		if ( $id === '' ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'Sin spreadsheet_id' );
		}
		$tok = $this->token( $connection );
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => $tok['error'] ?? 'sin token' );
		}
		$r = Http::get( self::API . '/spreadsheets/' . rawurlencode( $id ) . '/values/' . $this->range( $config, 'A1:Z1' ), $tok['token'] );
		return array( 'ok' => $r['ok'], 'http' => $r['http'], 'error' => $r['error'] );
	}

	public function discover( array $connection, array $config, array $query = array() ): array
	{
		$tok = $this->token( $connection );
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'items' => array(), 'error' => $tok['error'] ?? 'sin token' );
		}
		$q = "mimeType='application/vnd.google-apps.spreadsheet' and trashed=false";
		$url = self::DRIVE . '/files?q=' . rawurlencode( $q ) . '&fields=files(id,name,modifiedTime)&pageSize=100&orderBy=modifiedTime%20desc';
		if ( ! empty( $query['search'] ) ) {
			$url .= '&q2=' . rawurlencode( (string) $query['search'] );
		}
		$r  = Http::get( $url, $tok['token'] );
		$items = array();
		if ( ! empty( $r['body']['files'] ) && is_array( $r['body']['files'] ) ) {
			foreach ( $r['body']['files'] as $f ) {
				$items[] = array( 'id' => (string) ( $f['id'] ?? '' ), 'name' => (string) ( $f['name'] ?? '' ) );
			}
		}
		return array( 'ok' => $r['ok'], 'items' => $items, 'error' => $r['error'] );
	}

	public function create( array $connection, array $config ): array
	{
		$tok = $this->token( $connection );
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'config' => array(), 'error' => $tok['error'] ?? 'sin token' );
		}
		$title = trim( (string) ( $config['title'] ?? 'Nueva planilla' ) );
		$r = Http::post( self::API . '/spreadsheets?fields=spreadsheetId,properties(title)', $tok['token'], array(
			'json' => array( 'properties' => array( 'title' => $title ) ),
		) );
		if ( empty( $r['ok'] ) || empty( $r['body']['spreadsheetId'] ) ) {
			return array( 'ok' => false, 'config' => array(), 'error' => $r['error'] );
		}
		$spreadsheet_id = (string) $r['body']['spreadsheetId'];
		$sheet_title    = $this->sheet( $config );
		// Best-effort rename of the default "Sheet1" (sheetId 0).
		if ( $sheet_title !== 'Sheet1' ) {
			Http::post( self::API . '/spreadsheets/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate', $tok['token'], array(
				'json' => array( 'requests' => array(
					array( 'updateSheetProperties' => array(
						'properties' => array( 'sheetId' => 0, 'title' => $sheet_title ),
						'fields'     => 'title',
					) ),
				) ),
			) );
		}
		$out = array_merge( $config, array( 'spreadsheet_id' => $spreadsheet_id, 'sheet_title' => $sheet_title ) );
		// Write headers when provided.
		$columns = array_values( array_filter( (array) ( $config['columns'] ?? array() ) ) );
		if ( $columns ) {
			Http::post( self::API . '/spreadsheets/' . rawurlencode( $spreadsheet_id ) . '/values/' . $this->range( $out, 'A1:1' ) . '?valueInputOption=USER_ENTERED', $tok['token'], array(
				'json' => array( 'values' => array( $columns ) ),
			) );
		}
		return array( 'ok' => true, 'config' => $out, 'error' => '' );
	}

	public function columns( array $connection, array $config ): array
	{
		$id = (string) ( $config['spreadsheet_id'] ?? '' );
		if ( $id === '' ) {
			return array( 'ok' => false, 'columns' => array(), 'error' => 'Sin spreadsheet_id' );
		}
		$tok = $this->token( $connection );
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'columns' => array(), 'error' => $tok['error'] ?? 'sin token' );
		}
		$r = Http::get( self::API . '/spreadsheets/' . rawurlencode( $id ) . '/values/' . $this->range( $config, 'A1:Z1' ), $tok['token'] );
		if ( empty( $r['ok'] ) ) {
			return array( 'ok' => false, 'columns' => array(), 'error' => $r['error'] );
		}
		$columns = array();
		if ( ! empty( $r['body']['values'][0] ) && is_array( $r['body']['values'][0] ) ) {
			$columns = array_values( array_filter( array_map( 'strval', $r['body']['values'][0] ) ) );
		}
		return array( 'ok' => true, 'columns' => $columns, 'error' => '' );
	}

	public function write( array $connection, array $config, array $row ): array
	{
		$id = (string) ( $config['spreadsheet_id'] ?? '' );
		if ( $id === '' ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'Sin spreadsheet_id', 'summary' => '' );
		}
		$tok = $this->token( $connection );
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => $tok['error'] ?? 'sin token', 'summary' => '' );
		}
		$r = Http::post( self::API . '/spreadsheets/' . rawurlencode( $id ) . '/values/' . $this->range( $config, 'A1:1' ) . ':append?valueInputOption=USER_ENTERED', $tok['token'], array(
			'json' => array( 'values' => array( array_values( $row ) ) ),
		) );
		if ( empty( $r['ok'] ) ) {
			return array( 'ok' => false, 'http' => $r['http'], 'error' => $r['error'], 'summary' => '' );
		}
		$updated = is_array( $r['body'] ) ? (int) ( $r['body']['updates']['updatedRows'] ?? 1 ) : 1;
		return array( 'ok' => true, 'http' => $r['http'], 'error' => '', 'summary' => $updated . ' fila(s)' );
	}

	/** Set the header row (A1:1) to the given column titles. Returns {ok, http,
	 *  error, summary}. Used by the assistant to (re)name columns safely. */
	public function set_headers( array $connection, array $config, array $headers ): array
	{
		$id = (string) ( $config['spreadsheet_id'] ?? '' );
		if ( $id === '' ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'Sin spreadsheet_id', 'summary' => '' );
		}
		if ( empty( $headers ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'Sin títulos de columna', 'summary' => '' );
		}
		$tok = $this->token( $connection );
		if ( empty( $tok['ok'] ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => $tok['error'] ?? 'sin token', 'summary' => '' );
		}
		$r = Http::request( 'PUT', self::API . '/spreadsheets/' . rawurlencode( $id ) . '/values/' . $this->range( $config, 'A1:1' ) . '?valueInputOption=USER_ENTERED', $tok['token'], array(
			'json' => array( 'values' => array( array_values( array_map( 'strval', $headers ) ) ) ),
		) );
		if ( empty( $r['ok'] ) ) {
			return array( 'ok' => false, 'http' => $r['http'], 'error' => $r['error'], 'summary' => '' );
		}
		$updated = is_array( $r['body'] ) ? (int) ( $r['body']['updatedCells'] ?? count( $headers ) ) : count( $headers );
		return array( 'ok' => true, 'http' => $r['http'], 'error' => '', 'summary' => $updated . ' celda(s) de título actualizada(s)' );
	}
}
