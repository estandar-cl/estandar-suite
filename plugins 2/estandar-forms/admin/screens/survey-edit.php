<?php
if ( ! defined('ABSPATH') ) exit;

// JSON_LIFECYCLE_V1 — UI canónica compartida con Forms (tabs/panels/JS).
require_once __DIR__ . '/../partials/json-lifecycle-panel.php';

function estandar_surveys_edit_page(): void {
    if ( ! current_user_can('manage_options') ) { wp_die('No autorizado'); }

    global $wpdb;
    $table      = $wpdb->prefix . 'estandar_forms';
    $editing_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;

    $survey = null;
    if ( $editing_id > 0 ) {
        $survey = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND form_type = 'survey'",
            $editing_id
        ) );
        if ( ! $survey ) { wp_die('Encuesta no encontrada.'); }
    }

    /* ── Save ──────────────────────────────────────────────── */
    if ( isset( $_POST['estandar_survey_save'] ) ) {
        check_admin_referer('estandar_survey_save');

        $s_name             = sanitize_text_field( wp_unslash( $_POST['survey_name'] ?? '' ) );
        $s_slug             = sanitize_title( wp_unslash( $_POST['survey_slug'] ?? '' ) );
        $fields_raw         = wp_unslash( $_POST['fields_json'] ?? '[]' );
        $config_raw         = wp_unslash( $_POST['survey_config_json'] ?? '{}' );
        // MS-6 (ADR 0034): Builder universal — el tipo de experiencia es configurable.
        $s_experience_type  = sanitize_key( wp_unslash( $_POST['experience_type'] ?? 'survey' ) );
        $conv_config_raw    = wp_unslash( $_POST['conversation_config_json'] ?? '' );
        $id_mode            = sanitize_key( $_POST['identification_mode'] ?? 'anonymous' );
        $hero_title         = sanitize_text_field( $_POST['hero_title'] ?? '' );
        $hero_subtitle      = sanitize_text_field( $_POST['hero_subtitle'] ?? '' );
        $hero_cta           = sanitize_text_field( $_POST['hero_cta'] ?? 'Comenzar' );
        $hero_image_url     = esc_url_raw( $_POST['hero_image_url'] ?? '' );
        $completion_title   = sanitize_text_field( $_POST['completion_title'] ?? '¡Gracias!' );
        $completion_message = sanitize_text_field( $_POST['completion_message'] ?? 'Tu respuesta fue registrada exitosamente.' );
        $notify_raw         = sanitize_textarea_field( wp_unslash( $_POST['notify_emails'] ?? '' ) );
        $notify_emails      = [];
        $invalid_emails     = [];
        foreach ( preg_split('/[\r\n,]+/', $notify_raw) ?: [] as $email ) {
            $email = trim((string) $email);
            if ( $email === '' ) {
                continue;
            }
            $sanitized = sanitize_email($email);
            if ( ! is_email($sanitized) ) {
                $invalid_emails[] = $email;
                continue;
            }
            $notify_emails[] = $sanitized;
        }
        $notify_emails = array_values(array_unique($notify_emails));

        if ( $s_name === '' ) {
            echo '<div class="notice notice-error"><p>El nombre es obligatorio.</p></div>';
        } elseif ( ! empty($invalid_emails) ) {
            echo '<div class="notice notice-error"><p>Correos inválidos: ' . esc_html(implode(', ', $invalid_emails)) . '</p></div>';
        } else {
            if ( ! in_array($id_mode, ['anonymous','identified','optional'], true) ) { $id_mode = 'anonymous'; }
            if ( $s_slug === '' ) { $s_slug = sanitize_title($s_name); }

            $fields = function_exists('estandar_forms_decode_fields')
                ? estandar_forms_decode_fields($fields_raw)
                : json_decode($fields_raw, true);
            if ( ! is_array($fields) ) { $fields = []; }
            $fields_json = wp_json_encode($fields);

            // R9 · ADR 0034: fusión determinística — preserva claves desconocidas de
            // survey_config existente; nunca reconstruye desde un set fijo (pérdida silenciosa).
            $current_config_raw = '';
            if ( $editing_id > 0 ) {
                $existing_cfg = $wpdb->get_row( $wpdb->prepare(
                    "SELECT survey_config FROM $table WHERE id = %d",
                    $editing_id
                ) );
                $current_config_raw = (string) ( $existing_cfg->survey_config ?? '' );
            }
            $survey_config = wp_json_encode( estandar_forms_merge_survey_config(
                $current_config_raw,
                [
                    'identification_mode' => $id_mode,
                    'hero_title'          => $hero_title,
                    'hero_subtitle'       => $hero_subtitle,
                    'hero_cta'            => $hero_cta,
                    'hero_image_url'      => $hero_image_url,
                    'completion_title'    => $completion_title,
                    'completion_message'  => $completion_message,
                ]
            ) );

            $data = [
                'name'          => $s_name,
                'slug'          => $s_slug,
                'form_type'     => $s_experience_type,
                'fields_json'   => $fields_json,
                'survey_config' => $survey_config,
                'notify_emails' => implode(', ', $notify_emails),
            ];
            // MS-6 (ADR 0034): para experiencias no-survey se persiste conversation_config
            // (flujo/branching/scoring/completion). Survey conserva survey_config como fuente.
            $allowed_types = class_exists('Estandar_Conversation_Engine')
                ? Estandar_Conversation_Engine::conversational_form_types()
                : [ 'survey', 'lead_qualification' ];
            if ( ! in_array( $s_experience_type, $allowed_types, true ) ) {
                $s_experience_type = 'survey';
                $data['form_type'] = 'survey';
            }
            if ( $s_experience_type !== 'survey' ) {
                $conv = json_decode( $conv_config_raw, true );
                $data['conversation_config'] = is_array( $conv ) ? wp_json_encode( $conv ) : '';
            }

            $data_formats = array_fill( 0, count( $data ), '%s' );

            if ( $editing_id > 0 ) {
                $wpdb->update($table, $data, ['id' => $editing_id], $data_formats, ['%d']);
                if ( class_exists('Estandar_Forms_Shortcode') ) {
                    Estandar_Forms_Shortcode::clear_form_cache($editing_id);
                }
                echo '<div class="updated"><p>Encuesta actualizada.</p></div>';
                $survey = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $editing_id));
            } else {
                $wpdb->insert($table, $data, $data_formats);
                $new_id = (int) $wpdb->insert_id;
                echo '<div class="updated"><p>Encuesta creada. <a href="' . esc_url(admin_url('admin.php?page=estandar-surveys-edit&edit='.$new_id)) . '">Editar</a></p></div>';
                $survey = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $new_id));
                $editing_id = $new_id;
            }
        }
    }

    /* ── Current values ──────────────────────────────────── */
    $cur_name    = $survey ? esc_attr((string)$survey->name)  : '';
    $cur_slug    = $survey ? esc_attr((string)($survey->slug ?? '')) : '';
    $cur_fields  = $survey ? esc_attr((string)$survey->fields_json) : '[]';
    $cur_notify_emails = $survey ? esc_textarea((string)($survey->notify_emails ?? '')) : '';
    $cur_config  = [];
    if ( $survey && !empty($survey->survey_config) ) {
        $cur_config = (array)(json_decode((string)$survey->survey_config, true) ?: []);
    }
    $cur_conversation_config = '';
    if ( $survey && !empty($survey->conversation_config) ) {
        $cur_conversation_config = esc_textarea( json_encode( json_decode( (string) $survey->conversation_config, true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
    }
    $cur_id_mode            = esc_attr((string)($cur_config['identification_mode'] ?? 'anonymous'));
    $cur_hero_title         = esc_attr((string)($cur_config['hero_title'] ?? ''));
    $cur_hero_subtitle      = esc_attr((string)($cur_config['hero_subtitle'] ?? ''));
    $cur_hero_cta           = esc_attr((string)($cur_config['hero_cta'] ?? 'Comenzar'));
    $cur_hero_image         = esc_url((string)($cur_config['hero_image_url'] ?? ''));
    $cur_completion_title   = esc_attr((string)($cur_config['completion_title'] ?? '¡Gracias!'));
    $cur_completion_message = esc_attr((string)($cur_config['completion_message'] ?? 'Tu respuesta fue registrada exitosamente.'));

    $survey_id = $editing_id;
    $public_url = $survey_id > 0 ? home_url('/?estandar_survey_preview='.$survey_id) : '';

    estandar_forms_enqueue_admin_assets(true);
    wp_enqueue_script(
        'estandar-forms-survey-admin',
        ESTANDAR_FORMS_URL . 'assets/js/estandar-survey-admin.js',
        ['jquery','jquery-ui-sortable'],
        ESTANDAR_FORMS_VERSION,
        true
    );

    estandar_forms_render_admin_layout_start();
    ?>
    <?php estandar_forms_render_admin_surface_start(); ?>

    <?php estandar_forms_render_admin_tabs('estandar-surveys'); ?>

    <?php
    // JSON_LIFECYCLE_V1 — mismas pestañas y ciclo canónico que Forms
    // (un survey ES una fila de wp_estandar_forms; misma clase, mismos AJAX).
    $ef_json_lifecycle = $survey && ! empty( $survey->id ) && class_exists( 'Estandar_Forms_Template' );
    if ( $ef_json_lifecycle ) {
        estandar_forms_render_json_lifecycle_tabs();
    }
    ?>

    <div id="ef-visual-root">
    <form method="post" action="" id="estandar-survey-edit-form">
        <?php wp_nonce_field('estandar_survey_save'); ?>
        <input type="hidden" name="estandar_survey_save" value="1" />

        <!-- Datos básicos -->
        <section class="estandar-admin-panel">
            <div class="estandar-admin-panel-header">
                <h3><?php echo $survey_id > 0 ? 'Editando: ' . esc_html( $survey->name ?? '' ) : 'Nueva encuesta'; ?></h3>
                <?php if ( $survey_id > 0 ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-surveys' ) ); ?>" class="button button-secondary">← Volver</a>
                <?php endif; ?>
            </div>
            <table class="form-table">
                <tr>
                    <th><label for="survey_name">Nombre</label></th>
                    <td><input type="text" id="survey_name" name="survey_name" class="regular-text" value="<?php echo $cur_name; ?>" required /></td>
                </tr>
                <tr>
                    <th><label for="survey_slug">Slug</label></th>
                    <td>
                        <input type="text" id="survey_slug" name="survey_slug" class="regular-text" value="<?php echo $cur_slug; ?>" />
                        <p class="description">Shortcode: <code>[estandar_form slug="..."]</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="identification_mode">Identificación</label></th>
                    <td>
                        <select name="identification_mode" id="identification_mode">
                            <option value="anonymous"  <?php selected($cur_id_mode, 'anonymous'); ?>>Anónima — sin datos del respondente</option>
                            <option value="identified" <?php selected($cur_id_mode, 'identified'); ?>>Identificada — nombre y email requeridos</option>
                            <option value="optional"   <?php selected($cur_id_mode, 'optional'); ?>>Opcional — puede saltar la identificación</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="notify_emails">Correos de notificación</label></th>
                    <td>
                        <textarea id="notify_emails" name="notify_emails" class="large-text" rows="2" placeholder="equipo@ejemplo.com, operaciones@ejemplo.com"><?php echo $cur_notify_emails; ?></textarea>
                        <p class="description">Uno o varios correos separados por coma. Si está vacío, no se enviará notificación al completar la encuesta.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="experience_type">Tipo de experiencia</label></th>
                    <td>
                        <?php $cur_form_type = $survey ? sanitize_key((string)($survey->form_type ?? 'survey')) : 'survey'; ?>
                        <select name="experience_type" id="experience_type">
                            <option value="survey" <?php selected($cur_form_type, 'survey'); ?>>Encuesta (Survey)</option>
                            <option value="lead_qualification" <?php selected($cur_form_type, 'lead_qualification'); ?>>Lead Qualification (AltaCruz)</option>
                        </select>
                        <p class="description">MS-6 (ADR 0034): todas las experiencias comparten el mismo motor y render; la diferencia es configuración.</p>
                    </td>
                </tr>
            </table>

            <div id="conversation-config-panel" style="margin-top:12px">
                <h4 style="margin:0 0 6px">Configuración del flujo (conversation_config)</h4>
                <textarea id="conversation_config_json" name="conversation_config_json" class="large-text" rows="8"
                          style="font-family:monospace"><?php echo $cur_conversation_config; ?></textarea>
                <p class="description">JSON con flow (branching), scoring y completion_strategy. Para "Lead Qualification" usa
                    <code>{"completion_strategy":{"type":"lead"}}</code> para emitir <code>estandar_lead_captured</code>.</p>
            </div>
        </section>

        <!-- Pantalla de inicio (hero) -->
        <section class="estandar-admin-panel" style="margin-top:16px">
            <div class="estandar-admin-panel-header"><h3>Pantalla de inicio (Hero)</h3></div>
            <table class="form-table">
                <tr>
                    <th><label for="hero_title">Título</label></th>
                    <td><input type="text" id="hero_title" name="hero_title" class="regular-text" value="<?php echo $cur_hero_title; ?>" placeholder="Cuéntanos tu experiencia" /></td>
                </tr>
                <tr>
                    <th><label for="hero_subtitle">Subtítulo</label></th>
                    <td><input type="text" id="hero_subtitle" name="hero_subtitle" class="regular-text" value="<?php echo $cur_hero_subtitle; ?>" placeholder="Tu opinión nos ayuda a mejorar" /></td>
                </tr>
                <tr>
                    <th><label for="hero_cta">Texto del botón</label></th>
                    <td><input type="text" id="hero_cta" name="hero_cta" class="regular-text" value="<?php echo $cur_hero_cta; ?>" /></td>
                </tr>
                <tr>
                    <th><label for="hero_image_url">Imagen de fondo (URL)</label></th>
                    <td><input type="url" id="hero_image_url" name="hero_image_url" class="large-text" value="<?php echo $cur_hero_image; ?>" /></td>
                </tr>
            </table>
        </section>

        <!-- Pantalla de finalización -->
        <section class="estandar-admin-panel" style="margin-top:16px">
            <div class="estandar-admin-panel-header"><h3>Pantalla de finalización</h3></div>
            <table class="form-table">
                <tr>
                    <th><label for="completion_title">Título</label></th>
                    <td><input type="text" id="completion_title" name="completion_title" class="regular-text" value="<?php echo $cur_completion_title; ?>" /></td>
                </tr>
                <tr>
                    <th><label for="completion_message">Mensaje</label></th>
                    <td><input type="text" id="completion_message" name="completion_message" class="large-text" value="<?php echo $cur_completion_message; ?>" /></td>
                </tr>
            </table>
        </section>

        <!-- Preguntas -->
        <section class="estandar-admin-panel" style="margin-top:16px">
            <div class="estandar-admin-panel-header">
                <h3>Preguntas</h3>
                <div class="estandar-admin-actions" id="survey-add-question-bar">
                    <?php
                    // MS-4 (ADR 0034): catálogo único de tipos del motor (T2). El label por tipo
                    // es UI; el set de tipos proviene de una única fuente.
                    $q_type_labels = [
                        'nps'      => 'NPS (0-7)',
                        'rating'   => 'Rating (estrellas)',
                        'matrix'   => 'Evaluación múltiple',
                        'checkbox' => 'Checkbox múltiple',
                        'emoji'    => 'Emojis',
                        'cards'    => 'Cards de selección',
                        'text'     => 'Texto libre',
                        'select'   => 'Select',
                        'radio'    => 'Radio',
                        'textarea' => 'Texto multilínea',
                    ];
                    $q_catalog = class_exists( 'Estandar_Conversation_Engine' )
                        ? Estandar_Conversation_Engine::question_type_catalog()
                        : array_keys( $q_type_labels );
                    foreach ( $q_catalog as $qt ) : ?>
                    <button type="button" class="button esvy-add-question" data-type="<?php echo esc_attr($qt); ?>">
                        + <?php echo esc_html( $q_type_labels[ $qt ] ?? $qt ); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <input type="hidden" name="fields_json" id="survey-fields-json" value="<?php echo $cur_fields; ?>" />

            <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
                <button type="button" class="button esvy-expand-all" title="Expandir todas">▼ Expandir todas</button>
                <button type="button" class="button esvy-collapse-all" title="Contraer todas">▶ Contraer todas</button>
                <span id="esvy-q-count" style="margin-left:auto;font-size:.82rem;color:#6b7280"></span>
            </div>

            <div id="survey-questions-list" style="margin-top:8px" data-survey-id="<?php echo (int) $survey_id; ?>">
                <!-- Populated by estandar-survey-admin.js -->
            </div>
        </section>

        <!-- URL + QR -->
        <?php if ( $survey_id > 0 ) : ?>
        <section class="estandar-admin-panel" style="margin-top:16px">
            <div class="estandar-admin-panel-header"><h3>URL pública y QR</h3></div>
            <?php $survey_url_here = home_url('?estandar_survey_id=' . $survey_id); ?>
            <table class="form-table">
                <tr>
                    <th>Shortcode</th>
                    <td><code>[estandar_form id="<?php echo $survey_id; ?>"]</code></td>
                </tr>
                <tr>
                    <th>URL directa</th>
                    <td>
                        <input type="text" class="large-text" readonly value="<?php echo esc_attr($survey_url_here); ?>" onclick="this.select()" />
                    </td>
                </tr>
                <tr>
                    <th>Código QR</th>
                    <td>
                        <div id="esvy-qr-container" style="margin-bottom:10px">
                            <img src="<?php echo esc_url('https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($survey_url_here)); ?>"
                                 alt="QR Survey" width="220" height="220"
                                 style="border:1px solid #e5e7eb;border-radius:8px;padding:8px;background:#fff" />
                        </div>
                        <a href="<?php echo esc_url('https://api.qrserver.com/v1/create-qr-code/?size=600x600&format=png&data=' . rawurlencode($survey_url_here)); ?>"
                           class="button" download="qr-survey-<?php echo $survey_id; ?>.png" target="_blank">
                            ⬇ Descargar QR (600×600 PNG)
                        </a>
                    </td>
                </tr>
            </table>
        </section>
        <?php endif; ?>

        <div style="margin-top:20px;padding-bottom:32px">
            <button type="submit" class="button button-primary button-large">
                <?php echo $survey_id > 0 ? 'Actualizar encuesta' : 'Crear encuesta'; ?>
            </button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=estandar-surveys')); ?>"
               class="button button-large" style="margin-left:8px">Cancelar</a>
        </div>

    </form>
    </div>

    <?php
    if ( $ef_json_lifecycle ) {
        estandar_forms_render_json_lifecycle_panels( $survey, '#ef-visual-root' );
    }
    estandar_forms_render_admin_surface_end();
    estandar_forms_render_admin_layout_end();
}

/* ── Duplicate handler ──────────────────────────────────── */
add_action('admin_post_estandar_survey_duplicate', function () {
    if ( ! current_user_can('manage_options') ) { wp_die('No autorizado'); }

    $survey_id = isset($_GET['survey_id']) ? (int)$_GET['survey_id'] : 0;
    check_admin_referer('estandar_survey_duplicate_' . $survey_id);

    global $wpdb;
    $table  = $wpdb->prefix . 'estandar_forms';
    $source = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND form_type = 'survey'", $survey_id));
    if ( ! $source ) {
        wp_safe_redirect(admin_url('admin.php?page=estandar-surveys'));
        exit;
    }

    $new_name = sanitize_text_field((string)$source->name) . ' (copia)';
    $new_slug = sanitize_title($new_name) . '-' . time();

    $wpdb->insert($table, [
        'name'          => $new_name,
        'slug'          => $new_slug,
        'form_type'     => 'survey',
        'fields_json'   => $source->fields_json,
        'survey_config' => $source->survey_config ?? null,
        'notify_emails' => $source->notify_emails ?? '',
    ], ['%s','%s','%s','%s','%s','%s']);

    $new_id = (int)$wpdb->insert_id;
    wp_safe_redirect(admin_url('admin.php?page=estandar-surveys-edit&edit='.$new_id.'&duplicated=1'));
    exit;
});
