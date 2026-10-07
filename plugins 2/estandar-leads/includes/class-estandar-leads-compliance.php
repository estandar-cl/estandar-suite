<?php
/**
 * Feedback Compliance Engine for Estandar Leads.
 *
 * Persigue feedback comercial faltante mediante un scheduler diario de WP-Cron
 * que escala notificaciones a operadores y supervisores según el tiempo transcurrido
 * sin clasificación.
 *
 * Niveles de escalamiento:
 *   48h   → Recordatorio al operador asignado.
 *   96h   → Segundo recordatorio al operador + CC al supervisor.
 *   7d    → Escalamiento al supervisor.
 *   14d   → Escalamiento final al supervisor + compliance_cc.
 *   weekly → Recordatorio semanal continuo (cada 7 días tras los 14d).
 *
 * Configuración (wp_options 'estandar_leads_compliance_settings'):
 *   enable_compliance_engine  bool    Activa/desactiva el motor. Default: false.
 *   feedback_due_hours        int     Compatibilidad histórica; el primer nivel es fijo en 48h.
 *   supervisor_email          string  Email del supervisor.
 *   compliance_cc_email       string  Email de copia en escalamientos finales.
 *
 * Tabla: wp_estandar_lead_notifications
 *   Registro de auditoría de cada notificación enviada.
 *
 * NO modifica: OCI, Analytics, Chatbot, WhatsApp.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_Compliance {

    const CRON_HOOK    = 'estandar_leads_compliance_daily';
    const OPTION_KEY   = 'estandar_leads_compliance_settings';
    const NOTIF_TABLE  = 'estandar_lead_notifications';

    // Escalation level identifiers.
    const LEVEL_48H    = '48h';
    const LEVEL_96H    = '96h';
    const LEVEL_7D     = '7d';
    const LEVEL_14D    = '14d';
    const LEVEL_WEEKLY = 'weekly';

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily_check' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'maybe_send_weekly_reports' ), 30 );
        add_action( 'admin_post_estandar_leads_save_compliance_settings', array( __CLASS__, 'save_settings_handler' ) );

        // Schedule daily cron on every load if not already scheduled.
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }

        // Deactivation: clear the cron.
        register_deactivation_hook( ESTANDAR_LEADS_FILE, array( __CLASS__, 'deactivate' ) );
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public static function defaults(): array {
        return array(
            'enable_compliance_engine' => 0,
            'feedback_due_hours'       => 48,
            'supervisor_email'         => '',
            'compliance_cc_email'      => '',
        );
    }

    public static function get_settings(): array {
        $saved = get_option( self::OPTION_KEY, array() );
        return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
    }

    public static function save_settings( array $raw ): void {
        $s = array();
        $s['enable_compliance_engine'] = ! empty( $raw['enable_compliance_engine'] ) ? 1 : 0;
        $s['feedback_due_hours']       = 48;
        $s['supervisor_email']         = sanitize_email( $raw['supervisor_email'] ?? '' );
        $s['compliance_cc_email']      = sanitize_email( $raw['compliance_cc_email'] ?? '' );

        update_option( self::OPTION_KEY, $s, false );
    }

    public static function save_settings_handler(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No autorizado.' );
        }
        check_admin_referer( 'estandar_leads_compliance_settings' );
        self::save_settings( $_POST );
        wp_safe_redirect( add_query_arg(
            array( 'page' => 'estandar-leads-settings', 'tab' => 'compliance', 'saved' => '1' ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Daily job
    // -------------------------------------------------------------------------

    /**
     * Entry point called by WP-Cron daily.
     * Queries all leads without feedback and dispatches escalations as needed.
     */
    public static function run_daily_check(): void {
        $settings = self::get_settings();

        if ( empty( $settings['enable_compliance_engine'] ) ) {
            return;
        }

        // OPERATIONAL_HOURS_V1: do not send compliance emails outside business hours.
        if ( class_exists( 'Estandar_Operational_Hours' ) && ! Estandar_Operational_Hours::is_open() ) {
            return;
        }

        $last_error = null;

        $cron_result = array(
            'operators_processed' => 0,
            'emails_sent'         => 0,
            'leads_covered'       => 0,
            'skipped_rate_limit'  => 0,
            'digests_sent'        => 0,
        );

        // Load pending leads grouped by assigned operator (reuses bootstrap query).
        $groups = self::pending_leads_by_operator();

        if ( empty( $groups ) ) {
            self::record_cron_run( $cron_result );
            return;
        }

        foreach ( $groups as $operator_id => $leads ) {
            $cron_result['operators_processed']++;

            $operator_id    = (int) $operator_id;
            $first_lead     = reset( $leads );
            $operator_email = sanitize_email( $first_lead['operator_email'] ?? '' );

            // Rate limit: 1 email per operator per calendar day.
            if ( is_email( $operator_email ) && self::operator_notified_today( $operator_id, $operator_email ) ) {
                $cron_result['skipped_rate_limit']++;
                continue;
            }

            // Determine which leads actually need a notification today.
            $actionable = array();
            foreach ( $leads as $lead ) {
                $hours   = self::hours_since( $lead['created_at'] );
                $level   = self::next_unsent_level( (int) $lead['id'], $hours );
                if ( $level === null ) {
                    continue;
                }
                if ( $level === self::LEVEL_WEEKLY && ! self::should_send_weekly( (int) $lead['id'] ) ) {
                    continue;
                }
                $lead['_pending_level'] = $level;
                $actionable[] = $lead;
            }

            if ( empty( $actionable ) ) {
                continue;
            }

            try {
                $sent = self::send_daily_digest( $actionable, $settings );
                if ( $sent ) {
                    $cron_result['emails_sent']++;
                    $cron_result['leads_covered'] += count( $actionable );
                    $cron_result['digests_sent']++;
                }
            } catch ( Throwable $e ) {
                $last_error = $e->getMessage();
                self::telemetry( 'error', 'COMPLIANCE_DAILY_EXCEPTION',
                    'Daily check threw exception for operator.',
                    array( 'operator_id' => $operator_id, 'exception' => get_class( $e ) )
                );
            }
        }

        self::record_cron_run( $cron_result, $last_error );
    }

    // ─── Weekly project report (reuses daily cron + email engine + template) ──────

    /**
     * Recipients map: [ project_slug => recipient_email ]. AK is configured here.
     * Empty by default → nothing sends unless a project is registered.
     */
    public static function weekly_report_recipients(): array {
        $map = (array) get_option( 'estandar_leads_weekly_report_recipients', array() );
        return (array) apply_filters( 'estandar_leads_weekly_report_recipients', $map );
    }

    /**
     * Weekly report schedule settings (option-backed; falls back to legacy filter/defaults).
     * Source: estandar_leads_weekly_report_settings (enabled · day ISO 1-7 · hour 0-23).
     */
    public static function weekly_settings(): array {
        $saved = (array) get_option( 'estandar_leads_weekly_report_settings', array() );
        return array(
            'enabled' => isset( $saved['enabled'] ) ? (int) ! empty( $saved['enabled'] ) : 1,
            'day'     => isset( $saved['day'] ) ? max( 1, min( 7, (int) $saved['day'] ) ) : (int) apply_filters( 'estandar_leads_weekly_report_day', 5 ),
            'hour'    => isset( $saved['hour'] ) ? max( 0, min( 23, (int) $saved['hour'] ) ) : 0,
        );
    }

    /** Hooked on CRON_HOOK (daily). Sends on the configured day, once per project per day. */
    public static function maybe_send_weekly_reports(): void {
        $settings = self::get_settings();
        if ( empty( $settings['enable_compliance_engine'] ) ) {
            return;
        }
        $wcfg = self::weekly_settings();
        if ( empty( $wcfg['enabled'] ) ) {
            return;
        }
        if ( (int) wp_date( 'N' ) !== (int) $wcfg['day'] ) {
            return;
        }
        if ( (int) wp_date( 'G' ) < (int) $wcfg['hour'] ) {
            return; // earliest dispatch hour (default 0 → siempre pasa)
        }
        foreach ( self::weekly_report_recipients() as $project_slug => $to ) {
            $project_slug = sanitize_title( (string) $project_slug );
            $to           = sanitize_email( (string) $to );
            if ( $project_slug === '' || ! is_email( $to ) ) {
                continue;
            }
            if ( get_transient( 'es_weekly_report_' . $project_slug . '_' . wp_date( 'Y-m-d' ) ) ) {
                continue; // idempotent: one send per project per calendar day
            }
            self::send_weekly_report( $project_slug, $to, $settings );
            set_transient( 'es_weekly_report_' . $project_slug . '_' . wp_date( 'Y-m-d' ), 1, DAY_IN_SECONDS );
        }
    }

    /** The single NEW query: 7-day rollup + top pending, by project. */
    public static function weekly_project_rollup( string $project_slug, int $days = 7 ): array {
        global $wpdb;
        $leads = $wpdb->prefix . 'es_leads';
        $since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        $metrics = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(first_seen_at >= %s) AS nuevos,
                SUM(last_classified_at >= %s) AS clasificados,
                SUM(last_classified_at >= %s AND is_valid_lead = 1) AS validos,
                SUM(last_classified_at >= %s AND is_valid_lead = 0) AS invalidos,
                SUM(updated_at >= %s AND sales_stage = 'MEETING_BOOKED') AS reuniones,
                SUM(updated_at >= %s AND sales_stage = 'PROPOSAL_SENT') AS propuestas,
                SUM(updated_at >= %s AND (sales_stage = 'CONVERTED' OR status = 'CONVERTED')) AS clientes,
                SUM(updated_at >= %s AND (sales_stage = 'LOST' OR status = 'LOST')) AS perdidos,
                SUM(first_seen_at >= %s AND feedback_completed = 0) AS sin_clasificar
             FROM {$leads} WHERE project_slug = %s",
            $since, $since, $since, $since, $since, $since, $since, $since, $since, $project_slug
        ), ARRAY_A ) ?: array();

        $top = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, behavioral_score, priority, status, created_at
             FROM {$leads}
             WHERE project_slug = %s AND feedback_completed = 0 AND status NOT IN ('CONVERTED','LOST')
             ORDER BY (behavioral_score IS NULL), behavioral_score DESC, created_at ASC
             LIMIT 10",
            $project_slug
        ), ARRAY_A ) ?: array();

        return array( 'metrics' => array_map( 'intval', $metrics ), 'top' => $top );
    }

    private static function send_weekly_report( string $project_slug, string $to, array $settings ): bool {
        $rollup       = self::weekly_project_rollup( $project_slug, 7 );
        $wr_s         = Estandar_Leads_Email::get_settings();
        $wr_project   = function_exists( 'resolve_project_display_name' )
            ? resolve_project_display_name( $project_slug, $project_slug ) : $project_slug;
        $wr_metrics   = $rollup['metrics'];
        $wr_top       = $rollup['top'];
        $wr_period    = wp_date( 'd/m' , time() - 7 * DAY_IN_SECONDS ) . ' – ' . wp_date( 'd/m' );

        $html = Estandar_Leads_Email::render_template(
            'compliance-weekly-report.php',
            compact( 'wr_project', 'wr_metrics', 'wr_top', 'wr_period', 'wr_s' )
        );
        $subject = sprintf( '📊 Resumen semanal de leads — %s (%s)', $wr_project, $wr_period );
        $sent = Estandar_Leads_Email::send( $to, $subject, $html, $wr_s );
        do_action( 'estandar_leads_weekly_report_sent', array(
            'project_slug' => $project_slug, 'to' => $to, 'result' => (bool) $sent,
        ) );
        return (bool) $sent;
    }

    /**
     * Determine and dispatch the correct escalation for a single lead.
     *
     * Handles catch-up: if the cron was down for days, this finds the most severe
     * unsent level and sends it (one email per cron run per lead).
     *
     * @param array $lead      Row from es_leads + operator join.
     * @param array $settings  Compliance settings.
     */
    private static function process_lead( array $lead, array $settings ): void {
        $lead_id       = (int) $lead['id'];
        $hours_elapsed = self::hours_since( $lead['created_at'] );
        $target_level  = self::next_unsent_level( $lead_id, $hours_elapsed );

        if ( $target_level === null ) {
            return; // Nothing to send.
        }

        // Weekly level: gate on 7-day interval since last notification.
        if ( $target_level === self::LEVEL_WEEKLY && ! self::should_send_weekly( $lead_id ) ) {
            return;
        }

        self::dispatch_escalation( $lead, $settings, $target_level );
    }

    /**
     * Return the most severe escalation level that applies but has NOT yet been sent.
     *
     * Fixed levels (sent once): 48h, 96h, 7d, 14d.
     * Weekly level: sent repeatedly every 7 days after 14d.
     *
     * @param int $lead_id
     * @param int $hours     Hours since lead creation.
     * @return string|null
     */
    private static function next_unsent_level( int $lead_id, int $hours ): ?string {
        // Collect all fixed levels that should have fired, ordered least → most severe.
        $applicable = array();
        if ( $hours >= 48 ) {
            $applicable[] = self::LEVEL_48H;
        }
        if ( $hours >= 96 ) {
            $applicable[] = self::LEVEL_96H;
        }
        if ( $hours >= ( 7 * 24 ) ) {
            $applicable[] = self::LEVEL_7D;
        }
        if ( $hours >= ( 14 * 24 ) ) {
            $applicable[] = self::LEVEL_14D;
        }

        $target_level = empty( $applicable ) ? null : end( $applicable );
        if ( $target_level === null ) {
            return null;
        }

        if ( ! self::notification_exists( $lead_id, $target_level ) ) {
            return $target_level;
        }

        // After the 14d escalation, continue with weekly supervisor reminders.
        if ( $target_level === self::LEVEL_14D && $hours > ( 14 * 24 ) ) {
            return self::LEVEL_WEEKLY;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    /**
     * Send the appropriate escalation email(s) and record the audit entry.
     *
     * @param array  $lead         Lead data row.
     * @param array  $settings     Compliance settings.
     * @param string $level        Escalation level constant.
     * @return bool                True if email was sent successfully.
     */
    private static function dispatch_escalation( array $lead, array $settings, string $level ): bool {
        if (class_exists('Estandar_Leads_QA_Policy') && Estandar_Leads_QA_Policy::is_test_lead((int) ($lead['id'] ?? 0))) {
            return false;
        }
        $lead_id         = (int) $lead['id'];
        $operator_id     = (int) ( $lead['assigned_operator_id'] ?? 0 );
        $operator_email  = sanitize_email( $lead['operator_email'] ?? '' );
        $supervisor      = sanitize_email( $settings['supervisor_email'] ?? '' );
        $compliance_cc   = sanitize_email( $settings['compliance_cc_email'] ?? '' );

        // Determine recipients per escalation level.
        $recipients = self::recipients_for_level( $level, $operator_email, $supervisor, $compliance_cc );

        if ( empty( $recipients['to'] ) ) {
            self::telemetry( 'warning', 'COMPLIANCE_NO_RECIPIENT',
                'Escalation skipped: no valid recipient for level ' . $level,
                array( 'lead_id' => $lead_id, 'level' => $level )
            );
            return false;
        }

        // Generate a fresh signed evaluation token for each notification.
        $token       = Estandar_Leads_Tokens::create( $lead_id, $operator_id ?: null );
        $eval_url    = $token !== '' ? Estandar_Leads_Tokens::url( $token ) : '';
        $token_hash  = $token !== '' ? Estandar_Leads_Tokens::hash( $token ) : '';

        $email_settings = Estandar_Leads_Email::get_settings();

        $subject = self::subject_for_level( $level, $lead );
        $html    = self::render_email( $level, $lead, $eval_url, $email_settings );

        // Build headers (CC/BCC).
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        foreach ( $recipients['cc'] as $cc ) {
            if ( is_email( $cc ) ) {
                $headers[] = 'Cc: ' . $cc;
            }
        }
        $shadow_context = array(
            'type'  => self::shadow_copy_type_for_level( $level ),
            'to'    => $recipients['to'],
            'level' => $level,
        );
        $headers = apply_filters( 'estandar_compliance_email_headers', $headers, $shadow_context );

        // Update feedback_status BEFORE attempting email.
        // feedback_status is time-based (lead is overdue/escalated regardless of email delivery).
        // compliance_escalation event (email delivery audit) is recorded only on success.
        self::update_feedback_status( $lead_id, self::feedback_status_for_level( $level ), array(
            'triggered_by'     => 'escalation_cron',
            'escalation_level' => $level,
            'operator_id'      => $operator_id ?: null,
            'days_elapsed'     => (int) floor( self::hours_since( $lead['created_at'] ) / 24 ),
        ) );

        $sent = Estandar_Leads_Email::send(
            $recipients['to'],
            $subject,
            $html,
            $email_settings,
            $headers
        );
        do_action( 'estandar_compliance_email_sent', array_merge( $shadow_context, array( 'result' => (bool) $sent ) ) );

        if ( ! $sent ) {
            self::telemetry( 'error', 'COMPLIANCE_EMAIL_FAILED',
                'Compliance escalation email failed to send.',
                array( 'lead_id' => $lead_id, 'level' => $level, 'to' => $recipients['to'] )
            );
            return false;
        }

        // Audit: record in notifications table (token stored as hash only — raw token never persisted).
        self::record_notification( $lead_id, $operator_id, $level, $recipients, $token_hash );

        // Audit: insert into lead events (email-delivery audit — only on success).
        Estandar_Leads_Repository::insert_event( $lead_id, 'compliance_escalation', array(
            'operator_id' => $operator_id ?: null,
            'metadata'    => array(
                'escalation_level'   => $level,
                'recipient_email'    => $recipients['to'],
                'cc_emails'          => implode( ',', $recipients['cc'] ),
                'notification_type'  => self::notification_type_for_level( $level ),
                'hours_elapsed'      => self::hours_since( $lead['created_at'] ),
                'token_expires_days' => 21,
            ),
        ) );

        self::telemetry( 'info', 'COMPLIANCE_ESCALATION_SENT',
            'Compliance escalation sent.',
            array(
                'lead_id'  => $lead_id,
                'level'    => $level,
                'to'       => $recipients['to'],
                'cc_count' => count( $recipients['cc'] ),
            )
        );

        return true;
    }

    /**
     * Send a single grouped daily digest email for an operator with actionable leads.
     * Each lead in $leads must have _pending_level set.
     *
     * @param  array $leads     Actionable leads (each with _pending_level).
     * @param  array $settings  Compliance settings.
     * @return bool
     */
    /**
     * Día de despacho del digest operacional: lunes y jueves (máx 2/semana/operador).
     * El cron sigue corriendo a diario; esto solo gatea el ENVÍO del correo.
     */
    /**
     * Digest operator settings (option-backed; falls back to the legacy filter/defaults).
     * Days in ISO-8601 (1=Lun .. 7=Dom). Source: estandar_compliance_digest_settings.
     */
    public static function digest_settings(): array {
        $saved = (array) get_option( 'estandar_compliance_digest_settings', array() );
        $days  = ( isset( $saved['days'] ) && is_array( $saved['days'] ) )
            ? array_values( array_filter( array_map( 'intval', $saved['days'] ), static function ( $d ) { return $d >= 1 && $d <= 7; } ) )
            : array_map( 'intval', (array) apply_filters( 'estandar_compliance_digest_dispatch_days', array( 1, 4 ) ) );
        if ( empty( $days ) ) {
            $days = array( 1, 4 );
        }
        return array(
            'enabled' => isset( $saved['enabled'] ) ? (int) ! empty( $saved['enabled'] ) : 1,
            'days'    => $days,
            'hour'    => isset( $saved['hour'] ) ? max( 0, min( 23, (int) $saved['hour'] ) ) : 0,
        );
    }

    public static function is_digest_dispatch_day(): bool {
        $cfg = self::digest_settings();
        if ( empty( $cfg['enabled'] ) ) {
            return false;
        }
        $iso = (int) current_time( 'N' ); // 1=Lun .. 7=Dom
        if ( ! in_array( $iso, array_map( 'intval', $cfg['days'] ), true ) ) {
            return false;
        }
        if ( (int) current_time( 'G' ) < (int) $cfg['hour'] ) {
            return false; // earliest dispatch hour (default 0 → siempre pasa)
        }
        return true;
    }

    private static function send_daily_digest( array $leads, array $settings ): bool {
        if ( empty( $leads ) ) {
            return false;
        }

        $first_lead     = reset( $leads );
        $operator_id    = (int) ( $first_lead['assigned_operator_id'] ?? 0 );
        $operator_email = sanitize_email( $first_lead['operator_email'] ?? '' );
        $operator_name  = sanitize_text_field( $first_lead['operator_name'] ?? '' );

        if ( ! is_email( $operator_email ) ) {
            $operator_email = sanitize_email( $settings['supervisor_email'] ?? '' );
        }
        if ( ! is_email( $operator_email ) ) {
            return false;
        }

        // Build classified structure (by age tier). Daily digests use one workspace CTA.
        $classified = array(
            self::TIER_RECENT   => array(),
            self::TIER_STALE    => array(),
            self::TIER_ARCHIVED => array(),
        );
        $lead_ids       = array();
        $workspace_url  = '';
        $workspace_hash = '';

        if ( $operator_id > 0 && class_exists( 'Estandar_Leads_Operator_Workspace' ) ) {
            $workspace_token = Estandar_Leads_Operator_Workspace::create_token( $operator_id );
            if ( $workspace_token !== '' ) {
                $workspace_url  = Estandar_Leads_Operator_Workspace::workspace_url( $workspace_token );
                $workspace_hash = Estandar_Leads_Operator_Workspace::hash_token( $workspace_token );
            }
        }

        foreach ( $leads as $lead ) {
            $lead_id = (int) $lead['id'];
            $days    = (int) floor( self::hours_since( $lead['created_at'] ) / 24 );
            $tier    = $days <= 30 ? self::TIER_RECENT : ( $days <= 90 ? self::TIER_STALE : self::TIER_ARCHIVED );

            $classified[ $tier ][] = $lead;
            $lead_ids[]     = $lead_id;
        }

        $total          = count( $leads );
        $email_settings = Estandar_Leads_Email::get_settings();
        $subject        = sprintf(
            '⚠️ %d lead%s pendiente%s de clasificación — resumen diario',
            $total,
            $total !== 1 ? 's' : '',
            $total !== 1 ? 's' : ''
        );

        $es_operator_name = $operator_name;
        $es_classified    = $classified;
        $es_total         = $total;
        $es_s             = $email_settings;
        $es_context       = 'daily'; // Differentiates badge from bootstrap context.
        $es_workspace_url = $workspace_url;

        // Recovery Layer · leads recuperables del operador (integrado en el digest, sin email aparte).
        $es_recoverable = array();
        if ( class_exists( 'Estandar_Leads_Recovery' ) ) {
            $rec_cfg = Estandar_Leads_Recovery::get_settings();
            if ( ! empty( $rec_cfg['include_recovery_in_digest'] ) ) {
                $es_recoverable = Estandar_Leads_Recovery::recoverable_leads_for_operator( $operator_id );
            }
        }

        $html = Estandar_Leads_Email::render_template(
            'compliance-bootstrap-digest.php',
            compact( 'es_operator_name', 'es_classified', 'es_total', 'es_s', 'es_context', 'es_workspace_url', 'es_recoverable' )
        );

        // Update feedback_status for all leads BEFORE attempting email.
        // Leads are overdue/escalated regardless of whether the digest email delivers.
        foreach ( $leads as $lead ) {
            $lid       = (int) $lead['id'];
            $level_pre = sanitize_key( $lead['_pending_level'] ?? self::LEVEL_48H );
            $lead_days = (int) floor( self::hours_since( $lead['created_at'] ) / 24 );
            self::update_feedback_status( $lid, self::feedback_status_for_level( $level_pre ), array(
                'triggered_by'     => 'daily_cron',
                'escalation_level' => $level_pre,
                'operator_id'      => $operator_id ?: null,
                'days_elapsed'     => $lead_days,
            ) );
        }

        $shadow_context = array(
            'type'  => 'daily_digest',
            'to'    => $operator_email,
            'level' => 'daily_digest',
        );
        $headers = apply_filters(
            'estandar_compliance_email_headers',
            self::digest_headers( $leads, $settings ),
            $shadow_context
        );
        // CONSOLIDACIÓN DE ENVÍOS: el cron sigue corriendo a diario y los estados de
        // compliance/escalación YA se actualizaron arriba (loop update_feedback_status).
        // El correo operacional solo se DESPACHA lunes y jueves (máx 2/semana/operador).
        if ( ! self::is_digest_dispatch_day() ) {
            return false;
        }

        $sent = Estandar_Leads_Email::send(
            $operator_email,
            $subject,
            $html,
            $email_settings,
            $headers
        );
        do_action( 'estandar_compliance_email_sent', array_merge( $shadow_context, array( 'result' => (bool) $sent ) ) );

        if ( ! $sent ) {
            self::telemetry( 'error', 'COMPLIANCE_DAILY_DIGEST_FAILED',
                'Daily digest email failed.',
                array( 'operator_id' => $operator_id, 'lead_count' => $total )
            );
            return false;
        }

        // Audit: one notification row per lead + email-delivery event (only on success).
        global $wpdb;
        $now = gmdate( 'Y-m-d H:i:s' );
        foreach ( $leads as $idx => $lead ) {
            $lid   = (int) $lead['id'];
            $level = sanitize_key( $lead['_pending_level'] ?? self::LEVEL_48H );
            $wpdb->insert(
                $wpdb->prefix . self::NOTIF_TABLE,
                array(
                    'lead_id'           => $lid,
                    'operator_id'       => $operator_id ?: null,
                    'escalation_level'  => $level,
                    'notification_type' => 'daily_digest',
                    'recipient_email'   => $operator_email,
                    'cc_emails'         => '',
                    'token_hash'        => $workspace_hash,
                    'is_retroactive'    => 0,
                    'metadata'          => wp_json_encode( array(
                        'digest_lead_count' => $total,
                        'sent_by'           => 'daily_cron',
                        'workspace_cta'      => $workspace_url !== '',
                    ) ),
                    'sent_at'    => $now,
                    'created_at' => $now,
                ),
                array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
            );

            Estandar_Leads_Repository::insert_event( $lid, 'operator_daily_digest_sent', array(
                'operator_id' => $operator_id ?: null,
                'metadata'    => array(
                    'escalation_level'  => $level,
                    'notification_type' => 'daily_digest',
                    'digest_size'       => $total,
                ),
            ) );
        }

        self::telemetry( 'info', 'COMPLIANCE_DAILY_DIGEST_SENT',
            'Daily compliance digest sent.',
            array( 'operator_id' => $operator_id, 'lead_count' => $total )
        );

        return true;
    }

    // -------------------------------------------------------------------------
    // Recipient routing
    // -------------------------------------------------------------------------

    private static function digest_headers( array $leads, array $settings ): array {
        $headers       = array( 'Content-Type: text/html; charset=UTF-8' );
        $supervisor    = sanitize_email( $settings['supervisor_email'] ?? '' );
        $compliance_cc = sanitize_email( $settings['compliance_cc_email'] ?? '' );
        $needs_supervisor = false;
        $needs_compliance = false;

        foreach ( $leads as $lead ) {
            $level = sanitize_key( $lead['_pending_level'] ?? '' );
            if ( in_array( $level, array( self::LEVEL_96H, self::LEVEL_7D, self::LEVEL_14D, self::LEVEL_WEEKLY ), true ) ) {
                $needs_supervisor = true;
            }
            if ( in_array( $level, array( self::LEVEL_14D, self::LEVEL_WEEKLY ), true ) ) {
                $needs_compliance = true;
            }
        }

        if ( $needs_supervisor && is_email( $supervisor ) ) {
            $headers[] = 'Cc: ' . $supervisor;
        }
        if ( $needs_compliance && is_email( $compliance_cc ) && $compliance_cc !== $supervisor ) {
            $headers[] = 'Cc: ' . $compliance_cc;
        }

        return $headers;
    }

    /**
     * Build recipient lists (to / cc) per escalation level.
     *
     * Level    To                  CC
     * ------   ----------------    ----------------------------
     * 48h      operator            —
     * 96h      operator            supervisor
     * 7d       supervisor          operator (if any)
     * 14d      supervisor          operator, compliance_cc
     * weekly   supervisor          operator, compliance_cc
     *
     * @return array{to: string, cc: string[]}
     */
    private static function recipients_for_level(
        string $level,
        string $operator_email,
        string $supervisor,
        string $compliance_cc
    ): array {
        $to = '';
        $cc = array();

        switch ( $level ) {
            case self::LEVEL_48H:
                $to = $operator_email ?: $supervisor;
                break;

            case self::LEVEL_96H:
                $to = $operator_email ?: $supervisor;
                if ( $supervisor && $supervisor !== $to ) {
                    $cc[] = $supervisor;
                }
                break;

            case self::LEVEL_7D:
                $to = $supervisor;
                if ( $operator_email && $operator_email !== $to ) {
                    $cc[] = $operator_email;
                }
                break;

            case self::LEVEL_14D:
            case self::LEVEL_WEEKLY:
                $to = $supervisor;
                if ( $operator_email && $operator_email !== $to ) {
                    $cc[] = $operator_email;
                }
                if ( $compliance_cc && $compliance_cc !== $to && ! in_array( $compliance_cc, $cc, true ) ) {
                    $cc[] = $compliance_cc;
                }
                break;
        }

        // Validate final address.
        if ( ! is_email( $to ) ) {
            $to = '';
        }

        return array(
            'to' => $to,
            'cc' => array_values( array_filter( $cc, 'is_email' ) ),
        );
    }

    // -------------------------------------------------------------------------
    // Email rendering
    // -------------------------------------------------------------------------

    private static function subject_for_level( string $level, array $lead ): string {
        $project = resolve_project_display_name( $lead['project_slug'] ?? '', $lead['project_area'] ?? '' );
        $name    = sanitize_text_field( $lead['name'] ?? '' );
        $tag     = $project ? " · $project" : '';

        switch ( $level ) {
            case self::LEVEL_48H:
                return "⏳ Recordatorio: clasificación de lead pendiente{$tag}";
            case self::LEVEL_96H:
                return "⚠️ 2° recordatorio: lead sin clasificar{$tag}";
            case self::LEVEL_7D:
                return "🔴 Escalamiento (7 días): lead sin respuesta{$tag}";
            case self::LEVEL_14D:
                return "🚨 Escalamiento final (14 días): lead crítico sin clasificar{$tag}";
            case self::LEVEL_WEEKLY:
                return "📋 Seguimiento semanal: lead aún sin clasificar{$tag}";
            default:
                return 'Recordatorio de clasificación de lead';
        }
    }

    private static function notification_type_for_level( string $level ): string {
        switch ( $level ) {
            case self::LEVEL_48H:  return 'operator_reminder';
            case self::LEVEL_96H:  return 'operator_reminder_with_supervisor';
            case self::LEVEL_7D:   return 'supervisor_escalation';
            case self::LEVEL_14D:  return 'compliance_escalation';
            case self::LEVEL_WEEKLY: return 'weekly_digest';
            default:               return 'reminder';
        }
    }

    /**
     * Stable QA context for Compliance Shadow Copy.
     */
    private static function shadow_copy_type_for_level( string $level ): string {
        switch ( $level ) {
            case self::LEVEL_48H:     return 'reminder_soft';
            case self::LEVEL_96H:     return 'reminder_operational';
            case self::LEVEL_7D:      return 'reminder_insistent';
            case self::LEVEL_14D:     return 'escalation';
            case self::LEVEL_WEEKLY:  return 'weekly_digest';
            default:                  return '';
        }
    }

    private static function render_email(
        string $level,
        array $lead,
        string $eval_url,
        array $email_settings
    ): string {
        $es_level         = $level;
        $es_lead          = $lead;
        $es_eval_url      = $eval_url;
        $es_s             = $email_settings;
        $es_hours_elapsed = self::hours_since( $lead['created_at'] );

        return Estandar_Leads_Email::render_template(
            'compliance-escalation.php',
            compact( 'es_level', 'es_lead', 'es_eval_url', 'es_s', 'es_hours_elapsed' )
        );
    }

    // -------------------------------------------------------------------------
    // Audit / state helpers
    // -------------------------------------------------------------------------

    /**
     * Check if a fixed-level notification was already sent for this lead.
     */
    private static function notification_exists( int $lead_id, string $level ): bool {
        global $wpdb;

        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::NOTIF_TABLE . "
             WHERE lead_id = %d AND escalation_level = %s
             LIMIT 1",
            $lead_id,
            $level
        ) );

        return (int) $count > 0;
    }

    /**
     * For weekly: only send if the last notification (any type) was 7+ days ago.
     */
    private static function should_send_weekly( int $lead_id ): bool {
        global $wpdb;

        $last_sent = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(sent_at) FROM {$wpdb->prefix}" . self::NOTIF_TABLE . "
             WHERE lead_id = %d",
            $lead_id
        ) );

        if ( ! $last_sent ) {
            return true; // Never notified — send.
        }

        $hours_since_last = self::hours_since( $last_sent );
        return $hours_since_last >= ( 7 * 24 );
    }

    /**
     * Persist a notification record in the audit table.
     *
     * @param array{to: string, cc: string[]} $recipients
     */
    private static function record_notification(
        int $lead_id,
        int $operator_id,
        string $level,
        array $recipients,
        string $token_hash
    ): void {
        global $wpdb;

        $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->insert(
            $wpdb->prefix . self::NOTIF_TABLE,
            array(
                'lead_id'           => $lead_id,
                'operator_id'       => $operator_id ?: null,
                'escalation_level'  => $level,
                'notification_type' => self::notification_type_for_level( $level ),
                'recipient_email'   => $recipients['to'],
                'cc_emails'         => implode( ',', $recipients['cc'] ),
                'token_hash'        => $token_hash,
                'metadata'          => wp_json_encode( array(
                    'sent_by' => 'compliance_engine',
                    'version' => ESTANDAR_LEADS_VERSION,
                ) ),
                'sent_at'           => $now,
                'created_at'        => $now,
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Map an escalation level to the appropriate feedback_status value.
     *
     * Levels 48h / 96h → 'overdue'   (operator notified, not yet supervisor).
     * Levels 7d / 14d / weekly → 'escalated' (supervisor involved).
     */
    private static function feedback_status_for_level( string $level ): string {
        switch ( $level ) {
            case self::LEVEL_7D:
            case self::LEVEL_14D:
            case self::LEVEL_WEEKLY:
                return 'escalated';
            default:
                return 'overdue';
        }
    }

    /**
     * Canonical feedback_status values:
     *   pending   — lead received, no action taken yet.
     *   overdue   — lead past due window, first/second reminder sent.
     *   escalated — lead escalated to supervisor (7d / 14d / weekly levels).
     *   received  — operator submitted feedback; cycle closed.
     *
     * @param int    $lead_id
     * @param string $status   Target status.
     * @param array  $context  Optional audit context:
     *                           'triggered_by'     string — 'escalation_cron'|'daily_cron'|'terminal_status'|'bootstrap'
     *                           'escalation_level' string — LEVEL_* constant, e.g. '48h', '7d'
     *                           'days_elapsed'     int    — days since lead creation
     *                           'operator_id'      int    — operator involved (if any)
     */
    private static function update_feedback_status( int $lead_id, string $status, array $context = array() ): void {
        global $wpdb;

        $allowed = array( 'pending', 'received', 'overdue', 'escalated' );
        if ( ! in_array( $status, $allowed, true ) ) {
            return;
        }

        // Read current value so we can detect an actual change and skip no-ops.
        $current = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT feedback_status FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            $lead_id
        ) );

        // Skip DB write and event if nothing changed.
        if ( $current === $status ) {
            return;
        }

        $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->update(
            $wpdb->prefix . 'es_leads',
            array(
                'feedback_status' => $status,
                'updated_at'      => $now,
            ),
            array( 'id' => $lead_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // GAP-1 fix: audit every feedback_status transition as an immutable event.
        Estandar_Leads_Repository::insert_event( $lead_id, 'feedback_status_changed', array(
            'operator_id' => ! empty( $context['operator_id'] ) ? (int) $context['operator_id'] : null,
            'metadata'    => array_filter( array(
                'from_status'      => $current,
                'to_status'        => $status,
                'triggered_by'     => $context['triggered_by']     ?? 'unknown',
                'escalation_level' => $context['escalation_level'] ?? null,
                'days_elapsed'     => isset( $context['days_elapsed'] ) ? (int) $context['days_elapsed'] : null,
            ), static function ( $v ) { return $v !== null; } ),
        ) );
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /**
     * Public proxy for templates and external callers.
     */
    public static function hours_since_public( string $datetime ): int {
        return self::hours_since( $datetime );
    }

    /**
     * Hours elapsed since a UTC datetime string.
     *
     * @param string $datetime  MySQL UTC datetime (Y-m-d H:i:s).
     * @return int
     */
    private static function hours_since( string $datetime ): int {
        if ( $datetime === '' ) {
            return 0;
        }
        $ts = strtotime( $datetime . ' UTC' );
        if ( $ts === false ) {
            return 0;
        }
        return (int) floor( ( time() - $ts ) / HOUR_IN_SECONDS );
    }

    private static function telemetry( string $severity, string $code, string $message, array $context = array() ): void {
        try {
            do_action( 'estandar_telemetry_event', array(
                'source_plugin' => 'estandar-leads',
                'type'          => 'business_event',
                'severity'      => $severity,
                'code'          => $code,
                'message'       => $message,
                'context'       => $context,
            ) );
        } catch ( Throwable $e ) {
            // Telemetry failures must never break the compliance engine.
        }
    }

    // -------------------------------------------------------------------------
    // Public accessors (used by admin UI)
    // -------------------------------------------------------------------------

    /**
     * Notification history for a given lead (most recent first).
     *
     * @param int $lead_id
     * @return array
     */
    public static function notifications_for_lead( int $lead_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT n.*, o.name AS operator_name
             FROM {$wpdb->prefix}" . self::NOTIF_TABLE . " n
             LEFT JOIN {$wpdb->prefix}es_operators o ON o.id = n.operator_id
             WHERE n.lead_id = %d
             ORDER BY n.sent_at DESC
             LIMIT 50",
            $lead_id
        ), ARRAY_A );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Summary stats for the compliance dashboard.
     *
     * @return array{pending: int, overdue: int, received: int, total_notifications: int}
     */
    public static function stats(): array {
        global $wpdb;

        $leads_table = $wpdb->prefix . 'es_leads';
        $notif_table = $wpdb->prefix . self::NOTIF_TABLE;

        $pending   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $leads_table WHERE feedback_completed = 0 AND feedback_status = 'pending'" );
        $overdue   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $leads_table WHERE feedback_completed = 0 AND feedback_status = 'overdue'" );
        $escalated = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $leads_table WHERE feedback_completed = 0 AND feedback_status = 'escalated'" );
        $received  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $leads_table WHERE feedback_completed = 1" );
        $total_n   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $notif_table" );

        return array(
            'pending'             => $pending,
            'overdue'             => $overdue,
            'escalated'           => $escalated,
            'received'            => $received,
            'total_notifications' => $total_n,
        );
    }

    /**
     * Statuses that represent a terminal commercial disposition — operator has
     * made a meaningful judgment on the lead. Transitioning to any of these
     * closes the compliance cycle (feedback_status = 'received').
     *
     * NOT included: CONTACTED, NO_RESPONSE — intermediate states where the
     * operator is still working the lead but has not rendered a final verdict.
     * NEW is also excluded (initial ingestion, no operator action).
     */
    const FEEDBACK_TERMINAL_STATUSES = array(
        'NO_RESPONSE',      // Operator decision: attempted, no answer — is a classification.
        'UNREACHABLE',
        'QUALIFIED',
        'MEETING_BOOKED',
        'PROPOSAL_SENT',
        'NEGOTIATION',
        'CONVERTED',
        'LOST',
        'INVALID',          // Spam / error — cierra el ciclo sin gestión comercial.
        // Legacy real-estate statuses.
        'VISIT_SCHEDULED',
        'VISITED',
        'BANK_EVALUATION',
        'RESERVED',
        'DEED_SIGNED',
        'DELIVERED',
    );

    /**
     * Mark a lead's feedback_status as 'received' when operator reaches a
     * terminal commercial disposition. Intermediate statuses (CONTACTED,
     * NO_RESPONSE) do not close the cycle — the lead is still being worked.
     *
     * Hook signature: estandar_lead_status_changed( $lead_id, $from, $to, $now )
     * Registered with 3 accepted args; $now (4th) is ignored.
     *
     * @param int    $lead_id
     * @param string $from_status
     * @param string $to_status
     */
    public static function on_lead_status_changed( int $lead_id, string $from_status, string $to_status ): void {
        if ( ! in_array( $to_status, self::FEEDBACK_TERMINAL_STATUSES, true ) ) {
            return; // Intermediate state — keep pursuing feedback.
        }
        self::update_feedback_status( $lead_id, 'received', array(
            'triggered_by' => 'terminal_status',
            'escalation_level' => $to_status,
        ) );
    }

    // =========================================================================
    // RETROACTIVE BOOTSTRAP
    // =========================================================================

    const BOOTSTRAP_OPTION    = 'estandar_leads_compliance_bootstrap';
    const BOOTSTRAP_BATCH_MAX = 500;  // Max leads loaded per run.
    // TODO: this cap is global across all operators per cron run, not per-operator.
    // With a very large backlog, leads belonging to operators processed later in
    // pending_leads_by_operator() may be silently excluded from this run's digest.
    const DIGEST_THRESHOLD    = 3;    // >N pending leads → grouped digest email.

    /**
     * Age tier constants. Classify leads by days since creation.
     */
    const TIER_RECENT   = 'recent';   //  0–30 days
    const TIER_STALE    = 'stale';    // 31–90 days
    const TIER_ARCHIVED = 'archived'; // 90+ days

    /**
     * Admin-triggered bootstrap entry point.
     * Runs synchronously (suitable for manual trigger; max 500 leads per call).
     *
     * @param bool $dry_run When true: counts operators/leads/emails without sending any email
     *                      or inserting any notification row. Returns result with dry_run=true.
     * @return array{processed_operators: int, emails_sent: int, leads_covered: int, skipped_rate_limit: int, skipped_threshold: int, dry_run: bool}
     */
    public static function run_bootstrap( bool $dry_run = false ): array {
        $settings = self::get_settings();

        $result = array(
            'processed_operators' => 0,
            'emails_sent'         => 0,
            'leads_covered'       => 0,
            'skipped_rate_limit'  => 0,
            'skipped_threshold'   => 0,
            'dry_run'             => $dry_run,
        );

        // Group all pending leads by assigned operator.
        $groups = self::pending_leads_by_operator();

        if ( empty( $groups ) ) {
            if ( ! $dry_run ) {
                self::record_bootstrap_run( $result );
            }
            return $result;
        }

        foreach ( $groups as $operator_id => $leads ) {
            $result['processed_operators']++;

            $operator_id    = (int) $operator_id;
            $first_lead     = reset( $leads );
            // COMPLIANCE_BY_INTEREST_V1: resolves via historical operator_assigned
            // event when a submission_id is available; falls back to the legacy
            // lead-level fields otherwise (identical to the prior direct read).
            $resolved       = self::operator_and_project_for_submission( $first_lead, $first_lead['submission_id'] ?? null );
            $operator_email = sanitize_email( $resolved['operator_email'] ?? '' );
            $operator_name  = sanitize_text_field( $resolved['operator_name'] ?? '' );

            if ( ! is_email( $operator_email ) ) {
                continue; // No valid email.
            }

            // Rate limit: max 1 email per operator per calendar day.
            // In dry-run mode we still apply this check to give an accurate preview.
            if ( self::operator_notified_today( $operator_id, $operator_email ) ) {
                $result['skipped_rate_limit']++;
                continue;
            }

            // Rule: if operator already has >DIGEST_THRESHOLD notified pending leads → skip.
            $already_pending_count = self::count_already_notified_pending( $operator_id );
            $lead_count            = count( $leads );

            if ( $already_pending_count > self::DIGEST_THRESHOLD ) {
                $result['skipped_threshold'] += $lead_count;
                continue;
            }

            if ( $dry_run ) {
                // Dry-run: count what would happen without sending anything.
                $result['emails_sent']++;
                $result['leads_covered'] += $lead_count;
                continue;
            }

            // Live run: classify and dispatch.
            $classified = self::classify_by_tier( $leads );

            if ( $lead_count > self::DIGEST_THRESHOLD ) {
                $sent = self::send_bootstrap_digest(
                    $operator_id,
                    $operator_name,
                    $operator_email,
                    $classified,
                    $settings
                );
                if ( $sent ) {
                    $result['emails_sent']++;
                    $result['leads_covered'] += $lead_count;
                }
            } else {
                foreach ( $leads as $lead ) {
                    $sent = self::send_bootstrap_individual( $lead, $settings );
                    if ( $sent ) {
                        $result['emails_sent']++;
                        $result['leads_covered']++;
                    }
                }
            }
        }

        if ( ! $dry_run ) {
            self::record_bootstrap_run( $result );
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Bootstrap: data retrieval
    // -------------------------------------------------------------------------

    /**
     * Load all pending leads (no feedback) grouped by assigned operator.
     * Returns array keyed by operator_id (or 0 for unassigned).
     *
     * @return array<int, array>
     */
    private static function pending_leads_by_operator(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT l.id, l.lead_uuid, l.name, l.email, l.phone,
                    l.project_slug, l.project_area, l.created_at, l.feedback_status,
                    l.assigned_operator_id,
                    o.name  AS operator_name,
                    o.email AS operator_email
             FROM {$wpdb->prefix}es_leads l
             LEFT JOIN {$wpdb->prefix}es_operators o ON o.id = l.assigned_operator_id
             WHERE l.feedback_completed = 0
             ORDER BY l.created_at ASC
             LIMIT " . self::BOOTSTRAP_BATCH_MAX,
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return array();
        }

        $groups = array();
        foreach ( $rows as $row ) {
            if ( class_exists( 'Estandar_Leads_QA_Policy' ) && Estandar_Leads_QA_Policy::is_test_lead( (int) ( $row['id'] ?? 0 ) ) ) {
                continue;
            }
            $key = (int) ( $row['assigned_operator_id'] ?? 0 );
            $groups[ $key ][] = $row;
        }

        return $groups;
    }

    /**
     * COMPLIANCE_BY_INTEREST_V1 (D1/D2/D3, read-only resolver).
     *
     * Resolves operator + project for a single interest (submission_id) using
     * the historical operator_assigned event when it exists; falls back to the
     * lead-level legacy fields (assigned_operator_id / project_slug) when no
     * such event exists (submissions predating INTEREST_ASSIGNMENT_V1) or when
     * no submission_id is supplied. Ignores operator_reassigned entirely — it
     * carries no submission_id and is out of scope by design (D3).
     *
     * @param array    $lead          Row with at least: id, assigned_operator_id,
     *                                operator_name, operator_email, project_slug.
     * @param int|null $submission_id
     * @return array{operator_id:int, operator_name:string, operator_email:string, project_slug:string, source:string}
     */
    public static function operator_and_project_for_submission( array $lead, $submission_id = null ): array {
        $legacy = array(
            'operator_id'    => (int) ( $lead['assigned_operator_id'] ?? 0 ),
            'operator_name'  => (string) ( $lead['operator_name'] ?? '' ),
            'operator_email' => (string) ( $lead['operator_email'] ?? '' ),
            'project_slug'   => (string) ( $lead['project_slug'] ?? '' ),
            'source'         => 'legacy',
        );

        $submission_id = (int) $submission_id;
        $lead_id       = (int) ( $lead['id'] ?? 0 );
        if ( $submission_id <= 0 || $lead_id <= 0 ) {
            return $legacy; // D2: nothing to look up — no history to reconstruct.
        }

        $by_submission = Estandar_Leads_Repository::operators_by_submission( $lead_id );
        $row           = $by_submission[ $submission_id ] ?? null;
        if ( ! $row ) {
            return $legacy; // D2: pre-Fase-1 submission — use legacy model as-is.
        }

        return array(
            'operator_id'    => (int) $row->operator_id,
            'operator_name'  => (string) $row->operator_name,
            'operator_email' => (string) $row->operator_email,
            'project_slug'   => (string) $row->project_slug,
            'source'         => 'interest',
        );
    }

    /**
     * Classify a flat list of leads into age tiers.
     *
     * @param  array $leads
     * @return array{recent: array, stale: array, archived: array}
     */
    private static function classify_by_tier( array $leads ): array {
        $tiers = array(
            self::TIER_RECENT   => array(),
            self::TIER_STALE    => array(),
            self::TIER_ARCHIVED => array(),
        );

        foreach ( $leads as $lead ) {
            $days = (int) floor( self::hours_since( $lead['created_at'] ) / 24 );
            if ( $days <= 30 ) {
                $tiers[ self::TIER_RECENT ][] = $lead;
            } elseif ( $days <= 90 ) {
                $tiers[ self::TIER_STALE ][] = $lead;
            } else {
                $tiers[ self::TIER_ARCHIVED ][] = $lead;
            }
        }

        return $tiers;
    }

    /**
     * Count leads for this operator that are already in the notifications table
     * AND still have feedback_completed = 0.
     */
    private static function count_already_notified_pending( int $operator_id ): int {
        if ( $operator_id === 0 ) {
            return 0;
        }
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT n.lead_id)
             FROM {$wpdb->prefix}" . self::NOTIF_TABLE . " n
             INNER JOIN {$wpdb->prefix}es_leads l ON l.id = n.lead_id
             WHERE n.operator_id = %d
               AND l.feedback_completed = 0",
            $operator_id
        ) );
    }

    /**
     * Check whether this operator received any compliance notification today.
     */
    private static function operator_notified_today( int $operator_id, string $operator_email ): bool {
        global $wpdb;

        $today = gmdate( 'Y-m-d' );

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}" . self::NOTIF_TABLE . "
             WHERE (operator_id = %d OR recipient_email = %s)
               AND DATE(sent_at) = %s
             LIMIT 1",
            $operator_id,
            $operator_email,
            $today
        ) );

        return $count > 0;
    }

    // -------------------------------------------------------------------------
    // Bootstrap: email dispatch
    // -------------------------------------------------------------------------

    /**
     * Send a single grouped digest email covering all leads for one operator.
     *
     * @param  int    $operator_id
     * @param  string $operator_name
     * @param  string $operator_email
     * @param  array  $classified       Leads keyed by tier.
     * @param  array  $settings         Compliance settings.
     * @return bool
     */
    private static function send_bootstrap_digest(
        int $operator_id,
        string $operator_name,
        string $operator_email,
        array $classified,
        array $settings
    ): bool {
        // Generate a signed token for each lead so the operator can click-through.
        $classified_with_tokens = array();
        $lead_ids               = array();

        foreach ( $classified as $tier => $leads ) {
            $classified_with_tokens[ $tier ] = array();
            foreach ( $leads as $lead ) {
                $lead_id = (int) $lead['id'];
                $token   = Estandar_Leads_Tokens::create( $lead_id, $operator_id );
                $lead['_eval_url']   = $token !== '' ? Estandar_Leads_Tokens::url( $token ) : '';
                $lead['_token_hash'] = $token !== '' ? Estandar_Leads_Tokens::hash( $token ) : '';
                $classified_with_tokens[ $tier ][] = $lead;
                $lead_ids[] = $lead_id;
            }
        }

        $total = array_sum( array_map( 'count', $classified_with_tokens ) );

        $email_settings = Estandar_Leads_Email::get_settings();
        $subject        = sprintf(
            '📋 %d lead%s pendiente%s de clasificación — resumen retroactivo',
            $total,
            $total !== 1 ? 's' : '',
            $total !== 1 ? 's' : ''
        );

        $es_operator_name       = $operator_name;
        $es_classified          = $classified_with_tokens;
        $es_total               = $total;
        $es_s                   = $email_settings;

        $html = Estandar_Leads_Email::render_template(
            'compliance-bootstrap-digest.php',
            compact( 'es_operator_name', 'es_classified', 'es_total', 'es_s' )
        );

        // Update feedback_status for all covered leads BEFORE email attempt.
        foreach ( $lead_ids as $lid ) {
            self::update_feedback_status( $lid, 'overdue', array(
                'triggered_by'     => 'bootstrap',
                'escalation_level' => 'bootstrap_digest',
                'operator_id'      => $operator_id ?: null,
            ) );
        }

        $sent = Estandar_Leads_Email::send(
            $operator_email,
            $subject,
            $html,
            $email_settings,
            array( 'Content-Type: text/html; charset=UTF-8' )
        );

        if ( ! $sent ) {
            self::telemetry( 'error', 'COMPLIANCE_BOOTSTRAP_DIGEST_FAILED',
                'Bootstrap digest email failed.',
                array( 'operator_id' => $operator_id, 'to' => $operator_email, 'lead_count' => $total )
            );
            return false;
        }

        // Record ONE notification entry covering the whole digest (only on success).
        $now = gmdate( 'Y-m-d H:i:s' );
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . self::NOTIF_TABLE,
            array(
                'lead_id'           => (int) ( $lead_ids[0] ?? 0 ), // Representative lead.
                'operator_id'       => $operator_id,
                'escalation_level'  => 'bootstrap_digest',
                'notification_type' => 'retroactive_digest',
                'recipient_email'   => $operator_email,
                'cc_emails'         => '',
                'token_hash'        => '',
                'is_retroactive'    => 1,
                'metadata'          => wp_json_encode( array(
                    'lead_ids'    => $lead_ids,
                    'lead_count'  => $total,
                    'tier_counts' => array(
                        self::TIER_RECENT   => count( $classified_with_tokens[ self::TIER_RECENT ] ?? array() ),
                        self::TIER_STALE    => count( $classified_with_tokens[ self::TIER_STALE ] ?? array() ),
                        self::TIER_ARCHIVED => count( $classified_with_tokens[ self::TIER_ARCHIVED ] ?? array() ),
                    ),
                ) ),
                'sent_at'           => $now,
                'created_at'        => $now,
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        self::telemetry( 'info', 'COMPLIANCE_BOOTSTRAP_DIGEST_SENT',
            'Bootstrap digest sent.',
            array( 'operator_id' => $operator_id, 'lead_count' => $total )
        );

        return true;
    }

    /**
     * Send a single retroactive reminder for one lead.
     *
     * @param  array $lead
     * @param  array $settings  Compliance settings.
     * @return bool
     */
    private static function send_bootstrap_individual( array $lead, array $settings ): bool {
        $lead_id     = (int) $lead['id'];
        $operator_id = (int) ( $lead['assigned_operator_id'] ?? 0 );
        $to          = sanitize_email( $lead['operator_email'] ?? '' );

        if ( ! is_email( $to ) ) {
            // Try supervisor as fallback.
            $to = sanitize_email( $settings['supervisor_email'] ?? '' );
        }

        if ( ! is_email( $to ) ) {
            return false;
        }

        $token      = Estandar_Leads_Tokens::create( $lead_id, $operator_id ?: null );
        $eval_url   = $token !== '' ? Estandar_Leads_Tokens::url( $token ) : '';
        $token_hash = $token !== '' ? Estandar_Leads_Tokens::hash( $token ) : '';

        $email_settings = Estandar_Leads_Email::get_settings();

        // Determine the retroactive level label from age.
        $days         = (int) floor( self::hours_since( $lead['created_at'] ) / 24 );
        $tier         = $days <= 30 ? self::TIER_RECENT : ( $days <= 90 ? self::TIER_STALE : self::TIER_ARCHIVED );
        $es_level     = self::LEVEL_48H; // Retroactive always renders as 48h visual.
        $es_lead      = $lead;
        $es_eval_url  = $eval_url;
        $es_s         = $email_settings;
        $es_hours_elapsed = self::hours_since( $lead['created_at'] );

        $html = Estandar_Leads_Email::render_template(
            'compliance-escalation.php',
            compact( 'es_level', 'es_lead', 'es_eval_url', 'es_s', 'es_hours_elapsed' )
        );
        $project_display = resolve_project_display_name( $lead['project_slug'] ?? '', $lead['project_area'] ?? '' );

        $subject = sprintf(
            '⏳ Clasificación pendiente%s — activación de seguimiento',
            $project_display !== '' ? ' · ' . $project_display : ''
        );

        // Update feedback_status BEFORE email — time-based, not delivery-based.
        self::update_feedback_status( $lead_id, 'overdue', array(
            'triggered_by'     => 'bootstrap',
            'escalation_level' => 'retroactive_reminder',
            'operator_id'      => $operator_id ?: null,
            'days_elapsed'     => $days,
        ) );

        $sent = Estandar_Leads_Email::send(
            $to,
            $subject,
            $html,
            $email_settings,
            array( 'Content-Type: text/html; charset=UTF-8' )
        );

        if ( ! $sent ) {
            return false;
        }

        // Notification record (email delivery audit — only on success).
        $now = gmdate( 'Y-m-d H:i:s' );
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . self::NOTIF_TABLE,
            array(
                'lead_id'           => $lead_id,
                'operator_id'       => $operator_id ?: null,
                'escalation_level'  => 'bootstrap_' . $tier,
                'notification_type' => 'retroactive_reminder',
                'recipient_email'   => $to,
                'cc_emails'         => '',
                'token_hash'        => $token_hash,
                'is_retroactive'    => 1,
                'metadata'          => wp_json_encode( array(
                    'age_tier'     => $tier,
                    'days_elapsed' => $days,
                ) ),
                'sent_at'           => $now,
                'created_at'        => $now,
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        return true;
    }

    // -------------------------------------------------------------------------
    // Bootstrap: state
    // -------------------------------------------------------------------------

    /**
     * Persist the bootstrap run result and timestamp.
     */
    const RUN_LOG_OPTION = 'estandar_leads_compliance_run_log';

    /**
     * Persist bootstrap run result and timestamp into the unified run log.
     */
    private static function record_bootstrap_run( array $result ): void {
        $log = self::get_run_log();
        $log['last_bootstrap_run_at'] = gmdate( 'Y-m-d H:i:s' );
        $log['last_bootstrap_result'] = $result;
        update_option( self::RUN_LOG_OPTION, $log, false );

        // Legacy option kept for back-compat.
        update_option( self::BOOTSTRAP_OPTION, array(
            'last_run_at' => $log['last_bootstrap_run_at'],
            'result'      => $result,
        ), false );
    }

    /**
     * Persist daily cron run result and optional error into the unified run log.
     *
     * @param array       $result
     * @param string|null $error  Exception message or null if clean run.
     */
    private static function record_cron_run( array $result, ?string $error = null ): void {
        $log = self::get_run_log();
        $log['last_cron_run_at']  = gmdate( 'Y-m-d H:i:s' );
        $log['last_cron_result']  = $result;
        if ( $error !== null ) {
            $log['last_error'] = sanitize_text_field( $error );
        }
        update_option( self::RUN_LOG_OPTION, $log, false );
    }

    /**
     * Return the unified run log, initialising missing keys.
     *
     * @return array{
     *   last_cron_run_at: string,
     *   last_cron_result: array,
     *   last_bootstrap_run_at: string,
     *   last_bootstrap_result: array,
     *   last_error: string
     * }
     */
    private static function get_run_log(): array {
        $defaults = array(
            'last_cron_run_at'      => '',
            'last_cron_result'      => array(),
            'last_bootstrap_run_at' => '',
            'last_bootstrap_result' => array(),
            'last_error'            => '',
        );
        $stored = get_option( self::RUN_LOG_OPTION, array() );
        return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
    }

    /**
     * Return the full run log for admin display.
     * Keys: last_cron_run_at, last_cron_result, last_bootstrap_run_at, last_bootstrap_result, last_error.
     */
    public static function last_run_info(): array {
        return self::get_run_log();
    }

    /**
     * Return bootstrap history (last run). Kept for back-compat.
     *
     * @return array{last_run_at: string, result: array}|null
     */
    public static function bootstrap_status(): ?array {
        $log = self::get_run_log();
        if ( $log['last_bootstrap_run_at'] === '' ) {
            return null;
        }
        return array(
            'last_run_at' => $log['last_bootstrap_run_at'],
            'result'      => $log['last_bootstrap_result'],
        );
    }

    /**
     * Age tier labels (for UI display).
     */
    public static function tier_labels(): array {
        return array(
            self::TIER_RECENT   => '0–30 días',
            self::TIER_STALE    => '31–90 días',
            self::TIER_ARCHIVED => '+90 días',
        );
    }
}
