<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Shared document-editing form: field map, rendering and request parsing.
 * No WordPress-admin coupling, so both the wp-admin workspace and the
 * operator front-end gateway render/parse the same editor without duplicating it.
 */
class Estandar_Quotes_Document_Form {
	public static function field_map(): array {
		return array( 'client.name' => 'Cliente', 'client.tax_id' => 'RUT', 'client.phone' => 'Teléfono', 'client.email' => 'Email', 'project.name' => 'Proyecto', 'project.stage' => 'Etapa', 'property.unit_code' => 'Unidad/casa', 'property.model' => 'Modelo', 'property.orientation' => 'Orientación', 'property.area_total_m2' => 'Superficie total', 'property.area_usable_m2' => 'Superficie útil', 'property.floors' => 'Pisos', 'property.bedrooms' => 'Dormitorios', 'property.bathrooms' => 'Baños', 'pricing.total_uf.amount' => 'Valor UF', 'uf_rate.value_clp' => 'UF en CLP', 'uf_rate.effective_date' => 'Fecha UF', 'uf_rate.source' => 'Fuente UF', 'financing.credit_percent' => 'Crédito %', 'financing.annual_rate_percent' => 'Tasa anual %', 'financing.income_multiple' => 'Multiplicador renta', 'validity.days' => 'Vigencia días', 'project.sales_room.name' => 'Sala de ventas', 'project.sales_room.address' => 'Dirección sala', 'issuer.authority_name' => 'Operador', 'conditions.items_text' => 'Condiciones', 'property.images_refs' => 'Referencias de imágenes permitidas' );
	}

	public static function at( array $a, string $path ) {
		foreach ( explode( '.', $path ) as $part ) { if ( ! is_array( $a ) || ! array_key_exists( $part, $a ) ) { return ''; } $a = $a[ $part ]; }
		return $a;
	}

	public static function put( array &$a, string $path, $value ): void {
		$parts = explode( '.', $path ); $last = array_pop( $parts );
		foreach ( $parts as $part ) { if ( ! isset( $a[ $part ] ) || ! is_array( $a[ $part ] ) ) { $a[ $part ] = array(); } $a =& $a[ $part ]; }
		$a[ $last ] = $value;
	}

	public static function render_fields( array $d, bool $readonly, string $field_name = 'q' ): string {
		$disabled = $readonly ? ' disabled' : ''; $out = '<table class="form-table"><tbody>';
		foreach ( self::field_map() as $path => $label ) {
			$value = $path === 'property.images_refs' ? implode( "\n", array_map( static function ( $asset ) { return $asset['asset_ref'] ?? ''; }, (array) ( $d['property']['images'] ?? array() ) ) ) : self::at( $d, $path );
			$out .= '<tr><th><label>' . esc_html( $label ) . '</label></th><td>';
			if ( $path === 'conditions.items_text' || $path === 'property.images_refs' ) {
				$out .= '<textarea name="' . esc_attr( $field_name ) . '[' . esc_attr( $path ) . ']" rows="4" class="large-text"' . $disabled . '>' . esc_textarea( is_array( $value ) ? implode( "\n", $value ) : $value ) . '</textarea>';
			} else {
				$out .= '<input class="regular-text" name="' . esc_attr( $field_name ) . '[' . esc_attr( $path ) . ']" value="' . esc_attr( (string) $value ) . '"' . $disabled . '>';
			}
			$out .= '</td></tr>';
		}
		$out .= '<tr><th>Bodega</th><td><input type="checkbox" name="' . esc_attr( $field_name ) . '[property.storage]" value="1"' . checked( ! empty( $d['property']['storage'] ), true, false ) . $disabled . '></td></tr>';
		$out .= '<tr><th>Estacionamiento</th><td><input type="checkbox" name="' . esc_attr( $field_name ) . '[property.parking]" value="1"' . checked( ! empty( $d['property']['parking'] ), true, false ) . $disabled . '></td></tr>';
		$out .= '</tbody></table>';
		return $out;
	}

	/** $input is the already-unslashed request sub-array (e.g. $_POST['q']), never raw superglobals. */
	public static function document_from_request( array $current, array $input, string $profile_key ): array {
		$d = $current;
		foreach ( $input as $path => $value ) {
			if ( $path !== 'property.images_refs' ) { self::put( $d, sanitize_text_field( $path ), is_array( $value ) ? $value : sanitize_text_field( $value ) ); }
		}
		$d['property']['storage'] = ! empty( $input['property.storage'] );
		$d['property']['parking'] = ! empty( $input['property.parking'] );
		if ( isset( $input['conditions.items_text'] ) ) {
			$d['conditions']['items'] = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/\r?\n/', (string) $input['conditions.items_text'] ) ) ) );
		}
		if ( isset( $input['property.images_refs'] ) ) {
			$allowed = array_flip( Estandar_Quotes_Document_Definitions::get( $profile_key )['assets'] ?? array() );
			$d['property']['images'] = array();
			foreach ( preg_split( '/\r?\n/', (string) $input['property.images_refs'] ) as $ref ) {
				$ref = sanitize_key( $ref );
				if ( isset( $allowed[ $ref ] ) ) { $d['property']['images'][] = array( 'kind' => 'floor_plan', 'asset_ref' => $ref ); }
			}
		}
		return $d;
	}
}
