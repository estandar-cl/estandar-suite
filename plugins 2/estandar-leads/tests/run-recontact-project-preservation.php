<?php
/**
 * Verifica que project_slug vacio no sobrescriba el proyecto existente en recontacto.
 * Prueba la logica exacta del fix en class-estandar-leads-ingestion.php:259-265.
 */
$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS $name\n"; } else { ++$fail; echo "FAIL $name\n"; } }

/**
 * Replica fiel de la logica de ingestion para el update de project_slug en recontacto.
 * Ref: class-estandar-leads-ingestion.php ~line 259-265 (post-fix).
 */
function simulate_recontact_update( string $resolved_slug, string $existing_project, bool $is_test_lead, bool $project_locked ): array {
	$lead_update = array(
		'latest_source_plugin' => 'estandar-forms',
		'lead_intent'          => '',
		'last_seen_at'         => '2026-07-17',
		'updated_at'           => '2026-07-17',
	);

	if ( ! $is_test_lead && ! $project_locked ) {
		if ( $resolved_slug !== '' ) {
			$lead_update['project_slug'] = $resolved_slug;
			$lead_update['project_area'] = 'Inmobiliaria';
		}
	}

	return $lead_update;
}

// ==========================================================================
// CASO 1: resolve_slug retorna '' -> project_slug NO se incluye en el update
// ==========================================================================
echo "--- CASO 1: resolve_slug vacio no sobrescribe ---\n";
$update1 = simulate_recontact_update( '', 'altacruz', false, false );
check( 'project_slug NO esta en el update (preserva existente)', ! array_key_exists( 'project_slug', $update1 ) );
check( 'project_area NO esta en el update', ! array_key_exists( 'project_area', $update1 ) );

// ==========================================================================
// CASO 2: resolve_slug retorna valor valido -> SI se actualiza
// ==========================================================================
echo "\n--- CASO 2: resolve_slug valido actualiza ---\n";
$update2 = simulate_recontact_update( 'los-parronales', 'altacruz', false, false );
check( 'project_slug = los-parronales', ( $update2['project_slug'] ?? '' ) === 'los-parronales' );
check( 'project_area incluida', array_key_exists( 'project_area', $update2 ) );

// ==========================================================================
// CASO 3: Mismo proyecto (resolve_slug = existing) -> update inofensivo
// ==========================================================================
echo "\n--- CASO 3: mismo proyecto -> update inofensivo ---\n";
$update3 = simulate_recontact_update( 'altacruz', 'altacruz', false, false );
check( 'project_slug = altacruz (sin cambio)', ( $update3['project_slug'] ?? '' ) === 'altacruz' );

// ==========================================================================
// CASO 4: Test lead -> nunca modifica project_slug
// ==========================================================================
echo "\n--- CASO 4: test lead no modifica project_slug ---\n";
$update4 = simulate_recontact_update( 'altacruz', 'altacruz', true, false );
check( 'test lead no incluye project_slug', ! array_key_exists( 'project_slug', $update4 ) );

// ==========================================================================
// CASO 5: Proyecto bloqueado por transfer -> no modifica
// ==========================================================================
echo "\n--- CASO 5: proyecto bloqueado no modifica ---\n";
$update5 = simulate_recontact_update( 'los-parronales', 'altacruz', false, true );
check( 'proyecto bloqueado no incluye project_slug', ! array_key_exists( 'project_slug', $update5 ) );

// ==========================================================================
// CASO 6: AltaCruz especifico: resolve vacio desde slug del form
// ==========================================================================
echo "\n--- CASO 6: AltaCruz (resolve '' desde 'altacruz') ---\n";
$update6 = simulate_recontact_update( '', 'altacruz', false, false );
check( 'AltaCruz: project_slug original se preserva (no en update)', ! array_key_exists( 'project_slug', $update6 ) );

// ==========================================================================
// CASO 7: Alto Jaramillo
// ==========================================================================
echo "\n--- CASO 7: Alto Jaramillo (resolve '' desde 'alto-jaramillo') ---\n";
$update7 = simulate_recontact_update( '', 'alto-jaramillo', false, false );
check( 'Alto Jaramillo: project_slug original se preserva', ! array_key_exists( 'project_slug', $update7 ) );

// ==========================================================================
// CASO 8: Los Parronales (resolve 'los-parronales' OK)
// ==========================================================================
echo "\n--- CASO 8: Los Parronales (resolve OK) ---\n";
$update8 = simulate_recontact_update( 'los-parronales', 'los-parronales', false, false );
check( 'Los Parronales: project_slug = los-parronales', ( $update8['project_slug'] ?? '' ) === 'los-parronales' );
check( 'Los Parronales: project_area incluida', array_key_exists( 'project_area', $update8 ) );

// ==========================================================================
// CASO 9: Lead nuevo con resolve vacio -> update no deberia pasar (no es recontacto)
//    El caso de nuevo lead (is_new=true) no pasa por esta rama del codigo.
//    Solo verificamos que el guard funciona para el caso recontacto.
// ==========================================================================

echo "\n=== RESULTADO ===\n";
echo "PASS: $pass / FAIL: $fail\n";
exit( $fail ? 1 : 0 );
