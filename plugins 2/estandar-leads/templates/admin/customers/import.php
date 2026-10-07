<?php
/**
 * Customer Registry — Import page (CSV / XLSX).
 *
 * Variables injected by Estandar_Leads_Customers_Admin::render_import():
 *   string   $step     — 'upload' | 'preview' | 'done'
 *   string   $tk       — transient key (preview step only)
 *   string[] $headers  — column headers from file (preview step only)
 *   array[]  $rows     — data rows from file (preview step only)
 *   array    $result   — import counters (done step only)
 *   string   $error
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$list_url = admin_url( 'admin.php?page=estandar-customers' );
$error_messages = array(
    'no_file'       => 'No se seleccionó ningún archivo.',
    'bad_ext'       => 'Formato no soportado. Usa CSV.',
    'empty'         => 'El archivo está vacío o no pudo leerse.',
    'expired'       => 'La sesión de importación expiró. Sube el archivo nuevamente.',
    'xlsx_disabled' => 'XLSX temporalmente deshabilitado. Use CSV.',
);
$embedded = function_exists( 'estandar_core_is_embedded_admin' ) && estandar_core_is_embedded_admin();
?>
<?php if ( ! $embedded ) : ?>
<div class="wrap">
<h1>Importar clientes</h1>
<?php endif; ?>
<a href="<?php echo esc_url( $list_url ); ?>" style="margin-bottom:12px;display:inline-block;">&larr; Volver a la lista</a>
<?php if ( ! $embedded ) : ?>
<hr class="wp-header-end">
<?php endif; ?>

<?php if ( $error ) : ?>
<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error_messages[ $error ] ?? $error ); ?></p></div>
<?php endif; ?>

<?php if ( $step === 'done' && $result ) : ?>
<!-- ── Result ── -->
<div class="notice notice-success"><p><strong>Importación completada.</strong></p></div>
<table class="form-table" style="max-width:360px;">
<tr><th>Importados (nuevos)</th><td><?php echo (int) $result['imported']; ?></td></tr>
<tr><th>Actualizados</th>       <td><?php echo (int) $result['updated']; ?></td></tr>
<tr><th>Vinculados a lead</th>  <td><?php echo (int) $result['matched']; ?></td></tr>
<tr><th>Sin lead encontrado</th><td><?php echo (int) $result['unmatched']; ?></td></tr>
<tr><th>Errores de fila</th>    <td><?php echo (int) $result['errors']; ?></td></tr>
</table>
<p><a href="<?php echo esc_url( $list_url ); ?>" class="button button-primary">Ver lista de clientes</a></p>

<?php elseif ( $step === 'preview' && ! empty( $rows ) ) : ?>
<!-- ── Preview ── -->
<p>Se detectaron <strong><?php echo count( $rows ); ?></strong> fila(s). Revisa la vista previa y confirma para importar.</p>
<div style="overflow-x:auto;max-height:400px;border:1px solid #ddd;margin-bottom:16px;">
<table class="wp-list-table widefat fixed" style="font-size:12px;">
<thead>
<tr><?php foreach ( $headers as $h ) : ?><th><?php echo esc_html( $h ); ?></th><?php endforeach; ?></tr>
</thead>
<tbody>
<?php foreach ( array_slice( $rows, 0, 50 ) as $row ) : ?>
<tr><?php foreach ( $row as $cell ) : ?><td><?php echo esc_html( $cell ); ?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ( count( $rows ) > 50 ) : ?>
<p style="color:#666;font-size:12px;">Mostrando las primeras 50 filas de <?php echo count( $rows ); ?>.</p>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'estandar_customers_import_confirm' ); ?>
    <input type="hidden" name="action" value="estandar_customers_import_confirm">
    <input type="hidden" name="tk"     value="<?php echo esc_attr( $tk ); ?>">
    <p>
        <button type="submit" class="button button-primary">Confirmar e importar</button>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-customers&view=import' ) ); ?>" class="button" style="margin-left:8px;">Cancelar</a>
    </p>
</form>

<?php else : ?>
<!-- ── Upload form ── -->
<p>Sube un archivo CSV o XLSX. La primera fila debe contener los nombres de columna.</p>
<p><strong>Columnas reconocidas:</strong>
<code>first_name</code>, <code>last_name</code>, <code>email</code>, <code>phone</code>,
<code>rut</code>, <code>organization</code>, <code>project_slug</code>, <code>company_id</code>,
<code>external_id</code>, <code>customer_stage</code>, <code>amount</code>,
<code>started_at</code>, <code>completed_at</code>.
</p>
<p style="color:#666;font-size:12px;">Columnas adicionales son ignoradas. En español también se aceptan: <code>nombre</code>, <code>apellido</code>, <code>telefono</code>, <code>empresa</code>, <code>proyecto</code>, <code>etapa</code>, <code>monto</code>, <code>inicio</code>, <code>fin</code>.</p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
    <?php wp_nonce_field( 'estandar_customers_import_upload' ); ?>
    <input type="hidden" name="action" value="estandar_customers_import_upload">
    <table class="form-table">
    <tr>
        <th><label for="import_file">Archivo (CSV o XLSX)</label></th>
        <td><input type="file" id="import_file" name="import_file" accept=".csv,.xlsx" required></td>
    </tr>
    </table>
    <?php submit_button( 'Vista previa' ); ?>
</form>
<?php endif; ?>
<?php if ( ! $embedded ) : ?>
</div>
<?php endif; ?>
