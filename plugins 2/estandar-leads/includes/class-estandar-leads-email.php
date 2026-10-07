<?php
/**
 * HTML email rendering and delivery for Estandar Leads.
 *
 * Settings stored in wp_options('estandar_leads_email_settings').
 * Template: templates/email/lead-notification.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_Email {

    // Holds settings during a single wp_mail() call so static hooks can read them.
    private static $sending_settings = null;

    public static function global_footer_text(): string {
        return "Este mensaje fue enviado mediante una plataforma de gestión y seguimiento comercial desarrollada por Estandar Studio.\nwww.estandar.cl";
    }

    public static function global_footer_html(): string {
        return esc_html( 'Este mensaje fue enviado mediante una plataforma de gestión y seguimiento comercial desarrollada por Estandar Studio.' )
            . '<br><a href="https://www.estandar.cl" style="color:inherit;text-decoration:none;">www.estandar.cl</a>';
    }

    public static function append_global_footer( string $html ): string {
        $footer = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border-top:1px solid #e8eaed;"><tr><td style="padding:18px 24px;text-align:center;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;font-size:11px;color:#9aa0a6;line-height:1.5;">'
            . self::global_footer_html()
            . '</td></tr></table>';

        if ( stripos( $html, '</body>' ) !== false ) {
            return preg_replace( '/<\/body>/i', $footer . '</body>', $html, 1 );
        }
        return $html . $footer;
    }

    public static function render_template( string $template, array $vars ): string {
        $allowed = array(
            'lead-notification.php',
            'autoresponse.php',
            'compliance-escalation.php',
            'compliance-bootstrap-digest.php',
            'sales-activation.php',
        );
        if ( ! in_array( $template, $allowed, true ) ) {
            return '';
        }

        extract( $vars, EXTR_SKIP );
        ob_start();
        include ESTANDAR_LEADS_PATH . 'templates/email/' . $template;
        return self::append_global_footer( (string) ob_get_clean() );
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public static function defaults() {
        return array(
            'sender_name'            => primer_is_active() ? estandar_core_get_sender_name() : get_bloginfo( 'name' ),
            'sender_email'           => primer_is_active() ? estandar_core_get_sender_email() : (string) get_option( 'admin_email', '' ),
            'reply_to_email'         => '',
            'notification_cc_email'  => '',
            'notification_bcc_email' => '',
            'subject'                => 'Nuevo Lead Recibido',
            'preheader'              => 'Nuevo contacto registrado con información para seguimiento comercial.',
            'intro_text'             => 'Se ha registrado un nuevo contacto que requiere seguimiento y clasificación.',
            'button_label'           => 'Clasificar Lead',
            'brand_color'            => '#1a73e8',
            'logo_url'               => '',
            'footer_text'            => self::global_footer_text(),
            'show_business_context'  => 1,
        );
    }

    public static function get_settings() {
        $saved = get_option( 'estandar_leads_email_settings', array() );
        $settings = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
        if ( primer_is_active() ) {
            $settings['sender_name'] = estandar_core_get_sender_name( (string) ( $settings['sender_name'] ?? '' ), (string) get_bloginfo( 'name' ) );
        }
        if ( primer_is_active() ) {
            $settings['sender_email'] = estandar_core_get_sender_email( (string) ( $settings['sender_email'] ?? '' ), (string) get_option( 'admin_email', '' ) );
        }
        if ( empty( $settings['notification_cc_email'] ) ) {
            $settings['notification_cc_email'] = self::sanitize_email_list( get_option( 'estandar_leads_notification_cc_email', '' ) );
        }
        return $settings;
    }

    public static function save_settings( array $raw ) {
        $s = array();

        $s['sender_name']  = sanitize_text_field( $raw['sender_name'] ?? '' );
        $s['sender_email'] = sanitize_email( $raw['sender_email'] ?? '' );
        $s['reply_to_email'] = sanitize_email( $raw['reply_to_email'] ?? '' );
        $s['notification_cc_email'] = self::sanitize_email_list( $raw['notification_cc_email'] ?? '' );
        $s['notification_bcc_email'] = self::sanitize_email_list( $raw['notification_bcc_email'] ?? '' );
        $s['subject']      = sanitize_text_field( $raw['subject'] ?? '' );
        $s['preheader']    = sanitize_text_field( $raw['preheader'] ?? '' );
        $s['button_label'] = sanitize_text_field( $raw['button_label'] ?? '' );
        $s['logo_url']     = esc_url_raw( $raw['logo_url'] ?? '' );
        $s['brand_color']  = self::sanitize_hex_color( $raw['brand_color'] ?? '' );

        $allowed_html = array(
            'a'      => array( 'href' => array(), 'style' => array() ),
            'strong' => array(),
            'em'     => array(),
            'br'     => array(),
        );
        $s['intro_text']   = wp_kses( $raw['intro_text'] ?? '', $allowed_html );
        $s['footer_text']  = wp_kses( $raw['footer_text'] ?? '', $allowed_html );

        $s['show_business_context'] = ! empty( $raw['show_business_context'] ) ? 1 : 0;

        update_option( 'estandar_leads_email_settings', $s, false );
        update_option( 'estandar_leads_notification_cc_email', $s['notification_cc_email'], false );
    }

    public static function sanitize_email_list( $value ): string {
        $parts = preg_split( '/[,\\n]+/', (string) wp_unslash( $value ) );
        $out = array();
        foreach ( (array) $parts as $email ) {
            $email = sanitize_email( trim( (string) $email ) );
            if ( is_email( $email ) ) {
                $out[] = $email;
            }
        }
        return implode( ',', array_values( array_unique( $out ) ) );
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render the HTML email.
     *
     * @param array $data {
     *   operator_name, lead_name, lead_email, lead_phone,
     *   project_area, campaign, keyword, source_plugin, feedback_url, workspace_url
     * }
     * @param array|null $settings  Override settings (null = load from DB).
     * @return string  HTML string.
     */
    public static function render( array $data, $settings = null ) {
        $es_s = $settings !== null ? $settings : self::get_settings();
        $data['quote_create_url'] = apply_filters( 'estandar_leads_quote_create_url', '', $data );
        $es_d = $data;

        return self::render_template( 'lead-notification.php', compact( 'es_s', 'es_d' ) );
    }

    /**
     * Render with placeholder / demo data for preview.
     */
    public static function preview( $settings = null ) {
        return self::render( array(
            'operator_name' => 'Carla Fernández',
            'lead_name'     => 'Juan Pérez',
            'lead_email'    => 'juan.perez@email.com',
            'lead_phone'    => '+56 9 1234 5678',
            'project_area'  => 'Proyecto Demo — Casas de ejemplo',
            'project_slug'  => 'example-project',
            'campaign'      => 'google-demo-2025',
            'keyword'       => 'casas ejemplo precio',
            'utm_source'    => 'google',
            'utm_medium'    => 'cpc',
            'landing_url'   => home_url( '/contacto/' ),
            'submitted_at'  => gmdate( 'c' ),
            'form_fields'   => array(
                'tipo_cliente' => 'Empresa constituida',
                'servicio'     => 'Asesoría contable mensual',
                'urgencia'     => 'Esta semana',
                'mensaje'      => 'Necesito cotizar contabilidad mensual para mi empresa.',
            ),
            'source_plugin' => 'estandar-chatbot',
            'feedback_url'  => home_url( '/?estandar_feedback=1&token=PREVIEW-NOT-REAL' ),
            'workspace_url' => home_url( '/workspace/PREVIEW-NOT-REAL' ),
            'workspace_backlog' => array(
                'classification_pending' => 3,
                'action_required' => 4,
                'waiting_response' => 11,
                'closed' => 22,
            ),
            'consolidated_sheet_url' => '',
        ), $settings );
    }

    // -------------------------------------------------------------------------
    // LEAD_RESPONSE_ACCELERATION_V1 — Autoresponse settings (Fase 1)
    //
    // Stored in wp_option('estandar_leads_autoresponse_settings'), keyed by
    // project_uuid with project_slug fallback. enabled=0 by default
    // (opt-in per project — feature is inert until configured).
    // -------------------------------------------------------------------------

    public static function autoresponse_defaults(): array {
        return array(
            'enabled'           => 0,
            'subject'           => 'Gracias por tu interés — {{proyecto}}',
            'body'              => "Hola {{nombre}},\n\nGracias por tu interés en {{proyecto}}. Hemos recibido correctamente tu solicitud de información.\n\nUno de nuestros asesores se comunicará contigo a la brevedad.",
            'pdf_attachment_id' => 0,
            'landing_url'       => '',
            'throttle_hours'    => 24,
            // Empty = text-only header. Non-empty = explicit public image URL for emails.
            'logo_url'          => '',
        );
    }

    public static function public_email_logo_url( string $url ): string {
        $url = trim( $url );
        if ( strpos( $url, '/' ) === 0 ) {
            $url = home_url( $url );
        }

        $url = esc_url_raw( $url );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $extension = strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        if (
            $host === ''
            || $host === 'localhost'
            || substr( $host, -6 ) === '.local'
            || substr( $host, -5 ) === '.test'
            || ! in_array( $extension, array( 'png', 'jpg', 'jpeg', 'gif' ), true )
        ) {
            return '';
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $public_ip = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
            if ( ! $public_ip ) {
                return '';
            }
        }

        $url = set_url_scheme( $url, 'https' );
        return wp_parse_url( $url, PHP_URL_SCHEME ) === 'https' ? $url : '';
    }

    /**
     * Resolve autoresponse settings for a project, merged over the
     * 'default' entry and the hardcoded defaults. Returns enabled=0 if
     * nothing has been configured for this project nor 'default'.
     */
    public static function get_autoresponse_settings( string $project_slug ): array {
        $project_slug = sanitize_key( $project_slug );
        $all = get_option( 'estandar_leads_autoresponse_settings', array() );
        if ( ! is_array( $all ) ) {
            $all = array();
        }

        $defaults = self::autoresponse_defaults();
        $default_entry = array_merge(
            $defaults,
            is_array( $all['default'] ?? null ) ? $all['default'] : array()
        );

        $project_uuid = '';
        foreach ( Estandar_Leads_Projects::registry() as $project ) {
            if ( (string) ( $project['project_slug'] ?? '' ) === $project_slug ) {
                $project_uuid = sanitize_text_field( (string) ( $project['project_uuid'] ?? '' ) );
                break;
            }
        }

        if ( $project_uuid !== '' && is_array( $all[ $project_uuid ] ?? null ) ) {
            return array_merge( $default_entry, $all[ $project_uuid ] );
        }

        if ( $project_slug !== '' && is_array( $all[ $project_slug ] ?? null ) ) {
            return array_merge( $default_entry, $all[ $project_slug ] );
        }

        return $default_entry;
    }

    /**
     * Save autoresponse settings for a project (or 'default'). Additive,
     * sanitizes each field.
     */
    public static function save_autoresponse_settings( array $raw, string $project_slug = 'default', string $project_uuid = '' ): void {
        $project_slug = $project_slug === 'default' ? 'default' : sanitize_key( $project_slug );
        $project_uuid = sanitize_text_field( $project_uuid );
        if ( $project_slug === '' ) {
            $project_slug = 'default';
        }

        $all = get_option( 'estandar_leads_autoresponse_settings', array() );
        if ( ! is_array( $all ) ) {
            $all = array();
        }

        $defaults = self::autoresponse_defaults();
        $current = self::get_autoresponse_settings( $project_slug );
        $entry = array(
            'enabled'           => ! empty( $raw['enabled'] ) ? 1 : 0,
            'subject'           => sanitize_text_field( $raw['subject'] ?? $defaults['subject'] ),
            'body'              => sanitize_textarea_field( $raw['body'] ?? $defaults['body'] ),
            'pdf_attachment_id' => absint( $raw['pdf_attachment_id'] ?? 0 ),
            'landing_url'       => array_key_exists( 'landing_url', $raw )
                ? esc_url_raw( $raw['landing_url'] )
                : esc_url_raw( $current['landing_url'] ?? '' ),
            'throttle_hours'    => array_key_exists( 'throttle_hours', $raw )
                ? max( 0, absint( $raw['throttle_hours'] ) )
                : max( 0, absint( $current['throttle_hours'] ?? $defaults['throttle_hours'] ) ),
            // AUTORESPONSE_TEMPLATE_FIX_V1 — per-project logo override.
            'logo_url'          => array_key_exists( 'logo_url', $raw )
                ? esc_url_raw( $raw['logo_url'] )
                : esc_url_raw( $current['logo_url'] ?? '' ),
            'project_slug'      => $project_slug,
            'project_uuid'      => $project_uuid,
        );

        $storage_key = $project_uuid !== '' ? $project_uuid : $project_slug;
        $all[ $storage_key ] = $entry;
        update_option( 'estandar_leads_autoresponse_settings', $all, false );
    }

    /**
     * Substitute `{{variable}}` placeholders with sanitized scalar values.
     * TEMPLATE_ENGINE_V1.1 supports simple conditional blocks only:
     * `{{#if variable}}...{{/if}}`. No expressions, else, nesting, or PHP.
     * Unknown placeholders are left as-is (visible breadcrumb if misconfigured).
     *
     * @param string               $text
     * @param array<string,string> $vars  e.g. ['nombre' => 'Juan', 'proyecto' => 'Proyecto Demo']
     * @return string
     */
    public static function substitute_placeholders( string $text, array $vars ): string {
        $search = array();
        $replace = array();
        $clean_vars = array();
        foreach ( $vars as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( $key === '' ) {
                continue;
            }
            $clean_vars[ $key ] = self::format_field_value( $value );
        }

        $text = preg_replace_callback(
            '/^[ \t]*\{\{#if[ \t]+([a-zA-Z0-9_\\-]+)\}\}\R?(.*?)^[ \t]*\{\{\/if\}\}[ \t]*(?:\R|$)/ms',
            function ( $matches ) use ( $clean_vars ) {
                $key = sanitize_key( (string) $matches[1] );
                if ( ! array_key_exists( $key, $clean_vars ) || $clean_vars[ $key ] === '' ) {
                    return '';
                }
                return rtrim( (string) $matches[2] ) . "\n";
            },
            $text
        );

        foreach ( $clean_vars as $key => $value ) {
            $search[]  = '{{' . $key . '}}';
            $replace[] = $value;
        }
        return str_replace( $search, $replace, $text );
    }

    /**
     * Render the autoresponse (lead-facing) email.
     *
     * @param array $data {
     *   project_slug, project_name, lead_name, operator_name, operator_email,
     *   message, pdf_link, landing_link, whatsapp_link, pixel_url
     * }
     * @param array|null $autoresponse_settings  Override (null = load by project_slug).
     * @return string  HTML string.
     */
    public static function render_autoresponse( array $data, $autoresponse_settings = null ) {
        $es_s = self::get_settings();
        $es_a = $autoresponse_settings !== null ? $autoresponse_settings : self::get_autoresponse_settings( (string) ( $data['project_slug'] ?? '' ) );
        $es_d = $data;

        // AUTORESPONSE_TEMPLATE_FIX_V1 — per-project logo overrides brand logo.
        // Empty string = fall through to brand/site logo in public_email_logo_url().
        if ( ! empty( $es_a['logo_url'] ) ) {
            $es_s['logo_url'] = (string) $es_a['logo_url'];
        }

        return self::render_template( 'autoresponse.php', compact( 'es_s', 'es_a', 'es_d' ) );
    }

    public static function operator_form_rows( array $fields ): array {
        $rows = array();
        $blocked = array(
            'action', 'estandar_ajax', 'estandar_form_id', 'estandar_form_nonce',
            '_estandar_token', '_redirect', 'estandar_ts', 'form_id', 'lead_id',
            'lead_uuid', 'session_id', 'current_page_url', 'landing_url', 'referrer',
            'gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'utm_source',
            'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'first_touch_timestamp', 'last_touch_timestamp', 'nonce', '_wpnonce',
            'email', 'correo', 'mail', 'telefono', 'phone', 'tel', 'celular',
            'mobile', 'nombre', 'name', 'full_name', 'fullname',
            // FULL_NAME_V1: surname is merged into the lead name, never shown as a form row.
            'apellido', 'apellidos', 'last_name', 'lastname', 'surname',
            // DEDUP_LOCATION: region/city already shown in the Ubicación block.
            'region', 'ciudad', 'city', 'comuna', 'commune', 'province', 'provincia',
            // DEDUP_MESSAGE: message rendered in its own dedicated block.
            'message', 'mensaje', 'comments', 'comentario', 'consulta',
        );

        foreach ( $fields as $key => $value ) {
            $key = is_string( $key ) ? sanitize_key( $key ) : '';
            if ( $key === '' || in_array( $key, $blocked, true ) || strpos( $key, 'token' ) !== false || strpos( $key, 'nonce' ) !== false ) {
                continue;
            }
            $value = self::format_field_value( $value );
            if ( $value === '' ) {
                continue;
            }
            $value = self::humanize_select_value( $key, $value );
            $rows[ self::field_label( $key ) ] = $value;
        }

        return $rows;
    }

    /**
     * FULL_NAME_V1 — Compose the lead's full name by merging the surname
     * captured as a separate form field into the base lead name.
     * Presentational only: does not alter stored lead data.
     */
    public static function full_lead_name( string $lead_name, array $fields ): string {
        $name    = trim( $lead_name );
        $aliases = array( 'apellido', 'apellidos', 'last_name', 'lastname', 'surname' );
        $surname = '';

        foreach ( $fields as $key => $value ) {
            $key = is_string( $key ) ? sanitize_key( $key ) : '';
            if ( in_array( $key, $aliases, true ) && is_scalar( $value ) ) {
                $surname = trim( sanitize_text_field( (string) $value ) );
                if ( $surname !== '' ) {
                    break;
                }
            }
        }

        if ( $surname !== '' && stripos( $name, $surname ) === false ) {
            $name = trim( $name . ' ' . $surname );
        }

        return $name;
    }

    public static function capture_channel_label( string $source_plugin ): string {
        $source_plugin = sanitize_key( $source_plugin );
        if ( strpos( $source_plugin, 'forms' ) !== false ) {
            return 'Formulario Web';
        }
        if ( strpos( $source_plugin, 'chatbot' ) !== false ) {
            return 'Chatbot IA';
        }
        if ( strpos( $source_plugin, 'whatsapp' ) !== false ) {
            return 'WhatsApp';
        }
        if ( strpos( $source_plugin, 'import' ) !== false ) {
            return 'Importado';
        }
        if ( $source_plugin === '' || strpos( $source_plugin, 'manual' ) !== false || $source_plugin === 'estandar-leads' ) {
            return 'Manual';
        }
        return 'Otro';
    }

    public static function origin_label( string $source, string $medium ): string {
        $channel = Estandar_Leads_Attribution::resolve_channel( array( 'utm_source' => $source, 'utm_medium' => $medium ) );
        if ( $channel === 'google_ads' ) { return 'Google Ads'; }
        if ( $channel === 'google_organic' ) { return 'Google Orgánico'; }
        if ( $channel === 'meta_ads' ) { return 'Meta Ads'; }
        if ( $channel === 'organic' ) { return 'Orgánico'; }
        if ( $channel === 'direct' ) { return 'Directo'; }
        if ( $channel === 'form_web' ) { return 'Formulario Web'; }

        $source = strtolower( trim( sanitize_text_field( $source ) ) );
        $medium = strtolower( trim( sanitize_text_field( $medium ) ) );

        $sources = array(
            'ig'        => 'Instagram',
            'instagram' => 'Instagram',
            'fb'        => 'Facebook',
            'facebook'  => 'Facebook',
            'google'    => 'Google',
            'linkedin'  => 'LinkedIn',
            'tiktok'    => 'TikTok',
        );
        $mediums = array(
            'paid'       => 'Ads',
            'cpc'        => 'Ads',
            'ppc'        => 'Ads',
            'paid_social'=> 'Ads',
            'organic'    => 'Orgánico',
            'referral'   => 'Referido',
            'email'      => 'Email',
        );

        $source_label = $sources[ $source ] ?? ucfirst( $source );
        $medium_label = $mediums[ $medium ] ?? ucfirst( $medium );

        return trim( implode( ' ', array_filter( array( $source_label, $medium_label ) ) ) );
    }

    public static function relative_capture_time( string $datetime ): string {
        $timestamp = strtotime( $datetime );
        if ( ! $timestamp ) {
            return '';
        }

        $seconds = max( 0, current_time( 'timestamp', true ) - $timestamp );
        if ( $seconds < MINUTE_IN_SECONDS ) {
            return 'Recibido hace menos de 1 minuto';
        }
        if ( $seconds < HOUR_IN_SECONDS ) {
            $minutes = (int) floor( $seconds / MINUTE_IN_SECONDS );
            return sprintf( 'Recibido hace %d %s', $minutes, $minutes === 1 ? 'minuto' : 'minutos' );
        }
        if ( $seconds < DAY_IN_SECONDS ) {
            $hours = (int) floor( $seconds / HOUR_IN_SECONDS );
            return sprintf( 'Recibido hace %d %s', $hours, $hours === 1 ? 'hora' : 'horas' );
        }

        $days = (int) floor( $seconds / DAY_IN_SECONDS );
        return sprintf( 'Recibido hace %d %s', $days, $days === 1 ? 'día' : 'días' );
    }

    public static function landing_label( string $url ): string {
        $url = esc_url_raw( $url );
        if ( $url === '' ) {
            return '';
        }

        $post_id = url_to_postid( $url );
        if ( $post_id > 0 ) {
            $title = trim( wp_strip_all_tags( get_the_title( $post_id ) ) );
            if ( $title !== '' ) {
                return $title;
            }
        }

        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        if ( $path !== '' ) {
            return ucwords( str_replace( array( '-', '_' ), ' ', basename( $path ) ) );
        }

        return (string) wp_parse_url( $url, PHP_URL_HOST );
    }

    public static function whatsapp_url( string $phone ): string {
        $digits = preg_replace( '/\D+/', '', $phone );
        if ( ! is_string( $digits ) || $digits === '' ) {
            return '';
        }
        if ( strlen( $digits ) === 9 && strpos( $digits, '9' ) === 0 ) {
            $digits = '56' . $digits;
        }
        return 'https://wa.me/' . $digits;
    }

    public static function operator_tracked_url( string $event_type, string $target_url, array $data ): string {
        $target_url = esc_url_raw( $target_url );
        if ( $target_url === '' ) {
            return '';
        }

        $lead_id       = absint( $data['lead_id'] ?? 0 );
        $submission_id = absint( $data['submission_id'] ?? 0 );
        if ( $lead_id < 1 ) {
            return $target_url;
        }

        $token = Estandar_Leads_Tokens::sign_link(
            $lead_id,
            $submission_id,
            sanitize_key( $event_type ),
            $target_url,
            30,
            sanitize_key( (string) ( $data['project_slug'] ?? '' ) ),
            absint( $data['operator_id'] ?? 0 )
        );

        return rest_url( 'estandar-leads/v1/go/' . rawurlencode( $token ) );
    }

    private static function field_label( string $key ): string {
        $label = str_replace( array( '_', '-' ), ' ', $key );
        return ucwords( $label );
    }

    /**
     * Humanize slug-style select values (e.g. "persona_natural" → "Persona natural").
     * Only applied to known select-type field keys; free-text values are returned as-is.
     */
    public static function humanize_select_value( string $key, string $value ): string {
        static $select_keys = array(
            'tipo_cliente', 'tipo_empresa', 'tipo_persona', 'client_type', 'persona',
            'servicio', 'service', 'service_category', 'categoria', 'category',
            'urgencia', 'urgency', 'plazo', 'timeline', 'cuando',
            'estado', 'status', 'estado_civil', 'civil_status',
        );
        if ( ! in_array( strtolower( trim( $key ) ), $select_keys, true ) ) {
            return $value;
        }
        // Only transform values that look like slugs (underscores/hyphens, no spaces, all lowercase).
        if ( preg_match( '/^[a-z0-9_\-]+$/', $value ) ) {
            return ucfirst( str_replace( array( '_', '-' ), ' ', $value ) );
        }
        return $value;
    }

    private static function format_field_value( $value ): string {
        if ( is_array( $value ) ) {
            $value = implode( ', ', array_map( array( __CLASS__, 'format_field_value' ), $value ) );
        }
        if ( is_bool( $value ) ) {
            $value = $value ? 'Si' : 'No';
        }
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        return trim( sanitize_textarea_field( (string) $value ) );
    }

    // -------------------------------------------------------------------------
    // Sending
    // -------------------------------------------------------------------------

    /**
     * Send a single HTML email with correct headers.
     *
     * @param string     $to
     * @param string     $subject
     * @param string     $html
     * @param array|null $settings
     * @return bool
     */
    public static function send( $to, $subject, $html, $settings = null, array $headers = array(), array $attachments = array() ) {
        self::$sending_settings = $settings !== null ? $settings : self::get_settings();

        add_filter( 'wp_mail_from',         array( __CLASS__, '_filter_from' ) );
        add_filter( 'wp_mail_from_name',    array( __CLASS__, '_filter_from_name' ) );
        add_filter( 'wp_mail_content_type', array( __CLASS__, '_filter_content_type' ) );
        add_action( 'wp_mail_failed',       array( __CLASS__, '_log_mail_failed' ) );

        $result = wp_mail( $to, $subject, $html, $headers, $attachments );

        remove_filter( 'wp_mail_from',         array( __CLASS__, '_filter_from' ) );
        remove_filter( 'wp_mail_from_name',    array( __CLASS__, '_filter_from_name' ) );
        remove_filter( 'wp_mail_content_type', array( __CLASS__, '_filter_content_type' ) );
        remove_action( 'wp_mail_failed',       array( __CLASS__, '_log_mail_failed' ) );

        if ( $result ) {
            update_option( 'estandar_leads_smtp_last_success', array(
                'ts'      => gmdate( 'Y-m-d H:i:s' ),
                'to'      => sanitize_email( (string) $to ),
                'subject' => sanitize_text_field( (string) $subject ),
            ), false );
        }

        self::$sending_settings = null;
        return $result;
    }

    /**
     * Hooked to wp_mail_failed during send(). Stores the last error for SMTP Health diagnostics.
     * Intentionally public static for remove_action compatibility.
     *
     * @param WP_Error $error
     */
    public static function _log_mail_failed( $error ) {
        if ( ! ( $error instanceof WP_Error ) ) {
            return;
        }
        update_option( 'estandar_leads_smtp_last_error', array(
            'ts'      => gmdate( 'Y-m-d H:i:s' ),
            'message' => $error->get_error_message(),
            'code'    => $error->get_error_code(),
        ), false );
    }

    // -------------------------------------------------------------------------
    // SMTP Health
    // -------------------------------------------------------------------------

    /**
     * Returns a SMTP health snapshot using the four-layer Configuration Health model.
     * Layers: Existencia → Conexión → Uso → Efecto
     *
     * @return array {
     *   state        string  'PASS'|'WARNING'|'FAIL'
     *   layers       array   Per-layer results (label, ok, detail)
     *   smtp_plugin  string  Detected SMTP plugin name or ''
     *   last_success array|null
     *   last_error   array|null
     * }
     */
    public static function smtp_health(): array {
        $settings     = self::get_settings();
        $sender_email = sanitize_email( (string) ( $settings['sender_email'] ?? '' ) );
        $sender_name  = sanitize_text_field( (string) ( $settings['sender_name'] ?? '' ) );
        $last_success = get_option( 'estandar_leads_smtp_last_success', null );
        $last_error   = get_option( 'estandar_leads_smtp_last_error', null );

        // Layer 1: Existencia — email del remitente configurado y válido
        $l1_ok     = is_email( $sender_email );
        $l1_detail = $l1_ok
            ? $sender_name . ' <' . $sender_email . '>'
            : 'Email del remitente no configurado o inválido.';

        // Layer 2: Conexión — plugin SMTP activo detectado
        $smtp_plugin = self::_detect_smtp_plugin();
        $l2_ok       = $smtp_plugin !== '';
        $l2_detail   = $l2_ok
            ? 'Plugin detectado: ' . $smtp_plugin
            : 'No se detectó ningún plugin SMTP activo. wp_mail() usará el servidor PHP por defecto.';

        // Layer 3: Uso — phpmailer_init está hookeado (plugin inyecta credenciales)
        $phpmailer_hooked = (bool) has_filter( 'phpmailer_init' );
        $l3_ok     = $phpmailer_hooked || $l2_ok;
        $l3_detail = $l3_ok
            ? 'Configuración SMTP activa para wp_mail().'
            : 'phpmailer_init no está hookeado. El envío puede fallar o usar el servidor PHP local.';

        // Layer 4: Efecto — último envío exitoso sin error posterior
        $has_success    = is_array( $last_success ) && ! empty( $last_success['ts'] );
        $has_recent_err = is_array( $last_error ) && ! empty( $last_error['ts'] )
            && ( $has_success
                ? strtotime( $last_error['ts'] ) > strtotime( $last_success['ts'] )
                : true );
        $l4_ok     = $has_success && ! $has_recent_err;
        $l4_detail = $has_recent_err
            ? 'Último error: ' . ( $last_error['message'] ?? '' ) . ' (' . ( $last_error['ts'] ?? '' ) . ')'
            : ( $has_success
                ? 'Último envío exitoso: ' . $last_success['ts'] . ' → ' . ( $last_success['to'] ?? '' )
                : 'Sin historial de envíos. Usa "Enviar email de prueba" para verificar.' );

        $layers = array(
            array( 'label' => 'Existencia', 'ok' => $l1_ok, 'detail' => $l1_detail ),
            array( 'label' => 'Conexión',   'ok' => $l2_ok, 'detail' => $l2_detail ),
            array( 'label' => 'Uso',        'ok' => $l3_ok, 'detail' => $l3_detail ),
            array( 'label' => 'Efecto',     'ok' => $l4_ok, 'detail' => $l4_detail ),
        );

        if ( ! $l1_ok ) {
            $state = 'FAIL';
        } elseif ( $l1_ok && $l2_ok && $l3_ok && $l4_ok ) {
            $state = 'PASS';
        } else {
            $state = 'WARNING';
        }

        return array(
            'state'        => $state,
            'layers'       => $layers,
            'smtp_plugin'  => $smtp_plugin,
            'last_success' => is_array( $last_success ) ? $last_success : null,
            'last_error'   => is_array( $last_error )   ? $last_error   : null,
        );
    }

    /**
     * Detects the active SMTP plugin by checking known classes and options.
     *
     * @return string  Human-readable plugin name, or '' if none detected.
     */
    private static function _detect_smtp_plugin(): string {
        if ( class_exists( 'WPMailSMTP\Core' ) || get_option( 'wp_mail_smtp' ) ) {
            return 'WP Mail SMTP';
        }
        if ( class_exists( 'FluentSmtp\App' ) || get_option( 'fluentsmtp_settings' ) ) {
            return 'FluentSMTP';
        }
        if ( class_exists( 'PostmanOptions' ) || get_option( 'postman_options' ) ) {
            return 'Post SMTP';
        }
        if ( get_option( 'swpsmtp_options' ) ) {
            return 'Easy WP SMTP';
        }
        if ( class_exists( 'Brevo_Smtp' ) || get_option( 'sib_smtp_settings' ) ) {
            return 'Brevo SMTP';
        }
        if ( has_filter( 'phpmailer_init' ) ) {
            return 'Plugin SMTP (desconocido)';
        }
        return '';
    }

    // wp_mail filter callbacks — intentionally public static for remove_filter compatibility.
    public static function _filter_from() {
        $email = self::$sending_settings['sender_email'] ?? '';
        return primer_is_active()
            ? estandar_core_get_sender_email( (string) $email, (string) get_option( 'admin_email' ) )
            : ( sanitize_email( $email ) ?: (string) get_option( 'admin_email' ) );
    }

    public static function _filter_from_name() {
        $name = sanitize_text_field( self::$sending_settings['sender_name'] ?? '' );
        return primer_is_active()
            ? estandar_core_get_sender_name( $name, (string) get_bloginfo( 'name' ) )
            : ( $name ?: get_bloginfo( 'name' ) );
    }

    public static function _filter_content_type() {
        return 'text/html';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function sanitize_hex_color( $color ) {
        $color = trim( (string) $color );
        if ( function_exists( 'sanitize_hex_color' ) ) {
            return sanitize_hex_color( $color ) ?: '#1a73e8';
        }
        return preg_match( '/^#[0-9a-f]{3,6}$/i', $color ) ? $color : '#1a73e8';
    }

    // -------------------------------------------------------------------------
    // Quality vocabulary
    // -------------------------------------------------------------------------

    public static function qualities() {
        return array( 'HOT', 'WARM', 'COLD', 'JUNK' );
    }

    public static function quality_labels() {
        return array(
            'HOT'  => 'Caliente — interés real',
            'WARM' => 'Tibio — posible interés',
            'COLD' => 'Frío — bajo interés',
            'JUNK' => 'Descartado — spam / error',
        );
    }

    public static function quality_colors() {
        return array(
            'HOT'  => '#d93025',
            'WARM' => '#e37400',
            'COLD' => '#1a73e8',
            'JUNK' => '#80868b',
        );
    }
}
