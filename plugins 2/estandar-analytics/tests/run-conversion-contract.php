<?php

define( 'ABSPATH', __DIR__ . '/' );

class EA_Settings {
	public static function get() {
		return array(
			'conversions' => array(
				array(
					'key'  => 'lead',
					'type' => 'form_submit',
				),
			),
		);
	}
}

class EA_Utils {
	public static function sanitize_url( $value ) {
		return (string) $value;
	}
}

function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/class-events.php';

$method = new ReflectionMethod( EA_Events::class, 'matches_conversion' );
$definition = array( 'key' => 'lead', 'type' => 'form_submit' );

$attempt = $method->invoke( null, 'form_submit', $definition, 'form_submit_attempt', array(), '' );
$success = $method->invoke( null, 'form_submit', $definition, 'form_submit', array(), '' );

if ( $attempt || ! $success ) {
	fwrite( STDERR, "Conversion contract failed.\n" );
	exit( 1 );
}

echo "ANALYTICS CONVERSION CONTRACT PASS\n";
