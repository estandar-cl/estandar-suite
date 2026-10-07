<?php
/**
 * gate-php-matrix.php — Gate de lint multi-versión de PHP.
 *
 * Ejecuta `php -l` sobre todos los archivos PHP de los 26 plugins con las
 * versiones de PHP declaradas en `tests/release-targets.php` (php_matrix).
 * Caza código que compila en la versión local (8.5) pero explota en las
 * versiones de los sitios de producción (8.2 / 8.4) — la clase de bug de
 * acacios.cl (2026-08-16, fatal en PHP 8 por uso de constante indefinida).
 *
 * Salida de éxito: "PHP_MATRIX_OK versiones=N archivos=ARCH total_errores=0"
 * Salida de fallo:  "PHP_MATRIX_FAIL ..." + detalle + exit(1)
 *
 * Uso: php tests/gate-php-matrix.php [--scope=plugin1,plugin2]
 */

require __DIR__ . '/gate-scope.php';

$tests_dir = dirname( __FILE__ );
$suite_root = dirname( $tests_dir );
$plugins_root = $GATE_ROOT;

$targets = require $tests_dir . '/release-targets.php';
$versions = $targets['php_matrix'] ?? array( '8.2', '8.4' );

$bin_root = '/opt/homebrew/opt';
$bins = array();
foreach ( $versions as $v ) {
	$b = "{$bin_root}/php@{$v}/bin/php";
	if ( ! is_file( $b ) ) {
		echo "PHP_MATRIX_FAIL binario_php@{$v}_no_encontrado={$b}\n";
		exit( 1 );
	}
	$bins[ $v ] = $b;
}

// Recolectar archivos PHP de los plugins (misma exclusión que los demás gates).
$files = array();
$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugins_root, FilesystemIterator::SKIP_DOTS )
);
foreach ( $it as $file ) {
	if ( ! $file->isFile() || $file->getExtension() !== 'php' ) {
		continue;
	}
	$rel = str_replace( '\\', '/', $file->getPathname() );
	if ( ! gate_in_scope( substr( $rel, strlen( rtrim( $plugins_root, '/' ) ) + 1 ) ) ) {
		continue;
	}
	if ( preg_match( '#(^|/)(\.[^/]*/|tests?/|vendor/|node_modules/|fixtures?/|acceptance/)#i', '/' . $rel ) ) {
		continue;
	}
	// Código de terceros vendido (dompdf S16) no pasa por gates propios:
	// lintarlo serial con cada versión PHP (~400 archivos) no aporta (es código
	// upstream ya validado) y triplica el tiempo del gate.
	if ( strpos( '/' . $rel, '/lib/dompdf/' ) !== false ) {
		continue;
	}
	$files[] = $rel;
}

$total = count( $files );
$errors = array();

foreach ( $bins as $v => $bin ) {
	foreach ( $files as $f ) {
		$out = array();
		$rc  = 0;
		exec( escapeshellarg( $bin ) . ' -l ' . escapeshellarg( $f ) . ' 2>&1', $out, $rc );
		if ( $rc !== 0 ) {
			$errors[] = sprintf( 'php@%s  %s', $v, implode( ' | ', $out ) );
		}
	}
}

if ( count( $errors ) > 0 ) {
	echo "PHP_MATRIX_FAIL versiones=" . count( $bins ) . " archivos={$total} total_errores=" . count( $errors ) . "\n";
	foreach ( array_slice( $errors, 0, 40 ) as $e ) {
		echo "  ✗ {$e}\n";
	}
	exit( 1 );
}

echo "PHP_MATRIX_OK versiones=" . count( $bins ) . ' (' . implode( ',', $versions ) . ") archivos={$total} total_errores=0\n";
exit( 0 );
