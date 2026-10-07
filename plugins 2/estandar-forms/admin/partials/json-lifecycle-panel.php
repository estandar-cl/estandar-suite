<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * JSON_LIFECYCLE_V1 — UI compartida del ciclo JSON (tabs + panels + JS).
 *
 * Un solo bloque de UI para TODOS los editores de entidades que viven en
 * wp_estandar_forms (Forms y Surveys). Extraído de form-edit.php sin cambios
 * de comportamiento; survey-edit.php lo reutiliza parametrizando únicamente
 * el selector del contenedor Visual. Nunca duplicar este markup en un screen.
 *
 * Toda acción pasa por los mismos endpoints AJAX canónicos
 * (estandar_forms_json_*) y por Estandar_Forms_Template::apply().
 */

/**
 * Pestañas de primer nivel del editor (Visual / JSON / Vista previa / Historial).
 */
function estandar_forms_render_json_lifecycle_tabs(): void {
    ?>
    <nav class="ef-lifecycle-tabs" style="display:flex;gap:4px;margin:16px 0 20px;border-bottom:1px solid #dcdcde;">
        <?php foreach ( [ 'visual' => 'Visual', 'json' => 'JSON', 'preview' => 'Vista previa', 'history' => 'Historial' ] as $ef_tab_id => $ef_tab_label ) : ?>
            <button type="button" class="button ef-tab-btn<?php echo $ef_tab_id === 'visual' ? ' button-primary' : ''; ?>" data-ef-tab="<?php echo esc_attr( $ef_tab_id ); ?>" style="border-radius:6px 6px 0 0;margin-bottom:-1px;">
                <?php echo esc_html( $ef_tab_label ); ?>
            </button>
        <?php endforeach; ?>
    </nav>
    <?php
}

/**
 * Paneles JSON / Vista previa / Historial + JS de acciones.
 *
 * @param object $current         Fila persistida de wp_estandar_forms (id, slug).
 * @param string $visual_selector Selector CSS del contenedor del editor Visual
 *                                que las pestañas muestran/ocultan.
 */
function estandar_forms_render_json_lifecycle_panels( $current, string $visual_selector = '.estandar-admin-layout' ): void {
    if ( ! $current || empty( $current->id ) || ! class_exists( 'Estandar_Forms_Template' ) ) {
        return;
    }
    // Settings reales de CodeMirror hacia JS; false = usuario deshabilitó
    // el editor de código → fallback textarea plano (comportamiento requerido).
    $ef_code_editor_settings = function_exists( 'wp_enqueue_code_editor' )
        ? wp_enqueue_code_editor( [ 'type' => 'application/json' ] )
        : false;
    $ef_initial_envelope = Estandar_Forms_Template::export( (int) $current->id );
    $ef_initial_json = is_wp_error( $ef_initial_envelope ) ? '' : Estandar_Forms_Template::to_json( $ef_initial_envelope );
    ?>
        <!-- ══════════ JSON_LIFECYCLE_V1 — pestaña JSON (editor canónico) ══════════ -->
        <div id="ef-panel-json" class="ef-tab-panel" style="display:none;">
            <section class="estandar-admin-panel">
                <div class="estandar-admin-panel-header">
                    <div>
                        <h3>Editor JSON — representación canónica</h3>
                        <p>Documento versionado del formulario completo. Validar → Diff → Aplicar. Nada se escribe sin pasar por el importador canónico. El bloque <code>readonly</code> (id, created_at, estadísticas) es informativo y nunca se importa. Los valores redactados (<code>__ESTANDAR_REDACTED__</code>) preservan el secreto configurado.</p>
                    </div>
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
                    <button type="button" class="button button-primary" id="ef-json-apply">Aplicar</button>
                    <button type="button" class="button" id="ef-json-validate">Validar</button>
                    <button type="button" class="button" id="ef-json-diff">Ver diferencias</button>
                    <button type="button" class="button" id="ef-json-format">Formatear</button>
                    <button type="button" class="button" id="ef-json-restore">Restaurar actual</button>
                    <button type="button" class="button" id="ef-json-copy">Copiar</button>
                    <button type="button" class="button" id="ef-json-download">Descargar</button>
                    <button type="button" class="button" id="ef-json-upload">Subir JSON…</button>
                    <input type="file" id="ef-json-file" accept=".json,application/json" style="display:none;">
                    <label style="margin-left:auto;display:flex;align-items:center;gap:12px;">
                        <span><input type="checkbox" id="ef-json-replace"> Reemplazo total (en vez de merge)</span>
                        <span><input type="checkbox" id="ef-json-allow-slug"> Permitir cambio de slug</span>
                    </label>
                </div>
                <textarea id="ef-json-editor" rows="28" style="width:100%;font-family:Menlo,Consolas,monospace;font-size:12px;" spellcheck="false"><?php echo esc_textarea( $ef_initial_json ); ?></textarea>
                <div id="ef-json-report" style="margin-top:12px;"></div>
            </section>
        </div>

        <!-- ══════════ JSON_LIFECYCLE_V1 — pestaña Vista previa ══════════ -->
        <div id="ef-panel-preview" class="ef-tab-panel" style="display:none;">
            <section class="estandar-admin-panel">
                <div class="estandar-admin-panel-header">
                    <div>
                        <h3>Vista previa</h3>
                        <p>Render real del formulario persistido (los envíos están deshabilitados en esta vista). Cambios aplicados vía JSON o Visual se reflejan al recargar.</p>
                    </div>
                </div>
                <div id="ef-preview-sandbox" style="max-width:760px;">
                    <?php echo do_shortcode( '[estandar_form id="' . (int) $current->id . '"]' ); ?>
                </div>
            </section>
        </div>

        <!-- ══════════ JSON_LIFECYCLE_V1 — pestaña Historial ══════════ -->
        <div id="ef-panel-history" class="ef-tab-panel" style="display:none;">
            <section class="estandar-admin-panel">
                <div class="estandar-admin-panel-header">
                    <div>
                        <h3>Historial del ciclo JSON</h3>
                        <p>Snapshots y aplicaciones registradas (fundación de revisiones). Un snapshot puede cargarse en el editor JSON y re-aplicarse.</p>
                    </div>
                </div>
                <?php $ef_history = Estandar_Forms_Template::history( (int) $current->id ); ?>
                <?php if ( empty( $ef_history ) ) : ?>
                    <p>Sin eventos todavía. El primer «Aplicar» desde el editor JSON creará un snapshot y un registro de aplicación.</p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead><tr><th style="width:160px;">Fecha</th><th style="width:180px;">Evento</th><th>Detalle</th><th style="width:140px;"></th></tr></thead>
                        <tbody>
                        <?php foreach ( $ef_history as $ef_ev ) :
                            $ef_labels = [ 'form_snapshot' => 'Snapshot', 'form_json_applied' => 'JSON aplicado', 'form_json_rollback' => 'Rollback automático' ];
                            $ef_snapshot_env = $ef_ev['context']['envelope'] ?? null;
                        ?>
                            <tr>
                                <td><?php echo esc_html( $ef_ev['created_at'] ); ?></td>
                                <td><?php echo esc_html( $ef_labels[ $ef_ev['event_type'] ] ?? $ef_ev['event_type'] ); ?></td>
                                <td><?php echo esc_html( $ef_ev['message'] ); ?></td>
                                <td>
                                    <?php if ( is_array( $ef_snapshot_env ) ) : ?>
                                        <button type="button" class="button button-small ef-history-load" data-envelope="<?php echo esc_attr( (string) wp_json_encode( $ef_snapshot_env ) ); ?>">Cargar en editor</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>

        <script>
        (function () {
            var formId   = <?php echo (int) $current->id; ?>;
            var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'estandar_forms_json' ) ); ?>;
            var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var slug     = <?php echo wp_json_encode( (string) $current->slug ); ?>;
            var textarea = document.getElementById('ef-json-editor');
            var report   = document.getElementById('ef-json-report');
            var cm       = null;
            var cmSettings = <?php echo wp_json_encode( $ef_code_editor_settings ?: null ); ?>;

            // Reusar el editor de código de WordPress (CodeMirror) con los settings
            // reales del enqueue; si no está disponible, el textarea plano funciona igual.
            if (window.wp && wp.codeEditor && textarea && cmSettings) {
                try { cm = wp.codeEditor.initialize(textarea, cmSettings).codemirror; } catch (e) { cm = null; }
            }
            function getDoc()   { return cm ? cm.getValue() : textarea.value; }
            function setDoc(v)  { if (cm) { cm.setValue(v); } else { textarea.value = v; } }

            // ── Pestañas ────────────────────────────────────────────────────
            var visual = document.querySelector(<?php echo wp_json_encode( $visual_selector ); ?>);
            var panels = { json: document.getElementById('ef-panel-json'), preview: document.getElementById('ef-panel-preview'), history: document.getElementById('ef-panel-history') };
            document.querySelectorAll('.ef-tab-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var tab = btn.getAttribute('data-ef-tab');
                    document.querySelectorAll('.ef-tab-btn').forEach(function (b) { b.classList.remove('button-primary'); });
                    btn.classList.add('button-primary');
                    if (visual) { visual.style.display = tab === 'visual' ? '' : 'none'; }
                    Object.keys(panels).forEach(function (k) { if (panels[k]) { panels[k].style.display = k === tab ? '' : 'none'; } });
                    if (tab === 'json' && cm) { cm.refresh(); }
                    // URL estable por pestaña (?ef_tab=json|preview|history).
                    try {
                        var url = new URL(window.location.href);
                        if (tab === 'visual') { url.searchParams.delete('ef_tab'); } else { url.searchParams.set('ef_tab', tab); }
                        window.history.replaceState(null, '', url.toString());
                    } catch (e) {}
                });
            });

            // Pestaña inicial seleccionable por URL (deep-link estable).
            (function () {
                var initial = null;
                try { initial = new URLSearchParams(window.location.search).get('ef_tab'); } catch (e) {}
                if (initial && initial !== 'visual') {
                    var btn = document.querySelector('.ef-tab-btn[data-ef-tab="' + initial + '"]');
                    if (btn) { btn.click(); }
                }
            })();

            // Vista previa: sandbox sin envíos.
            var sandbox = document.getElementById('ef-preview-sandbox');
            if (sandbox) { sandbox.addEventListener('submit', function (e) { e.preventDefault(); e.stopPropagation(); }, true); }

            // ── Diagnósticos ────────────────────────────────────────────────
            function esc(t) { var d = document.createElement('div'); d.textContent = String(t == null ? '' : t); return d.innerHTML; }
            function renderIssues(errors, warnings, headline) {
                var html = '';
                if (headline) { html += '<p><strong>' + esc(headline) + '</strong></p>'; }
                (errors || []).forEach(function (it) {
                    html += '<div style="border-left:3px solid #d63638;padding:6px 10px;margin:4px 0;background:#fcf0f1;">'
                         + '<code>' + esc(it.path) + '</code> — ' + esc(it.message)
                         + (it.allowed ? '<br><small>Valores permitidos: ' + esc(it.allowed.join(', ')) + '</small>' : '')
                         + '</div>';
                });
                (warnings || []).forEach(function (it) {
                    html += '<div style="border-left:3px solid #dba617;padding:6px 10px;margin:4px 0;background:#fcf9e8;">'
                         + '<code>' + esc(it.path) + '</code> — ' + esc(it.message) + '</div>';
                });
                report.innerHTML = html || '<p>Sin observaciones.</p>';
            }
            function renderDiff(changes) {
                if (!changes || !changes.length) { report.innerHTML = '<p><strong>Diff:</strong> sin cambios semánticos.</p>'; return; }
                var html = '<p><strong>Diff semántico (' + changes.length + ' cambio(s)):</strong></p>';
                changes.forEach(function (c) {
                    var color = c.destructive ? '#d63638' : (c.kind === 'added' ? '#00a32a' : '#2271b1');
                    html += '<div style="border-left:3px solid ' + color + ';padding:6px 10px;margin:4px 0;background:#f6f7f7;">'
                         + esc(c.label) + (c.destructive ? ' <strong style="color:#d63638;">⚠ destructivo</strong>' : '')
                         + ' <code style="float:right;">' + esc(c.path) + '</code></div>';
                });
                report.innerHTML = html;
            }
            function post(action, extra) {
                var body = new URLSearchParams();
                body.set('action', action); body.set('nonce', nonce); body.set('form_id', formId);
                Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
                return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() }).then(function (r) { return r.json(); });
            }
            function currentOpts() {
                return {
                    doc: getDoc(),
                    strategy: document.getElementById('ef-json-replace').checked ? 'replace' : 'merge',
                    allow_slug_change: document.getElementById('ef-json-allow-slug').checked ? '1' : ''
                };
            }

            // ── Acciones ────────────────────────────────────────────────────
            document.getElementById('ef-json-format').addEventListener('click', function () {
                try { setDoc(JSON.stringify(JSON.parse(getDoc()), null, 2)); report.innerHTML = '<p>Formateado.</p>'; }
                catch (e) { renderIssues([{ path: '$', message: 'JSON inválido: ' + e.message }], []); }
            });
            document.getElementById('ef-json-validate').addEventListener('click', function () {
                post('estandar_forms_json_validate', { doc: getDoc() }).then(function (res) {
                    var d = res.data || {};
                    renderIssues(d.errors, d.warnings, d.valid ? '✓ Documento válido y ejecutable (capas 1–4).' : 'Validación con errores:');
                });
            });
            document.getElementById('ef-json-diff').addEventListener('click', function () {
                post('estandar_forms_json_diff', currentOpts()).then(function (res) {
                    if (!res.success) { renderIssues([{ path: '$', message: (res.data && res.data.message) || 'Error' }], []); return; }
                    renderDiff(res.data.changes);
                });
            });
            document.getElementById('ef-json-apply').addEventListener('click', function () {
                post('estandar_forms_json_apply', currentOpts()).then(function (res) {
                    if (!res.success) {
                        var d = res.data || {};
                        renderIssues((d.errors && d.errors.length ? d.errors : [{ path: '$', message: d.message || 'Error al aplicar' }]), d.warnings, 'No se aplicó:');
                        return;
                    }
                    report.innerHTML = '<p><strong>✓ Aplicado.</strong> ' + (res.data.changes || []).length + ' cambio(s). Recargando…</p>';
                    window.setTimeout(function () { window.location.reload(); }, 900);
                });
            });
            document.getElementById('ef-json-restore').addEventListener('click', function () {
                post('estandar_forms_json_export', {}).then(function (res) {
                    if (res.success) { setDoc(res.data.json); report.innerHTML = '<p>Documento restaurado a la versión persistida.</p>'; }
                });
            });
            document.getElementById('ef-json-copy').addEventListener('click', function () {
                navigator.clipboard.writeText(getDoc()).then(function () { report.innerHTML = '<p>Copiado al portapapeles.</p>'; });
            });
            document.getElementById('ef-json-download').addEventListener('click', function () {
                var blob = new Blob([getDoc()], { type: 'application/json' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'form-' + slug + '.estandar-template.json';
                a.click();
                URL.revokeObjectURL(a.href);
            });
            document.getElementById('ef-json-upload').addEventListener('click', function () { document.getElementById('ef-json-file').click(); });
            document.getElementById('ef-json-file').addEventListener('change', function (e) {
                var file = e.target.files && e.target.files[0];
                if (!file) { return; }
                var reader = new FileReader();
                reader.onload = function () { setDoc(String(reader.result)); report.innerHTML = '<p>Archivo cargado en el editor. Valida antes de aplicar.</p>'; };
                reader.readAsText(file);
                e.target.value = '';
            });
            document.querySelectorAll('.ef-history-load').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    try { setDoc(JSON.stringify(JSON.parse(btn.getAttribute('data-envelope')), null, 2)); } catch (e) { return; }
                    document.querySelector('.ef-tab-btn[data-ef-tab="json"]').click();
                    report.innerHTML = '<p>Snapshot cargado en el editor. Revisa el diff y aplica para restaurar.</p>';
                });
            });
        })();
        </script>
    <?php
}
