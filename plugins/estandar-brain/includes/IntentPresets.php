<?php
/**
 * IntentPresets — vocabulario de intenciones por ÁREA DE NEGOCIO.
 *
 * Los presets viven como DATOS (no como un `defaults()` único hardcodeado): el
 * sitio elige su área en la UI y precarga su vocabulario, que luego puede editar.
 * Añadir un área nueva no requiere tocar la lógica: se agrega aquí.
 *
 * @package Estandar\Brain
 */

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catálogo de áreas de negocio y sus vocabularios de intent.
 */
class IntentPresets {

	/**
	 * Área por defecto cuando no hay ninguna elegida.
	 */
	const DEFAULT_AREA = 'generico';

	/**
	 * Áreas disponibles: slug => etiqueta.
	 *
	 * @return array<string,string>
	 */
	public static function areas(): array {
		return array(
			'generico'      => 'Genérico (sin rubro específico)',
			'inmobiliaria'  => 'Inmobiliaria / Proyectos',
			'contabilidad'  => 'Contabilidad / Tributario',
			'salud'         => 'Salud / Clínicas',
			'educacion'     => 'Educación / Admisión',
			'automotriz'    => 'Automotriz',
			'construccion'  => 'Construcción / Obras',
			'servicios'     => 'Servicios profesionales',
			'retail'        => 'Retail / E-commerce',
			'otro'          => 'Otro (definir a mano)',
		);
	}

	/**
	 * ¿Es un área válida?
	 *
	 * @param string $area Slug.
	 * @return bool
	 */
	public static function valid( string $area ): bool {
		return array_key_exists( $area, self::areas() );
	}

	/**
	 * Preset (intent => puntos) de un área.
	 *
	 * @param string $area Slug del área.
	 * @return array<string,int>
	 */
	public static function preset( string $area ): array {
		$all = self::all();
		return $all[ $area ] ?? $all[ self::DEFAULT_AREA ];
	}

	/**
	 * Todos los presets.
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function all(): array {
		return array(
			'generico'     => array(
				'cotizar'   => 20,
				'ventas'    => 20,
				'precios'   => 18,
				'asesor'    => 12,
				'consulta'  => 8,
				'urgencia'  => 8,
				'humano'    => 5,
				'info'      => 5,
				'soporte'   => 5,
				'faq'       => 5,
			),
			'inmobiliaria' => array(
				'agendar_visita' => 22,
				'cotizar'        => 18,
				'disponibilidad' => 18,
				'arriendo'       => 18,
				'inversion'      => 16,
				'financiamiento' => 15,
				'precios'        => 14,
				'proyecto'       => 12,
				'entrega'        => 10,
				'consulta'       => 8,
				'humano'         => 5,
			),
			'contabilidad' => array(
				'ventas'               => 20,
				'cotizar'              => 20,
				'precios'              => 18,
				'cambio_contador'      => 18,
				'declaracion_renta'    => 18,
				'contabilidad_mensual' => 15,
				'inicio_actividades'   => 15,
				'opciones'             => 12,
				'asesor'               => 12,
				'tributaria'           => 12,
				'consulta'             => 8,
				'urgencia'             => 8,
				'humano'               => 5,
				'soporte'              => 5,
				'info'                 => 5,
				'faq'                  => 5,
			),
			'salud'        => array(
				'agendar_hora' => 22,
				'urgencia'     => 20,
				'especialidad' => 18,
				'cobertura'    => 15,
				'resultados'   => 14,
				'precios'      => 12,
				'consulta'     => 8,
				'humano'       => 5,
				'info'         => 5,
			),
			'educacion'    => array(
				'admision'  => 22,
				'agendar'   => 18,
				'arancel'   => 18,
				'becas'     => 15,
				'malla'     => 14,
				'modalidad' => 12,
				'consulta'  => 8,
				'info'      => 5,
			),
			'automotriz'   => array(
				'agendar_visita' => 22,
				'test_drive'     => 20,
				'cotizar'        => 20,
				'financiamiento' => 18,
				'precios'        => 16,
				'mantencion'     => 14,
				'repuestos'      => 12,
				'humano'         => 5,
			),
			'construccion' => array(
				'cotizar'        => 22,
				'presupuesto'    => 20,
				'visita_terreno' => 20,
				'precios'        => 16,
				'plazos'         => 14,
				'materiales'     => 12,
				'consulta'       => 8,
			),
			'servicios'    => array(
				'cotizar'        => 20,
				'presupuesto'    => 18,
				'agendar_reunion' => 18,
				'precios'        => 16,
				'urgencia'       => 10,
				'consulta'       => 8,
				'soporte'        => 5,
				'humano'         => 5,
				'info'           => 5,
			),
			'retail'       => array(
				'comprar'    => 22,
				'cotizar'    => 18,
				'stock'      => 18,
				'precios'    => 16,
				'despacho'   => 14,
				'devolucion' => 12,
				'soporte'    => 8,
				'humano'     => 5,
			),
			'otro'         => array(),
		);
	}
}
