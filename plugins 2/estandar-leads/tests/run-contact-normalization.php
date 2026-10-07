<?php

define( 'ABSPATH', __DIR__ );

function sanitize_email( $value ) {
    return trim( (string) $value );
}

require dirname( __DIR__ ) . '/includes/class-estandar-leads-normalizer.php';

$cases = array(
    '+56912345678' => '56912345678',
    '912345678'    => '56912345678',
    '9 1234 5678' => '56912345678',
    '0056912345678' => '56912345678',
);

foreach ( $cases as $input => $storage ) {
    if ( Estandar_Leads_Normalizer::phone_storage( $input ) !== $storage ) {
        throw new RuntimeException( 'Storage normalization failed for ' . $input );
    }
    if ( Estandar_Leads_Normalizer::phone_display( $input ) !== '+56 9 1234 5678' ) {
        throw new RuntimeException( 'Display normalization failed for ' . $input );
    }
}

echo "PASS: COMPLIANCE_CONTACT_NORMALIZATION\n";
