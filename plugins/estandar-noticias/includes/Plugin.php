<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	const CRON_HOOK      = 'estandar_noticias_cron';
	const OPTION_RUN_TOKEN = 'estandar_noticias_run_token';

	public static function init(): void {
		Admin::init();
		Cli::init();
		Rest::init();
		Ratings::init();
		Updater::init();
		add_action( 'init', array( '\\Estandar\\Noticias\\Publish', 'register' ) );
		add_action( 'init', array( __CLASS__, 'maybe_run_trigger' ), 20 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
	}

	public static function run_token(): string {
		$t = (string) get_option( self::OPTION_RUN_TOKEN, '' );
		if ( $t === '' ) {
			$t = wp_generate_password( 32, false );
			update_option( self::OPTION_RUN_TOKEN, $t, false );
		}
		return $t;
	}

	public static function maybe_run_trigger(): void {
		if ( ! isset( $_GET['en_run'] ) ) {
			return;
		}
		$expected = self::run_token();
		$given    = (string) $_GET['en_run'];
		if ( $given === '' || ! hash_equals( $expected, $given ) ) {
			status_header( 403 );
			echo 'forbidden';
			exit;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		$ed  = isset( $_GET['edicion'] ) ? sanitize_key( (string) $_GET['edicion'] ) : '';
		$res = Pipeline::run( $ed, ! empty( $_GET['force'] ) );
		echo wp_json_encode( $res, JSON_UNESCAPED_UNICODE );
		exit;
	}

	public static function schedules( array $schedules ): array {
		if ( ! isset( $schedules['estandar_noticias_daily'] ) ) {
			$schedules['estandar_noticias_daily'] = array(
				'interval' => DAY_IN_SECONDS,
				'display'  => 'Estandar Noticias (diario)',
			);
		}
		return $schedules;
	}

	public static function activate(): void {
		$existing = get_option( Settings::OPTION, null );
		if ( ! is_array( $existing ) || ! $existing ) {
			update_option( Settings::OPTION, Settings::defaults() );
		}
		Publish::register();
		Ratings::install();
		Updater::clear_cache();
		self::run_token();
		flush_rewrite_rules();
		self::reschedule();
		do_action( 'estandar_noticias_activate' );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function reschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_event( self::next_run(), 'daily', self::CRON_HOOK );
	}

	public static function next_run(): int {
		$hora = (string) Settings::get( 'general', 'hora', '07:30' );
		$tz   = wp_timezone();
		try {
			$when = new \DateTimeImmutable( 'today ' . $hora, $tz );
		} catch ( \Throwable $e ) {
			$when = new \DateTimeImmutable( 'today 07:30', $tz );
		}
		$ts = $when->getTimestamp();
		if ( $ts <= time() ) {
			$ts += DAY_IN_SECONDS;
		}
		return $ts;
	}

	public static function run( string $edicion = '' ): void {
		$res = Pipeline::run( $edicion );
		do_action( 'estandar_noticias_run', $edicion, $res );
		return;
	}

	public static function suite_active(): bool {
		return defined( 'ESTANDAR_SUITE_CORE_VERSION' ) || class_exists( '\\Estandar\\Core\\Admin' );
	}
}
