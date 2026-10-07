<?php
/**
 * Admin — Leads list (v1.1).
 *
 * Variables disponibles (set por Estandar_Leads_Admin::leads_content()):
 *   $leads                 — array de lead objects
 *   $operators             — array de operadores activos (id, name)
 *   $created_link          — feedback URL mostrada tras "Enviar a operador" (puede estar vacía)
 *   $created_link_operator — nombre del operador al que se envió
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Mensajes de error por redirect ────────────────────────────────────────────
$el_errors = array(
    'no_lead'           => 'Lead no encontrado.',
    'no_operator'       => 'Este lead no tiene operador asignado. Selecciona un operador y espera la confirmación antes de enviar.',
    'operator_inactive' => 'El operador asignado está inactivo. Reactívalo o asigna otro.',
    'no_operator_email' => 'El operador asignado no tiene email configurado. Edítalo en Configuración.',
    'token_failed'      => 'No se pudo generar el token de clasificación. Inténtalo de nuevo.',
);
$el_error_key  = isset( $_GET['el_error'] )   ? sanitize_key( $_GET['el_error'] )  : '';
$el_bulk       = isset( $_GET['el_bulk'] )    ? sanitize_key( $_GET['el_bulk'] )   : '';
$el_deleted    = isset( $_GET['el_deleted'] ) ? absint( $_GET['el_deleted'] )       : 0;

// ── KPI — derivados del array $leads ya cargado, sin query adicional ──────────
$kpi_new        = 0;
$kpi_unassigned = 0;
$kpi_meeting    = 0;
$kpi_reserved   = 0;
$kpi_sales      = 0;

// Umbral de latencia: leads con status NEW hace más de 7 días.
$latency_threshold_days = 7;
$kpi_stale              = 0;
$latency_date_limit     = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $latency_threshold_days . ' days' ) );

foreach ( $leads as $lead ) {
    if ( $lead->status === 'NEW' )                    $kpi_new++;
    if ( empty( $lead->assigned_operator_id ) )       $kpi_unassigned++;
    if ( in_array( $lead->status, array( 'MEETING_BOOKED', 'PROPOSAL_SENT', 'NEGOTIATION', 'VISIT_SCHEDULED', 'VISITED' ), true ) ) $kpi_meeting++;
    if ( $lead->status === 'RESERVED' )               $kpi_reserved++;
    if ( in_array( $lead->status, array( 'DEED_SIGNED', 'DELIVERED' ), true ) ) $kpi_sales++;
    // Stale: NEW y sin mover en más de 7 días
    if ( $lead->status === 'NEW' && ! empty( $lead->last_seen_at ) && $lead->last_seen_at < $latency_date_limit ) {
        $kpi_stale++;
    }
}
?>

    <?php if ( $el_error_key && isset( $el_errors[ $el_error_key ] ) ) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html( $el_errors[ $el_error_key ] ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $el_bulk === 'deleted' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html( $el_deleted ); ?> lead<?php echo $el_deleted !== 1 ? 's' : ''; ?> eliminado<?php echo $el_deleted !== 1 ? 's' : ''; ?> correctamente.</p>
        </div>
    <?php elseif ( $el_bulk === 'empty' ) : ?>
        <div class="notice notice-warning is-dismissible">
            <p>Selecciona al menos un lead antes de aplicar la acción.</p>
        </div>
    <?php endif; ?>

    <?php if ( ! empty( $created_link ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php if ( $created_link_operator !== '' ) : ?>
                    <strong>Email enviado a <?php echo esc_html( $created_link_operator ); ?>.</strong><br>
                <?php endif; ?>
                Enlace de clasificación:<br>
                <input class="large-text" readonly style="margin-top:4px;"
                       value="<?php echo esc_attr( $created_link ); ?>">
            </p>
        </div>
    <?php endif; ?>

    <!-- ── KPI cards ─────────────────────────────────────────────────────── -->
    <?php if ( $kpi_stale > 0 ) : ?>
        <div class="notice notice-warning" style="margin:0 0 18px;">
            <p>
                <strong>Latencia operativa:</strong>
                <?php echo esc_html( $kpi_stale ); ?> lead<?php echo $kpi_stale > 1 ? 's' : ''; ?>
                en estado NEW sin clasificar por más de <?php echo esc_html( $latency_threshold_days ); ?> días.
                Cada día sin clasificar degrada la calidad de los reportes de resultado.
            </p>
        </div>
    <?php endif; ?>
    <div style="display:flex;gap:12px;margin:0 0 24px;flex-wrap:wrap;">
        <?php
        $kpis = array(
            array( 'label' => 'Nuevos',       'value' => $kpi_new,        'color' => '#2271b1' ),
            array( 'label' => 'Sin asignar',  'value' => $kpi_unassigned, 'color' => '#d63638' ),
            array( 'label' => 'En proceso',   'value' => $kpi_meeting,    'color' => '#996800' ),
            array( 'label' => 'Reservas',     'value' => $kpi_reserved,   'color' => '#996800' ),
            array( 'label' => 'Ventas',       'value' => $kpi_sales,      'color' => '#00a32a' ),
            array( 'label' => '+7d sin mover','value' => $kpi_stale,      'color' => $kpi_stale > 0 ? '#d63638' : '#c3c4c7' ),
        );
        foreach ( $kpis as $kpi ) : ?>
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px 20px;min-width:110px;text-align:center;">
                <div style="font-size:28px;font-weight:700;line-height:1.1;color:<?php echo esc_attr( $kpi['color'] ); ?>;">
                    <?php echo esc_html( $kpi['value'] ); ?>
                </div>
                <div style="font-size:12px;color:#646970;margin-top:4px;">
                    <?php echo esc_html( $kpi['label'] ); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Tabla operacional ─────────────────────────────────────────────── -->
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="es-bulk-form">
        <?php wp_nonce_field( 'estandar_leads_bulk_delete' ); ?>
        <input type="hidden" name="action" value="estandar_leads_bulk_delete">

        <!-- Bulk action bar -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
            <select name="bulk_action" id="es-bulk-action" style="min-width:180px;">
                <option value="">— Acción en lote —</option>
                <option value="export_basic">Exportar CSV básico</option>
                <option value="export_ai_context">Exportar CSV contexto IA</option>
                <option value="export_ai_context_jsonl">Exportar JSONL contexto IA</option>
                <option value="delete">Eliminar seleccionados</option>
            </select>
            <button type="submit" class="button" id="es-bulk-apply"
                    onclick="return esLeadsBulkConfirm(this);">Aplicar</button>
            <span id="es-bulk-count" style="color:#666;font-size:13px;"></span>
        </div>

    <table class="widefat striped" id="es-leads-table">
        <thead>
            <tr>
                <th style="width:32px;"><input type="checkbox" id="es-check-all" title="Seleccionar todos"></th>
                <th style="width:72px;color:#646970;">ID</th>
                <th>Lead</th>
                <th style="width:100px;">Origen</th>
                <th>Estado</th>
                <th>Operador</th>
                <th>Última actividad</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $status_labels = Estandar_Leads_Normalizer::status_labels();
        foreach ( $leads as $lead ) :
            $assigned_id  = (int) ( $lead->assigned_operator_id ?? 0 );
            $has_operator = $assigned_id > 0;
            $assigned_in_options = false;
            foreach ( $operators as $op_check ) {
                if ( (int) $op_check->id === $assigned_id ) {
                    $assigned_in_options = true;
                    break;
                }
            }
            $status_label = $status_labels[ $lead->status ] ?? $lead->status;
        ?>
            <tr id="es-lead-row-<?php echo esc_attr( $lead->id ); ?>">

                <td><input type="checkbox" name="lead_ids[]" value="<?php echo esc_attr( $lead->id ); ?>" class="es-lead-check"></td>
                <td style="color:#646970;font-size:12px;white-space:nowrap;">#<?php echo esc_html( $lead->id ); ?></td>
                <td>
                    <?php
                    $last_fields = array();
                    if ( ! empty( $lead->last_form_fields_json ) ) {
                        $decoded = json_decode( $lead->last_form_fields_json, true );
                        if ( is_array( $decoded ) ) {
                            $last_fields = $decoded;
                        }
                    }
                    $display_name = method_exists( 'Estandar_Leads_Email', 'full_lead_name' )
                        ? Estandar_Leads_Email::full_lead_name( (string) ( $lead->name ?: $lead->email ?: $lead->phone ), $last_fields )
                        : (string) ( $lead->name ?: $lead->email ?: $lead->phone );
                    ?>
                    <strong><?php echo esc_html( $display_name ); ?></strong>
                    <?php if ( ! empty( $lead->project_slug ) ) : ?>
                        <br><span style="font-size:11px;color:#718096;"><?php echo esc_html( $lead->project_slug ); ?></span>
                    <?php endif; ?>
                </td>

                <td>
                    <?php
                    $src_plugin = (string) ( $lead->first_source_plugin ?? '' );
                    $src_map    = array(
                        'estandar-chatbot' => array( 'Chatbot IA',     'chatbot' ),
                        'estandar-forms'   => array( 'Formulario Web', 'form' ),
                        'manual'           => array( 'Manual',         'manual' ),
                        'api'              => array( 'API',            'api' ),
                    );
                    list( $src_label, $src_mod ) = $src_map[ $src_plugin ] ?? array( $src_plugin ?: '—', 'unknown' );
                    ?>
                    <span class="es-origin-badge es-origin-<?php echo esc_attr( $src_mod ); ?>">
                        <?php echo esc_html( $src_label ); ?>
                    </span>
                </td>

                <td><?php echo esc_html( $status_label ); ?></td>

                <td>
                    <select
                        class="es-operator-select"
                        data-lead-id="<?php echo esc_attr( $lead->id ); ?>"
                        style="max-width:160px;"
                    >
                        <option value="0"<?php selected( 0, $assigned_id ); ?>>— Sin operador —</option>
                        <?php if ( $assigned_id > 0 && ! $assigned_in_options ) : ?>
                            <?php $historical_operator_name = (string) ( $lead->assigned_operator_name ?? '' ); ?>
                            <option value="<?php echo esc_attr( $assigned_id ); ?>" selected>Operador histórico eliminado<?php echo $historical_operator_name !== '' ? ': ' . esc_html( $historical_operator_name ) : ''; ?></option>
                        <?php endif; ?>
                        <?php foreach ( $operators as $op ) : ?>
                            <option value="<?php echo esc_attr( $op->id ); ?>"<?php selected( (int) $op->id, $assigned_id ); ?>>
                                <?php echo esc_html( $op->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="es-assign-status"
                          id="es-assign-<?php echo esc_attr( $lead->id ); ?>"
                          style="display:block;font-size:11px;color:#666;margin-top:2px;min-height:14px;">
                        <?php if ( $has_operator && ! empty( $lead->assigned_at ) ) :
                            echo esc_html( Estandar_Leads_Normalizer::datetime_local( $lead->assigned_at ) );
                        endif; ?>
                    </span>
                </td>

                <td style="white-space:nowrap;font-size:13px;color:#646970;">
                    <?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $lead->last_seen_at ) ); ?>
                </td>

                <td>
                    <!-- No <form> here — nested forms break HTML and corrupt the bulk-delete POST. -->
                    <!-- JS submits #es-send-operator-form (outside the table) on click. -->
                    <button
                        type="button"
                        class="button es-create-link-btn"
                        id="es-link-btn-<?php echo esc_attr( $lead->id ); ?>"
                        data-lead-id="<?php echo esc_attr( $lead->id ); ?>"
                        <?php echo ! $has_operator ? 'disabled title="Asigna un operador primero"' : ''; ?>
                    >Enviar a operador</button>
                </td>

            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </form><!-- /#es-bulk-form -->

<!-- Hidden singleton form for "Enviar a operador".
     Kept outside es-bulk-form to avoid nested-form HTML invalidity.
     JS populates #es-send-operator-lead-id and submits on button click. -->
<form method="post"
      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
      id="es-send-operator-form"
      style="display:none;">
    <?php wp_nonce_field( 'estandar_leads_create_link' ); ?>
    <input type="hidden" name="action"  value="estandar_leads_create_link">
    <input type="hidden" name="lead_id" value="" id="es-send-operator-lead-id">
</form>

<script>
(function ($) {
    var ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( 'estandar_assign_operator' ) ); ?>;

    // ── Bulk: select all ───────────────────────────────────────────────────
    $('#es-check-all').on('change', function () {
        $('.es-lead-check').prop('checked', this.checked);
        esLeadsUpdateBulkCount();
    });

    $(document).on('change', '.es-lead-check', function () {
        var total   = $('.es-lead-check').length;
        var checked = $('.es-lead-check:checked').length;
        $('#es-check-all').prop('indeterminate', checked > 0 && checked < total)
                          .prop('checked', checked === total);
        esLeadsUpdateBulkCount();
    });

    function esLeadsUpdateBulkCount() {
        var n = $('.es-lead-check:checked').length;
        $('#es-bulk-count').text(n > 0 ? n + ' seleccionado' + (n > 1 ? 's' : '') : '');
    }

    // ── Bulk: confirm before submit ────────────────────────────────────────

    // ── "Enviar a operador" — singleton form outside the table ────────────
    // Buttons inside the table carry data-lead-id; clicking one populates
    // #es-send-operator-form and submits it. No nested <form> needed.
    $(document).on('click', '.es-create-link-btn', function () {
        var leadId = $(this).data('lead-id');
        if ( ! leadId ) { return; }
        $('#es-send-operator-lead-id').val(leadId);
        $('#es-send-operator-form').trigger('submit');
    });

    $('#es-leads-table').on('change', '.es-operator-select', function () {
        var $select    = $(this);
        var leadId     = $select.data('lead-id');
        var operatorId = $select.val();
        var $status    = $('#es-assign-' + leadId);
        var $linkBtn   = $('#es-link-btn-' + leadId);

        $select.prop('disabled', true);
        $status.text('Guardando…');

        $.post(
            ajaxurl,
            {
                action:      'estandar_assign_operator',
                nonce:       ajaxNonce,
                lead_id:     leadId,
                operator_id: operatorId
            },
            function (response) {
                $select.prop('disabled', false);

                if (response.success) {
                    var name = response.data.operator_name;
                    if (name) {
                        $status.text('✓ Asignado');
                        $linkBtn.prop('disabled', false).removeAttr('title');
                        esLeadsToast('Operador asignado correctamente.');
                    } else {
                        $status.text('');
                        $linkBtn.prop('disabled', true).attr('title', 'Asigna un operador primero');
                        esLeadsToast('Asignación removida.');
                    }
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : 'Error desconocido.';
                    $status.text('✗ ' + msg);
                    esLeadsToast('Error: ' + msg, true);
                    window.location.reload();
                }
            }
        ).fail(function () {
            $select.prop('disabled', false);
            $status.text('✗ Error de conexión.');
            esLeadsToast('Error de conexión. Inténtalo de nuevo.', true);
        });
    });

    window.esLeadsBulkConfirm = function (btn) {
        var action  = $('#es-bulk-action').val();
        var checked = $('.es-lead-check:checked').length;

        if (!action) {
            alert('Selecciona una acción en lote.');
            return false;
        }
        if (checked === 0) {
            alert('Selecciona al menos un lead.');
            return false;
        }
        if (action === 'delete') {
            return confirm(
                '¿Eliminar ' + checked + ' lead' + (checked > 1 ? 's' : '') + '?\n\n' +
                'Esta acción borrará también submissions, eventos, clasificaciones y contactos asociados. ' +
                'No se puede deshacer.'
            );
        }
        return true;
    };

    function esLeadsToast(msg, isError) {
        var bg = isError ? '#b32d2e' : '#1d2327';
        var $toast = $(
            '<div style="position:fixed;bottom:24px;right:24px;background:' + bg + ';color:#fff;' +
            'padding:10px 18px;border-radius:4px;font-size:13px;line-height:1.4;max-width:320px;' +
            'box-shadow:0 2px 8px rgba(0,0,0,.35);opacity:0;transform:translateY(8px);' +
            'transition:opacity .25s,transform .25s;z-index:99999;">' + $('<span>').text(msg).html() + '</div>'
        ).appendTo('body');

        $toast[0].offsetHeight;
        $toast.css({ opacity: 1, transform: 'translateY(0)' });

        setTimeout(function () {
            $toast.css({ opacity: 0, transform: 'translateY(8px)' });
            setTimeout(function () { $toast.remove(); }, 300);
        }, 2800);
    }
})(jQuery);
</script>
