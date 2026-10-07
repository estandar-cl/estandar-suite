<?php
/**
 * Branding común de Estandar Suite.
 *
 * @package Estandar\Suite
 */

namespace Estandar\Suite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tokens de marca y cabecera reutilizable por las páginas de settings.
 */
class Branding {

	/**
	 * Init (placeholder: reservado para hooks de branding).
	 *
	 * @return void
	 */
	public static function init(): void {
		// Sin hooks por ahora: el branding se aplica vía Assets/CSS y header().
	}

	/**
	 * Datos de marca (filtrables).
	 *
	 * @return array
	 */
	public static function data(): array {
		$data = array(
			'name'     => 'Estandar Suite',
			'tagline'  => 'Plugins de Estandar',
			'version'  => defined( 'ESTANDAR_SUITE_VERSION' ) ? ESTANDAR_SUITE_VERSION : '',
			'color'    => '#1d4ed8',
			'color_2'  => '#0f172a',
			'logo_url' => '',
			'links'    => array(),
		);

		/**
		 * Filtra los datos de marca del hub.
		 *
		 * @param array $data Datos de marca.
		 */
		return apply_filters( 'estandar_suite_brand', $data );
	}

	/**
	 * Variables CSS de marca.
	 *
	 * @return string
	 */
	public static function css_vars(): string {
		$data = self::data();

		return sprintf(
			':root{--es-brand:%1$s;--es-brand-2:%2$s;}',
			esc_attr( (string) $data['color'] ),
			esc_attr( (string) $data['color_2'] )
		);
	}

	/**
	 * Imprime la cabecera de marca. Usable dentro de páginas de settings.
	 *
	 * @param array $args Opciones: title, tagline, version, links.
	 * @return void
	 */
	public static function header( array $args = array() ): void {
		$data = self::data();

		$title   = $args['title'] ?? $data['name'];
		$tagline = $args['tagline'] ?? $data['tagline'];
		$version = $args['version'] ?? $data['version'];
		$links   = $args['links'] ?? $data['links'];

		?>
		<div class="es-brand">
			<div class="es-brand__mark" aria-hidden="true">
				<svg width="34" height="34" viewBox="0 0 34 34" xmlns="http://www.w3.org/2000/svg" role="img">
					<rect x="1" y="1" width="32" height="32" rx="8" fill="var(--es-brand, #1d4ed8)"/>
					<path d="M11 10h12v3.2h-8.4v3.1H22v3.1h-7.4v3.4H23V26H11z" fill="#fff"/>
				</svg>
			</div>
			<div class="es-brand__text">
				<span class="es-brand__title">
					<?php echo esc_html( (string) $title ); ?>
					<?php if ( '' !== (string) $version ) : ?>
						<span class="es-brand__version">v<?php echo esc_html( (string) $version ); ?></span>
					<?php endif; ?>
				</span>
				<?php if ( '' !== (string) $tagline ) : ?>
					<span class="es-brand__tagline"><?php echo esc_html( (string) $tagline ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $links ) && is_array( $links ) ) : ?>
				<div class="es-brand__links">
					<?php foreach ( $links as $link ) : ?>
						<?php if ( ! empty( $link['url'] ) ) : ?>
							<a class="es-brand__link" href="<?php echo esc_url( (string) $link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( (string) ( $link['label'] ?? $link['url'] ) ); ?>
							</a>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
