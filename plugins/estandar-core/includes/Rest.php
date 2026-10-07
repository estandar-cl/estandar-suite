<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated REST for Core. Application-Passwords/standard WP auth via
 * permission_callback (is_user_logged_in + capability). No secret exposure.
 */
class Rest
{
	const NS = 'estandar-suite/core/v1';

	public static function init(): void
	{
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void
	{
		register_rest_route( self::NS, '/health', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function () {
				return rest_ensure_response( Health::snapshot() );
			},
		) );

		register_rest_route( self::NS, '/selfcheck', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( AutoDiag::snapshot( ! empty( $request->get_param( 'force' ) ) ) );
			},
		) );

		register_rest_route( self::NS, '/telemetry', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( array( 'events' => Telemetry::recent( (int) $request->get_param( 'limit' ) ?: 50 ) ) );
			},
		) );

		// ── Leads Panel: API JSON para terceros ────────────────────────────
		register_rest_route( self::NS, '/leads', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return LeadsPanel::authorized();
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$filters = LeadsPanel::request_filters( $request );
				$rows    = LeadsPanel::unified_leads( $filters, (int) ( $request->get_param( 'limit' ) ?: 200 ) );
				return rest_ensure_response( array(
					'columns' => LeadsPanel::columns(),
					'count'   => count( $rows ),
					'leads'   => $rows,
				) );
			},
		) );

		// ── OPERATORS_READ_V1: lectura remota de es_operators (admin) ──────
		register_rest_route( self::NS, '/operators', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function () {
				global $wpdb;
				$rows = (array) $wpdb->get_results(
					"SELECT id, name, email, project_slug, whatsapp_number, active
					 FROM {$wpdb->prefix}es_operators ORDER BY id ASC",
					ARRAY_A
				);
				return rest_ensure_response( array( 'operators' => $rows ) );
			},
		) );

		// ── Leads Panel: página visual (viewer sin login WP) ──────────────
		register_rest_route( self::NS, '/leads/view', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return LeadsPanel::authorized();
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$filters = LeadsPanel::request_filters( $request );
				$filters['access'] = sanitize_text_field( (string) ( $request->get_param( 'access' ) ?? $request->get_param( 'pass' ) ?? '' ) );
				$total   = LeadsPanel::count_operator_leads( $filters );
				$rows    = LeadsPanel::operator_leads( $filters, 30, 0 );
				$export_base = rest_url( self::NS . '/leads/export' );
				$html = LeadsPanel::render_viewer( $rows, $filters, $export_base, 1, 1, $total );
				return new \WP_REST_Response( $html, 200, array(
					'Content-Type' => 'text/html; charset=' . get_bloginfo( 'charset' ),
					'Cache-Control' => 'no-store',
				) );
			},
		) );

		// ── Leads Panel: descarga CSV/XLSX/PDF ─────────────────────────────
		register_rest_route( self::NS, '/leads/export', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return LeadsPanel::authorized();
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$format = sanitize_key( (string) $request->get_param( 'format' ) );
				if ( ! in_array( $format, array( 'csv', 'xlsx', 'pdf' ), true ) ) {
					$format = 'csv';
				}
				$is_operator = ( sanitize_key( (string) $request->get_param( 'view' ) ) === 'operator' );
				$filters = LeadsPanel::request_filters( $request );
				$rows = $is_operator
					? LeadsPanel::operator_leads( $filters, 10000, 0 )
					: LeadsPanel::unified_leads( $filters, 10000 );
				$columns = $is_operator ? LeadsPanel::operator_columns() : LeadsPanel::columns();
				$bytes = ''; $mime = 'text/csv'; $ext = 'csv';
				if ( $format === 'xlsx' ) {
					$bytes = LeadsPanel::export_xlsx( $rows, $columns );
					$mime  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
					$ext   = 'xlsx';
				} elseif ( $format === 'pdf' ) {
					$bytes = LeadsPanel::export_pdf( $rows, $columns );
					$mime  = 'application/pdf';
					$ext   = 'pdf';
				} else {
					$bytes = LeadsPanel::export_csv( $rows, $columns );
				}
				$filename = 'leads-' . gmdate( 'Ymd-His' ) . '.' . $ext;
				return new \WP_REST_Response( (string) $bytes, 200, array(
					'Content-Type'        => $mime,
					'Content-Disposition' => 'attachment; filename="' . $filename . '"',
					'Content-Length'      => (string) strlen( (string) $bytes ),
					'Cache-Control'       => 'no-store',
				) );
			},
		) );

		// ── Leads Panel: envío por correo del export (CSV/XLSX/PDF) ────────
		register_rest_route( self::NS, '/leads/email', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return LeadsPanel::authorized();
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$to     = sanitize_email( (string) ( $request->get_param( 'email' ) ?: $request->get_param( 'to' ) ) );
				$format = sanitize_key( (string) $request->get_param( 'format' ) );
				if ( ! in_array( $format, array( 'csv', 'xlsx', 'pdf' ), true ) ) {
					$format = 'csv';
				}
				if ( ! is_email( $to ) ) {
					return new \WP_Error( 'estandar_suite_leads_email_invalid', 'Correo destino inválido.', array( 'status' => 400 ) );
				}
				$filters = LeadsPanel::request_filters( $request );
				$rows    = LeadsPanel::operator_leads( $filters, 10000, 0 );
				$columns = LeadsPanel::operator_columns();
				$bytes = ''; $ext = 'csv';
				if ( $format === 'xlsx' ) {
					$bytes = LeadsPanel::export_xlsx( $rows, $columns );
					$ext   = 'xlsx';
				} elseif ( $format === 'pdf' ) {
					$bytes = LeadsPanel::export_pdf( $rows, $columns );
					$ext   = 'pdf';
				} else {
					$bytes = LeadsPanel::export_csv( $rows, $columns );
				}
				if ( $bytes === '' ) {
					return new \WP_Error( 'estandar_suite_leads_email_generate', 'No se pudo generar el archivo.', array( 'status' => 500 ) );
				}
				$filename = 'leads-' . gmdate( 'Ymd-His' ) . '.' . $ext;
				$tmp = tempnam( sys_get_temp_dir(), 'lead' );
				file_put_contents( $tmp, $bytes );
				$subject = 'Estandar Suite — Leads (' . strtoupper( $ext ) . ')';
				$body    = 'Adjunto el archivo de leads (' . count( $rows ) . ' registros, formato ' . strtoupper( $ext ) . ').';
				$result  = Mail::send_raw( array(
					'to'          => $to,
					'subject'     => $subject,
					'html'        => '<p>' . esc_html( $body ) . '</p>',
					'alt'         => $body,
					'attachments' => array( $tmp ),
				) );
				Mail::log( $result );
				@unlink( $tmp );
				if ( empty( $result['ok'] ) ) {
					return new \WP_Error( 'estandar_suite_leads_email_failed', 'No se pudo enviar el correo: ' . (string) ( $result['error'] ?? 'revisa el transporte SMTP' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'to' => $to, 'format' => $format, 'rows' => count( $rows ) ) );
			},
		) );
	}
}
