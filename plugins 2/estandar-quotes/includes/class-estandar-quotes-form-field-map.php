<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Form_Field_Map {

	private static array $maps = [];

	public static function register( string $profile_key, array $mappings ): void {
		self::$maps[ $profile_key ] = $mappings;
	}

	public static function for_profile( string $profile_key ): array {
		$base = self::$maps[ $profile_key ] ?? [];
		$all = apply_filters( 'estandar_quotes_form_field_map', [ $profile_key => $base ] );
		return $all[ $profile_key ] ?? $base;
	}

	public static function default_mappings(): array {
		return [
			'client.tax_id' => [
				'sources' => [ 'rut', 'tax_id', 'run', 'identificacion' ],
				'transform' => 'rut',
				'overwrite' => 'always',
				'validate' => 'rut',
			],
			'project.name' => [
				'sources' => [ 'proyecto', 'project', 'nombre_proyecto' ],
				'transform' => 'text',
				'overwrite' => 'if_empty',
			],
			'project.stage' => [
				'sources' => [ 'etapa', 'stage' ],
				'transform' => 'text',
				'overwrite' => 'if_empty',
			],
			'property.unit_code' => [
				'sources' => [ 'unidad', 'unit_code', 'codigo', 'numero_unidad' ],
				'transform' => 'text',
				'overwrite' => 'always',
			],
			'property.model' => [
				'sources' => [ 'modelo', 'model', 'tipo_propiedad', 'tipo' ],
				'transform' => 'text',
				'overwrite' => 'always',
			],
			'property.orientation' => [
				'sources' => [ 'orientacion', 'orientation' ],
				'transform' => 'text',
				'overwrite' => 'always',
			],
			'property.area_total_m2' => [
				'sources' => [ 'superficie_total', 'area_total', 'superficie', 'metros_totales' ],
				'transform' => 'chilean_float',
				'overwrite' => 'always',
			],
			'property.area_usable_m2' => [
				'sources' => [ 'superficie_util', 'area_util', 'metros_utiles' ],
				'transform' => 'chilean_float',
				'overwrite' => 'always',
			],
			'property.floors' => [
				'sources' => [ 'pisos', 'floors' ],
				'transform' => 'int',
				'overwrite' => 'always',
			],
			'property.bedrooms' => [
				'sources' => [ 'dormitorios', 'bedrooms', 'habitaciones' ],
				'transform' => 'int',
				'overwrite' => 'always',
			],
			'property.bathrooms' => [
				'sources' => [ 'banos', 'bathrooms', 'baños' ],
				'transform' => 'int',
				'overwrite' => 'always',
			],
			'property.storage' => [
				'sources' => [ 'bodega', 'storage' ],
				'transform' => 'chilean_bool',
				'overwrite' => 'always',
			],
			'property.parking' => [
				'sources' => [ 'estacionamiento', 'parking' ],
				'transform' => 'chilean_bool',
				'overwrite' => 'always',
			],
			'pricing.total_uf.amount' => [
				'sources' => [ 'presupuesto', 'valor', 'precio', 'budget', 'valor_propiedad', 'monto' ],
				'transform' => 'chilean_float',
				'overwrite' => 'always',
				'validate' => 'positive',
			],
			'uf_rate.value_clp' => [
				'sources' => [ 'valor_uf', 'uf_value', 'uf_clp' ],
				'transform' => 'chilean_float',
				'overwrite' => 'always',
				'validate' => 'positive',
			],
			'uf_rate.effective_date' => [
				'sources' => [ 'fecha_uf', 'uf_date' ],
				'transform' => 'date',
				'overwrite' => 'always',
				'validate' => 'date',
			],
		];
	}
}