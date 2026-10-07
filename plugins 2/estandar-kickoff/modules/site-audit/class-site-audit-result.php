<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Value Object: resultado de un colector.
 * Contrato de riesgo: id · severity (P0-P3) · evidence · detail.
 */
class Estandar_KickOff_Site_Audit_Result {

	private $data;
	private $risks;

	public function __construct( array $data = [], array $risks = [] ) {
		$this->data  = $data;
		$this->risks = $risks;
	}

	public function add_risk( $id, $severity, $evidence, $detail ) {
		$this->risks[] = [
			'id'       => $id,
			'severity' => $severity,
			'evidence' => $evidence,
			'detail'   => $detail,
		];
		return $this;
	}

	public function to_array() {
		return [
			'data'  => $this->data,
			'risks' => $this->risks,
		];
	}
}
