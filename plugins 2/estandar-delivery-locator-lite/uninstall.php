<?php
/**
 * Desinstalación: elimina datos del plugin.
 *
 * @package Estandar_Delivery_Locator_Lite
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'estandar_delivery_locator';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla sin input de usuario.
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

delete_option( 'estandar_dl_settings' );
delete_option( 'estandar_dl_version' );
