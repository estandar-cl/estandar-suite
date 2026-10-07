<?php
/**
 * PROMPT_ARCHITECTURE_V1 — suite de composición canónica del system prompt.
 * Run: php tests/run-prompt-composition.php   (requiere WP vivo: SALADEPRUEBAS)
 *
 * Valida: System Base siempre presente · Perfil del negocio inyectado ·
 * Conocimiento inyectado · prompt custom sigue funcionando (reemplaza al
 * default, sin duplicarlo) · orden de bloques estable · sin duplicación de
 * identidad entre bloques · proxy y WhatsApp usan el mismo ensamblador.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    require_once dirname(__DIR__, 4) . '/wp-load.php';
}

$failures = array();
$check = static function ($label, $ok) use (&$failures) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) { $failures[] = $label; }
};

// Backup de opciones que la suite manipula.
$opts = array(
    'chatbot_system_prompt',
    'chatbot_business_industry',
    'chatbot_business_goal',
    'chatbot_business_primary_cta',
    'chatbot_business_tone',
    'chatbot_business_sensitive_topics',
);
$backup = array();
foreach ($opts as $o) { $backup[$o] = get_option($o, null); }

try {
    update_option('chatbot_business_industry', 'inmobiliaria-test', false);
    update_option('chatbot_business_goal', 'objetivo-test', false);
    update_option('chatbot_business_primary_cta', 'CTA-TEST-9871', false);
    update_option('chatbot_business_tone', 'tono-test', false);
    update_option('chatbot_business_sensitive_topics', 'tema-sensible-test', false);

    // ── 1 · Modo default (sin prompt custom) ────────────────────────────
    update_option('chatbot_system_prompt', '', false);
    $md = estandar_chatbot_build_system_prompt('es', 'CONOCIMIENTO-TEST-5150', 'REGLA-ESPECIALIZADA-77');

    $pos = static function ($needle) use ($md) { return strpos($md, $needle); };

    $check('System Base presente', $pos('=== SYSTEM BASE ===') !== false);
    $check('Comportamiento default presente', $pos('=== CONTEXTO GLOBAL ===') !== false && $pos('ROL DEL ASISTENTE') !== false);
    $check('Perfil del negocio inyectado', $pos('=== PERFIL DEL NEGOCIO ===') !== false);
    $check('Perfil: rubro/objetivo/CTA/tono/sensibles presentes',
        $pos('inmobiliaria-test') !== false && $pos('objetivo-test') !== false
        && $pos('CTA-TEST-9871') !== false && $pos('tono-test') !== false
        && $pos('tema-sensible-test') !== false);
    $check('Contexto especializado inyectado', $pos('=== CONTEXTO ESPECIALIZADO ===') !== false && $pos('REGLA-ESPECIALIZADA-77') !== false);
    $check('Conocimiento inyectado', $pos('=== BASE DE CONOCIMIENTO ===') !== false && $pos('CONOCIMIENTO-TEST-5150') !== false);
    $check('Orden: base < comportamiento < perfil < especializado < conocimiento',
        $pos('=== SYSTEM BASE ===') < $pos('=== CONTEXTO GLOBAL ===')
        && $pos('=== CONTEXTO GLOBAL ===') < $pos('=== PERFIL DEL NEGOCIO ===')
        && $pos('=== PERFIL DEL NEGOCIO ===') < $pos('=== CONTEXTO ESPECIALIZADO ===')
        && $pos('=== CONTEXTO ESPECIALIZADO ===') < $pos('=== BASE DE CONOCIMIENTO ==='));

    // Sin duplicación: la identidad vive SOLO en el perfil. El bloque de
    // comportamiento (entre CONTEXTO GLOBAL y PERFIL) no contiene marca ni CTA.
    $global_block = substr($md, (int) $pos('=== CONTEXTO GLOBAL ==='), (int) $pos('=== PERFIL DEL NEGOCIO ===') - (int) $pos('=== CONTEXTO GLOBAL ==='));
    $brand = estandar_chatbot_get_brand_label();
    $check('Prompt default sin nombre de empresa (identidad solo en Perfil)',
        $brand === '' || stripos($global_block, $brand) === false);
    $check('Prompt default sin CTA (CTA solo en Perfil)', stripos($global_block, 'CTA-TEST-9871') === false);
    $check('Perfil aparece una sola vez', substr_count($md, '=== PERFIL DEL NEGOCIO ===') === 1);
    $check('Conocimiento aparece una sola vez', substr_count($md, 'CONOCIMIENTO-TEST-5150') === 1);

    // ── 2 · Modo custom (backward compatibility) ────────────────────────
    update_option('chatbot_system_prompt', 'REGLAS-CUSTOM-4242: responde en una línea.', false);
    $md2 = estandar_chatbot_build_system_prompt('es', 'CONOCIMIENTO-TEST-5150');

    $check('Custom: prompt propio presente', strpos($md2, 'REGLAS-CUSTOM-4242') !== false);
    $check('Custom: default NO se añade (sin duplicación de comportamiento)', strpos($md2, 'ROL DEL ASISTENTE') === false);
    $check('Custom: Perfil sigue inyectándose', strpos($md2, '=== PERFIL DEL NEGOCIO ===') !== false);
    $check('Custom: Conocimiento sigue inyectándose', strpos($md2, 'CONOCIMIENTO-TEST-5150') !== false);
    $check('Custom: System Base presente', strpos($md2, '=== SYSTEM BASE ===') !== false);
    $check('Custom: comportamiento antes que conocimiento (precedencia intacta)',
        strpos($md2, 'REGLAS-CUSTOM-4242') < strpos($md2, '=== BASE DE CONOCIMIENTO ==='));

    // ── 3 · Perfil vacío no genera bloque huérfano ───────────────────────
    foreach (array('chatbot_business_industry','chatbot_business_goal','chatbot_business_primary_cta','chatbot_business_tone','chatbot_business_sensitive_topics') as $o) {
        update_option($o, '', false);
    }
    $md3 = estandar_chatbot_build_system_prompt('es', '');
    $profile_block_start = strpos($md3, '=== PERFIL DEL NEGOCIO ===');
    // Con brand del sitio el bloque existe (solo Empresa); nunca un bloque vacío.
    $check('Perfil sin campos: sin bloque vacío ni líneas huérfanas',
        $profile_block_start === false || strpos($md3, "=== PERFIL DEL NEGOCIO ===\n\n-") !== false);

    // ── 4 · Punto canónico único: proxy y WhatsApp usan el mismo builder ─
    $proxy_src = (string) file_get_contents(dirname(__DIR__) . '/includes/openai-proxy.php');
    $wa_src    = (string) file_get_contents(dirname(__DIR__) . '/includes/whatsapp-handler.php');
    $check('Proxy usa estandar_chatbot_build_system_prompt', strpos($proxy_src, 'estandar_chatbot_build_system_prompt(') !== false);
    $check('WhatsApp usa estandar_chatbot_build_system_prompt', strpos($wa_src, 'estandar_chatbot_build_system_prompt(') !== false);
    $check('Proxy sin ensamblado paralelo de bloques', strpos($proxy_src, 'PERFIL DEL NEGOCIO') === false && strpos($proxy_src, 'BASE DE CONOCIMIENTO') === false);
    $check('WhatsApp sin ensamblado paralelo de bloques', strpos($wa_src, 'PERFIL DEL NEGOCIO') === false && strpos($wa_src, 'BASE DE CONOCIMIENTO') === false);
} finally {
    foreach ($backup as $o => $v) {
        if ($v === null) { delete_option($o); } else { update_option($o, $v, false); }
    }
}

echo empty($failures) ? "ALL PASS\n" : count($failures) . " FAILED\n";
exit(empty($failures) ? 0 : 1);
