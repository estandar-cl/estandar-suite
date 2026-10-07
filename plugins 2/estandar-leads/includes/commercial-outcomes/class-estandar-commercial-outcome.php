<?php
/**
 * Commercial Outcomes — CommercialOutcome entity (domain).
 *
 * Describes a single business outcome dispatched by an adapter: a lead reaching a
 * commercial stage, what was sent, and what came back. Adapter-agnostic — Meta is
 * only one possible `adapter` value. No WordPress, no Meta-specific logic.
 *
 * @package EstandarLeads\CommercialOutcomes
 * @since   10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Estandar_Commercial_Outcome {

	/** @var int */
	public $lead_id;
	/** @var string|null */
	public $company_id;
	/** @var string|null */
	public $project_id;
	/** @var Estandar_Commercial_Stage */
	public $stage;
	/** @var string */
	public $adapter;
	/** @var string */
	public $event;
	/** @var array */
	public $payload;
	/** @var array */
	public $response;
	/** @var int */
	public $http_code;
	/** @var string */
	public $status;

	public function __construct( array $data ) {
		$this->lead_id    = isset( $data['lead_id'] ) ? (int) $data['lead_id'] : 0;
		$this->company_id = isset( $data['company_id'] ) && $data['company_id'] !== '' ? (string) $data['company_id'] : null;
		$this->project_id = isset( $data['project_id'] ) && $data['project_id'] !== '' ? (string) $data['project_id'] : null;
		$this->stage      = $data['stage'] instanceof Estandar_Commercial_Stage
			? $data['stage']
			: new Estandar_Commercial_Stage( (string) ( $data['stage'] ?? '' ) );
		$this->adapter    = (string) ( $data['adapter'] ?? '' );
		$this->event      = (string) ( $data['event'] ?? '' );
		$this->payload    = is_array( $data['payload'] ?? null ) ? $data['payload'] : array();
		$this->response   = is_array( $data['response'] ?? null ) ? $data['response'] : array();
		$this->http_code  = isset( $data['http_code'] ) ? (int) $data['http_code'] : 0;
		$this->status     = (string) ( $data['status'] ?? '' );
	}

	public function stage_code(): string {
		return $this->stage->code();
	}
}
