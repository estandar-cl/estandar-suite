<?php
$activation = Estandar_Leads_Sales_Activation::get_settings();
$activation_state = Estandar_Leads_Sales_Activation::get_run_state();
$next_activation = wp_next_scheduled( Estandar_Leads_Sales_Activation::CRON_HOOK );
$day_labels = array( 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo' );
$test_result = false;
if ( isset( $_GET['activation_result'] ) ) {
	$test_result = get_transient( 'estandar_leads_sales_activation_test_' . get_current_user_id() );
	delete_transient( 'estandar_leads_sales_activation_test_' . get_current_user_id() );
}
?>
<div style="padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;margin-bottom:16px;">
<strong>Fase 1 &nbsp; ✓ Email</strong>
<p class="description" style="margin:5px 0 8px;">Actualmente disponible por email. El renderer consume exclusivamente Recommendations producidas por Sales Activation Engine.</p>
<strong style="font-size:12px;">Próximamente</strong>
<span style="display:block;font-size:12px;color:#64748b;margin-top:3px;">WhatsApp · Dashboard inteligente · Push · Slack / Teams · API</span>
</div>
<?php if ( isset( $_GET['activation_saved'] ) ) : ?><div class="notice notice-success inline"><p>Configuración de activación guardada.</p></div><?php endif; ?>
<?php if ( is_array( $test_result ) ) : ?>
<div class="notice notice-<?php echo $test_result['status'] === 'success' ? 'success' : ( $test_result['status'] === 'error' ? 'error' : 'warning' ); ?> inline"><p><strong><?php echo esc_html( strtoupper( $test_result['status'] ) ); ?>:</strong> <?php echo esc_html( $test_result['message'] ); ?><?php if ( ! empty( $test_result['recipients'] ) ) : ?> Destinatarios: <?php echo esc_html( implode( ', ', $test_result['recipients'] ) ); ?>.<?php endif; ?></p></div>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:820px;">
<?php wp_nonce_field( 'estandar_leads_sales_activation_settings' ); ?><input type="hidden" name="action" value="estandar_leads_save_sales_activation">
<table class="form-table" role="presentation">
<tr><th>Habilitado</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $activation['enabled'], 1 ); ?>> Enviar activaciones programadas</label></td></tr>
<tr><th>Días de envío</th><td><?php foreach ( $day_labels as $day => $label ) : ?><label style="margin-right:14px;"><input type="checkbox" name="send_days[]" value="<?php echo (int) $day; ?>" <?php checked( in_array( $day, $activation['send_days'], true ) ); ?>> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></td></tr>
<tr><th><label for="activation_time">Hora</label></th><td><input id="activation_time" type="time" name="send_time" value="<?php echo esc_attr( $activation['send_time'] ); ?>"><p class="description">Timezone de WordPress: <?php echo esc_html( wp_timezone_string() ); ?>.</p></td></tr>
<tr><th><label for="activation_max">Máximo de recomendaciones</label></th><td><input id="activation_max" type="number" min="1" max="5" name="max_recommendations" value="<?php echo (int) $activation['max_recommendations']; ?>"></td></tr>
<tr><th><label for="activation_min">Score mínimo</label></th><td><input id="activation_min" type="number" min="1" max="100" name="min_score" value="<?php echo (int) $activation['min_score']; ?>"><p class="description">Umbral interno de activación. El score no se muestra en el correo.</p></td></tr>
<tr><th><label for="activation_recipients">Destinatarios</label></th><td><textarea id="activation_recipients" name="recipients" rows="2" class="large-text"><?php echo esc_textarea( $activation['recipients'] ); ?></textarea><p class="description">Opcional. Vacío usa el email del operador. Si se completa, reemplaza el destinatario operativo; admite varios emails separados por coma.</p></td></tr>
<tr><th><label for="activation_cc">CC</label></th><td><textarea id="activation_cc" name="cc" rows="2" class="large-text"><?php echo esc_textarea( $activation['cc'] ); ?></textarea></td></tr>
<tr><th><label for="activation_tone">Tono motivacional</label></th><td><select id="activation_tone" name="tone"><?php foreach ( array( 'disabled' => 'Desactivado', 'professional' => 'Profesional', 'close' => 'Cercano', 'inspiring' => 'Inspirador', 'custom' => 'Personalizado' ) as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $activation['tone'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
<tr><th><label for="activation_intro">Texto personalizado</label></th><td><textarea id="activation_intro" name="custom_intro" rows="3" class="large-text"><?php echo esc_textarea( $activation['custom_intro'] ); ?></textarea><p class="description">Se usa con el tono “Personalizado”. No se agregan citas atribuidas a terceros.</p></td></tr>
<tr><th><label for="activation_closing">Cierre personalizado</label></th><td><textarea id="activation_closing" name="custom_closing" rows="3" class="large-text"><?php echo esc_textarea( $activation['custom_closing'] ); ?></textarea></td></tr>
<tr><th>Próximo envío</th><td><strong><?php echo $next_activation ? esc_html( wp_date( 'd/m/Y H:i', $next_activation, wp_timezone() ) ) : 'No programado'; ?></strong></td></tr>
<tr><th>Último envío</th><td><strong><?php echo $activation_state['last_send_at'] ? esc_html( Estandar_Leads_Normalizer::datetime_local( $activation_state['last_send_at'] ) ) : 'Sin envíos registrados'; ?></strong><?php if ( $activation_state['last_run_at'] ) : ?><p class="description">Última ejecución: <?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $activation_state['last_run_at'] ) ); ?> · <?php echo esc_html( $activation_state['message'] ); ?></p><?php endif; ?></td></tr>
<tr><th>Recomendaciones enviadas</th><td><?php echo (int) $activation_state['recommendation_count']; ?></td></tr>
<tr><th>Destinatarios efectivos</th><td><?php echo ! empty( $activation_state['recipients'] ) ? esc_html( implode( ', ', $activation_state['recipients'] ) ) : 'Sin envíos registrados'; ?></td></tr>
</table>
<?php submit_button( 'Guardar Sales Activation' ); ?>
</form>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:820px;margin:0 0 28px;">
<?php wp_nonce_field( 'estandar_leads_test_sales_activation' ); ?><input type="hidden" name="action" value="estandar_leads_test_sales_activation">
<label for="activation_test_email"><strong>Enviar prueba ahora</strong></label><br>
<input id="activation_test_email" type="email" name="test_email" class="regular-text" required value="<?php echo esc_attr( get_option( 'admin_email', '' ) ); ?>">
<?php submit_button( 'Enviar prueba ahora', 'secondary', 'submit', false ); ?>
</form>
