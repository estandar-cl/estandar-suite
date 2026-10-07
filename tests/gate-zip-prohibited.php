<?php
/**
 * gate-zip-prohibited.php — Gate de archivos prohibidos en los ZIPs.
 *
 * Escanea los directorios de los 26 plugins por archivos que NUNCA deben
 * publicarse en un ZIP de distribución: secretos en claro, config de base de
 * datos, backups, logs y llaves privadas. El escaneo se hace sobre el código
 * fuente (que es lo que se empaqueta), antes de generar el ZIP.
 *
 * Prohibidos (basename o rel-path):
 *   - .env / .env.*                     (secretos en claro)
 *   - wp-config.php / wp-config-*.php   (credenciales de BD)
 *   - *.sql, *.sqlite, *.db             (dumps / bases embebidas)
 *   - *.log                             (logs operacionales)
 *   - *.bak, *.backup, *.old, *.bak-*   (backups)
 *   - *.pem, *.key, *.p12, *.pfx, id_rsa*, id_ed25519*  (llaves privadas)
 *
 * Salida de éxito: "ZIP_PROHIBITED_OK plugins=26 prohibidos=0"
 * Salida de fallo:  "ZIP_PROHIBITED_FAIL ..." + detalle + exit(1)
 *
 * Uso: php tests/gate-zip-prohibited.php [--scope=plugin1,plugin2]
 */

require __DIR__ . '/gate-scope.php';
$plugins_root = $GATE_ROOT;

$PROHIBITED = array(
	'/^\.env($|\.)/',           // .env, .env.local, .env.production...
	'/^wp-config(?:-.+)?\.php$/',
	'/\.sql(?:ite\d?)?$/',
	'/\.db$/',
	'/\.log$/',
	'/\.bak(?:\.?[0-9A-Za-z_-]*)?$/',
	'/\.(?:backup|old)$/',
	'/\.(?:pem|key|p12|pfx)$/',
	'/^id_(?:rsa|ed25519|ecdsa|dsa)(?:\.pub)?$/',
);

$bad = array();
$plugins = 0;

$it = new RecursiveDirectoryIterator( $plugins_root, FilesystemIterator::SKIP_DOTS );
foreach ( $it as $dir ) {
	if ( ! $dir->isDir() ) {
		continue;
	}
	$slug = $dir->getFilename();
	if ( strpos( $slug, 'estandar-' ) !== 0 ) {
		continue;
	}
	if ( ! gate_in_scope( $slug . '/' ) ) {
		continue;
	}
	$plugins++;

	$rit = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir->getPathname(), FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $rit as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$rel = str_replace( '\\', '/', $file->getPathname() );
		$rel = substr( $rel, strlen( rtrim( $plugins_root, '/' ) ) + 1 );
		$base = $file->getFilename();
		foreach ( $PROHIBITED as $pat ) {
			if ( preg_match( $pat, $base ) || preg_match( $pat, $rel ) ) {
				$bad[] = $rel;
				break;
			}
		}
	}
}

$bad = array_values( array_unique( $bad ) );
sort( $bad );

if ( count( $bad ) > 0 ) {
	echo 'ZIP_PROHIBITED_FAIL plugins=' . $plugins . ' prohibidos=' . count( $bad ) . "\n";
	foreach ( $bad as $rel ) {
		echo "  ✗ {$rel}\n";
	}
	exit( 1 );
}

echo 'ZIP_PROHIBITED_OK plugins=' . $plugins . " prohibidos=0\n";
exit( 0 );
