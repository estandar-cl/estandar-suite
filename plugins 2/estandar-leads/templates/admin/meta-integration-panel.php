<?php
/**
 * Panel Meta (read-only): diagnostics + Meta Status + visor de últimos eventos.
 *
 * Fuente ÚNICA de este markup. Consumido por:
 *   - Estandar_Leads_Meta_Integration_Provider::render_extra()  (pantalla Integraciones, core)
 *   - templates/admin/settings.php                              (fallback standalone sin core)
 *
 * Autocontenido: no espera variables del includer. Nunca imprime Access Token,
 * PII ni payloads.
 *
 * @package EstandarLeads
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Leads_Meta_Adapter' ) ) {
	return;
}

$_mp_settings   = Estandar_Leads_Meta_Adapter::get_settings();
$_mp_configured = $_mp_settings['pixel_id'] !== '' && $_mp_settings['access_token'] !== '';

if ( ! $_mp_configured ) {
	echo '<p style="color:#6b7280;font-size:13px;">Configura Pixel ID y Access Token para habilitar diagnóstico y visor de eventos.</p>';
	return;
}

$_mp_enabled   = $_mp_settings['enabled'] !== '0';
$_mp_server_ev = $_mp_settings['server_events'] !== '0';
$_mp_pixel_on  = class_exists( 'Estandar_Leads_Meta_Pixel' ) && Estandar_Leads_Meta_Pixel::is_active();
$_mp_last      = get_transient( 'estandar_meta_diag_last' );
$_mp_qstats    = Estandar_Leads_Meta_Adapter::queue_stats();
$_mp_qcounts   = $_mp_qstats['counts'];
$_mp_logrows   = Estandar_Leads_Meta_Adapter::recent_log_rows( 20 );
?>

<!-- ── Meta CAPI Diagnostics ──────────────────────────────────────────────── -->
<div style="margin:24px 0 32px;">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <button type="button" id="es-meta-diag-btn" class="button button-secondary"
            style="font-size:13px;padding:6px 16px;">Probar conexión</button>
        <span id="es-meta-diag-spinner" style="display:none;vertical-align:middle;">
            <img src="<?php echo esc_url( admin_url( 'images/spinner.gif' ) ); ?>" style="width:20px;height:20px;">
        </span>
    </div>

    <?php if ( $_mp_last ) : ?>
    <div style="margin-top:12px;padding:8px 14px;border:1px solid #e5e7eb;border-radius:6px;background:#f9f9f9;font-size:12px;color:#6b7280;max-width:520px;">
        <strong>Último diagnóstico</strong> —
        <?php echo esc_html( wp_date( 'd/m/Y H:i:s', (int) $_mp_last['timestamp'] ) ); ?> ·
        <span style="font-weight:700;color:<?php echo $_mp_last['status'] === 'READY' ? '#166534' : '#991b1b'; ?>;">
            <?php echo esc_html( $_mp_last['status'] ); ?>
        </span>
        <?php if ( $_mp_last['http'] ) : ?> · HTTP <?php echo esc_html( (string) $_mp_last['http'] ); ?><?php endif; ?>
        <?php if ( $_mp_last['latency_ms'] ) : ?> · <?php echo esc_html( (string) $_mp_last['latency_ms'] ); ?> ms<?php endif; ?>
    </div>
    <?php endif; ?>

    <div id="es-meta-diag-card" style="display:none;margin-top:16px;padding:16px 20px;border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;max-width:520px;font-size:13px;font-family:monospace;line-height:1.8;"></div>
</div>
<script>
(function(){
    var btn     = document.getElementById('es-meta-diag-btn');
    var spinner = document.getElementById('es-meta-diag-spinner');
    var card    = document.getElementById('es-meta-diag-card');
    if (!btn) return;

    function pf(label, val, w) {
        w = w || 22;
        var pad = label + ' ';
        while (pad.length < w) pad += '.';
        var color = val === 'PASS' || val === 'READY' ? '#166534' : (val === 'FAIL' || val === 'BLOCKED' ? '#991b1b' : '#374151');
        return pad + ' <span style="color:' + color + ';font-weight:600;">' + esc(val) + '</span><br>';
    }
    function esc(s) { return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
    function sec(t) { return '<strong style="display:block;margin-top:10px;color:#1d2327;font-family:sans-serif;">' + esc(t) + '</strong>'; }

    btn.addEventListener('click', function(){
        btn.disabled = true;
        spinner.style.display = 'inline-block';
        card.style.display = 'none';

        var fd = new FormData();
        fd.append('action',     'estandar_leads_meta_diagnostics');
        fd.append('_ajax_nonce','<?php echo esc_js( wp_create_nonce( 'estandar_leads_meta_diagnostics' ) ); ?>');

        fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(json){
                btn.disabled = false;
                spinner.style.display = 'none';
                card.style.display = 'block';

                if (!json.success) {
                    card.innerHTML = '<span style="color:#991b1b;font-family:sans-serif;">Error: ' + esc((json.data||{}).message||'Error desconocido.') + '</span>';
                    return;
                }
                var d   = json.data;
                var cfg = d.config  || {};
                var fmt = d.format  || {};
                var pay = d.payload || {};
                var html = '<strong style="font-size:14px;font-family:sans-serif;">Meta Diagnostics</strong>';

                html += sec('Configuración');
                html += pf('Pixel',         cfg.pixel  || '—');
                html += pf('Token',         cfg.token  || '—');

                html += sec('Formato');
                html += pf('Pixel format',  fmt.pixel  || '—');
                html += pf('Token format',  fmt.token  || '—');

                html += sec('Payload');
                html += pf('Build',            pay.build            || '—');
                html += pf('event_name',       pay.event_name       || '—');
                html += pf('event_time',       pay.event_time       || '—');
                html += pf('user_data',        pay.user_data        || '—');
                html += pf('event_source_url', pay.event_source_url || '—');

                if (d.http !== null) {
                    html += sec('Conectividad');
                    html += pf('HTTP',            String(d.http));
                    if (d.latency_ms != null)      html += pf('Latencia',         d.latency_ms + ' ms');
                    if (d.events_received != null) html += pf('events_received',  String(d.events_received));
                    if (d.fbtrace_id)              html += pf('fbtrace_id',       String(d.fbtrace_id));
                    if (d.error) {
                        html += pf('Error', esc(d.error.code) + ' — ' + esc(d.error.message));
                        if (d.error.user_title) html += pf('Detalle',    d.error.user_title);
                        if (d.error.user_msg)   html += pf('Sugerencia', d.error.user_msg);
                    }
                }

                html += sec('Estado general');
                var sc = d.status === 'READY' ? '#166534' : '#991b1b';
                var sb = d.status === 'READY' ? '#f0fdf4' : '#fef2f2';
                var si = d.status === 'READY' ? '🟢' : '🔴';
                html += '<div style="margin-top:6px;padding:6px 12px;border-radius:6px;background:' + sb + ';color:' + sc + ';font-weight:700;font-family:sans-serif;display:inline-block;">'
                    + si + ' ' + esc(d.status) + (d.reason ? ' — ' + esc(d.reason) : '') + '</div>';

                card.innerHTML = html;
            })
            .catch(function(e){
                btn.disabled = false;
                spinner.style.display = 'none';
                card.style.display = 'block';
                card.innerHTML = '<span style="color:#991b1b;font-family:sans-serif;">Error de red: ' + esc(e.message||String(e)) + '</span>';
            });
    });
})();
</script>

<!-- ── Meta Status + Visor de eventos (solo lectura — sin PII, sin tokens) ── -->
<div style="margin:8px 0 32px;max-width:860px;">
    <h3 style="font-size:14px;margin:0 0 8px;">Meta Status</h3>
    <table class="widefat striped" style="max-width:520px;">
        <tbody>
            <tr><td>Pixel configurado</td><td><?php echo $_mp_settings['pixel_id'] !== '' ? '✓' : '✗'; ?></td></tr>
            <tr><td>Token configurado</td><td><?php echo $_mp_settings['access_token'] !== '' ? '✓' : '✗'; ?></td></tr>
            <tr><td>Activado (maestro)</td><td><?php echo $_mp_enabled ? '✓' : '✗'; ?></td></tr>
            <tr><td>Browser Events</td><td><?php echo $_mp_pixel_on ? '✓ activo' : '— inactivo'; ?></td></tr>
            <tr><td>Server Events</td><td><?php echo ( $_mp_enabled && $_mp_server_ev ) ? '✓ activo' : '— inactivo'; ?></td></tr>
            <tr><td>Test Event Code</td><td><?php echo $_mp_settings['test_event_code'] !== '' ? '✓ presente (envíos van a Test Events)' : '—'; ?></td></tr>
            <tr><td>Conectividad Graph API</td><td>
                <?php if ( $_mp_last ) : ?>
                    <?php echo esc_html( $_mp_last['status'] ); ?> (HTTP <?php echo esc_html( (string) $_mp_last['http'] ); ?>, <?php echo esc_html( wp_date( 'd/m H:i', (int) $_mp_last['timestamp'] ) ); ?>)
                <?php else : ?>
                    Sin diagnóstico reciente — usar «Probar conexión»
                <?php endif; ?>
            </td></tr>
            <tr><td>Cola</td><td>
                <?php
                $_mp_qparts = array();
                foreach ( array( 'pending', 'retry', 'completed', 'dead' ) as $_mp_qs ) {
                    $_mp_qparts[] = $_mp_qs . ': ' . (int) ( $_mp_qcounts[ $_mp_qs ] ?? 0 );
                }
                echo esc_html( implode( ' · ', $_mp_qparts ) );
                ?>
            </td></tr>
            <tr><td>Último envío</td><td><?php echo $_mp_qstats['last_attempt'] ? esc_html( $_mp_qstats['last_attempt'] ) . ' UTC' : 'Sin envíos registrados'; ?></td></tr>
            <tr><td>Último error</td><td><?php echo $_mp_qstats['last_error'] ? esc_html( $_mp_qstats['last_error'] ) : '—'; ?></td></tr>
        </tbody>
    </table>

    <h3 style="font-size:14px;margin:24px 0 8px;">Últimos eventos enviados</h3>
    <?php if ( empty( $_mp_logrows ) ) : ?>
        <p style="color:#6b7280;font-size:13px;">Sin eventos registrados todavía. Los envíos aparecen aquí cuando el worker procesa la cola.</p>
    <?php else : ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Timestamp (UTC)</th><th>Evento</th><th>lead_uuid</th><th>event_id</th>
                <th>Resultado</th><th>HTTP</th><th>Retries</th><th>Duración</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $_mp_logrows as $_mp_row ) : ?>
            <tr>
                <td><?php echo esc_html( $_mp_row['created_at'] ); ?></td>
                <td><?php echo esc_html( $_mp_row['event_name'] ); ?></td>
                <td style="font-family:monospace;font-size:11px;"><?php echo esc_html( $_mp_row['lead_uuid'] ); ?></td>
                <td style="font-family:monospace;font-size:11px;"><?php echo esc_html( $_mp_row['event_id'] ); ?></td>
                <td style="font-weight:600;color:<?php echo $_mp_row['status'] === 'success' ? '#166534' : ( $_mp_row['status'] === 'retry' ? '#92400e' : '#991b1b' ); ?>;">
                    <?php echo esc_html( $_mp_row['status'] ); ?>
                </td>
                <td><?php echo esc_html( (string) $_mp_row['http_code'] ); ?></td>
                <td><?php echo esc_html( (string) $_mp_row['retry_count'] ); ?></td>
                <td><?php echo $_mp_row['duration_ms'] !== null ? esc_html( $_mp_row['duration_ms'] . ' ms' ) : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
