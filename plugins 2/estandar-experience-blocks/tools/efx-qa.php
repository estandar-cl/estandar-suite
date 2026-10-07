<?php
/**
 * Estandar FX — QA harness (local only).
 *
 * Boots WordPress and renders real block markup through do_blocks(), so the
 * whole pipeline runs exactly as in production: attribute sanitization,
 * wrapper stamping, per-page enqueue and the browser runtime.
 *
 * Usage: with the SALADEPRUEBAS server running,
 *   http://localhost:8000/wp-content/plugins/estandar-experience-blocks/tools/efx-qa.php
 */

$efx_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
if ( 0 !== strpos( $efx_host, 'localhost' ) && 0 !== strpos( $efx_host, '127.0.0.1' ) ) {
	http_response_code( 403 );
	exit( 'Local QA tool.' );
}

require dirname( __DIR__, 4 ) . '/wp-load.php';

$efx_tools_url = plugins_url( 'tools', dirname( __FILE__ ) . '/../estandar-experience-blocks.php' );
$efx_img_a     = $efx_tools_url . '/fixture-before.svg';
$efx_img_b     = $efx_tools_url . '/fixture-after.svg';

$efx_markup = <<<HTML
<!-- wp:heading {"estandarFx":{"reveal":{"effect":"rise","distance":36},"text":{"effect":"cascade","split":"words"},"timing":{"duration":850,"stagger":60,"easing":"expo"}}} -->
<h2 class="wp-block-heading" id="qa-hero">Experiencias que se sienten premium</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"estandarFx":{"reveal":{"effect":"fade"},"timing":{"duration":900,"easing":"quint"}}} -->
<p id="qa-fade">Párrafo con entrada fade — pipeline completo desde atributo hasta WAAPI.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"estandarFx":{"text":{"effect":"scramble"},"timing":{"stagger":40}}} -->
<h3 id="qa-scramble">DECODIFICADO</h3>
<!-- /wp:heading -->

<!-- wp:heading {"estandarFx":{"text":{"effect":"typewriter","speed":40}}} -->
<h3 id="qa-typewriter">Escrito letra por letra…</h3>
<!-- /wp:heading -->

<!-- wp:heading {"estandarFx":{"text":{"effect":"rotating-typewriter","speed":32,"hold":1.2}}} -->
<h3 id="qa-rotating-typewriter">Captura|Atribución|Operación|Crecimiento</h3>
<!-- /wp:heading -->

<!-- wp:heading {"estandarFx":{"text":{"effect":"glitch","intensity":1.1},"timing":{"duration":750,"stagger":28}}} -->
<h3 id="qa-glitch">GLITCH TEXT</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"estandarFx":{"text":{"effect":"reveal-wall"},"timing":{"duration":850,"stagger":36}}} -->
<p id="qa-reveal-wall">Una pared de texto aparece por bloques para titulares editoriales y manifiestos breves.</p>
<!-- /wp:paragraph -->

<!-- wp:group {"estandarFx":{"reveal":{"effect":"rise","children":true},"timing":{"stagger":90}}} -->
<div class="wp-block-group" id="qa-children"><p>Hijo uno</p><p>Hijo dos</p><p>Hijo tres</p></div>
<!-- /wp:group -->

<!-- wp:group {"estandarFx":{"hover":{"effect":"lift","intensity":1.2}},"style":{"spacing":{"padding":{"top":"2rem","bottom":"2rem","left":"2rem","right":"2rem"}}}} -->
<div class="wp-block-group" id="qa-hover-lift" style="padding:2rem;background:#f3f4f6;border-radius:16px"><p>Hover: lift</p></div>
<!-- /wp:group -->

<!-- wp:group {"estandarFx":{"hover":{"effect":"glow","color":"#6366f1"},"pointer":{"effect":"tilt","strength":12}}} -->
<div class="wp-block-group" id="qa-tilt" style="padding:2rem;background:#111;color:#fff;border-radius:16px"><p>Tilt 3D + glow (mover el cursor encima)</p></div>
<!-- /wp:group -->

<!-- wp:group {"estandarFx":{"hover":{"effect":"electric-border","color":"#22d3ee","colorB":"#a855f7","intensity":1.1}}} -->
<div class="wp-block-group" id="qa-electric-border" style="padding:2rem;background:#09090b;color:#fff;border-radius:18px"><p>Electric Border</p></div>
<!-- /wp:group -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"estandarFx":{"pointer":{"effect":"magnetic","strength":0.4}}} -->
<div class="wp-block-button" id="qa-magnetic"><a class="wp-block-button__link wp-element-button">Botón magnético</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"estandarFx":{"pointer":{"effect":"emoji-burst","count":12,"intensity":1}}} -->
<div class="wp-block-button" id="qa-emoji-burst"><a class="wp-block-button__link wp-element-button">Emoji Burst</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:group {"estandarFx":{"pointer":{"effect":"spotlight","size":360},"ambient":{"effect":"noise","intensity":1}}} -->
<div class="wp-block-group" id="qa-spotlight" style="padding:4rem 2rem;background:#0f172a;color:#e2e8f0;border-radius:16px"><p>Spotlight + grano</p></div>
<!-- /wp:group -->

<!-- wp:heading {"estandarFx":{"ambient":{"effect":"gradient","color":"#10b981","colorB":"#6366f1","speed":6}}} -->
<h2 class="wp-block-heading" id="qa-gradient">Gradiente vivo sobre texto</h2>
<!-- /wp:heading -->

<!-- wp:heading {"estandarFx":{"ambient":{"effect":"spotlight-text","color":"#f59e0b","speed":5}}} -->
<h2 class="wp-block-heading" id="qa-spotlight-text">Spotlight Text</h2>
<!-- /wp:heading -->

<!-- wp:group {"estandarFx":{"ambient":{"effect":"float","speed":7}}} -->
<div class="wp-block-group" id="qa-float" style="padding:1.5rem;background:#ecfdf5;border-radius:16px;width:fit-content"><p>Flotando…</p></div>
<!-- /wp:group -->

<!-- wp:group {"estandarFx":{"particles":{"effect":"dots","density":1.2,"link":true,"color":"#10b981"}}} -->
<div class="wp-block-group" id="qa-particles" style="padding:5rem 2rem;background:#022c22;color:#d1fae5;border-radius:16px"><p>Partículas conectadas</p></div>
<!-- /wp:group -->

<!-- wp:group {"estandarFx":{"particles":{"effect":"line-ripple","density":1,"speed":0.8,"color":"#ffffff","length":1.1,"force":1.2}}} -->
<div class="wp-block-group" id="qa-line-ripple" style="padding:7rem 2rem;background:#050816;color:#f8fafc;border-radius:20px"><h2>Line Ripple Background</h2><p>Fondo fluido con líneas reactivas al cursor.</p></div>
<!-- /wp:group -->

<!-- wp:group {"estandarFx":{"particles":{"effect":"blinking-squares","density":1.1,"speed":0.9,"color":"#38bdf8"}}} -->
<div class="wp-block-group" id="qa-blinking-squares" style="padding:6rem 2rem;background:#020617;color:#e0f2fe;border-radius:20px"><h2>Blinking Squares</h2><p>Grid ambiental para fondos tech.</p></div>
<!-- /wp:group -->

<!-- wp:image {"estandarFx":{"scroll":{"effect":"parallax","speed":0.3},"hover":{"effect":"zoom-media"}}} -->
<figure class="wp-block-image" id="qa-parallax"><img src="{$efx_img_a}" alt="Parallax" style="border-radius:16px"/></figure>
<!-- /wp:image -->

<!-- wp:image {"estandarFx":{"hover":{"effect":"image-reveal","intensity":1}}} -->
<figure class="wp-block-image" id="qa-image-reveal"><img src="{$efx_img_a}" alt="Hover image reveal" style="border-radius:16px"/><figcaption>Hover Image Reveal</figcaption></figure>
<!-- /wp:image -->

<!-- wp:image {"estandarFx":{"hover":{"effect":"dot-image-reveal","intensity":1}}} -->
<figure class="wp-block-image" id="qa-dot-image-reveal"><img src="{$efx_img_b}" alt="Dot image reveal" style="border-radius:16px"/></figure>
<!-- /wp:image -->

<!-- wp:estandar/marquee {"speed":80,"gap":56} -->
<!-- wp:paragraph --><p>ESTANDAR ·</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>EXPERIENCE ·</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>MOTION ·</p><!-- /wp:paragraph -->
<!-- /wp:estandar/marquee -->

<!-- wp:estandar/before-after {"beforeUrl":"{$efx_img_a}","afterUrl":"{$efx_img_b}","startPosition":40} /-->

<!-- wp:estandar/stack-cards {"topOffset":80,"gap":40} -->
<!-- wp:group {"style":{"color":{"background":"#dbeafe"}}} --><div class="wp-block-group has-background" style="background-color:#dbeafe;padding:6rem 2rem"><p>Tarjeta 1</p></div><!-- /wp:group -->
<!-- wp:group {"style":{"color":{"background":"#fee2e2"}}} --><div class="wp-block-group has-background" style="background-color:#fee2e2;padding:6rem 2rem"><p>Tarjeta 2</p></div><!-- /wp:group -->
<!-- wp:group {"style":{"color":{"background":"#dcfce7"}}} --><div class="wp-block-group has-background" style="background-color:#dcfce7;padding:6rem 2rem"><p>Tarjeta 3</p></div><!-- /wp:group -->
<!-- /wp:estandar/stack-cards -->

<!-- wp:paragraph {"estandarFx":{"reveal":{"effect":"mask"},"advanced":{"once":false}}} -->
<p id="qa-replay">Entrada con replay (once=false): sale y vuelve a entrar.</p>
<!-- /wp:paragraph -->
HTML;

$efx_rendered = do_blocks( $efx_markup );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Estandar FX — QA</title>
	<?php wp_head(); ?>
	<style>
		body { font-family: -apple-system, sans-serif; margin: 0; background: #fff; color: #111; }
		main { max-width: 860px; margin: 0 auto; padding: 4rem 1.5rem; }
		.qa-spacer { height: 70vh; display: grid; place-items: center; color: #9ca3af; }
		main > * { margin-bottom: 2.5rem; }
	</style>
</head>
<body>
	<main>
		<p style="color:#6b7280">QA — Estandar Experience Blocks v<?php echo esc_html( ESTANDAR_EFX_VERSION ); ?></p>
		<?php echo $efx_rendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_blocks output. ?>
		<div class="qa-spacer">— fin —</div>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
