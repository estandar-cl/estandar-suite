<?php
/**
 * HTML email template — resumen periódico de leads (LEAD_DIGEST_V1, self-contained).
 *
 * Variables en scope (set por Estandar\Core\Leads\LeadsDigest::render_email):
 *   $es_digest    — project_slug, project_label, sections[], generated_at
 *   $es_identity  — identidad compartida de la Suite (Core\Mail)
 *
 * Usa el formato BASE de plantillas: card 600px sobre fondo + banda de marca
 * (Mail::header_html: logo sobre color) + footer de la Suite (Mail::footer_html).
 * Tabla sin columna de fecha; el título de cada sección indica el día/semana/mes.
 * Contacto con iconos: ✉ correo (mailto) y 💬 WhatsApp (wa.me).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$es_d   = $es_digest;
$brand  = preg_match( '/^#[0-9a-fA-F]{3,8}$/', (string) ( $es_identity['brand_color'] ?? '' ) ) ? (string) $es_identity['brand_color'] : '#111111';
$label  = (string) ( $es_d['project_label'] ?? $es_d['project_slug'] ?? '' );
$pre    = (string) ( $es_identity['preheader'] ?? '' );
$gen    = (string) ( $es_d['generated_at'] ?? '' );

$months = array( 1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre' );

$fmt_dt = static function ( string $mysql ) {
	$ts = strtotime( $mysql );
	if ( ! $ts ) {
		return $mysql;
	}
	return date( 'd/m/Y H:i', $ts );
};
$fmt_day = static function ( string $ymd ) use ( $months ) {
	$ts = strtotime( $ymd );
	if ( ! $ts ) {
		return $ymd;
	}
	return (int) date( 'j', $ts ) . ' de ' . $months[ (int) date( 'n', $ts ) ];
};
$fmt_dm = static function ( string $ymd ) {
	$ts = strtotime( $ymd );
	return $ts ? date( 'd/m', $ts ) : $ymd;
};
$digits = static function ( string $phone ): string {
	$d = preg_replace( '/[^0-9]/', '', $phone );
	return (string) $d;
};

$section_title = static function ( array $s ) use ( $label, $fmt_day, $fmt_dm, $months ) {
	$key  = (string) ( $s['key'] ?? '' );
	$from = (string) ( $s['from'] ?? '' );
	$to   = (string) ( $s['to'] ?? '' );
	if ( $key === 'weekly' ) {
		return 'Interesados en ' . $label . ' la semana del ' . $fmt_dm( $from ) . ' al ' . $fmt_dm( $to );
	}
	if ( $key === 'monthly' ) {
		$ts = strtotime( $from );
		return 'Interesados en ' . $label . ' en ' . ( $ts ? $months[ (int) date( 'n', $ts ) ] . ' ' . date( 'Y', $ts ) : $from );
	}
	return 'Interesados en ' . $label . ' el día ' . $fmt_day( $from );
};
?>
<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title><?php echo esc_html( 'Leads — ' . $label ); ?></title>
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
<?php if ( $pre !== '' ) : ?><div style="display:none;font-size:1px;color:#f0f2f5;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;"><?php echo esc_html( $pre ); ?></div><?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f2f5;padding:40px 16px;">
	<tr><td align="center">
		<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
			<tr><td style="padding:0;">
				<?php echo $es_header; ?>
			</td></tr>
			<tr><td style="padding:24px 24px 8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;font-size:14px;line-height:1.55;color:#1d2327;">

				<?php foreach ( (array) ( $es_d['sections'] ?? array() ) as $section ) :
					$rows  = (array) ( $section['rows'] ?? array() );
					$count = count( $rows );
				?>
				<div style="font-size:15px;font-weight:700;color:#111;margin:6px 0 10px;">
					<?php echo esc_html( $section_title( $section ) ); ?>
					<span style="font-weight:400;color:#6b7280;">(<?php echo (int) $count; ?>)</span>
				</div>
				<?php if ( $count === 0 ) : ?>
					<div style="font-size:13px;color:#9ca3af;padding:2px 0 16px;">Sin leads.</div>
				<?php else : ?>
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
					<tr>
						<th align="center" style="width:34px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;padding:6px 4px;border-bottom:1px solid #ececef;">&nbsp;</th>
						<th align="left" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;padding:6px 8px;border-bottom:1px solid #ececef;">Lead</th>
						<th align="center" style="width:90px;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;padding:6px 8px;border-bottom:1px solid #ececef;">Contacto</th>
					</tr>
					<?php foreach ( $rows as $lead ) :
						$contacted = ! empty( $lead['contacted'] );
						$mark      = $contacted ? '✔' : '○';
						$mark_help = $contacted ? 'Contactado' : 'Pendiente';
						$mark_col  = $contacted ? '#059669' : '#d97706';
						$repeat    = (int) ( $lead['repeat_count'] ?? 0 );
						$name      = trim( (string) ( $lead['name'] ?? '' ) ) !== '' ? (string) $lead['name'] : '(sin nombre)';
						$email     = trim( (string) ( $lead['email'] ?? '' ) );
						$phone_d   = $digits( (string) ( $lead['phone'] ?? '' ) );
					?>
					<tr>
						<td align="center" style="padding:10px 4px;border-bottom:1px solid #f1f1f3;font-size:15px;color:<?php echo esc_attr( $mark_col ); ?>;" title="<?php echo esc_attr( $mark_help ); ?>"><?php echo esc_html( $mark ); ?></td>
						<td style="padding:10px 8px;border-bottom:1px solid #f1f1f3;font-size:13px;color:#111;">
							<div style="font-weight:600;"><?php echo esc_html( $name ); ?></div>
							<div style="font-size:11px;color:#6b7280;margin-top:1px;">
								<?php echo esc_html( $repeat > 1 ? 'Dejó sus datos ' . $repeat . '×' . str_repeat( '🔥', $repeat ) : 'Dejó sus datos 1 vez' ); ?>
							</div>
						</td>
						<td align="center" style="padding:10px 8px;border-bottom:1px solid #f1f1f3;white-space:nowrap;">
							<?php if ( $email !== '' ) : ?>
								<a href="<?php echo esc_url( 'mailto:' . $email ); ?>" title="Enviar correo" style="display:inline-block;font-size:18px;text-decoration:none;padding:0 5px;">✉️</a>
							<?php endif; ?>
							<?php if ( $phone_d !== '' ) : ?>
								<a href="<?php echo esc_url( 'https://wa.me/' . $phone_d ); ?>" title="Abrir WhatsApp" style="display:inline-block;font-size:18px;text-decoration:none;padding:0 5px;">💬</a>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</table>
				<?php endif; ?>
				<?php endforeach; ?>

				<div style="margin-top:22px;padding-top:10px;border-top:1px solid #e2e4e7;color:#888;font-size:11px;">
					Generado <?php echo esc_html( $fmt_dt( $gen ) ); ?>.
				</div>
			</td></tr>
			<tr><td style="padding:12px 24px 18px;">
				<?php echo $es_footer; ?>
			</td></tr>
		</table>
	</td></tr>
</table>
</body>
</html>
