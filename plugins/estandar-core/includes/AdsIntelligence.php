<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdsIntelligence
{
	const OPTION = 'estandar_suite_ads_platforms';

	const PLATFORMS = array(
		'google_ads'      => 'Google Ads',
		'meta_ads'        => 'Meta Ads',
		'openai_ads'      => 'OpenAI Ads',
		'linkedin_ads'    => 'LinkedIn Ads',
		'tiktok_ads'      => 'TikTok Ads',
		'microsoft_ads'   => 'Microsoft Ads',
	);

	const JOURNEY_TO_CONVERSION = array(
		'lead_captured'   => 'Lead',
		'lead_qualified'  => 'Qualified Lead',
		'meeting_scheduled' => 'Schedule',
		'deal_won'        => 'Purchase',
	);

	public static function platforms(): array
	{
		$configured = get_option( self::OPTION, array() );
		$configured = is_array( $configured ) ? $configured : array();
		$result = array();
		foreach ( self::PLATFORMS as $key => $label ) {
			$result[ $key ] = array(
				'key'     => $key,
				'label'   => $label,
				'enabled' => ! empty( $configured[ $key ]['enabled'] ),
				'config'  => $configured[ $key ] ?? array(),
			);
		}
		return $result;
	}

	public static function configure( string $platform, array $config ): void
	{
		$platforms = get_option( self::OPTION, array() );
		$platforms = is_array( $platforms ) ? $platforms : array();
		$platforms[ $platform ] = $config;
		update_option( self::OPTION, $platforms, false );
	}

	public static function should_send( string $platform, string $event ): bool
	{
		$cfg = self::platforms()[ $platform ] ?? null;
		if ( ! $cfg || empty( $cfg['enabled'] ) ) {
			return false;
		}
		$events = (array) ( $cfg['config']['events'] ?? array_keys( self::JOURNEY_TO_CONVERSION ) );
		return in_array( $event, $events, true );
	}

	public static function build_conversion_payload( string $platform, array $lead_data, string $event ): array
	{
		$conversion_type = self::JOURNEY_TO_CONVERSION[ $event ] ?? 'Lead';

		return array(
			'platform'         => $platform,
			'conversion_type'  => $conversion_type,
			'event'            => $event,
			'lead_id'          => $lead_data['id'] ?? 0,
			'gclid'            => $lead_data['gclid'] ?? '',
			'fbclid'           => $lead_data['fbclid'] ?? '',
			'revenue'          => $lead_data['revenue'] ?? 0,
			'currency'         => $lead_data['currency'] ?? 'CLP',
			'timestamp'        => gmdate( 'Y-m-d H:i:s' ),
			'idempotency_key'  => hash( 'sha256', ( $lead_data['id'] ?? '0' ) . '-' . $event . '-' . gmdate( 'Ymd' ) ),
		);
	}

	public static function on_journey_event( int $pipeline_id, string $from, string $to, string $reason ): void
	{
		$event = 'lead_captured';
		if ( $to === 'QUALIFIED' )     $event = 'lead_qualified';
		if ( $to === 'MEETING' )      $event = 'meeting_scheduled';
		if ( $to === 'CLOSED_WON' )   $event = 'deal_won';

		if ( ! class_exists( 'Estandar\\Flow\\Pipeline' ) ) {
			return;
		}

		$pipeline_row = \Estandar\Flow\Pipeline::get( $pipeline_id );
		if ( ! $pipeline_row ) {
			return;
		}
		$lead_data = array(
			'id'       => $pipeline_row['origin_lead_id'] ?? $pipeline_id,
			'revenue'  => $pipeline_row['amount'] ?? 0,
			'currency' => $pipeline_row['currency'] ?? 'CLP',
		);

		foreach ( self::platforms() as $platform => $cfg ) {
			if ( self::should_send( $platform, $event ) ) {
				$payload = self::build_conversion_payload( $platform, $lead_data, $event );
				do_action( 'estandar_suite_ad_conversion_send', $platform, $payload );
				do_action( 'estandar_advertising_event_ready', $platform, $payload );
			}
		}
	}
}
