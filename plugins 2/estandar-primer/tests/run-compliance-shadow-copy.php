<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$test_options = [];
$test_actions = [];

function get_option( $key, $default = false ) {
	global $test_options;
	return $test_options[ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	global $test_options;
	$test_options[ $key ] = $value;
	return true;
}
function wp_parse_args( $args, $defaults = [] ) { return array_merge( $defaults, $args ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_EMAIL ); }
function is_email( $value ) { return false !== filter_var( (string) $value, FILTER_VALIDATE_EMAIL ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function add_filter() {}
function add_action() {}
function do_action( $hook, $payload = null ) {
	global $test_actions;
	$test_actions[] = [ $hook, $payload ];
}

require dirname( __DIR__ ) . '/includes/compliance-shadow-copy.php';

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function set_shadow_settings( array $changes ): void {
	global $test_options;
	$test_options[ ESTANDAR_COMPLIANCE_SHADOW_COPY_OPTION ] = array_merge(
		estandar_core_compliance_shadow_copy_defaults(),
		$changes
	);
}

$base_headers = [ 'Content-Type: text/html; charset=UTF-8' ];
$context      = [ 'type' => 'daily_digest', 'to' => 'operador@example.com', 'level' => 'daily_digest' ];

set_shadow_settings( [ 'enabled' => 0 ] );
assert_true( $base_headers === estandar_core_compliance_shadow_copy_headers( $base_headers, $context ), 'OFF must not add BCC.' );

set_shadow_settings( [ 'enabled' => 1, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
assert_true( $base_headers === estandar_core_compliance_shadow_copy_headers( $base_headers, $context ), 'Expired monitoring must not add BCC.' );
assert_true( 0 === get_option( ESTANDAR_COMPLIANCE_SHADOW_COPY_OPTION )['enabled'], 'Expiration must disable monitoring.' );

$allowed = [
	'daily_digest',
	'reminder_soft',
	'reminder_operational',
	'reminder_insistent',
	'escalation',
	'weekly_digest',
];
foreach ( $allowed as $type ) {
	set_shadow_settings( [ 'enabled' => 1, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] );
	$headers = estandar_core_compliance_shadow_copy_headers(
		$base_headers,
		[ 'type' => $type, 'to' => 'operador@example.com', 'level' => $type ]
	);
	assert_true( in_array( 'Bcc: gestion@estandar.cl', $headers, true ), "ON must copy {$type}." );
}

foreach ( [ 'new_lead', 'chatbot', 'analytics' ] as $type ) {
	set_shadow_settings( [ 'enabled' => 1, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] );
	$headers = estandar_core_compliance_shadow_copy_headers(
		$base_headers,
		[ 'type' => $type, 'to' => 'operador@example.com', 'level' => $type ]
	);
	assert_true( $base_headers === $headers, "{$type} must be excluded." );
}

set_shadow_settings( [ 'enabled' => 1, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ] );
$headers = estandar_core_compliance_shadow_copy_headers(
	[ 'Content-Type: text/html; charset=UTF-8', 'Bcc: auditor@example.com' ],
	$context
);
assert_true( in_array( 'Bcc: auditor@example.com', $headers, true ), 'Existing BCC must be preserved.' );
assert_true( in_array( 'Bcc: gestion@estandar.cl', $headers, true ), 'Shadow BCC must be added alongside existing BCC.' );

set_shadow_settings( [ 'enabled' => 1, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
assert_true( $base_headers === estandar_core_compliance_shadow_copy_headers( $base_headers, $context ), 'Auto-expiration must stop copies immediately.' );

echo "PASS: COMPLIANCE_SHADOW_COPY_V1 (8 validations)\n";
