<?php
/**
 * Estandar Diagnostics Engine — Phase 1 built-in checks.
 *
 * Groups: 'plugins' and 'updates'. Generic, read-only, no client logic.
 * Platform constants (updater host/url/transient) are read from Estandar_Updates
 * when available — never duplicated here.
 *
 * @package estandar-core-mu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------- */
/* Group: plugins                                                             */
/* -------------------------------------------------------------------------- */

final class Estandar_Check_Plugins_Inventory implements Estandar_Diagnostic_Check {
	public function id(): string { return 'core.plugins.inventory'; }
	public function group(): string { return 'plugins'; }
	public function label(): string { return 'Estandar plugins inventory'; }
	public function is_repairable(): bool { return false; }

	public function run( Estandar_Diagnostic_Context $ctx ): Estandar_Diagnostic_Result {
		$plugins  = $ctx->estandar_plugins();
		$evidence = array();
		foreach ( $plugins as $file => $data ) {
			$evidence[ dirname( (string) $file ) ] = (string) ( $data['Version'] ?? '?' );
		}
		if ( empty( $plugins ) ) {
			return Estandar_Diagnostic_Result::info( $this, 'No Estandar plugins detected.' );
		}
		return Estandar_Diagnostic_Result::info( $this, count( $plugins ) . ' Estandar plugin(s) detected.', $evidence );
	}
}

final class Estandar_Check_Plugins_Core_Active implements Estandar_Diagnostic_Check {
	public function id(): string { return 'core.plugins.core_active'; }
	public function group(): string { return 'plugins'; }
	public function label(): string { return 'Core (estandar-core-mu) active'; }
	public function is_repairable(): bool { return false; }

	public function run( Estandar_Diagnostic_Context $ctx ): Estandar_Diagnostic_Result {
		if ( $ctx->is_core_active() ) {
			return Estandar_Diagnostic_Result::pass( $this, 'estandar-core-mu is loaded.', array( 'version' => $ctx->core_version() ) );
		}
		return Estandar_Diagnostic_Result::warning(
			$this,
			'estandar-core-mu is not loaded; core-dependent features (incl. updates) are disabled.',
			array( 'ESTANDAR_CORE_MU_VERSION' => 'undefined' ),
			'Install/activate estandar-core-mu (as mu-plugin or active plugin).'
		);
	}
}

final class Estandar_Check_Plugins_Version_Spread implements Estandar_Diagnostic_Check {
	public function id(): string { return 'core.plugins.version_spread'; }
	public function group(): string { return 'plugins'; }
	public function label(): string { return 'Estandar plugin version consistency'; }
	public function is_repairable(): bool { return false; }

	public function run( Estandar_Diagnostic_Context $ctx ): Estandar_Diagnostic_Result {
		$versions = array();
		foreach ( $ctx->estandar_plugins() as $data ) {
			$versions[ (string) ( $data['Version'] ?? '?' ) ] = true;
		}
		$distinct = array_keys( $versions );
		if ( count( $distinct ) <= 1 ) {
			return Estandar_Diagnostic_Result::pass( $this, 'All Estandar plugins share one version.', array( 'version' => $distinct[0] ?? 'n/a' ) );
		}
		return Estandar_Diagnostic_Result::info(
			$this,
			'Estandar plugins report ' . count( $distinct ) . ' distinct versions.',
			array( 'versions' => implode( ', ', $distinct ) )
		);
	}
}

/* -------------------------------------------------------------------------- */
/* Group: updates                                                             */
/* -------------------------------------------------------------------------- */

final class Estandar_Check_Updates_Engine_Loaded implements Estandar_Diagnostic_Check {
	public function id(): string { return 'core.updates.engine_loaded'; }
	public function group(): string { return 'updates'; }
	public function label(): string { return 'Update engine loaded'; }
	public function is_repairable(): bool { return false; }

	public function run( Estandar_Diagnostic_Context $ctx ): Estandar_Diagnostic_Result {
		if ( class_exists( 'Estandar_Updates' ) ) {
			return Estandar_Diagnostic_Result::pass( $this, 'Estandar_Updates is loaded.' );
		}
		return Estandar_Diagnostic_Result::fatal(
			$this,
			'Estandar_Updates not loaded — no Estandar plugin can self-update.',
			array( 'core_active' => $ctx->is_core_active() ? 'yes' : 'no' ),
			'Ensure estandar-core-mu is active (the updater boots from core).'
		);
	}
}

final class Estandar_Check_Updates_Filter_Registered implements Estandar_Diagnostic_Check {
	public function id(): string { return 'core.updates.filter_registered'; }
	public function group(): string { return 'updates'; }
	public function label(): string { return 'Update URI filter registered'; }
	public function is_repairable(): bool { return false; }

	public function run( Estandar_Diagnostic_Context $ctx ): Estandar_Diagnostic_Result {
		if ( ! class_exists( 'Estandar_Updates' ) ) {
			return Estandar_Diagnostic_Result::fatal( $this, 'Update engine not loaded; cannot evaluate filter.' );
		}
		$tag = 'update_plugins_' . Estandar_Updates::HOSTNAME;
		$has = $ctx->has_filter( $tag );
		if ( $has !== false ) {
			return Estandar_Diagnostic_Result::pass( $this, 'Updater filter is registered.', array( 'filter' => $tag, 'priority' => (string) $has ) );
		}
		return Estandar_Diagnostic_Result::fail(
			$this,
			'Updater filter not registered — WP will not query Estandar updates.',
			array( 'filter' => $tag ),
			'Confirm core booted; the filter is added by Estandar_Updates::boot().'
		);
	}
}

final class Estandar_Check_Updates_Headers implements Estandar_Diagnostic_Check {
	public function id(): string { return 'core.updates.headers'; }
	public function group(): string { return 'updates'; }
	public function label(): string { return 'Update URI headers present'; }
	public function is_repairable(): bool { return false; }

	public function run( Estandar_Diagnostic_Context $ctx ): Estandar_Diagnostic_Result {
		$host    = class_exists( 'Estandar_Updates' ) ? Estandar_Updates::HOSTNAME : '';
		$missing = array();
		foreach ( $ctx->estandar_plugins() as $file => $data ) {
			$uri = (string) ( $data['UpdateURI'] ?? '' );
			$ok  = $uri !== '' && ( $host === '' || wp_parse_url( $uri, PHP_URL_HOST ) === $host );
			if ( ! $ok ) {
				$missing[ dirname( (string) $file ) ] = $uri === '' ? '(no header)' : $uri;
			}
		}
		if ( empty( $missing ) ) {
			return Estandar_Diagnostic_Result::pass( $this, 'All Estandar plugins carry a valid Update URI header.' );
		}
		return Estandar_Diagnostic_Result::warning(
			$this,
			count( $missing ) . ' Estandar plugin(s) lack a valid Update URI header (they will not self-update).',
			$missing,
			'Update those plugins once to a build that includes the Update URI header.'
		);
	}
}

final class Estandar_Check_Updates_Manifest implements Estandar_Diagnostic_Check {
	public function id(): string { return 'core.updates.manifest_reachable'; }
	public function group(): string { return 'updates'; }
	public function label(): string { return 'Update manifest reachable'; }
	public function is_repairable(): bool { return false; }

	public function run( Estandar_Diagnostic_Context $ctx ): Estandar_Diagnostic_Result {
		if ( ! class_exists( 'Estandar_Updates' ) ) {
			return Estandar_Diagnostic_Result::fatal( $this, 'Update engine not loaded; cannot resolve manifest URL.' );
		}
		$url  = Estandar_Updates::BASE_URL . 'versions.json';
		$resp = $ctx->http_get( $url, 10 );
		if ( $resp['code'] === 200 ) {
			$data  = json_decode( $resp['body'], true );
			$count = is_array( $data ) ? count( $data ) : 0;
			if ( $count > 0 ) {
				return Estandar_Diagnostic_Result::pass( $this, 'Manifest reachable (200).', array( 'entries' => (string) $count ) );
			}
			return Estandar_Diagnostic_Result::warning( $this, 'Manifest reachable but empty/invalid JSON.', array( 'url' => $url ), 'Republish versions.json.' );
		}
		return Estandar_Diagnostic_Result::warning(
			$this,
			'Manifest not reachable from this host.',
			array( 'url' => $url, 'http_code' => (string) $resp['code'], 'error' => $resp['error'] ),
			'Check DNS/egress and any server-side domain/IP allowlist on the updates host.'
		);
	}
}

/**
 * Register all built-in checks into a registry.
 */
final class Estandar_Diagnostics_Builtins {
	public static function register( Estandar_Diagnostic_Registry $registry ): void {
		$registry->register( new Estandar_Check_Plugins_Inventory() );
		$registry->register( new Estandar_Check_Plugins_Core_Active() );
		$registry->register( new Estandar_Check_Plugins_Version_Spread() );
		$registry->register( new Estandar_Check_Updates_Engine_Loaded() );
		$registry->register( new Estandar_Check_Updates_Filter_Registered() );
		$registry->register( new Estandar_Check_Updates_Headers() );
		$registry->register( new Estandar_Check_Updates_Manifest() );
	}
}
