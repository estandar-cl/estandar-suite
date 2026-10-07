<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_Quotes_Admin_Actions {

	public static function init(): void {
		add_action( 'admin_post_estandar_quotes_admin_create_quote', array( __CLASS__, 'handle_admin_create_quote' ) );
		add_action( 'admin_post_estandar_quotes_admin_send_notification', array( __CLASS__, 'handle_admin_send_notification' ) );
		add_action( 'admin_post_estandar_quotes_force_update_check', array( __CLASS__, 'handle_force_update_check' ) );
		add_filter( 'estandar_leads_operator_portal_actions', array( __CLASS__, 'operator_portal_actions' ), 10, 2 );
	}

	public static function handle_force_update_check(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}
		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'esq_force_update_check' ) ) {
			wp_die( 'Solicitud no válida.' );
		}
		delete_transient( 'estandar_updates_remote_versions' );
		delete_site_transient( 'estandar_updates_remote_versions' );
		wp_update_plugins();
		$referer = wp_get_referer() ?: admin_url( 'update-core.php' );
		wp_safe_redirect( add_query_arg( array( 'esq_notice' => 'update_forced' ), $referer ) );
		exit;
	}

	public static function handle_admin_create_quote(): void {
		if ( ! current_user_can( Estandar_Quotes_Admin::CAP_EDIT ) ) {
			wp_die( 'No autorizado.' );
		}
		$lead_id = absint( $_GET['lead_id'] ?? 0 );
		$submission_id = absint( $_GET['submission_id'] ?? 0 );
		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );

		if ( ! $lead_id || ! wp_verify_nonce( $nonce, 'esq_admin_create_quote_' . $lead_id ) ) {
			wp_die( 'Solicitud no válida.' );
		}

		if ( $submission_id <= 0 ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d ORDER BY id DESC LIMIT 1",
				$lead_id
			) );
			$submission_id = $row ? (int) $row->id : 0;
			if ( $submission_id <= 0 ) {
				wp_die( 'Lead no tiene submissions.' );
			}
		}

		$existing = Estandar_Quotes_Repository::get_by_submission( $submission_id );
		if ( $existing ) {
			wp_safe_redirect( add_query_arg( array(
				'page' => 'estandar-quotes',
				'quote' => $existing['quote_uuid'],
				'esq_notice' => 'draft_existing',
			), admin_url( 'admin.php' ) ) );
			exit;
		}

		$snapshot = Estandar_Quotes_Lead_Integration::snapshot_for_submission( $submission_id );
		if ( empty( $snapshot ) ) {
			wp_die( 'Lead no encontrado.' );
		}

		$profile_key = Estandar_Quotes_Admin::active_profile_key();
		$settings = get_option( 'estandar_quotes_defaults', array() );
		$hydrated = Estandar_Quotes_Hydration_Pipeline::hydrate_new(
			$snapshot['lead_snapshot'],
			$profile_key,
			$settings
		);
		$defaults = $hydrated->to_array();

		$project_slug = ! empty( $snapshot['project_slug'] ) ? $snapshot['project_slug'] : '';
		$quote_id = Estandar_Quotes_Repository::create_draft( array(
			'profile_key'    => $profile_key,
			'project_slug'   => $project_slug,
			'lead_id'        => $lead_id,
			'submission_id'  => $submission_id,
			'lead_snapshot'  => $snapshot['lead_snapshot'],
			'created_by'     => get_current_user_id(),
			'source'         => 'admin_create_quote',
		) );

		if ( ! $quote_id ) {
			global $wpdb;
			$error_msg = 'No fue posible crear el borrador de cotización.';
			if ( ! empty( $wpdb->last_error ) ) {
				$error_msg .= ' DB: ' . $wpdb->last_error;
			}
			wp_die( esc_html( $error_msg ) );
		}

		if ( ! Estandar_Quotes_Repository::initialize_draft( $quote_id, $defaults ) ) {
			Estandar_Quotes_Repository::transition( $quote_id, 'expired' );
			wp_die( 'No fue posible inicializar la cotización.' );
		}

		$quote = Estandar_Quotes_Repository::get( $quote_id );
		wp_safe_redirect( add_query_arg( array(
			'page' => 'estandar-quotes',
			'quote' => $quote['quote_uuid'],
			'esq_notice' => 'draft_created',
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_admin_send_notification(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}

		$lead_id = absint( $_GET['lead_id'] ?? 0 );
		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );

		if ( ! $lead_id || ! wp_verify_nonce( $nonce, 'esq_admin_send_notification_' . $lead_id ) ) {
			wp_die( 'Solicitud no válida.' );
		}

		global $wpdb;
		$submission_id = absint( $_GET['submission_id'] ?? 0 );
		if ( $submission_id <= 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d ORDER BY id DESC LIMIT 1",
				$lead_id
			) );
			$submission_id = $row ? (int) $row->id : 0;
			if ( $submission_id <= 0 ) {
				wp_die( 'Lead no tiene submissions.' );
			}
		}
		$lead = $wpdb->get_row( $wpdb->prepare(
			"SELECT l.*, s.id AS submission_id, s.form_fields_json, s.context_json, s.message
			 FROM {$wpdb->prefix}es_leads l
			 JOIN {$wpdb->prefix}es_lead_submissions s ON s.lead_id = l.id
			 WHERE s.id = %d ORDER BY s.id DESC LIMIT 1",
			$submission_id
		) );

		if ( ! $lead ) {
			wp_die( 'Lead no encontrado.' );
		}

		$operator_id = (int) $lead->assigned_operator_id;
		if ( $operator_id <= 0 ) {
			wp_die( 'Este lead no tiene operador asignado.' );
		}

		$operator = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}es_operators WHERE id = %d LIMIT 1",
			$operator_id
		) );
		if ( ! $operator || empty( $operator->email ) ) {
			wp_die( 'El operador asignado no tiene email configurado.' );
		}

		$payload = array(
			'contact'  => array(
				'name'  => $lead->name,
				'email' => $lead->email,
				'phone' => $lead->phone,
			),
			'content' => array(
				'message'        => $lead->message,
				'source_form_id' => '',
			),
			'attribution' => array(),
			'context'     => json_decode( $lead->context_json ?? '{}', true ) ?: array(),
			'meta'        => array(
				'sanitized_raw_fields' => json_decode( $lead->form_fields_json ?? '{}', true ) ?: array(),
			),
		);

		Estandar_Leads_Repository::insert_event( $lead_id, 'operator_notification_manual_admin_override', array(
			'operator_id'   => $operator_id,
			'admin_user_id' => get_current_user_id(),
			'submission_id' => $submission_id,
			'reason'        => 'manual_admin_override',
		) );

		$referer = wp_get_referer() ?: admin_url( 'admin.php?page=estandar-leads' );

		$notification_data = array(
			'operator_name' => sanitize_text_field( $operator->name ),
			'operator_id'   => $operator_id,
			'lead_name'     => sanitize_text_field( $lead->name ),
			'lead_email'    => sanitize_email( $lead->email ),
			'lead_phone'    => sanitize_text_field( $lead->phone ),
			'project_slug'  => sanitize_text_field( $lead->project_slug ?? '' ),
			'project_area'  => '',
			'campaign'      => '',
			'submitted_at'  => sanitize_text_field( $lead->submitted_at ?? '' ),
			'submission_count' => 1,
			'lead_type'     => 'Nuevo',
			'form_fields'   => json_decode( $lead->form_fields_json ?? '{}', true ) ?: array(),
			'message'       => sanitize_textarea_field( $lead->message ?? '' ),
			'lead_id'       => $lead_id,
			'submission_id' => $submission_id,
			'source_plugin' => 'estandar-forms',
		);

		if ( class_exists( 'Estandar_Leads_Email' ) ) {
			$email_html = Estandar_Leads_Email::render( $notification_data );
			$subject = 'Nuevo lead: ' . sanitize_text_field( $lead->name );
			$sent = wp_mail( $operator->email, $subject, $email_html, array( 'Content-Type: text/html; charset=UTF-8' ) );
			wp_safe_redirect( add_query_arg( array(
				'esq_notice' => $sent ? 'notification_sent' : 'notification_failed',
				'lead_id'    => $lead_id,
			), $referer ) );
		} else {
			Estandar_Leads_Repository::insert_event( $lead_id, 'notification_failed', array(
				'reason'  => 'email_renderer_unavailable',
				'message' => 'Estandar_Leads_Email class not available',
			) );
			wp_safe_redirect( add_query_arg( array(
				'esq_notice' => 'notification_failed',
				'lead_id'    => $lead_id,
			), $referer ) );
		}
		exit;
	}

	public static function operator_portal_actions( array $actions, object $lead ): array {
		$quote_url = '';
		if ( class_exists( 'Estandar_Quotes_Lead_Integration' ) ) {
			$existing = Estandar_Quotes_Repository::get_by_submission( (int) $lead->submission_id );
			if ( $existing ) {
				$quote_url = admin_url( add_query_arg( array(
					'page' => 'estandar-quotes',
					'quote' => $existing['quote_uuid'],
				), 'admin.php' ) );
			} else {
				$quote_url = admin_url( add_query_arg( array(
					'action'       => 'estandar_quotes_admin_create_quote',
					'lead_id'      => (int) $lead->id,
					'submission_id' => (int) $lead->submission_id,
					'_wpnonce'     => wp_create_nonce( 'esq_admin_create_quote_' . (int) $lead->id ),
				), 'admin-post.php' ) );
			}
		}

		$notify_url = admin_url( add_query_arg( array(
			'action'        => 'estandar_quotes_admin_send_notification',
			'lead_id'       => (int) $lead->id,
			'submission_id' => (int) ( $lead->submission_id ?? 0 ),
			'_wpnonce'      => wp_create_nonce( 'esq_admin_send_notification_' . (int) $lead->id ),
		), 'admin-post.php' ) );

		if ( $quote_url !== '' ) {
			$actions['create_quote'] = array(
				'label' => 'Cotización',
				'url'   => $quote_url,
				'class' => 'button button-small',
			);
		}

		$actions['send_notification'] = array(
			'label' => 'Enviar notificación',
			'url'   => $notify_url,
			'class' => 'button button-small',
			'confirm' => '¿Enviar notificación al operador ahora?',
		);

		$actions['force_update'] = array(
			'label' => 'Forzar actualización',
			'url'   => admin_url( add_query_arg( array(
				'action'   => 'estandar_quotes_force_update_check',
				'_wpnonce' => wp_create_nonce( 'esq_force_update_check' ),
			), 'admin-post.php' ) ),
			'class' => 'button button-small',
		);

		return $actions;
	}
}