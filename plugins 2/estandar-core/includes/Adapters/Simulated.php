<?php

namespace Estandar\Core\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Estandar\Core\Sandbox;

/**
 * Simulated destination adapter — validates the full Integration Core pipeline
 * without real OAuth ("OAuth Pending" mode). Uses the Sandbox virtual store as
 * its backend, so create/columns/write behave like the real adapters but touch
 * no external service. Replaced by real adapters once credentials exist.
 */
class Simulated implements AdapterInterface
{
	public function key(): string
	{
		return 'simulated';
	}

	public function label(): string
	{
		return 'Simulación (sin OAuth)';
	}

	public function provider(): string
	{
		return 'sandbox';
	}

	public function scopes(): array
	{
		return array();
	}

	public function test( array $connection, array $config ): array
	{
		$file = Sandbox::get( (string) ( $config['file_id'] ?? '' ) );
		if ( ! $file ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'archivo simulado no encontrado' );
		}
		return array( 'ok' => true, 'http' => 200, 'error' => '' );
	}

	public function discover( array $connection, array $config, array $query = array() ): array
	{
		$items = Sandbox::files();
		if ( ! empty( $query['search'] ) ) {
			$needle = strtolower( (string) $query['search'] );
			$items  = array_values( array_filter( $items, static function ( $i ) use ( $needle ) {
				return $needle === '' || strpos( strtolower( (string) $i['name'] ), $needle ) !== false;
			} ) );
		}
		return array( 'ok' => true, 'items' => $items, 'error' => '' );
	}

	public function create( array $connection, array $config ): array
	{
		$title       = (string) ( $config['title'] ?? $config['name'] ?? 'Nueva planilla' );
		$sheet_title = (string) ( $config['sheet_title'] ?? 'Datos' );
		$columns     = array_values( array_filter( (array) ( $config['columns'] ?? array() ) ) );
		if ( ! $columns ) {
			return array( 'ok' => false, 'config' => array(), 'error' => 'Sin columnas para crear el archivo simulado' );
		}
		$key = Sandbox::create( $title, $sheet_title, $columns );
		if ( $key === '' ) {
			return array( 'ok' => false, 'config' => array(), 'error' => 'No se pudo crear el archivo simulado' );
		}
		$out = array_merge( $config, array(
			'file_id'     => $key,
			'name'        => $title,
			'sheet_title' => $sheet_title,
			'columns'     => $columns,
		) );
		return array( 'ok' => true, 'config' => $out, 'error' => '' );
	}

	public function columns( array $connection, array $config ): array
	{
		$file = Sandbox::get( (string) ( $config['file_id'] ?? '' ) );
		if ( ! $file ) {
			return array( 'ok' => false, 'columns' => array(), 'error' => 'archivo simulado no encontrado' );
		}
		return array( 'ok' => true, 'columns' => $file['columns'], 'error' => '' );
	}

	public function write( array $connection, array $config, array $row ): array
	{
		$key = (string) ( $config['file_id'] ?? '' );
		if ( $key === '' || ! Sandbox::get( $key ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'archivo simulado no encontrado', 'summary' => '' );
		}
		$count = Sandbox::append( $key, array_values( $row ) );
		if ( $count < 0 ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'fallo al escribir en el archivo simulado', 'summary' => '' );
		}
		return array( 'ok' => true, 'http' => 200, 'error' => '', 'summary' => '1 fila(s) · total ' . $count );
	}
}
