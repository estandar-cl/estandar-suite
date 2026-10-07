<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Estandar_Quotes_Preview_Admin {
	const CAPABILITY = 'estandar_quotes_view';
	public static function activate_capability(): void { $role = get_role( 'administrator' ); if ( $role ) { $role->add_cap( self::CAPABILITY ); } }
	public static function init(): void { self::activate_capability(); add_action( 'admin_menu', array( __CLASS__, 'menu' ) ); }
	public static function menu(): void { add_submenu_page( null, 'Preview de cotización', '', self::CAPABILITY, 'estandar-quotes-preview', array( __CLASS__, 'render' ) ); }
	public static function request_allowed( bool $has_capability, bool $valid_nonce ): bool { return $has_capability && $valid_nonce; }
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'No autorizado.', 'estandar-quotes' ) ); }
		$quote_uuid = sanitize_text_field( wp_unslash( $_GET['quote'] ?? '' ) ); if ( $quote_uuid === '' ) { self::form(); return; }
		if ( ! self::request_allowed( true, wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'estandar_quotes_preview_' . $quote_uuid ) ) ) { wp_die( esc_html__( 'Solicitud no válida.', 'estandar-quotes' ) ); } $source = sanitize_key( wp_unslash( $_GET['source'] ?? '' ) ); $revision = sanitize_text_field( wp_unslash( $_GET['revision'] ?? '' ) );
		try { $artifact = Estandar_Quotes_Preview_Service::resolve( $quote_uuid, $source, $revision ); if ( ! apply_filters( 'estandar_quotes_preview_authorized', true, $quote_uuid, get_current_user_id() ) ) { throw new RuntimeException( 'Unauthorized preview scope.' ); } self::page( $artifact ); } catch ( Throwable $e ) { self::error( esc_html( $e->getMessage() ) ); }
	}
	private static function form(): void { echo '<div class="wrap"><h1>Preview de cotización</h1><p>Indique una cotización y una fuente explícita. Esta pantalla no modifica datos.</p></div>'; }
	private static function error( string $detail = '' ): void { echo '<div class="wrap"><h1>Preview no disponible</h1><p>La cotización, fuente o definición solicitada no es válida para preview.' . ( $detail !== '' ? '<br><code>' . $detail . '</code>' : '' ) . '</p></div>'; }
	private static function page( array $artifact ): void { nocache_headers(); echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Preview de cotización</title><style>body{margin:0;background:#f1f1f1}.esq-admin-bar{position:sticky;top:0;z-index:2;padding:12px;background:#1d2327;color:#fff;text-align:right}.esq-admin-bar button{padding:8px 12px;font-weight:700}</style></head><body><div class="esq-admin-bar"><button type="button" id="esq-print">Imprimir</button></div><main id="esq-preview">' . $artifact['preview_html'] . '</main><template id="esq-print-html">' . $artifact['print_html'] . '</template><script>(function(){var b=document.getElementById("esq-print"),t=document.getElementById("esq-print-html");b.addEventListener("click",function(){var w=window.open("","_blank","noopener,noreferrer");if(!w){return;}w.document.open();w.document.write(t.innerHTML);w.document.close();w.focus();w.print();});}());</script></body></html>'; }
}
