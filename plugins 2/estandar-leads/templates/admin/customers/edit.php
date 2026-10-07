<?php
/**
 * Customer Registry — Create / Edit page.
 *
 * Variables injected by Estandar_Leads_Customers_Admin::render_edit():
 *   array|null  $customer   — existing record or null for new
 *   array|null  $lead       — matched lead summary or null
 *   int         $id         — 0 for new
 *   int         $saved      — 1 after successful save
 *   int         $rec_done   — 1=matched 0=not found -1=not run
 *   string      $error
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$is_new = ( $id === 0 || $customer === null );
$v      = function( string $field ) use ( $customer ): string {
    return esc_attr( (string) ( $customer[ $field ] ?? '' ) );
};

$list_url = admin_url( 'admin.php?page=estandar-customers' );
$embedded = function_exists( 'estandar_core_is_embedded_admin' ) && estandar_core_is_embedded_admin();
?>
<?php if ( ! $embedded ) : ?>
<div class="wrap">
<h1><?php echo $is_new ? 'Nuevo cliente' : 'Editar cliente'; ?></h1>
<?php endif; ?>
<a href="<?php echo esc_url( $list_url ); ?>" style="margin-bottom:12px;display:inline-block;">&larr; Volver a la lista</a>

<?php if ( $saved ) : ?>
<div class="notice notice-success is-dismissible"><p>Cliente guardado correctamente.</p></div>
<?php endif; ?>
<?php if ( $rec_done === 1 ) : ?>
<div class="notice notice-success is-dismissible"><p>Lead vinculado correctamente.</p></div>
<?php elseif ( $rec_done === 0 ) : ?>
<div class="notice notice-warning is-dismissible"><p>No se encontró un lead coincidente.</p></div>
<?php endif; ?>
<?php if ( $error === 'save_failed' ) : ?>
<div class="notice notice-error is-dismissible"><p>Error al guardar. Inténtalo de nuevo.</p></div>
<?php endif; ?>

<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">

<!-- ── Editor ── -->
<div style="flex:1;min-width:360px;">
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'estandar_customers_save' ); ?>
    <input type="hidden" name="action" value="estandar_customers_save">
    <?php if ( ! $is_new ) : ?>
    <input type="hidden" name="id" value="<?php echo (int) $customer['id']; ?>">
    <?php endif; ?>

    <table class="form-table" role="presentation">
    <tr>
        <th><label for="company_id">Empresa (ID)</label></th>
        <td><input type="text" id="company_id" name="company_id" value="<?php echo $v('company_id'); ?>" class="regular-text"></td>
    </tr>
    <tr>
        <th><label for="project_slug">Proyecto</label></th>
        <td><input type="text" id="project_slug" name="project_slug" value="<?php echo $v('project_slug'); ?>" class="regular-text"></td>
    </tr>
    <tr>
        <th><label for="external_id">ID externo</label></th>
        <td><input type="text" id="external_id" name="external_id" value="<?php echo $v('external_id'); ?>" class="regular-text"></td>
    </tr>
    <tr>
        <th><label for="customer_stage">Etapa</label></th>
        <td><input type="text" id="customer_stage" name="customer_stage" value="<?php echo $v('customer_stage'); ?>" class="regular-text" placeholder="Ej: prospecto, activo, cerrado"></td>
    </tr>
    <tr>
        <th><label for="first_name">Nombre</label></th>
        <td><input type="text" id="first_name" name="first_name" value="<?php echo $v('first_name'); ?>" class="regular-text"></td>
    </tr>
    <tr>
        <th><label for="last_name">Apellido</label></th>
        <td><input type="text" id="last_name" name="last_name" value="<?php echo $v('last_name'); ?>" class="regular-text"></td>
    </tr>
    <tr>
        <th><label for="email">Email</label></th>
        <td><input type="email" id="email" name="email" value="<?php echo $v('email'); ?>" class="regular-text"></td>
    </tr>
    <tr>
        <th><label for="phone">Teléfono</label></th>
        <td><input type="text" id="phone" name="phone" value="<?php echo $v('phone'); ?>" class="regular-text"></td>
    </tr>
    <tr>
        <th><label for="rut">RUT</label></th>
        <td><input type="text" id="rut" name="rut" value="<?php echo $v('rut'); ?>" class="regular-text" placeholder="12345678-9"></td>
    </tr>
    <tr>
        <th><label for="organization">Organización</label></th>
        <td><input type="text" id="organization" name="organization" value="<?php echo $v('organization'); ?>" class="regular-text"></td>
    </tr>
    <tr>
        <th><label for="amount">Monto</label></th>
        <td><input type="number" id="amount" name="amount" value="<?php echo esc_attr( $customer['amount'] ?? '' ); ?>" step="0.01" class="regular-text"></td>
    </tr>
    <tr>
        <th><label for="started_at">Inicio</label></th>
        <td><input type="datetime-local" id="started_at" name="started_at" value="<?php echo esc_attr( $customer['started_at'] ? str_replace( ' ', 'T', substr( $customer['started_at'], 0, 16 ) ) : '' ); ?>"></td>
    </tr>
    <tr>
        <th><label for="completed_at">Fin</label></th>
        <td><input type="datetime-local" id="completed_at" name="completed_at" value="<?php echo esc_attr( $customer['completed_at'] ? str_replace( ' ', 'T', substr( $customer['completed_at'], 0, 16 ) ) : '' ); ?>"></td>
    </tr>
    <tr>
        <th><label for="metadata_json">Metadata (JSON)</label></th>
        <td><textarea id="metadata_json" name="metadata_json" rows="4" class="large-text code"><?php echo esc_textarea( (string) ( $customer['metadata_json'] ?? '' ) ); ?></textarea></td>
    </tr>
    </table>

    <p class="submit">
        <?php submit_button( 'Guardar', 'primary', 'submit', false ); ?>
    </p>
</form>

<?php if ( ! $is_new ) : ?>
<!-- Reconcile button -->
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:0;">
    <?php wp_nonce_field( 'estandar_customers_reconcile' ); ?>
    <input type="hidden" name="action" value="estandar_customers_reconcile">
    <input type="hidden" name="id" value="<?php echo (int) $customer['id']; ?>">
    <button type="submit" class="button button-secondary">Reconciliar</button>
    <span style="color:#666;font-size:12px;margin-left:6px;">Busca un lead coincidente y lo vincula.</span>
</form>
<?php endif; ?>
</div><!-- /editor -->

<?php if ( ! $is_new ) : ?>
<!-- ── Detail panel ── -->
<div style="flex:0 0 300px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:16px;">
<h3 style="margin-top:0;">Vinculación</h3>

<?php if ( $customer['matched_lead_id'] ) : ?>
<table style="width:100%;border-collapse:collapse;font-size:13px;">
<tr><th style="text-align:left;padding:4px 0;width:50%;">Lead</th>
    <td>#<?php echo (int) $customer['matched_lead_id']; ?></td></tr>
<tr><th style="text-align:left;padding:4px 0;">Vinculado por</th>
    <td><?php echo esc_html( $customer['matched_by'] ?? '—' ); ?></td></tr>
<tr><th style="text-align:left;padding:4px 0;">Confianza</th>
    <td><?php
        $cm = array( 'high' => 'Alta 🟢', 'medium' => 'Media 🟡', 'low' => 'Baja 🔴' );
        echo esc_html( $cm[ $customer['matched_confidence'] ?? '' ] ?? '—' );
    ?></td></tr>
<tr><th style="text-align:left;padding:4px 0;">Vinculado el</th>
    <td><?php echo $customer['matched_at'] ? esc_html( date_i18n( 'd M Y H:i', strtotime( $customer['matched_at'] ) ) ) : '—'; ?></td></tr>
</table>

<?php if ( $lead ) : ?>
<hr style="margin:12px 0;">
<h4 style="margin:0 0 8px;">Resumen del lead</h4>
<table style="width:100%;border-collapse:collapse;font-size:13px;">
<tr><th style="text-align:left;padding:3px 0;">Nombre</th>    <td><?php echo esc_html( $lead['name'] ?? '—' ); ?></td></tr>
<tr><th style="text-align:left;padding:3px 0;">Email</th>     <td><?php echo esc_html( $lead['email'] ?? '—' ); ?></td></tr>
<tr><th style="text-align:left;padding:3px 0;">Teléfono</th>  <td><?php echo esc_html( ! empty( $lead['phone'] ) ? Estandar_Leads_Normalizer::phone_display( $lead['phone'] ) : '—' ); ?></td></tr>
<tr><th style="text-align:left;padding:3px 0;">Calidad</th>   <td><?php echo esc_html( $lead['quality'] ?? '—' ); ?></td></tr>
<tr><th style="text-align:left;padding:3px 0;">Etapa</th>     <td><?php echo esc_html( $lead['sales_stage'] ?? '—' ); ?></td></tr>
<tr><th style="text-align:left;padding:3px 0;">Creado</th>    <td><?php echo $lead['created_at'] ? esc_html( date_i18n( 'd M Y', strtotime( $lead['created_at'] ) ) ) : '—'; ?></td></tr>
</table>
<p style="margin:8px 0 0;font-size:11px;color:#888;">Solo lectura. No se pueden editar datos del lead desde aquí.</p>
<?php endif; ?>

<?php else : ?>
<p style="color:#888;">Sin lead vinculado.<br>Usa el botón <em>Reconciliar</em> para buscar uno.</p>
<?php endif; ?>
</div><!-- /detail panel -->
<?php endif; ?>

</div><!-- /flex -->
<?php if ( ! $embedded ) : ?>
</div>
<?php endif; ?>
