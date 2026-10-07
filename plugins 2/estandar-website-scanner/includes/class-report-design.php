<?php
/**
 * Report design settings.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles visual settings for report.
 */
class EWS_Report_Design {

	/**
	 * Option key.
	 */
	private const OPTION_KEY = 'ews_report_layout_settings';

	/**
	 * Defaults.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			'page_size'        => 'a4',
			'header_banner'    => '',
			'cover_background' => '',
			'pdf_cover_background' => '',
			'pdf_cover_title'  => 'scanner',
			'pdf_subtitle_es'  => 'Diagnóstico digital',
			'pdf_subtitle_en'  => 'Digital diagnosis',
			'client_logo'      => '',
			'logo_position'    => 'top-right',
			'font'             => 'Inter',
			'layout'           => 'one-column',
			'primary_color'    => '#1e73be',
			'secondary_color'  => '#0f172a',
			'chart_palette'    => '#1e73be,#10b981,#f59e0b,#ef4444,#8b5cf6',
		);
	}

	/**
	 * Get settings.
	 *
	 * @return array<string, string>
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, string>
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$output   = array();
		foreach ( $defaults as $key => $value ) {
			$raw = isset( $input[ $key ] ) ? (string) $input[ $key ] : $value;
			switch ( $key ) {
				case 'header_banner':
				case 'cover_background':
				case 'pdf_cover_background':
				case 'client_logo':
					$output[ $key ] = esc_url_raw( $raw );
					break;
				case 'primary_color':
				case 'secondary_color':
					$output[ $key ] = sanitize_hex_color( $raw ) ?: $value;
					break;
				case 'chart_palette':
					$output[ $key ] = sanitize_text_field( $raw );
					break;
				default:
					$output[ $key ] = sanitize_text_field( $raw );
					break;
			}
		}
		return $output;
	}

	/**
	 * Parse chart palette.
	 *
	 * @param array<string, string> $settings Settings.
	 * @return array<int, string>
	 */
	public static function chart_palette( array $settings ): array {
		$colors = array_map( 'trim', explode( ',', (string) ( $settings['chart_palette'] ?? '' ) ) );
		$colors = array_values( array_filter( $colors, static function ( string $color ): bool {
			return (bool) sanitize_hex_color( $color );
		} ) );
		if ( empty( $colors ) ) {
			$colors = array( '#1e73be', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6' );
		}
		return $colors;
	}
}
