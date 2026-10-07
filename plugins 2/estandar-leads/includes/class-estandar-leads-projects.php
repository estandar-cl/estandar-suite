<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Projects
{
    private static function match_key($value)
    {
        $value = sanitize_title(remove_accents((string) $value));
        return str_replace(array('-', '_'), '', $value);
    }

    public static function normalize_slug($slug, $fallback = '')
    {
        $slug = sanitize_title(remove_accents((string) $slug));
        if ($slug === '') {
            $slug = sanitize_title(remove_accents((string) $fallback));
        }
        return trim($slug, "- \t\n\r\0\x0B");
    }

    public static function resolve_slug($slug, $fallback = '')
    {
        $candidate = self::normalize_slug($slug, $fallback);
        if ($candidate === '') {
            return '';
        }

        $candidate_key = self::match_key($candidate);
        $matches = array();
        foreach (self::registry() as $project) {
            $registered_slug = self::normalize_slug($project['project_slug'] ?? '');
            $registered_name = self::normalize_slug($project['name'] ?? '');

            if ($candidate === $registered_slug || ($registered_name !== '' && $candidate === $registered_name)) {
                return $registered_slug;
            }

            if (
                $candidate_key !== '' &&
                (
                    $candidate_key === self::match_key($registered_slug) ||
                    ($registered_name !== '' && $candidate_key === self::match_key($registered_name))
                )
            ) {
                $matches[$registered_slug] = $registered_slug;
            }
        }

        if (count($matches) === 1) {
            return (string) array_values($matches)[0];
        }

        if (count($matches) > 1) {
            error_log('[estandar-leads] PROJECT_SLUG_MULTI_MATCH: Candidate "' . $candidate . '" (' . $slug . ') matched ' . count($matches) . ' projects: ' . implode(', ', $matches));
            if (function_exists('do_action')) {
                do_action('estandar_telemetry_event', array(
                    'source_plugin' => 'estandar-leads',
                    'type'          => 'business_event',
                    'severity'      => 'warning',
                    'code'          => 'PROJECT_SLUG_MULTI_MATCH',
                    'message'       => 'Project slug resolve_slug() matched multiple projects; returning empty.',
                    'context'       => array(
                        'candidate'       => $candidate,
                        'original_slug'   => $slug,
                        'fallback'        => $fallback,
                        'matched_slugs'   => array_values($matches),
                        'match_count'     => count($matches),
                    ),
                ));
            }
        }

        // PROJECT_REGISTRY_ENFORCEMENT_V1 (RULE_04, aprobado) — un candidato que
        // no matchea NINGÚN proyecto del Registry ya NO se persiste como si fuera
        // válido. Antes este pass-through dejaba slugs falsos como 'contabilidad'
        // en es_leads.project_slug (ej. Lead AK, ver AK_PROJECT_RESOLUTION_AUDIT_V1
        // y PROJECT_RESOLUTION_ARCHITECTURE): el notifier hacía fallback a
        // broadcast, pero el slug quedaba huérfano permanentemente en Registry
        // Health como si hiciera falta "crear el proyecto" — cuando en realidad
        // el proyecto correcto (ej. ak-contadores) ya existía y el candidato
        // simplemente nunca coincidió con él. 'unresolved' = '' reutiliza el
        // mismo camino de fallback del notifier (ya soporta project_slug vacío)
        // en vez de inventar un proyecto nuevo por cada variante de texto que
        // llegue sin resolver.
        return '';
    }

    public static function registry()
    {
        if (!class_exists('Estandar_Core_Project_Registry')) {
            return array();
        }

        $client_id    = function_exists( 'estandar_core_get_client_slug' ) ? (string) estandar_core_get_client_slug() : '';
        $all_projects = $client_id !== ''
            ? Estandar_Core_Project_Registry::get_all_for_client( $client_id )
            : Estandar_Core_Project_Registry::get_all();

        $out = array();
        foreach ((array) $all_projects as $project) {
            $slug = self::normalize_slug($project['project_id'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[$slug] = array(
                'project_slug' => $slug,
                'project_uuid' => sanitize_text_field($project['project_uuid'] ?? ''),
                'name' => sanitize_text_field($project['label'] ?? $slug),
                'city' => sanitize_text_field($project['city'] ?? ''),
            );
        }

        return array_values($out);
    }

    /**
     * Returns a flat array of all project slugs available in the registry.
     *
     * @return string[]
     */
    public static function get_all_slugs(): array
    {
        $registry = self::registry();
        $slugs = array();
        foreach ( $registry as $entry ) {
            $slug = $entry['project_slug'] ?? '';
            if ( $slug !== '' ) {
                $slugs[] = $slug;
            }
        }
        return array_values( array_unique( $slugs ) );
    }

    /**
     * Returns the closest slug from $registry_slugs to $slug using Levenshtein distance,
     * or '' if no reasonable candidate exists (distance > 3) or the match is exact.
     *
     * @param string   $slug
     * @param string[] $registry_slugs
     * @return string
     */
    public static function suggest_slug( string $slug, array $registry_slugs ): string {
        if ( empty( $registry_slugs ) || $slug === '' ) {
            return '';
        }
        $best       = '';
        $best_score = PHP_INT_MAX;
        foreach ( $registry_slugs as $candidate ) {
            $d = levenshtein( $slug, $candidate );
            if ( $d < $best_score ) {
                $best_score = $d;
                $best       = $candidate;
            }
        }
        return ( $best_score <= 3 && $best !== $slug ) ? $best : '';
    }
}
