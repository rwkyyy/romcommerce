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
		// No load_plugin_textdomain(): WordPress.org auto-loads translations for
		// a hosted plugin by its slug since WP 4.6, and calling it manually is
		// flagged as discouraged by Plugin Check.
		( new Admin\Menu() )->register();
		( new Admin\Ajax() )->register();
		( new ModuleRegistrar() )->register();

		do_action( 'romcommerce/register_modules', $this->modules );
		$this->modules->boot();
		do_action( 'romcommerce/booted', $this );
	}

	public function modules(): ModuleLoader {
		return $this->modules;
	}
}
