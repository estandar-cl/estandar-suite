<?php
if (!defined('ABSPATH')) exit;

class Estandar_Secure_Share_Email {

    public static function get_template_real(string $key): array {
        $defaults = self::default_templates();
        $custom = get_option('estandar_secure_share_templates', []);
        return $custom[$key] ?? $defaults[$key] ?? ['subject' => '', 'html' => ''];
    }

    private static function get_template(string $key): array {
        return self::get_template_real($key);
    }

    private static function default_templates(): array {
        $settings = get_option('estandar_secure_share_settings', []);
        $brand = $settings['brand_name'] ?? 'ESTANDAR';
        $tagline = $settings['brand_tagline'] ?? 'Security Solutions';

        return [
            'request_notification' => [
                'subject' => 'Solicitud de nueva entrega — ' . get_bloginfo('name'),
                'html' => '<h2>Solicitud de nueva entrega segura</h2>'
                    . '<p>Un usuario ha solicitado una nueva entrega segura.</p>'
                    . '<p><strong>Correo del solicitante:</strong> {{requester_email}}</p>'
                    . '<p><strong>Fecha de solicitud:</strong> {{request_date}}</p>'
                    . '<p>Puedes crear una nueva entrega desde el panel de administración de Secure Share.</p>'
                    . '<p><em>— ' . esc_html($brand) . ' ' . esc_html($tagline) . '</em></p>',
            ],
            'request_confirmation' => [
                'subject' => 'Hemos recibido tu solicitud — ' . get_bloginfo('name'),
                'html' => '<h2>Solicitud recibida</h2>'
                    . '<p>Hemos recibido tu solicitud para una nueva entrega segura.</p>'
                    . '<p>El administrador revisará tu solicitud y, si es aprobada, recibirás un nuevo enlace.</p>'
                    . '<p><strong>Fecha de solicitud:</strong> {{request_date}}</p>'
                    . '<p><em>— ' . esc_html($brand) . ' ' . esc_html($tagline) . '</em></p>',
            ],
            'new_delivery' => [
                'subject' => 'Nueva entrega segura disponible — ' . get_bloginfo('name'),
                'html' => '<h2>Nueva entrega segura disponible</h2>'
                    . '<p>Se ha creado una nueva entrega segura para ti.</p>'
                    . '<p><strong>Enlace:</strong> <a href="{{delivery_url}}">{{delivery_url}}</a></p>'
                    . '<p><strong>Expira:</strong> {{expires_at}}</p>'
                    . '<p><strong>Importante:</strong> El contenido solo puede verse una vez. Al hacer clic en "Mostrar clave", se destruirá permanentemente.</p>'
                    . '<p><em>— ' . esc_html($brand) . ' ' . esc_html($tagline) . '</em></p>',
            ],
            'rejected' => [
                'subject' => 'Solicitud no procesada — ' . get_bloginfo('name'),
                'html' => '<h2>Solicitud no procesada</h2>'
                    . '<p>Lamentamos informarte que tu solicitud de entrega segura no pudo ser procesada en esta ocasión.</p>'
                    . '<p>Si crees que se trata de un error, por favor contacta directamente al administrador.</p>'
                    . '<p><em>— ' . esc_html($brand) . ' ' . esc_html($tagline) . '</em></p>',
            ],
        ];
    }

    private static function replace_vars(string $text, array $vars): string {
        foreach ($vars as $key => $value) {
            $text = str_replace('{{' . $key . '}}', esc_html($value), $text);
        }
        return $text;
    }

    private static function send(string $template_key, string $to, array $vars): bool {
        $template = self::get_template($template_key);
        if (empty($template['subject']) && empty($template['html'])) return false;

        $subject = self::replace_vars($template['subject'], $vars);
        $html = self::replace_vars($template['html'], $vars);
        $plain = wp_strip_all_tags($html);

        $settings = get_option('estandar_secure_share_settings', []);
        $from_email = $settings['from_email'] ?? get_option('admin_email');

        add_filter('wp_mail_from', function () use ($from_email) { return $from_email; }, 99);
        add_filter('wp_mail_from_name', function () { return get_bloginfo('name'); }, 99);
        add_filter('wp_mail_content_type', function () { return 'text/html'; }, 99);

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $sent = wp_mail($to, $subject, $html, $headers);

        remove_all_filters('wp_mail_from', 99);
        remove_all_filters('wp_mail_from_name', 99);
        remove_all_filters('wp_mail_content_type', 99);

        return $sent;
    }

    public static function send_request_notification(string $requester_email, array $delivery, int $request_id): bool {
        $settings = get_option('estandar_secure_share_settings', []);
        $admin_email = $settings['admin_email'] ?? get_option('admin_email');

        self::send('request_notification', $admin_email, [
            'requester_email' => $requester_email,
            'request_date' => current_time('mysql'),
            'original_token' => $delivery['token_public'] ?? '',
        ]);

        return self::send('request_confirmation', $requester_email, [
            'request_date' => current_time('mysql'),
        ]);
    }

    public static function send_new_delivery(string $email, array $delivery): bool {
        return self::send('new_delivery', $email, [
            'delivery_url' => home_url('/' . ESTANDAR_SECURE_SHARE_REWRITE_PREFIX . '/' . $delivery['token']),
            'expires_at' => $delivery['expires_at'],
        ]);
    }

    public static function send_test(string $template_key, string $to): bool {
        return self::send($template_key, $to, [
            'requester_email' => $to,
            'request_date' => current_time('mysql'),
            'delivery_url' => home_url('/secure/demo-token'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
    }

    public static function reset_template(string $key): void {
        $templates = get_option('estandar_secure_share_templates', []);
        unset($templates[$key]);
        update_option('estandar_secure_share_templates', $templates);
    }
}
