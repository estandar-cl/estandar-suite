<?php
/**
 * Project Provisioning — Phase 0 (scaffolding, inert).
 *
 * Contracts + orchestrator + discovery hook + state option. No module
 * provisioners are registered in P0, so a provision() run is a no-op:
 * zero config writes (only the provisioning state option is touched).
 *
 * BC axiom (frozen): fill-missing only. Never overwrite. Never migrate.
 *
 * @package estandar-core-mu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------- */
/* Interface                                                                  */
/* -------------------------------------------------------------------------- */

interface Estandar_Project_Provisioner_Interface {
	/** Stable namespaced id, e.g. 'leads.operators.provisioner'. */
	public function id(): string;
	/** Human label for reports/telemetry. */
	public function label(): string;
	/**
	 * Fill MISSING configuration for the project. MUST be idempotent,
	 * check-then-fill (never overwrite), and dry-run aware.
	 */
	public function provision( Estandar_Provisioning_Context $ctx ): void;
}

/* -------------------------------------------------------------------------- */
/* Value object: Estandar_Project (immutable registry snapshot)               */
/* -------------------------------------------------------------------------- */

final class Estandar_Project {

	private string $slug;
	private string $uuid;
	private string $label;
	private string $type;
	private string $company_id;
	private bool $active;
	private string $created_at;

	private function __construct( array $d ) {
		$this->slug       = (string) ( $d['slug'] ?? '' );
		$this->uuid       = (string) ( $d['uuid'] ?? '' );
		$this->label      = (string) ( $d['label'] ?? '' );
		$this->type       = (string) ( $d['type'] ?? '' );
		$this->company_id = (string) ( $d['company_id'] ?? '' );
		$this->active     = (bool) ( $d['active'] ?? true );
		$this->created_at = (string) ( $d['created_at'] ?? '' );
	}

	/** Map a Project Registry row → value object. Storage-agnostic. */
	public static function from_registry_row( array $row ): self {
		return new self( array(
			'slug'       => $row['project_id'] ?? '',
			'uuid'       => $row['project_uuid'] ?? '',
			'label'      => $row['label'] ?? '',
			'type'       => $row['project_type'] ?? '',
			'company_id' => $row['company_id'] ?? '',
			'active'     => $row['active'] ?? true,
			'created_at' => $row['created_at'] ?? '',
		) );
	}

	public function slug(): string { return $this->slug; }
	public function uuid(): string { return $this->uuid; }
	public function label(): string { return $this->label; }
	public function type(): string { return $this->type; }
	public function company_id(): string { return $this->company_id; }
	public function is_active(): bool { return $this->active; }
	public function created_at(): string { return $this->created_at; }

	public function to_array(): array {
		return array(
			'slug' => $this->slug, 'uuid' => $this->uuid, 'label' => $this->label,
			'type' => $this->type, 'company_id' => $this->company_id,
			'active' => $this->active, 'created_at' => $this->created_at,
		);
	}
}

/* -------------------------------------------------------------------------- */
/* Context + recorder                                                         */
/* -------------------------------------------------------------------------- */

final class Estandar_Provisioning_Context {

	private Estandar_Project $project;
	private array $intent;
	private bool $dry_run;
	private string $run_id;

	/** @var array<string,array{status:string,changes:array,message:string,ms:int}> */
	private array $results = array();
	private string $current = '';

	public function __construct( Estandar_Project $project, array $intent, bool $dry_run, string $run_id ) {
		$this->project = $project;
		$this->intent  = $intent;
		$this->dry_run = $dry_run;
		$this->run_id  = $run_id;
	}

	public function project(): Estandar_Project { return $this->project; }
	public function is_dry_run(): bool { return $this->dry_run; }
	public function run_id(): string { return $this->run_id; }

	/** @return mixed */
	public function intent( string $key, $default = null ) {
		return $this->intent[ $key ] ?? $default;
	}

	// ── Recorder (provisioner-facing) ──────────────────────────────────────
	public function record_applied( string $config_key, $detail = null ): void {
		$this->touch();
		$this->results[ $this->current ]['status']    = 'applied';
		$this->results[ $this->current ]['changes'][] = sanitize_key( $config_key );
	}
	public function record_skipped( string $reason = 'already_configured' ): void {
		$this->touch();
		if ( $this->results[ $this->current ]['status'] === 'noop' ) {
			$this->results[ $this->current ]['status']  = 'skipped';
			$this->results[ $this->current ]['message'] = $reason;
		}
	}
	public function record_noop( string $reason = 'not_applicable' ): void {
		$this->touch();
		if ( $this->results[ $this->current ]['status'] === 'noop' && $this->results[ $this->current ]['message'] === '' ) {
			$this->results[ $this->current ]['message'] = $reason;
		}
	}

	// ── Orchestrator-only lifecycle (do not call from provisioners) ─────────
	public function _begin_module( string $module_id ): void {
		$this->current = $module_id;
		if ( ! isset( $this->results[ $module_id ] ) ) {
			$this->results[ $module_id ] = array( 'status' => 'noop', 'changes' => array(), 'message' => '', 'ms' => 0 );
		}
	}
	public function _fail_module( string $module_id, string $message ): void {
		$this->_begin_module( $module_id );
		$this->results[ $module_id ]['status']  = 'failed';
		$this->results[ $module_id ]['message'] = $message;
	}
	public function _end_module( string $module_id, int $ms ): void {
		if ( isset( $this->results[ $module_id ] ) ) {
			$this->results[ $module_id ]['ms'] = $ms;
		}
	}
	public function _results(): array { return $this->results; }

	private function touch(): void {
		if ( $this->current === '' ) {
			$this->current = '_unknown';
		}
		if ( ! isset( $this->results[ $this->current ] ) ) {
			$this->results[ $this->current ] = array( 'status' => 'noop', 'changes' => array(), 'message' => '', 'ms' => 0 );
		}
	}
}

/* -------------------------------------------------------------------------- */
/* Report (immutable)                                                         */
/* -------------------------------------------------------------------------- */

final class Estandar_Provisioning_Report {

	private string $run_id;
	private string $slug;
	private bool $dry_run;
	private array $modules;
	private string $overall;

	private function __construct( string $run_id, string $slug, bool $dry_run, array $modules, string $overall ) {
		$this->run_id  = $run_id;
		$this->slug    = $slug;
		$this->dry_run = $dry_run;
		$this->modules = $modules;
		$this->overall = $overall;
	}

	public static function from_context( Estandar_Provisioning_Context $ctx ): self {
		$modules = $ctx->_results();
		return new self( $ctx->run_id(), $ctx->project()->slug(), $ctx->is_dry_run(), $modules, self::derive_overall( $modules ) );
	}

	public static function disabled( Estandar_Project $project, string $run_id ): self {
		return new self( $run_id, $project->slug(), false, array(), 'disabled' );
	}

	private static function derive_overall( array $modules ): string {
		if ( empty( $modules ) ) {
			return 'noop';
		}
		$failed = 0; $total = 0;
		foreach ( $modules as $m ) {
			$total++;
			if ( ( $m['status'] ?? '' ) === 'failed' ) { $failed++; }
		}
		if ( $failed === 0 )      { return 'ok'; }
		if ( $failed === $total ) { return 'failed'; }
		return 'partial';
	}

	public function run_id(): string { return $this->run_id; }
	public function project_slug(): string { return $this->slug; }
	public function dry_run(): bool { return $this->dry_run; }
	public function modules(): array { return $this->modules; }
	public function overall_status(): string { return $this->overall; }

	public function counts(): array {
		$c = array( 'applied' => 0, 'skipped' => 0, 'noop' => 0, 'failed' => 0 );
		foreach ( $this->modules as $m ) {
			$s = $m['status'] ?? 'noop';
			if ( isset( $c[ $s ] ) ) { $c[ $s ]++; }
		}
		return $c;
	}

	public function to_array(): array {
		return array(
			'run_id' => $this->run_id, 'project_slug' => $this->slug,
			'dry_run' => $this->dry_run, 'overall_status' => $this->overall,
			'modules' => $this->modules,
		);
	}
}

/* -------------------------------------------------------------------------- */
/* Registry (self-registration, priority-ordered, dedupe by id)               */
/* -------------------------------------------------------------------------- */

final class Estandar_Provisioner_Registry {

	/** @var array<string,array{p:Estandar_Project_Provisioner_Interface,prio:int}> */
	private array $items = array();

	public function register( Estandar_Project_Provisioner_Interface $provisioner, int $priority = 50 ): void {
		$id = $provisioner->id();
		if ( $id === '' ) {
			return;
		}
		$this->items[ $id ] = array( 'p' => $provisioner, 'prio' => $priority );
	}

	/** @return Estandar_Project_Provisioner_Interface[] priority ASC, then id ASC. */
	public function all(): array {
		$items = $this->items;
		uasort( $items, static function ( $a, $b ) {
			return $a['prio'] <=> $b['prio'];
		} );
		// stable tiebreak by id for determinism
		$ids = array_keys( $items );
		sort( $ids );
		$byprio = array();
		foreach ( $items as $id => $it ) { $byprio[ $it['prio'] ][] = $id; }
		ksort( $byprio );
		$ordered = array();
		foreach ( $byprio as $group ) {
			sort( $group );
			foreach ( $group as $id ) { $ordered[] = $this->items[ $id ]['p']; }
		}
		return $ordered;
	}
}

/* -------------------------------------------------------------------------- */
/* State option gateway (advisory marker, NOT the idempotency gate)           */
/* -------------------------------------------------------------------------- */

final class Estandar_Provisioning_State {

	const OPTION_KEY = 'estandar_project_provisioning_state';
	const SCHEMA     = 1;

	private static function read(): array {
		$state = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $state ) || ( $state['schema'] ?? 0 ) !== self::SCHEMA ) {
			return array( 'schema' => self::SCHEMA, 'projects' => array() );
		}
		return $state;
	}

	public static function get( string $slug ): array {
		$state = self::read();
		return $state['projects'][ $slug ]['modules'] ?? array();
	}

	public static function all(): array {
		return self::read()['projects'] ?? array();
	}

	/** Merge a run's outcome. Never destructive. */
	public static function record_run( Estandar_Provisioning_Report $report ): void {
		$state = self::read();
		$slug  = $report->project_slug();
		$now   = self::now();

		$existing = $state['projects'][ $slug ]['modules'] ?? array();
		foreach ( $report->modules() as $module_id => $m ) {
			$existing[ $module_id ] = array(
				'version'        => 1,
				'status'         => (string) ( $m['status'] ?? 'noop' ),
				'provisioned_at' => $now,
				'changes'        => array_values( (array) ( $m['changes'] ?? array() ) ),
				'message'        => (string) ( $m['message'] ?? '' ),
			);
		}

		$state['projects'][ $slug ] = array(
			'last_run_id' => $report->run_id(),
			'last_run_at' => $now,
			'modules'     => $existing,
		);

		update_option( self::OPTION_KEY, $state, false );
	}

	private static function now(): string {
		return function_exists( 'gmdate' ) ? gmdate( 'c' ) : date( 'c' );
	}
}

/* -------------------------------------------------------------------------- */
/* Orchestrator + facade                                                      */
/* -------------------------------------------------------------------------- */

final class Estandar_Project_Provisioner {

	/**
	 * Single canonical entry point. Never throws to the caller.
	 */
	public static function provision( Estandar_Project $project, array $intent = array(), array $opts = array() ): Estandar_Provisioning_Report {
		$run_id  = self::run_id();
		$dry_run = ! empty( $opts['dry_run'] );

		// 1. Kill switch.
		if ( ! (bool) apply_filters( 'estandar_project_provisioning_enabled', true ) ) {
			self::log( 'warning', 'PROVISION_DISABLED', array( 'run_id' => $run_id, 'project_slug' => $project->slug() ) );
			return Estandar_Provisioning_Report::disabled( $project, $run_id );
		}

		// 2. Context + intent enrichment.
		$intent = (array) apply_filters( 'estandar_project_provisioning_intent', $intent, $project );
		$ctx    = new Estandar_Provisioning_Context( $project, $intent, $dry_run, $run_id );

		// 3. Discover.
		$registry = new Estandar_Provisioner_Registry();
		do_action( 'estandar_project_provisioner_register', $registry );
		$provisioners = $registry->all();

		self::log( 'info', 'PROVISION_RUN_STARTED', array(
			'run_id' => $run_id, 'project_slug' => $project->slug(),
			'dry_run' => $dry_run, 'module_count' => count( $provisioners ),
		) );

		// 4. Execute (per-module failure isolation).
		foreach ( $provisioners as $p ) {
			$module_id = $p->id();
			$ctx->_begin_module( $module_id );
			$t0 = microtime( true );
			try {
				$p->provision( $ctx );
			} catch ( Throwable $e ) {
				$ctx->_fail_module( $module_id, $e->getMessage() );
			}
			$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			$ctx->_end_module( $module_id, $ms );

			$status = $ctx->_results()[ $module_id ]['status'] ?? 'noop';
			$code   = $dry_run ? 'PROVISION_DRY_RUN'
				: ( $status === 'failed' ? 'PROVISION_MODULE_FAILED'
				: ( $status === 'applied' ? 'PROVISION_MODULE_APPLIED' : 'PROVISION_MODULE_SKIPPED' ) );
			self::log( $status === 'failed' ? 'error' : 'info', $code, array(
				'run_id' => $run_id, 'project_slug' => $project->slug(),
				'module' => $module_id, 'ms' => $ms,
			) );
		}

		// 5. Aggregate + persist + notify.
		$report = Estandar_Provisioning_Report::from_context( $ctx );
		if ( ! $dry_run ) {
			Estandar_Provisioning_State::record_run( $report );
		}
		do_action( 'estandar_project_provisioned', $report );

		self::log( 'info', 'PROVISION_RUN_COMPLETED', array(
			'run_id' => $run_id, 'project_slug' => $project->slug(),
			'overall_status' => $report->overall_status(),
		) + $report->counts() );

		return $report;
	}

	private static function run_id(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'prov_', true );
	}

	private static function log( string $severity, string $code, array $context ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}
		do_action( 'estandar_telemetry_event', array(
			'source_plugin' => 'estandar-core-mu',
			'type'          => 'business_event',
			'severity'      => $severity,
			'code'          => $code,
			'message'       => 'Project provisioning: ' . $code,
			'context'       => $context,
		) );
	}
}

/* -------------------------------------------------------------------------- */
/* Bootstrap — wires the create → provision listener                          */
/* -------------------------------------------------------------------------- */

final class Estandar_Project_Provisioning {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Primary trigger: a project was created → provision (fill-missing).
		add_action( 'estandar_project_created', array( __CLASS__, 'on_project_created' ), 10, 2 );
	}

	/** @param mixed $project @param mixed $intent */
	public static function on_project_created( $project, $intent = array() ): void {
		if ( ! $project instanceof Estandar_Project ) {
			return;
		}
		Estandar_Project_Provisioner::provision( $project, is_array( $intent ) ? $intent : array() );
	}
}
