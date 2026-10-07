<?php
if ( ! defined( 'ABSPATH' ) ) exit;

interface Estandar_KickOff_Site_Audit_Collector {

	/** Dominio de la sección en el snapshot (key de sections). */
	public function domain();

	/** @return Estandar_KickOff_Site_Audit_Result */
	public function collect();
}
