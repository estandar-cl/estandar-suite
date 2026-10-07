<?php

declare(strict_types=1);

$wp_load = dirname(__DIR__, 4) . '/wp-load.php';
if (!is_file($wp_load)) {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(1);
}

require_once $wp_load;

$option_name = 'estandar_leads_autoresponse_settings';
$original = get_option($option_name, null);
$project = reset(Estandar_Leads_Projects::registry());

if (!is_array($project) || empty($project['project_slug'])) {
    fwrite(STDERR, "No registry project available\n");
    exit(1);
}

$slug = sanitize_key((string) $project['project_slug']);
$uuid = sanitize_text_field((string) ($project['project_uuid'] ?? ''));
$storage_key = $uuid !== '' ? $uuid : $slug;

try {
    update_option($option_name, [
        $storage_key => [
            'enabled' => 0,
            'subject' => 'Original',
            'body' => 'Original',
            'pdf_attachment_id' => 0,
            'landing_url' => 'https://example.test/project',
            'throttle_hours' => 72,
            'project_slug' => $slug,
            'project_uuid' => $uuid,
        ],
    ], false);

    Estandar_Leads_Email::save_autoresponse_settings([
        'enabled' => 1,
        'subject' => 'Información de {{proyecto}}',
        'body' => "Hola {{nombre}},\n\nTe compartimos la información solicitada.",
        'pdf_attachment_id' => 123,
    ], $slug, $uuid);

    $saved = get_option($option_name, []);
    $settings = Estandar_Leads_Email::get_autoresponse_settings($slug);
    $checks = [
        'storage_key' => isset($saved[$storage_key]),
        'enabled' => (int) ($settings['enabled'] ?? 0) === 1,
        'subject' => ($settings['subject'] ?? '') === 'Información de {{proyecto}}',
        'body' => str_contains((string) ($settings['body'] ?? ''), 'información solicitada'),
        'pdf' => (int) ($settings['pdf_attachment_id'] ?? 0) === 123,
        'landing_preserved' => ($settings['landing_url'] ?? '') === 'https://example.test/project',
        'throttle_preserved' => (int) ($settings['throttle_hours'] ?? 0) === 72,
    ];

    foreach ($checks as $name => $passed) {
        if (!$passed) {
            fwrite(STDERR, "FAIL: {$name}\n");
            exit(1);
        }
    }

    $logo_checks = [
        'empty_logo_text_only' => Estandar_Leads_Email::public_email_logo_url('') === '',
        'https_upgrade' => Estandar_Leads_Email::public_email_logo_url('http://acacios.cl/logo.png') === 'https://acacios.cl/logo.png',
        'private_rejected' => Estandar_Leads_Email::public_email_logo_url('http://localhost:8000/logo.png') === '',
        'svg_rejected' => Estandar_Leads_Email::public_email_logo_url('https://acacios.cl/logo.svg') === '',
    ];
    $rendered = Estandar_Leads_Email::render_autoresponse([
        'project_name' => 'Altacruz',
        'subject' => 'Gracias por tu interés',
        'body' => 'Hola Luis',
        'pdf_link' => 'https://example.com/ficha',
        'whatsapp_link' => 'https://example.com/whatsapp',
    ], Estandar_Leads_Email::autoresponse_defaults());
    $logo_checks['project_heading'] = str_contains($rendered, 'Altacruz');
    $logo_checks['pdf_context'] = str_contains($rendered, 'características principales');
    $logo_checks['whatsapp_context'] = str_contains($rendered, 'atención inmediata');
    $logo_checks['global_footer'] = str_contains($rendered, 'Estandar Studio') && str_contains($rendered, 'www.estandar.cl');

    $test_message = Estandar_Leads_Autoresponse::build_test_message($slug, (string) ($project['name'] ?? $slug));
    $logo_checks['test_whatsapp_uses_redirect'] = str_contains($test_message['html'], 'whatsapp-redirect');
    $logo_checks['test_whatsapp_no_hardcoded'] = !str_contains($test_message['html'], 'https://wa.me/56912345678');

    $conditional = Estandar_Leads_Email::substitute_placeholders(
        "Hola {{nombre}}\n{{#if telefono}}\nTeléfono: {{telefono}}\n{{/if}}\n{{#if email}}\nEmail: {{email}}\n{{/if}}\n{{#if mensaje}}\nMensaje: {{mensaje}}\n{{/if}}",
        [
            'nombre' => 'Rodrigo',
            'telefono' => '+56900000000',
            'email' => '',
            'mensaje' => '',
        ]
    );
    $logo_checks['conditional_keeps_present'] = str_contains($conditional, 'Teléfono: +56900000000');
    $logo_checks['conditional_removes_empty'] = !str_contains($conditional, 'Email:') && !str_contains($conditional, 'Mensaje:');
    $logo_checks['conditional_no_blank_lines'] = !str_contains($conditional, "\n\n");

    foreach ($logo_checks as $name => $passed) {
        if (!$passed) {
            fwrite(STDERR, "FAIL: {$name}\n");
            exit(1);
        }
    }

    echo "PASS: autoresponse settings UI storage\n";
} finally {
    if ($original === null) {
        delete_option($option_name);
    } else {
        update_option($option_name, $original, false);
    }
}
