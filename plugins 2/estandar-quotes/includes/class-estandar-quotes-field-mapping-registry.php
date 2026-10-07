<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Field_Mapping_Registry {

	private static array $maps = [];

	public static function register( string $profile_key, array $definitions ): void {
		self::$maps[ $profile_key ] = $definitions;
	}

	public static function for_profile( string $profile_key ): array {
		$base = self::$maps[ $profile_key ] ?? self::defaults();
		$all = apply_filters( 'estandar_quotes_field_mappings', array( $profile_key => $base ) );
		return is_array( $all[ $profile_key ] ?? null ) ? $all[ $profile_key ] : $base;
	}

	public static function defaults(): array {
		return array(
			array(
				'target'    => 'client.tax_id',
				'sources'   => array( 'rut', 'tax_id', 'run', 'identificacion' ),
				'transform' => 'rut',
				'validate'  => 'rut',
			),
			array(
				'target'    => 'project.name',
				'sources'   => array( 'proyecto', 'project', 'nombre_proyecto' ),
				'transform' => 'text',
				'overwrite' => 'if_empty',
			),
			array(
				'target'    => 'project.stage',
				'sources'   => array( 'etapa', 'stage' ),
				'transform' => 'text',
				'overwrite' => 'if_empty',
			),
			array(
				'target'    => 'property.unit_code',
				'sources'   => array( 'unidad', 'unit_code', 'codigo', 'numero_unidad' ),
				'transform' => 'text',
			),
			array(
				'target'    => 'property.model',
				'sources'   => array( 'modelo', 'model', 'tipo_propiedad', 'tipo' ),
				'transform' => 'text',
			),
			array(
				'target'    => 'property.orientation',
				'sources'   => array( 'orientacion', 'orientation' ),
				'transform' => 'text',
			),
			array(
				'target'    => 'property.area_total_m2',
				'sources'   => array( 'superficie_total', 'area_total', 'superficie', 'metros_totales' ),
				'transform' => 'chilean_float',
			),
			array(
				'target'    => 'property.area_usable_m2',
				'sources'   => array( 'superficie_util', 'area_util', 'metros_utiles' ),
				'transform' => 'chilean_float',
			),
			array(
				'target'    => 'property.floors',
				'sources'   => array( 'pisos', 'floors' ),
				'transform' => 'int',
			),
			array(
				'target'    => 'property.bedrooms',
				'sources'   => array( 'dormitorios', 'bedrooms', 'habitaciones' ),
				'transform' => 'int',
			),
			array(
				'target'    => 'property.bathrooms',
				'sources'   => array( 'banos', 'bathrooms', 'baños' ),
				'transform' => 'int',
			),
			array(
				'target'    => 'property.storage',
				'sources'   => array( 'bodega', 'storage' ),
				'transform' => 'chilean_bool',
			),
			array(
				'target'    => 'property.parking',
				'sources'   => array( 'estacionamiento', 'parking' ),
				'transform' => 'chilean_bool',
			),
			array(
				'target'    => 'pricing.total_uf.amount',
				'sources'   => array( 'presupuesto', 'valor', 'precio', 'budget', 'valor_propiedad', 'monto' ),
				'transform' => 'chilean_float',
				'validate'  => 'positive',
				'default'   => null,
			),
			array(
				'target'    => 'uf_rate.value_clp',
				'sources'   => array( 'valor_uf', 'uf_value', 'uf_clp' ),
				'transform' => 'chilean_float',
				'validate'  => 'positive',
			),
			array(
				'target'    => 'uf_rate.effective_date',
				'sources'   => array( 'fecha_uf', 'uf_date' ),
				'transform' => 'date',
				'validate'  => 'date',
			),
		);
	}
}
