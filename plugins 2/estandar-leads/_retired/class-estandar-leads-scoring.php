<?php
/**
 * BEHAVIORAL_SCORE_V1 — substrate (Fase A).
 *
 * Lead-level evolving score, additive, no new tables, no schema migration beyond
 * the additive columns on es_leads (behavioral_score, temperature, priority,
 * score_updated_at, score_components_json).
 *
 * BASE = wC·CAPTURE + wQ·QUALIFICATION + wB·BEHAVIOR, weighted AVERAGE over
 *        whichever of {CAPTURE, QUALIFICATION} are present (reweight when
 *        absent); BEHAVIOR always participates — 0 is a valid value (no
 *        prospect signal), never excluded — capture-only leads cannot score
 *        CALIENTE. wC=0.30 · wQ=0.40 · wB=0.30.
 * TOTAL = clamp( BASE + wP·PROFILE + wI·INTENT , 0, 100 )   wP=0.20 · wI=0.10
 *   MONOTONIC_BLEND_FIX_V1 (2026-07-17): PROFILE and INTENT are optional
 *   *commercial-evidence* signals layered as a positive ADDITIVE boost on
 *   top of BASE, not folded into the same weighted-average denominator.
 *   Folding them in let a genuinely positive but moderate signal (profile/
 *   intent points are intentionally capped low) DROP the total whenever it
 *   scored below the pre-existing BASE average (e.g. capture=100, behavior=0
 *   → BASE=50; blending in a profile/intent scoring 15-20 dragged the
 *   average DOWN to ~30-38 instead of up — see INTENT_SCORE_CALIBRATION_V1).
 *   The commercial contract requires the opposite: positive evidence must
 *   never lower the score. BASE itself (capture/qualification/behavior) is
 *   completely unchanged — leads without a profile/intent (all historical
 *   leads, and every lead before this fix) compute byte-identical totals.
 *
 * Behavior counts ONLY prospect signals from es_lead_events; internal actors
 * (admin/operator/supervisor) are excluded (OBSERVABILITY_ACTOR_SEMANTICS).
 *
 * recompute() never inserts events — it only UPDATEs es_leads — so the
 * estandar_lead_event_recorded → recompute path cannot recurse.
 *
 * calculate() is a pure function (no WP/DB) so it is unit-testable standalone.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Scoring
{
    const W_CAPTURE       = 0.30;
    const W_QUALIFICATION = 0.40;
    const W_BEHAVIOR      = 0.30;
    // COMMERCIAL_PROFILE_V1: derived from captured commercial fields
    // (Estandar_Leads_Commercial_Profile). Absent when null (never
    // fabricated). MONOTONIC_BLEND_FIX_V1: added as a positive ADDITIVE
    // boost on top of BASE (capture/qualification/behavior), not blended
    // into the same weighted average — see class header. Absent → 0 boost,
    // identical to before this component existed.
    const W_PROFILE       = 0.20;
    // INTENT_SCORE_V1: moderate, configurable contribution from chatbot
    // lead_intent (Estandar_Leads_Intent_Score). Absent/unmapped intent →
    // null → 0 boost (never fabricated, never enough alone to force
    // CALIENTE — same additive-boost treatment as W_PROFILE, see class
    // header). Toggle: `estandar_leads_intent_scoring_enabled` option
    // (default enabled).
    const W_INTENT         = 0.10;

    const DEFAULT_HOT  = 70;
    const DEFAULT_WARM = 40;

    /** Reusable engine metadata (single source for renderers/exports). */
    const ENGINE_VERSION = 'BEHAVIORAL_SCORE_V1';

    const TEMP_HOT  = 'CALIENTE';
    const TEMP_WARM = 'TIBIO';
    const TEMP_COLD = 'FRIO';

    const PRIO_CRITICAL = 'CRITICA';
    const PRIO_HIGH     = 'ALTA';
    const PRIO_MEDIUM   = 'MEDIA';
    const PRIO_LOW      = 'BAJA';

    /** Internal actors whose events never count as prospect behavior. */
    const INTERNAL_ACTORS = array('user', 'admin', 'system', 'operator', 'supervisor');

    /** Terminal lead states → score 0 (closed, nothing to prioritize). */
    const TERMINAL_STATUSES = array('CONVERTED', 'LOST');
    const TERMINAL_STAGES   = array('CONVERTED', 'LOST', 'INVALID');

    /**
     * Prospect behavioral signals: weight + decay half-life (days) + optional cap.
     * Operator-side contact actions (whatsapp_clicked/email_clicked/call_clicked)
     * are intentionally absent here — they are operational, not prospect calor.
     */
    public static function behavior_weights(): array
    {
        return array(
            'lead_recontacted'               => array('w' => 18, 'halflife' => 14),
            'autoresponse_engaged'           => array('w' => 15, 'halflife' => 7),
            'autoresponse_link_click'        => array('w' => 12, 'halflife' => 7),
            'autoresponse_email_open_signal' => array('w' => 3,  'halflife' => 3, 'cap' => 6),
            // OPEN_EVIDENCE_V1 — classified opens: only probable_human_open and
            // confirmed_human_engagement carry weight; technical_fetch and
            // probable_machine_open are excluded via score_eligible=false in
            // event metadata.
            'autoresponse_open_classified'   => array('w' => 6, 'halflife' => 3, 'cap' => 12),
        );
    }

    /** Event types whose insertion should trigger a recompute (cheap whitelist). */
    public static function recompute_on(): array
    {
        return array_merge(
            array_keys(self::behavior_weights()),
            array(
                'status_changed', 'quality_changed', 'commercial_stage_changed',
                'feedback_status_changed', 'contact_attempt', 'contact_logged',
                'lead_lost_reason_set', 'whatsapp_clicked', 'email_clicked', 'call_clicked',
            )
        );
    }

    public static function init(): void
    {
        // Seed on capture/recontact (reuses the existing ingestion hook — no edit to ingestion).
        add_action('estandar_lead_ingested', array(__CLASS__, 'on_lead_ingested'), 20, 4);

        // Recompute on relevant behavioral / operational events.
        add_action('estandar_lead_event_recorded', array(__CLASS__, 'on_event_recorded'), 20, 4);

        // Time-decay sweep — reuses the existing compliance daily cron (no edit to compliance).
        $cron = class_exists('Estandar_Leads_Compliance')
            ? Estandar_Leads_Compliance::CRON_HOOK
            : 'estandar_leads_compliance_daily';
        add_action($cron, array(__CLASS__, 'run_decay_sweep'), 20);
    }

    public static function on_lead_ingested($lead_id, $submission_id = 0, $is_new = true, $payload = array()): void
    {
        if (!$is_new && class_exists('Estandar_Leads_QA_Policy') && !empty(Estandar_Leads_QA_Policy::from_payload(is_array($payload) ? $payload : array())['is_test_lead'])) {
            return;
        }
        self::recompute((int) $lead_id);
    }

    public static function on_event_recorded($event_id, $lead_id = 0, $event_type = '', $metadata = array()): void
    {
        if (!in_array((string) $event_type, self::recompute_on(), true)) {
            return;
        }
        self::recompute((int) $lead_id);
    }

    // ─── Pure calculation (no WP/DB) ──────────────────────────────────────────

    /**
     * @param array $in {
     *   capture:        int|null   0–100 or null if absent
     *   qualification:  int|null   0–100 or null if absent
     *   behavior_events array      [ ['type'=>, 'actor_type'=>, 'age_days'=>float], ... ]
     *   is_valid_lead:  int|null   0|1|null
     *   quality:        string     HOT|WARM|COLD|JUNK|''
     *   status:         string
     *   sales_stage:    string
     *   gestion:        array      ['contact_attempts'=>int,'feedback_completed'=>0|1,'workspace_opened'=>bool]
     *   thresholds:     array      ['hot'=>int,'warm'=>int]
     * }
     * @return array ['total'=>int,'temperature'=>string,'priority'=>string,'components'=>array]
     */
    public static function calculate(array $in): array
    {
        $thresholds = is_array($in['thresholds'] ?? null) ? $in['thresholds'] : array();
        $hot  = isset($thresholds['hot'])  ? (int) $thresholds['hot']  : self::DEFAULT_HOT;
        $warm = isset($thresholds['warm']) ? (int) $thresholds['warm'] : self::DEFAULT_WARM;

        $capture = isset($in['capture']) && $in['capture'] !== null ? self::clamp((int) $in['capture']) : null;
        $qual    = isset($in['qualification']) && $in['qualification'] !== null ? self::clamp((int) $in['qualification']) : null;
        $profile = is_array($in['profile'] ?? null) ? $in['profile'] : null;
        $profile_score = ($profile !== null && isset($profile['score'])) ? self::clamp((int) $profile['score']) : null;
        $profile_high_intent = $profile !== null && !empty($profile['high_intent']);
        $intent = is_array($in['intent'] ?? null) ? $in['intent'] : null;
        $intent_score = ($intent !== null && isset($intent['score'])) ? self::clamp((int) $intent['score']) : null;

        // 1) Invalid / terminal guard → score 0, preserving capture state.
        $is_valid = $in['is_valid_lead'] ?? null;
        $quality  = strtoupper((string) ($in['quality'] ?? ''));
        $status   = strtoupper((string) ($in['status'] ?? ''));
        $stage    = strtoupper((string) ($in['sales_stage'] ?? ''));

        $reason = '';
        if (array_key_exists('score_eligible', $in) && empty($in['score_eligible'])) {
            $reason = 'test';
        } elseif ($is_valid === 0 || $is_valid === '0' || $quality === 'JUNK') {
            $reason = 'invalid';
        } elseif (in_array($status, self::TERMINAL_STATUSES, true) || in_array($stage, self::TERMINAL_STAGES, true)) {
            $reason = 'terminal';
        }
        if ($reason !== '') {
            return array(
                'total'       => 0,
                'temperature' => self::TEMP_COLD,
                'priority'    => self::PRIO_LOW,
                'components'  => array(
                    'total' => 0, 'capture' => $capture, 'qualification' => $qual, 'behavior' => 0,
                    'profile' => $profile_score, 'intent' => $intent_score,
                    'excluded' => $reason, 'temperature' => self::TEMP_COLD, 'priority' => self::PRIO_LOW,
                    'priority_reason' => $reason,
                ),
            );
        }

        // 2) Components. OPEN_EVIDENCE_V1: clasificar antes de puntuar — el
        // sanitizer elimina zero-point (technical_fetch/scanner/prefetch) y
        // previene double-count raw+classified antes de otorgar puntos.
        $behavior = self::behavior_score(self::sanitize_behavior_events((array) ($in['behavior_events'] ?? array())));
        $behavior_value = $behavior['value'];

        // 3) BASE weighted average — capture/qualification reweight when
        //    absent (null); behavior is always measurable — 0 = "sin señal
        //    del prospecto", a valid value that must weigh in (capture-only
        //    leads are not CALIENTE). Byte-identical to the pre-MONOTONIC_
        //    BLEND_FIX_V1 formula for capture/qualification/behavior.
        $sum = 0.0;
        $wsum = 0.0;
        if ($capture !== null) { $sum += self::W_CAPTURE * $capture;    $wsum += self::W_CAPTURE; }
        if ($qual    !== null) { $sum += self::W_QUALIFICATION * $qual; $wsum += self::W_QUALIFICATION; }
        $sum += self::W_BEHAVIOR * $behavior_value;
        $wsum += self::W_BEHAVIOR;

        $base_total = $wsum > 0 ? ($sum / $wsum) : 0.0;

        // 3b) MONOTONIC_BLEND_FIX_V1 — profile/intent are additive positive
        //     boosts on top of BASE, never blended into its denominator.
        //     Absent (null) → 0 boost, so a lead with neither is completely
        //     unaffected (base_total === old $total exactly). Present with a
        //     positive score → total strictly increases (clamp(100) is the
        //     only documented ceiling — saturation, not punishment).
        $boost = 0.0;
        if ($profile_score !== null) { $boost += self::W_PROFILE * $profile_score; }
        if ($intent_score  !== null) { $boost += self::W_INTENT  * $intent_score; }

        $total = self::clamp((int) round($base_total + $boost));

        // 4) Temperature (with safety override: recontact + recent deep engagement → CALIENTE).
        $temperature = $total >= $hot ? self::TEMP_HOT : ($total >= $warm ? self::TEMP_WARM : self::TEMP_COLD);
        $override = false;
        if ($behavior['has_recontact'] && $behavior['deep_engagement'] && $temperature !== self::TEMP_HOT) {
            $temperature = self::TEMP_HOT;
            $override = true;
        }

        // 5) Priority = temperature × desatención × señal.
        $gestion = is_array($in['gestion'] ?? null) ? $in['gestion'] : array();
        $contact_attempts   = (int) ($gestion['contact_attempts'] ?? 0);
        $feedback_completed = (int) ($gestion['feedback_completed'] ?? 0);
        $workspace_opened   = !empty($gestion['workspace_opened']);
        $sin_gestion = ($contact_attempts === 0 && $feedback_completed === 0);

        list($priority, $priority_reason) = self::priority_for(
            $temperature, $sin_gestion, $behavior['has_recontact'], $workspace_opened,
            $behavior['deep_engagement'], $profile_high_intent
        );

        return array(
            'total'       => $total,
            'temperature' => $temperature,
            'priority'    => $priority,
            'components'  => array(
                'total'           => $total,
                'capture'         => $capture,
                'qualification'   => $qual,
                'behavior'        => $behavior_value,
                'behavior_detail' => $behavior['detail'],
                'profile'         => $profile_score,
                'profile_id'      => $profile !== null ? (string) ($profile['profile_id'] ?? '') : '',
                'profile_detail'  => $profile !== null ? (array) ($profile['signals'] ?? array()) : array(),
                'profile_high_intent' => $profile_high_intent,
                'intent'          => $intent_score,
                'intent_id'       => $intent !== null ? (string) ($intent['intent'] ?? '') : '',
                'intent_detail'   => $intent !== null ? (string) ($intent['reason'] ?? '') : '',
                'weights_used'    => array(
                    'capture'       => $capture !== null ? self::W_CAPTURE : 0,
                    'qualification' => $qual !== null ? self::W_QUALIFICATION : 0,
                    'profile'       => $profile_score !== null ? self::W_PROFILE : 0,
                    'intent'        => $intent_score !== null ? self::W_INTENT : 0,
                    'behavior'      => self::W_BEHAVIOR,
                ),
                'temperature'     => $temperature,
                'temperature_override' => $override,
                'priority'        => $priority,
                'priority_reason' => $priority_reason,
            ),
        );
    }

    private static function behavior_score(array $events): array
    {
        $weights = self::behavior_weights();
        $sum = 0.0;
        $caps = array();      // per-type capped accumulation
        $detail = array();
        $has_recontact = false;
        $deep_engagement = false;

        foreach ($events as $ev) {
            $type  = (string) ($ev['type'] ?? '');
            $actor = (string) ($ev['actor_type'] ?? '');
            if (!isset($weights[$type])) {
                continue;
            }
            if (in_array($actor, self::INTERNAL_ACTORS, true) || (array_key_exists('score_eligible', $ev) && empty($ev['score_eligible']))) {
                continue; // exclude admin/operator/supervisor
            }
            $w        = (float) $weights[$type]['w'];
            $halflife = (float) $weights[$type]['halflife'];
            $age      = (float) ($ev['age_days'] ?? 0);
            $mult     = self::decay_multiplier($age, $halflife);
            if ($mult <= 0) {
                continue;
            }
            $contrib = $w * $mult;

            if (isset($weights[$type]['cap'])) {
                $cap = (float) $weights[$type]['cap'];
                $caps[$type] = min($cap, ($caps[$type] ?? 0) + $contrib);
            } else {
                $sum += $contrib;
            }

            if ($type === 'lead_recontacted') {
                $has_recontact = true;
            }
            if ($type === 'autoresponse_engaged' || $type === 'autoresponse_link_click') {
                $deep_engagement = true;
            }
            $detail[] = array('type' => $type, 'actor' => $actor, 'age_days' => round($age, 2), 'mult' => $mult, 'contrib' => round($contrib, 2));
        }

        foreach ($caps as $capped) {
            $sum += $capped;
        }

        return array(
            'value'           => self::clamp((int) round($sum)),
            'detail'          => $detail,
            'has_recontact'   => $has_recontact,
            'deep_engagement' => $deep_engagement,
        );
    }

    /** Bucketed decay: full weight if fresh, half past halflife/2, zero past halflife. */
    private static function decay_multiplier(float $age_days, float $halflife): float
    {
        if ($halflife <= 0) {
            return 1.0;
        }
        if ($age_days <= 0) {
            return 1.0;
        }
        if ($age_days < $halflife / 2) {
            return 1.0;
        }
        if ($age_days < $halflife) {
            return 0.5;
        }
        return 0.0;
    }

    /**
     * Priority = temperature × desatención × señal. Operational only — it never
     * feeds qualification nor ads platforms.
     *
     * OPERATIONAL_PRIORITY_V2 (additive):
     *  - $deep_engagement = recent strong prospect engagement
     *    (autoresponse_link_click / autoresponse_engaged inside decay window) —
     *    a click weighs more than an open both in score and here.
     *  - $profile_high_intent = COMMERCIAL_PROFILE_V1 high-intent rule
     *    (company operating + provider in play + immediate start).
     * Every branch returns an explicit reason (auditable breakdown).
     */
    private static function priority_for(string $temperature, bool $sin_gestion, bool $has_recontact, bool $workspace_opened, bool $deep_engagement = false, bool $profile_high_intent = false): array
    {
        if ($temperature === self::TEMP_HOT) {
            if ($sin_gestion && ($has_recontact || $workspace_opened || $deep_engagement)) {
                return array(self::PRIO_CRITICAL, 'caliente_sin_gestion_con_senal');
            }
            if ($sin_gestion) {
                return array(self::PRIO_HIGH, 'caliente_sin_gestion');
            }
            return array(self::PRIO_MEDIUM, 'caliente_en_gestion');
        }
        if ($temperature === self::TEMP_WARM) {
            if ($has_recontact && $sin_gestion) {
                return array(self::PRIO_HIGH, 'tibio_recontacto_sin_gestion');
            }
            if ($deep_engagement && $sin_gestion) {
                return array(self::PRIO_HIGH, 'tibio_engagement_reciente_sin_gestion');
            }
            if ($profile_high_intent && $sin_gestion) {
                return array(self::PRIO_HIGH, 'tibio_perfil_comercial_alto_sin_gestion');
            }
            return array(self::PRIO_MEDIUM, 'tibio');
        }
        if ($sin_gestion && ($deep_engagement || $profile_high_intent)) {
            return array(self::PRIO_MEDIUM, $deep_engagement ? 'frio_engagement_reciente_sin_gestion' : 'frio_perfil_comercial_alto_sin_gestion');
        }
        return array(self::PRIO_LOW, 'frio');
    }

    private static function clamp(int $v): int
    {
        return max(0, min(100, $v));
    }

    // ─── DB-bound recompute + readers (WP) ────────────────────────────────────

    public static function recompute(int $lead_id): void
    {
        global $wpdb;
        $lead_id = absint($lead_id);
        if ($lead_id <= 0) {
            return;
        }

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT is_valid_lead, quality, status, sales_stage, contact_attempts, feedback_completed
             FROM {$wpdb->prefix}es_leads WHERE id = %d",
            $lead_id
        ), ARRAY_A);
        if (!$lead) {
            return;
        }

        $result = self::calculate(array(
            'capture'         => self::read_capture($lead_id),
            'qualification'   => self::read_qualification($lead_id),
            'profile'         => self::read_profile($lead_id),
            'intent'          => self::read_intent($lead_id),
            'behavior_events' => self::read_behavior_events($lead_id),
            'score_eligible'   => self::read_score_eligibility($lead_id),
            'is_valid_lead'   => $lead['is_valid_lead'] === null ? null : (int) $lead['is_valid_lead'],
            'quality'         => (string) $lead['quality'],
            'status'          => (string) $lead['status'],
            'sales_stage'     => (string) $lead['sales_stage'],
            'gestion'         => array(
                'contact_attempts'   => (int) $lead['contact_attempts'],
                'feedback_completed' => (int) $lead['feedback_completed'],
                'workspace_opened'   => self::has_operator_workspace_open($lead_id),
            ),
            'thresholds'      => array(
                'hot'  => (int) get_option('estandar_leads_temp_hot', self::DEFAULT_HOT),
                'warm' => (int) get_option('estandar_leads_temp_warm', self::DEFAULT_WARM),
            ),
        ));

        $update = array(
            'behavioral_score'      => (int) $result['total'],
            'temperature'           => (string) $result['temperature'],
            'priority'              => (string) $result['priority'],
            'score_updated_at'      => gmdate('Y-m-d H:i:s'),
            'score_components_json' => wp_json_encode($result['components']),
        );
        $formats = array('%d', '%s', '%s', '%s', '%s');
        if (self::lead_column_exists('capture_score')) {
            $update['capture_score'] = (int) ($result['components']['capture'] ?? 0);
            $formats[] = '%d';
        }
        if (self::lead_column_exists('capture_profile')) {
            $update['capture_profile'] = self::capture_profile($lead_id);
            $formats[] = '%s';
        }
        if (self::lead_column_exists('capture_updated_at')) {
            $update['capture_updated_at'] = $update['score_updated_at'];
            $formats[] = '%s';
        }

        // UPDATE only — never insert an event here (prevents recompute recursion).
        $wpdb->update(
            $wpdb->prefix . 'es_leads',
            $update,
            array('id' => $lead_id),
            $formats,
            array('%d')
        );
    }

    private static function lead_column_exists(string $column): bool
    {
        global $wpdb;
        static $columns = null;
        if ($columns === null) {
            $columns = (array) $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}es_leads", 0);
        }
        return in_array($column, $columns, true);
    }

    /**
     * Capture score = current completeness of the CONSOLIDATED lead (canonical
     * cache of current lead state). Frozen weights: phone 50 · email 30 · name 20.
     * Computed only from es_leads identity columns (read-only).
     *
     * Note: the originating submission's scoring_json.lead_score is an
     * ingestion-time origin score (chatbot AI intent / forms experience-engine),
     * a different concept that is NOT a measure of consolidated identity
     * completeness — it is intentionally NOT used here.
     */
    private static function read_capture(int $lead_id)
    {
        global $wpdb;

        // Consolidated identity = current es_leads state, not the originating submission.
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT name, email, phone FROM {$wpdb->prefix}es_leads WHERE id = %d",
            $lead_id
        ), ARRAY_A);
        if (!is_array($lead)) {
            return null;
        }

        $capture = 0;
        if (trim((string) ($lead['phone'] ?? '')) !== '') { $capture += 50; }
        if (trim((string) ($lead['email'] ?? '')) !== '') { $capture += 30; }
        if (trim((string) ($lead['name'] ?? ''))  !== '') { $capture += 20; }

        return $capture;
    }

    private static function capture_profile(int $lead_id): string
    {
        global $wpdb;
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT name, email, phone FROM {$wpdb->prefix}es_leads WHERE id = %d",
            $lead_id
        ), ARRAY_A);
        if (!is_array($lead)) {
            return 'missing';
        }
        $parts = array();
        foreach (array('name', 'email', 'phone') as $field) {
            if (trim((string) ($lead[$field] ?? '')) !== '') {
                $parts[] = $field;
            }
        }
        return !empty($parts) ? implode(',', $parts) : 'missing';
    }

    /**
     * Qualification score, if persisted. Today it is display-first (context_json),
     * so this returns null in most cases → reweight to capture+behavior. Plugs in
     * once Fase D/F persist a numeric qualification score.
     */
    private static function read_qualification(int $lead_id)
    {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare(
            "SELECT context_json FROM {$wpdb->prefix}es_lead_submissions
             WHERE lead_id = %d ORDER BY id DESC LIMIT 1",
            $lead_id
        ));
        if (!$json) {
            return null;
        }
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            return null;
        }
        $q = $data['qualification'] ?? null;
        if (is_array($q) && isset($q['score']) && is_numeric($q['score'])) {
            return (int) $q['score'];
        }
        return null;
    }

    /**
     * COMMERCIAL_PROFILE_V1 — derive the commercial profile from the latest
     * submission's captured fields. Read-only, deterministic, null when no
     * commercial field is present (profile reweights out of the average).
     */
    private static function read_profile(int $lead_id)
    {
        if (!class_exists('Estandar_Leads_Commercial_Profile')) {
            return null;
        }
        global $wpdb;
        $submission = $wpdb->get_row($wpdb->prepare(
            "SELECT form_fields_json, context_json, source_plugin FROM {$wpdb->prefix}es_lead_submissions
             WHERE lead_id = %d ORDER BY id DESC LIMIT 1",
            $lead_id
        ));

        // CHATBOT_EVIDENCE_V1 composite inputs — only gathered when the
        // intent-scoring phase is enabled; leaves the pre-existing
        // field-based profile dimensions untouched when disabled/absent.
        $evidence = array();
        if ($submission && get_option('estandar_leads_intent_scoring_enabled', '1')) {
            $lead = $wpdb->get_row($wpdb->prepare(
                "SELECT phone, lead_intent FROM {$wpdb->prefix}es_leads WHERE id = %d",
                $lead_id
            ), ARRAY_A);
            if (is_array($lead)) {
                $evidence = array(
                    'capture_method'  => (string) ($submission->source_plugin ?? ''),
                    'intent'          => (string) ($lead['lead_intent'] ?? ''),
                    'phone_present'   => trim((string) ($lead['phone'] ?? '')) !== '',
                    'completeness'    => (int) (self::read_capture($lead_id) ?? 0),
                    'behavior_signal' => !empty(self::read_behavior_events($lead_id)),
                );
            }
        }

        return Estandar_Leads_Commercial_Profile::derive_for_submission($submission, $evidence);
    }

    /**
     * INTENT_SCORE_V1 — moderate, configurable contribution from the
     * chatbot-captured lead_intent. Read-only, deterministic, null when the
     * phase is disabled, the engine class is unavailable, or the lead has no
     * (or an unmapped) intent — reweights out of the average exactly like
     * capture/qualification/profile. Historical leads without lead_intent
     * degrade to null, never fabricated.
     */
    private static function read_intent(int $lead_id)
    {
        if (!get_option('estandar_leads_intent_scoring_enabled', '1')) {
            return null;
        }
        if (!class_exists('Estandar_Leads_Intent_Score')) {
            return null;
        }
        global $wpdb;
        $intent = $wpdb->get_var($wpdb->prepare(
            "SELECT lead_intent FROM {$wpdb->prefix}es_leads WHERE id = %d",
            $lead_id
        ));
        if ($intent === null) {
            return null;
        }
        return Estandar_Leads_Intent_Score::derive((string) $intent);
    }

    private static function read_behavior_events(int $lead_id): array
    {
        global $wpdb;
        $types = array_keys(self::behavior_weights());
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $sql = "SELECT event_type, submission_id, metadata, created_at
                FROM {$wpdb->prefix}es_lead_events
                WHERE lead_id = %d AND event_type IN ($placeholders)";
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, array_merge(array($lead_id), $types)),
            ARRAY_A
        );

        $now = time();
        $events = array();
        foreach ((array) $rows as $row) {
            $meta = json_decode((string) ($row['metadata'] ?? ''), true);
            $actor = is_array($meta) ? (string) ($meta['actor_type'] ?? '') : '';
            $ts = strtotime((string) ($row['created_at'] ?? ''));
            $age_days = $ts ? max(0, ($now - $ts) / DAY_IN_SECONDS) : 0;
            $events[] = array(
                'type'       => (string) $row['event_type'],
                'submission_id' => (int) ($row['submission_id'] ?? 0),
                'actor_type' => $actor,
                'open_classification' => is_array($meta) ? (string) ($meta['open_classification'] ?? '') : '',
                'score_eligible' => !is_array($meta) || !array_key_exists('score_eligible', $meta) || !empty($meta['score_eligible']),
                'age_days'   => $age_days,
            );
        }
        // OPEN_EVIDENCE_V1 — clasificación antes de puntuar (determinístico):
        // technical_fetch/scanner/prefetch = 0 y sin double-count raw+classified.
        return self::sanitize_behavior_events($events);
    }

    /**
     * OPEN_EVIDENCE_V1 — single source of truth for behavior events fed to
     * calculate(). Garantías:
     *   1. Clases zero-point (technical_fetch / prefetch / scanner /
     *      probable_machine_open) aportan EXACTAMENTE 0 puntos.
     *   2. SIN double-counting: cuando existe un evento clasificado
     *      (`autoresponse_open_classified`) para una submission, la señal raw
     *      (`autoresponse_email_open_signal`) de esa misma submission se
     *      descarta — el clasificado es autoritativo.
     * PURE (unit-testable standalone).
     *
     * @param array $events [ {type, submission_id?, open_classification?, score_eligible?, actor_type?, age_days?}, ... ]
     * @return array eventos sanitizados (clases zero-point eliminadas, raw descartado).
     */
    public static function sanitize_behavior_events(array $events): array
    {
        $zero_point = array('technical_fetch', 'prefetch', 'scanner', 'probable_machine_open');
        $classified_subs = array();

        // Primer pase: submissions con veredicto clasificado autoritativo.
        foreach ($events as $ev) {
            $type = (string) ($ev['type'] ?? '');
            if ($type !== 'autoresponse_open_classified') {
                continue;
            }
            $sid = (int) ($ev['submission_id'] ?? 0);
            if ($sid > 0) {
                $classified_subs[$sid] = true;
            }
        }

        $clean = array();
        foreach ($events as $ev) {
            $type = (string) ($ev['type'] ?? '');

            // Gate 1: clases zero-point nunca contribuyen.
            if ($type === 'autoresponse_open_classified') {
                $class = strtolower((string) ($ev['open_classification'] ?? ''));
                if (in_array($class, $zero_point, true)) {
                    continue;
                }
            }

            // Gate 2: señal raw descartada cuando existe veredicto clasificado
            // para la misma submission (no double-counting).
            if ($type === 'autoresponse_email_open_signal') {
                $sid = (int) ($ev['submission_id'] ?? 0);
                if ($sid > 0 && isset($classified_subs[$sid])) {
                    continue;
                }
            }

            // score_eligible=false (flag legacy explícito) → excluido.
            if (array_key_exists('score_eligible', $ev) && empty($ev['score_eligible'])) {
                continue;
            }

            $clean[] = $ev;
        }
        return $clean;
    }

    private static function read_score_eligibility(int $lead_id): bool
    {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare(
            "SELECT context_json FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d ORDER BY id ASC LIMIT 1",
            $lead_id
        ));
        $context = json_decode((string) $json, true);
        return !is_array($context) || empty($context['qa_protection']['is_test_lead']);
    }

    private static function has_operator_workspace_open(int $lead_id): bool
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT metadata FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND event_type IN ('lead_list_opened','lead_viewed')",
            $lead_id
        ), ARRAY_A);
        foreach ((array) $rows as $row) {
            $meta = json_decode((string) ($row['metadata'] ?? ''), true);
            if (is_array($meta) && ($meta['actor_type'] ?? '') === 'operator') {
                return true;
            }
        }
        return false;
    }

    public static function run_decay_sweep(): void
    {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}es_leads
             WHERE status NOT IN ('CONVERTED','LOST')
               AND (score_updated_at IS NULL OR score_updated_at < DATE_SUB(NOW(), INTERVAL 1 DAY))
             ORDER BY score_updated_at ASC
             LIMIT 300"
        );
        foreach ((array) $ids as $id) {
            self::recompute((int) $id);
        }
    }
}
