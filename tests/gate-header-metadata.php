<?php
/**
 * gate-header-metadata.php — Gate de coherencia de headers de plugins.
 *
 * Valida que los 26 plugins declaren en su archivo principal los headers
 * `Requires PHP`, `Requires at least` y `Tested up to`, y que sean coherentes
 * con los floors del proyecto (tests/release-targets.php):
 *
 *   - Requires PHP     ≥ php_floor (8.0 — el código usa features 8.0).
 *   - Requires at least ≥ wp_min   (6.0).
 *   - Tested up to      ≥ wp_min   (presente, no vacío).
 *
 * Headers ausentes o incoherentes = candidatos a romper instalaciones
 * (WordPress muestra el aviso de incompatibilidad) → el gate falla.
 *
 * Salida de éxito: "HEADER_META_OK plugins=N incoherentes=0"
 * Salida de fallo:  "HEADER_META_FAIL ..." + detalle + exit(1)
 *
 * Uso: php tests/gate-header-metadata.php [--scope=plugin1,plugin2]
 */

require __DIR__ . '/gate-scope.php';

$tests_dir = dirname( __FILE__ );
$plugins_root = $GATE_ROOT;

$targets = require $tests_dir . '/release-targets.php';
$php_floor = $targets['php_floor'] ?? '8.0';
$wp_min    = $targets['wp_min'] ?? '6.0';

// Normaliza "8.0" / "8.0.1" / "8" → array(8,0,1) para comparar versiones.
$norm = static function ( string $v ): array {
	if ( ! preg_match( '/^\d+(\.\d+){0,2}$/', trim( $v ) ) ) {
		return array();
	}
	return array_map( 'intval', explode( '.', trim( $v ) ) );
};

$lt = static function ( array $a, array $b ): bool {
	$n = max( count( $a ), count( $b ) );
	for ( $i = 0; $i < $n; $i++ ) {
		$x = $a[ $i ] ?? 0;
		$y = $b[ $i ] ?? 0;
		if ( $x !== $y ) {
			return $x < $y;
		}
	}
	return false;
};

// Lectura de headers desde el bloque de cabecera del main file.
$read_headers = static function ( string $main ): array {
	$src = (string) file_get_contents( $main, false, null, 0, 8192 );
	$get = static function ( string $key, string $src ): string {
		if ( preg_match( '/^[ \t\/*#]*' . preg_quote( $key, '/' ) . ': *([0-9][0-9A-Za-z_.\-]*) *$/m', $src, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	};
	return array(
		'name'       => $get( 'Plugin Name', $src ),
		'php'        => $get( 'Requires PHP', $src ),
		'at_least'   => $get( 'Requires at least', $src ),
		'tested_up'  => $get( 'Tested up to', $src ),
	);
};

// Descubrir el main file de cada plugin (el que declara "Plugin Name:").
$plugins = array();
$it = new RecursiveDirectoryIterator( $plugins_root, FilesystemIterator::SKIP_DOTS );
foreach ( $it as $dir ) {
	if ( ! $dir->isDir() ) {
		continue;
	}
	$slug = $dir->getFilename();
	// Solo plugins de la Suite (dirs `estandar-*`); se ignoran bin/images/
	// reports/tests/.release-build y otros artefactos del workspace.
	if ( strpos( $slug, 'estandar-' ) !== 0 ) {
		continue;
	}
	if ( ! gate_in_scope( $slug . '/' ) ) {
		continue;
	}
	$main = '';
	foreach ( glob( $dir->getPathname() . '/*.php' ) ?: array() as $candidate ) {
		$head = (string) file_get_contents( $candidate, false, null, 0, 4000 );
		if ( strpos( $head, 'Plugin Name:' ) !== false ) {
			$main = $candidate;
			break;
		}
	}
	if ( $main === '' ) {
		$plugins[ $slug ] = null;
		continue;
	}
	$plugins[ $slug ] = $main;
}

$php_floor_a = $norm( $php_floor );
$wp_min_a    = $norm( $wp_min );

$bad = array();
foreach ( $plugins as $slug => $main ) {
	if ( ! $main ) {
		$bad[] = "{$slug}: sin main file con Plugin Name";
		continue;
	}
	$h = $read_headers( $main );

	$php_a = $norm( $h['php'] );
	if ( empty( $php_a ) ) {
		$bad[] = "{$slug}: falta Requires PHP";
	} elseif ( $lt( $php_a, $php_floor_a ) ) {
		$bad[] = "{$slug}: Requires PHP {$h['php']} < floor {$php_floor}";
	}

	$wp_a = $norm( $h['at_least'] );
	if ( empty( $wp_a ) ) {
		$bad[] = "{$slug}: falta Requires at least";
	} elseif ( $lt( $wp_a, $wp_min_a ) ) {
		$bad[] = "{$slug}: Requires at least {$h['at_least']} < wp_min {$wp_min}";
	}

	$tut_a = $norm( $h['tested_up'] );
	if ( empty( $tut_a ) ) {
		$bad[] = "{$slug}: falta Tested up to";
	} elseif ( $lt( $tut_a, $wp_min_a ) ) {
		$bad[] = "{$slug}: Tested up to {$h['tested_up']} < wp_min {$wp_min}";
	}
}

if ( count( $bad ) > 0 ) {
	echo 'HEADER_META_FAIL plugins=' . count( $plugins ) . ' incoherentes=' . count( $bad ) . " (php_floor={$php_floor} wp_min={$wp_min})\n";
	foreach ( $bad as $line ) {
		echo "  ✗ {$line}\n";
	}
	exit( 1 );
}

echo 'HEADER_META_OK plugins=' . count( $plugins ) . " incoherentes=0 (php_floor={$php_floor} wp_min={$wp_min})\n";
exit( 0 );
