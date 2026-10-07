<?php
/**
 * HTML email template — operator lead notification (v2, self-contained).
 *
 * Variables in scope (set by Estandar\Core\Leads\OperatorNotifier::render_email):
 *   $es_s       — settings array (sender_name, sender_email, brand_color, logo_url, ...)
 *   $es_d       — data array    (lead_name, lead_email, lead_phone, project_slug, ...)
 *   $es_identity — identidad compartida de la Suite (cabecera/footer, Core\Mail)
 *
 * Port de templates/email/lead-notification.php (V4 + NOTIFICATION_ENRICHMENT_V1)
 * SIN dependencias de clases legacy: todos los helpers son inline.
 * Table-based layout, inline CSS only, no JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$brand = esc_attr( $es_s['brand_color'] ?? '#000000' );

// ── Helpers inline (self-contained) ────────────────────────────────────────
$fmt_field_value = static function ( $value ): string {
	if ( is_array( $value ) ) {
		$parts = array();
		foreach ( $value as $v ) {
			$p = is_array( $v )
				? implode( ', ', array_filter( array_map( 'strval', (array) $v ) ) )
				: ( is_scalar( $v ) ? trim( sanitize_textarea_field( (string) $v ) ) : '' );
			if ( $p !== '' ) { $parts[] = $p; }
		}
		return implode( ', ', $parts );
	}
	if ( is_bool( $value ) ) { return $value ? 'Si' : 'No'; }
	if ( ! is_scalar( $value ) ) { return ''; }
	return trim( sanitize_textarea_field( (string) $value ) );
};

$form_rows = static function ( array $fields ) use ( $fmt_field_value ): array {
	$blocked = array(
		'action', 'estandar_ajax', 'estandar_form_id', 'estandar_form_nonce',
		'_estandar_token', '_redirect', 'estandar_ts', 'form_id', 'lead_id',
		'lead_uuid', 'session_id', 'current_page_url', 'landing_url', 'referrer',
		'gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid',
		'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
		'first_touch_timestamp', 'last_touch_timestamp', 'nonce', '_wpnonce',
		'email', 'correo', 'mail', 'telefono', 'phone', 'tel', 'celular',
		'mobile', 'nombre', 'name', 'full_name', 'fullname',
		'apellido', 'apellidos', 'last_name', 'lastname', 'surname',
		'region', 'ciudad', 'city', 'comuna', 'commune', 'province', 'provincia',
		'message', 'mensaje', 'comments', 'comentario', 'consulta',
		// QUALIFY_LABELS_V1: plazo/financiamiento ya van etiquetados arriba (no
		// repetirlos crudos en "Perfil del prospecto").
		'when_buy', 'how_finance',
	);
	$select_keys = array(
		'tipo_cliente', 'tipo_empresa', 'tipo_persona', 'client_type', 'persona',
		'servicio', 'service', 'service_category', 'categoria', 'category',
		'urgencia', 'urgency', 'plazo', 'timeline', 'cuando',
		'estado', 'status', 'estado_civil', 'civil_status',
	);
	$rows = array();
	foreach ( $fields as $key => $value ) {
		$key = is_string( $key ) ? sanitize_key( $key ) : '';
		if ( $key === '' || strpos( $key, '_' ) === 0 || in_array( $key, $blocked, true ) || strpos( $key, 'token' ) !== false || strpos( $key, 'nonce' ) !== false ) {
			continue;
		}
		$value = $fmt_field_value( $value );
		if ( $value === '' ) {
			continue;
		}
		if ( in_array( strtolower( trim( $key ) ), $select_keys, true ) && preg_match( '/^[a-z0-9_\-]+$/', $value ) ) {
			$value = ucfirst( str_replace( array( '_', '-' ), ' ', $value ) );
		}
		// QUALIFY_V1: etiquetas legibles para claves calificadoras conocidas.
		$label_overrides = array(
			'when_buy'    => 'Plazo de compra',
			'how_finance' => 'Financiamiento',
		);
		$label = $label_overrides[ $key ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
		$rows[ $label ] = $value;
	}
	return $rows;
};

$pick_field = static function ( array $fields, array $keys ): string {
	foreach ( $keys as $key ) {
		foreach ( $fields as $field_key => $field_value ) {
			if ( ! is_scalar( $field_value ) ) { continue; }
			$normalized_key = strtolower( trim( str_replace( array( '-', ' ' ), '_', (string) $field_key ) ) );
			if ( $normalized_key === $key ) {
				$value = trim( preg_replace( '/\s+/', ' ', (string) $field_value ) );
				if ( $value !== '' ) { return $value; }
			}
		}
	}
	return '';
};

$whatsapp_digits = static function ( string $phone ): string {
	$digits = preg_replace( '/\D+/', '', $phone );
	if ( ! is_string( $digits ) || $digits === '' ) { return ''; }
	if ( strlen( $digits ) === 9 && strpos( $digits, '9' ) === 0 ) { $digits = '56' . $digits; }
	return $digits;
};
$phone_display = static function ( string $phone ): string {
	$digits = preg_replace( '/\D+/', '', $phone );
	if ( strlen( $digits ) === 11 && strpos( $digits, '56' ) === 0 ) {
		return '+56 ' . substr( $digits, 2, 1 ) . ' ' . substr( $digits, 3, 4 ) . ' ' . substr( $digits, 7 );
	}
	return $phone;
};

$tracked_url = static function ( string $event_type, string $target_url, array $data ): string {
	// MAIL_TRACKING_V2: la ruta legacy `estandar-leads/v1/go/{token}` se retiró;
	// ahora se usa la ruta v2 propia (MailTracking). Si no está disponible, cae a
	// la URL directa.
	if ( ! class_exists( 'Estandar\\Core\\Leads\\MailTracking' ) ) {
		return esc_url_raw( $target_url );
	}
	$payload = array(
		'lead_id'       => (int) ( $data['lead_id'] ?? 0 ),
		'submission_id' => (int) ( $data['submission_id'] ?? 0 ),
		'operator_id'   => (int) ( $data['operator_id'] ?? 0 ),
		'project'       => (string) ( $data['project_slug'] ?? '' ),
		'actor'         => 'operator',
		'resource'      => 'operator',
		'evt'           => $event_type,
	);
	if ( $event_type === 'operator_email_opened' ) {
		return \Estandar\Core\Leads\MailTracking::open_url( $payload );
	}
	return \Estandar\Core\Leads\MailTracking::click_url( $target_url, $payload );
};

$channel_label = static function ( string $source, string $medium, string $source_plugin, string $origin_tag = '' ): string {
	// ORIGIN_V1: el `_origin` que dejó la captura manda (4 orígenes claros):
	// web_form · web_popup · instant_fb · instant_ig.
	$origin = sanitize_key( $origin_tag );
	if ( $origin === 'instant_ig' ) { return 'Formulario instantáneo de Instagram'; }
	if ( $origin === 'instant_fb' ) { return 'Formulario instantáneo de Facebook'; }
	if ( $origin === 'web_popup' )  { return 'Popup Web'; }
	if ( $origin === 'web_form' )   { return 'Formulario Web'; }
	$source_key  = strtolower( trim( $source ) );
	$medium_key  = strtolower( trim( $medium ) );
	$plugin_key  = sanitize_key( $source_plugin );
	// Fallback (capturas antiguas sin `_origin`): source_plugin de Meta.
	if ( $plugin_key === 'instagram' ) { return 'Formulario instantáneo de Instagram'; }
	if ( $plugin_key === 'facebook' )  { return 'Formulario instantáneo de Facebook'; }
	$is_meta = in_array( $source_key, array( 'fb', 'facebook', 'ig', 'instagram' ), true );
	if ( $is_meta ) { return in_array( $source_key, array( 'ig', 'instagram' ), true ) ? 'Instagram Ads' : 'Facebook Ads'; }
	if ( $source_key === 'google' && $medium_key === 'cpc' ) { return 'Google Ads'; }
	if ( $source_key === 'google' && $medium_key === 'organic' ) { return 'Google Orgánico'; }
	if ( $medium_key === 'organic' ) { return 'Orgánico'; }
	if ( $medium_key === 'cpc' || $medium_key === 'ppc' || $medium_key === 'paid' ) { return 'Ads'; }
	if ( $source_key === 'direct' || ( $source_key === '' && $medium_key === '' && $plugin_key === '' ) ) { return 'Directo'; }
	if ( strpos( $plugin_key, 'chatbot' ) !== false ) { return 'Chatbot'; }
	if ( strpos( $plugin_key, 'forms' ) !== false || strpos( $plugin_key, 'capture' ) !== false ) { return 'Formulario Web'; }
	if ( strpos( $plugin_key, 'whatsapp' ) !== false ) { return 'WhatsApp'; }
	if ( $source !== '' || $medium !== '' ) { return trim( implode( ' ', array_filter( array( $source, $medium ) ) ) ); }
	return 'Directo';
};

$project_name = function_exists( 'resolve_project_display_name' )
	? resolve_project_display_name( (string) ( $es_d['project_slug'] ?? '' ), (string) ( $es_d['project_area'] ?? '' ) )
	: ( (string) ( $es_d['project_slug'] ?? '' ) !== '' ? $es_d['project_slug'] : '' );

// ── Datos derivados ────────────────────────────────────────────────────────
$form_fields = is_array( $es_d['form_fields'] ?? null ) ? $es_d['form_fields'] : array();
$form_rows   = $form_rows( $form_fields );
if ( empty( $form_rows ) && ! empty( $es_d['message'] ) ) {
	$form_rows['Mensaje'] = sanitize_textarea_field( (string) $es_d['message'] );
}

$whatsapp_digits = $whatsapp_digits( (string) ( $es_d['lead_phone'] ?? '' ) );
$whatsapp_url    = $whatsapp_digits !== '' ? 'https://wa.me/' . $whatsapp_digits : '';
$phone_url       = $whatsapp_digits !== '' ? 'tel:' . $whatsapp_digits : '';
$email_url       = ! empty( $es_d['lead_email'] ) ? 'mailto:' . sanitize_email( (string) $es_d['lead_email'] ) : '';

$submission_count = (int) ( $es_d['submission_count'] ?? 0 );
$is_commercial_recontact = ! empty( $es_d['is_commercial_recontact'] );
$is_same_session_contact = ! empty( $es_d['is_same_session_contact'] );
$repeated_lead    = ! empty( $es_d['repeated_lead'] );
$high_intent_signal = ! empty( $es_d['behavior_high_intent'] ) || ! empty( $es_d['high_intent_signal'] );
$unmanaged_lead   = ! empty( $es_d['unmanaged_lead'] );
// LEAD_FRESH_V1: primer contacto real (un solo ingreso, sin recontactos).
// Para estos leads los estados "aún no contactado / sin clasificación" y el
// SLA "dentro de plazo" son obvios e irrelevantes → se omiten del correo.
$is_fresh_lead   = $submission_count <= 1 && ! $is_commercial_recontact && ! $repeated_lead;
$commercial_recontact_count = (int) ( $es_d['commercial_recontact_count'] ?? 0 );
$commercial_risk_signal = ! empty( $es_d['commercial_risk_signal'] );
$previous_status  = sanitize_text_field( (string) ( $es_d['previous_status'] ?? '' ) );
$customer_matched = ! empty( $es_d['customer_matched'] );
$lead_type        = sanitize_text_field( (string) ( $es_d['lead_type'] ?? ( $submission_count > 1 ? 'Recurrente' : 'Nuevo' ) ) );
$lead_priority_label = sanitize_text_field( (string) ( $es_d['lead_priority_label'] ?? '' ) );

$first_submitted_at = (string) ( $es_d['first_submitted_at'] ?? '' );
$last_submitted_at  = (string) ( $es_d['last_submitted_at'] ?? '' );
$first_ingress = $first_submitted_at !== '' ? wp_date( 'd/m/Y · H:i', \Estandar\Core\Time::epoch( $first_submitted_at ) ) : '';
$last_ingress  = $last_submitted_at !== '' ? wp_date( 'd/m/Y · H:i', \Estandar\Core\Time::epoch( $last_submitted_at ) ) : '';

$service_need  = $pick_field( $form_fields, array( 'service', 'servicio', 'service_category', 'categoria', 'category', 'tipo_servicio' ) );
$message_source = trim( (string) ( $es_d['message'] ?? '' ) );
if ( $message_source === '' ) {
	$message_source = $pick_field( $form_fields, array( 'message', 'mensaje', 'comments', 'comentario', 'consulta' ) );
}
$message_need   = trim( preg_replace( '/\s+/', ' ', $message_source ) );
$urgency        = $pick_field( $form_fields, array( 'urgency', 'urgencia', 'plazo', 'timeline', 'cuando' ) );
$commercial_need = $service_need !== '' ? $service_need : $message_need;
$has_commercial_need = $commercial_need !== '';
if ( ! $has_commercial_need ) { $commercial_need = 'No indicada'; }

// ── QUALIFY_V1 (2026-09-14): calificación por preguntas del formulario ──────
// Los instant forms de Meta (y los forms de captura) pueden traer preguntas
// calificadoras (plazo de compra, financiamiento). Se extraen por sinónimos y
// se deriva una prioridad simple para que el operador ordene/descarte a ojo.
$purchase_timeline = $pick_field( $form_fields, array(
	'when_buy', 'cuando_piensas_comprar', 'cuando_compras', 'cuando_compra',
	'plazo_compra', 'plazo', 'timeline', 'cuando', 'intencion_compra',
) );
$financing = $pick_field( $form_fields, array(
	'how_finance', 'financiamiento', 'financiacion', 'financia',
	'como_la_vas_a_financiar', 'forma_de_pago', 'pago', 'credito', 'subsidio',
) );
$qual_lower = static function ( string $s ): string {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
};
// QUALIFY_LABELS_V1: los instant forms de Meta entregan la *key* interna de la
// opción (asap, exploring, m3_6, nose…), no el texto visible. Se mapean a la
// etiqueta legible del formulario para el correo (el score usa el valor crudo).
$qual_labels = array(
	'asap'      => 'Lo antes posible',
	'm1_3'      => '1-3 meses',
	'm3_6'      => '3-6 meses',
	'exploring' => 'Solo averiguando',
	'subsidio'  => 'Tengo subsidio',
	'credito'   => 'Crédito hipotecario',
	'contado'   => 'Ahorro-contado',
	'nose'      => 'Aún no sé',
);
$qual_label = static function ( string $v ) use ( $qual_labels ): string {
	$k = strtolower( trim( $v ) );
	return isset( $qual_labels[ $k ] ) ? $qual_labels[ $k ] : $v;
};
$purchase_timeline_disp = $purchase_timeline !== '' ? $qual_label( $purchase_timeline ) : '';
$financing_disp         = $financing !== '' ? $qual_label( $financing ) : '';
// QUALIFY_CONFIG_V1: pesos y umbrales configurables (Core → Calificación). El
// financiamiento resuelto (contado/ahorro) es señal fuerte; "solo averiguando"
// es neutro (no penaliza), de modo que tener la plata no quede como "Frío".
$_q_defaults = array(
	'plazo_asap' => 3, 'plazo_m1_3' => 2, 'plazo_m3_6' => 1, 'plazo_exploring' => 0,
	'fin_contado' => 3, 'fin_subsidio' => 2, 'fin_credito' => 1, 'fin_nose' => 0,
	'hot' => 5, 'warm' => 2,
);
$q = is_array( $es_d['qualify'] ?? null )
	? array_merge( $_q_defaults, array_map( 'intval', $es_d['qualify'] ) )
	: $_q_defaults;
$tl_l = $qual_lower( $purchase_timeline );
$fn_l = $qual_lower( $financing );
$tl_key = '';
if ( $tl_l !== '' ) {
	if ( preg_match( '/antes posible|inmediat|asap|esta semana|este mes|urgent/', $tl_l ) )     { $tl_key = 'asap'; }
	elseif ( preg_match( '/1\s*-?\s*3|uno a tres|corto plazo|m1_3/', $tl_l ) )                   { $tl_key = 'm1_3'; }
	elseif ( preg_match( '/3\s*-?\s*6|m3_6/', $tl_l ) )                                          { $tl_key = 'm3_6'; }
	elseif ( preg_match( '/averiguando|explorando|exploring|informand|solo mirando|a futuro|no estoy seguro/', $tl_l ) ) { $tl_key = 'exploring'; }
}
$fn_key = '';
if ( $fn_l !== '' ) {
	if ( preg_match( '/contado|ahorro/', $fn_l ) )               { $fn_key = 'contado'; }
	elseif ( preg_match( '/subsidio/', $fn_l ) )                 { $fn_key = 'subsidio'; }
	elseif ( preg_match( '/cr[eé]dito|hipotecari/', $fn_l ) )    { $fn_key = 'credito'; }
	elseif ( preg_match( '/a[uú]n no|no s[eé]|no lo s[eé]|nose/', $fn_l ) ) { $fn_key = 'nose'; }
}
$qual_score = ( $tl_key !== '' ? (int) $q[ 'plazo_' . $tl_key ] : 0 )
	+ ( $fn_key !== '' ? (int) $q[ 'fin_' . $fn_key ] : 0 );
$qual_priority = '';
if ( $purchase_timeline !== '' || $financing !== '' ) {
	$qual_priority = $qual_score >= (int) $q['hot'] ? 'Caliente' : ( $qual_score >= (int) $q['warm'] ? 'Tibio' : 'Frío' );
}

// IEE-5 (v2): riesgo comercial desde el motor.
$iee_active = is_array( $es_d['interest_narratives'] ?? null ) && ! empty( $es_d['interest_narratives']['headline'] );
$commercial_risk = '';
if ( $iee_active ) {
	$commercial_risk = (string) $es_d['interest_narratives']['headline'];
} elseif ( $commercial_risk_signal ) {
	$commercial_risk = 'Riesgo de pérdida por recurrencia sin gestión registrada.';
} elseif ( $high_intent_signal && $unmanaged_lead ) {
	$commercial_risk = 'Riesgo de demora: hay señales comerciales y aún falta gestión.';
} elseif ( $unmanaged_lead && ! $is_fresh_lead ) {
	$commercial_risk = 'El prospecto aún no ha sido contactado.';
}
if ( $lead_priority_label !== '' ) {
	$commercial_risk = ( $commercial_risk !== '' ? $commercial_risk . ' · ' : '' ) . 'Prioridad: ' . $lead_priority_label;
}

$utm_source = sanitize_text_field( (string) ( $es_d['utm_source'] ?? '' ) );
$utm_medium = sanitize_text_field( (string) ( $es_d['utm_medium'] ?? '' ) );
$origin_tag     = $pick_field( $form_fields, array( '_origin' ) );
$origin_channel = $channel_label( $utm_source, $utm_medium, (string) ( $es_d['source_plugin'] ?? '' ), $origin_tag );
$origin_campaign = sanitize_text_field( (string) ( $es_d['campaign_name'] ?? '' ) );
$origin_landing_raw = esc_url_raw( (string) ( $es_d['landing_url'] ?? '' ) );
$origin_landing = $origin_landing_raw !== '' ? strtok( $origin_landing_raw, '?' ) : '';

$activity_summary = array();
if ( $iee_active ) {
	$activity_summary = array_values( array_filter( (array) ( $es_d['interest_narratives']['evolution'] ?? array() ) ) );
	if ( empty( $activity_summary ) ) { $activity_summary[] = 'requiere revisión inicial del operador'; }
} elseif ( $is_commercial_recontact ) {
	$activity_summary[] = 'es un recontacto comercial (x' . $commercial_recontact_count . ')';
} elseif ( $repeated_lead ) { $activity_summary[] = 'volvió a contactar'; }
if ( $high_intent_signal ) { $activity_summary[] = 'mostró señales comerciales activas'; }
if ( $unmanaged_lead ) { $activity_summary[] = 'está pendiente de contacto o clasificación'; }
if ( $commercial_risk_signal ) { $activity_summary[] = 'presenta riesgo SLA/comercial por recurrencia sin gestión'; }
if ( empty( $activity_summary ) ) { $activity_summary[] = 'requiere revisión inicial del operador'; }

$recommended_actions = array();
if ( $iee_active ) {
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

// NOTIFICATION_ENRICHMENT_V1
$score_total    = (float) ( $es_d['score_total'] ?? 0 );
$score_behavior = (float) ( $es_d['score_behavior'] ?? 0 );
$score_capture  = (float) ( $es_d['score_capture'] ?? 0 );
$has_score_ctx  = $score_total > 0 || $score_behavior > 0 || $score_capture > 0;

$lead_age_days = (int) ( $es_d['lead_age_days'] ?? 0 );
$sla_state     = sanitize_key( (string) ( $es_d['sla_state'] ?? '' ) );
$sla_label     = '';
$sla_color     = '#5f6368';
if ( $sla_state === 'escalated' )   { $sla_label = 'Escalado';        $sla_color = '#c62828'; }
elseif ( $sla_state === 'overdue' ) { $sla_label = 'Vencido';         $sla_color = '#d93025'; }
elseif ( $sla_state === 'within' )  { $sla_label = 'Dentro de plazo'; $sla_color = '#188038'; }
$has_sla_ctx = ! $is_fresh_lead && ( $lead_age_days > 0 || in_array( $sla_state, array( 'escalated', 'overdue', 'within' ), true ) );

$revisited_signal = $repeated_lead
	|| ( $iee_active && ( strpos( (string) ( $es_d['interest_narratives']['headline'] ?? '' ), 'interesarse' ) !== false
		|| strpos( (string) ( $es_d['interest_narratives']['headline'] ?? '' ), 'volvió' ) !== false ) );
$engagement_click = ! empty( $es_d['journey']['link_clicked'] );
$action_gesture = '';
if ( $is_commercial_recontact ) {
	$action_gesture = 'Recontacto x' . $commercial_recontact_count . ' → WhatsApp primero.';
} elseif ( $sla_state === 'escalated' ) {
	$action_gesture = 'SLA escalado → Llamar de inmediato.';
} elseif ( $sla_state === 'overdue' ) {
	$action_gesture = 'SLA vencido → Llamar ahora.';
} elseif ( $revisited_signal ) {
	$action_gesture = 'Volvió a interesarse → Email de reactivación.';
} elseif ( $engagement_click ) {
	$action_gesture = 'Mostró interés por el documento → WhatsApp con referencia.';
} elseif ( $high_intent_signal ) {
	$action_gesture = 'Alta intención → WhatsApp con siguiente paso concreto.';
}
$has_suggested_action = $action_gesture !== '' || ! empty( $recommended_actions );

$ai_context_url = esc_url_raw( (string) ( $es_d['ai_context_url'] ?? '' ) );
// Panel de Leads: profundiza directo al lead (ficha del pipeline) cuando es
// posible, así la operadora no tiene que buscarlo entre los recientes.
// El id canónico del lead vive en $es_d['lead_id'] (email_data()); $lead_id NO
// está en el scope de este template (provocaba warning PHP + link sin ?lead=).
$panel_lead_id  = absint( $es_d['lead_id'] ?? 0 );
$panel_url      = home_url( '/panel-leads/' . ( $panel_lead_id > 0 ? '?lead=' . $panel_lead_id : '' ) );

$lead_full_name = trim( (string) ( $es_d['lead_name'] ?? '' ) );
// NAME_FALLBACK_V1: si es_leads.name llegó vacío (p. ej. submission procesada
// con una versión anterior o form con campo de nombre no-canónico), recuperar
// el nombre desde los campos crudos del form ANTES de caer en "Contacto sin
// nombre". Genérico: claves de nombre estándar + prefijos conocidos.
if ( $lead_full_name === '' ) {
	$name_keys = array( 'nombre', 'name', 'full_name', 'fullname', 'nombre_completo', 'nombre_apellido', 'nombre_propietario' );
	foreach ( $form_fields as $fk => $fv ) {
		$fkey = sanitize_key( (string) $fk );
		$is_name_key = in_array( $fkey, $name_keys, true )
			|| strpos( $fkey, 'como_te_llama' ) === 0
			|| strpos( $fkey, 'tu_nombre' ) === 0;
		if ( $is_name_key && is_scalar( $fv ) ) {
			$name_val = trim( sanitize_text_field( (string) $fv ) );
			if ( $name_val !== '' ) {
				$lead_full_name = $name_val;
				break;
			}
		}
	}
}
$surname_keys = array( 'apellido', 'apellidos', 'last_name', 'lastname', 'surname' );
foreach ( $form_fields as $fk => $fv ) {
	if ( in_array( sanitize_key( (string) $fk ), $surname_keys, true ) && is_scalar( $fv ) ) {
		$surname = trim( sanitize_text_field( (string) $fv ) );
		if ( $surname !== '' && stripos( $lead_full_name, $surname ) === false ) {
			$lead_full_name = trim( $lead_full_name . ' ' . $surname );
		}
		break;
	}
}

$lead_phone_raw = (string) ( $es_d['lead_phone'] ?? '' );
$lead_phone_display = $lead_phone_raw !== '' ? ( $phone_display( $lead_phone_raw ) ?: $lead_phone_raw ) : '';

$contact_history = '';
if ( $iee_active ) {
	$contact_history = (string) ( $es_d['interest_narratives']['status'] ?? 'Nuevo prospecto recibido.' );
	$evolution = array_filter( (array) ( $es_d['interest_narratives']['evolution'] ?? array() ) );
	if ( ! empty( $evolution ) ) { $contact_history .= ' · ' . implode( ' · ', $evolution ); }
} elseif ( $is_commercial_recontact ) {
	$contact_history = '🔥 Recontacto comercial x' . $commercial_recontact_count . ' · ' . $submission_count . ' ingresos totales';
} elseif ( $is_same_session_contact ) {
	$contact_history = 'Misma sesión · ' . $submission_count . ' ingresos';
} elseif ( $repeated_lead ) {
	$contact_history = 'Lead repetido · ' . $submission_count . ' ingresos';
} else {
	$contact_history = 'Primer contacto pendiente';
}

$submitted_at_raw = (string) ( $es_d['submitted_at'] ?? $es_d['created_at'] ?? '' );
$absolute_time    = $submitted_at_raw !== '' ? wp_date( 'd/m/Y · H:i', \Estandar\Core\Time::epoch( $submitted_at_raw ) ) : '';

$priority_reason = '';
if ( $iee_active ) {
	$priority_reason = (string) ( $es_d['interest_narratives']['pending'] ?? '' );
	$interest_state = is_array( $es_d['interest_decision'] ?? null ) ? ( $es_d['interest_decision']['interest_label'] ?? '' ) : '';
	if ( $interest_state !== '' ) { $priority_reason = $priority_reason !== '' ? $interest_state . '. ' . $priority_reason : $interest_state; }
} elseif ( $commercial_risk_signal ) {
	$priority_reason = 'El prospecto volvió a contactar fuera de la sesión y aún no registra gestión comercial.';
} elseif ( $is_commercial_recontact ) {
	$priority_reason = 'Recontacto comercial registrado (x' . $commercial_recontact_count . '). Ya había contactado antes y volvió en una nueva sesión.';
} elseif ( $high_intent_signal ) {
	$priority_reason = 'Se detectaron señales comerciales activas.';
} elseif ( $repeated_lead ) {
	$priority_reason = 'El prospecto volvió a contactar.';
} elseif ( $unmanaged_lead && ! $is_fresh_lead ) {
	$priority_reason = 'El prospecto aún no registra clasificación comercial.';
}

$eval_temperature = sanitize_text_field( (string) ( $es_d['eval_temperature'] ?? '' ) );
$eval_reason      = sanitize_text_field( (string) ( $es_d['eval_priority_reason'] ?? '' ) );
$eval_signals     = is_array( $es_d['eval_behavior_signals'] ?? null ) ? array_values( array_filter( array_map( 'sanitize_text_field', $es_d['eval_behavior_signals'] ) ) ) : array();
$has_eval         = $eval_temperature !== '' || $eval_reason !== '' || ! empty( $eval_signals ) || $has_score_ctx;

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
$tracked_phone_url     = $phone_url !== '' ? $tracked_url( 'operator_clicked_call', $phone_url, $track_data ) : '';
$tracked_whatsapp_url  = $whatsapp_url !== '' ? $tracked_url( 'operator_clicked_whatsapp', $whatsapp_url, $track_data ) : '';
$tracked_email_url     = $email_url !== '' ? $tracked_url( 'operator_clicked_email', $email_url, $track_data ) : '';
$tracked_feedback_url  = ! empty( $es_d['feedback_url'] ) ? $tracked_url( 'operator_feedback_started', (string) $es_d['feedback_url'], $track_data ) : '';
$tracked_workspace_url = ! empty( $es_d['workspace_url'] ) ? $tracked_url( 'operator_clicked_lead', (string) $es_d['workspace_url'], $track_data ) : '';
$operator_open_pixel_url = $tracked_url( 'operator_email_opened', home_url( '/' ), $track_data );
$sheet_url = ! empty( $es_d['consolidated_sheet_url'] ) ? esc_url( (string) $es_d['consolidated_sheet_url'] ) : '';
?>
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo esc_html( $es_s['subject'] ?? 'Nuevo lead' ); ?></title>
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
  <?php if ( $operator_open_pixel_url !== '' ) : ?><img src="<?php echo esc_url( $operator_open_pixel_url ); ?>" width="1" height="1" alt="" style="display:none;width:1px;height:1px;border:0;opacity:0;"><?php endif; ?>

  <!--[if mso]><table role="presentation" width="100%"><tr><td><![endif]-->

  <div style="display:none;font-size:1px;color:#f0f2f5;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
    <?php echo esc_html( $es_s['preheader'] ?? '' ); ?>
    &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
  </div>

  <table class="ew" role="presentation" width="100%" cellpadding="0" cellspacing="0"
         style="background-color:#f0f2f5;padding:40px 16px;">
    <tr>
      <td align="center" valign="top">

        <table class="ec" role="presentation" width="600" cellpadding="0" cellspacing="0"
               style="width:600px;max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;
                      box-shadow:0 1px 6px rgba(0,0,0,.1);">

          <!-- ── HEADER (identidad compartida de la Suite) ────────────────── -->
          <tr>
            <td style="padding:0;">
              <?php echo \Estandar\Core\Mail::header_html( $es_identity ); ?>
            </td>
          </tr>

          <!-- ── IDENTITY ───────────────────────────────────────────────── -->
          <tr>
            <td class="eb" style="padding:24px 36px 4px;
                font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0;font-size:28px;font-weight:700;color:#202124;line-height:1.2;">
                <?php echo esc_html( $lead_full_name !== '' ? $lead_full_name : 'Contacto sin nombre' ); ?>
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
              <p style="margin:12px 0 0;font-size:13px;color:#5f6368;line-height:1.6;">
                <?php if ( $has_commercial_need ) : ?><strong style="color:#202124;">Necesidad:</strong> <?php echo esc_html( $commercial_need ); ?><br><?php endif; ?>
                <?php if ( $purchase_timeline_disp !== '' ) : ?><strong style="color:#202124;">Plazo de compra:</strong> <?php echo esc_html( $purchase_timeline_disp ); ?><br><?php endif; ?>
                <?php if ( $financing_disp !== '' ) : ?><strong style="color:#202124;">Financiamiento:</strong> <?php echo esc_html( $financing_disp ); ?><br><?php endif; ?>
                <?php if ( $qual_priority !== '' ) : $qual_color = $qual_priority === 'Caliente' ? '#188038' : ( $qual_priority === 'Frío' ? '#d93025' : '#f9ab00' ); ?><strong style="color:#202124;">Prioridad:</strong> <span style="color:<?php echo esc_attr( $qual_color ); ?>;font-weight:700;"><?php echo esc_html( $qual_priority ); ?></span><br><?php endif; ?>
                <?php if ( $urgency !== '' && $urgency !== $purchase_timeline ) : ?><strong style="color:#202124;">Urgencia:</strong> <?php echo esc_html( $urgency ); ?><br><?php endif; ?>
                <?php if ( $customer_matched ) : ?><strong style="color:#202124;">Cliente:</strong> MATCHED<br><?php endif; ?>
                <strong style="color:#202124;">Estado comercial:</strong> <?php echo esc_html( $contact_history ); ?><br>
                <?php if ( $absolute_time !== '' ) : ?><span style="color:#80868b;">Recibido: <?php echo esc_html( $absolute_time ); ?></span><?php endif; ?>
              </p>
            </td>
          </tr>

          <!-- ── ORIGIN ────────────────────────────────────────────────── -->
          <?php $_has_origin = $origin_channel !== '' || $origin_campaign !== '' || $origin_landing !== ''; ?>
          <?php if ( $_has_origin ) : ?>
          <tr>
            <td style="padding:8px 36px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#202124;">Origen</p>
              <p style="margin:0;font-size:13px;color:#5f6368;line-height:1.6;word-break:break-word;">
                <?php if ( $origin_channel !== '' ) : ?><strong style="color:#202124;">Canal:</strong> <?php echo esc_html( $origin_channel ); ?><br><?php endif; ?>
                <?php if ( $origin_campaign !== '' ) : ?><strong style="color:#202124;">Campaña:</strong> <?php echo esc_html( $origin_campaign ); ?><br><?php endif; ?>
                <?php if ( $origin_landing !== '' ) : ?><strong style="color:#202124;">Landing:</strong> <?php echo esc_html( $origin_landing ); ?><?php endif; ?>
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
                <strong style="color:#202124;">Número de ingresos:</strong> <?php echo esc_html( (string) $submission_count ); ?>
                <?php if ( $is_commercial_recontact && $commercial_recontact_count > 0 ) : ?><br><strong style="color:#202124;">Recontactos comerciales:</strong> <?php echo esc_html( (string) $commercial_recontact_count ); ?><?php endif; ?>
              </p>
            </td>
          </tr>
          <?php endif; ?>

          <?php if ( $commercial_risk !== '' ) : ?>
          <!-- ── COMMERCIAL RISK / IEE-5 STATE ────────────────────────────── -->
          <tr>
            <td style="padding:8px 36px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0;font-size:13px;color:#d93025;line-height:1.5;font-weight:600;">
                <?php echo esc_html( $commercial_risk ); ?>
              </p>
              <?php if ( $priority_reason !== '' ) : ?>
              <p style="margin:4px 0 0;font-size:12px;color:#5f6368;line-height:1.4;">
                <?php echo esc_html( $priority_reason ); ?>
              </p>
              <?php endif; ?>
            </td>
          </tr>
          <?php endif; ?>

          <?php if ( $has_sla_ctx ) : ?>
          <!-- ── SLA Y ANTIGÜEDAD (SLA_AGE_CONTEXT_V1) ──────────────────── -->
          <tr>
            <td style="padding:4px 36px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#202124;">SLA y antigüedad</p>
              <p style="margin:0;font-size:13px;color:#5f6368;line-height:1.6;">
                <?php if ( $lead_age_days > 0 ) : ?><strong style="color:#202124;">Lead age:</strong> <?php echo esc_html( (string) $lead_age_days ); ?> <?php echo esc_html( $lead_age_days === 1 ? 'día' : 'días' ); ?><?php echo $unmanaged_lead ? ' sin gestión' : ''; ?><br><?php endif; ?>
                <?php if ( $sla_label !== '' ) : ?><strong style="color:#202124;">SLA:</strong> <span style="color:<?php echo $sla_color; ?>;font-weight:700;"><?php echo esc_html( $sla_label ); ?></span><?php endif; ?>
              </p>
            </td>
          </tr>
          <?php endif; ?>

          <?php if ( $has_eval ) : ?>
          <!-- ── POR QUÉ ESTA PRIORIDAD (PRIORITY_CONTEXT_V1 + SCORE) ───── -->
          <tr>
            <td style="padding:4px 36px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#202124;">Por qué esta prioridad</p>
              <p style="margin:0;font-size:13px;color:#5f6368;line-height:1.6;">
                <?php if ( $eval_temperature !== '' ) : ?><strong style="color:#202124;">Temperatura:</strong> <?php echo esc_html( $eval_temperature ); ?><br><?php endif; ?>
                <?php if ( $eval_reason !== '' ) : ?><strong style="color:#202124;">Razón:</strong> <?php echo esc_html( $eval_reason ); ?><br><?php endif; ?>
                <?php if ( $has_score_ctx ) : ?><strong style="color:#202124;">Score:</strong> <?php echo esc_html( (string) $score_total ); ?> <span style="color:#80868b;">(Comportamiento <?php echo esc_html( (string) $score_behavior ); ?> · Captura <?php echo esc_html( (string) $score_capture ); ?>)</span><br><?php endif; ?>
                <?php if ( ! empty( $eval_signals ) ) : ?><strong style="color:#202124;">Señales:</strong> <?php echo esc_html( implode( ' · ', $eval_signals ) ); ?><?php endif; ?>
              </p>
            </td>
          </tr>
          <?php endif; ?>

          <!-- ── ACTIONS ────────────────────────────────────────────────── -->
          <tr>
            <td class="cta" align="left" style="padding:18px 32px 6px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 4px 8px;font-size:13px;font-weight:700;color:#202124;">Acciones:</p>
              <?php if ( $whatsapp_url !== '' ) : ?>
                <a href="<?php echo esc_url( $tracked_whatsapp_url ); ?>" style="display:inline-block;margin:4px;padding:16px 30px;border-radius:6px;background:#25d366;color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;">WhatsApp</a>
              <?php endif; ?>
              <?php if ( $tracked_feedback_url !== '' ) : ?>
                <a href="<?php echo esc_url( $tracked_feedback_url ); ?>" style="display:inline-block;margin:4px;padding:13px 22px;border-radius:6px;background:#202124;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Clasificar</a>
              <?php endif; ?>
              <?php if ( $panel_url !== '' ) : ?>
                <a href="<?php echo esc_url( $panel_url ); ?>" style="display:inline-block;margin:4px;padding:13px 22px;border-radius:6px;background:#3c4043;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Panel de Leads</a>
              <?php endif; ?>
              <?php if ( $tracked_workspace_url !== '' ) : ?>
                <a href="<?php echo esc_url( $tracked_workspace_url ); ?>" style="display:inline-block;margin:4px;padding:13px 22px;border-radius:6px;background:<?php echo $brand; ?>;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">Panel de Operación</a>
              <?php endif; ?>
              <?php if ( $sheet_url !== '' ) : ?>
                <a href="<?php echo $sheet_url; ?>" style="display:inline-block;margin:4px;padding:11px 18px;border-radius:6px;background:#50575e;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600;">Registro Consolidado</a>
              <?php endif; ?>
              <p style="margin:10px 4px 0;font-size:12px;color:#80868b;">
                <?php if ( $tracked_email_url !== '' ) : ?><a href="<?php echo esc_url( $tracked_email_url ); ?>" style="color:#5f6368;">Enviar email</a> · <?php endif; ?>
                <?php if ( $tracked_feedback_url !== '' ) : ?><a href="<?php echo esc_url( $tracked_feedback_url ); ?>" style="color:#80868b;font-size:11px;">Enlace de clasificación</a><?php endif; ?>
              </p>
            </td>
          </tr>

          <!-- ── ACCIÓN SUGERIDA (ACCION_SUGERIDA_V1) ──────────────────── -->
          <?php if ( $has_suggested_action ) : ?>
          <tr>
            <td style="padding:4px 36px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#202124;">Acción sugerida</p>
              <?php if ( $action_gesture !== '' ) : ?>
              <p style="margin:0 0 6px;font-size:14px;font-weight:700;color:#188038;line-height:1.5;">
                <?php echo esc_html( $action_gesture ); ?>
              </p>
              <?php endif; ?>
              <?php if ( ! empty( $recommended_actions ) ) : ?>
              <ul style="margin:0;padding-left:18px;font-size:13px;color:#5f6368;line-height:1.7;">
                <?php foreach ( $recommended_actions as $_ra ) : ?>
                  <li><?php echo esc_html( $_ra ); ?></li>
                <?php endforeach; ?>
              </ul>
              <?php endif; ?>
            </td>
          </tr>
          <?php endif; ?>

          <!-- ── MENSAJE DEL PROSPECTO ─────────────────────────────────── -->
          <tr>
            <td style="padding:4px 36px 12px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">
              <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#202124;">Mensaje del prospecto</p>
              <?php if ( $message_need !== '' ) : ?>
              <div style="background:#f8f9fa;border-left:3px solid #dadce0;border-radius:0 4px 4px 0;padding:12px 16px;font-size:14px;color:#202124;line-height:1.6;word-break:break-word;">
                <?php echo nl2br( esc_html( $message_need ) ); ?>
              </div>
              <?php elseif ( $service_need !== '' || ! empty( $form_rows ) ) : ?>
              <div style="background:#f8f9fa;border-left:3px solid #dadce0;border-radius:0 4px 4px 0;padding:12px 16px;font-size:13px;color:#5f6368;line-height:1.6;">
                Sin mensaje libre — necesidad declarada en el formulario. El mensaje del prospecto acelera la preparación de la llamada.
              </div>
              <?php else : ?>
              <div style="background:#fef7e0;border-left:3px solid #f9ab00;border-radius:0 4px 4px 0;padding:12px 16px;font-size:13px;color:#7f5417;line-height:1.6;">
                <strong>Form básico — sin mensaje/necesidad capturada.</strong> Un formulario perfilado captura la necesidad real y agiliza el primer contacto.
              </div>
              <?php endif; ?>
            </td>
          </tr>

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

          <!-- ── TECHNICAL DETAILS ─────────────────────────────────────── -->
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

          <!-- ── FOOTER (identidad compartida de la Suite) ───────────────── -->
          <tr>
            <td style="padding:0 36px 20px;">
              <?php echo \Estandar\Core\Mail::footer_html( $es_identity ); ?>
            </td>
          </tr>

        </table>
        <!-- /Container -->

      </td>
    </tr>
  </table>
  <!-- /Email wrapper -->

  <!--[if mso]></td></tr></table><![endif]-->

</body>
</html>
