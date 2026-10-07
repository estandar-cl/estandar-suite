<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Estandar WP Exporter — Scanner
 *
 * Responsabilidad ÚNICA:
 * - Dado un input lógico (plugins / themes),
 *   resolver una lista de archivos reales y válidos.
 *
 * REGLAS ABSOLUTAS:
 * - NO crea ZIP
 * - NO escribe archivos
 * - NO usa ZipArchive
 * - NO usa AJAX
 * - NO persiste estado
 * - NO asume permisos
 * - NO toca uploads
 *
 * Salida:
 * - Array plano de archivos:
 *   [
 *     [
 *       'abs' => '/abs/path/file.php',
 *       'rel' => 'plugins/plugin-slug/file.php',
 *       'size' => 12345
 *     ],
 *     ...
 *   ]
 */

final class Estandar_WPE_Scanner {

	/**
	 * Punto de entrada principal.
	 */
	public static function scan(array $selection, array $settings = []) : array {

		$defaults = [
			'max_file_mb' => 25,
			'skip_symlinks' => true,
			'exclude_dirs' => [
				'.git', '.svn', '.hg',
				'.DS_Store',
				'__MACOSX'
			],
			'exclude_ext' => [
				'log', 'tmp'
			],
		];

		$settings = array_replace_recursive($defaults, $settings);

		$result = [];

		// Plugins
		if ( ! empty($selection['plugins']) ) {
			$result = array_merge(
				$result,
				self::scan_plugins((array) $selection['plugins'], $settings)
			);
		}

		// Themes
		if ( ! empty($selection['themes']) ) {
			$result = array_merge(
				$result,
				self::scan_themes((array) $selection['themes'], $settings)
			);
		}

		return $result;
	}

	/* ======================================================
	   PLUGINS
	====================================================== */

	private static function scan_plugins(array $plugins, array $settings) : array {

		if ( ! function_exists('get_plugins') ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$out = [];

		foreach ( $plugins as $plugin_file ) {

			$plugin_file = sanitize_text_field((string) $plugin_file);

			if ( ! isset($all_plugins[$plugin_file]) ) {
				continue;
			}

			$abs_plugin = wp_normalize_path(WP_PLUGIN_DIR . '/' . $plugin_file);

			// Plugin de un solo archivo
			if ( dirname($plugin_file) === '.' ) {

				if ( is_file($abs_plugin) ) {
					$out[] = self::file_entry(
						$abs_plugin,
						'plugins/' . basename($plugin_file),
						$settings
					);
				}

				continue;
			}

			// Plugin con carpeta
			$slug = explode('/', $plugin_file)[0];
			$plugin_dir = wp_normalize_path(WP_PLUGIN_DIR . '/' . $slug);

			if ( ! is_dir($plugin_dir) ) {
				continue;
			}

			$out = array_merge(
				$out,
				self::scan_directory(
					$plugin_dir,
					'plugins/' . $slug,
					$settings
				)
			);
		}

		return array_values(array_filter($out));
	}

	/* ======================================================
	   THEMES
	====================================================== */

	private static function scan_themes(array $themes, array $settings) : array {

		$all = wp_get_themes();
		$out = [];
		$root = wp_normalize_path(get_theme_root());

		foreach ( $themes as $stylesheet ) {

			$stylesheet = sanitize_text_field((string) $stylesheet);

			if ( ! isset($all[$stylesheet]) ) {
				continue;
			}

			$theme_dir = wp_normalize_path($root . '/' . $stylesheet);

			if ( ! is_dir($theme_dir) ) {
				continue;
			}

			$out = array_merge(
				$out,
				self::scan_directory(
					$theme_dir,
					'themes/' . $stylesheet,
					$settings
				)
			);
		}

		return array_values(array_filter($out));
	}

	/* ======================================================
	   CORE SCAN
	====================================================== */

	private static function scan_directory(string $abs_dir, string $rel_prefix, array $settings) : array {

		$files = [];
		$stack = [ rtrim($abs_dir, '/') ];

		$max_bytes = max(1, (int) $settings['max_file_mb']) * 1024 * 1024;

		while ( ! empty($stack) ) {

			$dir = array_pop($stack);

			$items = @scandir($dir);
			if ( ! is_array($items) ) {
				continue;
			}

			foreach ( $items as $item ) {

				if ( $item === '.' || $item === '..' ) {
					continue;
				}

				$abs = wp_normalize_path($dir . '/' . $item);

				// Symlink
				if ( $settings['skip_symlinks'] && is_link($abs) ) {
					continue;
				}

				// Directorio
				if ( is_dir($abs) ) {

					if ( self::is_excluded_dir($item, $settings) ) {
						continue;
					}

					$stack[] = $abs;
					continue;
				}

				// Archivo
				if ( ! estandar_wpe_is_path_within($abs, $abs_dir) ) {
					continue;
				}

				$size = @filesize($abs);
				if ( $size === false || $size > $max_bytes ) {
					continue;
				}

				$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
				if ( self::is_excluded_ext($ext, $settings) ) {
					continue;
				}

				$rel = ltrim(str_replace($abs_dir, '', $abs), '/');

				$files[] = [
					'abs'  => $abs,
					'rel'  => $rel_prefix . '/' . $rel,
					'size' => $size,
				];
			}
		}

		return $files;
	}

	/* ======================================================
	   HELPERS INTERNOS
	====================================================== */

	private static function is_excluded_dir(string $name, array $settings) : bool {
		return in_array(strtolower($name), array_map('strtolower', (array) $settings['exclude_dirs']), true);
	}

	private static function is_excluded_ext(string $ext, array $settings) : bool {
		return in_array(strtolower($ext), array_map('strtolower', (array) $settings['exclude_ext']), true);
	}

	private static function file_entry(string $abs, string $rel, array $settings) : ?array {

		$size = @filesize($abs);
		if ( $size === false ) {
			return null;
		}

		$max_bytes = max(1, (int) $settings['max_file_mb']) * 1024 * 1024;
		if ( $size > $max_bytes ) {
			return null;
		}

		$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
		if ( self::is_excluded_ext($ext, $settings) ) {
			return null;
		}

		return [
			'abs'  => wp_normalize_path($abs),
			'rel'  => ltrim($rel, '/'),
			'size' => $size,
		];
	}
}
