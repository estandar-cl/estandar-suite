<?php
/** @var string $operator_name @var array $recommendations @var array $tone @var array $email_settings */
$brand = $email_settings['brand_color'] ?? '#1a73e8';
$logo  = $email_settings['logo_url'] ?? '';
?>
<!doctype html>
<html><body style="margin:0;background:#f4f6f8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#172033;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:28px 12px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;background:#fff;border-radius:12px;overflow:hidden;">
<tr><td style="padding:28px 32px;background:<?php echo esc_attr( $brand ); ?>;color:#fff;">
<?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="" style="max-height:36px;max-width:180px;margin-bottom:16px;"><br><?php endif; ?>
<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">Activación de ventas</div>
<h1 style="font-size:25px;line-height:1.25;margin:8px 0 6px;">Oportunidades para activar hoy</h1>
<p style="margin:0;font-size:15px;line-height:1.5;">Hola <?php echo esc_html( $operator_name ?: 'equipo' ); ?>, priorizamos señales comerciales verificables para ayudarte a decidir el siguiente contacto.</p>
</td></tr>
<?php if ( ! empty( $tone['intro'] ) ) : ?><tr><td style="padding:20px 32px 0;font-size:14px;line-height:1.6;color:#465066;"><?php echo esc_html( $tone['intro'] ); ?></td></tr><?php endif; ?>
<tr><td style="padding:20px 24px 28px;">
<?php foreach ( $recommendations as $item ) : ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e8ef;border-radius:10px;margin-bottom:16px;">
<tr><td style="padding:20px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
<td><div style="font-size:18px;font-weight:750;color:#172033;"><?php echo esc_html( $item['name'] ?: 'Lead sin nombre' ); ?></div><div style="font-size:13px;color:#687386;margin-top:3px;"><?php echo esc_html( $item['project_name'] ); ?></div></td>
<td align="right" valign="top"><span style="display:inline-block;padding:5px 9px;border-radius:999px;background:<?php echo $item['priority'] === 'ALTA' ? '#fee2e2' : ( $item['priority'] === 'MEDIA' ? '#fef3c7' : '#e8f0fe' ); ?>;color:<?php echo $item['priority'] === 'ALTA' ? '#991b1b' : ( $item['priority'] === 'MEDIA' ? '#92400e' : '#174ea6' ); ?>;font-size:11px;font-weight:800;">PRIORIDAD <?php echo esc_html( $item['priority'] ); ?></span></td>
</tr></table>
<div style="font-size:13px;color:#465066;line-height:1.55;margin-top:13px;"><?php echo esc_html( $item['journey_summary'] ); ?></div>
<div style="font-size:13px;color:#465066;line-height:1.55;margin-top:8px;"><strong><?php echo esc_html( $item['phone'] ); ?></strong><?php if ( $item['email'] ) : ?> · <?php echo esc_html( $item['email'] ); ?><?php endif; ?></div>
<ul style="margin:14px 0 12px;padding-left:20px;color:#263247;font-size:13px;line-height:1.6;">
<?php foreach ( $item['reasons'] as $reason ) : ?><li><?php echo esc_html( $reason ); ?></li><?php endforeach; ?>
</ul>
<div style="font-size:12px;color:#7a8495;margin-bottom:15px;">Última actividad: <?php echo esc_html( Estandar_Leads_Normalizer::datetime_local( $item['latest_activity'] ) ); ?></div>
<table role="presentation" cellpadding="0" cellspacing="0"><tr>
<?php foreach ( array( 'whatsapp_url' => 'WhatsApp', 'call_url' => 'Llamar', 'classify_url' => 'Clasificar', 'detail_url' => 'Abrir ficha' ) as $key => $label ) : ?>
<td style="padding:0 7px 7px 0;"><a href="<?php echo esc_url( $item[ $key ] ); ?>" style="display:inline-block;padding:9px 12px;border-radius:6px;background:<?php echo $key === 'whatsapp_url' ? '#128c7e' : $brand; ?>;color:#fff;text-decoration:none;font-size:12px;font-weight:700;"><?php echo esc_html( $label ); ?></a></td>
<?php endforeach; ?>
</tr></table>
</td></tr></table>
<?php endforeach; ?>
<?php if ( ! empty( $tone['closing'] ) ) : ?><p style="margin:8px 8px 0;font-size:13px;line-height:1.6;color:#465066;"><?php echo esc_html( $tone['closing'] ); ?></p><?php endif; ?>
</td></tr></table>
</td></tr></table>
</body></html>
