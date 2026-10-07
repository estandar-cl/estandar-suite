<?php
/**
 * OPERATOR_SOURCE_OF_TRUTH_V1 — tests de la resolución del operador del proyecto.
 *
 *   php tests/run-operator-source.php   → expect "0 failed"
 *
 * Verifica (WordPress real / Playground):
 *   - ProjectRegistry::operator_for() toma la fuente PRIMARIA `es_operators`
 *     (primer operador ACTIVO asignado al proyecto).
 *   - Al DESACTIVAR el único operador, operator_for() devuelve vacío (NO cae al
 *     espejo obsoleto del registry).
 *   - Sin filas en es_operators (proyecto legacy), cae al ESPEJO del registry.
 *
 * Limpieza total de fixtures al final.
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__, 3 ) . '/Playground/wp-load.php';
}

if ( ! class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) ) {
	fwrite( STDERR, "ProjectRegistry no cargada — ¿estandar-core activo en Playground?\n" );
	exit( 1 );
}

use Estandar\Core\Leads\ProjectRegistry as PR;
use Estandar\Core\Leads\Operators as Ops;

$passed = 0; $failed = 0;
function chk( $name, $ok ) { global $passed, $failed; if ( $ok ) { $passed++; echo "PASS  {$name}\n"; } else { $failed++; echo "FAIL  {$name}\n"; } }

global $wpdb;
$reg_table = $wpdb->prefix . 'estandar_project_registry';
$op_table  = $wpdb->prefix . 'es_operators';
PR::maybe_install();
Ops::maybe_install();

$slug = 'zz-test-osrc-' . substr( md5( (string) microtime( true ) ), 0, 6 );

// Fixture: proyecto con espejo en el registry.
$now = current_time( 'mysql' );
$wpdb->insert( $reg_table, array(
	'client_id'      => 'testcl',
	'project_id'     => $slug,
	'project_uuid'   => wp_generate_uuid4(),
	'label'          => 'TEST Operator Source',
	'active'         => 1,
	'sort_order'     => 9999,
	'created_at'     => $now,
	'updated_at'     => $now,
	'operator_name'  => 'MIRROR VIEJO',
	'operator_email' => 'mirror@example.com',
	'operator_phone' => '111111111',
) );

echo "── Fuente primaria: es_operators (primer ACTIVO) ──────────────────────\n";
$id_active = (int) $wpdb->insert_id; // no-op, mantener orden
$id_a = 0; $id_b = 0;
$wpdb->insert( $op_table, array( 'operator_uuid' => wp_generate_uuid4(), 'name' => 'Activo', 'email' => 'activo@example.com', 'project_slug' => $slug, 'whatsapp_number' => '222222222', 'active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
$id_a = (int) $wpdb->insert_id;
$wpdb->insert( $op_table, array( 'operator_uuid' => wp_generate_uuid4(), 'name' => 'Inactivo', 'email' => 'inactivo@example.com', 'project_slug' => $slug, 'whatsapp_number' => '333333333', 'active' => 0, 'created_at' => $now, 'updated_at' => $now ) );
$id_b = (int) $wpdb->insert_id;

$op = PR::operator_for( $slug );
chk( '1a toma el operador ACTIVO de es_operators (no el espejo)', ( $op['email'] ?? '' ) === 'activo@example.com' && ( $op['phone'] ?? '' ) === '222222222' );
chk( '1b no devuelve el espejo del registry', ( $op['email'] ?? '' ) !== 'mirror@example.com' );

echo "── Al desactivar el único activo → vacío (sin espejo obsoleto) ────────\n";
$wpdb->update( $op_table, array( 'active' => 0 ), array( 'id' => $id_a ) );
$op2 = PR::operator_for( $slug );
chk( '2a todos inactivos → vacío (no cae al mirror)', $op2 === array() );

echo "── Proyecto legacy sin es_operators → cae al ESPEJO del registry ──────\n";
$wpdb->delete( $op_table, array( 'project_slug' => $slug ) );
$op3 = PR::operator_for( $slug );
chk( '3a sin es_operators → espejo del registry', ( $op3['email'] ?? '' ) === 'mirror@example.com' && ( $op3['name'] ?? '' ) === 'MIRROR VIEJO' );

// Limpieza
$wpdb->delete( $reg_table, array( 'project_id' => $slug ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
