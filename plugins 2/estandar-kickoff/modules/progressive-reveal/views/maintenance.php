<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<title><?php echo esc_html( $settings['title'] ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( ESTANDAR_PR_ASSETS_URL . 'maintenance.css?ver=' . ESTANDAR_PR_VERSION ); ?>">
</head>
<body>
	<div class="estandar-maintenance-video-bg">
		<video autoplay muted loop playsinline preload="metadata">
			<source src="<?php echo esc_url( ESTANDAR_PR_ASSETS_URL . 'loopmov.mp4' ); ?>" type="video/mp4">
		</video>
	</div>
	<main class="estandar-pr-page" role="main">
		<section class="estandar-pr-panel" aria-label="<?php echo esc_attr( $settings['title'] ); ?>">
			<div class="estandar-pr-brand">
				<?php if ( ! empty( $branding['logo_url'] ) ) : ?>
					<img src="<?php echo esc_url( $branding['logo_url'] ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				<?php else : ?>
					<span><?php echo esc_html( $branding['initials'] ); ?></span>
				<?php endif; ?>
			</div>

			<h1><?php echo esc_html( $settings['title'] ); ?></h1>
			<p><?php echo esc_html( $settings['subtitle'] ); ?></p>

			<div class="estandar-pr-progress" aria-label="<?php echo esc_attr( sprintf( __( '%d por ciento completado', 'estandar-kickoff' ), $progress['percent'] ) ); ?>">
				<div class="estandar-pr-progress-bar" style="width: <?php echo esc_attr( $progress['percent'] ); ?>%;"></div>
			</div>
			<div class="estandar-pr-percent"><?php echo esc_html( $progress['percent'] ); ?>%</div>

			<a class="estandar-pr-countdown" href="<?php echo esc_url( $redirect_url ); ?>" data-countdown-start="<?php echo esc_attr( $settings['redirect_delay'] ); ?>" aria-live="polite">
				<span>
					<?php
					printf(
						/* translators: %s = countdown number span */
						esc_html__( 'En %s segundos serás redirigido.', 'estandar-kickoff' ),
						'<span class="estandar-pr-countdown__number">' . esc_html( $settings['redirect_delay'] ) . '</span>'
					);
					?>
				</span>
				<span class="estandar-pr-countdown__cta"><?php esc_html_e( 'Haz clic aquí para ir ahora.', 'estandar-kickoff' ); ?></span>
			</a>

			<footer class="estandar-maintenance__attribution">
				<?php esc_html_e( 'Built by', 'estandar-kickoff' ); ?>
				<a href="https://estandar.cl"
					target="_blank"
					rel="noopener noreferrer">Estandar</a>
			</footer>
		</section>
	</main>
	<script>
	(function () {
		var countdown = document.querySelector('.estandar-pr-countdown');
		if (!countdown) {
			return;
		}

		var number = countdown.querySelector('.estandar-pr-countdown__number');
		var seconds = parseInt(countdown.getAttribute('data-countdown-start'), 10) || 10;
		var redirectUrl = countdown.getAttribute('href');
		if (!number || !redirectUrl) {
			return;
		}

		number.textContent = seconds;
		var timer = window.setInterval(function () {
			seconds -= 1;
			number.textContent = Math.max(seconds, 0);

			if (seconds <= 0) {
				window.clearInterval(timer);
				window.location.href = redirectUrl;
			}
		}, 1000);
	}());
	</script>
</body>
</html>
