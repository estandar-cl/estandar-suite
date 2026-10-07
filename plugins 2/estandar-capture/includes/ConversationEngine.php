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
		'incident'           => 'Postventa',
		'recovery'           => 'Recuperación',
		'support'            => 'Soporte',
		'assessment'         => 'Evaluación',
		'lead_qualification' => 'Clasificación comercial',
	);

	const WIDGETS = array( 'text', 'textarea', 'select', 'multi_select', 'radio', 'checkbox', 'rating', 'file', 'contact', 'date', 'number', 'email', 'phone', 'url', 'info' );

	const COMPLETION_STRATEGIES = array( 'create_lead', 'send_email', 'book_calendar', 'generate_quote', 'trigger_workflow', 'webhook', 'save_exit' );

	public static function register(): void
	{
		add_filter( 'estandar_suite_capture_form_types', array( __CLASS__, 'extend_types' ), 10 );
		add_action( 'estandar_suite_capture_form_type_changed', array( __CLASS__, 'on_type_changed' ), 10, 3 );
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

	public static function on_type_changed( int $form_id, string $from, string $to ): void
	{
		$config = get_option( 'estandar_suite_conversation_config', array() );
		$config = is_array( $config ) ? $config : array();
		$key = 'form_' . $form_id;

		if ( ! isset( $config[ $key ] ) ) {
			$config[ $key ] = array();
		}
		$config[ $key ]['experience_type'] = $to;
		$config[ $key ]['converted_at'] = gmdate( 'Y-m-d H:i:s' );

		update_option( 'estandar_suite_conversation_config', $config, false );
		do_action( 'estandar_conversation_type_changed', $form_id, $from, $to );
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
}
