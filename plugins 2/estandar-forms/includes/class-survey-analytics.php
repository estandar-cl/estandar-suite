<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Survey analytics queries.
 *
 * Storage model (SURVEYS_STORAGE_SIMPLIFICATION_V1):
 *   Funnel events  → ea_events  (event_label = form_id string; event_value = question_index)
 *   Session state  → estandar_form_entries  (status: 'active' | 'survey_completed')
 *   Per-question   → estandar_survey_responses  (session_token, time_spent_seconds, answer_*)
 *
 * All methods have EA-inactive fallbacks (returns zero / empty array).
 * No references to the removed estandar_survey_sessions / estandar_survey_events tables.
 */
class Estandar_Survey_Analytics {

    /* ── helpers ──────────────────────────────────────────────────────── */

    private static function ea_events(): ?string {
        if ( ! class_exists( 'EA_Utils' ) ) {
            return null;
        }
        return EA_Utils::table( 'events' );
    }

    /* ── public API ───────────────────────────────────────────────────── */

    public static function get_summary( int $form_id ): array {
        global $wpdb;

        $entries   = $wpdb->prefix . 'estandar_form_entries';
        $responses = $wpdb->prefix . 'estandar_survey_responses';
        $ea_events = self::ea_events();
        $form_id_s = (string) $form_id;

        // Views: from ea_events (survey_view fired before session starts)
        $views = 0;
        if ( $ea_events ) {
            $views = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $ea_events WHERE event_type = 'survey_view' AND event_label = %s",
                $form_id_s
            ) );
        }

        // Starts: every entry for this form_id is a survey session
        $starts = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $entries WHERE form_id = %d",
            $form_id
        ) );

        // Completed: entries with status = 'survey_completed'
        $completed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $entries WHERE form_id = %d AND status = 'survey_completed'",
            $form_id
        ) );

        // Abandoned: form_entries.status is the authoritative source (set by handle_event on abandon).
        // ea_events fallback counts the event if status wasn't persisted (e.g. session closed before AJAX).
        $abandoned = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $entries WHERE form_id = %d AND status = 'survey_abandoned'",
            $form_id
        ) );
        if ( $abandoned === 0 && $ea_events ) {
            $abandoned = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $ea_events WHERE event_type = 'survey_abandoned' AND event_label = %s",
                $form_id_s
            ) );
        }

        // Avg time: SUM(time_spent_seconds) per session_token from responses.
        // Approximation: excludes time on non-answered screens (hero, completion).
        $avg_seconds = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(session_time) FROM (
                SELECT SUM(time_spent_seconds) AS session_time
                FROM $responses
                WHERE form_id = %d
                GROUP BY session_token
             ) t",
            $form_id
        ) );

        $completion_rate = $starts > 0 ? round( $completed / $starts * 100, 1 ) : 0.0;

        return [
            'views'            => $views,
            'starts'           => $starts,
            'completed'        => $completed,
            'abandoned'        => $abandoned,
            'completion_rate'  => $completion_rate,
            'avg_time_seconds' => (int) $avg_seconds,
        ];
    }

    public static function get_funnel( int $form_id ): array {
        $ea_events = self::ea_events();
        if ( ! $ea_events ) {
            return [];
        }

        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_value AS question_index, COUNT(*) AS cnt
             FROM $ea_events
             WHERE event_type = 'survey_question_view' AND event_label = %s
             GROUP BY event_value
             ORDER BY CAST(event_value AS UNSIGNED) ASC",
            (string) $form_id
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return [];
        }

        $max    = (int) $rows[0]['cnt'];
        $funnel = [];

        foreach ( $rows as $row ) {
            $cnt      = (int) $row['cnt'];
            $funnel[] = [
                'question_index' => (int) $row['question_index'],
                'views'          => $cnt,
                'rate'           => $max > 0 ? round( $cnt / $max * 100, 1 ) : 0.0,
            ];
        }

        return $funnel;
    }

    public static function get_question_times( int $form_id ): array {
        global $wpdb;
        $responses = $wpdb->prefix . 'estandar_survey_responses';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT question_index, question_label, question_type,
                    AVG(time_spent_seconds) AS avg_seconds,
                    COUNT(*) AS response_count
             FROM $responses
             WHERE form_id = %d
             GROUP BY question_index, question_label, question_type
             ORDER BY question_index ASC",
            $form_id
        ), ARRAY_A );

        return array_map( static function ( $row ) {
            return [
                'question_index' => (int) $row['question_index'],
                'question_label' => (string) ( $row['question_label'] ?? '' ),
                'question_type'  => (string) ( $row['question_type'] ?? 'text' ),
                'avg_seconds'    => (int) round( (float) $row['avg_seconds'] ),
                'response_count' => (int) $row['response_count'],
            ];
        }, (array) $rows );
    }

    public static function get_nps( int $form_id ): array {
        global $wpdb;
        $responses = $wpdb->prefix . 'estandar_survey_responses';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT CAST(answer_value AS SIGNED) AS score, COUNT(*) AS cnt
             FROM $responses
             WHERE form_id = %d AND question_type = 'nps' AND answer_value REGEXP '^[0-9]+$'
             GROUP BY score",
            $form_id
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return ['score' => null, 'promoters' => 0, 'passives' => 0, 'detractors' => 0, 'total' => 0];
        }

        $promoters = $passives = $detractors = 0;

        foreach ( $rows as $row ) {
            $score = (int) $row['score'];
            $cnt   = (int) $row['cnt'];
            if ( $score >= 7 )      { $promoters  += $cnt; }
            elseif ( $score >= 5 )  { $passives   += $cnt; }
            else                    { $detractors += $cnt; }
        }

        $total = $promoters + $passives + $detractors;
        $nps   = $total > 0
            ? round( ( $promoters / $total - $detractors / $total ) * 100, 1 )
            : null;

        return [
            'score'      => $nps,
            'promoters'  => $promoters,
            'passives'   => $passives,
            'detractors' => $detractors,
            'total'      => $total,
        ];
    }

    public static function get_rating_avg( int $form_id ): array {
        global $wpdb;
        $responses = $wpdb->prefix . 'estandar_survey_responses';
        $forms_tbl = $wpdb->prefix . 'estandar_forms';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT AVG(CAST(answer_value AS DECIMAL(4,2))) AS avg_rating, COUNT(*) AS total
             FROM $responses
             WHERE form_id = %d AND question_type = 'rating' AND answer_value REGEXP '^[0-9]+$'",
            $form_id
        ), ARRAY_A );

        // Detect max from survey question config, default to 5
        $max = 5;
        $survey = $wpdb->get_var( $wpdb->prepare(
            "SELECT fields_json FROM $forms_tbl WHERE id = %d AND form_type = 'survey'",
            $form_id
        ) );
        if ( $survey ) {
            $structure = json_decode( $survey, true );
            if ( is_array( $structure ) ) {
                foreach ( $structure as $field ) {
                    if ( ( $field['type'] ?? '' ) === 'rating' && isset( $field['max'] ) ) {
                        $max = (int) $field['max'];
                        break;
                    }
                }
            }
        }

        return [
            'avg_rating' => $row && $row['avg_rating'] !== null ? round( (float) $row['avg_rating'], 2 ) : null,
            'total'      => $row ? (int) $row['total'] : 0,
            'max'        => $max,
        ];
    }

    public static function get_matrix_stats( int $form_id ): array {
        global $wpdb;
        $responses = $wpdb->prefix . 'estandar_survey_responses';
        $forms_tbl = $wpdb->prefix . 'estandar_forms';

        // Detect scale from survey config
        $scale = 7;
        $by_row = [];
        $survey = $wpdb->get_var( $wpdb->prepare(
            "SELECT fields_json FROM $forms_tbl WHERE id = %d AND form_type = 'survey'",
            $form_id
        ) );
        if ( $survey ) {
            $structure = json_decode( $survey, true );
            if ( is_array( $structure ) ) {
                foreach ( $structure as $field ) {
                    if ( ( $field['type'] ?? '' ) === 'matrix' ) {
                        $scale = (int) ( $field['scale'] ?? 7 );
                        if ( isset( $field['rows'] ) && is_array( $field['rows'] ) ) {
                            foreach ( $field['rows'] as $row ) {
                                $by_row[ $row['id'] ] = [
                                    'label'  => $row['label'] ?? '',
                                    'scores' => [],
                                ];
                            }
                        }
                        break;
                    }
                }
            }
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT answer_value FROM $responses
             WHERE form_id = %d AND question_type = 'matrix'",
            $form_id
        ), ARRAY_A );

        foreach ( $rows as $r ) {
            $data = json_decode( $r['answer_value'], true );
            if ( ! is_array( $data ) ) continue;
            foreach ( $data as $row_id => $score ) {
                $score = (int) $score;
                if ( $score < 1 || $score > $scale ) continue;
                if ( ! isset( $by_row[ $row_id ] ) ) continue;
                $by_row[ $row_id ]['scores'][] = $score;
            }
        }

        $result = [];
        foreach ( $by_row as $row_id => $data ) {
            $scores = $data['scores'];
            $total  = count( $scores );
            $avg    = $total > 0 ? round( array_sum( $scores ) / $total, 2 ) : null;

            // Standard deviation
            $stddev = null;
            if ( $total > 1 && $avg !== null ) {
                $variance = 0;
                foreach ( $scores as $sc ) {
                    $variance += ( $sc - $avg ) ** 2;
                }
                $stddev = round( sqrt( $variance / ( $total - 1 ) ), 2 );
            }

            // Distribution
            $dist = array_count_values( $scores );
            $dist_full = [];
            for ( $s = 1; $s <= $scale; $s++ ) {
                $dist_full[ $s ] = $dist[ $s ] ?? 0;
            }

            $result[] = [
                'row_id' => $row_id,
                'label'  => $data['label'],
                'avg'    => $avg,
                'stddev' => $stddev,
                'total'  => $total,
                'dist'   => $dist_full,
            ];
        }

        // Sort by avg descending (best → worst)
        usort( $result, function ( $a, $b ) {
            if ( $a['avg'] === null && $b['avg'] === null ) return 0;
            if ( $a['avg'] === null ) return 1;
            if ( $b['avg'] === null ) return -1;
            return $b['avg'] <=> $a['avg'];
        } );

        return $result;
    }

    /**
     * Paginated respondent list.
     * Reads from form_entries (session metadata) + survey_responses (answers).
     * Returns same shape as the original implementation so survey-results.php needs no changes.
     */
    public static function get_responses_page( int $form_id, int $page = 1, int $per_page = 25 ): array {
        global $wpdb;

        $entries   = $wpdb->prefix . 'estandar_form_entries';
        $responses = $wpdb->prefix . 'estandar_survey_responses';
        $offset    = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $entries WHERE form_id = %d",
            $form_id
        ) );

        $entry_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $entries WHERE form_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
            $form_id, $per_page, $offset
        ), ARRAY_A );

        $result = [];

        foreach ( (array) $entry_rows as $entry ) {
            $data = (array) ( json_decode( (string) $entry['data_json'], true ) ?: [] );

            // Only include rows written by the survey engine.
            if ( empty( $data['survey_session'] ) ) {
                continue;
            }

            $token = (string) ( $data['session_token'] ?? '' );
            $resps = [];

            if ( $token !== '' ) {
                $resps = (array) $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM $responses WHERE session_token = %s ORDER BY question_index ASC",
                    $token
                ), ARRAY_A );
            }

            // Shape matches what survey-results.php expects from the original implementation.
            $result[] = [
                'session'   => [
                    'id'                 => $entry['id'],
                    'session_token'      => $token,
                    'respondent_name'    => (string) ( $data['respondent_name'] ?? '' ),
                    'respondent_email'   => (string) ( $data['respondent_email'] ?? '' ),
                    'respondent_phone'   => (string) ( $data['respondent_phone'] ?? '' ),
                    'status'             => $entry['status'] === 'survey_completed' ? 'completed' : 'started',
                    'answered_questions' => count( $resps ),
                    'total_questions'    => (int) ( $data['total_questions'] ?? 0 ),
                    'started_at'         => $entry['created_at'] ?? '',
                ],
                'responses' => $resps,
            ];
        }

        return ['total' => $total, 'sessions' => $result];
    }

    public static function get_choice_distribution( int $form_id, string $question_type ): array {
        global $wpdb;
        $responses = $wpdb->prefix . 'estandar_survey_responses';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT question_index, question_label, answer_value, answer_label, COUNT(*) AS cnt
             FROM $responses
             WHERE form_id = %d AND question_type = %s
             GROUP BY question_index, answer_value, answer_label
             ORDER BY question_index ASC, cnt DESC",
            $form_id, $question_type
        ), ARRAY_A );

        $grouped = [];
        foreach ( (array) $rows as $row ) {
            $qi = (int) $row['question_index'];
            if ( ! isset( $grouped[ $qi ] ) ) {
                $grouped[ $qi ] = [
                    'question_index' => $qi,
                    'question_label' => (string) ( $row['question_label'] ?? '' ),
                    'choices'        => [],
                ];
            }
            $grouped[ $qi ]['choices'][] = [
                'value' => (string) $row['answer_value'],
                'label' => (string) $row['answer_label'],
                'count' => (int) $row['cnt'],
            ];
        }

        return array_values( $grouped );
    }
}
