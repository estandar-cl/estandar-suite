<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Admin-only operational boundary for the Search Console provider. */
final class Estandar_SEO_Operations {
    private const CAP = 'manage_options';
    private const MAX_BACKFILL_DAYS = 31;

    public static function init(): void {
        add_action( 'admin_post_estandar_seo_test_connection', array( __CLASS__, 'test_connection' ) );
        add_action( 'admin_post_estandar_seo_sync_now', array( __CLASS__, 'sync_now' ) );
        add_action( 'admin_post_estandar_seo_backfill', array( __CLASS__, 'backfill' ) );
        add_action( 'admin_post_estandar_seo_export_conversion_csv', array( __CLASS__, 'export_conversion_csv' ) );
    }

    public static function test_connection(): void {
        self::authorize( 'estandar_seo_test_connection' );
        $run = Estandar_SEO_Repository::start_run( 'connection_test' );
        $result = ( new Estandar_Search_Console_Adapter() )->validate_selected_property();
        $result = array_merge( array( 'rows_received' => 0, 'rows_inserted' => 0, 'rows_skipped' => 0 ), $result );
        Estandar_SEO_Repository::finish_run( $run, $result ); self::redirect( empty( $result['success'] ) ? 'connection_failed' : 'connection_ok' );
    }

    public static function sync_now(): void {
        self::authorize( 'estandar_seo_sync_now' );
        self::run_dates( array( Estandar_SEO_Service::stable_query_date() ), 'manual' );
    }

    public static function backfill(): void {
        self::authorize( 'estandar_seo_backfill' );
        $start = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) ); $end = sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) );
        $dates = self::dates_in_range( $start, $end );
        if ( empty( $dates ) ) { self::redirect( 'backfill_invalid' ); }
        self::run_dates( $dates, 'backfill' );
    }

    public static function health(): array {
        $adapter = new Estandar_Search_Console_Adapter(); $latest = Estandar_SEO_Repository::latest_run();
        $config = (array) get_option( 'estandar_seo_search_console', array() );
        return array( 'enabled' => Estandar_SEO_Service::enabled(), 'configured' => $adapter->configured(), 'selected_property' => (string) ( $config['site_url'] ?? '' ), 'latest' => $latest, 'next_scheduled' => wp_next_scheduled( 'estandar_seo_intelligence_daily_sync' ) );
    }

    public static function render_panel(): void {
        if ( ! current_user_can( self::CAP ) ) { return; }
        $health = self::health(); $latest = $health['latest']; $base = admin_url( 'admin.php?page=estandar_projects_integrations&provider=google_search_console' );
        echo '<div style="margin:24px 0;max-width:980px"><h3>Operación y diagnóstico</h3>';
        echo '<p><strong>Estado:</strong> ' . esc_html( ! $health['enabled'] ? 'Deshabilitado' : ( ! $health['configured'] ? 'Configuración OAuth pendiente' : 'Listo para validar' ) ) . ' · <strong>Próxima ejecución:</strong> ' . esc_html( $health['next_scheduled'] ? gmdate( 'Y-m-d H:i:s', (int) $health['next_scheduled'] ) . ' UTC' : 'no programada' ) . '</p>';
        self::form( 'estandar_seo_test_connection', 'estandar_seo_test_connection', 'Probar conexión', array() );
        self::form( 'estandar_seo_sync_now', 'estandar_seo_sync_now', 'Sincronizar ahora', array() );
        self::form( 'estandar_seo_backfill', 'estandar_seo_backfill', 'Ejecutar backfill (máx. 31 días)', array( 'start_date' => '<label>Desde <input type="date" name="start_date" required></label>', 'end_date' => '<label>Hasta <input type="date" name="end_date" required></label>' ) );
        if ( ! empty( $_GET['seo_operation'] ) ) { echo '<p><strong>Resultado:</strong> ' . esc_html( sanitize_key( (string) $_GET['seo_operation'] ) ) . '</p>'; }
        self::render_runs( Estandar_SEO_Repository::recent_runs( 10 ) ); echo '</div>';
        self::render_conversion_panel();
    }

    /** Compact editorial view; all data is local and aggregate. */
    public static function render_conversion_panel(): void {
        if ( ! current_user_can( self::CAP ) || ! class_exists( 'Estandar_SEO_Conversion_Analytics' ) ) { return; }
        $zone = Estandar_SEO_Service::commercial_timezone(); $to = (new DateTimeImmutable('now',$zone))->modify('-' . Estandar_SEO_Service::stability_delay_days() . ' days');
        $from = $to->modify('-27 days'); $period = array('from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'));
        $data = Estandar_SEO_Conversion_Analytics::get_organic_conversion_summary($period); $rows=$data['dimensions']; usort($rows,static function($a,$b){return ($b['lead_per_click_rate']<=>$a['lead_per_click_rate']);});
        echo '<div style="margin:24px 0;max-width:980px"><h3>SEO Intelligence · Tráfico orgánico y Leads</h3><p>Período: '.esc_html($period['from'].' a '.$period['to'].' · '.$zone->getName()).'. Clicks y Leads son agregados independientes.</p>';
        echo '<p><strong>Clicks:</strong> '.(int)($data['seo_metrics']['clicks']??0).' · <strong>Leads Google Organic:</strong> '.(int)($data['commercial_metrics']['google_organic_leads']??0).' · <strong>Calificados:</strong> '.(int)($data['commercial_metrics']['qualified_leads']??0).' · <strong>Lead/click:</strong> '.esc_html(number_format_i18n(100*(float)($data['conversion_rates']['lead_per_click_rate']??0),1)).'%</p>';
        echo '<table class="widefat striped"><thead><tr><th>Landing</th><th>Proyecto</th><th>Clicks</th><th>Leads</th><th>Calificados</th><th>Lead/click</th><th>Señal</th></tr></thead><tbody>';
        foreach(array_slice($rows,0,50) as $r){$signal=($r['clicks']??0)>0&&empty($r['google_organic_leads'])?'Tráfico sin leads':(($r['google_organic_leads']??0)>0&&($r['clicks']??0)<10?'Leads y bajo tráfico':'—');echo '<tr><td>'.esc_html($r['landing_url']).'</td><td>'.esc_html($r['project_id']?:'—').'</td><td>'.(int)($r['clicks']??0).'</td><td>'.(int)($r['google_organic_leads']??0).'</td><td>'.(int)($r['qualified_leads']??0).'</td><td>'.esc_html(number_format_i18n(100*(float)($r['lead_per_click_rate']??0),1)).'%</td><td>'.esc_html($signal).'</td></tr>';}
        if(empty($rows)){echo '<tr><td colspan="7">Sin cobertura combinada para el período.</td></tr>';} echo '</tbody></table>';
        echo '<p>Frescura SEO: '.esc_html(($data['freshness']['latest_commercial_date']??'sin datos').' · '.(!empty($data['freshness']['stale'])?'stale':'vigente')).'.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="estandar_seo_export_conversion_csv"><input type="hidden" name="from" value="'.esc_attr($period['from']).'"><input type="hidden" name="to" value="'.esc_attr($period['to']).'">'; wp_nonce_field('estandar_seo_export_conversion_csv'); submit_button('Exportar CSV agregado','secondary','submit',false); echo '</form></div>';
    }
    public static function export_conversion_csv(): void {
        self::authorize('estandar_seo_export_conversion_csv'); $period=array('from'=>sanitize_text_field(wp_unslash($_POST['from']??'')),'to'=>sanitize_text_field(wp_unslash($_POST['to']??''))); $data=Estandar_SEO_Conversion_Analytics::get_organic_conversion_summary($period);
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=seo-organic-conversions.csv'); $out=fopen('php://output','w'); fputcsv($out,array('landing_url','project_id','clicks','impressions','ctr','average_position','google_organic_leads','classified_leads','qualified_leads','commercial_results_available','lead_per_click_rate','qualified_per_lead_rate'), ',', '"', '');
        foreach((array)$data['dimensions'] as $r){fputcsv($out,array($r['landing_url']??'',$r['project_id']??'',(int)($r['clicks']??0),(int)($r['impressions']??0),(float)($r['ctr']??0),(float)($r['average_position']??0),(int)($r['google_organic_leads']??0),(int)($r['classified_leads']??0),(int)($r['qualified_leads']??0),(int)($r['commercial_results_available']??0),(float)($r['lead_per_click_rate']??0),(float)($r['qualified_per_lead_rate']??0)), ',', '"', '');} fclose($out); exit;
    }
    private static function form( string $action, string $nonce, string $label, array $fields ): void {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-flex;gap:8px;align-items:center;margin:0 12px 12px 0">'; wp_nonce_field( $nonce ); echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'; foreach ( $fields as $html ) { echo $html; } submit_button( $label, 'secondary', 'submit', false ); echo '</form>';
    }
    private static function render_runs( array $runs ): void {
        echo '<h4>Últimas ejecuciones (UTC)</h4><table class="widefat striped"><thead><tr><th>Tipo</th><th>Fecha comercial</th><th>Estado</th><th>Inicio</th><th>Término</th><th>Recibidas</th><th>Insertadas</th><th>Omitidas</th><th>Próximo intento</th><th>Error</th></tr></thead><tbody>';
        if ( empty( $runs ) ) { echo '<tr><td colspan="10">Sin ejecuciones registradas.</td></tr>'; }
        foreach ( $runs as $run ) { echo '<tr><td>' . esc_html( $run['trigger_type'] ) . '</td><td>' . esc_html( $run['commercial_date'] ?: '—' ) . '</td><td>' . esc_html( $run['status'] ) . '</td><td>' . esc_html( $run['started_at'] ) . '</td><td>' . esc_html( $run['finished_at'] ?: '—' ) . '</td><td>' . (int) $run['rows_received'] . '</td><td>' . (int) $run['rows_inserted'] . '</td><td>' . (int) $run['rows_skipped'] . '</td><td>' . esc_html( $run['next_attempt_at'] ?: '—' ) . '</td><td>' . esc_html( $run['error_message'] ?: '—' ) . '</td></tr>'; }
        echo '</tbody></table>';
    }
    private static function run_dates( array $dates, string $trigger ): void {
        if ( ! Estandar_SEO_Service::enabled() ) { self::redirect( 'disabled' ); }
        $validation = ( new Estandar_Search_Console_Adapter() )->validate_selected_property();
        if ( empty( $validation['success'] ) ) { $run = Estandar_SEO_Repository::start_run( $trigger ); Estandar_SEO_Repository::finish_run( $run, array_merge( $validation, array( 'rows_received' => 0, 'rows_inserted' => 0, 'rows_skipped' => 0 ) ) ); self::redirect( 'property_invalid' ); }
        if ( ! add_option( 'estandar_seo_sync_lock', gmdate( 'Y-m-d H:i:s' ), '', 'no' ) ) { self::redirect( 'already_running' ); }
        try { foreach ( $dates as $date ) { Estandar_SEO_Service::execute( $date, $trigger ); } } finally { delete_option( 'estandar_seo_sync_lock' ); }
        self::redirect( $trigger === 'backfill' ? 'backfill_complete' : 'sync_complete' );
    }
    private static function dates_in_range( string $start, string $end ): array {
        try { $zone = Estandar_SEO_Service::commercial_timezone(); $from = new DateTimeImmutable( $start, $zone ); $to = new DateTimeImmutable( $end, $zone ); } catch ( Exception $e ) { return array(); }
        if ( $from->format( 'Y-m-d' ) !== $start || $to->format( 'Y-m-d' ) !== $end || $from > $to ) { return array(); }
        $days = $from->diff( $to )->days + 1; if ( $days > self::MAX_BACKFILL_DAYS ) { return array(); }
        $out = array(); for ( $d = $from; $d <= $to; $d = $d->modify( '+1 day' ) ) { $out[] = $d->format( 'Y-m-d' ); } return $out;
    }
    private static function authorize( string $nonce ): void { if ( ! current_user_can( self::CAP ) ) { wp_die( 'Sin permisos.' ); } check_admin_referer( $nonce ); }
    private static function redirect( string $result ): void { wp_safe_redirect( admin_url( 'admin.php?page=estandar_projects_integrations&provider=google_search_console&seo_operation=' . rawurlencode( $result ) ) ); exit; }
}
