<?php
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-estandar-leads-attribution.php';
require dirname( __DIR__ ) . '/includes/lead-attribution-audit.php';

$fail = 0;
$check = static function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$fail += $ok ? 0 : 1;
};

$empty = build_leads_attribution_health_summary( array() );
$check( 'arreglo vacio', $empty['total_leads'] === 0 && $empty['accepted_rate'] === 0 && $empty['click_identifier_coverage_rate'] === 0 );

$summary = build_leads_attribution_health_summary( array(
	array( 'utm_source' => 'google', 'utm_medium' => 'cpc', 'gclid' => 'g1', 'oci_status' => 'completed' ),
	array( 'utm_source' => 'google', 'utm_medium' => 'paid', 'gbraid' => 'gb1', 'oci_status' => 'failed' ),
	array( 'utm_source' => 'google', 'utm_medium' => 'ppc', 'wbraid' => 'wb1' ),
	array( 'utm_source' => 'google', 'utm_medium' => 'cpc' ),
	array( 'utm_source' => 'meta', 'utm_medium' => 'paid', 'fbclid' => 'fb1' ),
	array( 'utm_source' => 'google', 'utm_medium' => 'organic' ),
	array( 'landing_url' => 'https://example.test/?gclid=url-gclid', 'oci_status' => 'sent' ),
	array( 'name' => 'Partial' ),
) );

$check( 'mezcla fuentes', $summary['google_ads_leads'] === 5 && $summary['meta_ads_leads'] === 1 && $summary['organic_or_unknown_leads'] === 2 );
$check( 'Google Ads con/sin click identifier', $summary['google_ads_with_click_identifier'] === 4 && $summary['google_ads_without_click_identifier'] === 1 );
$check( 'OCI estados', $summary['oci_accepted'] === 2 && $summary['oci_rejected'] === 1 && $summary['oci_unknown'] === 5 );
$check( 'datos parciales sin fatal', $summary['total_leads'] === 8 && $summary['leads_without_click_identifier'] === 3 );
$check( 'rates', $summary['click_identifier_coverage_rate'] === 62.5 && $summary['accepted_rate'] === 66.67 && $summary['rejection_rate'] === 33.33 );

echo 'Ejemplo agregado: Leads totales 8; Google Ads detectados 5; Google Ads con click identifier 4; Google Ads sin click identifier 1; OCI elegibles 4; OCI enviados 2; OCI aceptados 2; OCI rechazados 1; % cobertura click identifier 62.50%; % aceptación OCI 66.67%.' . "\n";
exit( $fail === 0 ? 0 : 1 );
