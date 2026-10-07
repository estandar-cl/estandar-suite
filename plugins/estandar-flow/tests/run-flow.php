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
if (!function_exists('esc_html')) { function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); } }
if (!function_exists('esc_url')) { function esc_url($u) { return (string) $u; } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($t) { return (string) $t; } }
if (!function_exists('current_time')) { function current_time($t) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('wp_parse_args')) { function wp_parse_args($args, $defaults = array()) { if (is_object($args)) { $args = get_object_vars($args); } return array_merge((array) $defaults, (array) $args); } }
if (!function_exists('sanitize_email')) { function sanitize_email($e) { return trim((string) $e); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }

require dirname(__DIR__) . '/includes/Pipeline.php';
require dirname(__DIR__) . '/includes/Journey/StateMachine.php';
require dirname(__DIR__) . '/includes/Customers.php';
require dirname(__DIR__) . '/includes/Quotes.php';
require dirname(__DIR__) . '/includes/QuotesAdoption.php';
require dirname(__DIR__) . '/includes/Quotes/Schema.php';
require dirname(__DIR__) . '/includes/Quotes/Repository.php';
require dirname(__DIR__) . '/includes/Quotes/Document/Fields.php';
require dirname(__DIR__) . '/includes/Quotes/Document/Calculator.php';
require dirname(__DIR__) . '/includes/Quotes/Document/Template.php';
require dirname(__DIR__) . '/includes/Quotes/Document/Renderer.php';
require dirname(__DIR__) . '/includes/Quotes/Document/Pdf.php';
require dirname(__DIR__) . '/includes/Quotes/Document/PdfStore.php';
require dirname(__DIR__) . '/includes/Quotes/Artifacts.php';
require dirname(__DIR__) . '/includes/Quotes/Settings.php';
require dirname(__DIR__) . '/includes/Quotes/DocumentBuilder.php';
require dirname(__DIR__) . '/includes/Quotes/Editor.php';
require dirname(__DIR__) . '/includes/Quotes/Lifecycle.php';
require dirname(__DIR__) . '/includes/Quotes/Expiry.php';
require dirname(__DIR__) . '/includes/Quotes/Gateway.php';
require dirname(__DIR__) . '/includes/Adoption.php';
require dirname(__DIR__) . '/includes/Admin.php';

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

// ── 11. QuotesAdoption (proyección PURE + guards sin DB) ───────────────────
$qa_row = array(
	'id' => 77, 'quote_uuid' => 'abc-123', 'status' => 'issued',
	'lead_snapshot_json' => json_encode( array( 'amount' => 1250000 ) ),
	'draft_document_json' => null,
	'created_at' => '2026-07-23 13:01:42', 'updated_at' => '2026-07-23 13:01:42',
);
$proj = F\QuotesAdoption::project( $qa_row );
check('11a quotes project origin_quote_id', (int) $proj['origin_quote_id'] === 77);
check('11b quotes project quote_uuid', $proj['quote_uuid'] === 'abc-123');
check('11c quotes project status', $proj['status'] === 'issued');
check('11d quotes project amount desde json', (float) $proj['amount'] === 1250000.0);
check('11e quotes project currency default', $proj['currency'] === 'CLP');
check('11f quotes project created_at', $proj['created_at'] === '2026-07-23 13:01:42');
check('11g quotes project cantidad con items', (float) F\QuotesAdoption::project( array(
	'id' => 78, 'quote_uuid' => 'x', 'status' => 'draft', 'created_at' => 't', 'updated_at' => 't',
	'draft_document_json' => json_encode( array( 'items' => array(
		array( 'amount' => 100 ), array( 'total' => 250 ),
	) ) ),
) )['amount'] === 350.0 );
check('11h quotes legacy_present sin DB → false', ! F\QuotesAdoption::legacy_present());
check('11i quotes counts sin DB → cero', F\QuotesAdoption::counts()['legacy_total'] === 0);
check('11j quotes import sin DB → zero counts', F\QuotesAdoption::import(true, 5)['imported'] === 0);
check('11k quotes rollback sin DB → 0', F\QuotesAdoption::rollback() === 0);

// ── 12. QUOTE_V2: normalize/total/format + guards sin DB ───────────────────
$qi = F\Quotes::normalize_items( array(
	array( 'label' => 'Terreno', 'amount' => 1000, 'qty' => 2 ),
	array( 'label' => 'Documentación', 'amount' => 50, 'qty' => 1 ),
	array( 'label' => '  ', 'amount' => 0 ),
) );
$qi_dec = F\Quotes::decode_items( (string) $qi );
check('12a normalize_items filtra vacíos', count( $qi_dec ) === 2 );
check('12b normalize_items items con qty', (float) $qi_dec[0]['qty'] === 2.0 && (float) $qi_dec[0]['amount'] === 1000.0 );
$quote = array( 'items' => $qi_dec, 'amount' => 999, 'currency' => 'CLP' );
check('12c total suma items (no amount)', F\Quotes::total( $quote ) === 2050.0 );
check('12d total sin items → amount', F\Quotes::total( array( 'amount' => 1234.5 ) ) === 1234.5 );
check('12e format CLP separador miles', F\Quotes::format_amount( 1250000, 'CLP' ) === '$ 1.250.000' );
check('12f format UF 2 decimales', F\Quotes::format_amount( 3.5, 'UF' ) === 'UF 3,50' );
$html = F\Quotes::quote_document_html( array( 'id' => 1, 'quote_uuid' => 'abc123def456', 'title' => 'Terrero X', 'items' => $qi_dec, 'currency' => 'CLP', 'validity_days' => 15, 'notes' => 'Pago a 30 días' ), array( 'name' => 'Juan Pérez', 'email' => 'juan@x.cl' ) );
check('12g documento incluye cliente', strpos( $html, 'Juan Pérez' ) !== false && strpos( $html, 'juan@x.cl' ) !== false );
check('12h documento incluye total y validez', strpos( $html, '$ 2.050' ) !== false && strpos( $html, '15 días' ) !== false && strpos( $html, 'Pago a 30 días' ) !== false );
check('12i get sin DB → null', F\Quotes::get( 1 ) === null );
check('12j update sin DB → false', F\Quotes::update( 1, array( 'title' => 'x' ) ) === false );
$send = F\Quotes::send( 1, 'juan@x.cl' );
check('12k send sin DB → no_encontrada', ! empty( $send['ok'] ) === false && ( $send['error'] ?? '' ) === 'no_encontrada' );

// ── 13. Puente señales → journey (PURE) ───────────────────────────────────
check('13a lead_created → CAPTURED', F\Pipeline::signal_target('lead_created') === 'CAPTURED');
check('13b lead_qualified → QUALIFIED', F\Pipeline::signal_target('lead_qualified') === 'QUALIFIED');
check('13c meeting_booked → MEETING', F\Pipeline::signal_target('meeting_booked') === 'MEETING');
check('13d sale_closed → CLOSED_WON', F\Pipeline::signal_target('sale_closed') === 'CLOSED_WON');
check('13e lead_rejected → INVALID', F\Pipeline::signal_target('lead_rejected') === 'INVALID');
check('13f evento desconocido → vacío', F\Pipeline::signal_target('otro_evento') === '');

// path_to: camino más corto en el grafo del journey.
check('13g NEW → CAPTURED directo', F\Pipeline::path_to('NEW', 'CAPTURED') === array('CAPTURED'));
check('13h NEW → QUALIFIED pasa por CAPTURED/CLASSIFIED', F\Pipeline::path_to('NEW', 'QUALIFIED') === array('CAPTURED','CLASSIFIED','CONTACTED','ENGAGED','QUALIFIED'));
check('13i mismo estado → sin pasos', F\Pipeline::path_to('NEW', 'NEW') === array());
check('13j destino inalcanzable → vacío', F\Pipeline::path_to('INVALID', 'QUALIFIED') === array());
check('13k todos los pasos del camino son válidos', (function(){
	$prev = 'NEW';
	foreach (F\Pipeline::path_to('NEW', 'CLOSED_WON') as $st) {
		if (!F\Journey\StateMachine::validate_transition($prev, $st)) { return false; }
		$prev = $st;
	}
	return $prev === 'CLOSED_WON';
})());

// advance_to sin DB → no rompe y reporta bloqueo vacío.
$adv = F\Pipeline::advance_to(1, 'CAPTURED');
check('13l advance_to sin DB → estructura segura', is_array($adv) && ($adv['steps'] ?? null) === array());
check('13m on_signal sin DB no lanza', (function(){ F\Pipeline::on_signal(array('event_name'=>'lead_created','entity_type'=>'capture','entity_id'=>1)); return true; })());
check('13n on_signal ignora señal no-array', (function(){ F\Pipeline::on_signal('x'); return true; })());
check('13o resolve_pipeline_id sin DB → 0', F\Pipeline::resolve_pipeline_id(array('entity_type'=>'lead','entity_id'=>5)) === 0);

// Invariantes: las que estaban definidas y no se aplicaban.
check('13p QUALIFIED exige commercial_fit', F\Journey\StateMachine::validate_invariants(array('commercial_fit'=>'','stage'=>'NEW'), 'QUALIFIED') !== null);
check('13q QUALIFIED con fit pasa', F\Journey\StateMachine::validate_invariants(array('commercial_fit'=>'MATCH','stage'=>'NEW'), 'QUALIFIED') === null);
check('13r CONTACTED exige operador', F\Journey\StateMachine::validate_invariants(array('assigned_operator'=>'','stage'=>'NEW'), 'CONTACTED') !== null);
check('13s CLOSED_WON exige cotización', F\Journey\StateMachine::validate_invariants(array('metadata_json'=>''), 'CLOSED_WON') !== null);
check('13t legacy_to_journey MEETING_BOOKED → MEETING', F\Journey\StateMachine::legacy_to_journey('MEETING_BOOKED') === 'MEETING');
check('13u journey_to_legacy MEETING → MEETING_BOOKED', F\Journey\StateMachine::journey_to_legacy('MEETING') === 'MEETING_BOOKED');

// ── 14. M1: Quotes\Schema + Repository (capa de datos aditiva) ─────────────
check('14a satellite_tables tiene 3 tablas', count(F\Quotes\Schema::satellite_tables()) === 3);
check('14b satellite_tables nombres canónicos', F\Quotes\Schema::satellite_tables() === array(
	'estandar_flow_quote_revisions', 'estandar_flow_quote_events', 'estandar_flow_quote_artifacts',
));
check('14c next_quote_number formato sin DB', F\Quotes\Repository::next_quote_number() === 'COT-000001');
check('14d next_quote_number prefijo custom', F\Quotes\Repository::next_quote_number('PRES') === 'PRES-000001');
check('14e next_quote_number prefijo vacío → COT', F\Quotes\Repository::next_quote_number('  ') === 'COT-000001');
check('14f add_revision no-op sin DB', F\Quotes\Repository::add_revision(1, array('a' => 1)) === array());
check('14g list_revisions sin DB → array', F\Quotes\Repository::list_revisions(1) === array());
check('14h get_revision sin DB → null', F\Quotes\Repository::get_revision(1, 1) === null);
check('14i log_event sin DB → 0', F\Quotes\Repository::log_event(1, 'quote.created') === 0);
check('14j ensure_columns invocable sin DB', (function(){ F\Quotes\Schema::ensure_columns(); return true; })());
check('14k maybe_migrate invocable sin DB', (function(){ F\Quotes\Schema::maybe_migrate(); return true; })());
check('14l activate invocable sin DB (no fatal)', (function(){ F\Quotes\Schema::activate(); return true; })());

// ── 15. M2/M3: Document/Fields + Calculator + Template + Renderer (PURE) ───
use Estandar\Flow\Quotes\Document\Calculator as Calc;
use Estandar\Flow\Quotes\Document\Fields as DocFields;
use Estandar\Flow\Quotes\Document\Renderer as DocRenderer;

check('15a line_total qty default 1', Calc::line_total(array('unit_price' => 1000)) === 1000.0);
check('15b line_total qty * unit_price', Calc::line_total(array('qty' => 3, 'unit_price' => 1000)) === 3000.0);
$items = array(
	array('qty' => 2, 'unit_price' => 1000),
	array('qty' => 1, 'unit_price' => 500),
);
check('15c subtotal suma líneas', Calc::subtotal($items) === 2500.0);
check('15d descuento amount', Calc::discount_amount(1000.0, 200, 'amount') === 200.0);
check('15e descuento percent', Calc::discount_amount(1000.0, 10, 'percent') === 100.0);
check('15f descuento negativo → 0', Calc::discount_amount(1000.0, -5) === 0.0);
check('15g IVA no incluido 19%', Calc::tax_amount(1000.0, 19, false) === 190.0);
check('15h IVA incluido despeja impuesto', round(Calc::tax_amount(1190.0, 19, true), 2) === 190.0);
check('15i uf_to_clp', Calc::uf_to_clp(10.0, 40000.0) === 400000.0);
check('15j uf_to_clp valor <= 0 → 0', Calc::uf_to_clp(10.0, 0.0) === 0.0);
check('15k clp_to_uf', Calc::clp_to_uf(400000.0, 40000.0) === 10.0);
check('15l clp_to_uf valor <= 0 → 0', Calc::clp_to_uf(400000.0, 0.0) === 0.0);
check('15m format CLP', Calc::format_amount(1234567.0, 'CLP') === '$ 1.234.567');
check('15n format UF', Calc::format_amount(1234.56, 'UF') === 'UF 1.234,56');
check('15o format otros', Calc::format_amount(1234.5, 'USD') === '1.234,50 USD');
$ct = Calc::compose_totals($items, array('tax_rate' => 19, 'currency' => 'CLP'));
check('15p compose_totals claves', array_keys($ct) === array('subtotal', 'discount', 'net', 'tax_rate', 'tax_amount', 'total', 'currency'));
check('15q compose_totals total con IVA', $ct['subtotal'] === 2500.0 && $ct['tax_amount'] === 475.0 && $ct['total'] === 2975.0);
$cti = Calc::compose_totals(array(array('qty' => 1, 'unit_price' => 1190)), array('tax_rate' => 19, 'tax_included' => true));
check('15r compose_totals IVA incluido', $cti['total'] === 1190.0 && round($cti['tax_amount'], 2) === 190.0);
check('15s financing_rows incompleto → vacío', Calc::financing_rows(array(), 1000.0, 40000.0) === array());
$frows = Calc::financing_rows(array('credit_percent' => 80, 'annual_rate_percent' => 5, 'income_multiple' => 4, 'terms_years' => 20), 1000.0, 40000.0);
check('15t financing_rows con datos', count($frows) === 1 && $frows[0]['years'] === 20 && $frows[0]['dividend_clp'] > 0);
check('15u Fields::group client', count(DocFields::group('client')) === 5);
$lead_keys = DocFields::lead_inherited();
check('15v lead_inherited incluye client.name y excluye client.address', in_array('client.name', $lead_keys, true) && ! in_array('client.address', $lead_keys, true));

$doc = array(
	'profile' => 'generic@1',
	'meta' => array('document_title' => 'COTIZACIÓN', 'quote_number' => 'COT-000123', 'issued_at' => '2026-09-25', 'valid_until' => '2026-10-10', 'currency' => 'UF'),
	'issuer' => array('company_name' => 'Estandar', 'logo_url' => 'https://x.cl/l.png', 'primary_color' => '#111111', 'accent_color' => '#cc0000', 'authority_name' => 'Luis', 'authority_role' => 'Gerente', 'authority_id' => '1-9', 'footer_legal' => 'Legal'),
	'client' => array('name' => 'Juan Pérez', 'tax_id' => '1-9', 'email' => 'j@x.cl', 'phone' => '+56', 'address' => 'Calle 1'),
	'project' => array('name' => 'Acacios', 'stage' => 'Venta', 'sales_room_name' => 'Sala'),
	'property' => array('unit_code' => 'A-101', 'bedrooms' => 3, 'parking' => 1),
	'pricing' => array('total_uf' => 1000, 'uf_value_clp' => 40000),
	'financing' => array('credit_percent' => 80, 'annual_rate_percent' => 5, 'income_multiple' => 4, 'terms_years' => 20),
	'items' => array(array('description' => 'Servicio', 'detail' => 'Detalle', 'unit' => 'un', 'qty' => 2, 'unit_price' => 1000)),
	'totals' => array('tax_rate' => 19),
	'conditions' => array('Condición A'),
);
$html = DocRenderer::render($doc);
check('15w render contiene secciones', strpos($html, 'Cliente') !== false && strpos($html, 'Totales') !== false && strpos($html, 'Condiciones') !== false && strpos($html, 'Sala de venta') !== false && strpos($html, 'Inversión') !== false);
check('15x render contiene slots data-qfield', strpos($html, 'data-qfield="client.name"') !== false && strpos($html, 'data-qfield="meta.quote_number"') !== false && strpos($html, 'data-qfield="totals.total"') !== false);
$print = DocRenderer::render_print($doc);
check('15y render_print sin data-qfield', strpos($print, 'data-qfield') === false);
$script_doc = $doc;
$script_doc['client']['name'] = '<script>alert(1)</script>';
$esc = DocRenderer::render($script_doc);
check('15z escapado de <script>', strpos($esc, '<script>') === false && strpos($esc, '&lt;script&gt;') !== false);

// ── 15bis. Réplica inmobiliaria real_estate@1 (estructura y layout) ────────
$re_doc = array(
	'profile' => 'real_estate@1',
	'meta'    => array(
		'title' => 'Cotización Altacruz', 'document_title' => 'COTIZACIÓN',
		'quote_number' => '11035737', 'issued_at' => '2026-06-06',
		'valid_until' => '2026-06-13', 'currency' => 'UF',
	),
	'branding' => array(
		'company' => array( 'name' => 'ACACIOS', 'logo_url' => 'https://acacios.cl/logo.png', 'color' => '#1b3a5b' ),
		'project' => array( 'name' => 'Altacruz', 'logo_url' => 'https://acacios.cl/altacruz.png', 'color' => '#b34700', 'slug' => 'altacruz' ),
	),
	'client' => array(
		'name' => 'Marisol Andrea González Moraga', 'tax_id' => '17.288.682-5',
		'email' => 'marisolgonzalezmoraga@gmail.com', 'phone' => '(+56 9) 77150669', 'address' => 'Nancagua',
	),
	'project' => array(
		'name' => 'Altacruz', 'stage' => '1',
		'sales_room_name' => 'Av. Armando Jaramillo 1053, Nancagua, Chile. Acacios',
		'sales_room_phone' => '+56942305982', 'sales_room_email' => 'acacios@acacios.cl', 'sales_room_hours' => 'No Informa',
	),
	'property' => array(
		'unit_code' => 'Casa C-10', 'model' => '110', 'orientation' => 'Indefinido',
		'area_total_m2' => 110, 'area_usable_m2' => '', 'floors' => 1,
		'bedrooms' => 4, 'bathrooms' => 3, 'storage' => '', 'parking' => '',
	),
	'pricing' => array( 'total_uf' => 4875, 'uf_value_clp' => 40712.34, 'uf_effective_date' => '2026-06-06', 'uf_source' => 'SII' ),
	'investment_rows' => array(
		array( 'concept' => 'Casa: C-10', 'total_uf' => 4875, 'total_clp' => 198472658 ),
		array( 'concept' => 'Valores Totales', 'total_uf' => 4875, 'total_clp' => 198472658 ),
	),
	'financing' => array(
		'credit_percent' => 80, 'annual_rate_percent' => 4.10, 'income_multiple' => 4,
		'credit_uf' => 3900, 'simulation_no' => 1, 'terms_years' => array( 5, 8, 10, 15, 20, 25, 30 ),
	),
	'conditions' => array(
		'La tasa de interés es sólo referencial.',
		'Validez cotización 7 días corridos a contar de esta fecha, y no constituye reserva de compra.',
		'Serán de cargo exclusivo del comprador, todos los gastos que genere esta operación.',
	),
	'notes'  => 'Disponibilidad y precios sujetos a confirmación en sala de ventas.',
	'images' => array( array( 'url' => 'https://acacios.cl/casa1.jpg', 'caption' => 'Fachada' ) ),
);
$re  = DocRenderer::render( $re_doc );
$rep = DocRenderer::render_print( $re_doc );
$re_nologo = $re_doc;
unset( $re_nologo['branding']['company']['logo_url'], $re_nologo['branding']['project']['logo_url'] );
$re_nl = DocRenderer::render( $re_nologo );

check('15bis-a zonas de branding empresa+proyecto', strpos( $re, 'esq-brand-company' ) !== false && strpos( $re, 'esq-brand-project' ) !== false && strpos( $re, 'ACACIOS' ) !== false && strpos( $re, 'Altacruz' ) !== false );
check('15bis-b colores de branding aplicados (sin logo)', strpos( $re_nl, 'color:#1b3a5b' ) !== false && strpos( $re_nl, 'color:#b34700' ) !== false && strpos( $re_nl, 'ACACIOS' ) !== false );
check('15bis-c rótulos de encabezado', strpos( $re, 'Cotización N°' ) !== false && strpos( $re, 'VALOR U.F:' ) !== false && strpos( $re, 'Fecha:' ) !== false && strpos( $re, 'VIGENCIA:' ) !== false );
check('15bis-d títulos de sección exactos', strpos( $re, 'DATOS DEL CLIENTE' ) !== false && strpos( $re, 'DATOS DEL PROYECTO Y LA PROPIEDAD' ) !== false && strpos( $re, 'VALOR DE LA INVERSIÓN' ) !== false && strpos( $re, 'DATOS SALA DE VENTA' ) !== false && strpos( $re, 'FINANCIAMIENTO' ) !== false && strpos( $re, 'IMÁGENES DEL PROYECTO' ) !== false );
check('15bis-e orden de secciones', (function () use ( $re ) {
	$order = array( 'DATOS DEL CLIENTE', 'DATOS DEL PROYECTO Y LA PROPIEDAD', 'VALOR DE LA INVERSIÓN', 'DATOS SALA DE VENTA', 'FINANCIAMIENTO', 'IMÁGENES DEL PROYECTO' );
	$prev  = -1;
	foreach ( $order as $label ) {
		$pos = strpos( $re, $label );
		if ( $pos === false || $pos < $prev ) { return false; }
		$prev = $pos;
	}
	return true;
})() );
check('15bis-f page-break solo antes de financiamiento (imágenes en pág. 2)', substr_count( $re, 'page-break-before: always' ) === 1 );
check('15bis-g sin position:absolute/flex/grid', strpos( $re, 'position:absolute' ) === false && strpos( $re, 'display:flex' ) === false && strpos( $re, 'display:grid' ) === false );
check('15bis-h @page letter portrait', strpos( $re, 'size: letter portrait' ) !== false );
check('15bis-i sin anchos fijos de página', strpos( $re, 'width:8.5in' ) === false && strpos( $re, 'width:11in' ) === false );
check('15bis-j imágenes con max-width:100%', (function () use ( $re ) {
	$imgs = substr_count( $re, '<img' );
	return $imgs > 0 && substr_count( $re, 'max-width:100%' ) >= $imgs;
})() );
check('15bis-k filas de inversión formateadas', strpos( $re, 'Valores Totales' ) !== false && strpos( $re, '4.875,00' ) !== false && strpos( $re, '198.472.658' ) !== false && strpos( $re, 'Total(UF)' ) !== false && strpos( $re, 'Total.($)' ) !== false );
check('15bis-l línea de financiamiento y encabezados', strpos( $re, 'Simulación Dividendo Nº 1' ) !== false && strpos( $re, 'Monto Crédito: 3900 U.F. (80%) (Tasa 4,10)' ) !== false && strpos( $re, 'Valor mes/UF' ) !== false && strpos( $re, 'Renta Mínima /$' ) !== false && strpos( $re, 'U.F. ' ) !== false );
check('15bis-m condiciones numeradas 1.- y 3.-', strpos( $re, '1.- ' ) !== false && strpos( $re, '3.- ' ) !== false && strpos( $re, 'Nota:' ) !== false );
check('15bis-n render_print sin slots y firma sin web', strpos( $rep, 'data-qfield' ) === false && strpos( $rep, 'class="esq-sign"' ) !== false && (function () use ( $rep ) {
	$pos = strpos( $rep, 'class="esq-sign"' );
	return $pos !== false && strpos( substr( $rep, $pos ), 'estandar.cl' ) === false;
})() );
check('15bis-o render con slots de campos inmobiliarios', strpos( $re, 'data-qfield="client.name"' ) !== false && strpos( $re, 'data-qfield="property.unit_code"' ) !== false && strpos( $re_nl, 'data-qfield="branding.project.name"' ) !== false && strpos( $re, 'data-qfield="notes"' ) !== false );
check('15bis-p sin desborde: anchos px <= 527pt', (function () use ( $re ) {
	if ( preg_match_all( '/width:\s*(\d+(?:\.\d+)?)px/i', $re, $m ) ) {
		foreach ( $m[1] as $w ) {
			if ( (float) $w > 527.0 ) { return false; }
		}
	}
	return true;
})() );

// Fallback genérico intacto cuando falta el perfil inmobiliario.
check('15bis-q perfil distinto usa layout genérico', strpos( DocRenderer::render( array( 'profile' => 'generic@1', 'items' => array( array( 'description' => 'X', 'qty' => 1, 'unit_price' => 10 ) ) ) ), 'Ítems' ) !== false );


// ── 16. M4: Document/Pdf + PdfStore + Artifacts (fallback y seguridad) ──────
use Estandar\Flow\Quotes\Document\Pdf as DocPdf;
use Estandar\Flow\Quotes\Document\PdfStore as DocPdfStore;
use Estandar\Flow\Quotes\Artifacts as Artifacts;

check('16a source_hash determinista', DocPdf::source_hash('<p>x</p>', 'a') === DocPdf::source_hash('<p>x</p>', 'a'));
check('16b source_hash distinto con distinto html', DocPdf::source_hash('<p>x</p>') !== DocPdf::source_hash('<p>y</p>'));
check('16c source_hash distinto con distinto extra', DocPdf::source_hash('<p>x</p>', 'a') !== DocPdf::source_hash('<p>x</p>', 'b'));
check('16d filename formato canónico', DocPdf::filename(array(
	'id' => 5,
	'meta' => array('quote_number' => 'COT-000123'),
	'client' => array('name' => 'Juan Pérez'),
), 2) === 'Cotizacion-juan-perez-COT-000123-02.pdf');
check('16e filename sanea traversal', (function(){
	$n = DocPdf::filename(array(
		'id' => 5,
		'meta' => array('quote_number' => '../COT 1'),
		'client' => array('name' => '../../etc/passwd'),
	), 0);
	return strpos($n, '/') === false && strpos($n, '..') === false && strpos($n, '\\') === false;
})());
check('16f filename fallback sin correlativo → Cotizacion-{id}', DocPdf::filename(array('id' => 9)) === 'Cotizacion-9.pdf');
check('16g available() false sin Core', DocPdf::available() === false);
$pdf_render = DocPdf::render(array('id' => 1, 'client' => array('name' => 'X')));
check('16h render sin Core → ok=false, error=sin_motor', $pdf_render['ok'] === false && $pdf_render['error'] === 'sin_motor' && is_string($pdf_render['html']));
check('16i save sin Core → null (no fatal)', DocPdf::save(array('id' => 1), sys_get_temp_dir() . '/x.pdf') === null);
check('16j PdfStore path usa basename (sin traversal)', DocPdfStore::path('../../etc/passwd') === DocPdfStore::dir() . '/passwd');
check('16k Artifacts register sin DB → 0', Artifacts::register(1, 'u', 'h', 'p', 10, 'k') === 0);
check('16l Artifacts exists/latest sin DB → null', Artifacts::exists(1, 'u', 'h') === null && Artifacts::latest_for_quote(1) === null);

// ── 17. M5: Quotes\Settings + DocumentBuilder (settings → documento/correo) ─
use Estandar\Flow\Quotes\Settings as QSettings;
use Estandar\Flow\Quotes\DocumentBuilder;

$qdef = QSettings::defaults();
check('17a defaults contiene enabled/currency/vat', array_key_exists('enabled', $qdef) && array_key_exists('currency', $qdef) && array_key_exists('vat', $qdef));
check('17b defaults cubre claves de Admin', isset($qdef['company_name'], $qdef['document_title'], $qdef['authority_name'], $qdef['email_subject_template'], $qdef['sales_room_name'], $qdef['validity_days']));
check('17c enabled true por defecto sin WP', QSettings::enabled() === true);

$GLOBALS['__opts'][QSettings::OPTION] = array('enabled' => '0');
check('17d enabled false explícito', QSettings::enabled() === false);

$GLOBALS['__opts'][QSettings::OPTION] = array(
	'email_subject_template' => '{title} {quote_number} — {site_name}',
	'conditions'             => "Uno\n\n  Dos  \n",
);
$subj_doc = array('meta' => array('quote_number' => 'COT-000123', 'title' => 'Casa'), 'issuer' => array('company_name' => 'Estandar'));
check('17e subject_for interpola tokens', QSettings::subject_for($subj_doc) === 'Casa COT-000123 — Estandar');
check('17f conditions_lines parte y limpia', QSettings::conditions_lines() === array('Uno', 'Dos'));

$GLOBALS['__opts'][QSettings::OPTION] = array('email_subject_template' => '');
check('17g subject_for cae a fallback', QSettings::subject_for($subj_doc) === 'Cotización COT-000123');

$GLOBALS['__opts'][QSettings::OPTION] = array('validity_days' => '');
check('17h validity_days vacío → 15', QSettings::validity_days() === 15);
$Qf = QSettings::financing_defaults();
check('17i financing_defaults con credit/rate/multiple', isset($Qf['credit_percent'], $Qf['annual_rate_percent'], $Qf['income_multiple']));

unset($GLOBALS['__opts'][QSettings::OPTION]);

$base = DocumentBuilder::for_quote(0);
check('17j for_quote(0) claves canónicas sin fatal', is_array($base) && isset($base['meta'], $base['issuer'], $base['client'], $base['items'], $base['totals']) && $base['meta']['quote_number'] === 'COT-0');
$base_html = DocRenderer::render_print($base);
check('17k render_print(for_quote(0)) con título y sin slots', strpos($base_html, 'Cotización') !== false && strpos($base_html, 'data-qfield') === false);
check('17l Quotes::document delega en DocumentBuilder', F\Quotes::document(0) === $base);

// ── 18. M7/M9: Lifecycle + Expiry + puente cotización → journey ────────────
use Estandar\Flow\Quotes\Lifecycle as QLifecycle;
use Estandar\Flow\Quotes\Expiry as QExpiry;

check('18a can_transition draft → sent', QLifecycle::can_transition('draft', 'sent'));
check('18b can_transition sent → accepted', QLifecycle::can_transition('sent', 'accepted'));
check('18c can_transition sent → rejected', QLifecycle::can_transition('sent', 'rejected'));
check('18d can_transition sent → expired', QLifecycle::can_transition('sent', 'expired'));
check('18e can_transition accepted → sent inválida', !QLifecycle::can_transition('accepted', 'sent'));
check('18f can_transition draft → accepted inválida', !QLifecycle::can_transition('draft', 'accepted'));
check('18g can_transition estado desconocido inválida', !QLifecycle::can_transition('issued', 'sent'));
check('18h TRANSITIONS canónicas', QLifecycle::TRANSITIONS === array(
	'draft' => array('sent'), 'sent' => array('accepted', 'rejected', 'expired'),
	'accepted' => array(), 'rejected' => array(), 'expired' => array(),
));

check('18i signal_target quote_issued → PROPOSAL', F\Pipeline::signal_target('quote_issued') === 'PROPOSAL');
check('18j signal_target quote_accepted → CLOSED_WON', F\Pipeline::signal_target('quote_accepted') === 'CLOSED_WON');
check('18k signal_target quote_rejected → LOST', F\Pipeline::signal_target('quote_rejected') === 'LOST');

check('18l transition(0,...) → false sin DB', QLifecycle::transition(0, 'sent') === false);
check('18m transition inexistente sin DB → false', QLifecycle::transition(1, 'accepted') === false);
check('18n transition estado inválido → false', QLifecycle::transition(1, 'bogus') === false);
check('18o expire_due sin DB → 0', QLifecycle::expire_due() === 0);
check('18p set_valid_until_from_send sin DB no fatal', (function(){ QLifecycle::set_valid_until_from_send(1); return true; })());
check('18q advance_proposal sin DB no fatal', (function(){ QLifecycle::advance_proposal(1); return true; })());

check('18r Expiry::HOOK canónico', QExpiry::HOOK === 'estandar_suite_flow_quote_expiry');
check('18s Expiry::run no fatal', (function(){ QExpiry::run(); return true; })());
check('18t Expiry::schedule sin WP no fatal', (function(){ QExpiry::schedule(); return true; })());
check('18u Expiry::unschedule sin WP no fatal', (function(){ QExpiry::unschedule(); return true; })());

// ── 19. M6: Quotes\Editor (WYSIWYG tipo SII + normalize/save) ──────────────
use Estandar\Flow\Quotes\Editor as QEditor;

check('19a Editor::to_number parsea CLP formateado', QEditor::to_number('$ 1.234.567') === 1234567.0);
check('19b Editor::to_number parsea UF formateado', QEditor::to_number('UF 1.234,56') === 1234.56);

$flat_model = array(
	'meta.title'           => 'Cotización X',
	'meta.currency'        => 'UF',
	'client.name'          => 'Juan Pérez',
	'client.tax_id'        => '1-9',
	'items.0.description'  => 'Servicio',
	'items.0.qty'          => '2',
	'items.0.unit_price'   => '1000',
	'items.1.description'  => 'Extra',
	'items.1.qty'          => '1',
	'items.1.unit_price'   => '500',
	'conditions.list'      => "Uno\nDos",
	'notes'                => 'Pago 30 días',
);
$ndoc = QEditor::normalize_post(array('document_json' => json_encode($flat_model)), 7);
check('19c normalize_post JSON válido → canónico', is_array($ndoc) && isset($ndoc['meta'], $ndoc['items'], $ndoc['totals']));
check('19d normalize_post meta/title + currency', $ndoc['meta']['title'] === 'Cotización X' && $ndoc['meta']['currency'] === 'UF');
check('19e normalize_post items agrupados por índice', count($ndoc['items']) === 2 && $ndoc['items'][0]['qty'] === 2.0 && $ndoc['items'][1]['unit_price'] === 500.0);
check('19f normalize_post line_total + totals', $ndoc['items'][0]['line_total'] === 2000.0 && $ndoc['totals']['subtotal'] === 2500.0);
check('19g normalize_post conditions desde lista', $ndoc['conditions'] === array('Uno', 'Dos'));

$ndoc_bad = QEditor::normalize_post(array(
	'document_json' => '{no es json',
	'title'         => 'Legacy',
	'currency'      => 'CLP',
	'items'         => "Terreno|120000000\nDoc|50000",
	'notes'         => 'n',
), 8);
check('19h normalize_post JSON inválido → fallback legado', $ndoc_bad['meta']['title'] === 'Legacy' && count($ndoc_bad['items']) === 2 && $ndoc_bad['items'][0]['unit_price'] === 120000000.0);

$ndoc_empty = QEditor::normalize_post(array(), 9);
check('19i normalize_post sin datos → documento seguro', isset($ndoc_empty['meta'], $ndoc_empty['items']) && $ndoc_empty['items'] === array());

$doc_canvas = array(
	'meta'   => array('currency' => 'CLP'),
	'client' => array('name' => 'Acme'),
	'items'  => array(array('description' => 'S', 'qty' => 1, 'unit_price' => 100)),
);
$canvas = QEditor::canvas($doc_canvas);
check('19j canvas envuelve en .esq-canvas con data-pdf-width', strpos($canvas, 'class="esq-canvas"') !== false && strpos($canvas, 'data-pdf-width="8.5in"') !== false);
check('19k canvas incluye slots data-qfield', strpos($canvas, 'data-qfield="client.name"') !== false);
check('19l canvas JS namespaced sin eval', strpos($canvas, 'window.esqModel') !== false && strpos($canvas, 'window.esqCanvas') !== false && strpos($canvas, 'eval(') === false);

$page = QEditor::page(1, 0, 'aviso', 'info', 'https://x.cl/panel/', 'nonce123', $doc_canvas, array('quotes' => array(), 'back' => 'https://x.cl/panel/?lead=1'));
check('19m page: form quote_save + document_json', strpos($page, 'name="lp_action" value="quote_save"') !== false && strpos($page, 'name="document_json"') !== false && strpos($page, 'name="_wpnonce"') !== false);
check('19n page: botones Guardar/Vista previa/Generar PDF', strpos($page, '>Guardar<') !== false && strpos($page, 'id="esq-preview"') !== false && strpos($page, 'id="esq-print"') !== false);

check('19o save_from_post sin DB → no_creada', (function(){
	$r = QEditor::save_from_post(5, array('title' => 'x', 'items' => 'a|1'));
	return $r['ok'] === false && in_array($r['error'], array('no_creada', 'sin_modulo'), true);
})());

// ── 20. M8: Quotes\Gateway (link público del cliente) ─────────────────────
use Estandar\Flow\Quotes\Gateway as QGateway;

check('20a Gateway constantes QV/SLUG', QGateway::QV === 'estandar_quote_token' && QGateway::SLUG === 'cotizacion');
check('20b is_public_path token válido', QGateway::is_public_path('cotizacion/' . str_repeat('a', 64)) === true);
check('20c is_public_path con barras', QGateway::is_public_path('/cotizacion/abcdef0123456789/') === true);
check('20d is_public_path sin token', QGateway::is_public_path('cotizacion/') === false);
check('20e is_public_path ruta ajena', QGateway::is_public_path('otra/cotizacion/x') === false);
check('20f is_public_path vacío', QGateway::is_public_path('') === false);
check('20g is_valid_token rechaza vacío/corto/no-hex', ! QGateway::is_valid_token('') && ! QGateway::is_valid_token('abc') && ! QGateway::is_valid_token(str_repeat('z', 32)));
check('20h is_valid_token acepta 64 hex', QGateway::is_valid_token(str_repeat('a', 64)));
check('20i token_for(0) no fatal → ""', QGateway::token_for(0) === '');
check('20j public_url(0) no fatal → ""', QGateway::public_url(0) === '');
check('20k find_by_token formato inválido → null', QGateway::find_by_token('') === null && QGateway::find_by_token('../etc/passwd') === null);

$pub = QGateway::render_public_page(
	array('id' => 42, 'status' => 'sent', 'quote_number' => 'COT-000042'),
	str_repeat('b', 64),
	'<div class="q-doc">Documento</div>',
	''
);
check('20l página pública autocontenida + botón Aceptar', strpos($pub, '<!DOCTYPE html>') !== false && strpos($pub, 'Aceptar') !== false && strpos($pub, 'q-doc') !== false);
check('20m página pública incluye token en la decisión', strpos($pub, 'name="_token"') !== false && strpos($pub, str_repeat('b', 64)) !== false);
$pub_done = QGateway::render_public_page(array('id' => 7, 'status' => 'accepted', 'quote_number' => 'COT-7'), str_repeat('c', 64));
check('20n página de estado no enviado deshabilita botones', strpos($pub_done, 'disabled') !== false && strpos($pub_done, '<form') === false && strpos($pub_done, 'Aceptada') !== false);
check('20o Quotes::public_url(0) no fatal → ""', F\Quotes::public_url(0) === '');

// ── 21. Branding por proyecto (Settings + DocumentBuilder) ─────────────────
use Estandar\Flow\Quotes\Settings as QBranding;

unset($GLOBALS['__opts'][QBranding::PROJECT_BRANDING_OPTION]);
$pb = QBranding::project_branding('acacios');
check('21a project_branding sin opción → name no vacío (no fatal)', is_array($pb) && $pb['name'] === 'Acacios');
check('21b project_branding expone slug/logo_url/color', $pb['slug'] === 'acacios' && $pb['logo_url'] === '' && $pb['color'] === '');

$cb = QBranding::company_branding();
check('21c company_branding claves canónicas', isset($cb['name'], $cb['logo_url'], $cb['color']));
$br = QBranding::branding('acacios');
check('21d branding trae company + project', isset($br['company'], $br['project']) && $br['project']['slug'] === 'acacios');

QBranding::save_project_branding(array(
	'Acacios' => array('name' => '  Acacios SpA  ', 'logo_id' => '12', 'logo_url' => 'https://x.cl/a.png', 'color' => '#1b3a5b'),
	'Bogus'   => array('name' => 'X', 'color' => 'no-color'),
));
$all = QBranding::project_branding_all();
check('21e save_project_branding normaliza slug', isset($all['acacios']) && $all['acacios']['logo_id'] === 12);
check('21f project_branding_all lee y sanea name/color', ($all['acacios']['name'] ?? '') === 'Acacios SpA' && ($all['acacios']['color'] ?? '') === '#1b3a5b');
check('21g color inválido → vacío', ($all['bogus']['color'] ?? 'x') === '');
unset($GLOBALS['__opts'][QBranding::PROJECT_BRANDING_OPTION]);

$ir = DocumentBuilder::build_investment_rows(
	array('name' => 'Altacruz'),
	array('unit_code' => 'Casa C-10'),
	array('total_uf' => 4875, 'uf_value_clp' => 40712.34)
);
check('21h investment_rows concepto y montos', count($ir) === 1 && $ir[0]['concept'] === 'Casa: C-10' && $ir[0]['total_uf'] === 4875.0 && $ir[0]['total_clp'] > 0);
check('21i investment_rows sin unit_code usa nombre proyecto', (function () {
	$r = DocumentBuilder::build_investment_rows(array('name' => 'Altacruz'), array('bedrooms' => 3), array('total_uf' => 100, 'uf_value_clp' => 40000));
	return count($r) === 1 && $r[0]['concept'] === 'Altacruz' && $r[0]['total_clp'] === 4000000.0;
})());
check('21j investment_rows sin datos → vacío', DocumentBuilder::build_investment_rows(array(), array(), array()) === array());

$branded_lead = array('project_slug' => 'acacios', 'project_name' => 'Acacios', 'bedrooms' => 4, 'bathrooms' => 3);
$bdoc = DocumentBuilder::for_quote(0, $branded_lead);
check('21k for_quote incluye branding', isset($bdoc['branding']['company'], $bdoc['branding']['project']));
check('21l for_quote project.slug del lead', ($bdoc['project']['slug'] ?? '') === 'acacios');
check('21m for_quote property.bedrooms/bathrooms poblados', (int) ($bdoc['property']['bedrooms'] ?? 0) === 4 && (int) ($bdoc['property']['bathrooms'] ?? 0) === 3);
check('21n for_quote investment_rows desde pricing', count($bdoc['investment_rows']) === 1 && $bdoc['investment_rows'][0]['concept'] === 'Acacios');

// ── 22. Perfil activo + document_json (WYSIWYG) → DocumentBuilder ──────────
unset($GLOBALS['__opts'][QSettings::OPTION]);
check('22a active_profile default real_estate@1', QSettings::active_profile() === 'real_estate@1');
$Qdef22 = QSettings::defaults();
check('22b defaults incluye active_profile', ($Qdef22['active_profile'] ?? '') === 'real_estate@1');

$GLOBALS['__opts'][QSettings::OPTION] = array('active_profile' => 'generic@1');
check('22c active_profile respeta opción', QSettings::active_profile() === 'generic@1');

$GLOBALS['__opts'][QSettings::OPTION] = array('active_profile' => '   ');
check('22d active_profile vacío → default', QSettings::active_profile() === 'real_estate@1');
unset($GLOBALS['__opts'][QSettings::OPTION]);

$nested_profile = array(
	'profile' => 'real_estate@1',
	'meta'    => array('title' => 'X', 'currency' => 'UF'),
	'client'  => array('name' => 'Juan'),
	'items'   => array(array('description' => 'S', 'qty' => 1, 'unit_price' => 10)),
);
$np = QEditor::normalize_post(array('document_json' => json_encode($nested_profile)), 1);
check('22e normalize_post conserva profile anidado', ($np['profile'] ?? '') === 'real_estate@1');

$np_def = QEditor::normalize_post(array('document_json' => json_encode(array('meta' => array('title' => 'X')))), 1);
check('22f normalize_post sin profile usa activo', ($np_def['profile'] ?? '') === 'real_estate@1');

$np_flat = QEditor::normalize_post(array('document_json' => json_encode(array('profile' => 'real_estate@1', 'meta.title' => 'X'))), 1);
check('22g normalize_post flat conserva profile', ($np_flat['profile'] ?? '') === 'real_estate@1');

$np_legacy = QEditor::normalize_post(array('title' => 'L', 'items' => 'A|10'), 1);
check('22h normalize_post legado usa activo', ($np_legacy['profile'] ?? '') === 'real_estate@1');

check('22i save_from_post sin DB no fatal', (function () {
	$r = QEditor::save_from_post(5, array('title' => 'x', 'items' => 'a|1'));
	return is_array($r) && $r['ok'] === false && in_array($r['error'], array('no_creada', 'sin_modulo'), true);
})());

$base22 = DocumentBuilder::for_quote(0);
check('22j for_quote(0) profile no vacío', is_string($base22['profile'] ?? null) && '' !== $base22['profile']);
check('22k for_quote(0) profile = activo', ($base22['profile'] ?? '') === 'real_estate@1');

$stored_re = array(
	'profile'         => 'real_estate@1',
	'meta'            => array('quote_number' => '11035737', 'currency' => 'UF'),
	'branding'        => array('company' => array('name' => 'ACACIOS')),
	'client'          => array('name' => 'Marisol'),
	'investment_rows' => array(array('concept' => 'Casa: C-10', 'total_uf' => 4875, 'total_clp' => 198472658)),
	'items'           => array(array('description' => 'Editado', 'qty' => 1, 'unit_price' => 100)),
);
$live_re = array(
	'profile'         => 'generic@1',
	'meta'            => array('quote_number' => 'COT-9', 'issued_at' => '2026-06-06', 'currency' => 'CLP'),
	'branding'        => array('company' => array('name' => 'X', 'logo_url' => 'L'), 'project' => array('name' => 'Altacruz')),
	'client'          => array('name' => 'Lead', 'email' => 'e@x.cl'),
	'investment_rows' => array(array('concept' => 'Vivo')),
	'items'           => array(array('description' => 'Vivo', 'qty' => 1, 'unit_price' => 5)),
	'totals'          => array('tax_rate' => 19),
);
$m22 = DocumentBuilder::merge_document($stored_re, $live_re);
check('22l merge conserva profile/quote_number/branding/investment_rows guardados', ($m22['profile'] ?? '') === 'real_estate@1' && ($m22['meta']['quote_number'] ?? '') === '11035737' && ($m22['branding']['company']['name'] ?? '') === 'ACACIOS' && ($m22['investment_rows'][0]['concept'] ?? '') === 'Casa: C-10');
check('22m merge rellena huecos vivos (logo/meta/client)', ($m22['branding']['company']['logo_url'] ?? '') === 'L' && ($m22['meta']['issued_at'] ?? '') === '2026-06-06' && ($m22['client']['email'] ?? '') === 'e@x.cl');
check('22n merge conserva items editados y recalcula totales', ($m22['items'][0]['description'] ?? '') === 'Editado' && (float) ($m22['totals']['subtotal'] ?? 0) === 100.0);
check('22o merge con vacío devuelve live', DocumentBuilder::merge_document(array(), array('profile' => 'x')) === array('profile' => 'x'));

// ── 23. Branding extendido: banda, caja de condiciones, pie fijo, operador ─
$Qd23 = QSettings::defaults();
check('23a defaults incluye imágenes de banda y pie', array_key_exists('company_header_image_id', $Qd23) && array_key_exists('company_header_image_url', $Qd23) && array_key_exists('footer_image_id', $Qd23) && array_key_exists('footer_image_url', $Qd23));

$GLOBALS['__opts'][QSettings::OPTION] = array(
	'company_name'             => 'ACACIOS',
	'company_header_image_url' => 'https://x.cl/band.png',
	'footer_image_url'         => 'https://x.cl/foot.png',
);
$cb23 = QSettings::company_branding();
check('23b company_branding expone header_image_url', ($cb23['header_image_url'] ?? '') === 'https://x.cl/band.png');
$fb23 = QSettings::footer_branding();
check('23c footer_branding expone image_url', ($fb23['image_url'] ?? '') === 'https://x.cl/foot.png');
unset($GLOBALS['__opts'][QSettings::OPTION]);

QBranding::save_project_branding(array('Altacruz' => array(
	'name'             => 'Altacruz',
	'header_image_url' => 'https://x.cl/alt-band.png',
	'images'           => array(
		array('id' => 7, 'url' => 'https://x.cl/1.png', 'caption' => 'Fachada'),
		array('url' => 'https://x.cl/2.png', 'caption' => 'Living'),
		array('id' => 0, 'url' => '', 'caption' => 'vacía'),
	),
)));
$pb23 = QBranding::project_branding('altacruz');
check('23d project_branding expone header_image_url', ($pb23['header_image_url'] ?? '') === 'https://x.cl/alt-band.png');
check('23e project_branding expone galería saneada', count($pb23['images']) === 2 && $pb23['images'][0]['url'] === 'https://x.cl/1.png' && $pb23['images'][1]['caption'] === 'Living');
$all23 = QBranding::project_branding_all();
check('23f save_project_branding guarda header e imágenes', ($all23['altacruz']['header_image_url'] ?? '') === 'https://x.cl/alt-band.png' && count($all23['altacruz']['images'] ?? array()) === 2);
$br23 = QBranding::branding('altacruz');
check('23g branding incluye footer', isset($br23['footer']['image_url']));
unset($GLOBALS['__opts'][QBranding::PROJECT_BRANDING_OPTION]);

$re23 = $re_doc;
$re23['branding']['company']['header_image_url'] = 'https://x.cl/band.png';
$re23['branding']['project']['header_image_url'] = 'https://x.cl/alt-band.png';
$re23['branding']['project']['images']           = array(array('url' => 'https://x.cl/1.png', 'caption' => 'Fachada'));
$re23['branding']['footer']                      = array('image_url' => 'https://x.cl/foot.png');
$re23['operator']                                = array('name' => 'Carla Operadora');
unset($re23['images']);
$h23 = DocRenderer::render_print($re23);
check('23h banda superior full-bleed con la imagen del proyecto', strpos($h23, 'class="esq-banner"') !== false && strpos($h23, 'class="esq-banner-img"') !== false && strpos($h23, 'https://x.cl/alt-band.png') !== false && strpos($h23, 'esq-banner-overlay') !== false);
check('23i caja de condiciones con fondo y sin borde', strpos($h23, 'esq-cond-box') !== false && strpos($h23, '.esq-cond-box { background-color: #f0f0f0; border: 0;') !== false);
check('23j pie fijo repetible presente', strpos($h23, 'esq-footer-fixed') !== false && strpos($h23, 'position: fixed') !== false && strpos($h23, 'https://x.cl/foot.png') !== false);
check('23k imágenes del proyecto desde branding, sin page-break', strpos($h23, 'IMÁGENES DEL PROYECTO') !== false && strpos($h23, 'https://x.cl/1.png') !== false && substr_count($h23, 'page-break-before: always') === 1);
check('23l firma usa operador del proyecto', strpos($h23, 'Carla Operadora') !== false);

$GLOBALS['__opts'][QSettings::OPTION] = array('company_name' => 'Estandar SpA', 'authority_name' => 'Luis');
$bdoc23 = DocumentBuilder::for_quote(0);
check('23m operator.name fallback a autoridad', ($bdoc23['operator']['name'] ?? '') === 'Luis');
check('23n for_quote expone branding.footer.image_url', array_key_exists('image_url', (array) ($bdoc23['branding']['footer'] ?? array())));

$GLOBALS['__opts'][QSettings::OPTION] = array(
	'company_name'             => 'Estandar SpA',
	'company_header_image_url' => 'https://x.cl/h.png',
	'footer_image_url'         => 'https://x.cl/f.png',
);
$bdoc23b = DocumentBuilder::for_quote(0);
check('23o for_quote branding.company.header_image_url', ($bdoc23b['branding']['company']['header_image_url'] ?? '') === 'https://x.cl/h.png');
check('23p for_quote branding.footer.image_url', ($bdoc23b['branding']['footer']['image_url'] ?? '') === 'https://x.cl/f.png');
unset($GLOBALS['__opts'][QSettings::OPTION]);

// ── 24. Embed de imágenes locales + caption único (regresión Acacios) ───────
$re24 = $re_doc;
$re24['images'] = array( array( 'url' => 'https://acacios.cl/wp-content/uploads/2026/09/f1.png', 'caption' => 'Fachada24' ) );
$h24 = DocRenderer::render_print( $re24 );
check('24a caption de galería aparece una sola vez', substr_count( $h24, 'Fachada24' ) === 1);
check('24b galería sin caption duplicado en alt', preg_match('/<img class="esq-image"[^>]*alt=""[^>]*>/', $h24) === 1);
check('24c galería limita altura de imagen (<=6cm)', strpos( $h24, 'max-height:5.8cm' ) !== false);

// Sin raíces locales (harness) el HTML sale intacto.
$raw24 = '<p>x</p><img src="https://acacios.cl/wp-content/uploads/2026/09/f1.png">';
check('24d sin wp_upload_dir → HTML intacto', DocPdf::localize_images( $raw24 ) === $raw24);

if ( ! function_exists( 'wp_upload_dir' ) ) {
	$img_dir24  = rtrim( sys_get_temp_dir(), '/\\' ) . '/estandar-flow-img-test';
	@mkdir( $img_dir24, 0777, true );
	$img_file24 = $img_dir24 . '/f1.png';
	// PNG 1x1 válido para que `realpath`/`is_file` lo acepten.
	@file_put_contents( $img_file24, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC' ) );
	function wp_upload_dir() { return array( 'baseurl' => 'https://acacios.cl/wp-content/uploads', 'basedir' => rtrim( sys_get_temp_dir(), '/\\' ) . '/estandar-flow-img-test' ); }
	function content_url( $p = '' ) { return 'https://acacios.cl/wp-content' . $p; }
	function site_url( $p = '' ) { return 'https://acacios.cl' . $p; }
}
$real24 = realpath( $img_file24 );
$loc24  = DocPdf::localize_images( '<img src="https://acacios.cl/wp-content/uploads/f1.png" alt="">' );
check('24e URL de uploads → ruta local en disco', is_string( $real24 ) && strpos( $loc24, $real24 ) !== false);
$ext24 = DocPdf::localize_images( '<img src="https://cdn.externo.cl/a.png"><img src="https://acacios.cl/wp-content/uploads/../../etc/passwd"><img src="https://acacios.cl/wp-content/uploads/nope.png">' );
check('24f externa + traversal + inexistente intactos', strpos( $ext24, 'https://cdn.externo.cl/a.png' ) !== false && strpos( $ext24, '../../etc/passwd' ) !== false && strpos( $ext24, 'nope.png' ) !== false);

// ── 25. Rediseño de settings: plantillas globales + settings por proyecto ──
use Estandar\Flow\Quotes\Settings as QTpl;

unset($GLOBALS['__opts'][QTpl::TEMPLATES_OPTION], $GLOBALS['__opts'][QTpl::PROJECTS_OPTION], $GLOBALS['__opts'][QTpl::PROJECT_BRANDING_OPTION]);

$dt = QTpl::default_templates();
check('25a default_templates tiene tpl1 con campos', isset($dt['tpl1']) && $dt['tpl1']['id'] === 'tpl1' && $dt['tpl1']['name'] === 'Plantilla 1' && array_key_exists('background_url', $dt['tpl1']) && array_key_exists('bottom_url', $dt['tpl1']));
check('25b default_template_id tpl1', QTpl::default_template_id() === 'tpl1');
check('25c template inexistente cae a tpl1', QTpl::template('nope')['id'] === 'tpl1');
check('25d templates() sin opción incluye tpl1', isset(QTpl::templates()['tpl1']));

QTpl::save_templates(array(
	'tpl1' => array('id' => 'tpl1', 'name' => 'Principal', 'background_id' => '7', 'background_url' => 'https://x.cl/bg.png', 'bottom_id' => 8, 'bottom_url' => 'https://x.cl/bo.png', 'primary_color' => '#112233', 'accent_color' => 'no-color'),
	'tpl2' => array('id' => 'TPL2', 'name' => 'Segunda', 'background_url' => 'https://x.cl/2.png'),
));
$tpls = QTpl::templates();
check('25e save_templates sanea id/ids/colores', ($tpls['tpl1']['background_id'] ?? 0) === 7 && ($tpls['tpl1']['primary_color'] ?? '') === '#112233' && ($tpls['tpl1']['accent_color'] ?? 'x') === '');
check('25f save_templates normaliza id a slug', isset($tpls['tpl2']) && $tpls['tpl2']['id'] === 'tpl2');
check('25g template(id) devuelve la elegida', QTpl::template('tpl2')['name'] === 'Segunda');

$def = QTpl::project_settings('acacios');
check('25h project_settings default no fatal', is_array($def) && $def['template'] === 'tpl1' && is_array($def['data']) && array_key_exists('financing', $def['data']));

QTpl::save_project_settings(array('Acacios' => array(
	'template' => 'tpl2',
	'name'     => 'Acacios SpA',
	'header_image_url' => 'https://x.cl/band.png',
	'images'   => array(array('id' => 3, 'url' => 'https://x.cl/1.png', 'caption' => 'Fachada')),
	'data'     => array(
		'project_name'   => 'Acacios',
		'sales_room_name' => 'Sala',
		'uf_value_clp'   => '40000',
		'conditions'     => array('Uno', 'Dos'),
		'notes'          => 'Nota breve',
		'financing'      => array('credit_percent' => '75'),
	),
)));
$ps = QTpl::project_settings('acacios');
check('25i save_project_settings saneado', $ps['template'] === 'tpl2' && $ps['data']['project_name'] === 'Acacios' && $ps['data']['conditions'] === array('Uno', 'Dos') && $ps['data']['financing']['credit_percent'] === '75');
check('25j template_for_project resuelve elegida', QTpl::template_for_project('acacios')['id'] === 'tpl2');
check('25k template_for_project default sin proyecto', QTpl::template_for_project('nope')['id'] === 'tpl1');

$GLOBALS['__opts'][QTpl::OPTION] = array('sales_room_phone' => '+569', 'uf_value_clp' => '41000', 'conditions' => 'Global', 'credit_percent' => '80');
$eff = QTpl::effective_data('acacios');
check('25l effective_data prioriza proyecto sobre global', $eff['uf_value_clp'] === '40000' && $eff['project_name'] === 'Acacios');
check('25m effective_data global rellena huecos', $eff['sales_room_phone'] === '+569' && $eff['sales_room_name'] === 'Sala');
unset($GLOBALS['__opts'][QTpl::OPTION]);

$pb25 = QTpl::project_branding('acacios');
check('25n project_branding lee de project_settings', $pb25['name'] === 'Acacios SpA' && $pb25['header_image_url'] === 'https://x.cl/band.png' && count($pb25['images']) === 1);
check('25o project_branding expone color/logo sin fatal', is_string($pb25['color']) && is_string($pb25['logo_url']));

$doc25 = DocumentBuilder::for_quote(0, array('project_slug' => 'acacios'));
check('25p for_quote incluye template (id/name/urls)', isset($doc25['template']['id'], $doc25['template']['name'], $doc25['template']['background_url'], $doc25['template']['bottom_url']) && $doc25['template']['id'] === 'tpl2');
check('25q for_quote usa effective_data (sala/uf)', ($doc25['project']['sales_room_name'] ?? '') === 'Sala' && ($doc25['pricing']['uf_value_clp'] ?? '') === '40000');
check('25r for_quote condiciones y notas del proyecto', $doc25['conditions'] === array('Uno', 'Dos') && ($doc25['notes'] ?? '') === 'Nota breve');

// Migración aditiva del mapa viejo de branding hacia `projects`.
unset($GLOBALS['__opts'][QTpl::PROJECTS_OPTION]);
$GLOBALS['__opts'][QTpl::PROJECT_BRANDING_OPTION] = array('viejo' => array('name' => 'Viejo', 'color' => '#abcdef'));
QTpl::save_project_settings(array());
$mig = QTpl::project_settings_all();
check('25s migra mapa viejo de branding a projects', isset($mig['viejo']) && ($mig['viejo']['name'] ?? '') === 'Viejo' && ($mig['viejo']['color'] ?? '') === '#abcdef');

unset($GLOBALS['__opts'][QTpl::TEMPLATES_OPTION], $GLOBALS['__opts'][QTpl::PROJECTS_OPTION], $GLOBALS['__opts'][QTpl::PROJECT_BRANDING_OPTION]);

// ── 26. Plantilla 1: fondo e imagen inferior full-bleed (0 margen) ─────────
$tpl_doc = $re_doc;
$tpl_doc['template'] = array(
	'id'             => 'tpl1',
	'name'           => 'Plantilla 1',
	'background_url' => 'https://acacios.cl/wp-content/uploads/bg.png',
	'bottom_url'     => 'https://acacios.cl/wp-content/uploads/bottom.png',
);
$ht = DocRenderer::render_print( $tpl_doc );

check('26a fondo full-bleed como <img> con tamaño letter', strpos( $ht, '<img class="esq-bg-fixed"' ) !== false && strpos( $ht, 'width:8.5in' ) !== false && strpos( $ht, 'height:11in' ) !== false);
check('26b imagen inferior full-bleed a ancho completo', strpos( $ht, '<img class="esq-bottom-fixed"' ) !== false && strpos( $ht, 'bottom:0;left:0;right:0;width:100%' ) !== false);
check('26c @page sin márgenes y padding del contenido', strpos( $ht, '@page { margin: 0; }' ) !== false && strpos( $ht, 'padding:1.4cm 1.5cm 2.0cm' ) !== false);
check('26d full-bleed detrás del contenido (z-index:-1)', substr_count( $ht, 'z-index:-1' ) >= 2);
check('26e bottom de plantilla reemplaza el pie de texto', strpos( $ht, '<div class="esq-footer-fixed">' ) === false);
check('26f un solo page-break (documento en 2 páginas)', substr_count( $ht, 'page-break-before: always' ) === 1);
check('26g URLs de plantilla presentes para localizar/embeber', strpos( $ht, 'https://acacios.cl/wp-content/uploads/bg.png' ) !== false && strpos( $ht, 'https://acacios.cl/wp-content/uploads/bottom.png' ) !== false);

// Sin plantilla: layout EXACTAMENTE como antes (regresión Acacios).
$hnt = DocRenderer::render_print( $re_doc );
check('26h sin plantilla no hay overlay full-bleed', strpos( $hnt, 'class="esq-bg-fixed"' ) === false && strpos( $hnt, 'class="esq-bottom-fixed"' ) === false && strpos( $hnt, '@page { margin: 0; }' ) === false);
check('26i sin plantilla conserva el pie de texto', strpos( $hnt, '<div class="esq-footer-fixed">' ) !== false);
check('26j sin plantilla conserva los márgenes @page', strpos( $hnt, '@page { size: letter portrait; margin: 1.4cm 1.5cm 2.2cm 1.5cm; }' ) !== false);
check('26k sin plantilla no emite ancho letter fijo', strpos( $hnt, 'width:8.5in' ) === false);

// Solo fondo: el pie de texto se mantiene (no hay bottom que lo reemplace).
$bg_doc = $re_doc;
$bg_doc['template'] = array( 'id' => 'tpl1', 'background_url' => 'https://acacios.cl/wp-content/uploads/bg.png' );
$hbg = DocRenderer::render_print( $bg_doc );
check('26l solo fondo mantiene el pie de texto', strpos( $hbg, '<img class="esq-bg-fixed"' ) !== false && strpos( $hbg, 'class="esq-bottom-fixed"' ) === false && strpos( $hbg, '<div class="esq-footer-fixed">' ) !== false);

// El layout genérico también respeta la plantilla.
$gen_doc = array(
	'profile'  => 'generic@1',
	'items'    => array( array( 'description' => 'X', 'qty' => 1, 'unit_price' => 10 ) ),
	'template' => array( 'bottom_url' => 'https://x.cl/bo.png' ),
);
$hgen = DocRenderer::render_print( $gen_doc );
check('26m layout genérico respeta bottom de plantilla', strpos( $hgen, '<img class="esq-bottom-fixed"' ) !== false && strpos( $hgen, '<div class="esq-footer-fixed">' ) === false);

// ── 27. Wizard Proyectos: header/bottom del proyecto + CC de envío ─────────
unset( $GLOBALS['__opts'][QTpl::PROJECTS_OPTION], $GLOBALS['__opts'][QTpl::PROJECT_BRANDING_OPTION] );

$dps = QTpl::default_project_settings();
check('27a default_project_settings incluye bottom_image_id/url', array_key_exists( 'bottom_image_id', $dps ) && array_key_exists( 'bottom_image_url', $dps ));

QTpl::save_project_entry( 'acacios', array(
	'header_image_url' => 'https://x.cl/h.png',
	'bottom_image_url' => 'https://x.cl/b.png',
	'bottom_image_id'  => 9,
	'template'         => 'tpl1',
) );
QTpl::save_project_entry( 'otro', array( 'name' => 'Otro' ) );
$ps27 = QTpl::project_settings( 'acacios' );
check('27b save_project_entry guarda header y bottom', $ps27['header_image_url'] === 'https://x.cl/h.png' && $ps27['bottom_image_url'] === 'https://x.cl/b.png' && (int) $ps27['bottom_image_id'] === 9);
check('27c save_project_entry no borra otros proyectos', isset( QTpl::project_settings_all()['otro'] ));

$doc27 = DocumentBuilder::for_quote( 0, array( 'project_slug' => 'acacios' ) );
check('27d for_quote usa bottom_image del proyecto', ($doc27['template']['bottom_url'] ?? '') === 'https://x.cl/b.png');
check('27e for_quote expone header del proyecto en branding', ($doc27['branding']['project']['header_image_url'] ?? '') === 'https://x.cl/h.png');

$doc_data27 = QTpl::project_data_from_document( array(
	'project'    => array( 'name' => 'Acacios', 'sales_room_name' => 'Sala X', 'sales_room_phone' => '+569' ),
	'meta'       => array( 'document_title' => 'COTIZACIÓN' ),
	'pricing'    => array( 'uf_value_clp' => '41000', 'uf_source' => 'SII' ),
	'conditions' => array( 'Uno', 'Dos' ),
	'notes'      => 'Nota',
	'financing'  => array( 'credit_percent' => '80' ),
) );
check('27f project_data_from_document mapea campos', $doc_data27['project_name'] === 'Acacios' && $doc_data27['sales_room_name'] === 'Sala X' && $doc_data27['document_title'] === 'COTIZACIÓN' && $doc_data27['uf_value_clp'] === '41000' && $doc_data27['conditions'] === array( 'Uno', 'Dos' ));
QTpl::save_project_entry( 'acacios', array( 'data' => $doc_data27 ) );
$ps27b = QTpl::project_settings( 'acacios' );
check('27g save_project_entry persiste data sin perder bottom', $ps27b['data']['project_name'] === 'Acacios' && $ps27b['bottom_image_url'] === 'https://x.cl/b.png');

check('27h normalize_cc string separado por comas/espacios', F\Quotes::normalize_cc( 'a@x.cl, b@y.cl' ) === array( 'a@x.cl', 'b@y.cl' ));
check('27i normalize_cc array deduplica', F\Quotes::normalize_cc( array( 'a@x.cl', 'a@x.cl' ) ) === array( 'a@x.cl' ));
check('27j normalize_cc descarta inválidos', F\Quotes::normalize_cc( 'no-es-mail, c@z.cl' ) === array( 'c@z.cl' ));
check('27k normalize_cc vacío → []', F\Quotes::normalize_cc( '' ) === array() && F\Quotes::normalize_cc( array() ) === array());
$send27 = F\Quotes::send( 1, 'juan@x.cl', array(), array( 'cc' => 'x@y.cl' ) );
check('27l send con cc sin DB → no_encontrada sin fatal', is_array( $send27 ) && empty( $send27['ok'] ) && ($send27['error'] ?? '') === 'no_encontrada');

QTpl::save_project_settings( array( 'solo' => array( 'bottom_image_url' => 'https://x.cl/s.png', 'bottom_image_id' => '5' ) ) );
$solo27 = QTpl::project_settings( 'solo' );
check('27m save_project_settings sanea bottom del proyecto', $solo27['bottom_image_url'] === 'https://x.cl/s.png' && (int) $solo27['bottom_image_id'] === 5);

// ── 28. Banner full-bleed detrás de los textos + operador/cargo ────────────
$fields28 = DocFields::all();
check('28a catálogo expone operator.name/role editables', isset( $fields28['operator.name'], $fields28['operator.role'] ) && $fields28['operator.name']['group'] === 'operator' && $fields28['operator.name']['editable'] === true && $fields28['operator.role']['editable'] === true);

$dps28 = QTpl::default_project_settings();
check('28b default_project_settings data incluye operator_name/role', array_key_exists( 'operator_name', $dps28['data'] ) && array_key_exists( 'operator_role', $dps28['data'] ));

QTpl::save_project_entry( 'bannerproj', array(
	'header_image_url' => 'https://x.cl/band.png',
	'data'             => array( 'operator_name' => 'Carla Operadora', 'operator_role' => 'Ejecutiva de Ventas' ),
) );
$ps28 = QTpl::project_settings( 'bannerproj' );
check('28c save_project_entry persiste operator_name/role saneados', $ps28['data']['operator_name'] === 'Carla Operadora' && $ps28['data']['operator_role'] === 'Ejecutiva de Ventas');

$doc28 = DocumentBuilder::for_quote( 0, array( 'project_slug' => 'bannerproj' ) );
check('28d for_quote operator.name/role desde data del proyecto', ($doc28['operator']['name'] ?? '') === 'Carla Operadora' && ($doc28['operator']['role'] ?? '') === 'Ejecutiva de Ventas');

$doc28['branding']['company']['name']  = 'Inmobiliaria Acacios';
$doc28['branding']['project']['name']  = 'Altacruz';
$doc28['branding']['company']['color'] = '#1b3a5b';
$doc28['branding']['project']['header_image_url'] = 'https://x.cl/band.png';
$h28 = DocRenderer::render_print( $doc28 );
check('28e banner full-bleed con imagen y overlay', strpos( $h28, 'class="esq-banner"' ) !== false && strpos( $h28, 'width:8.5in' ) !== false && strpos( $h28, 'class="esq-banner-img"' ) !== false && strpos( $h28, 'https://x.cl/band.png' ) !== false && strpos( $h28, 'position:absolute' ) !== false);
check('28f textos del header ENCIMA de la imagen (overlay z-index>img z-index)', (function () use ( $h28 ) {
	$img = strpos( $h28, 'class="esq-banner-img"' );
	$ovl = strpos( $h28, 'class="esq-banner-overlay"' );
	if ( false === $img || false === $ovl || $ovl < $img ) { return false; }
	return strpos( $h28, 'z-index:2' ) !== false && strpos( $h28, 'Inmobiliaria Acacios' ) !== false && strpos( $h28, 'Altacruz' ) !== false && strpos( $h28, 'Cotización N°' ) !== false;
})() );
check('28g sin imagen de encabezado no hay banner (header en flujo)', strpos( DocRenderer::render_print( $re_doc ), 'class="esq-banner"' ) === false && strpos( DocRenderer::render_print( $re_doc ), 'esq-band' ) !== false);

$sig_doc = array(
	'profile'  => 'real_estate@1',
	'branding' => array( 'company' => array( 'name' => 'Inmobiliaria Acacios' ) ),
	'operator' => array( 'name' => 'Carla Operadora', 'role' => 'Ejecutiva de Ventas' ),
);
$sig = DocRenderer::render_print( $sig_doc );
check('28h firma muestra nombre + cargo y sin estandar.cl', (function () use ( $sig ) {
	$pos = strpos( $sig, 'class="esq-sign"' );
	if ( false === $pos ) { return false; }
	$block = substr( $sig, $pos );
	return strpos( $block, 'Carla Operadora' ) !== false && strpos( $block, 'Ejecutiva de Ventas' ) !== false && strpos( $block, 'estandar.cl' ) === false;
})() );

unset( $GLOBALS['__opts'][QTpl::PROJECTS_OPTION], $GLOBALS['__opts'][QTpl::PROJECT_BRANDING_OPTION], $GLOBALS['__opts'][QTpl::TEMPLATES_OPTION] );

// ── 29. document_json NO pisa branding ni plantilla vivos (admin config) ───
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! class_exists( 'FlowWpdbStub29' ) ) {
	class FlowWpdbStub29 {
		public $prefix = 'wp_';
		public $row    = array();
		public function prepare( $query, ...$args ) { return $query; }
		public function get_row( $query, $mode = null ) { return $this->row; }
	}
}
$GLOBALS['__opts'][QTpl::OPTION] = array(
	'company_name'             => 'Estandar Vivo',
	'company_header_image_url' => 'https://x.cl/vivo-band.png',
);
QTpl::save_project_entry( 'acacios', array(
	'header_image_url' => 'https://x.cl/vivo-proj-header.png',
	'bottom_image_url' => 'https://x.cl/vivo-bottom.png',
) );
$GLOBALS['wpdb'] = new FlowWpdbStub29();
$GLOBALS['wpdb']->row = array(
	'id'            => 4242,
	'document_json' => json_encode( array(
		'branding' => array(),
		'template' => array( 'id' => 'tpl1', 'name' => 'Guardada', 'bottom_url' => '' ),
		'project'  => array( 'slug' => 'acacios' ),
		'client'   => array( 'name' => 'Cliente Guardado' ),
	) ),
);
$doc29 = DocumentBuilder::for_quote( 4242 );
check('29a document_json vacío no pisa branding.project vivo', ($doc29['branding']['project']['header_image_url'] ?? '') === 'https://x.cl/vivo-proj-header.png');
check('29b document_json vacío no pisa branding.company vivo', ($doc29['branding']['company']['header_image_url'] ?? '') === 'https://x.cl/vivo-band.png');
check('29c document_json vacío no pisa template.bottom_url vivo', ($doc29['template']['bottom_url'] ?? '') === 'https://x.cl/vivo-bottom.png');
check('29d client guardado se conserva (merge normal)', ($doc29['client']['name'] ?? '') === 'Cliente Guardado');
unset( $GLOBALS['wpdb'] );

// ── 30. email_body: default, interpolación y send tolerante sin DB ──────────
unset( $GLOBALS['__opts'][QTpl::OPTION] );
$eb_default = QSettings::email_body();
check('30a email_body default incluye placeholders', $eb_default !== '' && strpos( $eb_default, '{client_name}' ) !== false && strpos( $eb_default, '{quote_number}' ) !== false);

$GLOBALS['__opts'][QTpl::OPTION] = array(
	'email_body' => 'Hola {client_name} | {quote_number} | {company_name} | {valid_until} | {project_name}',
);
$eb_doc = array(
	'client' => array( 'name' => 'Juan Pérez' ),
	'meta'   => array( 'quote_number' => 'COT-77', 'valid_until' => '2026-10-01' ),
	'issuer' => array( 'company_name' => 'Estandar SpA' ),
	'project' => array( 'name' => 'Altacruz' ),
);
$eb_rendered = QSettings::render_email_body( $eb_doc );
check('30b render_email_body interpola placeholders', $eb_rendered === 'Hola Juan Pérez | COT-77 | Estandar SpA | 2026-10-01 | Altacruz');
check('30c render_email_body tolerante a doc vacío', is_string( QSettings::render_email_body( array() ) ) && QSettings::render_email_body( array() ) !== '');
unset( $GLOBALS['__opts'][QTpl::OPTION] );
$send30 = F\Quotes::send( 1, 'juan@x.cl' );
check('30d send sin DB no fatal (no_encontrada)', is_array( $send30 ) && empty( $send30['ok'] ) && ( $send30['error'] ?? '' ) === 'no_encontrada');

// ── 31. UF efectivo (SII) + galería (límite 6) + imágenes adaptativas ───────
unset( $GLOBALS['__opts'][QSettings::OPTION], $GLOBALS['__opts'][QSettings::UF_OPTION] );

$GLOBALS['__opts'][QSettings::OPTION] = array( 'uf_value_clp' => '40000' );
check('31a uf_value usa override manual', QSettings::uf_value() === 40000.0);

$GLOBALS['__opts'][QSettings::UF_OPTION] = array( 'value' => 41234.56, 'date' => '2026-09-25', 'source' => 'mindicador' );
check('31b uf_value prioriza caché SII sobre manual', QSettings::uf_value() === 41234.56);
$ufe = QSettings::uf_effective();
check('31c uf_effective expone value/date/source', $ufe['value'] === 41234.56 && $ufe['date'] === '2026-09-25' && $ufe['source'] === 'mindicador');

unset( $GLOBALS['__opts'][QSettings::OPTION], $GLOBALS['__opts'][QSettings::UF_OPTION] );
check('31d uf_value sin nada → 0', QSettings::uf_value() === 0.0);

$GLOBALS['__opts'][QSettings::UF_OPTION] = array( 'value' => 41000.0, 'date' => '2026-09-24', 'source' => 'mindicador' );
$kept = QSettings::refresh_uf_value();
check('31e refresh sin wp_remote_get mantiene caché (no fatal)', $kept['value'] === 41000.0 && $kept['source'] === 'mindicador');

check('31f cron UF schedule/unschedule/refresh no fatal', (function () {
	QSettings::schedule_uf_cron();
	QSettings::unschedule_uf_cron();
	QSettings::cron_refresh();
	return true;
})() );

unset( $GLOBALS['__opts'][QSettings::PROJECTS_OPTION], $GLOBALS['__opts'][QSettings::PROJECT_BRANDING_OPTION] );
$many = array();
for ( $i = 1; $i <= 8; $i++ ) {
	$many[] = array( 'id' => $i, 'url' => 'https://x.cl/' . $i . '.png', 'caption' => 'img' . $i );
}
QSettings::save_project_settings( array( 'gal' => array( 'images' => $many ) ) );
$gal = QSettings::project_settings( 'gal' );
check('31g sanitize_images recorta a 6', count( $gal['images'] ) === 6 && (int) $gal['images'][5]['id'] === 6);
check('31h MAX_PROJECT_IMAGES = 6', QSettings::MAX_PROJECT_IMAGES === 6);
unset( $GLOBALS['__opts'][QSettings::PROJECTS_OPTION], $GLOBALS['__opts'][QSettings::PROJECT_BRANDING_OPTION] );

$noi      = DocumentBuilder::for_quote( 0, array( 'project_slug' => 'sinimagenes' ) );
$noi_html = DocRenderer::render_print( $noi );
check('31i sin imágenes NO emite la sección', strpos( $noi_html, 'IMÁGENES DEL PROYECTO' ) === false && strpos( $noi_html, 'esq-space' ) === false);

$one           = $re_doc;
$one['images'] = array( array( 'url' => 'https://x.cl/uno.png', 'caption' => 'Una' ) );
$one_html      = DocRenderer::render_print( $one );
check('31j con 1 imagen SÍ emite la sección (sin celda vacía)', strpos( $one_html, 'IMÁGENES DEL PROYECTO' ) !== false && strpos( $one_html, 'https://x.cl/uno.png' ) !== false && substr_count( $one_html, '<td style="width:100%;">' ) === 1);

$five           = $re_doc;
$five['images'] = array();
for ( $i = 1; $i <= 5; $i++ ) {
	$five['images'][] = array( 'url' => 'https://x.cl/f' . $i . '.png' );
}
$five_html = DocRenderer::render_print( $five );
check('31k con 5 imágenes reduce alto (adaptativo)', strpos( $five_html, 'max-height:3.8cm' ) !== false);

$GLOBALS['__opts'][QSettings::UF_OPTION] = array( 'value' => 41234.56, 'date' => '2026-09-25', 'source' => 'mindicador' );
unset( $GLOBALS['__opts'][QSettings::OPTION] );
$ufdoc = DocumentBuilder::for_quote( 0, array( 'project_slug' => 'ufproj', 'property' => array( 'total_uf' => 1000 ) ) );
check('31l pricing toma el UF efectivo del SII', (float) ( $ufdoc['pricing']['uf_value_clp'] ?? 0 ) === 41234.56 && ( $ufdoc['pricing']['uf_source'] ?? '' ) === 'mindicador' && ( $ufdoc['pricing']['uf_effective_date'] ?? '' ) === '2026-09-25');
check('31m investment_rows calcula total_clp con el UF efectivo', (float) ( $ufdoc['investment_rows'][0]['total_clp'] ?? 0 ) === 41234560.0);

$hb = $re_doc;
$hb['branding']['project']['header_image_url'] = 'https://x.cl/b.png';
unset( $hb['branding']['company']['color'], $hb['branding']['project']['color'] );
$hb_html = DocRenderer::render_print( $hb );
check('31n overlay del header en NEGRO (no blanco)', strpos( $hb_html, 'esq-banner-overlay' ) !== false && strpos( $hb_html, 'color:#1a1a1a' ) !== false && strpos( $hb_html, 'color:#ffffff' ) === false);

unset( $GLOBALS['__opts'][QSettings::UF_OPTION], $GLOBALS['__opts'][QSettings::PROJECTS_OPTION], $GLOBALS['__opts'][QSettings::PROJECT_BRANDING_OPTION] );

// ── 32. Wizard Proyectos: filtro de leads por proyecto + guard del paso 5 ──
if ( ! class_exists( 'FlowWpdbStub32' ) ) {
	class FlowWpdbStub32 {
		public $prefix     = 'wp_';
		public $last_query = '';
		public function prepare( $query, ...$args ) { $this->last_query = (string) $query; return $query; }
		public function get_var( $query = null ) { return $this->prefix . 'es_leads'; }
		public function get_results( $query = null, $mode = null ) { $this->last_query = (string) $query; return array(); }
		public function esc_like( $t ) { return addcslashes( (string) $t, '_%\\' ); }
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $url ) { $GLOBALS['__redirect32'] = (string) $url; return true; }
}
$GLOBALS['__redirect32'] = '';

$GLOBALS['wpdb'] = new FlowWpdbStub32();
F\Admin::project_leads( '', 200, '' );
$q_no_slug = $GLOBALS['wpdb']->last_query;
check('32a project_leads sin slug NO filtra (sin WHERE)', strpos( $q_no_slug, 'WHERE' ) === false);

F\Admin::project_leads( '', 200, 'acacios' );
$q_slug = $GLOBALS['wpdb']->last_query;
check('32b project_leads con slug filtra WHERE project_slug = %s', strpos( $q_slug, 'WHERE project_slug = %s' ) !== false);

F\Admin::project_leads( 'juan', 200, 'acacios' );
$q_both = $GLOBALS['wpdb']->last_query;
check('32c project_leads con búsqueda + slug combina filtros', strpos( $q_both, 'LIKE' ) !== false && strpos( $q_both, 'project_slug = %s' ) !== false && strpos( $q_both, 'AND' ) !== false);
unset( $GLOBALS['wpdb'] );

$_GET = array( 'page' => 'otra-pantalla', 'tab' => 'proyectos', 'step' => '5', 'lead' => '7' );
F\Admin::maybe_prepare_projects();
check('32d maybe_prepare_projects no actúa si page≠estandar-suite-flow', '' === $GLOBALS['__redirect32'] );

$_GET = array( 'page' => 'estandar-suite-flow', 'tab' => 'proyectos', 'step' => '4', 'lead' => '7' );
F\Admin::maybe_prepare_projects();
check('32e maybe_prepare_projects no actúa si step≠5', '' === $GLOBALS['__redirect32'] );

$_GET = array( 'page' => 'estandar-suite-flow', 'tab' => 'proyectos', 'step' => '5', 'lead' => '7', 'quote' => '9' );
F\Admin::maybe_prepare_projects();
check('32f maybe_prepare_projects no actúa si ya hay quote', '' === $GLOBALS['__redirect32'] );

$_GET = array();
check('32g create_project_lead sin identidad → 0', F\Admin::create_project_lead( array( 'name' => 'X', 'email' => '', 'phone' => '' ) ) === 0);
unset( $GLOBALS['wpdb'] );
check('32h create_project_lead sin DB/Core no fatal → 0', F\Admin::create_project_lead( array( 'name' => 'X', 'email' => 'x@y.cl', 'phone' => '' ) ) === 0);

// ── 33. Galería editable: prefill + images_json + header transparente ──────
unset( $GLOBALS['__opts'][QSettings::PROJECTS_OPTION], $GLOBALS['__opts'][QSettings::PROJECT_BRANDING_OPTION] );
QSettings::save_project_settings( array( 'galpre' => array( 'images' => array(
	array( 'id' => 11, 'url' => 'https://x.cl/g1.png', 'caption' => 'Fachada' ),
	array( 'id' => 12, 'url' => 'https://x.cl/g2.png', 'caption' => 'Living' ),
) ) ) );

$docg = DocumentBuilder::for_quote( 0, array( 'project_slug' => 'galpre' ) );
check('33a for_quote prefija doc.images desde settings del proyecto', isset( $docg['images'] ) && count( $docg['images'] ) === 2 && $docg['images'][0]['url'] === 'https://x.cl/g1.png' && $docg['images'][1]['caption'] === 'Living');

$htmlg = DocRenderer::render_print( $docg );
check('33b render usa doc.images editadas (prioridad sobre branding)', strpos( $htmlg, 'https://x.cl/g1.png' ) !== false && strpos( $htmlg, 'IMÁGENES DEL PROYECTO' ) !== false);

$many_json = array();
for ( $i = 1; $i <= 8; $i++ ) {
	$many_json[] = array( 'id' => $i, 'url' => 'https://x.cl/e' . $i . '.png', 'caption' => 'e' . $i );
}
$ndoc_img = QEditor::normalize_post( array(
	'document_json' => json_encode( array( 'client.name' => 'X' ) ),
	'images_json'   => json_encode( $many_json ),
), 5 );
check('33c normalize_post incorpora images_json a doc.images (máx 6)', isset( $ndoc_img['images'] ) && count( $ndoc_img['images'] ) === 6 && $ndoc_img['images'][5]['url'] === 'https://x.cl/e6.png' && $ndoc_img['images'][0]['caption'] === 'e1');

$ndoc_img_legacy = QEditor::normalize_post( array(
	'document_json' => '{no-json',
	'images_json'   => json_encode( array( array( 'url' => 'https://x.cl/leg.png', 'caption' => 'Leg' ) ) ),
), 6 );
check('33d normalize_post legado también incorpora images_json', isset( $ndoc_img_legacy['images'] ) && count( $ndoc_img_legacy['images'] ) === 1 && $ndoc_img_legacy['images'][0]['url'] === 'https://x.cl/leg.png');

$ndoc_noimg = QEditor::normalize_post( array( 'document_json' => json_encode( array( 'client.name' => 'X' ) ) ), 7 );
check('33e sin images_json no se toca doc.images', ! array_key_exists( 'images', $ndoc_noimg ) );

$canvas_g = QEditor::canvas( $docg, array( 'gallery' => true ) );
check('33f canvas trae control de galería + images_json', strpos( $canvas_g, 'name="images_json"' ) !== false && strpos( $canvas_g, 'esfp-media-gallery-pick' ) !== false && strpos( $canvas_g, 'https://x.cl/g1.png' ) !== false);
check('33f2 canvas sin opt no emite galería (no pisa images)', strpos( QEditor::canvas( $docg ), 'name="images_json"' ) === false);

$doc33 = DocumentBuilder::for_quote( 0, array( 'project_slug' => 'galpre' ) );
$doc33['branding']['company']['name']             = 'Inmobiliaria Acacios';
$doc33['branding']['project']['name']             = 'Altacruz';
$doc33['branding']['project']['header_image_url'] = 'https://x.cl/band.png';
$h33 = DocRenderer::render_print( $doc33 );
check('33g banner con header image → nombres transparentes (texto copiable)', substr_count( $h33, 'color:transparent' ) >= 2 && strpos( $h33, 'Inmobiliaria Acacios' ) !== false && strpos( $h33, 'Altacruz' ) !== false && strpos( $h33, 'display:none' ) === false && strpos( $h33, 'visibility:hidden' ) === false);
check('33h banner conserva meta visible en negro', strpos( $h33, 'esq-banner-overlay' ) !== false && strpos( $h33, 'color:#1a1a1a' ) !== false && strpos( $h33, 'Cotización N°' ) !== false);

// Sin imagen de header: los nombres se muestran normal (no transparentes).
$doc33b = $re_doc;
$doc33b['branding']['company']['name'] = 'Inmobiliaria Acacios';
$doc33b['branding']['project']['name'] = 'Altacruz';
$h33b = DocRenderer::render_print( $doc33b );
check('33i sin header image los nombres NO son transparentes', strpos( $h33b, 'color:transparent' ) === false && strpos( $h33b, 'Inmobiliaria Acacios' ) !== false);

unset( $GLOBALS['__opts'][QSettings::PROJECTS_OPTION], $GLOBALS['__opts'][QSettings::PROJECT_BRANDING_OPTION] );

// ── 34. field_options (selects), "Otro proyecto" y notas/condiciones default ─
unset( $GLOBALS['__opts'][QSettings::OPTION], $GLOBALS['__opts'][QSettings::PROJECTS_OPTION], $GLOBALS['__opts'][QSettings::PROJECT_BRANDING_OPTION] );

$fo_default = QSettings::field_options( '' );
check('34a field_options default pisos 1-3', ( $fo_default['property.floors'] ?? array() ) === array( '1', '2', '3' ));
check('34b field_options default dormitorios 1-4 / baños 1-3', ( $fo_default['property.bedrooms'] ?? array() ) === array( '1', '2', '3', '4' ) && ( $fo_default['property.bathrooms'] ?? array() ) === array( '1', '2', '3' ));
check('34c field_options default bodega/estacionamiento Sí/No', ( $fo_default['property.storage'] ?? array() ) === array( 'Sí', 'No' ) && ( $fo_default['property.parking'] ?? array() ) === array( 'Sí', 'No' ));

QSettings::save_project_entry( 'fop', array( 'field_options' => array(
	'property.floors'    => array( 'PB', '1', '2' ),
	'property.unit_code' => "Casa C-10\nCasa C-11",
) ) );
$fo_proj = QSettings::field_options( 'fop' );
check('34d field_options merge override del proyecto', ( $fo_proj['property.floors'] ?? array() ) === array( 'PB', '1', '2' ));
check('34e field_options conserva defaults no sobreescritos', ( $fo_proj['property.bathrooms'] ?? array() ) === array( '1', '2', '3' ));
check('34f field_options acepta texto multilínea', ( $fo_proj['property.unit_code'] ?? array() ) === array( 'Casa C-10', 'Casa C-11' ));

$many_opts = array();
for ( $i = 1; $i <= 60; $i++ ) { $many_opts[] = 'op' . $i; }
$clean_fo = QSettings::sanitize_field_options( array(
	'property.model' => array_merge( array( '110', '110', '', '120' ), $many_opts ),
	'bad key!'       => array( 'x' ),
) );
check('34g sanitize_field_options dedup/vacíos/límite 50', ( $clean_fo['property.model'] ?? array() ) === array_slice( array_values( array_unique( array_merge( array( '110', '120' ), $many_opts ) ) ), 0, 50 ) && ! isset( $clean_fo['badkey'] ) && ! isset( $clean_fo['bad key!'] ));

$doc_fo = DocumentBuilder::for_quote( 0 );
check('34h for_quote incluye field_options default', ( $doc_fo['field_options']['property.floors'] ?? array() ) === array( '1', '2', '3' ));

$doc_fo2 = DocumentBuilder::for_quote( 0, array( 'project_slug' => 'fop' ) );
check('34i for_quote usa field_options del proyecto', ( $doc_fo2['field_options']['property.floors'] ?? array() ) === array( 'PB', '1', '2' ));

$canvas_doc34 = array(
	'profile'       => 'real_estate@1',
	'meta'          => array( 'currency' => 'CLP', 'quote_number' => 'COT-1' ),
	'project'       => array( 'name' => 'Altacruz' ),
	'property'      => array( 'floors' => '2', 'unit_code' => 'Casa C-10' ),
	'field_options' => array( 'property.floors' => array( '1', '2', '3' ) ),
);
$canvas_fo = QEditor::canvas( $canvas_doc34 );
check('34j canvas emite <select> para campo con opciones', strpos( $canvas_fo, '<select' ) !== false && strpos( $canvas_fo, 'data-qfield="property.floors"' ) !== false);
check('34k select incluye opción vacía "—" y valor actual seleccionado', strpos( $canvas_fo, '<option value="">—</option>' ) !== false && strpos( $canvas_fo, '<option value="2" selected>2</option>' ) !== false);
check('34l project.name siempre es select con "Otro proyecto…"', strpos( $canvas_fo, 'data-qfield="project.name"' ) !== false && strpos( $canvas_fo, 'Otro proyecto' ) !== false && strpos( $canvas_fo, '__otro__' ) !== false);
check('34m project.name precarga el proyecto actual', strpos( $canvas_fo, '<option value="Altacruz" selected>Altacruz</option>' ) !== false);
check('34n JS navega a projects_url al elegir Otro', strpos( $canvas_fo, 'otherProjectUrl' ) !== false && strpos( $canvas_fo, 'goOtherProject' ) !== false);

$canvas_plain34 = QEditor::canvas( array( 'profile' => 'real_estate@1', 'project' => array( 'name' => 'X' ), 'property' => array( 'model' => '110' ) ) );
check('34o campo sin opciones conserva su slot (no select)', strpos( $canvas_plain34, 'data-qfield="property.model"' ) !== false && strpos( $canvas_plain34, 'data-qfield="property.model" data-qtype="text"' ) !== false);

check('34p default_notes y defaults activos', QSettings::default_notes() !== '' && ( QSettings::defaults()['notes'] ?? '' ) === QSettings::default_notes());
$dps_fo = QSettings::default_project_settings();
check('34q default_project_settings data notas/condiciones', ( $dps_fo['data']['notes'] ?? '' ) === QSettings::default_notes() && count( $dps_fo['data']['conditions'] ?? array() ) === 3);
$doc_notes34 = DocumentBuilder::for_quote( 0 );
check('34r for_quote prefija notas y 3 condiciones', ( $doc_notes34['notes'] ?? '' ) === QSettings::default_notes() && count( $doc_notes34['conditions'] ?? array() ) === 3);
$doc_fo3 = DocumentBuilder::for_quote( 0, array( 'project_slug' => 'fop' ) );
check('34t project.name cae al slug cuando no hay nombre', ( (string) ( $doc_fo3['project']['name'] ?? '' ) ) !== '' && ( $doc_fo3['project']['name'] ?? '' ) === 'fop');
check('34s sanitize_project_data restaura defaults si vacío', (function () {
	QSettings::save_project_entry( 'foempty', array( 'data' => array( 'notes' => '', 'conditions' => array() ) ) );
	$s = QSettings::project_settings( 'foempty' );
	return ( $s['data']['notes'] ?? '' ) === QSettings::default_notes() && count( $s['data']['conditions'] ?? array() ) === 3;
})());

// ── 35. Valor de la propiedad (UF): financiamiento + botones del paso 6 ────
check('35a Fields incluye pricing.property_value_uf', isset( DocFields::all()['pricing.property_value_uf'] ) && ( DocFields::all()['pricing.property_value_uf']['group'] ?? '' ) === 'pricing' && ( DocFields::all()['pricing.property_value_uf']['type'] ?? '' ) === 'number');

$fin35      = array( 'credit_percent' => 80, 'annual_rate_percent' => 5, 'income_multiple' => 4, 'terms_years' => 20 );
$fr35_total = Calc::financing_rows( $fin35, 1000.0, 40000.0 );          // base = total
$fr35_prop  = Calc::financing_rows( $fin35, 1000.0, 40000.0, 2000.0 );  // base = property
check('35b financing_rows con base property_value_uf distinta de total_uf', $fr35_total[0]['credit_uf'] === 800.0 && $fr35_prop[0]['credit_uf'] === 1600.0 && $fr35_prop[0]['dividend_uf'] > $fr35_total[0]['dividend_uf']);
check('35c financing_rows usa total_uf como fallback (retrocompatible)', $fr35_total[0]['credit_uf'] === 800.0);

$doc35_fallback = DocumentBuilder::for_quote( 0, array( 'project_slug' => 'pvuf', 'pricing' => array( 'total_uf' => 1500 ) ) );
check('35d for_quote property_value_uf fallback a pricing.total_uf', (float) ( $doc35_fallback['pricing']['property_value_uf'] ?? 0 ) === 1500.0);
$doc35_explicit = DocumentBuilder::for_quote( 0, array( 'project_slug' => 'pvuf', 'pricing' => array( 'property_value_uf' => 2200, 'total_uf' => 1500 ) ) );
check('35e for_quote respeta property_value_uf explícito', (float) ( $doc35_explicit['pricing']['property_value_uf'] ?? 0 ) === 2200.0);

QSettings::save_project_entry( 'pvuf', array( 'data' => array( 'property_value_uf' => '3100' ) ) );
$doc35_settings = DocumentBuilder::for_quote( 0, array( 'project_slug' => 'pvuf', 'pricing' => array( 'total_uf' => 1500 ) ) );
check('35f for_quote prefija property_value_uf desde settings del proyecto', (float) ( $doc35_settings['pricing']['property_value_uf'] ?? 0 ) === 3100.0);
unset( $GLOBALS['__opts'][QSettings::PROJECTS_OPTION] );

$re35 = DocRenderer::render( array(
	'profile'   => 'real_estate@1',
	'pricing'   => array( 'total_uf' => 1000, 'property_value_uf' => 2000, 'uf_value_clp' => 40000 ),
	'financing' => array( 'credit_percent' => 80, 'annual_rate_percent' => 5, 'income_multiple' => 4, 'terms_years' => 20 ),
) );
check('35g render imprime fila "Valor de la propiedad (UF)" editable', strpos( $re35, 'Valor de la propiedad (UF)' ) !== false && strpos( $re35, 'data-qfield="pricing.property_value_uf"' ) !== false);
check('35h nota de financiamiento usa el crédito desde property_value_uf', strpos( $re35, 'Monto Crédito: 1600 U.F.' ) !== false);

$tpl35 = (string) file_get_contents( dirname( __DIR__ ) . '/includes/templates/feature-projects.php' );
check('35i paso 6 incluye botón "Enviar a operador"', strpos( $tpl35, 'Enviar a operador' ) !== false && strpos( $tpl35, 'name="to_operator"' ) !== false);
check('35j paso 6 incluye botón "Descargar" con download=1', strpos( $tpl35, '>Descargar<' ) !== false && strpos( $tpl35, '$download_url' ) !== false);

unset( $GLOBALS['__opts'][QSettings::OPTION], $GLOBALS['__opts'][QSettings::PROJECTS_OPTION], $GLOBALS['__opts'][QSettings::PROJECT_BRANDING_OPTION] );

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
