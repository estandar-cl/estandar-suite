<?php
if ( ! defined('ABSPATH') ) exit;

function estandar_forms_entry_value(array $data, array $keys): string {
    foreach ( $keys as $key ) {
        if ( isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '' ) {
            return sanitize_text_field((string) $data[$key]);
        }
    }

    return '';
}

function estandar_forms_entry_label(string $key): string {
    $label = str_replace(['_', '-'], ' ', $key);
    $label = trim(preg_replace('/\s+/', ' ', $label));
    return $label !== '' ? ucwords($label) : $key;
}

function estandar_forms_entry_display_value($value): string {
    if ( is_bool($value) ) {
        return $value ? 'Sí' : 'No';
    }

    if ( is_scalar($value) || $value === null ) {
        $text = trim((string) $value);
        return $text !== '' ? $text : '—';
    }

    return estandar_forms_pretty_json($value);
}

function estandar_forms_local_datetime($value): string {
    $value = trim((string) $value);
    if ( $value === '' ) {
        return '';
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return wp_date('d/m/Y H:i', $dt->getTimestamp(), wp_timezone());
    } catch ( Exception $e ) {
        return $value;
    }
}

function estandar_forms_pretty_json($value): string {
    $decoded = is_array($value) ? $value : json_decode((string) $value, true);
    if ( ! is_array($decoded) ) {
        return (string) $value;
    }

    $json = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '';
}

function estandar_forms_table_exists(string $table): bool {
    global $wpdb;
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    return (string) $found === $table;
}

function estandar_forms_render_kv_row(string $label, $value): void {
    echo '<tr><th style="width:220px;">' . esc_html($label) . '</th><td>';
    if ( is_array($value) ) {
        echo '<pre style="white-space:pre-wrap;margin:0;max-height:280px;overflow:auto;">' . esc_html(estandar_forms_pretty_json($value)) . '</pre>';
    } else {
        echo esc_html(estandar_forms_entry_display_value($value));
    }
    echo '</td></tr>';
}

function estandar_forms_request_entry_ids($value): array {
    $raw = is_array($value) ? $value : [$value];
    $ids = array_map('absint', $raw);
    return array_values(array_unique(array_filter($ids)));
}

function estandar_forms_get_entries_for_export(array $entry_ids = []): array {
    global $wpdb;
    $entries_table = $wpdb->prefix . 'estandar_form_entries';
    $forms_table = $wpdb->prefix . 'estandar_forms';
    $lead_submissions_table = $wpdb->prefix . 'es_lead_submissions';
    $has_submissions = estandar_forms_table_exists($lead_submissions_table);

    $select = "SELECT e.id, e.form_id, e.created_at, e.status, e.data_json, f.name AS form_name";
    $join = " FROM {$entries_table} e LEFT JOIN {$forms_table} f ON f.id = e.form_id";

    if ( $has_submissions ) {
        $select .= ", s.id AS submission_id, s.lead_id";
        $join .= " LEFT JOIN (
                SELECT source_entry_id, MAX(id) AS id
                FROM {$lead_submissions_table}
                WHERE source_entry_id IS NOT NULL
                GROUP BY source_entry_id
            ) latest_submission ON latest_submission.source_entry_id = e.id
            LEFT JOIN {$lead_submissions_table} s ON s.id = latest_submission.id";
    } else {
        $select .= ", NULL AS submission_id, NULL AS lead_id";
    }

    $where = '';
    if ( ! empty($entry_ids) ) {
        $where = ' WHERE e.id IN (' . implode(',', array_map('absint', $entry_ids)) . ')';
    }

    $rows = $wpdb->get_results($select . $join . $where . ' ORDER BY e.created_at DESC', ARRAY_A);
    return is_array($rows) ? $rows : [];
}

function estandar_forms_send_entries_csv(array $rows): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=estandar-forms-entries-' . gmdate('Ymd-His') . '.csv');
    $out = fopen('php://output', 'w');
    if ( $out ) {
        fputcsv($out, ['Fecha', 'Formulario', 'Nombre', 'Correo', 'Estado']);
        foreach ( $rows as $row ) {
            $data = json_decode((string) ($row['data_json'] ?? ''), true);
            $data = is_array($data) ? $data : [];
            fputcsv($out, [
                estandar_forms_local_datetime($row['created_at'] ?? ''),
                (string) ($row['form_name'] ?? ''),
                estandar_forms_entry_value($data, ['nombre', 'name', 'full_name', 'fullname']),
                estandar_forms_entry_value($data, ['email', 'correo', 'mail']),
                (string) ($row['status'] ?? ''),
            ]);
        }
        fclose($out);
    }
    exit;
}

function estandar_forms_send_entries_json(array $rows): void {
    $entries = [];
    foreach ( $rows as $row ) {
        $data = json_decode((string) ($row['data_json'] ?? ''), true);
        $entries[] = [
            'entry_id'      => absint($row['id'] ?? 0),
            'form_id'       => absint($row['form_id'] ?? 0),
            'form_name'     => (string) ($row['form_name'] ?? ''),
            'created_at'    => (string) ($row['created_at'] ?? ''),
            'created_local' => estandar_forms_local_datetime($row['created_at'] ?? ''),
            'status'        => (string) ($row['status'] ?? ''),
            'lead_id'       => ! empty($row['lead_id']) ? absint($row['lead_id']) : null,
            'submission_id' => ! empty($row['submission_id']) ? absint($row['submission_id']) : null,
            'data'          => is_array($data) ? $data : [],
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=estandar-forms-entries-' . gmdate('Ymd-His') . '.json');
    echo wp_json_encode(
        [
            'exported_at' => current_time('mysql'),
            'count'       => count($entries),
            'entries'     => $entries,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );
    exit;
}

function estandar_forms_handle_entries_export(): void {
    if ( ! current_user_can('manage_options') ) {
        return;
    }

    global $wpdb;
    $entries_table = $wpdb->prefix . 'estandar_form_entries';

    if ( isset($_GET['estandar_forms_export_entries']) ) {
        check_admin_referer('estandar_forms_export_entries', '_wpnonce');
        $entry_ids = isset($_GET['entry_id']) ? estandar_forms_request_entry_ids(wp_unslash($_GET['entry_id'])) : [];
        $format = isset($_GET['format']) ? sanitize_key((string) wp_unslash($_GET['format'])) : 'csv';
        $rows = estandar_forms_get_entries_for_export($entry_ids);
        if ( $format === 'json' ) {
            estandar_forms_send_entries_json($rows);
        }
        estandar_forms_send_entries_csv($rows);
    }

    if ( isset($_GET['estandar_forms_delete_entry']) ) {
        $entry_id = absint($_GET['estandar_forms_delete_entry']);
        check_admin_referer('estandar_forms_delete_entry_' . $entry_id, '_wpnonce');
        if ( $entry_id > 0 ) {
            $wpdb->delete($entries_table, ['id' => $entry_id], ['%d']);
        }
        wp_safe_redirect(add_query_arg(['page' => 'estandar-forms-entries', 'estandar_forms_entry_notice' => 'deleted'], admin_url('admin.php')));
        exit;
    }

    if ( isset($_POST['estandar_forms_entries_bulk_action']) ) {
        check_admin_referer('estandar_forms_entries_bulk', '_wpnonce');
        $action = sanitize_key((string) wp_unslash($_POST['estandar_forms_entries_bulk_action']));
        $entry_ids = isset($_POST['entry_ids']) ? estandar_forms_request_entry_ids(wp_unslash($_POST['entry_ids'])) : [];

        if ( empty($entry_ids) ) {
            wp_safe_redirect(add_query_arg(['page' => 'estandar-forms-entries', 'estandar_forms_entry_notice' => 'none_selected'], admin_url('admin.php')));
            exit;
        }

        if ( $action === 'export_csv' || $action === 'export_json' ) {
            $rows = estandar_forms_get_entries_for_export($entry_ids);
            if ( $action === 'export_json' ) {
                estandar_forms_send_entries_json($rows);
            }
            estandar_forms_send_entries_csv($rows);
        }

        if ( $action === 'delete' ) {
            $wpdb->query("DELETE FROM {$entries_table} WHERE id IN (" . implode(',', array_map('absint', $entry_ids)) . ')');
            wp_safe_redirect(add_query_arg(['page' => 'estandar-forms-entries', 'estandar_forms_entry_notice' => 'deleted'], admin_url('admin.php')));
            exit;
        }
    }
}
add_action('admin_init', 'estandar_forms_handle_entries_export');
