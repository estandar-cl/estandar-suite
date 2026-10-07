<?php
/**
 * ESTANDAR SUITE v2 — Capture AiChat + AiChatPrompt tests.
 *
 *   php tests/run-ai-chat.php   → expect "0 failed"
 *
 * Covers: provider resolution, key from vault, message sanitization, request
 * body building, response parsing, error contract, prompt composition.
 */

define('ABSPATH', __DIR__);
$GLOBALS['__test_options'] = array();
$GLOBALS['__test_http'] = array();
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return trim((string) $t); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return trim((string) $t); } }
if (!function_exists('get_option')) { function get_option($k, $d = null) { return array_key_exists($k, $GLOBALS['__test_options']) ? $GLOBALS['__test_options'][$k] : $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__test_options'][$k] = $v; return true; } }
if (!function_exists('get_transient')) { function get_transient($k) { return $GLOBALS['__test_options']['_t_' . $k] ?? false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $e = 0) { $GLOBALS['__test_options']['_t_' . $k] = $v; return true; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d) { return json_encode($d); } }
if (!function_exists('is_wp_error')) { function is_wp_error($x) { return $x instanceof \WP_Error; } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return (int) ($r['response']['code'] ?? 200); } }
if (!class_exists('WP_Error')) { class WP_Error { public $message; public function __construct($c, $m) { $this->message = $m; } public function get_error_message() { return $this->message; } } }
if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args) {
        $GLOBALS['__test_http'][] = array('url' => $url, 'args' => $args);
        if (!empty($GLOBALS['__test_http_error'])) {
            return new WP_Error('err', $GLOBALS['__test_http_error']);
        }
        if (!empty($GLOBALS['__test_http_body'])) {
            return array('body' => $GLOBALS['__test_http_body'], 'response' => array('code' => $GLOBALS['__test_http_code'] ?? 200));
        }
        return array(
            'body' => json_encode(array(
                'choices' => array(array('message' => array('content' => 'Hola, ¿en qué te ayudo?'), 'finish_reason' => 'stop')),
                'usage' => array('prompt_tokens' => 10, 'completion_tokens' => 5),
                'model' => 'deepseek-chat',
            )),
            'response' => array('code' => 200),
        );
    }
}

require dirname(__DIR__) . '/../estandar-core/includes/AiKeys.php';
require dirname(__DIR__) . '/includes/AiChat.php';
require dirname(__DIR__) . '/includes/AiChatPrompt.php';

use Estandar\Capture\AiChat;
use Estandar\Capture\AiChatPrompt;

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

// ── 1. Proveedor y modelo por defecto ────────────────────────────────────
$GLOBALS['__test_options']['estandar_suite_core_ai_settings'] = array(
    'deepseek' => 'sk-deep-test',
    'openai' => '',
    'active_provider' => 'deepseek',
    'default_model' => 'deepseek-chat',
);
check('1a provider default deepseek', AiChat::provider() === 'deepseek');
check('1b default_model deepseek-chat', AiChat::default_model() === 'deepseek-chat');
check('1c api_key del vault', AiChat::api_key() === 'sk-deep-test');

// ── 2. Chat happy path (HTTP mock 200) ────────────────────────────────────
$GLOBALS['__test_http'] = array();
$r = AiChat::chat(array(
    array('role' => 'system', 'content' => 'Eres un asistente.'),
    array('role' => 'user', 'content' => 'Hola'),
), array('model' => 'deepseek-chat', 'temperature' => 0.4, 'max_tokens' => 500));
check('2a content extraído', ($r['content'] ?? '') === 'Hola, ¿en qué te ayudo?');
check('2b finish_reason stop', ($r['finish_reason'] ?? '') === 'stop');
check('2c usage presente', is_array($r['usage'] ?? null));
check('2d sin error', empty($r['error']));
check('2e endpoint deepseek', strpos($GLOBALS['__test_http'][0]['url'] ?? '', 'api.deepseek.com') !== false);
$headers = $GLOBALS['__test_http'][0]['args']['headers'] ?? array();
check('2f Authorization bearer', ($headers['Authorization'] ?? '') === 'Bearer sk-deep-test');
$body = json_decode($GLOBALS['__test_http'][0]['args']['body'] ?? '{}', true);
check('2g body model', ($body['model'] ?? '') === 'deepseek-chat');
check('2h body temperature', ($body['temperature'] ?? null) === 0.4);
check('2i body max_tokens', ($body['max_tokens'] ?? null) === 500);

// ── 3. Sin clave → error claro ───────────────────────────────────────────
$GLOBALS['__test_options']['estandar_suite_core_ai_settings'] = array(
    'deepseek' => '', 'openai' => '', 'active_provider' => 'deepseek', 'default_model' => 'deepseek-chat',
    'adopted' => 1,
);
$GLOBALS['__test_options']['estandar_suite_core_ai_adopted_from'] = array();
$r = AiChat::chat(array(array('role' => 'user', 'content' => 'Hola')));
check('3a error sin clave', !empty($r['error']) && ($r['error_code'] ?? '') === 'missing_api_key');
check('3b no hace HTTP', count($GLOBALS['__test_http']) === 1);

// ── 4. HTTP error → error con código ─────────────────────────────────────
$GLOBALS['__test_options']['estandar_suite_core_ai_settings'] = array(
    'deepseek' => 'sk-deep-test', 'openai' => '', 'active_provider' => 'deepseek', 'default_model' => 'deepseek-chat',
);
$GLOBALS['__test_http_body'] = json_encode(array('error' => array('message' => 'Invalid API key', 'code' => 'invalid_api_key')));
$GLOBALS['__test_http_code'] = 401;
$r = AiChat::chat(array(array('role' => 'user', 'content' => 'Hola')));
check('4a error message', ($r['error'] ?? '') === 'Invalid API key');
check('4b error code', ($r['error_code'] ?? '') === 'invalid_api_key');

// ── 5. Sanitización de mensajes (roles inválidos, límite, largo) ────────
$GLOBALS['__test_http_body'] = null; $GLOBALS['__test_http_code'] = 200;
$GLOBALS['__test_http'] = array();
$long = str_repeat('a', 6000);
$r = AiChat::chat(array(
    array('role' => 'evil', 'content' => 'no'),
    array('role' => 'user', 'content' => $long),
    array('role' => 'assistant', 'content' => 'ok'),
));
$body = json_decode($GLOBALS['__test_http'][0]['args']['body'] ?? '{}', true);
$roles = array_column($body['messages'] ?? array(), 'role');
check('5a sin roles inválidos', !in_array('evil', $roles, true));
check('5b rol user permitido', in_array('user', $roles, true));
check('5c contenido truncado a 4000', strlen($body['messages'][0]['content'] ?? '') <= 4000);

// ── 6. Prompt composition ────────────────────────────────────────────────
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings'] = array(
    'system_prompt' => 'Sé directo y breve.',
    'business_industry' => 'inmobiliaria',
    'business_goal' => 'captar contactos',
    'business_primary_cta' => 'Quiero avanzar',
    'business_tone' => 'profesional',
    'business_sensitive_topics' => 'precio',
    'context_txt' => "Información del proyecto:\n- 200 departamentos",
    'openai_model' => 'deepseek-chat',
    'openai_temperature' => '0.4',
    'openai_max_tokens' => '800',
);
$system = AiChatPrompt::system();
check('6a incluye instrucciones', strpos($system, 'Sé directo') !== false);
check('6b incluye industria', strpos($system, 'inmobiliaria') !== false);
check('6c incluye contexto', strpos($system, '200 departamentos') !== false);
check('6d incluye temas sensibles', strpos($system, 'precio') !== false);
// CHAT_TONE_V1: bloque genérico de estilo/captación, antes de las instrucciones.
check('6h prompt incluye bloque de estilo natural', strpos($system, 'Estilo de conversación') !== false);
check('6i prompt incluye captación de contacto', strpos($system, 'Captación de contacto') !== false);
check('6j estilo va ANTES de las instrucciones del usuario', strpos($system, 'Estilo de conversación') !== false && strpos($system, 'Estilo de conversación') < strpos($system, 'Sé directo'));
check('6k estilo no bloquea la respuesta por falta de datos', strpos($system, 'NUNCA bloquees una respuesta útil') !== false);
check('6l prompt incluye precisión de datos técnicos (no inventar)', strpos($system, 'Precisión de datos técnicos') !== false && strpos($system, 'deriva al asesor') !== false);
check('6m precisión usa con seguridad los datos del contexto', strpos($system, 'úsalo con seguridad') !== false);
$opts = AiChatPrompt::chat_opts();
check('6e opts model', ($opts['model'] ?? '') === 'deepseek-chat');
check('6f opts temperature', ($opts['temperature'] ?? null) === 0.4);
check('6g opts max_tokens', ($opts['max_tokens'] ?? null) === 800);

// ── 7. LEGACY_FALLBACK_V1: sitio migrado con SOLO opciones legacy ────────
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings'] = array(
    'openai_model' => 'deepseek-chat', // v2 system_prompt/context_txt/business_* vacíos.
);
$GLOBALS['__test_options']['chatbot_rol_sistema'] = 'Eres asesor del negocio; conoces sus productos y servicios.';
$GLOBALS['__test_options']['chatbot_business_industry'] = 'agro';
$GLOBALS['__test_options']['chatbot_business_goal'] = 'agendar visitas';
$GLOBALS['__test_options']['chatbot_business_primary_cta'] = 'Agenda tu visita';
$GLOBALS['__test_options']['chatbot_business_tone'] = 'cercano';
$GLOBALS['__test_options']['chatbot_business_sensitive_topics'] = 'precios';
$system = AiChatPrompt::system();
check('7a rol legacy usado como prompt', strpos($system, 'asesor del negocio') !== false);
check('7b perfil legacy industry', strpos($system, 'agro') !== false);
check('7c perfil legacy goal', strpos($system, 'agendar visitas') !== false);
check('7d perfil legacy cta', strpos($system, 'Agenda tu visita') !== false);
check('7e perfil legacy sensitive', strpos($system, 'precios') !== false);
check('7f rol legacy no se duplica (prompt ∩ contexto)', substr_count($system, 'asesor del negocio') === 1);

// system_prompt v2 presente → rol legacy pasa a context_txt (una sola vez).
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings'] = array(
    'system_prompt' => 'Sé breve.',
    'openai_model' => 'deepseek-chat',
);
$system = AiChatPrompt::system();
check('7g system_prompt v2 tiene prioridad', strpos($system, 'Sé breve') !== false);
check('7h rol legacy cae a contexto si no se usó como prompt',
    strpos($system, 'asesor del negocio') !== false && strpos($system, 'Contexto oficial') !== false);
check('7i rol legacy sigue sin duplicarse', substr_count($system, 'asesor del negocio') === 1);

// Sin v2 ni legacy, el prompt base sigue funcionando.
unset($GLOBALS['__test_options']['chatbot_rol_sistema']);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings'] = array();
$system = AiChatPrompt::system();
check('7j sin v2 ni legacy funciona', strpos($system, 'asistente virtual profesional') !== false);

// ── 8. PROJECT_FOCUS_V1: resolución pura por URL contra el registry ──────
$registry = array(
    array('project_slug' => 'parque-uno', 'name' => 'Parque Uno', 'city' => 'Santiago', 'url_patterns' => array('/proyectos/parque-uno')),
    array('project_slug' => 'kume', 'name' => 'Kume', 'city' => 'Temuco', 'url_patterns' => array('/kume')),
);
$GLOBALS['__test_options']['chatbot_project_registry'] = $registry;
$p = AiChatPrompt::match_project_for_url('https://sitio.cl/proyectos/parque-uno/detalle', $registry);
check('8a match por url_pattern (path)', ($p['project_slug'] ?? '') === 'parque-uno');
$p = AiChatPrompt::match_project_for_url('https://sitio.cl/kume/', $registry);
check('8b match segunda entrada + slash final', ($p['project_slug'] ?? '') === 'kume');
$p = AiChatPrompt::match_project_for_url('https://sitio.cl/', $registry);
check('8c home no matchea', empty($p));
$p = AiChatPrompt::match_project_for_url('https://sitio.cl/otra-pagina', $registry);
check('8d pagina desconocida no matchea', empty($p));
$p = AiChatPrompt::match_project_for_url('https://sitio.cl/kume?utm=1#x', $registry);
check('8e ignora query/hash', ($p['project_slug'] ?? '') === 'kume');
$p = AiChatPrompt::resolve_project('https://sitio.cl/otra', 'kume');
check('8f context_slug explícito tiene prioridad', ($p['project_slug'] ?? '') === 'kume');
check('8g registry vacío → sin match', AiChatPrompt::match_project_for_url('https://sitio.cl/kume', array()) === array());
// 8h/8i: nota de contexto para anteponer al turno del usuario.
$GLOBALS['__test_options']['chatbot_project_registry'] = $registry;
check('8h page_context_note con proyecto', strpos(AiChatPrompt::page_context_note('https://sitio.cl/kume/'), 'página del proyecto «Kume»') !== false);
check('8i page_context_note sin proyecto vacío', AiChatPrompt::page_context_note('https://sitio.cl/') === '');

// ── 9. system() inyecta enfoque de proyecto + contacto + desambiguación ──
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings'] = array(
    'system_prompt' => 'Sé breve.',
    'openai_model' => 'deepseek-chat',
);
$GLOBALS['__test_options']['chatbot_project_registry'] = $registry;
$GLOBALS['__test_options']['chatbot_responsables'] = array(
    array('nombre' => 'Ana Pérez', 'project_slug' => 'kume', 'email' => 'ana@x.cl', 'telefono' => '+56911111111'),
);
unset($GLOBALS['__test_options']['chatbot_requires_disambiguation']);

$system = AiChatPrompt::system('https://sitio.cl/kume/');
check('9a incluye instrucción de proyecto', strpos($system, 'página del proyecto «Kume»') !== false);
check('9b incluye ciudad del proyecto', strpos($system, '(Temuco)') !== false);
check('9c incluye contacto de ventas del proyecto', strpos($system, 'Ana Pérez') !== false && strpos($system, 'ana@x.cl') !== false);
$system = AiChatPrompt::system('https://sitio.cl/');
check('9d sin match no inyecta proyecto', strpos($system, 'página del proyecto') === false);

$GLOBALS['__test_options']['chatbot_requires_disambiguation'] = 1;
$system = AiChatPrompt::system('https://sitio.cl/');
check('9e desambiguación lista proyectos disponibles',
    strpos($system, 'Proyectos disponibles en este sitio') !== false
    && strpos($system, 'Parque Uno') !== false
    && strpos($system, 'Kume') !== false);
unset($GLOBALS['__test_options']['chatbot_requires_disambiguation']);

// 9g: con proyecto resuelto NO debe pedir desambiguar (evita contradicción).
$GLOBALS['__test_options']['chatbot_requires_disambiguation'] = 1;
$system = AiChatPrompt::system('https://sitio.cl/kume/');
check('9g proyecto enfocado no pide desambiguar',
    strpos($system, 'página del proyecto «Kume»') !== false
    && strpos($system, 'pregúntale cuál le interesa') === false
    && strpos($system, 'NO preguntes cuál proyecto') !== false);
unset($GLOBALS['__test_options']['chatbot_requires_disambiguation']);

// 9h: proyectos sin url_patterns no se listan al desambiguar.
$GLOBALS['__test_options']['chatbot_project_registry'] = array(
    array('project_slug' => 'kume', 'name' => 'Kume', 'city' => 'Temuco', 'url_patterns' => array('/kume')),
    array('project_slug' => 'postventa', 'name' => 'Postventa', 'city' => '', 'url_patterns' => array()),
);
$GLOBALS['__test_options']['chatbot_requires_disambiguation'] = 1;
$system = AiChatPrompt::system('https://sitio.cl/');
check('9h no lista proyectos internos sin url_patterns',
    strpos($system, 'Kume') !== false && strpos($system, 'Postventa') === false);
unset($GLOBALS['__test_options']['chatbot_requires_disambiguation']);

// systems sin args (WhatsApp/admin preview) siguen funcionando.
$system = AiChatPrompt::system();
check('9f system() sin args funciona', strpos($system, 'asistente virtual profesional') !== false);

// ── 10. CHAT_AJAX_HARDEN_V1: rate-limit + presupuesto compartidos ────────
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
$GLOBALS['__test_options'] = array(); // limpia transients del test
$rate_ok = true;
for ($i = 0; $i < 20; $i++) { $rate_ok = AiChatPrompt::chat_rate_ok('9.9.9.9') && $rate_ok; }
check('10a rate-limit: 20 permitidas', $rate_ok);
check('10b rate-limit: la 21 se bloquea', AiChatPrompt::chat_rate_ok('9.9.9.9') === false);

$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['chat_daily_budget'] = 2;
unset($GLOBALS['__test_options']['_t_estandar_suite_chat_budget_' . gmdate('Ymd')]);
check('10c budget: 1ª ok', AiChatPrompt::chat_budget_ok() === true);
check('10d budget: 2ª ok', AiChatPrompt::chat_budget_ok() === true);
check('10e budget: 3ª bloqueada', AiChatPrompt::chat_budget_ok() === false);
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['chat_daily_budget']);

// ── 11. CHATBOT_PROFILE_V1: `perfil` añade énfasis (default sin cambios) ──
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['perfil'] = 'lead';
check('11a perfil=lead añade enfoque de captación', strpos(AiChatPrompt::system('https://sitio.cl/'), 'invita con naturalidad a dejar los datos de contacto') !== false);
$GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['perfil'] = 'survey';
check('11b perfil=survey añade enfoque de encuesta', strpos(AiChatPrompt::system('https://sitio.cl/'), 'una a la vez') !== false);
unset($GLOBALS['__test_options']['estandar_suite_capture_chatbot_settings']['perfil']);
check('11c perfil default no añade bloque', strpos(AiChatPrompt::system('https://sitio.cl/'), 'invita con naturalidad a dejar los datos de contacto') === false);

echo PHP_EOL . $failed . ' failed · ' . $passed . ' passed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
