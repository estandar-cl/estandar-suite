<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function build_lead_attribution_audit( $lead ): array {
	$row = is_object( $lead ) ? get_object_vars( $lead ) : ( is_array( $lead ) ? $lead : array() );
	$get = static function ( $key ) use ( $row ) {
		foreach ( array( $key, 'first_' . $key ) as $candidate ) {
			if ( array_key_exists( $candidate, $row ) && is_scalar( $row[ $candidate ] ) ) {
				$value = trim( (string) $row[ $candidate ] );
				if ( $value !== '' ) {
					return $value;
				}
			}
		}
		return null;
	};
	$parse_url = static function ( $url ) {
		$query = is_string( $url ) ? (string) ( parse_url( $url, PHP_URL_QUERY ) ?? '' ) : '';
		if ( $query === '' ) {
			return array();
		}
		parse_str( $query, $parsed );
		return is_array( $parsed ) ? $parsed : array();
	};
	$url_qs = $parse_url( $get( 'landing_url' ) );
	$from_qs = static function ( $key ) use ( $url_qs ) {
		return isset( $url_qs[ $key ] ) && is_scalar( $url_qs[ $key ] ) && trim( (string) $url_qs[ $key ] ) !== ''
			? trim( (string) $url_qs[ $key ] )
			: null;
	};
	$pick = static function ( $key ) use ( $get, $from_qs ) {
		return $get( $key ) ?? $from_qs( $key );
	};

	$gclid  = $pick( 'gclid' );
	$gbraid = $pick( 'gbraid' );
	$wbraid = $pick( 'wbraid' );
	$fbclid = $pick( 'fbclid' );
	$has_click = $gclid !== null || $gbraid !== null || $wbraid !== null || $fbclid !== null;
	$source_detected = Estandar_Leads_Attribution::channel_label( $row );

	$oci_status = strtolower( trim( (string) ( $row['oci_status'] ?? $row['queue_status'] ?? '' ) ) );
	$oci_eligible = $source_detected === 'Google Ads' && ( $gclid !== null || $gbraid !== null || $wbraid !== null );
	$oci_rejected = in_array( $oci_status, array( 'failed', 'dead', 'rejected' ), true );
	$oci_sent = in_array( $oci_status, array( 'sent', 'completed', 'accepted' ), true );

	if ( $source_detected === 'Google Ads' && ! $oci_eligible ) {
		$diagnosis = array( 'status' => 'missing_click_identifier', 'severity' => 'high', 'message' => 'Google Ads lead without click identifier.' );
	} elseif ( $oci_rejected ) {
		$diagnosis = array( 'status' => 'oci_rejected', 'severity' => 'medium', 'message' => (string) ( $row['oci_error_message'] ?? $row['last_error'] ?? 'OCI rejected or failed.' ) );
	} elseif ( $oci_sent ) {
		$diagnosis = array( 'status' => 'ok', 'severity' => 'none', 'message' => 'Attribution and OCI accepted.' );
	} else {
		$diagnosis = array( 'status' => 'unknown', 'severity' => 'low', 'message' => 'No OCI terminal status available.' );
	}

	return array(
		'source_detected' => $source_detected,
		'has_click_identifier' => $has_click,
		'gclid' => $gclid,
		'gbraid' => $gbraid,
		'wbraid' => $wbraid,
		'fbclid' => $fbclid,
		'utm_source' => $pick( 'utm_source' ),
		'utm_medium' => $pick( 'utm_medium' ),
		'utm_campaign' => $pick( 'utm_campaign' ),
		'landing_url' => $get( 'landing_url' ),
		'referrer' => $get( 'referrer' ),
		'oci' => array(
			'eligible' => $oci_eligible,
			'queued' => $oci_status !== '',
			'sent' => $oci_sent,
			'accepted' => in_array( $oci_status, array( 'sent', 'completed', 'accepted' ), true ),
			'rejected' => $oci_rejected,
			'error_code' => $row['oci_error_code'] ?? null,
			'error_message' => $row['oci_error_message'] ?? $row['last_error'] ?? null,
			'conversion_action' => $row['oci_conversion_action'] ?? $row['export_status'] ?? null,
		),
		'diagnosis' => $diagnosis,
	);
}

function build_leads_attribution_health_summary( array $leads ): array {
	$summary = array(
		'total_leads' => 0,
		'google_ads_leads' => 0,
		'meta_ads_leads' => 0,
		'organic_or_unknown_leads' => 0,
		'leads_with_click_identifier' => 0,
		'leads_without_click_identifier' => 0,
		'google_ads_with_click_identifier' => 0,
		'google_ads_without_click_identifier' => 0,
		'oci_eligible' => 0,
		'oci_not_eligible' => 0,
		'oci_queued' => 0,
		'oci_sent' => 0,
		'oci_accepted' => 0,
		'oci_rejected' => 0,
		'oci_unknown' => 0,
		'rejection_rate' => 0,
		'accepted_rate' => 0,
		'click_identifier_coverage_rate' => 0,
	);

	foreach ( $leads as $lead ) {
		$a = build_lead_attribution_audit( $lead );
		$summary['total_leads']++;

		$source = (string) ( $a['source_detected'] ?? 'Unknown' );
		$has_click = ! empty( $a['has_click_identifier'] );
		$oci = is_array( $a['oci'] ?? null ) ? $a['oci'] : array();

		if ( $source === 'Google Ads' ) {
			$summary['google_ads_leads']++;
			$summary[ $has_click ? 'google_ads_with_click_identifier' : 'google_ads_without_click_identifier' ]++;
		} elseif ( $source === 'Meta Ads' ) {
			$summary['meta_ads_leads']++;
		} else {
			$summary['organic_or_unknown_leads']++;
		}

		$summary[ $has_click ? 'leads_with_click_identifier' : 'leads_without_click_identifier' ]++;
		$summary[ ! empty( $oci['eligible'] ) ? 'oci_eligible' : 'oci_not_eligible' ]++;

		if ( ! empty( $oci['queued'] ) ) {
			$summary['oci_queued']++;
		}
		if ( ! empty( $oci['sent'] ) ) {
			$summary['oci_sent']++;
		}
		if ( ! empty( $oci['accepted'] ) ) {
			$summary['oci_accepted']++;
		} elseif ( ! empty( $oci['rejected'] ) ) {
			$summary['oci_rejected']++;
		} else {
			$summary['oci_unknown']++;
		}
	}

	$total = $summary['total_leads'];
	$terminal = $summary['oci_accepted'] + $summary['oci_rejected'];
	$summary['click_identifier_coverage_rate'] = $total > 0 ? round( $summary['leads_with_click_identifier'] * 100 / $total, 2 ) : 0;
	$summary['accepted_rate'] = $terminal > 0 ? round( $summary['oci_accepted'] * 100 / $terminal, 2 ) : 0;
	$summary['rejection_rate'] = $terminal > 0 ? round( $summary['oci_rejected'] * 100 / $terminal, 2 ) : 0;

	return $summary;
}
