<?php
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-estandar-leads-attribution.php';
require dirname( __DIR__ ) . '/includes/lead-attribution-audit.php';

$fail = 0;
$check = static function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$fail += $ok ? 0 : 1;
};

$a = build_lead_attribution_audit( array( 'utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => 'g1', 'oci_status' => 'completed', 'export_status' => 'purchase' ) );
$check( 'Google Ads + gclid + OCI accepted', $a['source_detected'] === 'Google Ads' && $a['has_click_identifier'] && $a['oci']['accepted'] );

$a = build_lead_attribution_audit( array( 'utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => 'g1', 'oci_status' => 'failed', 'oci_error_message' => 'bad click' ) );
$check( 'Google Ads + gclid + OCI rejected', $a['oci']['rejected'] && $a['diagnosis']['status'] === 'oci_rejected' );

$a = build_lead_attribution_audit( array( 'utm_source' => 'google', 'utm_medium' => 'cpc' ) );
$check( 'Google Ads sin click identifier', $a['source_detected'] === 'Google Ads' && ! $a['has_click_identifier'] && $a['diagnosis']['status'] === 'missing_click_identifier' );

$a = build_lead_attribution_audit( array( 'utm_source' => 'meta', 'utm_medium' => 'paid', 'fbclid' => 'fb1' ) );
$check( 'Meta + fbclid', $a['source_detected'] === 'Meta Ads' && $a['fbclid'] === 'fb1' );

$a = build_lead_attribution_audit( array( 'utm_source' => 'google', 'utm_medium' => 'organic' ) );
$check( 'Google orgánico sin paid identifiers', $a['source_detected'] === 'Google Organic' && ! $a['has_click_identifier'] );

$a = build_lead_attribution_audit( array( 'landing_url' => 'https://example.test/?gclid=url-gclid' ) );
$check( 'datos parciales', $a['gclid'] === 'url-gclid' && $a['utm_campaign'] === null && $a['oci']['error_code'] === null );

echo 'Ejemplo: Origen detectado Google Ads; Click identifier presente Sí; Identificadores encontrados gclid=g1; Elegibilidad OCI Sí; Estado OCI accepted.' . "\n";
exit( $fail === 0 ? 0 : 1 );
