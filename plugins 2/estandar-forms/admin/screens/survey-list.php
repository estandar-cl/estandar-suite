<?php
if ( ! defined('ABSPATH') ) exit;

function estandar_surveys_list_page(): void {
    if ( ! current_user_can('manage_options') ) { wp_die('No autorizado'); }
    $embedded = function_exists( 'estandar_core_is_embedded_admin' ) && estandar_core_is_embedded_admin();

    global $wpdb;
    $table = $wpdb->prefix . 'estandar_forms';

    /* ── Delete ──────────────────────────────────────────── */
    $deleting_id = isset( $_GET['delete'] ) ? (int) $_GET['delete'] : 0;
    if ( $deleting_id > 0 && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( (string) wp_unslash( $_GET['_wpnonce'] ), 'estandar_survey_delete_' . $deleting_id ) ) {
        $wpdb->delete( $table, ['id' => $deleting_id, 'form_type' => 'survey'], ['%d','%s'] );
        if ( class_exists('Estandar_Forms_Shortcode') ) {
            Estandar_Forms_Shortcode::clear_form_cache( $deleting_id );
        }
        echo '<div class="updated"><p>Encuesta eliminada.</p></div>';
    }

    $surveys = $wpdb->get_results(
        "SELECT * FROM $table WHERE form_type = 'survey' ORDER BY created_at DESC"
    );

    estandar_forms_enqueue_admin_assets( false );
    if ( ! $embedded ) {
        estandar_forms_render_admin_layout_start();
    }
    ?>
    <?php if ( ! $embedded ) : ?><?php estandar_forms_render_admin_surface_start(); ?>

    <?php estandar_forms_render_admin_tabs('estandar-surveys'); ?><?php endif; ?>
    <?php estandar_forms_render_notice_from_request(); ?>

    <section class="estandar-admin-panel">
        <div class="estandar-admin-panel-header">
            <div>
                <h3>Encuestas de satisfacción</h3>
                <p>Crea, publica y analiza encuestas desde aquí.</p>
            </div>
            <a href="<?php echo esc_url( admin_url('admin.php?page=estandar-surveys-edit') ); ?>"
               class="button button-primary">+ Nueva encuesta</a>
        </div>

        <?php if ( empty($surveys) ) : ?>
        <div class="estandar-admin-empty">
            <p>No hay encuestas todavía.</p>
            <a href="<?php echo esc_url( admin_url('admin.php?page=estandar-surveys-edit') ); ?>"
               class="button button-primary">Crear primera encuesta</a>
        </div>
        <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nombre</th>
                    <th>Shortcode</th>
                    <th>Preguntas</th>
                    <th>Creada</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $surveys as $survey ) :
                $sid         = (int) $survey->id;
                $s_name      = esc_html( $survey->name );
                $s_slug      = esc_html( $survey->slug ?? '' );
                $s_fields    = json_decode( (string) $survey->fields_json, true );
                $s_qcount    = is_array($s_fields) ? count($s_fields) : 0;
                $s_created   = esc_html( wp_date( 'd M Y', strtotime( $survey->created_at ) ) );

                $edit_url      = admin_url( 'admin.php?page=estandar-surveys-edit&edit=' . $sid );
                $results_url   = admin_url( 'admin.php?page=estandar-surveys-results&survey_id=' . $sid );
                $analytics_url = admin_url( 'admin.php?page=estandar-surveys-analytics&survey_id=' . $sid );
                $delete_url    = wp_nonce_url( admin_url('admin.php?page=estandar-surveys&delete=' . $sid), 'estandar_survey_delete_' . $sid );
                $dup_url       = wp_nonce_url( admin_url('admin-post.php?action=estandar_survey_duplicate&survey_id=' . $sid), 'estandar_survey_duplicate_' . $sid );
            ?>
            <tr>
                <td><?php echo $sid; ?></td>
                <td><strong><?php echo $s_name; ?></strong></td>
                <td>
                    <code>[estandar_form id="<?php echo $sid; ?>"]</code>
                    <?php if ( $s_slug !== '' ) : ?>
                    <br><small><code>[estandar_form slug="<?php echo $s_slug; ?>"]</code></small>
                    <?php endif; ?>
                </td>
                <td><?php echo $s_qcount; ?></td>
                <td><?php echo $s_created; ?></td>
                <td>
                    <a href="<?php echo esc_url($edit_url); ?>">Editar</a> |
                    <a href="<?php echo esc_url($results_url); ?>">Resultados</a> |
                    <a href="<?php echo esc_url($analytics_url); ?>">Analytics</a> |
                    <a href="<?php echo esc_url($dup_url); ?>">Duplicar</a> |
                    <a href="<?php echo esc_url($delete_url); ?>"
                       onclick="return confirm('¿Eliminar esta encuesta?')"
                       style="color:#dc2626">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <?php
    if ( ! $embedded ) {
        estandar_forms_render_admin_surface_end();
        estandar_forms_render_admin_layout_end();
    }
}

function estandar_surveys_render_nav( string $active ): void {
    $sid = isset( $_GET['survey_id'] ) ? (int) $_GET['survey_id'] : 0;
    if ( $sid <= 0 ) {
        return;
    }
    $items = [
        ['label' => 'Resultados', 'url' => admin_url( 'admin.php?page=estandar-surveys-results&survey_id=' . $sid ),   'slug' => 'results'],
        ['label' => 'Analytics',  'url' => admin_url( 'admin.php?page=estandar-surveys-analytics&survey_id=' . $sid ), 'slug' => 'analytics'],
    ];
    echo '<nav class="estandar-admin-tabs" style="margin-bottom:16px">';
    foreach ( $items as $item ) {
        $is_active = $item['slug'] === $active;
        echo '<a href="' . esc_url( $item['url'] ) . '" class="estandar-admin-tab' . ( $is_active ? ' is-active' : '' ) . '">' . esc_html( $item['label'] ) . '</a>';
    }
    echo '</nav>';
}
