<?php
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/class-estandar-leads-attribution.php';

$cases = array(
	'google + organic'          => array( array( 'utm_source' => 'google', 'utm_medium' => 'organic' ), 'google_organic' ),
	'google organic + fbp'      => array( array( 'utm_source' => 'google', 'utm_medium' => 'organic', 'meta_fbp' => 'fb.1.browser' ), 'google_organic' ),
	'google + cpc'              => array( array( 'utm_source' => 'google', 'utm_medium' => 'cpc' ), 'google_ads' ),
	'google + paid'             => array( array( 'utm_source' => 'google', 'utm_medium' => 'paid' ), 'google_ads' ),
	'google + gclid'            => array( array( 'utm_source' => 'google', 'utm_medium' => 'organic', 'gclid' => 'g1' ), 'google_ads' ),
	'google + gbraid'           => array( array( 'utm_source' => 'google', 'utm_medium' => 'organic', 'gbraid' => 'gb1' ), 'google_ads' ),
	'google referrer only'      => array( array( 'referrer' => 'https://www.google.com/search?q=estandar' ), 'google_organic' ),
	'organic without campaign'  => array( array( 'utm_source' => 'newsletter', 'utm_medium' => 'organic' ), 'organic' ),
	'existing Meta traffic'     => array( array( 'utm_source' => 'meta', 'utm_medium' => 'paid', 'fbclid' => 'fb1' ), 'meta_ads' ),
	'existing non-Google paid'  => array( array( 'utm_source' => 'linkedin', 'utm_medium' => 'paid' ), 'unknown' ),
	'source empty not organic'  => array( array(), 'unknown' ),
	'existing direct traffic'   => array( array( 'utm_source' => 'direct' ), 'direct' ),
	'valid direct session'      => array( array( 'session_id' => 'session-1' ), 'direct' ),
	'form web fallback'         => array( array( 'source_plugin' => 'estandar-forms' ), 'form_web' ),
	'existing unknown traffic'  => array( array( 'utm_source' => 'newsletter', 'utm_medium' => 'email' ), 'unknown' ),
);

$fail = 0;
foreach ( $cases as $label => $case ) {
	$actual = Estandar_Leads_Attribution::resolve_channel( $case[0] );
	$ok = $actual === $case[1];
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok ? '' : " expected={$case[1]} actual={$actual}" ) . "\n";
	$fail += $ok ? 0 : 1;
}

exit( $fail === 0 ? 0 : 1 );
