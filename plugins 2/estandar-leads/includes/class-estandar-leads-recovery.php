<?php
/**
 * Recovery layer — separa "lead realmente perdido" de "LOST + sin contacto efectivo".
 *
 * Capa additiva: reutiliza el cron diario de Compliance, el motor de email y la
 * historia de eventos (es_lead_events). No toca scoring, clasificación, status,
 * quality, OCI, Meta, Google ni schema. Idempotente vía eventos.
 *
 * Eventos nuevos:
 *   - lead_second_attempt_requested
 *   - lead_recovery_email_sent
 *   - lead_recovery_email_failed
 *   - lead_lost_reason_set   (metadata.reason = no_contact)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_Recovery {

    /** 24h tras el cierre antes de actuar (fallback si no hay settings). */
    const DELAY = 24 * HOUR_IN_SECONDS;

    const OPTION_KEY = 'estandar_leads_recovery_settings';

    public static function init() {
        // Reutiliza el cron diario de Compliance — NO registra cron nuevo.
        if ( class_exists( 'Estandar_Leads_Compliance' ) ) {
            add_action( Estandar_Leads_Compliance::CRON_HOOK, array( __CLASS__, 'run_recovery_scan' ), 20 );
        }
        add_action( 'admin_post_estandar_leads_save_recovery_settings', array( __CLASS__, 'save_settings_handler' ) );
    }

    // =====================================================================
    // SETTINGS
    // =====================================================================
    public static function defaults() {
        return array(
            'recovery_enabled'                => 1,
            'recovery_delay_hours'            => 24,
            'recovery_email_enabled'          => 1,
            'second_attempt_reminder_enabled' => 1,
            'high_interest_open_threshold'    => 3,
            'include_recovery_in_digest'      => 1,
            'send_standalone_recovery_emails' => 0,
        );
    }

    public static function get_settings() {
        $raw = get_option( self::OPTION_KEY, array() );
        $raw = is_array( $raw ) ? $raw : array();
        $d   = self::defaults();
        return array(
            'recovery_enabled'                => isset( $raw['recovery_enabled'] ) ? (int) ! empty( $raw['recovery_enabled'] ) : $d['recovery_enabled'],
            'recovery_delay_hours'            => isset( $raw['recovery_delay_hours'] ) ? max( 1, (int) $raw['recovery_delay_hours'] ) : $d['recovery_delay_hours'],
            'recovery_email_enabled'          => isset( $raw['recovery_email_enabled'] ) ? (int) ! empty( $raw['recovery_email_enabled'] ) : $d['recovery_email_enabled'],
            'second_attempt_reminder_enabled' => isset( $raw['second_attempt_reminder_enabled'] ) ? (int) ! empty( $raw['second_attempt_reminder_enabled'] ) : $d['second_attempt_reminder_enabled'],
            'high_interest_open_threshold'    => isset( $raw['high_interest_open_threshold'] ) ? max( 1, (int) $raw['high_interest_open_threshold'] ) : $d['high_interest_open_threshold'],
            'include_recovery_in_digest'      => isset( $raw['include_recovery_in_digest'] ) ? (int) ! empty( $raw['include_recovery_in_digest'] ) : $d['include_recovery_in_digest'],
            'send_standalone_recovery_emails' => isset( $raw['send_standalone_recovery_emails'] ) ? (int) ! empty( $raw['send_standalone_recovery_emails'] ) : $d['send_standalone_recovery_emails'],
        );
    }

    public static function save_settings_handler() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No autorizado.' );
        }
        check_admin_referer( 'estandar_leads_recovery_settings' );
        update_option( self::OPTION_KEY, array(
            'recovery_enabled'                => ! empty( $_POST['recovery_enabled'] ) ? 1 : 0,
            'recovery_delay_hours'            => max( 1, absint( $_POST['recovery_delay_hours'] ?? 24 ) ),
            'recovery_email_enabled'          => ! empty( $_POST['recovery_email_enabled'] ) ? 1 : 0,
            'second_attempt_reminder_enabled' => ! empty( $_POST['second_attempt_reminder_enabled'] ) ? 1 : 0,
            'high_interest_open_threshold'    => max( 1, absint( $_POST['high_interest_open_threshold'] ?? 3 ) ),
            'include_recovery_in_digest'      => ! empty( $_POST['include_recovery_in_digest'] ) ? 1 : 0,
            'send_standalone_recovery_emails' => ! empty( $_POST['send_standalone_recovery_emails'] ) ? 1 : 0,
        ) );
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=estandar-leads-settings' ) );
        exit;
    }

    /** Outcomes equivalentes a "Sin respuesta" (normalizados: lowercase, sin acentos, _→espacio). */
    private static function no_response_outcomes() {
        return array(
            'no response', 'sin respuesta', 'no responde', 'no contesta',
            'no answer', 'unreachable', 'no ubicable', 'no localizado',
        );
    }

    /** Normaliza un outcome para comparación tolerante a mayúsculas/acentos/separadores. */
    private static function normalize_outcome( $outcome ) {
        $s = mb_strtolower( trim( (string) $outcome ) );
        $s = str_replace( array( '_', '-' ), ' ', $s );
        $s = strtr( $s, array( 'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n' ) );
        return preg_replace( '/\s+/', ' ', $s );
    }

    // =====================================================================
    // CRON SCAN
    // =====================================================================
    public static function run_recovery_scan() {
        global $wpdb;

        $cfg = self::get_settings();
        if ( empty( $cfg['recovery_enabled'] ) ) {
            return;
        }
        $delay = (int) $cfg['recovery_delay_hours'] * HOUR_IN_SECONDS;

        $leads = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}es_leads WHERE status = 'LOST'"
        );
        if ( empty( $leads ) ) {
            return;
        }

        foreach ( $leads as $lead ) {
            if ( ! self::is_no_contact_lost( (int) $lead->id ) ) {
                continue;
            }

            // AUTOMATIZACIÓN 3 · lost_reason = no_contact (inmediato, idempotente).
            self::ensure_lost_reason( (int) $lead->id );

            // Gate de N horas desde el cierre (configurable).
            $lost_at = self::lost_transition_time( $lead );
            if ( ! $lost_at || ( time() - strtotime( $lost_at ) ) < $delay ) {
                continue;
            }

            // AUTOMATIZACIÓN 1 · segundo intento (evento para el digest; email individual solo si standalone).
            if ( ! empty( $cfg['second_attempt_reminder_enabled'] ) ) {
                self::maybe_second_attempt( $lead, $cfg );
            }

            // AUTOMATIZACIÓN 2 · email de recuperación al lead (una vez).
            if ( ! empty( $cfg['recovery_email_enabled'] ) ) {
                self::maybe_recovery_email( $lead );
            }
        }
    }

    // =====================================================================
    // CONDICIÓN BASE
    // =====================================================================
    /** status = LOST (asumido por el caller) + último outcome = "Sin respuesta". */
    private static function is_no_contact_lost( $lead_id ) {
        global $wpdb;
        $outcome = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT outcome FROM {$wpdb->prefix}es_lead_contacts
              WHERE lead_id = %d ORDER BY contacted_at DESC, id DESC LIMIT 1",
            $lead_id
        ) );
        if ( $outcome === '' ) {
            return false;
        }
        return in_array( self::normalize_outcome( $outcome ), self::no_response_outcomes(), true );
    }

    private static function lost_transition_time( $lead ) {
        global $wpdb;
        $t = $wpdb->get_var( $wpdb->prepare(
            "SELECT created_at FROM {$wpdb->prefix}es_lead_events
              WHERE lead_id = %d AND event_type = 'status_changed' AND to_status = 'LOST'
           ORDER BY created_at DESC, id DESC LIMIT 1",
            (int) $lead->id
        ) );
        return $t ?: ( $lead->updated_at ?? '' );
    }

    private static function has_event( $lead_id, $type ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d AND event_type = %s",
            $lead_id, $type
        ) ) > 0;
    }

    // =====================================================================
    // AUTOMATIZACIÓN 1 · SEGUNDO INTENTO
    // =====================================================================
    private static function maybe_second_attempt( $lead, $cfg = null ) {
        $lead_id = (int) $lead->id;
        if ( self::has_event( $lead_id, 'lead_second_attempt_requested' ) ) {
            return; // solo una vez
        }
        $cfg = is_array( $cfg ) ? $cfg : self::get_settings();

        // Por defecto NO se envía email individual al operador: la solicitud viaja
        // en el digest operacional (## Leads Recuperables). Solo standalone si se activa.
        if ( ! empty( $cfg['send_standalone_recovery_emails'] ) ) {
            $operator_email = sanitize_email( (string) ( $lead->assigned_operator_email ?? '' ) );
            $operator_name  = (string) ( $lead->assigned_operator_name ?? '' );
            if ( is_email( $operator_email ) && class_exists( 'Estandar_Leads_Email' ) ) {
                $subject = 'Segundo intento requerido · lead marcado como perdido';
                $body  = 'Hola ' . ( $operator_name ?: 'operador/a' ) . ',<br><br>';
                $body .= 'El lead <strong>' . esc_html( $lead->name ?: ( '#' . $lead_id ) ) . '</strong> fue marcado como perdido por falta de contacto.<br><br>';
                $body .= 'Registra un segundo intento o confirma cierre definitivo.';
                Estandar_Leads_Email::send( $operator_email, $subject, $body );
            }
        }

        // El evento se registra siempre → el digest lo recoge.
        Estandar_Leads_Repository::insert_event( $lead_id, 'lead_second_attempt_requested', array(
            'operator_id' => absint( $lead->assigned_operator_id ?? 0 ),
            'metadata'    => array(
                'requested_at' => gmdate( 'Y-m-d H:i:s' ),
                'reason'       => 'lost_no_contact',
                'delivery'     => ! empty( $cfg['send_standalone_recovery_emails'] ) ? 'standalone+digest' : 'digest',
            ),
        ) );
    }

    // =====================================================================
    // AUTOMATIZACIÓN 2 · EMAIL DE RECUPERACIÓN
    // =====================================================================
    private static function maybe_recovery_email( $lead ) {
        $lead_id = (int) $lead->id;
        if ( self::has_event( $lead_id, 'lead_recovery_email_sent' )
          || self::has_event( $lead_id, 'lead_recovery_email_failed' ) ) {
            return; // solo un envío
        }

        $to = sanitize_email( (string) ( $lead->email ?? '' ) );
        if ( ! is_email( $to ) ) {
            Estandar_Leads_Repository::insert_event( $lead_id, 'lead_recovery_email_failed', array(
                'metadata' => array( 'result' => 'no_email' ),
            ) );
            return;
        }

        $nombre   = $lead->name ?: 'hola';
        $proyecto = $lead->project_slug ?: 'tu interés';
        $operador = (string) ( $lead->assigned_operator_name ?? 'El equipo' );

        $subject = 'Intentamos comunicarnos contigo';
        $body  = 'Hola ' . esc_html( $nombre ) . ',<br><br>';
        $body .= 'Intentamos comunicarnos contigo respecto a tu interés en ' . esc_html( $proyecto ) . ', pero no logramos contactarte.<br><br>';
        $body .= 'Si aún estás evaluando alternativas o tienes dudas, estaremos encantados de ayudarte.<br><br>';
        $body .= 'Puedes responder este correo o escribirnos directamente por WhatsApp para conversar cuando te acomode.<br><br>';
        $body .= 'Saludos,<br>' . esc_html( $operador );

        $sent = class_exists( 'Estandar_Leads_Email' )
            ? Estandar_Leads_Email::send( $to, $subject, $body )
            : false;

        if ( $sent ) {
            Estandar_Leads_Repository::insert_event( $lead_id, 'lead_recovery_email_sent', array(
                'metadata' => array( 'result' => 'sent', 'to' => $to ),
            ) );
        } else {
            Estandar_Leads_Repository::insert_event( $lead_id, 'lead_recovery_email_failed', array(
                'metadata' => array( 'result' => 'send_failed' ),
            ) );
        }
    }

    // =====================================================================
    // AUTOMATIZACIÓN 3 · lost_reason
    // =====================================================================
    private static function ensure_lost_reason( $lead_id ) {
        if ( self::has_event( $lead_id, 'lead_lost_reason_set' ) ) {
            return;
        }
        // Solo poblar automáticamente no_contact cuando outcome = Sin respuesta (ya validado).
        Estandar_Leads_Repository::insert_event( $lead_id, 'lead_lost_reason_set', array(
            'metadata' => array( 'lost_reason' => 'no_contact' ),
        ) );
    }

    /** Valores válidos del campo operativo (referencia; solo se puebla no_contact). */
    public static function lost_reasons() {
        return array( 'no_contact', 'not_interested', 'budget', 'invalid', 'other' );
    }

    public static function get_lost_reason( $lead_id ) {
        global $wpdb;
        $meta = $wpdb->get_var( $wpdb->prepare(
            "SELECT metadata FROM {$wpdb->prefix}es_lead_events
              WHERE lead_id = %d AND event_type = 'lead_lost_reason_set'
           ORDER BY created_at DESC, id DESC LIMIT 1",
            (int) $lead_id
        ) );
        if ( ! $meta ) {
            return '';
        }
        $decoded = json_decode( (string) $meta, true );
        return is_array( $decoded ) ? (string) ( $decoded['lost_reason'] ?? '' ) : '';
    }

    // =====================================================================
    // AUTOMATIZACIÓN 4 · SEGMENTO DE ALTO INTERÉS (helper, sin acciones)
    // =====================================================================
    /**
     * high_interest_lost = LOST + outcome "Sin respuesta" + >= 1 señal de interés.
     * Señales: recontactos>0 | autoresponse_engaged | autoresponse_link_click | email_open_count>=3.
     */
    public static function is_high_interest_lost( $lead_id ) {
        $lead_id = (int) $lead_id;

        global $wpdb;
        $status = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}es_leads WHERE id = %d", $lead_id
        ) );
        if ( $status !== 'LOST' || ! self::is_no_contact_lost( $lead_id ) ) {
            return false;
        }
        return ! empty( self::interest_signals( $lead_id ) );
    }

    /** Devuelve las etiquetas de señales de interés detectadas (threshold configurable). */
    public static function interest_signals( $lead_id ) {
        global $wpdb;
        $lead_id = (int) $lead_id;
        $cfg = self::get_settings();
        $signals = array();

        $subs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d", $lead_id
        ) );
        if ( $subs > 1 ) {
            $signals[] = 'recontacto';
        }
        if ( self::has_event( $lead_id, 'autoresponse_engaged' ) ) {
            $signals[] = 'engagement';
        }
        if ( self::has_event( $lead_id, 'autoresponse_link_click' ) ) {
            $signals[] = 'clic enlace';
        }
        $opens = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
              WHERE lead_id = %d AND event_type = 'autoresponse_email_open_signal'",
            $lead_id
        ) );
        if ( $opens >= (int) $cfg['high_interest_open_threshold'] ) {
            $signals[] = 'apertura email';
        }
        return $signals;
    }

    /** Etiqueta legible para el digest: "Alto (recontacto + apertura email)" o "—". */
    public static function interest_label( $lead_id ) {
        $signals = self::interest_signals( $lead_id );
        if ( empty( $signals ) ) {
            return '—';
        }
        return 'Alto (' . implode( ' + ', $signals ) . ')';
    }

    /**
     * Prioridad dinámica (sin estado/columna nueva):
     *   ALTA  = engagement | clic enlace | recontacto
     *   MEDIA = email_open_count >= threshold (y no ALTA)
     *   BAJA  = resto
     */
    public static function recovery_priority( $lead_id ) {
        global $wpdb;
        $lead_id = (int) $lead_id;

        $subs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d", $lead_id
        ) );
        if ( $subs > 1
          || self::has_event( $lead_id, 'autoresponse_engaged' )
          || self::has_event( $lead_id, 'autoresponse_link_click' ) ) {
            return 'ALTA';
        }

        $cfg   = self::get_settings();
        $opens = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
              WHERE lead_id = %d AND event_type = 'autoresponse_email_open_signal'",
            $lead_id
        ) );
        if ( $opens >= (int) $cfg['high_interest_open_threshold'] ) {
            return 'MEDIA';
        }

        return 'BAJA';
    }

    /**
     * second_attempt_completed (derivado, sin estado nuevo): existe un contacto
     * (contact_attempt / contact_logged) POSTERIOR al lead_second_attempt_requested.
     */
    public static function second_attempt_completed( $lead_id ) {
        global $wpdb;
        $lead_id = (int) $lead_id;
        $req_at = $wpdb->get_var( $wpdb->prepare(
            "SELECT created_at FROM {$wpdb->prefix}es_lead_events
              WHERE lead_id = %d AND event_type = 'lead_second_attempt_requested'
           ORDER BY created_at ASC, id ASC LIMIT 1",
            $lead_id
        ) );
        if ( ! $req_at ) {
            return false;
        }
        $later = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
              WHERE lead_id = %d AND event_type IN ('contact_attempt','contact_logged')
                AND created_at > %s",
            $lead_id, $req_at
        ) );
        return $later > 0;
    }

    /**
     * Leads recuperables de un operador para el digest:
     * status=LOST · lost_reason=no_contact · second_attempt_requested=true · completed=false.
     * @return array<int,array> filas listas para el template.
     */
    public static function recoverable_leads_for_operator( $operator_id ) {
        global $wpdb;
        $operator_id = (int) $operator_id;
        if ( $operator_id <= 0 ) {
            return array();
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.id, l.name, l.project_slug, l.assigned_operator_name, l.updated_at
               FROM {$wpdb->prefix}es_leads l
              WHERE l.status = 'LOST' AND l.assigned_operator_id = %d
                AND EXISTS (SELECT 1 FROM {$wpdb->prefix}es_lead_events e
                             WHERE e.lead_id = l.id AND e.event_type = 'lead_lost_reason_set')
                AND EXISTS (SELECT 1 FROM {$wpdb->prefix}es_lead_events e2
                             WHERE e2.lead_id = l.id AND e2.event_type = 'lead_second_attempt_requested')",
            $operator_id
        ) );
        if ( empty( $rows ) ) {
            return array();
        }

        $out = array();
        foreach ( $rows as $r ) {
            if ( self::second_attempt_completed( (int) $r->id ) ) {
                continue; // ya hubo segundo intento
            }
            // último outcome
            $outcome = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT outcome FROM {$wpdb->prefix}es_lead_contacts
                  WHERE lead_id = %d ORDER BY contacted_at DESC, id DESC LIMIT 1",
                (int) $r->id
            ) );
            $out[] = array(
                'id'             => (int) $r->id,
                'name'           => $r->name ?: ( '#' . (int) $r->id ),
                'project'        => $r->project_slug ?: '—',
                'operator'       => $r->assigned_operator_name ?: '—',
                'closed_at'      => $r->updated_at ?: '—',
                'last_outcome'   => $outcome !== '' ? $outcome : '—',
                'interest_label' => self::interest_label( (int) $r->id ),
                'priority'       => self::recovery_priority( (int) $r->id ),
            );
        }
        return $out;
    }

    // =====================================================================
    // EXPORT IA · Recovery Signals
    // =====================================================================
    public static function get_recovery_signals( $lead_id ) {
        $lead_id = (int) $lead_id;
        return array(
            'lost_reason'             => self::get_lost_reason( $lead_id ),
            'high_interest_lost'      => self::is_high_interest_lost( $lead_id ),
            'recovery_email_sent'     => self::has_event( $lead_id, 'lead_recovery_email_sent' ),
            'second_attempt_requested' => self::has_event( $lead_id, 'lead_second_attempt_requested' ),
            'terminal_reopened'       => self::has_event( $lead_id, 'lead_operational_reopened' ),
        );
    }
}
