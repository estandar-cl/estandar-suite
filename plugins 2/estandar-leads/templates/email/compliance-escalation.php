<?php
/**
 * HTML email template — compliance escalation / feedback reminder.
 *
 * Variables in scope (set by Estandar_Leads_Compliance::render_email()):
 *   $es_level         string  Escalation level constant (48h, 96h, 7d, 14d, weekly).
 *   $es_lead          array   Lead data row (name, email, phone, project_slug, project_area, created_at).
 *   $es_eval_url      string  Signed evaluation URL (may be empty).
 *   $es_s             array   Email settings (brand_color, logo_url, sender_name, button_label…).
 *   $es_hours_elapsed int     Hours elapsed since lead creation.
 *
 * Rules: table-based layout, inline CSS only, no JS, no tracking pixels.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$brand   = esc_attr( $es_s['brand_color'] ?? '#1a73e8' );
$logo    = Estandar_Leads_Email::public_email_logo_url( (string) ( $es_s['logo_url'] ?? '' ) );
$site    = esc_html( get_bloginfo( 'name' ) );
$project = esc_html( resolve_project_display_name( $es_lead['project_slug'] ?? '', $es_lead['project_area'] ?? '' ) );

// ── Per-level copy ────────────────────────────────────────────────────────────
$level_config = array(
    Estandar_Leads_Compliance::LEVEL_48H => array(
        'badge_color' => '#dba617',
        'badge_label' => '⏳ PENDIENTE — 48 h',
        'headline'    => 'Tienes un lead pendiente de clasificar.',
        'body'        => 'Han pasado más de 48 horas desde que recibiste este contacto y aún no ha sido clasificado en el sistema. Ingresa ahora y registra el resultado de tu gestión.',
        'cta_label'   => 'Clasificar lead ahora →',
    ),
    Estandar_Leads_Compliance::LEVEL_96H => array(
        'badge_color' => '#c05621',
        'badge_label' => '⚠️ 2° RECORDATORIO — 96 h',
        'headline'    => 'Segundo aviso: clasificación de lead pendiente.',
        'body'        => 'Han transcurrido más de 96 horas sin clasificar este lead. Esta notificación ha sido copiada al supervisor del equipo comercial. Por favor, ingresa y completa la evaluación.',
        'cta_label'   => 'Clasificar lead →',
    ),
    Estandar_Leads_Compliance::LEVEL_7D => array(
        'badge_color' => '#d63638',
        'badge_label' => '🔴 ESCALAMIENTO — 7 días',
        'headline'    => 'Escalamiento: lead sin clasificar por 7 días.',
        'body'        => 'Este lead lleva más de 7 días sin recibir clasificación comercial. Se ha notificado al supervisor. La ventana de contacto efectivo se está cerrando; la información sigue disponible para que el operador registre el resultado.',
        'cta_label'   => 'Ver y clasificar →',
    ),
    Estandar_Leads_Compliance::LEVEL_14D => array(
        'badge_color' => '#9b1c1c',
        'badge_label' => '🚨 ESCALAMIENTO FINAL — 14 días',
        'headline'    => 'Escalamiento final: lead crítico sin clasificar.',
        'body'        => 'Este lead acumula más de 14 días sin respuesta comercial. Se ha notificado al supervisor y al área de cumplimiento. Se requiere acción inmediata para cerrar el ciclo.',
        'cta_label'   => 'Clasificar ahora →',
    ),
    Estandar_Leads_Compliance::LEVEL_WEEKLY => array(
        'badge_color' => '#6b21a8',
        'badge_label' => '📋 SEGUIMIENTO SEMANAL',
        'headline'    => 'Recordatorio semanal: lead sin clasificar.',
        'body'        => 'Este lead continúa sin clasificación comercial. Se envía este recordatorio semanal hasta que el ciclo sea cerrado. Por favor, ingresa al sistema y registra el estado actual.',
        'cta_label'   => 'Clasificar lead →',
    ),
);

$cfg = $level_config[ $es_level ] ?? $level_config[ Estandar_Leads_Compliance::LEVEL_48H ];

$badge_color = esc_attr( $cfg['badge_color'] );
$badge_label = esc_html( $cfg['badge_label'] );
$headline    = esc_html( $cfg['headline'] );
$body_text   = esc_html( $cfg['body'] );
$cta_label   = esc_html( $cfg['cta_label'] );

// ── Lead data ─────────────────────────────────────────────────────────────────
$lead_name    = esc_html( $es_lead['name'] ?? '' );
$lead_email   = esc_html( $es_lead['email'] ?? '' );
$lead_phone   = esc_html( Estandar_Leads_Normalizer::phone_display( $es_lead['phone'] ?? '' ) );
$created_local = Estandar_Leads_Normalizer::datetime_local( $es_lead['created_at'] ?? '' );
$context_gap = ! empty( $es_lead['id'] ) ? Estandar_Leads_Repository::latest_context_gap( (int) $es_lead['id'] ) : array();
$context_gap_label = (string) ( $context_gap['label'] ?? '' );
$show_context_gap = $context_gap_label !== '' && (string) ( $context_gap['value'] ?? 'NO' ) !== 'NO';

$days_elapsed = (int) floor( $es_hours_elapsed / 24 );
$elapsed_label = $days_elapsed > 0
    ? $days_elapsed . ' ' . ( $days_elapsed === 1 ? 'día' : 'días' ) . ' sin clasificar'
    : $es_hours_elapsed . ' horas sin clasificar';

$has_cta = $es_eval_url !== '';
?>
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo esc_html( $cfg['badge_label'] ); ?></title>
  <style>
    @media only screen and (max-width: 640px) {
      .ew  { padding: 12px 4px !important; }
      .ec  { width: 100% !important; border-radius: 0 !important; }
      .eb  { padding: 24px 18px !important; }
      .ef  { padding: 16px 18px !important; }
      .cta a { padding: 14px 28px !important; font-size: 15px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

  <!-- Preheader -->
  <div style="display:none;font-size:1px;color:#f0f2f5;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
    <?php echo esc_html( $cfg['headline'] ); ?>
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
  </div>

  <table role="presentation" class="ew" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f2f5;padding:32px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" class="ec" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.10);">

          <!-- Header / brand bar -->
          <tr>
            <td style="background-color:<?php echo $brand; ?>;padding:20px 32px;text-align:left;">
              <?php if ( $logo ) : ?>
                <img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo $site; ?>" height="32" style="display:block;border:0;">
              <?php else : ?>
                <span style="color:#ffffff;font-family:Arial,sans-serif;font-size:18px;font-weight:700;letter-spacing:-.3px;"><?php echo $site; ?></span>
              <?php endif; ?>
            </td>
          </tr>

          <!-- Escalation badge -->
          <tr>
            <td style="background-color:<?php echo $badge_color; ?>;padding:10px 32px;text-align:center;">
              <span style="color:#ffffff;font-family:Arial,sans-serif;font-size:12px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;"><?php echo $badge_label; ?></span>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td class="eb" style="padding:32px 40px;font-family:Arial,sans-serif;">

              <p style="margin:0 0 8px;font-size:20px;font-weight:700;color:#111827;line-height:1.3;">
                <?php echo $headline; ?>
              </p>
              <p style="margin:0 0 24px;font-size:14px;color:#4b5563;line-height:1.6;">
                <?php echo $body_text; ?>
              </p>

              <!-- Lead data card -->
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:24px;">
                <tr>
                  <td style="padding:16px 20px;">
                    <p style="margin:0 0 12px;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.6px;">Datos del lead</p>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">

                      <?php if ( $lead_name ) : ?>
                      <tr>
                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#6b7280;padding:4px 0;width:110px;">Nombre</td>
                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#111827;font-weight:600;padding:4px 0;"><?php echo $lead_name; ?></td>
                      </tr>
                      <?php endif; ?>

                      <tr>
                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#6b7280;padding:4px 0;">Email</td>
                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#111827;padding:4px 0;"><?php echo $lead_email ?: 'Sin email'; ?></td>
                      </tr>

                      <?php if ( $lead_phone ) : ?>
                      <tr>
                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#6b7280;padding:4px 0;">Teléfono</td>
                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#111827;padding:4px 0;"><?php echo $lead_phone; ?></td>
                      </tr>
                      <?php endif; ?>

                      <?php if ( $project ) : ?>
                      <tr>
                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#6b7280;padding:4px 0;">Proyecto</td>
                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#111827;padding:4px 0;"><?php echo $project; ?></td>
                      </tr>
                      <?php endif; ?>

                      <tr>
                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#6b7280;padding:4px 0;">Recibido</td>
                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#111827;padding:4px 0;"><?php echo esc_html( $created_local ); ?></td>
                      </tr>

	                      <tr>
	                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#6b7280;padding:4px 0;">Estado</td>
	                        <td style="padding:4px 0;">
                          <span style="display:inline-block;background:<?php echo $badge_color; ?>;color:#fff;font-family:Arial,sans-serif;font-size:11px;font-weight:700;padding:2px 8px;border-radius:3px;">
                            <?php echo esc_html( $elapsed_label ); ?>
                          </span>
                        </td>
	                      </tr>

	                      <?php if ( $show_context_gap ) : ?>
	                      <tr>
	                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#6b7280;padding:4px 0;">Contexto reportado</td>
	                        <td style="font-family:Arial,sans-serif;font-size:13px;color:#111827;padding:4px 0;">• <?php echo esc_html( $context_gap_label ); ?></td>
	                      </tr>
	                      <?php endif; ?>

	                    </table>
                  </td>
                </tr>
              </table>

              <!-- CTA button -->
              <?php if ( $has_cta ) : ?>
              <table role="presentation" class="cta" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                <tr>
                  <td align="center">
                    <a href="<?php echo esc_url( $es_eval_url ); ?>"
                       style="display:inline-block;background-color:<?php echo $brand; ?>;color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:700;text-decoration:none;padding:14px 36px;border-radius:6px;letter-spacing:-.2px;">
                      <?php echo $cta_label; ?>
                    </a>
                  </td>
                </tr>
              </table>

              <p style="margin:0 0 8px;font-size:12px;color:#9ca3af;text-align:center;line-height:1.5;">
                Si el botón no funciona, copia este enlace en tu navegador:<br>
                <a href="<?php echo esc_url( $es_eval_url ); ?>" style="color:#6b7280;word-break:break-all;">
                  <?php echo esc_url( $es_eval_url ); ?>
                </a>
              </p>
              <?php endif; ?>

            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>
