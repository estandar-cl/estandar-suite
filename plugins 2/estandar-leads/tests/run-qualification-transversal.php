<?php
/**
 * COMMERCIAL_QUALIFICATION transversal suite — prueba de generalidad.
 * Run: php tests/run-qualification-transversal.php
 *
 * Demuestra que el engine no contiene hardcoding de rubros/campos/slugs:
 * el MISMO engine califica tres rubros inventados con vocabularios de
 * campos distintos, solo cambiando configuración declarativa.
 *
 * Cubre además: acciones independientes commercial_fit / ads_feedback ·
 * aliases cortos de acciones (routing/notification/autoresponse/ads_feedback)
 * · precedencia por fuente de datos (estructurado > contexto persistido >
 * claves custom > texto libre como fallback) · defaults backward-compatible
 * · manual override con las acciones nuevas · escaneo anti-hardcoding del
 * código fuente del engine y el loader.
 */

define('ABSPATH', __DIR__ . '/');

function absint($v) { return abs((int) $v); }
function sanitize_text_field($v) { return is_scalar($v) ? trim((string) $v) : ''; }
function remove_accents($v) { return strtr((string) $v, array('á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n')); }
function add_action(...$a) {}
function do_action(...$a) {}
function apply_filters($tag, $value, ...$a) { return $GLOBALS['__filters'][$tag] ?? $value; }
function get_option($key, $default = false) { return $GLOBALS['__options'][$key] ?? $default; }

class Estandar_Leads_Normalizer {
    public static function email($v) { return strtolower(trim((string) $v)); }
    public static function phone_storage($v) { return preg_replace('/\D+/', '', (string) $v); }
}
class Estandar_Leads_Projects {
    public static function resolve_slug($slug, $fallback = '') {
        $slug = strtolower(trim((string) $slug));
        return $slug !== '' ? $slug : strtolower(trim((string) $fallback));
    }
    public static function registry() { return array(); }
}

require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-rules.php';
require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-engine.php';

$failed = 0;
function check($label, $ok) {
    global $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

$E = 'Estandar_Commercial_Qualification_Engine';
$R = 'Estandar_Commercial_Qualification_Rules';

function payload_for(string $project, array $fields, array $overrides = array()): array {
    return array_replace_recursive(array(
        'schema_version' => '1.0',
        'source_plugin' => 'estandar-forms',
        'contact' => array('name' => 'Persona Prueba', 'email' => 'p@example.test', 'phone' => '+56 9 5555 1234'),
        'content' => array('message' => ''),
        'context' => array('project_slug' => $project, 'project_area' => '', 'intent' => 'lead_capture'),
        'attribution' => array('utm_source' => '', 'utm_medium' => ''),
        'scoring' => array(),
        'chatbot' => array(),
        'meta' => array('sanitized_raw_fields' => $fields),
    ), $overrides);
}

/* ── 0 · Anti-hardcoding: el código fuente no conoce ningún negocio ───────── */
echo "0 · Anti-hardcoding (escaneo de fuentes)\n";
$sources = file_get_contents(dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-engine.php')
    . file_get_contents(dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-rules.php');
$forbidden = array(
    'financiamiento_subsidio', 'orientacion_emprender', 'ak-contadores', 'ak_contadores',
    'acacios', 'altacruz', 'parronales', 'contabilidad', 'inmobiliaria',
    'necesidad_principal', 'tipo_propiedad', 'dormitorios', 'especialidad',
);
$leaks = array();
foreach ($forbidden as $token) {
    if (stripos($sources, $token) !== false) {
        $leaks[] = $token;
    }
}
check('cero tokens de negocio en engine+loader (' . count($forbidden) . ' escaneados)', $leaks === array());

/* ── 1 · Rubro A — inmobiliario genérico (campos propios del rubro) ───────── */
echo "1 · Rubro A: proyecto habitacional, campos propios\n";
$GLOBALS['__filters'] = array();
$GLOBALS['__options'] = array($R::OPTION => array(
    'proyecto-lomas' => array(
        array(
            'id' => 'a-presupuesto-bajo', 'priority' => 10,
            'conditions' => array(array('field' => 'fields.rango_presupuesto', 'op' => 'lt', 'value' => 2000)),
            'action' => array(
                'decision' => 'review', 'commercial_fit' => 'below_threshold',
                'ads_feedback' => 'skip', 'reason' => 'presupuesto_bajo_umbral',
            ),
        ),
        array(
            'id' => 'a-unidad-premium', 'priority' => 5,
            'conditions' => array(
                array('field' => 'fields.tipo_unidad', 'op' => 'in', 'value' => 'casa-aislada,penthouse'),
                array('field' => 'fields.rango_presupuesto', 'op' => 'gte', 'value' => 8000),
            ),
            'action' => array(
                'qualification' => 'high_value', 'commercial_fit' => 'premium',
                'quality' => 'HOT', 'sales_stage' => 'visit_candidate', 'confidence' => 90,
            ),
        ),
    ),
));

$d = $E::qualify(payload_for('proyecto-lomas', array('tipo_unidad' => 'Penthouse', 'rango_presupuesto' => '9500')));
check('A premium: qualified high_value', $d['decision'] === 'qualified' && $d['qualification'] === 'high_value');
check('A premium: commercial_fit',       $d['commercial_fit'] === 'premium');
check('A premium: quality/sales_stage',  $d['quality'] === 'HOT' && $d['sales_stage'] === 'visit_candidate');
check('A premium: ads_feedback allow (default qualified)', $d['ads_feedback_policy'] === 'allow');
check('A premium: confidence de regla',  $d['confidence'] === 90);

$d = $E::qualify(payload_for('proyecto-lomas', array('tipo_unidad' => 'depto', 'rango_presupuesto' => '1500')));
check('A bajo umbral: review',           $d['decision'] === 'review' && $d['commercial_fit'] === 'below_threshold');
check('A bajo umbral: alias ads_feedback→skip', $d['ads_feedback_policy'] === 'skip');
check('A bajo umbral: routing review',   $d['routing_policy'] === 'review' && $d['notification_policy'] === 'skip');

/* ── 2 · Rubro B — servicios profesionales (otro vocabulario, mismo engine) ── */
echo "2 · Rubro B: servicios profesionales, campos distintos\n";
$GLOBALS['__options'] = array($R::OPTION => array(
    'estudio-sur' => array(
        array(
            'id' => 'b-derivar-externo', 'priority' => 10,
            'conditions' => array(array('field' => 'fields.materia_consulta', 'op' => 'not_in', 'value' => 'societario,tributario,laboral')),
            'action' => array(
                'decision' => 'disqualified', 'invalid_reason' => 'fuera_de_practica',
                'commercial_fit' => 'out_of_scope',
            ),
        ),
    ),
));

$d = $E::qualify(payload_for('estudio-sur', array('materia_consulta' => 'penal')));
check('B fuera de práctica: disqualified',  $d['decision'] === 'disqualified' && $d['invalid_reason'] === 'fuera_de_practica');
check('B fuera de práctica: ads_feedback skip por defecto de clase', $d['ads_feedback_policy'] === 'skip');
check('B fuera de práctica: commercial_fit', $d['commercial_fit'] === 'out_of_scope');
$d = $E::qualify(payload_for('estudio-sur', array('materia_consulta' => 'Tributario')));
check('B en práctica: default qualified',   $d['decision'] === 'qualified' && $d['ads_feedback_policy'] === 'allow');

/* ── 3 · Rubro C — salud (tercer vocabulario) + aliases cortos de acción ───── */
echo "3 · Rubro C: salud, aliases cortos routing/notification/autoresponse\n";
$GLOBALS['__options'] = array($R::OPTION => array(
    'centro-andes' => array(
        array(
            'id' => 'c-urgencia', 'priority' => 1,
            'conditions' => array(array('field' => 'fields.nivel_urgencia', 'op' => 'equals', 'value' => 'inmediata')),
            'action' => array(
                'routing' => 'route', 'notification' => 'notify', 'autoresponse' => 'skip',
                'sales_stage' => 'fast_track', 'reason' => 'urgencia_inmediata',
            ),
        ),
    ),
));
$d = $E::qualify(payload_for('centro-andes', array('nivel_urgencia' => 'Inmediata', 'prevision_paciente' => 'x')));
check('C alias routing/notification',    $d['routing_policy'] === 'route' && $d['notification_policy'] === 'notify');
check('C alias autoresponse→skip',       $d['autoresponse_policy'] === 'skip');
check('C sales_stage',                   $d['sales_stage'] === 'fast_track');
check('C sigue qualified',               $d['decision'] === 'qualified');

/* ── 4 · Precedencia por fuente de datos (tiers) ───────────────────────────── */
echo "4 · Precedencia: estructurado > contexto > custom > texto libre\n";
check('tier fields.* = 1',      $R::field_tier('fields.cualquier_campo') === 1);
check('tier contact.* = 1',     $R::field_tier('contact.email') === 1);
check('tier context.* = 2',     $R::field_tier('context.intent') === 2);
check('tier attribution.* = 2', $R::field_tier('attribution.utm_source') === 2);
check('tier custom = 3',        $R::field_tier('clave_extension_rara') === 3);
check('tier content.* = 4',     $R::field_tier('content.message') === 4);
check('tier message = 4',       $R::field_tier('message') === 4);
check('regla mixta hereda el tier más débil',
    $R::rule_data_tier(array('conditions' => array(
        array('field' => 'fields.x'), array('field' => 'message'),
    ))) === 4);

// Texto libre con prioridad 1 NO puede ganarle a estructurado con prioridad 99.
$GLOBALS['__options'] = array($R::OPTION => array(
    'global' => array(
        array(
            'id' => 'texto-agresivo', 'priority' => 1,
            'conditions' => array(array('field' => 'message', 'op' => 'contains', 'value' => 'urgente')),
            'action' => array('quality' => 'from_free_text', 'commercial_fit' => 'text_guess'),
        ),
        array(
            'id' => 'estructurado-tardio', 'priority' => 99,
            'conditions' => array(array('field' => 'fields.segmento_declarado', 'op' => 'equals', 'value' => 'corporativo')),
            'action' => array('quality' => 'from_structured'),
        ),
    ),
));
$d = $E::qualify(payload_for('cualquier-proyecto', array('segmento_declarado' => 'corporativo'), array(
    'content' => array('message' => 'es URGENTE por favor'),
)));
check('estructurado gana aunque su priority sea peor', $d['quality'] === 'from_structured');
check('texto libre solo rellena claves libres (fallback)', $d['commercial_fit'] === 'text_guess');
check('ambas reglas matchearon, orden por tier', $d['matched_rules'] === array('estructurado-tardio', 'texto-agresivo'));

// Contexto persistido gana sobre texto libre, pierde contra estructurado.
$rules = $R::sort_rules(array(
    $R::normalize_rule(array('id' => 'r-texto', 'priority' => 1,
        'conditions' => array(array('field' => 'content.message', 'op' => 'not_empty'))), 'global'),
    $R::normalize_rule(array('id' => 'r-contexto', 'priority' => 50,
        'conditions' => array(array('field' => 'context.intent', 'op' => 'not_empty'))), 'global'),
    $R::normalize_rule(array('id' => 'r-estructurado', 'priority' => 99,
        'conditions' => array(array('field' => 'fields.z', 'op' => 'not_empty'))), 'global'),
));
check('orden global de evaluación = estructurado, contexto, texto',
    array_column($rules, 'id') === array('r-estructurado', 'r-contexto', 'r-texto'));

// Dentro de un mismo tier, la prioridad configurada manda (compat con suite V1).
$rules = $R::sort_rules(array(
    $R::normalize_rule(array('id' => 'z-mismo-tier', 'priority' => 5,
        'conditions' => array(array('field' => 'fields.a', 'op' => 'not_empty'))), 'global'),
    $R::normalize_rule(array('id' => 'a-mismo-tier', 'priority' => 20,
        'conditions' => array(array('field' => 'fields.b', 'op' => 'not_empty'))), 'global'),
));
check('mismo tier: priority ASC se conserva', array_column($rules, 'id') === array('z-mismo-tier', 'a-mismo-tier'));

/* ── 5 · Backward compatibility ────────────────────────────────────────────── */
echo "5 · Compatibilidad\n";
$GLOBALS['__options'] = array();
$d = $E::qualify(payload_for('cualquiera', array()));
check('sin reglas: decisión default intacta',
    $d['decision'] === 'qualified' && $d['routing_policy'] === 'route'
    && $d['notification_policy'] === 'notify' && $d['autoresponse_policy'] === 'allow');
check('sin reglas: nuevas claves con default no intrusivo',
    $d['commercial_fit'] === '' && $d['ads_feedback_policy'] === 'allow');
check('sin reglas: sigue siendo default', $E::is_default_decision($d));

// Decisión persistida ANTIGUA (sin claves nuevas) sigue evaluando como default.
$legacy = $E::default_decision();
unset($legacy['commercial_fit'], $legacy['ads_feedback_policy']);
check('decisión legacy sin claves nuevas: is_default no se rompe', $E::is_default_decision($legacy));

/* ── 6 · Manual override con acciones nuevas ───────────────────────────────── */
echo "6 · Manual override\n";
$E::reset_cache();
$d = $E::apply_manual_override(0, 0, array('commercial_fit' => 'strategic', 'ads_feedback' => 'skip'));
check('override: commercial_fit manual',  $d['commercial_fit'] === 'strategic');
check('override: alias ads_feedback',     $d['ads_feedback_policy'] === 'skip');
check('override: fuente manual',          $d['decision_source'] === 'manual');
check('override: decisión base intacta',  $d['decision'] === 'qualified' && $d['routing_policy'] === 'route');

/* ── Resultado ─────────────────────────────────────────────────────────────── */
echo "\n" . ($failed === 0 ? 'ALL PASS' : $failed . ' FAILED') . "\n";
exit($failed > 0 ? 1 : 0);
