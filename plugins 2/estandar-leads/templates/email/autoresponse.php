<?php
/**
 * HTML email template — autoresponse (lead-facing).
 *
 * LEAD_RESPONSE_ACCELERATION_V1 (Fase 1-2).
 *
 * Variables in scope (set by Estandar_Leads_Email::render_autoresponse()):
 *   $es_s — brand settings array (brand_color, logo_url, ...)
 *   $es_a — autoresponse settings array (enabled, subject, body, pdf_attachment_id, landing_url, throttle_hours)
 *   $es_d — data array {
 *     project_slug, project_name, lead_name, operator_name, operator_email,
 *     subject, body (already placeholder-substituted, plain text),
 *     pdf_link, landing_link, whatsapp_link, pixel_url
 *   }
 *
 * Rules: table-based layout, inline CSS only, minimal JS-free tracking pixel
 * for autoresponse_email_open_signal (R-class, best-effort — many clients
 * block remote images). No classification UI, no internal links, no
 * operator-facing data — this email goes to the LEAD.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$brand = esc_attr( $es_s['brand_color'] ?? '#1a73e8' );
$logo_url = Estandar_Leads_Email::public_email_logo_url( (string) ( $es_s['logo_url'] ?? '' ) );
$body_text = (string) ( $es_d['body'] ?? '' );
$project_name = trim( (string) ( $es_d['project_name'] ?? '' ) );
$pdf_link = (string) ( $es_d['pdf_link'] ?? '' );
$landing_link = (string) ( $es_d['landing_link'] ?? '' );
$whatsapp_link = (string) ( $es_d['whatsapp_link'] ?? '' );
$pixel_url = (string) ( $es_d['pixel_url'] ?? '' );
$has_cta = ( $pdf_link !== '' || $landing_link !== '' || $whatsapp_link !== '' );
?>
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo esc_html( $es_d['subject'] ?? 'Gracias por tu interés' ); ?></title>
  <style>
    @media only screen and (max-width: 640px) {
      .ew { padding: 12px 4px !important; }
      .ec { width: 100% !important; border-radius: 0 !important; }
      .eb { padding: 24px 18px !important; }
      .ef { padding: 16px 18px !important; }
      .cta a { padding: 13px 20px !important; font-size: 14px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

  <!--[if mso]><table role="presentation" width="100%"><tr><td><![endif]-->

  <table class="ew" role="presentation" width="100%" cellpadding="0" cellspacing="0"
         style="background-color:#f0f2f5;padding:40px 16px;">
    <tr>
      <td align="center" valign="top">

        <!-- Container -->
        <table class="ec" role="presentation" width="600" cellpadding="0" cellspacing="0"
               style="width:600px;max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;
                      box-shadow:0 1px 6px rgba(0,0,0,.1);">

          <!-- ── HEADER ─────────────────────────────────────────────────── -->
          <tr>
            <td bgcolor="<?php echo $brand; ?>"
                style="background-color:<?php echo $brand; ?>;padding:26px 36px;">
              <?php if ( $logo_url !== '' ) : ?>
                <img src="<?php echo esc_url( $logo_url ); ?>"
                     alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
                     style="display:block;width:auto;max-width:240px;max-height:44px;border:0;" height="44">
              <?php else : ?>
                <span style="font-size:18px;font-weight:700;color:#ffffff;letter-spacing:-.3px;
                             font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
                  <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
                </span>
              <?php endif; ?>
            </td>
          </tr>

          <!-- ── BODY ───────────────────────────────────────────────────── -->
          <tr>
            <td class="eb" style="padding:28px 36px;
                font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <?php if ( $project_name !== '' ) : ?>
                <p style="margin:0 0 5px;font-size:12px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:<?php echo $brand; ?>;">
                  Proyecto
                </p>
                <h1 style="margin:0 0 20px;font-size:25px;line-height:1.25;color:#202124;">
                  <?php echo esc_html( $project_name ); ?>
                </h1>
              <?php endif; ?>
              <p style="margin:0;font-size:15px;color:#202124;line-height:1.7;white-space:pre-line;word-break:break-word;">
                <?php echo nl2br( esc_html( $body_text ) ); ?>
              </p>
            </td>
          </tr>

          <!-- ── CTAS ───────────────────────────────────────────────────── -->
          <?php if ( $has_cta ) : ?>
          <tr>
            <td class="cta" align="left" style="padding:0 32px 8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <?php if ( $pdf_link !== '' ) : ?>
                <p style="margin:0 4px 12px;font-size:14px;color:#5f6368;line-height:1.6;">
                  Mientras te contactamos, puedes revisar la ficha y las características principales del proyecto.
                </p>
              <?php endif; ?>
              <?php if ( $pdf_link !== '' ) : ?>
                <a href="<?php echo esc_url( $pdf_link ); ?>" style="display:inline-block;margin:4px;padding:13px 22px;border-radius:6px;background:<?php echo $brand; ?>;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Ver ficha del proyecto</a>
              <?php endif; ?>
              <?php if ( $landing_link !== '' ) : ?>
                <a href="<?php echo esc_url( $landing_link ); ?>" style="display:inline-block;margin:4px;padding:13px 22px;border-radius:6px;background:#ffffff;border:1px solid <?php echo $brand; ?>;color:<?php echo $brand; ?>;text-decoration:none;font-size:14px;font-weight:700;">Conocer más</a>
              <?php endif; ?>
              <?php if ( $whatsapp_link !== '' ) : ?>
                <p style="margin:16px 4px 8px;font-size:14px;color:#5f6368;line-height:1.6;">
                  Si prefieres atención inmediata, también puedes escribirnos directamente por WhatsApp.
                </p>
                <a href="<?php echo esc_url( $whatsapp_link ); ?>" style="display:inline-block;margin:4px;padding:13px 22px;border-radius:6px;background:#25d366;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Escribir por WhatsApp</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endif; ?>

          <!-- ── OPERATOR SIGNATURE ─────────────────────────────────────── -->
          <?php if ( ! empty( $es_d['operator_name'] ) ) : ?>
          <tr>
            <td style="padding:8px 36px 24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0;font-size:13px;color:#5f6368;line-height:1.6;">
                <?php echo esc_html( (string) $es_d['operator_name'] ); ?>
                <?php if ( ! empty( $es_d['project_name'] ) ) : ?>
                  <br><span style="color:#9aa0a6;"><?php echo esc_html( (string) $es_d['project_name'] ); ?></span>
                <?php endif; ?>
              </p>
            </td>
          </tr>
          <?php endif; ?>

        </table>
        <!-- /Container -->

      </td>
    </tr>
  </table>

  <!--[if mso]></td></tr></table><![endif]-->

  <?php if ( $pixel_url !== '' ) : ?>
  <img src="<?php echo esc_url( $pixel_url ); ?>" alt="" width="1" height="1" style="display:block;border:0;width:1px;height:1px;">
  <?php endif; ?>

</body>
</html>
