<?php
/**
 * AI Context Builder — consolidate lead intelligence for AI analysis.
 *
 * Builds markdown export of lead, submission, session, attribution, analytics, compliance.
 * No schema modifications. Read-only over existing data.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_AI_Context {

    /**
     * EXPORT_TIMEZONE_V1 — display convention. Stored timestamps (UTC, gmdate)
     * are NEVER altered; key dates render with an explicit zone plus the
     * America/Santiago equivalent so no date in the export is ambiguous.
     */
    const EXPORT_TIMEZONE = 'America/Santiago';

    /**
     * Render a stored UTC timestamp with explicit timezone:
     *   "2026-07-14 03:10:00 UTC (2026-07-13 23:10 America/Santiago)"
     * Unparseable values render verbatim (historical data is never rewritten).
     */
    private static function ts_display( $value, string $empty = 'No disponible' ): string {
        $value = trim( (string) $value );
        if ( $value === '' || strtoupper( $value ) === 'NULL' || $value === '—' ) {
            return $empty;
        }
        try {
            $utc = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
        } catch ( Exception $e ) {
            return $value;
        }
        try {
            $local = $utc->setTimezone( new DateTimeZone( self::EXPORT_TIMEZONE ) );
            return $utc->format( 'Y-m-d H:i:s' ) . ' UTC (' . $local->format( 'Y-m-d H:i' ) . ' ' . self::EXPORT_TIMEZONE . ')';
        } catch ( Exception $e ) {
            return $utc->format( 'Y-m-d H:i:s' ) . ' UTC';
        }
    }

    /**
     * @param int    $lead_id
     * @param string $view 'commercial' (default) hides low-level technical events
     *                      from the Timeline; 'technical' includes everything so
     *                      no telemetry is lost in the technical export.
     */
    public static function build_for_lead( $lead_id, $view = 'commercial' ) {
        global $wpdb;
        $view    = ( $view === 'technical' ) ? 'technical' : 'commercial';
        $lead_id = absint( $lead_id );
        if ( ! $lead_id ) {
            return '';
        }

        $lead = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}es_leads WHERE id = %d",
            $lead_id
        ) );
        if ( ! $lead ) {
            return '';
        }

        // FASE 1: todas las submissions en orden cronológico (canon 1 lead → N submissions).
        $submissions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d ORDER BY created_at ASC",
            $lead_id
        ) );
        // Submission más reciente: base de Attribution/Analytics (comportamiento previo conservado).
        $submission = ! empty( $submissions ) ? end( $submissions ) : null;
        if ( $submission ) {
            reset( $submissions );
        }
        $canonical_project = self::canonical_project( $lead, $submission );

        $md = "# Commercial AI Context Export\n\n";
        $md .= "_Canonical engineering artifact — runtime trace for developers, debugging, audits, diagnostics and AI agents. Not intended for operators._\n\n";

        // ── 1. RUNTIME CONTEXT ───────────────────────────────────────────────
        $md .= "## 1. Runtime Context\n\n" . self::build_runtime_context( $lead, $submission ) . "\n";

        // ── 2. COMMERCIAL SNAPSHOT ───────────────────────────────────────────
        // Compact editorial index. Every value is already owned by the lead,
        // attribution or SLA/action renderers below; this introduces no state.
        $md .= "## 2. Commercial Snapshot\n\n" . self::build_commercial_snapshot( $lead, $submission ) . "\n";

        // ── 2. INPUT CONTEXT — everything the engine received; no interpretation
        $s2  = "### Lead\n\n";
        $s2 .= "- **Lead ID:** " . $lead->id . "\n";
        $s2 .= "- **UUID:** " . self::display_value( $lead->lead_uuid ?? '' ) . "\n";
        $s2 .= "- **Name:** " . self::display_value( self::prospect_display_name( $lead, $submission ) ) . "\n";
        $s2 .= "- **Email:** " . self::display_value( $lead->email ?? '' ) . "\n";
        $s2 .= "- **Phone:** " . self::display_value( class_exists( 'Estandar_Leads_Normalizer' ) ? Estandar_Leads_Normalizer::phone_display( $lead->phone ?? '' ) : ( $lead->phone ?? '' ) ) . "\n";
        $s2 .= "- **Project:** " . self::display_value( $canonical_project ) . "\n";
        $s2 .= "- **Operator:** " . self::display_value( $lead->assigned_operator_name ?? '' ) . "\n";
        $s2 .= "- **Status:** " . self::display_value( $lead->status ?? '' ) . "\n";
        $s2 .= "- **Quality:** " . self::display_value( $lead->quality ?? '' ) . "\n";
        $s2 .= "- **First Seen:** " . self::ts_display( $lead->first_seen_at ?? '' ) . "\n";
        $s2 .= "- **Last Seen:** " . self::ts_display( $lead->last_seen_at ?? '' ) . "\n";
        $s2 .= "- **Source Plugin:** " . self::display_value( ( $submission && ! empty( $submission->source_plugin ) ) ? $submission->source_plugin : '' ) . "\n";
        if ( $submission ) {
            $s2 .= "- **Submission ID:** " . $submission->id . "\n";
            $s2 .= "- **Session ID:** " . ( $submission->session_id ?: 'NULL' ) . "\n";
        }
        $s2 .= self::demote_headings( self::build_person_context( $lead ) );
        $s2 .= self::demote_headings( self::build_data_quality( $lead, $submission ) );
        $s2 .= self::business_context_lines( $submission );
        $s2 .= self::demote_headings( self::build_location_context( $submission ) );
        if ( ! empty( $submissions ) ) {
            $s2 .= "\n### Submissions\n\n";
            $s2 .= "_" . count( $submissions ) . " submission(s) · canon: 1 lead → N submissions_\n\n";
            foreach ( $submissions as $i => $sub ) {
                $s2 .= "#### Submission " . ( $i + 1 ) . " · ID " . $sub->id . "\n";
                $s2 .= "- **Date:** " . self::ts_display( $sub->submitted_at ?: $sub->created_at ?: '', 'NULL' ) . "\n";
                $s2 .= "- **Form:** " . ( $sub->source_form_id ?: 'NULL' ) . "\n";
                $s2 .= "- **Origin:** " . ( $sub->source_plugin ?: 'NULL' ) . "\n";
                $sub_project = self::canonical_project( $lead, $sub );
                $s2 .= "- **Project:** " . ( $sub_project !== '' ? $sub_project : 'NULL' ) . "\n";
                $form_fields = json_decode( (string) $sub->form_fields_json, true );
                if ( is_array( $form_fields ) ) {
                    foreach ( $form_fields as $fk => $fv ) {
                        if ( ! is_scalar( $fv ) ) { continue; }
                        $val = trim( (string) $fv );
                        if ( $val === '' ) { continue; }
                        if ( mb_strlen( $val ) > 200 ) { $val = mb_substr( $val, 0, 200 ) . '…'; }
                        $key_label = ucwords( str_replace( array( '_', '-' ), ' ', trim( str_replace( array( "\r", "\n" ), ' ', (string) $fk ) ) ) );
                        $val = str_replace( array( "\r", "\n" ), ' ', $val );
                        $s2 .= "  - " . $key_label . ": " . $val . "\n";
                    }
                }
            }
        }
        $s2 .= self::demote_headings( self::build_origin( $submission, $lead ) );
        $s2 .= self::demote_headings( self::build_seo_landing_context( $lead->id ?? 0 ) );
        $s2 .= self::demote_headings( self::build_initial_commercial_inference( $lead, $submission ) );
        $s2 .= "\n### Session\n\n" . self::build_session_block( $submission, $lead );
        $s2 .= "\n### Analytics\n\n" . self::build_analytics_quality_block( $submission );
        $s2 .= self::demote_headings( self::build_commercial_history_context( $lead ) );
        $s2 .= self::demote_headings( self::build_recurrent_context( $lead, $submissions ) );
        $s2 .= self::demote_headings( self::build_interest_evolution( $submissions ) );
        $s2 .= self::demote_headings( self::build_experience( $lead ) );
        // INTENT_VS_SALES_STAGE (Lead #478): lead_intent = necesidad capturada;
        // sales_stage = estado comercial posterior. Intent presente + stage
        // vacío = stage PENDING (no ausente, jamás inferido desde el intent).
        $ss_stage = trim( (string) ( $lead->sales_stage ?? '' ) );
        if ( $ss_stage === '' && trim( (string) ( $lead->lead_intent ?? '' ) ) !== '' ) {
            $ss_stage = 'PENDING';
        }
        $ss_fields = array_filter( array(
            'Sales stage'            => $ss_stage,
            'Lead intent'            => $lead->lead_intent ?: '',
            'First contact'          => $lead->first_contact_at ?: '',
            'Last commercial action' => isset( $lead->last_commercial_action ) ? ( $lead->last_commercial_action ?: '' ) : '',
            'Is valid lead'          => $lead->is_valid_lead === null ? '' : ( $lead->is_valid_lead ? 'TRUE' : 'FALSE' ),
            'Invalid reason'         => $lead->invalid_reason ?: '',
            'Would buy again'        => $lead->would_buy_again ?: '',
        ) );
        if ( ! empty( $ss_fields ) ) {
            $s2 .= "\n### Sales Stage\n\n";
            foreach ( $ss_fields as $ss_label => $ss_val ) {
                $s2 .= "- **" . $ss_label . ":** " . $ss_val . "\n";
            }
        }
        $s2 .= self::demote_headings( self::build_compliance_and_recovery( $lead ) );
        $md .= "## 3. Input Context\n\n" . $s2 . "\n";

        // ── 3. IDENTITY RESOLUTION ───────────────────────────────────────────
        $md .= "## 4. Identity Resolution\n\n" . self::build_identity_resolution( $lead, $submission ) . "\n";

        // ── 4. ENGINE PIPELINE ───────────────────────────────────────────────
        $pipeline = self::compute_engine_pipeline( $lead, $submissions, $submission );
        $md .= "## 5. Engine Pipeline\n\n" . self::render_engine_pipeline( $pipeline ) . "\n";

        // ── 5. DECISION LOG ──────────────────────────────────────────────────
        $s5  = self::demote_headings( self::build_commercial_policy( $lead_id ) );
        $s5 .= self::demote_headings( self::build_engine_decisions( $lead ) );
        $s5 .= self::demote_headings( self::build_scoring( $lead, $submissions ) );
        $s5 .= self::demote_headings( self::build_scoring_reasoning( $lead ) );
        $s5 .= self::demote_headings( self::build_behavior_signals( $lead ) );
        // Ads Feedback Observability — same resolver feeds export and panel
        // (single SSOT: Estandar_Leads_Ads_Feedback_Observability). Guarded so
        // standalone harnesses without the resolver keep working unchanged.
        if ( class_exists( 'Estandar_Leads_Ads_Feedback_Observability' ) ) {
            $s5 .= "\n" . Estandar_Leads_Ads_Feedback_Observability::render( $lead_id );
        }
        $md .= "## 6. Decision Log\n\n" . $s5 . "\n";

        // ── 6. EVENT TRACE — single merged chronological timeline ─────────────
        $_tl = self::build_timeline( $lead_id, $view ); // include automation: complete chronological history
        $_tl = preg_replace( '/^\n?## Timeline\n\n/', '', $_tl );
        $s6  = "### Timeline (merged chronological)\n\n_Timestamps: UTC (convert to " . self::EXPORT_TIMEZONE . " for local reading)._\n\n" . ( ltrim( (string) $_tl ) ?: "No events recorded.\n" );
        $s6 .= "\n### Automation (actor detail)\n\n" . self::build_automation_events( $lead_id, $view );
        $s6 .= self::demote_headings( self::build_operator_management( $lead_id, $lead ) );
        $md .= "## 7. Event Trace\n\n" . $s6 . "\n";

        // ── 7. STATE MACHINE ─────────────────────────────────────────────────
        $md .= "## 8. State Machine\n\n" . self::build_state_machine( $lead_id, $lead ) . "\n";

        // ── 8. DIAGNOSTICS ───────────────────────────────────────────────────
        $s8  = self::demote_headings( self::build_system_findings( $lead, $submissions ) );
        $s8 .= self::demote_headings( self::build_consistency_checks( $lead, $submission, (array) $submissions ) );
        $s8 .= self::demote_headings( self::build_data_provenance() );
        $s8 .= self::build_v51_diagnostics( $lead_id, $lead, (array) $submissions, $submission );
        $md .= "## 9. Diagnostics\n\n" . $s8 . "\n";

        // ── 9. RUNTIME METRICS ───────────────────────────────────────────────
        $md .= "## 10. Runtime Metrics\n\n" . self::build_runtime_metrics( $lead_id, $pipeline ) . "\n";

        // ── 10. RAW SOURCES ──────────────────────────────────────────────────
        $md .= "## 11. Raw Sources\n\n" . self::build_raw_sources( $lead, $submissions ) . "\n";

        return $md;
    }

    private static function demote_headings( string $md ): string {
        return preg_replace( '/^## /m', '### ', $md );
    }

    /** Editorial snapshot over existing canonical values; no new commercial inference. */
    private static function build_commercial_snapshot( $lead, $submission ): string {
        $created = (string) ( $lead->created_at ?? ( $lead->first_seen_at ?? '' ) );
        $elapsed = class_exists( 'Estandar_Leads_Compliance' )
            ? Estandar_Leads_Compliance::hours_since_public( $created )
            : self::hours_since( $created );
        $no_contact = self::operator_contact_missing( $lead );
        $next_actions = self::next_actions_for( $lead );
        $sla_status = self::sla_status( $lead );
        $lead_id = (int) ( $lead->id ?? 0 );
        $sla_metrics = self::operator_response_sla_metrics( $lead_id, $created );

        $rows = array(
            'Lead' => self::display_value( self::prospect_display_name( $lead, $submission ) ),
            'Status' => self::display_value( $lead->status ?? '' ),
            'Project' => self::display_value( self::canonical_project( $lead, $submission ) ),
            'Operator' => self::display_value( $lead->assigned_operator_name ?? '' ),
            'Channel' => self::display_value( self::derive_channel( $submission ) ),
            'Temperature' => self::display_value( $lead->temperature ?? '' ),
            'Priority' => self::display_value( $lead->priority ?? '' ),
            'Next Action' => empty( $next_actions ) ? 'Sin acciones pendientes.' : $next_actions[0],
            'Waiting for contact' => $no_contact ? $elapsed . 'h (no contact recorded)' : 'No — contact recorded',
            'Lead Age' => $elapsed . 'h since capture',
            'Operator SLA' => $sla_metrics['status_label'],
            'SLA' => $sla_status . ( $sla_status === 'excluded_test' ? '' : ' · ' . max( 0, 48 - $elapsed ) . 'h remaining' ),
            'Notification Status' => self::notification_status_summary( $lead_id ),
        );
        // LEAD_ASSESSMENT_V1 — one-line fit summary. Only rendered when the
        // assessment persisted a fit (absence = not assessed, never a state).
        $fit_value = trim( (string) ( $lead->commercial_fit ?? '' ) );
        if ( $fit_value !== '' ) {
            $rows['Fit (Assessment)'] = self::display_value( $fit_value )
                . ' · ' . self::display_value( $lead->fit_reason ?? '' )
                . ' · conf ' . ( ( (int) ( $lead->fit_confidence ?? 0 ) ) ?: 'n/a' )
                . ' · ' . self::display_value( $lead->fit_decision_source ?? '' );
        }
        $md = '';
        foreach ( $rows as $label => $value ) {
            $md .= '- **' . $label . ':** ' . $value . "\n";
        }
        return $md;
    }

    /**
     * ROUTING_NOTIFICATION_EXPLAINABILITY_V1 — one-line deterministic summary
     * of notification delivery for the Commercial Snapshot. Derived from the
     * same event family as §9 Notification Delivery (single cheap query, no
     * new state); does not duplicate that section's full detail, only points
     * to it. Absence of events renders as absence, never as "delivered".
     */
    private static function notification_status_summary( int $lead_id ): string {
        if ( $lead_id <= 0 ) {
            return 'No disponible';
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT event_type, metadata, created_at FROM {$wpdb->prefix}es_lead_events
              WHERE lead_id = %d
                AND event_type IN (
                    'operator_notified','operator_notification_deferred',
                    'operator_notification_consolidated','notification_skipped_by_policy',
                    'routing_unresolved','routing_skipped_by_policy'
                )
           ORDER BY created_at DESC, id DESC LIMIT 1",
            $lead_id
        ) );
        if ( ! $row ) {
            return 'No notification telemetry recorded';
        }
        // NOTIFICATION_POLICY_VS_DELIVERY_V1: uses the same canonical status
        // literals as diag_notification_delivery() — never a synonym like
        // "queued" for what is, on the evidence, `deferred_by_policy`.
        switch ( (string) $row->event_type ) {
            case 'operator_notified':
                return 'delivered — see Notification Delivery (§9)';
            case 'operator_notification_deferred':
                return 'deferred_by_policy (business hours gate) — see Notification Policy / Notification Delivery (§9)';
            case 'operator_notification_consolidated':
                return 'consolidated into an earlier notification — see Notification Delivery (§9)';
            case 'notification_skipped_by_policy':
                return 'skipped_by_policy — see Notification Policy (§9)';
            default:
                return 'not_attempted — routing blocked before delivery, see Notification Policy (§9)';
        }
    }

    private static function build_need( $lead, $submissions, $submission ): string {
        $md = "\n## Need\n\n";
        $md .= self::business_context_lines( $submission );
        $md .= self::demote_headings( self::build_location_context( $submission ) );
        $md .= self::demote_headings( self::build_behavior_signals( $lead ) );
        return $md;
    }

    private static function business_context_lines( $submission ): string {
        $bc_exclude = array(
            'name', 'nombre', 'lastname', 'apellido', 'email', 'phone', 'telefono',
            'project', 'proyecto', 'project_slug',
            'region', 'región', 'city', 'ciudad', 'comuna', 'commune', 'location', 'ubicacion', 'ubicación',
            'uuid', 'session_id', 'submission_id', 'entry_id', 'created_at', 'updated_at',
            'gclid', 'fbclid', 'wbraid', 'gbraid',
            'ip', 'user_agent', 'nonce', 'referer',
        );
        $bc_fields = $submission ? json_decode( (string) $submission->form_fields_json, true ) : null;
        if ( ! is_array( $bc_fields ) ) {
            return "### Business Context\n\nNONE\n";
        }
        $lines = array();
        foreach ( $bc_fields as $bc_key => $bc_val ) {
            if ( ! is_scalar( $bc_val ) ) { continue; }
            $bc_raw_key = (string) $bc_key;
            $bc_norm    = strtolower( trim( $bc_raw_key ) );
            if ( $bc_norm === '' || $bc_norm[0] === '_' || strpos( $bc_norm, 'utm_' ) === 0 || in_array( $bc_norm, $bc_exclude, true ) ) {
                continue;
            }
            $bc_value = trim( str_replace( array( "\r\n", "\r", "\n" ), ' ', (string) $bc_val ) );
            if ( $bc_value === '' ) { continue; }
            if ( mb_strlen( $bc_value ) > 300 ) { $bc_value = mb_substr( $bc_value, 0, 300 ) . '…'; }
            $bc_value = self::normalize_location_value( $bc_norm, $bc_value );
            $bc_label = ucfirst( trim( str_replace( array( '_', '-' ), ' ', $bc_raw_key ) ) );
            $lines[]  = "- **" . $bc_label . ":** " . $bc_value;
        }
        return "### Business Context\n\n" . ( empty( $lines ) ? "NONE\n" : implode( "\n", array_unique( $lines ) ) . "\n" );
    }

    private static function build_origin( $submission, $lead = null ): string {
        $md = "\n## Origin\n\n";
        if ( ! $submission ) {
            return $md . "NULL\n";
        }
        $session_attr = self::analytics_attribution_for_session( (string) ( $submission->session_id ?? '' ) );
        $campaign = self::campaign_display( (string) ( $submission->utm_campaign ?? '' ), $session_attr );
		$google_ads = self::google_ads_attribution_for_submission( $submission, $session_attr );
        $landing = self::canonical_landing_for( $lead ?: (object) array(), $submission, $session_attr );

        // META_ATTRIBUTION_STATUS_V1: the status is platform-aware. A Meta
        // acquisition with valid evidence (fbclid/fbc/UTM Meta + resolved
        // campaign) must never render "unavailable" — that string belongs to
        // the Google ValueTrack block when granular fields are absent.
        $attribution_status = (string) $google_ads['status'];
        $channel = class_exists( 'Estandar_Leads_Attribution' )
            ? Estandar_Leads_Attribution::resolve_channel( $submission )
            : '';
        if ( $channel === 'meta_ads' ) {
            $meta_attr = self::meta_attribution_status( $submission, $campaign );
            $attribution_status = $meta_attr['status']
                . ( $meta_attr['evidence'] !== '' ? ' (evidence: ' . $meta_attr['evidence'] . ' · provenance: ' . $meta_attr['provenance'] . ')' : '' );
        }
        $rows = array(
            'Landing (canonical)' => $landing['url'] !== ''
                ? $landing['url'] . ' (source: ' . $landing['source'] . ')'
                : 'NULL',
            'Source'      => self::display_value( $submission->utm_source ?? '' ),
            'Medium'      => self::display_value( $submission->utm_medium ?? '' ),
            'Campaign'    => $campaign['name'] !== '' ? $campaign['name'] : self::display_value( $campaign['id'] ),
			'Attribution Status' => $attribution_status,
			'Campaign ID'    => self::attribution_display( $google_ads, 'campaign_id' ),
			'Ad Group ID'    => self::attribution_display( $google_ads, 'adgroup_id' ),
			'Asset Group ID' => self::attribution_display( $google_ads, 'asset_group_id' ),
			'Creative ID'    => self::attribution_display( $google_ads, 'creative_id' ),
			'Keyword'        => self::attribution_display( $google_ads, 'keyword' ),
			'Match Type'     => self::attribution_display( $google_ads, 'match_type' ),
			'Network'        => self::attribution_display( $google_ads, 'network' ),
			'Device'         => self::attribution_display( $google_ads, 'device' ),
			'Placement'      => self::attribution_display( $google_ads, 'placement' ),
			'Campaign Type'  => self::attribution_display( $google_ads, 'campaign_type' ),
            'Content'     => self::display_value( $submission->utm_content ?? '' ),
            'GCLID'       => self::display_value( $submission->gclid ?? '' ),
            'GBRAID'      => self::display_value( $submission->gbraid ?? '' ),
            'WBRAID'      => self::display_value( $submission->wbraid ?? '' ),
            'FBCLID'      => self::display_value( $submission->fbclid ?? '' ),
            'Click IDs'    => implode( ', ', array_filter( array(
                (string) ( $submission->gclid ?? '' ),
                (string) ( $submission->gbraid ?? '' ),
                (string) ( $submission->wbraid ?? '' ),
                (string) ( $submission->fbclid ?? '' ),
            ) ) ) ?: 'NULL',
            'Source Plugin' => self::display_value( $submission->source_plugin ?? '' ),
        );
        foreach ( $rows as $label => $value ) {
            $md .= "- **" . $label . ":** " . $value . "\n";
        }
        return $md;
    }

    private static function build_activity( $lead_id, $lead, $submissions, $view ): string {
        $md  = "\n## Activity\n\n";
        $md .= self::demote_headings( self::build_commercial_history_context( $lead ) );
        $md .= self::demote_headings( self::build_recurrent_context( $lead, $submissions ) );
        $md .= self::demote_headings( self::build_operator_management( $lead_id, $lead ) );
        $md .= self::demote_headings( self::build_timeline( $lead_id, $view ) );
        $md .= self::demote_headings( self::build_interest_evolution( $submissions ) );
        return $md;
    }

    private static function build_compliance_and_recovery( $lead ): string {
        $md  = "\n### Compliance\n\n";
        $md .= "- **Assigned at:** " . self::ts_display( $lead->assigned_at ?: '', 'NULL' ) . "\n";
        $md .= "- **Feedback completed:** " . ( $lead->feedback_completed ? 'TRUE' : 'FALSE' ) . "\n";
        $md .= "- **Feedback status:** " . ( $lead->feedback_status ?: 'NULL' ) . "\n";
        $md .= self::demote_headings( self::build_compliance_sla( $lead ) );
        if ( class_exists( 'Estandar_Leads_Recovery' ) ) {
            $rec = Estandar_Leads_Recovery::get_recovery_signals( $lead->id );
            $md .= "\n### Recovery Signals\n\n";
            if ( empty( $rec['lost_reason'] ) && empty( $rec['high_interest_lost'] ) && empty( $rec['recovery_email_sent'] ) && empty( $rec['second_attempt_requested'] ) && empty( $rec['terminal_reopened'] ) ) {
                $md .= "No recovery signals.\n";
            } else {
                $md .= "- **lost_reason:** " . ( $rec['lost_reason'] !== '' ? $rec['lost_reason'] : 'NULL' ) . "\n";
                $md .= "- **high_interest_lost:** " . ( $rec['high_interest_lost'] ? 'TRUE' : 'FALSE' ) . "\n";
                $md .= "- **recovery_email_sent:** " . ( $rec['recovery_email_sent'] ? 'TRUE' : 'FALSE' ) . "\n";
                $md .= "- **second_attempt_requested:** " . ( $rec['second_attempt_requested'] ? 'TRUE' : 'FALSE' ) . "\n";
                $md .= "- **terminal_reopened:** " . ( ! empty( $rec['terminal_reopened'] ) ? 'TRUE' : 'FALSE' ) . "\n";
            }
        }
        return $md;
    }

    /** Decode score_components_json (ya cargado en $lead). */
    private static function decode_components( $lead ) {
        $c = json_decode( (string) ( $lead->score_components_json ?? '' ), true );
        return is_array( $c ) ? $c : array();
    }

	    private static function canonical_project( $lead, $submission = null ): string {
	        if ( class_exists( 'Estandar_Leads_Repository' ) ) {
	            $slug = Estandar_Leads_Repository::canonical_project( $lead, $submission );
	            return $slug === Estandar_Leads_Repository::UNRESOLVED_PROJECT ? '' : $slug;
	        }
	        return (string) ( $lead->project_slug ?? '' );
	    }

	    private static function analytics_events_count( string $session_id ): int {
	        global $wpdb;
	        $session_id = sanitize_text_field( $session_id );
	        if ( $session_id === '' ) {
	            return 0;
	        }
	        $old_suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
	        $count = (int) $wpdb->get_var( $wpdb->prepare(
	            "SELECT COUNT(*) FROM {$wpdb->prefix}ea_events WHERE session_id = %s",
	            $session_id
	        ) );
	        if ( $old_suppress !== null ) {
	            $wpdb->suppress_errors( $old_suppress );
	        }
	        return $count;
	    }

		    private static function analytics_attribution_for_session( string $session_id ): array {
	        global $wpdb;
	        $session_id = sanitize_text_field( $session_id );
	        $empty = array( 'campaign_id' => '', 'campaign_name' => '', 'landing_page' => '', 'adgroup_id' => '', 'asset_group_id' => '', 'creative_id' => '', 'keyword' => '', 'match_type' => '', 'network' => '', 'device' => '', 'placement' => '', 'campaign_type' => '' );
	        if ( $session_id === '' ) {
	            return $empty;
	        }
	        if ( function_exists( 'estandar_analytics_get_attribution_by_session' ) ) {
	            $attr = estandar_analytics_get_attribution_by_session( $session_id );
	            if ( is_array( $attr ) ) {
	                return array(
	                    'campaign_id'   => (string) ( $attr['campaign_id'] ?? '' ),
	                    'campaign_name' => (string) ( $attr['campaign_name'] ?? '' ),
	                    'landing_page'  => (string) ( $attr['landing_url'] ?? ( $attr['landing_page'] ?? '' ) ),
	                ) + array_intersect_key( $attr, array_flip( array( 'adgroup_id', 'asset_group_id', 'creative_id', 'keyword', 'match_type', 'network', 'device', 'placement', 'campaign_type' ) ) );
	            }
	        }
	        $old_suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
	        $session = $wpdb->get_row( $wpdb->prepare(
	            "SELECT campaign, campaign_id, landing_page FROM {$wpdb->prefix}ea_sessions WHERE session_id = %s LIMIT 1",
	            $session_id
	        ) );
	        if ( ! $session ) {
	            if ( $old_suppress !== null ) { $wpdb->suppress_errors( $old_suppress ); }
	            return $empty;
	        }
	        $campaign_id = (string) ( $session->campaign_id ?? '' );
	        $campaign    = (string) ( $session->campaign ?? '' );
	        $campaign_name = (string) $wpdb->get_var( $wpdb->prepare(
	            "SELECT campaign_name FROM {$wpdb->prefix}ea_ads_daily
	             WHERE campaign_name != '' AND (campaign_id = %s OR campaign_name = %s)
	             ORDER BY stat_date DESC, id DESC LIMIT 1",
	            $campaign_id,
	            $campaign
	        ) );
	        if ( $old_suppress !== null ) {
	            $wpdb->suppress_errors( $old_suppress );
	        }
		        return array(
		            'campaign_id'   => $campaign_id,
		            'campaign_name' => $campaign_name,
		            'landing_page'  => (string) ( $session->landing_page ?? '' ),
		        );
		    }

		    private static function display_value( $value, string $empty = 'No disponible' ): string {
		        $value = trim( (string) $value );
		        return $value === '' || strtoupper( $value ) === 'NULL' || $value === '—' ? $empty : $value;
		    }

		private static function google_ads_attribution_for_submission( $submission, array $session_attr ): array {
			$fields = array( 'campaign_id', 'adgroup_id', 'asset_group_id', 'creative_id', 'keyword', 'match_type', 'network', 'device', 'placement', 'campaign_type' );
			$context = json_decode( (string) ( $submission->context_json ?? '' ), true );
			$explicit = is_array( $context['google_ads_attribution'] ?? null ) ? $context['google_ads_attribution'] : array();
			$explicit_provenance = is_array( $context['google_ads_attribution_provenance'] ?? null ) ? $context['google_ads_attribution_provenance'] : array();
			$out = array( 'values' => array(), 'provenance' => array(), 'status' => 'unavailable' );
			foreach ( $fields as $field ) {
				$value = trim( (string) ( $explicit[ $field ] ?? '' ) );
				if ( $value !== '' ) {
					$out['values'][ $field ] = $value;
					$out['provenance'][ $field ] = (string) ( $explicit_provenance[ $field ] ?? 'explicit_capture' );
					$out['status'] = 'explicit captured attribution';
					continue;
				}
				$value = trim( (string) ( $session_attr[ $field ] ?? '' ) );
				if ( $value !== '' ) {
					$out['values'][ $field ] = $value;
					$out['provenance'][ $field ] = 'analytics_session';
				}
			}
			if ( $out['status'] === 'unavailable' && ! empty( $out['values'] ) ) {
				$out['status'] = 'explicit captured attribution';
			}
			return $out;
		}

		/**
		 * META_ATTRIBUTION_STATUS_V1 — canonical Meta attribution status for a
		 * Meta-channel submission. Deterministic, evidence-first:
		 *   explicit_meta_attribution  fbclid or fbc captured (click evidence)
		 *   captured                   UTM Meta evidence and/or resolved campaign
		 *   unavailable                Meta channel without usable evidence
		 *
		 * @return array{status:string, evidence:string, provenance:string}
		 */
		private static function meta_attribution_status( $submission, array $campaign = array() ): array {
			$fbclid = trim( (string) ( $submission->fbclid ?? '' ) );
			$fbc    = trim( (string) ( $submission->meta_fbc ?? '' ) );
			if ( $fbclid !== '' || $fbc !== '' ) {
				return array(
					'status'     => 'explicit_meta_attribution',
					'evidence'   => $fbclid !== '' ? 'fbclid' : 'fbc',
					'provenance' => 'explicit_capture',
				);
			}
			$evidence = array();
			$source = strtolower( trim( (string) ( $submission->utm_source ?? '' ) ) );
			if ( in_array( $source, array( 'facebook', 'instagram', 'meta', 'fb', 'ig' ), true ) ) {
				$evidence[] = 'utm_source=' . $source;
			}
			if ( trim( (string) ( $campaign['id'] ?? '' ) ) !== '' || trim( (string) ( $campaign['name'] ?? '' ) ) !== '' ) {
				$evidence[] = 'campaign_resolved';
			}
			if ( ! empty( $evidence ) ) {
				return array(
					'status'     => 'captured',
					'evidence'   => implode( ' + ', $evidence ),
					'provenance' => 'utm_capture',
				);
			}
			return array( 'status' => 'unavailable', 'evidence' => '', 'provenance' => '' );
		}

		private static function attribution_display( array $attribution, string $field ): string {
			$value = trim( (string) ( $attribution['values'][ $field ] ?? '' ) );
			if ( $value === '' ) {
				return 'No disponible';
			}
			$provenance = trim( (string) ( $attribution['provenance'][ $field ] ?? '' ) );
			return $value . ( $provenance !== '' ? ' (provenance: ' . $provenance . ')' : '' );
		}

		    private static function campaign_display( string $utm_campaign, array $session_attr ): array {
		        $campaign_id = trim( (string) ( $session_attr['campaign_id'] ?? '' ) );
		        $campaign_name = trim( (string) ( $session_attr['campaign_name'] ?? '' ) );
		        $utm_campaign = trim( $utm_campaign );

		        if ( $campaign_id !== '' ) {
		            return array(
		                'id'   => $campaign_id,
		                'name' => ( $campaign_name !== '' && $campaign_name !== $campaign_id ) ? $campaign_name : '',
		            );
		        }

		        return array( 'id' => '', 'name' => $utm_campaign );
		    }

		    private static function looks_like_campaign_id( string $value ): bool {
		        $value = trim( $value );
		        return $value !== '' && (bool) preg_match( '/^(?:[0-9]{6,}|[a-z]{2,}[_-]?[0-9]{4,}|[0-9]+[_-][a-z0-9_-]+)$/i', $value );
		    }

		    private static function build_data_quality( $lead, $submission ): string {
		        $email = trim( (string) ( $lead->email ?? '' ) );
		        $phone_raw = trim( (string) ( $lead->phone ?? '' ) );
		        $phone = class_exists( 'Estandar_Leads_Normalizer' ) ? Estandar_Leads_Normalizer::phone_display( $phone_raw ) : $phone_raw;
		        $message = $submission ? trim( (string) ( $submission->message ?? '' ) ) : '';
		        $location = self::location_display( $submission );

		        $email_typo = $email !== '' ? self::email_typo_reason( $email ) : '';
		        $email_valid = function_exists( 'is_email' )
		            ? (bool) is_email( $email )
		            : (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
		        $email_ok = $email !== '' && $email_valid && $email_typo === '';
		        $phone_ok = $phone_raw !== '' && self::phone_quality_ok( $phone_raw );
		        $message_ok = $message !== '' && ! self::message_looks_noisy( $message );
		        $location_ok = $location !== '';

		        $rows = array(
		            'Email'    => array( $email_ok, self::display_value( $email ), $email === '' ? 'missing' : ( $email_ok ? '' : ( $email_typo !== '' ? $email_typo : 'invalid syntax' ) ) ),
		            'Phone'    => array( $phone_ok, self::display_value( $phone ), $phone_raw === '' ? 'missing' : ( $phone_ok ? '' : 'malformed' ) ),
		            'Message'  => array( $message_ok, self::display_value( self::short_text( $message, 160 ) ), $message === '' ? 'optional · not provided' : ( $message_ok ? '' : 'probable test/noise' ) ),
		            'Location' => array( $location_ok, self::display_value( $location ), $location_ok ? '' : 'optional · not provided' ),
		        );

		        $md = "\n## Data Quality\n\n";
		        foreach ( $rows as $label => $row ) {
		            $md .= "- **" . $label . ":** " . ( $row[0] ? 'PASS' : 'WARNING' ) . " · " . $row[1] . ( $row[2] !== '' ? " · " . $row[2] : '' ) . "\n";
		        }
		        return $md;
		    }

		    private static function phone_quality_ok( string $phone ): bool {
		        $digits = class_exists( 'Estandar_Leads_Normalizer' ) ? Estandar_Leads_Normalizer::phone_storage( $phone ) : preg_replace( '/\D+/', '', $phone );
		        return (bool) preg_match( '/^56\d{9}$/', (string) $digits );
		    }

		    private static function email_typo_reason( string $email ): string {
		        $email = strtolower( trim( $email ) );
		        if ( $email === '' || strpos( $email, '@' ) === false ) {
		            return '';
		        }
		        $domain = substr( strrchr( $email, '@' ), 1 );
		        $typos = array(
		            'gmail.con' => 'probable typo: gmail.com',
		            'gmai.com' => 'probable typo: gmail.com',
		            'gmial.com' => 'probable typo: gmail.com',
		            'hotmai.com' => 'probable typo: hotmail.com',
		            'hotmail.con' => 'probable typo: hotmail.com',
		            'outlok.com' => 'probable typo: outlook.com',
		            'outlook.con' => 'probable typo: outlook.com',
		            'yaho.com' => 'probable typo: yahoo.com',
		            'yahoo.con' => 'probable typo: yahoo.com',
		        );
		        return $typos[ $domain ] ?? '';
		    }

		    private static function message_looks_noisy( string $message ): bool {
		        $m = strtolower( trim( $message ) );
		        if ( $m === '' ) {
		            return false;
		        }
		        if ( mb_strlen( $m ) < 4 ) {
		            return true;
		        }
		        return (bool) preg_match( '/^(test|testing|prueba|asdf|qwerty|na|n\/a|demo|hola|1234+|xxx+)$/u', $m );
		    }

		    private static function short_text( string $value, int $limit ): string {
		        $value = trim( preg_replace( '/\s+/', ' ', $value ) );
		        return mb_strlen( $value ) > $limit ? mb_substr( $value, 0, $limit ) . '…' : $value;
		    }

		    private static function location_display( $submission ): string {
		        if ( ! $submission ) {
		            return '';
		        }
		        $ctx = json_decode( (string) ( $submission->context_json ?? '' ), true );
		        $ctx = is_array( $ctx ) ? $ctx : array();
		        $fields = json_decode( (string) ( $submission->form_fields_json ?? '' ), true );
		        $fields = is_array( $fields ) ? $fields : array();
		        $region = self::normalize_location_value( 'region', self::first_scalar( array( $ctx, $fields ), array( 'region' ) ) );
		        $city = self::normalize_location_value( 'city', self::first_scalar( array( $ctx, $fields ), array( 'city', 'ciudad', 'comuna' ) ) );
		        return trim( implode( ', ', array_filter( array( $city, $region ) ) ) );
		    }

		    private static function normalize_location_value( string $key, string $value ): string {
	        $value = trim( $value );
	        if ( $value === '' || ! class_exists( 'Estandar_Forms_Dataset_Provider' ) ) {
	            return $value;
	        }
	        $dataset = '';
	        if ( $key === 'region' ) {
	            $dataset = 'cl_regions';
	        } elseif ( in_array( $key, array( 'city', 'ciudad', 'comuna' ), true ) ) {
	            $dataset = 'cl_regions_communes';
	        }
	        if ( $dataset === '' ) {
	            return $value;
	        }
	        foreach ( Estandar_Forms_Dataset_Provider::get( $dataset ) as $row ) {
	            if ( (string) ( $row['value'] ?? '' ) === $value ) {
	                return (string) ( $row['label'] ?? $value );
	            }
	        }
	        return $value;
	    }

	    private static function build_location_context( $submission ): string {
	        if ( ! $submission ) {
	            return '';
	        }
	        $ctx = json_decode( (string) ( $submission->context_json ?? '' ), true );
	        $ctx = is_array( $ctx ) ? $ctx : array();
	        $fields = json_decode( (string) ( $submission->form_fields_json ?? '' ), true );
	        $fields = is_array( $fields ) ? $fields : array();
	        $region = self::first_scalar( array( $ctx, $fields ), array( 'region' ) );
	        $city   = self::first_scalar( array( $ctx, $fields ), array( 'city', 'ciudad', 'comuna' ) );
	        $region = self::normalize_location_value( 'region', $region );
	        $city   = self::normalize_location_value( 'city', $city );
	        if ( $region === '' && $city === '' ) {
	            return '';
	        }
	        $md  = "\n## Location\n\n";
		        $md .= "- **Region:** " . self::display_value( $region ) . "\n";
		        $md .= "- **City:** " . self::display_value( $city ) . "\n";
	        return $md;
	    }

		    private static function build_initial_commercial_inference( $lead, $submission ): string {
		        $ctx = $submission ? json_decode( (string) ( $submission->context_json ?? '' ), true ) : array();
		        $ctx = is_array( $ctx ) ? $ctx : array();
		        $fields = $submission ? json_decode( (string) ( $submission->form_fields_json ?? '' ), true ) : array();
		        $fields = is_array( $fields ) ? $fields : array();
		        $initial_stage = self::first_scalar( array( $ctx, $fields ), array( 'initial_sales_stage', 'sales_stage_initial', 'commercial_stage_initial' ) );
		        if ( $initial_stage === '' ) {
		            $initial_stage = 'NEW';
		        }
		        $project = self::canonical_project( $lead, $submission );
		        $source_channel = self::derive_channel( $submission );
		        $md  = "\n## Initial Commercial Inference\n\n";
		        $md .= "- **Initial Stage:** " . self::display_value( $initial_stage ) . "\n";
		        $md .= "- **Source Channel:** " . self::display_value( $source_channel ) . "\n";
		        $md .= "- **Project:** " . self::display_value( $project ) . "\n";
		        return $md;
		    }

	    private static function first_scalar( array $sources, array $keys ): string {
	        foreach ( $sources as $source ) {
	            if ( ! is_array( $source ) ) {
	                continue;
	            }
	            foreach ( $keys as $key ) {
	                if ( isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ) {
	                    $value = trim( (string) $source[ $key ] );
	                    if ( $value !== '' ) {
	                        return $value;
	                    }
	                }
	            }
	        }
	        return '';
	    }

	    private static function build_compliance_sla( $lead ): string {
	        if ( class_exists( 'Estandar_Leads_QA_Policy' ) && Estandar_Leads_QA_Policy::is_test_lead( (int) ( $lead->id ?? 0 ) ) ) {
	            return "- **SLA status:** excluded_test\n";
	        }
	        $created = (string) ( $lead->created_at ?? ( $lead->first_seen_at ?? '' ) );
	        $elapsed = class_exists( 'Estandar_Leads_Compliance' )
	            ? Estandar_Leads_Compliance::hours_since_public( $created )
	            : self::hours_since( $created );
	        $due = 48;
	        $remaining = max( 0, $due - $elapsed );
	        $feedback_status = (string) ( $lead->feedback_status ?? '' );
	        if ( ! empty( $lead->feedback_completed ) || $feedback_status === 'received' ) {
	            $sla_status = 'complete';
	        } elseif ( $feedback_status === 'escalated' ) {
	            $sla_status = 'escalated';
	        } elseif ( $feedback_status === 'overdue' ) {
	            $sla_status = 'overdue';
	        } elseif ( $elapsed >= $due ) {
	            $sla_status = 'due_now';
	        } else {
	            $sla_status = 'within_sla';
	        }
	        $md  = "- **Lead age:** " . $elapsed . "h\n";
	        $md .= "- **SLA status:** " . $sla_status . "\n";
	        $md .= "- **SLA elapsed:** " . $elapsed . "h\n";
	        $md .= "- **SLA remaining:** " . $remaining . "h\n";

	        $lead_id = (int) ( $lead->id ?? 0 );
	        $sla_metrics = self::operator_response_sla_metrics( $lead_id, $created );
	        $md .= "- **Operator SLA status:** " . $sla_metrics['status_label'] . "\n";
	        $md .= "- **Operator SLA started at:** " . self::ts_display( $sla_metrics['started_at'] ) . "\n";
	        if ( $sla_metrics['queued_hours'] > 0 ) {
	            $md .= "- **Time queued (before delivery):** " . $sla_metrics['queued_hours'] . "h (reason: " . $sla_metrics['deferral_reason'] . ")\n";
	        }
	        $md .= "- **Operator SLA elapsed:** " . $sla_metrics['sla_elapsed_hours'] . "h\n";
	        $md .= "- **Operator SLA remaining:** " . $sla_metrics['sla_remaining_hours'] . "h\n";
	        return $md;
	    }

	    private static function sla_status( $lead ): string {
	        if ( class_exists( 'Estandar_Leads_QA_Policy' ) && Estandar_Leads_QA_Policy::is_test_lead( (int) ( $lead->id ?? 0 ) ) ) {
	            return 'excluded_test';
	        }
	        $created = (string) ( $lead->created_at ?? ( $lead->first_seen_at ?? '' ) );
	        $elapsed = class_exists( 'Estandar_Leads_Compliance' )
	            ? Estandar_Leads_Compliance::hours_since_public( $created )
	            : self::hours_since( $created );
	        $feedback_status = (string) ( $lead->feedback_status ?? '' );
	        if ( ! empty( $lead->feedback_completed ) || $feedback_status === 'received' ) {
	            return 'complete';
	        }
	        if ( $feedback_status === 'escalated' || $feedback_status === 'overdue' ) {
	            return $feedback_status;
	        }
	        return $elapsed >= 48 ? 'due_now' : 'within_sla';
	    }

	    /**
	     * OPERATOR_RESPONSE_SLA_V1 — independent metric from lead_age.
	     *
	     * Returns a structured SLA timeline anchored to the first operator_notified
	     * event (real delivery), not lead capture. When a notification was deferred
	     * by operational hours, the SLA clock only starts at that delivery moment.
	     *
	     * @return array{status_label:string,started_at:string,queued_hours:int,deferral_reason:string,sla_elapsed_hours:int,sla_remaining_hours:int,sla_due_hours:int,captured_at:string,delivered_at:string,contacted_at:string}
	     */
	    private static function operator_response_sla_metrics( int $lead_id, string $captured_at = '' ): array {
	        global $wpdb;
	        $defaults = array(
	            'status_label'       => 'pending_delivery',
	            'started_at'         => '',
	            'queued_hours'       => 0,
	            'deferral_reason'    => '',
	            'sla_elapsed_hours'  => 0,
	            'sla_remaining_hours' => 48,
	            'sla_due_hours'      => 48,
	            'captured_at'        => $captured_at !== '' ? $captured_at : '',
	            'delivered_at'       => '',
	            'contacted_at'       => '',
	        );

	        if ( $lead_id < 1 ) {
	            return $defaults;
	        }

	        $events_table = $wpdb->prefix . 'es_lead_events';

	        $first_deferred = $wpdb->get_row( $wpdb->prepare(
	            "SELECT created_at, metadata FROM {$events_table}
	             WHERE lead_id = %d AND event_type = 'operator_notification_deferred'
	             ORDER BY created_at ASC LIMIT 1",
	            $lead_id
	        ) );

	        $first_notified = $wpdb->get_row( $wpdb->prepare(
	            "SELECT created_at, metadata FROM {$events_table}
	             WHERE lead_id = %d AND event_type = 'operator_notified'
	             ORDER BY created_at ASC LIMIT 1",
	            $lead_id
	        ) );

	        $contact_row = $wpdb->get_row( $wpdb->prepare(
	            "SELECT contacted_at FROM {$wpdb->prefix}es_lead_contacts
	             WHERE lead_id = %d ORDER BY contacted_at ASC LIMIT 1",
	            $lead_id
	        ) );

	        if ( $captured_at === '' ) {
	            $captured_at = (string) $wpdb->get_var( $wpdb->prepare(
	                "SELECT created_at FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
	                $lead_id
	            ) );
	        }

	        $defaults['captured_at'] = $captured_at !== '' ? $captured_at : '';

	        if ( $first_deferred ) {
	            $meta = json_decode( (string) ( $first_deferred->metadata ?? '' ), true );
	            $defaults['deferral_reason'] = is_array( $meta ) ? (string) ( $meta['reason'] ?? 'outside_operational_hours' ) : 'outside_operational_hours';
	        }

	        if ( $first_notified ) {
	            $defaults['delivered_at'] = (string) $first_notified->created_at;
	            $defaults['started_at']   = (string) $first_notified->created_at;

	            if ( $first_deferred ) {
	                $deferred_ts = strtotime( (string) $first_deferred->created_at );
	                $notified_ts = strtotime( (string) $first_notified->created_at );
	                if ( $deferred_ts && $notified_ts && $notified_ts > $deferred_ts ) {
	                    $defaults['queued_hours'] = (int) floor( ( $notified_ts - $deferred_ts ) / HOUR_IN_SECONDS );
	                }
	            }

	            $started_ts = strtotime( $defaults['started_at'] );
	            if ( $started_ts ) {
	                $defaults['sla_elapsed_hours'] = (int) floor( ( time() - $started_ts ) / HOUR_IN_SECONDS );
	                $defaults['sla_remaining_hours'] = max( 0, 48 - $defaults['sla_elapsed_hours'] );
	                $defaults['sla_due_hours'] = 48;

	                if ( $defaults['sla_elapsed_hours'] >= 48 ) {
	                    $defaults['status_label'] = 'due_now';
	                } else {
	                    $defaults['status_label'] = 'active · ' . $defaults['sla_remaining_hours'] . 'h remaining';
	                }
	            } else {
	                $defaults['status_label'] = 'delivered (unparseable timestamp)';
	            }
	        } else {
	            if ( $first_deferred ) {
	                $defaults['status_label'] = 'awaiting_delivery (deferred: ' . $defaults['deferral_reason'] . ')';
	            } else {
	                $defaults['status_label'] = 'pending_delivery';
	            }
	        }

	        if ( $contact_row && ! empty( $contact_row->contacted_at ) ) {
	            $defaults['contacted_at'] = (string) $contact_row->contacted_at;
	            $defaults['status_label'] = 'contact_registered';
	        }

	        return $defaults;
	    }

	    private static function hours_since( string $datetime ): int {
	        $ts = strtotime( $datetime );
	        return $ts ? max( 0, (int) floor( ( time() - $ts ) / HOUR_IN_SECONDS ) ) : 0;
	    }

    private static function build_recurrent_context( $lead, $submissions ): string {
        $count = is_array( $submissions ) ? count( $submissions ) : 0;
        if ( $count < 2 ) {
            return '';
        }

        global $wpdb;
        $prev_status = self::latest_terminal_status( absint( $lead->id ?? 0 ) );
        $prev_quality = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT previous_quality FROM {$wpdb->prefix}es_lead_classifications
             WHERE lead_id = %d AND previous_quality != ''
             ORDER BY classified_at DESC, id DESC LIMIT 1",
            absint( $lead->id ?? 0 )
        ) );
        $latest = end( $submissions );
        if ( $latest ) {
            reset( $submissions );
        }

        $md  = "\n## Recurrent Context\n\n";
        $md .= "- **Previous status:** " . ( $prev_status !== '' ? $prev_status : 'NULL' ) . "\n";
        $md .= "- **Previous quality:** " . ( $prev_quality !== '' ? $prev_quality : 'NULL' ) . "\n";
        $md .= "- **Total interests:** " . $count . "\n";
        $md .= "- **Last submission:** " . ( $latest ? ( $latest->submitted_at ?: $latest->created_at ?: 'NULL' ) : 'NULL' ) . "\n";
        return $md;
    }

    private static function build_person_context( $lead ): string {
        global $wpdb;

        $lead_id = absint( $lead->id ?? 0 );
        $where = array( 'id = %d' );
        $vals = array( $lead_id );
        foreach ( array( 'email_hash', 'phone_hash' ) as $field ) {
            $value = trim( (string) ( $lead->{$field} ?? '' ) );
            if ( $value !== '' ) {
                $where[] = "{$field} = %s";
                $vals[] = $value;
            }
        }
        if ( self::lead_column_exists( 'rut' ) ) {
            $rut = trim( (string) ( $lead->rut ?? '' ) );
            if ( $rut !== '' ) {
                $where[] = 'rut = %s';
                $vals[] = $rut;
            }
        }

        $leads = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, project_slug FROM {$wpdb->prefix}es_leads WHERE " . implode( ' OR ', $where ),
            $vals
        ) );
        $ids = array_values( array_unique( array_map( 'absint', wp_list_pluck( $leads ?: array(), 'id' ) ) ) );
        if ( empty( $ids ) ) {
            $ids = array( $lead_id );
        }

        $id_ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $subs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id IN ({$id_ph})",
            $ids
        ) );
        $customers = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_customers WHERE matched_lead_id IN ({$id_ph})",
            $ids
        ) );
        $projects = array();
        foreach ( $leads ?: array() as $row ) {
            $project = sanitize_key( (string) ( $row->project_slug ?? '' ) );
            if ( $project !== '' ) {
                $projects[ $project ] = true;
            }
        }

        $md  = "## PERSON\n\n";
        $md .= "- **Lead count:** " . count( $ids ) . "\n";
        $md .= "- **Submission count:** " . $subs . "\n";
        if ( count( $projects ) > 1 ) {
            $md .= "- **Projects involved:**\n";
            foreach ( array_keys( $projects ) as $project_slug ) {
                $md .= "  - " . $project_slug . "\n";
            }
        } else {
            $md .= "- **Projects:** " . ( ! empty( $projects ) ? implode( ', ', array_keys( $projects ) ) : 'NULL' ) . "\n";
        }
        $md .= "- **Customer:** " . ( $customers > 0 ? 'MATCHED' : 'UNMATCHED' ) . "\n\n";
        return $md;
    }

    private static function build_commercial_history_context( $lead ): string {
        global $wpdb;

        $prev_status = self::latest_terminal_status( absint( $lead->id ?? 0 ) );
        $prev_quality = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT previous_quality FROM {$wpdb->prefix}es_lead_classifications
             WHERE lead_id = %d AND previous_quality != ''
             ORDER BY classified_at DESC, id DESC LIMIT 1",
            absint( $lead->id ?? 0 )
        ) );
	        $contact = $wpdb->get_row( $wpdb->prepare(
            "SELECT contacted_at, outcome FROM {$wpdb->prefix}es_lead_contacts
             WHERE lead_id = %d ORDER BY contacted_at DESC, id DESC LIMIT 1",
            absint( $lead->id ?? 0 )
	        ) );
	        if ( $prev_status === '' && $prev_quality === '' && ! $contact ) {
	            // RC4 — distinguish "no history at all" from "customer activity but no
	            // operator activity yet". Never claim there is no history when
	            // submissions or events already exist for the lead.
	            $lead_id = absint( $lead->id ?? 0 );
	            $has_customer_activity = (int) $wpdb->get_var( $wpdb->prepare(
	                "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d",
	                $lead_id
	            ) ) > 0;
	            if ( ! $has_customer_activity ) {
	                $has_customer_activity = (int) $wpdb->get_var( $wpdb->prepare(
	                    "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d",
	                    $lead_id
	                ) ) > 0;
	            }
	            return $has_customer_activity
	                ? "## COMMERCIAL HISTORY\n\nNo commercial activity recorded.\n\n"
	                : "## COMMERCIAL HISTORY\n\nNo commercial history yet.\n\n";
	        }

	        $md  = "## COMMERCIAL HISTORY\n\n";
        $md .= "- **Previous status:** " . ( $prev_status !== '' ? $prev_status : 'NULL' ) . "\n";
        $md .= "- **Previous quality:** " . ( $prev_quality !== '' ? $prev_quality : 'NULL' ) . "\n";
        $md .= "- **Last contact:** " . ( $contact && ! empty( $contact->contacted_at ) ? $contact->contacted_at : 'NULL' ) . "\n";
        $md .= "- **Last outcome:** " . ( $contact && ! empty( $contact->outcome ) ? $contact->outcome : 'NULL' ) . "\n\n";
        return $md;
    }

    private static function lead_column_exists( string $column ): bool {
        global $wpdb;
	        static $columns = null;
	        if ( $columns === null ) {
	            $columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}es_leads", 0 );
	        }
	        return in_array( $column, $columns, true );
	    }

    /**
     * AI Summary — estado semántico compacto (sin IA, sin nuevas consultas).
     * No expone scores crudos; statements determinísticos.
     */
    private static function build_ai_summary( $lead, $submissions ) {
        $comp   = self::decode_components( $lead );
        $latest = ! empty( $submissions ) ? end( $submissions ) : null;

        // Source semántico.
        $source = '—';
        if ( $latest ) {
            $sp = (string) ( $latest->source_plugin ?? '' );
            if ( stripos( $sp, 'chatbot' ) !== false )   { $source = 'Chatbot'; }
            elseif ( stripos( $sp, 'forms' ) !== false ) { $source = 'Forms'; }
            elseif ( $sp !== '' )                        { $source = $sp; }
        }

        // Capture: COMPLETE / PARTIAL / MISSING (sin score crudo).
        $cap = $comp['capture'] ?? null;
        if ( $cap !== null && (float) $cap >= 100 )   { $capture = 'COMPLETE'; }
        elseif ( $cap !== null && (float) $cap > 0 )  { $capture = 'PARTIAL'; }
        else                                          { $capture = 'MISSING'; }

        // Interaction: ACTIVE / NONE (sin score crudo).
        $beh = $comp['behavior'] ?? null;
        $interaction = ( $beh !== null && (float) $beh > 0 ) ? 'ACTIVE' : 'NONE';

        // Pending (etapas incompletas).
        $no_contact   = self::operator_contact_missing( $lead );
        $exp          = json_decode( (string) ( $lead->experience_data_json ?? '' ), true );
        $exp_done     = is_array( $exp ) && ( ! empty( $exp['completed'] ) || ! empty( $exp['completed_at'] ) );
        $qual_done    = ! empty( $lead->quality );
        // COMPLIANCE_STATE_SSOT: el summary usa la MISMA fuente que el Engine
        // Pipeline — Compliance solo aparece pendiente cuando el engine no
        // está en PASS (activo o completo = PASS, jamás "pendiente").
        $comp_pending = self::compliance_engine_state( $lead )[0] !== 'PASS';

        $pending = array();
        if ( $no_contact )   { $pending[] = 'Contact'; }
        if ( ! $exp_done )   { $pending[] = 'Experience'; }
        if ( ! $qual_done )  { $pending[] = 'Qualification'; }
        if ( $comp_pending ) { $pending[] = 'Compliance'; }

        $lines   = array();
        $lines[] = 'Channel: ' . self::derive_channel( $latest );
        $lines[] = 'Capture Completeness: ' . $capture;
        $lines[] = 'Post-capture interaction: ' . $interaction;
        $lines[] = 'Pending: ' . ( empty( $pending ) ? 'NONE' : implode( ', ', $pending ) );

        return "## AI Summary\n\n" . implode( "\n", $lines ) . "\n\n";
    }

    /** Canal determinístico desde el resolver canónico de atribución. */
    private static function derive_channel( $submission ) {
        return Estandar_Leads_Attribution::channel_label( $submission );
    }

    /**
     * Machine Context — estado consolidado en tokens máquina (render-only).
     * Reusa columnas + score_components_json + experience_data_json. Sin cálculos nuevos.
     */
    private static function build_machine_context( $lead, $submissions ) {
        $comp = self::decode_components( $lead );
        $fmt  = static function ( $v ) { return ( $v === null || $v === '' ) ? 'NULL' : (string) $v; };

        $engine = defined( 'Estandar_Leads_Scoring::ENGINE_VERSION' ) ? Estandar_Leads_Scoring::ENGINE_VERSION : 'BEHAVIORAL_SCORE_V1';
        $schema = class_exists( 'Estandar_Leads_Schema' ) ? Estandar_Leads_Schema::SCHEMA_VERSION : 'NULL';

        $rows = array(
            'Lifecycle'   => self::lifecycle_stage( $lead ),
            'Status'      => $fmt( $lead->status ),
            'Evidence'    => self::score_confidence( $comp ),
            'Temperature' => $fmt( $lead->temperature ),
            'Priority'    => $fmt( $lead->priority ),
            'Engine'      => $engine,
            'Schema'      => $fmt( $schema ),
        );

        $md = "## Machine Context\n\n";
        foreach ( $rows as $k => $v ) {
            $md .= $k . ': ' . $v . "\n";
        }
        return $md . "\n";
    }

    /** Etapa de ciclo de vida determinística (primer cuello de botella del pipeline). */
    private static function lifecycle_stage( $lead ) {
        $no_contact = self::operator_contact_missing( $lead );
        if ( $no_contact ) { return 'awaiting_contact'; }
        $exp = json_decode( (string) ( $lead->experience_data_json ?? '' ), true );
        $exp_done = is_array( $exp ) && ( ! empty( $exp['completed'] ) || ! empty( $exp['completed_at'] ) );
        if ( ! $exp_done ) { return 'awaiting_experience'; }
        if ( empty( $lead->quality ) ) { return 'awaiting_classification'; }
        $fb = (string) ( $lead->feedback_status ?? '' );
        if ( $fb === 'overdue' || $fb === 'escalated' ) { return 'compliance_breach'; }
        if ( ! empty( $lead->high_interest_lost ) || ! empty( $lead->second_attempt_requested ) ) { return 'recovery'; }
        return 'complete';
    }

    /** Guarded high-intent rule label (harnesses may not load the profile class). */
    private static function high_intent_rule_label(): string {
        return class_exists( 'Estandar_Leads_Commercial_Profile' )
            ? Estandar_Leads_Commercial_Profile::HIGH_INTENT_RULE
            : 'commercial profile high-intent rule';
    }

    /**
     * COMPLIANCE_STATE_SSOT: única definición del estado del Compliance Engine
     * para pipeline, AI Summary y diagnósticos — un lead con compliance activo
     * (feedback 'pending') o completo es PASS; nunca se reporta "pendiente" en
     * un bloque cuando otro lo declara PASS.
     *
     * @return array{0:string,1:string} [PASS|WARNING|PENDING, reason]
     */
    private static function compliance_engine_state( $lead ): array {
        $fb = (string) ( $lead->feedback_status ?? '' );
        if ( ! empty( $lead->feedback_completed ) || $fb === 'received' ) { return array( 'PASS', 'feedback complete' ); }
        if ( $fb === 'overdue' || $fb === 'escalated' )                   { return array( 'WARNING', 'compliance ' . $fb ); }
        if ( $fb === 'pending' )                                          { return array( 'PASS', 'compliance active' ); }
        return array( 'PENDING', 'no compliance state' );
    }

    private static function operator_contact_missing( $lead ): bool {
        return ( (int) ( $lead->contact_attempts ?? 0 ) === 0 ) || empty( $lead->first_contact_at );
    }

    private static function latest_terminal_status( int $lead_id ): string {
        global $wpdb;

        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}es_lead_classifications
             WHERE lead_id = %d AND status IN ('INVALID','LOST')
             ORDER BY classified_at DESC, id DESC LIMIT 1",
            $lead_id
        ) );
    }

    private static function has_event( int $lead_id, string $type ): bool {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d AND event_type = %s",
            $lead_id,
            sanitize_key( $type )
        ) ) > 0;
    }

    private static function prospect_display_name( $lead, $submission ): string {
        $name = trim( sanitize_text_field( (string) ( $lead->name ?? '' ) ) );
        $fields = $submission ? json_decode( (string) ( $submission->form_fields_json ?? '' ), true ) : array();
        $fields = is_array( $fields ) ? $fields : array();

        foreach ( array( 'full_name', 'fullname', 'nombre_completo', 'nombre_y_apellido' ) as $key ) {
            if ( isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) ) {
                $full = trim( sanitize_text_field( (string) $fields[ $key ] ) );
                if ( $full !== '' ) {
                    return $full;
                }
            }
        }

        foreach ( array( 'apellido', 'apellidos', 'last_name', 'lastname', 'surname' ) as $key ) {
            if ( isset( $fields[ $key ] ) && is_scalar( $fields[ $key ] ) ) {
                $surname = trim( sanitize_text_field( (string) $fields[ $key ] ) );
                if ( $surname !== '' && $name !== '' && stripos( $name, $surname ) === false ) {
                    return trim( $name . ' ' . $surname );
                }
            }
        }

        return $name;
    }

    /**
     * Interest Evolution — replaces the former Submission History + Submission
     * Analysis blocks (redundant: both described the same per-submission
     * evolution). One compact block per submission (project, channel, campaign,
     * operator), chronological, last one marked (actual). Render-only; reuses
     * the already-loaded submissions and existing extraction helpers.
     */
    private static function build_interest_evolution( $submissions ) {
        if ( ! is_array( $submissions ) || count( $submissions ) < 2 ) {
            return '';
        }

        $last_index = count( $submissions ) - 1;
        $blocks = array();
        foreach ( array_values( $submissions ) as $i => $sub ) {
            $fields   = self::extract_submission_fields( $sub );
            $project  = trim( (string) ( $fields['project'] ?? '' ) );
            $operator = trim( (string) ( $fields['operator'] ?? '' ) );
            $campaign = trim( (string) ( $fields['campaign'] ?? '' ) );
            $channel  = self::submission_source_label( $sub );

            $label   = 'Interest #' . ( $i + 1 ) . ( $i === $last_index ? ' (actual)' : '' );
            $block   = $label . "\n\n";
            $block  .= "Proyecto\n" . ( $project !== '' ? $project : 'NULL' ) . "\n\n";
            $block  .= "Canal\n" . ( $channel !== '' ? $channel : 'NULL' ) . "\n\n";
            $block  .= "Campaña\n" . ( $campaign !== '' ? $campaign : 'NULL' ) . "\n\n";
            $block  .= "Operador\n" . ( $operator !== '' ? $operator : 'NULL' );
            $blocks[] = $block;
        }

        return "\n## Interest Evolution\n\n" . implode( "\n\n↓\n\n", $blocks ) . "\n";
    }

    /** Etiqueta de origen determinística por submission (Forms / Chatbot / otro). */
    private static function submission_source_label( $sub ): string {
        $sp = strtolower( (string) ( $sub->source_plugin ?? '' ) );
        if ( strpos( $sp, 'chatbot' ) !== false ) { return 'Chatbot'; }
        if ( strpos( $sp, 'forms' ) !== false )   { return 'Forms'; }
        if ( $sp !== '' )                          { return ucfirst( $sp ); }
        return 'Unknown';
    }

    /** Extrae campos comparables de una submission (form_fields_json + context_json + columnas). */
    private static function extract_submission_fields( $sub ) {
        $ff  = json_decode( (string) ( $sub->form_fields_json ?? '' ), true );
        $ff  = is_array( $ff ) ? $ff : array();
        $ctx = json_decode( (string) ( $sub->context_json ?? '' ), true );
        $ctx = is_array( $ctx ) ? $ctx : array();

        $pick = static function ( $arr, $keys ) {
            foreach ( $keys as $k ) {
                if ( isset( $arr[ $k ] ) && is_scalar( $arr[ $k ] ) && trim( (string) $arr[ $k ] ) !== '' ) {
                    return trim( (string) $arr[ $k ] );
                }
            }
            return '';
        };

        $project = class_exists( 'Estandar_Leads_Repository' ) ? Estandar_Leads_Repository::project_for_submission( $sub ) : (string) ( $sub->project_slug ?? '' );
        if ( class_exists( 'Estandar_Leads_Repository' ) && $project === Estandar_Leads_Repository::UNRESOLVED_PROJECT ) { $project = ''; }

        $intent = (string) ( $sub->lead_intent ?? '' );
        if ( $intent === '' ) { $intent = $pick( $ff, array( 'intent', 'intencion', 'lead_intent' ) ); }
        if ( $intent === '' && isset( $ctx['intent'] ) && is_scalar( $ctx['intent'] ) ) {
            $intent = trim( (string) $ctx['intent'] );
        }

        $operator = (string) ( $sub->assigned_operator_name ?? '' );
        if ( $operator === '' ) { $operator = (string) ( $sub->operator_name ?? '' ); }

        return array(
            'project'  => $project,
            'service'  => $pick( $ff, array( 'service', 'servicio', 'service_category', 'categoria', 'category' ) ),
            'urgency'  => $pick( $ff, array( 'urgency', 'urgencia' ) ),
            'intent'   => $intent,
            'email'    => $pick( $ff, array( 'email', 'your-email', 'correo', 'e-mail' ) ),
            'phone'    => $pick( $ff, array( 'phone', 'your-phone', 'telefono', 'tel', 'celular' ) ),
            'campaign' => (string) ( $sub->utm_campaign ?? '' ),
            'operator' => $operator,
        );
    }

    /**
     * SEMANTIC_STATUS_V1 (Lead #478) — vocabulario único de estados para todos
     * los renderers (panel, export y bulk pasan por build_for_lead):
     *   PASS    = completado con éxito
     *   PENDING = paso del ciclo de vida aún no alcanzado
     *   SKIPPED = intencionalmente no ejecutado (decisión de política)
     *   WARNING = degradado pero usable (evidencia ausente, captura intacta)
     *   FAIL    = operación ejecutada que falló o invariante roto
     * Los pasos de ciclo de vida (contacto/calificación/experiencia) son
     * PENDING mientras el lead no los alcanza — nunca FAIL sin fallo real.
     */
    private static function semantic_status( string $check, bool $ok, string $policy_state = '' ): string {
        if ( $ok ) { return 'PASS'; }
        if ( $policy_state === 'SKIPPED_BY_POLICY' ) { return 'SKIPPED (by policy)'; }
        if ( $policy_state === 'REVIEW_PENDING' )    { return 'PENDING (commercial review)'; }
        if ( in_array( $check, array( 'Commercial Contact', 'Qualification', 'Experience' ), true ) ) {
            return 'PENDING';
        }
        // Un lead sin ninguna submission rompe el invariante de captura.
        if ( 'Submission Linked' === $check ) { return 'FAIL'; }
        return 'WARNING';
    }

    /**
     * Consistency Checks — consistencia OPERACIONAL sobre datos ya cargados.
     * Sin validaciones de integridad técnica (UUID/phone/email). Render-only, sin consultas.
     */
    private static function build_consistency_checks( $lead, $submission, $submissions = array() ) {
        $comp = self::decode_components( $lead );
        $collision = self::identity_collision( $lead, (array) $submissions );
        $attr_notes = self::attribution_consistency_notes( $lead, $submission );

        $has_attr = $submission && (
            ! empty( $submission->utm_source ) || ! empty( $submission->utm_medium ) ||
            ! empty( $submission->utm_campaign ) || ! empty( $submission->gclid ) || ! empty( $submission->fbclid )
        );
        $exp = json_decode( (string) ( $lead->experience_data_json ?? '' ), true );
        $exp_done = is_array( $exp ) && ( ! empty( $exp['completed'] ) || ! empty( $exp['completed_at'] ) );
        $qual = array_key_exists( 'qualification', $comp ) ? $comp['qualification'] : null;
        $has_contact = ! self::operator_contact_missing( $lead );

        // Policy states: an intentional routing/notification skip is NOT an
        // operational failure — never render it as FAIL.
        $routing_skipped = self::has_event( (int) $lead->id, 'routing_skipped_by_policy' );
        $review_pending  = self::has_event( (int) $lead->id, 'qualification_review' );
        $policy_state    = $routing_skipped ? 'SKIPPED_BY_POLICY' : ( $review_pending ? 'REVIEW_PENDING' : '' );

        $checks = array(
            'Operator Assigned'     => ( ! empty( $lead->assigned_operator_id ) || ! empty( $lead->assigned_operator_name ) ),
            'Project Identified'    => self::canonical_project( $lead, $submission ) !== '',
            'Submission Linked'     => (bool) $submission,
            'Attribution Available' => (bool) $has_attr,
            'Scoring Available'     => ( ! empty( $comp ) || ( $lead->behavioral_score ?? null ) !== null ),
            'Analytics Available'   => ( $submission && ! empty( $submission->session_id ) ),
            'Commercial Contact'    => (bool) $has_contact,
            'Qualification'         => ( $qual !== null ),
            'Experience'            => (bool) $exp_done,
        );

        $maxlen = 0;
        foreach ( array_keys( $checks ) as $k ) { $maxlen = max( $maxlen, strlen( $k ) ); }

        $policy_aware = array( 'Operator Assigned', 'Commercial Contact' );
        $lines = array();
        foreach ( $checks as $label => $ok ) {
            $dots = str_repeat( '.', ( $maxlen + 4 ) - strlen( $label ) );
            $applies = ( $policy_state !== '' && in_array( $label, $policy_aware, true ) ) ? $policy_state : '';
            $lines[] = $label . ' ' . $dots . ' ' . self::semantic_status( $label, (bool) $ok, $applies );
        }
        if ( ! empty( $collision ) ) {
            $lines[] = '';
            $lines[] = '### Identity Collision Warning';
            $lines[] = '- Status: WARNING';
            $lines[] = '- Reason: Conflicting normalized identities consolidated on the same lead.';
            foreach ( $collision as $line ) { $lines[] = $line; }
        }
        // Contact Variation only when there is no real collision: a conflict
        // already classified as Identity Collision is never double-reported.
        $variation = empty( $collision ) ? self::contact_variation_notes( $lead, (array) $submissions ) : array();
        if ( ! empty( $variation ) ) {
            $lines[] = '';
            $lines[] = '### Contact Variation Notice';
            $lines[] = '- Status: INFO (contact details vary across submissions — not an identity conflict, not a failure)';
            foreach ( $variation as $line ) { $lines[] = $line; }
        }
        if ( ! empty( $attr_notes ) ) {
            $lines[] = '';
            $lines[] = '### Attribution Consistency Notice';
            $lines[] = '- Status: INFO (informational — not an identity conflict, not a failure)';
            foreach ( $attr_notes as $line ) { $lines[] = $line; }
        }
        return "## Consistency Checks\n\n" . implode( "\n", $lines ) . "\n\n";
    }

    /**
     * Normalized identity triple for one submission (render-side, reuses the
     * same normalizers the ingestion pipeline uses — cosmetic differences in
     * case, whitespace or phone formatting are erased before comparison).
     *
     * @return array{email:string, phone:string, name_key:string, name_raw:string}
     */
    private static function submission_identity( $sub ): array {
        $email_norm = trim( (string) ( $sub->email_normalized ?? '' ) );
        if ( $email_norm === '' ) {
            $email_raw = trim( (string) ( $sub->email ?? '' ) );
            $email_norm = class_exists( 'Estandar_Leads_Normalizer' )
                ? Estandar_Leads_Normalizer::email( $email_raw )
                : strtolower( $email_raw );
        }

        $phone_norm = trim( (string) ( $sub->phone_normalized ?? '' ) );
        if ( $phone_norm === '' ) {
            $phone_raw = trim( (string) ( $sub->phone ?? '' ) );
            $phone_norm = class_exists( 'Estandar_Leads_Normalizer' )
                ? Estandar_Leads_Normalizer::phone_storage( $phone_raw )
                : preg_replace( '/\D+/', '', $phone_raw );
        }

        $name_raw = trim( (string) ( $sub->name ?? '' ) );
        return array(
            'email'    => $email_norm,
            'phone'    => $phone_norm,
            'name_key' => strtolower( preg_replace( '/\s+/', ' ', $name_raw ) ),
            'name_raw' => $name_raw,
        );
    }

    /**
     * Identity Collision — REAL identity conflicts only. Canonical rule:
     *
     *   same normalized phone
     *   AND two or more relevant submissions
     *   AND (multiple distinct normalized names OR multiple distinct
     *        normalized emails)
     *
     * Submissions are grouped by normalized phone; a collision exists ONLY
     * when a shared phone carries conflicting names or emails. Everything
     * else is NOT a collision and is classified separately:
     *   - distinct phones with a consistent identity → Contact Variation
     *     (contact_variation_notes(), informational);
     *   - campaign / landing / utm / analytics / source / project / metadata
     *     differences → attribution observations
     *     (attribution_consistency_notes(), informational).
     * Identity is never mixed with attribution.
     *
     * @return array<int,string> report lines; empty = no collision.
     */
    private static function identity_collision( $lead, array $submissions ): array {
        if ( count( $submissions ) < 2 ) {
            return array();
        }

        $groups = array(); // phone_norm => ['count'=>n, 'emails'=>map, 'names'=>map]
        foreach ( $submissions as $sub ) {
            $identity = self::submission_identity( $sub );
            if ( $identity['phone'] === '' ) {
                continue; // no phone → cannot satisfy the shared-phone rule.
            }
            if ( ! isset( $groups[ $identity['phone'] ] ) ) {
                $groups[ $identity['phone'] ] = array( 'count' => 0, 'emails' => array(), 'names' => array() );
            }
            $groups[ $identity['phone'] ]['count']++;
            if ( $identity['email'] !== '' ) {
                $groups[ $identity['phone'] ]['emails'][ strtolower( $identity['email'] ) ] = $identity['email'];
            }
            if ( $identity['name_key'] !== '' ) {
                $groups[ $identity['phone'] ]['names'][ $identity['name_key'] ] = $identity['name_raw'];
            }
        }

        $out = array();
        foreach ( $groups as $phone => $group ) {
            if ( $group['count'] < 2 ) {
                continue;
            }
            if ( count( $group['emails'] ) <= 1 && count( $group['names'] ) <= 1 ) {
                continue;
            }
            $out[] = '- Shared phone: ' . $phone;
            if ( count( $group['emails'] ) > 1 ) {
                $out[] = '- Emails: ' . implode( ' | ', array_values( $group['emails'] ) );
            }
            if ( count( $group['names'] ) > 1 ) {
                $out[] = '- Names: ' . implode( ' | ', array_values( $group['names'] ) );
            }
        }
        if ( empty( $out ) ) {
            return array();
        }
        $out[] = '- Action: Manual review recommended.';
        return $out;
    }

    /**
     * Contact Variation — informational, NEVER a collision and never a
     * failure: contact details vary across submissions without any shared
     * phone carrying conflicting names/emails (e.g. the same person reachable
     * at two phones, or submissions consolidated by email with distinct
     * phones). Only rendered when identity_collision() is empty.
     *
     * @return array<int,string>
     */
    private static function contact_variation_notes( $lead, array $submissions ): array {
        if ( count( $submissions ) < 2 ) {
            return array();
        }

        $emails = array();
        $phones = array();
        $names  = array();
        foreach ( $submissions as $sub ) {
            $identity = self::submission_identity( $sub );
            if ( $identity['email'] !== '' ) {
                $emails[ strtolower( $identity['email'] ) ] = $identity['email'];
            }
            if ( $identity['phone'] !== '' ) {
                $phones[ $identity['phone'] ] = $identity['phone'];
            }
            if ( $identity['name_key'] !== '' ) {
                $names[ $identity['name_key'] ] = $identity['name_raw'];
            }
        }

        $out = array();
        if ( count( $phones ) > 1 ) {
            $out[] = '- Phones: ' . implode( ' | ', array_values( $phones ) );
        }
        if ( count( $emails ) > 1 ) {
            $out[] = '- Emails: ' . implode( ' | ', array_values( $emails ) );
        }
        if ( count( $names ) > 1 ) {
            $out[] = '- Names: ' . implode( ' | ', array_values( $names ) );
        }
        return $out;
    }

    /**
     * Attribution Consistency — informational observations (never a collision,
     * never a FAIL): landing page that does not reference the lead project,
     * or a submission campaign that matches neither the session campaign id
     * nor its resolved name.
     *
     * @return array<int,string>
     */
    private static function attribution_consistency_notes( $lead, $submission ): array {
        $out = array();
        $session_attr = array();
        if ( $submission && ! empty( $submission->session_id ) ) {
            $session_attr = self::analytics_attribution_for_session( (string) $submission->session_id );
        }

        $landing = self::canonical_landing_for( $lead, $submission, $session_attr );
        $landing_url = (string) $landing['url'];
        $landing_path = $landing_url !== '' ? (string) ( parse_url( $landing_url, PHP_URL_PATH ) ?: '' ) : '';
        $landing_slug = sanitize_key( trim( basename( rtrim( $landing_path, '/' ) ) ) );
        $lead_project = self::canonical_project( $lead, $submission );
        if ( $lead_project !== '' && $landing_slug !== '' && $landing_slug !== $lead_project ) {
            $out[] = '- Landing/Project: landing "' . $landing_path . '" does not reference project "' . $lead_project . '".';
        }

        $lead_campaign = trim( (string) ( ( $submission->utm_campaign ?? '' ) !== '' ? $submission->utm_campaign : ( $lead->first_utm_campaign ?? '' ) ) );
        $session_campaign_id = trim( (string) ( $session_attr['campaign_id'] ?? '' ) );
        $session_campaign_name = trim( (string) ( $session_attr['campaign_name'] ?? '' ) );
        if ( $lead_campaign !== '' && $session_campaign_id !== ''
            && $lead_campaign !== $session_campaign_id
            && ( $session_campaign_name === '' || $lead_campaign !== $session_campaign_name ) ) {
            $out[] = '- Campaign: submission "' . $lead_campaign . '" != session "' . $session_campaign_id . '"' . ( $session_campaign_name !== '' ? ' (' . $session_campaign_name . ')' : '' ) . '.';
        }

        return $out;
    }

    /**
     * LANDING_PRECEDENCE_V1 — canonical landing for display/diagnostics.
     * Delegates to Estandar_Leads_Attribution::canonical_landing() when loaded:
     * explicit (submission landing) > submission (lead first landing) >
     * analytics session > referrer > home fallback; a generic home URL never
     * replaces a more precise landing.
     *
     * @return array{url:string, source:string}
     */
    private static function canonical_landing_for( $lead, $submission, array $session_attr = array() ): array {
        $candidates = array(
            'explicit'   => trim( (string) ( $submission->landing_url ?? '' ) ),
            'submission' => trim( (string) ( $lead->first_landing_url ?? '' ) ),
            'session'    => trim( (string) ( $session_attr['landing_page'] ?? '' ) ),
            'referrer'   => trim( (string) ( $submission->referrer ?? '' ) ),
            'home'       => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '',
        );
        if ( class_exists( 'Estandar_Leads_Attribution' ) ) {
            return Estandar_Leads_Attribution::canonical_landing( $candidates );
        }
        foreach ( array( 'explicit', 'submission', 'session', 'referrer', 'home' ) as $source ) {
            if ( $candidates[ $source ] !== '' ) {
                return array( 'url' => $candidates[ $source ], 'source' => $source );
            }
        }
        return array( 'url' => '', 'source' => 'none' );
    }

    /** SEO_INTELLIGENCE_LEADS_V1 — local, aggregated landing context only. */
    private static function build_seo_landing_context( $lead_id ): string {
        if ( ! class_exists( 'Estandar_Leads_SEO_Intelligence' ) ) { return ''; }
        $context = Estandar_Leads_SEO_Intelligence::context_for_lead( absint( $lead_id ) );
        if ( ! in_array( $context['status'] ?? '', array( 'available', 'stale' ), true ) ) { return ''; }
        $metrics = $context['metrics']; $lines = array(
            '- **Landing normalizada:** ' . $context['normalized_landing_url'],
            '- **Período:** ' . $context['period']['from'] . ' a ' . $context['period']['to'] . ' (' . $context['period']['timezone'] . ')',
            '- **Clicks / Impressions / CTR / Posición media:** ' . (int) $metrics['clicks'] . ' / ' . (int) $metrics['impressions'] . ' / ' . number_format( (float) $metrics['ctr'] * 100, 1 ) . '% / ' . number_format( (float) $metrics['average_position'], 1 ),
            '- **Frescura:** ' . (int) $context['freshness']['age_days'] . ' días' . ( ! empty( $context['freshness']['stale'] ) ? ' (STALE)' : '' ),
            '- **Atribución:** Google Search Console · agregado por landing · exact query known: false',
            '- **Nota:** Datos agregados de la página; no representan necesariamente la búsqueda exacta de este prospecto.',
        );
        if ( ! empty( $context['top_queries'] ) ) { $lines[] = '- **Top queries agregadas:** ' . implode( ' · ', array_map( static function ( $query ) { return (string) $query['query']; }, $context['top_queries'] ) ); }
        return "### SEO Landing Context\n\n" . implode( "\n", $lines ) . "\n";
    }

    /** System findings — solo observaciones relevantes; sin líneas vacías. */
    private static function build_system_findings( $lead, $submissions ) {
        $f = array();
        if ( $lead->name && $lead->email && $lead->phone ) { $f[] = '✓ Contacto completo'; }
        $latest = ! empty( $submissions ) ? end( $submissions ) : null;
        if ( $latest ) { reset( $submissions ); }
        if ( self::canonical_project( $lead, $latest ) !== '' ) { $f[] = '✓ Proyecto identificado'; }
        if ( $lead->assigned_operator_id || $lead->assigned_operator_name ) { $f[] = '✓ Operador asignado'; }

        if ( $latest && ! empty( $latest->utm_campaign ) ) { $f[] = '✓ Campaña identificada'; }

        $classified  = ! empty( $lead->feedback_completed ) || ! empty( $lead->quality ) || ! empty( $lead->sales_stage );
        if ( ! $classified ) { $f[] = '⚠ Sin clasificación'; }

        $routing_skipped = self::has_event( (int) $lead->id, 'routing_skipped_by_policy' );
        $review_pending  = self::has_event( (int) $lead->id, 'qualification_review' );
        $has_contact = ! self::operator_contact_missing( $lead );
        if ( ! $has_contact && ! $routing_skipped && ! $review_pending ) { $f[] = '⚠ Sin contacto registrado'; }
        if ( $routing_skipped ) { $f[] = 'ℹ Routing omitido por política (decisión intencional, no es fallo)'; }
        if ( $review_pending ) { $f[] = 'ℹ Lead retenido para revisión comercial (política de calificación)'; }
        if ( self::has_event( (int) $lead->id, 'qualification_manual_override' ) ) { $f[] = 'ℹ Calificación con override manual (decisión de operador, autoritativa)'; }
        if ( ! empty( self::identity_collision( $lead, (array) $submissions ) ) ) { $f[] = '⚠ Identity Collision Warning'; }

        $comp = self::decode_components( $lead );
        if ( ! ( array_key_exists( 'qualification', $comp ) && $comp['qualification'] !== null ) ) { $f[] = '⚠ Qualification pendiente'; }

        $exp = json_decode( (string) ( $lead->experience_data_json ?? '' ), true );
        if ( ! ( is_array( $exp ) && ! empty( $exp['completed_at'] ) ) ) { $f[] = '⚠ Experience no completado'; }

        if ( empty( $f ) ) { return ''; }
        return "## System Findings\n\n" . implode( "\n", $f ) . "\n\n";
    }

    /** Scoring — solo valores almacenados (Total/Capture/Behavior/Qualification/Updated/Profile). Render-only. */
    private static function build_scoring( $lead, $submissions ) {
        $comp = self::decode_components( $lead );
        $md   = "\n## Scoring\n\n";
        if ( ( $lead->behavioral_score ?? null ) === null && empty( $comp ) ) {
            return $md . "MISSING\n";
        }
        $fmt  = static function ( $v ) { return ( $v === null || $v === '' ) ? 'NULL' : (string) $v; };
        $qual = array_key_exists( 'qualification', $comp ) ? $comp['qualification'] : null;

        $profile = 'NULL';
        $latest  = ! empty( $submissions ) ? end( $submissions ) : null;
        if ( $latest ) {
            $ctx = json_decode( (string) ( $latest->context_json ?? '' ), true );
            if ( is_array( $ctx ) && ! empty( $ctx['qualification']['profile_id'] ) ) {
                $profile = (string) $ctx['qualification']['profile_id'];
            }
        }

        // COMMERCIAL_PROFILE_V1: fallback al perfil derivado por el engine de
        // scoring cuando la submission no trae qualification.profile_id.
        if ( $profile === 'NULL' && trim( (string) ( $comp['profile_id'] ?? '' ) ) !== '' ) {
            $profile = (string) $comp['profile_id'];
        }

        $md .= "- **Total:** " . $fmt( $lead->behavioral_score ?? ( $comp['total'] ?? null ) ) . "\n";
        $md .= "- **Capture Completeness:** " . $fmt( $comp['capture'] ?? null ) . "\n";
        $md .= "- **Behavior:** " . $fmt( $comp['behavior'] ?? null ) . "\n";
        $md .= "- **Qualification:** " . ( $qual === null ? 'NULL' : (string) $qual ) . "\n";
        $md .= "- **Commercial Profile Score:** " . $fmt( $comp['profile'] ?? null ) . "\n";
        if ( ! empty( $comp['profile_high_intent'] ) ) {
            $md .= "- **Commercial Profile:** HIGH_INTENT (" . self::high_intent_rule_label() . ")\n";
        }
        if ( ( $comp['intent'] ?? null ) !== null ) {
            $md .= "- **Intent Score:** " . $fmt( $comp['intent'] ?? null ) . " (" . (string) ( $comp['intent_id'] ?? '' ) . ")\n";
        }
        $md .= "- **Updated:** " . $fmt( $lead->score_updated_at ) . "\n";
        $md .= "- **Profile:** " . $profile . "\n";
        return $md;
    }

    /**
     * COMMERCIAL_QUALIFICATION_V1 — Commercial Policy block.
     *
     * Exposes the qualification decision and every policy state as EXPLICIT
     * policy outcomes: a routing/notification skipped by policy is an
     * intentional decision, never an operational failure. Absence of policy
     * events = default policy (qualified / route / notify / allow).
     */
    private static function build_commercial_policy( $lead_id ) {
        global $wpdb;
        $md = "\n## Commercial Policy\n\n";

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_type, reason, metadata, created_at FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d
               AND event_type IN ('qualification_completed','qualification_manual_override','qualification_review','routing_skipped_by_policy','notification_skipped_by_policy','autoresponse_skipped_by_policy')
             ORDER BY created_at ASC, id ASC",
            absint( $lead_id )
        ) );
        $rows = is_array( $rows ) ? $rows : array();

        $qualification = null;
        $manual_meta   = null;
        $routing      = 'ROUTE (default policy)';
        $notification = 'NOTIFY (default policy)';
        $autoresponse = 'ALLOW (default policy)';
        $reason       = 'default_policy';

        foreach ( $rows as $row ) {
            $meta = json_decode( (string) ( $row->metadata ?? '' ), true );
            $meta = is_array( $meta ) ? $meta : array();
            $row_reason = trim( (string) ( $row->reason ?? ( $meta['policy_reason'] ?? '' ) ) );
            switch ( (string) $row->event_type ) {
                case 'qualification_completed':
                    if ( is_array( $meta['qualification'] ?? null ) ) {
                        $qualification = $meta['qualification'];
                    }
                    break;
                case 'qualification_manual_override':
                    if ( is_array( $meta['qualification'] ?? null ) ) {
                        $manual_meta = $meta;
                    }
                    break;
                case 'routing_skipped_by_policy':
                    $routing      = 'SKIPPED_BY_POLICY';
                    $notification = 'SKIPPED (routing skipped by policy)';
                    break;
                case 'qualification_review':
                    $routing      = 'REVIEW (held by policy)';
                    $notification = 'SKIPPED (pending commercial review)';
                    break;
                case 'notification_skipped_by_policy':
                    $notification = 'SKIPPED_BY_POLICY';
                    break;
                case 'autoresponse_skipped_by_policy':
                    $autoresponse = 'SKIPPED_BY_POLICY';
                    break;
            }
            if ( $row_reason !== '' ) {
                $reason = $row_reason;
            }
        }

        // MANUAL_OVERRIDE is authoritative: the effective decision and every
        // policy line reflect the operator decision, never the stale
        // automatic one — regardless of event chronology.
        $decision_source = is_array( $qualification ) ? 'SYSTEM (qualification engine)' : 'DEFAULT (no rules matched)';
        $manual_summary  = 'NONE';
        if ( is_array( $manual_meta ) ) {
            $qualification   = $manual_meta['qualification'];
            $decision_source = 'MANUAL_OVERRIDE (operator decision — authoritative)';
            $overridden      = array_filter( array_map( 'strval', (array) ( $manual_meta['overridden'] ?? array() ) ) );
            $manual_summary  = ! empty( $overridden ) ? 'fields: ' . implode( ', ', $overridden ) : 'APPLIED';

            $policy_labels = array( 'route' => 'ROUTE', 'review' => 'REVIEW', 'skip' => 'SKIP' );
            $routing = ( $policy_labels[ (string) ( $qualification['routing_policy'] ?? '' ) ] ?? 'ROUTE' ) . ' (manual override)';
            $notification = ( (string) ( $qualification['notification_policy'] ?? '' ) === 'notify' ? 'NOTIFY' : 'SKIPPED' ) . ' (manual override)';
            $autoresponse = ( (string) ( $qualification['autoresponse_policy'] ?? '' ) === 'allow' ? 'ALLOW' : 'SKIPPED' ) . ' (manual override)';
            $manual_reason = trim( (string) ( $qualification['policy_reason'] ?? '' ) );
            if ( $manual_reason !== '' ) {
                $reason = $manual_reason;
            }
        }

        if ( is_array( $qualification ) ) {
            $md .= "- **Qualification:** " . self::display_value( (string) ( $qualification['qualification'] ?? '' ) ) . "\n";
            $md .= "- **Admission Policy:** " . ( ! empty( $qualification['eligible'] ) ? 'ALLOWED' : 'DISALLOWED' ) . "\n";
            $md .= "- **Decision:** " . strtoupper( (string) ( $qualification['decision'] ?? '' ) ) . "\n";
            $md .= "- **Eligible:** " . ( ! empty( $qualification['eligible'] ) ? 'TRUE' : 'FALSE' ) . "\n";
            $matched = (array) ( $qualification['matched_rules'] ?? array() );
            $md .= "- **Matched Rules:** " . ( ! empty( $matched ) ? implode( ', ', $matched ) : 'NONE' ) . "\n";
            $sources = (array) ( $qualification['rule_sources'] ?? array() );
            if ( ! empty( $sources ) ) {
                $pairs = array();
                foreach ( $sources as $rule_id => $source ) { $pairs[] = $rule_id . ' (' . $source . ')'; }
                $md .= "- **Rule Sources:** " . implode( ', ', $pairs ) . "\n";
            } else {
                $md .= "- **Rule Sources:** NONE\n";
            }
            $md .= "- **Confidence:** " . (int) ( $qualification['confidence'] ?? 0 ) . "\n";
            $md .= "- **Review Required:** " . ( ! empty( $qualification['review_required'] ) ? 'TRUE' : 'FALSE' ) . "\n";
            if ( trim( (string) ( $qualification['invalid_reason'] ?? '' ) ) !== '' ) {
                $md .= "- **Invalid Reason:** " . (string) $qualification['invalid_reason'] . "\n";
            }
        } else {
            // ADMISSION_POLICY_V1: sin decisión del engine NO existe calificación —
            // la admisión por política nunca se rotula "qualified". QUALIFIED
            // queda reservado para la clasificación comercial real del operador.
            $md .= "- **Qualification:** PENDING (no operator qualification recorded)\n";
            $md .= "- **Admission Policy:** ALLOWED\n";
            $md .= "- **Admission Reason:** no exclusion rule matched\n";
        }
        $md .= "- **Decision Source:** " . $decision_source . "\n";
        $md .= "- **Manual Override:** " . $manual_summary . "\n";
        $md .= "- **Routing Decision:** " . $routing . "\n";
        $md .= "- **Notification Decision:** " . $notification . "\n";
        $md .= "- **Autoresponse Decision:** " . $autoresponse . "\n";
        $md .= "- **Policy Reason:** " . $reason . "\n";
        $md .= "\n_Policy skips are intentional decisions, not operational failures._\n";
        return $md;
    }

    /**
     * Engine Decisions — por qué el engine produjo cada estado derivado.
     * Solo valores ya disponibles ($lead + score_components_json). Sin cálculos nuevos, sin prosa.
     */
    private static function build_engine_decisions( $lead ) {
        $comp = self::decode_components( $lead );
        $cap  = $comp['capture'] ?? null;
        $beh  = $comp['behavior'] ?? null;
        $qual = array_key_exists( 'qualification', $comp ) ? $comp['qualification'] : null;
        $engine = defined( 'Estandar_Leads_Scoring::ENGINE_VERSION' ) ? Estandar_Leads_Scoring::ENGINE_VERSION : 'BEHAVIORAL_SCORE_V1';

        $sep = "\n--------------------------------\n\n";

        $md  = "\n## Engine Decisions\n\n";

        // Temperature.
        $md .= "Temperature\n" . ( $lead->temperature ? strtoupper( (string) $lead->temperature ) : 'NULL' ) . "\nReason\n";
        $md .= "Capture=" . ( $cap === null ? 'NULL' : (string) $cap ) . "\n";
        $md .= "Behavior=" . ( $beh === null ? 'NULL' : (string) $beh ) . "\n";
        $md .= "Qualification=" . ( $qual === null ? 'MISSING' : (string) $qual ) . "\n";

        $md .= $sep;

        // Priority.
        $md .= "Priority\n" . ( $lead->priority ? strtoupper( (string) $lead->priority ) : 'NULL' ) . "\nReason\n";
        $md .= ( ! empty( $comp['priority_reason'] ) ? (string) $comp['priority_reason'] : $engine . " thresholds" ) . "\n";

        $md .= $sep;

        // Lifecycle.
        $md .= "Lifecycle\n" . self::lifecycle_stage( $lead ) . "\nReason\n";
        $reasons    = array();
        $no_contact = self::operator_contact_missing( $lead );
        $exp        = json_decode( (string) ( $lead->experience_data_json ?? '' ), true );
        $exp_done   = is_array( $exp ) && ( ! empty( $exp['completed'] ) || ! empty( $exp['completed_at'] ) );
        $fb         = (string) ( $lead->feedback_status ?? '' );
        if ( $no_contact )             { $reasons[] = 'Commercial contact missing'; }
        if ( ! $exp_done )             { $reasons[] = 'Experience missing'; }
        if ( empty( $lead->quality ) ) { $reasons[] = 'Qualification missing'; }
        if ( $fb === 'overdue' || $fb === 'escalated' ) { $reasons[] = 'Compliance ' . $fb; }
        if ( ! empty( $lead->high_interest_lost ) || ! empty( $lead->second_attempt_requested ) ) { $reasons[] = 'Recovery signal present'; }
        if ( empty( $reasons ) )       { $reasons[] = 'All stages complete'; }
        foreach ( $reasons as $r ) { $md .= $r . "\n"; }

        return $md;
    }

    /**
     * Data Provenance — mapeo determinístico subsistema → fuente. Estático, sin consultas.
     */
    private static function build_data_provenance() {
        $rows = array(
            'Capture Completeness' => 'Lead',
            'Behavior'      => 'Lead Events',
            'Qualification' => 'Operator Feedback',
            'Experience'    => 'Experience Engine',
            'Compliance'    => 'Compliance Engine',
            'Recovery'      => 'Recovery Engine',
            'Analytics'     => 'Analytics Engine',
        );
        $md = "\n## Data Provenance\n\n";
        foreach ( $rows as $k => $v ) {
            $md .= $k . "\nSource: " . $v . "\n\n";
        }
        return $md;
    }

    /**
     * Behavior Signals — eventos que contribuyeron al Behavior score.
     * Solo desde score_components_json.behavior_detail (ya cargado). Sin invención, sin consultas.
     */
    private static function build_behavior_signals( $lead ) {
        $comp   = self::decode_components( $lead );
        $detail = is_array( $comp['behavior_detail'] ?? null ) ? $comp['behavior_detail'] : array();

        $counts = array();
        foreach ( $detail as $ev ) {
            $t = is_array( $ev ) ? (string) ( $ev['type'] ?? '' ) : (string) $ev;
            $t = trim( $t );
            if ( $t === '' ) { continue; }
            $counts[ $t ] = ( $counts[ $t ] ?? 0 ) + 1;
        }

        $md = "\n## Behavior Signals\n\n";
        if ( empty( $counts ) ) {
            return $md . "NONE\n";
        }
        foreach ( $counts as $t => $n ) {
            $md .= '- ' . $t . ( $n > 1 ? ' ×' . $n : '' ) . "\n";
        }
        return $md;
    }

    /**
     * Reasoning — por qué existe cada score. Determinístico, sin prosa.
     * Capture signals = campos de identidad presentes; Behavior = behavior_detail; Qualification = valor o MISSING.
     */
    private static function build_scoring_reasoning( $lead ) {
        $comp = self::decode_components( $lead );
        $mfmt = static function ( $v ) { return ( $v === null || $v === '' ) ? 'NULL' : (string) $v; };

        $cap_signals = array();
        if ( ! empty( $lead->name ) )  { $cap_signals[] = 'name'; }
        if ( ! empty( $lead->phone ) ) { $cap_signals[] = 'phone'; }
        if ( ! empty( $lead->email ) ) { $cap_signals[] = 'email'; }

        $detail = is_array( $comp['behavior_detail'] ?? null ) ? $comp['behavior_detail'] : array();
        $beh_counts = array();
        foreach ( $detail as $ev ) {
            $t = is_array( $ev ) ? (string) ( $ev['type'] ?? '' ) : (string) $ev;
            $t = trim( $t );
            if ( $t === '' ) { continue; }
            $beh_counts[ $t ] = ( $beh_counts[ $t ] ?? 0 ) + 1;
        }

        $qual = array_key_exists( 'qualification', $comp ) ? $comp['qualification'] : null;

        $md  = "\n## Score Breakdown\n\n";
        $md .= "Capture Completeness\n" . $mfmt( $comp['capture'] ?? null ) . "\nSignals:\n";
        if ( empty( $cap_signals ) ) {
            $md .= "- NONE\n";
        } else {
            foreach ( $cap_signals as $s ) { $md .= '- ' . $s . "\n"; }
        }

        $md .= "\nBehavior\n" . $mfmt( $comp['behavior'] ?? null ) . "\nSignals:\n";
        if ( empty( $beh_counts ) ) {
            $md .= "- NONE\n";
        } else {
            foreach ( $beh_counts as $t => $n ) { $md .= '- ' . $t . ( $n > 1 ? ' ×' . $n : '' ) . "\n"; }
        }

        $md .= "\nQualification\n";
        if ( $qual === null ) {
            $md .= "MISSING\nReason: No operator qualification found.\n";
        } else {
            $md .= (string) $qual . "\n";
        }

        // COMMERCIAL_PROFILE_V1: breakdown auditable (dimensión = nivel, puntos,
        // campo de origen). Solo cuando el componente existe.
        $profile_detail = is_array( $comp['profile_detail'] ?? null ) ? $comp['profile_detail'] : array();
        if ( ( $comp['profile'] ?? null ) !== null || ! empty( $profile_detail ) ) {
            $md .= "\nCommercial Profile\n" . $mfmt( $comp['profile'] ?? null ) . "\nSignals:\n";
            if ( empty( $profile_detail ) ) {
                $md .= "- NONE\n";
            } else {
                foreach ( $profile_detail as $signal ) {
                    if ( is_array( $signal ) && ! empty( $signal['reason'] ) ) {
                        $md .= '- ' . (string) $signal['reason'] . "\n";
                    }
                }
            }
            if ( ! empty( $comp['profile_high_intent'] ) ) {
                $md .= "High intent: TRUE (" . self::high_intent_rule_label() . ")\n";
            }
        }
        return $md;
    }

    /**
     * Missing Context — lista solo lo ausente. Render-only, sin consultas.
     */
    private static function build_missing_context( $lead ) {
        $comp = self::decode_components( $lead );
        $missing = array();

        $qual = array_key_exists( 'qualification', $comp ) ? $comp['qualification'] : null;
        if ( $qual === null && empty( $lead->quality ) ) { $missing[] = 'Qualification'; }

        $exp = json_decode( (string) ( $lead->experience_data_json ?? '' ), true );
        $exp_done = is_array( $exp ) && ( ! empty( $exp['completed'] ) || ! empty( $exp['completed_at'] ) );
        if ( ! $exp_done ) { $missing[] = 'Experience'; }

        if ( empty( $lead->last_commercial_action ) ) { $missing[] = 'Commercial Outcome'; }
        // INTENT_VS_SALES_STAGE (Lead #478): con intent capturado, el stage
        // vacío se reporta como PENDING en el bloque Sales Stage — listarlo
        // aquí como ausente sería contradictorio.
        if ( empty( $lead->sales_stage ) && trim( (string) ( $lead->lead_intent ?? '' ) ) === '' ) { $missing[] = 'Sales Stage'; }
        $md = "\n## Missing Context\n\n";
        if ( empty( $missing ) ) {
            return $md . "NONE\n";
        }
        foreach ( $missing as $m ) { $md .= '- ' . $m . "\n"; }
        return $md;
    }

    /** Derived: confidence = nº de componentes presentes (weights_used / fallback a valores). */
    private static function score_confidence( array $comp ) {
        $keys    = array( 'capture', 'qualification', 'behavior' );
        $wu      = is_array( $comp['weights_used'] ?? null ) ? $comp['weights_used'] : array();
        $present = 0;
        if ( ! empty( $wu ) ) {
            foreach ( $keys as $k ) {
                // Behavior siempre pondera (weight fijo 0.30); como evidencia solo
                // cuenta cuando hay señal real del prospecto (valor > 0).
                if ( 'behavior' === $k ) {
                    if ( (float) ( $comp['behavior'] ?? 0 ) > 0 ) { $present++; }
                    continue;
                }
                if ( (float) ( $wu[ $k ] ?? 0 ) > 0 ) { $present++; }
            }
        } else {
            foreach ( $keys as $k ) {
                if ( ( $comp[ $k ] ?? null ) !== null ) { $present++; }
            }
        }
        // Presentación: misma lógica de conteo, etiquetas Evidence Level.
        $labels = array(
            0 => 'Información inicial',
            1 => 'Inicial (33%)',
            2 => 'Parcial (66%)',
            3 => 'Completa (100%)',
        );
        return $labels[ $present ] ?? 'Información inicial';
    }

    /**
     * Lead Intelligence — estado operativo del lead (render-only).
     * Reusa score_components_json, experience_data_json, feedback_status. Sin cálculos nuevos.
     */
    private static function build_lead_intelligence( $lead, $submissions = array() ) {
        $comp = self::decode_components( $lead );
        // Real identity conflicts only (COMMERCIAL_QUALIFICATION_V1): landing or
        // campaign mismatches are attribution notes, never an identity collision.
        $panel_warnings = self::identity_collision( $lead, (array) $submissions );

        // Captura: 100 = Completa · >0 = Parcial · 0/null = Pendiente.
        $cap = $comp['capture'] ?? null;
        if ( $cap !== null && (float) $cap >= 100 )    { $cap_state = 'Completa'; }
        elseif ( $cap !== null && (float) $cap > 0 )   { $cap_state = 'Parcial'; }
        else                                           { $cap_state = 'Pendiente'; }

        // Comportamiento: >0 = En progreso · 0/null = Sin interacción.
        $beh = $comp['behavior'] ?? null;
        $beh_state = ( $beh !== null && (float) $beh > 0 ) ? 'En progreso' : 'Sin interacción';

        // Calificación: valor presente = Completa · null = Pendiente.
        $qual = array_key_exists( 'qualification', $comp ) ? $comp['qualification'] : null;
        $qual_state = ( $qual !== null ) ? 'Completa' : 'Pendiente';

        // Experiencia: completed = Completada · presente = En progreso · sin experience = Pendiente.
        $exp = json_decode( (string) ( $lead->experience_data_json ?? '' ), true );
        if ( is_array( $exp ) && ( ! empty( $exp['completed'] ) || ! empty( $exp['completed_at'] ) ) ) {
            $exp_state = 'Completada';
        } elseif ( is_array( $exp ) && ! empty( $exp ) ) {
            $exp_state = 'En progreso';
        } else {
            $exp_state = 'Pendiente';
        }

        // Compliance: pending = Activo · overdue|escalated = Escalado · received/otro = No requerido.
        $fb = (string) ( $lead->feedback_status ?? '' );
        if ( $fb === 'overdue' || $fb === 'escalated' ) { $comp_state = 'Escalado'; }
        elseif ( $fb === 'pending' )                    { $comp_state = 'Activo'; }
        else                                            { $comp_state = 'No requerido'; }

        // Solo el estado activo por componente (reduce ruido).
        $blocks = array(
            'Captura'        => $cap_state,
            'Comportamiento' => $beh_state,
            'Calificación'   => $qual_state,
            'Experiencia'    => $exp_state,
            'Compliance'     => $comp_state,
        );

        $md = "\n## Lead Intelligence\n\n";
        if ( ! empty( $panel_warnings ) ) {
            $md .= "⚠ Identity Collision Warning\n\n";
        }
        foreach ( $blocks as $label => $state ) {
            $md .= $label . "\n" . '✓ ' . $state . "\n\n";
        }
        return $md;
    }

    /**
     * Recommended Next Actions — siguiente cuello de botella operativo.
     * Pipeline ordenado: primera etapa incompleta → sus acciones, y stop.
     * Render-only; sin IA, sin consultas nuevas, máximo 5 acciones.
     */
    private static function build_next_actions( $lead ) {
        $actions = self::next_actions_for( $lead );
        $md = "\n## Action\n\n";
        if ( empty( $actions ) ) {
            return $md . "- Sin acciones pendientes.\n";
        }
        foreach ( $actions as $i => $action ) {
            $md .= ( $i + 1 ) . '. ' . $action . "\n";
        }
        return $md;
    }

    /** Same deterministic action ordering used by the snapshot and Action renderer. */
    private static function next_actions_for( $lead ): array {
        // 1 · Contacto comercial.
        $no_contact = self::operator_contact_missing( $lead );

        // 2 · Experience.
        $exp      = json_decode( (string) ( $lead->experience_data_json ?? '' ), true );
        $exp_done = is_array( $exp ) && ( ! empty( $exp['completed'] ) || ! empty( $exp['completed_at'] ) );

        // 3 · Qualification (clasificación).
        $qual_done = ! empty( $lead->quality );

        // 4 · Compliance.
        $fb             = (string) ( $lead->feedback_status ?? '' );
        $compliance_bad = ( $fb === 'overdue' || $fb === 'escalated' );

        // 5 · Recuperación.
        $recovery_avail = ! empty( $lead->high_interest_lost ) || ! empty( $lead->second_attempt_requested );
        $terminal_reopened = self::has_event( absint( $lead->id ?? 0 ), 'lead_operational_reopened' );

        if ( $terminal_reopened ) {
            $actions = array( 'Contactar nuevamente.', 'Verificar si la descalificación previa sigue vigente.', 'Reclasificar.' );
        } elseif ( $no_contact ) {
            $actions = array( 'Contactar por teléfono.', 'Registrar resultado del primer contacto.' );
        } elseif ( ! $exp_done ) {
            $actions = array( 'Completar Experience.' );
        } elseif ( ! $qual_done ) {
            $actions = array( 'Clasificar el lead.' );
        } elseif ( $compliance_bad ) {
            $actions = array( 'Resolver incumplimiento de seguimiento.', 'Contactar nuevamente al prospecto.' );
        } elseif ( $recovery_avail ) {
            $actions = array( 'Ejecutar Recovery.', 'Registrar resultado.' );
        } else {
            $actions = array();
        }

        return array_slice( $actions, 0, 5 );
    }

    /** Experience — render-only desde experience_data_json. */
	    private static function build_experience( $lead ) {
	        $exp = json_decode( (string) ( $lead->experience_data_json ?? '' ), true );
	        $md  = "\n## Experience\n\n";
	        if ( ! is_array( $exp ) || empty( $exp['completed_at'] ) ) {
	            return $md . "No experience data yet.\n";
	        }
        $md .= "- **Completed:** Sí\n";
        $md .= "- **Qualification Profile:** " . ( $exp['experience_slug'] ?? '—' ) . "\n";
        $answers = is_array( $exp['answers'] ?? null ) ? $exp['answers'] : array();
        if ( ! empty( $answers ) ) {
            $md .= "- **Answers:**\n";
            foreach ( $answers as $k => $v ) {
                if ( ! is_scalar( $v ) ) { continue; }
                $md .= "  - " . $k . ": " . ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string) $v ) . "\n";
            }
        } else {
            $md .= "- **Answers:** —\n";
        }
        $md .= "- **Completion Date:** " . ( $exp['completed_at'] ?? '—' ) . "\n";
        return $md;
    }

    /**
     * FASE 1 · Timeline cronológico desde es_lead_events.
     * Read-only, sin schema. Formato compacto por línea.
     */
    private static function build_timeline( $lead_id, $view = 'commercial', $include_automation = true ) {
        global $wpdb;

        $allowed = array(
            'lead_created', 'operator_assigned', 'operator_notified', 'contact_attempt',
            'quality_changed', 'status_changed', 'commercial_stage_changed',
            'lead_recontacted', 'lead_whatsapp_opened', 'compliance_escalation',
            'lead_operational_reopened',
            'operator_daily_digest_sent',
        );

        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT created_at, event_type, from_status, to_status, reason, operator_id, metadata
               FROM {$wpdb->prefix}es_lead_events
              WHERE lead_id = %d
           ORDER BY created_at ASC, id ASC",
            $lead_id
        ) );
        if ( empty( $events ) ) {
            return '';
        }

        // Mapa operador id → nombre (una sola query).
        $op_names = array();
        $op_rows = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}es_operators" );
        foreach ( (array) $op_rows as $op ) {
            $op_names[ (int) $op->id ] = $op->name;
        }

        // Construir entradas (timestamp + "core" sin fecha) para poder colapsar.
        $entries = array();
        foreach ( $events as $ev ) {
            $type = (string) $ev->event_type;
            // Whitelist + prefijo autoresponse_* (when $include_automation is true).
            if ( ! in_array( $type, $allowed, true ) && ( ! $include_automation || strpos( $type, 'autoresponse_' ) !== 0 ) ) {
                continue;
            }
            // RC3 — commercial view hides low-level technical events (throttling,
            // internal retries, transport diagnostics). They remain available in
            // the technical export ($view === 'technical'); no telemetry is lost.
            if ( $view !== 'technical' && self::is_low_level_technical( $type ) ) {
                continue;
            }
            $meta = json_decode( (string) ( $ev->metadata ?? '' ), true );
            $meta = is_array( $meta ) ? $meta : array();
            if ( $type === 'contact_attempt' && (string) ( $meta['source'] ?? '' ) !== 'operator_portal' && (string) ( $meta['actor_type'] ?? '' ) !== 'operator' ) {
                continue;
            }
            $detail = array();
            if ( $ev->from_status !== '' || $ev->to_status !== '' ) {
                $detail[] = ( $ev->from_status ?: '∅' ) . '→' . ( $ev->to_status ?: '∅' );
            }
            $op_id = (int) ( $ev->operator_id ?? 0 );
            if ( $op_id && ! empty( $op_names[ $op_id ] ) ) {
                $detail[] = 'op:' . $op_names[ $op_id ];
            }
            $reason = trim( (string) $ev->reason );
            if ( $reason !== '' ) {
                $detail[] = mb_substr( $reason, 0, 120 );
            }
            $suffix = $detail ? ' · ' . implode( ' · ', $detail ) : '';
            $entries[] = array(
                'ts'   => (string) $ev->created_at,
                'type' => $type,
                'core' => self::human_event_label( $type ) . $suffix,
            );
        }
        if ( empty( $entries ) ) {
            return '';
        }

        // MEJORA 2 · colapsar eventos consecutivos idénticos (mismo core) → ×N.
        $lines = array();
        $i = 0;
        $n = count( $entries );
        while ( $i < $n ) {
            $core = $entries[ $i ]['core'];
            $ts   = $entries[ $i ]['ts'];
            $cat  = self::timeline_category( $entries[ $i ]['type'] );
            $sev  = self::timeline_severity( $entries[ $i ]['type'] );
            $count = 1;
            while ( $i + $count < $n && $entries[ $i + $count ]['core'] === $core ) {
                $count++;
            }
            $mult = $count > 1 ? ' ×' . $count : '';
            $lines[] = "- [" . $ts . "] " . $cat . ' ' . $core . $mult . ' [' . $sev . ']';
            $i += $count;
        }

        return "\n## Timeline\n\n" . implode( "\n", $lines ) . "\n";
    }

    /**
     * RC3 · Low-level technical event detector.
     * Transport/throttling/retry diagnostics that do not materially affect
     * commercial action. Suppressed from the commercial view only.
     */
    private static function is_low_level_technical( $type ) {
        $type = strtolower( (string) $type );
        foreach ( array( 'throttle', 'retry', 'transport' ) as $needle ) {
            if ( strpos( $type, $needle ) !== false ) {
                return true;
            }
        }
        return false;
    }

    private static function human_event_label( string $type ): string {
        $labels = array(
            'lead_created'               => 'Lead created',
            'operator_assigned'          => 'Operator assigned',
            'operator_notified'          => 'Operator notified',
            'contact_attempt'            => 'Contact attempt',
            'quality_changed'            => 'Quality changed',
            'status_changed'             => 'Status changed',
            'commercial_stage_changed'   => 'Commercial stage changed',
            'lead_recontacted'           => 'Lead recontacted',
            'lead_whatsapp_opened'       => 'WhatsApp opened',
            'compliance_escalation'      => 'Compliance escalation',
            'operator_daily_digest_sent' => 'Daily digest sent',
            'autoresponse_link_click'    => 'Autoresponse link clicked',
            'autoresponse_engaged'       => 'Autoresponse engaged',
            'qualification_completed'          => 'Commercial qualification completed',
            'qualification_manual_override'    => 'Manual qualification override (operator)',
            'qualification_review'             => 'Held for commercial review (policy)',
            'routing_skipped_by_policy'        => 'Routing skipped by policy',
            'notification_skipped_by_policy'   => 'Notification skipped by policy',
            'autoresponse_skipped_by_policy'   => 'Autoresponse skipped by policy',
            'autoresponse_skipped'             => 'Autoresponse skipped',
            'autoresponse_open_classified'     => 'Email open classified',
            'operator_notification_delivered'  => 'Notification delivered (deferred → sent)',
        );
        if ( isset( $labels[ $type ] ) ) {
            return $labels[ $type ];
        }
        return ucfirst( str_replace( '_', ' ', $type ) );
    }

    /** Categoría determinística por tipo de evento: SYSTEM · CUSTOMER · COMMERCIAL · AUTOMATION. */
    private static function timeline_category( $type ) {
        $type = (string) $type;
        if ( strpos( $type, 'autoresponse_' ) === 0 ) {
            return 'AUTOMATION';
        }
        if ( in_array( $type, array( 'lead_recontacted', 'lead_whatsapp_opened' ), true ) ) {
            return 'CUSTOMER';
        }
        if ( in_array( $type, array( 'contact_attempt', 'quality_changed', 'commercial_stage_changed', 'compliance_escalation' ), true ) ) {
            return 'COMMERCIAL';
        }
        if ( $type === 'operator_daily_digest_sent' ) {
            return 'SYSTEM';
        }
        return 'SYSTEM';
    }

    /** Severidad determinística por tipo de evento: WARNING · IMPORTANT · INFO. */
    private static function timeline_severity( $type ) {
        $type = (string) $type;
        if ( strpos( $type, 'failed' ) !== false ) {
            return 'WARNING';
        }
        $important = array(
            'compliance_escalation', 'lead_recontacted', 'quality_changed',
            'status_changed', 'commercial_stage_changed', 'contact_attempt',
            'lead_whatsapp_opened', 'autoresponse_link_click', 'autoresponse_engaged',
        );
        if ( in_array( $type, $important, true ) ) {
            return 'IMPORTANT';
        }
        return 'INFO';
    }

    /**
     * MEJORA 1 · Resumen ejecutivo determinístico (sin IA).
     * Reutiliza el lead + las submissions ya cargadas; una sola query (último contacto).
     */
    private static function build_summary( $lead, $submissions ) {
        $latest = ! empty( $submissions ) ? end( $submissions ) : null;
        if ( $latest ) { reset( $submissions ); }
        $comp = self::decode_components( $lead );

        $md  = "## Executive Summary\n\n";
        $md .= "- **Status:** " . ( $lead->status ?: 'NULL' ) . "\n";
        $md .= "- **Temperature:** " . ( $lead->temperature ?: 'NULL' ) . "\n";
        $md .= "- **Priority:** " . ( $lead->priority ?: 'NULL' ) . "\n";
        $md .= "- **Lifecycle:** " . self::lifecycle_stage( $lead ) . "\n";
        $md .= "- **SLA:** " . self::sla_status( $lead ) . "\n\n";

        return $md;
    }

    private static function build_commercial_readiness( $lead ): string {
        $comp = self::decode_components( $lead );
        $signals = array();
        if ( ! empty( $lead->assigned_operator_id ) || ! empty( $lead->assigned_operator_name ) ) { $signals[] = 'operator_assigned'; }
        if ( ! self::operator_contact_missing( $lead ) ) { $signals[] = 'operator_contact'; }
        if ( ! empty( $lead->quality ) ) { $signals[] = 'classified'; }
        if ( (float) ( $comp['behavior'] ?? 0 ) > 0 ) { $signals[] = 'behavior_evidence'; }

        $confidence = self::score_confidence( $comp );
        if ( empty( $signals ) ) {
            $readiness = 'neutral';
            $next = 'Asignar operador y contactar.';
        } elseif ( self::operator_contact_missing( $lead ) ) {
            $readiness = 'needs_contact';
            $next = 'Contactar y registrar resultado.';
        } elseif ( empty( $lead->quality ) ) {
            $readiness = 'needs_classification';
            $next = 'Clasificar oportunidad comercial.';
        } else {
            $readiness = 'ready';
            $next = 'Gestionar siguiente acción comercial.';
        }

        $md  = "\n## Commercial Readiness\n\n";
        $md .= "- **Readiness:** " . $readiness . "\n";
        $md .= "- **Next Action:** " . $next . "\n";
        return $md;
    }

    /**
     * Section 4 — Session presence and page data. Read-only; runs 1 query.
     *
     * SESSION_LANDING_FALLBACK_V1: when the session record is missing or its
     * landing is empty, the landing resolves non-destructively with explicit
     * provenance — 1. session · 2. submission (source_page_url/landing_url) ·
     * 3. lead.first_landing_url. The Analytics Quality WARNING for an
     * incomplete session is NOT cleared by the fallback (separate block).
     */
    private static function build_session_block( $submission, $lead = null ): string {
        global $wpdb;
        if ( ! $submission || ! $submission->session_id ) {
            $fallback = self::session_landing_fallback( $submission, $lead );
            return "Session: NULL\nSession Matched: NO\nSession ID: NULL\nLanding: " . $fallback['line'] . "\nLanding Path: " . $fallback['path'] . "\nPageviews: 0\nDuration: NULL\n";
        }
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ea_sessions WHERE session_id = %s",
            $submission->session_id
        ) );
        if ( $session ) {
            $landing_url  = (string) ( $session->landing_page ?? '' );
            $pv           = (int) ( $session->page_count ?? 0 );
            $dur          = (int) ( $session->duration ?? 0 );
            if ( $landing_url !== '' ) {
                $landing_path = parse_url( $landing_url, PHP_URL_PATH ) ?: 'NULL';
                return "Session: PRESENT\nSession Matched: YES\nSession ID: " . $session->session_id . "\nLanding: " . $landing_url . " (source: session)\nLanding Path: " . $landing_path . "\nPageviews: " . $pv . "\nDuration: " . ( $dur > 0 ? $dur . 's' : 'NULL' ) . "\n";
            }
            // Session exists but is incomplete (no landing) → non-destructive fallback.
            $fallback = self::session_landing_fallback( $submission, $lead );
            return "Session: PRESENT\nSession Matched: YES\nSession ID: " . $session->session_id . "\nLanding: " . $fallback['line'] . "\nLanding Path: " . $fallback['path'] . "\nPageviews: " . $pv . "\nDuration: " . ( $dur > 0 ? $dur . 's' : 'NULL' ) . "\n";
        }
        $fallback = self::session_landing_fallback( $submission, $lead );
        return "Session: LINKED_ID_NO_RECORD\nSession Matched: NO\nSession ID: " . $submission->session_id . "\nLanding: " . $fallback['line'] . "\nLanding Path: " . $fallback['path'] . "\nPageviews: 0\nDuration: NULL\n";
    }

    /**
     * Fallback landing (session unavailable/incomplete). Precedence:
     * submission context_json.source_page_url → submission.landing_url →
     * lead.first_landing_url. Returns the rendered line with its provenance.
     *
     * @return array{line:string, path:string}
     */
    private static function session_landing_fallback( $submission, $lead ): array {
        $candidates = array();
        if ( $submission ) {
            $ctx = json_decode( (string) ( $submission->context_json ?? '' ), true );
            $candidates['submission.source_page_url'] = is_array( $ctx ) ? trim( (string) ( $ctx['source_page_url'] ?? '' ) ) : '';
            $candidates['submission.landing_url']     = trim( (string) ( $submission->landing_url ?? '' ) );
        }
        $candidates['lead.first_landing_url'] = $lead ? trim( (string) ( $lead->first_landing_url ?? '' ) ) : '';

        foreach ( $candidates as $source => $url ) {
            if ( $url !== '' ) {
                return array(
                    'line' => $url . ' (fallback source: ' . $source . ' — session landing unavailable)',
                    'path' => (string) ( parse_url( $url, PHP_URL_PATH ) ?: 'NULL' ),
                );
            }
        }
        return array( 'line' => 'NULL', 'path' => 'NULL' );
    }

    /**
     * Section 5 — Analytics quality signals (events recorded, source, quality).
     * Companion to build_session_block; same query result but separate concerns.
     */
    private static function build_analytics_quality_block( $submission ): string {
        global $wpdb;
        if ( ! $submission || ! $submission->session_id ) {
            return "Events Recorded: NULL\nAnalytics Source: NULL\nAnalytics Quality: WARNING · Sin session_id (lead sin tracker JS)\n";
        }
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ea_sessions WHERE session_id = %s",
            $submission->session_id
        ) );
        $events_count = self::analytics_events_count( (string) $submission->session_id );
        if ( $session ) {
            $landing_url = (string) ( $session->landing_page ?? '' );
            $src         = (string) ( $session->source ?? '' );
            $quality     = ( $landing_url !== '' ? 'PASS' : 'WARNING' );
            return "Events Recorded: " . $events_count . "\nAnalytics Source: " . ( $src !== '' ? $src : 'NULL' ) . "\nAnalytics Quality: " . $quality . "\n";
        }
        return "Events Recorded: " . $events_count . "\nAnalytics Source: NULL\nAnalytics Quality: WARNING · Session ID no encontrada en Analytics (lead llegó antes del tracker JS o sin Analytics activo)\n";
    }

    /**
     * Section 7 — Automation events (autoresponse_*) isolated from the main timeline.
     * Includes actor_type context from metadata (distinguishes lead vs admin vs system).
     */
    private static function build_automation_events( int $lead_id, string $view ): string {
        global $wpdb;
        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT created_at, event_type, metadata
               FROM {$wpdb->prefix}es_lead_events
              WHERE lead_id = %d AND event_type LIKE 'autoresponse\\_%%'
           ORDER BY created_at ASC, id ASC",
            $lead_id
        ) );
        if ( empty( $events ) ) {
            return "NONE\n";
        }
        $lines = array();
        foreach ( $events as $ev ) {
            $meta   = json_decode( (string) ( $ev->metadata ?? '' ), true );
            $meta   = is_array( $meta ) ? $meta : array();
            $detail = array();
            if ( ! empty( $meta['actor_type'] ) )  { $detail[] = 'actor:' . $meta['actor_type']; }
            if ( ! empty( $meta['actor_email'] ) ) { $detail[] = $meta['actor_email']; }
            // SEMANTIC_STATUS_V1 (Lead #478): sin email no hay destino — es un
            // skip intencional, no un fallo. Cubre el evento nuevo
            // autoresponse_skipped Y las filas históricas failed/no_email.
            $type   = (string) $ev->event_type;
            $reason = sanitize_key( (string) ( $meta['reason'] ?? '' ) );
            if ( 'autoresponse_skipped' === $type || ( strpos( $type, 'failed' ) !== false && 'no_email' === $reason ) ) {
                $sev = 'SKIPPED';
            } elseif ( strpos( $type, 'failed' ) !== false ) {
                $sev = 'FAIL'; // fallo real de transporte/plantilla/config
            } else {
                $sev = 'INFO';
            }
            $suffix  = $detail ? ' · ' . implode( ' · ', $detail ) : '';
            $lines[] = "- [" . $ev->created_at . "] " . self::human_event_label( $type ) . $suffix . " [" . $sev . "]";
        }
        return implode( "\n", $lines ) . "\n";
    }

    /**
     * Section 13 — Execution Trace (NEW). Condensed lifecycle milestones in chronological
     * order: only key state-change events + current lifecycle + SLA. One line per milestone.
     */
    private static function build_execution_trace( int $lead_id, $lead ): string {
        global $wpdb;
        $milestone_types = array(
            'lead_created', 'operator_assigned', 'contact_attempt',
            'quality_changed', 'status_changed', 'compliance_escalation',
            'lead_operational_reopened',
        );
        $ph   = implode( ',', array_fill( 0, count( $milestone_types ), '%s' ) );
        $args = array_merge( array( $lead_id ), $milestone_types );
        $events = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, event_type, from_status, to_status, operator_id
                   FROM {$wpdb->prefix}es_lead_events
                  WHERE lead_id = %d AND event_type IN ({$ph})
               ORDER BY created_at ASC, id ASC",
                $args
            )
        );
        $op_names = array();
        if ( ! empty( $events ) ) {
            $op_rows = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}es_operators" );
            foreach ( (array) $op_rows as $op ) {
                $op_names[ (int) $op->id ] = $op->name;
            }
        }
        $lines = array();
        foreach ( (array) $events as $ev ) {
            $detail = array();
            $from   = trim( (string) $ev->from_status );
            $to     = trim( (string) $ev->to_status );
            if ( $from !== '' || $to !== '' ) {
                $detail[] = ( $from ?: '∅' ) . '→' . ( $to ?: '∅' );
            }
            $op_id = (int) ( $ev->operator_id ?? 0 );
            if ( $op_id && ! empty( $op_names[ $op_id ] ) ) {
                $detail[] = $op_names[ $op_id ];
            }
            $suffix  = $detail ? ' · ' . implode( ' · ', $detail ) : '';
            $lines[] = "- [" . $ev->created_at . "] " . self::human_event_label( (string) $ev->event_type ) . $suffix;
        }
        if ( empty( $lines ) ) {
            $lines[] = "No milestone events recorded.";
        }
        $lines[] = '';
        $lines[] = "Current lifecycle: " . self::lifecycle_stage( $lead );
        $lines[] = "SLA: " . self::sla_status( $lead );
        return implode( "\n", $lines ) . "\n";
    }

    /**
     * FASE 1 · Gestión operador: contacts + classifications + latest_feedback_reason.
     * Read-only, sin schema.
     */
    private static function build_operator_management( $lead_id, $lead ) {
        global $wpdb;

        $md = "\n## Gestión Operador\n\n";

        // Último contacto registrado.
        $contact = $wpdb->get_row( $wpdb->prepare(
            "SELECT c.outcome, c.channel, c.notes, c.contacted_at, o.name AS operator_name
               FROM {$wpdb->prefix}es_lead_contacts c
          LEFT JOIN {$wpdb->prefix}es_operators o ON o.id = c.operator_id
              WHERE c.lead_id = %d
           ORDER BY c.contacted_at DESC, c.id DESC
              LIMIT 1",
            $lead_id
        ) );
        if ( $contact ) {
            $md .= "- **Último outcome:** " . ( $contact->outcome ?: '—' ) . "\n";
            $md .= "- **Canal:** " . ( $contact->channel ?: '—' ) . "\n";
            $md .= "- **Fecha contacto:** " . ( $contact->contacted_at ?: '—' ) . "\n";
            $md .= "- **Operador:** " . ( $contact->operator_name ?: '—' ) . "\n";
            if ( trim( (string) $contact->notes ) !== '' ) {
                $md .= "- **Notas contacto:** " . trim( (string) $contact->notes ) . "\n";
            }
        } else {
            $md .= "- **Contacto registrado:** ninguno\n";
        }

        if ( trim( (string) ( $lead->latest_feedback_reason ?? '' ) ) !== '' ) {
            $md .= "- **Último feedback:** " . trim( (string) $lead->latest_feedback_reason ) . "\n";
        }

        // Historial de clasificación.
        $classifications = $wpdb->get_results( $wpdb->prepare(
            "SELECT cl.previous_quality, cl.quality, cl.sales_stage, cl.status, cl.notes, cl.classified_at, o.name AS operator_name
               FROM {$wpdb->prefix}es_lead_classifications cl
          LEFT JOIN {$wpdb->prefix}es_operators o ON o.id = cl.operator_id
              WHERE cl.lead_id = %d
           ORDER BY cl.classified_at ASC, cl.id ASC",
            $lead_id
        ) );
        if ( ! empty( $classifications ) ) {
            $md .= "\n**Historial de clasificación:**\n";
            foreach ( $classifications as $cl ) {
                $q = ( $cl->previous_quality ?: '∅' ) . '→' . ( $cl->quality ?: '∅' );
                $extra = array();
                if ( $cl->sales_stage !== '' ) { $extra[] = 'stage:' . $cl->sales_stage; }
                if ( $cl->status !== '' )      { $extra[] = 'status:' . $cl->status; }
                if ( ! empty( $cl->operator_name ) ) { $extra[] = 'op:' . $cl->operator_name; }
                $note = trim( (string) $cl->notes );
                if ( $note !== '' ) { $extra[] = mb_substr( $note, 0, 120 ); }
                $suffix = $extra ? ' · ' . implode( ' · ', $extra ) : '';
                $md .= "- [" . $cl->classified_at . "] " . $q . $suffix . "\n";
            }
        }

        return $md;
    }

    /**
     * §1 — Runtime Context: engine/schema/export versions, generator, timestamp,
     * plugin/WP/PHP versions and UUIDs. Absorbs the former Export Metadata section.
     * Read-only; constants + cheap built-ins only.
     */
    private static function build_runtime_context( $lead, $submission ): string {
        $engine = defined( 'Estandar_Leads_Scoring::ENGINE_VERSION' ) ? Estandar_Leads_Scoring::ENGINE_VERSION : 'BEHAVIORAL_SCORE_V1';
        $schema = class_exists( 'Estandar_Leads_Schema' ) ? Estandar_Leads_Schema::SCHEMA_VERSION : 'NULL';
        $plugin = defined( 'ESTANDAR_LEADS_VERSION' ) ? ESTANDAR_LEADS_VERSION : 'NULL';
        $wp     = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : 'NULL';
        $now    = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d\TH:i:sP' ) : gmdate( 'Y-m-d\TH:i:sP' );

        $sub_uuid = '';
        if ( $submission ) {
            foreach ( array( 'submission_uuid', 'uuid', 'lead_uuid' ) as $k ) {
                if ( isset( $submission->{$k} ) && trim( (string) $submission->{$k} ) !== '' ) {
                    $sub_uuid = (string) $submission->{$k};
                    break;
                }
            }
        }

        $rows = array(
            'Engine Version'    => (string) $engine,
            'Schema Version'    => (string) $schema,
            'Export Version'    => '5.4',
            'Generator'         => __CLASS__,
            'Generated At'      => (string) $now,
            'Plugin Version'    => (string) $plugin,
            'WordPress Version' => (string) $wp,
            'PHP Version'       => PHP_VERSION,
            'Lead UUID'         => self::display_value( $lead->lead_uuid ?? '', 'NULL' ),
            'Submission UUID'   => $sub_uuid !== '' ? $sub_uuid : 'NULL',
            'Session UUID'      => ( $submission && ! empty( $submission->session_id ) ) ? (string) $submission->session_id : 'NULL',
            'View'              => 'engineering (technical, no telemetry withheld)',
            'Timestamp Convention' => 'stored/raw timestamps are UTC; key dates also show ' . self::EXPORT_TIMEZONE . ' — historical values never rewritten',
            'Compatibility'     => 'Semantic data compatible; adds optional SEO Landing Context in 5.2; 5.3 adds Notification Delivery + Behavior Evidence blocks under Diagnostics and narrows Routing Decision to operator_assigned only (was previously overwritable by operator_notified); 5.4 splits Notification Policy out of Notification Delivery (new block — the `Reason:` line inside Delivery is REMOVED, moved to Notification Policy only) and renames Delivery status literals to a fixed 5-name canonical vocabulary (delivered/deferred_by_policy/scheduled/queued/failed — only delivered and deferred_by_policy have real evidence today) plus not_attempted/skipped_by_policy/consolidated outside that set — a parser reading the old "queued"/"not attempted"/"skipped by commercial policy" strings or the inline Reason: line from 5.3 will not find them in 5.4',
        );
        $md = '';
        foreach ( $rows as $k => $v ) {
            $md .= "- **" . $k . ":** " . $v . "\n";
        }
        return $md;
    }

    /**
     * §3 — Identity Resolution: how identifiers resolved. Read-only, derived from
     * already-loaded lead/submission columns; customer match via existing accessor.
     */
    private static function build_identity_resolution( $lead, $submission ): string {
        $email_hash = trim( (string) ( $lead->email_hash ?? '' ) );
        $phone_hash = trim( (string) ( $lead->phone_hash ?? '' ) );
        $email      = trim( (string) ( $lead->email ?? '' ) );
        $phone      = trim( (string) ( $lead->phone ?? '' ) );

        $email_match = $email_hash !== '' ? 'RESOLVED · email_hash' : ( $email !== '' ? 'PRESENT · unhashed' : 'NONE' );
        $phone_match = $phone_hash !== '' ? 'RESOLVED · phone_hash' : ( $phone !== '' ? 'PRESENT · unhashed' : 'NONE' );

        $customer_match  = 'UNMATCHED';
        $customer_reason = 'No customer record linked to this lead.';
        if ( class_exists( 'Estandar_Leads_Customer_Service' ) ) {
            $cust = Estandar_Leads_Customer_Service::get_by_lead( (int) ( $lead->id ?? 0 ) );
            if ( ! empty( $cust ) ) {
                $customer_match  = 'MATCHED';
                $customer_reason = 'Existing customer matched by identity.';
            }
        } else {
            $customer_reason = 'Customer service module not loaded — match not evaluated.';
        }

        $submission_match = $submission ? ( 'LINKED · submission #' . (int) $submission->id ) : 'NONE';
        $session_match    = ( $submission && ! empty( $submission->session_id ) )
            ? ( 'LINKED · ' . (string) $submission->session_id )
            : 'NONE · no tracker session_id';

        $strong = ( $email_hash !== '' ? 1 : 0 ) + ( $phone_hash !== '' ? 1 : 0 );
        $confidence = $strong >= 2 ? 'HIGH' : ( $strong === 1 ? 'MEDIUM' : 'LOW' );

        $reasons = array();
        if ( $email_hash !== '' ) { $reasons[] = 'email_hash'; }
        if ( $phone_hash !== '' ) { $reasons[] = 'phone_hash'; }
        if ( $submission )        { $reasons[] = 'submission_link'; }
        if ( $submission && ! empty( $submission->session_id ) ) { $reasons[] = 'session_link'; }
        $reason = empty( $reasons )
            ? 'No strong identifiers; lead created from minimal payload.'
            : ( 'Resolved via ' . implode( ' + ', $reasons ) . '.' );

        $md  = "- **Email match:** " . $email_match . "\n";
        $md .= "- **Phone match:** " . $phone_match . "\n";
        $md .= "- **Customer match:** " . $customer_match . "\n";
        $md .= "- **Submission match:** " . $submission_match . "\n";
        $md .= "- **Session match:** " . $session_match . "\n";
        $md .= "- **Confidence:** " . $confidence . "\n";
        $md .= "- **Reason:** " . $reason . "\n";
        $md .= "- **Customer reason:** " . $customer_reason . "\n";
        return $md;
    }

    /**
     * §4/§9 — Deterministic engine-stage statuses derived from already-loaded data.
     * No engine execution, no scoring; surfaces existing runtime state only.
     * @return array<string,array{0:string,1:string}> name → [status, reason]
     */
    private static function compute_engine_pipeline( $lead, $submissions, $submission ): array {
        $comp   = self::decode_components( $lead );
        $stages = array();

        // SEMANTIC_STATUS_V1 (Lead #478): evidencia aún no producida = PENDING
        // (paso no alcanzado), mismo vocabulario que Consistency Checks.
        $cap = $comp['capture'] ?? null;
        if ( $cap === null )           { $stages['Capture Engine'] = array( 'PENDING', 'capture component absent' ); }
        elseif ( (float) $cap >= 100 ) { $stages['Capture Engine'] = array( 'PASS', 'capture=' . $cap ); }
        elseif ( (float) $cap > 0 )    { $stages['Capture Engine'] = array( 'WARNING', 'partial capture=' . $cap ); }
        else                           { $stages['Capture Engine'] = array( 'PENDING', 'capture=0' ); }

        $sid = $submission ? trim( (string) ( $submission->session_id ?? '' ) ) : '';
        $stages['Analytics Engine'] = $sid !== ''
            ? array( 'PASS', 'session_id linked: ' . $sid )
            : array( 'PENDING', 'no session_id (no tracker JS)' );

        $beh = $comp['behavior'] ?? null;
        if ( $beh === null )        { $stages['Behavior Engine'] = array( 'PENDING', 'behavior component absent' ); }
        elseif ( (float) $beh > 0 ) { $stages['Behavior Engine'] = array( 'PASS', 'behavior=' . $beh ); }
        else                        { $stages['Behavior Engine'] = array( 'PENDING', 'no behavioral evidence' ); }

        $qual = array_key_exists( 'qualification', $comp ) ? $comp['qualification'] : null;
        $stages['Qualification Engine'] = ( $qual !== null || ! empty( $lead->quality ) )
            ? array( 'PASS', 'qualification present' )
            : array( 'PENDING', 'awaiting operator classification' );

        $exp = json_decode( (string) ( $lead->experience_data_json ?? '' ), true );
        if ( is_array( $exp ) && ( ! empty( $exp['completed'] ) || ! empty( $exp['completed_at'] ) ) ) {
            $stages['Experience Engine'] = array( 'PASS', 'experience completed' );
        } elseif ( is_array( $exp ) && ! empty( $exp ) ) {
            $stages['Experience Engine'] = array( 'WARNING', 'experience in progress' );
        } else {
            $stages['Experience Engine'] = array( 'PENDING', 'experience not started' );
        }

        $stages['Compliance Engine'] = self::compliance_engine_state( $lead );

        $rec_present = ! empty( $lead->high_interest_lost ) || ! empty( $lead->second_attempt_requested );
        if ( class_exists( 'Estandar_Leads_Recovery' ) ) {
            $rec = Estandar_Leads_Recovery::get_recovery_signals( (int) ( $lead->id ?? 0 ) );
            if ( is_array( $rec ) ) {
                $rec_present = $rec_present
                    || ! empty( $rec['lost_reason'] ) || ! empty( $rec['high_interest_lost'] )
                    || ! empty( $rec['recovery_email_sent'] ) || ! empty( $rec['second_attempt_requested'] )
                    || ! empty( $rec['terminal_reopened'] );
            }
        }
        $stages['Recovery Engine'] = $rec_present
            ? array( 'PASS', 'recovery signal present' )
            : array( 'SKIPPED', 'no recovery trigger' );

        $stages['Commercial Intelligence'] = array( 'PASS', 'lifecycle=' . self::lifecycle_stage( $lead ) );

        return $stages;
    }

    /** §4 — Render engine pipeline in canonical order with ↓ separators. */
    private static function render_engine_pipeline( array $stages ): string {
        $order = array(
            'Capture Engine', 'Analytics Engine', 'Behavior Engine', 'Qualification Engine',
            'Experience Engine', 'Compliance Engine', 'Recovery Engine', 'Commercial Intelligence',
        );
        $lines = array();
        $first = true;
        foreach ( $order as $name ) {
            if ( ! isset( $stages[ $name ] ) ) { continue; }
            if ( ! $first ) { $lines[] = '↓'; }
            $first = false;
            $lines[] = $name . ' — ' . $stages[ $name ][0] . ' · ' . $stages[ $name ][1];
        }
        return implode( "\n", $lines ) . "\n";
    }

    /**
     * §7 — State Machine: lifecycle evolution (execution trace) + current / expected
     * next / missing transition, derived deterministically from lifecycle_stage.
     */
    private static function build_state_machine( int $lead_id, $lead ): string {
        $current = self::lifecycle_stage( $lead );
        $map = array(
            'awaiting_contact'        => array( 'contacted',            'contact_attempt' ),
            'awaiting_experience'     => array( 'experience_completed', 'experience' ),
            'awaiting_classification' => array( 'qualified',            'operator_classification' ),
            'compliance_breach'       => array( 'compliance_resolved',  'follow_up_action' ),
            'recovery'                => array( 'recovered',            'recovery_action' ),
            'complete'                => array( 'terminal (won/lost)',  'none' ),
        );
        list( $next, $missing ) = $map[ $current ] ?? array( 'unknown', 'unknown' );

        $md  = "### Lifecycle Evolution\n\n";
        $md .= self::build_execution_trace( $lead_id, $lead );
        $md .= "\n### State\n\n";
        $md .= "- **Current State:** " . $current . "\n";
        $md .= "- **Lead Status:** " . ( $lead->status ?: 'NULL' ) . "\n";
        $md .= "- **Expected Next State:** " . $next . "\n";
        $md .= "- **Missing Transition:** " . $missing . "\n";
        return $md;
    }

    /**
     * §9 — Runtime Metrics: surface existing cheap runtime signals only.
     * No expensive computation; built-ins guarded for CLI/test harness.
     */
    private static function build_runtime_metrics( int $lead_id, array $pipeline ): string {
        global $wpdb;
        $events = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d",
            $lead_id
        ) );
        $exec     = function_exists( 'timer_stop' ) ? ( timer_stop( 0, 3 ) . 's (since WP start)' ) : 'N/A';
        $queries  = function_exists( 'get_num_queries' ) ? (string) get_num_queries() : 'N/A';
        $mem      = function_exists( 'size_format' ) ? size_format( memory_get_usage( true ) ) : (string) memory_get_usage( true );
        $mem_peak = function_exists( 'size_format' ) ? size_format( memory_get_peak_usage( true ) ) : (string) memory_get_peak_usage( true );

        $captured_at = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT created_at FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            $lead_id
        ) );
        $sla_metrics = self::operator_response_sla_metrics( $lead_id, $captured_at );

        $md  = "- **Execution time:** " . $exec . "\n";
        $md .= "- **SQL queries (request):** " . $queries . "\n";
        $md .= "- **Memory usage:** " . $mem . "\n";
        $md .= "- **Memory peak:** " . $mem_peak . "\n";
        $md .= "- **Events processed (es_lead_events):** " . $events . "\n";
        $md .= "- **Operator SLA status:** " . $sla_metrics['status_label'] . "\n";
        if ( $sla_metrics['queued_hours'] > 0 ) {
            $md .= "- **Notification queued:** " . $sla_metrics['queued_hours'] . "h (reason: " . $sla_metrics['deferral_reason'] . ")\n";
        }
        return $md;
    }

    /**
     * §10 — Raw Sources: canonical raw payloads verbatim (collapsed). No transformation.
     * Reuses already-loaded data; recovery via existing accessor.
     */
    private static function build_raw_sources( $lead, $submissions ): string {
        $wrap = static function ( $label, $payload ) {
            $payload = trim( (string) $payload );
            if ( $payload === '' ) { $payload = 'NULL'; }
            return "<details><summary>" . $label . "</summary>\n\n```\n" . $payload . "\n```\n\n</details>\n\n";
        };
        $comp = self::decode_components( $lead );

        $md  = $wrap( 'Lead (row)', wp_json_encode( $lead ) );
        $md .= $wrap( 'Scoring (score_components_json)', (string) ( $lead->score_components_json ?? '' ) );
        $md .= $wrap( 'Behavior (behavior_detail)', isset( $comp['behavior_detail'] ) ? wp_json_encode( $comp['behavior_detail'] ) : 'NULL' );
        $md .= $wrap( 'Experience (experience_data_json)', (string) ( $lead->experience_data_json ?? '' ) );
        $md .= $wrap( 'Compliance', wp_json_encode( array(
            'assigned_at'        => $lead->assigned_at ?? null,
            'feedback_completed' => $lead->feedback_completed ?? null,
            'feedback_status'    => $lead->feedback_status ?? null,
        ) ) );
        if ( class_exists( 'Estandar_Leads_Recovery' ) ) {
            $md .= $wrap( 'Recovery', wp_json_encode( Estandar_Leads_Recovery::get_recovery_signals( (int) ( $lead->id ?? 0 ) ) ) );
        }
        foreach ( (array) $submissions as $i => $sub ) {
            $md .= $wrap( 'Submission #' . ( $i + 1 ) . ' · form_fields_json', (string) ( $sub->form_fields_json ?? '' ) );
            $md .= $wrap( 'Submission #' . ( $i + 1 ) . ' · context_json', (string) ( $sub->context_json ?? '' ) );
            $chatbot = json_decode( (string) ( $sub->chatbot_json ?? '' ), true );
            if (is_array($chatbot) && !empty($chatbot['conversation_summary'])) {
                $md .= $wrap( 'Submission #' . ( $i + 1 ) . ' · chatbot_conversation_summary', sanitize_textarea_field( (string) $chatbot['conversation_summary'] ) );
            }
        }
        $latest = ! empty( $submissions ) ? end( $submissions ) : null;
        if ( $latest ) { reset( $submissions ); }
        $md .= $wrap( 'Analytics (session pointer)', wp_json_encode( array(
            'session_id' => $latest->session_id ?? null,
        ) ) );
        return $md;
    }

    // ── RFC v5.1 — 7 new diagnostic sections ────────────────────────────────

    /**
     * Entry point for the 7 new v5.1 diagnostic sub-sections.
     * One targeted DB query for assignment/routing events; all other data from
     * already-loaded $lead/$submissions/$submission.
     * Read-only; no schema changes; backward compatible with Export v5.
     */
    private static function build_v51_diagnostics( int $lead_id, $lead, array $submissions, $submission ): string {
        global $wpdb;

        // ROUTING_NOTIFICATION_EXPLAINABILITY_V1: extended event set — the
        // original three types feed Active Assignment / Operator Consistency;
        // the notification/autoresponse types feed Notification Delivery and
        // Behavior Evidence below. Read-only, same table, no new events.
        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_type, metadata, created_at, operator_id, submission_id, to_status, reason
               FROM {$wpdb->prefix}es_lead_events
              WHERE lead_id = %d
                AND event_type IN (
                    'operator_assigned','operator_notified','operator_reassigned',
                    'operator_notification_deferred','operator_notification_consolidated',
                    'operator_notification_delivered',
                    'notification_skipped_by_policy','routing_unresolved','routing_skipped_by_policy',
                    'autoresponse_email_sent','autoresponse_email_failed','autoresponse_skipped',
                    'autoresponse_email_open_signal','autoresponse_open_classified',
                    'autoresponse_link_click','autoresponse_engaged'
                )
              ORDER BY created_at ASC",
            $lead_id
        ) );
        $events = is_array( $events ) ? $events : array();

        $parsed = array();
        foreach ( $events as $ev ) {
            $meta     = json_decode( (string) ( $ev->metadata ?? '' ), true );
            $parsed[] = array(
                'type'    => (string) $ev->event_type,
                'meta'    => is_array( $meta ) ? $meta : array(),
                'created' => (string) $ev->created_at,
                'operator_id'   => ! empty( $ev->operator_id ) ? (int) $ev->operator_id : null,
                'submission_id' => ! empty( $ev->submission_id ) ? (int) $ev->submission_id : null,
                'to_status'     => (string) ( $ev->to_status ?? '' ),
                'reason'        => (string) ( $ev->reason ?? '' ),
            );
        }

        $md  = "\n### Active Assignment (v5.1)\n\n";
        $md .= self::diag_v51_active_assignment( $parsed, $submissions, $lead );

        $md .= "\n### Routing Decision (v5.1)\n\n";
        $md .= self::diag_v51_routing_decision( $parsed, $submissions );

        // NOTIFICATION_POLICY_VS_DELIVERY_V1 (hardening pass): the RULE that
        // was applied (why a notification was gated/skipped/deferred) and the
        // OBSERVED RESULT of the delivery attempt are two different questions
        // and are never rendered from the same field. Policy answers "what
        // gate fired and why"; Delivery answers "did it reach the operator,
        // when, with what delay". Delivery only ever points back to Policy —
        // it never repeats the policy reason text itself.
        $md .= "\n### Notification Policy (v1)\n\n";
        $md .= self::diag_notification_policy( $parsed );

        $md .= "\n### Notification Delivery (v1)\n\n";
        $md .= self::diag_notification_delivery( $parsed );

        $md .= "\n### Behavior Evidence (v1)\n\n";
        $md .= self::diag_behavior_evidence( $parsed );

        $md .= "\n### Operator Consistency (v5.1)\n\n";
        $md .= self::diag_v51_operator_consistency( $parsed, $lead );

        $md .= "\n### Active Project vs Submission History (v5.1)\n\n";
        $md .= self::diag_v51_project_drift( $lead, $submissions );

        $md .= "\n### Campaign Resolution (v5.1)\n\n";
        $md .= self::diag_v51_campaign_resolution( $lead, $submission );

        $md .= "\n### Capture Method (v5.1)\n\n";
        $md .= self::diag_v51_capture_method( $submissions );

        $md .= "\n### Recommended Operational Action (v5.1)\n\n";
        $md .= self::diag_v51_recommended_action( $lead, $submissions, $parsed );

        $md .= "\n### Operator SLA Timeline (v1)\n\n";
        $md .= self::diag_operator_sla_timeline( $lead_id, $lead, $parsed );

        return $md;
    }

    /**
     * Per-submission operator from operator_assigned events (INTEREST_ASSIGNMENT_V1).
     */
    private static function diag_v51_event_operator_id( array $ev ) {
        $meta = $ev['meta'] ?? array();
        $candidates = array(
            $ev['operator_id'] ?? null,
            $meta['operator_id'] ?? null,
            $meta['to_operator_id'] ?? null,
            $meta['operator']['id'] ?? null,
        );
        foreach ( $candidates as $value ) {
            $id = absint( $value );
            if ( $id > 0 ) {
                return $id;
            }
        }
        return null;
    }

    private static function diag_v51_event_submission_id( array $ev ): int {
        $meta = $ev['meta'] ?? array();
        return absint( $ev['submission_id'] ?? $meta['submission_id'] ?? 0 );
    }

    private static function diag_v51_event_project( array $ev ): string {
        $meta    = $ev['meta'] ?? array();
        $project = (string) ( $meta['project_slug'] ?? $ev['to_status'] ?? '' );
        return $project !== '' ? $project : '—';
    }

    private static function diag_v51_submission_project( $sub, $lead = null, bool $include_lead_fallback = true ): string {
        $row = is_object( $sub ) ? get_object_vars( $sub ) : ( is_array( $sub ) ? $sub : array() );

        $project = (string) ( $row['project_slug'] ?? '' );
        if ( $project !== '' ) {
            return $project;
        }

        $ctx = json_decode( (string) ( $row['context_json'] ?? '' ), true );
        if ( is_array( $ctx ) ) {
            $project = (string) ( $ctx['project_slug'] ?? '' );
            if ( $project !== '' ) {
                return $project;
            }
            $project = (string) ( $ctx['project_area'] ?? '' );
            if ( $project !== '' ) {
                return class_exists( 'Estandar_Leads_Projects' ) ? Estandar_Leads_Projects::resolve_slug( $project ) : $project;
            }
        }

        if ( $include_lead_fallback && $lead ) {
            return (string) ( $lead->project_slug ?? '' );
        }

        return '';
    }

    private static function diag_v51_active_assignment( array $events, array $submissions, $lead ): string {
        $by_sub = array();
        $latest = null;
        foreach ( $events as $ev ) {
            if ( $ev['type'] !== 'operator_assigned' ) {
                continue;
            }
            $latest = $ev;
            $sid = self::diag_v51_event_submission_id( $ev );
            if ( $sid ) {
                $by_sub[ $sid ] = $ev;
            }
        }

        $lead_op_id   = absint( $lead->assigned_operator_id ?? 0 );
        $lead_op_name = (string) ( $lead->assigned_operator_name ?? '' );
        $lead_label   = $lead_op_id ? "#$lead_op_id" . ( $lead_op_name !== '' ? " ($lead_op_name)" : '' ) : 'NULL';
        $md = "- **Persisted active operator:** $lead_label\n";
        if ( $latest ) {
            $meta    = $latest['meta'];
            $op_name = (string) ( $meta['operator_name'] ?? $meta['to_operator_name'] ?? $meta['operator']['name'] ?? '' );
            $op_id   = self::diag_v51_event_operator_id( $latest );
            $label   = $op_name !== '' ? $op_name : ( $op_id ? "#$op_id" : 'UNKNOWN' );
            $md     .= "- **Latest assignment event:** operator: **$label** · project: `" . self::diag_v51_event_project( $latest ) . "` · submission_id=" . ( self::diag_v51_event_submission_id( $latest ) ?: '—' ) . " · [{$latest['created']}]\n";
        } else {
            $md .= "- **Latest assignment event:** (none)\n";
        }
        $md .= "\n";

        if ( empty( $submissions ) ) {
            return $md . "- No submissions.\n";
        }

        foreach ( $submissions as $sub ) {
            $sid     = (int) ( $sub->id ?? 0 );
            $project = self::diag_v51_submission_project( $sub, $lead, true );
            $project = $project !== '' ? $project : '—';
            if ( isset( $by_sub[ $sid ] ) ) {
                $meta    = $by_sub[ $sid ]['meta'];
                $op_name = (string) ( $meta['operator_name'] ?? $meta['to_operator_name'] ?? $meta['operator']['name'] ?? '' );
                $op_id   = self::diag_v51_event_operator_id( $by_sub[ $sid ] );
                $label   = $op_name !== '' ? $op_name : ( $op_id ? "#$op_id" : 'UNKNOWN' );
                $md     .= "- **Submission #$sid** · project: `$project` · operator: **$label** · [{$by_sub[$sid]['created']}]\n";
            } else {
                $md .= "- **Submission #$sid** · project: `$project` · operator: (no submission-level operator_assigned event)\n";
            }
        }

        return $md !== '' ? $md : "- No operator_assigned events found.\n";
    }

    /**
     * ROUTING_NOTIFICATION_EXPLAINABILITY_V1: routing_mode sourced EXCLUSIVELY
     * from `operator_assigned` — the notifier writes routing_mode identically
     * on `operator_assigned` and `operator_notified` (LEAD-INVARIANT-001:
     * assignment happens before the operational-hours gate), but the two
     * events answer different questions: operator_assigned = "which project
     * matched and who was assigned" (routing_mode values: project_match /
     * allowed_project_match / fallback); operator_notified = "was the email
     * actually sent" (its own routing_mode is a byproduct of the same
     * decision, not a second decision — see Notification Delivery below for
     * WHEN/HOW delivery happened). Previously this function merged both event
     * types and let whichever fired last (usually operator_notified, whose
     * routing_mode literal is coarser: 'project'|'fallback') overwrite the
     * more specific value written by operator_assigned — this made
     * "Latest routing_mode" report the wrong granularity. Fixed: only
     * operator_assigned events determine the routing decision.
     */
    private static function diag_v51_routing_decision( array $events, array $submissions ): string {
        $decisions = array();
        foreach ( $events as $ev ) {
            if ( $ev['type'] !== 'operator_assigned' ) {
                continue;
            }
            $mode    = (string) ( $ev['meta']['routing_mode'] ?? '' );
            $project = self::diag_v51_event_project( $ev );
            $sid     = self::diag_v51_event_submission_id( $ev );
            $decisions[] = array(
                'type'    => $ev['type'],
                'mode'    => $mode !== '' ? $mode : 'NULL',
                'project' => $project !== '' ? $project : '—',
                'sid'     => $sid ?: '—',
                'created' => $ev['created'],
            );
        }

        if ( empty( $decisions ) ) {
            return "- No routing events found (operator_assigned).\n- Note: routing_mode is only present in events emitted after ROUTING_MATCH_V2 deploy.\n";
        }

        $md = '';
        foreach ( $decisions as $d ) {
            $flag = ( $d['mode'] === 'fallback' ) ? ' ⚠ FALLBACK' : '';
            $md  .= "- [{$d['created']}] `{$d['type']}` · routing_mode: **{$d['mode']}**{$flag} · project: `{$d['project']}` · submission: {$d['sid']}\n";
        }

        $latest = end( $decisions );
        reset( $decisions );
        $flag   = ( $latest['mode'] === 'fallback' ) ? ' ⚠' : '';
        $md    .= "\n**Latest routing_mode:** {$latest['mode']}{$flag}\n";
        $md    .= "_Source: operator_assigned only — operator_notified never overwrites this decision (see Notification Delivery)._\n";

        return $md;
    }

    /**
     * NOTIFICATION_POLICY_VS_DELIVERY_V1 — Notification Policy block.
     * Answers "which gate/rule fired and why" — a question independent of
     * whether delivery ultimately happened (see diag_notification_delivery()
     * for the observed outcome). Every reason/policy string lives HERE ONLY;
     * Notification Delivery never repeats it, only points back to this block.
     * Grouped by submission_id (same grouping as Delivery, since both gates
     * — business hours and debounce — operate per submission).
     */
    private static function diag_notification_policy( array $events ): string {
        $policy_types = array(
            'operator_notification_deferred', 'operator_notification_consolidated',
            'notification_skipped_by_policy', 'routing_unresolved', 'routing_skipped_by_policy',
        );

        $by_sub = array();
        foreach ( $events as $ev ) {
            if ( ! in_array( $ev['type'], $policy_types, true ) ) {
                continue;
            }
            $sid = self::diag_v51_event_submission_id( $ev );
            $by_sub[ $sid ?: 0 ][] = $ev;
        }

        if ( empty( $by_sub ) ) {
            return "- No notification policy gate intervened for this lead (no deferred/consolidated/skipped events found) — standard dispatch path, no gate to explain.\n";
        }

        $md = '';
        foreach ( $by_sub as $sid => $sub_events ) {
            usort( $sub_events, static function ( $a, $b ) {
                return strcmp( $a['created'], $b['created'] );
            } );

            $md .= "- **Submission " . ( $sid ? "#$sid" : '(unassigned)' ) . "**\n";

            foreach ( $sub_events as $ev ) {
                switch ( $ev['type'] ) {
                    case 'operator_notification_deferred':
                        $reason        = (string) ( $ev['meta']['reason'] ?? 'outside_operational_hours' );
                        $scheduled_for = (string) ( $ev['meta']['scheduled_for'] ?? '—' );
                        $md .= "  - **Business Hours Gate:** applied — reason: `$reason` · scheduled_for: $scheduled_for [" . $ev['created'] . "]\n";
                        $md .= "    _This delay is delivery POLICY (business hours), not a delivery problem — see Notification Delivery below for the observed outcome._\n";
                        break;
                    case 'operator_notification_consolidated':
                        $seconds = $ev['meta']['debounce_seconds'] ?? '—';
                        $md .= "  - **Debounce Consolidation Policy:** applied — window: {$seconds}s [" . $ev['created'] . "]\n";
                        break;
                    case 'notification_skipped_by_policy':
                        $reason = (string) ( $ev['meta']['policy_reason'] ?? $ev['reason'] ?? '' );
                        $md .= "  - **Commercial Qualification — Notification Policy:** SKIP" . ( $reason !== '' ? " (reason: $reason)" : '' ) . " [" . $ev['created'] . "]\n";
                        break;
                    case 'routing_unresolved':
                    case 'routing_skipped_by_policy':
                        $reason = (string) ( $ev['meta']['reason'] ?? $ev['meta']['policy_reason'] ?? $ev['reason'] ?? '' );
                        $md .= "  - **Commercial Qualification — Routing Policy:** BLOCKED" . ( $reason !== '' ? " (reason: $reason)" : '' ) . " [`{$ev['type']}`, " . $ev['created'] . "]\n";
                        break;
                }
            }
        }

        return $md;
    }

    /**
     * NOTIFICATION_POLICY_VS_DELIVERY_V1 — Notification Delivery block.
     * Answers "did the email leave, and when" — the OBSERVED OUTCOME only.
     * Never states a policy reason itself; points back to Notification Policy
     * above for that. Grouped by submission_id, same grouping as Policy.
     *
     * Canonical status vocabulary (fixed 5-name set requested for this axis;
     * a status is only ever emitted when a real es_lead_events row supports
     * it — nothing here is inferred or fabricated):
     *   - 'delivered'          REAL evidence: `operator_notified` event exists.
     *   - 'deferred_by_policy' REAL evidence: `operator_notification_deferred`
     *                          exists with no later `operator_notified`.
     *   - 'scheduled'          NOT a distinct event today. `operator_notification_deferred`
     *                          metadata carries `scheduled_for` (the
     *                          wp_schedule_single_event() timestamp) as an
     *                          informational sub-field of `deferred_by_policy` —
     *                          there is no separate "scheduled" event/state in
     *                          the notifier (class-estandar-leads-notifier.php),
     *                          so this status is never emitted standalone.
     *   - 'queued'             NOT evidenced. No generic notification queue
     *                          exists outside the business-hours gate; the
     *                          only "not yet delivered" state the notifier
     *                          produces is `deferred_by_policy`. Never emitted.
     *   - 'failed'             NOT evidenced. `LEADS_NOTIFIER_WP_MAIL_FAILED`
     *                          (notifier ~line 440) only writes to error_log()
     *                          and the telemetry action — no es_lead_events row
     *                          is ever inserted when wp_mail() fails. Never
     *                          emitted; if a future change adds that event,
     *                          this status becomes real without further design.
     * Statuses OUTSIDE this 5-name set — `not_attempted`, `skipped_by_policy`,
     * `consolidated` — answer a different question ("was an attempt even
     * made") and are kept as their own explicit labels rather than forced
     * into one of the 5 above.
     */
    private static function diag_notification_delivery( array $events ): string {
        $relevant_types = array(
            'operator_notified', 'operator_notification_deferred',
            'operator_notification_consolidated', 'notification_skipped_by_policy',
            'routing_unresolved', 'routing_skipped_by_policy',
        );

        $by_sub = array();
        foreach ( $events as $ev ) {
            if ( ! in_array( $ev['type'], $relevant_types, true ) ) {
                continue;
            }
            $sid = self::diag_v51_event_submission_id( $ev );
            $by_sub[ $sid ?: 0 ][] = $ev;
        }

        if ( empty( $by_sub ) ) {
            return "- No notification delivery events recorded for this lead.\n";
        }

        $md = '';
        foreach ( $by_sub as $sid => $sub_events ) {
            usort( $sub_events, static function ( $a, $b ) {
                return strcmp( $a['created'], $b['created'] );
            } );

            $md .= "- **Submission " . ( $sid ? "#$sid" : '(unassigned)' ) . "**\n";

            $deferred_count = 0;
            $deferred_first = null;
            $delivered      = null;
            $consolidated   = null;
            $skipped_policy = null;
            $blocked        = null;

            foreach ( $sub_events as $ev ) {
                switch ( $ev['type'] ) {
                    case 'operator_notification_deferred':
                        $deferred_count++;
                        if ( $deferred_first === null ) { $deferred_first = $ev; }
                        break;
                    case 'operator_notified':
                        $delivered = $ev; // multi-operator: last insert reflects the batch completing.
                        break;
                    case 'operator_notification_consolidated':
                        $consolidated = $ev;
                        break;
                    case 'notification_skipped_by_policy':
                        $skipped_policy = $ev;
                        break;
                    case 'routing_unresolved':
                    case 'routing_skipped_by_policy':
                        $blocked = $ev;
                        break;
                }
            }

            if ( $blocked ) {
                $md .= "  - **Status:** not_attempted — routing did not resolve an operator (`{$blocked['type']}`) — see Notification Policy above for the reason\n";
                continue;
            }

            if ( $skipped_policy ) {
                $md .= "  - **Status:** skipped_by_policy [" . $skipped_policy['created'] . "] — see Notification Policy above for the reason\n";
                continue;
            }

            if ( $consolidated && ! $delivered ) {
                $md .= "  - **Status:** consolidated — folded into an earlier notification; no separate email sent for this submission [" . $consolidated['created'] . "] — see Notification Policy above\n";
                continue;
            }

            if ( $deferred_count > 0 && $delivered ) {
                $queued_at    = $deferred_first['created'];
                $delivered_at = $delivered['created'];
                $delay        = self::human_delay( $queued_at, $delivered_at );
                $source       = $deferred_count > 1
                    ? "business_hours_queue (requeued {$deferred_count}x — see note)"
                    : 'business_hours_queue';
                $md .= "  - **Status:** delivered\n";
                $md .= "  - **Delivery source:** $source\n";
                $md .= "  - **Queued at:** $queued_at\n";
                $md .= "  - **Delivered at:** $delivered_at\n";
                $md .= "  - **Delay:** $delay — this is delivery POLICY (business hours), not a problem — see Notification Policy above for the gate/reason\n";
                if ( $deferred_count > 1 ) {
                    $md .= "  - _Note: more than one `operator_notification_deferred` event was recorded for this submission before delivery — real re-queueing evidence (e.g. the sweep/cron re-entering the gate), not a fabricated retry count._\n";
                }
                $op = self::diag_v51_event_operator_id( $delivered );
                if ( $op ) { $md .= "  - **Recipient operator_id:** #$op\n"; }
                continue;
            }

            if ( $deferred_count > 0 && ! $delivered ) {
                $md .= "  - **Status:** deferred_by_policy — not yet delivered (deferred {$deferred_count}x), no `operator_notified` event recorded yet — see Notification Policy above for reason/schedule\n";
                $md .= "  - **Queued at:** " . $deferred_first['created'] . "\n";
                continue;
            }

            if ( $delivered ) {
                $md .= "  - **Status:** delivered\n";
                $md .= "  - **Delivery source:** immediate\n";
                $md .= "  - **Delivered at:** " . $delivered['created'] . "\n";
                $op = self::diag_v51_event_operator_id( $delivered );
                if ( $op ) { $md .= "  - **Recipient operator_id:** #$op\n"; }
                continue;
            }

            $md .= "  - **Status:** no delivery evidence for this submission\n";
        }

        return $md !== '' ? $md : "- No notification delivery events recorded for this lead.\n";
    }

    /** UTC timestamp difference rendered as a short human string; 'unknown' if unparseable. */
    private static function human_delay( string $from, string $to ): string {
        try {
            $a = new DateTimeImmutable( $from, new DateTimeZone( 'UTC' ) );
            $b = new DateTimeImmutable( $to, new DateTimeZone( 'UTC' ) );
        } catch ( Exception $e ) {
            return 'unknown';
        }
        $diff_seconds = $b->getTimestamp() - $a->getTimestamp();
        if ( $diff_seconds < 0 ) {
            return 'unknown';
        }
        $minutes = intdiv( $diff_seconds, 60 );
        if ( $minutes < 60 ) {
            return "{$minutes}m";
        }
        $hours = intdiv( $minutes, 60 );
        $rem_m = $minutes % 60;
        return "{$hours}h{$rem_m}m";
    }

    /**
     * ROUTING_NOTIFICATION_EXPLAINABILITY_V1 — Behavior Evidence block.
     * Aggregates autoresponse telemetry and explains `autoresponse_engaged`
     * with its triggering evidence (record_engagement_if_new() in
     * class-estandar-leads-autoresponse.php fires it on the FIRST pdf/landing
     * click or whatsapp redirect). Golden rule: the stored event only carries
     * `resource_type` (pdf|landing|whatsapp), not a per-URL identifier, so
     * "unique links" in the URL sense is NOT calculable — this reports
     * distinct resource TYPES instead of inventing a link-level uniqueness
     * the data cannot support.
     */
    private static function diag_behavior_evidence( array $events ): string {
        $opens = 0; $clicks = 0; $sent = 0; $failed = 0; $skipped = 0;
        $resource_types = array();
        $engaged_events = array();
        $classified_opens = array();

        foreach ( $events as $ev ) {
            switch ( $ev['type'] ) {
                case 'autoresponse_email_sent':
                    $sent++;
                    break;
                case 'autoresponse_email_failed':
                    $failed++;
                    break;
                case 'autoresponse_skipped':
                    $skipped++;
                    break;
                case 'autoresponse_email_open_signal':
                    $opens++;
                    break;
                case 'autoresponse_open_classified':
                    $cl = (string) ( $ev['meta']['open_classification'] ?? '' );
                    if ( $cl !== '' ) {
                        $classified_opens[] = $cl;
                    }
                    break;
                case 'autoresponse_link_click':
                    $clicks++;
                    $rt = (string) ( $ev['meta']['resource_type'] ?? '' );
                    if ( $rt !== '' ) { $resource_types[ $rt ] = true; }
                    break;
                case 'autoresponse_engaged':
                    $engaged_events[] = $ev;
                    break;
            }
        }

        if ( $sent === 0 && $failed === 0 && $skipped === 0 && $opens === 0 && $clicks === 0 && empty( $engaged_events ) && empty( $classified_opens ) ) {
            return "- No autoresponse/behavior telemetry recorded for this lead.\n";
        }

        $md  = "- **Autoresponse sent:** $sent · **failed:** $failed · **skipped:** $skipped\n";
        $md .= "- **Email opens (referencial signal — never a KPI, may be a mail-client auto-scanner):** $opens\n";

        if ( ! empty( $classified_opens ) ) {
            $md .= "- **Open evidence classification:**\n";
            $tally = array_count_values( $classified_opens );
            foreach ( $tally as $class => $count ) {
                $label = self::open_class_label( $class );
                $md .= "  - $label: $count\n";
            }
        }

        $md .= "- **Link clicks (total events):** $clicks\n";
        if ( $clicks > 0 ) {
            $md .= "- **Distinct resource types clicked:** " . count( $resource_types ) . ' (' . implode( ', ', array_keys( $resource_types ) ) . ")\n";
            $md .= "  _Note: per-URL uniqueness is not calculable from stored data (events record `resource_type`, not a per-link identifier) — this counts distinct resource types (pdf/landing/whatsapp), not distinct URLs. Field intentionally omitted rather than estimated._\n";
        } else {
            $md .= "- **Distinct resource types clicked:** not applicable — no link clicks recorded\n";
        }

        if ( empty( $engaged_events ) ) {
            $md .= "- **autoresponse_engaged:** not recorded\n";
        } else {
            foreach ( $engaged_events as $ev ) {
                $channel = (string) ( $ev['meta']['channel'] ?? 'unknown' );
                $md .= "- **autoresponse_engaged** [" . $ev['created'] . "] — evidence: first `$channel` interaction\n";
            }
        }

        return $md;
    }

    private static function open_class_label( string $class ): string {
        $labels = array(
            'technical_fetch'              => 'technical_fetch (pre-fetch/scanner — no scoring)',
            'probable_machine_open'        => 'probable_machine_open (ambiguous — no scoring)',
            'probable_human_open'          => 'probable_human_open (likely human — contributes to score)',
            'confirmed_human_engagement'   => 'confirmed_human_engagement (confirmed by click — contributes to score)',
        );
        return $labels[ $class ] ?? $class;
    }

    /**
     * Compare lead.assigned_operator_id vs latest operator_assigned event.
     */
    private static function diag_v51_operator_consistency( array $events, $lead ): string {
        $lead_op_id   = absint( $lead->assigned_operator_id ?? 0 );
        $lead_op_name = (string) ( $lead->assigned_operator_name ?? '' );

        $latest_event_op_id   = null;
        $latest_event_op_name = '';
        $latest_sid           = null;
        $latest_event_seen    = false;
        foreach ( $events as $ev ) {
            if ( $ev['type'] !== 'operator_assigned' ) {
                continue;
            }
            $latest_event_seen    = true;
            $latest_event_op_id   = self::diag_v51_event_operator_id( $ev );
            $latest_event_op_name = (string) ( $ev['meta']['operator_name'] ?? $ev['meta']['to_operator_name'] ?? $ev['meta']['operator']['name'] ?? '' );
            $latest_sid           = self::diag_v51_event_submission_id( $ev );
        }

        $lead_label = $lead_op_id ? "$lead_op_id" . ( $lead_op_name !== '' ? " ($lead_op_name)" : '' ) : 'NULL';
        $md = "- **lead.assigned_operator_id:** $lead_label\n";

        if ( ! $latest_event_seen ) {
            $md .= "- **Latest operator_assigned event:** (none)\n";
            $md .= "- **Consistency:** UNKNOWN — no INTEREST_ASSIGNMENT_V1 events present\n";
            return $md;
        }

        if ( $latest_event_op_id === null ) {
            $md .= "- **Latest operator_assigned event:** op=UNKNOWN" . ( $latest_event_op_name !== '' ? " ($latest_event_op_name)" : '' ) . " · submission_id=" . ( $latest_sid ?: '—' ) . "\n";
            $md .= "- **Consistency:** UNKNOWN — latest operator_assigned event lacks parseable operator_id\n";
            return $md;
        }

        $event_label = "$latest_event_op_id" . ( $latest_event_op_name !== '' ? " ($latest_event_op_name)" : '' );
        $md .= "- **Latest operator_assigned event:** op=$event_label · submission_id=" . ( $latest_sid ?: '—' ) . "\n";

        if ( ! $lead_op_id ) {
            $md .= "- **Consistency:** UNKNOWN — lead row has no persisted operator_id\n";
        } elseif ( $lead_op_id === $latest_event_op_id ) {
            $md .= "- **Consistency:** CONSISTENT\n";
        } else {
            $md .= "- **Consistency:** INCONSISTENT — lead row has op_id=$lead_op_id, latest submission event has op_id=$latest_event_op_id\n";
            $md .= "  _Note: sync_assigned_operator() writes globally (last submission wins); operator_assigned events are per-submission (INTEREST_ASSIGNMENT_V1)._\n";
        }

        return $md;
    }

    /**
     * lead.project_slug vs project_slug across all submissions.
     */
    private static function diag_v51_project_drift( $lead, array $submissions ): string {
        $lead_project = (string) ( $lead->project_slug ?? '' );
        $md = "- **lead.project_slug:** " . ( $lead_project !== '' ? "`$lead_project`" : '(empty)' ) . "\n";

        if ( empty( $submissions ) ) {
            return $md . "- No submissions.\n";
        }

        $sub_projects = array();
        foreach ( $submissions as $sub ) {
            $p            = self::diag_v51_submission_project( $sub, $lead, false );
            $sub_projects[] = $p !== '' ? $p : '(empty)';
            $label        = $p !== '' ? "`$p`" : '(empty)';
            $date         = (string) ( $sub->submitted_at ?: $sub->created_at ?: '' );
            $md          .= "- **Submission #" . $sub->id . ":** $label" . ( $date !== '' ? " · [$date]" : '' ) . "\n";
        }

        $unique = array_unique( $sub_projects );
        sort( $unique );

        if ( count( $unique ) > 1 ) {
            $list = implode( ', ', array_map( function ( $p ) { return "`$p`"; }, $unique ) );
            $md  .= "\n**Status:** MULTI_PROJECT — " . count( $unique ) . " distinct projects: $list\n";
        } elseif ( count( $unique ) === 1 && $lead_project !== '' && $unique[0] !== $lead_project ) {
            $md .= "\n**Status:** DRIFT — lead.project_slug=`$lead_project` differs from submission project `{$unique[0]}`\n";
        } else {
            $md .= "\n**Status:** CONSISTENT\n";
        }

        return $md;
    }

    /**
     * Whether the campaign ID has a human label via CAMPAIGN_MAPPINGS_V1.
     */
    private static function diag_v51_campaign_resolution( $lead, $submission ): string {
        $utm_campaign = '';
        if ( $submission ) {
            $ctx          = json_decode( (string) ( $submission->context_json ?? '' ), true );
            $utm_campaign = is_array( $ctx ) ? (string) ( $ctx['utm_campaign'] ?? '' ) : '';
            if ( $utm_campaign === '' ) {
                $utm_campaign = (string) ( $submission->utm_campaign ?? '' );
            }
        }
        if ( $utm_campaign === '' ) {
            $utm_campaign = (string) ( $lead->first_utm_campaign ?? '' );
        }
        $utm_campaign = trim( $utm_campaign );

        if ( $utm_campaign === '' ) {
            return "- **utm_campaign:** (none)\n- **Resolution:** N/A\n";
        }

        $md = "- **utm_campaign raw:** `$utm_campaign`\n";

        if ( class_exists( 'Estandar_Campaign_Label_Resolver' ) ) {
            $store  = Estandar_Campaign_Label_Resolver::get_store();
            $entry  = $store['campaigns'][ $utm_campaign ] ?? null;
            $mapped = $entry !== null
                && ( ! is_array( $entry ) || ! array_key_exists( 'active', $entry ) || ! empty( $entry['active'] ) );
            if ( $mapped ) {
                $label = Estandar_Campaign_Label_Resolver::campaign( $utm_campaign );
                $md   .= "- **Label:** $label\n";
                $md   .= "- **Resolution:** RESOLVED\n";
            } else {
                $md .= "- **Label:** (none)\n";
                $md .= "- **Resolution:** UNRESOLVED — raw ID visible in reports · add via Leads → Campañas → Discovery Queue\n";
            }
        } elseif ( self::looks_like_campaign_id( $utm_campaign ) ) {
            $md .= "- **Resolution:** UNRESOLVED (Estandar_Campaign_Label_Resolver not available)\n";
        } else {
            $md .= "- **Resolution:** UTM_NAME (not a numeric platform ID)\n";
        }

        return $md;
    }

    /**
     * source_plugin with human label for each submission.
     */
    private static function diag_v51_capture_method( array $submissions ): string {
        if ( empty( $submissions ) ) {
            return "- No submissions.\n";
        }

        $resolve = static function ( string $plugin ): string {
            $p = strtolower( sanitize_key( $plugin ) );
            if ( strpos( $p, 'chatbot' ) !== false ) { return 'Chatbot'; }
            if ( strpos( $p, 'forms' ) !== false )   { return 'Formulario'; }
            if ( strpos( $p, 'whatsapp' ) !== false || strpos( $p, '-wa-' ) !== false ) { return 'WhatsApp'; }
            if ( strpos( $p, 'manual' ) !== false )  { return 'Manual'; }
            if ( strpos( $p, 'api' ) !== false )     { return 'API'; }
            return $p !== '' ? $p : 'Desconocido';
        };

        $tally = array();
        foreach ( $submissions as $sub ) {
            $method          = $resolve( (string) ( $sub->source_plugin ?? '' ) );
            $tally[ $method ] = ( $tally[ $method ] ?? 0 ) + 1;
        }

        $latest        = end( $submissions );
        reset( $submissions );
        $latest_src    = (string) ( $latest->source_plugin ?? '' );
        $latest_method = $resolve( $latest_src );

        $md  = "- **Latest source_plugin:** " . ( $latest_src !== '' ? "`$latest_src`" : '(empty)' ) . "\n";
        $md .= "- **Latest capture method:** $latest_method\n";
        if ( count( $submissions ) > 1 ) {
            $parts = array();
            foreach ( $tally as $method => $count ) {
                $parts[] = "$method ($count)";
            }
            $md .= "- **All submissions:** " . implode( ' · ', $parts ) . "\n";
        }

        return $md;
    }

    /**
     * OPERATOR_RESPONSE_SLA_V1 — full SLA timeline diagnostics.
     * Reads all relevant events (capture/deferral/delivery/contact) and renders
     * a chronological trace with all computed gaps. No historical timestamps
     * are rewritten — purely read-only over existing events.
     */
    private static function diag_operator_sla_timeline( int $lead_id, $lead, array $events ): string {
        if ( class_exists( 'Estandar_Leads_QA_Policy' ) && Estandar_Leads_QA_Policy::is_test_lead( $lead_id ) ) {
            return "- **SLA status:** excluded_test\n";
        }
        $captured_at = (string) ( $lead->created_at ?? ( $lead->first_seen_at ?? '' ) );
        $sla = self::operator_response_sla_metrics( $lead_id, $captured_at );

        $md  = "- **Captured at:** " . self::ts_display( $sla['captured_at'] !== '' ? $sla['captured_at'] : $captured_at ) . "\n";
        $md .= "- **Lead age:** " . self::hours_since( $captured_at ) . "h (since capture)\n";

        if ( $sla['queued_hours'] > 0 ) {
            $deferred_events = array_filter( array_values( $events ), static function ( $ev ) {
                return $ev['type'] === 'operator_notification_deferred';
            } );
            if ( ! empty( $deferred_events ) ) {
                $first = $deferred_events[0];
                $md .= "- **Enqueued at:** " . self::ts_display( (string) ( $first['created'] ?? '' ) ) . "\n";
                $md .= "- **Deferral reason:** " . $sla['deferral_reason'] . "\n";
                $md .= "- **Time in queue:** " . $sla['queued_hours'] . "h\n";
            }
        }

        $md .= "- **Operator SLA started at:** " . self::ts_display( $sla['started_at'] ) . "\n";
        $md .= "- **Operator SLA elapsed:** " . $sla['sla_elapsed_hours'] . "h\n";
        $md .= "- **Operator SLA remaining:** " . $sla['sla_remaining_hours'] . "h\n";

        if ( $sla['contacted_at'] !== '' ) {
            $md .= "- **Contact registered at:** " . self::ts_display( $sla['contacted_at'] ) . "\n";
            $contacted_ts = strtotime( $sla['contacted_at'] );
            $started_ts   = strtotime( $sla['started_at'] );
            if ( $contacted_ts && $started_ts && $contacted_ts > $started_ts ) {
                $response_h = (int) floor( ( $contacted_ts - $started_ts ) / HOUR_IN_SECONDS );
                $md .= "- **Response time (delivery→contact):** " . $response_h . "h\n";
            }
        }

        $sla_due_label = '';
        if ( $sla['started_at'] !== '' ) {
            $started_ts = strtotime( $sla['started_at'] );
            if ( $started_ts ) {
                $due_ts = $started_ts + ( 48 * HOUR_IN_SECONDS );
                $sla_due_label = self::ts_display( gmdate( 'Y-m-d H:i:s', $due_ts ) );
            }
        }
        $md .= "- **SLA due at:** " . ( $sla_due_label !== '' ? $sla_due_label : 'N/A (SLA not started)' ) . "\n";
        $md .= "- **Status:** " . $sla['status_label'] . "\n";

        return $md;
    }

    /**
     * Deterministic operational recommendation — no AI, no behavior changes.
     */
    private static function diag_v51_recommended_action( $lead, array $submissions, array $events ): string {
        $actions = array();

        $lead_op_id = (int) ( $lead->assigned_operator_id ?? 0 );
        if ( ! $lead_op_id ) {
            $actions[] = array( 'p' => 1, 'code' => 'ASSIGN_OPERATOR', 'label' => 'Lead sin operador asignado.' );
        }

        $quality     = trim( (string) ( $lead->quality ?? '' ) );
        $sales_stage = trim( (string) ( $lead->sales_stage ?? '' ) );
        $fb_done     = ! empty( $lead->feedback_completed );
        if ( ! $fb_done && $quality === '' && $sales_stage === '' ) {
            $actions[] = array( 'p' => 2, 'code' => 'CLASSIFY_LEAD', 'label' => 'Sin quality ni sales_stage registrados.' );
        }

        $sub_projects = array_unique( array_map( function ( $s ) use ( $lead ) { return self::diag_v51_submission_project( $s, $lead, false ); }, $submissions ) );
        if ( count( $sub_projects ) > 1 ) {
            $actions[] = array( 'p' => 3, 'code' => 'REVIEW_PROJECT', 'label' => 'Submissions en ' . count( $sub_projects ) . ' proyectos — revisar proyecto activo.' );
        }

        foreach ( $events as $ev ) {
            if ( in_array( $ev['type'], array( 'operator_assigned', 'operator_notified' ), true )
                 && ( $ev['meta']['routing_mode'] ?? '' ) === 'fallback' ) {
                $actions[] = array( 'p' => 4, 'code' => 'CHECK_ROUTING', 'label' => 'Routing fallback usado — verificar operadores del proyecto.' );
                break;
            }
        }

        $utm = trim( (string) ( $lead->first_utm_campaign ?? '' ) );
        if ( $utm !== '' && self::looks_like_campaign_id( $utm ) ) {
            $resolved = false;
            if ( class_exists( 'Estandar_Campaign_Label_Resolver' ) ) {
                $store    = Estandar_Campaign_Label_Resolver::get_store();
                $entry    = $store['campaigns'][ $utm ] ?? null;
                $resolved = $entry !== null
                    && ( ! is_array( $entry ) || ! array_key_exists( 'active', $entry ) || ! empty( $entry['active'] ) );
            }
            if ( ! $resolved ) {
                $actions[] = array( 'p' => 5, 'code' => 'LABEL_CAMPAIGN', 'label' => "Campaña `$utm` sin etiqueta — agregar en Leads → Campañas." );
            }
        }

        if ( empty( $actions ) ) {
            $fb_status = (string) ( $lead->feedback_status ?? '' );
            if ( $fb_done || $fb_status === 'complete' || $fb_status === 'received' ) {
                return "- **Action:** COMPLETE — lead clasificado, sin anomalías detectadas.\n";
            }
            return "- **Action:** FOLLOW_UP — sin anomalías; continuar seguimiento normal.\n";
        }

        usort( $actions, function ( $a, $b ) { return $a['p'] - $b['p']; } );

        $md = '';
        foreach ( $actions as $idx => $a ) {
            if ( $idx === 0 ) {
                $md .= "- **→ [{$a['code']}]** {$a['label']}\n";
            } else {
                $md .= "- [{$a['code']}] {$a['label']}\n";
            }
        }
        return $md;
    }
}
