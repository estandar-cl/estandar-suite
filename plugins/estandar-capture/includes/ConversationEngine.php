<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConversationEngine
{
	const VERSION = '1.0.0';

	const EXPERIENCES = array(
		'lead'               => 'Contacto',
		'survey'             => 'Encuesta',
		'quote'              => 'Cotización',
		'diagnostico'        => 'Diagnóstico',
		'onboarding'         => 'Onboarding',
		'recovery'           => 'Recuperación',
		'support'            => 'Soporte',
		'assessment'         => 'Evaluación',
		'lead_qualification' => 'Clasificación comercial',
	);

	const WIDGETS = array( 'text', 'textarea', 'select', 'multi_select', 'radio', 'checkbox', 'rating', 'file', 'contact', 'date', 'number', 'email', 'phone', 'url', 'info' );

	const COMPLETION_STRATEGIES = array( 'create_lead', 'send_email', 'book_calendar', 'generate_quote', 'trigger_workflow', 'webhook', 'save_exit' );

	/** Catálogo canónico de tipos de pregunta conversacional (F2 · port del motor legacy). */
	const QUESTION_TYPES = array( 'nps', 'rating', 'matrix', 'emoji', 'cards', 'checkbox', 'text', 'select', 'radio', 'textarea' );

	public static function register(): void
	{
		add_filter( 'estandar_suite_capture_form_types', array( __CLASS__, 'extend_types' ), 10 );
		add_action( 'estandar_suite_capture_form_type_changed', array( __CLASS__, 'on_type_changed' ), 10, 1 );
	}

	public static function extend_types( array $types ): array
	{
		foreach ( self::EXPERIENCES as $key => $label ) {
			if ( ! isset( $types[ $key ] ) ) {
				$types[ $key ] = $label;
			}
		}
		return $types;
	}

	public static function on_type_changed( array $payload ): void
	{
		$form_id = (int) ( $payload['form_id'] ?? 0 );
		$to      = (string) ( $payload['new_type'] ?? $payload['to'] ?? '' );
		if ( $form_id <= 0 ) {
			return;
		}
		$config = get_option( 'estandar_suite_conversation_config', array() );
		$config = is_array( $config ) ? $config : array();
		$key = 'form_' . $form_id;

		if ( ! isset( $config[ $key ] ) ) {
			$config[ $key ] = array();
		}
		if ( $to !== '' ) {
			$config[ $key ]['experience_type'] = $to;
		}
		$config[ $key ]['converted_at'] = gmdate( 'Y-m-d H:i:s' );

		update_option( 'estandar_suite_conversation_config', $config, false );
		do_action( 'estandar_conversation_type_changed', $form_id, $payload['old_type'] ?? '', $to );
	}

	public static function is_conversational( string $type ): bool
	{
		return isset( self::EXPERIENCES[ $type ] );
	}

	public static function experience_label( string $type ): string
	{
		return self::EXPERIENCES[ $type ] ?? $type;
	}

	public static function widgets(): array
	{
		return apply_filters( 'estandar_conversation_widgets', self::WIDGETS );
	}

	public static function completion_strategies(): array
	{
		return apply_filters( 'estandar_conversation_completion_strategies', self::COMPLETION_STRATEGIES );
	}

	public static function get_config( int $form_id ): array
	{
		$config = get_option( 'estandar_suite_conversation_config', array() );
		$config = is_array( $config ) ? $config : array();
		return $config[ 'form_' . $form_id ] ?? array();
	}

	public static function set_config( int $form_id, array $data ): void
	{
		$config = get_option( 'estandar_suite_conversation_config', array() );
		$config = is_array( $config ) ? $config : array();
		$key = 'form_' . $form_id;
		$existing = $config[ $key ] ?? array();
		$config[ $key ] = array_merge( $existing, $data );
		update_option( 'estandar_suite_conversation_config', $config, false );
	}

	public static function emit_event( string $event, int $form_id, array $data = array() ): void
	{
		$payload = array(
			'event'      => $event,
			'form_id'    => $form_id,
			'timestamp'  => gmdate( 'Y-m-d H:i:s' ),
			'data'       => $data,
		);

		do_action( 'estandar_conversation_event', $payload );
		do_action( "estandar_conversation_{$event}", $form_id, $data );
	}

	public static function start_session( int $form_id, array $context = array() ): array
	{
		$session = array(
			'form_id'    => $form_id,
			'started_at' => gmdate( 'Y-m-d H:i:s' ),
			'context'    => $context,
			'answers'    => array(),
			'current_step' => 0,
			'score'      => 0,
		);
		self::emit_event( 'started', $form_id, $context );
		return $session;
	}

	public static function process_step( array &$session, array $steps, int $step_index, array $answer ): array
	{
		$step = $steps[ $step_index ] ?? null;
		if ( ! $step ) {
			return array( 'completed' => true, 'session' => $session );
		}

		$session['answers'][ $step['id'] ] = $answer;
		$session['current_step'] = $step_index;

		if ( isset( $step['scoring'] ) && is_array( $step['scoring'] ) ) {
			$session['score'] += self::calculate_step_score( $step, $answer );
		}

		self::emit_event( 'step_completed', $session['form_id'], array(
			'step_id' => $step['id'],
			'step_index' => $step_index,
			'answer' => $answer,
		) );

		$next_index = $step_index + 1;

		if ( isset( $step['branching'] ) && is_array( $step['branching'] ) ) {
			foreach ( $step['branching'] as $branch ) {
				if ( self::evaluate_condition( $branch['condition'], $answer ) ) {
					$next_index = self::find_step_index( $steps, $branch['goto'] );
					break;
				}
			}
		}

		if ( $next_index >= count( $steps ) ) {
			return array( 'completed' => true, 'session' => $session );
		}

		return array(
			'completed' => false,
			'session'   => $session,
			'next_step' => $steps[ $next_index ],
			'next_index' => $next_index,
		);
	}

	public static function complete_session( array $session, array $completion_config ): array
	{
		$strategy = $completion_config['strategy'] ?? 'create_lead';
		$result = array( 'session' => $session, 'strategy' => $strategy, 'actions' => array() );

		switch ( $strategy ) {
			case 'create_lead':
				do_action( 'estandar_conversation_lead_qualified', $session );
				$result['actions'][] = 'lead_created';
				break;
			case 'send_email':
				do_action( 'estandar_conversation_completed', $session, $completion_config );
				$result['actions'][] = 'email_queued';
				break;
			case 'book_calendar':
				do_action( 'estandar_booking_requested', $session, $completion_config );
				$result['actions'][] = 'booking_requested';
				break;
			case 'generate_quote':
				do_action( 'estandar_quote_generated', $session, $completion_config );
				$result['actions'][] = 'quote_generated';
				break;
			case 'trigger_workflow':
				do_action( 'estandar_conversation_workflow_triggered', $session, $completion_config );
				$result['actions'][] = 'workflow_triggered';
				break;
		}

		self::emit_event( 'completed', $session['form_id'], array(
			'strategy' => $strategy,
			'score'    => $session['score'],
			'answers'  => $session['answers'],
		) );

		return $result;
	}

	private static function calculate_step_score( array $step, array $answer ): int
	{
		$scoring = $step['scoring'];
		foreach ( $scoring['rules'] ?? array() as $rule ) {
			if ( self::evaluate_rule( $rule, $answer ) ) {
				return (int) ( $rule['score'] ?? 0 );
			}
		}
		return 0;
	}

	private static function evaluate_rule( array $rule, array $answer ): bool
	{
		$field = $rule['field'] ?? '';
		$operator = $rule['operator'] ?? 'eq';
		$value = $rule['value'] ?? null;
		$actual = $answer[ $field ] ?? ( $answer['value'] ?? null );

		switch ( $operator ) {
			case 'eq': return $actual === $value;
			case 'neq': return $actual !== $value;
			case 'contains': return is_string( $actual ) && strpos( $actual, (string) $value ) !== false;
			case 'in': return is_array( $value ) && in_array( $actual, $value, true );
			case 'not_in': return is_array( $value ) && ! in_array( $actual, $value, true );
			default: return false;
		}
	}

	private static function evaluate_condition( string $condition, array $answer ): bool
	{
		$parts = explode( ':', $condition, 2 );
		$field = trim( $parts[0] );
		$expected = trim( $parts[1] ?? '' );
		$actual = $answer[ $field ] ?? ( $answer['value'] ?? '' );
		return (string) $actual === $expected;
	}

	private static function find_step_index( array $steps, string $step_id ): int
	{
		foreach ( $steps as $i => $step ) {
			if ( ( $step['id'] ?? '' ) === $step_id ) {
				return $i;
			}
		}
		return 0;
	}

	/* ── F2 · Runtime de Surveys (port canónico del motor legacy) ───────── */

	/** ¿El tipo de pregunta es parte del catálogo canónico? */
	public static function is_supported_question_type( string $type ): bool
	{
		return in_array( strtolower( trim( $type ) ), self::QUESTION_TYPES, true );
	}

	/** Catálogo de tipos de pregunta (filtrable). */
	public static function question_type_catalog(): array
	{
		return apply_filters( 'estandar_conversation_question_types', self::QUESTION_TYPES );
	}

	/**
	 * Validación/sanitización por tipo de pregunta (paridad con motor legacy).
	 * @return array{valid:bool, value:string, error:string}
	 */
	public static function validate_answer_value( string $type, string $value, array $field = array() ): array
	{
		$type = self::is_supported_question_type( $type ) ? strtolower( trim( $type ) ) : 'text';

		switch ( $type ) {
			case 'checkbox':
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$checked   = array_map( 'sanitize_text_field', $decoded );
					$other_val = sanitize_text_field( (string) ( $decoded['_other_text'] ?? '' ) );
					unset( $checked['_other_text'] );
					$sanitized = array( 'checked' => array_values( $checked ) );
					if ( $other_val !== '' ) {
						$sanitized['other_text'] = $other_val;
					}
					return array( 'valid' => true, 'value' => wp_json_encode( $sanitized, JSON_UNESCAPED_UNICODE ), 'error' => '' );
				}
				return array( 'valid' => true, 'value' => sanitize_text_field( $value ), 'error' => '' );

			case 'nps':
				if ( $value !== '' ) {
					$v = (int) $value;
					if ( $v < 0 || $v > 7 ) {
						return array( 'valid' => false, 'value' => $value, 'error' => 'Valor NPS fuera de rango (0-7).' );
					}
				}
				return array( 'valid' => true, 'value' => $value, 'error' => '' );

			case 'rating':
				if ( $value !== '' ) {
					$v   = (int) $value;
					$max = (int) ( $field['max'] ?? 5 );
					if ( $v < 1 || $v > $max ) {
						return array( 'valid' => false, 'value' => $value, 'error' => "Valor de estrellas fuera de rango (1-{$max})." );
					}
				}
				return array( 'valid' => true, 'value' => $value, 'error' => '' );

			case 'matrix':
				if ( $value !== '' ) {
					$decoded = json_decode( $value, true );
					if ( is_array( $decoded ) ) {
						$scale = (int) ( $field['scale'] ?? 7 );
						foreach ( $decoded as $score ) {
							$s = (int) $score;
							if ( $s < 1 || $s > $scale ) {
								return array( 'valid' => false, 'value' => $value, 'error' => "Valor de evaluación fuera de rango (1-{$scale})." );
							}
						}
					}
				}
				return array( 'valid' => true, 'value' => $value, 'error' => '' );

			default:
				return array( 'valid' => true, 'value' => $value, 'error' => '' );
		}
	}

	/**
	 * Normaliza conversation/survey config (contrato MS-2 · ADR 0034).
	 * Devuelve [] si no hay config válida. Port del motor legacy.
	 */
	public static function normalize_config( $raw ): array
	{
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( $raw === '' ) {
				return array();
			}
			$decoded = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$decoded = $raw;
		} else {
			return array();
		}

		if ( ! is_array( $decoded ) || $decoded === array() ) {
			return array();
		}

		$config = array();

		$flow = array();
		if ( isset( $decoded['flow'] ) && is_array( $decoded['flow'] ) ) {
			$mode = sanitize_key( (string) ( $decoded['flow']['mode'] ?? 'linear' ) );
			if ( ! in_array( $mode, array( 'linear', 'branching', 'progressive' ), true ) ) {
				$mode = 'linear';
			}
			$flow['mode'] = $mode;

			$start = sanitize_key( (string) ( $decoded['flow']['start'] ?? '' ) );
			if ( $start !== '' ) {
				$flow['start'] = $start;
			}

			$rules = array();
			foreach ( (array) ( $decoded['flow']['rules'] ?? array() ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$from = sanitize_key( (string) ( $rule['from'] ?? '' ) );
				if ( $from === '' ) {
					continue;
				}
				$nr = array( 'from' => $from );
				if ( isset( $rule['if_answer'] ) && is_scalar( $rule['if_answer'] ) ) {
					$nr['if_answer'] = sanitize_text_field( (string) $rule['if_answer'] );
				}
				$in = array();
				foreach ( (array) ( $rule['if_answer_in'] ?? array() ) as $v ) {
					if ( is_scalar( $v ) ) {
						$v = sanitize_text_field( (string) $v );
						if ( $v !== '' ) {
							$in[] = $v;
						}
					}
				}
				if ( $in !== array() ) {
					$nr['if_answer_in'] = $in;
				}
				$goto = sanitize_key( (string) ( $rule['goto'] ?? '__end__' ) );
				$nr['goto'] = $goto !== '' ? $goto : '__end__';
				$rules[] = $nr;
			}
			if ( $rules !== array() ) {
				$flow['rules'] = $rules;
			}
		}
		if ( $flow !== array() ) {
			$config['flow'] = $flow;
		}

		$scoring = array();
		if ( isset( $decoded['scoring'] ) && is_array( $decoded['scoring'] ) ) {
			$weights = array();
			foreach ( (array) ( $decoded['scoring']['weights'] ?? array() ) as $k => $w ) {
				$k = sanitize_key( (string) $k );
				if ( $k === '' ) {
					continue;
				}
				if ( is_numeric( $w ) ) {
					$weights[ $k ] = (float) $w;
				} elseif ( is_array( $w ) ) {
					$map = array();
					foreach ( $w as $val => $p ) {
						if ( is_scalar( $val ) && is_numeric( $p ) ) {
							$map[ (string) $val ] = (float) $p;
						}
					}
					if ( $map !== array() ) {
						$weights[ $k ] = $map;
					}
				}
			}
			if ( $weights !== array() ) {
				$scoring['weights'] = $weights;
			}
			$result_rules = array();
			foreach ( (array) ( $decoded['scoring']['result_rules'] ?? array() ) as $rr ) {
				if ( ! is_array( $rr ) ) {
					continue;
				}
				$nrr = array();
				if ( isset( $rr['min_score'] ) && is_numeric( $rr['min_score'] ) ) {
					$nrr['min_score'] = (float) $rr['min_score'];
				}
				if ( isset( $rr['max_score'] ) && is_numeric( $rr['max_score'] ) ) {
					$nrr['max_score'] = (float) $rr['max_score'];
				}
				$segment = sanitize_key( (string) ( $rr['segment'] ?? '' ) );
				if ( $segment !== '' ) {
					$nrr['segment'] = $segment;
				}
				$tier = sanitize_text_field( (string) ( $rr['tier'] ?? '' ) );
				if ( $tier !== '' ) {
					$nrr['tier'] = $tier;
				}
				if ( $nrr !== array() ) {
					$result_rules[] = $nrr;
				}
			}
			if ( $result_rules !== array() ) {
				$scoring['result_rules'] = $result_rules;
			}
			if ( isset( $decoded['scoring']['default_result'] ) && is_array( $decoded['scoring']['default_result'] ) ) {
				$dr = array();
				$seg = sanitize_key( (string) ( $decoded['scoring']['default_result']['segment'] ?? '' ) );
				if ( $seg !== '' ) {
					$dr['segment'] = $seg;
				}
				$tier = sanitize_text_field( (string) ( $decoded['scoring']['default_result']['tier'] ?? '' ) );
				if ( $tier !== '' ) {
					$dr['tier'] = $tier;
				}
				if ( $dr !== array() ) {
					$scoring['default_result'] = $dr;
				}
			}
			if ( $scoring !== array() ) {
				$config['scoring'] = $scoring;
			}
		}

		$completion = array();
		$cs = $decoded['completion_strategy'] ?? array();
		if ( is_array( $cs ) ) {
			$type = sanitize_key( (string) ( $cs['type'] ?? 'email' ) );
			if ( ! in_array( $type, array( 'email', 'lead', 'request', 'artefacto', 'reporte', 'calendario' ), true ) ) {
				$type = 'email';
			}
			$completion['type'] = $type;
			foreach ( array( 'lead_pipeline', 'notify_emails' ) as $bool_key ) {
				if ( isset( $cs[ $bool_key ] ) ) {
					$completion[ $bool_key ] = ! empty( $cs[ $bool_key ] );
				}
			}
		}
		if ( $completion !== array() ) {
			$config['completion_strategy'] = $completion;
		}

		$id = sanitize_key( (string) ( $decoded['identification'] ?? 'anonymous' ) );
		if ( ! in_array( $id, array( 'anonymous', 'identified', 'optional' ), true ) ) {
			$id = 'anonymous';
		}
		$config['identification'] = $id;

		return $config;
	}

	/**
	 * Mapa de branching por índice de pregunta (port flow_map del motor legacy).
	 * @return array<int, array{rules:array, catchall:null|int|string}>
	 */
	public static function flow_map( array $config, array $fields ): array
	{
		$flow = $config['flow'] ?? array();
		if ( empty( $flow['rules'] ) ) {
			return array();
		}

		$by_name = array();
		foreach ( $fields as $i => $f ) {
			if ( is_array( $f ) ) {
				$name = sanitize_key( (string) ( $f['name'] ?? '' ) );
				if ( $name !== '' ) {
					$by_name[ $name ] = (int) $i;
				}
			}
		}
		$resolve = static function ( string $ref ) use ( $by_name, $fields ) {
			if ( $ref === '' || $ref === '__end__' ) {
				return null;
			}
			if ( is_numeric( $ref ) && isset( $fields[ (int) $ref ] ) ) {
				return (int) $ref;
			}
			return $by_name[ $ref ] ?? null;
		};

		$map = array();
		foreach ( (array) $flow['rules'] as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$from = sanitize_key( (string) ( $rule['from'] ?? '' ) );
			$from_idx = $resolve( $from );
			if ( $from_idx === null ) {
				continue;
			}
			$answers = array();
			if ( isset( $rule['if_answer'] ) && is_scalar( $rule['if_answer'] ) ) {
				$a = sanitize_text_field( (string) $rule['if_answer'] );
				if ( $a !== '' ) {
					$answers[] = $a;
				}
			}
			foreach ( (array) ( $rule['if_answer_in'] ?? array() ) as $v ) {
				if ( is_scalar( $v ) ) {
					$a = sanitize_text_field( (string) $v );
					if ( $a !== '' ) {
						$answers[] = $a;
					}
				}
			}

			// FLOW_VALUE_LABEL_V1 (ADR 0034): un `if_answer` puede referirse al
			// VALUE canónico o al LABEL de la opción según cómo se guardó el
			// config (los value de las opciones se degradaron a slug en acacios
			// form 16 → SERVICIO caía a documentación). Para que el branching sea
			// robusto en cualquier instalación, cada if_answer se expande con el
			// value y el label de la opción del campo de origen que coincida
			// (exacto o normalizado), así matchea el valor que el usuario envía
			// sin importar el esquema de values del config.
			$from_field = $fields[ $from_idx ] ?? null;
			$from_opts  = ( is_array( $from_field ) && is_array( $from_field['options'] ?? null ) ) ? $from_field['options'] : array();
			if ( $from_opts !== array() && $answers !== array() ) {
				$expanded = array();
				$norm     = static function ( string $s ): string {
					$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
					$s = (string) preg_replace( '/[\s_\-]+/u', '', $s );
					return trim( $s );
				};
				foreach ( $answers as $a ) {
					$an = $norm( $a );
					$expanded[] = $a;
					if ( $an === '' ) {
						continue;
					}
					foreach ( $from_opts as $o ) {
						if ( ! is_array( $o ) ) {
							continue;
						}
						$ov = sanitize_text_field( (string) ( $o['value'] ?? '' ) );
						$ol = sanitize_text_field( (string) ( $o['label'] ?? $ov ) );
						$hit = ( $ov !== '' && $norm( $ov ) === $an ) || ( $ol !== '' && $norm( $ol ) === $an );
						if ( $hit ) {
							if ( $ov !== '' ) { $expanded[] = $ov; }
							if ( $ol !== '' ) { $expanded[] = $ol; }
						}
					}
				}
				$answers = array_values( array_unique( array_filter( $expanded, static function ( string $v ): bool { return $v !== ''; } ) ) );
			}

			$goto_ref = sanitize_key( (string) ( $rule['goto'] ?? '__end__' ) );
			if ( $goto_ref === '' || $goto_ref === '__end__' ) {
				$next = 'end';
			} else {
				$goto_idx = $resolve( $goto_ref );
				if ( $goto_idx === null ) {
					continue; // goto irresoluble → fallback lineal.
				}
				$next = $goto_idx;
			}

			if ( $answers === array() ) {
				if ( ! isset( $map[ $from_idx ]['catchall'] ) ) {
					$map[ $from_idx ]['catchall'] = $next;
				}
			} else {
				$map[ $from_idx ]['rules'][] = array(
					'answers' => array_values( array_unique( $answers ) ),
					'next'    => $next,
				);
			}
		}

		$out = array();
		foreach ( $map as $idx => $data ) {
			$out[ (int) $idx ] = array(
				'rules'    => $data['rules'] ?? array(),
				'catchall' => $data['catchall'] ?? null,
			);
		}
		return $out;
	}

	/** Alias BC de flow_map (paridad con motor legacy). */
	public static function survey_flow_map( array $config, array $fields ): array
	{
		return self::flow_map( $config, $fields );
	}

	/** Answers por nombre de campo desde respuestas persistidas + definición de preguntas. */
	public static function build_answers_from_responses( array $responses, array $fields ): array
	{
		$by_index = array();
		foreach ( $fields as $i => $f ) {
			if ( is_array( $f ) ) {
				$name = sanitize_key( (string) ( $f['name'] ?? '' ) );
				if ( $name !== '' ) {
					$by_index[ (int) $i ] = $name;
				}
			}
		}
		$answers = array();
		foreach ( $responses as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$idx  = (int) ( $r['question_index'] ?? -1 );
			$name = $by_index[ $idx ] ?? '';
			if ( $name === '' ) {
				continue;
			}
			$answers[ $name ] = self::normalize_answer_value( (string) ( $r['answer_value'] ?? '' ) );
		}
		return $answers;
	}

	/** Valor de respuesta normalizado (JSON compuesto se preserva como string JSON). */
	public static function normalize_answer_value( string $value ): string
	{
		$v = trim( $value );
		if ( $v !== '' && $v[0] === '{' ) {
			$decoded = json_decode( $v, true );
			return is_array( $decoded ) ? (string) wp_json_encode( $decoded ) : $v;
		}
		return $v;
	}

	/**
	 * Scoring determinístico por pesos (port del motor legacy).
	 * @return array{score:float, segments:array, tiers:array}
	 */
	public static function evaluate_scoring( array $config, array $answers ): array
	{
		$scoring = $config['scoring'] ?? array();
		$weights = (array) ( $scoring['weights'] ?? array() );
		$score   = 0.0;

		foreach ( $weights as $field => $w ) {
			$val = $answers[ $field ] ?? null;
			if ( is_array( $w ) ) {
				if ( $val !== null && isset( $w[ (string) $val ] ) ) {
					$score += (float) $w[ (string) $val ];
				}
			} elseif ( is_numeric( $w ) ) {
				if ( is_numeric( $val ) ) {
					$score += (float) $val * (float) $w;
				}
			}
		}

		$result = array( 'score' => $score, 'segments' => array(), 'tiers' => array() );
		foreach ( (array) ( $scoring['result_rules'] ?? array() ) as $rule ) {
			$min = isset( $rule['min_score'] ) ? (float) $rule['min_score'] : null;
			$max = isset( $rule['max_score'] ) ? (float) $rule['max_score'] : null;
			if ( ( $min === null || $score >= $min ) && ( $max === null || $score <= $max ) ) {
				if ( ! empty( $rule['segment'] ) ) {
					$result['segments'][] = $rule['segment'];
				}
				if ( ! empty( $rule['tier'] ) ) {
					$result['tiers'][] = $rule['tier'];
				}
			}
		}
		if ( $result['segments'] === array() && isset( $scoring['default_result']['segment'] ) ) {
			$result['segments'][] = $scoring['default_result']['segment'];
		}
		return $result;
	}

	/**
	 * Resultado de finalización de una sesión (port del motor legacy).
	 * @return array{scoring:array|null, completion_type:string}
	 */
	public static function completion_result( array $engine_cfg, array $answers ): array
	{
		$completion = $engine_cfg['completion_strategy'] ?? array();
		$type = (string) ( $completion['type'] ?? 'email' );
		$result = array(
			'scoring'         => null,
			'completion_type' => in_array( $type, array( 'email', 'lead', 'request', 'artefacto', 'reporte', 'calendario' ), true ) ? $type : 'email',
		);
		if ( ! empty( $engine_cfg['scoring'] ) ) {
			$scored = self::evaluate_scoring( $engine_cfg, $answers );
			if ( $scored !== array( 'score' => 0.0, 'segments' => array(), 'tiers' => array() ) ) {
				$result['scoring'] = $scored;
			}
		}
		return $result;
	}
}
