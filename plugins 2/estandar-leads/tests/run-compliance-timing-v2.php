<?php

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

final class Compliance_Timing_Wpdb {
    public $prefix = 'wp_';
    public $sent_levels = array();

    public function prepare( $query, ...$args ) {
        foreach ( $args as $arg ) {
            $query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $query, 1 );
        }
        return $query;
    }

    public function get_var( $query ) {
        foreach ( $this->sent_levels as $level ) {
            if ( strpos( $query, "escalation_level = '" . $level . "'" ) !== false ) {
                return 1;
            }
        }
        return 0;
    }
}

$wpdb = new Compliance_Timing_Wpdb();

require dirname( __DIR__ ) . '/includes/class-estandar-leads-compliance.php';

$method = new ReflectionMethod( Estandar_Leads_Compliance::class, 'next_unsent_level' );

$cases = array(
    array( 47, null ),
    array( 48, Estandar_Leads_Compliance::LEVEL_48H ),
    array( 95, Estandar_Leads_Compliance::LEVEL_48H ),
    array( 96, Estandar_Leads_Compliance::LEVEL_96H ),
    array( 167, Estandar_Leads_Compliance::LEVEL_96H ),
    array( 168, Estandar_Leads_Compliance::LEVEL_7D ),
    array( 335, Estandar_Leads_Compliance::LEVEL_7D ),
    array( 336, Estandar_Leads_Compliance::LEVEL_14D ),
);

foreach ( $cases as $case ) {
    $actual = $method->invoke( null, 84, $case[0] );
    if ( $actual !== $case[1] ) {
        throw new RuntimeException( "Timing mismatch at {$case[0]}h: " . var_export( $actual, true ) );
    }
}

$wpdb->sent_levels = array( '14d' );
if ( $method->invoke( null, 84, 336 ) !== null ) {
    throw new RuntimeException( 'Weekly must exclude exactly 14 days.' );
}
if ( $method->invoke( null, 84, 337 ) !== Estandar_Leads_Compliance::LEVEL_WEEKLY ) {
    throw new RuntimeException( 'Weekly must start after 14 days.' );
}

$wpdb->sent_levels = array( '96h' );
if ( $method->invoke( null, 84, 120 ) !== null ) {
    throw new RuntimeException( 'Timing must not fall back from 96h to 48h.' );
}

$wpdb->sent_levels = array( '7d' );
if ( $method->invoke( null, 84, 200 ) !== null ) {
    throw new RuntimeException( 'Timing must not fall back from 7d to lower levels.' );
}

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-leads-compliance.php' );
if ( strpos( $source, "\$lead['_pending_level']" ) === false ) {
    throw new RuntimeException( 'Daily digest does not preserve each lead pending escalation level.' );
}

echo "PASS: COMPLIANCE_TIMING_V2\n";
