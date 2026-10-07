<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Estandar_Leads_Commercial_Journey
{
    const VERSION = 1;

    private const EVENT_MAP = array(
        'lead_created'                  => 'lead.created',
        'lead_recontacted'              => 'lead.recontacted',
        'qualification_completed'       => 'classification.automatic.recorded',
        'qualification_review'          => 'classification.automatic.recorded',
        'qualification_manual_override' => 'classification.human.recorded',
        'status_changed'                 => 'lead.status.changed',
        'status_overridden'              => 'lead.status.changed',
    );

    public static function init(): void
    {
        add_action('estandar_lead_event_recorded', array(__CLASS__, 'record'), 30, 4);
    }

    public static function record($source_event_id, $lead_id, $source_event_type, $metadata = array()): int
    {
        if (!self::is_enabled()) {
            return 0;
        }

        $source_event_id = absint($source_event_id);
        $lead_id = absint($lead_id);
        $source_event_type = sanitize_key((string) $source_event_type);
        $event_name = self::canonical_name($source_event_type);
        if ($source_event_id <= 0 || $lead_id <= 0 || $event_name === '') {
            return 0;
        }

        global $wpdb;
        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, l.lead_uuid, l.project_slug, l.first_source_plugin,
                    s.source_plugin AS submission_source_plugin
             FROM {$wpdb->prefix}es_lead_events e
             INNER JOIN {$wpdb->prefix}es_leads l ON l.id = e.lead_id
             LEFT JOIN {$wpdb->prefix}es_lead_submissions s ON s.id = e.submission_id
             WHERE e.id = %d AND e.lead_id = %d LIMIT 1",
            $source_event_id,
            $lead_id
        ), ARRAY_A);
        if (!is_array($source)) {
            return 0;
        }

        $stored_metadata = json_decode((string) ($source['metadata'] ?? ''), true);
        $metadata = is_array($stored_metadata) ? $stored_metadata : (is_array($metadata) ? $metadata : array());
        if (array_key_exists('score_eligible', $metadata) && empty($metadata['score_eligible'])) {
            return 0;
        }
        $source_plugin = sanitize_key((string) (($source['submission_source_plugin'] ?? '') ?: ($source['first_source_plugin'] ?? '') ?: 'estandar-leads'));
        $idempotency_key = hash('sha256', 'journey:v1|estandar-leads|' . $source_event_id . '|' . $event_name);
        $existing_id = self::existing_id($idempotency_key);
        if ($existing_id > 0) {
            return $existing_id;
        }

        $occurred_at = self::datetime((string) ($source['created_at'] ?? ''));
        $recorded_at = gmdate('Y-m-d H:i:s');
        $actor = self::actor($metadata, absint($source['operator_id'] ?? 0));
        $data = self::data($source_event_type, $source, $metadata);
        $catalog_event = self::catalog_event($source_event_type, (string) ($source['to_status'] ?? ''));
        $event_id = wp_generate_uuid4();
        $correlation_id = !empty($source['submission_id'])
            ? 'submission:' . absint($source['submission_id'])
            : 'lead:' . $lead_id;
        $envelope = array(
            'version' => self::VERSION,
            'event_id' => $event_id,
            'event_type' => $catalog_event,
            'event_name' => $event_name,
            'event_version' => self::VERSION,
            'occurred_at' => gmdate('c', strtotime($occurred_at)),
            'recorded_at' => gmdate('c', strtotime($recorded_at)),
            'lead_id' => $lead_id,
            'lead_uuid' => (string) ($source['lead_uuid'] ?? ''),
            'project_slug' => sanitize_title((string) ($source['project_slug'] ?? '')),
            'source_plugin' => $source_plugin,
            'source_event_id' => $source_event_id,
            'origin' => array(
                'plugin' => $source_plugin,
                'source_event_id' => $source_event_id,
                'source_event_type' => $source_event_type,
            ),
            'actor' => $actor,
            'correlation_id' => $correlation_id,
            'idempotency_key' => $idempotency_key,
            'data' => $data,
            'payload' => $data,
            'metadata' => array(
                'lead_uuid' => (string) ($source['lead_uuid'] ?? ''),
                'project_slug' => sanitize_title((string) ($source['project_slug'] ?? '')),
                'correlation_id' => $correlation_id,
                'idempotency_key' => $idempotency_key,
                'recorded_at' => gmdate('c', strtotime($recorded_at)),
            ),
        );

        $inserted = $wpdb->insert($wpdb->prefix . 'es_commercial_journey_events', array(
            'event_id' => $event_id,
            'event_name' => $event_name,
            'event_version' => self::VERSION,
            'lead_id' => $lead_id,
            'lead_uuid' => $envelope['lead_uuid'],
            'project_slug' => $envelope['project_slug'],
            'source_plugin' => $source_plugin,
            'source_event_id' => $source_event_id,
            'correlation_id' => $correlation_id,
            'sequence_no' => $source_event_id,
            'idempotency_key' => $idempotency_key,
            'actor_json' => wp_json_encode($actor),
            'data_json' => wp_json_encode($data),
            'envelope_json' => wp_json_encode($envelope),
            'occurred_at' => $occurred_at,
            'recorded_at' => $recorded_at,
        ));

        return $inserted ? absint($wpdb->insert_id) : self::existing_id($idempotency_key);
    }

    public static function canonical_name(string $source_event_type): string
    {
        return self::EVENT_MAP[sanitize_key($source_event_type)] ?? '';
    }

    public static function catalog_event(string $source_event_type, string $to_status = ''): string
    {
        $source_event_type = sanitize_key($source_event_type);
        if ($source_event_type === 'lead_created') {
            return 'LeadCaptured';
        }
        if ($source_event_type === 'lead_recontacted') {
            return 'LeadRecontacted';
        }
        if (in_array($source_event_type, array('qualification_completed', 'qualification_review'), true)) {
            return 'LeadQualifiedAI';
        }
        if ($source_event_type === 'qualification_manual_override') {
            return 'LeadQualifiedOperator';
        }

        $status = strtoupper(sanitize_key($to_status));
        if ($status === 'CONTACTED') {
            return 'LeadContacted';
        }
        if (in_array($status, array('WON', 'CONVERTED', 'SALE', 'CLIENT', 'CLIENT_SIGNED', 'DEED_SIGNED', 'DELIVERED'), true)) {
            return 'LeadWon';
        }
        if (in_array($status, array('LOST', 'UNREACHABLE'), true)) {
            return 'LeadLost';
        }
        if (in_array($status, array('DISQUALIFIED', 'INVALID', 'REJECTED'), true)) {
            return 'LeadDisqualified';
        }
        return 'LeadStatusChanged';
    }

    public static function is_enabled(): bool
    {
        if (defined('ESTANDAR_LEADS_JOURNEY_SHADOW_ENABLED') && !ESTANDAR_LEADS_JOURNEY_SHADOW_ENABLED) {
            return false;
        }
        return (bool) apply_filters('estandar_leads_journey_shadow_enabled', true);
    }

    public static function has_source_event(int $source_event_id): bool
    {
        global $wpdb;
        return absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}es_commercial_journey_events WHERE source_event_id = %d LIMIT 1",
            absint($source_event_id)
        ))) > 0;
    }

    private static function existing_id(string $idempotency_key): int
    {
        global $wpdb;
        return absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}es_commercial_journey_events WHERE idempotency_key = %s LIMIT 1",
            $idempotency_key
        )));
    }

    private static function actor(array $metadata, int $operator_id): array
    {
        $type = sanitize_key((string) ($metadata['actor_type'] ?? 'system'));
        if ($type === 'user') {
            $type = 'operator';
        }
        return array(
            'type' => $type !== '' ? $type : 'system',
            'id' => $metadata['actor_id'] ?? ($operator_id > 0 ? $operator_id : null),
        );
    }

    private static function data(string $source_event_type, array $source, array $metadata): array
    {
        if (strpos($source_event_type, 'qualification_') === 0) {
            $qualification = is_array($metadata['qualification'] ?? null) ? $metadata['qualification'] : array();
            return array(
                'decision' => (string) ($qualification['decision'] ?? $source['to_status'] ?? ''),
                'qualification' => (string) ($qualification['qualification'] ?? ''),
                'commercial_fit' => (string) ($qualification['commercial_fit'] ?? ''),
                'rule_id' => (array) ($qualification['matched_rules'] ?? array()),
                'model_or_ruleset_version' => (string) ($qualification['engine_version'] ?? 'COMMERCIAL_QUALIFICATION_V1'),
                'overridden_fields' => (array) ($metadata['overridden'] ?? array()),
                'reason' => (string) ($source['reason'] ?? ''),
            );
        }
        if (in_array($source_event_type, array('status_changed', 'status_overridden'), true)) {
            return array(
                'from' => (string) ($source['from_status'] ?? ''),
                'to' => (string) ($source['to_status'] ?? ''),
                'reason' => (string) ($source['reason'] ?? ''),
            );
        }
        return array(
            'submission_id' => absint($source['submission_id'] ?? 0),
            'channel' => sanitize_key((string) ($source['submission_source_plugin'] ?? $source['first_source_plugin'] ?? '')),
        );
    }

    private static function datetime(string $value): string
    {
        $timestamp = strtotime($value);
        return gmdate('Y-m-d H:i:s', $timestamp ?: time());
    }
}
