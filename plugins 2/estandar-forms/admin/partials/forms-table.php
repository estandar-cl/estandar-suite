<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * FORMS_DIAGNOSTIC_V1 — structured operability diagnosis.
 *
 * Returns an associative array with:
 *   - operational    (bool)     true if the form can process submissions normally
 *   - diagnostic_code (string)  stable machine-readable code for the condition
 *   - title           (string)  one-line summary
 *   - message         (string)  detailed explanation (cause + impact)
 *   - resolution      (string)  what the user must do to fix it
 *   - action_label    (string)  button text
 *   - action_url      (string)  link to the editor section where the fix is applied
 *
 * The view only renders these fields — no branching logic in the template.
 */
function estandar_forms_operability_diagnosis($form): array {
    $form_type = sanitize_key((string) ($form->form_type ?? 'lead_capture'));
    if ( $form_type === 'lead' || $form_type === '' ) {
        $form_type = 'lead_capture';
    }

    $edit_url  = add_query_arg(['page' => 'estandar-forms', 'edit' => (int) ($form->id ?? 0)], admin_url('admin.php'));
    $proj_url  = admin_url('admin.php?page=estandar_projects');
    $link_edit = '<a href="' . esc_url($edit_url) . '">editor del formulario</a>';

    // ── general (legacy/non-commercial form type) ──────────────────────────
    if ( $form_type === 'general' ) {
        return [
            'operational'     => false,
            'diagnostic_code' => 'form_type_general',
            'title'           => 'Tipo de formulario no comercial',
            'message'         => 'El formulario está configurado como tipo "General". No genera leads, no notifica operadores y no ingresa al CRM.',
            'resolution'      => 'Cambia el tipo a "Lead Capture" en el ' . $link_edit . ' para activar el pipeline comercial.',
            'action_label'    => 'Configurar tipo',
            'action_url'      => $edit_url,
        ];
    }

    // ── email_direct ───────────────────────────────────────────────────────
    if ( $form_type === 'email_direct' ) {
        $has_recipient = trim((string) ($form->notify_emails ?? '')) !== '';
        if ( $has_recipient ) {
            return [
                'operational'     => true,
                'diagnostic_code' => 'email_direct_ok',
                'title'           => 'Correo directo operativo',
                'message'         => 'Envía correo directo a los destinatarios configurados. No genera leads ni entra al CRM.',
                'resolution'      => '',
                'action_label'    => '',
                'action_url'      => '',
            ];
        }
        return [
            'operational'     => false,
            'diagnostic_code' => 'email_direct_no_recipient',
            'title'           => 'Falta destinatario de correo',
            'message'         => 'El formulario es de tipo "Correo directo" pero no tiene ningún email destinatario configurado. Sin destinatario, las entradas se almacenan pero no se notifican.',
            'resolution'      => 'Agrega al menos un email en el campo "Notificar a" del ' . $link_edit . '.',
            'action_label'    => 'Agregar destinatario',
            'action_url'      => $edit_url,
        ];
    }

    // ── non-lead_capture types (survey, etc.) are considered operational ────
    if ( $form_type !== 'lead_capture' ) {
        return [
            'operational'     => true,
            'diagnostic_code' => 'non_commercial_type',
            'title'           => 'Formulario no comercial',
            'message'         => 'Este tipo de formulario no utiliza el pipeline de leads.',
            'resolution'      => '',
            'action_label'    => '',
            'action_url'      => '',
        ];
    }

    // ── lead_capture: requires routing context ─────────────────────────────
    $routing = estandar_forms_routing_context($form->context_schema ?? '');

    if ( ($routing['mode'] ?? 'none') === 'none' ) {
        return [
            'operational'     => false,
            'diagnostic_code' => 'routing_not_configured',
            'title'           => 'Routing no configurado',
            'message'         => 'El formulario captura leads pero no tiene definido un destino. Sin routing, los leads no se asignan a ningún operador ni proyecto.',
            'resolution'      => 'Configura el routing como "Proyecto fijo" o "Selector de proyecto" en la pestaña "Routing" del ' . $link_edit . '.',
            'action_label'    => 'Configurar routing',
            'action_url'      => $edit_url . '&tab=routing',
        ];
    }

    if ( ($routing['mode'] ?? 'none') === 'fixed_project' ) {
        $slug = (string) ($routing['project_slug'] ?? '');
        if ( $slug === '' ) {
            return [
                'operational'     => false,
                'diagnostic_code' => 'fixed_project_no_project',
                'title'           => 'Proyecto fijo no seleccionado',
                'message'         => 'El routing está configurado como "Proyecto fijo" pero no se ha seleccionado ningún proyecto.',
                'resolution'      => 'Selecciona un proyecto en la pestaña "Routing" del ' . $link_edit . '.',
                'action_label'    => 'Seleccionar proyecto',
                'action_url'      => $edit_url . '&tab=routing',
            ];
        }
        $preview = estandar_forms_project_operator_preview($slug);
        if ( ($preview['status'] ?? '') === 'missing_operator' ) {
            return [
                'operational'     => false,
                'diagnostic_code' => 'fixed_project_no_operator',
                'title'           => 'Proyecto sin operador',
                'message'         => 'El proyecto "' . esc_html($slug) . '" no tiene ningún operador activo asignado. Los leads se capturarán pero no se notificarán.',
                'resolution'      => 'Asigna un operador activo con email al proyecto "' . esc_html($slug) . '" en la página de ' . '<a href="' . esc_url($proj_url) . '">Proyectos</a>.',
                'action_label'    => 'Asignar operador',
                'action_url'      => $proj_url,
            ];
        }
        if ( ($preview['status'] ?? '') === 'missing_email' ) {
            return [
                'operational'     => false,
                'diagnostic_code' => 'fixed_project_operator_no_email',
                'title'           => 'Operador sin email',
                'message'         => 'El operador asignado al proyecto "' . esc_html($slug) . '" no tiene email configurado. Las notificaciones no podrán enviarse.',
                'resolution'      => 'Configura un email para el operador en la página de ' . '<a href="' . esc_url($proj_url) . '">Proyectos</a>.',
                'action_label'    => 'Revisar operador',
                'action_url'      => $proj_url,
            ];
        }
        return [
            'operational'     => true,
            'diagnostic_code' => 'fixed_project_ok',
            'title'           => 'Lead Capture operativo',
            'message'         => 'Proyecto fijo con operador y email configurados.',
            'resolution'      => '',
            'action_label'    => '',
            'action_url'      => '',
        ];
    }

    if ( ($routing['mode'] ?? 'none') === 'project_selector' ) {
        $raw_projects = (array) ($routing['selector_projects'] ?? []);
        if ( empty($raw_projects) ) {
            return [
                'operational'     => false,
                'diagnostic_code' => 'selector_no_projects',
                'title'           => 'Selector sin proyectos',
                'message'         => 'El routing está configurado como "Selector de proyecto" pero no incluye ningún proyecto.',
                'resolution'      => 'Agrega al menos un proyecto al selector en la pestaña "Routing" del ' . $link_edit . '.',
                'action_label'    => 'Agregar proyectos',
                'action_url'      => $edit_url . '&tab=routing',
            ];
        }
        $routable = 0;
        $failures = [];
        foreach ( $raw_projects as $project ) {
            $slug = (string) ($project['project_slug'] ?? $project['slug'] ?? '');
            if ( $slug === '' ) {
                $failures[] = '(slug vacío)';
                continue;
            }
            $preview = estandar_forms_project_operator_preview($slug);
            if ( ($preview['status'] ?? '') === 'ok' ) {
                $routable++;
            } else {
                $failures[] = $slug;
            }
        }
        if ( $routable > 0 ) {
            return [
                'operational'     => true,
                'diagnostic_code' => 'selector_has_routable',
                'title'           => 'Selector con opciones operativas',
                'message'         => $routable . ' de ' . count($raw_projects) . ' proyecto(s) tienen operador disponible.',
                'resolution'      => '',
                'action_label'    => '',
                'action_url'      => '',
            ];
        }
        $fail_list = array_slice($failures, 0, 5);
        return [
            'operational'     => false,
            'diagnostic_code' => 'selector_no_routable',
            'title'           => 'Selector sin operadores disponibles',
            'message'         => 'Ninguno de los proyectos del selector (' . implode(', ', $fail_list) . ') tiene un operador activo con email. Los leads se capturarán pero no se notificarán.',
            'resolution'      => 'Asigna operadores activos con email a los proyectos en la página de ' . '<a href="' . esc_url($proj_url) . '">Proyectos</a>.',
            'action_label'    => 'Asignar operadores',
            'action_url'      => $proj_url,
        ];
    }

    // ── fallback (should not be reached) ────────────────────────────────────
    return [
        'operational'     => false,
        'diagnostic_code' => 'unknown_state',
        'title'           => 'Estado desconocido',
        'message'         => 'El formulario se encuentra en un estado no reconocido. Revisa su configuración general.',
        'resolution'      => 'Abre el ' . $link_edit . ' y verifica la configuración del tipo y routing.',
        'action_label'    => 'Revisar configuración',
        'action_url'      => $edit_url,
    ];
}

/**
 * Legacy wrapper — delegates to estandar_forms_operability_diagnosis().
 * Keeps backward compatibility for any external callers.
 */
function estandar_forms_operability_status($form): array {
    $d = estandar_forms_operability_diagnosis($form);
    return [
        'ok'        => $d['operational'],
        'label'     => $d['operational'] ? 'OPERATIVO' : 'NO OPERATIVO',
        'class'     => $d['operational'] ? 'notice-success' : 'notice-error',
        'message'   => $d['title'],
        'fix_url'   => $d['action_url'],
        'fix_label' => $d['action_label'],
    ];
}

function estandar_forms_render_forms_table(array $forms): void {
    if ( $forms ) {
        $operative_count = 0;
        foreach ( $forms as $form ) {
            $status = estandar_forms_operability_status($form);
            if ( ! empty($status['ok']) ) {
                $operative_count++;
            }
        }
        $attention_count = max(0, count($forms) - $operative_count);

        echo '<div class="estandar-card" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;padding:12px;">';
        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
        echo '<button type="button" class="button es-forms-filter is-active" data-filter="all">Todos (' . esc_html((string) count($forms)) . ')</button>';
        echo '<button type="button" class="button es-forms-filter" data-filter="ok">Operativos (' . esc_html((string) $operative_count) . ')</button>';
        echo '<button type="button" class="button es-forms-filter" data-filter="attention">Requieren atención (' . esc_html((string) $attention_count) . ')</button>';
        echo '<input type="search" id="es-forms-search" class="regular-text" placeholder="Buscar formulario..." style="min-width:260px;">';
        echo '</div>';
        echo '<div id="es-forms-bulk-count" style="font-size:12px;color:#6b7280;">0 seleccionados</div>';
        echo '</div>';

        echo '<table class="widefat striped estandar-forms-list-table">';
        echo '<thead><tr><th style="width:36px;"><input type="checkbox" id="es-forms-select-all" aria-label="Seleccionar visibles"></th><th>ID</th><th>Nombre</th><th>Estado</th><th>Routing</th><th>Shortcode</th><th>Acciones</th></tr></thead><tbody>';

        foreach ( $forms as $form ) {
            $form_type = sanitize_key((string) ($form->form_type ?? ''));
            $routing = estandar_forms_routing_context($form->context_schema ?? '');
            $operability = estandar_forms_operability_status($form);
            $routing_label = '—';
            if ( $form_type === 'lead' || $form_type === 'lead_capture' ) {
                if ( ($routing['mode'] ?? 'none') === 'fixed_project' ) {
                    $preview = estandar_forms_project_operator_preview((string) ($routing['project_slug'] ?? ''));
                    $ok = ($preview['status'] ?? '') === 'ok';
                    $routing_label = ($ok ? 'OK' : 'REQUIRES ATTENTION') . ' · ' . (string) ($routing['project_name'] ?: $routing['project_slug']);
                } elseif ( ($routing['mode'] ?? 'none') === 'project_selector' ) {
                    $routing_label = 'Selector de proyecto';
                } else {
                    $routing_label = 'REQUIRES ATTENTION · Sin routing';
                }
            }
            $row_search = strtolower(implode(' ', array_filter([
                (string) ($form->id ?? ''),
                (string) ($form->name ?? ''),
                (string) ($form->slug ?? ''),
                (string) $routing_label,
                (string) ($form_type ?: ''),
            ])));
            echo '<tr class="es-forms-row" data-status="' . esc_attr($operability['ok'] ? 'ok' : 'attention') . '" data-search="' . esc_attr($row_search) . '">';
            echo '<td><input type="checkbox" class="es-forms-check" value="' . esc_attr((string) $form->id) . '" aria-label="Seleccionar ' . esc_attr((string) $form->name) . '"></td>';
            echo '<td>' . esc_html($form->id) . '</td>';
            echo '<td>' . esc_html($form->name) . '</td>';
            $diagnosis = estandar_forms_operability_diagnosis($form);
            echo '<td>';
            echo '<span style="cursor:help;display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;background:' . ($diagnosis['operational'] ? '#e7f7ed;color:#007a3d' : '#fdecec;color:#b42318') . ';">' . ($diagnosis['operational'] ? 'OPERATIVO' : 'NO OPERATIVO') . '</span>';
            if ( ! $diagnosis['operational'] ) {
                echo '<div style="margin-top:6px;font-size:12px;line-height:1.4;max-width:320px;">';
                echo '<strong>' . esc_html($diagnosis['title']) . '</strong><br>';
                echo '<span style="color:#4b5563;">' . $diagnosis['resolution'] . '</span>';
                if ( ! empty($diagnosis['action_url']) ) {
                    echo '<br><a href="' . esc_url($diagnosis['action_url']) . '" class="button button-small" style="margin-top:4px;">' . esc_html($diagnosis['action_label']) . '</a>';
                }
                echo '</div>';
            }
            echo '</td>';
            echo '<td>' . esc_html($routing_label) . '</td>';
            echo '<td><code>[estandar_form slug="' . esc_attr($form->slug) . '"]</code></td>';
            echo '<td>';
            echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'estandar-forms', 'edit' => $form->id], admin_url('admin.php'))) . '">Editar</a> ';
            echo '<a class="button" href="' . esc_url(
                wp_nonce_url(
                    add_query_arg(['action' => 'estandar_forms_duplicate', 'form_id' => $form->id], admin_url('admin-post.php')),
                    'estandar_forms_duplicate_' . $form->id
                )
            ) . '">Duplicar</a> ';
            echo '<a class="button" href="' . esc_url(
                wp_nonce_url(
                    add_query_arg(['action' => 'estandar_forms_export', 'form_id' => $form->id], admin_url('admin-post.php')),
                    'estandar_forms_export_' . $form->id
                )
            ) . '">Exportar</a> ';
            echo '<a class="button button-link-delete" href="' . esc_url(
                wp_nonce_url(
                    add_query_arg(['page' => 'estandar-forms', 'delete' => $form->id], admin_url('admin.php')),
                    'estandar_forms_delete_' . $form->id
                )
            ) . '" onclick="return confirm(\'¿Eliminar este formulario?\');">Eliminar</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        ?>
        <script>
        (function(){
            var filter='all';
            var search=document.getElementById('es-forms-search');
            var rows=[].slice.call(document.querySelectorAll('.es-forms-row'));
            var checks=[].slice.call(document.querySelectorAll('.es-forms-check'));
            var all=document.getElementById('es-forms-select-all');
            var count=document.getElementById('es-forms-bulk-count');
            function visible(row){
                var statusOk=filter==='all'||row.dataset.status===filter;
                var q=(search.value||'').toLowerCase().trim();
                return statusOk&&(!q||(row.dataset.search||'').indexOf(q)!==-1);
            }
            function updateCount(){
                var selected=checks.filter(function(c){return c.checked;}).length;
                count.textContent=selected+' seleccionado'+(selected===1?'':'s');
                if(all){
                    var visibleChecks=checks.filter(function(c){return c.closest('tr').style.display!=='none';});
                    all.checked=visibleChecks.length>0&&visibleChecks.every(function(c){return c.checked;});
                }
            }
            function render(){
                rows.forEach(function(row){row.style.display=visible(row)?'':'none';});
                updateCount();
            }
            document.querySelectorAll('.es-forms-filter').forEach(function(btn){
                btn.addEventListener('click',function(){
                    filter=btn.dataset.filter||'all';
                    document.querySelectorAll('.es-forms-filter').forEach(function(b){b.classList.remove('is-active');});
                    btn.classList.add('is-active');
                    render();
                });
            });
            if(search){search.addEventListener('input',render);}
            checks.forEach(function(c){c.addEventListener('change',updateCount);});
            if(all){all.addEventListener('change',function(){
                checks.forEach(function(c){if(c.closest('tr').style.display!=='none'){c.checked=all.checked;}});
                updateCount();
            });}
            render();
        })();
        </script>
        <style>
        .es-forms-filter.is-active{background:#1d2327;border-color:#1d2327;color:#fff;}
        </style>
        <?php
    } else {
        echo '<div class="estandar-empty-state">';
        echo '<h3>No hay formularios todavía</h3>';
        echo '<p>Crea el primer formulario para obtener su shortcode y empezar a recibir entradas.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=estandar-forms&create=1')) . '">Crear formulario</a></p>';
        echo '</div>';
    }
}
