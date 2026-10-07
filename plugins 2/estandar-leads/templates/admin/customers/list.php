<?php
/**
 * Customer Registry — List page.
 *
 * Variables injected by Estandar_Leads_Customers_Admin::render_list():
 *   array[]  $customers
 *   string[] $projects
 *   string[] $stages
 *   int      $total
 *   int      $total_pages
 *   int      $paged
 *   string   $search
 *   string   $project
 *   string   $stage
 *   string   $matched
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$base_url      = admin_url( 'admin.php?page=estandar-customers' );
$rec_all       = isset( $_GET['rec_all'] ) ? (int) $_GET['rec_all'] : -1;
$rec_remaining = isset( $_GET['rec_remaining'] ) ? (int) $_GET['rec_remaining'] : -1;
$bulk_deleted  = isset( $_GET['bulk_deleted']   ) ? (int) $_GET['bulk_deleted']   : -1;
$bulk_recon    = isset( $_GET['bulk_reconciled'] ) ? (int) $_GET['bulk_reconciled'] : -1;
$bf_request    = is_array( $backfill['request'] ?? null ) ? $backfill['request'] : array();
$bf_mode       = ( $bf_request['mode'] ?? '' ) === 'historical' ? 'historical' : 'reconciled';
$bf_global_date = (string) ( $bf_request['global_date'] ?? '' );
$bf_project    = (string) ( $bf_request['project'] ?? $project );
$bf_stage      = (string) ( $bf_request['stage'] ?? $stage );
$bf_limit      = (int) ( $bf_request['limit'] ?? 200 );
$embedded = function_exists( 'estandar_core_is_embedded_admin' ) && estandar_core_is_embedded_admin();
?>
<?php if ( ! $embedded ) : ?>
<div class="wrap">
<h1 class="wp-heading-inline">Clientes</h1>
<?php endif; ?>
<a href="<?php echo esc_url( Estandar_Leads_Customers_Admin::edit_url() ); ?>" class="page-title-action">+ Nuevo cliente</a>
<a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-customers&view=import' ) ); ?>" class="page-title-action">Importar</a>
<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=estandar_customers_export_csv' . ( $project !== '' ? '&project=' . rawurlencode( $project ) : '' ) . ( $stage !== '' ? '&stage=' . rawurlencode( $stage ) : '' ) . ( $matched !== '' ? '&matched=' . rawurlencode( $matched ) : '' ) . ( $search !== '' ? '&s=' . rawurlencode( $search ) : '' ) ), 'estandar_customers_export_csv' ) ); ?>" class="page-title-action">Exportar CSV</a>
<?php if ( ! $embedded ) : ?>
<hr class="wp-header-end">
<?php endif; ?>

<?php if ( $rec_all >= 0 ) : ?>
<?php if ( $rec_remaining > 0 ) : ?>
<div class="notice notice-warning is-dismissible">
    <p>Lote procesado: <strong><?php echo $rec_all; ?></strong> cliente(s) vinculados. Quedan <strong><?php echo $rec_remaining; ?></strong> sin vincular. Ejecute nuevamente para continuar.</p>
</div>
<?php else : ?>
<div class="notice notice-success is-dismissible"><p>Reconciliación completa: <strong><?php echo $rec_all; ?></strong> cliente(s) vinculados.</p></div>
<?php endif; ?>
<?php endif; ?>
<?php if ( $bulk_deleted >= 0 ) : ?>
<div class="notice notice-success is-dismissible"><p><strong><?php echo $bulk_deleted; ?></strong> cliente(s) eliminados.</p></div>
<?php endif; ?>
<?php if ( $bulk_recon >= 0 ) : ?>
<div class="notice notice-success is-dismissible"><p><strong><?php echo $bulk_recon; ?></strong> cliente(s) vinculados por reconciliación.</p></div>
<?php endif; ?>

<!-- Filters -->
<form method="get" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="page" value="estandar-customers">
    <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Nombre, email, teléfono, RUT…" style="width:220px;">
    <select name="project">
        <option value="">Todos los proyectos</option>
        <?php foreach ( $projects as $p ) : ?>
        <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $project, $p ); ?>><?php echo esc_html( $p ); ?></option>
        <?php endforeach; ?>
    </select>
    <select name="stage">
        <option value="">Todas las etapas</option>
        <?php foreach ( $stages as $st ) : ?>
        <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $stage, $st ); ?>><?php echo esc_html( $st ); ?></option>
        <?php endforeach; ?>
    </select>
    <select name="matched">
        <option value="">Todos</option>
        <option value="yes" <?php selected( $matched, 'yes' ); ?>>Vinculados</option>
        <option value="no"  <?php selected( $matched, 'no'  ); ?>>Sin vincular</option>
    </select>
    <?php submit_button( 'Filtrar', 'secondary', '', false ); ?>
    <?php if ( $search || $project || $stage || $matched ) : ?>
    <a href="<?php echo esc_url( $base_url ); ?>" class="button">Limpiar</a>
    <?php endif; ?>
</form>

<!-- Reconcile All -->
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
    <?php wp_nonce_field( 'estandar_customers_reconcile_all' ); ?>
    <input type="hidden" name="action" value="estandar_customers_reconcile_all">
    <button type="submit" class="button">Reconciliar todos</button>
    <span style="color:#666;font-size:12px;margin-left:8px;"><?php echo esc_html( $total ); ?> cliente(s) en total</span>
</form>

<!-- Backfill histórico a Meta (CUSTOMER_BACKFILL_V1) -->
<?php if ( is_array( $backfill ) ) : ?>
<div class="notice <?php echo ! empty( $backfill['dry_run'] ) ? 'notice-info' : 'notice-success'; ?>">
    <p>
        <strong><?php echo ! empty( $backfill['dry_run'] ) ? 'Simulación (no se envió nada a Meta)' : 'Envío a Meta ejecutado'; ?>.</strong>
        Modo: <strong><?php echo ( $backfill['mode'] ?? '' ) === 'historical' ? 'Histórico sin lead' : 'Reconciliados con lead'; ?></strong> ·
        Elegibles: <strong><?php echo (int) $backfill['eligible']; ?></strong> ·
        Ya enviados: <strong><?php echo (int) $backfill['duplicate']; ?></strong> ·
        Omitidos: <strong><?php echo (int) $backfill['skipped']; ?></strong>
        <?php if ( empty( $backfill['dry_run'] ) ) : ?>
        · Enviados: <strong><?php echo (int) $backfill['sent']; ?></strong>
        · Reintentables: <strong><?php echo (int) $backfill['retry']; ?></strong>
        · Fallidos: <strong><?php echo (int) $backfill['failed']; ?></strong>
        <?php endif; ?>
    </p>
    <?php if ( ! empty( $backfill['reasons'] ) ) : ?>
    <p style="color:#666;font-size:12px;">
        Motivos de omisión:
        <?php
        $parts = array();
        foreach ( (array) $backfill['reasons'] as $reason => $count ) {
            $parts[] = esc_html( $reason ) . ': ' . (int) $count;
        }
        echo implode( ' · ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
        ?>
    </p>
<?php endif; ?>
<?php if ( ! $embedded ) : ?>
</div>
<?php endif; ?>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;padding:10px;border:1px solid #dcdcde;background:#fff;">
    <?php wp_nonce_field( 'estandar_customers_meta_backfill' ); ?>
    <input type="hidden" name="action" value="estandar_customers_meta_backfill">
    <strong style="margin-right:8px;">Backfill histórico a Meta (Purchase)</strong>
    <select name="bf_backfill_mode" title="Reconciliados: identidad y atribución desde el lead canónico. Histórico: solo clientes sin lead, identidad del registro de cliente, sin atribución inventada."
        onchange="document.getElementById('es-bf-global-date').style.display = this.value === 'historical' ? '' : 'none';">
        <option value="reconciled" <?php selected( $bf_mode, 'reconciled' ); ?>>Reconciliados con lead</option>
        <option value="historical" <?php selected( $bf_mode, 'historical' ); ?>>Histórico sin lead</option>
    </select>
    <input type="date" name="bf_global_date" id="es-bf-global-date" value="<?php echo esc_attr( $bf_global_date ); ?>" style="<?php echo $bf_mode === 'historical' ? '' : 'display:none;'; ?>"
        max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"
        title="Fecha de conversión global (opcional, solo modo histórico): se usa únicamente para clientes sin fecha de cierre propia. Solo aplica a esta ejecución; no se guarda.">
    <select name="bf_project">
        <option value="">Todos los proyectos</option>
        <?php foreach ( $projects as $p ) : ?>
        <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $bf_project, $p ); ?>><?php echo esc_html( $p ); ?></option>
        <?php endforeach; ?>
    </select>
    <select name="bf_stage">
        <option value="">Todas las etapas</option>
        <?php foreach ( $stages as $st ) : ?>
        <option value="<?php echo esc_attr( $st ); ?>" <?php selected( $bf_stage, $st ); ?>><?php echo esc_html( $st ); ?></option>
        <?php endforeach; ?>
    </select>
    <input type="number" name="bf_limit" value="<?php echo esc_attr( $bf_limit ); ?>" min="1" max="1000" style="width:90px;" title="Máximo de clientes a procesar">
    <button type="submit" name="mode" value="dry" class="button">Simular</button>
    <button type="submit" name="mode" value="send" class="button button-primary"
        onclick="return confirm('Se enviarán eventos Purchase reales a Meta para los clientes elegibles. ¿Continuar?');">Enviar a Meta</button>
    <span style="color:#666;font-size:12px;margin-left:8px;">
        Reconciliados: solo clientes con lead válido. Histórico: solo clientes sin lead, con email o teléfono, proyecto y fecha de cierre (propia, o la fecha global si no tiene). No modifica leads ni clientes. Reejecutar no duplica.
    </span>
</form>

<?php if ( empty( $customers ) ) : ?>
<p>Sin resultados<?php echo $search ? ' para "<strong>' . esc_html( $search ) . '</strong>"' : ''; ?>.</p>
<?php else : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="es-customers-bulk-form">
    <?php wp_nonce_field( 'estandar_customers_bulk' ); ?>

    <!-- Bulk action bar -->
    <div class="tablenav top" style="display:flex;align-items:center;gap:8px;margin:8px 0;">
        <select name="bulk_action" id="es-bulk-action">
            <option value="">— Acción masiva —</option>
            <option value="reconcile">Reconciliar seleccionados</option>
            <option value="delete">Eliminar seleccionados</option>
        </select>
        <button type="submit" class="button" id="es-bulk-apply">Aplicar</button>
        <span id="es-bulk-count" style="color:#666;font-size:12px;"></span>
    </div>

    <table class="wp-list-table widefat fixed striped" style="margin-top:0;">
    <thead>
    <tr>
        <th style="width:32px"><input type="checkbox" id="es-select-all" title="Seleccionar todos"></th>
        <th style="width:150px">Nombre</th>
        <th>Email</th>
        <th>Teléfono</th>
        <th>Proyecto</th>
        <th>Etapa</th>
        <th style="width:70px">Lead</th>
        <th style="width:70px">Confianza</th>
        <th style="width:110px">Actualizado</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ( $customers as $c ) :
        $edit_url  = Estandar_Leads_Customers_Admin::edit_url( (int) $c['id'] );
        $full_name = trim( esc_html( $c['first_name'] ) . ' ' . esc_html( $c['last_name'] ) );
        $conf_map  = array( 'high' => '🟢', 'medium' => '🟡', 'low' => '🔴', '' => '—' );
        $conf      = $conf_map[ $c['matched_confidence'] ?? '' ] ?? '—';
        $upd       = $c['updated_at'] ? date_i18n( 'd M Y', strtotime( $c['updated_at'] ) ) : '—';
    ?>
    <tr>
        <td><input type="checkbox" name="customer_ids[]" value="<?php echo (int) $c['id']; ?>" class="es-row-check"></td>
        <td><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo $full_name ?: '—'; ?></a></td>
        <td><?php echo esc_html( $c['email'] ); ?></td>
        <td><?php echo esc_html( ! empty( $c['phone'] ) ? Estandar_Leads_Normalizer::phone_display( $c['phone'] ) : '' ); ?></td>
        <td><?php echo esc_html( $c['project_slug'] ); ?></td>
        <td><?php echo esc_html( $c['customer_stage'] ); ?></td>
        <td><?php echo $c['matched_lead_id'] ? '#' . (int) $c['matched_lead_id'] : '<span style="color:#aaa">—</span>'; ?></td>
        <td><?php echo $conf; ?></td>
        <td><?php echo esc_html( $upd ); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>

    <?php if ( $total_pages > 1 ) :
        $page_links = paginate_links( array(
            'base'      => add_query_arg( 'paged', '%#%' ),
            'format'    => '',
            'current'   => $paged,
            'total'     => $total_pages,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ) );
        echo '<div class="tablenav bottom"><div class="tablenav-pages">' . $page_links . '</div></div>';
    endif; ?>
</form>

<script>
(function(){
    var selectAll = document.getElementById('es-select-all');
    var form      = document.getElementById('es-customers-bulk-form');
    var counter   = document.getElementById('es-bulk-count');
    function updateCount(){
        var n = form.querySelectorAll('.es-row-check:checked').length;
        counter.textContent = n > 0 ? n + ' seleccionado(s)' : '';
    }
    selectAll.addEventListener('change', function(){
        form.querySelectorAll('.es-row-check').forEach(function(cb){ cb.checked = selectAll.checked; });
        updateCount();
    });
    form.addEventListener('change', function(e){
        if(e.target.classList.contains('es-row-check')) updateCount();
    });
    form.addEventListener('submit', function(e){
        var action = document.getElementById('es-bulk-action').value;
        if(!action){ e.preventDefault(); return; }
        var checked = form.querySelectorAll('.es-row-check:checked').length;
        if(checked === 0){ e.preventDefault(); alert('Selecciona al menos un cliente.'); return; }
        if(action === 'delete' && !confirm('¿Eliminar ' + checked + ' cliente(s)? Esta acción no se puede deshacer.')){ e.preventDefault(); return; }
        form.querySelector('[name="action"]') && form.querySelector('[name="action"]').remove();
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'action';
        input.value = action === 'delete' ? 'estandar_customers_bulk_delete' : 'estandar_customers_bulk_reconcile';
        form.appendChild(input);
    });
})();
</script>
<?php endif; ?>
</div>
