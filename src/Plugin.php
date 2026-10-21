<?php

declare(strict_types=1);

namespace RomCommerce;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private ModuleLoader $modules;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->modules = new ModuleLoader();
	}

	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		( new Admin\Menu() )->register();
		( new Admin\Ajax() )->register();
		( new Admin\WelcomeNotice() )->register();
		( new ModuleRegistrar() )->register();
		Tools\Abilities::register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Tools\Cli::register();
		}

		do_action( 'romcommerce/register_modules', $this->modules );
		$this->modules->boot();
		do_action( 'romcommerce/booted', $this );
	}

	/**
	 * Registers languages/ as a fallback translation path. Core still prefers
	 * a translate.wordpress.org language pack in WP_LANG_DIR when one is
	 * installed (WP_Textdomain_Registry searches it first), so this only fills
	 * the gap until community packs exist — RomCommerce's audience is mostly
	 * Romanian, and a pack below wp.org's completion threshold never ships.
	 * Plugin Check flags the call as discouraged; that is a warning, not a
	 * blocker, and accepted here (docs/decisions.md, 2026-10-03).
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'romcommerce', false, dirname( plugin_basename( ROMCOMMERCE_FILE ) ) . '/languages' );
	}

	public function modules(): ModuleLoader {
		return $this->modules;
	}
}
