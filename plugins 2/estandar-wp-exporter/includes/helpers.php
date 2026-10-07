<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Estandar WP Exporter — Helpers
 *
 * RESPONSABILIDAD ÚNICA:
 * - Funciones utilitarias puras
 * - Resolución de paths
 * - Sanitización básica
 * - Preparación de storage
 *
 * REGLAS:
 * - NO hooks
 * - NO clases
 * - NO AJAX
 * - NO Zip
 * - NO side effects ocultos
 */

/* ======================================================
   PATHS BASE
====================================================== */

/**
 * Devuelve el directorio base de runtime del plugin.
 * Prioriza el temp del sistema y cae a uploads si es necesario.
 */
function estandar_wpe_storage_dir() : string {

	$candidates = [
		trailingslashit(get_temp_dir()) . 'estandar-wpe',
		trailingslashit(wp_get_upload_dir()['basedir']) . 'estandar-wpe',
	];

	foreach ( $candidates as $candidate ) {
		$candidate = wp_normalize_path(untrailingslashit($candidate));

		if ( is_dir($candidate) && is_writable($candidate) ) {
			return trailingslashit($candidate);
		}

		if ( ! is_dir($candidate) && @wp_mkdir_p($candidate) && is_writable($candidate) ) {
			return trailingslashit($candidate);
		}
	}

	return trailingslashit(wp_normalize_path(untrailingslashit($candidates[0])));
}

/**
 * Devuelve path absoluto al directorio de jobs.
 */
function estandar_wpe_jobs_dir() : string {
	return trailingslashit(estandar_wpe_storage_dir() . 'jobs');
}

/**
 * Devuelve path absoluto al directorio de ZIPs temporales.
 */
function estandar_wpe_exports_dir() : string {
	return trailingslashit(estandar_wpe_storage_dir() . 'exports');
}

/* ======================================================
   STORAGE
====================================================== */

/**
 * Garantiza que existan los directorios necesarios del plugin.
 * - storage/
 * - storage/jobs/
 *
 * No lanza fatal. Retorna true/false.
 */
function estandar_wpe_ensure_storage_dirs() : bool {

	$dirs = [
		estandar_wpe_storage_dir(),
		estandar_wpe_jobs_dir(),
		estandar_wpe_exports_dir(),
	];

	foreach ( $dirs as $dir ) {
		if ( ! is_dir($dir) ) {
			if ( ! wp_mkdir_p($dir) ) {
				return false;
			}
		}

		$index_file = trailingslashit($dir) . 'index.html';
		if ( ! file_exists($index_file) ) {
			@file_put_contents($index_file, '');
		}
	}

	return true;
}

/**
 * Limpia jobs y ZIPs viejos para evitar acumulación en disco.
 */
function estandar_wpe_cleanup_stale_artifacts(int $max_age = DAY_IN_SECONDS) : void {

	foreach ( [ estandar_wpe_jobs_dir(), estandar_wpe_exports_dir() ] as $dir ) {
		if ( ! is_dir($dir) || ! is_readable($dir) ) {
			continue;
		}

		$items = @scandir($dir);
		if ( ! is_array($items) ) {
			continue;
		}

		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' || $item === 'index.html' ) {
				continue;
			}

			$path = wp_normalize_path(trailingslashit($dir) . $item);
			if ( ! is_file($path) ) {
				continue;
			}

			$mtime = @filemtime($path);
			if ( $mtime === false ) {
				continue;
			}

			if ( $max_age <= 0 || ( time() - $mtime ) >= $max_age ) {
				@unlink($path);
			}
		}
	}
}

/* ======================================================
   SANITIZACIÓN
====================================================== */

/**
 * Sanitiza un identificador de job.
 * Solo permite: a-z A-Z 0-9 _ -
 */
function estandar_wpe_sanitize_slug(string $value) : string {
	return preg_replace('/[^a-zA-Z0-9_-]/', '', $value);
}

/**
 * Genera un token aleatorio seguro.
 */
function estandar_wpe_random_token(int $length = 32) : string {
	$bytes = random_bytes(max(16, $length));
	return substr(bin2hex($bytes), 0, $length);
}

/* ======================================================
   TIEMPO / FECHAS
====================================================== */

/**
 * Timestamp actual en UTC.
 */
function estandar_wpe_now() : int {
	return time();
}

/* ======================================================
   SEGURIDAD BÁSICA
====================================================== */

/**
 * Verifica que una ruta esté dentro del plugin.
 * Evita path traversal.
 */
function estandar_wpe_is_safe_path(string $path) : bool {
	$real = realpath($path);
	if ( ! $real ) return false;

	return strpos($real, realpath(ESTANDAR_WPE_PATH)) === 0;
}

/**
 * Verifica que una ruta esté contenida dentro de un directorio base.
 */
function estandar_wpe_is_path_within(string $path, string $base_dir) : bool {

	$real_path = realpath($path);
	$real_base = realpath($base_dir);

	if ( ! $real_path || ! $real_base ) {
		return false;
	}

	$real_path = wp_normalize_path($real_path);
	$real_base = trailingslashit(wp_normalize_path($real_base));

	return strpos($real_path, $real_base) === 0;
}
