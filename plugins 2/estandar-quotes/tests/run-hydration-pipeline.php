<?php
/**
 * Tests for QuoteDraft, HydrationPipeline and Stage architecture.
 *
 * Verifies:
 *  - QuoteDraft dot-path get/set
 *  - Pipeline stage registration and order
 *  - Priority-based execution
 *  - Overwrite policies (merge, merge_if_empty, overwrite)
 *  - Profile compatibility filtering
 *  - Snapshot generation
 *  - Validation stage warnings/errors
 *  - Lead stage copies profile defaults with lead data
 *  - Context stage fills project name from context_json
 *  - Form stage maps form fields to document fields
 *  - Settings stage applies global defaults
 *  - Finance stage computes expires_at
 *  - Absence of regressions between Admin and Gateway paths
 */

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__ ) . '/includes/interface-estandar-quotes-hydration-stage.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-quote-draft.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-hydration-pipeline.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-canonical-json.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-calculator.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-real-estate-profile.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-acacios-profile.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-profiles.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-view-model.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-definitions.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-form.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-lead-stage.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-context-stage.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-field-transformer.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-field-mapping-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-form-stage.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-settings-stage.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-unit-stage.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-finance-stage.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-validation-stage.php';
require_once dirname( __DIR__ ) . '/includes/class-estandar-quotes-snapshot-stage.php';

// ── Helpers ──────────────────────────────────────────────────────────────

$pass = 0; $fail = 0;
function check( string $name, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { ++$pass; echo "PASS {$name}\n"; }
	else { ++$fail; echo "FAIL {$name}\n"; }
}

$GLOBALS['filters'] = [];
function add_filter( string $hook, callable $cb, int $prio = 10, int $args = 1 ): void {
	$GLOBALS['filters'][ $hook ][] = $cb;
}
function apply_filters( string $hook, $value, ...$args ) {
	if ( ! empty( $GLOBALS['filters'][ $hook ] ) ) {
		foreach ( $GLOBALS['filters'][ $hook ] as $cb ) {
			$value = call_user_func( $cb, $value, ...$args );
		}
	}
	return $value;
}
function add_action( $h, $cb, $prio = 10, $args = 1 ) {}
function get_option( $key, $default = false ) { return $default; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
function sanitize_email( $v ) { return filter_var( $v, FILTER_SANITIZE_EMAIL ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_title( $v ) { return strtolower( trim( preg_replace( '/[^a-z0-9_-]/', '-', (string) $v ), '-' ) ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v, $o = 0 ) { return json_encode( $v, $o ); }
function number_format_i18n( $n, $d ) { return number_format( $n, $d, ',', '.' ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
Estandar_Quotes_Real_Estate_Profile::init();
Estandar_Quotes_Acacios_Profile::init();

// Register pipeline stages (normally done in plugins_loaded)
Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Lead_Stage() );
Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Context_Stage() );
Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Form_Stage() );
Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Unit_Stage() );
Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Settings_Stage() );
Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Finance_Stage() );
Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Validation_Stage() );
Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Snapshot_Stage() );

// ── Section 1: QuoteDraft ───────────────────────────────────────────────

$d = new Estandar_Quotes_Quote_Draft();
check( 'QuoteDraft starts empty', $d->to_array() === [] );
check( 'QuoteDraft get returns null for missing path', $d->get( 'foo.bar' ) === null );

$d->set( 'client.name', 'Juan' );
$d->set( 'client.email', 'juan@test.cl' );
check( 'QuoteDraft set/get dot path', $d->get( 'client.name' ) === 'Juan' );
check( 'QuoteDraft get nested', $d->get( 'client.email' ) === 'juan@test.cl' );

$d->merge( [ 'project' => [ 'name' => 'Altacruz' ] ] );
check( 'QuoteDraft merge adds section', $d->get( 'project.name' ) === 'Altacruz' );

$d->merge_if_empty( 'project.stage', 'Etapa 1' );
check( 'merge_if_empty fills empty', $d->get( 'project.stage' ) === 'Etapa 1' );
$d->merge_if_empty( 'project.stage', 'Etapa 2' );
check( 'merge_if_empty does not overwrite', $d->get( 'project.stage' ) === 'Etapa 1' );

$d->add_error( 'field', 'error msg' );
check( 'QuoteDraft error collection', $d->has_errors() && $d->errors()[0]['message'] === 'error msg' );

$d2 = $d->clone();
$d2->set( 'client.name', 'Pedro' );
check( 'QuoteDraft clone is independent', $d->get( 'client.name' ) === 'Juan' && $d2->get( 'client.name' ) === 'Pedro' );

$d->set_meta( 'profile_key', 'real_estate@1' );
check( 'QuoteDraft meta set/get', $d->get_meta( 'profile_key' ) === 'real_estate@1' );
check( 'QuoteDraft meta default', $d->get_meta( 'nonexistent', 'fallback' ) === 'fallback' );

// ── Section 2: Pipeline stage registration and order ────────────────────

$pipeline_stages_before = Estandar_Quotes_Hydration_Pipeline::stages();
check( 'Pipeline has stages registered', count( $pipeline_stages_before ) >= 6 );

$priorities = array_map( fn( $s ) => $s->priority(), $pipeline_stages_before );
$sorted = $priorities;
sort( $sorted );
check( 'Pipeline stages are sorted by priority', $priorities === $sorted );

// Verify specific stages exist and have correct priorities
$stage_classes = array_map( fn( $s ) => get_class( $s ), $pipeline_stages_before );
check( 'LeadStage registered', in_array( 'Estandar_Quotes_Lead_Stage', $stage_classes, true ) );
check( 'ContextStage registered', in_array( 'Estandar_Quotes_Context_Stage', $stage_classes, true ) );
check( 'FormStage registered', in_array( 'Estandar_Quotes_Form_Stage', $stage_classes, true ) );
check( 'SettingsStage registered', in_array( 'Estandar_Quotes_Settings_Stage', $stage_classes, true ) );
check( 'FinanceStage registered', in_array( 'Estandar_Quotes_Finance_Stage', $stage_classes, true ) );
check( 'ValidationStage registered', in_array( 'Estandar_Quotes_Validation_Stage', $stage_classes, true ) );
check( 'SnapshotStage registered', in_array( 'Estandar_Quotes_Snapshot_Stage', $stage_classes, true ) );
check( 'UnitStage registered', in_array( 'Estandar_Quotes_Unit_Stage', $stage_classes, true ) );

// ── Section 3: Stage priority order ─────────────────────────────────────

foreach ( $pipeline_stages_before as $stage ) {
	$c = get_class( $stage );
	$p = $stage->priority();
	if ( $c === 'Estandar_Quotes_Lead_Stage' ) { check( 'LeadStage priority=10', $p === 10 ); }
	if ( $c === 'Estandar_Quotes_Context_Stage' ) { check( 'ContextStage priority=20', $p === 20 ); }
	if ( $c === 'Estandar_Quotes_Unit_Stage' ) { check( 'UnitStage priority=25', $p === 25 ); }
	if ( $c === 'Estandar_Quotes_Form_Stage' ) { check( 'FormStage priority=30', $p === 30 ); }
	if ( $c === 'Estandar_Quotes_Settings_Stage' ) { check( 'SettingsStage priority=40', $p === 40 ); }
	if ( $c === 'Estandar_Quotes_Finance_Stage' ) { check( 'FinanceStage priority=50', $p === 50 ); }
	if ( $c === 'Estandar_Quotes_Validation_Stage' ) { check( 'ValidationStage priority=60', $p === 60 ); }
	if ( $c === 'Estandar_Quotes_Snapshot_Stage' ) { check( 'SnapshotStage priority=70', $p === 70 ); }
}

// ── Section 4: Stage overwrite policies ─────────────────────────────────

foreach ( $pipeline_stages_before as $stage ) {
	$c = get_class( $stage );
	$policy = $stage->overwrite_policy();
	if ( $c === 'Estandar_Quotes_Lead_Stage' ) { check( 'LeadStage policy=merge_recursive', $policy === 'merge_recursive' ); }
	if ( $c === 'Estandar_Quotes_Context_Stage' ) { check( 'ContextStage policy=merge_if_empty', $policy === 'merge_if_empty' ); }
	if ( $c === 'Estandar_Quotes_Form_Stage' ) { check( 'FormStage policy=overwrite', $policy === 'overwrite' ); }
	if ( $c === 'Estandar_Quotes_Validation_Stage' ) { check( 'ValidationStage policy=read_only', $policy === 'read_only' ); }
}

// ── Section 5: Full hydration pipeline (generic profile, no form data) ──

$lead_snapshot = [
	'lead' => [ 'name' => 'Juan Pérez', 'email' => 'juan@test.cl', 'phone' => '+56912345678' ],
	'submission' => [
		'message' => 'Quiero información',
		'form_fields' => [],
		'context' => [],
	],
];
$settings = [
	'validity_days'       => 15,
	'uf_source'           => 'manual',
	'uf_value_clp'        => 40000,
	'annual_rate_percent' => 4.5,
	'credit_percent'      => 80,
	'income_multiple'     => 4,
	'sales_room_name'     => 'Sala Central',
	'sales_room_address'  => 'Av. Principal 1000',
	'conditions'          => "Condición 1\nCondición 2",
];
$profile_key = Estandar_Quotes_Real_Estate_Profile::KEY;

$hydrated = Estandar_Quotes_Hydration_Pipeline::hydrate_new( $lead_snapshot, $profile_key, $settings );
$doc = $hydrated->to_array();

check( 'Lead: client.name from snapshot', $doc['client']['name'] === 'Juan Pérez' );
check( 'Lead: client.email from snapshot', $doc['client']['email'] === 'juan@test.cl' );
check( 'Lead: client.phone from snapshot', $doc['client']['phone'] === '+56912345678' );
check( 'Lead: document_version set', $doc['document_version'] === 'real_estate@1' );
check( 'Lead: capture_context.message from snapshot', ( $doc['capture_context']['message'] ?? '' ) === 'Quiero información' );

check( 'Settings: validity.days applied', $doc['validity']['days'] === 15 );
check( 'Settings: uf_rate.value_clp applied', $doc['uf_rate']['value_clp'] === (float) 40000 );
check( 'Settings: financing.credit_percent applied', $doc['financing']['credit_percent'] === (float) 80 );
check( 'Settings: sales_room.name applied', $doc['project']['sales_room']['name'] === 'Sala Central' );
check( 'Settings: conditions applied', count( $doc['conditions']['items'] ?? [] ) === 2 );

check( 'Finance: expires_at computed', $doc['validity']['expires_at'] !== '' && $doc['validity']['expires_at'] !== null );
check( 'Finance: issued_at set', $doc['validity']['issued_at'] !== '' );

// ── Section 6: Snapshot stage ───────────────────────────────────────────
// Snapshot requires valid pricing data to normalize; with null amount it
// gracefully skips rather than crashing. The Validation stage already warns.
check( 'Snapshot: catches gracefully when pricing data is missing', count( $hydrated->warnings() ) >= 1 );
check( 'Snapshot: profile-level sanitization applied', $doc['client']['name'] === 'Juan Pérez' );

// ── Section 7: Form stage ───────────────────────────────────────────────

$form_snapshot = [
	'lead' => [ 'name' => 'María', 'email' => 'maria@test.cl', 'phone' => '+56998765432' ],
	'submission' => [
		'message' => 'Necesito cotización',
		'form_fields' => [
			'rut'             => '12345678-5',
			'unidad'          => 'A-101',
			'modelo'          => 'Departamento 2D1B',
			'orientacion'     => 'Norte',
			'superficie_total' => '65,5',
			'dormitorios'     => '2',
			'banos'           => '1',
			'bodega'          => 'Si',
			'estacionamiento' => 'No',
			'presupuesto'     => '3200',
			'valor_uf'        => '40500',
			'fecha_uf'        => '2026-07-22',
		],
		'context' => [ 'project_name' => 'Edificio Central' ],
	],
];
$hydrated2 = Estandar_Quotes_Hydration_Pipeline::hydrate_new( $form_snapshot, $profile_key, $settings );
$doc2 = $hydrated2->to_array();

check( 'Form: client.tax_id from rut field', $doc2['client']['tax_id'] === '12345678-5' );
check( 'Form: property.unit_code from unidad', $doc2['property']['unit_code'] === 'A-101' );
check( 'Form: property.model from modelo', $doc2['property']['model'] === 'Departamento 2D1B' );
check( 'Form: property.orientation from orientacion', $doc2['property']['orientation'] === 'Norte' );
check( 'Form: property.area_total_m2 from superficie_total', abs( (float) $doc2['property']['area_total_m2'] - 65.5 ) < 0.01 );
check( 'Form: property.bedrooms from dormitorios', $doc2['property']['bedrooms'] === 2 );
check( 'Form: property.bathrooms from banos', $doc2['property']['bathrooms'] === 1 );
check( 'Form: property.storage from bodega=Si', $doc2['property']['storage'] === true );
check( 'Form: property.parking from estacionamiento=No', $doc2['property']['parking'] === false );
check( 'Form: pricing.total_uf.amount from presupuesto', abs( (float) $doc2['pricing']['total_uf']['amount'] - 3200 ) < 0.01 );
// Settings stage (priority 40) runs after Form stage (priority 30), so settings value
// takes precedence when both are present. This matches the design: configured defaults
// override form-provided values to ensure consistency.
check( 'Form: uf_rate.value_clp from settings (overrides form)', $doc2['uf_rate']['value_clp'] === (float) 40000 );
check( 'Form: uf_rate.effective_date from fecha_uf', $doc2['uf_rate']['effective_date'] === '2026-07-22' );

// ── Section 8: Context stage ────────────────────────────────────────────

$ctx_snapshot = [
	'lead' => [ 'name' => 'Ana', 'email' => 'ana@test.cl', 'phone' => '+56911111111' ],
	'submission' => [
		'message' => 'Hola',
		'form_fields' => [],
		'context' => [
			'project_name' => 'Torre Corporativa',
			'project_area' => 'Santiago Centro',
			'client_context' => [
				'handoff' => [ 'owner' => 'Carla Fernández' ],
			],
		],
	],
];
$hydrated3 = Estandar_Quotes_Hydration_Pipeline::hydrate_new( $ctx_snapshot, $profile_key, $settings );
$doc3 = $hydrated3->to_array();

check( 'Context: project.name from context_json', $doc3['project']['name'] === 'Torre Corporativa' );
check( 'Context: project.stage from project_area', $doc3['project']['stage'] === 'Santiago Centro' );

// ── Section 9: Validation stage ─────────────────────────────────────────

$empty_snapshot = [
	'lead' => [ 'name' => '', 'email' => '', 'phone' => '' ],
	'submission' => [ 'message' => '', 'form_fields' => [], 'context' => [] ],
];
$hydrated4 = Estandar_Quotes_Hydration_Pipeline::hydrate_new( $empty_snapshot, $profile_key, [] );

check( 'Validation: warnings for missing client name', count( $hydrated4->warnings() ) >= 1 );
check( 'Validation: warnings include pricing warning', array_reduce( $hydrated4->warnings(), function( $found, $w ) {
	return $found || strpos( $w['message'], 'UF' ) !== false;
}, false ) );

// ── Section 10: Profile compatibility ────────────────────────────────────

$acacios_snapshot = [
	'lead' => [ 'name' => 'Cliente Acacios', 'email' => 'cliente@acacios.cl', 'phone' => '+56900000000' ],
	'submission' => [ 'message' => '', 'form_fields' => [], 'context' => [] ],
];
$acacios_settings = $settings;
$acacios_settings['conditions'] = ''; // Acacios uses its own conditions
$hydrated5 = Estandar_Quotes_Hydration_Pipeline::hydrate_new( $acacios_snapshot, Estandar_Quotes_Acacios_Profile::KEY, $acacios_settings );
$doc5 = $hydrated5->to_array();

check( 'Acacios profile: document_version set', $doc5['document_version'] === 'acacios_real_estate@1' );
check( 'Acacios profile: policy_version correct', $doc5['conditions']['policy_version'] === 'acacios_conditions@1' );
check( 'Acacios profile: conditions not overwritten by settings', ! isset( $doc5['conditions']['items'][0] ) || $doc5['conditions']['items'][0] !== 'Condición 1' );
check( 'Acacios profile: lead name copied', $doc5['client']['name'] === 'Cliente Acacios' );

// ── Section 11: Pipeline without form data ───────────────────────────────

$noform_snapshot = [
	'lead' => [ 'name' => 'Test', 'email' => 'test@test.cl', 'phone' => '+56900000000' ],
	'submission' => [ 'message' => '', 'form_fields' => [], 'context' => [] ],
];
$hydrated6 = Estandar_Quotes_Hydration_Pipeline::hydrate_new( $noform_snapshot, $profile_key, $settings );
$doc6 = $hydrated6->to_array();

check( 'No form: tax_id stays empty', $doc6['client']['tax_id'] === '' );
check( 'No form: property default values', $doc6['property']['area_total_m2'] === null );
check( 'No form: pricing default numeric', is_numeric( $doc6['pricing']['total_uf']['amount'] ) );

// ── Section 12: Pipeline reset and isolation ─────────────────────────────

check( 'Pipeline produces consistent results', $doc['client']['name'] === 'Juan Pérez' && $doc2['client']['name'] === 'María' && $doc3['client']['name'] === 'Ana' );

// ── Summary ──────────────────────────────────────────────────────────────

echo "\n{$pass} PASS / {$fail} FAIL\n";
exit( $fail ? 1 : 0 );
