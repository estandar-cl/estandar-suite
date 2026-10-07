<?php
if ( ! defined('ABSPATH') ) exit;

function estandar_forms_normalize_fields(array $fields): array {
    return estandar_forms_decode_fields($fields);
}

function estandar_forms_fields_define_project_context(array $fields): bool {
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = sanitize_key((string) ($field['name'] ?? ''));
        $type = sanitize_key((string) ($field['type'] ?? ''));
        if (in_array($name, ['project', 'project_name', 'project_slug', 'project_area'], true) || $type === 'project_select') {
            return true;
        }
    }
    return false;
}

function estandar_forms_enqueue_admin_assets(bool $with_builder = false): void {
    wp_enqueue_style(
        'estandar-forms-admin-css',
        ESTANDAR_FORMS_URL . 'admin/assets/admin.css',
        [],
        ESTANDAR_FORMS_VERSION
    );

    if ( function_exists( 'estandar_core_enqueue_admin_ui' ) ) {
        estandar_core_enqueue_admin_ui();
    }

    if ( $with_builder ) {
        wp_enqueue_script(
            'estandar-forms-admin-js',
            ESTANDAR_FORMS_URL . 'admin/assets/admin.js',
            ['jquery', 'jquery-ui-sortable'],
            ESTANDAR_FORMS_VERSION,
            true
        );
    }
}

function estandar_forms_nav_items(string $active): array {
    global $wpdb;
    $entries_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}estandar_form_entries");
    $survey_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}estandar_forms WHERE form_type = 'survey'");
    return [
        'items' => [
            [ 'label' => 'Formularios', 'url' => admin_url('admin.php?page=estandar-forms'), 'active' => $active === 'estandar-forms', 'slug' => 'estandar-forms' ],
            [ 'label' => 'Surveys' . ( $survey_count > 0 ? ' (' . $survey_count . ')' : '' ), 'url' => admin_url('admin.php?page=estandar-surveys'), 'active' => $active === 'estandar-surveys', 'slug' => 'estandar-surveys' ],
            [ 'label' => 'Entradas' . ( $entries_count > 0 ? ' (' . $entries_count . ')' : '' ), 'url' => admin_url('admin.php?page=estandar-forms-entries'), 'active' => $active === 'estandar-forms-entries', 'slug' => 'estandar-forms-entries' ],
        ],
    ];
}

function estandar_forms_render_admin_tabs(string $active): void {
    // When running inside the Estandar shell, the parent shell renders the
    // navigation bar (Formularios | Surveys | Entradas) from the 'navigation'
    // argument. Rendering them again here duplicates the menu.
    if ( estandar_forms_is_core_embedded() ) {
        return;
    }
    // CORE_OPTIONALITY_V1: forms tiene menú standalone; esta ruta corre sin core.
    global $wpdb;
    $entries_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}estandar_form_entries");
    global $wpdb;
    $survey_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}estandar_forms WHERE form_type = 'survey'");
    $tabs = [
        'estandar-forms' => [
            'label' => 'Formularios',
            'url'   => admin_url('admin.php?page=estandar-forms'),
        ],
        'estandar-surveys' => [
            'label' => 'Surveys' . ( $survey_count > 0 ? ' (' . $survey_count . ')' : '' ),
            'url'   => admin_url('admin.php?page=estandar-surveys'),
        ],
        'estandar-forms-entries' => [
            'label' => 'Entradas' . ( $entries_count > 0 ? ' (' . $entries_count . ')' : '' ),
            'url'   => admin_url('admin.php?page=estandar-forms-entries'),
        ],
    ];

    // CORE_OPTIONALITY_V1: forms tiene menú standalone; esta ruta corre sin core.
    if ( function_exists('estandar_render_tabs') ) {
        estandar_render_tabs($tabs, $active);
        return;
    }
    echo '<h2 class="nav-tab-wrapper">';
    foreach ( $tabs as $slug => $tab ) {
        printf(
            '<a href="%s" class="nav-tab%s">%s</a>',
            esc_url($tab['url'] ?? ''),
            $slug === $active ? ' nav-tab-active' : '',
            esc_html($tab['label'] ?? '')
        );
    }
    echo '</h2>';
}

function estandar_forms_render_notice_from_request(): void {
    $notice = isset($_GET['estandar_forms_notice']) ? sanitize_key((string) wp_unslash($_GET['estandar_forms_notice'])) : '';
    if ( $notice === '' ) {
        return;
    }

    // P1: imported_with_warnings needs a transient — handle before the static map.
    if ( $notice === 'imported_with_warnings' ) {
        $new_id   = absint( $_GET['estandar_forms_new_id'] ?? 0 );
        $warnings = ( $new_id > 0 ) ? get_transient( 'efimport_warnings_' . $new_id ) : [];
        if ( $new_id > 0 ) {
            delete_transient( 'efimport_warnings_' . $new_id );
        }
        $edit_url = ( $new_id > 0 )
            ? add_query_arg( [ 'page' => 'estandar-forms', 'edit' => $new_id ], admin_url( 'admin.php' ) )
            : '';
        echo '<div class="notice notice-warning"><p>';
        echo 'Formulario importado, pero se detectaron advertencias en <code>actions_json</code>. ';
        echo 'Revisa la configuración antes de publicar.';
        if ( $edit_url ) {
            echo ' <a href="' . esc_url( $edit_url ) . '">Editar formulario →</a>';
        }
        echo '</p>';
        if ( ! empty( $warnings ) && is_array( $warnings ) ) {
            echo '<ul style="margin:.4em 0 .6em 1.4em;list-style:disc;">';
            foreach ( $warnings as $w ) {
                echo '<li>' . esc_html( $w ) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        return;
    }

    $messages = [
        'imported'              => ['updated', 'Plantilla importada como nuevo formulario.'],
        'import_error'          => ['notice-error', 'No fue posible importar la plantilla. Revisa el archivo JSON.'],
        'template_saved'        => ['updated', 'Formulario guardado como template.'],
        'template_error'        => ['notice-error', 'No fue posible procesar el template.'],
        'created_from_template' => ['updated', 'Template creado como nuevo formulario.'],
    ];

    if ( empty($messages[$notice]) ) {
        return;
    }

    [$class, $message] = $messages[$notice];
    echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
}

function estandar_forms_is_core_embedded(): bool {
    return function_exists( 'estandar_core_is_embedded_admin' ) && estandar_core_is_embedded_admin();
}

function estandar_forms_render_admin_layout_start(): void {
    if ( estandar_forms_is_core_embedded() ) {
        return;
    }

    echo '<div class="wrap estandar-forms-admin estandar-admin estandar-admin--fluid">';
}

function estandar_forms_render_admin_layout_end(): void {
    if ( estandar_forms_is_core_embedded() ) {
        return;
    }

    echo '</div>';
}

function estandar_forms_render_admin_branding( string $title, string $subtitle = '' ): void {
    if ( estandar_forms_is_core_embedded() ) {
        return;
    }

    if ( function_exists( 'estandar_core_render_plugin_branding' ) ) {
        estandar_core_render_plugin_branding(
            [
                'title'    => $title,
                'subtitle' => $subtitle,
            ]
        );
        return;
    }

    echo '<h1>' . esc_html( $title ) . '</h1>';
    if ( '' !== trim( $subtitle ) ) {
        echo '<p class="description">' . esc_html( $subtitle ) . '</p>';
    }
}

function estandar_forms_render_admin_surface_start(): void {
    if ( estandar_forms_is_core_embedded() ) {
        return;
    }

    echo '<div class="estandar-surface">';
}

function estandar_forms_render_admin_surface_end(): void {
    if ( estandar_forms_is_core_embedded() ) {
        return;
    }

    echo '</div>';
}
/**
 * Se invoca SOLO como callback registrado vía estandar_register_navigation_page()
 * (estandar-forms.php), que ya envuelve esta función en
 * estandar_core_render_admin_shell(). Envolverla OTRA VEZ aquí (con su propio
 * 'navigation') duplicaba la fila de submenú: el shell exterior no la mostraba
 * (bail-out por shell anidado), pero el destino final (estandar_forms_forms_page /
 * estandar_forms_dashboard) YA dibuja esa misma fila localmente vía
 * estandar_forms_render_admin_tabs() — de ahí el submenú repetido.
 */
function estandar_forms_home_page() {
    if ( isset( $_GET['create'] ) && ! isset( $_GET['type'] ) ) {
        estandar_forms_create_picker();
        return;
    }
    $is_builder_request = isset( $_GET['edit'] ) || isset( $_POST['estandar_forms_save'] )
        || ( isset( $_GET['create'] ) && ( $_GET['type'] ?? '' ) === 'form' );
    if ( $is_builder_request ) {
        estandar_forms_dashboard();
        return;
    }
    estandar_forms_forms_page();
}

/**
 * "Nuevo elemento" type picker — shown when ?create=1 without a type parameter.
 * Lets the user choose between Formulario and Survey before entering either editor.
 */
function estandar_forms_create_picker(): void {
    $url_form   = admin_url( 'admin.php?page=estandar-forms&create=1&type=form' );
    $url_survey = admin_url( 'admin.php?page=estandar-surveys-edit' );
    ?>
    <div class="estandar-admin-panel" style="max-width:560px;margin:32px auto;">
        <div class="estandar-admin-panel-header">
            <div>
                <h3>Crear nuevo elemento</h3>
                <p>Elige el tipo de elemento que quieres crear.</p>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:24px 0 8px;">
            <a href="<?php echo esc_url($url_form); ?>"
               style="display:block;padding:24px 20px;border:2px solid #c3c4c7;border-radius:8px;text-decoration:none;color:inherit;transition:border-color .15s;">
                <span style="font-size:28px;display:block;margin-bottom:10px;">📝</span>
                <strong style="display:block;font-size:15px;margin-bottom:6px;color:#1d2327;">Formulario</strong>
                <span style="font-size:13px;color:#646970;">Lead Capture, Postventa, Soporte, Email directo</span>
            </a>
            <a href="<?php echo esc_url($url_survey); ?>"
               style="display:block;padding:24px 20px;border:2px solid #c3c4c7;border-radius:8px;text-decoration:none;color:inherit;transition:border-color .15s;">
                <span style="font-size:28px;display:block;margin-bottom:10px;">📊</span>
                <strong style="display:block;font-size:15px;margin-bottom:6px;color:#1d2327;">Survey</strong>
                <span style="font-size:13px;color:#646970;">NPS, Satisfacción, Experiencia comercial</span>
            </a>
        </div>
    </div>
    <?php
}

require_once __DIR__ . '/partials/forms-table.php';
require_once __DIR__ . '/handlers/save-handler.php';
require_once __DIR__ . '/handlers/delete-handler.php';
require_once __DIR__ . '/handlers/export-handler.php';
require_once __DIR__ . '/handlers/import-handler.php';
require_once __DIR__ . '/screens/form-preview.php';
require_once __DIR__ . '/screens/forms-list.php';
require_once __DIR__ . '/screens/form-edit.php';
