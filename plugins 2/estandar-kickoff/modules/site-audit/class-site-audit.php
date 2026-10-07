<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/interface-site-audit-collector.php';
require_once __DIR__ . '/class-site-audit-result.php';
require_once __DIR__ . '/class-health-check-engine.php';
require_once __DIR__ . '/collectors.php';

class Estandar_KickOff_Site_Audit {

	const OPTION_SNAPSHOT = 'estandar_kickoff_site_audit_snapshot';
	const SCHEMA_VERSION  = '1.0';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_estandar_kickoff_site_audit_run', [ $this, 'handle_run' ] );
	}

	/**
	 * Genera el snapshot completo (read-only, on-demand).
	 */
	public function generate_snapshot() {
		$collectors = [
			new Estandar_KickOff_Site_Audit_Core_Collector(),
			new Estandar_KickOff_Site_Audit_Php_Server_Collector(),
			new Estandar_KickOff_Site_Audit_Theme_Collector(),
			new Estandar_KickOff_Site_Audit_Plugins_Collector(),
			new Estandar_KickOff_Site_Audit_Estandar_Plugins_Collector(),
			new Estandar_KickOff_Site_Audit_Tracking_Collector(),
			new Estandar_KickOff_Site_Audit_Forms_Collector(),
		];

		$sections     = [];
		$risk_summary = [ 'P0' => 0, 'P1' => 0, 'P2' => 0, 'P3' => 0 ];

		foreach ( $collectors as $collector ) {
			$domain              = $collector->domain();
			$sections[ $domain ] = $collector->collect()->to_array();
			foreach ( $sections[ $domain ]['risks'] as $risk ) {
				$sev = $risk['severity'] ?? '';
				if ( isset( $risk_summary[ $sev ] ) ) {
					$risk_summary[ $sev ]++;
				}
			}
		}

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'generated_at'   => gmdate( 'c' ),
			'site_url'       => home_url(),
			'sections'       => $sections,
			'risk_summary'   => $risk_summary,
		];
	}

	public function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'estandar-kickoff' ) );
		}
		check_admin_referer( 'estandar_kickoff_site_audit_run' );

		update_option( self::OPTION_SNAPSHOT, $this->generate_snapshot(), false );

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'estandar-kickoff', 'tab' => 'site-audit', 'audit_done' => '1' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function get_last_snapshot() {
		$snapshot = get_option( self::OPTION_SNAPSHOT, null );
		return is_array( $snapshot ) ? $snapshot : null;
	}

	public static function render_tab() {
		include __DIR__ . '/views/admin-page.php';
	}
}
