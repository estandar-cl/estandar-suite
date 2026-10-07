<?php
if ( ! defined('ABSPATH') ) exit;

/* ─────────────────────────────────────────────────────────────────────────
   SURVEYS_EXPORT_V1 — admin-post handler (streaming CSV, no new tables).

   Route: admin-post.php?action=estandar_survey_export_csv&survey_id=N
   One row per answer (estandar_survey_responses JOIN estandar_form_entries).
   Columns: survey_id · survey_name · entry_id · session_token · submitted_at
            · respondent_name · respondent_email · respondent_phone · question_index
            · question_label · question_type · answer_value · answer_label
            · time_spent_seconds
───────────────────────────────────────────────────────────────────────── */
add_action( 'admin_post_estandar_survey_export_csv', 'estandar_survey_handle_export_csv' );

function estandar_survey_handle_export_csv(): void {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No autorizado', 403);
    }
    check_admin_referer('estandar_survey_export_csv');

    global $wpdb;
    $survey_id = absint( $_GET['survey_id'] ?? 0 );
    if ( $survey_id <= 0 ) {
        wp_die('survey_id requerido.');
    }

    $forms_table = $wpdb->prefix . 'estandar_forms';
    $survey = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, name FROM $forms_table WHERE id = %d AND form_type = 'survey'",
        $survey_id
    ) );
    if ( ! $survey ) {
        wp_die('Encuesta no encontrada.');
    }

    $responses_table = $wpdb->prefix . 'estandar_survey_responses';
    $entries_table   = $wpdb->prefix . 'estandar_form_entries';

    // Fetch all responses for this survey, joined to the session metadata in form_entries.
    // entry_id may be NULL for answers saved before the session completed (answered before complete).
    // We use a LEFT JOIN and fall back to parsing data_json by session_token.
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            r.id            AS response_id,
            r.entry_id,
            r.session_token,
            r.question_index,
            r.question_label,
            r.question_type,
            r.answer_value,
            r.answer_label,
            r.time_spent_seconds,
            r.answered_at,
            e.data_json,
            e.created_at    AS session_started_at
         FROM $responses_table r
         LEFT JOIN $entries_table e
               ON e.form_id = %d
              AND (
                  e.id = r.entry_id
                  OR ( r.entry_id IS NULL AND e.data_json LIKE %s )
              )
         WHERE r.form_id = %d
         ORDER BY r.session_token ASC, r.question_index ASC",
        $survey_id,
        '%' . $wpdb->esc_like( '"session_token"' ) . '%',
        $survey_id
    ), ARRAY_A );

    $survey_name = (string) $survey->name;
    $filename    = 'survey-' . $survey_id . '-' . gmdate('Ymd-His') . '.csv';

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Cache-Control: no-store, no-cache' );
    header( 'Pragma: no-cache' );

    $out = fopen( 'php://output', 'wb' );
    // UTF-8 BOM for Excel compatibility.
    fwrite( $out, "\xEF\xBB\xBF" );

    // Header row.
    fputcsv( $out, [
        'survey_id', 'survey_name', 'entry_id', 'session_token',
        'submitted_at', 'respondent_name', 'respondent_email', 'respondent_phone',
        'question_index', 'question_label', 'question_type',
        'answer_value', 'answer_label', 'time_spent_seconds',
    ] );

    // Guard against CSV formula injection in user-supplied text fields.
    // Prefixes cells that start with =, +, -, @ with a single quote (Excel/Sheets treat it as text).
    $csv_safe = static function ( $val ): string {
        $s = (string) $val;
        return ( $s !== '' && in_array( $s[0], ['=', '+', '-', '@', "\t", "\r"], true ) )
            ? "'" . $s
            : $s;
    };

    // Load matrix row labels from survey structure
    $matrix_row_labels = [];
    $structure_raw = $wpdb->get_var( $wpdb->prepare(
        "SELECT fields_json FROM {$wpdb->prefix}estandar_forms WHERE id = %d",
        $survey_id
    ) );
    if ( $structure_raw ) {
        $structure = json_decode( $structure_raw, true );
        if ( is_array( $structure ) ) {
            foreach ( $structure as $field ) {
                if ( ( $field['type'] ?? '' ) === 'matrix' && isset( $field['rows'] ) ) {
                    foreach ( $field['rows'] as $row_def ) {
                        $matrix_row_labels[ $row_def['id'] ] = $row_def['label'] ?? $row_def['id'];
                    }
                }
            }
        }
    }

    $seen_tokens = []; // track which entry metadata we've already decoded
    $token_meta  = [];

    foreach ( (array) $rows as $row ) {
        $token = (string) $row['session_token'];

        // Decode respondent info from data_json once per token.
        if ( ! isset( $token_meta[$token] ) ) {
            $d = $row['data_json'] !== null
                ? (array) ( json_decode( (string) $row['data_json'], true ) ?: [] )
                : [];
            // Only emit name/email if the survey collected identification.
            $token_meta[$token] = [
                'name'       => (string) ( $d['respondent_name']  ?? '' ),
                'email'      => (string) ( $d['respondent_email'] ?? '' ),
                'phone'      => (string) ( $d['respondent_phone'] ?? '' ),
                'started_at' => (string) ( $row['session_started_at'] ?? $row['answered_at'] ?? '' ),
                'entry_id'   => $row['entry_id'],
            ];
        }
        $meta = $token_meta[$token];

        fputcsv( $out, [
            $survey_id,
            $survey_name,
            $meta['entry_id'] ?? '',
            $token,
            $meta['started_at'],
            $csv_safe( $meta['name'] ),
            $csv_safe( $meta['email'] ),
            $csv_safe( $meta['phone'] ),
            (int) $row['question_index'] + 1,
            (string) ( $row['question_label'] ?? '' ),
            (string) ( $row['question_type']  ?? '' ),
            $csv_safe( ( $row['question_type'] ?? '' ) === 'matrix' && is_string( $row['answer_value'] ?? '' )
                ? ( function( $json ) use ( $matrix_row_labels ) {
                    $data = json_decode( $json, true );
                    if ( ! is_array( $data ) ) return $json;
                    $parts = [];
                    foreach ( $data as $k => $v ) {
                        $label = $matrix_row_labels[ $k ] ?? $k;
                        $parts[] = $label . '=' . (int) $v;
                    }
                    return implode( '; ', $parts );
                } )( $row['answer_value'] )
                : ( $row['answer_value'] ?? '' ) ),
            $csv_safe( $row['answer_label'] ?? '' ),
            (int)    $row['time_spent_seconds'],
        ] );
    }

    fclose( $out );
    exit;
}

/* ─────────────────────────────────────────────────────────────────────────
   RESULTADOS PAGE
───────────────────────────────────────────────────────────────────────── */
function estandar_surveys_results_page(): void {
    if ( ! current_user_can('manage_options') ) { wp_die('No autorizado'); }

    global $wpdb;
    $forms_table = $wpdb->prefix . 'estandar_forms';
    $survey_id   = isset( $_GET['survey_id'] ) ? (int) $_GET['survey_id'] : 0;

    if ( $survey_id <= 0 ) {
        wp_safe_redirect( admin_url('admin.php?page=estandar-surveys') );
        exit;
    }

    $survey = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $forms_table WHERE id = %d AND form_type = 'survey'",
        $survey_id
    ) );

    if ( ! $survey ) { wp_die('Encuesta no encontrada.'); }

    if ( ! class_exists('Estandar_Survey_Analytics') ) {
        wp_die('Analytics no disponible.');
    }

    $page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $per_pg   = 25;
    $data     = Estandar_Survey_Analytics::get_responses_page( $survey_id, $page, $per_pg );
    $total    = (int) $data['total'];
    $pages    = $total > 0 ? (int) ceil( $total / $per_pg ) : 1;
    $sessions = $data['sessions'];

    // Load matrix row labels and order from survey structure (once per page)
    $matrix_rows_ordered = []; // [{id, label}]
    $structure_raw = $wpdb->get_var( $wpdb->prepare(
        "SELECT fields_json FROM {$wpdb->prefix}estandar_forms WHERE id = %d",
        $survey_id
    ) );
    if ( $structure_raw ) {
        $structure = json_decode( $structure_raw, true );
        if ( is_array( $structure ) ) {
            foreach ( $structure as $field ) {
                if ( ( $field['type'] ?? '' ) === 'matrix' && isset( $field['rows'] ) ) {
                    foreach ( $field['rows'] as $row_def ) {
                        $matrix_rows_ordered[] = [
                            'id'    => $row_def['id'],
                            'label' => $row_def['label'] ?? $row_def['id'],
                        ];
                    }
                }
            }
        }
    }

    $export_url = wp_nonce_url(
        admin_url( 'admin-post.php?action=estandar_survey_export_csv&survey_id=' . $survey_id ),
        'estandar_survey_export_csv'
    );

    estandar_forms_enqueue_admin_assets();
    estandar_forms_render_admin_layout_start();
    ?>
    <?php estandar_forms_render_admin_surface_start(); ?>

    <?php estandar_forms_render_admin_tabs('estandar-surveys'); ?>
    <?php estandar_surveys_render_nav('results'); ?>

    <section class="estandar-admin-panel">
        <div class="estandar-admin-panel-header">
            <h3>Respuestas (<?php echo $total; ?>)</h3>
            <?php if ( $total > 0 ) : ?>
            <div>
                <a href="<?php echo esc_url($export_url); ?>"
                   class="button button-secondary">
                    ⬇ Exportar CSV
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ( empty($sessions) ) : ?>
        <div class="estandar-admin-empty" style="padding:32px;text-align:center;color:#6b7280">
            <p>Aún no hay respuestas para esta encuesta.</p>
        </div>
        <?php else : ?>

        <?php foreach ( $sessions as $sess_data ) :
            $sess = $sess_data['session'];
            $resps = $sess_data['responses'];
            $sess_status   = esc_html($sess['status'] ?? '');
            $sess_date     = esc_html(wp_date('d M Y H:i', strtotime($sess['started_at'] ?? '')));
            $sess_name     = esc_html($sess['respondent_name'] ?? '');
            $sess_email    = esc_html($sess['respondent_email'] ?? '');
            $sess_phone    = esc_html($sess['respondent_phone'] ?? '');
            $sess_id       = (int)$sess['id'];
            $answered      = (int)($sess['answered_questions'] ?? 0);
            $total_q       = (int)($sess['total_questions'] ?? 0);
        ?>
        <details style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:10px;overflow:hidden">
            <summary style="padding:14px 16px;cursor:pointer;display:flex;align-items:center;gap:10px;list-style:none;user-select:none">
                <span style="width:24px;height:24px;border-radius:50%;background:<?php echo $sess_status === 'completed' ? '#d1fae5' : '#fef3c7'; ?>;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0">
                    <?php echo $sess_status === 'completed' ? '✓' : '→'; ?>
                </span>
                <span style="font-weight:600;flex:1">
                    <?php echo $sess_name !== '' ? $sess_name : 'Anónimo #' . $sess_id; ?>
                    <?php if ($sess_email !== '') : ?>
                    <small style="font-weight:400;color:#6b7280"> — <?php echo $sess_email; ?></small>
                    <?php endif; ?>
                    <?php if ($sess_phone !== '') : ?>
                    <small style="font-weight:400;color:#6b7280"> — <?php echo $sess_phone; ?></small>
                    <?php endif; ?>
                </span>
                <span style="color:#6b7280;font-size:.82rem"><?php echo $answered; ?>/<?php echo $total_q; ?> respuestas</span>
                <span style="color:#9ca3af;font-size:.8rem"><?php echo $sess_date; ?></span>
                <span style="background:<?php echo $sess_status === 'completed' ? '#d1fae5' : '#fef3c7'; ?>;color:<?php echo $sess_status === 'completed' ? '#059669' : '#92400e'; ?>;padding:2px 10px;border-radius:50px;font-size:.75rem;font-weight:600">
                    <?php echo $sess_status === 'completed' ? 'Completada' : 'Iniciada'; ?>
                </span>
            </summary>
            <div style="padding:16px;background:#f9fafb;border-top:1px solid #e5e7eb">
                <?php if ( empty($resps) ) : ?>
                <p style="color:#6b7280;margin:0">Sin respuestas registradas.</p>
                <?php else : ?>
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr style="font-size:.8rem;color:#6b7280;text-align:left">
                            <th style="padding:6px 8px">#</th>
                            <th style="padding:6px 8px">Pregunta</th>
                            <th style="padding:6px 8px">Tipo</th>
                            <th style="padding:6px 8px">Respuesta</th>
                            <th style="padding:6px 8px">Tiempo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $resps as $resp ) :
                        $r_qi      = (int)($resp['question_index'] ?? 0) + 1;
                        $r_label   = esc_html($resp['question_label'] ?? '');
                        $r_type    = esc_html($resp['question_type'] ?? '');
                        $r_value   = $resp['answer_value'] ?? '';
                        $r_alabel  = $resp['answer_label'] ?? '';
                        $r_type    = $resp['question_type'] ?? '';

                        // Format matrix JSON answer_value
                        if ( $r_type === 'matrix' && is_string( $r_value ) && $r_value !== '' ) {
                            $matrix_data = json_decode( $r_value, true );
                            if ( is_array( $matrix_data ) && ! empty( $matrix_data ) ) {
                                $parts = [];
                                // Use editor's configured row order, not JSON key order
                                foreach ( $matrix_rows_ordered as $row_def ) {
                                    $row_id = $row_def['id'];
                                    if ( isset( $matrix_data[ $row_id ] ) ) {
                                        $parts[] = esc_html( $row_def['label'] ) . ': <strong>' . (int) $matrix_data[ $row_id ] . '</strong>';
                                    }
                                }
                                $r_display = implode( ' &middot; ', $parts );
                            } else {
                                $r_display = esc_html( $r_value );
                            }
                        } else {
                            $r_value_s  = esc_html( $r_value );
                            $r_alabel_s = esc_html( $r_alabel );
                            $r_display  = $r_alabel_s !== '' && $r_alabel_s !== $r_value_s ? $r_value_s . ' — ' . $r_alabel_s : $r_value_s;
                        }
                        $r_time    = (int)($resp['time_spent_seconds'] ?? 0);
                        $r_time_s  = $r_time > 0 ? $r_time . 's' : '—';
                    ?>
                    <tr style="border-top:1px solid #e5e7eb;font-size:.875rem">
                        <td style="padding:8px"><?php echo $r_qi; ?></td>
                        <td style="padding:8px"><?php echo $r_label; ?></td>
                        <td style="padding:8px"><code><?php echo $r_type; ?></code></td>
                        <td style="padding:8px;font-weight:600"><?php echo $r_display; ?></td>
                        <td style="padding:8px;color:#6b7280"><?php echo $r_time_s; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </details>
        <?php endforeach; ?>

        <?php if ( $pages > 1 ) : ?>
        <div style="margin-top:16px;display:flex;gap:6px;align-items:center">
            <?php for ( $p = 1; $p <= $pages; $p++ ) :
                $url = admin_url('admin.php?page=estandar-surveys-results&survey_id=' . $survey_id . '&paged=' . $p);
            ?>
            <a href="<?php echo esc_url($url); ?>"
               class="button<?php echo $p === $page ? ' button-primary' : ''; ?>">
                <?php echo $p; ?>
            </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </section>

    <?php
    estandar_forms_render_admin_surface_end();
    estandar_forms_render_admin_layout_end();
}
