<?php
/**
 * HTML email — weekly project leads report (last 7 days).
 * Rendered by Estandar_Leads_Email::render_template, sent by ::send (reused engine).
 *
 * Variables in scope:
 *   $wr_project string  Project display name.
 *   $wr_period  string  e.g. "01/06 – 08/06".
 *   $wr_metrics array   keyed: nuevos, clasificados, validos, invalidos, reuniones,
 *                       propuestas, clientes, perdidos, sin_clasificar.
 *   $wr_top     array   rows: id, name, behavioral_score, priority, status, created_at.
 *   $wr_s       array   Email settings (brand_color, logo_url, …).
 *
 * Rules: table-based layout, inline CSS only, no JS, no tracking pixels.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$brand = esc_attr( $wr_s['brand_color'] ?? '#1a73e8' );
$m     = (array) $wr_metrics;
$rows  = array(
    'Leads nuevos'        => (int) ( $m['nuevos'] ?? 0 ),
    'Clasificados'        => (int) ( $m['clasificados'] ?? 0 ),
    'Válidos'             => (int) ( $m['validos'] ?? 0 ),
    'Inválidos'           => (int) ( $m['invalidos'] ?? 0 ),
    'Reuniones agendadas' => (int) ( $m['reuniones'] ?? 0 ),
    'Propuestas enviadas' => (int) ( $m['propuestas'] ?? 0 ),
    'Clientes cerrados'   => (int) ( $m['clientes'] ?? 0 ),
    'Perdidos'            => (int) ( $m['perdidos'] ?? 0 ),
    'Sin clasificar'      => (int) ( $m['sin_clasificar'] ?? 0 ),
);
?>
<div style="font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:0 auto;color:#202124;">
  <h2 style="color:<?php echo $brand; ?>;margin:0 0 4px;">📊 Resumen semanal de leads</h2>
  <p style="margin:0 0 16px;color:#5f6368;font-size:14px;">
    <strong><?php echo esc_html( $wr_project ); ?></strong> · <?php echo esc_html( $wr_period ); ?>
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:20px;">
    <?php foreach ( $rows as $label => $value ) : ?>
      <tr>
        <td style="padding:8px 12px;border-bottom:1px solid #eee;font-size:14px;"><?php echo esc_html( $label ); ?></td>
        <td style="padding:8px 12px;border-bottom:1px solid #eee;font-size:16px;font-weight:bold;text-align:right;"><?php echo (int) $value; ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h3 style="color:#202124;font-size:15px;margin:0 0 8px;">Top pendientes de acción</h3>
  <?php if ( empty( $wr_top ) ) : ?>
    <p style="color:#5f6368;font-size:14px;">Sin leads pendientes de acción. ✅</p>
  <?php else : ?>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
      <tr style="background:#f8f9fa;">
        <td style="padding:6px 10px;font-size:12px;color:#5f6368;">#</td>
        <td style="padding:6px 10px;font-size:12px;color:#5f6368;">Lead</td>
        <td style="padding:6px 10px;font-size:12px;color:#5f6368;text-align:right;">Score</td>
        <td style="padding:6px 10px;font-size:12px;color:#5f6368;text-align:right;">Prioridad</td>
      </tr>
      <?php foreach ( $wr_top as $lead ) : ?>
        <tr>
          <td style="padding:6px 10px;border-bottom:1px solid #f1f1f1;font-size:13px;">#<?php echo (int) $lead['id']; ?></td>
          <td style="padding:6px 10px;border-bottom:1px solid #f1f1f1;font-size:13px;"><?php echo esc_html( $lead['name'] !== '' ? $lead['name'] : '—' ); ?></td>
          <td style="padding:6px 10px;border-bottom:1px solid #f1f1f1;font-size:13px;text-align:right;"><?php echo $lead['behavioral_score'] === null ? '—' : (int) $lead['behavioral_score']; ?></td>
          <td style="padding:6px 10px;border-bottom:1px solid #f1f1f1;font-size:13px;text-align:right;"><?php echo esc_html( $lead['priority'] !== '' ? $lead['priority'] : '—' ); ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
