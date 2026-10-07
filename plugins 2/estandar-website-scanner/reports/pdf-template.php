<?php
/**
 * PDF template and streaming.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build and stream report PDF.
 */
class EWS_PDF_Template {

	/**
	 * Stream report as PDF.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @param string               $lang Report language.
	 * @return void
	 */
	public static function stream( array $report, string $lang = 'en' ): void {
		if ( ! class_exists( '\\Dompdf\\Dompdf' ) ) {
			wp_die( esc_html__( 'DOMPDF is not installed. Please install DOMPDF to export PDF reports.', 'estandar-website-scanner' ) );
		}

		$lang = in_array( $lang, array( 'en', 'es' ), true ) ? $lang : 'en';
		$html = self::render_html( $report, $lang );

		$previous_error_reporting = error_reporting();
		error_reporting( $previous_error_reporting & ~E_DEPRECATED & ~E_USER_DEPRECATED );

		$dompdf = new \Dompdf\Dompdf(
			array(
				// Security: DOMPDF shouldn't fetch remote URLs (prevents SSRF via <img src="https://...">).
				'isRemoteEnabled' => false,
				'isHtml5ParserEnabled' => true,
			)
		);
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'LETTER', 'portrait' );
		$dompdf->render();
		$pdf_bytes = $dompdf->output();
		self::archive_pdf( $report, $pdf_bytes, $lang );

		if ( ob_get_length() ) {
			ob_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="estandar-report-' . sanitize_file_name( (string) ( $report['report_id'] ?? 'scan' ) ) . '-' . $lang . '.pdf"' );
		echo $pdf_bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		error_reporting( $previous_error_reporting );
		exit;
	}

	/**
	 * Persist PDF in uploads archive folder.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @param string               $pdf_bytes PDF binary.
	 * @param string               $lang Report language.
	 * @return void
	 */
	private static function archive_pdf( array $report, string $pdf_bytes, string $lang ): void {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'estandar-scanner-reports';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$url      = esc_url_raw( (string) ( $report['url'] ?? '' ) );
		$domain   = (string) wp_parse_url( $url, PHP_URL_HOST );
		$domain   = sanitize_file_name( str_replace( '.', '-', $domain ) );
		$date     = gmdate( 'Y-m-d' );
		$report_id = sanitize_file_name( (string) ( $report['report_id'] ?? 'scan' ) );
		$filename = $domain . '-' . $date . '-' . $report_id . '.pdf';
		$path     = trailingslashit( $dir ) . $filename;
		$file_url = trailingslashit( $uploads['baseurl'] ) . 'estandar-scanner-reports/' . $filename;

		file_put_contents( $path, $pdf_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		EWS_Scan_Repository::set_pdf_archive( (string) ( $report['report_id'] ?? '' ), $path, $file_url, $lang );
	}

	/**
	 * Render report HTML template.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @param string               $lang Report language.
	 * @return string
	 */
	private static function render_html( array $report, string $lang ): string {
		$report = self::prepare_report_images_for_pdf( $report );
		$cover   = self::render_cover_page( $report, $lang );
		$content = ews_render_report_layout(
			$report,
			array(
				'mode'       => 'pdf',
				'lang'       => $lang,
				'show_cover' => false,
			)
		);

		return '<!doctype html><html><head><meta charset="utf-8" /></head><body style="margin:0;padding:0;background:#fff;">' . $cover . $content . '</body></html>';
	}

	/**
	 * Prepare images (cover, banner, screenshot) for PDF embedding.
	 *
	 * Since DOMPDF remote fetching is disabled, we convert remote images to data URIs.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return array<string, mixed>
	 */
	private static function prepare_report_images_for_pdf( array $report ): array {
		$design = EWS_Report_Design::get_settings();

		// Header banner: embed only for PDF.
		$banner_url = trim( (string) ( $design['header_banner'] ?? '' ) );
		if ( '' !== $banner_url ) {
			$banner_data_uri = self::download_image_as_data_uri( $banner_url );
			if ( '' !== $banner_data_uri ) {
				$report['pdf_header_banner_data_uri'] = $banner_data_uri;
			}
		}

		// Screenshot: embed either the custom screenshot or the default thum.io screenshot.
		$img_url = '';
		if ( ! empty( $report['custom_website_image'] ) ) {
			$img_url = (string) $report['custom_website_image'];
		} elseif ( ! empty( $report['url'] ) && function_exists( 'ews_report_website_screenshot_url' ) ) {
			$img_url = (string) ews_report_website_screenshot_url( (string) $report['url'] );
		}

		$img_url = trim( (string) $img_url );
		if ( '' !== $img_url ) {
			$data_uri = self::download_image_as_data_uri( $img_url );
			if ( '' !== $data_uri ) {
				$report['custom_website_image'] = $data_uri;
			}
		}

		return $report;
	}

	/**
	 * Download an image and return it as a data URI.
	 *
	 * @param string $url Image URL.
	 * @return string Empty string on failure.
	 */
	private static function download_image_as_data_uri( string $url ): string {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, 'data:' ) ) {
			// Assume already embedded and sanitized upstream.
			return $url;
		}
		if ( ! EWS_Url_Safety::is_safe_url( $url ) ) {
			return '';
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'sslverify'           => true,
				'headers'             => array(
					'User-Agent' => 'Estandar Website Scanner/1.0',
				),
				// Hard limit to avoid memory blowups.
				'limit_response_size' => 3 * 1024 * 1024,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return '';
		}

		$mime = '';
		$headers = wp_remote_retrieve_headers( $response );
		if ( is_array( $headers ) ) {
			foreach ( $headers as $key => $value ) {
				if ( 'content-type' !== strtolower( (string) $key ) ) {
					continue;
				}
				$mime = is_array( $value ) ? (string) reset( $value ) : (string) $value;
				$mime = trim( explode( ';', $mime )[0] ?? '' );
				break;
			}
		}

		if ( '' === $mime ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$map  = array(
				'png'  => 'image/png',
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'webp' => 'image/webp',
				'gif'  => 'image/gif',
				'bmp'  => 'image/bmp',
				'svg'  => 'image/svg+xml',
			);
			$mime = $map[ $ext ] ?? 'image/png';
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $body );
	}

	/**
	 * Render editorial PDF cover page.
	 *
	 * @param array<string, mixed> $report Report data.
	 * @param string               $lang Language.
	 * @return string
	 */
	private static function render_cover_page( array $report, string $lang ): string {
		$design = EWS_Report_Design::get_settings();
		$design_font = sanitize_text_field( (string) ( $design['font'] ?? 'Inter' ) );

		$default_bg = EWS_PLUGIN_DIR . 'assets/pdf/cover-background.jpg';
		$background = trim( (string) ( $report['custom_cover_image'] ?? '' ) );
		if ( '' === $background ) {
			$background = trim( (string) ( $design['pdf_cover_background'] ?? '' ) );
		}
		if ( '' === $background && file_exists( $default_bg ) ) {
			// Use local filesystem path for DOMPDF.
			$background = $default_bg;
		}

		// Embed images as data URIs (DOMPDF remote fetching is disabled).
		if ( '' !== $background ) {
			if ( 0 === strpos( $background, 'http://' ) || 0 === strpos( $background, 'https://' ) ) {
				$background_data_uri = self::download_image_as_data_uri( $background );
				$background = '' !== $background_data_uri ? $background_data_uri : '';
			} elseif ( file_exists( $background ) ) {
				$background_data_uri = self::file_to_data_uri( $background );
				$background = '' !== $background_data_uri ? $background_data_uri : '';
			}
		}

		$date_string = self::format_cover_date( (string) ( $report['generated_at'] ?? '' ), $lang );
		ob_start();
		?>
		<style>
			@page:first { margin: 0; }
			.ews-pdf-cover {
				position: relative;
				width: 612pt;
				height: 792pt;
				overflow: hidden;
				/* Avoid DOMPDF producing an extra blank page after the cover. */
				page-break-after: auto;
				background: #ffffff;
				margin-top: -50mm;
				margin-left: -30mm;
			}
			.ews-pdf-cover-bg {
				position: absolute;
				top: 0;
				left: 0;
				width: 612pt;
				height: 792pt;
				display: block;
				object-fit: cover;
			}
			.ews-pdf-cover-date {
				position: absolute;
				top: 42pt;
				right: 50pt;
				font-family: <?php echo esc_html( $design_font ) . ', Arial, sans-serif'; ?>;
				font-size: 16px;
				color: #ffffff;
				letter-spacing: 0.2px;
			}
		</style>
		<div class="ews-pdf-cover">
			<?php if ( '' !== $background ) : ?>
				<img class="ews-pdf-cover-bg" src="<?php echo esc_attr( (string) $background ); ?>" alt="" />
			<?php endif; ?>
			<div class="ews-pdf-cover-date"><?php echo esc_html( $date_string ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Convert local image file to data URI.
	 *
	 * @param string $path Local file path.
	 * @return string Empty string on failure.
	 */
	private static function file_to_data_uri( string $path ): string {
		$path = trim( (string) $path );
		if ( '' === $path || ! file_exists( $path ) ) {
			return '';
		}

		$bytes = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return '';
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$mime_map = array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'webp' => 'image/webp',
			'gif'  => 'image/gif',
			'bmp'  => 'image/bmp',
			'svg'  => 'image/svg+xml',
		);
		$mime = $mime_map[ $ext ] ?? 'application/octet-stream';

		return 'data:' . $mime . ';base64,' . base64_encode( $bytes );
	}

	/**
	 * Format cover date by language.
	 *
	 * @param string $generated_at MySQL datetime.
	 * @param string $lang Language code.
	 * @return string
	 */
	private static function format_cover_date( string $generated_at, string $lang ): string {
		$timestamp = strtotime( $generated_at );
		if ( false === $timestamp ) {
			$timestamp = current_time( 'timestamp' );
		}

		if ( 'es' !== $lang ) {
			return gmdate( 'F j, Y', $timestamp );
		}

		$months = array(
			1  => 'enero',
			2  => 'febrero',
			3  => 'marzo',
			4  => 'abril',
			5  => 'mayo',
			6  => 'junio',
			7  => 'julio',
			8  => 'agosto',
			9  => 'septiembre',
			10 => 'octubre',
			11 => 'noviembre',
			12 => 'diciembre',
		);
		$month_index = (int) gmdate( 'n', $timestamp );
		$month = $months[ $month_index ] ?? gmdate( 'F', $timestamp );
		return gmdate( 'j', $timestamp ) . ' ' . $month . ' ' . gmdate( 'Y', $timestamp );
	}
}
