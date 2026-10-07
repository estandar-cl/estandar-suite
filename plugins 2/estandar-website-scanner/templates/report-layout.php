<?php
/**
 * Shared report template for web and PDF.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ews_render_report_layout' ) ) {
	/**
	 * Render report layout.
	 *
	 * @param array<string, mixed> $report Report.
	 * @param array<string, mixed> $args Render options.
	 * @return string
	 */
	function ews_render_report_layout( array $report, array $args = array() ): string {
		$mode       = isset( $args['mode'] ) ? (string) $args['mode'] : 'web';
		$lang       = isset( $args['lang'] ) ? (string) $args['lang'] : 'en';
		$show_cover = ! isset( $args['show_cover'] ) || (bool) $args['show_cover'];
		$is_pdf     = ( 'pdf' === $mode );
		$t          = EWS_Report_I18n::get( $lang );
		$design     = EWS_Report_Design::get_settings();
		$design_font = sanitize_text_field( (string) ( $design['font'] ?? 'Inter' ) );
		$palette    = EWS_Report_Design::chart_palette( $design );
		$score      = (float) ( $report['score']['health_score'] ?? 0 );
		$status     = (string) ( $report['score']['status'] ?? '' );
		$components = (array) ( $report['score']['components'] ?? array() );
		$banner     = esc_url_raw( (string) ( $design['header_banner'] ?? '' ) );
		$banner_src = $banner;
		if ( $is_pdf && ! empty( $report['pdf_header_banner_data_uri'] ) ) {
			$banner_src = (string) $report['pdf_header_banner_data_uri'];
		}
		$primary    = sanitize_hex_color( (string) ( $design['primary_color'] ?? '#0a84ff' ) ) ?: '#0a84ff';
		$secondary  = sanitize_hex_color( (string) ( $design['secondary_color'] ?? '#1d1d1f' ) ) ?: '#1d1d1f';
		$intent     = (array) ( $report['site_intent'] ?? array() );
		$screenshot = '';
		if ( ! empty( $report['custom_website_image'] ) ) {
			$custom_img = (string) $report['custom_website_image'];
			// Data URIs aren't "URLs" for esc_url/esc_url_raw, but are safe to embed.
			if ( 0 === strpos( $custom_img, 'data:' ) ) {
				$screenshot = $custom_img;
			} else {
				$screenshot = esc_url_raw( $custom_img );
			}
		}
		if ( '' === $screenshot ) {
			$screenshot = ews_report_website_screenshot_url( (string) ( $report['url'] ?? '' ) );
		}

		ob_start();
		?>
		<style>
			<?php if ( $is_pdf ) : ?>
			@page { margin: 50mm 10mm 10mm 30mm; }
			<?php endif; ?>
			.ews-report {
				--ews-primary: <?php echo esc_html( $primary ); ?>;
				--ews-ink: <?php echo esc_html( $secondary ); ?>;
				--ews-subtle: #6e6e73;
				--ews-border: #e5e5ea;
				font-family: <?php echo $is_pdf ? esc_html( $design_font ) . ', Arial, sans-serif' : '-apple-system, BlinkMacSystemFont, "SF Pro Display", "SF Pro Text", "Helvetica Neue", Arial, sans-serif'; ?>;
				color: var(--ews-ink);
				font-size: 13px;
				line-height: 1.45;
				background: #fff;
				max-width: <?php echo $is_pdf ? 'none' : '1200px'; ?>;
				margin: <?php echo $is_pdf ? '0' : '0 auto'; ?>;
				padding: <?php echo $is_pdf ? '0' : '24px'; ?>;
			}
			<?php if ( $is_pdf ) : ?>
			.ews-report, .ews-report * { font-family: <?php echo esc_html( $design_font ) . ', Arial, sans-serif'; ?> !important; }
			.ews-score-page { grid-template-columns: 1fr 1fr; }
			.ews-score-big { font-size: 58px; }
			.ews-shot { min-height: 190px; }
			.ews-charts { display:block; }
			.ews-chart-card { margin: 0 0 12px; page-break-inside: avoid; break-inside: avoid; }
			.ews-chart-card svg { display:block; width:100% !important; height:auto !important; max-height:320px !important; }
			.ews-chart-card img { display:block; width:100% !important; height:auto !important; max-height:320px !important; object-fit: contain; }
			<?php endif; ?>
			.ews-pdf-header {
				<?php echo ( $is_pdf && '' !== $banner ) ? 'position: fixed; top: -50mm; left: -30mm; right: -10mm; height: 80px; z-index: 100;' : 'display:none;'; ?>
			}
			.ews-pdf-header img { width: 100%; height: 80px; object-fit: cover; display: block; }
			.ews-web-banner { margin: 0 0 18px; border-radius: 12px; overflow: hidden; }
			.ews-web-banner img { width: 100%; height: 80px; object-fit: cover; display: block; }
			.ews-section {
				background: linear-gradient(180deg, #ffffff 0%, #fafafc 100%);
				border: 1px solid var(--ews-border);
				border-radius: 18px;
				padding: 18px;
				margin-bottom: 16px;
				box-shadow: 0 8px 24px rgba(16, 24, 40, 0.05);
			}
			.ews-section h2 { margin: 0 0 10px; font-size: 22px; line-height: 1.1; letter-spacing: -0.02em; font-weight: 700; }
			.ews-subtle { color: var(--ews-subtle); }
			.ews-score-page { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 16px; }
			.ews-score-big { font-size: 76px; line-height: .92; font-weight: 700; letter-spacing: -0.03em; color: var(--ews-primary); margin: 4px 0; }
			.ews-shot { border-radius: 16px; overflow: hidden; border: 1px solid var(--ews-border); background: #f2f2f7; min-height: 260px; }
			.ews-shot img { width: 100%; height: 100%; object-fit: cover; display: block; }
			.ews-kpi-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
			.ews-kpi { background: #fff; border: 1px solid var(--ews-border); border-radius: 12px; padding: 10px; }
			.ews-kpi strong { display: block; font-size: 12px; color: var(--ews-subtle); }
			.ews-kpi span { font-size: 20px; font-weight: 700; letter-spacing: -0.01em; }
			.ews-charts { display: grid; grid-template-columns: 1fr; gap: 14px; }
			.ews-chart-card { background:#fff; border:1px solid var(--ews-border); border-radius: 14px; padding: 14px; }
			.ews-chart-card h3 { margin: 0 0 8px; font-size: 16px; font-weight: 700; letter-spacing: -0.01em; }
			.ews-chart-card svg, .ews-chart-card img { display: block; width: 100%; height: <?php echo $is_pdf ? 'auto' : '500px'; ?>; max-height: <?php echo $is_pdf ? '320px' : 'none'; ?>; }
			.ews-chart-note { margin: 8px 0 0; color: var(--ews-subtle); font-size: 12px; }
			.ews-list { margin: 0; padding-left: 18px; }
			.ews-list li { margin: 0 0 6px; }
			.ews-intent-badge { display: inline-block; padding: 5px 10px; border-radius: 999px; background: #eef5ff; color: #154ea8; font-size: 12px; font-weight: 700; margin-right: 6px; margin-bottom: 6px; }
			.ews-compare-table, .ews-table { width: 100%; border-collapse: collapse; }
			.ews-compare-table th, .ews-compare-table td, .ews-table th, .ews-table td { border: 1px solid var(--ews-border); padding: 8px; text-align: left; }
			.report-cta { background:#f5f7fa; border-left:4px solid var(--ews-primary); padding:14px 16px; font-size:12px; line-height:1.55; border-radius:10px; }
			.ews-pdf-footer { <?php echo $is_pdf ? 'position: fixed; bottom: 10px; left: 0; right: 0;' : 'display:none;'; ?> text-align:center; font-size:10px; color:#8e8e93; }
			@media (max-width: 900px) {
				.ews-report { padding: 16px; }
				.ews-score-page { grid-template-columns: 1fr; }
				.ews-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
			}
		</style>

		<div class="ews-report">
			<div class="ews-pdf-header"><img src="<?php echo $is_pdf ? esc_attr( (string) $banner_src ) : esc_url( (string) $banner_src ); ?>" alt="" /></div>
			<?php if ( ! $is_pdf && '' !== $banner ) : ?>
				<div class="ews-web-banner"><img src="<?php echo esc_url( $banner ); ?>" alt="" /></div>
			<?php endif; ?>

			<?php if ( $show_cover ) : ?>
				<div class="ews-section"><h2><?php echo esc_html( EWS_Report_I18n::t( $t, 'website_intelligence_report' ) ); ?></h2></div>
			<?php endif; ?>

			<div class="ews-section ews-score-page">
				<div>
					<h2><?php echo esc_html( EWS_Report_I18n::t( $t, 'website_health_score' ) ); ?></h2>
					<div class="ews-score-big"><?php echo esc_html( number_format_i18n( $score, 2 ) ); ?> / 10</div>
					<p class="ews-subtle"><strong><?php echo esc_html( EWS_Report_I18n::t( $t, 'status' ) ); ?>:</strong> <?php echo esc_html( $status ); ?></p>
					<?php echo wp_kses_post( ews_report_executive_copy( $report, $lang ) ); ?>
				</div>
				<div class="ews-shot"><?php if ( '' !== $screenshot ) : ?><img src="<?php echo ( $is_pdf || 0 === strpos( (string) $screenshot, 'data:' ) ) ? esc_attr( (string) $screenshot ) : esc_url( $screenshot ); ?>" alt="Website screenshot" /><?php endif; ?></div>
			</div>

			<div class="ews-section">
				<h2><?php echo esc_html( ( 'es' === $lang ) ? 'Diagnóstico de propósito del sitio' : 'Site Purpose Diagnosis' ); ?></h2>
				<?php echo ews_render_intent_section( $intent, $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="ews-section">
				<h2><?php echo esc_html( EWS_Report_I18n::t( $t, 'visual_dashboard' ) ); ?></h2>
				<div class="ews-charts">
					<div class="ews-chart-card"><h3><?php echo esc_html( EWS_Report_I18n::t( $t, 'score_gauge' ) ); ?></h3><?php echo ews_render_chart_for_mode( 'gauge', array( 'score' => $score, 'palette' => $palette ), $is_pdf, $lang, $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><p class="ews-chart-note"><?php echo esc_html( EWS_Report_I18n::t( $t, 'score_gauge_explainer' ) ); ?></p></div>
					<div class="ews-chart-card"><h3><?php echo esc_html( EWS_Report_I18n::t( $t, 'priority_radar' ) ); ?></h3><?php echo ews_render_chart_for_mode( 'radar', array( 'components' => $components, 'palette' => $palette ), $is_pdf, $lang, $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><p class="ews-chart-note"><?php echo esc_html( EWS_Report_I18n::t( $t, 'priority_radar_explainer' ) ); ?></p></div>
					<div class="ews-chart-card"><h3><?php echo esc_html( EWS_Report_I18n::t( $t, 'competitor_snapshot' ) ); ?></h3><?php echo ews_render_chart_for_mode( 'competitors', array( 'palette' => $palette ), $is_pdf, $lang, $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><p class="ews-chart-note"><?php echo esc_html( EWS_Report_I18n::t( $t, 'competitor_snapshot_explainer' ) ); ?></p></div>
					<div class="ews-chart-card"><h3><?php echo esc_html( EWS_Report_I18n::t( $t, 'crawler_map' ) ); ?></h3><?php echo ews_render_chart_for_mode( 'crawler', array( 'palette' => $palette, 'tree' => (array) ( $report['crawler']['tree_map'] ?? array() ) ), $is_pdf, $lang, $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><p class="ews-chart-note"><?php echo esc_html( EWS_Report_I18n::t( $t, 'crawler_map_explainer' ) ); ?></p></div>
				</div>
			</div>

			<div class="ews-section"><h2><?php echo esc_html( ( 'es' === $lang ) ? 'Hallazgos Principales' : 'Key Findings' ); ?></h2><div class="ews-kpi-grid"><div class="ews-kpi"><strong><?php echo esc_html( EWS_Report_I18n::t( $t, 'performance' ) ); ?></strong><span><?php echo esc_html( (string) ( $components['performance'] ?? 'N/A' ) ); ?></span></div><div class="ews-kpi"><strong><?php echo esc_html( EWS_Report_I18n::t( $t, 'seo' ) ); ?></strong><span><?php echo esc_html( (string) ( $components['seo_technical'] ?? 'N/A' ) ); ?></span></div><div class="ews-kpi"><strong><?php echo esc_html( EWS_Report_I18n::t( $t, 'accessibility' ) ); ?></strong><span><?php echo esc_html( (string) ( $components['accessibility'] ?? 'N/A' ) ); ?></span></div><div class="ews-kpi"><strong><?php echo esc_html( EWS_Report_I18n::t( $t, 'infrastructure' ) ); ?></strong><span><?php echo esc_html( (string) ( $components['infrastructure'] ?? 'N/A' ) ); ?></span></div><div class="ews-kpi"><strong><?php echo esc_html( ( 'es' === $lang ) ? 'Conversión' : 'Conversion' ); ?></strong><span><?php echo esc_html( (string) ( $components['conversion_signals'] ?? 'N/A' ) ); ?></span></div></div></div>

			<div class="ews-section"><h2><?php echo esc_html( EWS_Report_I18n::t( $t, 'strengths' ) ); ?></h2><ul class="ews-list"><?php foreach ( (array) ( $report['summary']['strengths'] ?? array() ) as $item ) : ?><li><?php echo esc_html( ews_localize_line( (string) $item, $lang ) ); ?></li><?php endforeach; ?></ul></div>
			<div class="ews-section"><h2><?php echo esc_html( EWS_Report_I18n::t( $t, 'improvement_opportunities' ) ); ?></h2><ul class="ews-list"><?php foreach ( (array) ( $report['summary']['opportunities'] ?? array() ) as $item ) : ?><li><?php echo esc_html( ews_localize_line( (string) $item, $lang ) ); ?></li><?php endforeach; ?></ul></div>
			<div class="ews-section"><h2><?php echo esc_html( EWS_Report_I18n::t( $t, 'quick_wins' ) ); ?></h2><ul class="ews-list"><?php foreach ( (array) ( $report['value_analysis']['quick_wins'] ?? array() ) as $item ) : ?><li><?php echo esc_html( ews_localize_line( (string) $item, $lang ) ); ?></li><?php endforeach; ?></ul></div>
			<div class="ews-section"><h2><?php echo esc_html( EWS_Report_I18n::t( $t, 'strategic_recommendations' ) ); ?></h2><ul class="ews-list"><?php foreach ( (array) ( $report['recommendations'] ?? array() ) as $item ) : ?><li><?php echo esc_html( ews_localize_line( (string) $item, $lang ) ); ?></li><?php endforeach; ?></ul></div>
			<div class="ews-section"><h2><?php echo esc_html( EWS_Report_I18n::t( $t, 'ai_strategic_analysis' ) ); ?></h2><?php echo ews_render_strategic_analysis( $report, $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="ews-section"><h2><?php echo esc_html( ( 'es' === $lang ) ? 'Análisis de Diseño y Marketing' : 'Design & Marketing Assessment' ); ?></h2><?php echo ews_render_design_marketing_analysis( $report, $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="ews-section"><h2><?php echo esc_html( ( 'es' === $lang ) ? 'Comparación con sitios de la competencia' : 'Competitor Comparison' ); ?></h2><?php echo ews_render_competitor_comparison( $report, $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="ews-section"><h2><?php echo esc_html( ( 'es' === $lang ) ? 'Glosario técnico' : 'Technical Glossary' ); ?></h2><?php echo ews_render_technical_glossary( $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="ews-section"><?php echo ews_report_render_cta_block( $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>

			<?php if ( ! $is_pdf && ! empty( $report['report_id'] ) ) : ?>
				<?php
				$base = admin_url( 'admin-post.php?action=ews_download_report&report_id=' . rawurlencode( (string) $report['report_id'] ) );
				$en   = wp_nonce_url( add_query_arg( 'lang', 'en', $base ), 'ews_download_report_' . (string) $report['report_id'] );
				$es   = wp_nonce_url( add_query_arg( 'lang', 'es', $base ), 'ews_download_report_' . (string) $report['report_id'] );
				?>
				<div class="ews-section"><h2><?php echo esc_html( EWS_Report_I18n::t( $t, 'download_report' ) ); ?></h2><p><a class="button" href="<?php echo esc_url( $en ); ?>"><?php echo esc_html( EWS_Report_I18n::t( $t, 'english' ) ); ?></a> <a class="button" href="<?php echo esc_url( $es ); ?>"><?php echo esc_html( EWS_Report_I18n::t( $t, 'spanish' ) ); ?></a></p></div>
			<?php endif; ?>

			<div class="ews-pdf-footer">Report generated with Estandar Website Intelligence Scanner<br />www.estandar.cl</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'ews_render_intent_section' ) ) {
	function ews_render_intent_section( array $intent, string $lang ): string {
		if ( empty( $intent ) ) {
			return '<p>' . esc_html( ( 'es' === $lang ) ? 'No hay datos suficientes para inferir el propósito del sitio.' : 'Insufficient data to infer site purpose.' ) . '</p>';
		}
		$detected = (string) ( $intent['detected_intent'] ?? '' );
		$expected = (string) ( $intent['expected_intent'] ?? '' );
		$score    = (float) ( $intent['alignment_score'] ?? 0 );
		$impact   = (string) ( $intent['impact'] ?? '' );
		$missing  = (array) ( $intent['missing_signals'] ?? array() );
		$reco     = (string) ( $intent['strategic_recommendation'] ?? '' );

		$out  = '<p><span class="ews-intent-badge">' . esc_html( ( 'es' === $lang ) ? 'Objetivo detectado: ' : 'Detected goal: ' ) . esc_html( ews_humanize_intent( $detected, $lang ) ) . '</span>';
		$out .= '<span class="ews-intent-badge">' . esc_html( ( 'es' === $lang ) ? 'Objetivo esperado: ' : 'Expected goal: ' ) . esc_html( ews_humanize_intent( $expected, $lang ) ) . '</span>';
		$out .= '<span class="ews-intent-badge">' . esc_html( ( 'es' === $lang ) ? 'Alineación: ' : 'Alignment: ' ) . esc_html( number_format_i18n( $score, 1 ) . '%' ) . '</span></p>';
		if ( '' !== $impact ) {
			$out .= '<p class="ews-subtle">' . esc_html( $impact ) . '</p>';
		}
		if ( ! empty( $missing ) ) {
			$out .= '<ul class="ews-list">';
			foreach ( array_slice( $missing, 0, 5 ) as $line ) {
				$out .= '<li>' . esc_html( (string) $line ) . '</li>';
			}
			$out .= '</ul>';
		}
		if ( '' !== $reco ) {
			$out .= '<p><strong>' . esc_html( ( 'es' === $lang ) ? 'Recomendación estratégica:' : 'Strategic recommendation:' ) . '</strong> ' . esc_html( $reco ) . '</p>';
		}
		return $out;
	}
}

if ( ! function_exists( 'ews_report_chart_markup' ) ) {
	/**
	 * Render chart markup for web/pdf.
	 *
	 * @param string $svg SVG markup.
	 * @param bool   $is_pdf PDF mode.
	 * @param string $cache_key Conversion cache hint.
	 * @return string
	 */
	function ews_report_chart_markup( string $svg, bool $is_pdf, string $cache_key = '' ): string {
		if ( ! $is_pdf ) {
			return $svg;
		}
		$png_data_uri = ews_convert_svg_to_png_data_uri( $svg, $cache_key );
		if ( '' !== $png_data_uri ) {
			return '<img src="' . esc_attr( $png_data_uri ) . '" alt="chart" />';
		}
		$encoded = base64_encode( $svg );
		return '<img src="data:image/svg+xml;base64,' . esc_attr( $encoded ) . '" alt="chart" />';
	}
}

if ( ! function_exists( 'ews_pdf_can_render_svg_charts' ) ) {
	/**
	 * Determine if PDF runtime can safely convert SVG charts to PNG.
	 *
	 * @return bool
	 */
	function ews_pdf_can_render_svg_charts(): bool {
		return class_exists( '\\Imagick' );
	}
}

if ( ! function_exists( 'ews_render_chart_for_mode' ) ) {
	/**
	 * Render charts for web or PDF with safe fallback path.
	 *
	 * @param string               $chart_type Chart type.
	 * @param array<string, mixed> $data Chart data.
	 * @param bool                 $is_pdf PDF mode.
	 * @param string               $lang Language code.
	 * @param array<string, mixed> $report Report payload.
	 * @return string
	 */
	function ews_render_chart_for_mode( string $chart_type, array $data, bool $is_pdf, string $lang, array $report ): string {
		$palette    = (array) ( $data['palette'] ?? array() );
		$components = (array) ( $data['components'] ?? array() );
		$tree       = (array) ( $data['tree'] ?? array() );
		$score      = (float) ( $data['score'] ?? 0 );

		if ( ! $is_pdf ) {
			if ( 'gauge' === $chart_type ) {
				return ews_report_svg_gauge( $score, $palette );
			}
			if ( 'radar' === $chart_type ) {
				return ews_report_svg_radar( $components, $palette, $lang );
			}
			if ( 'competitors' === $chart_type ) {
				return ews_report_svg_competitors( $report, $palette, $lang );
			}
			return ews_report_svg_crawler( $tree, $palette, $lang );
		}

		// DOMPDF without Imagick distorts SVG data-URI charts; use stable HTML fallback.
		if ( ! ews_pdf_can_render_svg_charts() ) {
			if ( 'gauge' === $chart_type ) {
				return ews_pdf_chart_gauge( $score, $palette );
			}
			if ( 'radar' === $chart_type ) {
				return ews_pdf_chart_radar( $components, $palette, $lang );
			}
			if ( 'competitors' === $chart_type ) {
				return ews_pdf_chart_competitors( $report, $palette, $lang );
			}
			return ews_pdf_chart_crawler( $tree, $palette, $lang );
		}

		if ( 'gauge' === $chart_type ) {
			return ews_report_chart_markup( ews_report_svg_gauge( $score, $palette ), true, 'score-gauge' );
		}
		if ( 'radar' === $chart_type ) {
			return ews_report_chart_markup( ews_report_svg_radar( $components, $palette, $lang ), true, 'priority-radar' );
		}
		if ( 'competitors' === $chart_type ) {
			return ews_report_chart_markup( ews_report_svg_competitors( $report, $palette, $lang ), true, 'competitor-snapshot' );
		}
		return ews_report_chart_markup( ews_report_svg_crawler( $tree, $palette, $lang ), true, 'crawler-map' );
	}
}

if ( ! function_exists( 'ews_convert_svg_to_png_data_uri' ) ) {
	/**
	 * Convert SVG markup into PNG data URI for DOMPDF.
	 *
	 * @param string $svg SVG string.
	 * @param string $cache_key Cache hint.
	 * @return string
	 */
	function ews_convert_svg_to_png_data_uri( string $svg, string $cache_key = '' ): string {
		if ( '' === trim( $svg ) || ! class_exists( '\\Imagick' ) ) {
			return '';
		}
		static $cache = array();
		$key = md5( $cache_key . '|' . $svg );
		if ( isset( $cache[ $key ] ) ) {
			return (string) $cache[ $key ];
		}
		try {
			$imagick = new \Imagick();
			$imagick->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
			$imagick->readImageBlob( $svg );
			$imagick->setImageFormat( 'png32' );
			$png_blob = $imagick->getImageBlob();
			$imagick->clear();
			$imagick->destroy();
			$data_uri      = 'data:image/png;base64,' . base64_encode( $png_blob );
			$cache[ $key ] = $data_uri;
			return $data_uri;
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}

if ( ! function_exists( 'ews_pdf_chart_gauge' ) ) {
	/**
	 * PDF-safe gauge fallback.
	 *
	 * @param float              $score Score 0-10.
	 * @param array<int, string> $palette Colors.
	 * @return string
	 */
	function ews_pdf_chart_gauge( float $score, array $palette ): string {
		$score = max( 0, min( 10, $score ) );
		$width = round( $score * 10, 2 );
		$color = esc_attr( $palette[0] ?? '#0a84ff' );
		return '<div style="padding:20px 8px;"><div style="font-size:42px;font-weight:700;color:#1d1d1f;text-align:center;">' . esc_html( number_format( $score, 2 ) ) . ' / 10</div><div style="height:22px;border-radius:999px;background:#e5e7eb;overflow:hidden;margin:16px 24px;"><div style="height:22px;width:' . esc_attr( (string) $width ) . '%;background:' . $color . ';"></div></div></div>';
	}
}

if ( ! function_exists( 'ews_pdf_chart_radar' ) ) {
	/**
	 * PDF-safe radar fallback as bars.
	 *
	 * @param array<string, mixed> $components Components.
	 * @param array<int, string>   $palette Colors.
	 * @param string               $lang Lang code.
	 * @return string
	 */
	function ews_pdf_chart_radar( array $components, array $palette, string $lang ): string {
		$labels = ( 'es' === $lang )
			? array( 'Rendimiento' => 'performance', 'SEO' => 'seo_technical', 'Accesibilidad' => 'accessibility', 'Conversión' => 'conversion_signals', 'Estructura' => 'crawler_structure' )
			: array( 'Performance' => 'performance', 'SEO' => 'seo_technical', 'Accessibility' => 'accessibility', 'Conversion' => 'conversion_signals', 'Structure' => 'crawler_structure' );
		$color = esc_attr( $palette[1] ?? '#30b0c7' );
		$html = '<div style="padding:8px 12px;">';
		foreach ( $labels as $label => $key ) {
			$value = max( 0, min( 100, (float) ( $components[ $key ] ?? 0 ) ) );
			$html .= '<div style="margin:10px 0;"><div style="font-size:14px;color:#1d1d1f;margin-bottom:4px;">' . esc_html( $label ) . ' ' . esc_html( number_format( $value, 1 ) ) . '%</div><div style="height:14px;border-radius:999px;background:#e5e7eb;overflow:hidden;"><div style="height:14px;width:' . esc_attr( (string) round( $value, 2 ) ) . '%;background:' . $color . ';"></div></div></div>';
		}
		$html .= '</div>';
		return $html;
	}
}

if ( ! function_exists( 'ews_pdf_chart_competitors' ) ) {
	/**
	 * PDF-safe competitor chart fallback.
	 *
	 * @param array<string, mixed> $report Report.
	 * @param array<int, string>   $palette Colors.
	 * @param string               $lang Lang.
	 * @return string
	 */
	function ews_pdf_chart_competitors( array $report, array $palette, string $lang ): string {
		$rows = (array) ( $report['competitor_snapshot']['competitors'] ?? array() );
		$site_domain = (string) wp_parse_url( (string) ( $report['url'] ?? '' ), PHP_URL_HOST );
		$site_score  = (float) ( $report['score']['health_score'] ?? 0 );
		if ( empty( $rows ) ) {
			return '<p>' . esc_html( ( 'es' === $lang ) ? 'No se proporcionaron competidores para benchmarking.' : 'No competitors were provided for benchmarking.' ) . '</p>';
		}
		$data = array( array( 'domain' => $site_domain, 'score' => $site_score ) );
		foreach ( array_slice( $rows, 0, 3 ) as $row ) {
			$data[] = array( 'domain' => (string) ( $row['domain'] ?? 'n/a' ), 'score' => (float) ( $row['health_score'] ?? 0 ) );
		}
		$main = esc_attr( $palette[2] ?? '#ff9f0a' );
		$html = '<div style="padding:8px 12px;">';
		foreach ( $data as $idx => $row ) {
			$value = max( 0, min( 10, (float) $row['score'] ) );
			$width = round( $value * 10, 2 );
			$color = 0 === $idx ? $main : '#cbd5e1';
			$html .= '<div style="margin:10px 0;"><div style="font-size:14px;color:#1d1d1f;margin-bottom:4px;">' . esc_html( (string) $row['domain'] ) . ' ' . esc_html( number_format( $value, 2 ) ) . '</div><div style="height:14px;border-radius:999px;background:#e5e7eb;overflow:hidden;"><div style="height:14px;width:' . esc_attr( (string) $width ) . '%;background:' . $color . ';"></div></div></div>';
		}
		$html .= '</div>';
		return $html;
	}
}

if ( ! function_exists( 'ews_pdf_chart_crawler' ) ) {
	/**
	 * PDF-safe crawler chart fallback.
	 *
	 * @param array<string, mixed> $tree Tree.
	 * @param array<int, string>   $palette Colors.
	 * @param string               $lang Lang.
	 * @return string
	 */
	function ews_pdf_chart_crawler( array $tree, array $palette, string $lang ): string {
		if ( empty( $tree['url'] ) ) {
			return '<p>' . esc_html( ( 'es' === $lang ) ? 'Sin datos' : 'No data' ) . '</p>';
		}
		$counts = array( 0, 0, 0, 0 );
		$queue = array( array( 'node' => $tree, 'depth' => 0 ) );
		while ( ! empty( $queue ) ) {
			$item = array_shift( $queue );
			$depth = (int) ( $item['depth'] ?? 0 );
			if ( $depth > 3 ) {
				continue;
			}
			$counts[ $depth ]++;
			$children = (array) ( $item['node']['children'] ?? array() );
			foreach ( array_slice( $children, 0, 12 ) as $child ) {
				$queue[] = array( 'node' => $child, 'depth' => $depth + 1 );
			}
		}
		$total = max( 1, array_sum( $counts ) );
		$labels = ( 'es' === $lang ) ? array( 'Nivel 1', 'Nivel 2', 'Nivel 3', 'Nivel 4' ) : array( 'Level 1', 'Level 2', 'Level 3', 'Level 4' );
		$color = esc_attr( $palette[3] ?? '#32d74b' );
		$html = '<div style="padding:8px 12px;">';
		foreach ( $counts as $idx => $count ) {
			$width = round( ( $count / $total ) * 100, 2 );
			$html .= '<div style="margin:10px 0;"><div style="font-size:14px;color:#1d1d1f;margin-bottom:4px;">' . esc_html( $labels[ $idx ] . ': ' . $count . ' páginas' ) . '</div><div style="height:14px;border-radius:999px;background:#e5e7eb;overflow:hidden;"><div style="height:14px;width:' . esc_attr( (string) $width ) . '%;background:' . $color . ';"></div></div></div>';
		}
		$html .= '</div>';
		return $html;
	}
}

if ( ! function_exists( 'ews_humanize_intent' ) ) {
	function ews_humanize_intent( string $intent, string $lang ): string {
		$map_es = array(
			'lead_generation' => 'Generación de leads',
			'ecommerce' => 'Comercio electrónico',
			'service_booking' => 'Reserva de servicios',
			'content_marketing' => 'Marketing de contenidos',
			'brand_presence' => 'Presencia de marca',
		);
		$map_en = array(
			'lead_generation' => 'Lead generation',
			'ecommerce' => 'Ecommerce',
			'service_booking' => 'Service booking',
			'content_marketing' => 'Content marketing',
			'brand_presence' => 'Brand presence',
		);
		$map = ( 'es' === $lang ) ? $map_es : $map_en;
		return $map[ $intent ] ?? $intent;
	}
}

if ( ! function_exists( 'ews_render_strategic_analysis' ) ) {
	function ews_render_strategic_analysis( array $report, string $lang ): string {
		$ai      = (array) ( $report['ai_analysis'] ?? array() );
		$content = '';
		if ( ! empty( $ai['available'] ) ) {
			$content = ews_clean_ai_text( (string) ( $ai['content'] ?? '' ) );
		}
		if ( '' === trim( $content ) ) {
			$content = ( 'es' === $lang )
				? "Resumen Ejecutivo\nEl sitio presenta una base técnica sólida, con oportunidades claras en conversión y estructura SEO.\n\nPropósito del Sitio y su Alineación\nEl objetivo principal detectado es la captación de leads. La implementación actual tiene fricción en la etapa de calificación.\n\nPrioridades Principales\n1. Mejorar rendimiento móvil y tiempos de carga críticos.\n2. Corregir fundamentos SEO (H1, meta description, enlazado interno).\n3. Implementar un mecanismo de captación inteligente (formulario guiado o chatbot).\n\nMejoras Técnicas\nReducir recursos bloqueantes, optimizar imágenes y estabilizar layout para mejorar experiencia y rendimiento.\n\nMejoras SEO\nAlinear títulos, H1 y meta descriptions con intención de búsqueda y páginas de servicio.\n\nConclusión\nEn conclusión, el sitio cuenta con una base técnica sólida pero debe abordar con prioridad la accesibilidad, la optimización SEO fundamental y rediseñar su flujo de captación para alinear la estrategia con el objetivo comercial, impulsando así un crecimiento tangible en leads cualificados y en resultados de negocio."
				: "Executive Summary\nThe site has a solid technical base, with clear opportunities in conversion and SEO structure.\n\nSite Purpose and Alignment\nDetected purpose is lead generation. Current implementation adds friction in lead qualification.\n\nTop Priorities\n1. Improve mobile performance and critical load times.\n2. Fix foundational SEO (H1, meta description, internal linking).\n3. Implement smart lead capture (guided form or chatbot).\n\nTechnical Improvements\nReduce render-blocking resources, optimize images and stabilize layout for better UX and speed.\n\nSEO Improvements\nAlign titles, H1 and meta descriptions with search intent and service pages.\n\nConclusion\nIn conclusion, the site has a strong technical base but must prioritize accessibility, foundational SEO optimization and a redesigned lead-capture flow to align strategy with business goals and drive measurable growth in qualified leads.";
		}
		$section_titles = ( 'es' === $lang )
			? array( 'Resumen Ejecutivo', 'Propósito del Sitio y Alineación', 'Propósito del Sitio y su Alineación', 'Prioridades Principales', 'Mejoras Técnicas', 'Mejoras SEO', 'Oportunidades de Conversión', 'Conclusión' )
			: array( 'Executive Summary', 'Site Purpose and Alignment', 'Top Priorities', 'Technical Improvements', 'SEO Improvements', 'Conversion Opportunities', 'Conclusion' );
		$section_map = ews_parse_ai_sections( $content, $section_titles, $lang );

		$out = '';
		$has_conclusion = false;
		$order = 1;
		foreach ( $section_titles as $title ) {
			$body = trim( (string) ( $section_map[ $title ] ?? '' ) );
			if ( '' === $body ) {
				continue;
			}
			if ( 'es' === $lang ) {
				$body = ews_localize_line( $body, $lang );
			}
			if ( ( 'Conclusión' === $title && 'es' === $lang ) || ( 'Conclusion' === $title && 'en' === $lang ) ) {
				$has_conclusion = true;
				$out .= '<div style="margin-top:14px; padding:12px 14px; border-radius:10px; background:#eef5ff; border:1px solid #cfe2ff;"><div style="font-size:16px; font-weight:700; margin:0 0 6px;">' . esc_html( (string) $order . '. ' . $title ) . ':</div><p style="margin:0; font-size:14px; font-weight:700; line-height:1.55;">' . esc_html( $body ) . '</p></div>';
				$order++;
				continue;
			}
			if ( ( 'Prioridades Principales' === $title && 'es' === $lang ) || ( 'Top Priorities' === $title && 'en' === $lang ) ) {
				$parts = ews_extract_priority_items( $body );
				$out .= '<div style="margin-bottom:10px;"><div style="font-size:16px; font-weight:700; margin:0 0 6px;">' . esc_html( (string) $order . '. ' . $title ) . ':</div><ol style="margin:0 0 0 18px; padding:0;">';
				foreach ( array_slice( $parts, 0, 5 ) as $part ) {
					$out .= '<li style="margin:0 0 6px;">' . esc_html( $part ) . '</li>';
				}
				$out .= '</ol></div>';
				$order++;
				continue;
			}
			$out .= '<div style="margin-bottom:10px;"><div style="font-size:16px; font-weight:700; margin:0 0 4px;">' . esc_html( (string) $order . '. ' . $title ) . ':</div><p style="margin:0; line-height:1.55;">' . esc_html( $body ) . '</p></div>';
			$order++;
		}
		if ( ! $has_conclusion ) {
			$fallback_conclusion = ( 'es' === $lang )
				? 'En conclusión, el sitio cuenta con una base técnica sólida, pero debe priorizar accesibilidad, SEO fundamental y un flujo de captación inteligente para aumentar leads calificados y resultados de negocio.'
				: 'In conclusion, the site has a solid technical base, but it must prioritize accessibility, foundational SEO and an intelligent acquisition flow to increase qualified leads and business outcomes.';
			$out .= '<div style="margin-top:14px; padding:12px 14px; border-radius:10px; background:#eef5ff; border:1px solid #cfe2ff;"><div style="font-size:16px; font-weight:700; margin:0 0 6px;">' . esc_html( (string) $order . '. ' . ( ( 'es' === $lang ) ? 'Conclusión' : 'Conclusion' ) ) . ':</div><p style="margin:0; font-size:14px; font-weight:700; line-height:1.5;">' . esc_html( $fallback_conclusion ) . '</p></div>';
		}
		return $out;
	}
}

if ( ! function_exists( 'ews_render_design_marketing_analysis' ) ) {
	/**
	 * Render the approved Design & Marketing narrative.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @param string               $lang Language code.
	 * @return string
	 */
	function ews_render_design_marketing_analysis( array $report, string $lang ): string {
		$approved = (string) ( $report['design_marketing_analysis_approved'] ?? '' );
		$status   = (string) ( $report['design_marketing_analysis_status'] ?? '' );

		if ( '' === trim( $approved ) ) {
			if ( 'pending' === $status ) {
				return '<p>' . esc_html( ( 'es' === $lang ) ? 'Pendiente de aprobación.' : 'Pending approval.' ) . '</p>';
			}
			return '<p>' . esc_html( ( 'es' === $lang ) ? 'Análisis de diseño y marketing no disponible.' : 'Design & marketing analysis unavailable.' ) . '</p>';
		}

		$escaped = esc_html( $approved );
		$with_br = nl2br( $escaped );
		return '<div style="line-height:1.55; white-space:normal;">' . wp_kses_post( $with_br ) . '</div>';
	}
}

if ( ! function_exists( 'ews_parse_ai_sections' ) ) {
	/**
	 * Parse AI narrative into known sections.
	 *
	 * @param string              $content Raw AI content.
	 * @param array<int, string>  $section_titles Titles in desired order.
	 * @param string              $lang Language code.
	 * @return array<string, string>
	 */
	function ews_parse_ai_sections( string $content, array $section_titles, string $lang ): array {
		$normalized = str_replace( array( "\r\n", "\r", "\t" ), array( "\n", "\n", ' ' ), $content );
		$normalized = preg_replace( '/\s{2,}/', ' ', (string) $normalized );
		$heading_regex = ( 'es' === $lang )
			? '/(?:^|\n|\. )\s*(?:\d+\s*[\)\.\-:]?\s*)?(Resumen Ejecutivo|Propósito del Sitio y Alineación|Propósito del Sitio y su Alineación|Prioridades Principales|Mejoras Técnicas|Mejoras SEO|Oportunidades de Conversión|Conclusión)\s*:?\s*/iu'
			: '/(?:^|\n|\. )\s*(?:\d+\s*[\)\.\-:]?\s*)?(Executive Summary|Site Purpose and Alignment|Top Priorities|Technical Improvements|SEO Improvements|Conversion Opportunities|Conclusion)\s*:?\s*/iu';
		$parts = preg_split( $heading_regex, (string) $normalized, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$map   = array_fill_keys( $section_titles, '' );
		if ( false === $parts || empty( $parts ) ) {
			$map[ $section_titles[0] ] = trim( (string) $normalized );
			return $map;
		}
		$current = $section_titles[0];
		foreach ( $parts as $index => $part ) {
			$piece = trim( (string) $part );
			if ( '' === $piece ) {
				continue;
			}
			$is_heading = false;
			foreach ( $section_titles as $title ) {
				if ( 0 === strcasecmp( $title, $piece ) ) {
					$is_heading = true;
					break;
				}
			}
			if ( $is_heading ) {
				foreach ( $section_titles as $title ) {
					if ( 0 === strcasecmp( $title, $piece ) ) {
						$current = $title;
						break;
					}
				}
				continue;
			}
			$map[ $current ] = trim( $map[ $current ] . ' ' . $piece );
		}
		foreach ( $section_titles as $title ) {
			$pattern       = '/^\s*(?:\d+\s*[\)\.\-:]?\s*)?' . preg_quote( $title, '/' ) . '\s*:?\s*/iu';
			$map[ $title ] = preg_replace( $pattern, '', (string) $map[ $title ] );
			$map[ $title ] = trim( (string) $map[ $title ] );
		}
		return $map;
	}
}

if ( ! function_exists( 'ews_extract_priority_items' ) ) {
	/**
	 * Convert priority text into bullet items.
	 *
	 * @param string $body Priority body.
	 * @return array<int, string>
	 */
	function ews_extract_priority_items( string $body ): array {
		$list = preg_split( '/(?:\s*\d+[\)\.\-:]\s+)|(?:\.\s+)/u', $body );
		$list = array_values(
			array_filter(
				array_map(
					static function ( $item ) {
						$clean = trim( (string) $item );
						$clean = trim( $clean, "- \t\n\r\0\x0B" );
						return $clean;
					},
					is_array( $list ) ? $list : array()
				)
			)
		);
		if ( empty( $list ) ) {
			return array( trim( $body ) );
		}
		return $list;
	}
}

if ( ! function_exists( 'ews_report_render_cta_block' ) ) {
	function ews_report_render_cta_block( string $lang ): string {
		$show = (int) get_option( 'ews_show_cta', 1 );
		if ( 1 !== $show ) {
			return '';
		}
		$cta = (string) get_option( 'ews_report_cta', '' );
		if ( '' === trim( $cta ) ) {
			$cta = EWS_Report_I18n::default_cta_short( $lang );
		}
		$cta = wp_kses_post( $cta );
		$cta = nl2br( $cta );
		$cta = make_clickable( $cta );
		return '<div class="report-cta">' . $cta . '</div>';
	}
}

if ( ! function_exists( 'ews_report_website_screenshot_url' ) ) {
	function ews_report_website_screenshot_url( string $url ): string {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return '';
		}
		return 'https://image.thum.io/get/width/1600/crop/900/' . rawurlencode( $url );
	}
}

if ( ! function_exists( 'ews_clean_ai_text' ) ) {
	function ews_clean_ai_text( string $text ): string {
		$text = preg_replace( '/^\s{0,3}(#+|\-|\*{1,2}|\d+\.)\s?/m', '', $text );
		$text = str_replace( array( '**', '__', '`', '---' ), '', $text );
		$text = preg_replace( '/\b\d+\)\s*(Resumen Ejecutivo|Propósito del Sitio y Alineación|Propósito del Sitio y su Alineación|Prioridades Principales|Mejoras Técnicas|Mejoras SEO|Oportunidades de Conversión|Conclusión|Executive Summary|Site Purpose and Alignment|Top Priorities|Technical Improvements|SEO Improvements|Conversion Opportunities|Conclusion)\b/iu', '$1', (string) $text );
		$text = preg_replace( '/(\d+(?:[.,]\d+)?)\s*\/\s*(?:100)?(?=[\s\.\,\)\]])/u', '$1%', (string) $text );
		$text = preg_replace( '/\s+([,:;\.])/', '$1', (string) $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", (string) $text );
		return trim( (string) $text );
	}
}

if ( ! function_exists( 'ews_localize_line' ) ) {
	function ews_localize_line( string $line, string $lang ): string {
		if ( 'es' !== $lang ) {
			return $line;
		}
		$map = array(
			'Add a clear meta description to improve search snippets.' => 'Agregar una meta descripción clara para mejorar el snippet en buscadores.',
			'Add a primary H1 heading on the homepage.' => 'Agregar un encabezado H1 principal en la página de inicio.',
			'Performance:' => 'Rendimiento:',
			'Priority action:' => 'Acción prioritaria:',
			'needs improvement' => 'requiere mejora',
			'is strong' => 'es un punto fuerte',
			'No major strengths detected yet.' => 'Aún no se detectan fortalezas principales.',
			'No critical issues detected in the scanned modules.' => 'No se detectaron problemas críticos en los módulos evaluados.',
			'Implement JSON-LD structured data' => 'Implementar datos estructurados JSON-LD',
			'Structured Data' => 'Datos estructurados',
			'Keyword Signals' => 'Señales de palabras clave',
			'Include primary keyword phrase in meta description to improve topical relevance.' => 'Incluir la frase clave principal en la meta descripción para mejorar la relevancia temática.',
			'SEO opportunity: 5 crawled pages are missing a primary H1 heading.' => 'Oportunidad SEO: 5 páginas rastreadas no tienen un encabezado H1 principal.',
			'SEO opportunity: 16 pages are missing meta descriptions.' => 'Oportunidad SEO: 16 páginas no tienen meta description.',
			'SEO opportunity: 4 pages appear to have thin content (<200 words).' => 'Oportunidad SEO: 4 páginas presentan contenido insuficiente (<200 palabras).',
			'Strategic alignment:' => 'Alineación estratégica:',
			'Initial server response time was short.' => 'El tiempo inicial de respuesta del servidor fue adecuado.',
			'Avoid multiple page redirects.' => 'Evitar múltiples redirecciones de página.',
			'Minify CSS.' => 'Minificar CSS.',
			'Organization, LocalBusiness or relevant schema types' => 'Organization, LocalBusiness u otros tipos de schema relevantes',
		);
		return strtr( $line, $map );
	}
}

if ( ! function_exists( 'ews_report_executive_copy' ) ) {
	function ews_report_executive_copy( array $report, string $lang ): string {
		$score   = (float) ( $report['score']['health_score'] ?? 0 );
		$status  = (string) ( $report['score']['status'] ?? '' );
		$desktop = (float) ( $report['performance']['desktop']['performance_score'] ?? 0 );
		$mobile  = (float) ( $report['performance']['mobile']['performance_score'] ?? 0 );
		if ( 'es' === $lang ) {
			return '<p>El estado general del sitio es <strong>' . esc_html( strtolower( $status ) ) . '</strong> con un puntaje de <strong>' . esc_html( number_format_i18n( $score, 2 ) ) . '/10</strong>.</p><p class="ews-subtle">Rendimiento desktop: <strong>' . esc_html( number_format_i18n( $desktop, 1 ) ) . '/100</strong>. Rendimiento móvil: <strong>' . esc_html( number_format_i18n( $mobile, 1 ) ) . '/100</strong>.</p>';
		}
		return '<p>Overall site condition is <strong>' . esc_html( strtolower( $status ) ) . '</strong> with a score of <strong>' . esc_html( number_format_i18n( $score, 2 ) ) . '/10</strong>.</p><p class="ews-subtle">Desktop performance: <strong>' . esc_html( number_format_i18n( $desktop, 1 ) ) . '/100</strong>. Mobile performance: <strong>' . esc_html( number_format_i18n( $mobile, 1 ) ) . '/100</strong>.</p>';
	}
}

if ( ! function_exists( 'ews_render_competitor_comparison' ) ) {
	function ews_render_competitor_comparison( array $report, string $lang ): string {
		$rows       = (array) ( $report['competitor_snapshot']['competitors'] ?? array() );
		$site_host  = (string) wp_parse_url( (string) ( $report['url'] ?? '' ), PHP_URL_HOST );
		$site_score = (float) ( $report['score']['health_score'] ?? 0 );
		if ( empty( $rows ) ) {
			return '<p>' . esc_html( ( 'es' === $lang ) ? 'No se proporcionaron competidores para benchmarking.' : 'No competitors were provided for benchmarking.' ) . '</p>';
		}
		$html  = '<table class="ews-compare-table"><thead><tr><th>' . esc_html( ( 'es' === $lang ) ? 'Dominio' : 'Domain' ) . '</th><th>Health Score</th><th>' . esc_html( ( 'es' === $lang ) ? 'Diferencia' : 'Delta' ) . '</th></tr></thead><tbody>';
		$html .= '<tr><td>' . esc_html( $site_host ) . '</td><td>' . esc_html( number_format_i18n( $site_score, 2 ) ) . '</td><td>0%</td></tr>';
		foreach ( $rows as $row ) {
			$competitor_score = (float) ( $row['health_score'] ?? 0 );
			$delta = isset( $row['difference'] ) ? (float) $row['difference'] : ( ( $site_score > 0 ) ? round( ( ( $competitor_score - $site_score ) / $site_score ) * 100, 1 ) : 0 );
			$delta_label = ( $delta > 0 ? '+' : '' ) . $delta . '%';
			$html .= '<tr><td>' . esc_html( (string) ( $row['domain'] ?? '' ) ) . '</td><td>' . esc_html( number_format_i18n( $competitor_score, 2 ) ) . '</td><td>' . esc_html( $delta_label ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		return $html;
	}
}

if ( ! function_exists( 'ews_render_technical_glossary' ) ) {
	function ews_render_technical_glossary( string $lang ): string {
		$items = array(
			'SEO' => ( 'es' === $lang ) ? 'Optimización para buscadores para mejorar visibilidad orgánica.' : 'Search optimization to improve organic visibility.',
			'Crawler' => ( 'es' === $lang ) ? 'Bot que recorre páginas y enlaces internos para diagnóstico.' : 'Bot that traverses pages and internal links for diagnostics.',
			'UX' => ( 'es' === $lang ) ? 'Experiencia del usuario al navegar y convertir en el sitio.' : 'User experience quality across navigation and conversion.',
			'CTR' => ( 'es' === $lang ) ? 'Porcentaje de clics sobre impresiones en resultados de búsqueda.' : 'Click-through rate over impressions in search results.',
			'Core Web Vitals' => ( 'es' === $lang ) ? 'Métricas clave de experiencia real: LCP, INP y CLS.' : 'Core experience metrics: LCP, INP and CLS.',
			'LCP' => ( 'es' === $lang ) ? 'Tiempo de carga del contenido principal visible.' : 'Time to render the largest visible content.',
			'TTFB' => ( 'es' === $lang ) ? 'Tiempo que tarda el servidor en responder la primera solicitud.' : 'Server time to first byte.',
			'CLS' => ( 'es' === $lang ) ? 'Estabilidad visual; mide saltos inesperados del layout.' : 'Visual stability metric for unexpected layout shifts.',
			'Structured Data' => ( 'es' === $lang ) ? 'Datos estructurados en formato Schema.org para enriquecer resultados de búsqueda.' : 'Schema.org structured metadata used to enrich search results.',
			'Meta Tags' => ( 'es' === $lang ) ? 'Etiquetas HTML que describen título, descripción y directivas para indexación.' : 'HTML tags that define title, description and indexing directives.',
			'Indexación' => ( 'es' === $lang ) ? 'Proceso por el cual los buscadores agregan páginas a su índice.' : 'Process by which search engines add pages to their index.',
		);
		$out = '<ul class="ews-list">';
		foreach ( $items as $term => $desc ) {
			$out .= '<li><strong>' . esc_html( $term ) . ':</strong> ' . esc_html( $desc ) . '</li>';
		}
		$out .= '</ul>';
		return $out;
	}
}

if ( ! function_exists( 'ews_report_svg_gauge' ) ) {
	function ews_report_svg_gauge( float $score, array $palette ): string {
		$score = max( 0, min( 10, $score ) );
		$color = $palette[0] ?? '#0a84ff';
		$circumference = 2 * M_PI * 130;
		$progress      = $circumference * ( $score / 10 );
		return '<svg viewBox="0 0 900 500" width="100%" height="500" preserveAspectRatio="xMidYMid meet"><circle cx="450" cy="250" r="130" fill="none" stroke="#e5e7eb" stroke-width="36"/><circle cx="450" cy="250" r="130" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="36" stroke-linecap="round" transform="rotate(-90 450 250)" stroke-dasharray="' . round( $progress, 2 ) . ' ' . round( $circumference, 2 ) . '"/><text x="450" y="250" text-anchor="middle" font-size="74" fill="#1d1d1f" dominant-baseline="middle">' . esc_html( number_format( $score, 2 ) ) . '</text><text x="450" y="292" text-anchor="middle" font-size="24" fill="#6e6e73">/ 10</text></svg>';
	}
}

if ( ! function_exists( 'ews_report_svg_radar' ) ) {
	function ews_report_svg_radar( array $components, array $palette, string $lang ): string {
		$labels = ( 'es' === $lang ) ? array( 'Rendimiento', 'SEO', 'Accesibilidad', 'Conversión', 'Estructura' ) : array( 'Performance', 'SEO', 'Accessibility', 'Conversion', 'Structure' );
		$metrics = array(
			(float) ( $components['performance'] ?? 0 ),
			(float) ( $components['seo_technical'] ?? 0 ),
			(float) ( $components['accessibility'] ?? 0 ),
			(float) ( $components['conversion_signals'] ?? 0 ),
			(float) ( $components['crawler_structure'] ?? 0 ),
		);
		$cx = 450;
		$cy = 250;
		$r  = 180;
		$color = $palette[1] ?? '#30b0c7';
		$polygons = '';
		foreach ( array( 0.25, 0.5, 0.75, 1 ) as $grid_ratio ) {
			$grid_points = array();
			for ( $i = 0; $i < 5; $i++ ) {
				$angle = - M_PI / 2 + ( 2 * M_PI * $i / 5 );
				$grid_points[] = round( $cx + cos( $angle ) * $r * $grid_ratio, 2 ) . ',' . round( $cy + sin( $angle ) * $r * $grid_ratio, 2 );
			}
			$polygons .= '<polygon points="' . esc_attr( implode( ' ', $grid_points ) ) . '" fill="none" stroke="#eceef2" stroke-width="1"/>';
		}
		$value_points = array();
		$axes         = '';
		$texts        = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$angle = - M_PI / 2 + ( 2 * M_PI * $i / 5 );
			$ax_x  = $cx + cos( $angle ) * $r;
			$ax_y  = $cy + sin( $angle ) * $r;
			$axes .= '<line x1="' . round( $cx, 2 ) . '" y1="' . round( $cy, 2 ) . '" x2="' . round( $ax_x, 2 ) . '" y2="' . round( $ax_y, 2 ) . '" stroke="#d8dce2" stroke-width="1"/>';
			$ratio = max( 0, min( 100, $metrics[ $i ] ) ) / 100;
			$value_points[] = round( $cx + cos( $angle ) * $r * $ratio, 2 ) . ',' . round( $cy + sin( $angle ) * $r * $ratio, 2 );
			$label_x = $cx + cos( $angle ) * ( $r + 58 );
			$label_y = $cy + sin( $angle ) * ( $r + 58 );
			$texts .= '<text x="' . round( $label_x, 2 ) . '" y="' . round( $label_y, 2 ) . '" text-anchor="middle" font-size="16" fill="#6e6e73">' . esc_html( $labels[ $i ] . ' ' . number_format( $metrics[ $i ], 1 ) . '%' ) . '</text>';
		}
		return '<svg viewBox="0 0 900 500" width="100%" height="500" preserveAspectRatio="xMidYMid meet">' . $polygons . $axes . '<polygon points="' . esc_attr( implode( ' ', $value_points ) ) . '" fill="' . esc_attr( $color ) . '" fill-opacity="0.30" stroke="' . esc_attr( $color ) . '" stroke-width="4"/>' . $texts . '</svg>';
	}
}

if ( ! function_exists( 'ews_report_svg_competitors' ) ) {
	function ews_report_svg_competitors( array $report, array $palette, string $lang ): string {
		$rows = (array) ( $report['competitor_snapshot']['competitors'] ?? array() );
		$site_domain = (string) wp_parse_url( (string) ( $report['url'] ?? '' ), PHP_URL_HOST );
		$site_score  = (float) ( $report['score']['health_score'] ?? 0 );
		if ( empty( $rows ) ) {
			return '<div>' . esc_html( ( 'es' === $lang ) ? 'No se proporcionaron competidores para benchmarking.' : 'No competitors were provided for benchmarking.' ) . '</div>';
		}
		$data = array( array( 'domain' => $site_domain, 'score' => $site_score ) );
		foreach ( array_slice( $rows, 0, 3 ) as $row ) {
			$data[] = array( 'domain' => (string) ( $row['domain'] ?? 'n/a' ), 'score' => (float) ( $row['health_score'] ?? 0 ) );
		}
		$color_main = $palette[2] ?? '#ff9f0a';
		$color_comp = '#d1d1d6';
		$bar_w      = 130;
		$gap        = 70;
		$start_x    = 90;
		$base_y     = 420;
		$svg = '<svg viewBox="0 0 900 500" width="100%" height="500" preserveAspectRatio="xMidYMid meet"><line x1="80" y1="420" x2="840" y2="420" stroke="#d8dce2"/>';
		foreach ( $data as $index => $row ) {
			$score = max( 0, min( 10, (float) $row['score'] ) );
			$height = $score * 34;
			$x = $start_x + ( $index * ( $bar_w + $gap ) );
			$y = $base_y - $height;
			$fill = 0 === $index ? $color_main : $color_comp;
			$svg .= '<rect x="' . round( $x, 2 ) . '" y="' . round( $y, 2 ) . '" rx="10" ry="10" width="' . $bar_w . '" height="' . round( $height, 2 ) . '" fill="' . esc_attr( $fill ) . '"/>';
			$svg .= '<text x="' . round( $x + ( $bar_w / 2 ), 2 ) . '" y="' . round( $y - 8, 2 ) . '" text-anchor="middle" font-size="18" fill="#1d1d1f">' . esc_html( number_format( $score, 2 ) ) . '</text>';
			$svg .= '<text x="' . round( $x + ( $bar_w / 2 ), 2 ) . '" y="450" text-anchor="middle" font-size="14" fill="#6e6e73">' . esc_html( substr( (string) $row['domain'], 0, 18 ) ) . '</text>';
		}
		$svg .= '</svg>';
		return $svg;
	}
}

if ( ! function_exists( 'ews_report_svg_crawler' ) ) {
	function ews_report_svg_crawler( array $tree, array $palette, string $lang ): string {
		if ( empty( $tree['url'] ) ) {
			return '<div>' . esc_html( ( 'es' === $lang ) ? 'Sin datos' : 'No data' ) . '</div>';
		}
		$levels = array();
		$queue = array( array( 'node' => $tree, 'depth' => 0, 'parent' => -1, 'id' => 0 ) );
		$node_id = 1;
		while ( ! empty( $queue ) ) {
			$current = array_shift( $queue );
			$depth = (int) $current['depth'];
			if ( $depth > 3 ) {
				continue;
			}
			if ( ! isset( $levels[ $depth ] ) ) {
				$levels[ $depth ] = array();
			}
			$levels[ $depth ][] = $current;
			$children = (array) ( $current['node']['children'] ?? array() );
			$children = array_slice( $children, 0, 8 );
			foreach ( $children as $child ) {
				$queue[] = array( 'node' => $child, 'depth' => $depth + 1, 'parent' => (int) $current['id'], 'id' => $node_id++ );
			}
		}
		$positions = array();
		$y_map = array( 70, 170, 280, 390 );
		$svg = '<svg viewBox="0 0 900 500" width="100%" height="500" preserveAspectRatio="xMidYMid meet">';
		foreach ( $levels as $depth => $nodes ) {
			$count = max( 1, count( $nodes ) );
			foreach ( $nodes as $idx => $node ) {
				$x = 80 + ( ( 740 / $count ) * $idx ) + 40;
				$y = $y_map[ $depth ] ?? 420;
				$positions[ (int) $node['id'] ] = array( 'x' => $x, 'y' => $y, 'node' => $node );
			}
		}
		foreach ( $positions as $id => $pos ) {
			$parent = (int) ( $pos['node']['parent'] ?? -1 );
			if ( $parent >= 0 && isset( $positions[ $parent ] ) ) {
				$svg .= '<line x1="' . round( $positions[ $parent ]['x'], 2 ) . '" y1="' . round( $positions[ $parent ]['y'], 2 ) . '" x2="' . round( $pos['x'], 2 ) . '" y2="' . round( $pos['y'], 2 ) . '" stroke="#c9ced6" stroke-width="2"/>';
			}
		}
		foreach ( $positions as $id => $pos ) {
			$depth = (int) ( $pos['node']['depth'] ?? 0 );
			$radius = 12 - min( 4, $depth );
			$color = ( 0 === $depth ) ? ( $palette[3] ?? '#32d74b' ) : '#0a84ff';
			$svg .= '<circle cx="' . round( $pos['x'], 2 ) . '" cy="' . round( $pos['y'], 2 ) . '" r="' . $radius . '" fill="' . esc_attr( $color ) . '"/>';
			$label = (string) wp_parse_url( (string) ( $pos['node']['node']['url'] ?? '' ), PHP_URL_PATH );
			$label = '' !== $label ? trim( $label, '/' ) : 'home';
			$svg .= '<text x="' . round( $pos['x'], 2 ) . '" y="' . round( $pos['y'] + 20, 2 ) . '" text-anchor="middle" font-size="12" fill="#6e6e73">' . esc_html( substr( $label, 0, 20 ) ) . '</text>';
		}
		$svg .= '</svg>';
		return $svg;
	}
}
