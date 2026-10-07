<?php
/**
 * Architecture Tests — estandar-quotes
 *
 * Codifies ADR-001 decisions as automated checks.
 * Any future regression in these rules will fail CI.
 */

define( 'ABSPATH', __DIR__ );

$pass = 0; $fail = 0;
function check( string $name, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { ++$pass; echo "PASS {$name}\n"; }
	else { ++$fail; echo "FAIL {$name}\n"; }
}

function stage_files(): array {
	return glob( dirname( __DIR__ ) . '/includes/class-estandar-quotes-*-stage.php' );
}

function stage_classes(): array {
	return array(
		'Estandar_Quotes_Lead_Stage',
		'Estandar_Quotes_Context_Stage',
		'Estandar_Quotes_Unit_Stage',
		'Estandar_Quotes_Form_Stage',
		'Estandar_Quotes_Settings_Stage',
		'Estandar_Quotes_Finance_Stage',
		'Estandar_Quotes_Validation_Stage',
		'Estandar_Quotes_Snapshot_Stage',
	);
}

function all_includes(): array {
	return glob( dirname( __DIR__ ) . '/includes/*.php' );
}

function source( string $file ): string {
	return file_get_contents( $file );
}

// ─────────────────────────────────────────────────────────────────────────
// SECTION 1: Hydration Pipeline
// ─────────────────────────────────────────────────────────────────────────

$pipeline_src = source( dirname( __DIR__ ) . '/includes/class-estandar-quotes-hydration-pipeline.php' );

check( 'Pipeline composes via register() not hardcoded list',
	strpos( $pipeline_src, 'register' ) !== false && strpos( $pipeline_src, 'self::$stages[] = $stage' ) !== false );

check( 'Pipeline execution uses priority-sorted order',
	strpos( $pipeline_src, 'usort' ) !== false && strpos( $pipeline_src, 'priority' ) !== false );

check( 'Pipeline skips unsupported profiles',
	strpos( $pipeline_src, 'supported_profiles' ) !== false );

check( 'Pipeline has reset() for test isolation',
	strpos( $pipeline_src, 'function reset' ) !== false );

check( 'Pipeline offers hydrate_new() factory',
	strpos( $pipeline_src, 'function hydrate_new' ) !== false );

check( 'Pipeline offers hydrate_from_quote() factory',
	strpos( $pipeline_src, 'function hydrate_from_quote' ) !== false );

// ─────────────────────────────────────────────────────────────────────────
// SECTION 2: All stages implement the required interface
// ─────────────────────────────────────────────────────────────────────────

foreach ( stage_classes() as $class ) {
	$file = dirname( __DIR__ ) . '/includes/class-estandar-quotes-' . str_replace( '_', '-', strtolower( preg_replace( '/^Estandar_Quotes_/', '', $class ) ) ) . '.php';
	$src = source( $file );
	check( "$class implements Hydration_Stage", strpos( $src, 'implements Estandar_Quotes_Hydration_Stage' ) !== false );
	check( "$class has priority()", strpos( $src, 'function priority' ) !== false );
	check( "$class has apply()", strpos( $src, 'function apply' ) !== false );
	check( "$class has fields()", strpos( $src, 'function fields' ) !== false );
	check( "$class has overwrite_policy()", strpos( $src, 'function overwrite_policy' ) !== false );
	check( "$class has supported_profiles()", strpos( $src, 'function supported_profiles' ) !== false );
}

// ─────────────────────────────────────────────────────────────────────────
// SECTION 3: No stage knows other stages
// ─────────────────────────────────────────────────────────────────────────

$other_stages_pattern = '/Estandar_Quotes_(Lead|Context|Unit|Form|Settings|Finance|Validation|Snapshot)_Stage/';

foreach ( stage_files() as $file ) {
	$basename = basename( $file );
	$src = source( $file );

	// Remove self-reference (the class definition itself)
	$class_name = 'Estandar_Quotes_' . implode( '_', array_map( 'ucfirst', explode( '-', str_replace( array( 'class-estandar-quotes-', '-stage.php' ), '', $basename ) ) ) ) . '_Stage';

	// Find all stage references in this file
	preg_match_all( $other_stages_pattern, $src, $matches );
	$self_refs = 0;
	$other_refs = 0;
	foreach ( $matches[0] as $ref ) {
		if ( $ref === $class_name ) {
			$self_refs++;
		} else {
			$other_refs++;
		}
	}

	check( "$basename: no references to other stages", $other_refs === 0 );
	check( "$basename: has self-reference (definition)", $self_refs >= 1 );
}

// ─────────────────────────────────────────────────────────────────────────
// SECTION 4: No stage performs persistence, PDF, mail, or delivery
// ─────────────────────────────────────────────────────────────────────────

$forbidden_patterns = array(
	'DB/wpdb'      => array( '$wpdb', 'wpdb::', 'dbDelta', 'INSERT INTO ', 'DELETE FROM ' ),
	'PDF'          => array( 'Dompdf', 'PDF_Fake_Adapter', 'PDF_Adapter', '%PDF' ),
	'Mail'         => array( 'wp_mail', 'Mail_Transport', 'Fake_Mail' ),
	'Delivery'     => array( 'Delivery_Service', 'Delivery_Email' ),
	'Artifacts'    => array( 'Artifacts::generate', 'Artifacts::set_' ),
	'Repository'   => array( 'Estandar_Quotes_Repository' ),
	'Schema'       => array( 'Estandar_Quotes_Schema' ),
	'get_option'   => array( 'get_option(' ),
);

foreach ( stage_files() as $file ) {
	$basename = basename( $file );
	$src = source( $file );

	foreach ( $forbidden_patterns as $category => $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( strpos( $src, $pattern ) !== false ) {
				check( "$basename: no $category ($pattern)", false );
			}
		}
	}
}

// Mark all forbidden-category checks as passed (add explicit passes)
$stage_count = count( stage_files() );
foreach ( stage_files() as $file ) {
	$basename = basename( $file );
	$src = source( $file );

	$has_forbidden = false;
	foreach ( $forbidden_patterns as $category => $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( strpos( $src, $pattern ) !== false ) {
				$has_forbidden = true;
			}
		}
	}
	if ( ! $has_forbidden ) {
		check( "$basename: no forbidden infrastructure access", true );
	}
}

// ─────────────────────────────────────────────────────────────────────────
// SECTION 5: No stage contains profile-specific business logic
// ─────────────────────────────────────────────────────────────────────────

foreach ( stage_files() as $file ) {
	$basename = basename( $file );
	$src = source( $file );

		$is_lead = strpos( $basename, 'lead-stage' ) !== false;
	$is_snapshot = strpos( $basename, 'snapshot-stage' ) !== false;
	$is_settings = strpos( $basename, 'settings-stage' ) !== false;

	$calls_profile_defaults = preg_match( '/(Real_Estate_Profile|Acacios_Profile)::(defaults|normalize)\(/', $src );
	$references_profile_key = strpos( $src, 'Acacios_Profile::KEY' ) !== false || strpos( $src, 'Real_Estate_Profile::KEY' ) !== false;

	if ( $calls_profile_defaults ) {
		if ( $is_lead || $is_snapshot ) {
			check( "$basename: profile defaults/normalize calls allowed", true );
		} else {
			check( "$basename: no profile defaults/normalize calls", false );
		}
	} elseif ( $references_profile_key ) {
		if ( $is_settings ) {
			check( "$basename: profile KEY constant reference allowed (config routing)", true );
		} else {
			check( "$basename: unexpected profile reference", false );
		}
	} else {
		check( "$basename: no profile dependencies (correct)", true );
	}
}

// ─────────────────────────────────────────────────────────────────────────
// SECTION 6: QuoteDraft — no infrastructure dependencies
// ─────────────────────────────────────────────────────────────────────────

$draft_src = source( dirname( __DIR__ ) . '/includes/class-estandar-quotes-quote-draft.php' );

$draft_forbidden = array( '$wpdb', 'wpdb::', 'dbDelta', 'Estandar_Quotes_Repository', 'Estandar_Quotes_Schema',
	'Estandar_Quotes_Hydration_', 'Estandar_Quotes_Profile', 'get_option(', 'update_option(' );

foreach ( $draft_forbidden as $pattern ) {
	check( "QuoteDraft: no $pattern", strpos( $draft_src, $pattern ) === false );
}

check( 'QuoteDraft: is final class', strpos( $draft_src, 'final class' ) !== false );
check( 'QuoteDraft: has get() method', strpos( $draft_src, 'function get' ) !== false );
check( 'QuoteDraft: has set() method', strpos( $draft_src, 'function set' ) !== false );
check( 'QuoteDraft: has merge() method', strpos( $draft_src, 'function merge' ) !== false );
check( 'QuoteDraft: has merge_if_empty() method', strpos( $draft_src, 'function merge_if_empty' ) !== false );
check( 'QuoteDraft: has clone() method', strpos( $draft_src, 'function clone' ) !== false );
check( 'QuoteDraft: has to_array() method', strpos( $draft_src, 'function to_array' ) !== false );
check( 'QuoteDraft: has add_error() method', strpos( $draft_src, 'function add_error' ) !== false );
check( 'QuoteDraft: has add_warning() method', strpos( $draft_src, 'function add_warning' ) !== false );
check( 'QuoteDraft: has has_errors() method', strpos( $draft_src, 'function has_errors' ) !== false );
check( 'QuoteDraft: has errors() method', strpos( $draft_src, 'function errors' ) !== false );
check( 'QuoteDraft: has warnings() method', strpos( $draft_src, 'function warnings' ) !== false );
check( 'QuoteDraft: has set_meta() method', strpos( $draft_src, 'function set_meta' ) !== false );
check( 'QuoteDraft: has get_meta() method', strpos( $draft_src, 'function get_meta' ) !== false );
check( 'QuoteDraft: has from_quote_row() factory', strpos( $draft_src, 'function from_quote_row' ) !== false );

// ─────────────────────────────────────────────────────────────────────────
// SECTION 7: FormStage is pure interpreter
// ─────────────────────────────────────────────────────────────────────────

$form_src = source( dirname( __DIR__ ) . '/includes/class-estandar-quotes-form-stage.php' );

check( 'FormStage references Field_Mapping_Registry', strpos( $form_src, 'Field_Mapping_Registry::for_profile' ) !== false );
check( 'FormStage references Field_Transformer', strpos( $form_src, 'Field_Transformer::transform' ) !== false );
check( 'FormStage iterates mappings with foreach', strpos( $form_src, 'foreach ( $mappings' ) !== false || strpos( $form_src, 'foreach( $mappings' ) !== false );

// ─────────────────────────────────────────────────────────────────────────
// SECTION 8: SnapshotStage freezes without external state
// ─────────────────────────────────────────────────────────────────────────

$snap_src = source( dirname( __DIR__ ) . '/includes/class-estandar-quotes-snapshot-stage.php' );

check( 'SnapshotStage computes canonical hash', strpos( $snap_src, 'Canonical_Json::hash' ) !== false );
check( 'SnapshotStage stores snapshot_json meta', strpos( $snap_src, 'snapshot_json' ) !== false );
check( 'SnapshotStage catches normalization errors gracefully', strpos( $snap_src, 'catch ( InvalidArgumentException' ) !== false );

// ─────────────────────────────────────────────────────────────────────────
// SECTION 9: ValidationStage is read-only
// ─────────────────────────────────────────────────────────────────────────

$val_src = source( dirname( __DIR__ ) . '/includes/class-estandar-quotes-validation-stage.php' );

check( 'ValidationStage: overwrite_policy is read_only',
	strpos( $val_src, "'read_only'" ) !== false || strpos( $val_src, '"read_only"' ) !== false );

// ─────────────────────────────────────────────────────────────────────────
// SECTION 10: All controllers use HydrationPipeline (not manual hydration)
// ─────────────────────────────────────────────────────────────────────────

$admin_src = source( dirname( __DIR__ ) . '/includes/class-estandar-quotes-admin.php' );
$gateway_src = source( dirname( __DIR__ ) . '/includes/class-estandar-quotes-operator-gateway.php' );

check( 'Admin uses HydrationPipeline::hydrate_new', strpos( $admin_src, 'Hydration_Pipeline::hydrate_new' ) !== false );
check( 'Gateway uses HydrationPipeline::hydrate_new', strpos( $gateway_src, 'Hydration_Pipeline::hydrate_new' ) !== false );

// Confirm no direct Profile::defaults() call remains in controllers for draft init
// (SnapshotStage and LeadStage are allowed, but not Admin/Gateway)
$admin_direct_defaults = preg_match( '/Profile::defaults\(|Acacios_Profile::defaults\(/', $admin_src );
$gateway_direct_defaults = preg_match( '/Profile::defaults\(|Acacios_Profile::defaults\(/', $gateway_src );

check( 'Admin no direct Profile::defaults() call', $admin_direct_defaults === 0 );
check( 'Gateway no direct Profile::defaults() call', $gateway_direct_defaults === 0 );

// ─────────────────────────────────────────────────────────────────────────
// SECTION 11: Pipeline registration uses priority, not order
// ─────────────────────────────────────────────────────────────────────────

$bootstrap_src = source( dirname( __DIR__ ) . '/estandar-quotes.php' );

check( 'Bootstrap registers all 8 pipeline stages',
	substr_count( $bootstrap_src, 'Hydration_Pipeline::register' ) === 8 );

check( 'Bootstrap fires hydrator_stages action for extensibility',
	strpos( $bootstrap_src, 'estandar_quotes_hydrator_stages' ) !== false );

// ─────────────────────────────────────────────────────────────────────────
// SECTION 12: Field Mapping Registry is used by both profiles
// ─────────────────────────────────────────────────────────────────────────

check( 'Bootstrap registers mappings for real_estate@1',
	strpos( $bootstrap_src, "real_estate@1" ) !== false );
check( 'Bootstrap registers mappings for acacios_real_estate@1',
	strpos( $bootstrap_src, "acacios_real_estate@1" ) !== false );

$registry_src = source( dirname( __DIR__ ) . '/includes/class-estandar-quotes-field-mapping-registry.php' );
check( 'Field Mapping Registry has register() method',
	strpos( $registry_src, 'function register' ) !== false );
check( 'Field Mapping Registry has for_profile() method',
	strpos( $registry_src, 'function for_profile' ) !== false );
check( 'Field Mapping Registry has filter hook for extensibility',
	strpos( $registry_src, 'estandar_quotes_field_mappings' ) !== false );

$transformer_src = source( dirname( __DIR__ ) . '/includes/class-estandar-quotes-field-transformer.php' );
check( 'Field Transformer has transform() method',
	strpos( $transformer_src, 'function transform' ) !== false );
check( 'Field Transformer has validate() method',
	strpos( $transformer_src, 'function validate' ) !== false );
check( 'Field Transformer has evaluate_condition() method',
	strpos( $transformer_src, 'function evaluate_condition' ) !== false );

// ─────────────────────────────────────────────────────────────────────────
// SECTION 13: Bootstrap requires new files in correct order
// ─────────────────────────────────────────────────────────────────────────

check( 'Bootstrap loads FieldTransformer',
	strpos( $bootstrap_src, 'class-estandar-quotes-field-transformer.php' ) !== false );
check( 'Bootstrap loads FieldMappingRegistry',
	strpos( $bootstrap_src, 'class-estandar-quotes-field-mapping-registry.php' ) !== false );

// ─────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────

echo "\n{$pass} PASS / {$fail} FAIL\n";
exit( $fail ? 1 : 0 );
