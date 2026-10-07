<?php

namespace Estandar\Core\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract every destination adapter implements. An adapter is a transport
 * that knows how to discover, create and append to its destination using an
 * OAuth connection (row from Estandar\Core\Connections::get()).
 *
 * New destinations (Gmail, Calendar, Docs, OneDrive, SharePoint, CRMs…) are
 * added by implementing this interface and registering in Estandar\Core\Adapters
 * — nothing Google/Excel-specific is assumed here.
 */
interface AdapterInterface
{
	/** Stable adapter key, e.g. "google_sheets" or "excel_online". */
	public function key(): string;

	/** Human label shown in the wizard, e.g. "Google Sheets". */
	public function label(): string;

	/** OAuth provider key this adapter requires ("google" | "microsoft" | …). */
	public function provider(): string;

	/** OAuth scopes this adapter needs (configurable per adapter, not global). */
	public function scopes(): array;

	/**
	 * Verify the connection + config can reach the destination.
	 * @return array{ok:bool, http:int, error:string}
	 */
	public function test( array $connection, array $config ): array;

	/**
	 * List candidate destinations (files/workbooks) for the wizard.
	 * @return array{ok:bool, items:array<int,array{id:string,name:string}>, error:string}
	 */
	public function discover( array $connection, array $config, array $query = array() ): array;

	/**
	 * Create a new destination (spreadsheet/workbook + initial structure).
	 * @return array{ok:bool, config:array, error:string} — config carries the
	 *         created ids so the caller can persist it.
	 */
	public function create( array $connection, array $config ): array;

	/**
	 * Read the current columns/headers of the destination.
	 * @return array{ok:bool, columns:array, error:string}
	 */
	public function columns( array $connection, array $config ): array;

	/**
	 * Append one row. $row is an ordered array matching $config['columns'].
	 * @return array{ok:bool, http:int, error:string, summary:string}
	 */
	public function write( array $connection, array $config, array $row ): array;
}
