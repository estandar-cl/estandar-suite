<?php
/**
 * release-targets.php — Entornos objetivo de la Suite (config del release gate).
 *
 * Define las instalaciones de producción a las que se distribuye la Suite, sus
 * versiones de PHP/WP conocidas y los temas activos. Lo usa el pipeline de
 * release (release.command) para los gates:
 *
 *   - gate_php_matrix     → lint de sintaxis con las versiones PHP declaradas
 *                           mínima y máxima (instalaciones reales).
 *   - gate_theme_smoke    → smoke "todos juntos" activando cada tema objetivo.
 *   - gate_remote_health  → HTTP 200 + wp-json de cada instalación.
 *   - gate_header_metadata→ coherencia de headers "Requires PHP / at least".
 *
 * Retorna array asociativo. Este archivo se incluye desde release.command y
 * desde los scripts de test (no define constantes globales).
 */

return array(
	'installations' => array(
		array(
			'slug'      => 'estandar.cl',
			'url'       => 'https://estandar.cl',
			'hosting'   => 'siteground',
			'php'       => '8.2',        // PHP declarado (no expuesto); floor realista
			'wp'        => '7.0',
			'themes'    => array( 'astra', 'astra-child' ),
			'note'      => 'SiteGround (nginx); tema Astra + astra-child',
		),
		array(
			'slug'      => 'akcontadores.cl',
			'url'       => 'https://akcontadores.cl',
			'hosting'   => 'godaddy-managed-wp',
			'php'       => '8.2',
			'wp'        => '7.0',
			'themes'    => array( 'astra' ),
			'note'      => 'GoDaddy Managed WP + Cloudflare; Astra + Elementor',
		),
		array(
			'slug'      => 'acacios.cl',
			'url'       => 'https://acacios.cl',
			'hosting'   => 'siteground',
			'php'       => '8.2',        // verificado: PHP 8.2.33 (ZTS)
			'wp'        => '7.0',        // verificado: WP 7.0.4
			'themes'    => array( 'Divi', 'Divi-child' ),
			'note'      => 'SiteGround; PHP 8.2.33, WP 7.0.4, tema Divi + Divi-child',
		),
	),

	// Rango de versiones PHP sobre las que se debe lint (sintaxis).
	// Se intenta usar los binarios disponibles en /opt/homebrew/opt/php@X.Y/bin/php.
	// "min" también lo usa gate_header_metadata como límite inferior declarable.
	'php_matrix'  => array( '8.2', '8.4' ),

	// Versión PHP mínima que el código exige (features 8.0: str_contains,
	// match, array_is_list...). Es el floor de "Requires PHP" en los headers.
	'php_floor'   => '8.0',

	// Versión WordPress mínima declarada en headers "Requires at least".
	'wp_min'      => '6.0',

	// Temas objetivo a probar en el Playground (slug => carpeta local de origen).
	// Divi/Divi-child provienen del backup local de acacios; astra de wordpress.org.
	'theme_sources' => array(
		'Divi'        => '/Volumes/ESTANDAR_2026/01_Clientes/Acacios/wordpress/wp-content/themes/Divi',
		'Divi-child'  => '/Volumes/ESTANDAR_2026/01_Clientes/Acacios/wordpress/wp-content/themes/Divi-child',
		'astra'       => '/Volumes/ESTANDAR_2026/02_EstandarIA/Estandar_Suite/tests/themes/astra',
		'astra-child' => '/Volumes/ESTANDAR_2026/02_EstandarIA/Estandar_Suite/tests/themes/astra-child',
	),
);
