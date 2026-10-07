<?php
/**
 * Plugin settings (configuration only, not analytics data).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Settings {

	const OPTION = 'ea_settings';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function defaults() {
		return array(
			'enable_tracking'        => 1,
			'enable_click_tracking'  => 1,
			'enable_scroll_tracking' => 1,
			'enable_form_tracking'   => 1,
			'enable_whatsapp_tracking' => 1,
			'whatsapp_bridge_slug'     => 'run',
			'whatsapp_bridge_aliases'  => array(),
			'retention_days'         => 90,
			'bot_filtering'          => 1,
			'exclude_admin'          => 1,
			'ignore_logged_in_roles' => 1,
			'ignored_roles'          => array( 'administrator', 'editor' ),
			'anonymize_ip'           => 1,
			'custom_selectors'       => array(),
			'conversions'            => array(
				array(
					'key'   => 'whatsapp_click',
					'label' => 'WhatsApp click',
					'type'  => 'whatsapp_click',
					'value' => '',
				),
				array(
					'key'   => 'phone_click',
					'label' => 'Phone click',
					'type'  => 'phone_click',
					'value' => '',
				),
				array(
					'key'   => 'email_click',
					'label' => 'Email click',
					'type'  => 'email_click',
					'value' => '',
				),
			),
			'thank_you_paths'        => array( '/gracias', '/thank-you' ),
			'hash_salt'              => wp_generate_password( 32, false, false ),
			'admin_locale'           => 'es_ES',
		);
	}

	public static function get() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	public static function save_defaults() {
		// add_option is a no-op if the option already exists — never overwrites existing config.
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	public static function register_settings() {
		register_setting(
			'ea_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			return self::get();
		}

		$current = self::get();
		$out     = $current;

		$out['enable_tracking']        = ! empty( $input['enable_tracking'] ) ? 1 : 0;
		$out['enable_click_tracking']  = ! empty( $input['enable_click_tracking'] ) ? 1 : 0;
		$out['enable_scroll_tracking'] = ! empty( $input['enable_scroll_tracking'] ) ? 1 : 0;
		$out['enable_form_tracking']   = ! empty( $input['enable_form_tracking'] ) ? 1 : 0;
		$out['enable_whatsapp_tracking'] = ! empty( $input['enable_whatsapp_tracking'] ) ? 1 : 0;
		$current_slug = sanitize_title( (string) ( $current['whatsapp_bridge_slug'] ?? 'run' ) );
		$new_slug = sanitize_title( (string) ( $input['whatsapp_bridge_slug'] ?? $current_slug ) );
		$new_slug = $new_slug !== '' ? $new_slug : 'run';
		$aliases = array_values( array_unique( array_filter( array_map( 'sanitize_title', (array) ( $current['whatsapp_bridge_aliases'] ?? array() ) ) ) ) );
		if ( $current_slug !== '' && $current_slug !== $new_slug ) {
			$aliases[] = $current_slug;
		}
		$out['whatsapp_bridge_slug'] = $new_slug;
		$out['whatsapp_bridge_aliases'] = array_values( array_diff( array_unique( $aliases ), array( $new_slug ) ) );
		$out['bot_filtering']          = ! empty( $input['bot_filtering'] ) ? 1 : 0;
		$out['exclude_admin']          = ! empty( $input['exclude_admin'] ) ? 1 : 0;
		$out['ignore_logged_in_roles'] = ! empty( $input['ignore_logged_in_roles'] ) ? 1 : 0;
		$out['anonymize_ip']           = ! empty( $input['anonymize_ip'] ) ? 1 : 0;

		$retention = isset( $input['retention_days'] ) ? (int) $input['retention_days'] : 90;
		$allowed   = array( 30, 90, 180, 365 );
		$out['retention_days'] = in_array( $retention, $allowed, true ) ? $retention : 90;

		$out['ignored_roles'] = array();
		if ( ! empty( $input['ignored_roles'] ) && is_array( $input['ignored_roles'] ) ) {
			foreach ( $input['ignored_roles'] as $role ) {
				$role = sanitize_key( $role );
				if ( $role ) {
					$out['ignored_roles'][] = $role;
				}
			}
		}
		if ( empty( $out['ignored_roles'] ) ) {
			$out['ignored_roles'] = array( 'administrator', 'editor' );
		}

		$out['custom_selectors'] = array();
		if ( ! empty( $input['custom_selectors'] ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $input['custom_selectors'] );
			foreach ( $lines as $line ) {
				$line = trim( sanitize_text_field( $line ) );
				if ( $line !== '' ) {
					$out['custom_selectors'][] = $line;
				}
			}
		}

		$out['thank_you_paths'] = array();
		if ( ! empty( $input['thank_you_paths'] ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $input['thank_you_paths'] );
			foreach ( $lines as $line ) {
				$line = trim( sanitize_text_field( $line ) );
				if ( $line !== '' ) {
					$out['thank_you_paths'][] = $line;
				}
			}
		}

		$out['conversions'] = self::sanitize_conversions( $input['conversions'] ?? array() );

		$locale = isset( $input['admin_locale'] ) ? sanitize_text_field( $input['admin_locale'] ) : 'es_ES';
		$out['admin_locale'] = in_array( $locale, array( 'es_ES', 'en_US' ), true ) ? $locale : 'es_ES';

		if ( empty( $out['hash_salt'] ) ) {
			$out['hash_salt'] = wp_generate_password( 32, false, false );
		}

		return $out;
	}

	private static function sanitize_conversions( $rows ) {
		$clean = array();
		if ( ! is_array( $rows ) ) {
			return $clean;
		}

		$allowed_types = array(
			'whatsapp_click', 'phone_click', 'email_click', 'thank_you_page',
			'custom_selector', 'form_submit',
		);

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key  = EA_Utils::sanitize_text( $row['key'] ?? '', 100 );
			$type = EA_Utils::sanitize_text( $row['type'] ?? '', 50 );
			if ( $key === '' || ! in_array( $type, $allowed_types, true ) ) {
				continue;
			}
			$clean[] = array(
				'key'   => $key,
				'label' => EA_Utils::sanitize_text( $row['label'] ?? $key, 255 ),
				'type'  => $type,
				'value' => EA_Utils::sanitize_text( $row['value'] ?? '', 500 ),
			);
		}

		return $clean;
	}

	public static function get_tracker_config() {
		$s = self::get();
		return array(
			'endpoint'       => rest_url( 'ea/v1/collect' ),
			'clickTracking'  => (bool) $s['enable_click_tracking'],
			'scrollTracking' => (bool) $s['enable_scroll_tracking'],
			'formTracking'   => (bool) $s['enable_form_tracking'],
			'whatsappTracking' => (bool) $s['enable_whatsapp_tracking'],
			'whatsappBridgeBase' => rest_url( 'ea/v1/whatsapp/' ),
			'whatsappBridgeSlugs' => array_values( array_merge( array( $s['whatsapp_bridge_slug'] ), (array) $s['whatsapp_bridge_aliases'] ) ),
			'selectors'      => array_values( (array) $s['custom_selectors'] ),
			'conversions'    => array_values( (array) $s['conversions'] ),
			'thankYouPaths'  => array_values( (array) $s['thank_you_paths'] ),
			'siteHost'       => EA_Utils::extract_domain( home_url() ),
		);
	}
}
