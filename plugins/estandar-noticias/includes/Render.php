<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Render {

	public static function slug( array $data ): string {
		return sanitize_title( 'boletin-' . ( $data['edicion'] ?? 'principal' ) . '-' . ( $data['fecha'] ?? gmdate( 'Y-m-d' ) ) );
	}

	public static function titulo( array $data ): string {
		$base = trim( (string) ( $data['titulo'] ?? '' ) );
		if ( $base === '' ) {
			$base = 'Boletín de noticias';
		}
		return $base . ' — ' . self::fecha_larga( $data['fecha'] ?? '' );
	}

	public static function fecha_larga( string $ymd ): string {
		$ts = strtotime( $ymd . ' 12:00:00' );
		if ( ! $ts ) {
			return $ymd;
		}
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( 'j \d\e F \d\e Y', $ts );
		}
		return date( 'Y-m-d', $ts );
	}

	public static function clip( string $texto, int $max = 900 ): string {
		$texto = trim( $texto );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $texto, 'UTF-8' ) > $max ) {
			return rtrim( mb_substr( $texto, 0, $max, 'UTF-8' ) ) . '…';
		}
		return $texto;
	}

	public static function total( array $data ): int {
		$n = 0;
		foreach ( (array) ( $data['temas'] ?? array() ) as $t ) {
			$n += count( (array) ( $t['items'] ?? array() ) );
		}
		return $n;
	}

	public static function texto( array $data ): string {
		$out = array();
		$out[] = mb_strtoupper( (string) ( $data['titulo'] ?? 'Boletín de noticias' ), 'UTF-8' );
		$out[] = self::fecha_larga( $data['fecha'] ?? '' );
		$out[] = str_repeat( '=', 40 );

		foreach ( (array) ( $data['temas'] ?? array() ) as $t ) {
			$items = (array) ( $t['items'] ?? array() );
			if ( ! $items ) {
				continue;
			}
			$out[] = '';
			$out[] = '## ' . ( $t['nombre'] ?? '' );
			foreach ( $items as $it ) {
				$out[] = '';
				$out[] = '• ' . ( $it['titulo'] ?? '' );
				$meta  = trim( (string) ( $it['fuente'] ?? '' ) );
				if ( $meta !== '' ) {
					$out[] = '  ' . $meta;
				}
				$res = self::clip( (string) ( $it['texto'] ?? '' ) );
				if ( $res !== '' ) {
					$out[] = '  ' . $res;
				}
				if ( ! empty( $it['link'] ) ) {
					$out[] = '  ' . $it['link'];
				}
			}
		}
		return trim( implode( "\n", $out ) );
	}

	public static function html( array $data, bool $email = false ): string {
		$h   = array();
		$ttl = esc_html( (string) ( $data['titulo'] ?? 'Boletín de noticias' ) );
		$h[] = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:640px;margin:0 auto;color:#1d2327;">';
		$h[] = '<h1 style="font-size:22px;line-height:1.25;margin:0 0 4px;">' . $ttl . '</h1>';
		$h[] = '<p style="color:#646970;margin:0 0 18px;">' . esc_html( self::fecha_larga( $data['fecha'] ?? '' ) ) . '</p>';

		foreach ( (array) ( $data['temas'] ?? array() ) as $t ) {
			$items = (array) ( $t['items'] ?? array() );
			if ( ! $items ) {
				continue;
			}
			$h[] = '<h2 style="font-size:16px;text-transform:uppercase;letter-spacing:.04em;color:#2271b1;border-bottom:1px solid #dcdcde;padding-bottom:6px;margin:26px 0 12px;">' . esc_html( (string) ( $t['nombre'] ?? '' ) ) . '</h2>';
			foreach ( $items as $it ) {
				$h[] = '<div style="margin:0 0 18px;">';
				$link = ! empty( $it['link'] ) ? esc_url( (string) $it['link'] ) : '';
				$tit  = esc_html( (string) ( $it['titulo'] ?? '' ) );
				$h[]  = $link !== '' ? '<a href="' . $link . '" style="font-size:16px;font-weight:600;color:#1d2327;text-decoration:none;">' . $tit . '</a>' : '<strong>' . $tit . '</strong>';
				if ( ! empty( $it['fuente'] ) ) {
					$h[] = '<div style="font-size:12px;color:#8c8f94;margin:2px 0 6px;">' . esc_html( (string) $it['fuente'] ) . '</div>';
				}
				if ( ! empty( $it['texto'] ) ) {
					$h[] = '<p style="margin:0 0 6px;line-height:1.5;">' . esc_html( self::clip( (string) $it['texto'] ) ) . '</p>';
				}
				if ( $link !== '' ) {
					$h[] = '<a href="' . $link . '" style="font-size:13px;color:#2271b1;">Leer más</a>';
				}
				if ( ! $email ) {
					$h[] = '[esn_rate tema="' . esc_attr( (string) ( $t['nombre'] ?? '' ) ) . '" fuente="' . esc_attr( (string) ( $it['fuente'] ?? '' ) ) . '" item="' . esc_attr( Ratings::item_key( $it ) ) . '"]';
				}
				$h[] = '</div>';
			}
		}

		$h[] = '<hr style="border:none;border-top:1px solid #dcdcde;margin:24px 0;">';
		$h[] = '<p style="font-size:12px;color:#8c8f94;">Generado por Estandar Noticias · ' . esc_html( home_url( '/' ) ) . '</p>';
		$h[] = '</div>';
		return implode( "\n", $h );
	}

	public static function excerpt( array $data ): string {
		$primero = '';
		foreach ( (array) ( $data['temas'] ?? array() ) as $t ) {
			$items = (array) ( $t['items'] ?? array() );
			if ( $items ) {
				$primero = (string) ( $items[0]['titulo'] ?? '' );
				break;
			}
		}
		$base = trim( (string) ( $data['titulo'] ?? '' ) . ': ' . $primero );
		return wp_trim_words( $base, 28, '…' );
	}

	public static function tags( array $data ): array {
		$out = array();
		foreach ( (array) ( $data['temas'] ?? array() ) as $slug => $t ) {
			if ( ! empty( $t['items'] ) ) {
				$out[] = (string) ( $t['nombre'] ?? $slug );
			}
		}
		return $out;
	}
}
