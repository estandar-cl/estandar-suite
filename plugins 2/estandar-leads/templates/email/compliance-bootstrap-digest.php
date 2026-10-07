<?php
/**
 * HTML email template — compliance digest (bootstrap retroactive OR daily cron).
 *
 * Sent when an operator has pending leads grouped into one email.
 * Lists all leads grouped by age tier. Daily digests use a workspace CTA.
 *
 * Variables in scope:
 *   $es_operator_name  string  Operator display name.
 *   $es_classified     array   Leads keyed by tier (recent, stale, archived).
 *                              Bootstrap leads may include _eval_url and _token_hash.
 *   $es_total          int     Total number of pending leads.
 *   $es_s              array   Email settings (brand_color, logo_url, …).
 *   $es_context        string  'bootstrap' (default) or 'daily' — controls badge appearance.
 *   $es_workspace_url  string  Optional workspace URL for daily operator digest.
 *
 * Rules: table-based layout, inline CSS only, no JS, no tracking pixels.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$brand       = esc_attr( $es_s['brand_color'] ?? '#1a73e8' );
$logo        = Estandar_Leads_Email::public_email_logo_url( (string) ( $es_s['logo_url'] ?? '' ) );
$site        = esc_html( get_bloginfo( 'name' ) );
$op_name     = esc_html( $es_operator_name );
$total       = (int) $es_total;
$context     = ( isset( $es_context ) && $es_context === 'daily' ) ? 'daily' : 'bootstrap';
$workspace_url = esc_url( $es_workspace_url ?? '' );

// Context-specific badge config.
$ctx_badge_color = $context === 'daily' ? '#d97706' : '#6b21a8';
$ctx_badge_label = $context === 'daily'
    ? '📋 RESUMEN DE LEADS PENDIENTES'
    : '📋 ACTIVACIÓN DE SEGUIMIENTO RETROACTIVO';
$ctx_intro = $context === 'daily'
    ? "Tienes leads bajo tu gestión que requieren clasificación hoy."
    : "El sistema de compliance detectó leads bajo tu gestión que no han recibido clasificación comercial.";
$ctx_footer = $context === 'daily'
    ? "Este resumen fue generado automáticamente por el motor de compliance. Trabaja los leads desde el Workspace para cerrar el ciclo."
    : "Este resumen fue generado automáticamente al activar el motor de compliance retroactivo. Las clasificaciones que realices quedan registradas en el sistema y no recibirás un nuevo resumen por leads ya clasificados.";

// Tier display config.
$tier_config = array(
    Estandar_Leads_Compliance::TIER_RECENT => array(
        'label'        => '0–30 días',
        'badge_color'  => '#dba617',
        'badge_bg'     => '#fffbeb',
        'border_color' => '#fde68a',
        'icon'         => '⏳',
    ),
    Estandar_Leads_Compliance::TIER_STALE => array(
        'label'        => '31–90 días',
        'badge_color'  => '#d63638',
        'badge_bg'     => '#fef2f2',
        'border_color' => '#fecaca',
        'icon'         => '⚠️',
    ),
    Estandar_Leads_Compliance::TIER_ARCHIVED => array(
        'label'        => '+90 días',
        'badge_color'  => '#9b1c1c',
        'badge_bg'     => '#fff1f2',
        'border_color' => '#fda4af',
        'icon'         => '🚨',
    ),
);

// Visual grouping by project within each age tier — display-only, does not
// alter $es_classified, the underlying query, or lead selection.
$tier_project_groups = array();
foreach ( $tier_config as $tier_key => $tier ) {
    foreach ( ( $es_classified[ $tier_key ] ?? array() ) as $lead ) {
        $proj_label = resolve_project_display_name( $lead['project_slug'] ?? '', $lead['project_area'] ?? '' );
        $proj_key   = $proj_label !== '' ? $proj_label : 'Sin proyecto';
        $tier_project_groups[ $tier_key ][ $proj_key ][] = $lead;
    }
}

// Cap the number of individual lead rows rendered; the remainder is summarized
// in a single trailing line. Tier badges still report the true tier count.
$display_max = 20;
$rendered_count = 0;
$tier_project_groups_display = array();
foreach ( $tier_project_groups as $tier_key => $project_groups ) {
    foreach ( $project_groups as $proj_key => $proj_leads ) {
        if ( $rendered_count >= $display_max ) {
            break 2;
        }
        $slice = array_slice( $proj_leads, 0, $display_max - $rendered_count );
        if ( ! empty( $slice ) ) {
            $tier_project_groups_display[ $tier_key ][ $proj_key ] = $slice;
            $rendered_count += count( $slice );
        }
    }
}
$remaining_count = max( 0, $total - $rendered_count );
?>
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resumen retroactivo — leads pendientes</title>
  <style>
    @media only screen and (max-width: 640px) {
      .ew  { padding: 12px 4px !important; }
      .ec  { width: 100% !important; border-radius: 0 !important; }
      .eb  { padding: 24px 18px !important; }
      .ef  { padding: 16px 18px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

  <!-- Preheader -->
  <div style="display:none;font-size:1px;color:#f0f2f5;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
    Tienes <?php echo $total; ?> lead<?php echo $total !== 1 ? 's' : ''; ?> pendiente<?php echo $total !== 1 ? 's' : ''; ?> de clasificación.
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
  </div>

  <table role="presentation" class="ew" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f2f5;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" class="ec" width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.10);">

          <!-- Brand bar -->
          <tr>
            <td style="background-color:<?php echo $brand; ?>;padding:20px 32px;text-align:left;">
              <?php if ( $logo ) : ?>
                <img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo $site; ?>" height="32" style="display:block;border:0;">
              <?php else : ?>
                <span style="color:#ffffff;font-family:Arial,sans-serif;font-size:18px;font-weight:700;"><?php echo $site; ?></span>
              <?php endif; ?>
            </td>
          </tr>

          <!-- Context badge (bootstrap vs daily) -->
          <tr>
            <td style="background:<?php echo esc_attr( $ctx_badge_color ); ?>;padding:10px 32px;text-align:center;">
              <span style="color:#ffffff;font-family:Arial,sans-serif;font-size:12px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;">
                <?php echo esc_html( $ctx_badge_label ); ?>
              </span>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td class="eb" style="padding:32px 40px;font-family:Arial,sans-serif;">

              <?php if ( $context === 'daily' ) : ?>
              <p style="margin:0 0 16px;font-size:12px;color:#6b7280;line-height:1.5;">
                Este resumen incluye leads pendientes de todos los proyectos asignados a tu operador.
              </p>
              <?php endif; ?>

              <p style="margin:0 0 6px;font-size:20px;font-weight:700;color:#111827;line-height:1.3;">
                <?php echo $op_name ? 'Hola ' . esc_html( $op_name ) . ',' : 'Hola,'; ?>
              </p>
              <p style="margin:0 0 24px;font-size:14px;color:#4b5563;line-height:1.6;">
                <?php echo esc_html( $ctx_intro ); ?>
                <strong><?php echo $total; ?> lead<?php echo $total !== 1 ? 's' : ''; ?></strong>
                agrupado<?php echo $total !== 1 ? 's' : ''; ?> por antigüedad a continuación.
                <?php echo $workspace_url ? 'Abre el Workspace para gestionar la lista completa.' : 'Cada lead tiene un enlace directo para clasificarlo.'; ?>
              </p>

              <?php if ( $workspace_url ) : ?>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
                <tr>
                  <td align="center">
                    <a href="<?php echo $workspace_url; ?>"
                       style="display:inline-block;background:<?php echo $brand; ?>;color:#ffffff;font-family:Arial,sans-serif;font-size:15px;font-weight:700;text-decoration:none;padding:13px 28px;border-radius:6px;">
                      Abrir Workspace
                    </a>
                  </td>
                </tr>
              </table>
              <?php endif; ?>

              <?php foreach ( $tier_config as $tier_key => $tier ) : ?>
                <?php
                $tier_leads = $es_classified[ $tier_key ] ?? array();
                if ( empty( $tier_leads ) ) {
                    continue;
                }
                $tier_count = count( $tier_leads );
                $project_groups = $tier_project_groups_display[ $tier_key ] ?? array();
                if ( empty( $project_groups ) ) {
                    continue; // Every lead in this tier fell past the display cap.
                }
                ?>

                <!-- Tier section -->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="border:1px solid <?php echo esc_attr( $tier['border_color'] ); ?>;border-radius:6px;margin-bottom:20px;overflow:hidden;">

                  <!-- Tier header -->
                  <tr>
                    <td style="background:<?php echo esc_attr( $tier['badge_bg'] ); ?>;padding:10px 16px;border-bottom:1px solid <?php echo esc_attr( $tier['border_color'] ); ?>;">
                      <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                          <td style="font-family:Arial,sans-serif;font-size:13px;font-weight:700;color:#111827;">
                            <?php echo esc_html( $tier['icon'] ); ?>
                            <?php echo esc_html( $tier['label'] ); ?>
                          </td>
                          <td style="text-align:right;">
                            <span style="background:<?php echo esc_attr( $tier['badge_color'] ); ?>;color:#fff;font-family:Arial,sans-serif;font-size:11px;font-weight:700;padding:2px 8px;border-radius:3px;">
                              <?php echo $tier_count; ?> lead<?php echo $tier_count !== 1 ? 's' : ''; ?>
                            </span>
                          </td>
                        </tr>
                      </table>
                    </td>
                  </tr>

                  <!-- Leads in this tier, grouped by project (display-only) -->
                  <?php foreach ( $project_groups as $proj_key => $proj_leads ) : ?>
                    <tr>
                      <td style="background:#f3f4f6;padding:6px 16px;border-bottom:1px solid #e5e7eb;">
                        <span style="font-family:Arial,sans-serif;font-size:11px;font-weight:700;color:#4b5563;text-transform:uppercase;letter-spacing:.4px;">
                          <?php echo esc_html( $proj_key ); ?>
                        </span>
                      </td>
                    </tr>
                  <?php foreach ( $proj_leads as $i => $lead ) : ?>
                    <?php
                    $row_bg    = $i % 2 === 0 ? '#ffffff' : '#f9fafb';
                    $lead_name = esc_html( $lead['name'] ?? '' );
                    $lead_ph   = esc_html( Estandar_Leads_Normalizer::phone_display( $lead['phone'] ?? '' ) );
                    $lead_em   = esc_html( $lead['email'] ?? '' );
	                    $project   = esc_html( resolve_project_display_name( $lead['project_slug'] ?? '', $lead['project_area'] ?? '' ) );
	                    $days      = (int) floor( Estandar_Leads_Compliance::hours_since_public( $lead['created_at'] ) / 24 );
	                    $eval_url  = esc_url( $lead['_eval_url'] ?? '' );
	                    $context_gap = ! empty( $lead['id'] ) ? Estandar_Leads_Repository::latest_context_gap( (int) $lead['id'] ) : array();
	                    $context_gap_label = (string) ( $context_gap['label'] ?? '' );
	                    ?>
                    <tr>
                      <td style="background:<?php echo $row_bg; ?>;padding:12px 16px;border-bottom:1px solid #f3f4f6;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                          <tr>
                            <td style="vertical-align:top;width:70%;">
                              <?php if ( $lead_name ) : ?>
                                <p style="margin:0 0 2px;font-family:Arial,sans-serif;font-size:13px;font-weight:700;color:#111827;">
                                  <?php echo $lead_name; ?>
                                </p>
                              <?php endif; ?>
                              <p style="margin:0;font-family:Arial,sans-serif;font-size:12px;color:#6b7280;line-height:1.5;">
                                <?php if ( $lead_ph ) echo $lead_ph . ' · '; ?>
                                <?php echo $lead_em ?: 'Sin email'; ?>
                                <?php if ( $project ) echo ' · '; ?>
                                <?php if ( $project ) echo $project; ?>
                              </p>
	                              <p style="margin:4px 0 0;font-family:Arial,sans-serif;font-size:11px;color:#9ca3af;">
	                                <?php echo $days; ?> día<?php echo $days !== 1 ? 's' : ''; ?> sin clasificar
	                              </p>
	                              <?php if ( $context_gap_label !== '' && (string) ( $context_gap['value'] ?? 'NO' ) !== 'NO' ) : ?>
	                                <p style="margin:4px 0 0;font-family:Arial,sans-serif;font-size:11px;color:#8a6100;">
	                                  Contexto reportado: • <?php echo esc_html( $context_gap_label ); ?>
	                                </p>
	                              <?php endif; ?>
	                            </td>
                            <td style="vertical-align:middle;text-align:right;width:30%;">
                              <?php if ( ! $workspace_url && $eval_url ) : ?>
                                <a href="<?php echo $eval_url; ?>"
                                   style="display:inline-block;background:<?php echo $brand; ?>;color:#ffffff;font-family:Arial,sans-serif;font-size:12px;font-weight:700;text-decoration:none;padding:8px 14px;border-radius:5px;white-space:nowrap;">
                                  Clasificar →
                                </a>
                              <?php elseif ( ! $workspace_url ) : ?>
                                <span style="font-family:Arial,sans-serif;font-size:11px;color:#9ca3af;">Sin enlace</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        </table>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <?php endforeach; ?>

                </table>

              <?php endforeach; ?>

              <?php if ( $remaining_count > 0 ) : ?>
                <p style="margin:0 0 20px;font-size:13px;color:#6b7280;line-height:1.5;">
                  ... y <?php echo (int) $remaining_count; ?> lead<?php echo $remaining_count !== 1 ? 's' : ''; ?> adicional<?php echo $remaining_count !== 1 ? 'es' : ''; ?>.
                </p>
              <?php endif; ?>

              <?php // Recovery Layer · Leads Recuperables (integrado en el digest, sin email aparte). ?>
              <?php $es_recoverable = isset( $es_recoverable ) && is_array( $es_recoverable ) ? $es_recoverable : array(); ?>
              <?php if ( ! empty( $es_recoverable ) ) : ?>
                <h2 style="margin:28px 0 12px;font-size:16px;font-weight:700;color:#111827;border-top:1px solid #e5e7eb;padding-top:20px;">
                  Leads Recuperables
                </h2>
                <p style="margin:0 0 12px;font-size:13px;color:#6b7280;">
                  Marcados como perdidos por falta de contacto, con interés observado. Requieren un segundo intento o confirmar cierre.
                </p>
                <?php foreach ( $es_recoverable as $rl ) :
                  $prio = isset( $rl['priority'] ) ? (string) $rl['priority'] : 'BAJA';
                  $prio_color = $prio === 'ALTA' ? '#b42318' : ( $prio === 'MEDIA' ? '#996c00' : '#6b7280' );
                ?>
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px;border:1px solid #e5e7eb;border-radius:8px;">
                    <tr><td style="padding:12px 14px;font-size:13px;color:#374151;line-height:1.6;">
                      <strong style="font-size:14px;color:#111827;"><?php echo esc_html( $rl['name'] ); ?></strong><br>
                      Proyecto: <?php echo esc_html( $rl['project'] ); ?><br>
                      Operador: <?php echo esc_html( $rl['operator'] ); ?><br>
                      Cierre: <?php echo esc_html( $rl['closed_at'] ); ?><br>
                      Outcome: <?php echo esc_html( $rl['last_outcome'] ); ?><br>
                      Interés: <?php echo esc_html( $rl['interest_label'] ); ?><br>
                      Prioridad: <strong style="color:<?php echo esc_attr( $prio_color ); ?>;"><?php echo esc_html( $prio ); ?></strong><br>
                      <strong style="color:#b42318;">Acción: Segundo intento requerido</strong>
                    </td></tr>
                  </table>
                <?php endforeach; ?>
              <?php endif; ?>

              <p style="margin:24px 0 0;font-size:13px;color:#6b7280;line-height:1.6;border-top:1px solid #e5e7eb;padding-top:16px;">
                <?php echo esc_html( $ctx_footer ); ?>
              </p>

            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>
