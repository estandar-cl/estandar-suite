<?php
/**
 * generate-wp-functions-whitelist.php — genera tests/wp-functions-whitelist.php
 *
 * Bota el Playground local (WP 7.1, MISMA versión que producción acacios) y
 * recolecta las funciones de CORE de WordPress (definidas en wp-includes/ y
 * wp-admin/) más las de la Suite. El resultado se usa como whitelist del gate
 * gate-wp-api-compat.php: cualquier llamada a una función que no exista aquí
 * (ni sea builtin de PHP ni esté definida en el propio plugin) = candidato a
 * fatal en producción (ej. `upgrade_plugin()` eliminada en WP 7.1).
 *
 * Uso: php tests/generate-wp-functions-whitelist.php
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 'cli only' );
}

$root = dirname( __DIR__ );
$pg   = $root . '/Playground/wp-load.php';
if ( ! file_exists( $pg ) ) {
	fwrite( STDERR, "Playground no encontrado: {$pg}\n" );
	exit( 1 );
}

require $pg;

// Carga los includes admin bajo demanda (plugin.php, file.php, update.php,
// upgrader…) para que la whitelist incluya las funciones de wp-admin que solo
// existen cuando se requieren. Así el gate distingue "no existe en producción"
// (ej. upgrade_plugin eliminada en WP 7.1) de "existe pero no estaba cargada".
foreach ( array( 'plugin.php', 'file.php', 'misc.php', 'update.php', 'upgrade.php' ) as $inc ) {
	$path = ABSPATH . 'wp-admin/includes/' . $inc;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

$all   = get_defined_functions();
$user  = isset( $all['user'] ) ? $all['user'] : array();
$core  = array();
$suite = array();
$abspath  = trailingslashit( ABSPATH );
$suite_dir = dirname( $root ) . '/plugins/';

foreach ( $user as $fn ) {
	$file = '';
	try {
		$r    = new ReflectionFunction( $fn );
		$file = (string) $r->getFileName();
	} catch ( \Throwable $e ) {
		continue;
	}
	if ( $file === '' ) {
		continue;
	}
	if ( strpos( $file, $abspath . 'wp-includes' ) === 0 || strpos( $file, $abspath . 'wp-admin' ) === 0 ) {
		$core[] = $fn;
	} elseif ( strpos( $file, $suite_dir ) === 0 ) {
		$suite[] = $fn;
	}
}

$core  = array_values( array_unique( $core ) );
$suite = array_values( array_unique( $suite ) );

// Barrido estático de wp-includes/ y wp-admin/includes/: captura funciones de
// carga diferida que get_defined_functions() no lista al boot (ej. dbDelta en
// wp-admin/includes/upgrade.php, WP 7.1) sin tener que cargarlas todas.
$core = array_fill_keys( $core, true );
foreach ( array( 'wp-includes', 'wp-admin/includes' ) as $dir ) {
	$base = ABSPATH . $dir;
	if ( ! is_dir( $base ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->getExtension() !== 'php' ) {
			continue;
		}
		$src = (string) file_get_contents( $f->getPathname() );
		if ( preg_match_all( '/^\s*(?:final\s+|static\s+|abstract\s+|public\s+|private\s+|protected\s+)*function\s+&?\s*([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*\(/m', $src, $m ) ) {
			foreach ( $m[1] as $fn ) {
				$core[ $fn ] = true;
			}
		}
	}
}
$core = array_keys( $core );
sort( $core, SORT_STRING );
sort( $suite, SORT_STRING );

$out  = "<?php\n";
$out .= "/**\n";
$out .= " * wp-functions-whitelist.php — funciones de WP core (wp-includes/wp-admin) y de la Suite.\n";
$out .= " * GENERADO automáticamente (tests/generate-wp-functions-whitelist.php) contra WP " . get_bloginfo( 'version' ) . ".\n";
$out .= " * Regenerar si cambia el piso de WP soportado.\n";
$out .= " */\n\n";
$out .= "return array(\n";
$out .= "  'core'  => " . var_export( $core, true ) . ",\n";
$out .= "  'suite' => " . var_export( $suite, true ) . ",\n";
$out .= ");\n";

$target = __DIR__ . '/wp-functions-whitelist.php';
if ( file_put_contents( $target, $out ) === false ) {
	fwrite( STDERR, "no se pudo escribir {$target}\n" );
	exit( 1 );
}

echo "OK wp-functions-whitelist.php: core=" . count( $core ) . " suite=" . count( $suite ) . " (WP " . get_bloginfo( 'version' ) . ")\n";
