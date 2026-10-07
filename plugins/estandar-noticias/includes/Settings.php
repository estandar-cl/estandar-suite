<?php

namespace Estandar\Noticias;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION = 'estandar_noticias_settings';

	const PROVIDERS = array( 'deepseek', 'openai' );

	public static function providers(): array {
		return self::PROVIDERS;
	}

	public static function models( string $provider ): array {
		return $provider === 'openai'
			? array( 'gpt-4o-mini', 'gpt-4o' )
			: array( 'deepseek-chat', 'deepseek-reasoner' );
	}

	public static function default_temas(): array {
		return array(
			'ia-tecnologia' => array(
				'nombre' => 'IA y tecnología',
				'activo' => 1,
				'horas'  => 24,
				'feeds'  => array(
					'https://openai.com/news/rss.xml',
					'https://blog.google/technology/ai/rss/',
					'http://export.arxiv.org/rss/cs.AI',
				),
				'incluir' => array(),
			),
			'neurociencia' => array(
				'nombre' => 'Neurociencia, cerebro e inteligencia cognitiva',
				'activo' => 1,
				'horas'  => 72,
				'feeds'  => array(
					'https://www.nature.com/neuro.rss',
					'https://www.nature.com/nathumbehav.rss',
					'https://www.nature.com/nrn.rss',
					'https://www.thetransmitter.org/feed/',
					'https://www.quantamagazine.org/feed/',
					'https://neurosciencenews.com/feed/',
				),
				'incluir' => array(),
			),
			'geopolitica' => array(
				'nombre' => 'Geopolítica y defensa',
				'activo' => 1,
				'horas'  => 24,
				'feeds'  => array(
					'https://www.foreignaffairs.com/rss.xml',
					'https://www.bellingcat.com/feed/',
					'https://www.lawfaremedia.org/feeds/articles',
					'https://www.rand.org/blog.xml',
				),
				'incluir' => array(),
			),
			'masoneria' => array(
				'nombre' => 'Masonería',
				'activo' => 1,
				'horas'  => 24,
				'feeds'  => array(
					'https://www.quatuorcoronati.com/feed/',
					'https://www.thesquaremagazine.com/feed',
				),
				'incluir' => array(),
			),
			'salud-longevidad' => array(
				'nombre' => 'Salud y longevidad',
				'activo' => 1,
				'horas'  => 24,
				'feeds'  => array( 'https://www.nature.com/nm.rss' ),
				'incluir' => array(),
			),
			'negocios' => array(
				'nombre' => 'Negocios y emprendimiento real',
				'activo' => 1,
				'horas'  => 24,
				'feeds'  => array(
					'https://news.google.com/rss/search?q=negocios+chile&hl=es-CL&gl=CL&ceid=CL:es',
				),
				'incluir' => array(),
			),
			'economia-chile' => array(
				'nombre' => 'Economía y política Chile',
				'activo' => 1,
				'horas'  => 24,
				'feeds'  => array(
					'https://www.ciperchile.cl/feed/',
					'https://www.ex-ante.cl/feed/',
					'https://www.latercera.com/arc/outboundfeeds/rss/?outputType=xml',
				),
				'incluir' => array(
					'econom', 'impuest', 'tribut', 'reforma', 'banco central', 'inflaci',
					'vivienda', 'hipotec', 'emple', 'pyme', 'empresa', 'inversi', 'mercado',
					'presupuesto', 'deuda', 'dólar', 'dolar', 'tasa', 'pensión', 'pension',
					'sala cuna', 'constituc', 'congreso', 'gobierno', 'ministerio', 'polít',
					'polit', 'fiscal', 'corrup', 'seguridad', 'migrac', 'energía', 'minería',
					'litio', 'cobre', 'exporta', 'pib', 'crecimiento', 'salario', 'sueldo', 'trabajo',
				),
			),
			'wordpress-web-seo' => array(
				'nombre' => 'WordPress / Web / SEO',
				'activo' => 1,
				'horas'  => 24,
				'feeds'  => array(
					'https://wordpress.org/news/feed/',
					'https://developers.google.com/search/blog/feed.xml',
					'https://web.dev/feed.xml',
				),
				'incluir' => array(),
			),
			'inmobiliario-chile' => array(
				'nombre' => 'Inmobiliario Chile',
				'activo' => 1,
				'horas'  => 24,
				'feeds'  => array(
					'https://news.google.com/rss/search?q=inmobiliario+chile&hl=es-CL&gl=CL&ceid=CL:es',
				),
				'incluir' => array(),
			),
			'agro' => array(
				'nombre' => 'Agro / uvas y berries',
				'activo' => 1,
				'horas'  => 24,
				'feeds'  => array( 'https://www.odepa.gob.cl/feed' ),
				'incluir' => array(),
			),
		);
	}

	public static function default_ruido(): array {
		return array(
			'céline dion', 'celine dion', 'farándula', 'reality show', 'alfombra roja',
			'espectáculos', 'fútbol', 'futbol', 'colo colo', 'u de chile', 'club deportivo',
			'miss universo', 'teleserie', 'telenovela', 'influencer', 'instagram', 'tiktok',
			'reguetón', 'bad bunny', 'kardashian', 'luna de miel', 'receta de', 'restaurante',
			'restaurant', 'gastronomía', 'celebrities', 'horóscopo',
		);
	}

	public static function defaults(): array {
		return array(
			'general' => array(
				'activo'         => 0,
				'hora'           => '07:30',
				'ventana_horas'  => 24,
				'max_por_tema'   => 10,
				'max_por_fuente' => 3,
				'salida'         => 'ambos',
				'post_type'      => 'noticia',
				'taxonomia'      => 'tema',
				'categoria'      => 'Noticias',
				'idioma'         => 'es',
				'remitente'      => '',
			),
			'ai' => array(
				'provider' => 'deepseek',
				'modelo'   => '',
				'api_key'  => '',
			),
			'ediciones' => array(),
			'temas'              => self::default_temas(),
			'ruido'              => self::default_ruido(),
			'blacklist_dominios' => array(),
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return self::merge( self::defaults(), $saved );
	}

	public static function get( string $group, ?string $key = null, $fallback = null ) {
		$all = self::all();
		if ( ! isset( $all[ $group ] ) ) {
			return $fallback;
		}
		if ( $key === null ) {
			return $all[ $group ];
		}
		return $all[ $group ][ $key ] ?? $fallback;
	}

	public static function edicion( string $slug ): ?array {
		$eds = self::get( 'ediciones', null, array() );
		return isset( $eds[ $slug ] ) ? $eds[ $slug ] : null;
	}

	public static function tema( string $slug ): ?array {
		$temas = self::get( 'temas', null, array() );
		return isset( $temas[ $slug ] ) ? $temas[ $slug ] : null;
	}

	public static function save( array $input ): array {
		$clean = self::sanitize( $input, self::all() );
		update_option( self::OPTION, $clean );
		return $clean;
	}

	private static function merge( array $base, array $over ): array {
		foreach ( $over as $k => $v ) {
			if ( in_array( $k, array( 'ediciones', 'temas' ), true ) && is_array( $v ) ) {
				$base[ $k ] = $v;
				continue;
			}
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) ) {
				$base[ $k ] = self::merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	public static function sanitize( array $in, array $base ): array {
		$out = $base;

		$g = isset( $in['general'] ) && is_array( $in['general'] ) ? $in['general'] : array();
		$out['general']['activo']         = empty( $g['activo'] ) ? 0 : 1;
		$out['general']['hora']           = self::hora( (string) ( $g['hora'] ?? '07:30' ) );
		$out['general']['ventana_horas']  = self::int_range( $g['ventana_horas'] ?? 24, 1, 720, 24 );
		$out['general']['max_por_tema']   = self::int_range( $g['max_por_tema'] ?? 10, 1, 200, 10 );
		$out['general']['max_por_fuente'] = self::int_range( $g['max_por_fuente'] ?? 3, 1, 200, 3 );
		$salida = (string) ( $g['salida'] ?? 'ambos' );
		$out['general']['salida']         = in_array( $salida, array( 'ambos', 'post', 'correo' ), true ) ? $salida : 'ambos';
		$out['general']['post_type']      = sanitize_key( (string) ( $g['post_type'] ?? 'noticia' ) ) ?: 'noticia';
		$out['general']['taxonomia']      = sanitize_key( (string) ( $g['taxonomia'] ?? 'tema' ) ) ?: 'tema';
		$out['general']['categoria']      = sanitize_text_field( (string) ( $g['categoria'] ?? 'Noticias' ) );
		$out['general']['idioma']         = sanitize_key( (string) ( $g['idioma'] ?? 'es' ) ) ?: 'es';
		$out['general']['remitente']      = sanitize_email( (string) ( $g['remitente'] ?? '' ) );

		$ai = isset( $in['ai'] ) && is_array( $in['ai'] ) ? $in['ai'] : array();
		$provider = in_array( ( $ai['provider'] ?? '' ), self::PROVIDERS, true ) ? $ai['provider'] : 'deepseek';
		$out['ai']['provider'] = $provider;
		$modelo = sanitize_text_field( (string) ( $ai['modelo'] ?? '' ) );
		$out['ai']['modelo'] = in_array( $modelo, self::models( $provider ), true ) ? $modelo : '';
		$key = isset( $ai['api_key'] ) ? trim( (string) $ai['api_key'] ) : '';
		if ( $key !== '' ) {
			$out['ai']['api_key'] = $key;
		} elseif ( ! empty( $base['ai']['api_key'] ) ) {
			$out['ai']['api_key'] = $base['ai']['api_key'];
		}

		$temas = array();
		if ( isset( $in['temas'] ) && is_array( $in['temas'] ) ) {
			foreach ( $in['temas'] as $key => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$slug = sanitize_key( (string) ( $row['nombre'] ?? '' ) );
				if ( $slug === '' ) {
					$slug = sanitize_key( (string) $key );
				}
				if ( $slug === '' || strpos( $slug, '__new__' ) !== false ) {
					continue;
				}
				$temas[ $slug ] = array(
					'nombre'  => sanitize_text_field( (string) ( $row['nombre'] ?? $slug ) ),
					'activo'  => empty( $row['activo'] ) ? 0 : 1,
					'horas'   => self::int_range( $row['horas'] ?? 24, 1, 720, 24 ),
					'max'     => self::int_range( $row['max'] ?? 5, 0, 50, 5 ),
					'feeds'   => self::urls( $row['feeds'] ?? array(), $row['feeds_new'] ?? '' ),
					'incluir' => self::csv( $row['incluir'] ?? '' ),
				);
			}
		}
		if ( $temas ) {
			$out['temas'] = $temas;
		}

		$eds = array();
		if ( isset( $in['ediciones'] ) && is_array( $in['ediciones'] ) ) {
			foreach ( $in['ediciones'] as $key => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$slug = sanitize_key( (string) ( $row['titulo'] ?? '' ) );
				if ( $slug === '' ) {
					$slug = sanitize_key( (string) $key );
				}
				if ( $slug === '' || strpos( $slug, '__new__' ) !== false ) {
					continue;
				}
				$sel = array();
				if ( isset( $row['temas'] ) && is_array( $row['temas'] ) ) {
					foreach ( $row['temas'] as $t ) {
						$t = sanitize_key( (string) $t );
						if ( $t !== '' && isset( $temas[ $t ] ) ) {
							$sel[] = $t;
						}
					}
				}
				$eds[ $slug ] = array(
					'titulo'        => sanitize_text_field( (string) ( $row['titulo'] ?? $slug ) ),
					'activo'        => empty( $row['activo'] ) ? 0 : 1,
					'perfil'        => sanitize_textarea_field( (string) ( $row['perfil'] ?? '' ) ),
					'temas'         => array_values( $sel ),
					'destinatarios' => self::emails( $row['destinatarios'] ?? '' ),
					'asunto'        => sanitize_text_field( (string) ( $row['asunto'] ?? '' ) ),
					'max_por_tema'  => self::int_range( $row['max_por_tema'] ?? 0, 0, 50, 0 ),
				);
			}
		}
		if ( $eds ) {
			$out['ediciones'] = $eds;
		}

		$out['ruido']              = self::lines( $in['ruido'] ?? array() );
		$out['blacklist_dominios'] = self::lines( $in['blacklist_dominios'] ?? array() );

		return $out;
	}

	public static function lines( $value ): array {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_map( 'strval', $value ) );
		}
		$parts = preg_split( '/[\r\n]+/', (string) $value );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$p = trim( sanitize_text_field( (string) $p ) );
			if ( $p !== '' ) {
				$out[] = $p;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function csv( $value ): array {
		$parts = preg_split( '/[,;\r\n]+/', is_array( $value ) ? implode( ',', $value ) : (string) $value );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$p = trim( sanitize_text_field( (string) $p ) );
			if ( $p !== '' ) {
				$out[] = $p;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function urls( $checked, $extra ): array {
		$out = array();
		foreach ( self::lines( is_array( $checked ) ? $checked : array() ) as $u ) {
			$out[] = esc_url_raw( $u );
		}
		foreach ( self::lines( $extra ) as $u ) {
			$out[] = esc_url_raw( $u );
		}
		$out = array_values( array_unique( array_filter( $out ) ) );
		return array_values( array_filter( $out, static function ( $u ) {
			return (bool) preg_match( '#^https?://#i', (string) $u );
		} ) );
	}

	public static function emails( $value ): array {
		return array_values( array_filter( array_map( 'sanitize_email', self::lines( $value ) ), 'is_email' ) );
	}

	private static function hora( string $v ): string {
		if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( $v ), $m ) ) {
			return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		}
		return '07:30';
	}

	private static function int_range( $v, int $min, int $max, int $fallback ): int {
		$v = (int) $v;
		if ( $v < $min || $v > $max ) {
			return $fallback;
		}
		return $v;
	}
}
