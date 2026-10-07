<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Estandar WP Exporter — Exporter Orchestrator
 *
 * Versión mínima FUNCIONAL con ZIP real.
 * Compatible con:
 * - Admin JS actual
 * - Job Store
 * - Scanner
 */

final class Estandar_WPE_Exporter {

	/* ======================================================
	   API PÚBLICA
	====================================================== */

	public function init_job(array $selection, array $settings = []) : array {

		if ( ! current_user_can('manage_options') ) {
			return ['ok' => false, 'error' => 'No autorizado'];
		}

		$selection = $this->sanitize_selection($selection);

		if ( empty($selection['plugins']) && empty($selection['themes']) ) {
			return ['ok' => false, 'error' => 'Selecciona al menos un plugin o un theme.'];
		}

		$settings = $this->normalize_settings($settings);
		estandar_wpe_cleanup_stale_artifacts();

		$job_id = 'wpe_' . estandar_wpe_random_token(12);
		$token  = estandar_wpe_random_token(24);

		$job = [
			'job_id'    => $job_id,
			'created'   => estandar_wpe_now(),
			'status'    => 'pending',
			'token'     => $token,
			'selection' => $selection,
			'context'   => $this->build_job_context($selection),
			'settings'  => $settings,
			'progress'  => [
				'files_total' => 0,
				'files_done'  => 0,
				'bytes_total' => 0,
				'bytes_done'  => 0,
				'files_skipped' => 0,
				'percent'      => 0,
				'last_message' => 'Job creado. Esperando primer lote.',
			],
			'runtime'   => [
				'cursor'          => 0,
				'files'           => [],
				'zip_initialized' => false,
			],
			'result'    => [
				'zip_name'     => null,
				'zip_path'     => null,
				'download_url' => null,
				'engine'       => null,
			],
			'error'     => null,
		];

		if ( ! Estandar_WPE_Job_Store::create($job) ) {
			return ['ok' => false, 'error' => 'No se pudo crear el job.'];
		}

		return [
			'ok'     => true,
			'job_id' => $job_id,
			'token'  => $token,
		];
	}

	/**
	 * Ejecuta el job completo (una sola pasada):
	 * - Escanea archivos
	 * - Genera ZIP real
	 * - Devuelve URL descargable
	 */
	public function step_job(string $job_id, ?string $token = null) : array {

		if ( ! current_user_can('manage_options') ) {
			return ['ok' => false, 'error' => 'No autorizado'];
		}

		$job_id = estandar_wpe_sanitize_slug($job_id);
		$job    = Estandar_WPE_Job_Store::get($job_id);

		if ( ! is_array($job) ) {
			return ['ok' => false, 'error' => 'Job no encontrado'];
		}

		if ( empty($job['token']) || $job['token'] !== $token ) {
			return ['ok' => false, 'error' => 'Token inválido'];
		}

		// Si ya está listo, re-entregar (no borrar aquí)
		if ( $job['status'] === 'done' && ! empty($job['result']['download_url']) ) {
			return $this->format_progress($job, true);
		}

		if ( $job['status'] === 'error' ) {
			return [
				'ok'    => false,
				'error' => $job['error'] ?: 'El job se encuentra en error',
			];
		}

		/* ==================================================
		   1) Preparar manifiesto
		================================================== */

		if ( $job['status'] === 'pending' ) {
			$manifest = $this->build_manifest($job);

			if ( empty($manifest['ok']) ) {
				return $this->fail_job($job_id, $manifest['error'] ?? 'No se pudo preparar el job');
			}

			$job = Estandar_WPE_Job_Store::get($job_id);
			if ( ! is_array($job) ) {
				return $this->fail_job($job_id, 'No se pudo recargar el job tras preparar el manifiesto');
			}
		}

		/* ==================================================
		   2) Procesar siguiente lote
		================================================== */

		if ( ! estandar_wpe_ensure_storage_dirs() ) {
			return $this->fail_job($job_id, 'No se pudo preparar el directorio temporal del exportador');
		}

		$dir = estandar_wpe_exports_dir();
		if ( ! is_dir($dir) || ! is_writable($dir) ) {
			return $this->fail_job($job_id, 'No hay permisos de escritura en el directorio temporal del exportador');
		}

		$zip_path = $job['result']['zip_path'] ?? '';
		if ( ! is_string($zip_path) || $zip_path === '' ) {
			$zip_path = trailingslashit($dir) . 'estandar-wpe-' . $job_id . '.zip';
		}

		$manifest = isset($job['runtime']['files']) && is_array($job['runtime']['files'])
			? $job['runtime']['files']
			: [];
		$cursor = isset($job['runtime']['cursor']) ? (int) $job['runtime']['cursor'] : 0;

		if ( empty($manifest) ) {
			return $this->fail_job($job_id, 'El job no contiene un manifiesto válido');
		}

		$batch = $this->slice_batch($manifest, $cursor, $job['settings']);
		if ( empty($batch['files']) ) {
			return $this->finalize_job($job, $zip_path);
		}

		$zip_data = $this->build_zip(
			$zip_path,
			$batch['files'],
			! empty($job['runtime']['zip_initialized']),
			(string) ($job['result']['engine'] ?? '')
		);

		if ( empty($zip_data['ok']) ) {
			return $this->fail_job($job_id, $zip_data['error'] ?? 'No se pudo crear el ZIP');
		}

		$engine = (string) ($zip_data['engine'] ?? 'unknown');
		$processed_files = count($batch['files']);
		$next_cursor = $cursor + $processed_files;
		$total_files = count($manifest);
		$done = $next_cursor >= $total_files;
		$processed_bytes = (int) ($batch['bytes'] ?? 0);
		$skipped = (int) ($zip_data['skipped'] ?? 0);

		Estandar_WPE_Job_Store::update($job_id, [
			'status' => 'running',
			'progress' => [
				'files_done'    => $next_cursor,
				'bytes_done'    => min(
					(int) ($job['progress']['bytes_total'] ?? 0),
					(int) ($job['progress']['bytes_done'] ?? 0) + $processed_bytes
				),
				'files_skipped' => (int) ($job['progress']['files_skipped'] ?? 0) + $skipped,
				'percent'       => $total_files > 0 ? (int) floor(($next_cursor / $total_files) * 100) : 100,
				'last_message'  => $done
					? 'Exportación completada. ZIP listo.'
					: sprintf('Procesado lote de %d archivo(s).', $processed_files),
			],
			'runtime' => [
				'cursor'          => $next_cursor,
				'zip_initialized' => true,
			],
			'result' => [
				'zip_path'     => $zip_path,
				'engine'       => $engine,
			],
		]);

		$job = Estandar_WPE_Job_Store::get($job_id);
		if ( ! is_array($job) ) {
			return $this->fail_job($job_id, 'No se pudo recargar el job tras procesar el lote');
		}

		if ( $done ) {
			return $this->finalize_job($job, $zip_path);
		}

		return $this->format_progress($job, false);
	}

	/**
	 * Limpia ZIP (llamado explícitamente por AJAX después de abrir descarga).
	 */
	public function cleanup_job(string $job_id, ?string $token = null) : array {

		if ( ! current_user_can('manage_options') ) {
			return ['ok' => false, 'error' => 'No autorizado'];
		}

		$job_id = estandar_wpe_sanitize_slug($job_id);
		$job    = Estandar_WPE_Job_Store::get($job_id);

		if ( ! is_array($job) ) {
			return ['ok' => false, 'error' => 'Job no encontrado'];
		}

		if ( empty($job['token']) || $job['token'] !== $token ) {
			return ['ok' => false, 'error' => 'Token inválido'];
		}

		$zip_path = $job['result']['zip_path'] ?? null;

		if ( is_string($zip_path) && $zip_path !== '' && estandar_wpe_is_path_within($zip_path, estandar_wpe_exports_dir()) && file_exists($zip_path) ) {
			@unlink($zip_path);
		}

		Estandar_WPE_Job_Store::update($job_id, [
			'result' => [
				'zip_path'     => null,
				'download_url' => null,
			],
		]);

		return [
			'ok'      => true,
			'job_id'  => $job_id,
			'message' => 'ZIP eliminado.',
		];
	}

	/* ======================================================
	   NORMALIZACIÓN
	====================================================== */

	private function sanitize_selection(array $selection) : array {

		$plugins = [];
		$themes  = [];

		if ( ! empty($selection['plugins']) && is_array($selection['plugins']) ) {
			$plugins = array_values(array_filter(array_map('sanitize_text_field', $selection['plugins'])));
		}

		if ( ! empty($selection['themes']) && is_array($selection['themes']) ) {
			$themes = array_values(array_filter(array_map('sanitize_text_field', $selection['themes'])));
		}

		return [
			'plugins' => $plugins,
			'themes'  => $themes,
		];
	}

	private function normalize_settings(array $settings) : array {

		$batch_files = isset($settings['batch_files']) ? (int) $settings['batch_files'] : 200;
		$max_file_mb = isset($settings['max_file_mb']) ? (int) $settings['max_file_mb'] : 25;
		$batch_max_mb = isset($settings['batch_max_mb']) ? (int) $settings['batch_max_mb'] : 8;

		return [
			'batch_files'   => max(5, min(200, $batch_files)),
			'max_file_mb'   => max(1, min(200, $max_file_mb)),
			'batch_max_mb'  => max(2, min(64, $batch_max_mb)),
			'skip_symlinks' => true,
		];
	}

	private function fail_job(string $job_id, string $message) : array {

		Estandar_WPE_Job_Store::update($job_id, [
			'status' => 'error',
			'error'  => $message,
		]);

		return [
			'ok'    => false,
			'error' => $message,
		];
	}

	private function build_manifest(array $job) : array {

		$files = Estandar_WPE_Scanner::scan(
			$job['selection'],
			$job['settings']
		);

		if ( empty($files) ) {
			return [
				'ok'    => false,
				'error' => 'No se encontraron archivos para exportar',
			];
		}

		$total_bytes = 0;
		foreach ( $files as $file ) {
			$total_bytes += (int) ($file['size'] ?? 0);
		}

		$zip_path = trailingslashit(estandar_wpe_exports_dir()) . 'estandar-wpe-' . $job['job_id'] . '.zip';
		if ( file_exists($zip_path) ) {
			@unlink($zip_path);
		}

		Estandar_WPE_Job_Store::update($job['job_id'], [
			'status' => 'running',
			'progress' => [
				'files_total'   => count($files),
				'files_done'    => 0,
				'bytes_total'   => $total_bytes,
				'bytes_done'    => 0,
				'files_skipped' => 0,
				'percent'       => 0,
				'last_message'  => sprintf('Manifiesto listo con %d archivo(s).', count($files)),
			],
			'runtime' => [
				'cursor'          => 0,
				'files'           => array_values($files),
				'zip_initialized' => false,
			],
			'result' => [
				'zip_name'     => basename($zip_path),
				'zip_path'     => $zip_path,
				'download_url' => null,
				'engine'       => null,
			],
			'error' => null,
		]);

		return [ 'ok' => true ];
	}

	private function build_job_context(array $selection) : array {

		$current_theme = wp_get_theme();
		$user = wp_get_current_user();

		return [
			'site_url'        => home_url('/'),
			'site_name'       => get_bloginfo('name'),
			'wp_version'      => get_bloginfo('version'),
			'php_version'     => PHP_VERSION,
			'active_theme'    => [
				'name'       => $current_theme->get('Name'),
				'stylesheet' => $current_theme->get_stylesheet(),
				'template'   => $current_theme->get_template(),
				'version'    => $current_theme->get('Version'),
			],
			'selected_items'  => [
				'plugins' => array_values($selection['plugins'] ?? []),
				'themes'  => array_values($selection['themes'] ?? []),
			],
			'user'            => [
				'id'    => (int) $user->ID,
				'login' => (string) $user->user_login,
				'email' => (string) $user->user_email,
			],
		];
	}

	private function slice_batch(array $manifest, int $cursor, array $settings) : array {

		$batch_files = [];
		$batch_bytes = 0;
		$max_files   = max(1, (int) ($settings['batch_files'] ?? 25));
		$max_bytes   = max(1, (int) ($settings['batch_max_mb'] ?? 8)) * 1024 * 1024;
		$total_files = count($manifest);

		for ( $index = $cursor; $index < $total_files; $index++ ) {
			$file = $manifest[$index];
			$file_size = (int) ($file['size'] ?? 0);

			if ( ! empty($batch_files) && ( count($batch_files) >= $max_files || ( $batch_bytes + $file_size ) > $max_bytes ) ) {
				break;
			}

			$batch_files[] = $file;
			$batch_bytes  += $file_size;
		}

		return [
			'files' => $batch_files,
			'bytes' => $batch_bytes,
		];
	}

	private function finalize_job(array $job, string $zip_path) : array {

		if ( ! file_exists($zip_path) || filesize($zip_path) <= 0 ) {
			return $this->fail_job($job['job_id'], 'ZIP generado inválido o vacío');
		}

		$report_data = $this->build_export_report($job, $zip_path);
		$report_path = $this->create_report_file($job['job_id'], $report_data);

		if ( $report_path ) {
			$append_report = $this->build_zip(
				$zip_path,
				[
					[
						'abs'  => $report_path,
						'rel'  => 'export-report.json',
						'size' => filesize($report_path) ?: 0,
					],
				],
				true,
				(string) ($job['result']['engine'] ?? '')
			);

			@unlink($report_path);

			if ( empty($append_report['ok']) ) {
				return $this->fail_job($job['job_id'], $append_report['error'] ?? 'No se pudo adjuntar el reporte al ZIP');
			}
		}

		$job_id = (string) $job['job_id'];
		$token  = (string) ($job['token'] ?? '');
		$download_url = add_query_arg(
			[
					'action' => 'estandar_wpe_download',
					'job_id' => rawurlencode($job_id),
					'token'  => rawurlencode($token),
					'_wpnonce' => wp_create_nonce('estandar_wpe_download_' . $job_id),
				],
				admin_url('admin-ajax.php')
			);

		Estandar_WPE_Job_Store::update($job_id, [
			'status' => 'done',
			'progress' => [
				'files_done'   => (int) ($job['progress']['files_total'] ?? 0),
				'bytes_done'   => (int) ($job['progress']['bytes_total'] ?? 0),
				'percent'      => 100,
				'last_message' => 'Exportación completada. ZIP listo.',
			],
			'runtime' => [
				'cursor'          => (int) ($job['progress']['files_total'] ?? 0),
				'files'           => [],
				'zip_initialized' => true,
			],
			'result' => [
				'download_url' => $download_url,
			],
		]);

		$updated_job = Estandar_WPE_Job_Store::get($job_id);

		return $this->format_progress($updated_job ?: $job, true);
	}

	private function build_export_report(array $job, string $zip_path) : array {

		$selection = $job['selection'] ?? [];
		$context   = $job['context'] ?? [];
		$runtime_files = isset($job['runtime']['files']) && is_array($job['runtime']['files']) ? $job['runtime']['files'] : [];

		return [
			'plugin' => [
				'name'    => 'Estandar WP Exporter',
				'version' => ESTANDAR_WPE_VERSION,
			],
			'generated_at_gmt' => gmdate('c'),
			'job_id'           => (string) ($job['job_id'] ?? ''),
			'status'           => (string) ($job['status'] ?? 'done'),
			'site'             => $context['site_name'] ?? '',
			'site_url'         => $context['site_url'] ?? '',
			'wordpress' => [
				'version' => $context['wp_version'] ?? '',
				'php'     => $context['php_version'] ?? '',
			],
			'user' => $context['user'] ?? [],
			'active_theme' => $context['active_theme'] ?? [],
			'selection' => [
				'plugins' => array_values($selection['plugins'] ?? []),
				'themes'  => array_values($selection['themes'] ?? []),
			],
			'export' => [
				'files_total'     => (int) ($job['progress']['files_total'] ?? 0),
				'files_skipped'   => (int) ($job['progress']['files_skipped'] ?? 0),
				'bytes_total'     => (int) ($job['progress']['bytes_total'] ?? 0),
				'zip_size_bytes'  => file_exists($zip_path) ? (int) filesize($zip_path) : 0,
				'zip_filename'    => basename($zip_path),
				'zip_engine'      => (string) ($job['result']['engine'] ?? ''),
				'batch_files'     => (int) ($job['settings']['batch_files'] ?? 0),
				'max_file_mb'     => (int) ($job['settings']['max_file_mb'] ?? 0),
				'batch_max_mb'    => (int) ($job['settings']['batch_max_mb'] ?? 0),
			],
			'contents_preview' => array_slice(array_map(static function (array $file) : array {
				return [
					'path' => (string) ($file['rel'] ?? ''),
					'size' => (int) ($file['size'] ?? 0),
				];
			}, $runtime_files), 0, 100),
		];
	}

	private function create_report_file(string $job_id, array $report_data) : ?string {

		$report_path = trailingslashit(estandar_wpe_exports_dir()) . 'estandar-wpe-' . $job_id . '-report.json';
		$json = wp_json_encode($report_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if ( ! is_string($json) || $json === '' ) {
			return null;
		}

		return file_put_contents($report_path, $json, LOCK_EX) === false ? null : $report_path;
	}

	private function build_zip(string $zip_path, array $files, bool $append, string $preferred_engine = '') : array {

		if ( $preferred_engine === 'pclzip' && class_exists('PclZip') ) {
			return $this->build_zip_with_pclzip($zip_path, $files, $append);
		}

		if ( ( $preferred_engine === '' || $preferred_engine === 'ziparchive' ) && class_exists('ZipArchive') ) {
			return $this->build_zip_with_ziparchive($zip_path, $files, $append);
		}

		if ( ! class_exists('PclZip') ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		}

		if ( class_exists('PclZip') ) {
			return $this->build_zip_with_pclzip($zip_path, $files, $append);
		}

		return [
			'ok'    => false,
			'error' => 'No hay motor ZIP disponible en el servidor',
		];
	}

	private function build_zip_with_ziparchive(string $zip_path, array $files, bool $append) : array {

		$zip = new ZipArchive();
		$mode = $append ? ZipArchive::CREATE : ( ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( $zip->open($zip_path, $mode) !== true ) {
			return [
				'ok'    => false,
				'error' => 'No se pudo crear el ZIP',
			];
		}

		$bytes = 0;
		$added = 0;
		$skipped = 0;

		foreach ( $files as $file ) {
			if ( empty($file['abs']) || empty($file['rel']) || ! file_exists($file['abs']) ) {
				$skipped++;
				continue;
			}

			if ( $zip->addFile($file['abs'], $file['rel']) ) {
				$bytes += (int) ($file['size'] ?? 0);
				$added++;
			} else {
				$skipped++;
			}
		}

		$zip->close();

		if ( ! file_exists($zip_path) || $added <= 0 ) {
			return [
				'ok'    => false,
				'error' => 'ZIP generado inválido o vacío',
			];
		}

		return [
			'ok'     => true,
			'engine' => 'ziparchive',
			'bytes'  => $bytes,
			'added'  => $added,
			'skipped'=> $skipped,
		];
	}

	private function build_zip_with_pclzip(string $zip_path, array $files, bool $append) : array {

		$archive = new PclZip($zip_path);
		$bytes   = 0;
		$added   = 0;
		$skipped = 0;
		$created = $append && file_exists($zip_path);

		foreach ( $files as $file ) {
			if ( empty($file['abs']) || empty($file['rel']) || ! file_exists($file['abs']) ) {
				$skipped++;
				continue;
			}

			$rel_dir = trim(dirname($file['rel']), './');
			$options = [
				PCLZIP_OPT_REMOVE_PATH,
				dirname($file['abs']),
			];

			if ( $rel_dir !== '' ) {
				$options[] = PCLZIP_OPT_ADD_PATH;
				$options[] = $rel_dir;
			}

			$result = $created
				? $archive->add($file['abs'], ...$options)
				: $archive->create($file['abs'], ...$options);

			if ( $result === 0 ) {
				return [
					'ok'    => false,
					'error' => sprintf('Error creando ZIP: %s', $archive->errorInfo(true)),
				];
			}

			$created = true;
			$bytes += (int) ($file['size'] ?? 0);
			$added++;
		}

		if ( ! file_exists($zip_path) || $added <= 0 ) {
			return [
				'ok'    => false,
				'error' => 'ZIP generado inválido o vacío',
			];
		}

		return [
			'ok'     => true,
			'engine' => 'pclzip',
			'bytes'  => $bytes,
			'added'  => $added,
			'skipped'=> $skipped,
		];
	}

	/* ======================================================
	   OUTPUT (Contrato con Admin JS)
	====================================================== */

	private function format_progress(array $job, bool $done) : array {

		return [
			'ok'            => true,
			'done'          => $done,
			'job_id'        => $job['job_id'],
			'files_added'   => $job['progress']['files_done'] ?? 0,
			'files_total'   => $job['progress']['files_total'] ?? 0,
			'files_skipped' => $job['progress']['files_skipped'] ?? 0,
			'bytes_added'   => $job['progress']['bytes_done'] ?? 0,
			'bytes_total'   => $job['progress']['bytes_total'] ?? 0,
			'percent'       => $job['progress']['percent'] ?? ( $done ? 100 : 0 ),
			'download_url'  => $job['result']['download_url'] ?? null,
			'message'       => $done
				? 'Exportación completada. ZIP listo.'
				: ( $job['progress']['last_message'] ?? 'Procesando…' ),
		];
	}
}
