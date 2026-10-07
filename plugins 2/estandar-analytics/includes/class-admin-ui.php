<?php
/**
 * Analytics domain helpers and thin UI wrappers.
 *
 * Responsabilidades:
 *   - Formateo de valores de dominio (count, duration, percent).
 *   - Labels de ayuda específicos de Analytics.
 *   - Limpieza de URLs de páginas.
 *   - render_ranked_table(): wrapper sobre estandar_render_data_table()
 *     que aplica lógica de dominio (clean_page_label, bar proporcional).
 *   - render_insights(): componente Signal Ledger (CANDIDATO_CORE_COMPONENT_V2).
 *
 * NO contiene: layout, header, navigation, toolbar, KPI cards, chart cards.
 * Esos los provee Core via Shell V2 y Core Components.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Admin_UI {

	const MIN_SAMPLE = 10;

	// ── Formateo de dominio ─────────────────────────────────────────────────

	public static function metric_help( $key ) {
		$map = array(
			'visitors'        => __( 'Cantidad de visitantes únicos estimados dentro del período seleccionado.', 'estandar-analytics' ),
			'sessions'        => __( 'Número total de sesiones de navegación registradas en el período.', 'estandar-analytics' ),
			'pageviews'       => __( 'Total de páginas vistas registradas, incluyendo repeticiones en la misma sesión.', 'estandar-analytics' ),
			'avg_duration'    => __( 'Tiempo promedio estimado que un visitante permaneció activo en el sitio.', 'estandar-analytics' ),
			'bounce_rate'     => __( 'Porcentaje de sesiones donde el usuario abandonó sin interacción adicional.', 'estandar-analytics' ),
			'conversion_rate' => __( 'Porcentaje de sesiones que completaron una conversión definida.', 'estandar-analytics' ),
			'source'          => __( 'Origen estimado del tráfico detectado mediante referrer y parámetros UTM.', 'estandar-analytics' ),
			'landing'         => __( 'Primera página visitada al iniciar la sesión.', 'estandar-analytics' ),
			'exit'            => __( 'Última página vista antes de abandonar la sesión.', 'estandar-analytics' ),
			'conversions'     => __( 'Acciones de valor configuradas (clics, formularios, páginas de gracias).', 'estandar-analytics' ),
		);
		return $map[ $key ] ?? '';
	}

	public static function insufficient_label(): string {
		return __( 'Datos insuficientes', 'estandar-analytics' );
	}

	public static function render_empty( $message, $detail = '' ): void {
		if ( function_exists( 'estandar_render_empty_state' ) ) {
			estandar_render_empty_state(
				array(
					'message' => (string) $message,
					'detail'  => (string) $detail,
				)
			);
			return;
		}

		echo '<div class="ea-empty"><p>' . esc_html( (string) $message ) . '</p></div>';
	}

	public static function render_project_trust_badge( string $level, string $source = '' ): void {
		// ADQUISICION_UX_V1: el aviso "no soportado" solo tiene sentido cuando el
		// usuario efectivamente filtró por proyecto; sin filtro activo era ruido
		// críptico permanente en el header de cada sección.
		if ( 'unsupported' === $level ) {
			$active_project = isset( $_GET['ea_project'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ea_project'] ) ) : '';
			if ( '' === $active_project ) {
				return;
			}
		}

		$cfg = array(
			'exact' => array(
				'label'  => __( '✓ Exacto', 'estandar-analytics' ),
				'detail' => __( 'Fuente: es_leads.project_slug', 'estandar-analytics' ),
				'bg'     => '#e6f4ea',
				'color'  => '#1e8e3e',
			),
			'approx' => array(
				'label'  => __( '≈ Aproximado', 'estandar-analytics' ),
				'detail' => __( 'Fuente: ea_sessions.first_project_slug', 'estandar-analytics' ),
				'bg'     => '#fff4ce',
				'color'  => '#8a6100',
			),
			'unsupported' => array(
				'label'  => __( 'El filtro de proyecto no aplica aquí', 'estandar-analytics' ),
				'detail' => __( 'Esta sección muestra datos de todos los proyectos; el filtro seleccionado no la acota.', 'estandar-analytics' ),
				'bg'     => '#f6f7f7',
				'color'  => '#646970',
			),
		);
		$item = $cfg[ $level ] ?? $cfg['unsupported'];
		$detail = $source !== '' ? $source : $item['detail'];

		echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px;">';
		echo '<span style="display:inline-block;border-radius:4px;padding:4px 9px;font-size:12px;font-weight:700;background:' . esc_attr( $item['bg'] ) . ';color:' . esc_attr( $item['color'] ) . ';">' . esc_html( $item['label'] ) . '</span>';
		if ( $detail !== '' ) {
			echo '<span style="font-size:12px;color:#646970;">' . esc_html( $detail ) . '</span>';
		}
		echo '</div>';
	}

	public static function trend_note( $sample ): string {
		return (int) $sample < self::MIN_SAMPLE
			? __( 'Datos insuficientes para tendencia', 'estandar-analytics' )
			: '';
	}

	/**
	 * Range shortcuts (1d/2d/7d/30d/90d) — single source of truth: ea_from/ea_to.
	 *
	 * Reemplaza los mecanismos previos `ea_days` (funnel) y `range` (attribution),
	 * que no estaban conectados a EA_Utils::admin_filters() y por lo tanto no
	 * afectaban las consultas reales (ANALYTICS_RANGE_FIX, 2026-06-14).
	 *
	 * @param array  $filters    Resultado de EA_Utils::admin_filters() — usa 'from'/'to' para estado activo.
	 * @param string $page       Slug de la página actual (ea-funnel, ea-attribution, ...).
	 * @param array  $extra_args Query args adicionales a preservar (ea_project, ea_operator, ea_source, ...).
	 */
	public static function render_range_shortcuts( array $filters, string $page, array $extra_args = array() ): void {
		$today = EA_Utils::today();

		$ranges = array(
			'1d'  => array( 'days_back' => 0,  'label' => __( 'Hoy', 'estandar-analytics' ) ),
			'2d'  => array( 'days_back' => 1,  'label' => __( 'Últ. 2 días', 'estandar-analytics' ) ),
			'7d'  => array( 'days_back' => 6,  'label' => __( 'Últ. 7 días', 'estandar-analytics' ) ),
			'30d' => array( 'days_back' => 29, 'label' => __( 'Últ. 30 días', 'estandar-analytics' ) ),
			'90d' => array( 'days_back' => 89, 'label' => __( 'Últ. 90 días', 'estandar-analytics' ) ),
		);

		$current_from = $filters['from'] ?? '';
		$current_to   = $filters['to'] ?? '';

		echo '<div class="ea-range-shortcuts" style="display:flex;gap:6px;">';
		foreach ( $ranges as $range ) {
			$from   = EA_Utils::date_offset( $today, -1 * (int) $range['days_back'] );
			$active = ( $current_from === $from && $current_to === $today );
			$args   = array_merge(
				$extra_args,
				array( 'page' => $page, 'ea_from' => $from, 'ea_to' => $today )
			);
			$url    = add_query_arg( $args, admin_url( 'admin.php' ) );
			?>
			<a href="<?php echo esc_url( $url ); ?>"
			   style="display:inline-block;padding:5px 14px;border-radius:4px;font-size:13px;font-weight:<?php echo $active ? '600' : 'normal'; ?>;text-decoration:none;
			          background:<?php echo $active ? '#2271b1' : '#f0f0f1'; ?>;
			          color:<?php echo $active ? '#fff' : '#1d2327'; ?>;
			          border:1px solid <?php echo $active ? '#2271b1' : '#c3c4c7'; ?>;">
				<?php echo esc_html( $range['label'] ); ?>
			</a>
			<?php
		}
		echo '</div>';
	}

	public static function format_count( $value, $sample = null ): string {
		return number_format_i18n( (int) $value );
	}

	public static function format_percent( $value, $sample ): string {
		return number_format_i18n( (float) $value, 1 ) . '%';
	}

	public static function format_duration( $seconds, $sample = null ): string {
		$seconds = (int) $seconds;
		if ( $seconds < 60 ) {
			/* translators: %d: seconds */
			return sprintf( __( '%ds', 'estandar-analytics' ), $seconds );
		}
		/* translators: 1: minutes, 2: seconds */
		return sprintf( __( '%1$dm %2$ds', 'estandar-analytics' ), floor( $seconds / 60 ), $seconds % 60 );
	}

	public static function clean_page_label( $url ): string {
		$url = trim( (string) $url );
		if ( $url === '' ) {
			return __( 'Página sin ruta', 'estandar-analytics' );
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || $path === '' ) {
			$path = $url;
		}
		$path = '/' . ltrim( $path, '/' );
		if ( $path === '/' ) {
			return __( 'Homepage', 'estandar-analytics' );
		}
		return trim( wp_html_excerpt( trim( $path, '/' ), 56, '...' ) );
	}

	// ── Wrapper con lógica de dominio ───────────────────────────────────────

	/**
	 * Tabla de datos con barra proporcional.
	 * Thin wrapper sobre estandar_render_data_table() que aplica:
	 *   - clean_page_label() para rutas de páginas
	 *   - bar cell proporcional al máximo de la columna
	 *
	 * @param string $title
	 * @param array  $rows       Filas con keys $label_key y $value_key.
	 * @param string $help_key   Clave para metric_help() — determina label de columna.
	 * @param string $label_key
	 * @param string $value_key
	 */
	public static function render_ranked_table( $title, $rows, $help_key = '', $label_key = 'label', $value_key = 'total' ): void {
		$rows = (array) $rows;
		$max  = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, (int) ( $row[ $value_key ] ?? 0 ) );
		}

		$value_label = in_array( $help_key, array( 'landing', 'exit', 'source' ), true )
			? __( 'Sesiones', 'estandar-analytics' )
			: __( 'Total', 'estandar-analytics' );

		$is_page = in_array( $help_key, array( 'landing', 'exit' ), true )
			|| in_array( $label_key, array( 'landing_page', 'page_url' ), true );

		$table_rows = array();
		foreach ( $rows as $row ) {
			$raw   = (string) ( $row[ $label_key ] ?? '' );
			$label = $is_page ? self::clean_page_label( $raw ) : $raw;
			$count = (int) ( $row[ $value_key ] ?? 0 );

			$table_rows[] = array(
				$label,
				'<strong>' . esc_html( number_format_i18n( $count ) ) . '</strong>',
				array( 'type' => 'bar', 'value' => $count, 'max' => $max ),
			);
		}

		// Wrapper de sección con título (Core renderiza el contenido de la tabla).
		echo '<section class="estandar-section">';
		echo '<h2>' . esc_html( $title ) . '</h2>';
		// CORE_OPTIONALITY_V1: mismo contrato que render_empty() arriba.
		if ( function_exists( 'estandar_render_data_table' ) ) {
			estandar_render_data_table(
				array( __( 'Detalle', 'estandar-analytics' ), $value_label, __( 'Peso relativo', 'estandar-analytics' ) ),
				$table_rows
			);
		} else {
			echo '<p>' . esc_html( self::insufficient_label() ) . '</p>';
		}
		echo '</section>';
	}

	public static function render_url_report_table( $title, $rows ): void {
		$table_rows = array();
		foreach ( (array) $rows as $row ) {
			$source_medium = trim( (string) ( $row['source'] ?? '' ) . ' / ' . (string) ( $row['medium'] ?? '' ), ' /' );
			$campaign      = (string) ( $row['campaign'] ?? '' );
			$project       = (string) ( $row['project'] ?? '' );
			if ( in_array( strtolower( $source_medium ), array( '', 'direct', 'direct / none' ), true ) ) {
				$source_medium = __( 'Directo', 'estandar-analytics' );
			}
			if ( class_exists( 'Estandar_Campaign_Label_Resolver' ) && $campaign !== '' ) {
				$campaign = Estandar_Campaign_Label_Resolver::campaign( $campaign );
			}
			$table_rows[] = array(
				'<code>' . esc_html( (string) ( $row['url'] ?? $row['label'] ?? '/' ) ) . '</code>',
				'<strong>' . esc_html( number_format_i18n( (int) ( $row['total'] ?? 0 ) ) ) . '</strong>',
				esc_html( number_format_i18n( (int) ( $row['unique'] ?? 0 ) ) ),
				esc_html( $source_medium ),
				esc_html( $campaign !== '' ? $campaign : '—' ),
				esc_html( $project !== '' ? $project : '—' ),
				esc_html( (string) ( $row['first_seen'] ?? '' ) ),
				esc_html( (string) ( $row['last_seen'] ?? '' ) ),
			);
		}

		echo '<section class="estandar-section">';
		echo '<h2>' . esc_html( $title ) . '</h2>';
		if ( function_exists( 'estandar_render_data_table' ) ) {
			estandar_render_data_table(
				array(
					__( 'URL normalizada', 'estandar-analytics' ),
					__( 'Visitas', 'estandar-analytics' ),
					__( 'Únicas', 'estandar-analytics' ),
					__( 'Fuente / medio', 'estandar-analytics' ),
					__( 'Campaña', 'estandar-analytics' ),
					__( 'Proyecto', 'estandar-analytics' ),
					__( 'Primer registro', 'estandar-analytics' ),
					__( 'Último registro', 'estandar-analytics' ),
				),
				$table_rows
			);
		} else {
			echo '<p>' . esc_html( self::insufficient_label() ) . '</p>';
		}
		echo '</section>';
	}

	// ── Signal Ledger — CANDIDATO_CORE_COMPONENT_V2 ─────────────────────────

	/**
	 * Grid de insights: kicker / value / meta.
	 * Patrón que aparecerá en Leads, Scanner y TRAK.
	 * Mover a Core cuando una segunda implementación similar emerja en otro plugin.
	 */
	public static function render_insights( $insights ): void {
		if ( empty( $insights ) ) {
			return;
		}
		?>
		<section class="ea-insights">
			<h2 class="ea-section-title"><?php esc_html_e( 'Insights de negocio', 'estandar-analytics' ); ?></h2>
			<div class="ea-insights__grid">
				<?php foreach ( $insights as $item ) : ?>
					<article class="ea-insight">
						<p class="ea-insight__kicker"><?php echo esc_html( (string) ( $item['kicker'] ?? '' ) ); ?></p>
						<p class="ea-insight__value"><?php echo esc_html( (string) ( $item['value'] ?? '' ) ); ?></p>
						<p class="ea-insight__meta"><?php echo esc_html( (string) ( $item['meta'] ?? '' ) ); ?></p>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}
}
