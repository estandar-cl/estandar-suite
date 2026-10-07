<?php
/**
 * Meta Browser Pixel (frontend only) for Estandar Leads.
 *
 * Replaces the site-level pixel snippets (pixel_acacios / "pixel de facebook"):
 * prints the fbq base code from the same stored configuration the CAPI layer
 * uses, so Browser and Server events share one source of truth (Pixel ID).
 *
 * Emits:
 *   fbq('init', <pixel_id>)   on every frontend page
 *   fbq('track','PageView')   on every frontend page
 *   fbq('track','ViewContent', {content_name}) on singular content
 *   Lead — fired by existing capture integrations once fbq exists
 *          (estandar-forms already calls fbq('track','Lead') on submit).
 *
 * Gates — the pixel is NEVER printed unless ALL hold:
 *   enabled        !== '0'  (master switch; '' = enabled, backward compatible)
 *   browser_events === '1'  (explicit opt-in — default OFF so installs still
 *                            running the legacy snippets never double-fire)
 *   pixel_id       !== ''
 *
 * Boundaries: no HTTP, no queue, no $wpdb, no CAPI knowledge. Reads options,
 * prints markup. Access token is never needed nor read here.
 *
 * @package EstandarLeads
 * @since   13.0.47
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Meta_Pixel {

	const OPTION_PREFIX = 'estandar_leads_meta_';

	public static function init() {
		if ( is_admin() ) {
			return;
		}
		// Early in <head>, matching how Meta recommends placing the base code.
		add_action( 'wp_head', array( __CLASS__, 'print_pixel' ), 4 );
	}

	/**
	 * All gates in one place. Public so diagnostics can report Browser status.
	 */
	public static function is_active() {
		return self::option( 'enabled' ) !== '0'
			&& self::option( 'browser_events' ) === '1'
			&& self::option( 'pixel_id' ) !== '';
	}

	/** Idempotence guard: el base code se imprime a lo más una vez por request. */
	private static $printed = false;

	public static function print_pixel() {
		if ( self::$printed || ! self::is_active() ) {
			return;
		}
		self::$printed = true;

		$pixel_id = self::option( 'pixel_id' );
		?>
<!-- Meta Pixel — Estandar Leads -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '<?php echo esc_js( $pixel_id ); ?>');
fbq('track', 'PageView');
<?php if ( is_singular() ) : ?>
fbq('track', 'ViewContent', {content_name: '<?php echo esc_js( get_the_title() ); ?>'});
<?php endif; ?>
</script>
<noscript><img height="1" width="1" style="display:none" alt=""
src="https://www.facebook.com/tr?id=<?php echo esc_attr( rawurlencode( $pixel_id ) ); ?>&ev=PageView&noscript=1"/></noscript>
<!-- End Meta Pixel — Estandar Leads -->
		<?php
	}

	private static function option( $key ) {
		return (string) get_option( self::OPTION_PREFIX . $key, '' );
	}
}
