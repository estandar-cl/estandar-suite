<?php
/**
 * COMMERCIAL_PROFILE_V1 — deterministic commercial profile derivation.
 *
 * Turns commercial fields already captured on a submission
 * (necesidad_principal, situacion_empresa, contador_actual, plazo_inicio,
 * ciudad, …) into an auditable commercial profile: profile_id + score +
 * per-signal breakdown. Pure derivation, read-only:
 *
 *   - NEVER sets QUALIFIED — operator qualification is a separate concept
 *     (Admission Policy ≠ Commercial Profile ≠ Operator Qualification).
 *   - NEVER feeds ads platforms — consumed only by Estandar_Leads_Scoring
 *     (score/priority) and render layers.
 *   - Every point carries a reason: no opaque heuristics.
 *
 * The dimension dictionary is generic (need / company status / current
 * provider / start timeframe / location) with Spanish keyword defaults and is
 * configurable per installation via the
 * `estandar_leads_commercial_profile_dimensions` filter — no project slug is
 * hardcoded here.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Commercial_Profile
{
    const VERSION = 'COMMERCIAL_PROFILE_V1';

    /**
     * Dimension dictionary. Each dimension:
     *   keys    field-name candidates in form_fields_json / context_json
     *   levels  ordered level => ['tokens' => [...], 'points' => int]
     *           (first level whose token matches wins; 'stated' = fallback
     *           when the field is present but no keyword matched)
     */
    public static function dimensions(): array
    {
        $dimensions = array(
            'need' => array(
                'keys'   => array('necesidad_principal', 'necesidad', 'need', 'requerimiento'),
                'levels' => array(
                    'stated' => array('tokens' => array(), 'points' => 15),
                ),
            ),
            'company_status' => array(
                'keys'   => array('situacion_empresa', 'situacion', 'estado_empresa', 'company_status'),
                'levels' => array(
                    'operating' => array('tokens' => array('operando', 'en operacion', 'en marcha', 'funcionando', 'activa', 'operativa'), 'points' => 25),
                    'starting'  => array('tokens' => array('por iniciar', 'iniciando', 'creando', 'constitucion', 'formacion', 'recien creada'), 'points' => 15),
                    'idea'      => array('tokens' => array('idea', 'proyecto', 'evaluando iniciar'), 'points' => 5),
                    'stated'    => array('tokens' => array(), 'points' => 5),
                ),
            ),
            'current_provider' => array(
                'keys'   => array('contador_actual', 'proveedor_actual', 'contador'),
                'levels' => array(
                    'switching' => array('tokens' => array('evaluando', 'cambiar', 'cambio', 'no conforme', 'buscando', 'insatisfech', 'comparando'), 'points' => 20),
                    'none'      => array('tokens' => array('no tiene', 'no tengo', 'sin contador', 'ninguno', 'no cuento'), 'points' => 15),
                    'has'       => array('tokens' => array('tiene', 'tengo', 'interno', 'si'), 'points' => 5),
                    'stated'    => array('tokens' => array(), 'points' => 5),
                ),
            ),
            'start_timeframe' => array(
                'keys'   => array('plazo_inicio', 'plazo', 'inicio_estimado', 'cuando_inicia'),
                'levels' => array(
                    'immediate' => array('tokens' => array('inmediato', 'ya', 'ahora', 'cuanto antes', 'este mes', 'urgente', 'esta semana'), 'points' => 25),
                    'short'     => array('tokens' => array('1 mes', 'un mes', '2 meses', '3 meses', 'proximo mes', '30 dias', '60 dias', '90 dias', 'trimestre'), 'points' => 10),
                    'exploring' => array('tokens' => array('explorando', 'sin fecha', 'cotizando', 'mas adelante', 'solo informacion'), 'points' => 2),
                    'stated'    => array('tokens' => array(), 'points' => 5),
                ),
            ),
            'location' => array(
                'keys'   => array('ciudad', 'city', 'comuna', 'ubicacion'),
                'levels' => array(
                    'stated' => array('tokens' => array(), 'points' => 5),
                ),
            ),
        );

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('estandar_leads_commercial_profile_dimensions', $dimensions);
            if (is_array($filtered) && !empty($filtered)) {
                $dimensions = $filtered;
            }
        }
        return $dimensions;
    }

    /**
     * High-intent rule: company operating AND provider in play (switching or
     * none) AND immediate start. Deterministic; the reason is spelled out.
     */
    const HIGH_INTENT_RULE = 'company_status=operating AND current_provider IN (switching,none) AND start_timeframe=immediate';

    /**
     * CHATBOT_EVIDENCE_V1: composite signal for chatbot captures that never
     * typed a free-text commercial field but still carry enough correlated
     * evidence (capture method + stated intent + phone + completeness or
     * behavior) to be commercially usable. Configurable per installation via
     * `estandar_leads_commercial_profile_chatbot_evidence_points`.
     *
     * Distinct from INTENT_SCORE_V1 (Estandar_Leads_Intent_Score): that class
     * scores the intent's own value once, as its own weighted component
     * (W_INTENT). This dimension scores evidence *density* — the fact that
     * several independent signals correlate — not the intent value itself.
     * No field/point is read twice between the two engines.
     */
    public static function chatbot_evidence_points(): array
    {
        $points = array('strong' => 15, 'stated' => 8);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('estandar_leads_commercial_profile_chatbot_evidence_points', $points);
            if (is_array($filtered)) {
                $points = $filtered;
            }
        }
        return $points;
    }

    const CHATBOT_EVIDENCE_RULE = 'capture_method=estandar-chatbot AND intent stated AND phone available AND (completeness>=70 OR behavior_signal)';

    /**
     * Pure derivation from captured field maps. No WP/DB.
     *
     * @param array $fields   form_fields_json map (field => value)
     * @param array $context  context_json map (secondary source)
     * @param array $evidence optional chatbot evidence composite inputs:
     *   { capture_method: string, intent: string, phone_present: bool,
     *     completeness: int, behavior_signal: bool }
     *   Absent/empty evidence never adds a signal (degrades to the existing
     *   field-based dimensions only — historical leads are unaffected).
     * @return array|null null when no commercial dimension matched — the
     *                    profile is genuinely absent, never fabricated.
     *   { engine, profile_id, score, high_intent, high_intent_reason,
     *     signals: [ {dimension, field, value, level, points, reason} ],
     *     reasons: [string] }
     */
    public static function derive(array $fields, array $context = array(), array $evidence = array())
    {
        $signals = array();
        $levels  = array();
        $score   = 0;

        foreach (self::dimensions() as $dimension => $spec) {
            $found = self::first_field((array) ($spec['keys'] ?? array()), $fields, $context);
            if ($found === null) {
                continue;
            }
            list($field, $value) = $found;
            $comparable = self::comparable($value);

            $matched_level  = '';
            $matched_points = 0;
            foreach ((array) ($spec['levels'] ?? array()) as $level => $level_spec) {
                $tokens = (array) ($level_spec['tokens'] ?? array());
                if (empty($tokens)) {
                    // 'stated' fallback: field present, no keyword needed.
                    $matched_level  = (string) $level;
                    $matched_points = (int) ($level_spec['points'] ?? 0);
                    break;
                }
                foreach ($tokens as $token) {
                    $token = self::comparable((string) $token);
                    if ($token !== '' && strpos($comparable, $token) !== false) {
                        $matched_level  = (string) $level;
                        $matched_points = (int) ($level_spec['points'] ?? 0);
                        break 2;
                    }
                }
            }
            if ($matched_level === '') {
                continue;
            }

            $levels[$dimension] = $matched_level;
            $score += $matched_points;
            $signals[] = array(
                'dimension' => $dimension,
                'field'     => $field,
                'value'     => self::short((string) $value),
                'level'     => $matched_level,
                'points'    => $matched_points,
                'reason'    => $dimension . '=' . $matched_level . ' (+' . $matched_points . ') from field `' . $field . '`',
            );
        }

        // CHATBOT_EVIDENCE_V1 composite — only when explicit evidence is passed.
        if (!empty($evidence['capture_method']) && $evidence['capture_method'] === 'estandar-chatbot'
            && !empty($evidence['intent']) && !empty($evidence['phone_present'])
        ) {
            $strong = ((int) ($evidence['completeness'] ?? 0)) >= 70 || !empty($evidence['behavior_signal']);
            $level  = $strong ? 'strong' : 'stated';
            $points_map = self::chatbot_evidence_points();
            $points = (int) ($points_map[$level] ?? 0);
            if ($points > 0) {
                $levels['chatbot_evidence'] = $level;
                $score += $points;
                $signals[] = array(
                    'dimension' => 'chatbot_evidence',
                    'field'     => 'capture_method+intent+phone+completeness',
                    'value'     => 'chatbot:' . self::comparable((string) $evidence['intent']),
                    'level'     => $level,
                    'points'    => $points,
                    'reason'    => 'chatbot_evidence=' . $level . ' (+' . $points . ') ' . self::CHATBOT_EVIDENCE_RULE,
                );
            }
        }

        if (empty($signals)) {
            return null;
        }

        $high_intent = ($levels['company_status'] ?? '') === 'operating'
            && in_array($levels['current_provider'] ?? '', array('switching', 'none'), true)
            && ($levels['start_timeframe'] ?? '') === 'immediate';

        $pairs = array();
        foreach ($levels as $dimension => $level) {
            $pairs[] = $dimension . ':' . $level;
        }

        return array(
            'engine'             => self::VERSION,
            'profile_id'         => implode('+', $pairs),
            'score'              => max(0, min(100, (int) $score)),
            'high_intent'        => $high_intent,
            'high_intent_reason' => $high_intent ? self::HIGH_INTENT_RULE : '',
            'signals'            => $signals,
            'reasons'            => array_map(static function ($s) {
                return $s['reason'];
            }, $signals),
        );
    }

    /**
     * Derivation from a submission row (decodes form_fields_json/context_json).
     *
     * @param object|null $submission es_lead_submissions row.
     * @param array       $evidence   optional chatbot evidence composite (see derive()).
     * @return array|null
     */
    public static function derive_for_submission($submission, array $evidence = array())
    {
        if (!$submission) {
            return null;
        }
        $fields = json_decode((string) ($submission->form_fields_json ?? ''), true);
        $context = json_decode((string) ($submission->context_json ?? ''), true);
        return self::derive(is_array($fields) ? $fields : array(), is_array($context) ? $context : array(), $evidence);
    }

    /** @return array{0:string,1:string}|null [field, value] first non-empty candidate. */
    private static function first_field(array $keys, array $fields, array $context)
    {
        foreach (array($fields, $context) as $source) {
            foreach ($keys as $key) {
                foreach ($source as $candidate => $value) {
                    if (!is_scalar($value)) {
                        continue;
                    }
                    if (self::comparable((string) $candidate) !== self::comparable((string) $key)) {
                        continue;
                    }
                    $value = trim((string) $value);
                    if ($value !== '') {
                        return array((string) $candidate, $value);
                    }
                }
            }
        }
        return null;
    }

    /** Canonical comparable form: trimmed, lowercased, accents stripped. */
    public static function comparable($value): string
    {
        $value = trim((string) $value);
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        } else {
            $value = strtr($value, array(
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
                'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', 'Ü' => 'u',
            ));
        }
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    private static function short(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value));
        return mb_strlen($value) > 120 ? mb_substr($value, 0, 120) . '…' : $value;
    }
}
