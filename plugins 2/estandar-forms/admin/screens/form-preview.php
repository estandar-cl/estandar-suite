<?php
if ( ! defined('ABSPATH') ) exit;

function estandar_forms_entries_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No autorizado' );
    }
    if ( function_exists( 'estandar_core_render_admin_shell' ) ) {
        estandar_core_render_admin_shell( [
            'title'      => 'Forms',
            'subtitle'   => '',
            'width'      => 'fluid',
            'navigation' => estandar_forms_nav_items('estandar-forms-entries'),
            'callback'   => 'estandar_forms_entries_page_content',
        ] );
        return;
    }
    estandar_forms_entries_page_content();
}

function estandar_forms_entries_page_content() {
    global $wpdb;
    $entries_table = $wpdb->prefix . 'estandar_form_entries';
    $forms_table = $wpdb->prefix . 'estandar_forms';
    $lead_submissions_table = $wpdb->prefix . 'es_lead_submissions';
    $leads_table = $wpdb->prefix . 'es_leads';
    $entry_id = isset($_GET['entry_id']) ? absint($_GET['entry_id']) : 0;
    $view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : '';
    $submission_id = isset($_GET['submission_id']) ? absint($_GET['submission_id']) : 0;
    $embedded = function_exists( 'estandar_core_is_embedded_admin' ) && estandar_core_is_embedded_admin();

    if ( $view === 'submission' && ( $submission_id > 0 || $entry_id > 0 ) ) {
        $where = $submission_id > 0 ? 's.id = %d' : 's.source_entry_id = %d';
        $where_value = $submission_id > 0 ? $submission_id : $entry_id;
        $submission = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT s.*, l.name AS lead_name, l.email AS lead_email, e.data_json, e.status AS entry_status, e.created_at AS entry_created_at, f.name AS form_name
                 FROM {$lead_submissions_table} s
                 LEFT JOIN {$leads_table} l ON l.id = s.lead_id
                 LEFT JOIN {$entries_table} e ON e.id = s.source_entry_id
                 LEFT JOIN {$forms_table} f ON f.id = e.form_id
                 WHERE {$where}
                 ORDER BY s.id DESC
                 LIMIT 1",
                $where_value
            ),
            ARRAY_A
        );
        $submission = is_array($submission) ? $submission : [];
        $captured = json_decode((string) ($submission['data_json'] ?? ''), true);
        $captured = is_array($captured) ? $captured : [];
        $normalized = json_decode((string) ($submission['form_fields_json'] ?? ''), true);
        $normalized = is_array($normalized) ? $normalized : [];
        $context = json_decode((string) ($submission['context_json'] ?? ''), true);
        $context = is_array($context) ? $context : [];
        $payload = [
            'submission' => $submission,
            'captured'   => $captured,
            'normalized' => $normalized,
            'context'    => $context,
        ];

        estandar_forms_enqueue_admin_assets(false);
        ?>
        <?php if ( ! $embedded ) : ?><?php estandar_forms_render_admin_layout_start(); ?>
            <?php estandar_forms_render_admin_branding( 'Forms', 'Detalle de submission.' ); ?>
            <?php estandar_forms_render_admin_surface_start(); ?>
            <?php estandar_forms_render_admin_tabs('estandar-forms-entries'); ?>
        <?php endif; ?>
            <p><a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'estandar-forms-entries', 'entry_id' => absint($submission['source_entry_id'] ?? $entry_id)], admin_url('admin.php'))); ?>">&larr; Volver a Entry</a></p>
            <?php if ( empty($submission) ) : ?>
                <p>Submission no encontrada.</p>
            <?php else : ?>
                <section class="estandar-admin-panel">
                    <h3>Submission #<?php echo esc_html((string) $submission['id']); ?></h3>
                    <table class="widefat striped"><tbody>
                        <tr><th>Submission ID</th><td><?php echo esc_html((string) $submission['id']); ?></td><th>Lead ID</th><td><?php echo esc_html(! empty($submission['lead_id']) ? '#' . (string) $submission['lead_id'] : '—'); ?></td></tr>
                        <tr><th>Fecha/Hora</th><td><?php echo esc_html(estandar_forms_local_datetime($submission['submitted_at'] ?? $submission['entry_created_at'] ?? '')); ?></td><th>Estado</th><td><?php echo esc_html((string) ($submission['entry_status'] ?? '')); ?></td></tr>
                        <tr><th>Formulario origen</th><td><?php echo esc_html((string) ($submission['form_name'] ?? $submission['source_form_id'] ?? '')); ?></td><th>Entry ID</th><td><?php echo esc_html(! empty($submission['source_entry_id']) ? '#' . (string) $submission['source_entry_id'] : '—'); ?></td></tr>
                    </tbody></table>
                    <p style="margin:12px 0 0;">
                        <?php if ( ! empty($submission['lead_id']) ) : ?>
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=estandar-leads-portal&view=detail&lead_id=' . absint($submission['lead_id']))); ?>">Ver Lead →</a>
                        <?php endif; ?>
                        <?php if ( ! empty($submission['session_id']) ) : ?>
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ea-events&session_id=' . rawurlencode((string) $submission['session_id']))); ?>">Ver Sesión →</a>
                        <?php endif; ?>
                    </p>
                </section>

                <section class="estandar-admin-panel">
                    <h3>Campos capturados</h3>
                    <table class="widefat striped"><tbody>
                        <?php
                        foreach ( $captured as $key => $value ) {
                            estandar_forms_render_kv_row(estandar_forms_entry_label((string) $key), $value);
                        }
                        ?>
                    </tbody></table>
                </section>

                <section class="estandar-admin-panel">
                    <h3>Campos normalizados</h3>
                    <?php if ( empty($normalized) ) : ?>
                        <p>Sin campos normalizados adicionales.</p>
                    <?php else : ?>
                        <table class="widefat striped"><tbody>
                            <?php
                            foreach ( $normalized as $key => $value ) {
                                estandar_forms_render_kv_row(estandar_forms_entry_label((string) $key), $value);
                            }
                            ?>
                        </tbody></table>
                    <?php endif; ?>
                </section>

                <section class="estandar-admin-panel">
                    <h3>Atribución</h3>
                    <table class="widefat striped"><tbody>
                        <?php
                        foreach ( [
                            'UTM Source'   => $submission['utm_source'] ?? '',
                            'UTM Medium'   => $submission['utm_medium'] ?? '',
                            'UTM Campaign' => $submission['utm_campaign'] ?? '',
                            'UTM Term'     => $submission['utm_term'] ?? '',
                            'Landing URL'  => $submission['landing_url'] ?? '',
                            'Referrer'     => $submission['referrer'] ?? '',
                            'Session ID'   => $submission['session_id'] ?? '',
                        ] as $label => $value ) {
                            estandar_forms_render_kv_row($label, $value);
                        }
                        ?>
                    </tbody></table>
                </section>

                <section class="estandar-admin-panel">
                    <h3>Payload capturado</h3>
                    <pre style="white-space:pre-wrap;max-height:520px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:12px;"><?php echo esc_html(estandar_forms_pretty_json($payload)); ?></pre>
                </section>
            <?php endif; ?>
            <?php if ( ! $embedded ) : ?><?php estandar_forms_render_admin_surface_end(); ?>
        <?php estandar_forms_render_admin_layout_end(); ?><?php endif; ?>
        <?php
        return;
    }

    if ( $entry_id > 0 ) {
        $entry = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT e.id, e.form_id, e.created_at, e.status, e.data_json, f.name AS form_name
                 FROM {$entries_table} e
                 LEFT JOIN {$forms_table} f ON f.id = e.form_id
                 WHERE e.id = %d
                 LIMIT 1",
                $entry_id
            ),
            ARRAY_A
        );
        $entry = is_array($entry) ? $entry : [];
        $linked_lead = [];
        if ( ! empty($entry) ) {
            $linked_lead = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT s.id AS submission_id, s.lead_id, l.name, l.email
                     FROM {$lead_submissions_table} s
                     LEFT JOIN {$leads_table} l ON l.id = s.lead_id
                     WHERE s.source_entry_id = %d
                     ORDER BY s.id DESC
                     LIMIT 1",
                    $entry_id
                ),
                ARRAY_A
            );
            $linked_lead = is_array($linked_lead) ? $linked_lead : [];
        }
        $data = json_decode((string) ($entry['data_json'] ?? ''), true);
        $data = is_array($data) ? $data : [];

        estandar_forms_enqueue_admin_assets(false);
        ?>
        <?php if ( ! $embedded ) : ?><?php estandar_forms_render_admin_layout_start(); ?>
            <?php estandar_forms_render_admin_branding( 'Forms', 'Detalle de entrada recibida.' ); ?>
            <?php estandar_forms_render_admin_surface_start(); ?>
            <?php estandar_forms_render_admin_tabs('estandar-forms-entries'); ?>
        <?php endif; ?>
            <p><a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'estandar-forms-entries'], admin_url('admin.php'))); ?>">&larr; Volver</a></p>
            <?php if ( empty($entry) ) : ?>
                <p>Entrada no encontrada.</p>
            <?php else : ?>
                <section class="estandar-admin-panel">
                    <h3>Entry #<?php echo esc_html((string) $entry['id']); ?></h3>
                    <table class="widefat striped">
                        <tbody>
                            <tr><th>ID</th><td><?php echo esc_html((string) $entry['id']); ?></td><th>Formulario</th><td><?php echo esc_html((string) ($entry['form_name'] ?? '')); ?></td></tr>
                            <tr><th>Fecha</th><td><?php echo esc_html(estandar_forms_local_datetime($entry['created_at'] ?? '')); ?></td><th>Estado</th><td><?php echo esc_html((string) ($entry['status'] ?? '')); ?></td></tr>
                            <tr><th>Lead ID</th><td><?php echo esc_html(! empty($linked_lead['lead_id']) ? '#' . (string) $linked_lead['lead_id'] : '—'); ?></td><th>Submission ID</th><td><?php echo esc_html(! empty($linked_lead['submission_id']) ? '#' . (string) $linked_lead['submission_id'] : '—'); ?></td></tr>
                        </tbody>
                    </table>
                    <?php if ( ! empty($linked_lead['lead_id']) ) : ?>
                        <p style="margin:12px 0 0;">
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=estandar-leads-portal&view=detail&lead_id=' . absint($linked_lead['lead_id']))); ?>">Ver Lead →</a>
                            <?php if ( ! empty($linked_lead['submission_id']) ) : ?>
                                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=estandar-forms-entries&view=submission&submission_id=' . absint($linked_lead['submission_id']))); ?>">Ver Submission →</a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </section>

                <section class="estandar-admin-panel">
                    <h3>Datos capturados</h3>
                    <?php if ( empty($data) ) : ?>
                        <p>No hay datos capturados.</p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <tbody>
                            <?php foreach ( $data as $key => $value ) : ?>
                                <tr>
                                    <th style="width:220px;"><?php echo esc_html(estandar_forms_entry_label((string) $key)); ?></th>
                                    <td>
                                        <?php if ( is_array($value) ) : ?>
                                            <pre style="white-space:pre-wrap;margin:0;max-height:240px;overflow:auto;"><?php echo esc_html(estandar_forms_entry_display_value($value)); ?></pre>
                                        <?php else : ?>
                                            <?php echo esc_html(estandar_forms_entry_display_value($value)); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <details style="margin-top:14px;">
                        <summary>JSON técnico</summary>
                        <pre style="white-space:pre-wrap;max-height:520px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:12px;"><?php echo esc_html(estandar_forms_pretty_json($data)); ?></pre>
                    </details>
                </section>
            <?php endif; ?>
            <?php if ( ! $embedded ) : ?><?php estandar_forms_render_admin_surface_end(); ?>
        <?php estandar_forms_render_admin_layout_end(); ?><?php endif; ?>
        <?php
        return;
    }

    $rows = $wpdb->get_results(
        "SELECT e.id, e.created_at, e.status, e.data_json, f.name AS form_name
         FROM {$entries_table} e
         LEFT JOIN {$forms_table} f ON f.id = e.form_id
         ORDER BY e.created_at DESC
         LIMIT 200",
        ARRAY_A
    );
    $rows = is_array($rows) ? $rows : [];

    estandar_forms_enqueue_admin_assets(false);
    $embedded = function_exists( 'estandar_core_is_embedded_admin' ) && estandar_core_is_embedded_admin();
    ?>
    <?php if ( ! $embedded ) : ?><?php estandar_forms_render_admin_layout_start(); ?>
        <?php estandar_forms_render_admin_branding( 'Forms', 'Entradas recibidas por formularios Estandar.' ); ?>
        <?php estandar_forms_render_admin_surface_start(); ?>
        <?php estandar_forms_render_admin_tabs('estandar-forms-entries'); ?>
    <?php endif; ?>
        <?php
        $entry_notice = isset($_GET['estandar_forms_entry_notice']) ? sanitize_key((string) wp_unslash($_GET['estandar_forms_entry_notice'])) : '';
        if ( $entry_notice === 'deleted' ) {
            echo '<div class="updated"><p>Entrada(s) eliminada(s).</p></div>';
        } elseif ( $entry_notice === 'none_selected' ) {
            echo '<div class="notice notice-error"><p>Selecciona al menos una entrada.</p></div>';
        }
        ?>
        <section class="estandar-admin-panel">
            <div class="estandar-admin-panel-header">
                <div>
                    <h3>Entradas</h3>
                    <p>Fuente de verdad local para envíos, independiente de Email, Leads o Webhook.</p>
                </div>
                <?php if ( ! empty($rows) ) : ?>
                    <div class="estandar-builder-toolbar">
                        <button class="button" type="submit" form="estandar-forms-entries-bulk" name="estandar_forms_entries_bulk_action" value="export_csv">Exportar CSV</button>
                        <button class="button" type="submit" form="estandar-forms-entries-bulk" name="estandar_forms_entries_bulk_action" value="export_json">Exportar JSON</button>
                        <button class="button button-link-delete" type="submit" form="estandar-forms-entries-bulk" name="estandar_forms_entries_bulk_action" value="delete" onclick="return confirm('¿Eliminar las entradas seleccionadas? Esta acción no elimina Leads asociados.');">Eliminar</button>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ( empty($rows) ) : ?>
                <p>No hay entradas.</p>
            <?php else : ?>
                <form method="post" id="estandar-forms-entries-bulk" action="<?php echo esc_url(admin_url('admin.php?page=estandar-forms-entries')); ?>">
                    <?php wp_nonce_field('estandar_forms_entries_bulk'); ?>
                <table class="widefat striped">
                    <thead><tr><th><input type="checkbox" id="estandar-forms-select-all-entries"></th><th>ID</th><th>Fecha</th><th>Formulario</th><th>Nombre</th><th>Correo</th><th>Estado</th><th>Acciones</th></tr></thead>
                    <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <?php
                        $data = json_decode((string) ($row['data_json'] ?? ''), true);
                        $data = is_array($data) ? $data : [];
                        $row_id = absint($row['id'] ?? 0);
                        ?>
                        <tr>
                                    <td><input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr((string) $row_id); ?>"></td>
		                            <td><?php echo esc_html((string) $row_id); ?></td>
		                            <td><?php echo esc_html(estandar_forms_local_datetime($row['created_at'] ?? '')); ?></td>
		                            <td><?php echo esc_html((string) ($row['form_name'] ?? '')); ?></td>
		                            <td><?php echo esc_html(estandar_forms_entry_value($data, ['nombre', 'name', 'full_name', 'fullname'])); ?></td>
		                            <td><?php echo esc_html(estandar_forms_entry_value($data, ['email', 'correo', 'your-email'])); ?></td>
		                            <td><?php echo esc_html((string) ($row['status'] ?? '')); ?></td>
		                            <td>
                                        <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'estandar-forms-entries', 'entry_id' => $row_id], admin_url('admin.php'))); ?>">Ver</a>
                                        <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'estandar-forms-entries', 'estandar_forms_export_entries' => '1', 'entry_id' => $row_id, 'format' => 'csv'], admin_url('admin.php')), 'estandar_forms_export_entries')); ?>">CSV</a>
                                        <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'estandar-forms-entries', 'estandar_forms_export_entries' => '1', 'entry_id' => $row_id, 'format' => 'json'], admin_url('admin.php')), 'estandar_forms_export_entries')); ?>">JSON</a>
                                        <a class="button button-small button-link-delete" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'estandar-forms-entries', 'estandar_forms_delete_entry' => $row_id], admin_url('admin.php')), 'estandar_forms_delete_entry_' . $row_id)); ?>" onclick="return confirm('¿Eliminar esta entrada? Esta acción no elimina Leads asociados.');">Eliminar</a>
                                    </td>
		                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </form>
                <script>
                var selectAllEntries = document.getElementById('estandar-forms-select-all-entries');
                if (selectAllEntries) {
	                selectAllEntries.addEventListener('change', function () {
	                    document.querySelectorAll('#estandar-forms-entries-bulk input[name="entry_ids[]"]').forEach((input) => {
	                        input.checked = this.checked;
	                    });
	                });
                }
                </script>
            <?php endif; ?>
        </section>
             <?php if ( ! $embedded ) : ?><?php estandar_forms_render_admin_surface_end(); ?>
         <?php estandar_forms_render_admin_layout_end(); ?><?php endif; ?>
    <?php
}
