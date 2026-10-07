<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/estandar-wpe-smoke';

@mkdir($tmp . '/plugins/sample-plugin', 0777, true);
@mkdir($tmp . '/themes/sample-theme/vendor/lib', 0777, true);

file_put_contents($tmp . '/plugins/sample-plugin/sample-plugin.php', "<?php\n/* Plugin Name: Sample */\n");
file_put_contents($tmp . '/plugins/sample-plugin/vendor.php', "<?php\nreturn true;\n");
file_put_contents($tmp . '/plugins/sample-plugin/includes.php', "<?php\nreturn 'includes';\n");
file_put_contents($tmp . '/themes/sample-theme/style.css', "/*\nTheme Name: Sample Theme\n*/\n");
file_put_contents($tmp . '/themes/sample-theme/functions.php', "<?php\nreturn 'theme';\n");
file_put_contents($tmp . '/themes/sample-theme/screenshot.png', "fake-image-data");
file_put_contents($tmp . '/themes/sample-theme/vendor/lib/file.php', "<?php\nreturn 'ok';\n");

define('ABSPATH', $tmp . '/wp/');
define('DAY_IN_SECONDS', 86400);
define('WP_PLUGIN_DIR', $tmp . '/plugins');
define('ESTANDAR_WPE_PATH', $root . '/');
define('ESTANDAR_WPE_VERSION', '13.0.0');

function trailingslashit(string $value) : string { return rtrim($value, '/\\') . '/'; }
function untrailingslashit(string $value) : string { return rtrim($value, '/\\'); }
function wp_normalize_path(string $value) : string { return str_replace('\\', '/', $value); }
function wp_mkdir_p(string $dir) : bool { return is_dir($dir) || mkdir($dir, 0777, true); }
function get_temp_dir() : string { global $tmp; return trailingslashit($tmp . '/runtime'); }
function wp_get_upload_dir() : array { global $tmp; return [ 'basedir' => $tmp . '/uploads' ]; }
function sanitize_text_field($value) : string { return preg_replace('/[^a-zA-Z0-9._\\/-]/', '', (string) $value); }
function current_user_can(string $cap) : bool { return $cap === 'manage_options'; }
function admin_url(string $path = '') : string { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
function add_query_arg(array $args, string $url) : string { return $url . '?' . http_build_query($args); }
function wp_json_encode($value, int $flags = 0) { return json_encode($value, $flags); }
function home_url(string $path = '') : string { return 'https://example.test' . $path; }
function get_bloginfo(string $show) : string {
	$map = [
		'name'    => 'Smoke Test Site',
		'version' => '6.9.1',
	];
	return $map[$show] ?? '';
}
function wp_get_current_user() {
	return (object) [
		'ID'         => 1,
		'user_login' => 'admin',
		'user_email' => 'admin@example.test',
	];
}
function get_plugins() : array {
	return [
		'sample-plugin/sample-plugin.php' => [ 'Name' => 'Sample Plugin' ],
	];
}
function wp_get_themes() : array {
	return [
		'sample-theme' => new class {
			public function get(string $field) : string {
				$map = [
					'Name'    => 'Sample Theme',
					'Version' => '1.0.0',
				];
				return $map[$field] ?? '';
			}
			public function get_stylesheet() : string { return 'sample-theme'; }
			public function get_template() : string { return 'sample-theme'; }
		},
	];
}
function wp_get_theme() {
	$themes = wp_get_themes();
	return $themes['sample-theme'];
}
function get_theme_root() : string { global $tmp; return $tmp . '/themes'; }

require_once $root . '/includes/helpers.php';
require_once $root . '/includes/class-job-store.php';
require_once $root . '/includes/class-scanner.php';
require_once $root . '/includes/class-exporter.php';

$exporter = new Estandar_WPE_Exporter();
$init = $exporter->init_job(
	[
		'plugins' => [ 'sample-plugin/sample-plugin.php' ],
		'themes'  => [ 'sample-theme' ],
	],
	[
		'max_file_mb' => 5,
		'batch_files' => 2,
		'batch_max_mb' => 2,
	]
);

if ( empty($init['ok']) || empty($init['job_id']) || empty($init['token']) ) {
	fwrite(STDERR, "init_job failed\n");
	exit(1);
}

$result = null;
$iterations = 0;

do {
	$result = $exporter->step_job($init['job_id'], $init['token']);
	$iterations++;

	if ( empty($result['ok']) ) {
		fwrite(STDERR, "step_job failed\n");
		exit(1);
	}

	if ( $iterations > 10 ) {
		fwrite(STDERR, "step_job did not finish in expected batches\n");
		exit(1);
	}
} while ( empty($result['done']) );

if ( empty($result['download_url']) || $iterations < 2 ) {
	fwrite(STDERR, "incremental batching not validated\n");
	exit(1);
}

$job = Estandar_WPE_Job_Store::get($init['job_id']);
$zip = $job['result']['zip_path'] ?? '';
if ( ! is_string($zip) || ! file_exists($zip) ) {
	fwrite(STDERR, "zip not created\n");
	exit(1);
}

if ( class_exists('ZipArchive') ) {
	$archive = new ZipArchive();
	if ( $archive->open($zip) !== true ) {
		fwrite(STDERR, "zip cannot be opened\n");
		exit(1);
	}

	$entries = [];
	for ( $i = 0; $i < $archive->numFiles; $i++ ) {
		$entries[] = $archive->getNameIndex($i);
	}
	$archive->close();

	sort($entries);

	$expected = [
		'plugins/sample-plugin/includes.php',
		'plugins/sample-plugin/sample-plugin.php',
		'plugins/sample-plugin/vendor.php',
		'export-report.json',
		'themes/sample-theme/functions.php',
		'themes/sample-theme/screenshot.png',
		'themes/sample-theme/style.css',
		'themes/sample-theme/vendor/lib/file.php',
	];

	foreach ( $expected as $entry ) {
		if ( ! in_array($entry, $entries, true) ) {
			fwrite(STDERR, "missing zip entry: {$entry}\n");
			exit(1);
		}
	}
}

$cleanup = $exporter->cleanup_job($init['job_id'], $init['token']);
if ( empty($cleanup['ok']) || file_exists($zip) ) {
	fwrite(STDERR, "cleanup_job failed\n");
	exit(1);
}

fwrite(STDOUT, "Smoke test passed\n");
