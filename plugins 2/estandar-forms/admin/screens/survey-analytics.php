<?php
if ( ! defined('ABSPATH') ) exit;

function estandar_surveys_analytics_page(): void {
    if ( ! current_user_can('manage_options') ) { wp_die('No autorizado'); }

    global $wpdb;
    $forms_table = $wpdb->prefix . 'estandar_forms';
    $survey_id   = isset( $_GET['survey_id'] ) ? (int) $_GET['survey_id'] : 0;

    if ( $survey_id <= 0 ) {
        wp_safe_redirect( admin_url('admin.php?page=estandar-surveys') );
        exit;
    }

    $survey = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM $forms_table WHERE id = %d AND form_type = 'survey'",
        $survey_id
    ) );
    if ( ! $survey ) { wp_die('Encuesta no encontrada.'); }

    if ( ! class_exists('Estandar_Survey_Analytics') ) { wp_die('Analytics no disponible.'); }

    $summary      = Estandar_Survey_Analytics::get_summary( $survey_id );
    $funnel       = Estandar_Survey_Analytics::get_funnel( $survey_id );
    $q_times      = Estandar_Survey_Analytics::get_question_times( $survey_id );
    $nps          = Estandar_Survey_Analytics::get_nps( $survey_id );
    $rating       = Estandar_Survey_Analytics::get_rating_avg( $survey_id );
    $matrix_stats = Estandar_Survey_Analytics::get_matrix_stats( $survey_id );
    $emoji_dist   = Estandar_Survey_Analytics::get_choice_distribution( $survey_id, 'emoji' );
    $cards_dist   = Estandar_Survey_Analytics::get_choice_distribution( $survey_id, 'cards' );
    $radio_dist   = Estandar_Survey_Analytics::get_choice_distribution( $survey_id, 'radio' );

    $avg_min = $summary['avg_time_seconds'] > 0
        ? floor( $summary['avg_time_seconds'] / 60 ) . ':' . str_pad( $summary['avg_time_seconds'] % 60, 2, '0', STR_PAD_LEFT )
        : '—';

    estandar_forms_enqueue_admin_assets();
    estandar_forms_render_admin_layout_start();
    ?>
    <?php estandar_forms_render_admin_surface_start(); ?>

    <?php estandar_forms_render_admin_tabs('estandar-surveys'); ?>
    <?php estandar_surveys_render_nav('analytics'); ?>

    <!-- Summary metrics -->
    <section class="estandar-admin-panel">
        <div class="estandar-admin-panel-header"><h3>Resumen</h3></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:16px;padding:16px 0">

            <?php
            $metrics = [
                ['Vistas',        $summary['views'],           '#0069ff', '#eff6ff'],
                ['Iniciadas',     $summary['starts'],          '#7c3aed', '#f5f3ff'],
                ['Completadas',   $summary['completed'],       '#059669', '#d1fae5'],
                ['Abandonadas',   $summary['abandoned'],       '#d97706', '#fef3c7'],
                ['Tasa compl.',   $summary['completion_rate'] . '%', '#0284c7', '#e0f2fe'],
                ['Tiempo prom.',  $avg_min,                   '#dc2626', '#fee2e2'],
            ];
            foreach ($metrics as [$label, $value, $color, $bg]) : ?>
            <div style="background:<?php echo $bg; ?>;border-radius:10px;padding:16px;text-align:center">
                <div style="font-size:1.75rem;font-weight:700;color:<?php echo $color; ?>"><?php echo esc_html($value); ?></div>
                <div style="font-size:.8rem;color:#6b7280;margin-top:4px"><?php echo esc_html($label); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- NPS -->
    <?php if ( $nps['total'] > 0 ) : ?>
    <section class="estandar-admin-panel" style="margin-top:16px">
        <div class="estandar-admin-panel-header"><h3>NPS Score</h3></div>
        <div style="display:flex;gap:24px;align-items:center;padding:16px 0;flex-wrap:wrap">
            <div style="text-align:center;min-width:100px">
                <?php
                $nps_score = $nps['score'];
                $nps_color = $nps_score === null ? '#6b7280' : ($nps_score >= 50 ? '#059669' : ($nps_score >= 0 ? '#d97706' : '#dc2626'));
                ?>
                <div style="font-size:3rem;font-weight:800;color:<?php echo $nps_color; ?>">
                    <?php echo $nps_score !== null ? (int)$nps_score : '—'; ?>
                </div>
                <div style="font-size:.8rem;color:#6b7280">NPS</div>
            </div>
            <div style="display:flex;gap:20px;flex-wrap:wrap">
                <?php
                $nps_segments = [
                    ['Promotores (7)',    $nps['promoters'],  '#059669', '#d1fae5'],
                    ['Pasivos (5-6)',     $nps['passives'],   '#d97706', '#fef3c7'],
                    ['Detractores (0-4)', $nps['detractors'], '#dc2626', '#fee2e2'],
                ];
                foreach ($nps_segments as [$lbl, $cnt, $color, $bg]) : ?>
                <div style="background:<?php echo $bg; ?>;padding:12px 20px;border-radius:8px;text-align:center">
                    <div style="font-size:1.5rem;font-weight:700;color:<?php echo $color; ?>"><?php echo $cnt; ?></div>
                    <div style="font-size:.78rem;color:#6b7280"><?php echo esc_html($lbl); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="color:#6b7280;font-size:.82rem">Total: <?php echo $nps['total']; ?> respuestas</div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Rating -->
    <?php if ( $rating['total'] > 0 ) : ?>
    <section class="estandar-admin-panel" style="margin-top:16px">
        <div class="estandar-admin-panel-header"><h3>Rating promedio</h3></div>
        <div style="padding:16px 0;display:flex;align-items:center;gap:16px">
            <div style="font-size:2.5rem;font-weight:700;color:#f59e0b"><?php echo number_format((float)$rating['avg_rating'], 1); ?></div>
            <div style="color:#6b7280;font-size:.9rem">/ <?php echo (int) ( $rating['max'] ?? 5 ); ?> estrellas · <?php echo $rating['total']; ?> respuestas</div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Evaluación múltiple -->
    <?php if ( ! empty($matrix_stats) ) : ?>
    <section class="estandar-admin-panel" style="margin-top:16px">
        <div class="estandar-admin-panel-header"><h3>Evaluación múltiple</h3></div>
        <div style="padding:16px 0">
            <p style="font-size:.82rem;color:#6b7280;margin:0 0 12px">Ordenado de mejor a peor evaluación</p>
            <?php foreach ( $matrix_stats as $ms ) : ?>
            <div style="margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f3f4f6">
                <div style="font-weight:600;margin-bottom:6px;font-size:.9rem"><?php echo esc_html( $ms['label'] ); ?></div>
                <div style="display:flex;align-items:center;gap:12px;font-size:.85rem;flex-wrap:wrap">
                    <span style="color:#f59e0b;font-weight:700"><?php echo $ms['avg'] !== null ? number_format( $ms['avg'], 1 ) : '—'; ?></span>
                    <span style="color:#6b7280">promedio</span>
                    <?php if ( $ms['stddev'] !== null ) : ?>
                    <span style="color:#9ca3af">σ <?php echo number_format( $ms['stddev'], 1 ); ?></span>
                    <?php endif; ?>
                    <span style="color:#6b7280">· <?php echo (int) $ms['total']; ?> respuestas</span>
                </div>
                <div style="display:flex;gap:4px;margin-top:6px">
                    <?php $scale = count( $ms['dist'] ); ?>
                    <?php for ( $s = 1; $s <= $scale; $s++ ) :
                        $pct = $ms['total'] > 0 ? round( ( $ms['dist'][ $s ] / $ms['total'] ) * 100 ) : 0;
                    ?>
                    <div style="flex:1;text-align:center;font-size:.72rem;color:#6b7280">
                        <div style="background:#f3f4f6;height:40px;border-radius:4px;position:relative;overflow:hidden">
                            <div style="background:#f59e0b;position:absolute;bottom:0;left:0;right:0;height:<?php echo $pct; ?>%;border-radius:4px"></div>
                        </div>
                        <div style="margin-top:2px"><?php echo $s; ?></div>
                        <div><?php echo (int) $ms['dist'][ $s ]; ?></div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Funnel -->
    <?php if ( ! empty($funnel) ) : ?>
    <section class="estandar-admin-panel" style="margin-top:16px">
        <div class="estandar-admin-panel-header"><h3>Funnel por pregunta</h3></div>
        <div style="padding:16px 0">
            <?php foreach ( $funnel as $f_item ) :
                $f_qi   = (int)$f_item['question_index'] + 1;
                $f_rate = (float)$f_item['rate'];
                $f_cnt  = (int)$f_item['views'];
                $q_label = '';
                foreach ($q_times as $qt) {
                    if ($qt['question_index'] === (int)$f_item['question_index']) {
                        $q_label = $qt['question_label'];
                        break;
                    }
                }
            ?>
            <div style="margin-bottom:12px">
                <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:4px">
                    <span><strong>P<?php echo $f_qi; ?></strong> <?php echo esc_html($q_label); ?></span>
                    <span style="color:#6b7280"><?php echo $f_cnt; ?> vistas · <strong><?php echo $f_rate; ?>%</strong></span>
                </div>
                <div style="height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden">
                    <div style="height:100%;width:<?php echo (float)$f_rate; ?>%;background:#0069ff;border-radius:4px;transition:width .3s"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Tiempo por pregunta -->
    <?php if ( ! empty($q_times) ) : ?>
    <section class="estandar-admin-panel" style="margin-top:16px">
        <div class="estandar-admin-panel-header"><h3>Tiempo promedio por pregunta</h3></div>
        <table class="widefat striped" style="margin-top:0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Pregunta</th>
                    <th>Tipo</th>
                    <th>Tiempo prom.</th>
                    <th>Respuestas</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $q_times as $qt ) : ?>
            <tr>
                <td><?php echo (int)$qt['question_index'] + 1; ?></td>
                <td><?php echo esc_html($qt['question_label']); ?></td>
                <td><code><?php echo esc_html($qt['question_type']); ?></code></td>
                <td><?php echo (int)$qt['avg_seconds']; ?>s</td>
                <td><?php echo (int)$qt['response_count']; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <!-- Choice distributions -->
    <?php
    $choice_sections = [
        ['Distribución Emojis',  $emoji_dist],
        ['Distribución Cards',   $cards_dist],
        ['Distribución Radio',   $radio_dist],
    ];
    foreach ($choice_sections as [$section_label, $dist]) :
        if ( empty($dist) ) continue;
    ?>
    <section class="estandar-admin-panel" style="margin-top:16px">
        <div class="estandar-admin-panel-header"><h3><?php echo esc_html($section_label); ?></h3></div>
        <?php foreach ($dist as $q_dist) :
            $q_total = array_sum(array_column($q_dist['choices'], 'count'));
        ?>
        <div style="padding:12px 0;border-bottom:1px solid #f3f4f6">
            <p style="font-weight:600;margin:0 0 12px"><?php echo esc_html($q_dist['question_label']); ?></p>
            <?php foreach ($q_dist['choices'] as $choice) :
                $c_pct = $q_total > 0 ? round($choice['count'] / $q_total * 100, 1) : 0;
            ?>
            <div style="margin-bottom:8px">
                <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:3px">
                    <span><?php echo esc_html($choice['label'] ?: $choice['value']); ?></span>
                    <span style="color:#6b7280"><?php echo $choice['count']; ?> (<?php echo $c_pct; ?>%)</span>
                </div>
                <div style="height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden">
                    <div style="height:100%;width:<?php echo $c_pct; ?>%;background:#7c3aed;border-radius:3px"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>

    <?php if ( $summary['starts'] === 0 ) : ?>
    <section class="estandar-admin-panel" style="margin-top:16px;text-align:center;padding:48px">
        <p style="color:#6b7280;font-size:1rem">Sin datos todavía. Comparte la encuesta para comenzar a recopilar respuestas.</p>
        <code style="display:inline-block;margin-top:8px">[estandar_form id="<?php echo $survey_id; ?>"]</code>
    </section>
    <?php endif; ?>

    <?php
    estandar_forms_render_admin_surface_end();
    estandar_forms_render_admin_layout_end();
}
