<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Collect {

	public static function collect( array $temas, array $opts = array() ): array {
		$ventana   = (int) ( $opts['ventana_horas'] ?? 24 );
		$max_tema  = (int) ( $opts['max_por_tema'] ?? 10 );
		$max_fte   = (int) ( $opts['max_por_fuente'] ?? 3 );
		$ruido     = (array) ( $opts['ruido'] ?? array() );
		$blacklist = (array) ( $opts['blacklist_dominios'] ?? array() );
		$fetcher   = $opts['fetch'] ?? null;

		$out  = array();
		$errs = array();

		foreach ( $temas as $slug => $t ) {
			if ( empty( $t['activo'] ) ) {
				continue;
			}
			$horas   = ! empty( $t['horas'] ) ? (int) $t['horas'] : $ventana;
			$incluir = (array) ( $t['incluir'] ?? array() );
			$tmax    = ! empty( $t['max'] ) ? (int) $t['max'] : $max_tema;
			$seen      = array();
			$items     = array();
			$by_source = array();
			$fallback  = array();

			foreach ( (array) ( $t['feeds'] ?? array() ) as $url ) {
				$body = self::body( (string) $url, $fetcher );
				if ( $body === null ) {
					$errs[] = $slug . ': ' . $url;
					continue;
				}
				foreach ( Feeds::parse( $body ) as $it ) {
					$key = $it['link'] !== '' ? $it['link'] : md5( $it['titulo'] );
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					if ( ! self::pass_filters( $it, $horas, $ruido, $blacklist, $incluir ) ) {
						if ( self::pass_content( $it, $ruido, $blacklist, $incluir ) ) {
							$it['tema']       = (string) $slug;
							$fallback[ $key ] = $it;
						}
						continue;
					}
					$src = $it['fuente'];
					$by_source[ $src ] = ( $by_source[ $src ] ?? 0 ) + 1;
					if ( $max_fte > 0 && $by_source[ $src ] > $max_fte ) {
						continue;
					}
					$seen[ $key ] = true;
					$it['tema']   = (string) $slug;
					$items[]      = $it;
				}
			}

			if ( ! $items && $fallback ) {
				$items = array_values( $fallback );
			}

			usort( $items, static function ( $a, $b ) {
				return strcmp( (string) $b['fecha'], (string) $a['fecha'] );
			} );
			if ( $tmax > 0 ) {
				$items = array_slice( $items, 0, $tmax );
			}
			$out[ (string) $slug ] = $items;
		}

		return array( 'temas' => $out, 'errors' => $errs );
	}

	private static function body( string $url, $fetcher ): ?string {
		if ( is_callable( $fetcher ) ) {
			$r = call_user_func( $fetcher, $url );
			return is_string( $r ) ? $r : null;
		}
		return Feeds::fetch( $url );
	}

	public static function pass_filters( array $it, int $horas, array $ruido, array $blacklist, array $incluir ): bool {
		return self::in_window( $it, $horas ) && self::pass_content( $it, $ruido, $blacklist, $incluir );
	}

	public static function in_window( array $it, int $horas ): bool {
		if ( ! empty( $it['fecha'] ) ) {
			$ts = strtotime( (string) $it['fecha'] );
			if ( $ts && ( time() - $ts ) > $horas * 3600 ) {
				return false;
			}
		}
		return true;
	}

	public static function pass_content( array $it, array $ruido, array $blacklist, array $incluir ): bool {
		$titulo = (string) ( $it['titulo'] ?? '' );
		$texto  = (string) ( $it['texto'] ?? '' );
		$link   = (string) ( $it['link'] ?? '' );

		if ( $blacklist ) {
			$host = Feeds::host( $link );
			foreach ( $blacklist as $d ) {
				$d = self::norm( (string) $d );
				if ( $d !== '' && ( $host === $d || str_ends_with( $host, '.' . $d ) ) ) {
					return false;
				}
			}
		}

		$hay = self::norm( $titulo . ' ' . $texto );
		foreach ( $ruido as $w ) {
			$w = self::norm( (string) $w );
			if ( $w !== '' && str_contains( $hay, $w ) ) {
				return false;
			}
		}

		if ( $incluir ) {
			$ok = false;
			foreach ( $incluir as $w ) {
				$w = self::norm( (string) $w );
				if ( $w !== '' && str_contains( $hay, $w ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				return false;
			}
		}

		return true;
	}

	private static function norm( string $s ): string {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		return trim( $s );
	}
}
