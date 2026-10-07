<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Estandar WP Exporter — Job Store
 *
 * Responsabilidad única:
 * - Crear
 * - Leer
 * - Actualizar
 * - Eliminar
 *
 * Persistencia basada en archivos JSON dentro de /storage/jobs/
 *
 * Reglas estrictas:
 * - NO exporta archivos
 * - NO usa ZipArchive
 * - NO usa AJAX
 * - NO escanea filesystem externo
 * - NO ejecuta lógica pesada
 * - Maneja errores de forma controlada
 */

final class Estandar_WPE_Job_Store {

	/**
	 * Devuelve el path absoluto del archivo JSON de un job.
	 */
	private static function job_file(string $job_id) : string {

		$job_id = estandar_wpe_sanitize_slug($job_id);

		return trailingslashit(estandar_wpe_jobs_dir()) . $job_id . '.json';
	}

	/* ======================================================
	   CRUD
	====================================================== */

	/**
	 * Crea un nuevo job.
	 */
	public static function create(array $job) : bool {

		if ( empty($job['job_id']) ) {
			return false;
		}

		if ( ! estandar_wpe_ensure_storage_dirs() ) {
			return false;
		}

		$file = self::job_file($job['job_id']);

		if ( file_exists($file) ) {
			return false; // No sobrescribir
		}

		$job = self::normalize_job($job);

		return self::write_json($file, $job);
	}

	/**
	 * Obtiene un job por ID.
	 */
	public static function get(string $job_id) : ?array {

		$file = self::job_file($job_id);

		if ( ! file_exists($file) ) {
			return null;
		}

		$data = file_get_contents($file);
		if ( $data === false ) {
			return null;
		}

		$json = json_decode($data, true);
		if ( ! is_array($json) ) {
			return null;
		}

		return $json;
	}

	/**
	 * Actualiza un job existente (merge).
	 */
	public static function update(string $job_id, array $updates) : bool {

		$current = self::get($job_id);
		if ( ! is_array($current) ) {
			return false;
		}

		$merged = array_replace_recursive($current, $updates);
		$merged = self::normalize_job($merged);

		return self::write_json(self::job_file($job_id), $merged);
	}

	/**
	 * Elimina un job.
	 */
	public static function delete(string $job_id) : bool {

		$file = self::job_file($job_id);

		if ( ! file_exists($file) ) {
			return true;
		}

		return @unlink($file);
	}

	/**
	 * Lista jobs recientes ordenados por fecha descendente.
	 */
	public static function list_recent(int $limit = 15) : array {

		$dir = estandar_wpe_jobs_dir();
		if ( ! is_dir($dir ) || ! is_readable($dir) ) {
			return [];
		}

		$items = @scandir($dir);
		if ( ! is_array($items) ) {
			return [];
		}

		$jobs = [];

		foreach ( $items as $item ) {
			if ( substr($item, -5) !== '.json' ) {
				continue;
			}

			$job_id = substr($item, 0, -5);
			$job = self::get($job_id);

			if ( is_array($job) ) {
				$jobs[] = $job;
			}
		}

		usort($jobs, static function (array $left, array $right) : int {
			return (int) ($right['created'] ?? 0) <=> (int) ($left['created'] ?? 0);
		});

		return array_slice($jobs, 0, max(1, $limit));
	}

	/* ======================================================
	   Normalización
	====================================================== */

	/**
	 * Garantiza estructura mínima y valores seguros.
	 */
	private static function normalize_job(array $job) : array {

		$defaults = [
			'job_id'    => '',
			'created'   => time(),
			'status'    => 'pending', // pending | running | done | error
			'selection' => [
				'plugins' => [],
				'themes'  => [],
			],
			'progress'  => [
				'files_total'   => 0,
				'files_done'    => 0,
				'bytes_total'   => 0,
				'bytes_done'    => 0,
				'files_skipped' => 0,
				'percent'       => 0,
				'last_message'  => '',
			],
			'runtime'   => [
				'cursor'          => 0,
				'files'           => [],
				'zip_initialized' => false,
			],
			'settings'  => [],
			'result'    => [
				'zip_name'     => null,
				'zip_path'     => null,
				'download_url' => null,
				'engine'       => null,
			],
			'error'     => null,
		];

		return array_replace_recursive($defaults, $job);
	}

	/* ======================================================
	   IO interno
	====================================================== */

	/**
	 * Escritura segura de JSON.
	 */
	private static function write_json(string $file, array $data) : bool {

		$tmp = $file . '.tmp';

		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ( $json === false ) {
			return false;
		}

		if ( file_put_contents($tmp, $json, LOCK_EX) === false ) {
			return false;
		}

		return rename($tmp, $file);
	}
}
