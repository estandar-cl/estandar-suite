<?php
/**
 * ESTANDAR SUITE v2 — Capture · Render harness del maquetador (editor por subtabs).
 *
 * Renderiza la pantalla de edición de un form y vuelca el HTML a stdout para
 * que el test de cola (run-subtabs-functional.sh) verifique paneles/subtabs.
 *
 * Uso:
 *   php render-maq-harness.php <wp-path> <admin-user-id> estandar-suite-capture config forms formularios <form_id> [st]
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$_SERVER['HTTP_HOST'] = 'estandar-subtabs.test';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
$_SERVER['SERVER_NAME'] = 'estandar-subtabs.test';
$_SERVER['REQUEST_METHOD'] = 'GET';

$_GET['page'] = isset( $argv[3] ) ? (string) $argv[3] : 'estandar-suite-capture';
if ( ! empty( $argv[4] ) ) { $_GET['tab'] = (string) $argv[4]; }
if ( ! empty( $argv[5] ) ) { $_GET['sub'] = (string) $argv[5]; }
if ( ! empty( $argv[6] ) ) { $_GET['ss'] = (string) $argv[6]; }
if ( ! empty( $argv[7] ) ) { $_GET['editar'] = (int) $argv[7]; }
if ( ! empty( $argv[8] ) ) { $_GET['st'] = (string) $argv[8]; }

define( 'WP_ADMIN', true );
define( 'WP_INSTALLING', false );
define( 'WP_USE_THEMES', false );

require_once rtrim( $argv[1], '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

wp_set_current_user( (int) $argv[2] );

$GLOBALS['menu'] = array();
$GLOBALS['submenu'] = array();
$GLOBALS['_wp_menu_nopriv'] = array();
$GLOBALS['_wp_submenu_nopriv'] = array();
$GLOBALS['_registered_pages'] = array();

do_action( 'admin_menu' );

$parent = '';
foreach ( (array) $GLOBALS['submenu'] as $p => $items ) {
	foreach ( $items as $s ) {
		if ( $s[2] === $_GET['page'] ) {
			$parent = $p;
			break 2;
		}
	}
}
$hook = get_plugin_page_hookname( $_GET['page'], $parent );
ob_start();
do_action( $hook );
echo ob_get_clean();
