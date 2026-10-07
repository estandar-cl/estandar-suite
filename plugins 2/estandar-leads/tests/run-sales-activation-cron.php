<?php
/** Run: php tests/run-sales-activation-cron.php */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ESTANDAR_LEADS_FILE', __FILE__ );

$GLOBALS['activation_options'] = array();
$GLOBALS['activation_actions'] = array();
$GLOBALS['activation_cron'] = array();

function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return trim( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return sanitize_text_field( $v ); }
function sanitize_email( $v ) { return filter_var( $v, FILTER_SANITIZE_EMAIL ); }
function is_email( $v ) { return (bool) filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function get_option( $key, $default = false ) { return $GLOBALS['activation_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['activation_options'][ $key ] = $value; return true; }
function add_action( $hook, $callback ) { $GLOBALS['activation_actions'][ $hook ] = $callback; }
function add_filter( $hook, $callback ) { $GLOBALS['activation_actions'][ $hook ] = $callback; }
function register_deactivation_hook() {}
function wp_timezone() { return new DateTimeZone( 'America/Santiago' ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['activation_cron'][ $hook ] ?? false; }
function wp_schedule_single_event( $timestamp, $hook ) { $GLOBALS['activation_cron'][ $hook ] = $timestamp; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['activation_cron'][ $hook ] ); return 1; }

class Estandar_Leads_Email {
	public static function sanitize_email_list( $value ): string { return trim( (string) $value ); }
}

require dirname( __DIR__ ) . '/includes/class-estandar-leads-sales-activation.php';

$fail = 0;
function cron_check( $label, $ok ) {
	global $fail;
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	if ( ! $ok ) { $fail++; }
}

$future = new DateTimeImmutable( '+2 hours', wp_timezone() );
$GLOBALS['activation_options'][ Estandar_Leads_Sales_Activation::OPTION_KEY ] = array(
	'enabled' => 1,
	'send_days' => array( (int) $future->format( 'N' ) ),
	'send_time' => $future->format( 'H:i' ),
	'max_recommendations' => 5,
	'min_score' => 4,
);

$expected = Estandar_Leads_Sales_Activation::next_send_timestamp();
Estandar_Leads_Sales_Activation::init();
$scheduled = wp_next_scheduled( Estandar_Leads_Sales_Activation::CRON_HOOK );

cron_check( 'cron hook registrado con run_dispatch', ( $GLOBALS['activation_actions'][ Estandar_Leads_Sales_Activation::CRON_HOOK ] ?? null ) === array( 'Estandar_Leads_Sales_Activation', 'run_dispatch' ) );
cron_check( 'scheduler usa días/hora almacenados', $scheduled === $expected && (int) wp_date_for_test( $scheduled, 'N' ) === (int) $future->format( 'N' ) );
cron_check( 'scheduler usa timezone WordPress', wp_date_for_test( $scheduled, 'H:i' ) === $future->format( 'H:i' ) );

Estandar_Leads_Sales_Activation::save_settings( array( 'enabled' => 0 ) );
cron_check( 'deshabilitar limpia el próximo evento', wp_next_scheduled( Estandar_Leads_Sales_Activation::CRON_HOOK ) === false );

exit( $fail ? 1 : 0 );

function wp_date_for_test( $timestamp, $format ) {
	return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->format( $format );
}
