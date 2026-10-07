<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SurveyAnalytics — analytics de encuestas nativas v2 (F2).
 *
 * Puerto del Estandar_Survey_Analytics legacy leyendo las tablas v2 propias
 * (SurveyDb). Mismo contrato de métodos y shapes de respuesta para que las
 * pantallas de resultados se re-usen sin cambios de lógica.
 */
class SurveyAnalytics
{
	public static function get_summary( int $form_id ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array( 'views' => 0, 'starts' => 0, 'completed' => 0, 'abandoned' => 0, 'completion_rate' => 0.0, 'avg_time_seconds' => 0 );
		}
		$sessions  = $wpdb->prefix . SurveyDb::SESSIONS_TABLE;
		$responses = $wpdb->prefix . SurveyDb::RESPONSES_TABLE;

		$starts    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $sessions WHERE form_id = %d", $form_id ) );
		$completed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $sessions WHERE form_id = %d AND status = 'completed'", $form_id ) );
		$abandoned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $sessions WHERE form_id = %d AND status = 'abandoned'", $form_id ) );
		$avg = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(session_time) FROM (
				SELECT SUM(time_spent_seconds) AS session_time
				FROM $responses WHERE form_id = %d GROUP BY session_token
			) t", $form_id
		) );

		return array(
			'views'            => 0,
			'starts'           => $starts,
			'completed'        => $completed,
			'abandoned'        => $abandoned,
			'completion_rate'  => $starts > 0 ? round( $completed / $starts * 100, 1 ) : 0.0,
			'avg_time_seconds' => (int) $avg,
		);
	}

	public static function get_question_times( int $form_id ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$responses = $wpdb->prefix . SurveyDb::RESPONSES_TABLE;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT question_index, question_label, question_type,
			        AVG(time_spent_seconds) AS avg_seconds, COUNT(*) AS response_count
			 FROM $responses WHERE form_id = %d
			 GROUP BY question_index, question_label, question_type
			 ORDER BY question_index ASC",
			$form_id
		), ARRAY_A );
		return array_map( static function ( $row ) {
			return array(
				'question_index' => (int) $row['question_index'],
				'question_label' => (string) ( $row['question_label'] ?? '' ),
				'question_type'  => (string) ( $row['question_type'] ?? 'text' ),
				'avg_seconds'    => (int) round( (float) $row['avg_seconds'] ),
				'response_count' => (int) $row['response_count'],
			);
		}, (array) $rows );
	}

	public static function get_nps( int $form_id ): array
	{
		global $wpdb;
		$empty = array( 'score' => null, 'promoters' => 0, 'passives' => 0, 'detractors' => 0, 'total' => 0 );
		if ( ! isset( $wpdb ) ) {
			return $empty;
		}
		$responses = $wpdb->prefix . SurveyDb::RESPONSES_TABLE;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT CAST(answer_value AS SIGNED) AS score, COUNT(*) AS cnt
			 FROM $responses WHERE form_id = %d AND question_type = 'nps' AND answer_value REGEXP '^[0-9]+$'
			 GROUP BY score", $form_id
		), ARRAY_A );
		if ( empty( $rows ) ) {
			return $empty;
		}
		$promoters = $passives = $detractors = 0;
		foreach ( $rows as $row ) {
			$score = (int) $row['score'];
			$cnt   = (int) $row['cnt'];
			if ( $score >= 7 ) { $promoters += $cnt; }
			elseif ( $score >= 5 ) { $passives += $cnt; }
			else { $detractors += $cnt; }
		}
		$total = $promoters + $passives + $detractors;
		return array(
			'score'      => $total > 0 ? round( ( $promoters / $total - $detractors / $total ) * 100, 1 ) : null,
			'promoters'  => $promoters,
			'passives'   => $passives,
			'detractors' => $detractors,
			'total'      => $total,
		);
	}

	public static function get_rating_avg( int $form_id ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array( 'avg_rating' => null, 'total' => 0, 'max' => 5 );
		}
		$responses = $wpdb->prefix . SurveyDb::RESPONSES_TABLE;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT AVG(CAST(answer_value AS DECIMAL(4,2))) AS avg_rating, COUNT(*) AS total
			 FROM $responses WHERE form_id = %d AND question_type = 'rating' AND answer_value REGEXP '^[0-9]+$'",
			$form_id
		), ARRAY_A );
		$max = 5;
		$form = Forms::get( $form_id );
		if ( $form ) {
			$config = json_decode( (string) $form['config_json'], true );
			$fields = Forms::renderable_fields( is_array( $config ) ? $config : array() );
			foreach ( $fields as $field ) {
				if ( ( $field['type'] ?? '' ) === 'rating' && isset( $field['max'] ) ) {
					$max = (int) $field['max'];
					break;
				}
			}
		}
		return array(
			'avg_rating' => $row && $row['avg_rating'] !== null ? round( (float) $row['avg_rating'], 2 ) : null,
			'total'      => $row ? (int) $row['total'] : 0,
			'max'        => $max,
		);
	}

	public static function get_matrix_stats( int $form_id ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$responses = $wpdb->prefix . SurveyDb::RESPONSES_TABLE;

		$scale  = 7;
		$by_row = array();
		$form   = Forms::get( $form_id );
		if ( $form ) {
			$config = json_decode( (string) $form['config_json'], true );
			$fields = Forms::renderable_fields( is_array( $config ) ? $config : array() );
			foreach ( $fields as $field ) {
				if ( ( $field['type'] ?? '' ) === 'matrix' ) {
					$scale = (int) ( $field['scale'] ?? 7 );
					if ( isset( $field['rows'] ) && is_array( $field['rows'] ) ) {
						foreach ( $field['rows'] as $row ) {
							$by_row[ $row['id'] ] = array( 'label' => $row['label'] ?? '', 'scores' => array() );
						}
					}
					break;
				}
			}
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT answer_value FROM $responses WHERE form_id = %d AND question_type = 'matrix'", $form_id
		), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$data = json_decode( $r['answer_value'], true );
			if ( ! is_array( $data ) ) continue;
			foreach ( $data as $row_id => $score ) {
				$score = (int) $score;
				if ( $score < 1 || $score > $scale || ! isset( $by_row[ $row_id ] ) ) continue;
				$by_row[ $row_id ]['scores'][] = $score;
			}
		}

		$result = array();
		foreach ( $by_row as $row_id => $d ) {
			$scores = $d['scores'];
			$total  = count( $scores );
			$avg    = $total > 0 ? round( array_sum( $scores ) / $total, 2 ) : null;
			$dist   = array();
			for ( $s = 1; $s <= $scale; $s++ ) {
				$dist[ $s ] = 0;
			}
			foreach ( $scores as $sc ) {
				$dist[ $sc ] = ( $dist[ $sc ] ?? 0 ) + 1;
			}
			$result[] = array(
				'row_id' => $row_id,
				'label'  => $d['label'],
				'avg'    => $avg,
				'total'  => $total,
				'dist'   => $dist,
			);
		}
		usort( $result, static function ( $a, $b ) {
			if ( $a['avg'] === null && $b['avg'] === null ) return 0;
			if ( $a['avg'] === null ) return 1;
			if ( $b['avg'] === null ) return -1;
			return $b['avg'] <=> $a['avg'];
		} );
		return $result;
	}

	/** Sesiones paginadas (shape igual al legacy survey-results). */
	public static function get_responses_page( int $form_id, int $page = 1, int $per_page = 25 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array( 'total' => 0, 'sessions' => array() );
		}
		$sessions  = $wpdb->prefix . SurveyDb::SESSIONS_TABLE;
		$responses = $wpdb->prefix . SurveyDb::RESPONSES_TABLE;
		$offset    = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $sessions WHERE form_id = %d", $form_id ) );
		$session_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $sessions WHERE form_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
			$form_id, $per_page, $offset
		), ARRAY_A );

		$result = array();
		foreach ( (array) $session_rows as $sess ) {
			$data = (array) ( json_decode( (string) $sess['data_json'], true ) ?: array() );
			$resps = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM $responses WHERE session_token = %s ORDER BY question_index ASC",
				(string) $sess['session_token']
			), ARRAY_A );
			$result[] = array(
				'session' => array(
					'id'                 => (int) $sess['id'],
					'session_token'      => (string) $sess['session_token'],
					'respondent_name'    => (string) ( $data['respondent_name'] ?? '' ),
					'respondent_email'   => (string) ( $data['respondent_email'] ?? '' ),
					'respondent_phone'   => (string) ( $data['respondent_phone'] ?? '' ),
					'status'             => $sess['status'] === 'completed' ? 'completed' : 'started',
					'answered_questions' => count( $resps ),
					'total_questions'    => (int) ( $data['total_questions'] ?? 0 ),
					'started_at'         => $sess['created_at'] ?? '',
				),
				'responses' => $resps,
			);
		}
		return array( 'total' => $total, 'sessions' => $result );
	}

	public static function get_choice_distribution( int $form_id, string $question_type ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$responses = $wpdb->prefix . SurveyDb::RESPONSES_TABLE;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT question_index, question_label, answer_value, answer_label, COUNT(*) AS cnt
			 FROM $responses WHERE form_id = %d AND question_type = %s
			 GROUP BY question_index, answer_value, answer_label
			 ORDER BY question_index ASC, cnt DESC",
			$form_id, $question_type
		), ARRAY_A );
		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$qi = (int) $row['question_index'];
			if ( ! isset( $grouped[ $qi ] ) ) {
				$grouped[ $qi ] = array(
					'question_index' => $qi,
					'question_label' => (string) ( $row['question_label'] ?? '' ),
					'choices'        => array(),
				);
			}
			$grouped[ $qi ]['choices'][] = array(
				'value' => (string) $row['answer_value'],
				'label' => (string) $row['answer_label'],
				'count' => (int) $row['cnt'],
			);
		}
		return array_values( $grouped );
	}
}
