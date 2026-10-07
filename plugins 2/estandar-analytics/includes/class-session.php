<?php
/**
 * Session persistence and updates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Session {

	public static function upsert( $payload ) {
		global $wpdb;

		$table      = EA_Utils::table( 'sessions' );
		$session_id = EA_Utils::sanitize_text( $payload['session_id'] ?? '', 64 );
		if ( $session_id === '' ) {
			return false;
		}

		// Schema migration: project_attribution_slug (2026-08-05).
		// Permite rastrear el último proyecto navegado por sesión
		// y evita contaminación cross-project en merge_analytics().
		$col_check = $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'project_attribution_slug'" );
		if ( ! $col_check ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN project_attribution_slug varchar(190) NOT NULL DEFAULT '' AFTER first_project_page" );
		}

		$ip   = EA_Privacy::anonymize_ip( EA_Utils::get_client_ip() );
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$hash = EA_Privacy::visitor_hash( $ip, $ua );

		$utm_data = is_array( $payload['utm'] ?? null ) ? $payload['utm'] : array();
		$gclid    = EA_Utils::sanitize_text( $utm_data['gclid']   ?? ( $payload['gclid']   ?? '' ), 255 );
		$gbraid   = EA_Utils::sanitize_text( $utm_data['gbraid']  ?? ( $payload['gbraid']  ?? '' ), 255 );
		$wbraid   = EA_Utils::sanitize_text( $utm_data['wbraid']  ?? ( $payload['wbraid']  ?? '' ), 255 );
		$fbclid   = EA_Utils::sanitize_text( $utm_data['fbclid']  ?? ( $payload['fbclid']  ?? '' ), 255 );
		$meta_fbp = EA_Utils::sanitize_text( $payload['meta_fbp'] ?? '', 255 );
		$meta_fbc = EA_Utils::sanitize_text( $payload['meta_fbc'] ?? '', 255 );
		$ttclid   = EA_Utils::sanitize_text( $utm_data['ttclid']  ?? ( $payload['ttclid']  ?? '' ), 255 );
		$msclkid  = EA_Utils::sanitize_text( $utm_data['msclkid'] ?? ( $payload['msclkid'] ?? '' ), 255 );
		$campaign_id = EA_Utils::sanitize_text( $utm_data['campaign_id'] ?? ( $utm_data['gad_campaignid'] ?? ( $payload['campaign_id'] ?? '' ) ), 190 );
		$adset_id    = EA_Utils::sanitize_text( $utm_data['adset_id']    ?? ( $payload['adset_id']    ?? '' ), 190 );
		$ad_id       = EA_Utils::sanitize_text( $utm_data['ad_id']       ?? ( $payload['ad_id']       ?? '' ), 190 );
		$adgroup_id     = EA_Utils::sanitize_text( $utm_data['adgroup_id']     ?? '', 190 );
		$asset_group_id = EA_Utils::sanitize_text( $utm_data['asset_group_id'] ?? '', 190 );
		$creative_id    = EA_Utils::sanitize_text( $utm_data['creative_id']    ?? '', 190 );
		$keyword        = EA_Utils::sanitize_text( $utm_data['keyword']        ?? '', 255 );
		$match_type     = EA_Utils::sanitize_text( $utm_data['match_type']     ?? '', 32 );
		$network        = EA_Utils::sanitize_text( $utm_data['network']        ?? '', 32 );
		$google_device  = EA_Utils::sanitize_text( $utm_data['device']         ?? '', 32 );
		$placement      = EA_Utils::sanitize_text( $utm_data['placement']      ?? '', 255 );
		$campaign_type  = EA_Utils::sanitize_text( $utm_data['campaign_type']  ?? '', 64 );
		$source   = EA_Utils::classify_source(
			array(
				'source'   => $utm_data['source'] ?? ( $payload['source'] ?? '' ),
				'medium'   => $utm_data['medium'] ?? ( $payload['medium'] ?? '' ),
				'campaign' => $utm_data['campaign'] ?? ( $payload['campaign'] ?? '' ),
				'content'  => $utm_data['content'] ?? ( $payload['content'] ?? '' ),
				'term'     => $utm_data['term'] ?? ( $payload['term'] ?? '' ),
				'gad_source' => $utm_data['gad_source'] ?? ( $payload['gad_source'] ?? '' ),
				'gad_campaignid' => $utm_data['gad_campaignid'] ?? ( $payload['gad_campaignid'] ?? '' ),
				'referrer' => $payload['referrer'] ?? '',
			)
		);

		$landing = EA_Utils::sanitize_url( $payload['landing_page'] ?? ( $payload['page_url'] ?? '' ) );
		$exit    = EA_Utils::sanitize_url( $payload['page_url'] ?? '' );
		$first_project_slug = sanitize_title( remove_accents( (string) ( $payload['first_project_slug'] ?? '' ) ) );
		$first_project_page = EA_Utils::sanitize_url( $payload['first_project_page'] ?? '' );

		// Server-side resolution always takes precedence over any client-provided value.
		// tracker.js does not send first_project_slug; this is the canonical write path.
		if ( class_exists( 'EA_Attribution' ) ) {
			$resolved_slug = EA_Attribution::resolve_project_slug( $landing );
			if ( $resolved_slug !== '' ) {
				$first_project_slug = $resolved_slug;
				$first_project_page = $landing;
			}
		}

		$now     = EA_Utils::now();
		$returning = self::is_returning_visitor( $hash );

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s LIMIT 1", $session_id ),
			ARRAY_A
		);

		$duration   = max( 0, (int) ( $payload['duration'] ?? 0 ) );
		$page_count = max( 0, (int) ( $payload['page_count'] ?? 0 ) );
		$is_bounce  = ! empty( $payload['is_bounce'] ) ? 1 : ( $page_count <= 1 ? 1 : 0 );

		if ( $existing ) {
			$duration = max( (int) $existing['duration'], $duration );
			if ( $exit === '' ) {
				$exit = (string) $existing['exit_page'];
			}
			if ( ! $returning ) {
				$returning = (int) $existing['is_returning'];
			}

			$update = array(
				'visitor_hash'  => $hash,
				'last_activity' => $now,
				'duration'      => $duration,
				'exit_page'     => $exit,
				'is_returning'  => $returning,
			);
			$formats = array( '%s', '%s', '%d', '%s', '%d' );
			if ( empty( $existing['first_project_slug'] ) && $first_project_slug !== '' ) {
				$update['first_project_slug'] = $first_project_slug;
				$formats[] = '%s';
			}
			// project_attribution_slug: actualiza en CADA pageview con el proyecto actual.
			// A diferencia de first_project_slug (write-once), este campo refleja
			// el último proyecto navegado y se usa en merge_analytics() para evitar
			// contaminación cross-project de atribución.
			if ( $first_project_slug !== '' ) {
				$update['project_attribution_slug'] = $first_project_slug;
				$formats[] = '%s';
			}
			if ( empty( $existing['first_project_page'] ) && $first_project_page !== '' ) {
				$update['first_project_page'] = $first_project_page;
				$formats[] = '%s';
			}
			$existing_medium = strtolower( (string) ( $existing['medium'] ?? '' ) );
			$incoming_medium = strtolower( (string) ( $source['medium'] ?? '' ) );
			$existing_type   = strtolower( (string) ( $existing['traffic_type'] ?? '' ) );
			$incoming_type   = strtolower( (string) ( $source['traffic_type'] ?? '' ) );
			$can_upgrade_attribution = in_array( $existing_medium, array( '', 'none', 'unknown' ), true )
				&& in_array( $existing_type, array( '', 'direct', 'unknown' ), true )
				&& in_array( $incoming_medium, array( 'cpc', 'paid_social' ), true )
				&& in_array( $incoming_type, array( 'paid search', 'paid social' ), true );
			if ( $can_upgrade_attribution ) {
				foreach ( array( 'source', 'medium', 'campaign', 'content', 'term', 'referrer', 'referrer_domain', 'traffic_type' ) as $field ) {
					$update[ $field ] = (string) ( $source[ $field ] ?? '' );
					$formats[] = '%s';
				}
				if ( $gclid !== '' && empty( $existing['gclid'] ) ) {
					$update['gclid'] = $gclid;
					$formats[] = '%s';
				}
				if ( $gbraid !== '' && empty( $existing['gbraid'] ) ) {
					$update['gbraid'] = $gbraid;
					$formats[] = '%s';
				}
				if ( $wbraid !== '' && empty( $existing['wbraid'] ) ) {
					$update['wbraid'] = $wbraid;
					$formats[] = '%s';
				}
				if ( $fbclid !== '' && empty( $existing['fbclid'] ) ) {
					$update['fbclid'] = $fbclid;
					$formats[] = '%s';
				}
				if ( $meta_fbp !== '' && empty( $existing['meta_fbp'] ) ) {
					$update['meta_fbp'] = $meta_fbp;
					$formats[] = '%s';
				}
				if ( $meta_fbc !== '' && empty( $existing['meta_fbc'] ) ) {
					$update['meta_fbc'] = $meta_fbc;
					$formats[] = '%s';
				}
				if ( $ttclid !== '' && empty( $existing['ttclid'] ) ) {
					$update['ttclid'] = $ttclid;
					$formats[] = '%s';
				}
				if ( $msclkid !== '' && empty( $existing['msclkid'] ) ) {
					$update['msclkid'] = $msclkid;
					$formats[] = '%s';
				}
			}
			foreach ( array( 'campaign_id' => $campaign_id, 'adset_id' => $adset_id, 'ad_id' => $ad_id ) as $field => $value ) {
				if ( $value !== '' && empty( $existing[ $field ] ) ) {
					$update[ $field ] = $value;
					$formats[] = '%s';
				}
			}
			foreach ( array( 'adgroup_id' => $adgroup_id, 'asset_group_id' => $asset_group_id, 'creative_id' => $creative_id, 'keyword' => $keyword, 'match_type' => $match_type, 'network' => $network, 'google_device' => $google_device, 'placement' => $placement, 'campaign_type' => $campaign_type ) as $field => $value ) {
				if ( $value !== '' && empty( $existing[ $field ] ) ) {
					$update[ $field ] = $value;
					$formats[] = '%s';
				}
			}

			$wpdb->update(
				$table,
				$update,
				array( 'session_id' => $session_id ),
				$formats,
				array( '%s' )
			);

			self::update_aggregates( $existing['first_seen'], false );
			return $session_id;
		}

		$has_pageview  = ! empty( $payload['pageview'] ) && is_array( $payload['pageview'] );
		$initial_pages = $has_pageview ? 0 : max( 1, $page_count );

		$wpdb->insert(
			$table,
			array(
				'session_id'      => $session_id,
				'visitor_hash'    => $hash,
				'first_seen'      => $now,
				'last_activity'   => $now,
				'duration'        => $duration,
				'page_count'      => $initial_pages,
				'is_bounce'       => 1,
				'landing_page'    => $landing,
				'exit_page'       => $exit,
				'is_returning'    => $returning,
				'source'          => $source['source'],
				'medium'          => $source['medium'],
				'campaign'        => $source['campaign'],
				'content'         => $source['content'],
				'term'            => $source['term'],
				'campaign_id'     => $campaign_id,
				'adset_id'        => $adset_id,
				'ad_id'           => $ad_id,
				'adgroup_id'      => $adgroup_id,
				'asset_group_id'  => $asset_group_id,
				'creative_id'     => $creative_id,
				'keyword'         => $keyword,
				'match_type'      => $match_type,
				'network'         => $network,
				'google_device'   => $google_device,
				'placement'       => $placement,
				'campaign_type'   => $campaign_type,
				'gclid'           => $gclid,
				'gbraid'          => $gbraid,
				'wbraid'          => $wbraid,
				'fbclid'          => $fbclid,
				'meta_fbp'        => $meta_fbp,
				'meta_fbc'        => $meta_fbc,
				'ttclid'          => $ttclid,
				'msclkid'         => $msclkid,
				'referrer'        => $source['referrer'],
				'referrer_domain' => $source['referrer_domain'],
				'traffic_type'    => $source['traffic_type'],
				'first_project_slug' => $first_project_slug,
				'first_project_page' => $first_project_page,
				'project_attribution_slug' => $first_project_slug,
			),
			array(
				'%s', '%s', '%s', '%s',
				'%d', '%d', '%d',
				'%s', '%s', '%d',
				'%s', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s',
				'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
				'%s', '%s', '%s', '%s', '%s', '%s',
			)
		);

		self::store_referrer( $session_id, $source );
		self::update_aggregates( $now, true, $source, $returning );

		return $session_id;
	}

	public static function is_returning_visitor( $hash ) {
		if ( $hash === '' ) {
			return 0;
		}
		global $wpdb;
		$table = EA_Utils::table( 'sessions' );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE visitor_hash = %s AND first_seen < %s LIMIT 1",
				$hash,
				EA_Utils::now()
			)
		);
		return $found ? 1 : 0;
	}

	public static function store_device( $session_id, $device ) {
		if ( ! is_array( $device ) || $session_id === '' ) {
			return;
		}
		global $wpdb;
		$table = EA_Utils::table( 'devices' );
		$data  = array(
			'session_id'  => EA_Utils::sanitize_text( $session_id, 64 ),
			'browser'     => EA_Utils::sanitize_text( $device['browser'] ?? '', 100 ),
			'os'          => EA_Utils::sanitize_text( $device['os'] ?? '', 100 ),
			'device_type' => EA_Utils::sanitize_text( $device['device_type'] ?? '', 50 ),
			'viewport'    => EA_Utils::sanitize_text( $device['viewport'] ?? '', 20 ),
			'language'    => EA_Utils::sanitize_text( $device['language'] ?? '', 20 ),
			'timezone'    => EA_Utils::sanitize_text( $device['timezone'] ?? '', 50 ),
		);

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE session_id = %s LIMIT 1", $data['session_id'] )
		);

		if ( $exists ) {
			$wpdb->update( $table, $data, array( 'session_id' => $data['session_id'] ) );
			return;
		}

		$wpdb->insert( $table, $data );
	}

	private static function store_referrer( $session_id, $source ) {
		global $wpdb;
		$table = EA_Utils::table( 'referrers' );
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE session_id = %s LIMIT 1", $session_id )
		);
		if ( $exists ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'session_id'      => $session_id,
				'referrer_url'    => $source['referrer'],
				'referrer_domain' => $source['referrer_domain'],
				'source'          => $source['source'],
				'medium'          => $source['medium'],
				'campaign'        => $source['campaign'],
				'content'         => $source['content'],
				'term'            => $source['term'],
				'traffic_type'    => $source['traffic_type'],
				'recorded_at'     => EA_Utils::now(),
			)
		);
	}

	private static function update_aggregates( $first_seen, $is_new, $source = array(), $returning = 0 ) {
		$date = gmdate( 'Y-m-d', strtotime( $first_seen ) );
		if ( $is_new ) {
			EA_Utils::increment_aggregate( $date, 'sessions' );
			EA_Utils::increment_aggregate( $date, 'visitors' );
			if ( $returning ) {
				EA_Utils::increment_aggregate( $date, 'returning_visitors' );
			}
			if ( ! empty( $source['traffic_type'] ) ) {
				EA_Utils::increment_aggregate( $date, 'traffic_type', 1, 'type', $source['traffic_type'] );
			}
			if ( ! empty( $source['source'] ) ) {
				EA_Utils::increment_aggregate( $date, 'source', 1, 'source', $source['source'] );
			}
		}
	}

	public static function record_pageview( $session_id, $pageview ) {
		global $wpdb;
		if ( ! is_array( $pageview ) ) {
			return;
		}

		$table = EA_Utils::table( 'pageviews' );
		$url   = EA_Utils::sanitize_url( $pageview['page_url'] ?? '' );
		if ( $url === '' ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'session_id'   => EA_Utils::sanitize_text( $session_id, 64 ),
				'page_url'     => $url,
				'page_title'   => EA_Utils::sanitize_text( $pageview['page_title'] ?? '', 500 ),
				'viewed_at'    => EA_Utils::now(),
				'time_on_page' => max( 0, (int) ( $pageview['time_on_page'] ?? 0 ) ),
				'scroll_depth' => min( 100, max( 0, (int) ( $pageview['scroll_depth'] ?? 0 ) ) ),
			)
		);

		$sessions = EA_Utils::table( 'sessions' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$sessions} SET page_count = page_count + 1, is_bounce = IF(page_count + 1 > 1, 0, 1), exit_page = %s, last_activity = %s WHERE session_id = %s",
				$url,
				EA_Utils::now(),
				$session_id
			)
		);

		$date = EA_Utils::today();
		EA_Utils::increment_aggregate( $date, 'pageviews' );
		EA_Utils::increment_aggregate( $date, 'pages', 1, 'page', EA_Utils::normalize_page( $url ) );

		// Backfill first_project_slug on the session if it has not been resolved yet.
		// The WHERE guard enforces immutability: once set, this never overwrites.
		if ( class_exists( 'EA_Attribution' ) ) {
			$proj_slug = EA_Attribution::resolve_project_slug( $url );
			if ( $proj_slug !== '' ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$sessions} SET first_project_slug = %s, first_project_page = %s
						 WHERE session_id = %s AND first_project_slug = ''",
						$proj_slug,
						$url,
						EA_Utils::sanitize_text( $session_id, 64 )
					)
				);
			}
		}
	}
}
