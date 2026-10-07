<?php
/**
 * LEAD_ASSESSMENT_V1 — Lead Assessment settings section (partial).
 *
 * Included from templates/admin/settings.php. Two self-contained admin-post
 * forms following the canonical settings pattern (capability + nonce +
 * sanitize + update_option):
 *   1. Fit Indicator  → Estandar_Leads_Fit_Indicator::save_settings()
 *   2. AI Assessment  → Estandar_Leads_Assessment_AI::save_settings()
 *
 * Defaults keep the plugin byte-identical: indicator OFF, AI OFF.
 */

$fit_saved = isset( $_GET['fit_saved'] );
$ai_saved  = isset( $_GET['ai_saved'] );

$fit_settings = class_exists( 'Estandar_Leads_Fit_Indicator' ) ? Estandar_Leads_Fit_Indicator::settings() : array();
$ai_settings  = class_exists( 'Estandar_Leads_Assessment_AI' ) ? Estandar_Leads_Assessment_AI::settings() : array();

$fit_modes = array( 'text', 'badge', 'emoji', 'icon', 'image', 'html', 'template' );
$fit_states = class_exists( 'Estandar_Leads_Fit' ) ? Estandar_Leads_Fit::states() : array();
?>

<section id="lead-assessment" style="scroll-margin-top:48px;">
<hr style="margin:40px 0 32px;">

<h2 style="font-size:16px;font-weight:700;color:#111827;margin:0 0 4px;">Lead Assessment (calidad comercial)</h2>
<p style="margin:0 0 20px;font-size:13px;color:#6b7280;">
    Dimensión transversal <code>commercial_fit</code>: detecta leads comercialmente
    incorrectos o dudosos. Reglas deterministas primero; IA opcional, async y no autoritativa.
    Nunca elimina leads ni cambia <code>status</code>/<code>temperature</code>/<code>quality</code>.
    Configuración por instalación; la semántica canónica permanece central.
</p>

<?php if ( $fit_saved ) : ?>
    <div class="notice notice-success is-dismissible"><p>Configuración del indicador guardada.</p></div>
<?php endif; ?>
<?php if ( $ai_saved ) : ?>
    <div class="notice notice-success is-dismissible"><p>Configuración de IA guardada.</p></div>
<?php endif; ?>

<!-- ── Fit Indicator ─────────────────────────────────────────────────────── -->
<h3 style="font-size:14px;font-weight:700;color:#111827;margin:24px 0 12px;">Indicador de calidad (email)</h3>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:760px;">
    <?php wp_nonce_field( 'estandar_leads_save_fit_indicator_settings' ); ?>
    <input type="hidden" name="action" value="estandar_leads_save_fit_indicator_settings">

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row">Indicador</th>
            <td>
                <label>
                    <input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $fit_settings['enabled'] ) ); ?>>
                    Mostrar indicador de calidad comercial junto al nombre del lead
                </label>
                <p class="description">Representa CALIDAD COMERCIAL, no urgencia. Desactivado por defecto (email sin cambios).</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="fit_indicator_mode">Modo</label></th>
            <td>
                <select id="fit_indicator_mode" name="mode">
                    <?php foreach ( $fit_modes as $mode ) : ?>
                        <option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $fit_settings['mode'], $mode ); ?>><?php echo esc_html( $mode ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description">text · badge · emoji · icon · image · html · template. Extensible por filtro por instalación.</p>
            </td>
        </tr>
    </table>

    <h4 style="font-size:13px;font-weight:700;color:#111827;margin:16px 0 8px;">Representación por estado (opcional)</h4>
    <table class="widefat striped" style="max-width:760px;">
        <thead>
            <tr>
                <th>Estado</th>
                <th>Etiqueta</th>
                <th>Emoji</th>
                <th>Icono (clase)</th>
                <th>Imagen (URL)</th>
                <th>Color</th>
            </tr>
        </thead>
        <tbody>
            <?php $state_map = is_array( $fit_settings['state_map'] ?? null ) ? $fit_settings['state_map'] : array(); ?>
            <?php foreach ( $fit_states as $state ) : ?>
                <?php $cfg = is_array( $state_map[ $state ] ?? null ) ? $state_map[ $state ] : array(); ?>
                <tr>
                    <td><code><?php echo esc_html( $state ); ?></code></td>
                    <td><input type="text" name="state_map[<?php echo esc_attr( $state ); ?>][label]" class="regular-text" value="<?php echo esc_attr( $cfg['label'] ?? '' ); ?>" placeholder="<?php echo esc_attr( Estandar_Leads_Fit::state_label( $state ) ); ?>"></td>
                    <td><input type="text" name="state_map[<?php echo esc_attr( $state ); ?>][emoji]" class="small-text" value="<?php echo esc_attr( $cfg['emoji'] ?? '' ); ?>"></td>
                    <td><input type="text" name="state_map[<?php echo esc_attr( $state ); ?>][icon]" class="regular-text" value="<?php echo esc_attr( $cfg['icon'] ?? '' ); ?>"></td>
                    <td><input type="url" name="state_map[<?php echo esc_attr( $state ); ?>][image_url]" class="regular-text" value="<?php echo esc_attr( $cfg['image_url'] ?? '' ); ?>"></td>
                    <td><input type="text" name="state_map[<?php echo esc_attr( $state ); ?>][color]" class="small-text" value="<?php echo esc_attr( $cfg['color'] ?? '' ); ?>" placeholder="#5f6368"></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top:16px;"><?php submit_button( 'Guardar configuración del indicador' ); ?></p>
</form>

<!-- ── AI Assessment ─────────────────────────────────────────────────────── -->
<h3 style="font-size:14px;font-weight:700;color:#111827;margin:32px 0 12px;">IA opcional (coherencia de texto libre)</h3>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px;">
    <?php wp_nonce_field( 'estandar_leads_save_assessment_ai_settings' ); ?>
    <input type="hidden" name="action" value="estandar_leads_save_assessment_ai_settings">

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row">IA</th>
            <td>
                <label>
                    <input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $ai_settings['enabled'] ) ); ?>>
                    Habilitar evaluación IA asíncrona (requiere proveedor registrado por filtro)
                </label>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ai_provider">Proveedor</label></th>
            <td>
                <input class="regular-text" id="ai_provider" name="provider" value="<?php echo esc_attr( $ai_settings['provider'] ); ?>" placeholder="mi-proveedor">
                <p class="description">ID del proveedor registrado vía <code>estandar_leads_assessment_ai_provider</code>.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Aplicar resultado</th>
            <td>
                <label>
                    <input type="checkbox" name="dry_run" value="0" <?php checked( empty( $ai_settings['dry_run'] ) ); ?>>
                    Aplicar el resultado de la IA al lead (desmarcado = solo auditar en dry-run, sin aplicar)
                </label>
                <p class="description">Dry-run por defecto: la IA se ejecuta, audita el evento <code>fit_ai_assessed</code> y NO modifica el lead hasta que marques esta opción.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Proponer sin reglas</th>
            <td>
                <label>
                    <input type="checkbox" name="propose_when_absent" value="1" <?php checked( ! empty( $ai_settings['propose_when_absent'] ) ); ?>>
                    Permitir que la IA proponga fit cuando ninguna regla decidió (siempre con revisión)
                </label>
            </td>
        </tr>
    </table>

    <p class="description" style="margin-top:8px;">La IA nunca sobreescribe reglas ni al operador; nunca bloquea captura; los resultados son auditables.</p>
    <p style="margin-top:16px;"><?php submit_button( 'Guardar configuración de IA' ); ?></p>
</form>
</section>
