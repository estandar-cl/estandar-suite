<?php
/**
 * Utility helpers for Estandar Analytics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Utils {

	public static function table( $table ) {
		global $wpdb;
		return $wpdb->prefix . 'ea_' . $table;
	}

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function today() {
		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	public static function date_offset( $date, $days ) {
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? (string) $date : self::today();
		if ( function_exists( 'wp_timezone' ) ) {
			try {
				return ( new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() ) )
					->modify( (int) $days . ' days' )
					->format( 'Y-m-d' );
			} catch ( Exception $e ) {
				// Fallback below preserves the previous UTC behavior if timezone parsing fails.
			}
		}

		return gmdate( 'Y-m-d', strtotime( (int) $days . ' days', strtotime( $date ) ) );
	}

	public static function sanitize_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( strlen( $url ) > 2048 ) {
			$url = substr( $url, 0, 2048 );
		}
		return $url;
	}

	public static function sanitize_text( $value, $max = 255 ) {
		$value = sanitize_text_field( (string) $value );
		if ( strlen( $value ) > $max ) {
			$value = substr( $value, 0, $max );
		}
		return $value;
	}

	public static function extract_domain( $url ) {
		$url = (string) $url;
		if ( $url === '' ) {
			return '';
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || $host === '' ) {
			return '';
		}
		return strtolower( preg_replace( '/^www\./', '', $host ) );
	}

	public static function normalize_page( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || $path === '' ) {
			return '/';
		}
		return self::normalize_path( $path );
	}

	public static function normalize_report_url( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || $path === '' ) {
			return '/';
		}
		$path = '/' . trim( $path, '/' );
		return $path === '/' ? '/' : $path . '/';
	}

	/**
	 * Normalize a path fragment for exact comparisons.
	 *
	 * @param string $path Path or URL path segment.
	 * @return string
	 */
	public static function normalize_path( $path ) {
		$path = (string) $path;
		if ( $path === '' ) {
			return '/';
		}
		if ( strpos( $path, '/' ) === false && strpos( $path, 'http' ) === 0 ) {
			$path = wp_parse_url( $path, PHP_URL_PATH );
			$path = is_string( $path ) ? $path : '';
		}
		if ( $path !== '' && $path[0] !== '/' ) {
			$path = '/' . $path;
		}
		return untrailingslashit( $path ) ?: '/';
	}

	/**
	 * Exact thank-you path match (trailing slash normalized).
	 *
	 * @param string $page_url Full page URL.
	 * @param array  $paths    Configured path fragments.
	 * @return bool
	 */
	public static function matches_thank_you_path( $page_url, $paths ) {
		$current = self::normalize_page( $page_url );
		foreach ( (array) $paths as $path ) {
			if ( $path === '' ) {
				continue;
			}
			if ( $current === self::normalize_path( $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Inclusive date range as UTC datetime bounds (end exclusive).
	 *
	 * @param string $from_date Y-m-d start date.
	 * @param string $to_date   Y-m-d end date.
	 * @return array{0:string,1:string} Start inclusive, end exclusive.
	 */
	public static function sql_datetime_bounds( $from_date, $to_date ) {
		list( $from_date, $to_date ) = self::parse_date_range( $from_date, $to_date );
		if ( function_exists( 'wp_timezone' ) ) {
			try {
				$timezone = wp_timezone();
				$utc      = new DateTimeZone( 'UTC' );
				$start    = new DateTimeImmutable( $from_date . ' 00:00:00', $timezone );
				$end      = ( new DateTimeImmutable( $to_date . ' 00:00:00', $timezone ) )->modify( '+1 day' );

				return array(
					$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
					$end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				);
			} catch ( Exception $e ) {
				// Fallback below preserves the previous UTC behavior if timezone parsing fails.
			}
		}

		$end_exclusive = gmdate( 'Y-m-d', strtotime( $to_date . ' +1 day' ) ) . ' 00:00:00';
		return array( $from_date . ' 00:00:00', $end_exclusive );
	}

	public static function classify_source( $data ) {
		$source   = self::sanitize_text( $data['source'] ?? '', 100 );
		$medium   = self::sanitize_text( $data['medium'] ?? '', 100 );
		$campaign = self::sanitize_text( $data['campaign'] ?? '', 255 );
		$content  = self::sanitize_text( $data['content'] ?? '', 255 );
		$term     = self::sanitize_text( $data['term'] ?? '', 255 );
		$gad_source = self::sanitize_text( $data['gad_source'] ?? '', 100 );
		$gad_campaignid = self::sanitize_text( $data['gad_campaignid'] ?? '', 255 );
		$referrer = self::sanitize_url( $data['referrer'] ?? '' );
		$domain   = self::extract_domain( $referrer );
		$site     = self::extract_domain( home_url() );
		if ( $campaign === '' && $gad_campaignid !== '' ) {
			$campaign = $gad_campaignid;
		}

		$classification = apply_filters(
			'ea_source_classification',
			null,
			array(
				'source'          => $source,
				'medium'          => $medium,
				'campaign'        => $campaign,
				'content'         => $content,
				'term'            => $term,
				'gad_source'      => $gad_source,
				'gad_campaignid'  => $gad_campaignid,
				'referrer'        => $referrer,
				'referrer_domain' => $domain,
			)
		);

		if ( is_array( $classification ) ) {
			return $classification;
		}

		$organic_search = array( 'google', 'bing', 'yahoo', 'duckduckgo', 'yandex', 'baidu', 'ecosia' );
		$paid_search    = array( 'googleads', 'googleadservices', 'bingads' );
		$social         = array(
			'facebook'  => 'facebook',
			'fb.com'    => 'facebook',
			'instagram' => 'instagram',
			'linkedin'  => 'linkedin',
			'tiktok'    => 'tiktok',
			'twitter'   => 'twitter',
			'x.com'     => 'twitter',
			'pinterest' => 'pinterest',
			'youtube'   => 'youtube',
		);
		$email_domains  = array( 'mail.google.com', 'outlook.live.com', 'mail.yahoo.com', 'webmail', 'mail.' );

		if ( $gad_source !== '' || $gad_campaignid !== '' ) {
			return self::source_result( 'google', 'cpc', $campaign, $content, $term, $referrer, $domain, 'Paid Search' );
		}

		if ( $source !== '' || $medium !== '' ) {
			$medium_lower = strtolower( $medium );
			$source_lower = strtolower( $source );

			if ( in_array( $medium_lower, array( 'cpc', 'ppc', 'paidsearch', 'paid-search' ), true ) ) {
				return self::source_result( $source ?: 'paid-search', 'cpc', $campaign, $content, $term, $referrer, $domain, 'Paid Search' );
			}
			// Paid Social: variantes explícitas de medium, O medium='paid'/'cpc' con source de red social conocida.
			$social_sources = array( 'meta', 'facebook', 'fb', 'instagram', 'ig', 'tiktok', 'linkedin', 'twitter', 'pinterest', 'snapchat', 'youtube' );
			$is_paid_social  = in_array( $medium_lower, array( 'paid_social', 'paidsocial', 'paid-social', 'social_paid' ), true )
				|| ( in_array( $medium_lower, array( 'paid', 'cpc' ), true ) && in_array( $source_lower, $social_sources, true ) );
			// Meta Ads fallback: fbclid solo cuando Google/Bing no presentes
			$meta_fallback   = $fbclid !== '' && $gad_source === '' && $gbraid === '' && $wbraid === '';
			if ( $is_paid_social || $meta_fallback ) {
				return self::source_result( $source ?: 'paid-social', 'paid_social', $campaign, $content, $term, $referrer, $domain, 'Paid Social' );
			}
			// medium='paid' genérico sin source social → Paid Search como fallback seguro.
			if ( $medium_lower === 'paid' ) {
				return self::source_result( $source ?: 'paid', 'cpc', $campaign, $content, $term, $referrer, $domain, 'Paid Search' );
			}
			if ( in_array( $medium_lower, array( 'email', 'newsletter' ), true ) ) {
				return self::source_result( $source ?: 'email', 'email', $campaign, $content, $term, $referrer, $domain, 'Email' );
			}
			if ( in_array( $medium_lower, array( 'social', 'social-network', 'social-media' ), true ) ) {
				return self::source_result( $source ?: $domain, 'social', $campaign, $content, $term, $referrer, $domain, 'Organic Social' );
			}
			if ( in_array( $medium_lower, array( 'referral' ), true ) ) {
				return self::source_result( $source ?: $domain, 'referral', $campaign, $content, $term, $referrer, $domain, 'Referral' );
			}
			if ( in_array( $medium_lower, array( 'organic' ), true ) ) {
				return self::source_result( $source ?: $domain, 'organic', $campaign, $content, $term, $referrer, $domain, 'Organic Search' );
			}
			if ( in_array( $source_lower, $organic_search, true ) && $medium === '' ) {
				return self::source_result( $source_lower, 'organic', $campaign, $content, $term, $referrer, $domain, 'Organic Search' );
			}
			return self::source_result( $source ?: 'unknown', $medium ?: 'unknown', $campaign, $content, $term, $referrer, $domain, 'Unknown' );
		}

		if ( $referrer === '' || $domain === $site ) {
			return self::source_result( 'direct', 'none', '', '', '', '', '', 'Direct' );
		}

		foreach ( $email_domains as $needle ) {
			if ( strpos( $domain, $needle ) !== false ) {
				return self::source_result( 'email', 'email', '', '', '', $referrer, $domain, 'Email' );
			}
		}

		foreach ( $social as $needle => $label ) {
			if ( strpos( $domain, $needle ) !== false ) {
				return self::source_result( $label, 'social', '', '', '', $referrer, $domain, 'Organic Social' );
			}
		}

		foreach ( $paid_search as $needle ) {
			if ( strpos( $domain, $needle ) !== false ) {
				return self::source_result( 'google', 'cpc', '', '', '', $referrer, $domain, 'Paid Search' );
			}
		}

		foreach ( $organic_search as $search_engine ) {
			if ( strpos( $domain, $search_engine ) !== false ) {
				return self::source_result( $search_engine, 'organic', '', '', '', $referrer, $domain, 'Organic Search' );
			}
		}

		if ( $domain !== '' ) {
			return self::source_result( $domain, 'referral', '', '', '', $referrer, $domain, 'Referral' );
		}

		return self::source_result( 'unknown', 'unknown', '', '', '', $referrer, $domain, 'Unknown' );
	}

	private static function source_result( $source, $medium, $campaign, $content, $term, $referrer, $domain, $traffic_type ) {
		return array(
			'source'          => self::sanitize_text( $source, 100 ),
			'medium'          => self::sanitize_text( $medium, 100 ),
			'campaign'        => self::sanitize_text( $campaign, 255 ),
			'content'         => self::sanitize_text( $content, 255 ),
			'term'            => self::sanitize_text( $term, 255 ),
			'referrer'        => $referrer,
			'referrer_domain' => self::sanitize_text( $domain, 255 ),
			'traffic_type'    => self::sanitize_text( $traffic_type, 50 ),
		);
	}

	public static function classify_event( $event_type, $payload = array() ) {
		$type = self::sanitize_text( $event_type, 50 );
		return apply_filters(
			'ea_event_classification',
			array(
				'event_type'  => $type,
				'event_label' => self::sanitize_text( $payload['label'] ?? '', 255 ),
			),
			$payload
		);
	}

	public static function increment_aggregate( $date, $metric_key, $amount = 1, $dimension_key = '', $dimension_value = '' ) {
		global $wpdb;

		$table = self::table( 'daily_aggregates' );
		$date  = self::sanitize_text( $date, 10 );
		$key   = self::sanitize_text( $metric_key, 100 );
		$dim_k = self::sanitize_text( $dimension_key, 100 );
		$dim_v = self::sanitize_text( $dimension_value, 255 );

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE aggregate_date = %s AND metric_key = %s AND dimension_key = %s AND dimension_value = %s LIMIT 1",
				$date,
				$key,
				$dim_k,
				$dim_v
			)
		);

		if ( $existing ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET metric_value = metric_value + %d WHERE id = %d",
					(int) $amount,
					(int) $existing
				)
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'aggregate_date'  => $date,
				'metric_key'      => $key,
				'metric_value'    => (int) $amount,
				'dimension_key'   => $dim_k,
				'dimension_value' => $dim_v,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);
	}

	public static function get_client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			if ( strpos( $raw, ',' ) !== false ) {
				$parts = explode( ',', $raw );
				$raw   = trim( $parts[0] );
			}
			if ( filter_var( $raw, FILTER_VALIDATE_IP ) ) {
				return $raw;
			}
		}
		return '';
	}

	public static function parse_date_range( $from, $to ) {
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ? $from : self::date_offset( self::today(), -29 );
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ? $to : self::today();
		if ( strtotime( $from ) > strtotime( $to ) ) {
			$tmp  = $from;
			$from = $to;
			$to   = $tmp;
		}
		return array( $from, $to );
	}

	public static function admin_filters() {
		$from = isset( $_GET['ea_from'] ) ? sanitize_text_field( wp_unslash( $_GET['ea_from'] ) ) : '';
		$to   = isset( $_GET['ea_to'] ) ? sanitize_text_field( wp_unslash( $_GET['ea_to'] ) ) : '';
		list( $from, $to ) = self::parse_date_range( $from, $to );

		return array(
			'from'            => $from,
			'to'              => $to,
			'page'            => self::sanitize_text( $_GET['ea_page'] ?? '', 500 ),
			'campaign'        => self::sanitize_text( $_GET['ea_campaign'] ?? '', 255 ),
			'campaign_id'     => self::sanitize_text( $_GET['ea_campaign_id'] ?? '', 190 ),
			'adset_id'        => self::sanitize_text( $_GET['ea_adset_id'] ?? '', 190 ),
			'ad_id'           => self::sanitize_text( $_GET['ea_ad_id'] ?? '', 190 ),
			'source'          => self::sanitize_text( $_GET['ea_source'] ?? '', 100 ),
			'device'          => self::sanitize_text( $_GET['ea_device'] ?? '', 50 ),
			'conversion_type' => self::sanitize_text( $_GET['ea_conversion'] ?? '', 100 ),
			'project'         => self::sanitize_text( $_GET['ea_project'] ?? '', 190 ),
			'session_id'      => self::sanitize_text( $_GET['session_id'] ?? '', 64 ),
			'limit'           => in_array( (int) ( $_GET['ea_limit'] ?? 50 ), array( 20, 50 ), true ) ? (int) ( $_GET['ea_limit'] ?? 50 ) : 50,
		);
	}
}
