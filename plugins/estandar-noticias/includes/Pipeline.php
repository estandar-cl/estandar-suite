<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pipeline {

	public static function run( string $edicion_slug = '', bool $force = false ): array {
		$s = Settings::all();
		if ( empty( $s['general']['activo'] ) && $edicion_slug === '' && ! $force ) {
			return array( 'skipped' => 'inactivo' );
		}
		$eds = (array) $s['ediciones'];
		if ( $edicion_slug !== '' ) {
			$eds = isset( $eds[ $edicion_slug ] ) ? array( $edicion_slug => $eds[ $edicion_slug ] ) : array();
		}

		$out = array();
		foreach ( $eds as $slug => $ed ) {
			if ( empty( $ed['activo'] ) ) {
				continue;
			}
			$out[ (string) $slug ] = self::run_edicion( (string) $slug, (array) $ed, $s, $force );
		}
		return $out;
	}

	private static function run_edicion( string $slug, array $ed, array $s, bool $force = false ): array {
		$today = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$last  = get_option( 'estandar_noticias_ultimo_' . $slug );
		if ( ! $force && is_array( $last ) && (string) ( $last['fecha'] ?? '' ) === $today ) {
			return array( 'edicion' => $slug, 'skipped' => 'ya ejecutado hoy', 'fecha' => $today );
		}

		$temas = array();
		foreach ( (array) ( $ed['temas'] ?? array() ) as $t ) {
			if ( isset( $s['temas'][ $t ] ) ) {
				$temas[ $t ] = $s['temas'][ $t ];
			}
		}
		if ( ! $temas ) {
			return array( 'edicion' => $slug, 'total' => 0, 'error' => 'sin temas' );
		}

		$max_tema = (int) ( $ed['max_por_tema'] ?? 0 );
		if ( $max_tema <= 0 ) {
			$max_tema = (int) $s['general']['max_por_tema'];
		}

		$collected = Collect::collect(
			$temas,
			array(
				'ventana_horas'      => (int) $s['general']['ventana_horas'],
				'max_por_tema'       => $max_tema,
				'max_por_fuente'     => (int) $s['general']['max_por_fuente'],
				'ruido'              => (array) $s['ruido'],
				'blacklist_dominios' => (array) $s['blacklist_dominios'],
			)
		);

		$cands = array();
		foreach ( (array) $collected['temas'] as $tslug => $items ) {
			foreach ( (array) $items as $it ) {
				$it['tema'] = (string) $tslug;
				$cands[]    = $it;
			}
		}

		$data = array(
			'fecha'   => function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'edicion' => $slug,
			'titulo'  => (string) ( $ed['titulo'] ?? '' ),
			'temas'   => array(),
		);

		$perfil  = (string) ( $ed['perfil'] ?? '' );
		$curated = $perfil !== '' && $cands ? Curate::curate( $cands, $perfil ) : array();

		if ( $curated ) {
			foreach ( $curated as $c ) {
				$idx = (int) $c['candidato'];
				if ( ! isset( $cands[ $idx ] ) ) {
					continue;
				}
				$it   = $cands[ $idx ];
				$tema = $c['tema'] !== '' ? $c['tema'] : $it['tema'];
				if ( trim( (string) $c['resumen'] ) !== '' ) {
					$it['texto'] = $c['resumen'];
				}
				$data['temas'][ $tema ]['items'][] = $it;
			}
		} else {
			foreach ( $cands as $it ) {
				$data['temas'][ $it['tema'] ]['items'][] = $it;
			}
		}

		// Garantiza al menos 1 noticia por tema con candidatos, aunque la IA lo omita.
		foreach ( (array) $collected['temas'] as $tslug => $items ) {
			if ( empty( $data['temas'][ $tslug ]['items'] ) && $items ) {
				$data['temas'][ $tslug ]['items'] = array( $items[0] );
			}
		}
		// Mantiene el orden de los temas tal como están configurados.
		$orden = array_keys( $temas );
		$tmp   = array();
		foreach ( $orden as $tslug ) {
			if ( isset( $data['temas'][ $tslug ] ) ) {
				$tmp[ $tslug ] = $data['temas'][ $tslug ];
			}
		}
		foreach ( $data['temas'] as $k => $v ) {
			if ( ! isset( $tmp[ $k ] ) ) {
				$tmp[ $k ] = $v;
			}
		}
		$data['temas'] = $tmp;

		foreach ( $data['temas'] as $tslug => &$t ) {
			$t['nombre'] = (string) ( $s['temas'][ $tslug ]['nombre'] ?? $tslug );
		}
		unset( $t );

		$salida = (string) $s['general']['salida'];
		$res    = array(
			'edicion' => $slug,
			'total'   => Render::total( $data ),
			'errors'  => (array) $collected['errors'],
			'curado'  => (bool) $curated,
		);

		if ( in_array( $salida, array( 'ambos', 'post' ), true ) ) {
			$res['post'] = Publish::publish( $data );
		}
		if ( in_array( $salida, array( 'ambos', 'correo' ), true ) ) {
			$res['mail'] = Mailer::send( $data, $ed );
		}

		update_option(
			'estandar_noticias_ultimo_' . $slug,
			array(
				'fecha'   => $data['fecha'],
				'total'   => (int) $res['total'],
				'curado'  => (bool) $curated,
				'post'    => $res['post']['url'] ?? '',
				'mail_ok' => $res['mail']['ok'] ?? null,
				'errors'  => (array) $collected['errors'],
			),
			false
		);

		return $res;
	}
}
