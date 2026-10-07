<?php

namespace Estandar\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest
{
	const NS = 'estandar-suite/flow/v1';

	public static function init(): void
	{
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// Capability propia de cotizaciones (M10): se concede a `manage_options`
		// por defecto vía map_meta_cap, sin tocar roles ni la capability nativa.
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
	}

	/** Capacidad real para operar cotizaciones (admin o cap propia). */
	public static function can_manage_quotes(): bool
	{
		return current_user_can( 'manage_options' ) || current_user_can( 'estandar_manage_quotes' );
	}

	/**
	 * Mapea la capability sintética `estandar_manage_quotes` a `manage_options`.
	 * Aditivo: no altera ninguna otra capability.
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args )
	{
		if ( 'estandar_manage_quotes' === $cap ) {
			return array( 'manage_options' );
		}
		return $caps;
	}

	public static function routes(): void
	{
		register_rest_route( self::NS, '/pipeline', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( array( 'pipeline' => Pipeline::list(
					(string) $request->get_param( 'stage' ), (string) $request->get_param( 'project' ),
					(int) $request->get_param( 'limit' ) ?: 100
				) ) );
			},
		) );

		register_rest_route( self::NS, '/pipeline/(?P<id>\d+)/transition', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$body = (array) $request->get_json_params();
				$result = Pipeline::transition( (int) $request['id'], (string) ( $body['stage'] ?? '' ), (string) ( $body['reason'] ?? '' ) );
				if ( empty( $result ) ) {
					return new \WP_Error( 'estandar_suite_transition_failed', 'Transición inválida o sin cambios.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( $result );
			},
		) );

		register_rest_route( self::NS, '/journey', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( array( 'journey' => Pipeline::list_journey(
					(string) $request->get_param( 'state' ),
					(int) $request->get_param( 'limit' ) ?: 100
				) ) );
			},
		) );

		register_rest_route( self::NS, '/journey/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$row = Pipeline::get( (int) $request['id'] );
				if ( ! $row ) {
					return new \WP_Error( 'not_found', 'Pipeline not found.', array( 'status' => 404 ) );
				}
				return rest_ensure_response( $row );
			},
		) );

		register_rest_route( self::NS, '/journey/transition', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$body = (array) $request->get_json_params();
				$result = Pipeline::journey_transition(
					(int) ( $body['pipeline_id'] ?? 0 ),
					(string) ( $body['state'] ?? '' ),
					(string) ( $body['reason'] ?? '' ),
					(string) ( $body['operator'] ?? '' ),
					(array) ( $body['metadata'] ?? array() )
				);
				if ( $result === null ) {
					return new \WP_Error( 'estandar_suite_journey_transition_failed', 'Transición inválida.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( $result );
			},
		) );

		register_rest_route( self::NS, '/customers', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function () { return rest_ensure_response( array( 'customers' => Customers::list( 100 ) ) ); },
		) );

		register_rest_route( self::NS, '/quotes', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function () { return rest_ensure_response( array( 'quotes' => Quotes::list( 100 ) ) ); },
		) );

		// QUOTE_V2: CRUD + envío de cotizaciones.
		register_rest_route( self::NS, '/quotes', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return self::can_manage_quotes(); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$body = self::request_body( $request );
				$id   = Quotes::create( self::quote_fields( $body ) );
				if ( $id <= 0 ) {
					return new \WP_Error( 'estandar_suite_quote_create_failed', 'No se pudo crear la cotización.', array( 'status' => 400 ) );
				}
				$doc = self::quote_document( $body );
				if ( $doc && class_exists( 'Estandar\\Flow\\Quotes\\Editor' ) ) {
					\Estandar\Flow\Quotes\Editor::persist_document_json( $id, $doc );
				}
				return rest_ensure_response( array( 'ok' => true, 'quote' => Quotes::get( $id ) ) );
			},
		) );

		register_rest_route( self::NS, '/quotes/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$quote = Quotes::get( (int) $request['id'] );
				if ( ! $quote ) {
					return new \WP_Error( 'not_found', 'Cotización no encontrada.', array( 'status' => 404 ) );
				}
				return rest_ensure_response( $quote );
			},
		) );

		register_rest_route( self::NS, '/quotes/(?P<id>\d+)', array(
			'methods'             => 'PATCH',
			'permission_callback' => static function () { return self::can_manage_quotes(); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$body = self::request_body( $request );
				$ok   = Quotes::update( (int) $request['id'], self::quote_fields( $body ) );
				$doc  = self::quote_document( $body );
				if ( $doc && class_exists( 'Estandar\\Flow\\Quotes\\Editor' ) ) {
					\Estandar\Flow\Quotes\Editor::persist_document_json( (int) $request['id'], $doc );
				}
				if ( ! $ok ) {
					return new \WP_Error( 'estandar_suite_quote_update_failed', 'No se pudo actualizar la cotización.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'quote' => Quotes::get( (int) $request['id'] ) ) );
			},
		) );

		register_rest_route( self::NS, '/quotes/(?P<id>\d+)/send', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$body = (array) $request->get_json_params();
				$result = Quotes::send( (int) $request['id'], (string) ( $body['to'] ?? '' ), (array) ( $body['lead'] ?? array() ) );
				if ( empty( $result['ok'] ) ) {
					return new \WP_Error( 'estandar_suite_quote_send_failed', (string) ( $result['error'] ?? 'envío fallido' ), array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'result' => $result ) );
			},
		) );

		// ── M10: ciclo de vida, PDF, revisiones, eventos y duplicado ───────
		register_rest_route( self::NS, '/quotes/(?P<id>\d+)/status', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return self::can_manage_quotes(); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$body   = self::request_body( $request );
				$status = sanitize_key( (string) ( $body['status'] ?? '' ) );
				if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Lifecycle' ) ) {
					return new \WP_Error( 'estandar_suite_quote_lifecycle_missing', 'Ciclo de vida no disponible.', array( 'status' => 501 ) );
				}
				$ok = \Estandar\Flow\Quotes\Lifecycle::transition( (int) $request['id'], $status, array(
					'reject_reason' => (string) ( $body['reason'] ?? '' ),
				) );
				if ( ! $ok ) {
					return new \WP_Error( 'estandar_suite_quote_status_failed', 'Transición inválida para el estado actual.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'quote' => Quotes::get( (int) $request['id'] ) ) );
			},
		) );

		register_rest_route( self::NS, '/quotes/(?P<id>\d+)/pdf', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return self::can_manage_quotes(); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$id  = (int) $request['id'];
				$doc = class_exists( 'Estandar\\Flow\\Quotes\\DocumentBuilder' )
					? \Estandar\Flow\Quotes\DocumentBuilder::for_quote( $id )
					: array();
				if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Document\\Pdf' ) ) {
					return new \WP_Error( 'estandar_suite_quote_pdf_missing', 'Motor PDF no disponible.', array( 'status' => 501 ) );
				}
				$pdf = \Estandar\Flow\Quotes\Document\Pdf::render( $doc );
				if ( empty( $pdf['ok'] ) || '' === (string) $pdf['bytes'] ) {
					return new \WP_Error( 'estandar_suite_quote_pdf_failed', (string) ( $pdf['error'] ?? 'render_vacio' ), array( 'status' => 500 ) );
				}
				$filename = \Estandar\Flow\Quotes\Document\Pdf::filename( $doc );
				$path     = \Estandar\Flow\Quotes\Document\PdfStore::path( $filename );
				@file_put_contents( $path, (string) $pdf['bytes'] );
				$url = \Estandar\Flow\Quotes\Document\PdfStore::url( $filename );
				return rest_ensure_response( array(
					'ok'       => true,
					'url'      => $url,
					'bytes'    => strlen( (string) $pdf['bytes'] ),
					'fallback' => ! empty( $pdf['fallback'] ),
				) );
			},
		) );

		register_rest_route( self::NS, '/quotes/(?P<id>\d+)/revisions', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return self::can_manage_quotes(); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$rows = class_exists( 'Estandar\\Flow\\Quotes\\Repository' )
					? \Estandar\Flow\Quotes\Repository::list_revisions( (int) $request['id'] )
					: array();
				return rest_ensure_response( array( 'revisions' => $rows ) );
			},
		) );

		register_rest_route( self::NS, '/quotes/(?P<id>\d+)/events', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return self::can_manage_quotes(); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$rows = class_exists( 'Estandar\\Flow\\Quotes\\Repository' )
					? \Estandar\Flow\Quotes\Repository::list_events( (int) $request['id'] )
					: array();
				return rest_ensure_response( array( 'events' => $rows ) );
			},
		) );

		register_rest_route( self::NS, '/quotes/(?P<id>\d+)/duplicate', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return self::can_manage_quotes(); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$new_id = Quotes::duplicate( (int) $request['id'] );
				if ( $new_id <= 0 ) {
					return new \WP_Error( 'estandar_suite_quote_duplicate_failed', 'No se pudo duplicar la cotización.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'quote' => Quotes::get( $new_id ) ) );
			},
		) );

		// ── Herencia v2: adopción de cotizaciones legacy (es_quotes) ───────
		register_rest_route( self::NS, '/quotes/adoption', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function () { return rest_ensure_response( QuotesAdoption::counts() ); },
		) );

		register_rest_route( self::NS, '/quotes/adoption/import', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$dry = empty( $request->get_param( 'commit' ) );
				return rest_ensure_response( QuotesAdoption::import_all( $dry, (int) ( $request->get_param( 'limit' ) ?: 100 ) ) + array( 'commit' => ! $dry ) );
			},
		) );

		register_rest_route( self::NS, '/quotes/adoption/rollback', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function () { return rest_ensure_response( array( 'rolled' => QuotesAdoption::rollback() ) ); },
		) );
	}

	// ── Helpers de body (aditivo, compat con el body legado) ────────────────

	/** Body JSON, o parámetros si no hay JSON (compat con clientes legacy). */
	private static function request_body( \WP_REST_Request $request ): array
	{
		$body = (array) $request->get_json_params();
		if ( empty( $body ) ) {
			$body = (array) $request->get_params();
		}
		return $body;
	}

	/** Campos de cotización aceptados (escalares + ítems), normalizados. */
	private static function quote_fields( array $body ): array
	{
		$data   = array();
		$scalar = array( 'title', 'currency', 'notes', 'client_tax_id', 'profile_key' );
		foreach ( $scalar as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$data[ $key ] = $body[ $key ];
			}
		}
		foreach ( array( 'lead_id', 'pipeline_id', 'customer_id' ) as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$data[ $key ] = (int) $body[ $key ];
			}
		}
		if ( array_key_exists( 'validity_days', $body ) ) {
			$data['validity_days'] = (int) $body['validity_days'];
		} elseif ( array_key_exists( 'validity', $body ) ) {
			$data['validity_days'] = (int) $body['validity'];
		}
		if ( ! isset( $data['profile_key'] ) && array_key_exists( 'profile', $body ) ) {
			$data['profile_key'] = $body['profile'];
		}
		if ( array_key_exists( 'items', $body ) ) {
			$items = $body['items'];
			if ( is_string( $items ) ) {
				$decoded = json_decode( $items, true );
				$items   = is_array( $decoded ) ? $decoded : array();
			}
			$data['items'] = is_array( $items ) ? $items : array();
		}
		return $data;
	}

	/** Documento canónico del body (`document_json`, string JSON o array). */
	private static function quote_document( array $body ): array
	{
		$doc = $body['document_json'] ?? null;
		if ( null === $doc || '' === $doc || array() === $doc ) {
			return array();
		}
		if ( is_string( $doc ) ) {
			$decoded = json_decode( $doc, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $doc ) ? $doc : array();
	}
}
