<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apps Script deployer for the "liviano" Google Sheets adapter.
 *
 * Creates + deploys an Apps Script Web App programmatically via the Apps
 * Script API, so the user never copies/pastes code. The script exposes doPost
 * that appends a mapped row to a spreadsheet. The user only does the single
 * Google "Implementar" click (required by Google to publish with
 * "Anyone can access"); the Suite provides the project URL + the exact steps.
 *
 * Two modes:
 *   - preview(): generates the code + appsscript.json (PURE, no HTTP).
 *   - deploy():  creates the project, uploads content, creates a deployment
 *                and returns {ok, script_id, url, editor_url, steps}.
 */
class AppScriptDeploy
{
	const API = 'https://script.googleapis.com/v1';

	/** PURE: generate appsscript.json for the web app. */
	public static function manifest(): string
	{
		return wp_json_encode( array(
			'timeZone'     => 'America/Santiago',
			'dependencies' => array(),
			'webapp'       => array(
				'executeAs' => 'USER_DEPLOYING',
				'access'    => 'ANYONE',
			),
			'exceptionLogging' => 'STACKDRIVER',
			'runtimeVersion'   => 'V8',
		) );
	}

	/** PURE: generate the Code.gs source for a given spreadsheet/sheet. */
	public static function code( string $spreadsheet_id, string $sheet_title = '' ): string
	{
		$sheet_expr = $sheet_title !== ''
			? 'var sh = ss.getSheetByName(' . wp_json_encode( $sheet_title ) . ') || ss.getSheets()[0];'
			: 'var sh = ss.getSheets()[0];';
		return 'function doPost(e) {' . "\n"
			. '  try {' . "\n"
			. '    var body = JSON.parse(e.postData.contents);' . "\n"
			. '    var values = body.values || [];' . "\n"
			. '    var ss = SpreadsheetApp.openById(' . wp_json_encode( $spreadsheet_id ) . ');' . "\n"
			. '    ' . $sheet_expr . "\n"
			. '    sh.appendRow(values);' . "\n"
			. '    return ContentService.createTextOutput(JSON.stringify({ok:true, row:values.length}));' . "\n"
			. '  } catch (err) {' . "\n"
			. '    return ContentService.createTextOutput(JSON.stringify({ok:false, error:String(err)}));' . "\n"
			. '  }' . "\n"
			. '}' . "\n"
			. 'function doGet() { return ContentService.createTextOutput("ok"); }' . "\n";
	}

	/** PURE: build the content upload body. */
	public static function files_body( string $spreadsheet_id, string $sheet_title = '' ): array
	{
		return array( 'files' => array(
			array( 'name' => 'appsscript.json', 'type' => 'JSON', 'source' => self::manifest() ),
			array( 'name' => 'Code.gs', 'type' => 'SERVER_JS', 'source' => self::code( $spreadsheet_id, $sheet_title ) ),
		) );
	}

	/**
	 * Deploy: create project → upload content → create deployment (web app).
	 * Requires a fresh Google OAuth access token (the connection used by the
	 * assistant). Returns {ok, script_id, url, editor_url, deployment_id, error}.
	 */
	public static function deploy( string $access_token, string $title, string $spreadsheet_id, string $sheet_title = '' ): array
	{
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return array( 'ok' => false, 'error' => 'entorno sin HTTP' );
		}
		// 1) Create the project.
		$r = Http::post( self::API . '/projects', $access_token, array(
			'json' => array( 'title' => sanitize_text_field( $title ) ),
			'timeout' => 30,
		) );
		if ( empty( $r['ok'] ) || empty( $r['body']['scriptId'] ) ) {
			return array( 'ok' => false, 'error' => 'No se pudo crear el proyecto de Apps Script: ' . self::err( $r ) );
		}
		$script_id = (string) $r['body']['scriptId'];

		// 2) Upload the content (manifest + Code.gs).
		$r = Http::request( 'PUT', self::API . '/projects/' . rawurlencode( $script_id ) . '/content', $access_token, array(
			'json' => self::files_body( $spreadsheet_id, $sheet_title ),
			'timeout' => 30,
		) );
		if ( empty( $r['ok'] ) ) {
			return array( 'ok' => false, 'script_id' => $script_id, 'error' => 'No se pudo subir el contenido: ' . self::err( $r ) );
		}

		// 3) Create the web-app deployment.
		$r = Http::post( self::API . '/projects/' . rawurlencode( $script_id ) . '/deployments', $access_token, array(
			'json' => array( 'entryPoints' => array(
				array(
					'entryPointType' => 'WEB_APP',
					'webapp'         => array( 'entryPointConfig' => array(
						'executeAs' => 'USER_DEPLOYING',
						'access'    => 'ANYONE',
					) ),
				),
			) ),
			'timeout' => 30,
		) );
		if ( empty( $r['ok'] ) || empty( $r['body']['entryPoints'][0]['webapp']['url'] ) ) {
			return array( 'ok' => false, 'script_id' => $script_id, 'error' => 'No se pudo crear el deployment: ' . self::err( $r ) );
		}
		$url = (string) $r['body']['entryPoints'][0]['webapp']['url'];

		return array(
			'ok'            => true,
			'script_id'     => $script_id,
			'deployment_id' => (string) ( $r['body']['deploymentId'] ?? '' ),
			'url'           => $url,
			'editor_url'    => 'https://script.google.com/home/projects/' . rawurlencode( $script_id ) . '/edit',
			'error'         => '',
		);
	}

	/** Human error from an Http result. */
	private static function err( array $r ): string
	{
		$raw = (string) ( $r['error'] ?? 'desconocido' );
		return $raw === '' ? 'respuesta inválida' : $raw;
	}
}
