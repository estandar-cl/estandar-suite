<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Attribution
{
    /**
     * Canonical commercial channel resolver.
     *
     * @param array|object $attribution Submission/lead attribution fields.
     * @return string google_ads|google_organic|meta_ads|organic|direct|unknown
     */
    public static function resolve_channel($attribution): string
    {
        $row = is_object($attribution) ? get_object_vars($attribution) : (is_array($attribution) ? $attribution : array());
        $get = static function ($key) use ($row): string {
            foreach (array($key, 'first_' . $key) as $candidate) {
                if (isset($row[$candidate]) && is_scalar($row[$candidate])) {
                    $value = trim((string) $row[$candidate]);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
            return '';
        };

        $query = array();
        foreach (array('landing_url', 'first_landing_url', 'landing_page') as $field) {
            $url = $get($field);
            if ($url === '') {
                continue;
            }
            $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
            if (!is_array($parts) || empty($parts['query'])) {
                continue;
            }
            parse_str((string) $parts['query'], $parsed);
            if (is_array($parsed)) {
                $query += $parsed;
            }
        }
        $signal = static function ($key) use ($get, $query): string {
            $value = $get($key);
            if ($value !== '') {
                return $value;
            }
            return isset($query[$key]) && is_scalar($query[$key]) ? trim((string) $query[$key]) : '';
        };

        if ($signal('gclid') !== '' || $signal('gbraid') !== '' || $signal('wbraid') !== '') {
            return 'google_ads';
        }

        $source = strtolower($signal('utm_source'));
        $medium = strtolower($signal('utm_medium'));
        if ($signal('fbclid') !== '' || $signal('meta_fbc') !== ''
            || in_array($source, array('facebook', 'instagram', 'meta', 'fb', 'ig'), true)) {
            return 'meta_ads';
        }

        if ($medium === 'google_ads' || ($source === 'google' && in_array($medium, array('cpc', 'ppc', 'paid', 'paid_search'), true))) {
            return 'google_ads';
        }
        if ($medium === 'organic') {
            return $source === 'google' ? 'google_organic' : 'organic';
        }

        $referrer = $get('referrer');
        if ($referrer !== '') {
            $parts = function_exists('wp_parse_url') ? wp_parse_url($referrer) : parse_url($referrer);
            $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
            $path = is_array($parts) ? strtolower((string) ($parts['path'] ?? '')) : '';
            $ref_query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';
            parse_str($ref_query, $ref_params);
            if (preg_match('/(^|\.)google\.[a-z.]+$/', $host) && (strpos($path, '/search') === 0 || isset($ref_params['q']))) {
                return 'google_organic';
            }
        }

        if ($source === 'google') {
            return 'google_organic';
        }
        if ($source === 'bing') {
            return 'organic';
        }
        if (in_array($source, array('direct', 'directo'), true)) {
            return 'direct';
        }
        if ($source === '' && $medium === '') {
            $source_plugin = strtolower($get('source_plugin'));
            if (strpos($source_plugin, 'forms') !== false) {
                return 'form_web';
            }
            if ($signal('session_id') !== '') {
                return 'direct';
            }
            return 'unknown';
        }
        return 'unknown';
    }

    /**
     * Canonical platform eligibility over the canonical channel decision.
     * Commercial policy remains owned by Estandar_Leads_QA_Policy; callers
     * pass its already-resolved allow/block result instead of duplicating it.
     *
     * @param string       $platform google|meta
     * @param array|object $attribution
     * @param bool         $commercial_allowed
     * @return array {eligible:bool, channel:string, reason:string, evidence:string}
     */
    public static function platform_eligibility(string $platform, $attribution, bool $commercial_allowed = true): array
    {
        $platform = strtolower(trim($platform));
        $channel = self::resolve_channel($attribution);
        $row = is_object($attribution) ? get_object_vars($attribution) : (is_array($attribution) ? $attribution : array());
        $value = static function (string $key) use ($row): string {
            foreach (array($key, 'first_' . $key) as $candidate) {
                if (isset($row[$candidate]) && is_scalar($row[$candidate])) {
                    $candidate_value = trim((string) $row[$candidate]);
                    if ($candidate_value !== '') {
                        return $candidate_value;
                    }
                }
            }
            return '';
        };

        if (!$commercial_allowed) {
            return array('eligible' => false, 'channel' => $channel, 'reason' => 'commercial feedback not allowed', 'evidence' => '');
        }

        if ($platform === 'google') {
            $evidence = '';
            foreach (array('gclid', 'gbraid', 'wbraid') as $identifier) {
                if ($value($identifier) !== '') {
                    $evidence = $identifier;
                    break;
                }
            }
            if ($channel !== 'google_ads') {
                $reason = in_array($channel, array('google_organic', 'organic'), true)
                    ? 'organic/non-paid channel'
                    : 'non-Google Ads acquisition';
                return array('eligible' => false, 'channel' => $channel, 'reason' => $reason, 'evidence' => $evidence);
            }
            if ($evidence === '') {
                return array('eligible' => false, 'channel' => $channel, 'reason' => 'missing Google Ads click identifier (gclid/gbraid/wbraid)', 'evidence' => '');
            }
            return array('eligible' => true, 'channel' => $channel, 'reason' => 'Google Ads channel with click identifier', 'evidence' => $evidence);
        }

        if ($platform === 'meta') {
            $evidence = $value('fbclid') !== '' ? 'fbclid' : ($value('meta_fbc') !== '' ? 'fbc' : '');
            $fbp_only = $evidence === '' && $value('meta_fbp') !== '';
            if ($channel !== 'meta_ads') {
                return array(
                    'eligible' => false,
                    'channel'  => $channel,
                    'reason'   => $fbp_only ? 'non-Meta acquisition; _fbp alone is insufficient' : 'non-Meta acquisition',
                    'evidence' => $evidence,
                );
            }
            if ($evidence === '') {
                return array('eligible' => false, 'channel' => $channel, 'reason' => $fbp_only ? '_fbp alone is insufficient' : 'missing usable Meta attribution evidence (fbclid/fbc)', 'evidence' => '');
            }
            return array('eligible' => true, 'channel' => $channel, 'reason' => 'Meta Ads channel with attribution evidence', 'evidence' => $evidence);
        }

        return array('eligible' => false, 'channel' => $channel, 'reason' => 'unknown advertising platform', 'evidence' => '');
    }

    public static function channel_label($attribution): string
    {
        $labels = array(
            'google_ads'     => 'Google Ads',
            'google_organic' => 'Google Organic',
            'meta_ads'       => 'Meta Ads',
            'organic'        => 'Organic',
            'direct'         => 'Direct',
            'form_web'       => 'Formulario Web',
            'unknown'        => 'Unknown',
        );
        $channel = self::resolve_channel($attribution);
        return $labels[$channel] ?? 'Unknown';
    }

    public static function merge_analytics($attr)
    {
        $attr = is_array($attr) ? $attr : array();

        // CAPTURE_ATTRIBUTION_CONTEXT_V1: run the incoming attribution through
        // the canonical core builder BEFORE the analytics-session fallback, so
        // fields recoverable from the landing URL (utm_*, fbclid, gclid, ...)
        // are filled at the single ingestion choke point even when a capture
        // source emitted the minimal shape (landing_url + session_id only).
        // Precedence enforced: explicit payload > landing_url params > cookies
        // > analytics session. Guarded per CORE_OPTIONALITY (older Primer
        // without the builder keeps current behavior).
        if (function_exists('estandar_core_build_attribution_context')) {
            $attr = estandar_core_build_attribution_context($attr) + $attr;
        }

        $fields = array(
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'gclid',
            'gbraid',
	            'wbraid',
	            'fbclid',
	            'meta_fbc',
	            'meta_fbp',
	            'ttclid',
	            'msclkid',
	            'campaign_id',
	            'adgroup_id',
	            'asset_group_id',
	            'creative_id',
	            'keyword',
	            'match_type',
	            'network',
	            'device',
	            'placement',
	            'campaign_type',
	            'landing_url',
            'referrer',
            'first_touch_timestamp',
            'last_touch_timestamp',
            'session_id',
        );

        $needs_attribution = false;
        foreach ($fields as $field) {
            if (trim((string)($attr[$field] ?? '')) === '') {
                $needs_attribution = true;
                break;
            }
        }

        if (!$needs_attribution) {
            return $attr;
        }

        $session_id = sanitize_text_field($attr['session_id'] ?? '');
        if ($session_id !== '' && function_exists('estandar_analytics_get_attribution_by_session')) {
            $analytics = estandar_analytics_get_attribution_by_session($session_id);
        } elseif ($session_id === '' && function_exists('estandar_analytics_get_current_attribution')) {
            $analytics = estandar_analytics_get_current_attribution();
        } else {
            self::telemetry('warning', 'LEADS_ANALYTICS_ATTRIBUTION_UNAVAILABLE', 'Analytics attribution helper unavailable.', array(
                'reason' => $session_id !== '' ? 'by_session_helper_missing' : 'helper_missing',
            ));
            return $attr;
        }

        if (!is_array($analytics)) {
            self::telemetry('warning', 'LEADS_ANALYTICS_ATTRIBUTION_UNAVAILABLE', 'Analytics attribution helper returned no usable attribution.', array(
                'reason' => 'invalid_response',
            ));
            return $attr;
        }

        foreach ($fields as $field) {
            if (trim((string)($attr[$field] ?? '')) !== '') {
                continue;
            }
            if (trim((string)($analytics[$field] ?? '')) === '') {
                continue;
            }
            // Cross-project attribution guard (2026-08-05).
            // Do NOT fill attribution from an analytics session that belongs
            // to a different project. A 30-day ea_sid cookie can span multiple
            // projects on the same domain; without this guard, UTM/gclid from
            // Project A would contaminate leads in Project B.
            $lead_project    = trim( (string) ( $attr['project_slug'] ?? '' ) );
            $session_project = trim( (string) ( $analytics['project_attribution_slug'] ?? '' ) );
            if ( $lead_project !== '' && $session_project !== '' && $lead_project !== $session_project ) {
                continue;
            }
            $attr[$field] = $analytics[$field];
			$attr['attribution_provenance'][ $field ] = 'analytics_session';
        }
		if ( is_array( $analytics['attribution_provenance'] ?? null ) ) {
			$attr['attribution_provenance'] = (array) ( $attr['attribution_provenance'] ?? array() ) + $analytics['attribution_provenance'];
		}

        if (trim((string)($attr['attribution_capture_method'] ?? '')) === '' && trim((string)($analytics['capture_method'] ?? '')) !== '') {
            $attr['attribution_capture_method'] = $analytics['capture_method'];
        }
        if (trim((string)($attr['gclid'] ?? '')) !== '' && trim((string)($attr['gclid_capture_method'] ?? '')) === '') {
            $attr['gclid_capture_method'] = trim((string)($analytics['capture_method'] ?? '')) !== '' ? $analytics['capture_method'] : 'payload';
        }
        if (trim((string)($attr['fbclid'] ?? '')) !== '' && trim((string)($attr['fbclid_capture_method'] ?? '')) === '') {
            $attr['fbclid_capture_method'] = trim((string)($analytics['capture_method'] ?? '')) !== '' ? $analytics['capture_method'] : 'payload';
        }

        return $attr;
    }

    /**
     * LANDING_PRECEDENCE_V1 — canonical landing resolution.
     *
     * Precedence: explicit landing > submission landing > analytics session
     * landing > referrer > home fallback. A generic home URL (empty path or
     * "/", no query) NEVER replaces a more precise landing available at a
     * lower precedence level — precision wins over precedence for home URLs.
     *
     * Read-side helper only: capture/storage behavior (Forms included) is
     * unchanged. Deterministic, no WP/DB dependencies beyond the optional
     * home candidate the caller provides.
     *
     * @param array $candidates ['explicit'=>, 'submission'=>, 'session'=>, 'referrer'=>, 'home'=>]
     * @return array{url:string, source:string} source ∈ candidate keys | 'none'
     */
    public static function canonical_landing(array $candidates): array
    {
        $order = array('explicit', 'submission', 'session', 'referrer');

        // First pass: highest-precedence PRECISE landing.
        foreach ($order as $source) {
            $url = trim((string) ($candidates[$source] ?? ''));
            if ($url !== '' && !self::is_generic_home($url)) {
                return array('url' => $url, 'source' => $source);
            }
        }

        // Second pass: no precise landing anywhere — accept a generic one.
        foreach ($order as $source) {
            $url = trim((string) ($candidates[$source] ?? ''));
            if ($url !== '') {
                return array('url' => $url, 'source' => $source);
            }
        }

        $home = trim((string) ($candidates['home'] ?? ''));
        if ($home !== '') {
            return array('url' => $home, 'source' => 'home');
        }

        return array('url' => '', 'source' => 'none');
    }

    /** Generic home = no path (or "/") and no query string. */
    public static function is_generic_home(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $query = (string) ($parts['query'] ?? '');
        return $path === '' && $query === '';
    }

    private static function telemetry($severity, $code, $message, $context = array())
    {
        try {
            do_action('estandar_telemetry_event', array(
                'source_plugin' => 'estandar-leads',
                'type' => 'business_event',
                'severity' => $severity,
                'code' => $code,
                'message' => $message,
                'context' => is_array($context) ? $context : array(),
            ));
        } catch (Throwable $e) {
            return;
        }
    }
}
