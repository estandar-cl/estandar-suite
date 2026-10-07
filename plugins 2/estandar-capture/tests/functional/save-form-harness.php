<?php
/**
 * ESTANDAR SUITE v2 — Capture · Functional harness: guardado del maquetador.
 *
 * Ejecuta \Estandar\Capture\Admin::handle_save_form() con un contexto de request
 * y captura el redirect (wp_redirect) y el die (wp_die_handler) sin abortar el
 * proceso. Registra el estado persistido vía shutdown (el handler hace exit).
 *
 * Uso (desde run-subtabs-functional.sh):
 *   wp eval-file save-form-harness.php --path=$WP -- <persist|nonce|caps> <form_id> [name] [config_json] [user]
 *
 * Imprime una línea "RESULT <json>".
 */

error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$gargs = isset( $GLOBALS['argv'] ) ? (array) $GLOBALS['argv'] : array();
$dash  = array_search( '--', $gargs, true );
$gargs = $dash !== false ? array_slice( $gargs, $dash + 1 ) : array();
$mode = isset( $gargs[0] ) ? (string) $gargs[0] : 'persist';
$fid  = isset( $gargs[1] ) ? (int) $gargs[1] : 0;
$name = isset( $gargs[2] ) ? (string) $gargs[2] : 'Contacto General';
$cfg  = isset( $gargs[3] ) ? (string) $gargs[3] : '';
$user = isset( $gargs[4] ) ? (string) $gargs[4] : 'admin';

$out = array( 'mode' => $mode, 'redirect' => '', 'die_msg' => '', 'persisted' => null, 'ok' => false );

add_filter( 'wp_redirect', static function ( $location ) use ( &$out ) {
	$out['redirect'] = (string) $location;
	return false;
} );

add_filter( 'wp_die_handler', static function ( $handler ) use ( &$out ) {
	return static function ( $message, $title = '', $args = array() ) use ( &$out ) {
		$out['die_msg'] = is_wp_error( $message ) ? $message->get_error_message() : (string) $message;
		exit( 0 );
	};
}, 20 );

$target = $user === 'subscriber' ? get_users( array( 'role' => 'subscriber' ) )[0]->ID : 1;
wp_set_current_user( $target );
$nonce = $mode === 'nonce' ? 'WRONG_NONCE' : wp_create_nonce( 'estandar_suite_capture_save_form' );

$_POST = array(
	'_wpnonce'          => $nonce,
	'_wp_http_referer'  => 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios&editar=' . $fid . '&st=correo',
	'action'            => 'estandar_suite_capture_save_form',
	'form_id'           => $fid,
	'name'              => $name,
	'slug'              => '',
	'form_type'         => 'lead',
	'form_context'      => 'generic',
	'status'            => 'active',
	'config_json'       => $cfg !== '' ? $cfg : '{"sections":[{"title":"Nuevo","fields":[{"name":"telefono","label":"Telefono","type":"tel","required":0}]}],"webhook_url":"https://hook2.test/x"}',
);
$_REQUEST = array_merge( $_POST, $_REQUEST );

register_shutdown_function( static function () use ( &$out, $fid ) {
	$f = \Estandar\Capture\Forms::get( $fid );
	$out['persisted'] = $f ? array( 'name' => $f['name'], 'cfg' => $f['config_json'] ) : null;
	$out['ok']        = $out['die_msg'] === '' && $out['redirect'] !== '' && strpos( $out['redirect'], 'form_saved=1' ) !== false;
	echo 'RESULT ' . wp_json_encode( $out ) . "\n";
} );

\Estandar\Capture\Admin::handle_save_form();
echo "NO_EXIT\n";
