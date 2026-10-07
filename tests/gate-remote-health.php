<?php
/**
 * gate-remote-health.php — Gate de salud de las instalaciones de producción.
 *
 * Antes de publicar un update se verifica que los sitios objetivo estén
 * operativos: HTTP 200 en home y en el endpoint REST (wp-json) de cada
 * instalación. Previene publicar un update mientras un sitio está caído.
 *
 *  - Instalaciones (estandar.cl / akcontadores.cl / acacios.cl):
 *    home=200 y wp-json=200 (siguen redirecciones).
 *  - updates.estandar.cl (servidor de archivos estático, sin WP):
 *    solo home=200; wp-json NO aplica (404 esperado).
 *
 * Salida de éxito: "REMOTE_HEALTH_OK sitios=N ok=3"
 * Salida de fallo:  "REMOTE_HEALTH_FAIL ..." + detalle + exit(1)
 *
 * Uso: php tests/gate-remote-health.php
 */

$tests_dir = dirname( __FILE__ );
$targets = require $tests_dir . '/release-targets.php';

$probe = static function ( string $url ): int {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS      => 5,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT        => 20,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_USERAGENT      => 'Estandar-Release-Gate/1.0',
	) );
	curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	return $code;
};

$bad = array();
$ok  = 0;
$n   = 0;

foreach ( $targets['installations'] as $inst ) {
	$slug = $inst['slug'];
	$url  = rtrim( (string) $inst['url'], '/' );
	$n++;

	$h = $probe( $url . '/' );
	if ( $h === 200 ) {
		$ok++;
	} else {
		$bad[] = "{$slug}: home HTTP {$h}";
	}

	$j = $probe( $url . '/wp-json/' );
	if ( $j === 200 ) {
		$ok++;
	} else {
		$bad[] = "{$slug}: wp-json HTTP {$j}";
	}
}

// Servidor de updates: repositorio estático (solo home).
$updates = 'https://updates.estandar.cl';
$n++;
$h = $probe( $updates . '/' );
if ( $h === 200 ) {
	$ok++;
} else {
	$bad[] = "updates.estandar.cl: home HTTP {$h}";
}

if ( count( $bad ) > 0 ) {
	echo 'REMOTE_HEALTH_FAIL sitios=' . $n . ' ok=' . $ok . "\n";
	foreach ( $bad as $line ) {
		echo "  ✗ {$line}\n";
	}
	exit( 1 );
}

echo 'REMOTE_HEALTH_OK sitios=' . $n . " ok={$ok}\n";
exit( 0 );
