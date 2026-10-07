<?php
/**
 * INTENT_SCORE_V1 — deterministic, moderate scoring contribution derived
 * from the chatbot-captured `lead_intent`. Pure derivation, read-only:
 *
 *   - NEVER promotes an intent to CALIENTE by itself — it is one weighted
 *     component (W_INTENT) among others in Estandar_Leads_Scoring, not a
 *     temperature override.
 *   - NEVER feeds Admission Policy, Qualification, Ads/CAPI/OCI, or SLA /
 *     notification timing — consumed only by Estandar_Leads_Scoring.
 *   - The mapping is generic and filterable per installation via
 *     `estandar_leads_intent_score_mapping` — no project slug hardcoded.
 *   - Unmapped/unknown intents contribute nothing (never fabricated); an
 *     empty/absent intent (historical leads) also contributes nothing —
 *     these leads degrade to NULL and reweight out of the average, exactly
 *     like COMMERCIAL_PROFILE_V1.
 *
 * Toggle: `estandar_leads_intent_scoring_enabled` option (default enabled).
 * When disabled, Estandar_Leads_Scoring::read_intent() short-circuits to
 * null before this class is even consulted — this class stays a pure,
 * always-available derivation function.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Intent_Score
{
    const VERSION = 'INTENT_SCORE_V1';

    /**
     * INTENT_SCORE_CALIBRATION_V1 (2026-07-17) — default mapping calibrated
     * against the real intent-id vocabulary found in estandar-chatbot,
     * across every source that actually emits a `lead_intent` value on this
     * installation (SALADEPRUEBAS has no persisted `chatbot_lead_intents`
     * option yet — this is the union of every profile default plus the
     * AK-Contadores legacy fallback map, not a single site's config):
     *   - estandar-chatbot/includes/config-resolver.php (`lead_real_estate`,
     *     services, `faq_support` profile defaults)
     *   - estandar-chatbot/includes/leads-handler.php:1300
     *     (`estandar_chatbot_humanize_lead_intent()` legacy AK fallback map)
     * Tiers (0-100 scale, moderate caps — no single intent pushes a lead to
     * CALIENTE alone; W_INTENT=0.10 bounds the effective contribution):
     *   20 = explicit purchase/quote intent (ventas/cotizar/cotización)
     *   18 = concrete commercial trigger (precios, cambio de proveedor,
     *        declaración de renta — a filed reason to act)
     *   15 = recurring-service commercial intent (contabilidad mensual,
     *        inicio de actividades)
     *   12 = advisory/comparison intent (opciones, asesor, tributaria,
     *        remuneraciones) — interested, not yet decided
     *    8 = general inquiry with some direction (consulta, urgencia)
     *    5 = support/human-contact intent — assistance-seeking, not
     *        necessarily commercial (humano, soporte, info, faq)
     * Keys match the `lead_intent` values configured per installation in
     * the chatbot's `chatbot_lead_intents` option (or its legacy fallback).
     * Any id not in this list — including a genuinely unknown/unconfigured
     * one — resolves to NULL in derive(), never fabricated.
     */
    public static function mapping(): array
    {
        $mapping = array(
            // Explicit purchase/quote intent.
            'ventas'                  => 20,
            'cotizar'                 => 20,
            'cotizacion'              => 20,
            'cotizacion_inmobiliaria' => 20,
            // Concrete commercial trigger.
            'precios'                 => 18,
            'cambio_contador'         => 18,
            'declaracion_renta'       => 18,
            // Recurring-service commercial intent.
            'contabilidad_mensual'    => 15,
            'inicio_actividades'      => 15,
            // Advisory/comparison intent.
            'opciones'                => 12,
            'asesor'                  => 12,
            'tributaria'              => 12,
            'remuneraciones'          => 12,
            // General inquiry with some direction.
            'consulta'                => 8,
            'urgencia'                => 8,
            // Support/human-contact intent — assistance-seeking.
            'humano'                  => 5,
            'contacto_humano'         => 5,
            'soporte'                 => 5,
            'info'                    => 5,
            'faq'                     => 5,
            'consulta_general'        => 5,
        );

        if (function_exists('apply_filters')) {
            // Per-installation override takes full priority — replaces the
            // default mapping entirely (never merged) so an installation
            // that wants a smaller/different vocabulary is never forced to
            // carry unused defaults alongside its own configuration.
            $filtered = apply_filters('estandar_leads_intent_score_mapping', $mapping);
            if (is_array($filtered)) {
                $mapping = $filtered;
            }
        }
        return $mapping;
    }

    /**
     * Pure derivation from a raw intent key. No WP/DB except apply_filters.
     *
     * @param string $intent raw lead_intent value.
     * @return array|null null when intent is empty or unmapped — the
     *                    contribution is genuinely absent, never fabricated.
     *   { engine, intent, score, reason }
     */
    public static function derive(string $intent)
    {
        $intent = trim(function_exists('mb_strtolower') ? mb_strtolower($intent) : strtolower($intent));
        if ($intent === '') {
            return null;
        }

        $mapping = self::mapping();
        if (!array_key_exists($intent, $mapping)) {
            return null;
        }

        $points = max(0, min(100, (int) $mapping[$intent]));

        return array(
            'engine' => self::VERSION,
            'intent' => $intent,
            'score'  => $points,
            'reason' => 'lead_intent=' . $intent . ' (+' . $points . ') from configured intent-score mapping',
        );
    }
}
