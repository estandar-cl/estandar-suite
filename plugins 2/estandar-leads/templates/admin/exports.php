<?php
/**
 * LEADS_EXPORT_CENTER_V1
 * Variables: $f (filtros), $total (int), $preview (array), $operators, $projects
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$base_url    = admin_url( 'admin.php?page=estandar-leads-exports' );
$nonce_field = wp_create_nonce( 'estandar_leads_export_csv' );

$source_options = array(
    ''                  => 'Todos',
    'estandar-chatbot'  => 'Chatbot',
    'estandar-forms'    => 'Formulario',
    'manual'            => 'Manual',
    'api'               => 'API',
);
$status_options = array(
    ''          => 'Todos',
    'none'      => 'Sin gestión',
    'contacted' => 'Contactado',
    'qualified' => 'Calificado',
    'reserved'  => 'Reservado',
    'converted' => 'Convertido',
    'lost'      => 'Perdido',
);
$preset_options = array(
    'all' => 'Todo el historial',
    '24h' => 'Últimas 24 horas',
    '7d'  => 'Últimos 7 días',
    '30d' => 'Últimos 30 días',
    '90d' => 'Últimos 90 días',
);

$preview_cols = array(
    'lead_id'        => 'ID',
    'submission_id'  => 'Subm.',
    'submitted_at'   => 'Fecha',
    'name'           => 'Nombre',
    'proyecto'       => 'Proyecto',
    'operador'       => 'Operador',
    'source_plugin'  => 'Origen',
    'utm_campaign'   => 'Campaña',
    'estado_comercial' => 'Estado',
);
?>
<style>
.ec-filters { display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px; }
.ec-filter-group { display:flex;flex-direction:column;gap:4px;min-width:140px; }
.ec-filter-group label { font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.4px; }
.ec-filter-group select,
.ec-filter-group input { min-width:140px; }
.ec-result-banner { display:flex;align-items:center;justify-content:space-between;
    padding:12px 16px;background:#f0f4ff;border:1px solid #c7d4f7;border-radius:6px;margin-bottom:18px; }
.ec-result-banner strong { font-size:15px;color:#1a3a8a; }
.ec-result-banner .ec-export-btn { }
.ec-preview-table { width:100%;border-collapse:collapse;font-size:12px; }
.ec-preview-table th { background:#f0f0f1;padding:6px 10px;text-align:left;font-size:11px;
    font-weight:600;color:#646970;border-bottom:1px solid #dcdcde;white-space:nowrap; }
.ec-preview-table td { padding:6px 10px;border-bottom:1px solid #f0f0f1;vertical-align:middle; }
.ec-preview-table tr:last-child td { border-bottom:none; }
/* origin badges: .es-origin-badge / .es-origin-{chatbot|form|manual|api|unknown}
   definidos en estandar-core/admin/admin.css (LEADS_TABLE_UX_V1) */
.ec-empty { padding:32px;text-align:center;color:#646970;font-size:13px; }
.ec-utm-group { border-top:1px dashed #e2e8f0;padding-top:10px;margin-top:10px; }
.ec-utm-group legend { font-size:11px;color:#999;margin-bottom:8px; }
</style>

<!-- ── Filtros ──────────────────────────────────────────────────────────── -->
<form method="get" action="<?php echo esc_url( $base_url ); ?>">
    <input type="hidden" name="page" value="estandar-leads-exports">

    <div class="ec-filters">

        <div class="ec-filter-group">
            <label>Período</label>
            <select name="preset" onchange="this.form.submit()">
                <?php foreach ( $preset_options as $val => $lbl ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $f['preset'], $val ); ?>>
                        <?php echo esc_html( $lbl ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ( $f['preset'] === 'all' ) : ?>
        <div class="ec-filter-group">
            <label>Desde</label>
            <input type="date" name="date_from" value="<?php echo esc_attr( $f['date_from'] ? substr( $f['date_from'], 0, 10 ) : '' ); ?>">
        </div>
        <div class="ec-filter-group">
            <label>Hasta</label>
            <input type="date" name="date_to" value="<?php echo esc_attr( $f['date_to'] ); ?>">
        </div>
        <?php endif; ?>

        <div class="ec-filter-group">
            <label>Origen</label>
            <select name="source">
                <?php foreach ( $source_options as $val => $lbl ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $f['source'], $val ); ?>>
                        <?php echo esc_html( $lbl ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ec-filter-group">
            <label>Proyecto</label>
            <select name="project">
                <option value="">Todos</option>
                <?php foreach ( $projects as $p ) : ?>
                    <option value="<?php echo esc_attr( $p->project_slug ); ?>"
                        <?php selected( $f['project'], $p->project_slug ); ?>>
                        <?php echo esc_html( $p->project_slug ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ec-filter-group">
            <label>Operador</label>
            <select name="operator_id">
                <option value="-1" <?php selected( $f['operator_id'], -1 ); ?>>Todos</option>
                <option value="0"  <?php selected( $f['operator_id'], 0 ); ?>>Sin operador</option>
                <?php foreach ( $operators as $op ) : ?>
                    <option value="<?php echo esc_attr( $op->id ); ?>"
                        <?php selected( $f['operator_id'], (int) $op->id ); ?>>
                        <?php echo esc_html( $op->name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ec-filter-group">
            <label>Estado</label>
            <select name="status">
                <?php foreach ( $status_options as $val => $lbl ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $f['status'], $val ); ?>>
                        <?php echo esc_html( $lbl ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ec-filter-group" style="justify-content:flex-end;">
            <label>&nbsp;</label>
            <button type="submit" class="button button-primary">Aplicar filtros</button>
        </div>
    </div>

    <!-- UTM opcionales -->
    <details style="margin-bottom:20px;">
        <summary style="cursor:pointer;font-size:12px;color:#646970;padding:4px 0;">Filtros de campaña (UTM)</summary>
        <div class="ec-filters" style="margin-top:10px;">
            <div class="ec-filter-group">
                <label>utm_source</label>
                <input type="text" name="utm_source" value="<?php echo esc_attr( $f['utm_source'] ); ?>" placeholder="google, meta...">
            </div>
            <div class="ec-filter-group">
                <label>utm_medium</label>
                <input type="text" name="utm_medium" value="<?php echo esc_attr( $f['utm_medium'] ); ?>" placeholder="cpc, paid...">
            </div>
            <div class="ec-filter-group">
                <label>utm_campaign</label>
                <input type="text" name="utm_campaign" value="<?php echo esc_attr( $f['utm_campaign'] ); ?>" placeholder="nombre de campaña">
            </div>
        </div>
    </details>
</form>

<!-- ── Banner resultado + botón exportar ───────────────────────────────── -->
<?php
// Build human-readable active filter summary for audibility.
$active_filters = array();
if ( $f['source'] !== '' )       $active_filters[] = 'Origen: ' . ( $source_options[ $f['source'] ] ?? $f['source'] );
if ( $f['project'] !== '' )      $active_filters[] = 'Proyecto: ' . $f['project'];
if ( $f['operator_id'] === 0 )   $active_filters[] = 'Operador: Sin asignar';
elseif ( $f['operator_id'] > 0 ) {
    $op_name = '';
    foreach ( $operators as $op ) { if ( (int) $op->id === $f['operator_id'] ) { $op_name = $op->name; break; } }
    $active_filters[] = 'Operador: ' . ( $op_name ?: '#' . $f['operator_id'] );
}
if ( $f['status'] !== '' )       $active_filters[] = 'Estado: ' . ( $status_options[ $f['status'] ] ?? $f['status'] );
if ( $f['utm_campaign'] !== '' ) $active_filters[] = 'Campaña: ' . $f['utm_campaign'];
if ( $f['utm_source'] !== '' )   $active_filters[] = 'utm_source: ' . $f['utm_source'];
if ( $f['utm_medium'] !== '' )   $active_filters[] = 'utm_medium: ' . $f['utm_medium'];
$period_label = $preset_options[ $f['preset'] ] ?? 'Todo el historial';
?>
<div class="ec-result-banner">
    <div>
        <strong>Registros encontrados: <?php echo esc_html( number_format( $total ) ); ?></strong>
        <span style="font-size:12px;color:#4a5568;margin-left:10px;"><?php echo esc_html( $period_label ); ?></span>
        <?php if ( ! empty( $active_filters ) ) : ?>
        <div style="margin-top:4px;font-size:11px;color:#718096;">
            <?php echo esc_html( implode( ' · ', $active_filters ) ); ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ( $total > 0 ) : ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;text-align:right;">
        <?php wp_nonce_field( 'estandar_leads_export_csv' ); ?>
        <input type="hidden" name="action"       value="estandar_leads_export_csv">
        <input type="hidden" name="preset"       value="<?php echo esc_attr( $f['preset'] ); ?>">
        <input type="hidden" name="date_from"    value="<?php echo esc_attr( $f['date_from'] ); ?>">
        <input type="hidden" name="date_to"      value="<?php echo esc_attr( $f['date_to'] ); ?>">
        <input type="hidden" name="source"       value="<?php echo esc_attr( $f['source'] ); ?>">
        <input type="hidden" name="project"      value="<?php echo esc_attr( $f['project'] ); ?>">
        <input type="hidden" name="operator_id"  value="<?php echo esc_attr( $f['operator_id'] ); ?>">
        <input type="hidden" name="status"       value="<?php echo esc_attr( $f['status'] ); ?>">
        <input type="hidden" name="utm_source"   value="<?php echo esc_attr( $f['utm_source'] ); ?>">
        <input type="hidden" name="utm_medium"   value="<?php echo esc_attr( $f['utm_medium'] ); ?>">
        <input type="hidden" name="utm_campaign" value="<?php echo esc_attr( $f['utm_campaign'] ); ?>">
        <button type="submit" name="export_mode" value="full" class="button button-primary ec-export-btn">
            ↓ CSV — <?php echo esc_html( number_format( $total ) ); ?> filas
        </button>
        <button type="submit" name="export_mode" value="client" class="button ec-export-btn" style="margin-left:6px;">
            ↓ CSV Cliente
        </button>
        <div style="font-size:10px;color:#718096;margin-top:3px;">
            El archivo contendrá exactamente <?php echo esc_html( number_format( $total ) ); ?> filas con los filtros actuales.
            <span style="margin-left:6px;font-family:monospace;color:#a0aec0;"
                  title="Hash de filtros — debe coincidir con export_csv en el log del servidor">
                #<?php echo esc_html( $filter_hash ?? '' ); ?>
            </span>
        </div>
    </form>
    <?php endif; ?>
</div>

<!-- ── Vista previa ────────────────────────────────────────────────────── -->
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;">
    <div style="padding:10px 14px;border-bottom:1px solid #e2e8f0;font-size:12px;color:#646970;">
        Vista previa — primeras <?php echo min( 10, count( $preview ) ); ?> filas de <?php echo esc_html( number_format( $total ) ); ?>
    </div>
    <?php if ( empty( $preview ) ) : ?>
        <div class="ec-empty">No hay registros con los filtros actuales.</div>
    <?php else : ?>
    <div style="overflow-x:auto;">
    <table class="ec-preview-table">
        <thead>
            <tr>
                <?php foreach ( $preview_cols as $col => $label ) : ?>
                    <th><?php echo esc_html( $label ); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $preview as $row ) : ?>
            <tr>
                <td style="font-weight:600;color:#2d3748;">#<?php echo esc_html( $row['lead_id'] ); ?></td>
                <td style="color:#718096;"><?php echo esc_html( $row['submission_id'] ); ?></td>
                <td style="white-space:nowrap;font-size:11px;color:#718096;">
                    <?php echo esc_html( isset( $row['submitted_at'] ) ? substr( $row['submitted_at'], 0, 16 ) : '' ); ?>
                </td>
                <td><?php echo esc_html( $row['name'] ?? '' ); ?></td>
                <td><?php echo esc_html( $row['proyecto'] ?? '' ); ?></td>
                <td><?php echo esc_html( $row['operador'] ?? '—' ); ?></td>
                <td>
                    <?php
                    $src = $row['source_plugin'] ?? '';
                    $tag_map = array(
                        'estandar-chatbot' => array( 'Chatbot IA',     'chatbot' ),
                        'estandar-forms'   => array( 'Formulario Web', 'form' ),
                        'manual'           => array( 'Manual',         'manual' ),
                        'api'              => array( 'API',            'api' ),
                    );
                    list( $lbl, $mod ) = $tag_map[ $src ] ?? array( $src ?: '—', 'unknown' );
                    echo '<span class="es-origin-badge es-origin-' . esc_attr( $mod ) . '">' . esc_html( $lbl ) . '</span>';
                    ?>
                </td>
                <td style="font-size:11px;color:#718096;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php echo esc_html( $row['utm_campaign'] ?? '—' ); ?>
                </td>
                <td><span style="font-size:11px;"><?php echo esc_html( $row['estado_comercial'] ?? '' ); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ( $total > 10 ) : ?>
        <div style="padding:8px 14px;font-size:12px;color:#718096;border-top:1px solid #f0f0f1;">
            + <?php echo esc_html( number_format( $total - 10 ) ); ?> registros adicionales incluidos en el CSV.
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
