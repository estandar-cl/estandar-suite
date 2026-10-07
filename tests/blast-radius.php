<?php
/**
 * blast-radius.php — Análisis de impacto pre-release (sin git).
 *
 * Responde, antes de publicar: ¿qué cambió desde el ÚLTIMO RELEASE y qué hay
 * que verificar? Como la Suite no es un repo git, el baseline son los ZIPs del
 * último release (`.release-build/<id>/<plugin>.zip`, árbol completo), y se
 * comparan por hash (md5) contra el código fuente actual en `plugins/`.
 *
 * Salida (informativa, exit 0 salvo --strict):
 *   - Por plugin: cambiados / añadidos / eliminados + lista (cap `--limit`).
 *   - Verificación recomendada: tests propios del plugin + gates compartidos.
 *
 * Uso:
 *   php tests/blast-radius.php [--scope=a,b] [--limit=N] [--full] [--json]
 *                              [--base=<release_id|ruta .release-build>]
 *
 * Integración: `release.command --dry-run` lo invoca como paso informativo
 * ANTES de los gates (no aborta el release por sí mismo).
 */

require __DIR__ . '/gate-scope.php';

$GATE_ROOT = $GATE_ROOT;

$scope_arg  = '';
$base_arg   = '';
$limit      = 40;
$full       = in_array( '--full', $argv, true );
$as_json    = in_array( '--json', $argv, true );
$strict     = in_array( '--strict', $argv, true );
foreach ( $argv as $a ) {
	if ( strpos( $a, '--scope=' ) === 0 ) {
		$scope_arg = substr( $a, 8 );
	}
	if ( strpos( $a, '--base=' ) === 0 ) {
		$base_arg = substr( $a, 7 );
	}
	if ( strpos( $a, '--limit=' ) === 0 ) {
		$limit = max( 0, (int) substr( $a, 8 ) );
	}
}

// ── Localizar el baseline (último release) ─────────────────────────────────
$root        = dirname( __DIR__ );
$build_root  = $root . '/.release-build';

function br_latest_release_dir( string $build_root, string $base_arg ): ?string {
	if ( $base_arg !== '' ) {
		if ( is_dir( $base_arg ) ) {
			return rtrim( $base_arg, '/' );
		}
		$cand = $build_root . '/' . $base_arg;
		return is_dir( $cand ) ? $cand : null;
	}
	// Preferir el release_id del .release-history.log de la raíz.
	$hist = $build_root . '/../.release-history.log';
	if ( is_file( $hist ) ) {
		$lines = file( $hist, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( $lines ) {
			$last = end( $lines );
			if ( preg_match( '/release_id=([0-9\-]+)/', $last, $m ) && is_dir( $build_root . '/' . $m[1] ) ) {
				return $build_root . '/' . $m[1];
			}
		}
	}
	// Fallback: el directorio con ZIPs más reciente.
	$best = null;
	foreach ( glob( $build_root . '/*', GLOB_ONLYDIR ) ?: array() as $d ) {
		if ( glob( $d . '/estandar-*.zip' ) ) {
			if ( $best === null || strcmp( basename( $d ), basename( $best ) ) > 0 ) {
				$best = $d;
			}
		}
	}
	return $best;
}

$base = br_latest_release_dir( $build_root, $base_arg );

// ── Hashes del árbol actual ────────────────────────────────────────────────
function br_current_hashes( string $dir ): array {
	$out = array();
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $f ) {
		if ( ! $f->isFile() ) {
			continue;
		}
		$rel  = str_replace( $dir . '/', '', $f->getPathname() );
		$segs = explode( '/', $rel );
		$skip = false;
		foreach ( $segs as $s ) {
			if ( $s !== '' && ( $s[0] === '.' || in_array( $s, array( 'node_modules', 'vendor' ), true ) ) ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}
		$out[ $rel ] = md5_file( $f->getPathname() );
	}
	ksort( $out );
	return $out;
}

// ── Hashes del baseline (ZIP del último release) ───────────────────────────
function br_zip_hashes( string $zip, string $slug ): array {
	$out = array();
	if ( ! is_file( $zip ) || ! class_exists( 'ZipArchive' ) ) {
		return $out;
	}
	$z = new ZipArchive();
	if ( $z->open( $zip ) !== true ) {
		return $out;
	}
	for ( $i = 0; $i < $z->numFiles; $i++ ) {
		$stat = $z->statIndex( $i );
		if ( ! $stat || substr( $stat['name'], -1 ) === '/' ) {
			continue;
		}
		$name = $stat['name'];
		$rel  = preg_replace( '#^' . preg_quote( $slug, '#' ) . '/#', '', $name );
		$data = $z->getFromIndex( $i );
		if ( $data !== false ) {
			$out[ $rel ] = md5( $data );
		}
	}
	$z->close();
	ksort( $out );
	return $out;
}

// ── Plugins en alcance ─────────────────────────────────────────────────────
$plugins = array();
$it = new RecursiveDirectoryIterator( $GATE_ROOT, FilesystemIterator::SKIP_DOTS );
foreach ( $it as $dir ) {
	if ( ! $dir->isDir() ) {
		continue;
	}
	$slug = $dir->getFilename();
	if ( strpos( $slug, 'estandar-' ) !== 0 || $slug === 'estandar-zzz-gatetest' ) {
		continue;
	}
	if ( $scope_arg !== '' ) {
		$in = false;
		foreach ( explode( ',', $scope_arg ) as $s ) {
			if ( trim( $s ) === $slug ) {
				$in = true;
				break;
			}
		}
		if ( ! $in ) {
			continue;
		}
	}
	$plugins[ $slug ] = $dir->getPathname();
}
ksort( $plugins );

$report = array();
$tot    = array( 'changed' => 0, 'added' => 0, 'removed' => 0 );

foreach ( $plugins as $slug => $dir ) {
	$zip = $base ? $base . '/' . $slug . '.zip' : '';
	$cur = br_current_hashes( $dir );
	$old = $zip ? br_zip_hashes( $zip, $slug ) : array();

	$changed = array();
	$added   = array();
	$removed = array();
	foreach ( $cur as $rel => $h ) {
		if ( ! isset( $old[ $rel ] ) ) {
			$added[] = $rel;
		} elseif ( $old[ $rel ] !== $h ) {
			$changed[] = $rel;
		}
	}
	foreach ( $old as $rel => $h ) {
		if ( ! isset( $cur[ $rel ] ) ) {
			$removed[] = $rel;
		}
	}

	$tests = array();
	foreach ( glob( $dir . '/tests/*.php' ) ?: array() as $t ) {
		$tests[] = str_replace( $root . '/', '', $t );
	}
	foreach ( glob( $dir . '/tests/*.sh' ) ?: array() as $t ) {
		$tests[] = str_replace( $root . '/', '', $t );
	}

	$entry = array(
		'plugin'    => $slug,
		'baseline'  => $zip && is_file( $zip ) ? 'zip' : 'none',
		'changed'   => $changed,
		'added'     => $added,
		'removed'   => $removed,
		'tests'     => $tests,
	);
	$report[ $slug ] = $entry;
	$tot['changed'] += count( $changed );
	$tot['added']   += count( $added );
	$tot['removed'] += count( $removed );
}

// ── Salida ─────────────────────────────────────────────────────────────────
if ( $as_json ) {
	echo json_encode( array( 'base' => $base, 'totals' => $tot, 'plugins' => $report ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 0 );
}

$base_label = $base ? basename( $base ) : '(no encontrado — sin baseline)';
echo "── Blast radius pre-release (baseline: {$base_label}) ──\n";
if ( ! $base ) {
	echo "  ⚠ sin baseline (no hay .release-build con ZIPs); nada que comparar.\n";
	exit( 0 );
}

foreach ( $report as $slug => $r ) {
	$n = count( $r['changed'] ) + count( $r['added'] ) + count( $r['removed'] );
	if ( $n === 0 ) {
		echo "  ok  {$slug}: sin cambios desde el último release\n";
		continue;
	}
	printf(
		"  ●  %s: %d cambiados, %d añadidos, %d eliminados\n",
		$slug, count( $r['changed'] ), count( $r['added'] ), count( $r['removed'] )
	);
	if ( $r['tests'] ) {
		echo '     verificar: ' . implode( ', ', array_map( 'basename', $r['tests'] ) ) . "\n";
	}
	$show = $full ? PHP_INT_MAX : $limit;
	$all  = array();
	foreach ( $r['changed'] as $p ) { $all[] = "M {$p}"; }
	foreach ( $r['added'] as $p )   { $all[] = "A {$p}"; }
	foreach ( $r['removed'] as $p ) { $all[] = "D {$p}"; }
	foreach ( array_slice( $all, 0, $show ) as $line ) {
		echo "       {$line}\n";
	}
	if ( count( $all ) > $show ) {
		echo '       … +' . ( count( $all ) - $show ) . " (usar --full)\n";
	}
}
printf(
	"\n  TOTAL: %d cambiados, %d añadidos, %d eliminados\n",
	$tot['changed'], $tot['added'], $tot['removed']
);
echo "  (informativo: no aborta el release)\n";
exit( $strict && array_sum( $tot ) > 0 ? 2 : 0 );
