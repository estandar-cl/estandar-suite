<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * GF Import admin screen — rendered as a sub-page of Estandar Forms.
 *
 * @since 13.0.156
 */

function estandar_forms_gf_import_page(): void {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No autorizado');
    }

    $report = null;
    $result = null;
    $step   = isset( $_POST['gf_import_step'] ) ? sanitize_key( wp_unslash( $_POST['gf_import_step'] ) ) : '';

    if ( $step === 'analyze' && ! empty( $_FILES['gf_json_file']['tmp_name'] ) ) {
        check_admin_referer('estandar_gf_import');
        $raw = file_get_contents( (string) $_FILES['gf_json_file']['tmp_name'] );
        $importer = new Estandar_Forms_GF_Importer( $raw );
        $report   = $importer->analyze();

        // Store for next step
        if ( $report['can_import'] && $raw !== false ) {
            set_transient( 'estandar_gf_import_pending', wp_json_encode( [
                'json'  => $raw,
                'title' => $report['source_title'],
            ] ), 1800 );
        }
    }

    if ( $step === 'import' ) {
        check_admin_referer('estandar_gf_import');
        $pending = get_transient( 'estandar_gf_import_pending' );
        $pending = $pending ? json_decode( $pending, true ) : null;

        if ( $pending && ! empty( $pending['json'] ) ) {
            $form_type = sanitize_key( (string) ( $_POST['gf_form_type'] ?? 'lead' ) );
            $notify    = sanitize_textarea_field( (string) ( $_POST['gf_notify_emails'] ?? '' ) );

            $importer = new Estandar_Forms_GF_Importer( $pending['json'] );
            $result   = $importer->import( [
                'form_type'    => $form_type,
                'notify_emails'=> $notify,
            ] );

            if ( ! isset( $result['error'] ) ) {
                delete_transient( 'estandar_gf_import_pending' );
            }

            $report = $importer->analyze();
        }
    }

    // Embed Primer styles if available
    estandar_forms_enqueue_admin_assets();
    estandar_forms_render_admin_layout_start();
    ?>
    <?php estandar_forms_render_admin_surface_start(); ?>

    <div class="estandar-admin-panel">
        <div class="estandar-admin-panel-header">
            <h3>Importar Gravity Forms</h3>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-forms-forms' ) ); ?>" class="button button-secondary">← Formularios</a>
        </div>

        <?php if ( $result && ! isset( $result['error'] ) ) : ?>
            <div class="notice notice-success" style="margin:16px 0">
                <p><strong>Formulario importado correctamente.</strong></p>
                <p>ID: <?php echo (int) $result['form_id']; ?> — 
                   Shortcode: <code><?php echo esc_html( $result['shortcode'] ); ?></code></p>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-forms-forms&edit=' . (int) $result['form_id'] ) ); ?>" class="button button-primary">Editar formulario</a>
                    <a href="<?php echo esc_url( home_url( '?p=' . (int) $result['form_id'] ) ); ?>" class="button" target="_blank">Vista previa</a>
                </p>
            </div>
        <?php elseif ( $result && isset( $result['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $result['error'] ); ?></p></div>
        <?php endif; ?>

        <?php if ( $report ) : ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0">
                <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:14px">
                    <div style="font-size:1.8rem;font-weight:700;color:#0369a1"><?php echo (int) $report['compatibility_pct']; ?>%</div>
                    <div style="font-size:.82rem;color:#6b7280">Compatibilidad</div>
                </div>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px">
                    <div style="font-size:1.8rem;font-weight:700;color:#15803d"><?php echo (int) $report['total_fields']; ?></div>
                    <div style="font-size:.82rem;color:#6b7280">Campos totales</div>
                </div>
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px">
                    <div style="font-size:1.8rem;font-weight:700;color:#a16207"><?php echo (int) $report['mapped_fields']; ?></div>
                    <div style="font-size:.82rem;color:#6b7280">Mapeados</div>
                </div>
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px">
                    <div style="font-size:1.8rem;font-weight:700;color:#b91c1c"><?php echo (int) $report['lost_fields']; ?></div>
                    <div style="font-size:.82rem;color:#6b7280">No importables</div>
                </div>
            </div>

            <?php if ( ! empty( $report['lost_fields_detail'] ) ) : ?>
            <table class="widefat striped" style="margin-bottom:16px">
                <thead><tr><th>Campo GF</th><th>Tipo</th><th>Motivo</th></tr></thead>
                <tbody>
                <?php foreach ( $report['lost_fields_detail'] as $lost ) : ?>
                <tr>
                    <td><?php echo esc_html( $lost['label'] ); ?></td>
                    <td><code><?php echo esc_html( $lost['gf_type'] ); ?></code></td>
                    <td><?php echo esc_html( $lost['reason'] ); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ( $report['can_import'] && ! $result ) : ?>
            <form method="post" style="margin-top:16px">
                <?php wp_nonce_field('estandar_gf_import'); ?>
                <input type="hidden" name="gf_import_step" value="import" />

                <table class="form-table">
                    <tr>
                        <th><label for="gf_form_type">Tipo de formulario EF</label></th>
                        <td>
                            <select name="gf_form_type" id="gf_form_type">
                                <option value="lead">Lead (captura de leads)</option>
                                <option value="incident">Incident (post-venta)</option>
                                <option value="email_direct">Email directo</option>
                            </select>
                            <p class="description">Selecciona el motor de procesamiento de Estandar Forms.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gf_notify_emails">Emails de notificación</label></th>
                        <td>
                            <input type="text" name="gf_notify_emails" id="gf_notify_emails" class="regular-text"
                                   placeholder="equipo@ejemplo.com" />
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button button-primary button-large">
                        Importar «<?php echo esc_html( $report['source_title'] ); ?>» en Estandar Forms
                    </button>
                </p>
            </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( ! $report || ( $report && isset( $result['error'] ) ) ) : ?>
        <form method="post" enctype="multipart/form-data" style="margin-top:16px">
            <?php wp_nonce_field('estandar_gf_import'); ?>
            <input type="hidden" name="gf_import_step" value="analyze" />

            <p style="font-size:.9rem;color:#6b7280;margin-bottom:16px">
                Exporta un formulario desde Gravity Forms (Formularios → Importar/Exportar → Exportar formularios → selecciona → Descargar JSON) y súbelo aquí.
            </p>

            <table class="form-table">
                <tr>
                    <th><label for="gf_json_file">Archivo JSON de Gravity Forms</label></th>
                    <td>
                        <input type="file" name="gf_json_file" id="gf_json_file" accept=".json" required
                               style="padding:6px 0" />
                    </td>
                </tr>
            </table>

            <p>
                <button type="submit" class="button button-primary button-large">Analizar formulario</button>
            </p>
        </form>
        <?php endif; ?>
    </div>

    <?php
    estandar_forms_render_admin_surface_end();
    estandar_forms_render_admin_layout_end();
}
