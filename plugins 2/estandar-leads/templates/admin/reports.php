    <p><strong>Leads sin respuesta comercial:</strong> <?php echo esc_html( $pending ); ?></p>
    <p><strong>Ingresos sospechosos (últimos 30 días):</strong> <?php echo esc_html( $suspect ); ?></p>

    <!-- ── Tiempo entre etapas (diferencial) ────────────────────────────── -->
    <h2>Tiempo entre etapas</h2>
    <p style="color:#646970;font-size:13px;margin:-8px 0 12px;">
        Días transcurridos entre estados consecutivos.
        Responde: <strong>¿dónde se ralentiza el proceso comercial?</strong>
    </p>
    <?php if ( empty( $stage_age_differential ) ) : ?>
        <p style="color:#646970;">Sin transiciones registradas aún.</p>
    <?php else : ?>
        <table class="widefat striped" style="max-width:640px;margin-bottom:28px;">
            <thead>
                <tr>
                    <th>Desde</th><th>→ Hasta</th>
                    <th style="text-align:right;">N</th>
                    <th style="text-align:right;">Promedio</th>
                    <th style="text-align:right;">Mín.</th>
                    <th style="text-align:right;">Máx.</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $stage_age_differential as $row ) : ?>
                <tr>
                    <td><code style="font-size:11px;"><?php echo esc_html( $row->from_status ); ?></code></td>
                    <td><code style="font-size:11px;"><?php echo esc_html( $row->to_status ); ?></code></td>
                    <td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $row->transitions ) ); ?></td>
                    <td style="text-align:right;font-weight:600;">
                        <?php echo esc_html( number_format_i18n( (float) $row->avg_days, 1 ) ); ?> d
                    </td>
                    <td style="text-align:right;color:#646970;"><?php echo esc_html( number_format_i18n( (float) $row->min_days, 1 ) ); ?> d</td>
                    <td style="text-align:right;color:#646970;"><?php echo esc_html( number_format_i18n( (float) $row->max_days, 1 ) ); ?> d</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- ── Edad acumulada por etapa (contexto) ──────────────────────────── -->
    <h2>Edad acumulada por etapa</h2>
    <p style="color:#646970;font-size:13px;margin:-8px 0 12px;">
        Días desde la creación del lead hasta cada transición de estado.
        Contexto del ciclo total — no diagnóstica dónde se pierde velocidad.
    </p>
    <?php if ( empty( $stage_age_cumulative ) ) : ?>
        <p style="color:#646970;">Sin transiciones registradas aún.</p>
    <?php else : ?>
        <table class="widefat striped" style="max-width:640px;margin-bottom:28px;">
            <thead>
                <tr>
                    <th>Desde</th><th>→ Hasta</th>
                    <th style="text-align:right;">N</th>
                    <th style="text-align:right;">Promedio</th>
                    <th style="text-align:right;">Mín.</th>
                    <th style="text-align:right;">Máx.</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $stage_age_cumulative as $row ) : ?>
                <tr>
                    <td><code style="font-size:11px;"><?php echo esc_html( $row->from_status ); ?></code></td>
                    <td><code style="font-size:11px;"><?php echo esc_html( $row->to_status ); ?></code></td>
                    <td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $row->transitions ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( number_format_i18n( (float) $row->avg_days, 1 ) ); ?> d</td>
                    <td style="text-align:right;color:#646970;"><?php echo esc_html( number_format_i18n( (float) $row->min_days, 1 ) ); ?> d</td>
                    <td style="text-align:right;color:#646970;"><?php echo esc_html( number_format_i18n( (float) $row->max_days, 1 ) ); ?> d</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- ── Conversión por operador ──────────────────────────────────────── -->
    <h2>Conversión por operador</h2>
    <p style="color:#646970;font-size:13px;margin:-8px 0 12px;">
        No para castigar — para detectar diferencias de proceso y replicar lo que funciona.
    </p>
    <?php if ( empty( $operator_perf ) ) : ?>
        <p style="color:#646970;">Sin datos de operadores aún.</p>
    <?php else : ?>
        <table class="widefat striped" style="max-width:700px;margin-bottom:28px;">
            <thead>
                <tr>
                    <th>Operador</th>
                    <th style="text-align:right;">Leads</th>
                    <th style="text-align:right;">Clasificados <span title="Leads donde el operador completó la clasificación (calidad, etapa o feedback). Antes esta columna contaba solo status=QUALIFIED, que casi nunca se usa — por eso mostraba 0 aunque el operador sí clasificara." style="cursor:help;">ⓘ</span></th>
                    <th style="text-align:right;">Calificados <span title="Leads cuyo estado comercial es QUALIFIED (hito comercial, distinto de 'clasificado por el operador')." style="cursor:help;">ⓘ</span></th>
                    <th style="text-align:right;">Reuniones</th>
                    <th style="text-align:right;">Reservas</th>
                    <th style="text-align:right;">Ventas</th>
                    <th style="text-align:right;">Cierre %</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $operator_perf as $row ) :
                $cierre = (float) ( $row->cierre_pct ?? 0 );
            ?>
                <tr>
                    <td><?php echo esc_html( $row->operador ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $row->leads ) ); ?></td>
                    <td style="text-align:right;"><strong><?php echo esc_html( number_format_i18n( (int) ( $row->clasificados ?? 0 ) ) ); ?></strong></td>
                    <td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $row->calificados ) ); ?></td>
                    <td style="text-align:right;">
                        <?php if ( (int) $row->reuniones > 0 ) : ?>
                            <strong><?php echo esc_html( number_format_i18n( (int) $row->reuniones ) ); ?></strong>
                        <?php else : ?>
                            <span style="color:#c3c4c7;">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <?php if ( (int) $row->reservas > 0 ) : ?>
                            <strong><?php echo esc_html( number_format_i18n( (int) $row->reservas ) ); ?></strong>
                        <?php else : ?>
                            <span style="color:#c3c4c7;">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <?php if ( (int) $row->ventas > 0 ) : ?>
                            <strong style="color:#00a32a;"><?php echo esc_html( number_format_i18n( (int) $row->ventas ) ); ?></strong>
                        <?php else : ?>
                            <span style="color:#c3c4c7;">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;color:<?php echo $cierre > 0 ? '#00a32a' : '#646970'; ?>;">
                        <?php echo esc_html( number_format_i18n( $cierre, 1 ) . ' %' ); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- ── Latencia por operador ─────────────────────────────────────────── -->
    <h2>Latencia por operador</h2>
    <p style="color:#646970;font-size:13px;margin:-8px 0 12px;">
        Velocidad de gestión por operador. Detecta quién acumula leads sin clasificar.
    </p>
    <?php if ( empty( $operator_latency ) ) : ?>
        <p style="color:#646970;">Sin datos de operadores aún.</p>
    <?php else : ?>
        <table class="widefat striped" style="max-width:760px;margin-bottom:28px;">
            <thead>
                <tr>
                    <th>Operador</th>
                    <th style="text-align:right;">Asignados</th>
                    <th style="text-align:right;">NEW pendientes</th>
                    <th style="text-align:right;" title="NEW sin mover en más de 7 días">Vencidos +7d</th>
                    <th style="text-align:right;" title="Días desde asignación hasta primera clasificación">Días hasta clasificar</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $operator_latency as $row ) :
                $vencidos   = (int) ( $row->vencidos_7d ?? 0 );
                $avg_classify = $row->avg_days_to_classify !== null
                    ? number_format_i18n( (float) $row->avg_days_to_classify, 1 ) . ' d'
                    : '—';
            ?>
                <tr>
                    <td><?php echo esc_html( $row->operador ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $row->leads_asignados ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( number_format_i18n( (int) $row->pendientes_new ) ); ?></td>
                    <td style="text-align:right;<?php echo $vencidos > 0 ? 'color:#d63638;font-weight:600;' : 'color:#c3c4c7;'; ?>">
                        <?php echo esc_html( $vencidos > 0 ? number_format_i18n( $vencidos ) : '0' ); ?>
                    </td>
                    <td style="text-align:right;"><?php echo esc_html( $avg_classify ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- ── Campañas por resultado ────────────────────────────────────────── -->
    <h2>Campañas que traen buenos leads</h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Campaña</th><th>Ingresos</th><th>Buen lead</th>
                <th>No encaja</th><th>No responde</th><th>Reunión</th><th>Reserva</th><th>Venta</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $campaigns as $row ) : ?>
            <tr>
                <td><?php echo esc_html( class_exists( 'Estandar_Campaign_Label_Resolver' ) ? Estandar_Campaign_Label_Resolver::campaign( $row->label ) : $row->label ); ?></td>
                <td><?php echo esc_html( $row->total ); ?></td>
                <td><?php echo esc_html( $row->qualified ); ?></td>
                <td><?php echo esc_html( $row->disqualified ); ?></td>
                <td><?php echo esc_html( $row->unreachable ); ?></td>
                <td><?php echo esc_html( $row->meeting ); ?></td>
                <td><?php echo esc_html( $row->reservations ); ?></td>
                <td><?php echo esc_html( $row->sales ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ── Keywords por resultado ───────────────────────────────────────── -->
    <h2>Keywords que traen buenos leads <span title="Términos de búsqueda (utm_term) de Google/Bing. Meta no envía keywords de búsqueda: sus IDs de conjunto de anuncios se excluyen de esta tabla y se nombran en Estandar → Campañas." style="cursor:help;font-size:13px;">ⓘ</span></h2>
    <table class="widefat striped" style="margin-bottom:0;">
        <thead>
            <tr><th>Keyword</th><th>Ingresos</th><th>Buen lead</th><th>Reserva</th><th>Venta</th></tr>
        </thead>
        <tbody>
        <?php foreach ( $terms as $row ) : ?>
            <tr>
                <td><?php echo esc_html( $row->label ); ?></td>
                <td><?php echo esc_html( $row->total ); ?></td>
                <td><?php echo esc_html( $row->qualified ); ?></td>
                <td><?php echo esc_html( $row->reservations ); ?></td>
                <td><?php echo esc_html( $row->sales ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
