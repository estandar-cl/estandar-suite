<?php
/**
 * Admin — Operator portal (lead management dashboard).
 *
 * Variables injected by Estandar_Leads_Admin::portal_content():
 *   $filters     — parsed filter array
 *   $result      — { rows, total, per_page, page, page_count }
 *   $stats       — { total, pending, unreached, hot, converted }
 *   $operators   — all operators (for filter dropdown)
 *   $projects    — project registry
 *   $views       — Estandar_Leads_Portal::views()
 *   $quality_labels, $quality_colors — from Estandar_Leads_Email
 *   $status_labels — from Estandar_Leads_Normalizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$portal_base = admin_url( 'admin.php?page=estandar-leads' );
$el_errors = array(
    'no_lead'           => 'Lead no encontrado.',
    'no_operator'       => 'Este lead no tiene operador asignado. Selecciona un operador y espera la confirmación antes de enviar.',
    'operator_inactive' => 'El operador asignado está inactivo. Reactívalo o asigna otro.',
    'no_operator_email' => 'El operador asignado no tiene email configurado. Edítalo en Configuración.',
    'token_failed'      => 'No se pudo generar el token de clasificación. Inténtalo de nuevo.',
    'motivo_required'   => 'El motivo es obligatorio al cambiar la calidad del lead.',
    'contact_channel_required' => 'El canal de contacto es obligatorio para el estado seleccionado.',
    'contact_outcome_required' => 'El resultado del contacto es obligatorio para el estado seleccionado.',
);
$el_error_key = isset( $_GET['el_error'] ) ? sanitize_key( $_GET['el_error'] ) : '';

function el_portal_url( $params, $base ) {
    return esc_url( add_query_arg( $params, $base ) );
}

if ( ! function_exists( 'el_portal_context_value' ) ) {
    function el_portal_context_value( $latest, $key ) {
        if ( ! $latest || empty( $latest->context_json ) ) {
            return '';
        }

        $context = json_decode( (string) $latest->context_json, true );
        if ( ! is_array( $context ) ) {
            return '';
        }

        return isset( $context[ $key ] ) && is_scalar( $context[ $key ] )
            ? (string) $context[ $key ]
            : '';
    }
}

if ( ! function_exists( 'el_portal_pretty_json' ) ) {
    function el_portal_pretty_json( $value ) {
        $decoded = is_array( $value ) ? $value : json_decode( (string) $value, true );
        if ( ! is_array( $decoded ) ) {
            return (string) $value;
        }

        $json = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? $json : '';
    }
}

if ( ! function_exists( 'el_portal_form_fields' ) ) {
    function el_portal_form_fields( $latest ) {
        if ( ! $latest || empty( $latest->form_fields_json ) ) {
            return array();
        }

        $decoded = json_decode( (string) $latest->form_fields_json, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}

if ( ! function_exists( 'el_portal_event_metadata' ) ) {
    function el_portal_event_metadata( $event ) {
        $decoded = json_decode( (string) ( $event->metadata ?? '' ), true );
        return is_array( $decoded ) ? $decoded : array();
    }
}

if ( ! function_exists( 'el_portal_event_label' ) ) {
    function el_portal_event_label( $event_type ) {
        $labels = array(
            'lead_created'              => 'Lead creado',
            'status_changed'            => 'Estado cambiado',
            'status_overridden'         => 'Estado reemplazado',
            'quality_changed'           => 'Calidad cambiada',
            'feedback_status_changed'   => 'Compliance: estado de seguimiento',
            'compliance_escalation'     => 'Compliance: notificación enviada',
            'routing_fallback_used'     => 'Routing fallback usado',
            'operator_reassigned'       => 'Operador reasignado',
            'contact_logged'            => 'Contacto registrado',
            'commercial_stage_changed'   => 'Etapa comercial cambiada',
            'lead_list_opened'          => 'Portal abierto',
            'workspace_opened'          => 'Workspace abierto',
            'lead_viewed'               => 'Lead visto',
            'whatsapp_clicked'          => 'WhatsApp abierto',
            'email_clicked'             => 'Email abierto',
            'call_clicked'              => 'Llamada iniciada',
            'operator_email_opened'      => 'Email operador abierto',
            'operator_clicked_call'      => 'Operador abrió llamada',
            'operator_clicked_whatsapp'  => 'Operador abrió WhatsApp',
            'operator_clicked_email'     => 'Operador abrió email',
            'operator_clicked_lead'      => 'Operador abrió lead',
            'operator_clicked_maps'      => 'Operador abrió mapa',
            'operator_feedback_started'  => 'Feedback iniciado',
            'operator_feedback_completed'=> 'Feedback completado',
            'sales_activation_recommendation_generated' => 'Recomendación comercial generada',
            'sales_activation_email_sent' => 'Activación comercial enviada',
            'sales_activation_email_failed' => 'Activación comercial fallida',
        );
        return $labels[ $event_type ] ?? $event_type;
    }
}

if ( ! function_exists( 'el_portal_operator_response' ) ) {
	/**
	 * OBSERVABILITY_SEMANTIC_FIX — derive operator-response data for the lead ficha.
	 *
	 * Fixes 3 prior semantic defects:
	 *   1. Anchor: uses first operator_assigned EVENT (write-once) instead of
	 *      $lead->assigned_at (mutable, rewritten on every reassignment).
	 *   2. Actor filter: workspace/interaction events only counted when actor_type
	 *      is 'operator' or 'supervisor' — admin audits (Rodrigo) are excluded.
	 *   3. workspace_opened (not legacy lead_list_opened) is the canonical event.
	 *
	 * Returns actor_name on each key moment so the display block can show "por X".
	 * Measured in PROJECT BUSINESS TIME (PROJECT_CALENDAR_V1). Core-MU optional.
	 * Pure read over $history (DESC) + $lead.
	 */
	function el_portal_operator_response( $lead, array $history ) {
		$mgmt_types = class_exists( 'Estandar_Leads_Portal' ) ? Estandar_Leads_Portal::management_event_types() : array( 'status_changed', 'quality_changed', 'feedback_status_changed', 'contact_logged' );
		$intx_types = class_exists( 'Estandar_Leads_Portal' ) ? Estandar_Leads_Portal::interaction_event_types() : array( 'autoresponse_link_click', 'whatsapp_clicked', 'email_clicked' );

		// Tracked values. DESC iteration: "keeps overwriting" → ends at earliest in time.
		$first_assign_date        = '';   // earliest operator_assigned/notified event
		$first_workspace_date     = '';   // earliest workspace_opened by operator (not admin)
		$first_workspace_actor    = '';
		$first_lead_viewed_date   = '';   // earliest lead_viewed by operator (not admin)
		$first_lead_viewed_actor  = '';
		$first_intx               = null; // [type, date, actor_name] — earliest operator interaction
		$first_mgmt               = null; // [type, date, actor_name] — earliest management event
		$last_intx                = null; // [type, date, actor_name] — latest operator interaction
		$last_mgmt                = null; // [type, date, actor_name] — latest management event

		foreach ( $history as $ev ) {
			$type = (string) ( $ev->event_type ?? '' );
			$date = (string) ( $ev->created_at ?? '' );
			if ( $date === '' ) {
				continue;
			}

			// Decode metadata to read actor_type and actor_name.
			$meta       = el_portal_event_metadata( $ev );
			$actor_type = (string) ( $meta['actor_type'] ?? 'unknown' );
			// actor_name: metadata wins; fall back to JOIN'ed operator_name (pre-WORKSPACE_ACTOR_TRACE events).
			$actor_name = trim( (string) ( $meta['actor_name'] ?? '' ) ) ?: trim( (string) ( $ev->operator_name ?? '' ) );

			// An "operator action" is any event by operator or supervisor (excludes admin audits).
			$is_operator = in_array( $actor_type, array( 'operator', 'supervisor' ), true );

			// Anchor: earliest assignment event (FIX_1 — replaces mutable $lead->assigned_at).
			if ( in_array( $type, array( 'operator_assigned', 'operator_notified' ), true ) ) {
				$first_assign_date = $date; // keeps overwriting in DESC → earliest
			}

			// First workspace open by operator (FIX_2 actor filter + FIX_3 event type).
			if ( $type === 'workspace_opened' && $is_operator ) {
				$first_workspace_date  = $date;
				$first_workspace_actor = $actor_name;
			}

			// First lead detail view by operator.
			if ( $type === 'lead_viewed' && $is_operator ) {
				$first_lead_viewed_date  = $date;
				$first_lead_viewed_actor = $actor_name;
			}

			// First/last operator interaction (keeps overwriting in DESC → first_intx = earliest).
			if ( $is_operator && in_array( $type, $intx_types, true ) ) {
				$first_intx = array( $type, $date, $actor_name ); // overwrite → earliest
				if ( $last_intx === null ) {
					$last_intx = array( $type, $date, $actor_name ); // first match in DESC = latest
				}
			}

			// First/last management event.
			// Exclude compliance-triggered feedback_status_changed — that's system escalation, not operator action.
			if ( in_array( $type, $mgmt_types, true ) ) {
				if ( $type === 'feedback_status_changed' && ( $meta['triggered_by'] ?? '' ) === 'compliance' ) {
					continue;
				}
				$first_mgmt = array( $type, $date, $actor_name ); // overwrite → earliest
				if ( $last_mgmt === null ) {
					$last_mgmt = array( $type, $date, $actor_name );
				}
			}
		}

		// Canonical anchor: first operator_assigned event; fallback to stored field.
		$assigned_at = $first_assign_date !== '' ? $first_assign_date : trim( (string) ( $lead->assigned_at ?? '' ) );

		// Business time since assignment (project calendar). Core-MU optional.
		$biz_minutes = null;
		if ( $assigned_at !== '' && class_exists( 'Estandar_Project_Calendar' ) ) {
			$res = Estandar_Project_Calendar::business_elapsed_for_project(
				$assigned_at,
				gmdate( 'Y-m-d H:i:s' ),
				(string) ( $lead->project_slug ?? '' )
			);
			$biz_minutes = (int) ( $res['business_elapsed_minutes'] ?? 0 );
		}

		// SLA intervals: business minutes from assignment to first key moment.
		// Null = not occurred yet / calendar not available.
		$project_slug = (string) ( $lead->project_slug ?? '' );
		$biz_elapsed  = static function ( string $from, string $to ) use ( $project_slug ): ?int {
			if ( $from === '' || $to === '' || ! class_exists( 'Estandar_Project_Calendar' ) ) {
				return null;
			}
			$r = Estandar_Project_Calendar::business_elapsed_for_project( $from, $to, $project_slug );
			return isset( $r['business_elapsed_minutes'] ) ? (int) $r['business_elapsed_minutes'] : null;
		};

		$sla_access  = $biz_elapsed( $assigned_at, $first_workspace_date );   // assigned → primer acceso
		$sla_contact = $biz_elapsed( $assigned_at, $first_intx[1] ?? '' );    // assigned → primer contacto
		$sla_mgmt    = $biz_elapsed( $assigned_at, $first_mgmt[1] ?? '' );    // assigned → primera gestión

		// Status badge (ordered by severity / progress).
		if ( $assigned_at === '' ) {
			$status = 'Sin asignar';
		} elseif ( $last_mgmt !== null ) {
			$status = 'Gestionado';
		} elseif ( $first_workspace_date !== '' ) {
			$status = 'Workspace abierto sin gestión';
		} elseif ( $biz_minutes !== null && $biz_minutes < 60 ) {
			$status = 'Asignado recientemente';
		} else {
			$status = 'Sin interacción tras asignación';
		}

		return array(
			'assigned_at'             => $assigned_at,
			'first_workspace_date'    => $first_workspace_date,
			'first_workspace_actor'   => $first_workspace_actor,
			'first_lead_viewed_date'  => $first_lead_viewed_date,
			'first_lead_viewed_actor' => $first_lead_viewed_actor,
			'first_intx'              => $first_intx,  // [type, date, actor_name] or null
			'first_mgmt'              => $first_mgmt,  // [type, date, actor_name] or null
			'last_intx'               => $last_intx,   // [type, date, actor_name] or null
			'last_mgmt'               => $last_mgmt,   // [type, date, actor_name] or null
			'biz_minutes'             => $biz_minutes,
			'sla_access'              => $sla_access,   // min hábiles assigned→primer acceso
			'sla_contact'             => $sla_contact,  // min hábiles assigned→primer contacto
			'sla_mgmt'                => $sla_mgmt,     // min hábiles assigned→primera gestión
			'status'                  => $status,
		);
	}
}

if ( ! function_exists( 'el_portal_contact_action_url' ) ) {
    function el_portal_contact_action_url( $lead_id, $channel ) {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=estandar_leads_contact_action&lead_id=' . absint( $lead_id ) . '&channel=' . sanitize_key( $channel ) ),
            'estandar_leads_contact_action'
        );
    }
}

if ( ! function_exists( 'el_portal_capture_channel' ) ) {
    function el_portal_capture_channel( $source_plugin ) {
        return method_exists( 'Estandar_Leads_Email', 'capture_channel_label' )
            ? Estandar_Leads_Email::capture_channel_label( (string) $source_plugin )
            : (string) $source_plugin;
    }
}

if ( ! function_exists( 'el_portal_campaign_label' ) ) {
    function el_portal_campaign_label( $value ) {
        return class_exists( 'Estandar_Campaign_Label_Resolver' )
            ? Estandar_Campaign_Label_Resolver::campaign( $value )
            : (string) $value;
    }
}

if ( ! function_exists( 'el_portal_display_value' ) ) {
    function el_portal_display_value( $value, $empty = 'Sin dato' ) {
        $value = trim( (string) $value );
        return $value === '' || strtoupper( $value ) === 'NULL' || $value === '—' ? $empty : $value;
    }
}

if ( ! function_exists( 'el_portal_campaign_attribution' ) ) {
    function el_portal_campaign_attribution( $submission ) {
        global $wpdb;

        $fallback = array(
            'id'   => '',
            'name' => $submission && ! empty( $submission->utm_campaign ) ? el_portal_campaign_label( $submission->utm_campaign ) : '',
        );
        if ( ! $submission || empty( $submission->session_id ) ) {
            return $fallback;
        }

        $old_suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT campaign, campaign_id FROM {$wpdb->prefix}ea_sessions WHERE session_id = %s LIMIT 1",
            (string) $submission->session_id
        ) );
        if ( ! $session ) {
            if ( $old_suppress !== null ) { $wpdb->suppress_errors( $old_suppress ); }
            return $fallback;
        }

        $campaign_id = trim( (string) ( $session->campaign_id ?? '' ) );
        $campaign    = trim( (string) ( $session->campaign ?? '' ) );
        $campaign_name = $campaign_id !== '' ? (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT campaign_name FROM {$wpdb->prefix}ea_ads_daily
             WHERE campaign_name != '' AND (campaign_id = %s OR campaign_name = %s)
             ORDER BY stat_date DESC, id DESC LIMIT 1",
            $campaign_id,
            $campaign
        ) ) : '';
        if ( $old_suppress !== null ) {
            $wpdb->suppress_errors( $old_suppress );
        }

        if ( $campaign_id !== '' ) {
            return array(
                'id'   => $campaign_id,
                'name' => ( $campaign_name !== '' && $campaign_name !== $campaign_id ) ? $campaign_name : '',
            );
        }

        return array( 'id' => '', 'name' => $fallback['name'] );
    }
}

if ( ! function_exists( 'el_portal_ad_label' ) ) {
    function el_portal_ad_label( $value ) {
        return class_exists( 'Estandar_Campaign_Label_Resolver' )
            ? Estandar_Campaign_Label_Resolver::ad( $value )
            : (string) $value;
    }
}

if ( ! function_exists( 'el_portal_adset_label' ) ) {
    function el_portal_adset_label( $value ) {
        return class_exists( 'Estandar_Campaign_Label_Resolver' )
            ? Estandar_Campaign_Label_Resolver::adset( $value )
            : (string) $value;
    }
}

if ( ! function_exists( 'el_portal_has_routing_fallback' ) ) {
    function el_portal_has_routing_fallback( array $history ) {
        foreach ( $history as $event ) {
            if ( (string) ( $event->event_type ?? '' ) === 'routing_fallback_used' ) {
                return true;
            }

            $meta = el_portal_event_metadata( $event );
            if ( (string) ( $meta['routing_mode'] ?? '' ) === 'fallback' ) {
                return true;
            }
        }

        return false;
    }
}

if ( ! function_exists( 'el_portal_field_first' ) ) {
    function el_portal_field_first( array $fields, array $keys ) {
        foreach ( $keys as $key ) {
            if ( isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) && trim( (string) $fields[ $key ] ) !== '' ) {
                return trim( (string) $fields[ $key ] );
            }
        }

        return '';
    }
}
?>
<?php // ── AI Context export: handler ÚNICO compartido (ficha + listado). ──── ?>
<script>
jQuery(function ($) {
    function esCopyToClipboard(text) {
        // navigator.clipboard solo existe en contexto seguro (https/localhost).
        // En dominio local HTTP es undefined → fallback a textarea + execCommand.
        if (window.navigator && navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            try {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                var ok = document.execCommand('copy');
                document.body.removeChild(ta);
                ok ? resolve() : reject(new Error('execCommand copy failed'));
            } catch (err) {
                reject(err);
            }
        });
    }

    $(document).on('click', '[id^="es-copy-ai-context-"]', function (e) {
        e.preventDefault();
        var leadId = $(this).data('lead-id');
        $.post(ajaxurl, {
            action: 'estandar_leads_ai_context',
            lead_id: leadId
        }, function (response) {
            if (response.success && response.data && response.data.context) {
                esCopyToClipboard(response.data.context).then(function () {
                    alert('Contexto IA copiado al portapapeles');
                }).catch(function () {
                    window.prompt('Copia manual (Ctrl/Cmd+C):', response.data.context);
                });
            } else {
                alert('Error: ' + (response.data && response.data.message ? response.data.message : 'desconocido'));
            }
        }).fail(function () {
            alert('Error de conexión.');
        });
    });
});
</script>
<?php

if ( isset( $filters['view'] ) && $filters['view'] === 'detail' ) :
    $lead_detail = is_array( $lead_detail ?? null ) ? $lead_detail : null;
    $lead        = $lead_detail['lead'] ?? null;
    $submissions = $lead_detail['submissions'] ?? array();
    $submission_stats = $lead_detail['submission_stats'] ?? null;
    $latest      = ! empty( $submissions ) ? $submissions[0] : null;
    $history     = $lead_detail['history'] ?? array();
    $oci         = $lead_detail['oci'] ?? null;
    $classifications = $lead_detail['classifications'] ?? array();
    $contact_log = $lead_detail['contact_log'] ?? array();
    $person_summary = is_array( $lead_detail['person_summary'] ?? null ) ? $lead_detail['person_summary'] : array();
    $commercial_timeline = is_array( $lead_detail['commercial_timeline'] ?? null ) ? $lead_detail['commercial_timeline'] : array();
    $canonical_project = ( $lead && class_exists( 'Estandar_Leads_Repository' ) )
        ? Estandar_Leads_Repository::canonical_project( $lead, $latest )
        : (string) ( $lead->project_slug ?? '' );
    if ( class_exists( 'Estandar_Leads_Repository' ) && $canonical_project === Estandar_Leads_Repository::UNRESOLVED_PROJECT ) {
        $canonical_project = '';
    }
    $previous_classification = ! empty( $classifications ) ? $classifications[0] : null;
    $previous_status = '';
    foreach ( $history as $event ) {
        if ( in_array( (string) ( $event->event_type ?? '' ), array( 'status_changed', 'status_overridden' ), true ) && ! empty( $event->from_status ) ) {
            $previous_status = (string) $event->from_status;
            break;
        }
    }
    $assigned_id = $lead ? (int) ( $lead->assigned_operator_id ?? 0 ) : 0;
    $form_fields = el_portal_form_fields( $latest );
    $service_requested = el_portal_field_first( $form_fields, array( 'servicio', 'service', 'motivo', 'motivo_contacto', 'tipo_servicio' ) );
    $client_type = el_portal_field_first( $form_fields, array( 'tipo_cliente', 'client_type', 'tipo_de_cliente' ) );
    $urgency = el_portal_field_first( $form_fields, array( 'urgencia', 'urgency', 'cuando', 'plazo' ) );
    $origin_source = trim( (string) ( $lead->first_utm_source ?? '' ) );
    $origin_medium = trim( (string) ( $lead->first_utm_medium ?? '' ) );
    if ( $origin_source === '' && $origin_medium === '' ) {
        $origin_source = trim( (string) ( $latest->utm_source ?? '' ) );
        $origin_medium = trim( (string) ( $latest->utm_medium ?? '' ) );
    }
    $origin = trim( implode( ' / ', array_filter( array( $origin_source, $origin_medium ) ) ) );
    $detail_url = add_query_arg( array( 'view' => 'detail', 'lead_id' => absint( $lead->id ?? 0 ) ), $portal_base );
    $routing_fallback = el_portal_has_routing_fallback( $history );
    $lead_session_id = $latest && ! empty( $latest->session_id ) ? (string) $latest->session_id : '';
    $journey_enabled = defined( 'ESTANDAR_ANALYTICS_JOURNEY_V1' ) && ESTANDAR_ANALYTICS_JOURNEY_V1;
    $session_url = $lead_session_id !== '' ? admin_url( 'admin.php?page=ea-events&session_id=' . rawurlencode( $lead_session_id ) ) : '';
    $submission_count = $submission_stats ? (int) ( $submission_stats->submission_count ?? 0 ) : count( $submissions );
    $first_submission_at = $submission_stats && ! empty( $submission_stats->first_submitted_at ) ? $submission_stats->first_submitted_at : '';
    $last_submission_at = $submission_stats && ! empty( $submission_stats->last_submitted_at ) ? $submission_stats->last_submitted_at : '';
    $hours_since_first = $first_submission_at !== '' ? (int) floor( ( time() - strtotime( $first_submission_at ) ) / HOUR_IN_SECONDS ) : null;
    $commercial_risk_signal = $submission_count > 1 && trim( (string) ( $lead->quality ?? '' ) ) === '';
    $detail_context_gap = is_array( $context_gap_detail ?? null ) ? $context_gap_detail : array();
    $detail_context_gap_label = ! empty( $detail_context_gap )
        ? (string) ( $detail_context_gap['label'] ?? Estandar_Leads_Repository::context_gap_label( 'NO' ) )
        : 'Sin registro';

    // CUSTOMER_REGISTRY_V1 — read-only customer match for this lead.
    $matched_customer = ( class_exists( 'Estandar_Leads_Customer_Service' ) && $lead )
        ? Estandar_Leads_Customer_Service::get_by_lead( (int) $lead->id )
        : null;
    $audit_input = array_merge(
        $lead ? get_object_vars( $lead ) : array(),
        $latest ? get_object_vars( $latest ) : array(),
        $oci ? get_object_vars( $oci ) : array()
    );
    $attribution_audit = function_exists( 'build_lead_attribution_audit' ) ? build_lead_attribution_audit( $audit_input ) : array();
?>

    <p style="margin:0 0 16px;">
        <a class="button" href="<?php echo esc_url( $portal_base ); ?>">&larr; Volver al listado</a>
    </p>
    <?php if ( $el_error_key && isset( $el_errors[ $el_error_key ] ) ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $el_errors[ $el_error_key ] ); ?></p></div>
    <?php endif; ?>
    <?php if ( ! empty( $created_link ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php if ( $created_link_operator !== '' ) : ?><strong>Email enviado a <?php echo esc_html( $created_link_operator ); ?>.</strong><br><?php endif; ?>
            Enlace de clasificación:<br><input class="large-text" readonly style="margin-top:4px;" value="<?php echo esc_attr( $created_link ); ?>">
        </p></div>
    <?php endif; ?>

    <?php if ( ! $lead ) : ?>
        <div class="notice notice-error"><p>Lead no encontrado.</p></div>
        <?php return; ?>
    <?php endif; ?>
    <?php if ( $routing_fallback ) : ?>
        <div class="notice notice-warning">
            <p><strong>Proyecto sin operador asignado.</strong><br>
            Proyecto "<?php echo esc_html( resolve_project_display_name( $canonical_project, $lead->project_area ?? '' ) ?: '—' ); ?>" uso routing fallback.</p>
        </div>
    <?php endif; ?>
    <?php if ( $commercial_risk_signal ) : ?>
        <div class="notice notice-error">
            <p><strong>🚨 Recontacto sin gestión</strong><br>
            Este prospecto ya dejó sus datos anteriormente y no existe clasificación registrada.</p>
        </div>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">
            <div>
                <h2 style="margin:0 0 6px;font-size:24px;line-height:1.2;"><?php echo esc_html( $lead->name ?: '(sin nombre)' ); ?></h2>
                <div style="font-size:13px;color:#50575e;line-height:1.7;">
                    <?php if ( $lead->email ) : ?><div><strong>Email:</strong> <a href="mailto:<?php echo esc_attr( $lead->email ); ?>"><?php echo esc_html( $lead->email ); ?></a></div><?php endif; ?>
                    <?php if ( $lead->phone ) : ?><div><strong>Telefono:</strong> <?php echo esc_html( Estandar_Leads_Normalizer::phone_display( $lead->phone ) ); ?></div><?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <span style="display:inline-block;padding:5px 10px;border-radius:99px;background:#f0f6fc;color:#0969da;font-size:12px;font-weight:600;"><?php echo esc_html( $status_labels[ $lead->status ] ?? $lead->status ); ?></span>
                <?php if ( ! empty( $lead->quality ) ) : ?>
                    <?php echo Estandar_Leads_Portal::quality_badge( $lead->quality ); ?>
                <?php else : ?>
                    <span style="display:inline-block;padding:5px 10px;border-radius:99px;background:#f6f7f7;color:#646970;font-size:12px;">Calidad: sin evaluar</span>
                <?php endif; ?>
                <?php if ( $matched_customer ) : ?>
                    <span style="display:inline-block;padding:5px 10px;border-radius:99px;background:#d1fae5;color:#065f46;font-size:12px;font-weight:700;letter-spacing:.04em;">CLIENTE</span>
                <?php endif; ?>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-top:18px;">
            <div><strong>Operador</strong><br><?php echo esc_html( $lead->current_operator_name ?: $lead->assigned_operator_name ?: 'Sin asignar' ); ?><?php if ( $routing_fallback ) : ?><br><span style="display:inline-block;margin-top:4px;padding:2px 7px;border-radius:99px;background:#fff4ce;color:#8a6100;font-size:11px;font-weight:700;">Routing fallback</span><?php endif; ?></div>
            <div><strong>Proyecto</strong><br><?php echo esc_html( el_portal_display_value( resolve_project_display_name( $canonical_project, $lead->project_area ?? '' ) ) ); ?></div>
            <?php if ( $service_requested !== '' ) : ?><div><strong>Servicio solicitado</strong><br><?php echo esc_html( $service_requested ); ?></div><?php endif; ?>
            <?php if ( $client_type !== '' ) : ?><div><strong>Tipo cliente</strong><br><?php echo esc_html( $client_type ); ?></div><?php endif; ?>
            <?php if ( $urgency !== '' ) : ?><div><strong>Urgencia</strong><br><?php echo esc_html( $urgency ); ?></div><?php endif; ?>
            <div><strong>Primer ingreso</strong><br><?php echo esc_html( el_portal_display_value( Estandar_Leads_Normalizer::datetime_local( $lead->first_seen_at ) ) ); ?></div>
            <div><strong>Último ingreso</strong><br><?php echo esc_html( el_portal_display_value( Estandar_Leads_Normalizer::datetime_local( $lead->last_seen_at ) ) ); ?></div>
            <div><strong>Primer contacto</strong><br><?php echo esc_html( $first_submission_at !== '' ? Estandar_Leads_Normalizer::datetime_local( $first_submission_at ) : 'Sin dato' ); ?></div>
            <div><strong>Último contacto</strong><br><?php echo esc_html( $last_submission_at !== '' ? Estandar_Leads_Normalizer::datetime_local( $last_submission_at ) : 'Sin dato' ); ?></div>
            <div><strong>N° submissions</strong><br><?php echo esc_html( $submission_count ); ?></div>
            <div><strong>Estado comercial</strong><br><?php $stage_labels = Estandar_Leads_Normalizer::commercial_stage_labels(); echo esc_html( $stage_labels[ $lead->sales_stage ?? '' ] ?? 'Sin etapa' ); ?></div>
            <?php if ( (string) ( $lead->sales_stage ?? '' ) === 'LOST' ) : ?><div><strong>Motivo pérdida</strong><br><?php $lost_labels = Estandar_Leads_Normalizer::lost_reason_labels(); echo esc_html( $lost_labels[ $lead->invalid_reason ?? '' ] ?? 'Sin dato' ); ?></div><?php endif; ?>
        </div>
    </div>

    <?php if ( current_user_can( 'manage_options' ) && ! empty( $attribution_audit ) ) :
        $aa_ids = array_filter( array(
            'gclid'  => $attribution_audit['gclid'] ?? null,
            'gbraid' => $attribution_audit['gbraid'] ?? null,
            'wbraid' => $attribution_audit['wbraid'] ?? null,
            'fbclid' => $attribution_audit['fbclid'] ?? null,
        ) );
        $aa_oci = $attribution_audit['oci'] ?? array();
        $aa_diag = $attribution_audit['diagnosis'] ?? array();
    ?>
    <details style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-bottom:16px;">
        <summary style="cursor:pointer;font-weight:700;">Attribution Audit</summary>
        <table class="widefat striped" style="margin-top:12px;font-size:12px;">
            <tbody>
                <tr><th>Origen detectado</th><td><?php echo esc_html( $attribution_audit['source_detected'] ?? 'unknown' ); ?></td><th>Click identifier presente</th><td><?php echo ! empty( $attribution_audit['has_click_identifier'] ) ? 'Sí' : 'No'; ?></td></tr>
                <tr><th>Identificadores encontrados</th><td colspan="3"><?php echo esc_html( $aa_ids ? implode( ', ', array_map( static function ( $k, $v ) { return $k . '=' . $v; }, array_keys( $aa_ids ), $aa_ids ) ) : 'Ninguno' ); ?></td></tr>
                <tr><th>Elegibilidad OCI</th><td><?php echo ! empty( $aa_oci['eligible'] ) ? 'Sí' : 'No'; ?></td><th>Estado OCI</th><td><?php echo esc_html( ! empty( $aa_oci['accepted'] ) ? 'accepted' : ( ! empty( $aa_oci['rejected'] ) ? 'rejected' : ( ! empty( $aa_oci['sent'] ) ? 'sent' : ( ! empty( $aa_oci['queued'] ) ? 'queued' : 'unknown' ) ) ) ); ?></td></tr>
                <tr><th>Motivo</th><td colspan="3"><?php echo esc_html( $aa_oci['error_message'] ?? $aa_diag['message'] ?? 'unknown' ); ?></td></tr>
                <tr><th>Conversion action</th><td><?php echo esc_html( $aa_oci['conversion_action'] ?? 'unknown' ); ?></td><th>Diagnóstico</th><td><?php echo esc_html( ( $aa_diag['status'] ?? 'unknown' ) . ' / ' . ( $aa_diag['severity'] ?? 'unknown' ) ); ?></td></tr>
            </tbody>
        </table>
    </details>
    <?php endif; ?>

    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-bottom:16px;">
        <h2 style="margin:0 0 12px;">Prospect</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;">
            <div><strong>Leads</strong><br><?php echo esc_html( (int) ( $person_summary['lead_count'] ?? 1 ) ); ?></div>
            <div><strong>Submissions</strong><br><?php echo esc_html( (int) ( $person_summary['submission_count'] ?? $submission_count ) ); ?></div>
            <div><strong>Proyectos</strong><br><?php echo esc_html( (int) ( $person_summary['project_count'] ?? 0 ) ); ?></div>
            <div><strong>Cliente</strong><br><?php echo ! empty( $person_summary['customer_matched'] ) ? 'Sí' : 'No'; ?></div>
        </div>
        <?php if ( (int) ( $person_summary['lead_count'] ?? 1 ) > 1 ) : ?>
            <div style="margin-top:12px;padding:12px;border:1px solid #fedf89;background:#fffbeb;border-radius:6px;">
                <strong>Posible duplicado</strong><br>
                Lead IDs: <?php echo esc_html( implode( ', ', array_map( 'absint', (array) ( $person_summary['lead_ids'] ?? array() ) ) ) ); ?><br>
                Proyectos: <?php echo esc_html( implode( ', ', (array) ( $person_summary['projects'] ?? array() ) ) ?: '—' ); ?><br>
                Última actividad: <?php echo esc_html( ! empty( $person_summary['latest_activity'] ) ? Estandar_Leads_Normalizer::datetime_local( $person_summary['latest_activity'] ) : '—' ); ?>
            </div>
        <?php endif; ?>
    </div>

    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;margin-bottom:16px;">
        <h2 style="margin:0 0 20px;">Activity</h2>
        <div>
            <div style="font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Hitos comerciales</div>
            <?php if ( ! empty( $commercial_timeline ) ) : ?>
                <ul style="margin:0 0 0 18px;list-style:disc;">
                    <?php foreach ( $commercial_timeline as $item ) : ?>
                        <li>
                            <?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $item['date'] ?? '' ) ?: ( $item['date'] ?? '' ) ); ?>
                            - <strong><?php echo esc_html( $item['label'] ?? '' ); ?></strong>
                            <?php if ( ! empty( $item['detail'] ) ) : ?> - <?php echo esc_html( $item['detail'] ); ?><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p style="margin:0;color:#646970;">Sin eventos comerciales.</p>
            <?php endif; ?>
        </div>

    <?php
    // OBSERVABILITY_SEMANTIC_FIX — "Respuesta Operador" block.
    $op_resp = el_portal_operator_response( $lead, $history );
    $op_biz  = $op_resp['biz_minutes'];
    $op_biz_label = $op_resp['biz_minutes'] === null
        ? '—'
        : ( $op_biz >= 60 ? number_format( $op_biz / 60, 1 ) . ' h hábiles' : $op_biz . ' min hábiles' );
    $op_status_colors = array(
        'Gestionado'                     => array( '#0a6b2e', '#e6f4ea' ),
        'Workspace abierto sin gestión'  => array( '#8a6100', '#fff4ce' ),
        'Sin interacción tras asignación'=> array( '#8a1c1c', '#fde7e7' ),
        'Asignado recientemente'         => array( '#0969da', '#f0f6fc' ),
        'Sin asignar'                    => array( '#646970', '#f6f7f7' ),
    );
    $op_c = $op_status_colors[ $op_resp['status'] ] ?? array( '#646970', '#f6f7f7' );

    // Helper: format "Valentina · 11:09" or just "11:09" if no actor.
    $op_fmt = static function ( string $date, string $actor, string $event_type = '' ): string {
        $label = $date !== '' ? Estandar_Leads_Normalizer::datetime_local( $date ) : '';
        if ( $label === '' ) {
            return '—';
        }
        $parts = array();
        if ( $event_type !== '' ) {
            $parts[] = el_portal_event_label( $event_type );
        }
        if ( $actor !== '' ) {
            $parts[] = $actor;
        }
        $parts[] = $label;
        return implode( ' · ', $parts );
    };

    // SLA semáforo: green < 15 min hábiles, yellow 15-60, red > 60.
    // null = evento no ocurrido → no se muestra el intervalo.
    $sla_badge = static function ( ?int $minutes ): string {
        if ( $minutes === null ) {
            return '';
        }
        if ( $minutes < 15 ) {
            return '<span style="color:#0a6b2e;">🟢</span>';
        }
        if ( $minutes <= 60 ) {
            return '<span style="color:#8a6100;">🟡</span>';
        }
        return '<span style="color:#8a1c1c;">🔴</span>';
    };
    $sla_label = static function ( ?int $minutes ): string {
        if ( $minutes === null ) {
            return '—';
        }
        return $minutes >= 60
            ? number_format( $minutes / 60, 1 ) . ' h hábiles'
            : $minutes . ' min hábiles';
    };
    ?>
    <div style="border-top:1px solid #f0f0f0;padding-top:20px;margin-top:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
            <div style="font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.5px;">Respuesta del operador</div>
            <span style="display:inline-block;padding:5px 12px;border-radius:99px;font-size:12px;font-weight:700;color:<?php echo esc_attr( $op_c[0] ); ?>;background:<?php echo esc_attr( $op_c[1] ); ?>;"><?php echo esc_html( $op_resp['status'] ); ?></span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">

            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Operador asignado</div>
                <div style="font-weight:600;"><?php echo esc_html( $op_resp['assigned_at'] !== '' ? Estandar_Leads_Normalizer::datetime_local( $op_resp['assigned_at'] ) : '—' ); ?></div>
            </div>

            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Primer acceso operador</div>
                <div style="font-weight:600;"><?php echo esc_html( $op_fmt( $op_resp['first_workspace_date'], $op_resp['first_workspace_actor'] ) ); ?></div>
                <?php if ( $op_resp['first_workspace_date'] === '' ) : ?><div style="font-size:12px;color:#8a6100;">Workspace no abierto</div><?php endif; ?>
            </div>

            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Primer lead visto</div>
                <div style="font-weight:600;"><?php echo esc_html( $op_fmt( $op_resp['first_lead_viewed_date'], $op_resp['first_lead_viewed_actor'] ) ); ?></div>
            </div>

            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Última interacción</div>
                <div style="font-weight:600;"><?php
                    if ( is_array( $op_resp['last_intx'] ) ) {
                        echo esc_html( $op_fmt( $op_resp['last_intx'][1], $op_resp['last_intx'][2], $op_resp['last_intx'][0] ) );
                    } else { echo '—'; }
                ?></div>
            </div>

            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Última gestión</div>
                <div style="font-weight:600;"><?php
                    if ( is_array( $op_resp['last_mgmt'] ) ) {
                        echo esc_html( $op_fmt( $op_resp['last_mgmt'][1], $op_resp['last_mgmt'][2], $op_resp['last_mgmt'][0] ) );
                    } else {
                        echo '<span style="color:#8a1c1c;">Sin gestión</span>';
                    }
                ?></div>
            </div>

            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Tiempo hábil desde asignación</div>
                <div style="font-weight:600;"><?php echo esc_html( $op_biz_label ); ?></div>
                <?php if ( $op_resp['biz_minutes'] === null && $op_resp['assigned_at'] !== '' ) : ?>
                    <div style="font-size:11px;color:#8a6100;">Calendario de proyecto no disponible</div>
                <?php endif; ?>
            </div>

        </div>

        <?php if ( $op_resp['assigned_at'] !== '' && class_exists( 'Estandar_Project_Calendar' ) ) : ?>
        <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f0;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:10px;">Tiempos de respuesta</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">

                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:18px;line-height:1;"><?php echo $sla_badge( $op_resp['sla_access'] ); // phpcs:ignore ?></span>
                    <div>
                        <div style="font-size:11px;color:#646970;">Hasta primer acceso</div>
                        <div style="font-weight:600;font-size:13px;"><?php echo esc_html( $sla_label( $op_resp['sla_access'] ) ); ?></div>
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:18px;line-height:1;"><?php echo $sla_badge( $op_resp['sla_contact'] ); // phpcs:ignore ?></span>
                    <div>
                        <div style="font-size:11px;color:#646970;">Hasta primer intento de contacto</div>
                        <div style="font-weight:600;font-size:13px;"><?php echo esc_html( $sla_label( $op_resp['sla_contact'] ) ); ?></div>
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:18px;line-height:1;"><?php echo $sla_badge( $op_resp['sla_mgmt'] ); // phpcs:ignore ?></span>
                    <div>
                        <div style="font-size:11px;color:#646970;">Hasta primera gestión</div>
                        <div style="font-weight:600;font-size:13px;"><?php echo esc_html( $sla_label( $op_resp['sla_mgmt'] ) ); ?></div>
                    </div>
                </div>

            </div>
        </div>
        <?php endif; ?>

    </div>

    <?php if ( $matched_customer ) :
        $cm_conf_labels = array( 'high' => 'Alta', 'medium' => 'Media', 'low' => 'Baja' );
        $cm_conf        = $matched_customer['matched_confidence'] ?? '';
        $cm_conf_label  = $cm_conf_labels[ $cm_conf ] ?? '—';
        $cm_conf_colors = array(
            'high'   => array( '#065f46', '#d1fae5' ),
            'medium' => array( '#92400e', '#fef3c7' ),
            'low'    => array( '#991b1b', '#fee2e2' ),
        );
        $cm_cc = $cm_conf_colors[ $cm_conf ] ?? array( '#646970', '#f6f7f7' );

        $cm_amount      = $matched_customer['amount'] ?? '';
        $cm_amount_disp = ( $cm_amount !== '' && $cm_amount !== null )
            ? '$' . number_format( (float) $cm_amount, 0, ',', '.' )
            : '—';
        $cm_started     = ! empty( $matched_customer['started_at'] )
            ? date_i18n( 'd M Y', strtotime( $matched_customer['started_at'] ) )
            : '—';
    ?>
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
            <h2 style="margin:0;">Cliente</h2>
            <span style="display:inline-block;padding:5px 12px;border-radius:99px;font-size:12px;font-weight:700;color:<?php echo esc_attr( $cm_cc[0] ); ?>;background:<?php echo esc_attr( $cm_cc[1] ); ?>;">Confianza: <?php echo esc_html( $cm_conf_label ); ?></span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;">
            <div><strong>Proyecto</strong><br><?php echo esc_html( $matched_customer['project_slug'] ?: '—' ); ?></div>
            <div><strong>Unidad</strong><br><?php echo esc_html( $matched_customer['external_id'] ?: '—' ); ?></div>
            <div><strong>Fecha de compra</strong><br><?php echo esc_html( $cm_started ); ?></div>
            <div><strong>Monto</strong><br><?php echo esc_html( $cm_amount_disp ); ?></div>
            <div><strong>Estado</strong><br><?php echo esc_html( $matched_customer['customer_stage'] ?: '—' ); ?></div>
        </div>
        <p style="margin:12px 0 0;font-size:11px;color:#888;">Solo lectura — vinculado por: <?php echo esc_html( $matched_customer['matched_by'] ?? '—' ); ?>.
        <?php if ( $matched_customer['matched_at'] ) : ?>
            Vinculado el <?php echo esc_html( date_i18n( 'd M Y H:i', strtotime( $matched_customer['matched_at'] ) ) ); ?>.
        <?php endif; ?>
        </p>
    </div>
    <?php endif; ?>

    <?php
    // CLIENT_JOURNEY_V1 — Ad attribution block.
    // Authority: es_leads.first_utm_* (lead-level, not session).
    $cj_campaign_raw = trim( (string) ( $lead->first_utm_campaign ?? '' ) );
    $cj_ad_raw       = trim( (string) ( $lead->first_utm_content ?? '' ) );
    $cj_adset_raw    = trim( (string) ( $lead->first_utm_term ?? '' ) );
    $cj_source       = trim( (string) ( $lead->first_utm_source ?? '' ) );
    $cj_medium       = trim( (string) ( $lead->first_utm_medium ?? '' ) );
    $cj_fbclid       = trim( (string) ( $lead->first_fbclid ?? '' ) );
    $cj_gclid        = trim( (string) ( $lead->first_gclid ?? '' ) );
    $cj_landing      = trim( (string) ( $lead->first_landing_url ?? '' ) );
    $cj_has_ads      = $cj_campaign_raw !== '' || $cj_ad_raw !== '' || $cj_adset_raw !== '' || $cj_fbclid !== '' || $cj_gclid !== '';

    if ( $cj_has_ads ) :
        $cj_platform = '';
        $cj_channel = Estandar_Leads_Attribution::resolve_channel( $lead );
        if ( in_array( $cj_channel, array( 'meta_ads', 'google_ads' ), true ) ) {
            $cj_platform = Estandar_Leads_Attribution::channel_label( $lead );
        } elseif ( $cj_medium === 'paidsocial' ) {
            $cj_platform = 'Paid (' . $cj_source . ')';
        }

        $cj_campaign_label = $cj_campaign_raw !== '' ? el_portal_campaign_label( $cj_campaign_raw ) : '';
        $cj_adset_label    = $cj_adset_raw !== '' ? el_portal_adset_label( $cj_adset_raw ) : '';
        $cj_ad_label       = $cj_ad_raw !== '' ? el_portal_ad_label( $cj_ad_raw ) : '';

        $cj_campaign_is_id = $cj_campaign_raw !== '' && $cj_campaign_label !== $cj_campaign_raw;
        $cj_adset_is_id    = $cj_adset_raw !== '' && $cj_adset_label !== $cj_adset_raw;
        $cj_ad_is_id       = $cj_ad_raw !== '' && $cj_ad_label !== $cj_ad_raw;
    ?>
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
            <h2 style="margin:0;">Atribución</h2>
            <?php if ( $cj_platform !== '' ) : ?>
                <span style="display:inline-block;padding:5px 12px;border-radius:99px;font-size:12px;font-weight:700;color:#0969da;background:#f0f6fc;"><?php echo esc_html( $cj_platform ); ?></span>
            <?php endif; ?>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">

            <?php if ( $cj_campaign_label !== '' ) : ?>
            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Campa&ntilde;a</div>
                <div style="font-weight:600;"><?php echo esc_html( $cj_campaign_label ); ?></div>
                <?php if ( $cj_campaign_is_id ) : ?>
                    <div style="font-size:11px;color:#999;margin-top:2px;">ID: <?php echo esc_html( $cj_campaign_raw ); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ( $cj_adset_label !== '' ) : ?>
            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Conjunto de anuncios</div>
                <div style="font-weight:600;"><?php echo esc_html( $cj_adset_label ); ?></div>
                <?php if ( $cj_adset_is_id ) : ?>
                    <div style="font-size:11px;color:#999;margin-top:2px;">ID: <?php echo esc_html( $cj_adset_raw ); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ( $cj_ad_label !== '' ) : ?>
            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Anuncio</div>
                <div style="font-weight:600;"><?php echo esc_html( $cj_ad_label ); ?></div>
                <?php if ( $cj_ad_is_id ) : ?>
                    <div style="font-size:11px;color:#999;margin-top:2px;">ID: <?php echo esc_html( $cj_ad_raw ); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Primera captura</div>
                <div style="font-weight:600;"><?php echo esc_html( $lead->first_seen_at ? Estandar_Leads_Normalizer::datetime_local( $lead->first_seen_at ) : '—' ); ?></div>
            </div>

            <?php if ( $cj_source !== '' ) : ?>
            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Fuente / Medio</div>
                <div style="font-weight:600;"><?php echo esc_html( $cj_source . ( $cj_medium !== '' ? ' / ' . $cj_medium : '' ) ); ?></div>
            </div>
            <?php endif; ?>

            <?php if ( $cj_landing !== '' ) : ?>
            <div style="grid-column:1/-1;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:4px;">Landing page</div>
                <div style="font-size:13px;word-break:break-all;"><?php echo esc_html( $cj_landing ); ?></div>
            </div>
            <?php endif; ?>

        </div>

        <?php
        $cj_status_label = $status_labels[ $lead->status ] ?? $lead->status;
        $cj_is_advanced  = in_array( $lead->status, array( 'QUALIFIED', 'MEETING_BOOKED', 'PROPOSAL_SENT', 'NEGOTIATION', 'VISIT_SCHEDULED', 'VISITED', 'BANK_EVALUATION', 'RESERVED', 'CONVERTED', 'DEED_SIGNED', 'DELIVERED' ), true );
        if ( $cj_is_advanced ) : ?>
        <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f0;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:10px;">Resultado comercial</div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="display:inline-block;padding:5px 12px;border-radius:99px;font-size:12px;font-weight:700;color:#0a6b2e;background:#e6f4ea;"><?php echo esc_html( $cj_status_label ); ?></span>
                <?php if ( ! empty( $lead->quality ) ) : ?>
                    <?php echo Estandar_Leads_Portal::quality_badge( $lead->quality ); ?>
                <?php endif; ?>
                <?php if ( ! empty( $lead->sales_stage ) ) : ?>
                    <?php $cj_stage_labels = Estandar_Leads_Normalizer::commercial_stage_labels(); ?>
                    <span style="font-size:13px;color:#646970;"><?php echo esc_html( $cj_stage_labels[ $lead->sales_stage ] ?? $lead->sales_stage ); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( $latest ) : ?>
        <details style="margin-top:16px;border-top:1px solid #f0f0f0;padding-top:14px;">
            <summary style="cursor:pointer;font-size:12px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.5px;">UTM / Tracking raw</summary>
            <table class="widefat striped" style="margin-top:10px;font-size:12px;">
                <tbody>
                    <?php $attr_campaign = el_portal_campaign_attribution( $latest ); ?>
                    <tr><th>utm_source</th><td><?php echo esc_html( el_portal_display_value( $latest->utm_source ) ); ?></td><th>utm_medium</th><td><?php echo esc_html( el_portal_display_value( $latest->utm_medium ) ); ?></td></tr>
                    <tr><th>Campaña</th><td><?php echo esc_html( el_portal_display_value( $attr_campaign['name'] ) ); ?></td><th>Campaign ID</th><td><?php echo esc_html( el_portal_display_value( $attr_campaign['id'] ) ); ?></td></tr>
                    <tr><th>utm_term</th><td><?php echo esc_html( $latest->utm_term ? el_portal_adset_label( $latest->utm_term ) : 'Sin dato' ); ?></td><th>utm_content</th><td><?php echo esc_html( $latest->utm_content ? el_portal_ad_label( $latest->utm_content ) : 'Sin dato' ); ?></td></tr>
                    <tr><th>gclid</th><td><?php echo esc_html( el_portal_display_value( $latest->gclid ) ); ?></td><th>fbclid</th><td><?php echo esc_html( el_portal_display_value( $latest->fbclid ) ); ?></td></tr>
                    <tr><th>gbraid</th><td><?php echo esc_html( el_portal_display_value( $latest->gbraid ) ); ?></td><th>wbraid</th><td><?php echo esc_html( el_portal_display_value( $latest->wbraid ) ); ?></td></tr>
                    <tr><th>ttclid</th><td><?php echo esc_html( el_portal_display_value( $latest->ttclid ) ); ?></td><th>session_id</th><td><?php echo esc_html( el_portal_display_value( $latest->session_id ) ); ?></td></tr>
                    <tr><th>Landing URL</th><td colspan="3"><?php echo esc_html( el_portal_display_value( $latest->landing_url ) ); ?></td></tr>
                    <tr><th>Referrer</th><td colspan="3"><?php echo esc_html( el_portal_display_value( $latest->referrer ) ); ?></td></tr>
                    <tr><th>First touch</th><td><?php echo esc_html( el_portal_display_value( $latest->first_touch_timestamp ) ); ?></td><th>Last touch</th><td><?php echo esc_html( el_portal_display_value( $latest->last_touch_timestamp ) ); ?></td></tr>
                </tbody>
            </table>
        </details>
        <?php endif; ?>

    </div>
    <?php else : ?>
    <?php if ( $latest ) : ?>
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;margin-bottom:16px;">
        <h2 style="margin:0 0 12px;">Atribución</h2>
        <table class="widefat striped" style="font-size:12px;">
            <tbody>
                <?php $attr_campaign = el_portal_campaign_attribution( $latest ); ?>
                <tr><th>utm_source</th><td><?php echo esc_html( el_portal_display_value( $latest->utm_source ) ); ?></td><th>utm_medium</th><td><?php echo esc_html( el_portal_display_value( $latest->utm_medium ) ); ?></td></tr>
                <tr><th>Campaña</th><td><?php echo esc_html( el_portal_display_value( $attr_campaign['name'] ) ); ?></td><th>Campaign ID</th><td><?php echo esc_html( el_portal_display_value( $attr_campaign['id'] ) ); ?></td></tr>
                <tr><th>utm_term</th><td><?php echo esc_html( $latest->utm_term ? el_portal_adset_label( $latest->utm_term ) : 'Sin dato' ); ?></td><th>utm_content</th><td><?php echo esc_html( $latest->utm_content ? el_portal_ad_label( $latest->utm_content ) : 'Sin dato' ); ?></td></tr>
                <tr><th>gclid</th><td><?php echo esc_html( el_portal_display_value( $latest->gclid ) ); ?></td><th>fbclid</th><td><?php echo esc_html( el_portal_display_value( $latest->fbclid ) ); ?></td></tr>
                <tr><th>Landing URL</th><td colspan="3"><?php echo esc_html( el_portal_display_value( $latest->landing_url ) ); ?></td></tr>
                <tr><th>Referrer</th><td colspan="3"><?php echo esc_html( el_portal_display_value( $latest->referrer ) ); ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php
    $is_chatbot_lead = $latest && ( (string) ( $latest->source_plugin ?? '' ) === 'estandar-chatbot' );
    $cb_context = array(); $cb_scoring = array(); $cb_chatbot = array();
    $cb_intent = ''; $cb_requirement = ''; $cb_city = ''; $cb_source_page = '';
    $cb_tier = ''; $cb_route = ''; $cb_lead_id = 0; $cb_session_key = '';
    $cb_status = ''; $cb_force_human = false; $cb_escalation = false; $cb_esc_channel = '';
    $tier_display = '';
    if ( $is_chatbot_lead ) {
        $cb_context = json_decode( (string) ( $latest->context_json ?? '' ), true );
        $cb_scoring = json_decode( (string) ( $latest->scoring_json ?? '' ), true );
        $cb_chatbot = json_decode( (string) ( $latest->chatbot_json ?? '' ), true );
        $cb_context = is_array( $cb_context ) ? $cb_context : array();
        $cb_scoring = is_array( $cb_scoring ) ? $cb_scoring : array();
        $cb_chatbot = is_array( $cb_chatbot ) ? $cb_chatbot : array();
        $cb_intent       = (string) ( $cb_context['intent'] ?? '' );
        $cb_requirement  = (string) ( $cb_context['requirement'] ?? '' );
        $cb_city         = (string) ( $cb_context['city'] ?? '' );
        $cb_source_page  = (string) ( $cb_context['source_page_url'] ?? '' );
        $cb_tier         = (string) ( $cb_scoring['lead_tier'] ?? '' );
        $cb_route        = (string) ( $cb_scoring['lead_route'] ?? '' );
        $cb_lead_id      = (int) ( $cb_chatbot['chatbot_lead_id'] ?? 0 );
        $cb_session_key  = (string) ( $cb_chatbot['chatbot_session_key'] ?? '' );
        $cb_status       = (string) ( $cb_chatbot['chatbot_status'] ?? '' );
        $cb_force_human  = ! empty( $cb_chatbot['force_human'] );
        $cb_escalation   = ! empty( $cb_chatbot['escalation_requested'] );
        $cb_esc_channel  = (string) ( $cb_chatbot['escalation_channel'] ?? '' );
        $tier_labels = array(
            'hot' => array( '🔴', 'Alta' ), 'warm' => array( '🟠', 'Media' ),
            'cold' => array( '🔵', 'Baja' ), 'nurturing' => array( '⚪', 'Nurturing' ),
        );
        $tier_display = isset( $tier_labels[ $cb_tier ] ) ? $tier_labels[ $cb_tier ][0] . ' ' . $tier_labels[ $cb_tier ][1] : ucfirst( $cb_tier );
    }
    $has_intelligence = ( $latest && ! empty( $latest->message ) ) || $is_chatbot_lead;
    if ( $has_intelligence ) :
    ?>
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;margin-bottom:16px;">
        <h2 style="margin:0 0 16px;">Inteligencia del Prospecto</h2>

        <?php if ( $latest && ! empty( $latest->message ) ) : ?>
        <div style="margin-bottom:<?php echo esc_attr( $is_chatbot_lead ? '20px' : '0' ); ?>;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:6px;">Mensaje</div>
            <div style="font-size:15px;line-height:1.65;max-width:920px;white-space:pre-wrap;color:#1d2327;"><?php echo esc_html( $latest->message ); ?></div>
        </div>
        <?php endif; ?>

        <?php if ( $is_chatbot_lead ) : ?>
        <div style="<?php echo $latest && ! empty( $latest->message ) ? 'border-top:1px solid #f0f0f0;padding-top:16px;' : ''; ?>">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;margin-bottom:10px;">🤖 Señales del Chatbot</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;">
                <?php if ( $cb_intent !== '' ) : ?><div><strong>Intención</strong><br><?php echo esc_html( $cb_intent ); ?></div><?php endif; ?>
                <?php if ( $cb_requirement !== '' ) : ?><div style="grid-column:span 2;"><strong>Requerimiento</strong><br><span style="white-space:pre-wrap;"><?php echo esc_html( $cb_requirement ); ?></span></div><?php endif; ?>
                <?php if ( $cb_tier !== '' ) : ?><div><strong>Prioridad</strong><br><?php echo esc_html( $tier_display ); ?></div><?php endif; ?>
                <?php if ( $cb_route !== '' ) : ?><div><strong>Ruta</strong><br><?php echo esc_html( $cb_route ); ?></div><?php endif; ?>
                <?php if ( $cb_force_human ) : ?><div><strong>Solicitó humano</strong><br>Sí</div><?php endif; ?>
                <?php if ( $cb_escalation ) : ?><div><strong>Escalación</strong><br><?php echo esc_html( $cb_esc_channel !== '' ? ucfirst( $cb_esc_channel ) : 'Sí' ); ?></div><?php endif; ?>
                <?php if ( $cb_city !== '' ) : ?><div><strong>Ciudad</strong><br><?php echo esc_html( $cb_city ); ?></div><?php endif; ?>
                <?php if ( $cb_source_page !== '' ) : ?><div style="grid-column:span 2;"><strong>Página origen</strong><br><a href="<?php echo esc_url( $cb_source_page ); ?>" target="_blank" style="word-break:break-all;"><?php echo esc_html( wp_html_excerpt( $cb_source_page, 80, '…' ) ); ?></a></div><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <?php
    $interaction_timeline = is_array( $interaction_timeline ?? null ) ? $interaction_timeline : array();
    ?>
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;margin-bottom:16px;">
        <h2 style="margin:0 0 12px;">Señales</h2>
        <?php if ( empty( $interaction_timeline ) ) : ?>
            <p style="margin:0;color:#646970;">Sin señales registradas.</p>
        <?php else : ?>
            <div style="display:grid;gap:10px;">
                <?php foreach ( $interaction_timeline as $signal ) :
                    $signal_date = (string) ( $signal['date'] ?? '' );
                    if ( $signal_date === '' ) {
                        continue;
                    }
                ?>
                    <div style="display:flex;gap:10px;align-items:flex-start;">
                        <span class="dashicons dashicons-marker" aria-hidden="true" style="color:#50575e;margin-top:1px;"></span>
                        <div>
                            <strong><?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $signal_date ) ); ?></strong>
                            <?php echo esc_html( $signal['label'] ?? '' ); ?><br>
                            <span style="font-size:12px;color:#646970;">
                                <?php echo esc_html( Estandar_Leads_Normalizer::time_ago( $signal_date ) ); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-bottom:16px;">
        <h2 style="margin:0 0 12px;">Acciones</h2>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;align-items:flex-end;">
                <?php wp_nonce_field( 'estandar_leads_update_project' ); ?>
                <input type="hidden" name="action" value="estandar_leads_update_project">
                <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead->id ); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( add_query_arg( array( 'view' => 'detail', 'lead_id' => absint( $lead->id ) ), $portal_base ) ); ?>">
                <div>
                    <label style="display:block;font-size:11px;color:#666;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px;">Proyecto</label>
                    <?php
                    $available_projects = Estandar_Leads_Projects::get_all_slugs();
                    $current_project_slug = sanitize_text_field( $lead->project_slug ?? '' );
                    if ( ! empty( $available_projects ) ) : ?>
                        <select name="project_slug" style="max-width:200px;">
                            <option value="">— Sin proyecto —</option>
                            <?php foreach ( $available_projects as $ps ) : ?>
                                <option value="<?php echo esc_attr( $ps ); ?>"<?php selected( $ps, $current_project_slug ); ?>><?php echo esc_html( resolve_project_display_name( $ps, $ps ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else : ?>
                        <input type="text" name="project_slug" value="<?php echo esc_attr( $current_project_slug ); ?>" placeholder="ej: ak-contadores" style="max-width:200px;" />
                    <?php endif; ?>
                </div>
                <button class="button" type="submit">Asignar proyecto</button>
            </form>
            <div>
                <label style="display:block;font-size:11px;color:#666;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px;">Asignar operador</label>
                <select class="es-operator-select" data-lead-id="<?php echo esc_attr( $lead->id ); ?>" style="max-width:220px;">
                    <option value="0"<?php selected( 0, $assigned_id ); ?>>— Sin operador —</option>
                    <?php foreach ( $operators as $op ) : ?>
                        <option value="<?php echo esc_attr( $op->id ); ?>"<?php selected( (int) $op->id, $assigned_id ); ?>><?php echo esc_html( $op->name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="es-assign-status" id="es-assign-<?php echo esc_attr( $lead->id ); ?>" style="display:block;font-size:11px;color:#666;margin-top:2px;min-height:14px;"></span>
            </div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'estandar_leads_create_link' ); ?>
                <input type="hidden" name="action" value="estandar_leads_create_link">
                <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead->id ); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( add_query_arg( array( 'view' => 'detail', 'lead_id' => absint( $lead->id ) ), $portal_base ) ); ?>">
                <button class="button button-primary es-create-link-btn" id="es-link-btn-<?php echo esc_attr( $lead->id ); ?>" <?php echo $assigned_id <= 0 ? 'disabled title="Asigna un operador primero"' : ''; ?>>Enviar a operador</button>
            </form>
            <?php if ( ! empty( $lead->phone ) ) : ?>
                <a class="button" href="<?php echo esc_url( el_portal_contact_action_url( $lead->id, 'whatsapp' ) ); ?>" target="_blank" rel="noopener">WhatsApp</a>
                <a class="button" href="<?php echo esc_url( el_portal_contact_action_url( $lead->id, 'call' ) ); ?>">Llamar</a>
            <?php endif; ?>
            <?php if ( ! empty( $lead->email ) ) : ?>
                <a class="button" href="<?php echo esc_url( el_portal_contact_action_url( $lead->id, 'email' ) ); ?>">Email</a>
            <?php endif; ?>
            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=estandar_leads_export_lead&format=json&lead_id=' . absint( $lead->id ) ), 'estandar_leads_export_lead' ) ); ?>">Exportar JSON</a>
            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=estandar_leads_export_lead&format=csv&lead_id=' . absint( $lead->id ) ), 'estandar_leads_export_lead' ) ); ?>">Exportar CSV</a>
            <button class="button" id="es-copy-ai-context-<?php echo esc_attr( $lead->id ); ?>" data-lead-id="<?php echo esc_attr( $lead->id ); ?>">📋 Copiar Contexto IA</button>
            <button class="button" disabled title="Pendiente: LEAD_ARCHIVE_SYSTEM">Archivar</button>
            <?php
            $esq_admin_actions = apply_filters( 'estandar_leads_operator_portal_actions', array(), $lead );
            foreach ( $esq_admin_actions as $esq_action ) :
                $esq_url = esc_url( $esq_action['url'] ?? '' );
                $esq_label = esc_html( $esq_action['label'] ?? '' );
                $esq_class = esc_attr( $esq_action['class'] ?? 'button' );
                $esq_confirm = ! empty( $esq_action['confirm'] ) ? ' onclick="return confirm(\'' . esc_js( $esq_action['confirm'] ) . '\');"' : '';
                if ( $esq_url && $esq_label ) :
                    ?><a class="<?php echo $esq_class; ?>" href="<?php echo $esq_url; ?>"<?php echo $esq_confirm; ?>><?php echo $esq_label; ?></a><?php
                endif;
            endforeach;
            ?>
        </div>
        <details style="margin-top:16px;">
            <summary style="cursor:pointer;font-weight:600;">Calificar / registrar contacto</summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-top:12px;align-items:end;">
                <?php wp_nonce_field( 'estandar_leads_save_operation' ); ?>
                <input type="hidden" name="action" value="estandar_leads_save_operation">
                <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead->id ); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $detail_url ); ?>">
                <label>Estado
                    <select name="status" id="op-status">
                        <option value="">Sin cambio</option>
                        <?php foreach ( Estandar_Leads_Normalizer::display_statuses() as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>"<?php selected( (string) ( $lead->status ?? '' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Calidad
                    <select name="quality">
                        <option value="">Sin cambio</option>
                        <?php foreach ( $quality_labels as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>"<?php selected( (string) ( $lead->quality ?? '' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Estado comercial
                    <select name="commercial_stage" id="op-commercial-stage">
                        <option value="">Sin cambio</option>
                        <?php foreach ( Estandar_Leads_Normalizer::display_commercial_stages() as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>"<?php selected( (string) ( $lead->sales_stage ?? '' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div id="op-lost-reason-wrap">
                    <label>Motivo <span style="font-size:11px;color:#999;">(opcional)</span>
                        <?php
                        $current_reason = (string) ( $lead->invalid_reason ?? '' );
                        $select_reason  = strpos( $current_reason, 'OTHER: ' ) === 0 ? 'OTHER' : $current_reason;
                        $other_text     = strpos( $current_reason, 'OTHER: ' ) === 0 ? substr( $current_reason, 7 ) : '';
                        ?>
                        <select name="lost_reason" id="op-lost-reason">
                            <option value="">— Opcional —</option>
                            <?php foreach ( Estandar_Leads_Normalizer::display_lost_reasons() as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>"<?php selected( $select_reason, $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div id="op-lost-reason-other-wrap" style="margin-top:6px;<?php echo $select_reason !== 'OTHER' ? 'display:none;' : ''; ?>">
                        <input type="text" name="lost_reason_other" id="op-lost-reason-other"
                               placeholder="Describe el motivo"
                               value="<?php echo esc_attr( $other_text ); ?>"
                               style="width:100%;">
                    </div>
                </div>
	                <label>Canal contacto
                    <select name="contact_channel" id="op-contact-channel">
                        <option value="">No registrar</option>
                        <option value="phone">Telefono</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="email">Email</option>
                        <option value="meeting">Reunion</option>
                        <option value="manual">Otro</option>
                    </select>
                </label>
	                <label>Resultado contacto
	                    <input type="text" name="contact_outcome" id="op-contact-outcome" placeholder="Ej: agenda reunion, no responde">
	                </label>
	                <label><?php echo esc_html( (string) ( $context_gap_template['question'] ?? '¿Qué información faltó?' ) ); ?>
	                    <select name="context_gap">
	                        <?php foreach ( (array) ( $context_gap_template['options'] ?? array() ) as $gap_key => $gap_label ) : ?>
	                            <option value="<?php echo esc_attr( $gap_key ); ?>"><?php echo esc_html( $gap_label ); ?></option>
	                        <?php endforeach; ?>
	                    </select>
	                </label>
	                <label style="grid-column:1/-1;">Nota
	                    <textarea name="notes" rows="3" style="width:100%;"></textarea>
	                </label>
                <div style="grid-column:1/-1;">
                    <button class="button button-primary" type="submit">Guardar gestion</button>
                </div>
            </form>
        </details>
    </div>
<script>
(function() {
    var lostStages   = ['LOST', 'DISQUALIFIED'];
    var stageSelect  = document.getElementById('op-commercial-stage');
    var reasonWrap   = document.getElementById('op-lost-reason-wrap');
    var reasonSelect = document.getElementById('op-lost-reason');
    var otherWrap    = document.getElementById('op-lost-reason-other-wrap');
    var statusSelect = document.getElementById('op-status');
    var channelSelect = document.getElementById('op-contact-channel');
    var outcomeInput = document.getElementById('op-contact-outcome');
    var interactionStatuses = ['CONTACTED', 'QUALIFIED', 'MEETING_BOOKED', 'MEETING_SCHEDULED', 'VISIT_SCHEDULED', 'VISITED', 'PROPOSAL_SENT', 'LOST'];

    function updateReasonVisibility() {
        if (!stageSelect || !reasonWrap) return;
        var val = stageSelect.value;
        reasonWrap.style.opacity = (val === '' || lostStages.indexOf(val) > -1) ? '1' : '0.4';
    }

    function updateOtherVisibility() {
        if (!reasonSelect || !otherWrap) return;
        otherWrap.style.display = reasonSelect.value === 'OTHER' ? 'block' : 'none';
    }

    function updateContactRequirements() {
        if (!channelSelect || !outcomeInput) return;
        var status = statusSelect ? statusSelect.value : '';
        var stage = stageSelect ? stageSelect.value : '';
        var required = interactionStatuses.indexOf(status) > -1 || interactionStatuses.indexOf(stage) > -1;
        channelSelect.required = required;
        outcomeInput.required = required;
    }

    if (stageSelect) {
        stageSelect.addEventListener('change', updateReasonVisibility);
        stageSelect.addEventListener('change', updateContactRequirements);
        updateReasonVisibility();
    }
    if (statusSelect) {
        statusSelect.addEventListener('change', updateContactRequirements);
    }
    if (reasonSelect) {
        reasonSelect.addEventListener('change', updateOtherVisibility);
        updateOtherVisibility();
    }
    updateContactRequirements();
})();
</script>

    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-bottom:16px;">
        <h2 style="margin:0 0 12px;">Submissions</h2>
        <?php if ( ! empty( $submissions ) ) : ?>
            <table class="widefat striped" style="font-size:13px;">
                <thead><tr><th>Fecha</th><th>Proyecto</th><th>Fuente</th><th>Canal</th><th>Campaña</th><th>Landing</th></tr></thead>
                <tbody>
                <?php foreach ( $submissions as $submission ) : ?>
                    <?php
                    $sub_project = (string) ( $submission->canonical_project ?? $canonical_project );
                    $sub_channel = Estandar_Leads_Attribution::channel_label( $submission );
                    ?>
                    <tr>
                        <td><?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $submission->submitted_at ) ?: Estandar_Leads_Normalizer::datetime_local( $submission->created_at ?? '' ) ); ?></td>
                        <td><?php echo esc_html( el_portal_display_value( resolve_project_display_name( $sub_project, $sub_project ) ) ); ?></td>
                        <td><?php echo esc_html( el_portal_display_value( $submission->source_plugin ) ); ?></td>
                        <td><?php echo esc_html( el_portal_display_value( $sub_channel ) ); ?></td>
                        <?php $sub_campaign = el_portal_campaign_attribution( $submission ); ?>
                        <td><?php echo esc_html( el_portal_display_value( $sub_campaign['name'] ) ); ?><?php if ( $sub_campaign['id'] !== '' ) : ?><br><code><?php echo esc_html( $sub_campaign['id'] ); ?></code><?php endif; ?></td>
                        <td><?php echo esc_html( el_portal_display_value( $submission->landing_url ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>No hay submissions asociadas.</p>
        <?php endif; ?>
    </div>

    <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;">
        <h2 style="margin:0 0 12px;">Timeline</h2>
        <?php if ( ! empty( $contact_log ) ) : ?>
            <h3 style="margin:0 0 8px;">Contactos registrados</h3>
            <ul style="margin:0 0 16px 18px;list-style:disc;">
                <?php foreach ( $contact_log as $contact ) : ?>
                    <li><?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $contact->contacted_at ) ); ?> - <?php echo esc_html( $contact->operator_name ?: '—' ); ?> - <?php echo esc_html( $contact->channel ); ?> - <?php echo esc_html( $contact->outcome ); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ( ! empty( $classifications ) ) : ?>
            <h3 style="margin:0 0 8px;">Clasificaciones</h3>
            <table class="widefat striped" style="margin:0 0 20px;font-size:13px;">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Operador</th>
                        <th>Campo</th>
                        <th>Valor anterior</th>
                        <th>Valor nuevo</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $status_labels  = Estandar_Leads_Normalizer::status_labels();
                    $quality_labels = Estandar_Leads_Email::quality_labels();
                    foreach ( $classifications as $cls ) :
                        $prev_q    = (string) ( $cls->previous_quality ?? '' );
                        $curr_q    = (string) ( $cls->quality ?? '' );
                        $prev_s    = '';  // es_lead_classifications no almacena estado anterior; use — placeholder
                        $curr_s    = (string) ( $cls->status ?? '' );
                        $has_q_change = $curr_q !== '' && $curr_q !== $prev_q;
                        $has_s        = $curr_s !== '';
                    ?>
                    <?php if ( $has_q_change ) : ?>
                    <tr>
                        <td><?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $cls->classified_at ) ); ?></td>
                        <td><?php echo esc_html( $cls->operator_name ?: '—' ); ?></td>
                        <td>Calidad</td>
                        <td><?php echo esc_html( $quality_labels[ $prev_q ] ?? ( $prev_q ?: '—' ) ); ?></td>
                        <td><?php echo esc_html( $quality_labels[ $curr_q ] ?? $curr_q ); ?></td>
                        <td><?php echo esc_html( $cls->notes ?: '—' ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ( $has_s ) : ?>
                    <tr>
                        <td><?php if ( ! $has_q_change ) : echo esc_html( Estandar_Leads_Normalizer::datetime_local( $cls->classified_at ) ); endif; ?></td>
                        <td><?php if ( ! $has_q_change ) : echo esc_html( $cls->operator_name ?: '—' ); endif; ?></td>
                        <td>Estado</td>
                        <td>—</td>
                        <td><?php echo esc_html( $status_labels[ $curr_s ] ?? $curr_s ); ?></td>
                        <td><?php if ( ! $has_q_change ) : echo esc_html( $cls->notes ?: '—' ); endif; ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if ( ! $has_q_change && ! $has_s ) : ?>
                    <tr>
                        <td><?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $cls->classified_at ) ); ?></td>
                        <td><?php echo esc_html( $cls->operator_name ?: '—' ); ?></td>
                        <td>—</td><td>—</td><td>—</td>
                        <td><?php echo esc_html( $cls->notes ?: '—' ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if ( empty( $history ) ) : ?>
            <p>No hay eventos registrados.</p>
        <?php else :
            // ---- Event grouping ----
            // Group events by calendar date (Y-m-d). Events arrive DESC; we bucket them
            // and reverse within each day to get ASC for net-change computation.
            $sls      = Estandar_Leads_Normalizer::status_labels();
            $qls      = Estandar_Leads_Email::quality_labels();
            $fs_label = array( 'pending' => 'Pendiente', 'overdue' => 'Vencido', 'escalated' => 'Escalado', 'received' => 'Recibido' );
            $trig_lbl = array( 'escalation_cron' => 'Compliance Engine', 'daily_cron' => 'Compliance Engine (diario)', 'terminal_status' => 'Estado terminal', 'bootstrap' => 'Bootstrap retroactivo', 'unknown' => 'Sistema' );

            $by_date = array();
            foreach ( $history as $ev ) {
                $d = substr( (string) $ev->created_at, 0, 10 );
                $by_date[ $d ][] = $ev;
            }
        ?>
            <div style="margin:0;">
            <?php foreach ( $by_date as $date => $day_evts ) :
                // Bucket by category.
                $s_evts  = array(); // status_changed / status_overridden
                $q_evts  = array(); // quality_changed
                $f_evts  = array(); // feedback_status_changed
                $o_evts  = array(); // everything else

                foreach ( $day_evts as $ev ) {
                    $t = (string) $ev->event_type;
                    if ( in_array( $t, array( 'status_changed', 'status_overridden' ), true ) ) {
                        $s_evts[] = $ev;
                    } elseif ( $t === 'quality_changed' ) {
                        $q_evts[] = $ev;
                    } elseif ( $t === 'feedback_status_changed' ) {
                        $f_evts[] = $ev;
                    } else {
                        $o_evts[] = $ev;
                    }
                }

                // Reverse each bucket to get ASC order within the day.
                $s_asc = array_reverse( $s_evts );
                $q_asc = array_reverse( $q_evts );
                $f_asc = array_reverse( $f_evts );

                // Compute net changes.
                $net_status   = ( ! empty( $s_asc ) ) ? array( 'from' => $s_asc[0]->from_status, 'to' => end( $s_asc )->to_status, 'actor' => end( $s_asc )->operator_name, 'count' => count( $s_asc ) ) : null;
                $net_quality  = null;
                if ( ! empty( $q_asc ) ) {
                    $qm_first = el_portal_event_metadata( $q_asc[0] );
                    $qm_last  = el_portal_event_metadata( end( $q_asc ) );
                    $net_quality = array( 'from' => $qm_first['from_quality'] ?? '', 'to' => $qm_last['to_quality'] ?? '', 'reason' => end( $q_asc )->reason, 'actor' => end( $q_asc )->operator_name, 'count' => count( $q_asc ) );
                }
                $net_feedback = null;
                if ( ! empty( $f_asc ) ) {
                    $fm_first = el_portal_event_metadata( $f_asc[0] );
                    $fm_last  = el_portal_event_metadata( end( $f_asc ) );
                    $net_feedback = array( 'from' => $fm_first['from_status'] ?? '', 'to' => $fm_last['to_status'] ?? '', 'triggered_by' => $fm_last['triggered_by'] ?? '', 'level' => $fm_last['escalation_level'] ?? '', 'days' => $fm_last['days_elapsed'] ?? null, 'count' => count( $f_asc ) );
                }

                $has_grouped = $net_status || $net_quality || $net_feedback;
                $time_first  = Estandar_Leads_Normalizer::datetime_local( end( $day_evts )->created_at );
                $time_last   = Estandar_Leads_Normalizer::datetime_local( $day_evts[0]->created_at );
                $date_label  = Estandar_Leads_Normalizer::datetime_local( $date . ' 00:00:00' );
                // Use just the date portion (strip time).
                $date_label  = preg_replace( '/\s+\d+:\d+$/', '', $date_label ) ?: $date;
            ?>
                <div style="margin-bottom:20px;">
                    <!-- Date header -->
                    <div style="font-size:11px;font-weight:600;color:#646970;letter-spacing:.04em;text-transform:uppercase;margin-bottom:8px;border-bottom:1px solid #dcdcde;padding-bottom:4px;">
                        <?php echo esc_html( $date_label ); ?>
                    </div>

                    <?php if ( $has_grouped ) : ?>
                    <!-- Grouped net-change card -->
                    <div style="border-left:3px solid #2271b1;padding:8px 0 8px 14px;margin-bottom:8px;">

                        <?php
                        // Helper: render one dimension row.
                        // count=1 → flat. count>1 → <details> with each individual step.
                        ?>

                        <?php if ( $net_status ) : ?>
                        <div style="margin-bottom:8px;">
                            <span style="font-size:11px;font-weight:600;color:#646970;display:block;margin-bottom:3px;">Estado</span>
                            <?php if ( $net_status['count'] === 1 ) : ?>
                                <span style="font-size:13px;">
                                    <?php echo esc_html( $sls[ $s_asc[0]->from_status ] ?? ( $s_asc[0]->from_status ?: '—' ) ); ?>
                                    &nbsp;&rarr;&nbsp;
                                    <strong><?php echo esc_html( $sls[ $s_asc[0]->to_status ] ?? $s_asc[0]->to_status ); ?></strong>
                                </span>
                                <?php if ( $s_asc[0]->operator_name ) : ?><span style="font-size:11px;color:#646970;margin-left:6px;"><?php echo esc_html( $s_asc[0]->operator_name ); ?></span><?php endif; ?>
                            <?php else : ?>
                                <details>
                                    <summary style="font-size:12px;cursor:pointer;color:#2271b1;list-style:none;">
                                        <?php echo esc_html( $net_status['count'] ); ?> cambios &mdash;
                                        <?php echo esc_html( $sls[ $s_asc[0]->from_status ] ?? $s_asc[0]->from_status ); ?>
                                        &nbsp;&rarr;&nbsp;
                                        <strong><?php echo esc_html( $sls[ end( $s_asc )->to_status ] ?? end( $s_asc )->to_status ); ?></strong>
                                        <span style="font-size:11px;color:#646970;margin-left:4px;">▼ ver detalle</span>
                                    </summary>
                                    <ol style="margin:6px 0 0 14px;padding:0;font-size:12px;">
                                        <?php foreach ( $s_asc as $sev ) : ?>
                                        <li style="margin-bottom:4px;">
                                            <?php echo esc_html( $sls[ $sev->from_status ] ?? $sev->from_status ); ?>
                                            &rarr;
                                            <strong><?php echo esc_html( $sls[ $sev->to_status ] ?? $sev->to_status ); ?></strong>
                                            <?php if ( $sev->operator_name ) : ?><span style="color:#646970;margin-left:4px;"><?php echo esc_html( $sev->operator_name ); ?></span><?php endif; ?>
                                            <span style="color:#aaa;margin-left:4px;"><?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $sev->created_at ) ); ?></span>
                                        </li>
                                        <?php endforeach; ?>
                                    </ol>
                                </details>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ( $net_quality ) : ?>
                        <div style="margin-bottom:8px;">
                            <span style="font-size:11px;font-weight:600;color:#646970;display:block;margin-bottom:3px;">Calidad</span>
                            <?php if ( $net_quality['count'] === 1 ) :
                                $qm0 = el_portal_event_metadata( $q_asc[0] );
                            ?>
                                <span style="font-size:13px;">
                                    <?php echo esc_html( $qls[ $qm0['from_quality'] ?? '' ] ?? ( $qm0['from_quality'] ?? '—' ) ); ?>
                                    &nbsp;&rarr;&nbsp;
                                    <strong><?php echo esc_html( $qls[ $qm0['to_quality'] ?? '' ] ?? ( $qm0['to_quality'] ?? '' ) ); ?></strong>
                                </span>
                                <?php if ( $q_asc[0]->operator_name ) : ?><span style="font-size:11px;color:#646970;margin-left:6px;"><?php echo esc_html( $q_asc[0]->operator_name ); ?></span><?php endif; ?>
                                <?php if ( $q_asc[0]->reason ) : ?><div style="font-size:11px;color:#646970;font-style:italic;margin-top:2px;"><?php echo esc_html( $q_asc[0]->reason ); ?></div><?php endif; ?>
                            <?php else :
                                $qm_f = el_portal_event_metadata( $q_asc[0] );
                                $qm_l = el_portal_event_metadata( end( $q_asc ) );
                            ?>
                                <details>
                                    <summary style="font-size:12px;cursor:pointer;color:#2271b1;list-style:none;">
                                        <?php echo esc_html( $net_quality['count'] ); ?> cambios &mdash;
                                        <?php echo esc_html( $qls[ $qm_f['from_quality'] ?? '' ] ?? ( $qm_f['from_quality'] ?? '—' ) ); ?>
                                        &nbsp;&rarr;&nbsp;
                                        <strong><?php echo esc_html( $qls[ $qm_l['to_quality'] ?? '' ] ?? ( $qm_l['to_quality'] ?? '' ) ); ?></strong>
                                        <span style="font-size:11px;color:#646970;margin-left:4px;">▼ ver detalle</span>
                                    </summary>
                                    <ol style="margin:6px 0 0 14px;padding:0;font-size:12px;">
                                        <?php foreach ( $q_asc as $qev ) :
                                            $qevm = el_portal_event_metadata( $qev );
                                        ?>
                                        <li style="margin-bottom:4px;">
                                            <?php echo esc_html( $qls[ $qevm['from_quality'] ?? '' ] ?? ( $qevm['from_quality'] ?? '—' ) ); ?>
                                            &rarr;
                                            <strong><?php echo esc_html( $qls[ $qevm['to_quality'] ?? '' ] ?? ( $qevm['to_quality'] ?? '' ) ); ?></strong>
                                            <?php if ( $qev->operator_name ) : ?><span style="color:#646970;margin-left:4px;"><?php echo esc_html( $qev->operator_name ); ?></span><?php endif; ?>
                                            <?php if ( $qev->reason ) : ?><span style="color:#aaa;font-style:italic;margin-left:4px;"><?php echo esc_html( $qev->reason ); ?></span><?php endif; ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ol>
                                </details>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ( $net_feedback ) : ?>
                        <div style="margin-bottom:0;">
                            <span style="font-size:11px;font-weight:600;color:#646970;display:block;margin-bottom:3px;">Seguimiento</span>
                            <?php if ( $net_feedback['count'] === 1 ) :
                                $fm0 = el_portal_event_metadata( $f_asc[0] );
                            ?>
                                <span style="font-size:13px;">
                                    <?php echo esc_html( $fs_label[ $fm0['from_status'] ?? '' ] ?? ( $fm0['from_status'] ?? '—' ) ); ?>
                                    &nbsp;&rarr;&nbsp;
                                    <strong><?php echo esc_html( $fs_label[ $fm0['to_status'] ?? '' ] ?? ( $fm0['to_status'] ?? '' ) ); ?></strong>
                                </span>
                                <div style="font-size:11px;color:#646970;margin-top:1px;">
                                    Motor: <?php echo esc_html( $trig_lbl[ $fm0['triggered_by'] ?? '' ] ?? ( $fm0['triggered_by'] ?? 'Sistema' ) ); ?>
                                    <?php if ( ! empty( $fm0['escalation_level'] ) ) : ?>&middot; Nivel: <?php echo esc_html( $fm0['escalation_level'] ); ?><?php endif; ?>
                                    <?php if ( isset( $fm0['days_elapsed'] ) ) : ?>&middot; <?php echo esc_html( $fm0['days_elapsed'] ); ?>d<?php endif; ?>
                                </div>
                            <?php else :
                                $fm_f = el_portal_event_metadata( $f_asc[0] );
                                $fm_l = el_portal_event_metadata( end( $f_asc ) );
                            ?>
                                <details>
                                    <summary style="font-size:12px;cursor:pointer;color:#2271b1;list-style:none;">
                                        <?php echo esc_html( $net_feedback['count'] ); ?> transiciones &mdash;
                                        <?php echo esc_html( $fs_label[ $fm_f['from_status'] ?? '' ] ?? ( $fm_f['from_status'] ?? '—' ) ); ?>
                                        &nbsp;&rarr;&nbsp;
                                        <strong><?php echo esc_html( $fs_label[ $fm_l['to_status'] ?? '' ] ?? ( $fm_l['to_status'] ?? '' ) ); ?></strong>
                                        <span style="font-size:11px;color:#646970;margin-left:4px;">▼ ver detalle</span>
                                    </summary>
                                    <ol style="margin:6px 0 0 14px;padding:0;font-size:12px;">
                                        <?php foreach ( $f_asc as $fev ) :
                                            $fevm = el_portal_event_metadata( $fev );
                                        ?>
                                        <li style="margin-bottom:4px;">
                                            <?php echo esc_html( $fs_label[ $fevm['from_status'] ?? '' ] ?? ( $fevm['from_status'] ?? '—' ) ); ?>
                                            &rarr;
                                            <strong><?php echo esc_html( $fs_label[ $fevm['to_status'] ?? '' ] ?? ( $fevm['to_status'] ?? '' ) ); ?></strong>
                                            <span style="color:#646970;margin-left:4px;"><?php echo esc_html( $trig_lbl[ $fevm['triggered_by'] ?? '' ] ?? ( $fevm['triggered_by'] ?? '' ) ); ?></span>
                                            <?php if ( ! empty( $fevm['escalation_level'] ) ) : ?><span style="color:#aaa;margin-left:4px;"><?php echo esc_html( $fevm['escalation_level'] ); ?></span><?php endif; ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ol>
                                </details>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                    </div>
                    <?php endif; ?>

                    <?php foreach ( $o_evts as $ev ) :
                        $ev_meta = el_portal_event_metadata( $ev );
                        $ev_time = Estandar_Leads_Normalizer::datetime_local( $ev->created_at );

                        // Resolve display name for the actor who performed this event.
                        // Priority: actor_name (metadata) > operator_name (JOIN) > type-based fallback.
                        $ev_actor_display = '';
                        $ev_actor_type    = (string) ( $ev_meta['actor_type'] ?? 'unknown' );
                        if ( ! empty( $ev_meta['actor_name'] ) ) {
                            $ev_actor_display = (string) $ev_meta['actor_name'];
                        } elseif ( ! empty( $ev->operator_name ) ) {
                            $ev_actor_display = (string) $ev->operator_name;
                        } elseif ( $ev_actor_type === 'anonymous_token' ) {
                            $ev_actor_display = 'token anónimo';
                        } elseif ( $ev_actor_type === 'admin' || $ev_actor_type === 'supervisor' ) {
                            $ev_actor_display = ! empty( $ev_meta['actor_email'] ) ? (string) $ev_meta['actor_email'] : 'Administrador';
                        }
                    ?>
                    <div style="border-left:3px solid #dcdcde;padding:6px 0 6px 14px;margin-bottom:6px;">
                        <div style="font-size:11px;color:#646970;">
                            <?php echo esc_html( $ev_time ); ?> &middot; <?php echo esc_html( el_portal_event_label( $ev->event_type ) ); ?>
                            <?php if ( in_array( (string) $ev->event_type, array( 'workspace_opened', 'lead_viewed', 'lead_list_opened', 'whatsapp_clicked', 'email_clicked', 'call_clicked' ), true ) && $ev_actor_display !== '' ) : ?>
                                <span style="color:#2271b1;"> · por <?php echo esc_html( $ev_actor_display ); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ( (string) $ev->event_type === 'operator_reassigned' ) :
                            $ev_from   = trim( (string) ( $ev_meta['from_operator_name'] ?? '' ) );
                            $ev_to     = trim( (string) ( $ev_meta['to_operator_name'] ?? '' ) );
                            $ev_source = (string) ( $ev_meta['source'] ?? '' );
                            $ev_src_lbl = $ev_source === 'orphan_recovery' ? 'recuperación de huérfanos' : ( $ev_source === 'manual' ? 'manual' : $ev_source );
                        ?>
                            <div style="font-size:12px;color:#646970;">De <strong><?php echo esc_html( $ev_from !== '' ? $ev_from : 'sin operador' ); ?></strong> &rarr; <strong><?php echo esc_html( $ev_to !== '' ? $ev_to : 'sin operador' ); ?></strong><?php if ( $ev_src_lbl !== '' ) : ?> &middot; fuente: <?php echo esc_html( $ev_src_lbl ); ?><?php endif; ?></div>
                            <?php if ( $ev_actor_display !== '' ) : ?><div style="font-size:11px;color:#646970;">por <?php echo esc_html( $ev_actor_display ); ?></div><?php endif; ?>
                        <?php elseif ( (string) ( $ev_meta['routing_mode'] ?? '' ) === 'fallback' || (string) $ev->event_type === 'routing_fallback_used' ) : ?>
                            <div style="font-size:12px;color:#8a6100;">Proyecto sin operador asignado.</div>
                        <?php elseif ( (string) $ev->event_type === 'compliance_escalation' ) : ?>
                            <?php
                            $comp_level = (string) ( $ev_meta['escalation_level'] ?? '' );
                            $comp_type  = (string) ( $ev_meta['notification_type'] ?? '' );
                            $comp_labels = array(
                                'escalation_48h'  => 'Recordatorio a las 48 h sin gestión',
                                'escalation_96h'  => 'Recordatorio a las 96 h sin gestión',
                                'escalation_7d'   => 'Alerta a los 7 días sin gestión',
                                'escalation_14d'  => 'Alerta a los 14 días sin gestión',
                                'daily_digest'    => 'Resumen diario de leads pendientes',
                                'weekly_digest'   => 'Resumen semanal de leads pendientes',
                            );
                            $comp_human = $comp_labels[ $comp_type ] ?? ( $comp_level !== '' ? 'Recordatorio de seguimiento (nivel ' . $comp_level . ')' : 'Recordatorio de seguimiento enviado' );
                            ?>
                            <div style="font-size:12px;color:#646970;"><?php echo esc_html( $comp_human ); ?></div>
                        <?php elseif ( ! empty( $ev->reason ) ) : ?>
                            <div style="font-size:12px;color:#646970;font-style:italic;"><?php echo esc_html( $ev->reason ); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
	    </div>

    <details style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-top:16px;">
        <summary style="cursor:pointer;font-size:18px;font-weight:600;">Técnico</summary>

        <h3 style="margin:16px 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#646970;">Estado del sistema</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin-bottom:16px;">
            <div><strong>Estado (raw)</strong><br><code><?php echo esc_html( el_portal_display_value( $lead->status ) ); ?></code></div>
            <div><strong>Calidad (raw)</strong><br><code><?php echo esc_html( el_portal_display_value( $lead->quality ) ); ?></code></div>
            <div><strong>Estado previo</strong><br><code><?php echo esc_html( el_portal_display_value( $previous_status ) ); ?></code></div>
            <div><strong>Calidad previa</strong><br><code><?php echo esc_html( $previous_classification && ! empty( $previous_classification->previous_quality ) ? $previous_classification->previous_quality : '—' ); ?></code></div>
            <div><strong>Contexto faltante</strong><br><?php echo esc_html( $detail_context_gap_label ); ?></div>
        </div>

        <h3 style="margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#646970;">Captura</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin-bottom:16px;">
            <div><strong>Canal</strong><br><?php echo esc_html( el_portal_capture_channel( $latest && ! empty( $latest->source_plugin ) ? $latest->source_plugin : ( $lead->first_source_plugin ?? '' ) ) ); ?></div>
            <div><strong>Plugin origen</strong><br><?php echo esc_html( $lead->first_source_plugin ?: '—' ); ?></div>
            <div><strong>Plugin actual</strong><br><?php echo esc_html( $lead->latest_source_plugin ?: '—' ); ?></div>
            <div><strong>Submission source</strong><br><?php echo esc_html( $latest && ! empty( $latest->source_plugin ) ? $latest->source_plugin : '—' ); ?></div>
            <div><strong>Formulario</strong><br><?php echo esc_html( $latest && ! empty( $latest->source_form_id ) ? $latest->source_form_id : '—' ); ?></div>
            <div><strong>Entry</strong><br>
                <?php if ( $latest && ! empty( $latest->source_entry_id ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-forms-entries&entry_id=' . absint( $latest->source_entry_id ) ) ); ?>">#<?php echo esc_html( $latest->source_entry_id ); ?></a>
                <?php else : ?>—<?php endif; ?>
            </div>
            <div><strong>Lead ID</strong><br>#<?php echo esc_html( $lead->id ); ?></div>
            <div><strong>Submission ID</strong><br><?php echo esc_html( $latest && ! empty( $latest->id ) ? '#' . $latest->id : '—' ); ?></div>
        </div>

        <h3 style="margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#646970;">Identificadores</h3>
        <table class="widefat striped" style="margin-top:0 0 12px;">
            <tbody>
                <tr>
                    <th>Lead ID</th><td><?php echo esc_html( $lead->id ?: '—' ); ?></td>
                    <th>Submission ID</th><td><?php echo esc_html( $latest && ! empty( $latest->id ) ? $latest->id : '—' ); ?></td>
                </tr>
                <tr>
                    <th>First source plugin</th><td><?php echo esc_html( $lead->first_source_plugin ?: '—' ); ?></td>
                    <th>Latest source plugin</th><td><?php echo esc_html( $lead->latest_source_plugin ?: '—' ); ?></td>
                </tr>
                <tr>
                    <th>Submission source</th><td><?php echo esc_html( $latest && ! empty( $latest->source_plugin ) ? $latest->source_plugin : '—' ); ?></td>
                    <th>Source form ID</th><td><?php echo esc_html( $latest && ! empty( $latest->source_form_id ) ? $latest->source_form_id : '—' ); ?></td>
                </tr>
                <tr>
                    <th>Entry ID</th>
                    <td>
                        <?php if ( $latest && ! empty( $latest->source_entry_id ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-forms-entries&entry_id=' . absint( $latest->source_entry_id ) ) ); ?>">#<?php echo esc_html( $latest->source_entry_id ); ?></a>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                    <th>Fecha submission</th><td><?php echo esc_html( $latest && ! empty( $latest->submitted_at ) ? Estandar_Leads_Normalizer::datetime_local( $latest->submitted_at ) : '—' ); ?></td>
                </tr>
                <tr>
                    <th>Schema version</th><td><?php echo esc_html( $latest && ! empty( $latest->schema_version ) ? $latest->schema_version : '—' ); ?></td>
                    <th>Session ID</th><td><?php if ( $journey_enabled && $session_url !== '' ) : ?><a href="<?php echo esc_url( $session_url ); ?>"><code><?php echo esc_html( $lead_session_id ); ?></code></a> <a class="button button-small" href="<?php echo esc_url( $session_url ); ?>">Ver sesión</a><?php else : ?><?php echo esc_html( $lead_session_id ?: '—' ); ?><?php endif; ?></td>
                </tr>
                <?php if ( el_portal_context_value( $latest, 'client_slug' ) || el_portal_context_value( $latest, 'client_name' ) ) : ?>
                    <tr>
                        <th>Client slug</th><td><?php echo esc_html( el_portal_context_value( $latest, 'client_slug' ) ?: '—' ); ?></td>
                        <th>Client name</th><td><?php echo esc_html( el_portal_context_value( $latest, 'client_name' ) ?: '—' ); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ( el_portal_context_value( $latest, 'project_name' ) || el_portal_context_value( $latest, 'project_slug_source' ) ) : ?>
                    <tr>
                        <th>Project name</th><td><?php echo esc_html( el_portal_context_value( $latest, 'project_name' ) ?: '—' ); ?></td>
                        <th>Project slug source</th><td><?php echo esc_html( el_portal_context_value( $latest, 'project_slug_source' ) ?: '—' ); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th>Lead UUID</th><td colspan="3"><?php echo esc_html( $lead && ! empty( $lead->lead_uuid ) ? $lead->lead_uuid : '—' ); ?></td>
                </tr>
            </tbody>
        </table>
        <?php if ( $latest ) : ?>
            <h3 style="margin:14px 0 8px;">Campos raw</h3>
            <pre style="white-space:pre-wrap;max-height:420px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:12px;"><?php echo esc_html( el_portal_pretty_json( $latest->form_fields_json ?? '' ) ); ?></pre>
        <?php endif; ?>
    </details>

    <script>
    (function ($) {
        var ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( 'estandar_assign_operator' ) ); ?>;

        $(document).on('change', '.es-operator-select', function () {
            var $select = $(this);
            var leadId = $select.data('lead-id');
            var operatorId = $select.val();
            var $status = $('#es-assign-' + leadId);
            var $linkBtn = $('#es-link-btn-' + leadId);

            $select.prop('disabled', true);
            $status.text('Guardando...');

            $.post(ajaxurl, {
                action: 'estandar_assign_operator',
                nonce: ajaxNonce,
                lead_id: leadId,
                operator_id: operatorId
            }, function (response) {
                $select.prop('disabled', false);
                if (response.success) {
                    if (response.data.operator_name) {
                        $status.text('Asignado');
                        $linkBtn.prop('disabled', false).removeAttr('title');
                    } else {
                        $status.text('');
                        $linkBtn.prop('disabled', true).attr('title', 'Asigna un operador primero');
                    }
                    return;
                }
                var msg = response.data && response.data.message ? response.data.message : 'Error desconocido.';
                $status.text(msg);
            }).fail(function () {
                $select.prop('disabled', false);
                $status.text('Error de conexión.');
            });
        });
    })(jQuery);
    </script>

	<?php
	    return;
endif;
?>

    <!-- ── STATS BAR ──────────────────────────────────────────────────────── -->
    <?php if ( $el_error_key && isset( $el_errors[ $el_error_key ] ) ) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $el_errors[ $el_error_key ] ); ?></p></div>
    <?php endif; ?>
    <?php
    $el_bulk    = isset( $_GET['el_bulk'] )    ? sanitize_key( $_GET['el_bulk'] )  : '';
    $el_deleted = isset( $_GET['el_deleted'] ) ? absint( $_GET['el_deleted'] )      : 0;
    ?>
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
        <div class="notice notice-success is-dismissible"><p>
            <?php if ( $created_link_operator !== '' ) : ?><strong>Email enviado a <?php echo esc_html( $created_link_operator ); ?>.</strong><br><?php endif; ?>
            Enlace de clasificación:<br><input class="large-text" readonly style="margin-top:4px;" value="<?php echo esc_attr( $created_link ); ?>">
        </p></div>
    <?php endif; ?>

	    <?php
	    // ── SECCIÓN 2 (RIESGOS) — BACKLOG_TOTAL_FIX: agregación real en BD sobre
	    // TODOS los leads sin clasificar (quality vacío), no solo la página visible.
	    global $wpdb;
	    $risk_leads_t = $wpdb->prefix . 'es_leads';
	    $risk_op_row  = $wpdb->get_row(
	        "SELECT COALESCE(NULLIF(assigned_operator_name,''),'Sin asignar') AS k, COUNT(*) AS n
	         FROM {$risk_leads_t} WHERE (quality IS NULL OR quality = '')
	         GROUP BY k ORDER BY n DESC LIMIT 1",
	        ARRAY_A
	    );
	    $risk_pr_row  = $wpdb->get_row(
	        "SELECT COALESCE(NULLIF(project_slug,''),'—') AS k, COUNT(*) AS n
	         FROM {$risk_leads_t} WHERE (quality IS NULL OR quality = '')
	         GROUP BY k ORDER BY n DESC LIMIT 1",
	        ARRAY_A
	    );
	    $risk_top_op   = $risk_op_row ? (string) $risk_op_row['k'] : '—';
	    $risk_top_op_n = $risk_op_row ? (int) $risk_op_row['n'] : 0;
	    $risk_top_pr   = $risk_pr_row ? ( resolve_project_display_name( (string) $risk_pr_row['k'], '' ) ?: (string) $risk_pr_row['k'] ) : '—';
	    $risk_top_pr_n = $risk_pr_row ? (int) $risk_pr_row['n'] : 0;
	    ?>


	    <!-- ── FILTERS BAR ──────────────────────────────────────────────────── -->
    <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"
          style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:14px 18px;
                 margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
	        <input type="hidden" name="page" value="estandar-leads">
	        <input type="hidden" name="view" value="<?php echo esc_attr( $filters['view'] ); ?>">
	        <?php if ( ! empty( $filters['issue'] ) ) : ?>
	            <input type="hidden" name="issue" value="<?php echo esc_attr( $filters['issue'] ); ?>">
	        <?php endif; ?>

        <div>
            <label style="display:block;font-size:11px;color:#666;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px;">Buscar</label>
            <input type="text" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>"
                   placeholder="Nombre, email o teléfono" style="width:200px;">
        </div>

        <div>
            <label style="display:block;font-size:11px;color:#666;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px;">Proyecto</label>
            <select name="project">
                <option value="">Todos</option>
                <?php foreach ( $projects as $p ) :
                    $slug = $p['project_slug'] ?? '';
                ?>
                    <option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $slug, $filters['project'] ); ?>>
                        <?php echo esc_html( $p['name'] ?? $slug ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display:block;font-size:11px;color:#666;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px;">Calidad</label>
            <select name="quality">
                <option value="">Todas</option>
                <?php foreach ( $quality_labels as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"<?php selected( $key, $filters['quality'] ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display:block;font-size:11px;color:#666;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px;">Estado</label>
            <select name="status">
                <option value="">Todos</option>
                <?php foreach ( $status_labels as $key => $label ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>"<?php selected( $key, $filters['status'] ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display:block;font-size:11px;color:#666;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px;">Operador</label>
            <select name="operator_id">
                <option value="0">Todos</option>
                <?php foreach ( $operators as $op ) : ?>
                    <option value="<?php echo esc_attr( $op->id ); ?>"<?php selected( $op->id, $filters['operator_id'] ); ?>>
                        <?php echo esc_html( $op->name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display:block;font-size:11px;color:#666;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px;">Desde</label>
            <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
        </div>

        <div>
            <label style="display:block;font-size:11px;color:#666;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px;">Hasta</label>
            <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
        </div>

        <div style="display:flex;gap:6px;padding-bottom:1px;">
            <button type="submit" class="button button-primary">Filtrar</button>
            <a href="<?php echo esc_url( $portal_base ); ?>" class="button">Limpiar</a>
        </div>
    </form>

    <!-- ── VIEW TABS ───────────────────────────────────────────────────── -->
    <div style="display:flex;gap:6px;margin-bottom:12px;border-bottom:1px solid #e0e0e0;padding-bottom:0;">
        <?php foreach ( $views as $view_key => $view_label ) :
            $active = $filters['view'] === $view_key;
        ?>
            <a href="<?php echo el_portal_url( array( 'view' => $view_key ), $portal_base ); ?>"
               style="display:inline-block;padding:8px 16px;font-size:13px;font-weight:<?php echo $active ? '600' : '400'; ?>;
                      color:<?php echo $active ? '#1a73e8' : '#444'; ?>;text-decoration:none;
                      border-bottom:<?php echo $active ? '2px solid #1a73e8' : '2px solid transparent'; ?>;
                      margin-bottom:-1px;">
                <?php echo esc_html( $view_label ); ?>
            </a>
        <?php endforeach; ?>
        <span style="margin-left:auto;font-size:12px;color:#888;line-height:2.4;">
            <?php echo esc_html( number_format_i18n( $result['total'] ) ); ?> lead<?php echo $result['total'] !== 1 ? 's' : ''; ?>
        </span>
    </div>

    <?php $ah = is_array( $attribution_health_summary ?? null ) ? $attribution_health_summary : array(); ?>
    <h2 style="margin:0 0 10px;font-size:16px;">Attribution Health</h2>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
        <?php
        $attribution_health_items = array(
            array( 'label' => 'Leads totales', 'val' => $ah['total_leads'] ?? 0 ),
            array( 'label' => 'Google Ads detectados', 'val' => $ah['google_ads_leads'] ?? 0 ),
            array( 'label' => 'Google Ads con click identifier', 'val' => $ah['google_ads_with_click_identifier'] ?? 0 ),
            array( 'label' => 'Google Ads sin click identifier', 'val' => $ah['google_ads_without_click_identifier'] ?? 0 ),
            array( 'label' => 'OCI elegibles', 'val' => $ah['oci_eligible'] ?? 0 ),
            array( 'label' => 'OCI enviados', 'val' => $ah['oci_sent'] ?? 0 ),
            array( 'label' => 'OCI aceptados', 'val' => $ah['oci_accepted'] ?? 0 ),
            array( 'label' => 'OCI rechazados', 'val' => $ah['oci_rejected'] ?? 0 ),
            array( 'label' => '% cobertura click identifier', 'val' => number_format_i18n( (float) ( $ah['click_identifier_coverage_rate'] ?? 0 ), 2 ) . '%' ),
            array( 'label' => '% aceptación OCI', 'val' => number_format_i18n( (float) ( $ah['accepted_rate'] ?? 0 ), 2 ) . '%' ),
        );
        foreach ( $attribution_health_items as $item ) :
        ?>
            <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;min-width:140px;">
                <div style="font-size:20px;font-weight:700;line-height:1.2;"><?php echo esc_html( (string) $item['val'] ); ?></div>
                <div style="font-size:12px;color:#646970;"><?php echo esc_html( $item['label'] ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── LEADS TABLE ─────────────────────────────────────────────────── -->
    <?php if ( empty( $result['rows'] ) ) : ?>
        <div style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:40px;text-align:center;color:#888;">
            No hay leads que coincidan con los filtros seleccionados.
        </div>
    <?php else : ?>

    <?php
    // Compliance badge config — maps feedback_status to display properties.
    $compliance_cfg = array(
        'pending'   => array( 'label' => 'Pendiente', 'bg' => '#e8f0fe', 'color' => '#1a73e8' ),
        'overdue'   => array( 'label' => 'Vencido',   'bg' => '#fff4ce', 'color' => '#8a6100' ),
        'escalated' => array( 'label' => 'Escalado',  'bg' => '#fce8e6', 'color' => '#c5221f' ),
        'received'  => array( 'label' => 'Recibido',  'bg' => '#e6f4ea', 'color' => '#1e8e3e' ),
    );
    ?>

    <!-- Bulk delete bar — OUTSIDE the leads table, no nesting. -->
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="es-portal-bulk-form" style="margin-bottom:8px;">
        <?php wp_nonce_field( 'estandar_leads_bulk_delete' ); ?>
        <input type="hidden" name="action" value="estandar_leads_bulk_delete">
        <div style="display:flex;align-items:center;gap:10px;">
            <select name="bulk_action" id="es-portal-bulk-action" style="min-width:180px;">
                <option value="">— Acción en lote —</option>
                <option value="export_basic">Exportar CSV básico</option>
                <option value="export_ai_context">Exportar CSV contexto IA</option>
                <option value="delete">Eliminar seleccionados</option>
            </select>
            <button type="submit" class="button" id="es-portal-bulk-apply"
                    onclick="return esPortalBulkConfirm(this);">Aplicar</button>
            <span id="es-portal-bulk-count" style="color:#666;font-size:13px;"></span>
        </div>
    </form>

    <table class="widefat striped" style="border-radius:6px;overflow:hidden;">
        <thead>
            <tr>
                <th style="width:32px;"><input type="checkbox" id="es-portal-check-all" title="Seleccionar todos"></th>
                <th>Lead</th>
                <th>Contacto</th>
                <th>Proyecto</th>
                <th>Calidad</th>
                <th>Estado</th>
                <th>Compliance</th>
                <th>Operador</th>
                <th>Última gestión</th>
                <th>Antigüedad</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $result['rows'] as $row ) :
            $quality_color   = $quality_colors[ $row->quality ] ?? '#bbb';
            $has_quality     = $row->quality !== '';
            $is_reengaged    = (int) $row->submission_count > 1;
            $fs              = $row->feedback_status ?? 'pending';
            $fs_cfg          = $compliance_cfg[ $fs ] ?? $compliance_cfg['pending'];
            $days_old        = $row->created_at
                ? (int) floor( ( time() - strtotime( $row->created_at ) ) / DAY_IN_SECONDS )
                : '?';
            $age_color       = $days_old > 30 ? '#c5221f' : ( $days_old > 7 ? '#8a6100' : '#1a73e8' );
	            $row_signals     = $autoresponse_by_lead[ absint( $row->id ) ] ?? array();
	            $last_action     = $last_commercial_actions[ absint( $row->id ) ] ?? array( 'label' => 'Nunca contactado', 'date' => '' );
	            $row_context_gap = $context_gap_by_lead[ absint( $row->id ) ] ?? array();
	        ?>
            <tr>
                <td>
                    <input type="checkbox" name="lead_ids[]"
                           value="<?php echo esc_attr( $row->id ); ?>"
                           class="es-portal-lead-check"
                           form="es-portal-bulk-form">
                </td>
                <td>
                    <strong><?php echo esc_html( $row->name ?: '(sin nombre)' ); ?></strong>
                    <?php if ( $is_reengaged ) : ?>
                        <span style="font-size:11px;background:#e8f0fe;color:#1a73e8;border-radius:99px;
                                     padding:1px 7px;margin-left:4px;">
                            🔁 Recontactó ×<?php echo esc_html( $row->submission_count ); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ( $is_reengaged && ! $has_quality ) : ?>
                        <span style="font-size:11px;background:#fef2f2;color:#b42318;border-radius:99px;
                                     padding:1px 7px;margin-left:4px;font-weight:700;">
                            🚨 Recontacto sin gestión
                        </span>
                    <?php endif; ?>
	                    <?php if ( ! empty( $row->last_classified_at ) ) :
                        $cls_date    = Estandar_Leads_Normalizer::datetime_local( $row->last_classified_at );
                        $cls_by      = $row->last_classifier_name ?: 'Operador desconocido';
                        $_q_labels   = Estandar_Leads_Email::quality_labels();
                        $_s_labels   = Estandar_Leads_Normalizer::status_labels();
                        $cls_quality = isset( $_q_labels[ $row->quality ] )
                            ? $row->quality . ' — ' . $_q_labels[ $row->quality ]
                            : ( $row->quality ?: '—' );
                        $cls_status  = $_s_labels[ $row->status ] ?? $row->status;
                        $cls_tooltip = 'Revisado por ' . $cls_by . ' · ' . $cls_date
                                     . ' | Calidad: ' . $cls_quality
                                     . ' | Estado: ' . $cls_status;
                    ?>
		                        <span title="<?php echo esc_attr( $cls_tooltip ); ?>"
		                              style="font-size:11px;background:#e6f4ea;color:#1e8e3e;border-radius:99px;
		                                     padding:1px 7px;margin-left:2px;cursor:default;">✓ Clasificado</span>
	                    <?php endif; ?>
	                    <?php if ( ! empty( $row_context_gap ) && (string) ( $row_context_gap['value'] ?? 'NO' ) !== 'NO' ) : ?>
	                        <span title="Contexto faltante: <?php echo esc_attr( (string) ( $row_context_gap['label'] ?? '' ) ); ?>"
	                              style="font-size:11px;background:#fff4ce;color:#8a6100;border-radius:99px;padding:1px 7px;margin-left:2px;font-weight:700;">
	                            <?php echo esc_html( strtoupper( (string) ( $row_context_gap['label'] ?? '' ) ) ); ?>
	                        </span>
	                    <?php endif; ?>
	                </td>
                <td style="font-size:12px;color:#666;">
                    <?php echo esc_html( $row->email ?: 'Sin email' ); ?>
                    <?php if ( $row->phone ) : ?><br><?php echo esc_html( Estandar_Leads_Normalizer::phone_display( $row->phone ) ); ?><?php endif; ?>
                </td>
                <td style="font-size:12px;">
                    <?php echo esc_html( resolve_project_display_name( $row->project_slug ?? '', $row->project_area ?? '' ) ?: '—' ); ?>
                    <?php if ( (int) ( $row->routing_fallback_count ?? 0 ) > 0 ) : ?>
                        <br><span title="Proyecto sin operador asignado" style="display:inline-block;margin-top:3px;padding:1px 6px;border-radius:99px;background:#fff4ce;color:#8a6100;font-size:11px;font-weight:700;">Fallback</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( $has_quality ) :
                        echo Estandar_Leads_Portal::quality_badge( $row->quality );
                    else : ?>
                        <span style="color:#bbb;font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
	                <td>
	                    <?php
	                    $slabels = Estandar_Leads_Normalizer::status_labels();
	                    echo esc_html( $slabels[ $row->status ] ?? $row->status );
	                    ?>
	                    <?php if ( ! empty( $row->sales_stage ) ) :
	                        $stage_labels = Estandar_Leads_Normalizer::commercial_stage_labels();
	                    ?>
	                        <br><span title="Estado comercial" style="display:inline-block;margin-top:3px;padding:1px 7px;border-radius:99px;background:#f0f6fc;color:#0969da;font-size:11px;font-weight:700;">
	                            <?php echo esc_html( $stage_labels[ $row->sales_stage ] ?? $row->sales_stage ); ?>
	                        </span>
	                    <?php endif; ?>
	                </td>
                <td>
                    <span style="display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;
                                 background:<?php echo esc_attr( $fs_cfg['bg'] ); ?>;
                                 color:<?php echo esc_attr( $fs_cfg['color'] ); ?>;">
                        <?php echo esc_html( $fs_cfg['label'] ); ?>
                    </span>
                </td>
	                <td style="font-size:12px;">
	                    <?php $assigned_id = (int) ( $row->assigned_operator_id ?? 0 ); ?>
	                    <?php $assigned_in_options = false; foreach ( $operators as $op_check ) { if ( (int) $op_check->id === $assigned_id ) { $assigned_in_options = true; break; } } ?>
	                    <select class="es-operator-select" data-lead-id="<?php echo esc_attr( $row->id ); ?>" style="max-width:160px;">
	                        <option value="0"<?php selected( 0, $assigned_id ); ?>>— Sin operador —</option>
	                        <?php if ( $assigned_id > 0 && ! $assigned_in_options ) : ?>
	                            <option value="<?php echo esc_attr( $assigned_id ); ?>" selected>Operador histórico eliminado<?php echo ! empty( $row->assigned_operator_name ) ? ': ' . esc_html( $row->assigned_operator_name ) : ''; ?></option>
	                        <?php endif; ?>
	                        <?php foreach ( $operators as $op ) : ?>
	                            <option value="<?php echo esc_attr( $op->id ); ?>"<?php selected( (int) $op->id, $assigned_id ); ?>><?php echo esc_html( $op->name ); ?></option>
	                        <?php endforeach; ?>
                    </select>
                    <span class="es-assign-status" id="es-assign-<?php echo esc_attr( $row->id ); ?>" style="display:block;font-size:11px;color:#666;margin-top:2px;min-height:14px;">
                        <?php if ( $assigned_id > 0 && ! empty( $row->assigned_at ) ) : echo esc_html( Estandar_Leads_Normalizer::datetime_local( $row->assigned_at ) ); endif; ?>
                        <?php if ( (int) ( $row->routing_fallback_count ?? 0 ) > 0 ) : ?><br><span style="color:#8a6100;">Routing fallback</span><?php endif; ?>
                    </span>
                </td>
                <td style="white-space:nowrap;font-size:12px;">
                    <strong><?php echo esc_html( $last_action['label'] ?? 'Nunca contactado' ); ?></strong>
                    <?php if ( ! empty( $last_action['date'] ) ) : ?>
                        <br><span style="font-size:11px;color:#646970;"><?php echo esc_html( Estandar_Leads_Normalizer::time_ago( (string) $last_action['date'] ) ); ?></span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;white-space:nowrap;">
                    <span style="font-size:12px;font-weight:700;color:<?php echo esc_attr( $age_color ); ?>;">
                        <?php echo esc_html( $days_old ); ?>d
                    </span>
                </td>
                <td style="white-space:nowrap;">
                    <a class="button button-small"
                       href="<?php echo el_portal_url( array( 'view' => 'detail', 'lead_id' => $row->id ), $portal_base ); ?>">
                        Ver
                    </a>
                    <!-- No nested form — singleton #es-portal-send-form handles this via JS -->
                    <button type="button"
                            class="button button-small es-create-link-btn"
                            id="es-link-btn-<?php echo esc_attr( $row->id ); ?>"
                            data-lead-id="<?php echo esc_attr( $row->id ); ?>"
                            data-redirect="<?php echo esc_attr( add_query_arg( $_GET, $portal_base ) ); ?>"
                            <?php echo (int) ( $row->assigned_operator_id ?? 0 ) <= 0 ? 'disabled title="Asigna un operador primero"' : ''; ?>>Enviar</button>
                    <button type="button" class="button button-small"
                            id="es-copy-ai-context-<?php echo esc_attr( $row->id ); ?>"
                            data-lead-id="<?php echo esc_attr( $row->id ); ?>"
                            title="Copiar contexto IA al portapapeles">IA</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Singleton "Enviar a operador" form — outside the table, no nesting. -->
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
          id="es-portal-send-form" style="display:none;">
        <?php wp_nonce_field( 'estandar_leads_create_link' ); ?>
        <input type="hidden" name="action"      value="estandar_leads_create_link">
        <input type="hidden" name="lead_id"     value="" id="es-portal-send-lead-id">
        <input type="hidden" name="redirect_to" value="" id="es-portal-send-redirect">
    </form>

    <!-- ── PAGINATION ──────────────────────────────────────────────────── -->
    <?php if ( $result['page_count'] > 1 ) : ?>
        <div style="margin-top:16px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
            <?php for ( $p = 1; $p <= $result['page_count']; $p++ ) :
                $active_p = $p === $result['page'];
            ?>
                <a href="<?php echo el_portal_url( array_merge(
                    array( 'view' => $filters['view'], 'paged' => $p ),
                    array_filter( array(
                        'project'     => $filters['project'],
                        'quality'     => $filters['quality'],
	                        'status'      => $filters['status'],
	                        'operator_id' => $filters['operator_id'] ?: '',
	                        'source'      => $filters['source'],
	                        'issue'       => $filters['issue'],
	                        'date_from'   => $filters['date_from'],
	                        'date_to'     => $filters['date_to'],
	                        'search'      => $filters['search'],
	                    ) )
	                ), $portal_base ); ?>"
                   style="display:inline-block;padding:6px 12px;border:1px solid <?php echo $active_p ? '#1a73e8' : '#e0e0e0'; ?>;
                          background:<?php echo $active_p ? '#1a73e8' : '#fff'; ?>;
                          color:<?php echo $active_p ? '#fff' : '#444'; ?>;border-radius:4px;
                          font-size:13px;text-decoration:none;">
                    <?php echo esc_html( $p ); ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

    <?php endif; // end if rows ?>
	    <h2 style="margin:0 0 10px;font-size:16px;">Salud operacional</h2>
	    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
	        <?php
			        $stat_items = array(
			            array( 'label' => 'Nuevos', 'val' => $stats['new'] ?? 0, 'params' => array( 'view' => 'all', 'status' => 'NEW' ), 'color' => '#1a73e8' ),
			            array( 'label' => 'Recurrentes', 'val' => $stats['recurrent'] ?? 0, 'params' => array( 'view' => 'recontact_no_action' ), 'color' => '#7c3aed' ),
			            array( 'label' => 'Recontactados sin acción', 'val' => $stats['recontact_no_action'] ?? 0, 'params' => array( 'view' => 'recontact_no_action' ), 'color' => '#b42318' ),
			            array( 'label' => 'Vencidos >7 días', 'val' => $stats['stale_7d'] ?? 0, 'params' => array( 'view' => 'all', 'issue' => 'stale_7d' ), 'color' => '#9b1c1c' ),
			            array( 'label' => 'Escalados', 'val' => $stats['escalated'] ?? 0, 'params' => array( 'view' => 'escalated' ), 'color' => '#c5221f' ),
			            array( 'label' => 'Calificados', 'val' => $stats['qualified'] ?? 0, 'params' => array( 'view' => 'all', 'quality' => 'qualified' ), 'color' => '#15803d' ),
			            array( 'label' => 'Nuevos <24h', 'val' => $stats['new_24h'] ?? 0, 'params' => array( 'view' => 'all', 'issue' => 'new_24h' ), 'color' => '#1a73e8' ),
		        );
	        foreach ( $stat_items as $si ) :
	            $params = $si['params'];
	            $active = $filters['view'] === ( $params['view'] ?? 'all' ) && ( $filters['issue'] ?? '' ) === ( $params['issue'] ?? '' );
	        ?>
	            <a href="<?php echo el_portal_url( $params, $portal_base ); ?>"
	               style="text-decoration:none;flex:1;min-width:120px;max-width:200px;
	                      background:<?php echo $active ? $si['color'] : '#fff'; ?>;
                      border:1px solid <?php echo $active ? $si['color'] : '#e0e0e0'; ?>;
                      border-radius:6px;padding:14px 16px;text-align:center;
                      transition:box-shadow .15s;">
                <span style="display:block;font-size:28px;font-weight:700;
                             color:<?php echo $active ? '#fff' : $si['color']; ?>;line-height:1;">
                    <?php echo esc_html( $si['val'] ); ?>
                </span>
                <span style="display:block;font-size:12px;color:<?php echo $active ? 'rgba(255,255,255,.85)' : '#666'; ?>;
                             margin-top:4px;">
	                    <?php echo esc_html( $si['label'] ); ?>
	                </span>
	            </a>
	        <?php endforeach; ?>
	        <a href="<?php echo el_portal_url( array( 'view' => 'work_now' ), $portal_base ); ?>"
	           class="button"
	           style="min-width:120px;max-width:140px;height:auto;display:flex;align-items:center;justify-content:center;text-align:center;font-weight:400;font-size:12px;opacity:.85;">
	            Trabajar ahora
	        </a>
	    </div>

	    <!-- ── SECCIÓN 2 · RIESGOS (datos existentes, sin métricas nuevas) ─────── -->
	    <h2 style="margin:0 0 10px;font-size:16px;">Riesgos</h2>
	    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
	        <div style="flex:1;min-width:160px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:12px 16px;">
	            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;">Operador con mayor backlog</div>
	            <div style="font-size:15px;font-weight:700;color:#1d2327;margin-top:4px;"><?php echo esc_html( $risk_top_op ); ?></div>
	            <div style="font-size:12px;color:#b42318;"><?php echo esc_html( $risk_top_op_n ); ?> sin clasificar <span style="color:#999;">(total histórico)</span></div>
	        </div>
	        <div style="flex:1;min-width:160px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:12px 16px;">
	            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;">Proyecto con mayor backlog</div>
	            <div style="font-size:15px;font-weight:700;color:#1d2327;margin-top:4px;"><?php echo esc_html( $risk_top_pr ); ?></div>
	            <div style="font-size:12px;color:#b42318;"><?php echo esc_html( $risk_top_pr_n ); ?> sin clasificar <span style="color:#999;">(total histórico)</span></div>
	        </div>
		        <a href="<?php echo el_portal_url( array( 'view' => 'pending' ), $portal_base ); ?>" style="text-decoration:none;flex:1;min-width:160px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:12px 16px;">
		            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;">Leads sin clasificar</div>
		            <div style="font-size:22px;font-weight:700;color:#8a6100;margin-top:4px;"><?php echo esc_html( number_format_i18n( (int) ( $stats['pending'] ?? 0 ) ) ); ?></div>
		        </a>
		        <a href="<?php echo el_portal_url( array( 'view' => 'escalated' ), $portal_base ); ?>" style="text-decoration:none;flex:1;min-width:160px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:12px 16px;">
		            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#646970;">Leads escalados</div>
		            <div style="font-size:22px;font-weight:700;color:#c5221f;margin-top:4px;"><?php echo esc_html( number_format_i18n( (int) ( $stats['escalated'] ?? 0 ) ) ); ?></div>
		        </a>
	    </div>

	    <!-- ── SECCIÓN 3 · ACCESOS RÁPIDOS (filtros existentes, sin queries/endpoints) ── -->
	    <h2 style="margin:0 0 10px;font-size:16px;">Accesos rápidos</h2>
	    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
	        <a class="button" href="<?php echo el_portal_url( array( 'view' => 'escalated' ), $portal_base ); ?>">Ver escalados</a>
	        <a class="button" href="<?php echo el_portal_url( array( 'view' => 'all', 'issue' => 'stale_7d' ), $portal_base ); ?>">Ver vencidos</a>
	        <a class="button" href="<?php echo el_portal_url( array( 'view' => 'pending' ), $portal_base ); ?>">Ver sin clasificar</a>
	        <a class="button" href="<?php echo el_portal_url( array( 'view' => 'recontact_no_action' ), $portal_base ); ?>">Ver recontactados sin acción</a>
	    </div>

    <h2 style="margin:0 0 10px;font-size:16px;">Autorespuesta · últimos 7 días</h2>
	    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
	        <?php
        $autoresponse_stat_items = array(
            array( 'label' => 'Enviadas', 'value' => $autoresponse_stats['sent'] ?? 0, 'help' => 'Correos automáticos de bienvenida enviados al prospecto apenas dejó sus datos (no son correos a operadores).' ),
            array( 'label' => 'Clicks PDF', 'value' => $autoresponse_stats['pdf_clicks'] ?? 0, 'help' => 'Prospectos que abrieron el PDF/ficha incluido en ese correo. Señal de interés real.' ),
            array( 'label' => 'Clicks WhatsApp', 'value' => $autoresponse_stats['whatsapp_clicks'] ?? 0, 'help' => 'Prospectos que tocaron el botón de WhatsApp del correo. Señal fuerte: quieren hablar.' ),
            array( 'label' => 'Leads con interacción', 'value' => $autoresponse_stats['engaged_leads'] ?? 0, 'help' => 'Leads únicos que hicieron al menos una acción (abrir, click PDF o WhatsApp). Estos conviene contactarlos primero.' ),
            array( 'label' => 'Fallos de envío', 'value' => $autoresponse_stats['operational_failures'] ?? 0, 'help' => 'Correos que no se pudieron entregar (email inválido o error del servidor de correo). No incluye leads sin email.' ),
            array( 'label' => 'Omitidas por límite de frecuencia', 'value' => $autoresponse_stats['throttled'] ?? 0, 'help' => 'No se envió porque el mismo prospecto ya recibió una autorespuesta hace poco (protección anti-spam del sistema).' ),
        );
        foreach ( $autoresponse_stat_items as $item ) :
        ?>
            <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:11px 14px;min-width:130px;">
                <strong style="display:block;font-size:22px;line-height:1.1;"><?php echo esc_html( $item['value'] ); ?></strong>
                <span style="font-size:12px;color:#646970;"><?php echo esc_html( $item['label'] ); ?>
                    <?php if ( ! empty( $item['help'] ) ) : ?>
                        <span title="<?php echo esc_attr( $item['help'] ); ?>" style="cursor:help;display:inline-block;width:14px;height:14px;line-height:14px;text-align:center;border-radius:50%;background:#e5e7eb;color:#50575e;font-size:10px;font-weight:700;vertical-align:middle;">?</span>
                    <?php endif; ?>
                </span>
            </div>
	        <?php endforeach; ?>
	    </div>

		    <h2 style="margin:0 0 10px;font-size:16px;">Información faltante reportada · últimos 30 días
		        <span title="Cuando un operador clasifica un lead puede reportar qué información le faltó para gestionarlo (teléfono, comuna, presupuesto, etc.). Este bloque cuenta esos reportes. 'No faltó información' = clasificaciones donde el operador tuvo todo lo necesario." style="cursor:help;display:inline-block;width:15px;height:15px;line-height:15px;text-align:center;border-radius:50%;background:#e5e7eb;color:#50575e;font-size:10px;font-weight:700;vertical-align:middle;">?</span>
		    </h2>
	    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
	        <?php if ( empty( $context_gap_summary ) ) : ?>
	            <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:11px 14px;color:#646970;font-size:12px;">Sin datos todavía</div>
	        <?php else : ?>
	            <?php foreach ( $context_gap_summary as $gap_stat ) : ?>
	                <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:11px 14px;min-width:130px;">
	                    <strong style="display:block;font-size:22px;line-height:1.1;"><?php echo esc_html( (int) ( $gap_stat['count'] ?? 0 ) ); ?></strong>
	                    <span style="font-size:12px;color:#646970;"><?php echo esc_html( (string) ( $gap_stat['label'] ?? '' ) ); ?></span>
	                </div>
	            <?php endforeach; ?>
	        <?php endif; ?>
	    </div>

    <script>
    (function ($) {
        var ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( 'estandar_assign_operator' ) ); ?>;

        // ── Bulk: select all ───────────────────────────────────────────────
        $('#es-portal-check-all').on('change', function () {
            $('.es-portal-lead-check').prop('checked', this.checked);
            esPortalUpdateBulkCount();
        });

        $(document).on('change', '.es-portal-lead-check', function () {
            var total   = $('.es-portal-lead-check').length;
            var checked = $('.es-portal-lead-check:checked').length;
            $('#es-portal-check-all')
                .prop('indeterminate', checked > 0 && checked < total)
                .prop('checked', checked === total);
            esPortalUpdateBulkCount();
        });

        function esPortalUpdateBulkCount() {
            var n = $('.es-portal-lead-check:checked').length;
            $('#es-portal-bulk-count').text(
                n > 0 ? n + ' seleccionado' + (n > 1 ? 's' : '') : ''
            );
        }

        // ── Bulk: confirm before submit ────────────────────────────────────
        window.esPortalBulkConfirm = function (btn) {
            var action  = $('#es-portal-bulk-action').val();
            var checked = $('.es-portal-lead-check:checked').length;

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
                    'Esta acción borrará submissions, eventos, clasificaciones y contactos asociados. ' +
                    'No se puede deshacer.'
                );
            }
            return true;
        };

        // ── "Enviar a operador" — singleton form outside the table ─────────
        $(document).on('click', '.es-create-link-btn', function () {
            var leadId   = $(this).data('lead-id');
            var redirect = $(this).data('redirect') || '';
            if (!leadId) { return; }
            $('#es-portal-send-lead-id').val(leadId);
            $('#es-portal-send-redirect').val(redirect);
            $('#es-portal-send-form').trigger('submit');
        });

        // ── Assign operator via AJAX ───────────────────────────────────────
        $(document).on('change', '.es-operator-select', function () {
            var $select = $(this);
            var leadId = $select.data('lead-id');
            var operatorId = $select.val();
            var $status = $('#es-assign-' + leadId);
            var $linkBtn = $('#es-link-btn-' + leadId);

            $select.prop('disabled', true);
            $status.text('Guardando...');

            $.post(ajaxurl, {
                action: 'estandar_assign_operator',
                nonce: ajaxNonce,
                lead_id: leadId,
                operator_id: operatorId
            }, function (response) {
                $select.prop('disabled', false);
                if (response.success) {
                    if (response.data.operator_name) {
                        $status.text('Asignado');
                        $linkBtn.prop('disabled', false).removeAttr('title');
                    } else {
                        $status.text('');
                        $linkBtn.prop('disabled', true).attr('title', 'Asigna un operador primero');
                    }
                    return;
                }
                var msg = response.data && response.data.message ? response.data.message : 'Error desconocido.';
                $status.text(msg);
            }).fail(function () {
                $select.prop('disabled', false);
                $status.text('Error de conexión.');
            });
        });
    })(jQuery);
    </script>
