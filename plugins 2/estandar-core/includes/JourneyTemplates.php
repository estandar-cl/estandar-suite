<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JourneyTemplates
{
	const OPTION = 'estandar_suite_journey_templates';

	public static function get( string $template_id ): ?array
	{
		$templates = get_option( self::OPTION, array() );
		$templates = is_array( $templates ) ? $templates : array();
		return $templates[ $template_id ] ?? null;
	}

	public static function list(): array
	{
		$templates = get_option( self::OPTION, array() );
		return is_array( $templates ) ? $templates : array();
	}

	public static function save( string $template_id, array $data ): void
	{
		$templates = self::list();
		$templates[ $template_id ] = array(
			'template_id' => $template_id,
			'name'        => sanitize_text_field( (string) ( $data['name'] ?? $template_id ) ),
			'subject'     => sanitize_text_field( (string) ( $data['subject'] ?? '' ) ),
			'body_html'   => wp_kses_post( (string) ( $data['body_html'] ?? '' ) ),
			'body_text'   => sanitize_textarea_field( (string) ( $data['body_text'] ?? '' ) ),
			'variables'   => (array) ( $data['variables'] ?? array() ),
			'channels'    => (array) ( $data['channels'] ?? array( 'email' ) ),
			'attachments' => (array) ( $data['attachments'] ?? array() ),
			'cta'         => (array) ( $data['cta'] ?? array() ),
			'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::OPTION, $templates, false );
	}

	public static function delete( string $template_id ): void
	{
		$templates = self::list();
		unset( $templates[ $template_id ] );
		update_option( self::OPTION, $templates, false );
	}

	public static function render( string $template_id, array $variables ): string
	{
		$template = self::get( $template_id );
		if ( ! $template ) {
			return '';
		}
		$body = $template['body_html'] ?: $template['body_text'];
		$all_vars = array_merge( array(
			'site_name' => get_bloginfo( 'name' ),
			'site_url'  => get_bloginfo( 'url' ),
			'admin_url' => admin_url(),
			'year'      => gmdate( 'Y' ),
		), $variables );

		foreach ( $all_vars as $key => $value ) {
			$body = str_replace( '{{' . $key . '}}', esc_html( (string) $value ), $body );
		}
		return $body;
	}

	public static function seed_defaults(): void
	{
		$defaults = array(
			'operator_new_high_lead' => array(
				'name' => 'Nuevo Lead de Alta Prioridad',
				'subject' => 'Nuevo lead HIGH: {{lead_name}} - {{lead_company}}',
				'body_html' => '<p>Nuevo lead de <strong>alta prioridad</strong>:</p><p><strong>{{lead_name}}</strong> — {{lead_company}}</p><p>{{lead_message}}</p><p>Tel: {{lead_phone}} | Email: {{lead_email}}</p>',
				'variables' => array( 'lead_name', 'lead_company', 'lead_phone', 'lead_email', 'lead_message', 'lead_source', 'lead_priority' ),
			),
			'lead_autoresponse' => array(
				'name' => 'Autorespuesta al Prospecto',
				'subject' => 'Gracias por contactarnos, {{lead_name}}',
				'body_html' => '<p>Hola {{lead_name}},</p><p>Gracias por tu interés. Un ejecutivo te contactará pronto.</p>',
				'variables' => array( 'lead_name' ),
			),
			'meeting_confirmation' => array(
				'name' => 'Confirmación de Reunión',
				'subject' => 'Reunión confirmada: {{meeting_date}}',
				'body_html' => '<p>Tu reunión ha sido confirmada para el <strong>{{meeting_date}}</strong> a las <strong>{{meeting_time}}</strong>.</p>',
				'variables' => array( 'meeting_date', 'meeting_time', 'meeting_type', 'lead_name' ),
			),
			'quote_cover_email' => array(
				'name' => 'Cotización',
				'subject' => 'Cotización — {{lead_name}}',
				'body_html' => '<p>Adjunto encontrarás la cotización solicitada. Validez: {{validity_days}} días.</p>',
				'variables' => array( 'lead_name', 'quote_id', 'quote_amount', 'validity_days' ),
				'attachments' => array( 'quote_pdf' ),
			),
			'welcome_onboarding' => array(
				'name' => 'Bienvenida — Onboarding',
				'subject' => 'Bienvenido a bordo, {{lead_name}}',
				'body_html' => '<p>Gracias por confiar en nosotros. Comenzaremos el proceso de onboarding.</p>',
				'variables' => array( 'lead_name', 'onboarding_steps' ),
			),
			'recovery_reengagement' => array(
				'name' => 'Recuperación',
				'subject' => 'Te extrañamos, {{lead_name}}',
				'body_html' => '<p>Hola {{lead_name}}, notamos que estuviste interesado en nuestros servicios. ¿Podemos ayudarte?</p>',
				'variables' => array( 'lead_name' ),
			),
		);

		$existing = self::list();
		foreach ( $defaults as $id => $data ) {
			if ( ! isset( $existing[ $id ] ) ) {
				self::save( $id, $data );
			}
		}
	}
}
