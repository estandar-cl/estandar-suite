<h2 style="margin-top:32px;">Context Gap Audit</h2>
    <?php if ( isset( $_GET['context_gap_saved'] ) ) : ?>
        <div class="notice notice-success inline"><p>Template de contexto guardado.</p></div>
    <?php endif; ?>
    <?php
    $context_gap_question = (string) ( $context_gap_template['question'] ?? '¿Qué información faltó?' );
    $context_gap_custom_options = array();
    foreach ( (array) ( $context_gap_template['options'] ?? array() ) as $gap_key => $gap_label ) {
        if ( in_array( (string) $gap_key, array( 'NO', 'OTHER' ), true ) ) {
            continue;
        }
        $context_gap_custom_options[] = (string) $gap_label;
    }
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:760px;margin-bottom:24px;">
        <?php wp_nonce_field('estandar_leads_save_context_gap_settings'); ?>
        <input type="hidden" name="action" value="estandar_leads_save_context_gap_settings">
        <table class="form-table">
            <tr>
                <th><label for="context_gap_question">Pregunta</label></th>
                <td>
                    <input id="context_gap_question" name="context_gap_question" class="regular-text" value="<?php echo esc_attr( $context_gap_question ); ?>">
                    <p class="description">Una sola pregunta para auditoría interna. No se muestra al visitante.</p>
                </td>
            </tr>
            <tr>
                <th><label for="context_gap_options">Respuestas</label></th>
                <td>
                    <textarea id="context_gap_options" name="context_gap_options" rows="4" class="large-text" placeholder="Comuna&#10;Financiamiento&#10;Horizonte de compra"><?php echo esc_textarea( implode( "\n", $context_gap_custom_options ) ); ?></textarea>
                    <p class="description">Máximo 3 respuestas configurables. El sistema agrega siempre “No faltó información” y “Otro”. Sin scoring ni automatización.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Guardar Context Gap', 'secondary'); ?>
    </form>

    <h2 style="margin-top:32px;">WhatsApp</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:760px;margin-bottom:24px;">
        <?php wp_nonce_field('estandar_leads_save_whatsapp_settings'); ?>
        <input type="hidden" name="action" value="estandar_leads_save_whatsapp_settings">
        <table class="form-table">
            <tr>
                <th><label for="whatsapp_prefill_message">Mensaje prellenado</label></th>
                <td>
                    <textarea id="whatsapp_prefill_message" name="whatsapp_prefill_message" rows="3" class="large-text" placeholder="Hola, quiero recibir información sobre {{project_name}}."><?php echo esc_textarea($whatsapp_prefill_message); ?></textarea>
                    <p class="description">Disponible: <code>{{project_name}}</code>, <code>{{project_slug}}</code>, <code>{{sid_ref}}</code>. Si se deja vacío, se usa el mensaje por defecto sin mostrar código.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Guardar WhatsApp', 'secondary'); ?>
    </form>

<?php
// ── Google Ads OCI Settings ───────────────────────────────────────────────────
$_gads_adapter  = new Estandar_Google_Ads_Destination();
$ads_settings   = $_gads_adapter->get_settings();
$ads_saved      = isset( $_GET['ads_saved'] );
$ads_configured = $ads_settings['customer_id'] !== ''
    && $ads_settings['developer_token'] !== ''
    && $ads_settings['client_id'] !== ''
    && $ads_settings['client_secret'] !== ''
    && $ads_settings['refresh_token'] !== ''
    && ( $ads_settings['conversion_action_id'] !== '' || $ads_settings['conversion_action_map'] !== '' );
$queue_summary  = $_gads_adapter->queue_summary();
$queue_items    = $_gads_adapter->queue_recent( 30 );
$test_mode      = $ads_settings['test_mode'] === '1';
?>

<h2 style="margin-top:40px;">Google Ads — Conversiones Offline (OCI)</h2>
<p style="color:#6b7280;margin-top:-4px;">
    Envía leads calificados de vuelta a Google Ads para optimización basada en datos reales.
    Requiere una cuenta de Google Cloud y un Developer Token.
    <a href="https://developers.google.com/google-ads/api/docs/get-started/oauth-cloud-project" target="_blank" rel="noopener">
        Documentación →
    </a>
</p>

<?php if ( $ads_saved ) : ?>
    <div class="notice notice-success is-dismissible"><p>Configuración de Google Ads guardada.</p></div>
<?php endif; ?>

<?php /* Status bar */ ?>
<div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding:10px 14px;border-radius:4px;
    background:<?php echo $ads_configured ? '#f0fdf4' : '#fef2f2'; ?>;
    border:1px solid <?php echo $ads_configured ? '#86efac' : '#fca5a5'; ?>;">
    <span style="font-size:13px;font-weight:600;color:<?php echo $ads_configured ? '#166534' : '#991b1b'; ?>;">
        <?php echo $ads_configured ? '✓ Configurado' : '✗ Sin configurar — OCI inactivo'; ?>
    </span>
    <?php if ( $test_mode ) : ?>
        <span style="font-size:12px;font-weight:600;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:3px;">
            MODO TEST — conversiones no se envían a Google
        </span>
    <?php endif; ?>
    <?php if ( $queue_summary['total'] > 0 ) : ?>
        <span style="font-size:12px;color:#6b7280;">
            Cola: <?php echo (int) $queue_summary['pending']; ?> pendientes &nbsp;·&nbsp;
            <?php echo (int) $queue_summary['success']; ?> enviados &nbsp;·&nbsp;
            <?php if ( $queue_summary['failed'] > 0 ) : ?>
                <strong style="color:#d97706;"><?php echo (int) $queue_summary['failed']; ?> fallidos</strong> &nbsp;·&nbsp;
            <?php endif; ?>
            <?php if ( $queue_summary['dead'] > 0 ) : ?>
                <strong style="color:#dc2626;"><?php echo (int) $queue_summary['dead']; ?> muertos</strong>
            <?php endif; ?>
        </span>
    <?php endif; ?>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'estandar_leads_save_ads_settings' ); ?>
    <input type="hidden" name="action" value="estandar_leads_save_ads_settings">
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="gads_customer_id">Customer ID</label></th>
            <td>
                <input class="regular-text" id="gads_customer_id" name="gads_customer_id"
                    placeholder="1234567890"
                    value="<?php echo esc_attr( $ads_settings['customer_id'] ); ?>">
                <p class="description">ID numérico sin guiones. Visible en la URL de Google Ads.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="gads_conversion_action_id">Conversion Action ID</label></th>
            <td>
                <input class="regular-text" id="gads_conversion_action_id" name="gads_conversion_action_id"
                    placeholder="1234567890"
                    value="<?php echo esc_attr( $ads_settings['conversion_action_id'] ); ?>">
                <p class="description">ID numérico. Visible en la URL al editar la conversión en Google Ads → Objetivos.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="gads_conversion_action_map">Mapping proyecto/status</label></th>
            <td>
                <textarea class="large-text code" rows="5" id="gads_conversion_action_map" name="gads_conversion_action_map"
                    placeholder="project_uuid|QUALIFIED|1234567890&#10;project_uuid|RESERVED|2345678901&#10;QUALIFIED|1234567890"><?php echo esc_textarea( $ads_settings['conversion_action_map'] ); ?></textarea>
                <p class="description">Una regla por línea. Formato: project_uuid|STATUS|conversion_action_id. También permite STATUS|conversion_action_id como fallback.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="gads_conversion_value_map">Conversion value</label></th>
            <td>
                <textarea class="large-text code" rows="4" id="gads_conversion_value_map" name="gads_conversion_value_map"
                    placeholder="QUALIFIED|1&#10;VISIT_SCHEDULED|5&#10;RESERVED|50"><?php echo esc_textarea( $ads_settings['conversion_value_map'] ); ?></textarea>
                <p class="description">Formato: STATUS|valor o project_uuid|STATUS|valor. Si queda vacío, Google usará el valor configurado en la conversión.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="gads_currency_code">Moneda</label></th>
            <td>
                <input class="small-text" id="gads_currency_code" name="gads_currency_code"
                    maxlength="3" placeholder="CLP"
                    value="<?php echo esc_attr( $ads_settings['currency_code'] ); ?>">
                <p class="description">Requerida solo si se envía conversion value.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="gads_developer_token">Developer Token</label></th>
            <td>
                <input class="regular-text" id="gads_developer_token" name="gads_developer_token"
                    type="password" autocomplete="off"
                    value="<?php echo esc_attr( $ads_settings['developer_token'] ); ?>">
                <p class="description">Desde Google Ads → Herramientas → API Center.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="gads_client_id">Client ID</label></th>
            <td>
                <input class="regular-text" id="gads_client_id" name="gads_client_id"
                    value="<?php echo esc_attr( $ads_settings['client_id'] ); ?>">
                <p class="description">OAuth2 Client ID desde Google Cloud Console → Credenciales.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="gads_client_secret">Client Secret</label></th>
            <td>
                <input class="regular-text" id="gads_client_secret" name="gads_client_secret"
                    type="password" autocomplete="off"
                    value="<?php echo esc_attr( $ads_settings['client_secret'] ); ?>">
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="gads_refresh_token">Refresh Token</label></th>
            <td>
                <input class="large-text" id="gads_refresh_token" name="gads_refresh_token"
                    type="password" autocomplete="off"
                    value="<?php echo esc_attr( $ads_settings['refresh_token'] ); ?>">
                <p class="description">Token OAuth2 de larga duración. Se genera una vez con la herramienta de autorización.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Modo test</th>
            <td>
                <label>
                    <input type="checkbox" name="gads_test_mode" value="1"
                        <?php checked( $test_mode ); ?>>
                    Activar modo test — encola y loguea conversiones sin enviarlas a Google
                </label>
                <p class="description">Usar para validar el flujo completo antes de activar en producción.</p>
            </td>
        </tr>
    </table>
    <?php submit_button( 'Guardar configuración de Google Ads' ); ?>
</form>

<?php if ( ! empty( $queue_items ) ) : ?>
    <h3 style="margin-top:30px;">Cola OCI — últimos 30 eventos</h3>
    <table class="widefat striped" style="font-size:12px;">
        <thead>
            <tr>
                <th>#</th>
                <th>Lead ID</th>
                <th>Platform</th>
                <th>Project UUID</th>
                <th>Status</th>
                <th>Event ID</th>
                <th>Click ID</th>
                <th>Cola</th>
                <th>Intentos</th>
                <th>Sent at</th>
                <th>Último intento</th>
                <th>Respuesta/Error</th>
                <th>Queued</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $queue_items as $qi ) :
            $badge_colors = array(
                'pending' => array( 'bg' => '#e0f2fe', 'text' => '#0369a1' ),
                'success' => array( 'bg' => '#dcfce7', 'text' => '#166534' ),
                'failed'  => array( 'bg' => '#fef3c7', 'text' => '#92400e' ),
                'dead'    => array( 'bg' => '#fee2e2', 'text' => '#991b1b' ),
            );
            $bc = $badge_colors[ $qi->queue_status ] ?? array( 'bg' => '#f3f4f6', 'text' => '#374151' );
        ?>
            <tr>
                <td><?php echo (int) $qi->id; ?></td>
                <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-leads&view=detail&lead_id=' . absint( $qi->lead_id ) ) ); ?>"><?php echo (int) $qi->lead_id; ?></a></td>
                <td><?php echo esc_html( $qi->platform ?? 'google' ); ?></td>
                <td><code><?php echo esc_html( $qi->project_uuid ?: '—' ); ?></code></td>
                <td><code><?php echo esc_html( $qi->conversion_status ); ?></code></td>
                <td><code><?php echo esc_html( $qi->event_id ?? '—' ); ?></code></td>
                <td><?php echo esc_html( $qi->click_id_type ?? 'gclid' ); ?></td>
                <td>
                    <span style="background:<?php echo esc_attr( $bc['bg'] ); ?>;color:<?php echo esc_attr( $bc['text'] ); ?>;padding:1px 6px;border-radius:3px;font-weight:600;">
                        <?php echo esc_html( $qi->queue_status ); ?>
                    </span>
                </td>
                <td><?php echo (int) $qi->attempts; ?>/<?php echo Estandar_Google_Ads_Destination::MAX_ATTEMPTS; ?></td>
                <td><?php echo ! empty( $qi->sent_at ) ? esc_html( $qi->sent_at ) : '—'; ?></td>
                <td><?php echo $qi->last_attempted_at ? esc_html( $qi->last_attempted_at ) : '—'; ?></td>
                <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php echo $qi->last_error ? '<span title="' . esc_attr( $qi->last_error ) . '">' . esc_html( substr( $qi->last_error, 0, 60 ) ) . ( strlen( $qi->last_error ) > 60 ? '…' : '' ) . '</span>' : '—'; ?>
                </td>
                <td><?php echo esc_html( $qi->queued_at ?? $qi->created_at ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     META CONVERSIONS API
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php
$meta_settings    = class_exists( 'Estandar_Leads_Meta_Adapter' ) ? Estandar_Leads_Meta_Adapter::get_settings() : array( 'pixel_id' => '', 'access_token' => '', 'test_event_code' => '', 'test_mode' => '0', 'enabled' => '', 'browser_events' => '', 'server_events' => '', 'debug_logging' => '' );
$meta_saved       = isset( $_GET['meta_saved'] );
$meta_configured  = $meta_settings['pixel_id'] !== '' && $meta_settings['access_token'] !== '';
$meta_test_mode   = $meta_settings['test_mode'] === '1';
// Backward-compatible defaults: '' (never saved) = enabled/server ON, browser/debug OFF.
$meta_enabled     = $meta_settings['enabled'] !== '0';
$meta_server_ev   = $meta_settings['server_events'] !== '0';
$meta_browser_ev  = $meta_settings['browser_events'] === '1';
$meta_debug_log   = $meta_settings['debug_logging'] === '1';
// Con core presente, la configuración Meta vive en Proyectos → Integraciones
// (Integration Registry). Este bloque queda solo como fallback standalone.
$meta_registry_on = class_exists( 'Estandar_Integration_Registry' );
?>

<h2 style="margin-top:40px;">Meta — Conversions API (CAPI)</h2>
<?php if ( $meta_registry_on ) : ?>
<p style="color:#6b7280;margin-top:-4px;">
    La configuración, el diagnóstico y el visor de eventos de Meta se administran en
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar_projects_integrations&provider=meta' ) ); ?>"><strong>Proyectos → Integraciones</strong></a>.
</p>
<?php else : ?>
<p style="color:#6b7280;margin-top:-4px;">
    Envía eventos de conversión al servidor de Meta para optimización de campañas.
    Requiere un Pixel ID y un Access Token de Meta Business Manager.
</p>

<?php if ( $meta_saved ) : ?>
    <div class="notice notice-success is-dismissible"><p>Configuración de Meta Conversions API guardada.</p></div>
<?php endif; ?>

<div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding:10px 14px;border-radius:4px;
    background:<?php echo $meta_configured ? '#f0fdf4' : '#fef2f2'; ?>;
    border:1px solid <?php echo $meta_configured ? '#86efac' : '#fca5a5'; ?>;">
    <span style="font-size:13px;font-weight:600;color:<?php echo $meta_configured ? '#166534' : '#991b1b'; ?>;">
        <?php echo $meta_configured ? '✓ Configurado' : '✗ Sin configurar — CAPI inactivo'; ?>
    </span>
    <?php if ( ! $meta_enabled ) : ?>
        <span style="font-size:12px;font-weight:600;background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:3px;">
            DESACTIVADO — ni Browser ni Server Events
        </span>
    <?php endif; ?>
    <?php if ( $meta_test_mode ) : ?>
        <span style="font-size:12px;font-weight:600;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:3px;">
            MODO TEST — conversiones no se envían a Meta
        </span>
    <?php endif; ?>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'estandar_leads_save_meta_settings' ); ?>
    <input type="hidden" name="action" value="estandar_leads_save_meta_settings">
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row">Activar Meta</th>
            <td>
                <label>
                    <input type="checkbox" name="meta_enabled" value="1" <?php checked( $meta_enabled ); ?>>
                    Interruptor maestro — apaga Browser y Server Events sin borrar la configuración
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="meta_pixel_id">Pixel ID</label></th>
            <td>
                <input class="regular-text" id="meta_pixel_id" name="meta_pixel_id"
                    placeholder="123456789012345"
                    value="<?php echo esc_attr( $meta_settings['pixel_id'] ); ?>">
                <p class="description">Dataset ID de Meta Events Manager. Visible en la URL al editar el Pixel.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="meta_access_token">Access Token</label></th>
            <td>
                <input class="large-text" id="meta_access_token" name="meta_access_token"
                    type="password" autocomplete="off" value=""
                    placeholder="<?php echo $meta_settings['access_token'] !== '' ? '•••••••• (token guardado)' : ''; ?>">
                <p class="description">
                    <?php echo $meta_settings['access_token'] !== ''
                        ? 'Hay un token guardado. Dejar vacío para <strong>conservarlo</strong>; pegar uno nuevo para rotarlo.'
                        : 'Token de acceso del sistema desde Meta Business Manager → Configuración del sistema.'; ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="meta_test_event_code">Test Event Code</label></th>
            <td>
                <input class="regular-text" id="meta_test_event_code" name="meta_test_event_code"
                    placeholder="TEST12345"
                    value="<?php echo esc_attr( $meta_settings['test_event_code'] ); ?>">
                <p class="description">Opcional. Código de prueba desde Meta Events Manager → Test Events.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Browser Events</th>
            <td>
                <label>
                    <input type="checkbox" name="meta_browser_events" value="1" <?php checked( $meta_browser_ev ); ?>>
                    Imprimir el Pixel en el sitio — <code>fbq('init')</code>, PageView, ViewContent (Lead lo dispara la captura de formularios)
                </label>
                <p class="description">
                    ⚠ Activar solo DESPUÉS de desactivar los snippets <code>pixel_acacios</code> / pixel de Facebook —
                    ambos activos a la vez duplican PageView. Desactivado, el plugin nunca imprime el Pixel.
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">Server Events</th>
            <td>
                <label>
                    <input type="checkbox" name="meta_server_events" value="1" <?php checked( $meta_server_ev ); ?>>
                    Enviar eventos comerciales vía Conversions API (cola + retries)
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row">Debug Logging</th>
            <td>
                <label>
                    <input type="checkbox" name="meta_debug_logging" value="1" <?php checked( $meta_debug_log ); ?>>
                    Registrar cada envío en Telemetría (event_id, HTTP, duración — sin PII ni tokens)
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row">Modo test</th>
            <td>
                <label>
                    <input type="checkbox" name="meta_test_mode" value="1"
                        <?php checked( $meta_test_mode ); ?>>
                    Activar modo test — encola y loguea conversiones sin enviarlas a Meta
                </label>
                <p class="description">Usar para validar el flujo completo antes de activar en producción. La cola queda en <code>pending</code> y se despacha al desactivar el modo test.</p>
            </td>
        </tr>
    </table>
    <?php submit_button( 'Guardar configuración de Meta' ); ?>
</form>

<?php
// Diagnostics + Meta Status + visor de eventos: partial compartido con el
// proveedor del Integration Registry (fuente única de este markup).
$_meta_panel = ESTANDAR_LEADS_PATH . 'templates/admin/meta-integration-panel.php';
if ( file_exists( $_meta_panel ) ) {
    include $_meta_panel;
}
unset( $_meta_panel );
?>
<?php endif; // fin fallback standalone (sin Integration Registry) ?>

<?php
// LEAD_ASSESSMENT_V1 — Lead Assessment settings section (partial).
$_fit_assessment = ESTANDAR_LEADS_PATH . 'templates/admin/fit-assessment-settings.php';
if ( file_exists( $_fit_assessment ) ) {
    include $_fit_assessment;
}
unset( $_fit_assessment );
?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     COMPLIANCE ENGINE
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php
$compliance_settings = class_exists( 'Estandar_Leads_Compliance' ) ? Estandar_Leads_Compliance::get_settings() : array( 'enable_compliance_engine' => 0, 'feedback_due_hours' => 48, 'supervisor_email' => '', 'compliance_cc_email' => '' );
$compliance_enabled  = ! empty( $compliance_settings['enable_compliance_engine'] );
$compliance_saved    = isset( $_GET['saved'] ) && isset( $_GET['tab'] ) && sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) === 'compliance';

if ( $compliance_saved ) {
    echo '<div class="notice notice-success is-dismissible"><p>Configuración del motor de compliance guardada.</p></div>';
}

$compliance_stats = class_exists( 'Estandar_Leads_Compliance' ) ? Estandar_Leads_Compliance::stats() : array();
$run_log          = class_exists( 'Estandar_Leads_Compliance' ) ? Estandar_Leads_Compliance::last_run_info() : array();
?>

<section id="compliance-engine" style="scroll-margin-top:48px;">
<hr style="margin:40px 0 32px;">

<h2 style="font-size:16px;font-weight:700;color:#111827;margin:0 0 4px;">Motor de Compliance de Feedback</h2>
<p style="margin:0 0 20px;font-size:13px;color:#6b7280;">
    Persigue el feedback comercial faltante mediante notificaciones automáticas escalonadas.<br>
    No afecta a WhatsApp, OCI, Analytics ni Chatbot.
</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px;">
    <?php wp_nonce_field( 'estandar_leads_compliance_settings' ); ?>
    <input type="hidden" name="action" value="estandar_leads_save_compliance_settings">

    <div style="border:1px solid <?php echo $compliance_enabled ? '#86efac' : '#fca5a5'; ?>;background:<?php echo $compliance_enabled ? '#f0fdf4' : '#fef2f2'; ?>;border-radius:6px;padding:16px 20px;margin-bottom:20px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
            <div>
                <strong style="font-size:13px;color:#111827;">Estado:</strong>
                <span style="display:inline-block;margin-left:6px;padding:3px 9px;border-radius:99px;background:<?php echo $compliance_enabled ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo $compliance_enabled ? '#166534' : '#991b1b'; ?>;font-size:12px;font-weight:700;">
                    <?php echo $compliance_enabled ? '🟢 Activo' : '🔴 Inactivo'; ?>
                </span>
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="enable_compliance_engine" name="enable_compliance_engine" value="1" <?php checked( $compliance_enabled ); ?>>
                <span style="font-size:13px;font-weight:600;color:#374151;">Activar Compliance Engine</span>
            </label>
        </div>
        <?php if ( ! $compliance_enabled ) : ?>
            <p style="margin:10px 0 0;color:#991b1b;font-size:13px;">Los recordatorios automáticos, escalaciones y digest no se ejecutarán.</p>
        <?php endif; ?>
        <p class="description" style="margin:8px 0 0;">Requiere que WP-Cron esté activo. El job se ejecuta diariamente.</p>
    </div>

<!-- Last run log panel -->
<?php if ( ! empty( $run_log['last_cron_run_at'] ) || ! empty( $run_log['last_bootstrap_run_at'] ) ) : ?>
<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:14px 20px;margin-bottom:20px;max-width:640px;">
    <p style="margin:0 0 8px;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;">Historial de ejecuciones</p>
    <table role="presentation" style="width:100%;border-collapse:collapse;font-size:12px;">
        <?php if ( ! empty( $run_log['last_cron_run_at'] ) ) : ?>
        <tr>
            <td style="color:#6b7280;padding:3px 16px 3px 0;white-space:nowrap;">Último cron diario</td>
            <td style="color:#111827;font-weight:600;"><?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $run_log['last_cron_run_at'] ) ); ?></td>
            <td style="color:#6b7280;padding-left:16px;">
                <?php
                $cr = $run_log['last_cron_result'] ?? array();
                printf( 'Correos: %d · Leads: %d · Digests: %d',
                    (int) ( $cr['emails_sent'] ?? 0 ),
                    (int) ( $cr['leads_covered'] ?? 0 ),
                    (int) ( $cr['digests_sent'] ?? 0 )
                );
                ?>
            </td>
        </tr>
        <?php endif; ?>
        <?php if ( ! empty( $run_log['last_bootstrap_run_at'] ) ) : ?>
        <tr>
            <td style="color:#6b7280;padding:3px 16px 3px 0;white-space:nowrap;">Último bootstrap</td>
            <td style="color:#111827;font-weight:600;"><?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $run_log['last_bootstrap_run_at'] ) ); ?></td>
            <td style="color:#6b7280;padding-left:16px;">
                <?php
                $br = $run_log['last_bootstrap_result'] ?? array();
                $dr = ! empty( $br['dry_run'] );
                printf( '%sCorreos: %d · Leads: %d',
                    $dr ? '[Simulación] ' : '',
                    (int) ( $br['emails_sent'] ?? 0 ),
                    (int) ( $br['leads_covered'] ?? 0 )
                );
                ?>
            </td>
        </tr>
        <?php endif; ?>
        <?php if ( ! empty( $run_log['last_error'] ) ) : ?>
        <tr>
            <td style="color:#d63638;padding:3px 16px 3px 0;white-space:nowrap;">Último error</td>
            <td colspan="2" style="color:#d63638;"><?php echo esc_html( $run_log['last_error'] ); ?></td>
        </tr>
        <?php endif; ?>
    </table>
</div>
<?php endif; ?>

<?php if ( ! empty( $compliance_stats ) ) : ?>
<div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;">
    <?php
    $stat_blocks = array(
        array( 'label' => 'Pendientes',   'value' => $compliance_stats['pending'] ?? 0,             'color' => '#dba617' ),
        array( 'label' => 'Vencidos',     'value' => $compliance_stats['overdue'] ?? 0,             'color' => '#d63638' ),
        array( 'label' => 'Escalados',    'value' => $compliance_stats['escalated'] ?? 0,           'color' => '#9b1c1c' ),
        array( 'label' => 'Completados',  'value' => $compliance_stats['received'] ?? 0,            'color' => '#00a32a' ),
        array( 'label' => 'Notificaciones', 'value' => $compliance_stats['total_notifications'] ?? 0, 'color' => '#1a73e8' ),
    );
    foreach ( $stat_blocks as $sb ) :
    ?>
    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:14px 20px;min-width:120px;text-align:center;">
        <div style="font-size:24px;font-weight:700;color:<?php echo esc_attr( $sb['color'] ); ?>;"><?php echo (int) $sb['value']; ?></div>
        <div style="font-size:11px;color:#6b7280;margin-top:2px;"><?php echo esc_html( $sb['label'] ); ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

    <h3 style="font-size:13px;font-weight:700;color:#374151;margin:24px 0 4px;">Configuración avanzada</h3>
    <table class="form-table" role="presentation" style="margin-bottom:24px;">
        <tbody>

            <tr>
                <th scope="row" style="padding:12px 0;">
                    <label for="supervisor_email" style="font-size:13px;font-weight:600;color:#374151;">Email del supervisor</label>
                </th>
                <td style="padding:12px 0;">
                    <input type="email" id="supervisor_email" name="supervisor_email"
                        value="<?php echo esc_attr( $compliance_settings['supervisor_email'] ?? '' ); ?>"
                        style="width:340px;" class="regular-text" placeholder="supervisor@empresa.cl">
                    <p class="description" style="margin-top:4px;">Recibe CC en nivel 96 h y correos directos en 7 d, 14 d y semanal.</p>
                </td>
            </tr>

            <tr>
                <th scope="row" style="padding:12px 0;">
                    <label for="compliance_cc_email" style="font-size:13px;font-weight:600;color:#374151;">Email compliance CC</label>
                </th>
                <td style="padding:12px 0;">
                    <input type="email" id="compliance_cc_email" name="compliance_cc_email"
                        value="<?php echo esc_attr( $compliance_settings['compliance_cc_email'] ?? '' ); ?>"
                        style="width:340px;" class="regular-text" placeholder="cumplimiento@empresa.cl">
                    <p class="description" style="margin-top:4px;">Recibe copia en escalamientos de 14 d y semanales.</p>
                </td>
            </tr>

        </tbody>
    </table>

    <!-- Escalation reference -->
    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:16px 20px;margin-bottom:24px;">
        <p style="margin:0 0 10px;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;">Escalamiento automático</p>
        <table role="presentation" style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="text-align:left;font-size:11px;color:#9ca3af;font-weight:600;padding:4px 8px 4px 0;">Nivel</th>
                    <th style="text-align:left;font-size:11px;color:#9ca3af;font-weight:600;padding:4px 8px;">Para</th>
                    <th style="text-align:left;font-size:11px;color:#9ca3af;font-weight:600;padding:4px 0;">CC</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $esc_table = array(
                    array( '48 h',    '#dba617', 'Operador',   '—' ),
                    array( '96 h',    '#c05621', 'Operador',   'Supervisor' ),
                    array( '7 días',  '#d63638', 'Supervisor', 'Operador' ),
                    array( '14 días', '#9b1c1c', 'Supervisor', 'Operador + Compliance CC' ),
                    array( 'Semanal', '#6b21a8', 'Supervisor', 'Operador + Compliance CC' ),
                );
                foreach ( $esc_table as $et ) :
                ?>
                <tr>
                    <td style="padding:5px 8px 5px 0;">
                        <span style="display:inline-block;background:<?php echo esc_attr( $et[1] ); ?>;color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:3px;"><?php echo esc_html( $et[0] ); ?></span>
                    </td>
                    <td style="font-size:12px;color:#374151;padding:5px 8px;"><?php echo esc_html( $et[2] ); ?></td>
                    <td style="font-size:12px;color:#6b7280;padding:5px 0;"><?php echo esc_html( $et[3] ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php submit_button( 'Guardar configuración de compliance', 'primary', 'submit', false ); ?>
</form>
</section>

<!-- ── Recovery Layer (LOST + sin contacto) ───────────────────────────────── -->
<?php
$recovery_settings = class_exists( 'Estandar_Leads_Recovery' ) ? Estandar_Leads_Recovery::get_settings() : array();
?>
<section style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin:16px 0;">
    <h2 style="font-size:16px;font-weight:700;color:#111827;margin:0 0 4px;">Recovery Layer</h2>
    <p class="description" style="margin:0 0 16px;">Recuperación de leads marcados como perdidos por falta de contacto. La solicitud de segundo intento viaja en el digest operacional (no genera correos individuales).</p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'estandar_leads_recovery_settings' ); ?>
        <input type="hidden" name="action" value="estandar_leads_save_recovery_settings">
        <table class="form-table" role="presentation"><tbody>
            <tr><th scope="row">Recovery habilitado</th><td>
                <label><input type="checkbox" name="recovery_enabled" value="1" <?php checked( ! empty( $recovery_settings['recovery_enabled'] ) ); ?>> Activar la capa de recuperación</label>
            </td></tr>
            <tr><th scope="row">Delay (horas)</th><td>
                <input type="number" min="1" name="recovery_delay_hours" value="<?php echo esc_attr( $recovery_settings['recovery_delay_hours'] ?? 24 ); ?>" class="small-text"> horas tras el cierre antes de actuar
            </td></tr>
            <tr><th scope="row">Email de recuperación al lead</th><td>
                <label><input type="checkbox" name="recovery_email_enabled" value="1" <?php checked( ! empty( $recovery_settings['recovery_email_enabled'] ) ); ?>> Enviar correo comercial al prospecto</label>
            </td></tr>
            <tr><th scope="row">Recordatorio de segundo intento</th><td>
                <label><input type="checkbox" name="second_attempt_reminder_enabled" value="1" <?php checked( ! empty( $recovery_settings['second_attempt_reminder_enabled'] ) ); ?>> Registrar solicitud de segundo intento</label>
            </td></tr>
            <tr><th scope="row">Umbral de alto interés</th><td>
                <input type="number" min="1" name="high_interest_open_threshold" value="<?php echo esc_attr( $recovery_settings['high_interest_open_threshold'] ?? 3 ); ?>" class="small-text"> aperturas de email mínimas para marcar interés alto
            </td></tr>
            <tr><th scope="row">Incluir en digest</th><td>
                <label><input type="checkbox" name="include_recovery_in_digest" value="1" <?php checked( ! empty( $recovery_settings['include_recovery_in_digest'] ) ); ?>> Mostrar "Leads Recuperables" en el resumen operacional</label>
            </td></tr>
            <tr><th scope="row">Correos individuales (standalone)</th><td>
                <label><input type="checkbox" name="send_standalone_recovery_emails" value="1" <?php checked( ! empty( $recovery_settings['send_standalone_recovery_emails'] ) ); ?>> Enviar correo individual al operador (aumenta ruido; off por defecto)</label>
            </td></tr>
        </tbody></table>
        <?php submit_button( 'Guardar configuración de recovery', 'primary', 'submit_recovery', false ); ?>
    </form>
</section>

<!-- ── Bootstrap Retroactivo ──────────────────────────────────────────────── -->
<?php
$bootstrap_done   = isset( $_GET['bootstrap_done'] ) && (int) sanitize_key( wp_unslash( $_GET['bootstrap_done'] ) ) === 1;
$bootstrap_error  = isset( $_GET['bootstrap_error'] ) && (int) sanitize_key( wp_unslash( $_GET['bootstrap_error'] ) ) === 1;
$bootstrap_result = $bootstrap_done ? get_transient( 'estandar_leads_bootstrap_result' ) : null;
if ( $bootstrap_done ) {
    delete_transient( 'estandar_leads_bootstrap_result' );
}
$bootstrap_status = class_exists( 'Estandar_Leads_Compliance' ) ? Estandar_Leads_Compliance::bootstrap_status() : null;
$bootstrap_completed = $bootstrap_status && empty( $bootstrap_status['result']['dry_run'] );
?>

<?php if ( ! $bootstrap_completed ) : ?>
<div style="margin-top:32px;padding:24px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;max-width:640px;">

    <h3 style="margin:0 0 6px;font-size:14px;font-weight:700;color:#111827;">Bootstrap Retroactivo</h3>
    <p style="margin:0 0 16px;font-size:13px;color:#6b7280;line-height:1.5;">
        Detecta todos los leads existentes sin feedback y envía recordatorios agrupados por operador.<br>
        Reglas: máx. 1 correo por operador por día · &gt;3 pendientes → digest agrupado · se marca como retroactivo.
    </p>

    <?php if ( $bootstrap_error ) : ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:5px;padding:10px 14px;margin-bottom:16px;">
        <p style="margin:0;font-size:13px;color:#991b1b;">Error al ejecutar el bootstrap. Verifica que el módulo esté activo.</p>
    </div>
    <?php endif; ?>

    <?php if ( $bootstrap_done && is_array( $bootstrap_result ) ) : ?>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:5px;padding:12px 16px;margin-bottom:16px;">
        <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#166534;">✓ Bootstrap ejecutado correctamente.</p>
        <table role="presentation" style="font-size:12px;color:#374151;border-collapse:collapse;">
            <tr><td style="padding:2px 12px 2px 0;">Operadores procesados</td><td style="font-weight:700;"><?php echo (int) ( $bootstrap_result['processed_operators'] ?? 0 ); ?></td></tr>
            <tr><td style="padding:2px 12px 2px 0;">Correos enviados</td><td style="font-weight:700;"><?php echo (int) ( $bootstrap_result['emails_sent'] ?? 0 ); ?></td></tr>
            <tr><td style="padding:2px 12px 2px 0;">Leads cubiertos</td><td style="font-weight:700;"><?php echo (int) ( $bootstrap_result['leads_covered'] ?? 0 ); ?></td></tr>
            <tr><td style="padding:2px 12px 2px 0;">Omitidos (límite diario)</td><td style="color:#6b7280;"><?php echo (int) ( $bootstrap_result['skipped_rate_limit'] ?? 0 ); ?></td></tr>
            <tr><td style="padding:2px 12px 2px 0;">Omitidos (umbral 3+)</td><td style="color:#6b7280;"><?php echo (int) ( $bootstrap_result['skipped_threshold'] ?? 0 ); ?></td></tr>
        </table>
    </div>
    <?php endif; ?>

    <?php if ( $bootstrap_status ) : ?>
    <p style="margin:0 0 16px;font-size:12px;color:#9ca3af;">
        Último run: <?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $bootstrap_status['last_run_at'] ) ); ?>
        · Operadores: <?php echo (int) ( $bootstrap_status['result']['processed_operators'] ?? 0 ); ?>
        · Correos: <?php echo (int) ( $bootstrap_status['result']['emails_sent'] ?? 0 ); ?>
        · Leads: <?php echo (int) ( $bootstrap_status['result']['leads_covered'] ?? 0 ); ?>
    </p>
    <?php endif; ?>

    <!-- Tier reference -->
    <div style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;">
        <?php
        $tier_ref = array(
            array( '0–30 días',  '#dba617', 'Recordatorio individual o digest' ),
            array( '31–90 días', '#d63638', 'Recordatorio individual o digest' ),
            array( '+90 días',   '#9b1c1c', 'Digest crítico' ),
        );
        foreach ( $tier_ref as $tr ) :
        ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:5px;padding:8px 12px;">
            <span style="background:<?php echo esc_attr( $tr[1] ); ?>;color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:3px;"><?php echo esc_html( $tr[0] ); ?></span>
            <span style="font-size:11px;color:#6b7280;margin-left:6px;"><?php echo esc_html( $tr[2] ); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">

        <!-- Dry-run: count only, no emails, no DB writes -->
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
            <?php wp_nonce_field( 'estandar_leads_run_bootstrap' ); ?>
            <input type="hidden" name="action" value="estandar_leads_run_bootstrap">
            <input type="hidden" name="dry_run" value="1">
            <input type="submit" class="button button-secondary"
                   value="Simular (Dry Run)"
                   title="Cuenta operadores, leads y correos que se enviarían, sin enviar nada ni registrar nada.">
        </form>

        <!-- Live run: sends emails and records notifications -->
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
            <?php wp_nonce_field( 'estandar_leads_run_bootstrap' ); ?>
            <input type="hidden" name="action" value="estandar_leads_run_bootstrap">
            <input type="submit" class="button button-primary"
                   value="Ejecutar Bootstrap Retroactivo"
                   onclick="return confirm('¿Confirmas la ejecución? Se enviarán correos reales a los operadores con leads pendientes.');">
        </form>

    </div>
    <p class="description" style="margin-top:8px;">
        <strong>Simular</strong> primero para ver el alcance antes de ejecutar en producción.
    </p>

</div>
<?php endif; ?>

<?php
// ── Clasificación comercial (reglas de calificación) — aditiva, no destructiva ──
$_rules_config = class_exists('Estandar_Commercial_Qualification_Rules')
    ? get_option(Estandar_Commercial_Qualification_Rules::OPTION, array())
    : array();
$_rules_json  = ('' === wp_json_encode($_rules_config)) ? '' : wp_json_encode($_rules_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$rules_saved  = isset($_GET['rules_saved']);
$rules_reset  = isset($_GET['rules_reset']);
$rules_error  = isset($_GET['rules_error']);
?>
<section id="qualification-rules" style="scroll-margin-top:48px;">
<hr style="margin:40px 0 32px;">
<h2 style="font-size:16px;font-weight:700;color:#111827;margin:0 0 4px;">Clasificación comercial (reglas de calificación)</h2>
<p style="margin:0 0 20px;font-size:13px;color:#6b7280;">
    Reglas deterministas H/L para clasificar el valor comercial de leads nuevos
    (formato de contrato canónico compartido: legacy estandar-leads ↔ Suite v2
    estandar-brain/Rules). No borra ni modifica leads, entries, conversaciones ni
    configuraciones; solo clasifica leads entrantes.
</p>
<?php if ($rules_saved) : ?><div class="notice notice-success is-dismissible"><p>Reglas de clasificación guardadas y normalizadas.</p></div><?php endif; ?>
<?php if ($rules_reset) : ?><div class="notice notice-warning is-dismissible"><p>Reglas de clasificación eliminadas (se restaura la decisión por defecto).</p></div><?php endif; ?>
<?php if ($rules_error) : ?><div class="notice notice-error is-dismissible"><p>JSON inválido: no se guardaron cambios. Revisa el formato del payload.</p></div><?php endif; ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <?php wp_nonce_field('estandar_leads_save_qualification_rules'); ?>
    <input type="hidden" name="action" value="estandar_leads_save_qualification_rules">
    <textarea name="rules_json" rows="14" class="large-text code" style="font-family:monospace;"><?php echo esc_textarea($_rules_json); ?></textarea>
    <p class="description">Pega el payload canónico (buckets <code>global</code>/<code>project</code>/<code>manual</code>). Se valida y normaliza antes de guardar. Guardar no afecta datos existentes.</p>
    <input type="submit" class="button button-primary" value="Guardar reglas de clasificación">
</form>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
    <?php wp_nonce_field('estandar_leads_reset_qualification_rules'); ?>
    <input type="hidden" name="action" value="estandar_leads_reset_qualification_rules">
    <input type="submit" class="button button-secondary" value="Restaurar por defecto (eliminar reglas)"
           onclick="return confirm('¿Eliminar las reglas de clasificación? Se restaura la decisión por defecto. No se tocan datos.');">
</form>
</section>
