<?php
/**
 * Privacy, bot filtering, and visitor hashing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Privacy {

	public static function should_track() {
		$settings = EA_Settings::get();

		if ( empty( $settings['enable_tracking'] ) ) {
			return false;
		}

		if ( ! empty( $settings['exclude_admin'] ) && current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! empty( $settings['ignore_logged_in_roles'] ) && is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$roles = (array) $user->roles;
			$ignore = (array) ( $settings['ignored_roles'] ?? array( 'administrator', 'editor' ) );
			if ( array_intersect( $roles, $ignore ) ) {
				return false;
			}
		}

		if ( ! empty( $settings['bot_filtering'] ) && self::is_bot() ) {
			return false;
		}

		return true;
	}

	public static function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( $ua === '' ) {
			return true;
		}

		$patterns = array(
			'bot', 'spider', 'crawl', 'slurp', 'curl', 'wget', 'python-requests',
			'headless', 'phantom', 'selenium', 'lighthouse', 'pingdom', 'monitor',
			'facebookexternalhit', 'telegrambot', 'google-inspectiontool',
		);

		foreach ( $patterns as $pattern ) {
			if ( strpos( $ua, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	public static function anonymize_ip( $ip ) {
		$settings = EA_Settings::get();
		if ( empty( $settings['anonymize_ip'] ) || $ip === '' ) {
			return $ip;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			$parts[3] = '0';
			return implode( '.', $parts );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$parts = explode( ':', $ip );
			for ( $i = 4; $i < count( $parts ); $i++ ) {
				$parts[ $i ] = '0';
			}
			return implode( ':', $parts );
		}

		return '';
	}

	public static function visitor_hash( $ip, $ua ) {
		$settings = EA_Settings::get();
		$ip       = self::anonymize_ip( $ip );
		$ua       = substr( sanitize_text_field( (string) $ua ), 0, 200 );
		$salt     = (string) ( $settings['hash_salt'] ?? wp_salt( 'auth' ) );
		return hash( 'sha256', $salt . '|' . $ip . '|' . $ua );
	}

	public static function sanitize_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$clean = array();
		$allowed_top = array(
			'session_id', 'events', 'pageview', 'session', 'device',
			'utm', 'referrer', 'landing_page', 'page_url', 'page_title',
			'campaign_id', 'adset_id', 'ad_id',
			'duration', 'page_count', 'is_bounce', 'first_project_slug', 'first_project_page',
		);

		foreach ( $allowed_top as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$clean[ $key ] = $payload[ $key ];
			}
		}

		return apply_filters( 'ea_payload_before_insert', $clean, $payload );
	}
}
