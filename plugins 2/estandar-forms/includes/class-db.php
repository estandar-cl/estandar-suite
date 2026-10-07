<?php
if ( ! defined('ABSPATH') ) exit;

class Estandar_Forms_DB {

    const DB_VERSION = '2.4';
    const DB_OPTION  = 'estandar_forms_db_version';

    public static function bootstrap(): void {
        self::install();
        self::ensure_starter_form();
    }

    public static function needs_schema_repair(): bool {
        global $wpdb;

        return ! self::assert_schema($wpdb->prefix . 'estandar_forms');
    }

    public static function install() {

        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        $forms   = $wpdb->prefix . 'estandar_forms';
        $entries = $wpdb->prefix . 'estandar_form_entries';
        $logs    = $wpdb->prefix . 'estandar_form_logs';

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS $forms (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(200) NOT NULL,
                slug VARCHAR(100) NULL,
                success_message LONGTEXT NULL,
                submit_label VARCHAR(100) DEFAULT 'Enviar',
                after_submit_text TEXT NULL,
                notify_emails TEXT NULL,
                form_country VARCHAR(2) DEFAULT 'CL',
                store_entries TINYINT(1) DEFAULT 1,
                send_email TINYINT(1) DEFAULT 1,
                send_webhook TINYINT(1) DEFAULT 0,
                require_recaptcha TINYINT(1) DEFAULT 1,
                mail_subject TEXT NULL,
                mail_body LONGTEXT NULL,
                mail_sender_name VARCHAR(200) NULL,
                mail_reply_to_field VARCHAR(100) NULL,
                visual_theme VARCHAR(64) NOT NULL DEFAULT '',
                fields_json LONGTEXT NOT NULL,
                webhook_url TEXT NULL,
                webhook_payload_mode VARCHAR(32) NOT NULL DEFAULT 'estandar',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug)
            ) $charset"
        );

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS $entries (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                form_id BIGINT UNSIGNED NOT NULL,
                data_json LONGTEXT NOT NULL,
                status VARCHAR(20) DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY form_id (form_id),
                KEY status (status),
                KEY status_form_created (status, form_id, created_at)
            ) $charset"
        );

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS $logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                form_id BIGINT UNSIGNED NULL,
                event_type VARCHAR(50) NOT NULL,
                level VARCHAR(20) DEFAULT 'info',
                message TEXT NULL,
                context_json LONGTEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY form_id (form_id),
                KEY level_created (level, created_at),
                KEY event_created (event_type, created_at)
            ) $charset"
        );

        // ── Columnas heredadas (Forms 1.x – 1.7) ────────────────────────────
        self::ensure_column($forms, 'notify_emails', 'TEXT NULL');
        self::ensure_column($forms, 'form_country', "VARCHAR(2) DEFAULT 'CL'");
        self::ensure_column($forms, 'store_entries', 'TINYINT(1) DEFAULT 1');
        self::ensure_column($forms, 'send_email', 'TINYINT(1) DEFAULT 1');
        self::ensure_column($forms, 'send_webhook', 'TINYINT(1) DEFAULT 0');
        self::ensure_column($forms, 'require_recaptcha', 'TINYINT(1) DEFAULT 1');
        self::ensure_column($forms, 'mail_subject', 'TEXT NULL');
        self::ensure_column($forms, 'mail_body', 'LONGTEXT NULL');
        self::ensure_column($forms, 'mail_sender_name', 'VARCHAR(200) NULL');
        self::ensure_column($forms, 'mail_reply_to_field', 'VARCHAR(100) NULL');
        self::ensure_column($forms, 'submit_label', "VARCHAR(100) DEFAULT 'Enviar'");
        self::ensure_column($forms, 'after_submit_text', 'TEXT NULL');
        self::ensure_column($forms, 'visual_theme', "VARCHAR(64) NOT NULL DEFAULT ''");
        self::ensure_column($forms, 'webhook_payload_mode', "VARCHAR(32) NOT NULL DEFAULT 'estandar'");

        // ── Columna SURVEYS_V1 ──────────────────────────────────────────────
        self::ensure_column($forms, 'survey_config', 'LONGTEXT NULL');

        // ── Columnas Forms 4.0 ───────────────────────────────────────────────
        // form_type: motor de procesamiento. 'lead' = flujo 3.x intacto.
        self::ensure_column($forms, 'form_type',        "VARCHAR(32) NOT NULL DEFAULT 'lead'");
        // form_context: schema de contexto de negocio. 'generic' = sin campos extra.
        self::ensure_column($forms, 'form_context',     "VARCHAR(64) NOT NULL DEFAULT 'generic'");
        // request_type: clasificación semántica del tipo de solicitud.
        self::ensure_column($forms, 'request_type',     "VARCHAR(64) NOT NULL DEFAULT ''");
        // context_schema: JSON — campos de contexto dependientes del form_context.
        self::ensure_column($forms, 'context_schema',   'LONGTEXT NULL');
        // actions_json: JSON — pipeline de acciones post-submit (db, email, webhook).
        self::ensure_column($forms, 'actions_json',     'LONGTEXT NULL');
        // incidents_config: JSON — configuración del repeater de incidentes.
        self::ensure_column($forms, 'incidents_config', 'LONGTEXT NULL');
        // experience_config_json: EXPERIENCE_ENGINE_V1 — definición steps/flow/trigger/microcopy (form_type='experience').
        self::ensure_column($forms, 'experience_config_json', 'LONGTEXT NULL');
        // conversation_config: CONVERSATION_ENGINE_V1 (ADR 0034, MS-2) — config de flujo/branching/
        // scoring/completion para experiencias nuevas del motor (lead_qualification, cotizacion, etc.).
        // Aditiva; survey_config sigue siendo la fuente para encuestas. Sin migración de datos.
        self::ensure_column($forms, 'conversation_config', 'LONGTEXT NULL');

        // ── Índices tablas heredadas ─────────────────────────────────────────
        self::ensure_index($entries, 'form_id', 'ADD KEY form_id (form_id)');
        self::ensure_index($entries, 'status', 'ADD KEY status (status)');
        self::ensure_index($entries, 'status_form_created', 'ADD KEY status_form_created (status, form_id, created_at)');
        self::ensure_index($logs, 'form_id', 'ADD KEY form_id (form_id)');
        self::ensure_index($logs, 'level_created', 'ADD KEY level_created (level, created_at)');
        self::ensure_index($logs, 'event_created', 'ADD KEY event_created (event_type, created_at)');

        // ── Tablas Forms 4.0 ─────────────────────────────────────────────────
        $requests    = $wpdb->prefix . 'estandar_requests';
        $incidents   = $wpdb->prefix . 'estandar_incidents';
        $attachments = $wpdb->prefix . 'estandar_request_attachments';

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS $requests (
                id             BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
                request_ref    VARCHAR(24)          NOT NULL DEFAULT '',
                form_id        BIGINT(20) UNSIGNED  NOT NULL,
                form_type      VARCHAR(32)          NOT NULL DEFAULT 'incident',
                form_context   VARCHAR(64)          NOT NULL DEFAULT 'generic',
                request_type   VARCHAR(64)          NOT NULL DEFAULT '',
                status         VARCHAR(32)          NOT NULL DEFAULT 'open',
                owner_json     LONGTEXT,
                context_json   LONGTEXT,
                meta_json      LONGTEXT,
                source_ip      VARCHAR(45)          NOT NULL DEFAULT '',
                source_ua      TEXT,
                created_at     DATETIME             NOT NULL,
                updated_at     DATETIME             NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY   request_ref (request_ref),
                KEY          form_id (form_id),
                KEY          status (status),
                KEY          form_type (form_type),
                KEY          request_type (request_type),
                KEY          created_at (created_at)
            ) $charset"
        );

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS $incidents (
                id             BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
                request_id     BIGINT(20) UNSIGNED  NOT NULL,
                sort_order     TINYINT(3) UNSIGNED  NOT NULL DEFAULT 0,
                location       VARCHAR(190)         NOT NULL DEFAULT '',
                title          VARCHAR(190)         NOT NULL DEFAULT '',
                description    TEXT,
                status         VARCHAR(32)          NOT NULL DEFAULT 'open',
                created_at     DATETIME             NOT NULL,
                PRIMARY KEY  (id),
                KEY          request_id (request_id),
                KEY          location (location),
                KEY          status (status)
            ) $charset"
        );

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS $attachments (
                id             BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
                request_id     BIGINT(20) UNSIGNED  NOT NULL,
                incident_id    BIGINT(20) UNSIGNED  DEFAULT NULL,
                file_path      VARCHAR(500)         NOT NULL DEFAULT '',
                file_name      VARCHAR(190)         NOT NULL DEFAULT '',
                file_size      INT(10) UNSIGNED     NOT NULL DEFAULT 0,
                mime_type      VARCHAR(100)         NOT NULL DEFAULT '',
                created_at     DATETIME             NOT NULL,
                PRIMARY KEY  (id),
                KEY          request_id (request_id),
                KEY          incident_id (incident_id)
            ) $charset"
        );

        // ── Tabla Estandar_Secure_Token (enlaces públicos seguros, reutilizable) ─
        $tokens = $wpdb->prefix . 'estandar_secure_tokens';
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS $tokens (
                id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                module         VARCHAR(32)      NOT NULL,
                reference_id   BIGINT UNSIGNED  NOT NULL,
                token          VARCHAR(128)     NOT NULL,
                created_at     DATETIME         NOT NULL,
                expires_at     DATETIME         NULL,
                revoked_at     DATETIME         NULL,
                meta_json      LONGTEXT         NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY   token (token),
                KEY          module_ref (module, reference_id),
                KEY          expires_at (expires_at)
            ) $charset"
        );

        if ( self::assert_schema($forms) ) {
            update_option(self::DB_OPTION, self::DB_VERSION);
        }
    }

    public static function ensure_starter_form(): void {
        global $wpdb;

        $forms_table = $wpdb->prefix . 'estandar_forms';
        $forms_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$forms_table}");

        if ( $forms_count > 0 ) {
            return;
        }

        $starter_fields = [
            [
                'type'         => 'text',
                'name'         => 'nombre',
                'label'        => 'Nombre',
                'required'     => true,
                'error_message'=> 'Ingresa tu nombre.',
                'column_width' => '2',
            ],
            [
                'type'         => 'text',
                'name'         => 'apellido',
                'label'        => 'Apellido',
                'required'     => true,
                'error_message'=> 'Ingresa tu apellido.',
                'column_width' => '2',
            ],
            [
                'type'         => 'email',
                'name'         => 'email',
                'label'        => 'Mail',
                'required'     => true,
                'error_message'=> 'Ingresa un correo valido.',
                'column_width' => '3',
            ],
        ];

        $wpdb->insert(
            $forms_table,
            [
                'name'              => 'Formulario Estandar',
                'slug'              => 'formulario-estandar',
                'form_type'         => 'general',
                'success_message'   => 'Gracias. Hemos recibido tu información correctamente. Pronto nos pondremos en contacto contigo.',
                'submit_label'      => 'Enviar',
                'after_submit_text' => '',
                'notify_emails'     => '',
                'form_country'      => 'CL',
                'store_entries'     => 1,
                'send_email'        => 1,
                'send_webhook'      => 0,
                'require_recaptcha' => 1,
                'mail_subject'      => 'Nueva entrada - {{form_name}}',
                'mail_body'         => '',
                'mail_reply_to_field' => 'email',
                'fields_json'       => wp_json_encode($starter_fields),
                'webhook_url'       => '',
            ],
            ['%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%d','%s','%s','%s','%s','%s']
        );
    }

    private static function ensure_column(string $table, string $column, string $definition): void {
        global $wpdb;

        if ( ! self::column_exists($table, $column) ) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private static function column_exists(string $table, string $column): bool {
        global $wpdb;

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        if ( ! is_array($columns) ) {
            return false;
        }

        foreach ( $columns as $row ) {
            if ( isset($row['Field']) && (string) $row['Field'] === $column ) {
                return true;
            }
        }

        return false;
    }

    private static function assert_schema(string $forms_table): bool {
        foreach ( ['form_type', 'form_context', 'request_type', 'context_schema', 'actions_json', 'incidents_config', 'webhook_payload_mode', 'mail_sender_name', 'survey_config', 'experience_config_json', 'conversation_config'] as $column ) {
            if ( ! self::column_exists($forms_table, $column) ) {
                return false;
            }
        }

        return true;
    }

    private static function ensure_index(string $table, string $index_name, string $definition): void {
        global $wpdb;

        $exists = $wpdb->get_var(
            $wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index_name)
        );

        if ( ! $exists ) {
            $wpdb->query("ALTER TABLE {$table} {$definition}");
        }
    }
}
