<?php
if ( ! defined('ABSPATH') ) exit;

// JSON_LIFECYCLE_V1 — UI canónica compartida con Surveys (tabs/panels/JS).
require_once __DIR__ . '/../partials/json-lifecycle-panel.php';

function estandar_forms_dashboard() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('No autorizado');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'estandar_forms';

    /* =====================
       CONTEXTO
    ====================== */
    $editing_id  = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
    $deleting_id = isset($_GET['delete']) ? intval($_GET['delete']) : 0;
    $diagnostic  = isset($_GET['diagnostic']) ? sanitize_key((string) wp_unslash($_GET['diagnostic'])) : '';

    /* =====================
       ELIMINAR FORMULARIO
    ====================== */
    if (
        $deleting_id &&
        isset($_GET['_wpnonce']) &&
        wp_verify_nonce((string) wp_unslash($_GET['_wpnonce']), 'estandar_forms_delete_'.$deleting_id)
    ) {
        $deleted_form = $wpdb->get_row(
            $wpdb->prepare("SELECT id, slug FROM $table WHERE id = %d", $deleting_id)
        );
        $wpdb->delete($table, ['id' => $deleting_id], ['%d']);
        if ( class_exists('Estandar_Forms_Shortcode') ) {
            Estandar_Forms_Shortcode::clear_form_cache($deleted_form ?: $deleting_id);
        }
        echo '<div class="updated"><p>Formulario eliminado.</p></div>';
        $editing_id = 0;
    }

    /* =====================
       GUARDAR / ACTUALIZAR
    ====================== */
    if ( isset($_POST['estandar_forms_save']) ) {

        check_admin_referer('estandar_forms_save');
        $can_persist = true;

        $form_name   = sanitize_text_field( wp_unslash($_POST['form_name'] ?? '') );
        $form_slug   = sanitize_title( wp_unslash($_POST['form_slug'] ?? '') );
        $fields_json = wp_unslash($_POST['fields_json'] ?? '[]');
        $fields = estandar_forms_decode_fields($fields_json);
        if ( ! is_array($fields) ) {
            echo '<div class="notice notice-error"><p>La definición de campos no es válida.</p></div>';
            $fields_json = '[]';
        } else {
            $fields_json = wp_json_encode(estandar_forms_normalize_fields($fields));
        }

        if ( $form_name === '' ) {
            echo '<div class="notice notice-error"><p>Debes indicar un nombre para el formulario.</p></div>';
            $can_persist = false;
        }

        if ( empty($fields) ) {
            echo '<div class="notice notice-error"><p>Debes agregar al menos un campo antes de guardar.</p></div>';
            $can_persist = false;
        }

        if ( $form_slug === '' && $form_name !== '' ) {
            $form_slug = sanitize_title($form_name);
        }

        $webhook_payload_mode = sanitize_key( wp_unslash( $_POST['webhook_payload_mode'] ?? 'estandar' ) );
        if ( ! in_array( $webhook_payload_mode, [ 'estandar', 'cf7_compat' ], true ) ) {
            $webhook_payload_mode = 'estandar';
        }
        $form_type = sanitize_key( wp_unslash( $_POST['form_type'] ?? 'lead_capture' ) );
        if ( $form_type === 'lead' ) {
            $form_type = 'lead_capture';
        }
        if ( ! in_array( $form_type, [ 'general', 'lead_capture', 'feedback', 'postventa', 'soporte', 'email_direct', 'experience', 'survey', 'incident' ], true ) ) {
            $form_type = 'general';
        }
        $routing_mode = sanitize_key( wp_unslash( $_POST['routing_mode'] ?? 'none' ) );
        // Routing V2: accept only canonical modes.
        if ( ! in_array( $routing_mode, [ 'none', 'fixed_project', 'project_selector' ], true ) ) {
            $routing_mode = 'none';
        }
        $routing_project_slug = sanitize_title( remove_accents( wp_unslash( $_POST['routing_project_slug'] ?? '' ) ) );
        $routing_project_name = sanitize_text_field( wp_unslash( $_POST['routing_project_name'] ?? '' ) );
        $selector_projects_save = [];   // used by project_selector mode

        if ( $form_type === 'lead_capture' ) {
            if ( $routing_mode === 'none' ) {
                $project_registry_lead = estandar_forms_project_registry();
                if ( empty($project_registry_lead) ) {
                    echo '<div class="notice notice-error"><p>No hay proyectos en el registro. Cambia el tipo a <strong>Email directo</strong> o crea un proyecto primero.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Este formulario captura leads. Debe asociarse a un proyecto antes de guardar.</p></div>';
                }
                $can_persist = false;
            }
            if ( $routing_mode === 'fixed_project' ) {
                if ( function_exists('estandar_forms_project_belongs_to_current_client') && ! estandar_forms_project_belongs_to_current_client($routing_project_slug) ) {
                    echo '<div class="notice notice-error"><p>El proyecto seleccionado no pertenece al cliente actual o no está disponible en Project Registry.</p></div>';
                    $can_persist = false;
                }
                $routing_preview = function_exists('estandar_forms_project_operator_preview')
                    ? estandar_forms_project_operator_preview($routing_project_slug)
                    : array('status' => 'missing_operator');
                if ( $routing_project_slug === '' || ($routing_preview['status'] ?? '') !== 'ok' ) {
                    echo '<div class="notice notice-error"><p>El proyecto seleccionado debe tener operador activo y email antes de guardar.</p></div>';
                    $can_persist = false;
                }
                foreach ( estandar_forms_project_registry() as $project ) {
                    if ( (string) ($project['project_slug'] ?? '') === $routing_project_slug ) {
                        $routing_project_name = sanitize_text_field((string) ($project['name'] ?? $routing_project_slug));
                        break;
                    }
                }
            }
            if ( $routing_mode === 'project_selector' ) {
                // Read checked project slugs; V1 labels always come from Project Registry.
                $checked_slugs  = array_map(
                    fn($s) => sanitize_title(remove_accents((string) $s)),
                    (array) wp_unslash($_POST['selector_project_slugs'] ?? [])
                );
                $registry_index = [];
                foreach ( estandar_forms_project_registry() as $p ) {
                    $registry_index[(string) ($p['project_slug'] ?? '')] = (string) ($p['name'] ?? '');
                }

                foreach ( $checked_slugs as $slug ) {
                    if ( $slug === '' || ! isset($registry_index[$slug]) ) continue;
                    // Validate belongs to client
                    if ( function_exists('estandar_forms_project_belongs_to_current_client') && ! estandar_forms_project_belongs_to_current_client($slug) ) {
                        echo '<div class="notice notice-error"><p>El proyecto <strong>' . esc_html($registry_index[$slug] ?? $slug) . '</strong> no pertenece al cliente actual.</p></div>';
                        $can_persist = false;
                        continue;
                    }
                    $selector_projects_save[] = ['slug' => $slug, 'label' => $registry_index[$slug]];
                }
                if ( empty($selector_projects_save) && $can_persist ) {
                    echo '<div class="notice notice-error"><p>El selector de proyecto requiere al menos un proyecto seleccionado.</p></div>';
                    $can_persist = false;
                }
            }
        }

        // Build context_schema
        $routing_data = ['mode' => $routing_mode];
        if ( $routing_mode === 'fixed_project' ) {
            $routing_data['project_slug'] = $routing_project_slug;
            $routing_data['project_name'] = $routing_project_name;
        }
        if ( $routing_mode === 'project_selector' ) {
            $routing_data['projects'] = $selector_projects_save;
        }
        // JSON_LIFECYCLE_V1 — el editor visual solo es dueño de la clave
        // `routing`: las demás claves de context_schema (extensions, metadata
        // creada vía editor JSON) se preservan, nunca se destruyen.
        $existing_context = [];
        $posted_form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
        if ( $posted_form_id ) {
            $existing_raw = $wpdb->get_var(
                $wpdb->prepare("SELECT context_schema FROM $table WHERE id = %d", $posted_form_id)
            );
            $decoded_context = is_string($existing_raw) && $existing_raw !== '' ? json_decode($existing_raw, true) : [];
            if ( is_array($decoded_context) ) {
                $existing_context = $decoded_context;
            }
        }
        $existing_context['routing'] = $routing_data;
        $context_schema = wp_json_encode($existing_context);
        $notify_emails = sanitize_textarea_field( wp_unslash( $_POST['notify_emails'] ?? '' ) );

        $data = [
            'name'              => $form_name,
            'slug'              => $form_slug,
            'fields_json'       => $fields_json,
            'webhook_url'       => esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) ),
            'webhook_payload_mode' => $webhook_payload_mode,
            'notify_emails'     => $notify_emails,
            'form_country'      => estandar_forms_get_form_country(['form_country' => wp_unslash($_POST['form_country'] ?? 'CL')]),
            'store_entries'     => 1,
            'send_email'        => 'email_direct' === $form_type ? 1 : 0,
            'send_webhook'      => 'email_direct' === $form_type ? 0 : ( ! empty( $_POST['send_webhook'] ) ? 1 : 0 ),
            'require_recaptcha' => ! empty($_POST['require_recaptcha']) ? 1 : 0,
            'mail_subject'      => sanitize_text_field( wp_unslash( $_POST['mail_subject'] ?? '' ) ),
            'mail_body'         => '',
            'mail_sender_name'  => sanitize_text_field( wp_unslash( $_POST['mail_sender_name'] ?? '' ) ),
            'mail_reply_to_field' => '',
            'context_schema'    => $context_schema,
            'form_type'         => $form_type,
            'success_message'   => wp_kses_post( wp_unslash($_POST['success_message'] ?? '') ),
            'submit_label'      => sanitize_text_field( wp_unslash($_POST['submit_label'] ?? 'Enviar') ),
            'after_submit_text' => wp_kses_post( wp_unslash($_POST['after_submit_text'] ?? '') ),
            'visual_theme'      => sanitize_key( wp_unslash($_POST['visual_theme'] ?? '') ),
        ];

        $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;

        if ( $can_persist && $form_id ) {
            $previous_form = $wpdb->get_row(
                $wpdb->prepare("SELECT id, slug FROM $table WHERE id = %d", $form_id)
            );

            $wpdb->update(
                $table,
                $data,
                ['id' => $form_id],
                ['%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s'],
                ['%d']
            );

            if ( false === $wpdb->last_error || $wpdb->last_error === '' ) {
                echo '<div class="updated"><p>Formulario actualizado.</p></div>';
            } else {
                $render_persist_error( 'update', (string) $wpdb->last_error );
            }

            if ( class_exists('Estandar_Forms_Shortcode') ) {
                if ( $previous_form ) {
                    Estandar_Forms_Shortcode::clear_form_cache($previous_form);
                }
                Estandar_Forms_Shortcode::clear_form_cache(
                    [
                        'id'   => $form_id,
                        'slug' => $data['slug'],
                    ]
                );
            }

            $editing_id = $form_id;

        } elseif ( $can_persist ) {

            $wpdb->insert(
                $table,
                $data,
                ['%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']
            );

            if ( false === $wpdb->last_error || $wpdb->last_error === '' ) {
                echo '<div class="updated"><p>Formulario creado.</p></div>';
            } else {
                $render_persist_error( 'insert', (string) $wpdb->last_error );
            }

            if ( class_exists('Estandar_Forms_Shortcode') ) {
                Estandar_Forms_Shortcode::clear_form_cache(
                    [
                        'id'   => (int) $wpdb->insert_id,
                        'slug' => $data['slug'],
                    ]
                );
            }

            $editing_id = intval($wpdb->insert_id);
        }
    }

    /* =====================
       CARGAR FORMULARIO (EDICIÓN)
    ====================== */
    $current = null;
    if ( $editing_id ) {
        $current = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $editing_id)
        );
    }
    $current_country = estandar_forms_get_form_country($current ?: ['form_country' => 'CL']);
    $current_fields = $current && ! empty($current->fields_json)
        ? estandar_forms_decode_fields((string) $current->fields_json)
        : array();
    $current_has_project_context = estandar_forms_fields_define_project_context(is_array($current_fields) ? $current_fields : array());
    $current_routing = estandar_forms_routing_context($current->context_schema ?? '');
    $project_registry = estandar_forms_project_registry();
    $routing_preview = estandar_forms_project_operator_preview((string) ($current_routing['project_slug'] ?? ''));

    /* =====================
       LISTAR FORMULARIOS
    ====================== */
    $forms = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
    estandar_forms_enqueue_admin_assets(true);
    ?>

    <?php estandar_forms_render_admin_layout_start(); ?>
        <?php estandar_forms_render_admin_branding( 'Forms', 'Configura formularios ligeros, ordena campos por arrastre y publica con shortcode.' ); ?>
        <?php estandar_forms_render_admin_surface_start(); ?>

        <?php estandar_forms_render_admin_tabs('estandar-forms'); ?>
        <?php estandar_forms_render_notice_from_request(); ?>

        <?php if ( $current && 'routing_issue' === $diagnostic ) : ?>
            <div class="notice notice-warning">
                <p>Estás corrigiendo un problema de routing detectado por Integridad Operacional. Configura un proyecto válido y guarda los cambios.</p>
            </div>
        <?php endif; ?>


        <?php
        // JSON_LIFECYCLE_V1 — pestañas de primer nivel del editor.
        // Visual = editor existente · JSON = editor canónico de desarrollador ·
        // Vista previa = render real · Historial = fundación de revisiones.
        // UI compartida con Surveys: admin/partials/json-lifecycle-panel.php.
        $ef_json_lifecycle = $current && ! empty( $current->id ) && class_exists( 'Estandar_Forms_Template' );
        if ( $ef_json_lifecycle ) {
            estandar_forms_render_json_lifecycle_tabs();
        }
        ?>

        <div class="estandar-admin-layout">
            <div class="estandar-admin-main">
                <h2><?php echo $current ? 'Editar formulario' : 'Crear nuevo formulario'; ?></h2>

                <form method="post" id="estandar-form-builder" enctype="multipart/form-data">
                    <?php wp_nonce_field('estandar_forms_save'); ?>

                    <input type="hidden" name="form_id" id="form_id" value="<?php echo esc_attr($current->id ?? 0); ?>">

                    <section class="estandar-admin-panel">
                        <div class="estandar-admin-panel-header">
                            <div>
                                <h3>Identidad</h3>
                                <p>Nombre, slug y configuración pública del formulario.</p>
                            </div>
                        </div>

                        <table class="form-table">

                            <tr>
                                <th>Nombre del formulario</th>
                                <td>
                                    <input
                                        type="text"
                                        name="form_name"
                                        id="form_name"
                                        required
                                        class="regular-text"
                                        value="<?php echo esc_attr($current->name ?? ''); ?>"
                                    >
                                    <p class="description">Nombre interno para administrarlo en WordPress.</p>
                                </td>
                            </tr>

                            <tr>
                                <th>Slug del formulario</th>
                                <td>
                                    <input
                                        type="text"
                                        name="form_slug"
                                        id="form_slug"
                                        class="regular-text"
                                        value="<?php echo esc_attr($current->slug ?? ''); ?>"
                                        placeholder="ej: postventa, contacto, reclamos"
                                    >
                                    <p class="description">
                                        Identificador editable usado en el shortcode y en integraciones.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>Tema visual</th>
                                <td>
                                    <select name="visual_theme" id="visual_theme" class="regular-text">
                                        <option value="" <?php selected((string) ($current->visual_theme ?? ''), ''); ?>>Base</option>
                                    </select>
                                    <p class="description">
                                        Preset visual portable para adaptar renderer y estilos públicos.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>Pais del formulario</th>
                                <td>
                                    <select name="form_country" id="form_country" class="regular-text">
                                        <?php foreach ( estandar_forms_country_options() as $country_code => $country_label ): ?>
                                            <option value="<?php echo esc_attr($country_code); ?>" <?php selected($current_country, $country_code); ?>>
                                                <?php echo esc_html($country_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        Se usa para validar telefonos y adaptar placeholders del formulario.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>Destino del formulario</th>
                                <td>
                                    <?php
                                    $current_form_type = sanitize_key( (string) ( $current->form_type ?? 'lead_capture' ) );
                                    if ( $current_form_type === 'lead' ) {
                                        $current_form_type = 'lead_capture';
                                    }
                                    $current_operability = $current ? estandar_forms_operability_status($current) : null;
                                    ?>
                                    <label class="estandar-inline-check" style="display:block;margin-bottom:6px;">
                                        <input type="radio" name="form_type" value="lead_capture" <?php checked( $current_form_type === '' ? 'lead_capture' : $current_form_type, 'lead_capture' ); ?>>
                                        Lead Capture
                                    </label>
                                    <?php if ( $current_form_type === 'general' ) : // preserve if already set, but don't offer as new choice ?>
                                    <label class="estandar-inline-check" style="display:block;margin-bottom:6px;color:#999;">
                                        <input type="radio" name="form_type" value="general" <?php checked( $current_form_type, 'general' ); ?>>
                                        General <em style="font-size:11px;">(deprecado — usa Lead Capture + Selector de empresa)</em>
                                    </label>
                                    <?php endif; ?>
                                    <label class="estandar-inline-check" style="display:block;margin-bottom:6px;">
                                        <input type="radio" name="form_type" value="email_direct" <?php checked( $current_form_type, 'email_direct' ); ?>>
                                        Email directo
                                    </label>
                                    <p class="description">
                                        <strong>Lead Capture</strong>: requiere routing a un proyecto. Genera lead en CRM y notifica al operador del proyecto.<br>
                                        <strong>Email directo</strong>: envía el formulario por correo. No usa Leads ni operadores. Ideal para contactos simples.<br>
                                        <strong>General</strong>: formulario independiente, sin CRM. Solo disponible para formularios existentes.
                                    </p>
                                    <?php if ( $current_operability ) : ?>
                                        <div class="notice <?php echo esc_attr((string) $current_operability['class']); ?> inline" style="margin:10px 0 0;">
                                            <p>
                                                <strong>Estado operativo: <?php echo esc_html((string) $current_operability['label']); ?></strong><br>
                                                <?php echo esc_html((string) $current_operability['message']); ?>
                                            </p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ( $current_form_type === 'general' ) : ?>
                                        <div class="notice notice-info inline" style="margin:10px 0 0;">
                                            <p><strong>Formulario General.</strong> No genera Leads, no notifica operadores y no entra al CRM.</p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ( $editing_id && $current_form_type === 'lead_capture' && ! $current_has_project_context && ($current_routing['mode'] ?? 'none') === 'none' ) : ?>
                                        <div class="notice notice-warning inline" style="margin:10px 0 0;">
                                            <p><strong>Routing Context no definido.</strong> Este formulario no debe publicarse hasta asociarlo a un proyecto o selector visible.</p>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                            $rf_mode         = (string) ($current_routing['mode'] ?? 'none');
                            $rf_registry_url = admin_url('admin.php?page=estandar_projects');
                            $rf_client_slug  = function_exists('estandar_core_get_client_slug') ? estandar_core_get_client_slug() : '';
                            $rf_create_url   = $rf_client_slug !== ''
                                ? add_query_arg('project_id', $rf_client_slug, $rf_registry_url)
                                : $rf_registry_url;

                            $rf_form_types_needing_routing = ['lead_capture', 'lead', 'feedback', 'postventa', 'soporte', 'incident'];
                            $rf_needs_routing = in_array($current_form_type, $rf_form_types_needing_routing, true);
                            ?>
                            <tr id="ef-routing-row" style="<?php echo $rf_needs_routing ? '' : 'display:none;'; ?>">
                                <th>Routing Context</th>
                                <td>
                                    <?php if ( empty($project_registry) ) : ?>
                                        <div class="notice notice-warning inline" style="margin:0 0 12px;">
                                            <p>
                                                No hay proyectos en el registro.
                                                <a href="<?php echo esc_url($rf_create_url); ?>" target="_blank">→ Crear primer proyecto</a>
                                            </p>
                                        </div>
                                    <?php endif; ?>

                                    <!-- ── Radios Routing V2 ────────────────────────────────── -->
                                    <label class="estandar-inline-check" style="display:block;margin-bottom:6px;">
                                        <input type="radio" name="routing_mode" value="none"
                                               <?php checked($rf_mode, 'none'); ?>>
                                        Sin routing
                                    </label>
                                    <label class="estandar-inline-check" style="display:block;margin-bottom:6px;">
                                        <input type="radio" name="routing_mode" value="fixed_project"
                                               <?php checked($rf_mode, 'fixed_project'); ?>>
                                        Proyecto único
                                    </label>
                                    <label class="estandar-inline-check" style="display:block;margin-bottom:14px;">
                                        <input type="radio" name="routing_mode" value="project_selector"
	                                               <?php checked($rf_mode, 'project_selector'); ?>>
                                        Selección de proyecto
                                    </label>

                                    <!-- ── Bloque: Proyecto único ───────────────────────────── -->
                                    <div id="ef-fixed-block"
                                         style="<?php echo $rf_mode !== 'fixed_project' ? 'display:none;' : ''; ?>">
                                        <select name="routing_project_slug" id="routing_project_slug"
                                                class="regular-text" style="min-width:260px;">
                                            <option value="">— Seleccionar proyecto —</option>
                                            <?php foreach ( $project_registry as $rp ) :
                                                $rp_slug = sanitize_title(remove_accents((string) ($rp['project_slug'] ?? '')));
                                                $rp_name = sanitize_text_field((string) ($rp['name'] ?? $rp_slug));
                                                if ( $rp_slug === '' ) continue;
                                            ?>
                                                <option value="<?php echo esc_attr($rp_slug); ?>"
                                                        <?php selected((string) ($current_routing['project_slug'] ?? ''), $rp_slug); ?>>
                                                    <?php echo esc_html($rp_name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" name="routing_project_name" id="routing_project_name"
                                               value="<?php echo esc_attr((string) ($current_routing['project_name'] ?? '')); ?>">
                                        <p style="margin:6px 0 0;">
                                            <a href="<?php echo esc_url($rf_create_url); ?>" target="_blank"
                                               class="button button-small">+ Crear proyecto</a>
                                            <a href="<?php echo esc_url($rf_registry_url); ?>" target="_blank"
                                               style="font-size:12px;margin-left:8px;">Administrar proyectos</a>
                                        </p>
                                        <?php if ( $rf_mode === 'fixed_project' ) :
                                            $rp_preview = function_exists('estandar_forms_project_operator_preview')
                                                ? estandar_forms_project_operator_preview((string) ($current_routing['project_slug'] ?? ''))
                                                : ['status' => 'unknown'];
                                        ?>
                                        <div class="notice <?php echo ($rp_preview['status'] ?? '') === 'ok' ? 'notice-success' : 'notice-warning'; ?> inline"
                                             style="margin:10px 0 0;">
                                            <p>
                                                <?php echo esc_html((string) ($current_routing['project_name'] ?: $current_routing['project_slug'])); ?>
                                                &nbsp;·&nbsp;
                                                Operador: <strong><?php echo esc_html((string) ($rp_preview['operator_name'] ?: 'NO RESUELTO')); ?></strong>
                                                &nbsp;·&nbsp;
                                                <?php echo esc_html((string) ($rp_preview['status'] ?? '')); ?>
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- ── Bloque: Selección de proyecto ────────────────────── -->
                                    <?php
                                    // Build index of currently selected projects for this form
                                    $rf_current_selector = (array) ($current_routing['selector_projects'] ?? []);
                                    $rf_selected_index   = []; // slug => selected
                                    foreach ( $rf_current_selector as $sp ) {
                                        $rf_selected_index[(string) ($sp['slug'] ?? '')] = true;
                                    }
                                    ?>
                                    <div id="ef-selector-block"
	                                         style="<?php echo $rf_mode !== 'project_selector' ? 'display:none;' : ''; ?>">
                                        <?php if ( empty($project_registry) ) : ?>
                                            <div class="notice notice-warning inline" style="margin:0 0 12px;">
                                                <p>
                                                    No hay proyectos en el registro.
                                                    <a href="<?php echo esc_url($rf_create_url); ?>" target="_blank">→ Crear primer proyecto</a>
                                                </p>
                                            </div>
                                        <?php else : ?>
                                            <p class="description" style="margin:0 0 10px;">
                                                Selecciona los proyectos que aparecerán como opciones en el formulario.
                                                El selector es generado automáticamente por el sistema.
                                            </p>
                                            <table class="widefat" style="max-width:520px;">
                                                <thead>
                                                    <tr>
                                                        <th style="width:32px;"></th>
                                                        <th style="font-size:12px;">Proyecto</th>
	                                                    </tr>
	                                                </thead>
                                                <tbody>
                                                <?php foreach ( $project_registry as $i => $rp ) :
                                                    $rp_slug    = sanitize_title(remove_accents((string) ($rp['project_slug'] ?? '')));
                                                    $rp_name    = sanitize_text_field((string) ($rp['name'] ?? $rp_slug));
                                                    $rp_checked = isset($rf_selected_index[$rp_slug]);
	                                                    if ( $rp_slug === '' ) continue;
	                                                ?>
                                                    <tr>
                                                        <td style="padding:6px 8px;">
                                                            <input type="checkbox"
                                                                   name="selector_project_slugs[]"
                                                                   value="<?php echo esc_attr($rp_slug); ?>"
                                                                   <?php checked($rp_checked); ?>>
                                                        </td>
                                                        <td style="padding:6px 8px;">
                                                            <?php echo esc_html($rp_name); ?>
                                                        </td>
	                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                            <p style="margin:10px 0 0;">
                                                <a href="<?php echo esc_url($rf_create_url); ?>" target="_blank"
                                                   class="button button-small">+ Crear proyecto</a>
                                                <a href="<?php echo esc_url($rf_registry_url); ?>" target="_blank"
                                                   style="font-size:12px;margin-left:8px;">Administrar proyectos</a>
	                                            </p>
	                                            <p class="description" style="margin-top:8px;">
	                                                El selector muestra el nombre del proyecto registrado.
	                                                Si el usuario selecciona una opción sin proyecto asignado, el formulario rechaza el envío.
	                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <script>
                                    (function() {
                                        var routingRadios = document.querySelectorAll('input[name="routing_mode"]');
                                        var fixedBlock   = document.getElementById('ef-fixed-block');
                                        var selectorBlock = document.getElementById('ef-selector-block');
                                        function syncBlocks() {
                                            var checked = document.querySelector('input[name="routing_mode"]:checked');
                                            var mode = checked ? checked.value : 'none';
                                            if (fixedBlock)    fixedBlock.style.display    = mode === 'fixed_project'    ? '' : 'none';
                                            if (selectorBlock) selectorBlock.style.display = mode === 'project_selector' ? '' : 'none';
                                        }
                                        routingRadios.forEach(function(r) { r.addEventListener('change', syncBlocks); });

                                        var formTypeRadios = document.querySelectorAll('input[name="form_type"]');
                                        var routingRow = document.getElementById('ef-routing-row');
                                        var emailRow = document.getElementById('ef-email-row');
                                        var typesNeedingRouting = ['lead_capture', 'lead', 'feedback', 'postventa', 'soporte', 'incident'];
                                        var typesNeedingEmail = ['lead_capture', 'email_direct'];
                                        function syncFormType() {
                                            var checked = document.querySelector('input[name="form_type"]:checked');
                                            var type = checked ? checked.value : '';
                                            var needsRouting = typesNeedingRouting.indexOf(type) !== -1;
                                            var needsEmail = typesNeedingEmail.indexOf(type) !== -1;
                                            if (routingRow) {
                                                routingRow.style.display = needsRouting ? '' : 'none';
                                            }
                                            if (emailRow) {
                                                emailRow.style.display = needsEmail ? '' : 'none';
                                            }
                                        }
                                        formTypeRadios.forEach(function(r) { r.addEventListener('change', syncFormType); });
                                    })();
                                    </script>
                                </td>
                            </tr>
                            <tr id="ef-email-row" style="<?php echo $current_form_type === 'email_direct' || $current_form_type === 'lead_capture' ? '' : 'display:none;'; ?>">
                                <th>Notificación por correo</th>
                                <td>
                                    <label for="notify_emails" style="display:block;font-weight:600;margin-bottom:4px;">Correo destinatario</label>
                                    <textarea
                                        name="notify_emails"
                                        id="notify_emails"
                                        class="large-text"
                                        rows="2"
                                        placeholder="contacto@ejemplo.cl"
                                    ><?php echo esc_textarea( $current->notify_emails ?? '' ); ?></textarea>
                                    <p class="description">Uno o más correos separados por coma o salto de línea.</p>

                                    <label for="mail_subject" style="display:block;font-weight:600;margin:10px 0 4px;">Asunto</label>
                                    <input
                                        type="text"
                                        name="mail_subject"
                                        id="mail_subject"
                                        class="regular-text"
                                        value="<?php echo esc_attr( $current->mail_subject ?? '' ); ?>"
                                        placeholder="Nuevo mensaje desde {{form_name}}"
                                    >

                                    <label for="mail_sender_name" style="display:block;font-weight:600;margin:10px 0 4px;">Nombre remitente</label>
                                    <input
                                        type="text"
                                        name="mail_sender_name"
                                        id="mail_sender_name"
                                        class="regular-text"
                                        value="<?php echo esc_attr( $current->mail_sender_name ?? '' ); ?>"
                                        placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
                                    >
                                    <p class="description">Se usa como nombre del remitente en el correo. Si se deja vacío, se usará el nombre del sitio.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Protección</th>
                                <td>
                                    <label class="estandar-inline-check">
                                        <input type="checkbox" name="require_recaptcha" value="1" <?php checked((int) ($current->require_recaptcha ?? 1), 1); ?>>
                                        Requerir reCAPTCHA si está disponible
                                    </label>
                                    <p class="description">
                                        El formulario mantiene nonce, honeypot, rate limit y validación de campos.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>Mensaje de éxito</th>
                                <td>
                                    <textarea
                                        name="success_message"
                                        class="large-text"
                                        rows="3"
                                        placeholder="Mensaje que verá el usuario tras enviar el formulario"
                                    ><?php echo esc_textarea($current->success_message ?? ''); ?></textarea>

                                    <p class="description">
                                        Si se deja vacío, se mostrará el mensaje por defecto.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th>Texto del botón de envío</th>
                                <td>
                                    <input
                                        type="text"
                                        name="submit_label"
                                        class="regular-text"
                                        value="<?php echo esc_attr($current->submit_label ?? 'Enviar'); ?>"
                                    >
                                    <p class="description">
                                        Texto principal del CTA.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th>Texto posterior al botón</th>
                                <td>
                                    <textarea
                                        name="after_submit_text"
                                        class="large-text"
                                        rows="3"
                                        placeholder="Texto opcional bajo el botón"
                                    ><?php echo esc_textarea($current->after_submit_text ?? ''); ?></textarea>

                                    <p class="description">
                                        Texto complementario bajo el botón de envío.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th>Webhook</th>
                                <td>
                                    <label class="estandar-inline-check">
                                        <input type="checkbox" name="send_webhook" value="1" <?php checked( (int) ( $current->send_webhook ?? 0 ), 1 ); ?>>
                                        Enviar datos a URL externa al recibir un envío
                                    </label>
                                    <p class="description" style="margin-bottom:8px;">
                                        Útil para integrar con n8n, Make, Zapier o cualquier CRM via HTTP POST (JSON).
                                    </p>
                                    <input
                                        type="url"
                                        name="webhook_url"
                                        class="large-text"
                                        placeholder="https://hook.make.com/... o https://n8n.yourdomain.com/webhook/..."
                                        value="<?php echo esc_attr( $current->webhook_url ?? '' ); ?>"
                                    >
                                    <p class="description">
                                        Recibe el payload completo en formato JSON (schema v1.0: contact, attribution, context, meta).
                                        El envío es asíncrono — no afecta la velocidad del formulario.
                                    </p>
                                    <label for="webhook_payload_mode" style="display:block;margin-top:10px;font-weight:600;">Formato de payload</label>
                                    <select id="webhook_payload_mode" name="webhook_payload_mode">
                                        <?php $webhook_payload_mode = sanitize_key( (string) ( $current->webhook_payload_mode ?? 'estandar' ) ); ?>
                                        <option value="estandar" <?php selected( $webhook_payload_mode, 'estandar' ); ?>>Estandar JSON</option>
                                        <option value="cf7_compat" <?php selected( $webhook_payload_mode, 'cf7_compat' ); ?>>CF7 / n8n compatible</option>
                                    </select>
                                    <p class="description">
                                        CF7/n8n envía campos tipo your-name, your-apellido, your-email, your-phone y tu-rut.
                                    </p>
                                </td>
                            </tr>

                        </table>
                    </section>

                    <section class="estandar-admin-panel">
                        <div class="estandar-admin-panel-header">
                            <div>
                                <h3>Constructor de campos</h3>
                                <p>Puedes arrastrar para ordenar y plegar cada campo para trabajar más rápido.</p>
                            </div>
                            <div class="estandar-builder-toolbar">
                                <button type="button" class="button button-primary" id="add-field">
                                    + Agregar campo
                                </button>
                            </div>
                        </div>

                        <div class="estandar-field-quick-add">
                            <button type="button" class="button estandar-quick-add" data-type="text">Texto</button>
                            <button type="button" class="button estandar-quick-add" data-type="email">Email</button>
                            <button type="button" class="button estandar-quick-add" data-type="tel">Teléfono</button>
                            <button type="button" class="button estandar-quick-add" data-type="textarea">Textarea</button>
                            <button type="button" class="button estandar-quick-add" data-type="select">Select</button>
                            <button type="button" class="button estandar-quick-add" data-type="radio">Radio</button>
                            <button type="button" class="button estandar-quick-add" data-type="file">Archivo</button>
                            <button type="button" class="button estandar-quick-add" data-type="heading">Título</button>
                            <button type="button" class="button estandar-quick-add" data-type="html">HTML</button>
                            <button type="button" class="button estandar-quick-add" data-type="ubicacion">📍 Ubicación</button>
                        </div>

                        <div class="estandar-builder-summary">
                            <span><strong id="estandar-field-count">0</strong> campos configurados</span>
                            <span id="estandar-builder-hint">Agrega un campo para comenzar.</span>
                        </div>

                        <div id="fields-builder"></div>
                        <div id="fields-empty-state" class="estandar-empty-state" hidden>
                            <h4>Tu formulario todavía no tiene campos</h4>
                            <p>Usa los botones rápidos o agrega uno nuevo para empezar a construir.</p>
                        </div>
                    </section>

                    <section class="estandar-admin-panel">
                        <div class="estandar-admin-panel-header">
                            <div>
                                <h3>Vista previa</h3>
                                <p>Referencia visual rápida del formulario antes de guardar.</p>
                            </div>
                        </div>
                        <div id="form-preview"></div>
                    </section>

                    <input type="hidden" name="fields_json" id="fields_json">
                    <input type="hidden" name="estandar_forms_save" value="1">

                    <div class="estandar-admin-submit">
                        <?php submit_button($current ? 'Guardar cambios' : 'Crear formulario', 'primary large', 'submit', false); ?>
                    </div>
                </form>
            </div>

            <aside class="estandar-admin-sidebar">
                <section class="estandar-admin-panel estandar-admin-panel-sticky">
                    <div class="estandar-admin-panel-header">
                        <div>
                            <h3>Publicación</h3>
                            <p>Usa estos datos para insertar el formulario.</p>
                        </div>
                    </div>
                    <div class="estandar-side-block">
                        <span class="estandar-side-label">Shortcode por slug</span>
                        <code id="estandar-shortcode-slug">[estandar_form slug="<?php echo esc_attr($current->slug ?? 'mi-formulario'); ?>"]</code>
                    </div>
                    <div class="estandar-side-block">
                        <span class="estandar-side-label">Shortcode por ID</span>
                        <code id="estandar-shortcode-id"><?php echo ! empty($current->id) ? '[estandar_form id="' . esc_html($current->id) . '"]' : 'Disponible al guardar'; ?></code>
                    </div>
                    <div class="estandar-side-block">
                        <span class="estandar-side-label">Checklist rápido</span>
                        <ul class="estandar-side-checklist">
                            <li>Define nombre y slug.</li>
                            <li>Agrega campos obligatorios y mensajes claros.</li>
                            <li>Guarda y pega el shortcode en una página.</li>
                        </ul>
                    </div>
                    <?php if ( $current && ! empty($current->id) ): ?>
                    <div class="estandar-side-block">
                        <span class="estandar-side-label">Plantilla</span>
                        <a class="button" style="width:100%;text-align:center;box-sizing:border-box;" href="<?php echo esc_url(
                            wp_nonce_url(
                                add_query_arg([
                                    'action'  => 'estandar_forms_export',
                                    'form_id' => (int) $current->id,
                                ], admin_url('admin-post.php')),
                                'estandar_forms_export_' . (int) $current->id
                            )
                        ); ?>">↓ Exportar como plantilla</a>
                    </div>
                    <?php endif; ?>
                </section>
            </aside>
        </div>

        <?php
        if ( $ef_json_lifecycle ) {
            estandar_forms_render_json_lifecycle_panels( $current, '.estandar-admin-layout' );
        }
        ?>

        <?php if ( $current && ! empty($current->fields_json) ): ?>
            <script>
                window.ESTANDAR_FORM_FIELDS = <?php echo wp_json_encode(estandar_forms_decode_fields((string) $current->fields_json)); ?>;
            </script>
        <?php endif; ?>
        <?php estandar_forms_render_admin_surface_end(); ?>

    <?php estandar_forms_render_admin_layout_end(); ?>
    <?php
}
