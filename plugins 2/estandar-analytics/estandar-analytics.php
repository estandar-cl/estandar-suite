<?php
/**
 * Plugin Name: Estandar Analytics
 * Plugin URI: https://www.estandar.cl
 * Description: Self-hosted, privacy-first analytics for WordPress. Tracks sessions, sources, pageviews, events, and conversions without external SaaS.
 * Version: 13.0.173
 * Author: Luis Valdés de Estandar Studios CL
 * Author URI: https://www.estandar.cl
 * Text Domain: estandar-analytics
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://updates.estandar.cl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EA_VERSION', '13.0.173' );
define( 'ESTANDAR_ANALYTICS_VERSION', EA_VERSION );
define( 'EA_DB_VERSION', '1.4.4' );
define( 'EA_PATH', plugin_dir_path( __FILE__ ) );
define( 'EA_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'ESTANDAR_ANALYTICS_JOURNEY_V1' ) ) {
	define( 'ESTANDAR_ANALYTICS_JOURNEY_V1', true );
}
if ( ! defined( 'ESTANDAR_ANALYTICS_NORMALIZATION_V1' ) ) {
	define( 'ESTANDAR_ANALYTICS_NORMALIZATION_V1', true );
}

// Bootstrap fallback for primer_is_active().
// Executes only when Primer has not yet loaded (WordPress plugin load order is not
// guaranteed). Skipped entirely once Primer is present — Primer's own definition
// takes ownership.
if ( ! function_exists( 'primer_is_active' ) ) {
	function primer_is_active(): bool {
		return defined( 'PRIMER_VERSION' );
	}
}

// Bootstrap fallback for resolve_project_display_name() — definida en Primer,
// llamada sin function_exists() guard en este plugin. Sin este polyfill,
// desactivar Primer produce un fatal en vez de degradar con gracia.
if ( ! function_exists( 'resolve_project_display_name' ) ) {
	function resolve_project_display_name( $slug, $fallback_area = '' ): string {
		return '' !== (string) $fallback_area ? (string) $fallback_area : (string) $slug;
	}
}

require_once EA_PATH . 'includes/class-utils.php';
require_once EA_PATH . 'includes/class-installer.php';
require_once EA_PATH . 'includes/class-privacy.php';
require_once EA_PATH . 'includes/class-settings.php';
require_once EA_PATH . 'includes/class-i18n.php';
require_once EA_PATH . 'includes/class-admin-ui.php';
require_once EA_PATH . 'includes/class-attribution.php';
require_once EA_PATH . 'includes/class-session.php';
require_once EA_PATH . 'includes/class-events.php';
require_once EA_PATH . 'includes/class-whatsapp.php';
require_once EA_PATH . 'includes/class-rest.php';
require_once EA_PATH . 'includes/class-tracker.php';
require_once EA_PATH . 'includes/class-dashboard.php';
require_once EA_PATH . 'includes/class-estandar-analytics-commercial-stage-map.php';
require_once EA_PATH . 'includes/class-retention.php';
require_once EA_PATH . 'includes/class-core-compat.php';

if ( ! function_exists( 'estandar_analytics_empty_attribution' ) ) {
	function estandar_analytics_empty_attribution() {
		return array(
			'session_id'          => '',
			'utm_source'          => '',
			'utm_medium'          => '',
				'utm_campaign'        => '',
				'utm_term'            => '',
				'utm_content'         => '',
				'campaign_id'         => '',
				'campaign_name'       => '',
				'adgroup_id'          => '',
				'asset_group_id'      => '',
				'creative_id'         => '',
				'keyword'             => '',
				'match_type'          => '',
				'network'             => '',
				'device'              => '',
				'placement'           => '',
				'campaign_type'       => '',
				'gclid'               => '',
			'gbraid'              => '',
			'wbraid'              => '',
			'fbclid'              => '',
			'meta_fbp'            => '',
			'meta_fbc'            => '',
			'ttclid'              => '',
			'msclkid'             => '',
			'landing_url'         => '',
			'referrer'            => '',
			'traffic_type'        => '',
			'first_project_slug'  => '',
			'first_project_page'  => '',
			'attribution_model'   => 'first_touch',
			'capture_method'      => 'missing',
		);
	}
}

if ( ! function_exists( 'estandar_analytics_get_attribution_by_session' ) ) {
	function estandar_analytics_get_attribution_by_session( $session_id ) {
		$empty = array(
			'session_id'        => '',
			'utm_source'        => '',
			'utm_medium'        => '',
			'utm_campaign'      => '',
				'utm_term'          => '',
				'utm_content'       => '',
				'campaign_id'       => '',
				'campaign_name'     => '',
				'adgroup_id'        => '',
				'asset_group_id'    => '',
				'creative_id'       => '',
				'keyword'           => '',
				'match_type'        => '',
				'network'           => '',
				'device'            => '',
				'placement'         => '',
				'campaign_type'     => '',
				'gclid'             => '',
				'gbraid'            => '',
				'wbraid'            => '',
			'fbclid'            => '',
			'meta_fbp'          => '',
			'meta_fbc'          => '',
			'ttclid'            => '',
			'msclkid'           => '',
			'landing_url'       => '',
			'referrer'          => '',
			'traffic_type'      => '',
			'first_project_slug' => '',
			'first_project_page' => '',
			'attribution_model' => 'first_touch',
			'capture_method'    => 'missing',
		);

		$session_id = EA_Utils::sanitize_text( $session_id, 64 );
		if ( ! preg_match( '/^[a-zA-Z0-9\-]{8,64}$/', $session_id ) ) {
			return $empty;
		}

		global $wpdb;
		$table        = EA_Utils::table( 'sessions' );
		$columns      = array();
		$cache_key    = 'estandar_analytics_columns_' . md5( $table );
		$column_rows  = get_transient( $cache_key );

		if ( ! is_array( $column_rows ) ) {
			$old_suppress = $wpdb->suppress_errors( true );
			$column_rows  = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );

			if ( empty( $column_rows ) ) {
				$column_rows = $wpdb->get_results( "PRAGMA table_info({$table})", ARRAY_A );
			}
			$wpdb->suppress_errors( $old_suppress );
		}

		foreach ( (array) $column_rows as $column ) {
			if ( isset( $column['Field'] ) ) {
				$columns[] = (string) $column['Field'];
			} elseif ( isset( $column['name'] ) ) {
				$columns[] = (string) $column['name'];
			}
		}

		if ( empty( $columns ) || ! in_array( 'session_id', $columns, true ) ) {
			return $empty;
		}
		if ( ! get_transient( $cache_key ) ) {
			set_transient( $cache_key, (array) $column_rows, HOUR_IN_SECONDS );
		}

		$wanted = array(
			'session_id',
			'source',
			'medium',
				'campaign',
				'campaign_id',
				'adgroup_id',
				'asset_group_id',
				'creative_id',
				'keyword',
				'match_type',
				'network',
				'google_device',
				'placement',
				'campaign_type',
				'term',
				'content',
				'gclid',
				'gbraid',
				'wbraid',
				'fbclid',
				'meta_fbp',
				'meta_fbc',
				'ttclid',
				'msclkid',
				'landing_page',
			'referrer',
			'traffic_type',
			'first_project_slug',
			'first_project_page',
		);
		$select = array_values( array_intersect( $wanted, $columns ) );
		if ( empty( $select ) ) {
			return $empty;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . implode( ', ', array_map( 'sanitize_key', $select ) ) . " FROM {$table} WHERE session_id = %s LIMIT 1",
				$session_id
			),
			ARRAY_A
		);

		if ( empty( $row ) || ! is_array( $row ) ) {
			return $empty;
		}

			$campaign_id   = (string) ( $row['campaign_id'] ?? '' );
			$campaign_name = '';
			if ( $campaign_id !== '' || ! empty( $row['campaign'] ) ) {
				$ads_table = EA_Utils::table( 'ads_daily' );
				$old_suppress = $wpdb->suppress_errors( true );
				$campaign_name = (string) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT campaign_name FROM {$ads_table}
						 WHERE campaign_name != '' AND (campaign_id = %s OR campaign_name = %s)
						 ORDER BY stat_date DESC, id DESC LIMIT 1",
						$campaign_id,
						(string) ( $row['campaign'] ?? '' )
					)
				);
				$wpdb->suppress_errors( $old_suppress );
			}

			return array(
				'session_id'        => (string) ( $row['session_id'] ?? '' ),
				'utm_source'        => (string) ( $row['source'] ?? '' ),
				'utm_medium'        => (string) ( $row['medium'] ?? '' ),
				'utm_campaign'      => (string) ( $row['campaign'] ?? '' ),
				'utm_term'          => (string) ( $row['term'] ?? '' ),
					'campaign_id'       => $campaign_id,
					'campaign_name'     => $campaign_name,
					'adgroup_id'        => (string) ( $row['adgroup_id'] ?? '' ),
					'asset_group_id'    => (string) ( $row['asset_group_id'] ?? '' ),
					'creative_id'       => (string) ( $row['creative_id'] ?? '' ),
					'keyword'           => (string) ( $row['keyword'] ?? '' ),
					'match_type'        => (string) ( $row['match_type'] ?? '' ),
					'network'           => (string) ( $row['network'] ?? '' ),
					'device'            => (string) ( $row['google_device'] ?? '' ),
					'placement'         => (string) ( $row['placement'] ?? '' ),
					'campaign_type'     => (string) ( $row['campaign_type'] ?? '' ),
					'utm_content'       => (string) ( $row['content'] ?? '' ),
				'gclid'             => (string) ( $row['gclid'] ?? '' ),
				'gbraid'            => (string) ( $row['gbraid'] ?? '' ),
				'wbraid'            => (string) ( $row['wbraid'] ?? '' ),
				'fbclid'            => (string) ( $row['fbclid'] ?? '' ),
				'meta_fbp'          => (string) ( $row['meta_fbp'] ?? '' ),
				'meta_fbc'          => (string) ( $row['meta_fbc'] ?? '' ),
				'ttclid'            => (string) ( $row['ttclid'] ?? '' ),
				'msclkid'           => (string) ( $row['msclkid'] ?? '' ),
				'landing_url'       => (string) ( $row['landing_page'] ?? '' ),
			'referrer'          => (string) ( $row['referrer'] ?? '' ),
			'traffic_type'      => (string) ( $row['traffic_type'] ?? '' ),
			'first_project_slug' => (string) ( $row['first_project_slug'] ?? '' ),
			'first_project_page' => (string) ( $row['first_project_page'] ?? '' ),
			'project_attribution_slug' => (string) ( $row['project_attribution_slug'] ?? '' ),
			'attribution_model' => 'first_touch',
			'capture_method'    => 'ea_sid_session',
		);
	}
}

if ( ! function_exists( 'estandar_analytics_get_current_attribution' ) ) {
	function estandar_analytics_get_current_attribution() {
		if ( empty( $_COOKIE['ea_sid'] ) ) {
			return estandar_analytics_empty_attribution();
		}
		return estandar_analytics_get_attribution_by_session( wp_unslash( $_COOKIE['ea_sid'] ) );
	}
}

register_activation_hook( __FILE__, array( 'EA_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EA_Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( version_compare( (string) get_option( 'ea_db_version', '0' ), EA_DB_VERSION, '<' ) ) {
			EA_Installer::activate();
		}

		EA_I18n::init();
		EA_Settings::init();
		EA_REST::init();
		EA_WhatsApp::init();
		EA_Tracker::init();
		EA_Retention::init();
		EA_Core_Compat::init();

		if ( is_admin() ) {
			EA_Dashboard::init();
		}
	}
);

add_action(
	'admin_init',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( version_compare( (string) get_option( 'ea_db_version', '0' ), EA_DB_VERSION, '<' ) ) {
			EA_Installer::activate();
		}
	}
);
