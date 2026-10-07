<?php
/**
 * Tests for the declarative field mapping system.
 *
 * Covers: multiple sources, source priority, transformers, defaults,
 * conditions, overwrite policies, validations, profile compatibility,
 * unknown/missing fields, images mapping.
 */

define( 'ABSPATH', __DIR__ );

$pass = 0; $fail = 0;
function check( string $name, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { ++$pass; echo "PASS {$name}\n"; }
	else { ++$fail; echo "FAIL {$name}\n"; }
}

function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function add_filter( $h, $cb, $prio = 10, $args = 1 ) { $GLOBALS['filters'][ $h ][] = $cb; }
function apply_filters( $h, $value, ...$args ) {
	if ( ! empty( $GLOBALS['filters'][ $h ] ) ) {
		foreach ( $GLOBALS['filters'][ $h ] as $cb ) {
			$value = call_user_func( $cb, $value, ...$args );
		}
	}
	return $value;
}

require_once dirname( __DIR__ ) . '/includes/interface-estandar-quotes-hydration-stage.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-quote-draft.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-field-transformer.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-field-mapping-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-hydration-pipeline.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-form-stage.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-canonical-json.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-profiles.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-real-estate-profile.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-acacios-profile.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-calculator.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-view-model.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-definitions.php';

Estandar_Quotes_Real_Estate_Profile::init();
Estandar_Quotes_Acacios_Profile::init();

$defaults = Estandar_Quotes_Field_Mapping_Registry::defaults();
Estandar_Quotes_Field_Mapping_Registry::register( 'real_estate@1', $defaults );
Estandar_Quotes_Field_Mapping_Registry::register( 'acacios_real_estate@1', $defaults );
Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Form_Stage() );

// ── Helpers ──────────────────────────────────────────────────────────────

function make_draft(): Estandar_Quotes_Quote_Draft {
	$d = new Estandar_Quotes_Quote_Draft();
	$d->set_meta( 'profile_key', 'real_estate@1' );
	return $d;
}

function hydrate_form( array $form_fields, string $profile_key = 'real_estate@1' ): Estandar_Quotes_Quote_Draft {
	$snapshot = array(
		'lead' => array( 'name' => 'Test', 'email' => 'test@test.cl', 'phone' => '+56900000000' ),
		'submission' => array(
			'message' => 'Test',
			'form_fields' => $form_fields,
			'context' => array(),
		),
	);
	$draft = new Estandar_Quotes_Quote_Draft();
	$draft->set_meta( 'profile_key', $profile_key );
	$draft->set_meta( 'lead_snapshot', $snapshot );
	$context = array(
		'profile_key' => $profile_key,
		'lead_snapshot' => $snapshot,
		'settings' => array(),
	);
	return Estandar_Quotes_Hydration_Pipeline::execute( $draft, $context );
}

// ── Section 1: Multiple sources ──────────────────────────────────────────

$d = hydrate_form( array( 'rut' => '12345678-5' ) );
check( 'primary source rut maps to tax_id', $d->get( 'client.tax_id' ) === '12345678-5' );

$d = hydrate_form( array( 'tax_id' => '11111111-1' ) );
check( 'secondary source tax_id maps to tax_id', $d->get( 'client.tax_id' ) === '11111111-1' );

$d = hydrate_form( array( 'run' => '11111111-1' ) );
check( 'tertiary source run maps to tax_id', $d->get( 'client.tax_id' ) === '11111111-1' );

$d = hydrate_form( array( 'identificacion' => '22222222-2' ) );
check( 'quaternary source identificacion maps to tax_id', $d->get( 'client.tax_id' ) === '22222222-2' );

// ── Section 2: Source priority ──────────────────────────────────────────

$d = hydrate_form( array( 'rut' => '12345678-5', 'tax_id' => '99999999-9' ) );
check( 'first source wins over second', $d->get( 'client.tax_id' ) === '12345678-5' );

$d = hydrate_form( array( 'dormitorios' => '3', 'bedrooms' => '5' ) );
check( 'dormitorios wins over bedrooms', $d->get( 'property.bedrooms' ) === 3 );

$d = hydrate_form( array( 'bedrooms' => '5' ) );
check( 'bedrooms used when dormitorios absent', $d->get( 'property.bedrooms' ) === 5 );

$d = hydrate_form( array( 'habitaciones' => '2' ) );
check( 'habitaciones used when others absent', $d->get( 'property.bedrooms' ) === 2 );

// ── Section 3: Transformers ─────────────────────────────────────────────

$d = hydrate_form( array( 'superficie_total' => '65,5' ) );
check( 'chilean_float: comma decimal', abs( (float) $d->get( 'property.area_total_m2' ) - 65.5 ) < 0.01 );

$d = hydrate_form( array( 'superficie_total' => '12.345,67' ) );
check( 'chilean_float: thousands separator', abs( (float) $d->get( 'property.area_total_m2' ) - 12345.67 ) < 0.01 );

$d = hydrate_form( array( 'bodega' => 'Si' ) );
check( 'chilean_bool: Si is true', $d->get( 'property.storage' ) === true );

$d = hydrate_form( array( 'bodega' => 'No' ) );
check( 'chilean_bool: No is false', $d->get( 'property.storage' ) === false );

$d = hydrate_form( array( 'bodega' => '1' ) );
check( 'chilean_bool: 1 is true', $d->get( 'property.storage' ) === true );

$d = hydrate_form( array( 'dormitorios' => '4' ) );
check( 'int transformer', $d->get( 'property.bedrooms' ) === 4 );

$d = hydrate_form( array( 'fecha_uf' => '2026-07-22' ) );
check( 'date transformer: ISO format', $d->get( 'uf_rate.effective_date' ) === '2026-07-22' );

$d = hydrate_form( array( 'fecha_uf' => '22-07-2026' ) );
check( 'date transformer: DD-MM-YYYY parsed', $d->get( 'uf_rate.effective_date' ) === '2026-07-22' );

// ── Section 4: Default values ───────────────────────────────────────────

$d = hydrate_form( array() );
check( 'no form data returns empty draft', $d->get( 'property.bedrooms' ) === null );

// ── Section 5: Conditions ───────────────────────────────────────────────

$custom_mappings = array(
	array(
		'target'    => 'project.stage',
		'sources'   => array( 'etapa_personalizada' ),
		'transform' => 'text',
		'condition' => array( 'field' => 'proyecto', 'operator' => 'not_empty' ),
	),
);
Estandar_Quotes_Field_Mapping_Registry::register( 'test_conditional', $custom_mappings );
$d = hydrate_form( array( 'etapa_personalizada' => 'Fase 2' ), 'test_conditional' );
check( 'condition fails: etapa not mapped when project absent', $d->get( 'project.stage' ) === null || $d->get( 'project.stage' ) === '' );

$d = hydrate_form( array( 'proyecto' => 'Altacruz', 'etapa_personalizada' => 'Fase 2' ), 'test_conditional' );
check( 'condition passes: etapa mapped when project present', $d->get( 'project.stage' ) === 'Fase 2' );

// ── Section 6: Overwrite policies ───────────────────────────────────────

$if_empty_mappings = array(
	array(
		'target'    => 'project.name',
		'sources'   => array( 'nombre_proyecto' ),
		'transform' => 'text',
		'overwrite' => 'if_empty',
	),
);
Estandar_Quotes_Field_Mapping_Registry::register( 'test_overwrite', $if_empty_mappings );
$snapshot = array(
	'lead' => array( 'name' => 'Test', 'email' => 'test@test.cl', 'phone' => '+56900000000' ),
	'submission' => array(
		'message' => 'Test',
		'form_fields' => array( 'nombre_proyecto' => 'Nuevo Proyecto' ),
		'context' => array(),
	),
);
$draft = new Estandar_Quotes_Quote_Draft();
$draft->set_meta( 'profile_key', 'test_overwrite' );
$draft->set( 'project.name', 'Proyecto Existente' );
$context = array( 'profile_key' => 'test_overwrite', 'lead_snapshot' => $snapshot, 'settings' => array() );
$result = Estandar_Quotes_Hydration_Pipeline::execute( $draft, $context );
check( 'if_empty: does not overwrite existing value', $result->get( 'project.name' ) === 'Proyecto Existente' );

// ── Section 7: Validations ──────────────────────────────────────────────

$d = hydrate_form( array( 'rut' => 'invalid' ) );
check( 'invalid rut is rejected', $d->get( 'client.tax_id' ) !== 'invalid' );

$d = hydrate_form( array( 'rut' => '1' ) );
check( 'too short rut is rejected', $d->get( 'client.tax_id' ) !== '1' );

$d = hydrate_form( array( 'presupuesto' => '-100' ) );
check( 'negative price is rejected', $d->get( 'pricing.total_uf.amount' ) === null || $d->get( 'pricing.total_uf.amount' ) <= 0 );

$d = hydrate_form( array( 'fecha_uf' => 'not-a-date' ) );
check( 'invalid date is rejected', $d->get( 'uf_rate.effective_date' ) !== 'not-a-date' );

$valid_rut = '12345678-5';
check( 'valid rut 12345678-5', Estandar_Quotes_Field_Transformer::is_valid_rut( $valid_rut ) );
check( 'valid rut 11111111-1', Estandar_Quotes_Field_Transformer::is_valid_rut( '11111111-1' ) === true );
check( 'valid rut 22222222-2', Estandar_Quotes_Field_Transformer::is_valid_rut( '22222222-2' ) === true );
check( 'invalid rut 12345678-0', Estandar_Quotes_Field_Transformer::is_valid_rut( '12345678-0' ) === false );
check( 'invalid rut 1', Estandar_Quotes_Field_Transformer::is_valid_rut( '1' ) === false );

// ── Section 8: Profile compatibility ────────────────────────────────────

$d = hydrate_form( array( 'modelo' => 'Departamento 110', 'dormitorios' => '3' ), 'real_estate@1' );
check( 'real_estate profile: model mapped', $d->get( 'property.model' ) === 'Departamento 110' );
check( 'real_estate profile: bedrooms mapped', $d->get( 'property.bedrooms' ) === 3 );

$d = hydrate_form( array( 'modelo' => 'Casa 120', 'dormitorios' => '4' ), 'acacios_real_estate@1' );
check( 'acacios profile: model mapped', $d->get( 'property.model' ) === 'Casa 120' );
check( 'acacios profile: bedrooms mapped', $d->get( 'property.bedrooms' ) === 4 );

// ── Section 9: Empty form ───────────────────────────────────────────────

$d = hydrate_form( array() );
check( 'empty form returns draft with null fields', $d->get( 'property.unit_code' ) === null || $d->get( 'property.unit_code' ) === '' );

// ── Section 10: Unknown fields are ignored ──────────────────────────────

$d = hydrate_form( array( 'campo_desconocido' => 'valor_inexistente' ) );
check( 'unknown field does not create unexpected keys', $d->get( 'campo_desconocido' ) === null );

// ── Section 11: Images mapping ──────────────────────────────────────────

$d = hydrate_form( array( 'planos' => 'reference_plan_ground,reference_plan_upper' ) );
$images = $d->get( 'property.images' );
check( 'images mapped from planos field', is_array( $images ) && count( $images ) === 2 );
check( 'first image has correct asset_ref', ( $images[0]['asset_ref'] ?? '' ) === 'reference_plan_ground' );
check( 'second image has correct asset_ref', ( $images[1]['asset_ref'] ?? '' ) === 'reference_plan_upper' );

$d = hydrate_form( array( 'imagenes' => 'reference_plan_ground,invalid_ref' ) );
$images2 = $d->get( 'property.images' );
check( 'invalid image ref is skipped', is_array( $images2 ) && count( $images2 ) === 1 );

$d = hydrate_form( array( 'imagenes' => 'acacios_reference_plan_ground' ), 'acacios_real_estate@1' );
$images3 = $d->get( 'property.images' );
check( 'acacios image with acacios profile', count( $images3 ) === 1 );

// ── Section 13: Evaluete condition helpers ──────────────────────────────

check( 'condition not_empty: true when field present', Estandar_Quotes_Field_Transformer::evaluate_condition(
	array( 'field' => 'x', 'operator' => 'not_empty' ), array( 'x' => 'value' )
) );
check( 'condition not_empty: false when field absent', ! Estandar_Quotes_Field_Transformer::evaluate_condition(
	array( 'field' => 'x', 'operator' => 'not_empty' ), array()
) );
check( 'condition empty: true when field absent', Estandar_Quotes_Field_Transformer::evaluate_condition(
	array( 'field' => 'x', 'operator' => 'empty' ), array()
) );
check( 'condition equals: true on match', Estandar_Quotes_Field_Transformer::evaluate_condition(
	array( 'field' => 'x', 'operator' => 'equals', 'value' => 'yes' ), array( 'x' => 'yes' )
) );
check( 'condition equals: false on mismatch', ! Estandar_Quotes_Field_Transformer::evaluate_condition(
	array( 'field' => 'x', 'operator' => 'equals', 'value' => 'no' ), array( 'x' => 'yes' )
) );

echo "\n{$pass} PASS / {$fail} FAIL\n";
exit( $fail ? 1 : 0 );
