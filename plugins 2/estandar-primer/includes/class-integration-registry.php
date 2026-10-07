<?php
/**
 * Integration Registry — proveedores de plataformas publicitarias (Meta, Google, LinkedIn).
 *
 * Contrato común: cada proveedor implementa configuración (schema declarativo),
 * diagnóstico (status/rows/panel) y encapsula su propio almacenamiento. El resto
 * de Estandar Suite lee vía Estandar_Integration_Registry — NUNCA por nombres
 * concretos de wp_options (esos viven dentro de cada proveedor).
 *
 * Registro: los plugins consumidores se enganchan a `estandar_integrations_register`
 * y llaman Estandar_Integration_Registry::register(). El boot es lazy (primer
 * acceso), así todos los plugins ya cargaron. Si el plugin dueño de un proveedor
 * está inactivo, el registry muestra su placeholder — nunca un fatal.
 *
 * @package EstandarCoreMU
 * @since   13.0.47
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contrato de proveedor de integración.
 *
 * La capa de administración (Estandar_Integrations_Admin) renderiza SOLO desde
 * este contrato — cero código específico de proveedor en la capa admin.
 */
abstract class Estandar_Integration_Provider {

	/** Identificador estable, kebab/snake ('meta', 'google', 'linkedin'). */
	abstract public function id(): string;

	/** Etiqueta visible ('Meta', 'Google Ads'). */
	abstract public function label(): string;

	/** false = estructura preparada, sin lógica de negocio todavía. */
	public function is_implemented(): bool {
		return true;
	}

	/** Descripción corta para el dashboard de estado. */
	public function description(): string {
		return '';
	}

	/**
	 * Schema declarativo de configuración, listo para render genérico.
	 * Cada campo: name (atributo POST completo), label, type (text|password|checkbox),
	 * y según tipo: value (string) o checked (bool); opcionales description, placeholder.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings_fields(): array {
		return array();
	}

	/** Configuración vigente como pares clave→valor (sin exponer option names). */
	public function get_settings(): array {
		return array();
	}

	/** Persiste desde $_POST ya verificado (nonce+capability los pone la capa admin). */
	public function save_settings( array $post ): void {}

	/**
	 * Estado agregado para el dashboard.
	 *
	 * @return array{configured:bool,active:bool,summary:string}
	 */
	public function status(): array {
		return array(
			'configured' => false,
			'active'     => false,
			'summary'    => '',
		);
	}

	/**
	 * Panel propio del proveedor (diagnóstico, visor de eventos). Se renderiza
	 * debajo del formulario. El markup es responsabilidad del proveedor — la
	 * capa admin solo lo invoca. Prohibido imprimir tokens o PII.
	 */
	public function render_extra(): void {}
}

/**
 * Placeholder para proveedores planificados sin implementación (Google, LinkedIn).
 */
final class Estandar_Integration_Placeholder_Provider extends Estandar_Integration_Provider {

	private $provider_id;
	private $provider_label;

	public function __construct( string $id, string $label ) {
		$this->provider_id    = $id;
		$this->provider_label = $label;
	}

	public function id(): string {
		return $this->provider_id;
	}

	public function label(): string {
		return $this->provider_label;
	}

	public function is_implemented(): bool {
		return false;
	}

	public function description(): string {
		return 'Estructura preparada — implementación pendiente.';
	}

	public function status(): array {
		return array(
			'configured' => false,
			'active'     => false,
			'summary'    => 'No implementado',
		);
	}
}

/**
 * Registro central de proveedores. API estática, boot lazy.
 */
final class Estandar_Integration_Registry {

	/** @var array<string,Estandar_Integration_Provider> */
	private static $providers = array();

	private static $booted = false;

	/**
	 * Proveedores planificados: si ningún plugin registró una implementación
	 * real, aparecen como placeholder en el dashboard.
	 */
	private const PLANNED = array(
		'meta'     => 'Meta',
		'google'   => 'Google Ads',
		'linkedin' => 'LinkedIn Ads',
	);

	private static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Los plugins dueños de cada proveedor se registran aquí.
		do_action( 'estandar_integrations_register' );

		foreach ( self::PLANNED as $id => $label ) {
			if ( ! isset( self::$providers[ $id ] ) ) {
				self::register( new Estandar_Integration_Placeholder_Provider( $id, $label ) );
			}
		}
	}

	public static function register( Estandar_Integration_Provider $provider ): void {
		self::$providers[ $provider->id() ] = $provider;
	}

	/** @return array<string,Estandar_Integration_Provider> implementados primero */
	public static function all(): array {
		self::boot();
		$providers = self::$providers;
		uasort( $providers, static function ( Estandar_Integration_Provider $a, Estandar_Integration_Provider $b ): int {
			return (int) $b->is_implemented() <=> (int) $a->is_implemented();
		} );
		return $providers;
	}

	public static function get( string $id ): ?Estandar_Integration_Provider {
		self::boot();
		return self::$providers[ $id ] ?? null;
	}

	// ── Abstracción de configuración para el resto de la Suite ──────────────────
	// Consumir SIEMPRE por aquí; los nombres de wp_options son privados de cada
	// proveedor y pueden cambiar sin romper consumidores.

	public static function settings( string $id ): array {
		$provider = self::get( $id );
		return $provider ? $provider->get_settings() : array();
	}

	public static function is_active( string $id ): bool {
		$provider = self::get( $id );
		if ( ! $provider ) {
			return false;
		}
		$status = $provider->status();
		return ! empty( $status['active'] );
	}
}
