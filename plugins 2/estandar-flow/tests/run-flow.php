<?php
/**
 * ESTANDAR SUITE v2 — Flow tests (pure logic + guarded DB paths).
 *
 *   php tests/run-flow.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); } }
if (!function_exists('sanitize_title')) { function sanitize_title($t) { return strtolower((string) preg_replace('/[^a-z0-9\-_]/', '-', (string) $t)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) strip_tags((string) $t)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return trim((string) $t); } }
if (!function_exists('wp_generate_uuid4')) { function wp_generate_uuid4() { return 'uuid-' . bin2hex(random_bytes(16)); } }

require dirname(__DIR__) . '/includes/Pipeline.php';
require dirname(__DIR__) . '/includes/Journey/StateMachine.php';
require dirname(__DIR__) . '/includes/Journey/LeadAdapter.php';
require dirname(__DIR__) . '/includes/Customers.php';
require dirname(__DIR__) . '/includes/Quotes.php';
require dirname(__DIR__) . '/includes/Adoption.php';

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Flow as F;

// ── 1. Pipeline: stages + labels + transiciones ───────────────────────────
check('1a stages canónicas', F\Pipeline::STAGES === array('NEW', 'CONTACTED', 'QUALIFIED', 'MEETING_BOOKED', 'PROPOSAL_SENT', 'NEGOTIATION', 'CLIENT', 'LOST', 'INVALID'));
check('1b label QUALIFIED', F\Pipeline::status_label('QUALIFIED') === 'Calificado');
check('1c stage válida', F\Pipeline::is_valid_stage('CLIENT'));
check('1d stage inválida', !F\Pipeline::is_valid_stage('BOGUS'));
check('1e transition no-op sin DB', F\Pipeline::transition(1, 'CLIENT') === array());
check('1f list vacío sin DB', F\Pipeline::list() === array());

// ── 2. Customers: fingerprint + first-writer-wins (pure) ──────────────────
$fp1 = F\Customers::fingerprint('A@X.CL', '+56911112222');
$fp2 = F\Customers::fingerprint('a@x.cl', '56911112222');
check('2a fingerprint estable (normaliza email/phone)', $fp1 === $fp2);
$fp3 = F\Customers::fingerprint('b@x.cl', '');
check('2b email solo → fingerprint distinto', $fp3 !== $fp1);
check('2c create no-op sin DB', F\Customers::create(array('name' => 'X', 'email' => 'x@y.cl')) === 0);
check('2d create sin identidad → 0', F\Customers::create(array('name' => 'X')) === 0);

// ── 3. Quotes: uuid + create guards ───────────────────────────────────────
$q1 = F\Quotes::quote_uuid(1, 'n1');
$q2 = F\Quotes::quote_uuid(1, 'n1');
check('3a uuid determinista por nonce', $q1 === $q2);
check('3b create no-op sin DB', F\Quotes::create(array('pipeline_id' => 1)) === 0);

// ── 4. Adoption: proyección de leads legados (etapas preservadas) ─────────
$p = F\Adoption::project_lead(array(
    'id' => 7, 'lead_uuid' => 'u7', 'status' => 'QUALIFIED', 'sales_stage' => 'NEGOTIATION',
    'project_slug' => 'AK-Contadores', 'assigned_operator_name' => 'Carla', 'created_at' => '2026-01-01 00:00:00',
));
check('4a sales_stage gana sobre status', $p['stage'] === 'NEGOTIATION');
check('4b origin_lead_id preservado', $p['origin_lead_id'] === 7);
check('4c uuid preservado', $p['entity_uuid'] === 'u7');
check('4d timestamp preservado', $p['created_at'] === '2026-01-01 00:00:00');
$p2 = F\Adoption::project_lead(array('id' => 8, 'status' => 'BOGUS_STAGE'));
check('4e stage desconocida → NEW', $p2['stage'] === 'NEW');
check('4f import no-op sin DB', F\Adoption::import_pipeline(true, 5)['dry_run'] === true);
check('4g rollback no-op sin DB', F\Adoption::rollback_pipeline() === 0);

// ── 5. Safety gate ────────────────────────────────────────────────────────
check('5a tabla pipeline propia', strpos(F\Pipeline::TABLE, 'es_') !== 0);
check('5b tabla clientes propia', strpos(F\Customers::TABLE, 'es_customer') !== 0);

// ── 6. MS-1: Journey States ────────────────────────────────────────────────
$js = F\Pipeline::JOURNEY_STAGES;
check('6a 17 journey states', count($js) === 17);
check('6b NEW en journey', in_array('NEW', $js, true));
check('6c CAPTURED en journey', in_array('CAPTURED', $js, true));
check('6d RECOVERY en journey', in_array('RECOVERY', $js, true));
check('6e journey state válida', F\Pipeline::is_valid_journey_state('ACTIVE_CLIENT'));
check('6f journey state inválida', !F\Pipeline::is_valid_journey_state('BOGUS'));
check('6g journey label', F\Pipeline::journey_label('CLOSED_WON') === 'Ganado');

// ── 7. MS-1: StateMachine ──────────────────────────────────────────────────
use Estandar\Flow\Journey\StateMachine as SM;
check('7a NEW → CAPTURED válida', SM::validate_transition('NEW', 'CAPTURED'));
check('7b CAPTURED → CLASSIFIED válida', SM::validate_transition('CAPTURED', 'CLASSIFIED'));
check('7c CAPTURED → LOST válida', SM::validate_transition('CAPTURED', 'LOST'));
check('7d CAPTURED → INVALID válida', SM::validate_transition('CAPTURED', 'INVALID'));
check('7e NEW → CONTACTED inválida', !SM::validate_transition('NEW', 'CONTACTED'));
check('7f NEW → CLOSED_WON inválida', !SM::validate_transition('NEW', 'CLOSED_WON'));
check('7g NEW → MEETING inválida', !SM::validate_transition('NEW', 'MEETING'));
check('7h NEGOTIATING → CLOSED_WON válida', SM::validate_transition('NEGOTIATING', 'CLOSED_WON'));
check('7i NEGOTIATING → LOST válida', SM::validate_transition('NEGOTIATING', 'LOST'));
check('7j LOST → RECOVERY válida', SM::validate_transition('LOST', 'RECOVERY'));
check('7k RECOVERY → ENGAGED válida', SM::validate_transition('RECOVERY', 'ENGAGED'));
check('7l RECOVERY → LOST válida', SM::validate_transition('RECOVERY', 'LOST'));
check('7m CHURNING → RECOVERY válida', SM::validate_transition('CHURNING', 'RECOVERY'));
check('7n CHURNING → CHURNED válida', SM::validate_transition('CHURNING', 'CHURNED'));
check('7o ACTIVE_CLIENT → CHURNING válida', SM::validate_transition('ACTIVE_CLIENT', 'CHURNING'));
check('7p CLOSED_WON → POST_SALE válida', SM::validate_transition('CLOSED_WON', 'POST_SALE'));
check('7q POST_SALE → ACTIVE_CLIENT válida', SM::validate_transition('POST_SALE', 'ACTIVE_CLIENT'));
check('7r CHURNED → RECOVERY válida', SM::validate_transition('CHURNED', 'RECOVERY'));
check('7s INVALID → any inválida', !SM::validate_transition('INVALID', 'CAPTURED'));

// ── 8. MS-1: Legacy Mapping ────────────────────────────────────────────────
use Estandar\Flow\Journey\LeadAdapter as LA;
check('8a legacy NEW → journey NEW', LA::legacy_status_to_journey('NEW') === 'NEW');
check('8b legacy CONTACTED → journey CONTACTED', LA::legacy_status_to_journey('CONTACTED') === 'CONTACTED');
check('8c legacy QUALIFIED → journey QUALIFIED', LA::legacy_status_to_journey('QUALIFIED') === 'QUALIFIED');
check('8d legacy CLIENT → journey ACTIVE_CLIENT', LA::legacy_status_to_journey('CLIENT') === 'ACTIVE_CLIENT');
check('8e legacy DELIVERED → journey ACTIVE_CLIENT', LA::legacy_status_to_journey('DELIVERED') === 'ACTIVE_CLIENT');
check('8f legacy LOST → journey LOST', LA::legacy_status_to_journey('LOST') === 'LOST');
check('8g legacy SPAM → journey INVALID', LA::legacy_status_to_journey('SPAM') === 'INVALID');
check('8h legacy TEST → journey INVALID', LA::legacy_status_to_journey('TEST') === 'INVALID');
check('8i legacy BOGUS → journey NEW (default)', LA::legacy_status_to_journey('BOGUS_X') === 'NEW');
check('8j from_legacy sin DB → null', LA::from_legacy(1) === null);

// ── 9. MS-1: Reverse mapping (journey → legacy) ────────────────────────────
check('9a CAPTURED → legacy NEW', SM::journey_to_legacy('CAPTURED') === 'NEW');
check('9b CLASSIFIED → legacy NEW', SM::journey_to_legacy('CLASSIFIED') === 'NEW');
check('9c ENGAGED → legacy CONTACTED', SM::journey_to_legacy('ENGAGED') === 'CONTACTED');
check('9d MEETING → legacy MEETING_BOOKED', SM::journey_to_legacy('MEETING') === 'MEETING_BOOKED');
check('9e CLOSED_WON → legacy CLIENT', SM::journey_to_legacy('CLOSED_WON') === 'CLIENT');
check('9f RECOVERY → legacy LOST', SM::journey_to_legacy('RECOVERY') === 'LOST');
check('9g CHURNED → legacy LOST', SM::journey_to_legacy('CHURNED') === 'LOST');

// ── 10. MS-1: Pipeline journey methods (pure, sin DB) ──────────────────────
check('10a journey_transition sin DB → null', F\Pipeline::journey_transition(1, 'CAPTURED') === null);
check('10b set_priority sin DB → false', !F\Pipeline::set_priority(1, 'HIGH'));
check('10c set_priority inválida → false', !F\Pipeline::set_priority(1, 'BOGUS'));
check('10d set_assessment sin DB → false', !F\Pipeline::set_assessment(1, 'MATCH', 'HOT'));
check('10e get sin DB → null', F\Pipeline::get(1) === null);
check('10f list_journey sin DB → array vacío', F\Pipeline::list_journey() === array());

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
