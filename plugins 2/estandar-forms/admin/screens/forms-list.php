<?php
if ( ! defined('ABSPATH') ) exit;

function estandar_forms_forms_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No autorizado');
    }

    // Preflight report: show validation report before the actual import executes.
    $preflight_key = isset( $_GET['ef_preflight'] )
        ? sanitize_key( (string) wp_unslash( $_GET['ef_preflight'] ) )
        : '';
    if ( $preflight_key !== '' ) {
        estandar_forms_render_preflight_report( $preflight_key );
        return;
    }

    // P0 resolution step: a previous import was blocked because the project_slug is unknown.
    $resolve_key = isset( $_GET['estandar_forms_resolve'] )
        ? sanitize_key( (string) wp_unslash( $_GET['estandar_forms_resolve'] ) )
        : '';
    if ( $resolve_key !== '' ) {
        estandar_forms_render_import_resolve( $resolve_key );
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'estandar_forms';

    $render_persist_error = static function ( string $operation, string $db_error ): void {
        $message = 'No fue posible guardar el formulario. Revisar logs.';
        $error = strtolower( $db_error );

        if ( false !== strpos( $error, 'duplicate' ) || false !== strpos( $error, 'unique' ) ) {
            $message = 'Ya existe un formulario con ese slug.';
        } elseif ( false !== strpos( $error, 'unknown column' ) || false !== strpos( $error, 'no such column' ) || false !== strpos( $error, 'has no column named' ) ) {
            $message = 'La base de datos requiere migración antes de guardar formularios.';
        } elseif ( false !== strpos( $error, 'locked' ) ) {
            $message = 'La base de datos está temporalmente bloqueada. Intenta nuevamente.';
        } elseif ( false !== strpos( $error, 'permission' ) || false !== strpos( $error, 'denied' ) ) {
            $message = 'No fue posible guardar el formulario por permisos de base de datos.';
        } elseif ( $operation === 'insert' ) {
            $message = 'No fue posible crear el formulario. Revisar logs.';
        } elseif ( $operation === 'update' ) {
            $message = 'No fue posible actualizar el formulario. Revisar logs.';
        }

        echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
    };
    $deleting_id = isset($_GET['delete']) ? intval($_GET['delete']) : 0;

    if (
        $deleting_id &&
        isset($_GET['_wpnonce']) &&
        wp_verify_nonce((string) wp_unslash($_GET['_wpnonce']), 'estandar_forms_delete_' . $deleting_id)
    ) {
        $deleted_form = $wpdb->get_row(
            $wpdb->prepare("SELECT id, slug FROM $table WHERE id = %d", $deleting_id)
        );
        $wpdb->delete($table, ['id' => $deleting_id], ['%d']);
        if ( class_exists('Estandar_Forms_Shortcode') ) {
            Estandar_Forms_Shortcode::clear_form_cache($deleted_form ?: $deleting_id);
        }
        echo '<div class="updated"><p>Formulario eliminado.</p></div>';
    }

    $forms = $wpdb->get_results("SELECT * FROM $table WHERE (form_type IS NULL OR form_type != 'survey') ORDER BY created_at DESC");
    estandar_forms_enqueue_admin_assets(false);
    ?>
    <?php estandar_forms_render_admin_layout_start(); ?>
        <?php estandar_forms_render_admin_branding( 'Forms', 'Biblioteca y gestión de formularios existentes.' ); ?>
        <?php estandar_forms_render_admin_surface_start(); ?>

        <?php estandar_forms_render_admin_tabs('estandar-forms'); ?>
        <?php estandar_forms_render_notice_from_request(); ?>

        <section class="estandar-admin-panel">
            <div class="estandar-admin-panel-header">
                <div>
                    <h3>Biblioteca de formularios</h3>
                    <p>Desde aqui puedes revisar shortcodes, editar o eliminar formularios existentes.</p>
                </div>
                <div class="estandar-builder-toolbar">
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=estandar-forms&create=1')); ?>">+ Crear nuevo</a>
                </div>
            </div>
            <?php estandar_forms_render_forms_table($forms); ?>
        </section>

        <section class="estandar-admin-panel">
            <div class="estandar-admin-panel-header">
                <div>
                    <h3>Importar plantilla</h3>
                    <p>Sube un archivo <code>.estandar-template.json</code> para ver el reporte de validación antes de importar.</p>
                </div>
            </div>
            <form method="post" enctype="multipart/form-data"
                  action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field('estandar_forms_import'); ?>
                <input type="hidden" name="action" value="estandar_forms_import_preflight">
                <table class="form-table">
                    <tr>
                        <th scope="row">Archivo de plantilla</th>
                        <td>
                            <input type="file" name="template_json" accept=".json">
                            <p class="description">Archivo generado con "Exportar" desde cualquier instalación de Estandar Forms.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Validar plantilla', 'secondary', 'submit_import', false); ?>
            </form>
        </section>

        <?php estandar_forms_render_admin_surface_end(); ?>

    <?php estandar_forms_render_admin_layout_end(); ?>
    <?php
}

/**
 * Renders the preflight validation report.
 * Called when ?ef_preflight={key} is present.
 * Reads the envelope from a transient (set by handle_import_preflight), shows the report,
 * and offers Confirm / Cancel buttons. Never modifies the envelope.
 */
function estandar_forms_render_preflight_report( string $preflight_key ): void {
    // Support both new format ['envelope'=>...,'sha256'=>...] and legacy bare envelope.
    $data     = get_transient( $preflight_key );
    $envelope = ( is_array( $data ) && isset( $data['envelope'] ) ) ? $data['envelope'] : $data;
    $sha256   = ( is_array( $data ) && isset( $data['sha256'] ) ) ? (string) $data['sha256'] : '';

    if ( ! is_array( $envelope ) ) {
        estandar_forms_enqueue_admin_assets( false );
        estandar_forms_render_admin_layout_start();
        estandar_forms_render_admin_branding( 'Forms', 'Importar plantilla' );
        estandar_forms_render_admin_surface_start();
        estandar_forms_render_admin_tabs( 'estandar-forms' );
        echo '<div class="notice notice-error"><p>El reporte de validación expiró o no es válido. Vuelve a subir el archivo.</p></div>';
        estandar_forms_render_admin_surface_end();
        estandar_forms_render_admin_layout_end();
        return;
    }

    $report  = class_exists( 'Estandar_Forms_Template' )
        ? Estandar_Forms_Template::preflight( $envelope )
        : [];

    $meta    = $report['meta']    ?? [];
    $summary = $report['summary'] ?? [];
    $checks  = $report['checks']  ?? [];
    $global  = $report['global']  ?? [];

    // ── Helpers ──────────────────────────────────────────────────────────────
    $badge = static function ( string $status, string $override_label = '' ): string {
        $map = [
            'pass'    => [ '#e6f4ea', '#137333', 'PASS' ],
            'warning' => [ '#fef9e7', '#7d4e0f', 'AVISO' ],
            'fail'    => [ '#fce8e6', '#d93025', 'FAIL' ],
        ];
        [ $bg, $color, $default_label ] = $map[ $status ] ?? [ '#f1f3f4', '#444', strtoupper( $status ) ];
        $label = $override_label !== '' ? $override_label : $default_label;
        return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:' . $bg . ';color:' . $color . ';">' . esc_html( $label ) . '</span>';
    };

    $exported_fmt = '';
    if ( ! empty( $meta['exported_at'] ) ) {
        $ts           = strtotime( $meta['exported_at'] );
        $exported_fmt = $ts ? date_i18n( 'j M Y, H:i', $ts ) : esc_html( $meta['exported_at'] );
    }

    $global_status = $global['status'] ?? 'pass';
    $global_labels = [
        'pass'    => 'Todo correcto',
        'warning' => 'Hay avisos',
        'fail'    => 'Errores encontrados',
    ];

    estandar_forms_enqueue_admin_assets( false );
    ?>
    <?php estandar_forms_render_admin_layout_start(); ?>
        <?php estandar_forms_render_admin_branding( 'Forms', 'Importar plantilla — reporte de validación' ); ?>
        <?php estandar_forms_render_admin_surface_start(); ?>
        <?php estandar_forms_render_admin_tabs( 'estandar-forms' ); ?>

        <!-- Estado general -->
        <section class="estandar-admin-panel">
            <div class="estandar-admin-panel-header">
                <div>
                    <h3><?php echo esc_html( $meta['name'] ?? 'Sin nombre' ); ?></h3>
                    <p style="color:#646970;font-size:13px;margin:4px 0 0;">
                        Slug: <code><?php echo esc_html( $meta['slug'] ?? '' ); ?></code>
                        &nbsp;·&nbsp; Schema v<?php echo esc_html( $meta['schema_version'] ?? '?' ); ?>
                        <?php if ( $exported_fmt ) : ?>
                            &nbsp;·&nbsp; Exportado: <?php echo esc_html( $exported_fmt ); ?>
                        <?php endif; ?>
                        <?php if ( $sha256 !== '' ) : ?>
                            <br><span style="font-family:monospace;font-size:11px;color:#9da3a9;">SHA-256: <?php echo esc_html( $sha256 ); ?></span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Resumen global -->
            <div style="display:flex;align-items:center;gap:20px;padding:14px 0 18px;border-bottom:1px solid #f0f0f1;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <?php echo $badge( $global_status, $global_labels[ $global_status ] ?? $global_status ); // phpcs:ignore ?>
                    <span style="font-size:13px;color:#3c434a;font-weight:600;">Estado general</span>
                </div>
                <div style="display:flex;gap:16px;flex-wrap:wrap;">
                    <span style="font-size:12px;color:#646970;"><?php echo esc_html( $global['total_checks'] ?? 0 ); ?> checks</span>
                    <span style="font-size:12px;color:#137333;font-weight:600;"><?php echo esc_html( $global['pass_count'] ?? 0 ); ?> PASS</span>
                    <?php if ( ( $global['warning_count'] ?? 0 ) > 0 ) : ?>
                        <span style="font-size:12px;color:#7d4e0f;font-weight:600;"><?php echo esc_html( $global['warning_count'] ); ?> AVISO</span>
                    <?php endif; ?>
                    <?php if ( ( $global['fail_count'] ?? 0 ) > 0 ) : ?>
                        <span style="font-size:12px;color:#d93025;font-weight:600;"><?php echo esc_html( $global['fail_count'] ); ?> FAIL</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Resumen de contenido -->
            <div style="display:flex;gap:16px;padding:14px 0 18px;border-bottom:1px solid #f0f0f1;flex-wrap:wrap;">
                <?php
                $pills = [
                    ( $summary['field_count'] ?? 0 )  . ' campo(s)',
                    ( $summary['action_count'] ?? 0 ) . ' acción(es)',
                    'Contexto: ' . ( $summary['context_mode'] ?? 'none' ),
                ];
                if ( ! empty( $summary['has_incidents'] ) )  { $pills[] = 'Incidentes'; }
                if ( ! empty( $summary['has_survey'] ) )     { $pills[] = 'Survey config'; }
                if ( ! empty( $summary['has_experience'] ) ) { $pills[] = 'Experience config'; }
                foreach ( $pills as $pill ) :
                    ?>
                    <span style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:4px 10px;font-size:12px;color:#3c434a;">
                        <?php echo esc_html( $pill ); ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <!-- Compatibilidad de versiones -->
            <?php
            $v_check = $checks['source_version'] ?? null;
            if ( $v_check ) :
                $compat       = $v_check['compat'] ?? ( $v_check['status'] === 'pass' ? 'compatible' : 'revisar' );
                $compat_label = $compat === 'compatible' ? 'Compatible' : 'Revisar';
                $compat_color = $compat === 'compatible' ? '#137333' : '#7d4e0f';
            ?>
            <div style="padding:14px 0 18px;border-bottom:1px solid #f0f0f1;">
                <p style="font-size:12px;font-weight:600;color:#646970;margin:0 0 8px;text-transform:uppercase;letter-spacing:.04em;">Compatibilidad del plugin</p>
                <div style="display:flex;gap:32px;flex-wrap:wrap;align-items:center;">
                    <div>
                        <span style="font-size:11px;color:#9da3a9;display:block;margin-bottom:2px;">Plugin origen</span>
                        <span style="font-size:13px;color:#3c434a;"><?php echo esc_html( $meta['source_plugin'] ?? 'estandar-forms' ); ?> <strong>v<?php echo esc_html( $v_check['src_version'] ?? '?' ); ?></strong></span>
                    </div>
                    <div style="font-size:18px;color:#c3c4c7;">→</div>
                    <div>
                        <span style="font-size:11px;color:#9da3a9;display:block;margin-bottom:2px;">Plugin destino</span>
                        <span style="font-size:13px;color:#3c434a;">estandar-forms <strong>v<?php echo esc_html( $v_check['dest_version'] ?? '?' ); ?></strong></span>
                    </div>
                    <div>
                        <span style="font-size:13px;font-weight:700;color:<?php echo esc_attr( $compat_color ); ?>;"><?php echo esc_html( $compat_label ); ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Validaciones -->
            <table style="width:100%;border-collapse:collapse;margin-top:16px;">
                <thead>
                    <tr style="border-bottom:1px solid #dcdcde;">
                        <th style="text-align:left;padding:8px 12px;font-size:12px;color:#646970;font-weight:600;width:110px;">Estado</th>
                        <th style="text-align:left;padding:8px 12px;font-size:12px;color:#646970;font-weight:600;width:200px;">Validación</th>
                        <th style="text-align:left;padding:8px 12px;font-size:12px;color:#646970;font-weight:600;">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $checks as $check ) : ?>
                        <tr style="border-bottom:1px solid #f0f0f1;">
                            <td style="padding:10px 12px;"><?php echo $badge( $check['status'] ); // phpcs:ignore ?></td>
                            <td style="padding:10px 12px;font-size:13px;font-weight:600;"><?php echo esc_html( $check['label'] ); ?></td>
                            <td style="padding:10px 12px;font-size:13px;color:#3c434a;"><?php echo esc_html( $check['detail'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <!-- Confirm / Cancel -->
        <section class="estandar-admin-panel">
            <div style="display:flex;align-items:center;gap:16px;padding:4px 0;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'estandar_forms_import_confirmed' ); ?>
                    <input type="hidden" name="action" value="estandar_forms_import_confirmed">
                    <input type="hidden" name="ef_preflight_key" value="<?php echo esc_attr( $preflight_key ); ?>">
                    <button type="submit" class="button button-primary">Importar formulario</button>
                </form>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-forms-forms' ) ); ?>"
                   class="button button-secondary">Cancelar</a>
            </div>
        </section>

        <?php estandar_forms_render_admin_surface_end(); ?>
    <?php estandar_forms_render_admin_layout_end(); ?>
    <?php
}

/**
 * Renders the project resolution screen for a blocked import (P0).
 * Called when ?estandar_forms_resolve={transient_key} is present.
 */
function estandar_forms_render_import_resolve( string $transient_key ): void {
    $unresolved_slug = isset( $_GET['estandar_forms_unresolved_slug'] )
        ? sanitize_title( remove_accents( rawurldecode(
            sanitize_text_field( (string) wp_unslash( $_GET['estandar_forms_unresolved_slug'] ) )
        ) ) )
        : '';

    $available_projects = function_exists( 'estandar_forms_project_registry' )
        ? estandar_forms_project_registry()
        : [];

    estandar_forms_enqueue_admin_assets( false );
    ?>
    <?php estandar_forms_render_admin_layout_start(); ?>
        <?php estandar_forms_render_admin_branding( 'Forms', 'Importar plantilla — resolución de proyecto' ); ?>
        <?php estandar_forms_render_admin_surface_start(); ?>
        <?php estandar_forms_render_admin_tabs( 'estandar-forms' ); ?>

        <div class="notice notice-warning">
            <p>
                La plantilla referencia el proyecto <strong><?php echo esc_html( $unresolved_slug ); ?></strong>,
                que no existe en esta instalación. Elige cómo continuar:
            </p>
        </div>

        <section class="estandar-admin-panel">
            <div class="estandar-admin-panel-header">
                <div>
                    <h3>¿Cómo importar este formulario?</h3>
                    <p>Proyecto original en la plantilla: <code><?php echo esc_html( $unresolved_slug ); ?></code></p>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:16px 0;">

                <!-- Opción 1: Sin proyecto -->
                <div style="border:1px solid #c3c4c7;border-radius:6px;padding:20px;">
                    <strong style="display:block;margin-bottom:6px;">Importar sin proyecto</strong>
                    <p style="color:#646970;font-size:13px;margin:0 0 14px;">
                        El formulario se importa tal cual. El routing de proyecto quedará desactivado hasta que lo configures manualmente.
                    </p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'estandar_forms_import_resolve' ); ?>
                        <input type="hidden" name="action" value="estandar_forms_import_resolve">
                        <input type="hidden" name="ef_import_key" value="<?php echo esc_attr( $transient_key ); ?>">
                        <input type="hidden" name="ef_project_slug" value="">
                        <button type="submit" class="button button-secondary">Importar sin proyecto</button>
                    </form>
                </div>

                <!-- Opción 2: Seleccionar proyecto -->
                <div style="border:1px solid #c3c4c7;border-radius:6px;padding:20px;">
                    <strong style="display:block;margin-bottom:6px;">Asignar a un proyecto existente</strong>
                    <p style="color:#646970;font-size:13px;margin:0 0 14px;">
                        Selecciona el proyecto de esta instalación al que debe apuntar el formulario.
                    </p>
                    <?php if ( ! empty( $available_projects ) ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <?php wp_nonce_field( 'estandar_forms_import_resolve' ); ?>
                            <input type="hidden" name="action" value="estandar_forms_import_resolve">
                            <input type="hidden" name="ef_import_key" value="<?php echo esc_attr( $transient_key ); ?>">
                            <select name="ef_project_slug" style="display:block;width:100%;margin-bottom:10px;">
                                <?php foreach ( $available_projects as $project ) : ?>
                                    <option value="<?php echo esc_attr( $project['project_slug'] ); ?>">
                                        <?php echo esc_html( $project['name'] ); ?>
                                        (<?php echo esc_html( $project['project_slug'] ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-primary">Importar con este proyecto</button>
                        </form>
                    <?php else : ?>
                        <p style="color:#646970;font-size:13px;">No hay proyectos disponibles en esta instalación.</p>
                    <?php endif; ?>
                </div>

            </div>

            <p style="margin-top:8px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-forms-forms' ) ); ?>">← Cancelar y volver</a>
            </p>
        </section>

        <?php estandar_forms_render_admin_surface_end(); ?>
    <?php estandar_forms_render_admin_layout_end(); ?>
    <?php
}
