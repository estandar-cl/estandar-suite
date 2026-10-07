<?php
/**
 * seed-playground.php — Seed idempotente del Playground local para los tests
 * WP-dependientes del release gate.
 *
 * Solo toca la BD local del Playground (Estandar_Suite/Playground). Nunca
 * producción. Es idempotente: correrlo N veces deja el mismo estado.
 *
 * Uso: php tests/seed-playground.php [--reset]
 */

$_SERVER['HTTP_HOST'] = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_USE_THEMES', false );

$suite_root = dirname( __DIR__ );
require $suite_root . '/Playground/wp-load.php';

global $wpdb;

$reset = in_array( '--reset', $argv ?? array(), true );

// ── Proyecto de test en el registry (primer) ───────────────────────────────
$registry = $wpdb->prefix . 'estandar_project_registry';
$exists   = $wpdb->get_var( "SHOW TABLES LIKE '{$registry}'" ) === $registry;
if ( ! $exists ) {
	fwrite( STDERR, "seed: tabla {$registry} no existe — ¿estandar-primer activo en Playground?\n" );
	exit( 1 );
}

$now = current_time( 'mysql' );

if ( $reset ) {
	$wpdb->query( "DELETE FROM {$registry} WHERE project_id = 'test-project'" );
}

$wpdb->query(
	$wpdb->prepare(
		"INSERT INTO {$registry}
			(client_id, project_id, project_uuid, label, city, active, sort_order, created_at, updated_at,
			 company_id, company, project_type, region, commune, sale_type,
			 operator_name, operator_email, operator_phone, form_id, landing_url)
		 VALUES (%s, %s, %s, %s, %s, 1, 0, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
		 ON DUPLICATE KEY UPDATE
			label = VALUES(label), active = 1, operator_name = VALUES(operator_name),
			operator_email = VALUES(operator_email), operator_phone = VALUES(operator_phone),
			updated_at = VALUES(updated_at)",
		'', 'test-project', 'seed-test-project-0000-0000-0000-000000000000',
		'Test Project', 'Santiago', $now, $now,
		'', 'Test Company', 'real_estate', 'Metropolitana', 'Santiago', 'sale',
		'Test Operator', 'operator@test.local', '+56912345678', '', 'https://test.local/'
	)
);

// ── Operador de test en leads ───────────────────────────────────────────────
$operators = $wpdb->prefix . 'es_operators';
$op_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$operators}'" ) === $operators;
if ( ! $op_exists ) {
	fwrite( STDERR, "seed: tabla {$operators} no existe — ¿estandar-leads activo en Playground?\n" );
	exit( 1 );
}

if ( $reset ) {
	$wpdb->query( "DELETE FROM {$operators} WHERE project_slug = 'test-project' AND name = 'Test Operator'" );
}

$op_row = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT id FROM {$operators} WHERE project_slug = 'test-project' AND name = 'Test Operator'"
	)
);

if ( ! $op_row ) {
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$operators}
				(operator_uuid, name, email, project_slug, area, whatsapp_number, active, created_at, updated_at)
			 VALUES (%s, %s, %s, %s, %s, %s, 1, %s, %s)",
			'seed-operator-0000-0000-0000-0000-000000000000',
			'Test Operator', 'operator@test.local', 'test-project', 'Ventas', '+56912345678', $now, $now
		)
	);
}

echo "seed-playground: OK\n";
echo "  registry test-project: " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$registry} WHERE project_id = 'test-project' AND active = 1" ) . "\n";
echo "  operador Test Operator: " . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$operators} WHERE project_slug = 'test-project'" ) ) . "\n";
