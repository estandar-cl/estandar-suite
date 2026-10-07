<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MetaLeadAds — receptor del webhook "leadgen" de Meta (formularios
 * instantáneos de Facebook/Instagram) para ingestar cada lead como una entrada
 * del pipeline de captura, es decir la misma base que los formularios del sitio.
 *
 * Flujo:
 *   Meta → POST {site}/wp-json/estandar-suite/capture/v1/forms/facebook
 *        → MetaLeadAds::handle_notification() (verifica firma, resuelve el lead
 *          vía Graph API con Page Access Token) → FormBridge::handle_facebook().
 *
 * Opciones (se configuran por wp option):
 *   estandar_suite_capture_meta_verify_token · app secret · page token · forms map
 */
class MetaLeadAds
{
	const OPT_VERIFY     = 'estandar_suite_capture_meta_verify_token';
	const OPT_SECRET     = 'estandar_suite_capture_meta_app_secret';
	const OPT_PAGE_TOKEN = 'estandar_suite_capture_meta_page_token';
	const OPT_FORMS      = 'estandar_suite_capture_meta_forms';
	const OPT_AUTOLEARN  = 'estandar_suite_capture_meta_autolearn';
	const OPT_CAMPAIGNS  = 'estandar_suite_capture_meta_campaign_names';
	const GRAPH_VERSION  = 'v22.0';

	public static function init(): void
	{
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_raw_challenge' ), 10, 4 );
	}

	/** Emite el hub.challenge como texto plano (sin comillas de JSON). */
	public static function serve_raw_challenge( $served, $result, $request, $server )
	{
		if ( ! $request instanceof \WP_REST_Request ) {
			return $served;
		}
		if ( $request->get_route() !== '/' . Rest::NS . '/forms/facebook' || strtoupper( (string) $request->get_method() ) !== 'GET' ) {
			return $served;
		}
		$data = $result instanceof \WP_REST_Response ? $result->get_data() : $result;
		if ( is_string( $data ) && $data !== '' ) {
			if ( ! headers_sent() ) {
				header( 'Content-Type: text/plain; charset=utf-8' );
			}
			echo $data; // phpcs:ignore WordPress.Security.EscapeOutput
			return true;
		}
		return $served;
	}

	public static function verify_token(): string
	{
		$t = (string) get_option( self::OPT_VERIFY, '' );
		if ( $t === '' ) {
			$t = bin2hex( random_bytes( 16 ) );
			update_option( self::OPT_VERIFY, $t, false );
		}
		return $t;
	}

	public static function app_secret(): string
	{
		return (string) get_option( self::OPT_SECRET, '' );
	}

	public static function page_token(): string
	{
		return (string) get_option( self::OPT_PAGE_TOKEN, '' );
	}

	/** Mapa form_id de Meta → etiqueta legible del proyecto. @return array<string,string> */
	public static function forms_map(): array
	{
		$map = get_option( self::OPT_FORMS, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Resolución ADAPTATIVA del proyecto de un formulario instantáneo de Meta.
	 *
	 * Cascada (opcional: cada paso se ejecuta SOLO si el anterior no resolvió y si
	 * la instalación tiene el dato/feature; ninguno es obligatorio):
	 *   1) Mapa explícito form_id → slug (estandar_suite_capture_meta_forms).
	 *   2) Registro de etiquetas de campaña/anuncio (si existe):
	 *      campaign_id / adset_id / ad_id → proyecto.
	 *   3) Nombre de la campaña vía Graph (con caché persistente) → coincidencia
	 *      contra los slugs/labels de proyectos registrados (o del mapa).
	 *   4) Filtro 'estandar_capture_meta_lead_project_slug' (override o apagado).
	 *   5) Auto-aprendizaje OPCIONAL del mapa (por defecto DESACTIVADO).
	 *
	 * Nunca lanza: ante cualquier ausencia de datos/feature devuelve '' (no rompe la
	 * ingesta) y, si el mapa ya resuelve, el comportamiento es idéntico al anterior.
	 */
	public static function resolve_project_slug( int $form_id, array $data = array(), array $lead = array() ): string
	{
		try {
			$map  = self::forms_map();
			$fkey = (string) $form_id;
			$slug = (string) ( $map[ $fkey ] ?? $map[ (string) ( $lead['form_id'] ?? '' ) ] ?? '' );

			if ( $slug === '' ) {
				$slug = self::project_from_meta_ids(
					(string) ( $data['ad_id'] ?? $lead['ad_id'] ?? '' ),
					(string) ( $data['adset_id'] ?? $lead['adgroup_id'] ?? '' ),
					(string) ( $data['campaign_id'] ?? '' )
				);
			}
			if ( $slug === '' ) {
				$slug = self::project_from_campaign_name( (string) ( $data['campaign_id'] ?? '' ) );
			}

			/** Permite a cualquier instalación mapear/override (o desactivar devolviendo ''). */
			$slug = (string) apply_filters( 'estandar_capture_meta_lead_project_slug', $slug, $form_id, $data, $lead );
			$slug = function_exists( 'sanitize_key' ) ? sanitize_key( $slug ) : strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $slug ) ) );

			if ( $slug !== '' && $fkey !== '' && ! isset( $map[ $fkey ] ) && self::autolearn_enabled() ) {
				$map[ $fkey ] = $slug;
				update_option( self::OPT_FORMS, $map, false );
			}
			return $slug;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	private static function autolearn_enabled(): bool
	{
		return (string) get_option( self::OPT_AUTOLEARN, '0' ) === '1';
	}

	/** Proyecto desde ids de anuncio/conjunto/campaña del registro de etiquetas (si existe). */
	private static function project_from_meta_ids( string $ad_id, string $adset_id, string $campaign_id ): string
	{
		$reg = get_option( 'estandar_campaign_labels_v1', array() );
		if ( ! is_array( $reg ) ) {
			return '';
		}
		$lookup = array(
			'ads'       => $ad_id,
			'adsets'    => $adset_id,
			'campaigns' => $campaign_id,
		);
		foreach ( $lookup as $bucket => $id ) {
			if ( $id === '' || empty( $reg[ $bucket ][ $id ] ) ) {
				continue;
			}
			$project = (string) ( $reg[ $bucket ][ $id ]['project'] ?? '' );
			if ( $project !== '' ) {
				return $project;
			}
		}
		return '';
	}

	/** Proyecto desde el nombre de la campaña (Graph, con caché) contra proyectos registrados. */
	private static function project_from_campaign_name( string $campaign_id ): string
	{
		if ( $campaign_id === '' || ! function_exists( 'wp_remote_get' ) ) {
			return '';
		}
		$name = self::campaign_name_cached( $campaign_id );
		if ( $name === '' ) {
			return '';
		}
		return self::match_project_by_name( $name, self::project_candidates() );
	}

	/** Nombre de la campaña vía Graph, cacheado (solo cachés no vacías, para poder reintentar). */
	private static function campaign_name_cached( string $campaign_id ): string
	{
		$cache = get_option( self::OPT_CAMPAIGNS, array() );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}
		if ( isset( $cache[ $campaign_id ] ) && (string) $cache[ $campaign_id ] !== '' ) {
			return (string) $cache[ $campaign_id ];
		}
		$token = self::page_token();
		if ( $token === '' || ! function_exists( 'add_query_arg' ) ) {
			return '';
		}
		$url = add_query_arg(
			array( 'fields' => 'name', 'access_token' => $token ),
			'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . rawurlencode( $campaign_id )
		);
		$response = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$name = is_array( $data ) ? (string) ( $data['name'] ?? '' ) : '';
		if ( $name !== '' && count( $cache ) < 300 ) {
			$cache[ $campaign_id ] = $name;
			update_option( self::OPT_CAMPAIGNS, $cache, false );
		}
		return $name;
	}

	/** Candidatos slug→label para el match por nombre (mapa + registro de etiquetas + registry). */
	private static function project_candidates(): array
	{
		$out = array();
		foreach ( self::forms_map() as $slug ) {
			$slug = (string) $slug;
			if ( $slug !== '' ) {
				$out[ $slug ] = $out[ $slug ] ?? '';
			}
		}
		$reg = get_option( 'estandar_campaign_labels_v1', array() );
		if ( is_array( $reg ) ) {
			foreach ( array( 'campaigns', 'adsets', 'ads' ) as $bucket ) {
				foreach ( (array) ( $reg[ $bucket ] ?? array() ) as $row ) {
					$slug = (string) ( $row['project'] ?? '' );
					if ( $slug !== '' ) {
						$out[ $slug ] = $out[ $slug ] ?? (string) ( $row['label'] ?? '' );
					}
				}
			}
		}
		if ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) && method_exists( 'Estandar\\Core\\Leads\\ProjectRegistry', 'manager_list' ) ) {
			foreach ( (array) \Estandar\Core\Leads\ProjectRegistry::manager_list() as $row ) {
				$slug = (string) ( $row['project_id'] ?? '' );
				if ( $slug !== '' ) {
					$out[ $slug ] = $out[ $slug ] ?? (string) ( $row['label'] ?? '' );
				}
			}
		}
		return $out;
	}

	/**
	 * Coincidencia por nombre: gana el proyecto cuyo slug o label (normalizado, ≥4 chars)
	 * aparezca dentro del nombre de la campaña. Genérico y tolerante a acentos/case.
	 */
	public static function match_project_by_name( string $name, array $candidates ): string
	{
		$hay = self::normalize_token( $name );
		if ( $hay === '' ) {
			return '';
		}
		$best = '';
		$best_len = 0;
		foreach ( $candidates as $slug => $label ) {
			foreach ( array( (string) $slug, (string) $label ) as $needle ) {
				$n = self::normalize_token( $needle );
				if ( $n === '' || strlen( $n ) < 4 ) {
					continue;
				}
				if ( strpos( $hay, $n ) !== false && strlen( $n ) > $best_len ) {
					$best = (string) $slug;
					$best_len = strlen( $n );
				}
			}
		}
		return $best;
	}

	private static function normalize_token( string $s ): string
	{
		if ( function_exists( 'remove_accents' ) ) {
			$s = remove_accents( $s );
		}
		return (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $s ) );
	}

	/** GET: verificación del webhook (hub.mode/hub.verify_token/hub.challenge). */
	public static function verify( \WP_REST_Request $request )
	{
		$params    = (array) $request->get_params();
		$mode      = (string) ( $params['hub.mode'] ?? $params['hub_mode'] ?? '' );
		$token     = (string) ( $params['hub.verify_token'] ?? $params['hub_verify_token'] ?? '' );
		$challenge = (string) ( $params['hub.challenge'] ?? $params['hub_challenge'] ?? '' );

		if ( $mode === 'subscribe' && $token !== '' && hash_equals( self::verify_token(), $token ) ) {
			$response = new \WP_REST_Response( $challenge, 200 );
			$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
			return $response;
		}
		return new \WP_Error( 'estandar_meta_verify_failed', 'Verificación de webhook fallida.', array( 'status' => 403 ) );
	}

	/** POST: notificación de lead(s). Verifica firma, resuelve y ingesta. */
	public static function handle_notification( \WP_REST_Request $request )
	{
		$raw = (string) $request->get_body();
		$sig = (string) $request->get_header( 'x_hub_signature_256' );
		if ( $sig === '' ) {
			$sig = (string) $request->get_header( 'x-hub-signature-256' );
		}
		if ( $sig === '' && isset( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ) ) {
			$sig = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ) );
		}
		if ( ! self::signature_ok( $raw, $sig ) ) {
			return new \WP_Error( 'estandar_meta_bad_signature', 'Firma inválida.', array( 'status' => 403 ) );
		}
		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'estandar_meta_bad_payload', 'Payload inválido.', array( 'status' => 400 ) );
		}

		$leads = self::extract_leads( $payload );
		$ids   = array();
		foreach ( $leads as $lead ) {
			$res = self::process_lead( $lead );
			if ( ! empty( $res['id'] ) ) {
				$ids[] = (int) $res['id'];
			}
		}
		return rest_ensure_response( array( 'received' => count( $leads ), 'ingested' => count( $ids ), 'ids' => $ids ) );
	}

	private static function signature_ok( string $raw, string $header ): bool
	{
		$secret = self::app_secret();
		if ( $secret === '' ) {
			return true;
		}
		if ( $header === '' || strpos( $header, 'sha256=' ) !== 0 ) {
			return false;
		}
		$expected = 'sha256=' . hash_hmac( 'sha256', $raw, $secret );
		return hash_equals( $expected, $header );
	}

	/** @return array<int,array<string,string>> */
	private static function extract_leads( array $payload ): array
	{
		$out = array();
		foreach ( (array) ( $payload['entry'] ?? array() ) as $entry ) {
			foreach ( (array) ( $entry['changes'] ?? array() ) as $change ) {
				$value = (array) ( $change['value'] ?? array() );
				if ( empty( $value['leadgen_id'] ) ) {
					continue;
				}
				$out[] = array(
					'leadgen_id'   => (string) $value['leadgen_id'],
					'form_id'      => (string) ( $value['form_id'] ?? '' ),
					'page_id'      => (string) ( $value['page_id'] ?? '' ),
					'ad_id'        => (string) ( $value['ad_id'] ?? '' ),
					'adgroup_id'   => (string) ( $value['adgroup_id'] ?? '' ),
					'created_time' => (string) ( $value['created_time'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/** Resuelve el lead en Graph y lo ingesta. */
	private static function process_lead( array $lead ): array
	{
		$token = self::page_token();
		if ( $token === '' || empty( $lead['leadgen_id'] ) ) {
			return array();
		}
		$url = add_query_arg(
			array(
				'fields'       => 'id,created_time,field_data,form_id,ad_id,adset_id,campaign_id,platform',
				'access_token' => $token,
			),
			'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . rawurlencode( $lead['leadgen_id'] )
		);
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) ) {
			return array();
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return array();
		}

		$fields = array();
		$rawmap = array();
		foreach ( (array) ( $data['field_data'] ?? array() ) as $fd ) {
			$name  = (string) ( $fd['name'] ?? '' );
			$value = $fd['values'][0] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( ' ', $value );
			}
			$rawmap[ $name ] = (string) $value;
		}
		$name = $rawmap['full_name'] ?? trim( ( $rawmap['first_name'] ?? '' ) . ' ' . ( $rawmap['last_name'] ?? '' ) );
		$fields['name']     = $name;
		$fields['email']    = $rawmap['email'] ?? '';
		$fields['phone']    = $rawmap['phone_number'] ?? ( $rawmap['phone'] ?? '' );
		$fields['mensaje']  = $rawmap['mensaje'] ?? ( $rawmap['message'] ?? '' );
		$fields            += $rawmap;
		// ORIGIN_V1: distingue el formulario instantáneo de Facebook vs Instagram
		// (Meta entrega `platform`). Viaja como `_origin` para el correo/CRM.
		$platform = sanitize_key( (string) ( $data['platform'] ?? '' ) );
		$fields['_origin'] = ( $platform === 'instagram' ) ? 'instant_ig' : 'instant_fb';

		$form_id = (int) ( $data['form_id'] ?? $lead['form_id'] ?? 0 );
		$slug    = self::resolve_project_slug( $form_id, $data, $lead );
		if ( $slug !== '' ) {
			$fields['project_slug'] = $slug;
		}
		$map     = self::forms_map();
		$meta    = array(
			'leadgen_id' => (string) $data['id'],
			'page_id'    => (string) ( $lead['page_id'] ?? '' ),
			'ad_id'      => (string) ( $data['ad_id'] ?? $lead['ad_id'] ?? '' ),
			'adset_id'   => (string) ( $data['adset_id'] ?? $lead['adgroup_id'] ?? '' ),
			'campaign_id'=> (string) ( $data['campaign_id'] ?? '' ),
			'form_id'    => (string) $form_id,
			'form_label' => (string) ( $map[ (string) $form_id ] ?? '' ),
			'created_time'=> (string) ( $data['created_time'] ?? $lead['created_time'] ?? '' ),
		);

		return FormBridge::handle_facebook( $form_id, $fields, $meta );
	}
}
