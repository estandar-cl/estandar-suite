<?php
/**
 * E3.0 — Health Check Engine (infraestructura, sin checks migrados).
 * Collector → Health Check → Risk Engine → UI.
 * No altera snapshot ni schema 1.0; se activa en E3.1.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Unidad atómica de evaluación. Regla canon: sin evidencia → NA, nunca FAIL inferido.
 */
interface Estandar_KickOff_Site_Audit_Health_Check {

	/** ID único del check (ej. 'check_ssl'). */
	public function id();

	/** @return string[] Dominios del snapshot que necesita. */
	public function domains_required();

	/**
	 * @param array $sections Data por dominio: [ domain => data ].
	 * @return Estandar_KickOff_Site_Audit_Finding
	 */
	public function evaluate( array $sections );
}

/**
 * Value Object: resultado neutro de un check (sin severidad).
 */
class Estandar_KickOff_Site_Audit_Finding {

	const PASS = 'PASS';
	const FAIL = 'FAIL';
	const WARN = 'WARN';
	const NA   = 'NA';

	private $check_id;
	private $status;
	private $evidence;
	private $context;

	public function __construct( $check_id, $status, $evidence = '', array $context = [] ) {
		$valid          = [ self::PASS, self::FAIL, self::WARN, self::NA ];
		$this->check_id = $check_id;
		$this->status   = in_array( $status, $valid, true ) ? $status : self::NA;
		$this->evidence = $evidence;
		$this->context  = $context;
	}

	public function status() {
		return $this->status;
	}

	public function to_array() {
		return [
			'check_id' => $this->check_id,
			'status'   => $this->status,
			'evidence' => $this->evidence,
			'context'  => $this->context,
		];
	}
}

/**
 * Única autoridad de severidad: traduce findings a risks (contrato E1: id·severity·evidence·detail).
 */
class Estandar_KickOff_Site_Audit_Risk_Engine {

	/** @var Estandar_KickOff_Site_Audit_Health_Check[] */
	private $checks = [];

	/** Mapa declarativo check_id => [ 'severity' => P0-P3, 'detail' => string ]. */
	private $risk_map = [];

	public function register( Estandar_KickOff_Site_Audit_Health_Check $check, $severity, $detail ) {
		$this->checks[ $check->id() ]   = $check;
		$this->risk_map[ $check->id() ] = [ 'severity' => $severity, 'detail' => $detail ];
		return $this;
	}

	/**
	 * Ejecuta todos los checks sobre las secciones del snapshot.
	 *
	 * @param array $sections [ domain => data ] (solo data, sin risks).
	 * @return array [ 'findings' => array[], 'risks' => array[] ] Risks deduplicados: 1 finding = máx. 1 risk.
	 */
	public function run( array $sections ) {
		// Política por instalación: silenciar/re-severizar sin tocar código.
		$risk_map = apply_filters( 'estandar_kickoff_health_policy', $this->risk_map );

		$findings = [];
		$risks    = [];
		foreach ( $this->checks as $id => $check ) {
			$available = array_intersect_key( $sections, array_flip( $check->domains_required() ) );
			$finding   = count( $available ) === count( $check->domains_required() )
				? $check->evaluate( $available )
				: new Estandar_KickOff_Site_Audit_Finding( $id, Estandar_KickOff_Site_Audit_Finding::NA, 'dominio requerido ausente del snapshot' );

			$arr        = $finding->to_array();
			$findings[] = $arr;

			if ( Estandar_KickOff_Site_Audit_Finding::FAIL === $arr['status'] && isset( $risk_map[ $id ] ) && ! isset( $risks[ $id ] ) ) {
				$risks[ $id ] = [
					'id'       => $id,
					'severity' => $risk_map[ $id ]['severity'],
					'evidence' => $arr['evidence'],
					'detail'   => $risk_map[ $id ]['detail'],
				];
			}
		}

		return [ 'findings' => $findings, 'risks' => array_values( $risks ) ];
	}
}
