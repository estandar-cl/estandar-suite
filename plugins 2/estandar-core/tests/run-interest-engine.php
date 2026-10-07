<?php
/**
 * INTEREST EVOLUTION ENGINE — Core (IEE-1 + IEE-7) tests.
 *
 *   php tests/run-interest-engine.php   → expect "0 failed"
 *
 * IEE-7: TimeContext, evaluate_cooling, AttributionCanonical, CaptureCompleteness,
 * 6 synthetic fixtures (AFTER_HOURS_NO_ENGAGEMENT, AFTER_HOURS_HIGH_ENGAGEMENT,
 * ACTIONABLE_UNMANAGED_COOLING, RECENT_ENGAGEMENT_AFTER_WAIT,
 * REDIRECT_REFERRAL_UNKNOWN, PHONE_ONLY_CONTACTABLE).
 *
 * Puro, sin WordPress: define los stubs mínimos de WP y ejercita
 * InterestEngine, InterestSignals, InterestChannel, InterestState,
 * InterestPriority, NextBestAction, InterestEventLog, InterestContext,
 * EngagementEngine, TimeContext, Channels (AttributionCanonical),
 * CaptureCompleteness.
 */

define('ABSPATH', __DIR__);
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_filters'] = array();

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        return array_key_exists($tag, $GLOBALS['__test_filters']) ? $GLOBALS['__test_filters'][$tag] : $value;
    }
}
if (!function_exists('add_filter')) { function add_filter($t, $c, $p = 10, $a = 1) { $GLOBALS['__test_filters'][$t] = is_callable($c) ? $c() : $c; return true; } }
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); } }
if (!function_exists('absint')) { function absint($n) { return abs((int) $n); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) $t); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v); } }
if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
$GLOBALS['__test_wpdb'] = new class() {
    public $prefix = 'wp_';
    public function get_var($q) { return null; }
    public function get_col($q) { return array(); }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
    public function insert($t, $d, $f) { return 0; }
    public function prepare($q) { return $q; }
};
if (!isset($GLOBALS['wpdb'])) { $GLOBALS['wpdb'] = $GLOBALS['__test_wpdb']; }

require dirname(__DIR__) . '/includes/Interest/InterestSignals.php';
require dirname(__DIR__) . '/includes/Interest/InterestChannel.php';
require dirname(__DIR__) . '/includes/Interest/TimeContext.php';
require dirname(__DIR__) . '/includes/Interest/InterestState.php';
require dirname(__DIR__) . '/includes/Interest/InterestPriority.php';
require dirname(__DIR__) . '/includes/Interest/NextBestAction.php';
require dirname(__DIR__) . '/includes/Interest/InterestEngine.php';
require dirname(__DIR__) . '/includes/Interest/InterestEventLog.php';
require dirname(__DIR__) . '/includes/Interest/InterestContext.php';
require dirname(__DIR__) . '/includes/Interest/EngagementEngine.php';
require dirname(__DIR__) . '/includes/Channels.php';
require dirname(__DIR__) . '/includes/Quality/CaptureCompleteness.php';

use Estandar\Core\Interest\InterestEngine;
use Estandar\Core\Interest\InterestSignals;
use Estandar\Core\Interest\InterestChannel;
use Estandar\Core\Interest\TimeContext;
use Estandar\Core\Interest\InterestState;
use Estandar\Core\Interest\InterestPriority;
use Estandar\Core\Interest\NextBestAction;
use Estandar\Core\Interest\InterestEventLog;
use Estandar\Core\Interest\InterestContext;
use Estandar\Core\Interest\EngagementEngine;
use Estandar\Core\Channels;
use Estandar\Core\Quality\CaptureCompleteness;

$passed = 0;
$failed = 0;
function check($name, $ok) {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS  {$name}\n"; }
    else { $failed++; echo "FAIL  {$name}\n"; }
}

echo "═══ IEE-1: InterestSignals (registro, pesos, filtros) ═══════════════════\n";
check('1a peso first_form = 0 (fundación, no suma)', InterestSignals::weight('first_form') === 0);
check('1b peso second_form = 10', InterestSignals::weight('second_form') === 10);
check('1c peso return_visit_after_capture = 25', InterestSignals::weight('return_visit_after_capture') === 25);
check('1d peso quote_created = 30', InterestSignals::weight('quote_created') === 30);
check('1e peso señal desconocida = 0 (nunca error)', InterestSignals::weight('nope') === 0);
check('1f resolved() filtra peso 0 (first_form no aparece)', count(InterestSignals::resolved(array('first_form', 'email_opened'))) === 1);
check('1g resolved() deduplica', count(InterestSignals::resolved(array('email_opened', 'email_opened'))) === 1);
$GLOBALS['__test_filters']['estandar_interest_signal_weight'] = array('email_opened' => 99);
check('1h filtro de pesos por instalación (reemplaza)', InterestSignals::weight('email_opened') === 99);
unset($GLOBALS['__test_filters']['estandar_interest_signal_weight']);

echo "═══ IEE-1: InterestChannel (pesos configurables, neutral default) ════════\n";
check('2a channel desconocido = 1.0 (neutral)', InterestChannel::weight('') === 1.0);
check('2b channel direct = 1.0', InterestChannel::weight('direct') === 1.0);
check('2c channel organic = 0.8', InterestChannel::weight('organic') === 0.8);
check('2d label google_ads = "Google Ads"', InterestChannel::label('google_ads') === 'Google Ads');
$GLOBALS['__test_filters']['estandar_interest_channel_weight'] = array('direct' => 1.5);
check('2e filtro de canal por instalación', InterestChannel::weight('direct') === 1.5);
unset($GLOBALS['__test_filters']['estandar_interest_channel_weight']);

echo "═══ IEE-7: TimeContext (descomposición temporal multi-dimensional) ══════\n";
// Constructor con todas las dimensiones.
$t1 = new TimeContext(array(
    'chronological_age_hours' => 59,
    'operator_actionable_hours' => 0,
    'time_deferred_by_policy_hours' => 59,
    'hours_since_last_engagement' => 30,
    'hours_since_last_operator_action' => 59,
    'hours_since_delivery' => 55,
));
check('t1 chronological = 59', $t1->chronologicalAgeHours() === 59);
check('t1 operator_actionable = 0 (diferido)', $t1->operatorActionableHours() === 0);
check('t1 deferred = 59', $t1->timeDeferredByPolicyHours() === 59);
check('t1 engagement = 30', $t1->hoursSinceLastEngagement() === 30);
check('t1 operator action = 59', $t1->hoursSinceLastOperatorAction() === 59);
check('t1 delivery = 55', $t1->hoursSinceDelivery() === 55);
check('t1 isDeferred = true (chrono>0, actionable=0)', $t1->isDeferred() === true);
check('t1 hasRecentEngagement(6h) = false (30 >= 6)', $t1->hasRecentEngagement(6) === false);

// Constructor con solo chronological (default: todo accionable).
$t2 = new TimeContext(array('chronological_age_hours' => 10));
check('t2 operator_actionable = 10 (default = chronological)', $t2->operatorActionableHours() === 10);
check('t2 deferred = 0', $t2->timeDeferredByPolicyHours() === 0);
check('t2 isDeferred = false', $t2->isDeferred() === false);

// Factory from_elapsed (backward-compat).
$t3 = TimeContext::from_elapsed(72);
check('t3 from_elapsed: operator_actionable = 72 (sin deferral)', $t3->operatorActionableHours() === 72);
check('t3 from_elapsed: isDeferred = false', $t3->isDeferred() === false);

// hasRecentEngagement con engagement reciente.
$t4 = new TimeContext(array(
    'chronological_age_hours' => 50,
    'operator_actionable_hours' => 48,
    'hours_since_last_engagement' => 2,
));
check('t4 hasRecentEngagement(6h) = true (2 < 6)', $t4->hasRecentEngagement(6) === true);
check('t4 isDeferred = false (actionable > 0)', $t4->isDeferred() === false);

// toElapsedCompat devuelve operator_actionable.
check('t1 toElapsedCompat = 0', $t1->toElapsedCompat() === 0);
check('t2 toElapsedCompat = 10', $t2->toElapsedCompat() === 10);

// toArray.
$arr = $t1->toArray();
check('t1 toArray tiene 7 claves', count($arr) === 7);
check('t1 toArray is_deferred = true', $arr['is_deferred'] === true);

echo "═══ IEE-1: InterestState (umbrales + decaimiento) ═══════════════════════\n";
check('3a score 0 → CAPTURE', InterestState::from_score(0) === 'CAPTURE');
check('3b score 9 → CAPTURE', InterestState::from_score(9) === 'CAPTURE');
check('3c score 10 → REVISITED', InterestState::from_score(10) === 'REVISITED');
check('3d score 30 → ENGAGED', InterestState::from_score(30) === 'ENGAGED');
check('3e score 60 → HIGH', InterestState::from_score(60) === 'HIGH');
check('3f CAPTURE nunca decae a COOLING (captura ≠ atraso)', InterestState::apply_decay('CAPTURE', 500, false, 48) === 'CAPTURE');
check('3g ENGAGED + 500h sin gestión → COOLING', InterestState::apply_decay('ENGAGED', 500, false, 48) === 'COOLING');
check('3h ENGAGED + 48h → COOLING (umbral inclusive)', InterestState::apply_decay('ENGAGED', 48, false, 48) === 'COOLING');
check('3i ENGAGED + 47h → ENGAGED', InterestState::apply_decay('ENGAGED', 47, false, 48) === 'ENGAGED');
check('3j ENGAGED + 500h pero con contacto → ENGAGED (gestión registrada)', InterestState::apply_decay('ENGAGED', 500, true, 48) === 'ENGAGED');

echo "═══ IEE-7: InterestState::evaluate_cooling (TimeContext multi-dim) ══════\n";
// Caso A: lead diferido (operator_actionable=0) → no cooling.
$dA = InterestState::evaluate_cooling('ENGAGED', $t1, false, 48, 6);
check('3k diferido: ENGAGED + 59h chrono pero 0h actionable → ENGAGED (sin cooling)', $dA === 'ENGAGED');

// Caso B: lead accionable, sin engagement reciente, supera umbral → cooling.
$tB = new TimeContext(array('chronological_age_hours' => 60, 'operator_actionable_hours' => 55));
$dB = InterestState::evaluate_cooling('ENGAGED', $tB, false, 48, 6);
check('3l ENGAGED + 55h actionable sin engagement → COOLING', $dB === 'COOLING');

// Caso C: engagement reciente suprime cooling aunque operator_actionable > umbral.
$tC = new TimeContext(array(
    'chronological_age_hours' => 60,
    'operator_actionable_hours' => 55,
    'hours_since_last_engagement' => 2,
));
$dC = InterestState::evaluate_cooling('ENGAGED', $tC, false, 48, 6);
check('3m ENGAGED + 55h actionable + engagement 2h → ENGAGED (suprime cooling)', $dC === 'ENGAGED');

// Caso D: CAPTURE nunca cooling aunque esté diferido.
$dD = InterestState::evaluate_cooling('CAPTURE', $t1, false, 48, 6);
check('3n CAPTURE diferido → CAPTURE (nunca cooling)', $dD === 'CAPTURE');

// Caso E: contacto registrado suprime cooling.
$dE = InterestState::evaluate_cooling('ENGAGED', $tB, true, 48, 6);
check('3o ENGAGED + contacto → ENGAGED (sin cooling)', $dE === 'ENGAGED');

// Caso F: operator_actionable justo bajo umbral → sin cooling.
$tF = new TimeContext(array('chronological_age_hours' => 50, 'operator_actionable_hours' => 47));
$dF = InterestState::evaluate_cooling('ENGAGED', $tF, false, 48, 6);
check('3p ENGAGED + 47h actionable → ENGAGED (bajo umbral)', $dF === 'ENGAGED');

// Caso G: backward-compat apply_decay sigue funcionando.
$dG = InterestState::apply_decay('ENGAGED', 100, false, 48);
check('3q apply_decay 100h → COOLING (backward-compat)', $dG === 'COOLING');

echo "═══ IEE-1: InterestPriority (derivada, no hardcode) ═════════════════════\n";
check('4a CAPTURE sin contacto → low', InterestPriority::derive('CAPTURE', false, false) === 'low');
check('4b REVISITED sin contacto → normal', InterestPriority::derive('REVISITED', false, false) === 'normal');
check('4c ENGAGED sin contacto → high', InterestPriority::derive('ENGAGED', false, false) === 'high');
check('4d HIGH sin contacto → high', InterestPriority::derive('HIGH', false, false) === 'high');
check('4e COOLING sin contacto → urgent', InterestPriority::derive('COOLING', false, false) === 'urgent');
check('4f ENGAGED con contacto → low (ya gestionado)', InterestPriority::derive('ENGAGED', true, false) === 'low');
check('4g SLA violado + interés → urgent', InterestPriority::derive('ENGAGED', false, true) === 'urgent');
check('4h SLA violado + CAPTURE → low (captura no es atraso)', InterestPriority::derive('CAPTURE', false, true) === 'low');

echo "═══ IEE-1: NextBestAction ═══════════════════════════════════════════════\n";
check('5a CAPTURE → wait (primer contacto pendiente)', NextBestAction::derive('CAPTURE', true, false, false, false)['action'] === 'wait');
check('5b REVISITED → recontact', NextBestAction::derive('REVISITED', true, false, false, false)['action'] === 'recontact');
check('5c ENGAGED → call_now', NextBestAction::derive('ENGAGED', true, false, false, false)['action'] === 'call_now');
check('5d HIGH → call_now', NextBestAction::derive('HIGH', true, false, false, false)['action'] === 'call_now');
check('5e HIGH + cotización → schedule_visit', NextBestAction::derive('HIGH', true, false, false, true)['action'] === 'schedule_visit');
check('5f COOLING → escalate', NextBestAction::derive('COOLING', true, false, false, false)['action'] === 'escalate');
check('5g contacto + sin clasificar → request_classification', NextBestAction::derive('ENGAGED', true, true, false, false)['action'] === 'request_classification');
check('5h contacto + clasificado → request_classification (resultado)', NextBestAction::derive('ENGAGED', true, true, true, false)['action'] === 'request_classification');

echo "═══ IEE-1: InterestEngine::decide() — comportamiento comercial ══════════\n";
$new = InterestEngine::decide(array(
    'signals' => array('first_form'),
    'submission_count' => 1,
    'operator_assigned' => true,
));
check('6a primer ingreso → absent=false', empty($new['absent']));
check('6b primer ingreso → interest_level CAPTURE', $new['interest_level'] === 'CAPTURE');
check('6c primer ingreso → priority low', $new['priority']['level'] === 'low');
check('6d primer ingreso → NBA wait', $new['next_best_action']['action'] === 'wait');
check('6e primer ingreso → score 0 (first_form no suma)', $new['score'] === 0);
check('6f version IEE_V1', $new['version'] === 'IEE_V1');

$revisited = InterestEngine::decide(array(
    'signals' => array('first_form', 'return_visit_after_capture'),
    'submission_count' => 1,
    'operator_assigned' => true,
));
check('6g return_visit sin formulario → REVISITED', $revisited['interest_level'] === 'REVISITED');
check('6h return_visit → priority normal', $revisited['priority']['level'] === 'normal');
check('6i return_visit → NBA recontact', $revisited['next_best_action']['action'] === 'recontact');

$engaged = InterestEngine::decide(array(
    'signals' => array('first_form', 'email_opened', 'whatsapp_opened'),
    'submission_count' => 1,
    'operator_assigned' => true,
));
check('6j email+whatsapp → ENGAGED (15+20=35 ≥ 30)', $engaged['interest_level'] === 'ENGAGED');
check('6k email+whatsapp → priority high', $engaged['priority']['level'] === 'high');
check('6l email+whatsapp → NBA call_now', $engaged['next_best_action']['action'] === 'call_now');

$high = InterestEngine::decide(array(
    'signals' => array('first_form', 'email_click', 'whatsapp_opened', 'quote_created'),
    'submission_count' => 1,
    'operator_assigned' => true,
));
check('6m quote+clic+wa → HIGH (20+20+30=70 ≥ 60)', $high['interest_level'] === 'HIGH');
check('6n quote+clic+wa → NBA schedule_visit', $high['next_best_action']['action'] === 'schedule_visit');

$cooling = InterestEngine::decide(array(
    'signals' => array('first_form', 'email_opened'),
    'submission_count' => 1,
    'operator_assigned' => true,
    'elapsed_hours' => 100,
    'cooling_hours' => 48,
));
check('6o ENGAGED + 100h sin gestión → COOLING', $cooling['interest_level'] === 'COOLING');
check('6p COOLING → priority urgent', $cooling['priority']['level'] === 'urgent');
check('6q COOLING → NBA escalate', $cooling['next_best_action']['action'] === 'escalate');

$absent = InterestEngine::decide(array('signals' => array(), 'submission_count' => 0));
check('6r sin señales ni ingresos → absent=true (default)', $absent['absent'] === true);

$channel = InterestEngine::decide(array(
    'signals' => array('first_form', 'second_form'),
    'submission_count' => 2,
    'channel' => 'organic',
    'operator_assigned' => true,
));
check('6s canal orgánico aplica multiplicador 0.8 (10*0.8=8 → CAPTURE)', $channel['interest_level'] === 'CAPTURE');
check('6t score refleja multiplicador de canal', $channel['score'] === 8);

echo "═══ IEE-7: InterestEngine con TimeContext multi-dimensional ═════════════\n";
// Escenario: lead ENGAGED, 59h cronológico, pero 0h accionable (diferido).
// NO debe producir cooling.
$tc_deferred = new TimeContext(array(
    'chronological_age_hours' => 59,
    'operator_actionable_hours' => 0,
    'time_deferred_by_policy_hours' => 59,
    'hours_since_last_engagement' => 55,
));
$d_deferred = InterestEngine::decide(array(
    'signals' => array('first_form', 'email_opened', 'whatsapp_opened'),
    'submission_count' => 1,
    'operator_assigned' => true,
    'time_context' => $tc_deferred,
    'cooling_hours' => 48,
));
check('6u ENGAGED diferido 59h → ENGAGED (time_context impide cooling)', $d_deferred['interest_level'] === 'ENGAGED');
check('6v decision incluye time en output', isset($d_deferred['time']) && $d_deferred['time']['is_deferred'] === true);

// Escenario: lead ENGAGED, 55h accionable, engagement reciente 2h.
$tc_engaged = new TimeContext(array(
    'chronological_age_hours' => 60,
    'operator_actionable_hours' => 55,
    'hours_since_last_engagement' => 2,
));
$d_engaged_recent = InterestEngine::decide(array(
    'signals' => array('first_form', 'email_opened', 'whatsapp_opened'),
    'submission_count' => 1,
    'operator_assigned' => true,
    'time_context' => $tc_engaged,
    'cooling_hours' => 48,
));
check('6w ENGAGED + 55h actionable + engagement 2h → ENGAGED', $d_engaged_recent['interest_level'] === 'ENGAGED');

echo "═══ IEE-1: narratives (sin lógica en template) ═════════════════════════\n";
$n_new = InterestEngine::narratives($new);
check('7a narrative primer ingreso: headline exacto', $n_new['headline'] === 'Nuevo prospecto recibido.');
check('7b narrative primer ingreso: "Operador asignado."', $n_new['status'] === 'Operador asignado.');
check('7c narrative primer ingreso: "Primer contacto pendiente."', $n_new['pending'] === 'Primer contacto pendiente.');
check('7d narrative primer ingreso: sin evolución', count($n_new['evolution']) === 0);
$n_rev = InterestEngine::narratives($revisited);
check('7e narrative revisited: headline evolución', $n_rev['headline'] === 'El prospecto volvió a interesarse.');
check('7f narrative revisited: pendiente = NBA label', $n_rev['pending'] === 'Recontactar');

echo "═══ IEE-1: InterestEventLog (idempotencia, sin DB) ══════════════════════\n";
check('8a clave de idempotencia estable', InterestEventLog::idempotency_key('lead', 1, 'email_opened', 'submission:5') === 'interest:v1|lead|1|email_opened|submission5');
check('8b idempotency con scope vacío', InterestEventLog::idempotency_key('lead', 7, 'return_visit_after_capture') === 'interest:v1|lead|7|return_visit_after_capture');
check('8c record sin tabla → 0 (degrade sin romper)', InterestEventLog::record('lead', 1, 'email_opened', array()) === 0);
check('8d signal_ids sin tabla → array vacío', InterestEventLog::signal_ids('lead', 1) === array());

echo "═══ IEE-2: InterestContext (traducción, sin reglas de negocio) ══════════\n";
check('9a 1 ingreso → first_form', InterestContext::to_signals(array('submission_count' => 1)) === array('first_form'));
check('9b 2 ingresos → first+second', InterestContext::to_signals(array('submission_count' => 2)) === array('first_form', 'second_form'));
check('9c 3 ingresos → first+second+third', InterestContext::to_signals(array('submission_count' => 3)) === array('first_form', 'second_form', 'third_form'));
check('9d return_visit → señal dedicada', in_array('return_visit_after_capture', InterestContext::to_signals(array('submission_count' => 1, 'return_visit_after_capture' => true)), true));
check('9e email_opened traduce a email_opened', in_array('email_opened', InterestContext::to_signals(array('submission_count' => 1, 'behavior' => array('email_opened' => true))), true));
check('9f page_views 2 → page_depth_low', in_array('page_depth_low', InterestContext::to_signals(array('submission_count' => 1, 'behavior' => array('page_views' => 2))), true));
check('9g page_views 7 → page_depth_high', in_array('page_depth_high', InterestContext::to_signals(array('submission_count' => 1, 'behavior' => array('page_views' => 7))), true));
check('9h 2º ingreso por google_ads → ads_return', in_array('ads_return', InterestContext::to_signals(array('submission_count' => 2, 'channel' => 'google_ads')), true));
check('9i 1er ingreso por google_ads → NO ads_return (no es regreso)', ! in_array('ads_return', InterestContext::to_signals(array('submission_count' => 1, 'channel' => 'google_ads')), true));
check('9j 2º ingreso directo → direct_return', in_array('direct_return', InterestContext::to_signals(array('submission_count' => 2, 'channel' => 'direct')), true));
// IEE-7: referral NO se mapea automáticamente a direct_return.
check('9k IEE-7: 2º ingreso referral → NO direct_return (sin evidencia)', ! in_array('direct_return', InterestContext::to_signals(array('submission_count' => 2, 'channel' => 'referral')), true));
check('9l IEE-7: referral no genera ads_return', ! in_array('ads_return', InterestContext::to_signals(array('submission_count' => 2, 'channel' => 'referral')), true));
check('9m build() produce contexto para decide()', InterestEngine::decide(InterestContext::build(array(
    'submission_count' => 2,
    'channel' => 'google_ads',
    'behavior' => array('email_opened' => true, 'page_views' => 3),
    'operator_assigned' => true,
)))['interest_level'] === 'ENGAGED');
// IEE-7: build() con TimeContext.
$built_tc = InterestContext::build(array(
    'submission_count' => 2,
    'channel' => 'google_ads',
    'behavior' => array('email_opened' => true),
    'operator_assigned' => true,
    'operator_actionable_hours' => 10,
    'time_deferred_by_policy_hours' => 40,
    'hours_since_last_engagement' => 3,
));
check('9n build() incluye time_context', isset($built_tc['time_context']) && $built_tc['time_context'] instanceof TimeContext);
check('9o build() elapsed_hours = operator_actionable (compat)', $built_tc['elapsed_hours'] === 10);
check('9p build() decide con time_context no falla', InterestEngine::decide($built_tc)['interest_level'] === 'ENGAGED');

echo "═══ IEE-6: EngagementEngine (configurable, sin negocio hardcodeado) ═════\n";
$GLOBALS['__test_filters']['estandar_suite_engagement_rules'] = array(
    'default' => array(
        'enabled' => true,
        'interval_hours' => 24,
        'min_interest' => 'REVISITED',
        'conditions' => array(array('field' => 'contact_registered', 'op' => 'is_false')),
        'actions' => array(array('type' => 'email', 'template' => 'recontact')),
    ),
    'acacios' => array(
        'actions' => array(array('type' => 'visit', 'template' => 'casa_piloto')),
    ),
    'ak-contadores' => array(
        'actions' => array(array('type' => 'meeting', 'template' => 'agendar_reunion')),
    ),
    'off' => array('enabled' => false),
);
$decision_rev = InterestEngine::decide(array('signals' => array('first_form', 'second_form'), 'submission_count' => 2, 'operator_assigned' => true));
check('10a nivel REVISITED alcanza min REVISITED → acciona', count(EngagementEngine::evaluate('default', $decision_rev, array('elapsed_hours' => 48))) > 0);
check('10b acción default = email recontact', EngagementEngine::evaluate('default', $decision_rev, array('elapsed_hours' => 48))[0]['template'] === 'recontact');
check('10c perfil acacios propone visita (casa piloto)', EngagementEngine::evaluate('acacios', $decision_rev, array('elapsed_hours' => 48))[0]['template'] === 'casa_piloto');
check('10d perfil AK propone reunión', EngagementEngine::evaluate('ak-contadores', $decision_rev, array('elapsed_hours' => 48))[0]['template'] === 'agendar_reunion');
check('10e perfil deshabilitado → sin acciones', EngagementEngine::evaluate('off', $decision_rev, array('elapsed_hours' => 48)) === array());
check('10f nivel CAPTURE no alcanza REVISITED → sin acciones', EngagementEngine::evaluate('default', InterestEngine::decide(array('signals' => array('first_form'), 'submission_count' => 1, 'operator_assigned' => true)), array('elapsed_hours' => 48)) === array());
check('10g condición contact_registered=false bloquea si hay contacto', EngagementEngine::evaluate('default', $decision_rev, array('elapsed_hours' => 48, 'contact_registered' => true)) === array());
check('10h intervalo 24h no vencido → sin acciones', EngagementEngine::evaluate('default', $decision_rev, array('elapsed_hours' => 5)) === array());
unset($GLOBALS['__test_filters']['estandar_suite_engagement_rules']);

echo "═══ IEE-7 FIXTURE 1: AFTER_HOURS_NO_ENGAGEMENT ═════════════════════════\n";
// Lead capturado fuera de horario (viernes 10 PM, lunes 9 AM = 59h chrono).
// Notificación diferida hasta apertura. El operador aún no puede actuar.
// Expected: NO cooling generado únicamente por tiempo diferido.
// operator_actionable_hours = 0 (no ha sido entregado aún).
$f1_tc = new TimeContext(array(
    'chronological_age_hours' => 59,
    'operator_actionable_hours' => 0,
    'time_deferred_by_policy_hours' => 59,
    'hours_since_last_engagement' => 59,
    'hours_since_last_operator_action' => 59,
));
$f1 = InterestEngine::decide(array(
    'signals' => array('first_form'),
    'submission_count' => 1,
    'channel' => 'google_ads',
    'operator_assigned' => true,
    'time_context' => $f1_tc,
    'cooling_hours' => 48,
));
check('F1a interest_level = CAPTURE (nueva captura)', $f1['interest_level'] === 'CAPTURE');
check('F1b NO cooling (CAPTURE nunca es COOLING)', $f1['interest_level'] !== 'COOLING');
check('F1c priority = low (sin evolución)', $f1['priority']['level'] === 'low');
check('F1d time.is_deferred = true', isset($f1['time']) && $f1['time']['is_deferred'] === true);
check('F1e time.operator_actionable_hours = 0', isset($f1['time']) && $f1['time']['operator_actionable_hours'] === 0);

// Mismo lead pero con REVISITED (return_visit): el interés existe pero
// el operador sigue sin poder actuar porque está diferido.
$f1b = InterestEngine::decide(array(
    'signals' => array('first_form', 'return_visit_after_capture'),
    'submission_count' => 1,
    'operator_assigned' => true,
    'time_context' => $f1_tc,
    'cooling_hours' => 48,
));
check('F1f REVISITED diferido → REVISITED (sin cooling por deferral)', $f1b['interest_level'] === 'REVISITED');
check('F1g REVISITED diferido → priority normal (no escala)', $f1b['priority']['level'] === 'normal');

echo "═══ IEE-7 FIXTURE 2: AFTER_HOURS_HIGH_ENGAGEMENT ════════════════════════\n";
// Lead fuera de horario con interacción positiva (CTA click) antes de que
// el operador pueda actuar.
// Expected: engagement positivo; operator_actionable_age=0 mientras siga
// diferido; no falso cooling; el engagement se registra independientemente.
$f2_tc = new TimeContext(array(
    'chronological_age_hours' => 15,
    'operator_actionable_hours' => 0,
    'time_deferred_by_policy_hours' => 15,
    'hours_since_last_engagement' => 1,  // CTA click hace 1h
    'hours_since_last_operator_action' => 15,
));
$f2 = InterestEngine::decide(array(
    'signals' => array('first_form', 'email_click'),
    'submission_count' => 1,
    'operator_assigned' => true,
    'time_context' => $f2_tc,
    'has_positive_engagement' => true,
    'cooling_hours' => 48,
));
check('F2a interest_level = ENGAGED (email_click 20 ≥ 30? no, 20 solo → REVISITED)', $f2['interest_level'] === 'REVISITED');
check('F2b NO cooling (diferido, engagement reciente 1h)', $f2['interest_level'] !== 'COOLING');
check('F2c engagement.positive = true', isset($f2['engagement']) && $f2['engagement']['positive'] === true);
check('F2d time.operator_actionable_hours = 0', isset($f2['time']) && $f2['time']['operator_actionable_hours'] === 0);
check('F2e hasRecentEngagement = true (1h < 6h)', $f2_tc->hasRecentEngagement(6) === true);

// Mismo escenario con señales suficientes para ENGAGED.
$f2b_tc = new TimeContext(array(
    'chronological_age_hours' => 15,
    'operator_actionable_hours' => 0,
    'time_deferred_by_policy_hours' => 15,
    'hours_since_last_engagement' => 1,
));
$f2b = InterestEngine::decide(array(
    'signals' => array('first_form', 'email_opened', 'whatsapp_opened'),
    'submission_count' => 1,
    'operator_assigned' => true,
    'time_context' => $f2b_tc,
    'has_positive_engagement' => true,
    'cooling_hours' => 48,
));
check('F2f ENGAGED diferido con engagement → ENGAGED (sin cooling)', $f2b['interest_level'] === 'ENGAGED');
check('F2g priority = high (ENGAGED sin contacto)', $f2b['priority']['level'] === 'high');

echo "═══ IEE-7 FIXTURE 3: ACTIONABLE_UNMANAGED_COOLING ═══════════════════════\n";
// Lead entregado al operador dentro de horario, transcurre el umbral
// configurable (48h) sin gestión ni nueva interacción del prospecto.
// Expected: cooling/recovery según política.
$f3_tc = new TimeContext(array(
    'chronological_age_hours' => 50,
    'operator_actionable_hours' => 50,
    'time_deferred_by_policy_hours' => 0,
    'hours_since_last_engagement' => 50,
    'hours_since_last_operator_action' => 50,
));
$f3 = InterestEngine::decide(array(
    'signals' => array('first_form', 'email_opened', 'whatsapp_opened'),
    'submission_count' => 1,
    'operator_assigned' => true,
    'time_context' => $f3_tc,
    'cooling_hours' => 48,
));
check('F3a ENGAGED + 50h actionable sin gestión → COOLING', $f3['interest_level'] === 'COOLING');
check('F3b priority = urgent', $f3['priority']['level'] === 'urgent');
check('F3c NBA = escalate', $f3['next_best_action']['action'] === 'escalate');
check('F3d time.deferred = 0 (sin deferral)', isset($f3['time']) && $f3['time']['time_deferred_by_policy_hours'] === 0);
check('F3e NO hay engagement positivo (sin señales recientes)', ! isset($f3['engagement']));

echo "═══ IEE-7 FIXTURE 4: RECENT_ENGAGEMENT_AFTER_WAIT ═══════════════════════\n";
// Lead accionable con antigüedad y nueva interacción explícita del prospecto.
// Expected: recalcular cooling según política sin borrar historial.
// El engagement reciente (CTA click hace 1h) suprime cooling aunque
// operator_actionable_hours es alto.
$f4_tc = new TimeContext(array(
    'chronological_age_hours' => 55,
    'operator_actionable_hours' => 55,
    'time_deferred_by_policy_hours' => 0,
    'hours_since_last_engagement' => 1,  // CTA click hace 1h
    'hours_since_last_operator_action' => 55,
));
$f4 = InterestEngine::decide(array(
    'signals' => array('first_form', 'email_opened', 'chatbot_start'),
    'submission_count' => 1,
    'operator_assigned' => true,
    'time_context' => $f4_tc,
    'has_positive_engagement' => true,
    'cooling_hours' => 48,
));
check('F4a ENGAGED + 55h actionable pero engagement 1h → ENGAGED (suprime cooling)', $f4['interest_level'] === 'ENGAGED');
check('F4b NO es COOLING (engagement reciente protege)', $f4['interest_level'] !== 'COOLING');
check('F4c priority = high (ENGAGED sin contacto)', $f4['priority']['level'] === 'high');
check('F4d engagement registrado en output', isset($f4['engagement']) && $f4['engagement']['positive'] === true);
check('F4e time chrono = 55h (historial preservado)', isset($f4['time']) && $f4['time']['chronological_age_hours'] === 55);
check('F4f time engagement = 1h', isset($f4['time']) && $f4['time']['hours_since_last_engagement'] === 1);

echo "═══ IEE-7 FIXTURE 5: REDIRECT_REFERRAL_UNKNOWN ═════════════════════════\n";
// Referrer técnico/de redirección sin evidencia suficiente del canal original.
// Expected: preservar raw_referrer y NO inventar canonical_channel.
$f5_attr = array(
    'raw_referrer' => 'https://redirect.example.com/campaign/unknown',
    'raw_source' => '',
    'raw_medium' => '',
);
$f5_canon = Channels::canonicalize($f5_attr);
check('F5a raw_referrer preservado', $f5_canon['raw_referrer'] === 'https://redirect.example.com/campaign/unknown');
check('F5b canonical_channel vacío (sin evidencia)', $f5_canon['canonical_channel'] === '');
check('F5c attribution_confidence = 0.0', $f5_canon['attribution_confidence'] === 0.0);
check('F5d attribution_reason = referrer_insufficient', $f5_canon['attribution_reason'] === 'referrer_insufficient');

// Caso con click ID: debe resolverse con alta confianza.
$f5b_attr = array(
    'raw_referrer' => 'https://google.com/',
    'raw_source' => '',
    'raw_medium' => '',
    'gclid' => 'abc123',
);
$f5b_canon = Channels::canonicalize($f5b_attr);
check('F5e con gclid → canonical_channel = google_ads', $f5b_canon['canonical_channel'] === 'google_ads');
check('F5f con gclid → confidence = 0.95', $f5b_canon['attribution_confidence'] === 0.95);
check('F5g con gclid → reason = google_click_id_present', $f5b_canon['attribution_reason'] === 'google_click_id_present');

// Caso con UTM source+medium: confianza media.
$f5c_attr = array(
    'raw_referrer' => '',
    'raw_source' => 'google',
    'raw_medium' => 'cpc',
);
$f5c_canon = Channels::canonicalize($f5c_attr);
check('F5h google/cpc → google_ads', $f5c_canon['canonical_channel'] === 'google_ads');
check('F5i google/cpc → confidence 0.7', $f5c_canon['attribution_confidence'] === 0.7);
check('F5j raw_source/raw_medium preservados', $f5c_canon['raw_source'] === 'google' && $f5c_canon['raw_medium'] === 'cpc');

// Caso UTM source solo (sin medium): confianza baja.
$f5d_attr = array(
    'raw_referrer' => '',
    'raw_source' => 'facebook',
    'raw_medium' => '',
);
$f5d_canon = Channels::canonicalize($f5d_attr);
check('F5k facebook sin medium → meta_ads (confianza baja)', $f5d_canon['canonical_channel'] === 'meta_ads');
check('F5l confidence = 0.4', $f5d_canon['attribution_confidence'] === 0.4);

echo "═══ IEE-7 FIXTURE 6: PHONE_ONLY_CONTACTABLE ════════════════════════════\n";
// Lead sin email pero con teléfono utilizable.
// Expected: capture completeness refleja ausencia del email sin inferir
// automáticamente LOW commercial quality.
$f6 = CaptureCompleteness::evaluate(array(
    'email' => '',
    'phone' => '+56912345678',
    'email_valid' => false,
    'phone_valid' => true,
    'name' => 'Prospecto Test',
));
check('F6a has_usable_contact = true (teléfono disponible)', $f6['has_usable_contact'] === true);
check('F6b completeness_label = partial_usable', $f6['completeness_label'] === 'partial_usable');
check('F6c missing_channels = [email]', $f6['missing_channels'] === array('email'));
check('F6d available_channels = [phone]', $f6['available_channels'] === array('phone'));
check('F6e completeness_score = 0.5 (1 de 2)', $f6['completeness_score'] === 0.5);

// Caso completo: ambos canales.
$f6b = CaptureCompleteness::evaluate(array(
    'email' => 'test@example.com',
    'phone' => '+56912345678',
    'email_valid' => true,
    'phone_valid' => true,
));
check('F6f ambos canales → complete', $f6b['completeness_label'] === 'complete');
check('F6g completeness_score = 1.0', $f6b['completeness_score'] === 1.0);
check('F6h sin missing_channels', count($f6b['missing_channels']) === 0);

// Caso: sin datos contactables.
$f6c = CaptureCompleteness::evaluate(array(
    'email' => '',
    'phone' => '',
    'email_valid' => false,
    'phone_valid' => false,
));
check('F6i sin datos → unusable', $f6c['completeness_label'] === 'unusable');
check('F6j has_usable_contact = false', $f6c['has_usable_contact'] === false);

// Caso: email proporcionado pero no válido, teléfono válido.
// NO debe inferir baja calidad automáticamente.
$f6d = CaptureCompleteness::evaluate(array(
    'email' => 'invalido',
    'phone' => '+56912345678',
    'email_valid' => false,
    'phone_valid' => true,
));
check('F6k email inválido + phone válido → partial_usable', $f6d['completeness_label'] === 'partial_usable');
check('F6l tiene teléfono usable', $f6d['has_usable_contact'] === true);

echo "═══ IEE-7: Channels::canonicalize (casos borde) ═════════════════════════\n";
// Sin datos.
$c0 = Channels::canonicalize(array());
check('C1 sin datos → canonical vacío', $c0['canonical_channel'] === '');
check('C2 sin datos → confidence 0', $c0['attribution_confidence'] === 0.0);
check('C3 sin datos → reason no_evidence', $c0['attribution_reason'] === 'no_evidence');

// Solo referrer sin UTM ni click IDs.
$c1 = Channels::canonicalize(array('raw_referrer' => 'https://t.co/abc'));
check('C4 t.co referrer solo → canonical vacío', $c1['canonical_channel'] === '');
check('C5 t.co referrer → reason referrer_insufficient', $c1['attribution_reason'] === 'referrer_insufficient');

// Meta fbclid tiene prioridad sobre UTM.
$c2 = Channels::canonicalize(array('raw_source' => 'google', 'raw_medium' => 'cpc', 'fbclid' => 'fb123'));
check('C6 fbclid prevalece sobre UTM google → meta_ads', $c2['canonical_channel'] === 'meta_ads');
check('C7 fbclid → confidence 0.95', $c2['attribution_confidence'] === 0.95);
check('C8 raw_source/medium preservados', $c2['raw_source'] === 'google' && $c2['raw_medium'] === 'cpc');

// gclid + fbclid: gclid se evalúa primero.
$c3 = Channels::canonicalize(array('gclid' => 'g123', 'fbclid' => 'fb456'));
check('C9 gclid + fbclid → google_ads (primero en evaluación)', $c3['canonical_channel'] === 'google_ads');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
