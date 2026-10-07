<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Estandar_Quotes_Delivery_Email {

	private static function settings(): array {
		return array(
			'company_name'    => get_option( 'estandar_quotes_defaults' )['company_name'] ?? 'Estandar Studio',
			'company_email'   => get_option( 'estandar_quotes_defaults' )['sender_email'] ?? '',
			'company_phone'   => get_option( 'estandar_quotes_defaults' )['company_phone'] ?? '',
			'footer_legal'    => get_option( 'estandar_quotes_defaults' )['footer_legal'] ?? '',
		);
	}

	public static function render( array $delivery, array $metadata, array $artifact, array $recipients ): string {
		$is_operator = ( $metadata['kind'] ?? 'client' ) === 'operator';
		$doc_meta = $metadata['delivery_meta'] ?? array();
		$name    = esc_html( (string) ( $metadata['customer_name'] ?? '' ) );
		$summary = esc_html( (string) ( $metadata['summary'] ?? '' ) );
		$exec    = esc_html( (string) ( $metadata['executive_name'] ?? '' ) );
		$expires = esc_html( (string) ( $metadata['expires_at'] ?? '' ) );
		$ref     = esc_html( (string) ( $doc_meta['quote_number'] ?? '' ) );

		$project = sanitize_title( $doc_meta['project_slug'] ?? '' );
		$rev     = sprintf( '%02d', (int) ( $doc_meta['revision_no'] ?? 1 ) );
		$num     = $doc_meta['quote_number'] ?? '';
		$file    = $num ? "Cotizacion-{$project}-{$num}-{$rev}.pdf" : 'cotizacion.pdf';

		$s = self::settings();

		if ( $is_operator ) {
			return '<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;color:#333;padding:24px;max-width:600px;margin:0 auto;">
				<h1 style="color:#1d2327;border-bottom:2px solid #2271b1;padding-bottom:12px;">Confirmación de envío</h1>
				<p>La cotización fue enviada correctamente.</p>
				<table style="border-collapse:collapse;width:100%;margin:16px 0;">
					<tr><td style="padding:8px;border-bottom:1px solid #ddd;color:#666;">Destinatarios</td><td style="padding:8px;border-bottom:1px solid #ddd;font-weight:600;">' . esc_html( implode( ', ', $recipients ) ) . '</td></tr>
					<tr><td style="padding:8px;border-bottom:1px solid #ddd;color:#666;">Documento</td><td style="padding:8px;border-bottom:1px solid #ddd;font-weight:600;">' . $file . '</td></tr>
					<tr><td style="padding:8px;border-bottom:1px solid #ddd;color:#666;">Revisión</td><td style="padding:8px;border-bottom:1px solid #ddd;font-weight:600;">' . $rev . '</td></tr>
					<tr><td style="padding:8px;border-bottom:1px solid #ddd;color:#666;">SHA-256</td><td style="padding:8px;border-bottom:1px solid #ddd;font-family:monospace;font-size:11px;">' . esc_html( $artifact['pdf_sha256'] ?? '' ) . '</td></tr>
					<tr><td style="padding:8px;color:#666;">Fecha</td><td style="padding:8px;font-weight:600;">' . esc_html( $metadata['timestamp'] ?? '' ) . '</td></tr>
				</table>
				<p style="color:#999;font-size:12px;border-top:1px solid #ddd;padding-top:12px;">' . esc_html( $s['footer_legal'] ) . '</p>
				</body></html>';
		}

		$greeting = $name !== '' ? 'Hola ' . $name . ',' : 'Estimado(a),';
		$summary_line = $summary !== '' ? '<p>' . $summary . '</p>' : '<p>Adjuntamos la cotización solicitada.</p>';
		$details = '';
		if ( $ref !== '' ) { $details .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">Referencia</td><td style="padding:8px;font-weight:600;">' . esc_html( $ref ) . '</td></tr>'; }
		if ( $expires !== '' ) { $details .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;color:#666;">Vigencia</td><td style="padding:8px;font-weight:600;">' . $expires . '</td></tr>'; }
		if ( $exec !== '' ) { $details .= '<tr><td style="padding:8px;color:#666;">Ejecutivo</td><td style="padding:8px;font-weight:600;">' . $exec . '</td></tr>'; }

		$brand_footer = '';
		if ( $s['company_name'] ) { $brand_footer .= esc_html( $s['company_name'] ); }
		if ( $s['company_email'] ) { $brand_footer .= ( $brand_footer !== '' ? ' · ' : '' ) . esc_html( $s['company_email'] ); }
		if ( $s['company_phone'] ) { $brand_footer .= ( $brand_footer !== '' ? ' · ' : '' ) . esc_html( $s['company_phone'] ); }

		return '<!doctype html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;color:#333;padding:24px;max-width:600px;margin:0 auto;">
			<table style="width:100%;border-collapse:collapse;"><tr><td style="padding:16px 0;">
				<h1 style="color:#1d2327;margin:0;font-size:22px;">' . esc_html( $s['company_name'] ) . '</h1>
			</td></tr></table>
			<hr style="border:none;border-top:2px solid #2271b1;margin:0 0 24px 0;">
			<p style="font-size:15px;line-height:1.5;">' . $greeting . '</p>
			' . $summary_line . '
			' . ( $details !== '' ? '<table style="border-collapse:collapse;width:100%;margin:16px 0;">' . $details . '</table>' : '' ) . '
			<p style="font-size:15px;line-height:1.5;">El documento adjunto contiene los detalles de la cotización. Quedamos atentos a cualquier consulta.</p>
			<hr style="border:none;border-top:1px solid #ddd;margin:24px 0 12px 0;">
			' . ( $brand_footer !== '' ? '<p style="color:#999;font-size:12px;margin:4px 0;">' . $brand_footer . '</p>' : '' ) . '
			' . ( $s['footer_legal'] ? '<p style="color:#ccc;font-size:10px;margin:4px 0;">' . esc_html( $s['footer_legal'] ) . '</p>' : '' ) . '
			<p style="color:#ccc;font-size:10px;margin:4px 0;">Estandar Suite — Acacios.cl</p>
		</body></html>';
	}
}
