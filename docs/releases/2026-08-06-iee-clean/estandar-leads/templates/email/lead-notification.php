<?php
/**
 * HTML email template — lead notification.
 *
 * Variables in scope (set by Estandar_Leads_Email::render()):
 *   $es_s — settings array (brand_color, logo_url, button_label, etc.)
 *   $es_d — data array    (operator_name, lead_name, lead_email, ...)
 *
 * Rules: table-based layout, inline CSS only, no JS, no tracking pixels.
 *
 * EMAIL_NOTIFICATION_UX_V4 (action-trigger, UI only):
 *   Hierarchy: header → risk alert → lead identity + status badge + project +
 *   history → actions (WhatsApp primary / Workspace / Clasificar / Email) →
 *   workload panel → form data → 3 next-step actions → compact classification
 *   note → technical details (Lead ID + Submission ID) → footer.
 *   Marketing/tracking references removed for the operator (origin = website).
 *   No functional / routing / compliance / classification logic changes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Marca temporal de diagnóstico (TEMPLATE_TRACE, 2026-07-04): confirma qué
// archivo generó el correo recibido. Retirar tras validar en producción.
echo "<!-- __EMAIL_TEMPLATE_V2__ lead-notification.php -->\n";

$brand = esc_attr( $es_s['brand_color'] ?? '#1a73e8' );
$form_fields = is_array( $es_d['form_fields'] ?? null ) ? $es_d['form_fields'] : array();
$form_rows = Estandar_Leads_Email::operator_form_rows( $form_fields );
if ( empty( $form_rows ) && ! empty( $es_d['message'] ) ) {
    $form_rows['Mensaje'] = sanitize_textarea_field( $es_d['message'] );
}
$whatsapp_url = Estandar_Leads_Email::whatsapp_url( (string) ( $es_d['lead_phone'] ?? '' ) );
$phone_digits = Estandar_Leads_Normalizer::phone( (string) ( $es_d['lead_phone'] ?? '' ) );
$phone_url = $phone_digits !== '' ? 'tel:' . $phone_digits : '';
$email_url = ! empty( $es_d['lead_email'] ) ? 'mailto:' . sanitize_email( $es_d['lead_email'] ) : '';
$workspace_url = ! empty( $es_d['workspace_url'] ) ? esc_url( (string) $es_d['workspace_url'] ) : '';
$quote_create_url = ! empty( $es_d['quote_create_url'] ) ? esc_url( (string) $es_d['quote_create_url'] ) : '';
$sheet_url = ! empty( $es_d['consolidated_sheet_url'] ) ? esc_url( (string) $es_d['consolidated_sheet_url'] ) : '';
$workspace_backlog = is_array( $es_d['workspace_backlog'] ?? null ) ? $es_d['workspace_backlog'] : array();
$commercial_risk_signal = ! empty( $es_d['commercial_risk_signal'] );
$repeated_lead = ! empty( $es_d['repeated_lead'] );
$high_intent_signal = ! empty( $es_d['behavior_high_intent'] ?? $es_d['high_intent_signal'] );
$unmanaged_lead = ! empty( $es_d['unmanaged_lead'] );
$submission_count = (int) ( $es_d['submission_count'] ?? 0 );
$commercial_recontact_count = (int) ( $es_d['commercial_recontact_count'] ?? 0 );
$is_commercial_recontact = ! empty( $es_d['is_commercial_recontact'] );
$is_same_session_contact = ! empty( $es_d['is_same_session_contact'] );
$lead_type = sanitize_text_field( (string) ( $es_d['lead_type'] ?? ( $submission_count > 1 ? 'Recurrente' : 'Nuevo' ) ) );
$submission_position = (int) ( $es_d['submission_position'] ?? $submission_count );

// RECONTACT_BADGE — color-coded badge for commercial recontacts.
// Level 1: orange  · Level 2: red-orange  · Level 3+: deep red.
$recontact_badge_html = '';
$recontact_badge_colors = array(
    1 => array('bg' => '#fff3e0', 'border' => '#e65100', 'text' => '#e65100'),
    2 => array('bg' => '#fbe9e7', 'border' => '#d84315', 'text' => '#bf360c'),
    3 => array('bg' => '#ffebee', 'border' => '#c62828', 'text' => '#b71c1c'),
);
if ($is_commercial_recontact && $commercial_recontact_count > 0) {
    $level = min($commercial_recontact_count, 3);
    $c = $recontact_badge_colors[$level];
    $flames = str_repeat('🔥', min($commercial_recontact_count, 5));
    $recontact_badge_html = '<span style="display:inline-block;margin-left:8px;padding:4px 10px;border-radius:4px;'
        . 'background:' . $c['bg'] . ';border:1px solid ' . $c['border'] . ';'
        . 'color:' . $c['text'] . ';font-size:12px;font-weight:700;'
        . 'vertical-align:middle;">' . $flames . ' Recontacto x' . $commercial_recontact_count . '</span>';
}
$previous_status = sanitize_text_field( (string) ( $es_d['previous_status'] ?? '' ) );
$customer_matched = ! empty( $es_d['customer_matched'] );
$first_submitted_at = (string) ( $es_d['first_submitted_at'] ?? '' );
$last_submitted_at = (string) ( $es_d['last_submitted_at'] ?? '' );
$first_ingress = $first_submitted_at !== '' ? wp_date( 'd/m/Y · H:i', strtotime( $first_submitted_at ) ) : '';
$last_ingress = $last_submitted_at !== '' ? wp_date( 'd/m/Y · H:i', strtotime( $last_submitted_at ) ) : '';

$lead_priority_raw = (string) ( $es_d['lead_priority'] ?? '' );
$lead_priority_label = $lead_priority_raw !== '' ? strtoupper($lead_priority_raw) : '';

$pick_field = static function ( array $fields, array $keys ): string {
    foreach ( $keys as $key ) {
        foreach ( $fields as $field_key => $field_value ) {
            if ( ! is_scalar( $field_value ) ) {
                continue;
            }
            $normalized_key = strtolower( trim( str_replace( array( '-', ' ' ), '_', (string) $field_key ) ) );
            if ( $normalized_key === $key ) {
                $value = trim( preg_replace( '/\s+/', ' ', (string) $field_value ) );
                if ( $value !== '' ) {
                    return $value;
                }
            }
        }
    }
    return '';
};

$service_need = $pick_field( $form_fields, array( 'service', 'servicio', 'service_category', 'categoria', 'category', 'tipo_servicio' ) );
$message_source = trim( (string) ( $es_d['message'] ?? '' ) );
if ( $message_source === '' ) {
    $message_source = $pick_field( $form_fields, array( 'message', 'mensaje', 'comments', 'comentario', 'consulta' ) );
}
$message_need = trim( preg_replace( '/\s+/', ' ', $message_source ) );
$urgency = $pick_field( $form_fields, array( 'urgency', 'urgencia', 'plazo', 'timeline', 'cuando' ) );
$commercial_need = $service_need !== '' ? $service_need : $message_need;
$has_commercial_need = $commercial_need !== '';
if ( ! $has_commercial_need ) {
    $commercial_need = 'No indicada';
}
$commercial_risk = '';
// IEE-5: cuando el Interest Evolution Engine está activo, el riesgo comercial
// se deriva del motor (narratives). AUSENCIA = DEFAULT: si absent, se conserva
// la lógica previa (byte-idéntica).
$iee_active = is_array($es_d['interest_narratives'] ?? null)
    && ! empty($es_d['interest_narratives']['headline']);
if ($iee_active) {
    $commercial_risk = (string) $es_d['interest_narratives']['headline'];
} elseif ( $commercial_risk_signal ) {
    $commercial_risk = 'Riesgo de pérdida por recurrencia sin gestión registrada.';
} elseif ( $high_intent_signal && $unmanaged_lead ) {
    $commercial_risk = 'Riesgo de demora: hay señales comerciales y aún falta gestión.';
} elseif ( $unmanaged_lead ) {
    $commercial_risk = 'El prospecto aún no ha sido contactado.';
}
if ( $lead_priority_label !== '' ) {
    $commercial_risk = ($commercial_risk !== '' ? $commercial_risk . ' · ' : '') . 'Prioridad: ' . $lead_priority_label;
}
$utm_source = sanitize_text_field( (string) ( $es_d['utm_source'] ?? '' ) );
$utm_medium = sanitize_text_field( (string) ( $es_d['utm_medium'] ?? '' ) );
$email_origin_label = static function ( string $source, string $medium, string $source_plugin ) use ( $es_d ): string {
    $source_raw = trim( sanitize_text_field( $source ) );
    $medium_raw = trim( sanitize_text_field( $medium ) );
    $source_key = strtolower( $source_raw );
    $medium_key = strtolower( $medium_raw );
    $plugin_key = sanitize_key( $source_plugin );
    $channel = Estandar_Leads_Attribution::resolve_channel( $es_d + array( 'utm_source' => $source, 'utm_medium' => $medium ) );
    if ( $channel === 'meta_ads' && in_array( $source_key, array( 'ig', 'instagram' ), true ) ) { return 'Instagram Ads'; }
    if ( $channel === 'meta_ads' ) { return 'Facebook Ads'; }
    if ( $channel === 'google_ads' ) { return 'Google Ads'; }
    if ( $channel === 'google_organic' ) { return 'Google Orgánico'; }
    if ( $channel === 'organic' ) { return 'Orgánico'; }
    if ( $channel === 'form_web' ) { return 'Formulario Web'; }
    if ( in_array( $source_key, array( 'direct', 'directo' ), true ) || ( $source_key === '' && $medium_key === '' && $plugin_key === '' ) ) { return 'Directo'; }
    if ( strpos( $plugin_key, 'chatbot' ) !== false ) { return 'Chatbot'; }
    if ( $source_raw !== '' || $medium_raw !== '' ) {
        return trim( implode( ' ', array_filter( array( $source_raw, $medium_raw ) ) ) );
    }
    return $source_plugin !== '' ? Estandar_Leads_Email::capture_channel_label( $source_plugin ) : '';
};
$origin_channel = ( $utm_source !== '' || $utm_medium !== '' )
    ? $email_origin_label( $utm_source, $utm_medium, (string) ( $es_d['source_plugin'] ?? '' ) )
    : $email_origin_label( '', '', (string) ( $es_d['source_plugin'] ?? '' ) );
/* Commercial email shows only the human campaign name; raw utm/campaign ids never render (row hidden when absent). */
$origin_campaign = sanitize_text_field( (string) ( $es_d['campaign_name'] ?? '' ) );
$origin_landing_raw = esc_url_raw( (string) ( $es_d['landing_url'] ?? '' ) );
$origin_landing = $origin_landing_raw !== '' ? strtok( $origin_landing_raw, '?' ) : '';
$session_duration = sanitize_text_field( (string) ( $es_d['session_duration'] ?? $es_d['duration'] ?? '' ) );
$session_events = sanitize_text_field( (string) ( $es_d['events_recorded'] ?? $es_d['events'] ?? '' ) );
$activity_summary = array();
if ( $iee_active ) {
    // IEE-5: las señales de evolución vienen del motor.
    $activity_summary = array_values(array_filter((array) ( $es_d['interest_narratives']['evolution'] ?? array() )));
    if ( empty( $activity_summary ) ) {
        $activity_summary[] = 'requiere revisión inicial del operador';
    }
} elseif ( $is_commercial_recontact ) {
    $flames_summary = str_repeat('🔥', min($commercial_recontact_count, 3));
    $activity_summary[] = 'es un recontacto comercial (' . $flames_summary . ' x' . $commercial_recontact_count . ')';
} elseif ( $repeated_lead ) { $activity_summary[] = 'volvió a contactar'; }
if ( $high_intent_signal ) { $activity_summary[] = 'mostró señales comerciales activas'; }
if ( $unmanaged_lead ) { $activity_summary[] = 'está pendiente de contacto o clasificación'; }
if ( $commercial_risk_signal ) { $activity_summary[] = 'presenta riesgo SLA/comercial por recurrencia sin gestión'; }
if ( empty( $activity_summary ) ) { $activity_summary[] = 'requiere revisión inicial del operador'; }

$recommended_actions = array();
if ( $iee_active ) {
    // IEE-5: la siguiente acción proviene del motor (Next Best Action).
    $recommended_actions[] = (string) ( $es_d['interest_narratives']['pending'] ?? '' );
    $recommended_actions[] = 'Registrar resultado y clasificar después del contacto.';
} elseif ( $service_need !== '' ) {
    $recommended_actions[] = 'Contactar con referencia a: ' . $service_need . '.';
}
if ( $message_need !== '' && $message_need !== $service_need ) {
    $recommended_actions[] = 'Usar el mensaje del prospecto como contexto de apertura.';
}
if ( $urgency !== '' ) {
    $recommended_actions[] = 'Priorizar según urgencia indicada: ' . $urgency . '.';
}
if ( $is_commercial_recontact ) {
    $recommended_actions[] = 'Reconocer que ya había contactado antes (recontacto x' . $commercial_recontact_count . ').';
} elseif ( $repeated_lead ) {
    $recommended_actions[] = 'Reconocer que ya había contactado antes.';
}
if ( $high_intent_signal ) {
    $recommended_actions[] = 'Responder con una siguiente acción comercial concreta.';
}
if ( $unmanaged_lead ) {
    $recommended_actions[] = 'Registrar resultado y clasificar después del contacto.';
}
if ( empty( $recommended_actions ) ) {
    $recommended_actions[] = 'Contactar al prospecto y registrar resultado.';
    $recommended_actions[] = 'Clasificar solo con la información confirmada.';
}
$recommended_actions = array_slice( array_values( array_unique( $recommended_actions ) ), 0, 5 );

// FULL_NAME_V1 — surname merged into the headline, never a separate row.
$lead_full_name = Estandar_Leads_Email::full_lead_name( (string) ( $es_d['lead_name'] ?? '' ), $form_fields );

// LEAD_ASSESSMENT_V1 — optional commercial-quality indicator (NOT urgency).
// Rendered only when the installation enables it AND a fit state exists.
$fit_indicator_html = '';
if ( class_exists( 'Estandar_Leads_Fit_Indicator' ) ) {
    $fit_indicator_html = Estandar_Leads_Fit_Indicator::render(
        (string) ( $es_d['fit'] ?? '' ),
        (string) ( $es_d['fit_reason'] ?? '' ),
        array( 'template' => 'email' )
    );
}

// EMAIL_PHONE_DISPLAY_NORMALIZATION_V1 — display only. DB, capture, attribution
// and the WhatsApp link (which normalizes independently) are untouched.
// Fallback to the raw value if normalization yields nothing.
$lead_phone_raw = (string) ( $es_d['lead_phone'] ?? '' );
$lead_phone_display = $lead_phone_raw !== ''
    ? ( (string) Estandar_Leads_Normalizer::phone_display( $lead_phone_raw ) ?: $lead_phone_raw )
    : '';

// PROJECT_FIRST_V1 — project as top-of-email commercial context.
$project_name = resolve_project_display_name(
	$es_d['project_slug'] ?? '',
	$es_d['project_area'] ?? ''
);

// LOCATION — resolve region/city slugs to human labels via Dataset Provider (CORE_OPTIONALITY guard).
$location_region = '';
$location_city   = '';
if ( isset( $form_fields['region'] ) && (string) $form_fields['region'] !== '' ) {
    $region_slug = (string) $form_fields['region'];
    if ( class_exists( 'Estandar_Forms_Dataset_Provider' ) ) {
        foreach ( Estandar_Forms_Dataset_Provider::get( 'cl_regions' ) as $_r ) {
            if ( ( $_r['value'] ?? '' ) === $region_slug ) { $location_region = $_r['label'] ?? $region_slug; break; }
        }
    }
    if ( $location_region === '' ) { $location_region = $region_slug; }
}
if ( isset( $form_fields['city'] ) && (string) $form_fields['city'] !== '' ) {
    $city_slug = (string) $form_fields['city'];
    if ( class_exists( 'Estandar_Forms_Dataset_Provider' ) ) {
        foreach ( Estandar_Forms_Dataset_Provider::get( 'cl_regions_communes' ) as $_c ) {
            if ( ( $_c['value'] ?? '' ) === $city_slug ) { $location_city = $_c['label'] ?? $city_slug; break; }
        }
    }
    if ( $location_city === '' ) { $location_city = $city_slug; }
}
$maps_url = '';
if ( $location_region !== '' || $location_city !== '' ) {
    $maps_query = trim( implode( ', ', array_filter( array( $location_city, $location_region, 'Chile' ) ) ) );
    $maps_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $maps_query );
}

// HISTORY — repeat-contact summary (operational, not marketing).
if ( $iee_active ) {
    // IEE-5: el estado comercial proviene del motor.
    $contact_history = (string) ( $es_d['interest_narratives']['status'] ?? 'Nuevo prospecto recibido.' );
    $evolution = array_filter( (array) ( $es_d['interest_narratives']['evolution'] ?? array() ) );
    if ( ! empty( $evolution ) ) {
        $contact_history .= ' · ' . implode( ' · ', $evolution );
    }
} elseif ($is_commercial_recontact) {
    $flames_history = str_repeat('🔥', min($commercial_recontact_count, 3));
    $contact_history = $flames_history . ' Recontacto comercial x' . $commercial_recontact_count . ' · ' . $submission_count . ' ingresos totales';
} elseif ($is_same_session_contact) {
    $contact_history = 'Misma sesión · ' . $submission_count . ' ingresos';
} elseif ($repeated_lead) {
    $contact_history = 'Lead repetido · ' . $submission_count . ' ingresos';
} else {
    $contact_history = 'Primer contacto pendiente';
}

// ABSOLUTE TIMESTAMP — DD/MM/YYYY · HH:MM using the stored value.
$submitted_at_raw = (string) ( $es_d['submitted_at'] ?? $es_d['created_at'] ?? '' );
$relative_time    = ''; // legacy variable kept to avoid template breaks (not rendered)
$absolute_time    = $submitted_at_raw !== '' ? wp_date( 'd/m/Y · H:i', strtotime( $submitted_at_raw ) ) : '';

// RC2 — human-readable reason shown alongside the suggested priority.
// Derived only from signals already computed for this notification; never
// exposes internal rule IDs. Priority is only rendered when at least one
// signal is set, so this always resolves to a meaningful sentence.
if ( $iee_active ) {
    // IEE-5: la razón de prioridad proviene del motor (narratives + NBA).
    $priority_reason = (string) ( $es_d['interest_narratives']['pending'] ?? '' );
    $interest_state = is_array( $es_d['interest_decision'] ?? null ) ? ( $es_d['interest_decision']['interest_label'] ?? '' ) : '';
    if ( $interest_state !== '' ) {
        $priority_reason = $priority_reason !== '' ? $interest_state . '. ' . $priority_reason : $interest_state;
    }
} elseif ( $commercial_risk_signal ) {
    $priority_reason = 'El prospecto volvió a contactar fuera de la sesión y aún no registra gestión comercial.';
} elseif ( $is_commercial_recontact ) {
    $priority_reason = 'Recontacto comercial registrado (x' . $commercial_recontact_count . '). Ya había contactado antes y volvió en una nueva sesión.';
} elseif ( $high_intent_signal ) {
    $priority_reason = 'Se detectaron señales comerciales activas.';
} elseif ( $repeated_lead ) {
    $priority_reason = 'El prospecto volvió a contactar.';
} elseif ( $unmanaged_lead ) {
    $priority_reason = 'El prospecto aún no registra clasificación comercial.';
} else {
    $priority_reason = '';
}

$technical_items = array_filter( array(
    ! empty( $es_d['lead_id'] ) ? 'Lead #' . absint( $es_d['lead_id'] ) : '',
    ! empty( $es_d['submission_id'] ) ? 'Submission #' . absint( $es_d['submission_id'] ) : '',
) );
$technical_line = implode( ' · ', $technical_items );
$track_data = array(
    'lead_id'       => absint( $es_d['lead_id'] ?? 0 ),
    'submission_id' => absint( $es_d['submission_id'] ?? 0 ),
    'operator_id'   => absint( $es_d['operator_id'] ?? 0 ),
    'project_slug'  => sanitize_key( (string) ( $es_d['project_slug'] ?? '' ) ),
);
$tracked_phone_url = $phone_url !== '' ? Estandar_Leads_Email::operator_tracked_url( 'operator_clicked_call', $phone_url, $track_data ) : '';
$tracked_whatsapp_url = $whatsapp_url !== '' ? Estandar_Leads_Email::operator_tracked_url( 'operator_clicked_whatsapp', $whatsapp_url, $track_data ) : '';
$tracked_email_url = $email_url !== '' ? Estandar_Leads_Email::operator_tracked_url( 'operator_clicked_email', $email_url, $track_data ) : '';
$tracked_feedback_url = ! empty( $es_d['feedback_url'] ) ? Estandar_Leads_Email::operator_tracked_url( 'operator_feedback_started', (string) $es_d['feedback_url'], $track_data ) : '';
$tracked_workspace_url = $workspace_url !== '' ? Estandar_Leads_Email::operator_tracked_url( 'operator_clicked_lead', $workspace_url, $track_data ) : '';
$tracked_maps_url = $maps_url !== '' ? Estandar_Leads_Email::operator_tracked_url( 'operator_clicked_maps', $maps_url, $track_data ) : '';
$operator_open_pixel_url = Estandar_Leads_Email::operator_tracked_url( 'operator_email_opened', home_url( '/' ), $track_data );
?>
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo esc_html( $es_s['subject'] ?? 'Nuevo lead' ); ?></title>
  <style>
    /* Mobile overrides — supported by most clients except Gmail webmail */
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
  <?php if ( $operator_open_pixel_url !== '' ) : ?><img src="<?php echo esc_url( $operator_open_pixel_url ); ?>" width="1" height="1" alt="" style="display:none;width:1px;height:1px;border:0;opacity:0;"><?php endif; ?>

  <!--[if mso]><table role="presentation" width="100%"><tr><td><![endif]-->

  <!-- Preheader (invisible, shown in inbox preview) -->
  <div style="display:none;font-size:1px;color:#f0f2f5;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
    <?php echo esc_html( $es_s['preheader'] ?? '' ); ?>
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
  </div>

  <!-- Email wrapper -->
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
              <span style="font-size:18px;font-weight:700;color:#ffffff;letter-spacing:-.3px;
                           font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
                <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
              </span>
            </td>
          </tr>

          <!-- ── IDENTITY (FULL_NAME_V1) ────────────────────────────────── -->
          <tr>
            <td class="eb" style="padding:24px 36px 4px;
                font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0;font-size:28px;font-weight:700;color:#202124;line-height:1.2;">
                <?php echo esc_html( $lead_full_name !== '' ? $lead_full_name : 'Contacto sin nombre' ); ?>
                <?php if ( $recontact_badge_html !== '' ) : ?>
                  <?php echo $recontact_badge_html; ?>
                <?php endif; ?>
                <?php if ( $fit_indicator_html !== '' ) : ?>
                  <?php echo $fit_indicator_html; ?>
                <?php endif; ?>
              </p>
              <?php if ( $project_name !== '' ) : ?>
              <p style="margin:8px 0 0;font-size:13px;color:#5f6368;line-height:1.6;">
                <strong style="color:#202124;">Proyecto:</strong> <?php echo esc_html( $project_name ); ?>
              </p>
              <?php endif; ?>
              <?php if ( $lead_phone_display !== '' || ! empty( $es_d['lead_email'] ) ) : ?>
              <p style="margin:12px 0 0;font-size:15px;color:#3c4043;line-height:1.7;word-break:break-word;">
                <?php
                if ( $lead_phone_display !== '' ) {
                    echo $tracked_phone_url !== ''
                        ? '📱 <a href="' . esc_url( $tracked_phone_url ) . '" style="color:#3c4043;text-decoration:none;">' . esc_html( $lead_phone_display ) . '</a>'
                        : '📱 ' . esc_html( $lead_phone_display );
                }
                if ( ! empty( $es_d['lead_email'] ) ) {
                    echo $lead_phone_display !== '' ? '<br>' : '';
                    echo '✉ ' . esc_html( (string) $es_d['lead_email'] );
                }
                ?>
              </p>
              <?php endif; ?>
              <?php if ( $location_region !== '' || $location_city !== '' ) : ?>
              <p style="margin:12px 0 0;font-size:13px;color:#3c4043;line-height:1.7;">
                <span style="font-size:11px;font-weight:700;color:#5f6368;letter-spacing:1px;text-transform:uppercase;">📍 Ubicación</span><br>
                <?php if ( $location_region !== '' ) : ?>
                  <strong style="color:#202124;">Región:</strong> <?php echo esc_html( strtoupper( $location_region ) ); ?><br>
                <?php endif; ?>
                <?php if ( $location_city !== '' ) : ?>
                  <strong style="color:#202124;">Comuna:</strong> <?php if ( $tracked_maps_url !== '' ) : ?><a href="<?php echo esc_url( $tracked_maps_url ); ?>" style="color:#3c4043;text-decoration:none;"><?php endif; ?><?php echo esc_html( strtoupper( $location_city ) ); ?><?php if ( $tracked_maps_url !== '' ) : ?></a><?php endif; ?>
                <?php endif; ?>
              </p>
              <?php endif; ?>
              <p style="margin:12px 0 0;font-size:13px;color:#5f6368;line-height:1.6;">
                <?php if ( $has_commercial_need ) : ?><strong style="color:#202124;">Necesidad:</strong> <?php echo esc_html( $commercial_need ); ?><br><?php endif; ?>
                <?php if ( $urgency !== '' ) : ?><strong style="color:#202124;">Urgencia:</strong> <?php echo esc_html( $urgency ); ?><br><?php endif; ?>
                <?php if ( $customer_matched ) : ?><strong style="color:#202124;">Cliente:</strong> MATCHED<br><?php endif; ?>
                <strong style="color:#202124;">Estado comercial:</strong> <?php echo esc_html( $contact_history ); ?><br>
                <?php if ( $absolute_time !== '' ) : ?><span style="color:#80868b;">Recibido: <?php echo esc_html( $absolute_time ); ?></span><?php endif; ?>
              </p>
            </td>
          </tr>

          <!-- ── ORIGIN ────────────────────────────────────────────────── -->
          <?php $_has_origin = $origin_channel !== '' || $origin_campaign !== '' || $origin_landing !== '' || $session_duration !== '' || $session_events !== ''; ?>
          <?php if ( $_has_origin ) : ?>
          <tr>
            <td style="padding:8px 36px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#202124;">Origen</p>
              <p style="margin:0;font-size:13px;color:#5f6368;line-height:1.6;word-break:break-word;">
                <?php if ( $origin_channel !== '' ) : ?><strong style="color:#202124;">Canal:</strong> <?php echo esc_html( $origin_channel ); ?><br><?php endif; ?>
                <?php if ( $origin_campaign !== '' ) : ?><strong style="color:#202124;">Campaña:</strong> <?php echo esc_html( $origin_campaign ); ?><br><?php endif; ?>
                <?php if ( $origin_landing !== '' ) : ?><strong style="color:#202124;">Landing:</strong> <?php echo esc_html( $origin_landing ); ?><br><?php endif; ?>
                <?php if ( $session_duration !== '' ) : ?><strong style="color:#202124;">Duración sesión:</strong> <?php echo esc_html( $session_duration ); ?><br><?php endif; ?>
                <?php if ( $session_events !== '' ) : ?><strong style="color:#202124;">Eventos:</strong> <?php echo esc_html( $session_events ); ?><?php endif; ?>
              </p>
            </td>
          </tr>
          <?php endif; ?>

          <?php if ( $repeated_lead || $is_commercial_recontact || $is_same_session_contact ) : ?>
          <tr>
            <td style="padding:8px 36px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#202124;">Historial</p>
              <p style="margin:0;font-size:13px;color:#5f6368;line-height:1.6;">
                <?php if ( $first_ingress !== '' ) : ?><strong style="color:#202124;">Primer ingreso:</strong> <?php echo esc_html( $first_ingress ); ?><br><?php endif; ?>
                <?php if ( $last_ingress !== '' ) : ?><strong style="color:#202124;">Último ingreso:</strong> <?php echo esc_html( $last_ingress ); ?><br><?php endif; ?>
                <strong style="color:#202124;">Número de ingresos:</strong> <?php echo esc_html( $submission_count ); ?>
                <?php if ( $is_commercial_recontact && $commercial_recontact_count > 0 ) : ?><br><strong style="color:#202124;">Recontactos comerciales:</strong> <?php echo esc_html( $commercial_recontact_count ); ?><?php endif; ?>
              </p>
            </td>
          </tr>
          <?php endif; ?>

          <?php if ( $commercial_risk !== '' ) : ?>
          <!-- ── COMMERCIAL RISK / IEE-5 STATE ────────────────────────────── -->
          <tr>
            <td style="padding:8px 36px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <?php if ( $iee_active ) : ?>
              <p style="margin:0;font-size:14px;color:#202124;line-height:1.5;font-weight:700;">
                <?php echo esc_html( $commercial_risk ); ?>
              </p>
              <?php else : ?>
              <p style="margin:0;font-size:13px;color:#d93025;line-height:1.5;font-weight:600;">
                <?php echo esc_html( $commercial_risk ); ?>
              </p>
              <?php endif; ?>
              <?php if ( $priority_reason !== '' ) : ?>
              <p style="margin:4px 0 0;font-size:12px;color:#5f6368;line-height:1.4;">
                <?php echo esc_html( $priority_reason ); ?>
              </p>
              <?php endif; ?>
            </td>
          </tr>
          <?php endif; ?>

          <!-- ── JOURNEY DEL PROSPECTO (EMAIL_JOURNEY_V1) ──────────────── -->
          <?php
          // Construcción delegada al helper (misma lógica, orden fijo de
          // funnel). El template solo recorre y pinta ✓ / ➜ / ○ por estado.
          $journey_steps = Estandar_Leads_Journey_Builder::build( absint( $es_d['lead_id'] ?? 0 ), $es_d );
          ?>
          <tr>
            <td style="padding:4px 36px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#202124;">Journey del Prospecto</p>
              <p style="margin:0;font-size:13px;line-height:1.9;">
                <?php foreach ( $journey_steps as $_step ) : ?>
                  <?php if ( 'done' === $_step['status'] ) : ?>
                    <span style="color:#188038;">✓</span> <span style="color:#3c4043;"><?php echo esc_html( $_step['label'] ); ?></span><br>
                  <?php elseif ( 'current' === $_step['status'] ) : ?>
                    <span style="color:<?php echo $brand; ?>;font-weight:700;">➜</span> <strong style="color:#202124;"><?php echo esc_html( $_step['label'] ); ?></strong><br>
                  <?php else : ?>
                    <span style="color:#9aa0a6;">○</span> <span style="color:#9aa0a6;"><?php echo esc_html( $_step['label'] ); ?></span><br>
                  <?php endif; ?>
                <?php endforeach; ?>
              </p>
            </td>
          </tr>

          <!-- ── ACTIONS (CONTACT_ACTIONS_FIRST_V1) ─────────────────────── -->
          <tr>
            <td class="cta" align="left" style="padding:18px 32px 6px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 4px 8px;font-size:13px;font-weight:700;color:#202124;">Acciones:</p>
              <?php if ( $whatsapp_url !== '' ) : ?>
                <a href="<?php echo esc_url( $tracked_whatsapp_url ); ?>" style="display:inline-block;margin:4px;padding:16px 30px;border-radius:6px;background:#25d366;color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;">WhatsApp</a>
              <?php endif; ?>
              <a href="<?php echo esc_url( $tracked_feedback_url ); ?>" style="display:inline-block;margin:4px;padding:13px 22px;border-radius:6px;background:#202124;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Clasificar</a>
              <?php if ( $workspace_url !== '' ) : ?>
                <a href="<?php echo esc_url( $tracked_workspace_url ); ?>" style="display:inline-block;margin:4px;padding:13px 22px;border-radius:6px;background:<?php echo $brand; ?>;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Panel de Operación</a>
              <?php endif; ?>
              <?php if ( $quote_create_url !== '' ) : ?>
                <a href="<?php echo esc_url( $quote_create_url ); ?>" style="display:inline-block;margin:4px;padding:13px 22px;border-radius:6px;background:#1e8e3e;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Crear cotización</a>
              <?php endif; ?>
              <?php if ( $sheet_url !== '' ) : ?>
                <a href="<?php echo $sheet_url; ?>" style="display:inline-block;margin:4px;padding:11px 18px;border-radius:6px;background:#50575e;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600;">Registro Consolidado</a>
              <?php endif; ?>
              <p style="margin:10px 4px 0;font-size:12px;color:#80868b;">
                <?php if ( $email_url !== '' ) : ?><a href="<?php echo esc_url( $tracked_email_url ); ?>" style="color:#5f6368;">Enviar email</a> · <?php endif; ?>
                <a href="<?php echo esc_url( $tracked_feedback_url ); ?>" style="color:#80868b;font-size:11px;">Enlace de clasificación</a>
              </p>
            </td>
          </tr>

          <!-- ── MENSAJE DEL PROSPECTO ─────────────────────────────────── -->
          <?php if ( $message_need !== '' ) : ?>
          <tr>
            <td style="padding:4px 36px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#202124;">Mensaje del prospecto</p>
              <div style="background:#f8f9fa;border-left:3px solid #dadce0;border-radius:0 4px 4px 0;padding:12px 16px;font-size:14px;color:#202124;line-height:1.6;word-break:break-word;">
                <?php echo nl2br( esc_html( $message_need ) ); ?>
              </div>
            </td>
          </tr>
          <?php endif; ?>

          <!-- ── FORM DATA ──────────────────────────────────────────────── -->
          <?php if ( ! empty( $form_rows ) ) : ?>
          <tr>
            <td style="padding:12px 36px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#202124;">Perfil del prospecto</p>
              <ul style="margin:0;padding-left:18px;font-size:13px;color:#5f6368;line-height:1.8;">
                <?php foreach ( $form_rows as $label => $value ) : ?>
                  <li><strong style="color:#202124;"><?php echo esc_html( $label ); ?>:</strong> <?php echo nl2br( esc_html( $value ) ); ?></li>
                <?php endforeach; ?>
              </ul>
            </td>
          </tr>
          <?php endif; ?>

          <!-- ── BUSINESS CONTEXT (kept per scope: onboarding deferred) ─── -->
          <?php if ( ! empty( $es_s['show_business_context'] ) ) : ?>
          <tr>
            <td style="padding:4px 36px 16px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                     style="background-color:#fffbf0;border-left:3px solid <?php echo $brand; ?>;
                            border-radius:0 4px 4px 0;
                            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
                <tr>
                  <td style="padding:16px 18px;">
                    <p style="margin:0;font-size:13px;color:#5f6368;line-height:1.6;">
                      Tu clasificación mejora las métricas comerciales.
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <?php endif; ?>

          <!-- ── TECHNICAL DETAILS (CAPTURE_METADATA_COMPACT_V1) ────────── -->
          <?php if ( $technical_line !== '' ) : ?>
          <tr>
            <td style="padding:0 36px 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0;font-size:11px;color:#9aa0a6;line-height:1.5;word-break:break-word;">
                <span style="text-transform:uppercase;letter-spacing:.5px;">Detalles técnicos:</span>
                <?php echo esc_html( $technical_line ); ?>
              </p>
            </td>
          </tr>
          <?php endif; ?>

        </table>
        <!-- /Container -->

      </td>
    </tr>
  </table>
  <!-- /Email wrapper -->

  <!--[if mso]></td></tr></table><![endif]-->

</body>
</html>
